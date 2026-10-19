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
const BM_DETAIL_EVERY = 120;  // seconds between name/description/domain refresh rounds
const BM_DETAIL_BATCH = 6;    // products refreshed per round (spreads the API calls out; the public endpoints allow 20 requests / minute)
const BM_SYNC_EVERY   = 60;   // seconds between stock refreshes (the public stock endpoints allow 20 requests / minute)

/** BDT per 1 USD (admin > Supplier API). Supplier prices are in USD, the shop is in ৳. 0 = not set -> price sync is skipped. */
function bm_rate(): float { return max(0.0, (float)setting('bm_rate', '0')); }

/** Profit % added on top of the supplier price for ALL supplier-linked products (admin > Supplier API). */
function bm_markup(): float { return min(1000.0, max(0.0, (float)setting('bm_markup', '0'))); }

/** When on, every supplier-linked product follows the supplier price (no per-product tick needed). */
function bm_price_all(): bool { return setting('bm_price_all', '0') === '1'; }

/** Adds the two price-sync columns to `products` once (sup_auto_price, sup_markup). Never throws. */
function bm_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (setting('bm_schema_v', '') === '2') return;
        $have = $pdo->query("SHOW COLUMNS FROM products LIKE 'sup_auto_price'")->fetchColumn();
        if (!$have) $pdo->exec("ALTER TABLE products ADD COLUMN sup_auto_price TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN sup_markup DECIMAL(7,2) NOT NULL DEFAULT 0");
        save_setting('bm_schema_v', '2');
    } catch (Throwable $ex) { error_log('bulkmail schema: ' . $ex->getMessage()); }
}

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
    return [true, 'Connected to bulkmail.shop.'];
}

/** Forgets the key and switches the connection OFF. Linked products show "out of stock" until you connect again. */
function bm_disconnect(PDO $pdo): void {
    save_setting('bm_connected', '0');
    save_setting('bm_key', '');
    $pdo->exec('UPDATE products SET sup_stock = 0, stock = (SELECT COUNT(*) FROM stock_items WHERE product_id = products.id AND order_id IS NULL) WHERE supplier_id IS NOT NULL');
}

/** Customers must never learn who the supplier is: the supplier's name/domain is removed from any text copied into the shop. */
function bm_clean(string $t): string {
    $t = preg_replace('~(https?://)?(www\.)?bulk\s*mail(\.shop)?(v\d+)?~i', '', $t);
    return trim(preg_replace('/[ \t]{2,}/', ' ', $t));
}

/** Normalises one supplier product row (from /products or /products/{id}) to ['id','name','description','price','stock','domain']. */
function bm_norm(array $d, int $fallbackId = 0): array {
    // description_text = markup already removed by the API; fall back to stripping the stored description ourselves
    $txt = trim((string)($d['description_text'] ?? ''));
    if ($txt === '') {
        $raw = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", (string)($d['description'] ?? ''));
        $txt = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $d = array_merge($d, ['description' => $txt]);
    return [
        'id'          => (int)($d['id'] ?? $fallbackId),
        'name'        => bm_clean((string)($d['name'] ?? '')),
        'description' => bm_clean($txt),
        'price'       => (float)($d['price'] ?? 0),                              // USD, your own price when the key is sent
        'stock'       => (int)($d['stock_quantity'] ?? $d['stock'] ?? 0),
        'domain'      => bm_domain_text($d),
    ];
}

/** One supplier product, normalised, or ['error' => text]. Sends the saved key (when connected) so the price is YOUR price. */
function bm_product(int $id, int $timeout = 12): array {
    if ($id <= 0) return ['error' => 'Enter a valid product ID.'];
    $r = bm_call('GET', '/products/' . $id, null, bm_connected() ? '' : null, $timeout);
    if (empty($r['success']) || empty($r['data']) || !is_array($r['data'])) {
        if ((int)$r['_http'] === 404) return ['error' => 'No product with ID ' . $id . ' at bulkmail.shop.'];
        return ['error' => 'Could not read the product: ' . bm_err($r)];
    }
    return bm_norm($r['data'], $id);
}

/** Reads the supplier catalogue 100 products per request and returns the wanted ids => normalised product (1 call instead of 1 per product). */
function bm_catalogue(array $want, int $maxPages = 10): array {
    $want = array_flip(array_map('intval', $want));
    $out = [];
    $key = bm_connected() ? '' : null;
    for ($p = 1; $p <= $maxPages && count($out) < count($want); $p++) {
        $r = bm_call('GET', '/products?per_page=100&page=' . $p, null, $key, 15);
        if (empty($r['success']) || !is_array($r['data'] ?? null)) { error_log('bulkmail catalogue: ' . bm_err($r)); break; }
        foreach ($r['data'] as $d) {
            if (is_array($d) && isset($d['id']) && isset($want[(int)$d['id']])) $out[(int)$d['id']] = bm_norm($d);
        }
        if ($p >= (int)($r['meta']['total_pages'] ?? 1)) break;
    }
    return $out;
}

/** "Possible Domain" list of a supplier product as plain text ('' when the listing has none). */
function bm_domain_text(array $d): string {
    foreach (['possible_domains', 'possible_domain', 'domains', 'domain_list', 'email_domains', 'domain'] as $k) {
        if (!isset($d[$k])) continue;
        $v = $d[$k];
        if (is_array($v)) $v = implode(', ', array_filter(array_map(fn($x) => is_scalar($x) ? trim((string)$x) : '', $v), 'strlen'));
        $v = trim(bm_clean((string)$v));
        if ($v !== '') return mb_substr($v, 0, 255);
    }
    // some listings write it inside the description: "Possible Domain (a.com, b.com)"
    foreach (['description_text', 'description'] as $k) {
        $t = trim(strip_tags((string)($d[$k] ?? '')));
        if ($t !== '' && preg_match('/possible\s+domains?\s*[:(\-]\s*([^)\n]+)/i', $t, $m)) return mb_substr(trim(bm_clean($m[1])), 0, 255);
    }
    return '';
}

/**
 * Copies the live stock of every linked product into products.stock (so the shop shows the real number).
 * Runs at most once per BM_SYNC_EVERY seconds unless $force. Never throws.
 */
function bm_sync_stock(PDO $pdo, bool $force = false): void {
    try {
        if (!bm_connected()) return;
        bm_schema($pdo);
        bm_sync_details_soon($pdo);   // name / description / domain of products that follow the supplier (own timer)
        if (!$force) {
            $ts = (int)$pdo->query("SELECT v FROM settings WHERE k = 'bm_sync_ts'")->fetchColumn();
            if (time() - $ts < BM_SYNC_EVERY) return;
        }
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('bm_sync_ts', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)time()]);   // set first: a slow API never makes every page wait
        $ids = $pdo->query('SELECT DISTINCT supplier_id FROM products WHERE supplier_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return;
        // Same rule as stock_sync() in lib.php: shown stock = unsold own items + live supplier stock (kept in sup_stock).
        $upd = $pdo->prepare('UPDATE products SET sup_stock = ?, stock = (SELECT COUNT(*) FROM stock_items WHERE product_id = products.id AND order_id IS NULL) + ? WHERE supplier_id = ?');
        foreach (array_chunk($ids, 50) as $chunk) {
            $r = bm_call('GET', '/stock/check?ids=' . implode(',', array_map('intval', $chunk)), null, null, 6);
            if (empty($r['success']) || !is_array($r['data'] ?? null)) { error_log('bulkmail stock sync: ' . bm_err($r)); continue; }
            $list = is_array($r['data']['products'] ?? null) ? $r['data']['products'] : $r['data'];   // v2: data.products[]
            foreach ($list as $row) {
                if (!is_array($row) || !empty($row['error'])) continue;
                $pid = $row['id'] ?? $row['product_id'] ?? null;
                if ($pid === null) continue;
                $sc = max(0, (int)($row['stock'] ?? $row['stock_count'] ?? $row['stock_quantity'] ?? 0));
                $upd->execute([$sc, $sc, (int)$pid]);
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

/**
 * Refreshes name, price, description and "Possible Domain" of linked products from bulkmail.shop.
 *  - name:                  only products with sup_auto_name = 1
 *  - description + domain:  only products with sup_auto_desc = 1
 *  - price:                 only products with sup_auto_price = 1  ->  supplier USD price x BDT rate x (1 + markup %)
 * A field is never blanked: if the supplier sends nothing for it, the shop keeps what it has.
 * All linked products are read with ONE catalogue request per 100 products. Returns how many products changed. Never throws.
 * ($limit is kept only so old callers still work.)
 */
function bm_sync_details(PDO $pdo, int $limit = BM_DETAIL_BATCH): int {
    $changed = 0;
    try {
        bm_schema($pdo);
        $all = bm_price_all();
        $rows = $pdo->query('SELECT id, supplier_id, name, description, price, sup_domain, sup_auto_name, sup_auto_desc, sup_auto_price, sup_markup FROM products
                             WHERE supplier_id IS NOT NULL AND (sup_auto_name = 1 OR sup_auto_desc = 1 OR sup_auto_price = 1' . ($all ? ' OR 1 = 1' : '') . ') ORDER BY id')->fetchAll();
        if (!$rows) return 0;
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['supplier_id'], $rows)));
        $cat = bm_catalogue($ids);
        $single = 0;   // anything the catalogue did not list: ask one by one (at most 5 per round)
        foreach ($ids as $sid) {
            if (isset($cat[$sid]) || $single >= 5) continue;
            $single++;
            $d = bm_product($sid, 6);
            if (!isset($d['error'])) $cat[$sid] = $d;
        }

        $rate = bm_rate();
        if ($rate <= 0 && array_filter($rows, fn($r) => $all || (int)$r['sup_auto_price'] === 1)) {
            save_setting('bm_last_error', 'Price sync is on for some products but the USD→৳ rate is not set (Admin > Supplier API).');
        }
        $upd = $pdo->prepare('UPDATE products SET name = ?, description = ?, price = ?, sup_domain = ? WHERE id = ?');
        foreach ($rows as $r) {
            $d = $cat[(int)$r['supplier_id']] ?? null;
            if (!$d) continue;
            $name = (string)$r['name']; $desc = (string)$r['description']; $dom = (string)$r['sup_domain']; $price = (float)$r['price'];
            if ((int)$r['sup_auto_name'] === 1 && $d['name'] !== '') $name = mb_substr($d['name'], 0, 80);
            if ((int)$r['sup_auto_desc'] === 1) {
                $t = $d['description'];
                if ($d['domain'] !== '') {
                    $dom = $d['domain'];
                    $t = trim(preg_replace('/\(?\s*possible\s+domains?\s*[:(\-][^)\n]*\)?/i', '', $t));   // shown on its own line, so not twice
                }
                if ($t !== '') $desc = mb_substr($t, 0, 500);
            }
            if (($all || (int)$r['sup_auto_price'] === 1) && $rate > 0 && $d['price'] > 0) {
                $np = round($d['price'] * $rate * (1 + bm_markup() / 100), 2);   // supplier USD x rate x (1 + profit %)
                if ($np > 0) $price = $np;
            }
            if ($name !== (string)$r['name'] || $desc !== (string)$r['description'] || $dom !== (string)$r['sup_domain'] || abs($price - (float)$r['price']) > 0.004) {
                $upd->execute([$name, $desc, $price, $dom === '' ? null : $dom, (int)$r['id']]);
                $changed++;
            }
        }
    } catch (Throwable $ex) { error_log('bulkmail details sync: ' . $ex->getMessage()); }
    return $changed;
}

/** Starts a details round at most every BM_DETAIL_EVERY seconds, after the page has been sent to the visitor when the server allows it. */
function bm_sync_details_soon(PDO $pdo): void {
    try {
        $ts = (int)$pdo->query("SELECT v FROM settings WHERE k = 'bm_detail_ts'")->fetchColumn();
        if (time() - $ts < BM_DETAIL_EVERY) return;
        save_setting('bm_detail_ts', (string)time());   // set first: concurrent visitors do not repeat the round
        register_shutdown_function(function () use ($pdo) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            bm_sync_details($pdo);
        });
    } catch (Throwable $ex) { error_log('bulkmail details sync: ' . $ex->getMessage()); }
}
