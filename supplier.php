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
    $r = bm_call('GET', '/products/' . $id, null, null, 12);
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
    ];
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
        $upd = $pdo->prepare('UPDATE products SET stock = ? WHERE supplier_id = ?');
        foreach (array_chunk($ids, 50) as $chunk) {
            $r = bm_call('GET', '/stock/check?ids=' . implode(',', array_map('intval', $chunk)), null, null, 6);
            if (empty($r['success']) || !is_array($r['data'] ?? null)) { error_log('bulkmail stock sync: ' . bm_err($r)); continue; }
            foreach ($r['data'] as $row) {
                if (!is_array($row) || !isset($row['product_id'])) continue;
                $upd->execute([max(0, (int)($row['stock_count'] ?? 0)), (int)$row['product_id']]);
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
