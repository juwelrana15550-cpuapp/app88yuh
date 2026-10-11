<?php
if (!defined('SCHEMA_VERSION')) { http_response_code(404); exit; }   // never run directly from the browser
/**
 * bulkmail.shop API v2 integration (supplier).
 *
 * How it works
 *  - Admin > API Management: paste the bulkmail.shop API key -> Connect / Disconnect.
 *  - Admin > Products: type the supplier "Product ID" -> the product is linked.
 *  - Stock shown in your shop = live stock at bulkmail.shop (refreshed at most once a minute).
 *  - When a customer buys a linked product: the customer's wallet is charged, the order is placed at bulkmail.shop with
 *    your API key, and the delivered items (email:password) are shown to the customer instantly.
 *  - If bulkmail.shop clearly refuses the order (no stock, no balance, bad key ...) the customer is refunded automatically.
 *    If the answer is unclear (timeout / server error) the order stays "pending" for you to check - it is never refunded
 *    or re-sent automatically, so nothing can be bought twice.
 *
 * Docs: https://docs.bulkmail.shop  (auth: X-API-Key header, base URL https://bulkmail.shop/api/v2)
 */

const BM_DEFAULT_BASE = 'https://bulkmail.shop/api/v2';
const BM_SYNC_EVERY   = 15;    // seconds between live-stock refreshes (one request covers 50 products)
const BM_DETAIL_EVERY = 180;   // seconds between 'possible domain' refreshes

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
    $headers = ['Accept: application/json', 'User-Agent: Mozilla/5.0 (compatible; ShopSync/1.0)'];   // some hosts refuse requests that have no User-Agent
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
    bm_sync_stock($pdo, true, true);
    return [true, 'Connected to bulkmail.shop.'];
}

/** Forgets the key and switches the connection OFF. Linked products show "out of stock" until you connect again. */
function bm_disconnect(PDO $pdo): void {
    save_setting('bm_connected', '0');
    save_setting('bm_key', '');
    $pdo->exec('UPDATE products SET stock = 0 WHERE supplier_id IS NOT NULL');
}

/** Customers must never learn who the supplier is: the supplier's name/domain is removed from any text copied into the shop. */
function bm_clean(string $t): string {
    $t = preg_replace('~(https?://)?(www\.)?bulk\s*mail(\.shop)?(v\d+)?~i', '', $t);
    return trim(preg_replace('/[ \t]{2,}/', ' ', $t));
}

/** One supplier product, normalised: ['id','name','description','price','stock'] or ['error' => text]. Needs no API key. */
function bm_product(int $id): array {
    if ($id <= 0) return ['error' => 'Enter a valid product ID.'];
    $r = bm_call('GET', '/products/' . $id, null, null, 8);
    if (empty($r['success']) || empty($r['data']) || !is_array($r['data'])) {
        if ((int)$r['_http'] === 404) return ['error' => 'No product with ID ' . $id . ' at bulkmail.shop.'];
        return ['error' => 'Could not read the product: ' . bm_err($r)];
    }
    $d = $r['data'];
    return [
        'id'          => (int)($d['id'] ?? $id),
        'name'        => bm_clean((string)($d['name'] ?? '')),
        'description' => bm_clean((string)($d['description'] ?? '')),
        'price'       => (float)($d['price'] ?? 0),
        'stock'       => (int)($d['stock_quantity'] ?? 0),
        'domain'      => bm_domain($d),
    ];
}

/** The "possible domain" of a supplier product: a field with "domain" in its name, else the text "Possible Domain (x.com)" inside the name / description / sku. '' if none. */
function bm_domain(array $d): string {
    foreach ($d as $k => $v) {
        if (is_string($v) && stripos((string)$k, 'domain') !== false && trim($v) !== '') return mb_substr(trim(preg_replace('/^possible\s*domain\s*/i', '', trim($v), 1), " ()"), 0, 100);
        if (is_array($v) && stripos((string)$k, 'domain') !== false && $v) { $f = reset($v); if (is_string($f) && trim($f) !== '') return mb_substr(trim($f), 0, 100); }
    }
    $txt = implode("\n", array_filter([(string)($d['name'] ?? ''), (string)($d['description'] ?? ''), (string)($d['sku'] ?? '')], 'strlen'));
    if (preg_match('/possible\s*domains?\s*[:\-]?\s*\(?\s*([a-z0-9][a-z0-9.\-]*\.[a-z]{2,})/i', $txt, $m)) return mb_substr(strtolower($m[1]), 0, 100);
    return '';
}

/** Raw answers for one product ID (Admin > API Management > "Check a product ID"), so you can see exactly what the supplier sends. */
function bm_debug(int $id): string {
    $out = '';
    foreach ([['/products/' . $id, 'product details'], ['/stock/check?ids=' . $id, 'stock check'], ['/stock/' . $id, 'stock']] as [$path, $label]) {
        $r = bm_call('GET', $path, null, '', 15);
        $out .= '# ' . $label . '  GET ' . $path . '  ->  HTTP ' . (int)$r['_http'] . "\n" . mb_substr(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 3000) . "\n\n";
    }
    return $out;
}

/**
 * Keeps linked products up to date: live stock (every BM_SYNC_EVERY s) and "possible domain" (every BM_DETAIL_EVERY s).
 * Reads the connection from the database directly (never a stale per-request copy). Never throws.
 */
function bm_sync_stock(PDO $pdo, bool $force = false, bool $withDetails = false): void {
    try {
        $cfg = $pdo->query("SELECT k, v FROM settings WHERE k IN ('bm_connected','bm_key','bm_sync_ts','bm_dom_ts')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $key = trim((string)($cfg['bm_key'] ?? ''));
        if (($cfg['bm_connected'] ?? '0') !== '1' || $key === '') return;
        $now = time();
        $doStock  = $force || $now - (int)($cfg['bm_sync_ts'] ?? 0) >= BM_SYNC_EVERY;
        $doDetail = $withDetails || $now - (int)($cfg['bm_dom_ts'] ?? 0) >= BM_DETAIL_EVERY;
        if (!$doStock && !$doDetail) return;
        $mark = $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");   // set first: a slow API never makes every page wait
        if ($doStock)  $mark->execute(['bm_sync_ts', (string)$now]);
        if ($doDetail) $mark->execute(['bm_dom_ts', (string)$now]);
        $ids = array_map('intval', $pdo->query('SELECT DISTINCT supplier_id FROM products WHERE supplier_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return;
        if ($doStock)  bm_sync_stock_now($pdo, $key, $ids);
        if ($doDetail) bm_sync_details($pdo, $key, $ids);
    } catch (Throwable $ex) { error_log('supplier sync: ' . $ex->getMessage()); }
}

function bm_sync_stock_now(PDO $pdo, string $key, array $ids): void {
    $upd = $pdo->prepare('UPDATE products SET stock = ? WHERE supplier_id = ?');
    $done = []; $why = '';
    foreach (array_chunk($ids, 50) as $chunk) {
        $r = bm_call('GET', '/stock/check?ids=' . implode(',', $chunk), null, $key, 8);
        if (!empty($r['success']) && is_array($r['data'] ?? null)) {
            foreach ($r['data'] as $row) {
                if (!is_array($row) || !isset($row['product_id'])) continue;
                $upd->execute([max(0, (int)($row['stock_count'] ?? $row['stock_quantity'] ?? 0)), (int)$row['product_id']]);
                $done[(int)$row['product_id']] = 1;
            }
        } else { $why = bm_err($r); }
    }
    // Anything the bulk call did not return: ask one by one (limited, so a problem never turns into hundreds of requests).
    $n = 0;
    foreach ($ids as $id) {
        if (isset($done[$id]) || $n++ >= 20) continue;
        $r = bm_call('GET', '/stock/' . $id, null, $key, 8);
        $c = $r['data']['stock_count'] ?? null;
        if ($c === null) { $r = bm_call('GET', '/products/' . $id, null, $key, 8); $c = $r['data']['stock_quantity'] ?? null; }
        if ($c !== null) { $upd->execute([max(0, (int)$c), $id]); $done[$id] = 1; } elseif ($why === '') $why = bm_err($r);
    }
    $missing = count($ids) - count($done);
    if ($missing > 0) {
        save_setting('bm_last_error', date('Y-m-d H:i') . ' · live stock could not be read for ' . $missing . ' linked product(s): ' . $why . ' (Admin > API Management > "Check a product ID" shows the raw answer)');
        error_log('supplier stock sync: ' . $missing . ' missing: ' . $why);
    } elseif (strpos((string)$pdo->query("SELECT v FROM settings WHERE k = 'bm_last_error'")->fetchColumn(), 'live stock could not') !== false) {
        save_setting('bm_last_error', '');
    }
}

/** Refreshes the "possible domain" of linked products from the supplier's product list (100 per request). */
function bm_sync_details(PDO $pdo, string $key, array $ids): void {
    $need = array_flip($ids);
    $upd = $pdo->prepare('UPDATE products SET supplier_domain = ? WHERE supplier_id = ?');
    for ($pg = 1; $pg <= 10 && $need; $pg++) {
        $r = bm_call('GET', '/products?per_page=100&page=' . $pg, null, $key, 15);
        if (empty($r['success']) || !is_array($r['data'] ?? null)) break;
        foreach ($r['data'] as $d) {
            if (!is_array($d) || !isset($d['id']) || !isset($need[(int)$d['id']])) continue;
            $dom = bm_domain($d);
            $upd->execute([$dom !== '' ? $dom : null, (int)$d['id']]);
            unset($need[(int)$d['id']]);
        }
        $tp = (int)($r['meta']['total_pages'] ?? 0);
        if (count($r['data']) < 100 || ($tp && $pg >= $tp)) break;
    }
    $n = 0;
    foreach (array_keys($need) as $id) {   // not on those pages: ask one by one (limited)
        if ($n++ >= 15) break;
        $r = bm_call('GET', '/products/' . $id, null, $key, 8);
        if (!empty($r['success']) && is_array($r['data'] ?? null)) { $dom = bm_domain($r['data']); $upd->execute([$dom !== '' ? $dom : null, $id]); }
    }
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
    $r = bm_call('POST', '/orders', ['product_id' => (int)$o['supplier_id'], 'quantity' => $qty], '', 45);
    $http = (int)$r['_http'];

    if (!empty($r['success']) && !empty($r['data']) && is_array($r['data'])) {
        $sid = (string)($r['data']['id'] ?? $r['data']['order_number'] ?? 'unknown');
        $items = bm_items($r);
        if (!$items && isset($r['data']['id'])) {   // order made, items not in the answer: ask for them once
            $g = bm_call('GET', '/orders/' . (int)$r['data']['id'], null, '', 20);
            if (!empty($g['success'])) $items = bm_items($g);
        }
        if ($items) {
            if (count($items) < $qty) error_log('bulkmail order #' . $orderId . ': asked ' . $qty . ', got ' . count($items));
            $pdo->prepare("UPDATE orders SET status = 'delivered', delivery = ?, supplier_order_id = ? WHERE id = ? AND status = 'pending'")
                ->execute([implode("\n", $items), mb_substr($sid, 0, 40), $orderId]);
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
