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
    // Supplier 2 (bulkmail.shop) link columns: normally exist already, added here only if missing.
    $extra = ['products' => ['supplier_id' => 'INT NULL DEFAULT NULL'], 'orders' => ['supplier_order_id' => 'VARCHAR(40) NULL DEFAULT NULL']];
    foreach ($extra as $tbl => $cols) foreach ($cols as $c => $def) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$tbl, $c]);
        if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE $tbl ADD COLUMN $c $def");
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
            $res = [false, $r['error'] . (!empty($r['unknown']) ? ' Result unknown: check your supplier order history before retrying.' : ''), !empty($r['unknown'])];   // [2] = outcome unclear (may have been charged)
            return;
        }

        $acc = $r['data']['accounts'] ?? [];
        $acc = is_array($acc) ? array_values(array_filter(array_map('strval', $acc), 'strlen')) : [];
        $sid = $r['data']['order_id'] ?? '?';
        if (!$acc) {
            error_log('SUPPLIER: order #' . $oid . ' purchased (supplier order ' . $sid . ') but no accounts returned');
            $res = [false, 'Supplier returned no accounts (supplier order #' . $sid . '). Check the supplier panel. Order left pending.', true];
            return;
        }

        if (count($acc) < $qty) { // paid but short: do not mark delivered, keep the goods in the log
            error_log('SUPPLIER: order #' . $oid . ' wanted ' . $qty . ' got ' . count($acc) . ' (supplier order ' . $sid . ') | ' . implode(' ; ', $acc));
            $res = [false, 'Supplier returned only ' . count($acc) . ' of ' . $qty . ' accounts (supplier order #' . $sid . '). Order left pending: deliver manually (accounts are in the server log).', true];
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

/**
 * Copy the live stock of every Supplier 1 linked product into products.stock. At most once a minute unless $force. Never throws.
 * A linked product that is missing from the supplier catalogue is set to 0 (out of stock). If the supplier is unreachable nothing changes.
 */
function suphot_sync_stock(PDO $pdo, bool $force = false): void {
    try {
        if ((string)setting('suphot_url') === '' || (string)setting('suphot_key') === '') return;
        if (!$force && time() - (int)setting('suphot_sync_ts', '0') < 60) return;
        save_setting('suphot_sync_ts', (string)time());   // set first: a slow API never makes every page wait
        if (!$pdo->query('SELECT 1 FROM products WHERE supplier_type IS NOT NULL AND supplier_id IS NULL LIMIT 1')->fetchColumn()) return;
        $r = suphot_call('GET', '/products', [], null, 6);
        if (!$r['ok'] || !is_array($r['data']['products'] ?? null)) { error_log('supplier1 stock sync: ' . ($r['error'] ?? 'bad answer')); return; }
        $stock = [];
        foreach ($r['data']['products'] as $it) $stock[($it['product_type'] ?? '') . '|' . ($it['account_type'] ?? '')] = max(0, (int)($it['stock'] ?? 0));
        $upd = $pdo->prepare('UPDATE products SET stock = ? WHERE supplier_type = ? AND supplier_account = ? AND supplier_id IS NULL');
        foreach ($pdo->query('SELECT DISTINCT supplier_type, supplier_account FROM products WHERE supplier_type IS NOT NULL AND supplier_account IS NOT NULL AND supplier_id IS NULL')->fetchAll() as $row) {
            $upd->execute([$stock[$row['supplier_type'] . '|' . $row['supplier_account']] ?? 0, $row['supplier_type'], $row['supplier_account']]);
        }
    } catch (Throwable $e) { error_log('supplier1 stock sync: ' . $e->getMessage()); }
}

/**
 * Call right after a new order is committed. Returns 'off' (auto-deliver disabled: order stays pending for the admin),
 * 'delivered', 'failed' (supplier clearly refused: nothing was bought) or 'unclear' (may have been charged: leave pending).
 */
function suphot_auto_fulfill(int $oid): string {
    if (setting('suphot_auto') !== '1') return 'off';
    try {
        $r = suphot_fulfill_order($oid);
        if ($r[0]) return 'delivered';
        error_log('SUPPLIER AUTO: order #' . $oid . ': ' . $r[1]);
        if (empty($r[2])) return 'failed';
        suphot_mark_unclear($oid);
        return 'unclear';
    } catch (Throwable $e) { error_log('SUPPLIER AUTO: ' . $e->getMessage()); suphot_mark_unclear($oid); return 'unclear'; }
}

/** True when the Supplier Hot URL and API key are both saved. */
function suphot_connected(): bool {
    return (string)setting('suphot_url') !== '' && (string)setting('suphot_key') !== '';
}

/** Flag a pending order whose purchase result is unknown, so the admin checks the supplier history before retrying. */
function suphot_mark_unclear(int $oid): void {
    try { db()->prepare("UPDATE orders SET supplier_order_id = 'hot-unclear' WHERE id = ? AND status = 'pending'")->execute([$oid]); }
    catch (Throwable $e) {}
}

/** Cancel a pending order and give the customer the full amount back (same as Admin > Orders > Cancel & refund). Returns true if refunded. */
function suphot_refund_order(int $oid): bool {
    $pdo = db(); $done = false;
    with_tx($pdo, function (PDO $pdo) use ($oid, &$done) {
        $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending' FOR UPDATE");
        $st->execute([$oid]);
        if (!($o = $st->fetch())) return;
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$o['price'], $o['user_id']]);
        add_tx($pdo, (int)$o['user_id'], 'refund', (float)$o['price'], 'Refund for order #' . $oid);
        $done = true;
    });
    return $done;
}
