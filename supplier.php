<?php
if (!defined('SCHEMA_VERSION')) { http_response_code(404); exit; }   // never run directly from the browser
/**
 * bulkmail.shop API v2 integration (supplier).
 *
 * How it works
 *  - Admin > API Management: paste the bulkmail.shop API key -> Connect / Disconnect.
 *  - Admin > Products: type the supplier "Product ID" -> the product is linked.
 *  - Stock shown in your shop = live stock at bulkmail.shop (refreshed at most once a minute).
 *  - Name + description (with the possible domains) are copied from bulkmail.shop and refreshed every 15 minutes, for every product
 *    whose "auto-sync" box is ticked. The admin can still write his own name / description: typing your own text unticks the box.
 *  - When a customer buys a linked product: the customer's wallet is charged, the order is placed at bulkmail.shop with
 *    your API key, and the delivered items (email:password) are shown to the customer instantly.
 *  - If bulkmail.shop clearly refuses the order (no stock, no balance, bad key ...) the customer is refunded automatically.
 *    If the answer is unclear (timeout / server error) the order stays "pending" for you to check - it is never refunded
 *    or re-sent automatically, so nothing can be bought twice.
 *
 * Docs: https://docs.bulkmail.shop  (auth: X-API-Key header, base URL https://bulkmail.shop/api/v2)
 */

const BM_DEFAULT_BASE = 'https://bulkmail.shop/api/v2';
const BM_INFO_EVERY   = 300;  // seconds between name / description / domain refreshes of linked products
const BM_SYNC_EVERY   = 60;   // seconds between stock refreshes (the public stock endpoints allow 20 requests / minute)

function bm_base(): string { return rtrim(setting('bm_base', BM_DEFAULT_BASE), '/'); }
function bm_key(): string  { return trim(setting('bm_key', '')); }
/** True only while an API key is saved AND the admin has not disconnected. */
function bm_connected(): bool { return setting('bm_connected', '0') === '1' && bm_key() !== ''; }
function bm_mask(string $k): string {
    $n = strlen($k);
    return $n <= 8 ? str_repeat('•', $n) : substr($k, 0, 4) . str_repeat('•', 8) . substr($k, -4);
}

/**
 * One API request. Always returns an array: success (bool), data/error/errors from the API, _http (status, 0 = no answer).
 * $key = null sends no key (public endpoints), '' uses the saved key.
 */
function bm_call(string $method, string $path, ?array $body = null, ?string $key = '', int $timeout = 15): array {
    $headers = ['Accept: application/json'];
    if ($key === '') $key = bm_key();
    if ($key !== null && $key !== '') $headers[] = 'X-API-Key: ' . $key;
    $ch = curl_init(bm_base() . $path);
    $opt = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,   // a redirect could leak the key to another host
    ];
    if ($body !== null) { $headers[] = 'Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS] = json_encode($body); }
    $opt[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opt);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['success' => false, 'error' => 'No answer from bulkmail.shop (' . $err . ')', '_http' => 0];
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) return ['success' => false, 'error' => 'bulkmail.shop sent an unreadable answer (HTTP ' . $code . ')', '_http' => $code];
    $j['_http'] = $code;
    if ($code >= 400) $j['success'] = false;
    return $j;
}

/** Readable text for an error answer. */
function bm_err(array $r): string {
    $m = trim((string)($r['error'] ?? $r['message'] ?? ''));
    if (!empty($r['errors']) && is_array($r['errors'])) {
        foreach ($r['errors'] as $f => $list) { $m .= ($m !== '' ? ' · ' : '') . (is_array($list) ? implode(', ', array_map('strval', $list)) : (string)$list); break; }
    }
    return $m !== '' ? $m : 'HTTP ' . (int)($r['_http'] ?? 0);
}

/** Wallet balance at bulkmail.shop: ['balance' => float, 'currency' => 'USD'] or null. */
function bm_balance(?string $key = ''): ?array {
    $r = bm_call('GET', '/wallet/balance', null, $key, 12);
    if (empty($r['success']) || !isset($r['data']['balance'])) return null;
    return ['balance' => (float)$r['data']['balance'], 'currency' => (string)($r['data']['currency'] ?? 'USD')];
}

/** Checks the key with bulkmail.shop and, if it works, saves it and switches the connection ON. Returns [ok, message]. */
function bm_connect(PDO $pdo, string $key): array {
    $key = trim($key);
    if ($key === '' || strlen($key) > 300 || preg_match('/\s/', $key)) return [false, 'Paste the API key (no spaces).'];
    $r = bm_call('GET', '/wallet/balance', null, $key, 15);
    if (empty($r['success'])) {
        if ((int)$r['_http'] === 401 || (int)$r['_http'] === 403) return [false, 'bulkmail.shop rejected this API key. Check it and try again.'];
        return [false, 'Could not connect: ' . bm_err($r)];
    }
    save_setting('bm_key', $key);
    save_setting('bm_connected', '1');
    save_setting('bm_last_error', '');
    save_setting('bm_sync_ts', '0');
    bm_sync_stock($pdo, true);
    bm_sync_info($pdo, true);
    return [true, 'Connected to bulkmail.shop.'];
}

/** Forgets the key and switches the connection OFF. Linked products show "out of stock" until you connect again. */
function bm_disconnect(PDO $pdo): void {
    save_setting('bm_connected', '0');
    save_setting('bm_key', '');
    // API stock is gone; local (own) stock of linked products stays sellable.
    $pdo->exec('UPDATE products SET sup_stock = 0, stock = (SELECT COUNT(*) FROM stock_items WHERE product_id = products.id AND order_id IS NULL) WHERE supplier_id IS NOT NULL');
}

/** Customers must never learn who the supplier is: the supplier's name/domain is removed from any text copied into the shop. */
function bm_clean(string $t): string {
    $t = preg_replace('~(https?://)?(www\.)?bulk\s*mail(\.shop)?(v\d+)?~i', '', $t);
    return trim(preg_replace('/[ \t]{2,}/', ' ', $t));
}

/**
 * "Possible Domain" list of a supplier product as plain text ("a.com, b.net"), or ''.
 * 1) any field whose name contains "domain" (also nested), 2) text like "Possible Domain (a.com)" inside description / name / sku.
 * When it was found inside the description text, that text is removed from $desc (the shop shows it on its own line instead).
 */
function bm_find_domains(array $d, string &$desc): string {
    $out = [];
    $add = function ($v) use (&$add, &$out) {
        if (is_array($v)) { foreach ($v as $x) $add($x); return; }
        if (!is_scalar($v)) return;
        foreach (preg_split('/[\s,;|]+/', (string)$v) as $t) {
            $t = trim($t, " \t()[]\"'");
            if ($t !== '' && preg_match('/^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/i', $t)) $out[strtolower($t)] = $t;
        }
    };
    $scan = function (array $a) use (&$scan, $add) {
        foreach ($a as $k => $v) {
            if (is_string($k) && stripos($k, 'domain') !== false) $add($v);
            elseif (is_array($v)) $scan($v);
        }
    };
    $scan($d);
    if (!$out) {
        foreach (['description', 'name', 'sku'] as $f) {
            $txt = (string)($d[$f] ?? '');
            if ($txt !== '' && preg_match('/possible\s*domains?\s*[:(\-]?\s*\(?([^)\n]+)\)?/i', $txt, $m)) {
                $add($m[1]);
                if ($out && $f === 'description') $desc = trim(preg_replace('/[ \t]{2,}/', ' ', str_ireplace($m[0], '', $desc)));
                if ($out) break;
            }
        }
    }
    return mb_substr(implode(', ', array_values($out)), 0, 255);
}

/**
 * {supplier product ID => possible domain} read from the public bulkmail.shop pages, where every product card shows
 * "ID: 513 · Possible Domain (0r9.org)". Used when the API answer carries no domain. Cached 60 s (and per request).
 */
function bm_site_domains(): array {
    static $mem = null;
    if ($mem !== null) return $mem;
    $mem = [];
    try {
        $cached = json_decode(setting('bm_site_dom', ''), true);
        if (is_array($cached) && time() - (int)($cached['t'] ?? 0) < 60 && is_array($cached['m'] ?? null)) return $mem = $cached['m'];
    } catch (Throwable $ex) {}
    $origin = preg_replace('~/api(/v\d+)?$~i', '', bm_base());
    foreach ([$origin . '/products', $origin . '/'] as $page) {
        $ch = curl_init($page);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 7, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
        ]);
        $html = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($html === false || $code !== 200) continue;
        $text = preg_replace('/<[^>]+>/', ' ', (string)$html);                       // drop tags
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');          // &middot; etc.
        $text = preg_replace('/\s+/u', ' ', $text);
        if (preg_match_all('/ID:\s*(\d+)\D{0,12}?Possible\s*Domain\s*\(([^)]*)\)/iu', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { $d = trim($m[2]); if ($d !== '') $mem[(int)$m[1]] = mb_substr($d, 0, 255); }
        }
        if ($mem) break;
    }
    if ($mem) { try { save_setting('bm_site_dom', json_encode(['t' => time(), 'm' => $mem])); } catch (Throwable $ex) {} }
    return $mem;
}

/** One supplier product row from the API -> shop-ready text (name max 80, description max 500, supplier name removed). */
function bm_norm(array $d, int $fallbackId = 0): array {
    $desc = preg_replace('~<br\s*/?>~i', "\n", (string)($d['description'] ?? ''));
    $desc = bm_clean(str_replace(["\r\n", "\r"], "\n", $desc));
    $dom  = bm_clean(bm_find_domains($d, $desc));   // shown on its own "Possible Domain (...)" line on the product card
    return [
        'id'          => (int)($d['id'] ?? $fallbackId),
        'name'        => mb_substr(bm_clean((string)($d['name'] ?? '')), 0, 80),
        'description' => mb_substr($desc, 0, 500),
        'domains'     => $dom,
        'price'       => (float)($d['price'] ?? 0),
        'stock'       => (int)($d['stock_quantity'] ?? 0),
    ];
}

/** One supplier product, normalised: ['id','name','description','price','stock'] or ['error' => text]. Needs no API key. */
function bm_product(int $id): array {
    if ($id <= 0) return ['error' => 'Enter a valid product ID.'];
    $r = bm_call('GET', '/products/' . $id, null, null, 12);
    if (empty($r['success']) || empty($r['data']) || !is_array($r['data'])) {
        if ((int)$r['_http'] === 404) return ['error' => 'No product with ID ' . $id . ' at bulkmail.shop.'];
        return ['error' => 'Could not read the product: ' . bm_err($r)];
    }
    $n = bm_norm($r['data'], $id);
    if ($n['domains'] === '') $n['domains'] = bm_clean(bm_site_domains()[$id] ?? '');   // API has no domain: read it from the website like the bot does
    return $n;
}

/**
 * Keeps name + description (incl. possible domains) of linked products equal to bulkmail.shop, and refreshes their stock too.
 * Name / description are only changed where the "auto-sync" box is ticked, so text the admin wrote by hand is never overwritten.
 * One list request covers up to 100 products. Runs at most once per BM_INFO_EVERY seconds unless $force. Never throws.
 */
function bm_sync_info(PDO $pdo, bool $force = false): void {
    try {
        if (!bm_connected()) return;
        if (!$force) {
            $ts = (int)$pdo->query("SELECT v FROM settings WHERE k = 'bm_info_ts'")->fetchColumn();
            if (time() - $ts < BM_INFO_EVERY) return;
        }
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('bm_info_ts', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)time()]);
        $rows = $pdo->query('SELECT id, name, description, sup_stock, sup_domain, supplier_id, sup_auto_name, sup_auto_desc FROM products WHERE supplier_id IS NOT NULL')->fetchAll();
        if (!$rows) return;
        $need = []; foreach ($rows as $row) $need[(int)$row['supplier_id']] = true;
        $found = []; $rawSample = null;
        for ($page = 1; $page <= 10 && count($found) < count($need); $page++) {
            $r = bm_call('GET', '/products?page=' . $page . '&per_page=100', null, null, 8);
            if (empty($r['success']) || !is_array($r['data'] ?? null)) { error_log('bulkmail info sync: ' . bm_err($r)); break; }
            foreach ($r['data'] as $d) {
                if (is_array($d) && isset($d['id']) && isset($need[(int)$d['id']])) {
                    $found[(int)$d['id']] = bm_norm($d);
                    if ($force && !isset($rawSample)) $rawSample = $d;   // shown in Admin > API Management so you can see exactly what bulkmail.shop sends
                }
            }
            if ($page >= (int)($r['meta']['total_pages'] ?? $page)) break;
        }
        $i = 0; $fetched = [];   // products missing from the list (hidden / on another page): ask for them one by one, a few per run
        foreach (array_keys(array_diff_key($need, $found)) as $sid) {
            if (++$i > 5) break;
            $one = bm_product((int)$sid);
            if (!isset($one['error'])) { $found[(int)$sid] = $one; $fetched[(int)$sid] = true; }
        }
        $siteDom = null;   // the API may carry no domain: read it from the public website (one request covers every product)
        foreach ($found as $sid => $fp) {
            if ($fp['domains'] !== '') continue;
            if ($siteDom === null) $siteDom = bm_site_domains();
            if (!empty($siteDom[$sid])) $found[$sid]['domains'] = bm_clean($siteDom[$sid]);
        }
        if ($rawSample) save_setting('bm_raw_sample', mb_substr(json_encode($rawSample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 3000));
        $upd = $pdo->prepare('UPDATE products SET name = ?, description = ?, sup_stock = ?, stock = ? + (SELECT COUNT(*) FROM stock_items WHERE product_id = products.id AND order_id IS NULL), sup_domain = ? WHERE id = ?');   // the list also carries the live stock: a second way to keep it right
        foreach ($rows as $row) {
            $sp = $found[(int)$row['supplier_id']] ?? null;
            if (!$sp) continue;
            $name = (string)$row['name']; $desc = (string)$row['description'];
            $stk = max(0, (int)$sp['stock']);
            $old = ($row['sup_domain'] !== null && $row['sup_domain'] !== '') ? (string)$row['sup_domain'] : null;
            $dom = $sp['domains'] !== '' ? $sp['domains'] : $old;   // follows the supplier; one failed read never wipes the last known domain
            // "auto name" ON: the product name = the Possible Domain (like the bot); without a domain it follows the supplier's name.
            // The admin can type his own name any time (that switches auto name OFF and the sync never touches it again).
            if ((int)$row['sup_auto_name'] === 1) {
                if ($dom !== null) $name = mb_substr($dom, 0, 40);
                elseif ($sp['name'] !== '') $name = $sp['name'];
            }
            if ((int)$row['sup_auto_desc'] === 1 && $sp['description'] !== '') $desc = $sp['description'];
            if ($name !== (string)$row['name'] || $desc !== (string)$row['description'] || $stk !== (int)$row['sup_stock'] || $dom !== $old) $upd->execute([$name, $desc, $stk, $stk, $dom, (int)$row['id']]);
        }
    } catch (Throwable $ex) { error_log('bulkmail info sync: ' . $ex->getMessage()); }
}

/**
 * Copies the live stock of every linked product into products.stock (so the shop shows the real number).
 * Runs at most once per BM_SYNC_EVERY seconds unless $force. Never throws.
 */
function bm_sync_stock(PDO $pdo, bool $force = false): void {
    try {
        if (!bm_connected()) return;
        if (!$force) {
            $ts = (int)$pdo->query("SELECT v FROM settings WHERE k = 'bm_sync_ts'")->fetchColumn();
            if (time() - $ts < BM_SYNC_EVERY) return;
        }
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('bm_sync_ts', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)time()]);   // set first: a slow API never makes every page wait
        $ids = $pdo->query('SELECT DISTINCT supplier_id FROM products WHERE supplier_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return;
        // API number goes to sup_stock; the shown stock = API + unsold local items
        $upd = $pdo->prepare('UPDATE products SET sup_stock = ?, stock = ? + (SELECT COUNT(*) FROM stock_items WHERE product_id = products.id AND order_id IS NULL) WHERE supplier_id = ?');
        foreach (array_chunk($ids, 50) as $chunk) {
            $r = bm_call('GET', '/stock/check?ids=' . implode(',', array_map('intval', $chunk)), null, null, 6);
            if (empty($r['success']) || !is_array($r['data'] ?? null)) {
                error_log('bulkmail stock sync: ' . bm_err($r));
                if ($force) save_setting('bm_last_error', date('Y-m-d H:i') . ' · live stock could not be refreshed: ' . bm_err($r));   // shown in Admin > API Management
                continue;
            }
            foreach ($r['data'] as $row) {
                if (!is_array($row) || !isset($row['product_id'])) continue;
                $n = max(0, (int)($row['stock_count'] ?? 0));
                $upd->execute([$n, $n, (int)$row['product_id']]);
            }
        }
    } catch (Throwable $ex) { error_log('bulkmail stock sync: ' . $ex->getMessage()); }
}

/** Delivery line in the shop's own format: "email:password" -> "email|password" (only the first colon, so passwords keep theirs). */
function bm_line($it): string {
    if (is_array($it)) return implode('|', array_map(fn($v) => is_scalar($v) ? (string)$v : '', array_values($it)));
    $s = trim((string)$it);
    if ($s === '' || strpos($s, '|') !== false) return $s;
    $p = strpos($s, ':');
    return $p === false ? $s : substr($s, 0, $p) . '|' . substr($s, $p + 1);
}

function bm_items(array $r): array {
    $d = $r['data'] ?? [];
    $items = $d['stock_items'] ?? $d['items'] ?? ($d['order']['stock_items'] ?? []);
    return is_array($items) ? array_values(array_filter(array_map('bm_line', $items), fn($l) => $l !== '')) : [];
}

/**
 * Buys the items for one shop order that is still "pending" and linked to a supplier product.
 * Returns ['status' => 'delivered'|'refunded'|'pending', 'msg' => text for the customer].
 */
function bm_fulfil(PDO $pdo, int $orderId): array {
    $st = $pdo->prepare("SELECT o.*, p.supplier_id FROM orders o JOIN products p ON p.id = o.product_id WHERE o.id = ?");
    $st->execute([$orderId]); $o = $st->fetch();
    if (!$o || $o['status'] !== 'pending' || !(int)$o['supplier_id']) return ['status' => 'pending', 'msg' => 'Your order is being processed.'];

    // Claim the order: only one request may ever send it to the supplier.
    $claim = $pdo->prepare("UPDATE orders SET supplier_order_id = 'sending' WHERE id = ? AND status = 'pending' AND supplier_order_id IS NULL");
    $claim->execute([$orderId]);
    if ($claim->rowCount() !== 1) return ['status' => 'pending', 'msg' => 'Your order is being processed.'];

    $qty = max(1, (int)$o['qty']);
    // Items already taken from our own (local) stock for this order: only the rest is bought at bulkmail.shop.
    $lq = $pdo->prepare('SELECT line FROM stock_items WHERE order_id = ? ORDER BY id'); $lq->execute([$orderId]);
    $localLines = $lq->fetchAll(PDO::FETCH_COLUMN);
    $apiQty = $qty - count($localLines);
    if ($apiQty < 1) {   // safety: everything was local
        $pdo->prepare("UPDATE orders SET status = 'delivered', delivery = ?, supplier_order_id = NULL WHERE id = ? AND status = 'pending'")->execute([implode("\n", $localLines), $orderId]);
        return ['status' => 'delivered', 'msg' => 'Purchase successful! Your items are ready below.'];
    }
    $r = bm_call('POST', '/orders', ['product_id' => (int)$o['supplier_id'], 'quantity' => $apiQty], '', 45);
    $http = (int)$r['_http'];

    if (!empty($r['success']) && !empty($r['data']) && is_array($r['data'])) {
        $sid = (string)($r['data']['id'] ?? $r['data']['order_number'] ?? 'unknown');
        $items = bm_items($r);
        if (!$items && isset($r['data']['id'])) {   // order made, items not in the answer: ask for them once
            $g = bm_call('GET', '/orders/' . (int)$r['data']['id'], null, '', 20);
            if (!empty($g['success'])) $items = bm_items($g);
        }
        if ($items) {
            if (count($items) < $apiQty) error_log('bulkmail order #' . $orderId . ': asked ' . $apiQty . ', got ' . count($items));
            $pdo->prepare("UPDATE orders SET status = 'delivered', delivery = ?, supplier_order_id = ? WHERE id = ? AND status = 'pending'")
                ->execute([implode("\n", array_merge($localLines, $items)), mb_substr($sid, 0, 40), $orderId]);   // local items first, then the API items
            bm_sync_stock($pdo, true);
            return ['status' => 'delivered', 'msg' => 'Purchase successful! Your items are ready below.'];
        }
        // paid at the supplier but nothing to show yet: keep the order for the admin, with the supplier's order number
        $pdo->prepare('UPDATE orders SET supplier_order_id = ? WHERE id = ?')->execute([mb_substr($sid, 0, 40), $orderId]);
        save_setting('bm_last_error', 'Order #' . $orderId . ' was placed at bulkmail.shop (their order ' . $sid . ') but no items came back. Open it there and deliver it by hand.');
        return ['status' => 'pending', 'msg' => 'Your order was placed and is being prepared. It will appear in your orders shortly.'];
    }

    // The supplier clearly said no (a client error): nothing was bought, so the customer gets the money back.
    $definitive = in_array($http, [400, 401, 402, 403, 404, 409, 422, 429], true);
    if ($definitive) {
        $why = bm_err($r);
        $outOfStock = stripos(json_encode($r), 'INSUFFICIENT_STOCK') !== false || stripos($why, 'stock') !== false;
        save_setting('bm_last_error', date('Y-m-d H:i') . ' · order #' . $orderId . ' refused by bulkmail.shop: ' . $why);
        error_log('bulkmail order #' . $orderId . ' refused: ' . $why);
        if ($outOfStock) { try { bm_sync_stock($pdo, true); } catch (Throwable $e) {} }
        with_tx($pdo, function (PDO $pdo) use ($orderId) {
            $q = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending' FOR UPDATE"); $q->execute([$orderId]);
            if (!($ord = $q->fetch())) return;
            $pdo->prepare("UPDATE orders SET status = 'cancelled', supplier_order_id = NULL WHERE id = ?")->execute([$orderId]);
            $pdo->prepare('UPDATE stock_items SET order_id = NULL WHERE order_id = ?')->execute([$orderId]);   // local items taken for this order go back on the shelf
            stock_sync($pdo, (int)$ord['product_id']);
            $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$ord['price'], $ord['user_id']]);
            add_tx($pdo, (int)$ord['user_id'], 'refund', (float)$ord['price'], 'Refund for order #' . $orderId);
        });
        return ['status' => 'refunded', 'msg' => $outOfStock
            ? 'Sorry, this product just ran out of stock. Your money was refunded.'
            : 'This product is temporarily unavailable. Your money was refunded.'];
    }

    // No clear answer (timeout / server error): the supplier may or may not have charged. Never refund or resend automatically.
    save_setting('bm_last_error', date('Y-m-d H:i') . ' · order #' . $orderId . ' got no clear answer from bulkmail.shop (' . bm_err($r) . '). Check your orders at bulkmail.shop before delivering or cancelling it here.');
    error_log('bulkmail order #' . $orderId . ' unclear: ' . bm_err($r));
    return ['status' => 'pending', 'msg' => 'Your order was received and is being processed. It will appear in your orders shortly.'];
}
