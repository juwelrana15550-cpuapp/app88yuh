<?php
/* Supplier API integration (https://<supplier>/api/v1).
   File: supplier_hot.php. Needs lib.php helpers: db(), setting(), e(), with_tx(). Settings keys:
   suphot_url, suphot_key, suphot_auto. */

/** Add the two link columns to products (runs once, harmless if they exist). Never call inside a transaction. */
function suphot_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    foreach (['supplier_type', 'supplier_account'] as $c) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = ?");
        $st->execute([$c]);
        if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE products ADD COLUMN $c VARCHAR(40) NULL DEFAULT NULL");
    }
    $dt = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'delivery'")->fetchColumn();
    if ($dt && !in_array(strtolower((string)$dt), ['mediumtext', 'longtext'], true)) {
        $pdo->exec('ALTER TABLE orders MODIFY delivery MEDIUMTEXT NULL'); // bulk orders can be large
    }
}

/** Call the supplier. Returns ['ok'=>true,'data'=>...] or ['ok'=>false,'error'=>...,'unknown'=>bool]. */
function suphot_call(string $method, string $path, array $query = [], ?array $body = null, int $timeout = 30): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP curl extension is not installed.'];
    $base = rtrim((string)setting('suphot_url'), '/');
    $key  = (string)setting('suphot_key');
    if ($base === '' || $key === '') return ['ok' => false, 'error' => 'Supplier API URL / key not set (Admin > Supplier API).'];

    $ch  = curl_init($base . $path . '?' . http_build_query($query + ['api_key' => $key]));
    $hdr = ['Accept: application/json'];
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false];
    if ($method === 'POST') {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
        $hdr[] = 'Content-Type: application/json';
    }
    $opt[CURLOPT_HTTPHEADER] = $hdr;
    curl_setopt_array($ch, $opt);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'unknown' => true, 'error' => 'Could not reach supplier: ' . str_replace($key, '***', $cerr)];
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) return ['ok' => false, 'unknown' => $http < 400, 'error' => 'Unexpected supplier response (HTTP ' . $http . ').'];
    if ($http >= 400 || ($j['status'] ?? '') !== 'success') {
        return ['ok' => false, 'error' => (string)($j['message'] ?? ('Supplier error (HTTP ' . $http . ').'))];
    }
    return ['ok' => true, 'data' => $j['data'] ?? []];
}

/** Supplier product list, cached in the admin session for 5 minutes. */
function suphot_catalog(): array {
    $sig = md5((string)setting('suphot_url') . (string)setting('suphot_key'));
    if (!empty($_SESSION['sup_cat']) && $_SESSION['sup_cat']['t'] > time() - 300 && $_SESSION['sup_cat']['s'] === $sig) {
        return $_SESSION['sup_cat']['r'];
    }
    $r = suphot_call('GET', '/products', [], null, 12);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'items' => []];
    $out = ['ok' => true, 'items' => is_array($r['data']['products'] ?? null) ? $r['data']['products'] : []];
    $_SESSION['sup_cat'] = ['t' => time(), 's' => $sig, 'r' => $out];
    return $out;
}

/** <option> list for the "Supplier product" dropdown (value = "product_type|account_type"). */
function suphot_options(array $products): string {
    $h = '<option value="">— Not linked (manual delivery) —</option>';
    $seen = [];
    $cat = ((string)setting('suphot_url') !== '' && (string)setting('suphot_key') !== '') ? suphot_catalog() : ['items' => []];
    foreach ($cat['items'] as $it) {
        $v = ($it['product_type'] ?? '') . '|' . ($it['account_type'] ?? '');
        if ($v === '|' || isset($seen[$v])) continue;
        $seen[$v] = 1;
        $label = ($it['name'] ?? $v) . ' · ' . $v . ' · $' . ($it['price'] ?? '?') . ' · stock ' . ($it['stock'] ?? '?');
        $h .= '<option value="' . e($v) . '">' . e($label) . '</option>';
    }
    foreach ($products as $p) { // keep already-linked values selectable even if the catalogue is unreachable
        if (empty($p['supplier_type'])) continue;
        $v = $p['supplier_type'] . '|' . $p['supplier_account'];
        if (!isset($seen[$v])) { $seen[$v] = 1; $h .= '<option value="' . e($v) . '">' . e($v) . ' (linked)</option>'; }
    }
    return $h;
}

/**
 * Buy the order's quantity from the supplier for a pending order and mark it delivered.
 * Returns [bool ok, string message]. On any failure the order stays pending (nothing is lost).
 */
function suphot_fulfill_order(int $oid): array {
    $pdo = db();
    $res = [false, 'Order not found or already handled.'];
    with_tx($pdo, function (PDO $pdo) use ($oid, &$res) {
        $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending' FOR UPDATE");
        $st->execute([$oid]);
        if (!($o = $st->fetch())) return;

        $ps = $pdo->prepare('SELECT supplier_type, supplier_account FROM products WHERE id = ?');
        $ps->execute([$o['product_id']]);
        $p = $ps->fetch();
        if (!$p || empty($p['supplier_type']) || empty($p['supplier_account'])) {
            $res = [false, 'This product is not linked to a supplier product.'];
            return;
        }

        $qty = max(1, (int)($o['qty'] ?? 1));
        $r = suphot_call('POST', '/purchase', [], [
            'product_type' => $p['supplier_type'],
            'account_type' => $p['supplier_account'],
            'quantity'     => $qty,
        ], 90);
        if (!$r['ok']) {
            $res = [false, $r['error'] . (!empty($r['unknown']) ? ' Result unknown: check your supplier order history before retrying.' : '')];
            return;
        }

        $acc = $r['data']['accounts'] ?? [];
        $acc = is_array($acc) ? array_values(array_filter(array_map('strval', $acc), 'strlen')) : [];
        $sid = $r['data']['order_id'] ?? '?';
        if (!$acc) {
            error_log('SUPPLIER: order #' . $oid . ' purchased (supplier order ' . $sid . ') but no accounts returned');
            $res = [false, 'Supplier returned no accounts (supplier order #' . $sid . '). Check the supplier panel. Order left pending.'];
            return;
        }

        if (count($acc) < $qty) { // paid but short: do not mark delivered, keep the goods in the log
            error_log('SUPPLIER: order #' . $oid . ' wanted ' . $qty . ' got ' . count($acc) . ' (supplier order ' . $sid . ') | ' . implode(' ; ', $acc));
            $res = [false, 'Supplier returned only ' . count($acc) . ' of ' . $qty . ' accounts (supplier order #' . $sid . '). Order left pending: deliver manually (accounts are in the server log).'];
            return;
        }
        try {
            $pdo->prepare("UPDATE orders SET status='delivered', delivery=? WHERE id=?")
                ->execute([implode("\n", $acc), $oid]);
        } catch (Throwable $ex) {
            // Paid but could not save: keep the goods in the log so they can be delivered by hand.
            error_log('SUPPLIER: order #' . $oid . ' paid (supplier order ' . $sid . ') but DB update failed: ' . $ex->getMessage() . ' | ' . implode(' ; ', $acc));
            throw $ex;
        }
        $res = [true, 'Order #' . $oid . ' delivered via supplier.'];
    });
    return $res;
}

/** Call right after a new order is committed. Does nothing unless "auto-deliver" is on; failures leave the order pending. */
function suphot_auto_fulfill(int $oid): void {
    if (setting('suphot_auto') !== '1') return;
    try { suphot_fulfill_order($oid); } catch (Throwable $e) { error_log('SUPPLIER AUTO: ' . $e->getMessage()); }
}
