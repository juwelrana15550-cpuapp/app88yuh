<?php
/**
 * Automatic deposit verification: Binance Pay (USDT) + BSC / BEP-20 (USDT).
 * Needs lib.php (setting, save_setting, with_tx, add_tx, db). Included by deposits.php and admin.php.
 *
 * Settings (Admin > Payment methods > "Binance auto-verification"):
 *   bn_api_key, bn_api_secret   Binance API key / secret (read-only permission is enough)
 *   bn_pay_method               id of the payment method that is "Binance Pay"
 *   bn_bsc_method               id of the payment method that is "USDT BEP-20"  (its account field = your receiving wallet)
 *   bn_bsc_conf                 confirmations required on BSC
 *   bn_bsc_rpc                  optional own BSC RPC URL
 * BINANCE_API_KEY / BINANCE_API_SECRET environment variables are used when the admin fields are empty.
 */

const BN_USDT_BSC       = '0x55d398326f99059ff775485246999027b3197955';   // USDT (BEP-20), 18 decimals
const BN_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
const BN_TOLERANCE      = 1000000;   // 0.01 USDT, in 1e-8 units (covers PHP/JS rounding of the shown dollar amount)

class BnTempError extends RuntimeException {}

/* ------------------------------ config ------------------------------ */
function bn_key(): string    { $v = trim((string)setting('bn_api_key', '')); return $v !== '' ? $v : trim((string)getenv('BINANCE_API_KEY')); }
function bn_secret(): string { $v = trim((string)setting('bn_api_secret', '')); return $v !== '' ? $v : trim((string)getenv('BINANCE_API_SECRET')); }
function bn_mask(string $s): string { return $s === '' ? '' : str_repeat('•', 8) . substr($s, -4); }

/** 'pay' | 'bsc' | null — is this payment method one of the auto-verified ones? */
function bn_kind(string $methodId): ?string {
    if ($methodId === '') return null;
    if ($methodId === (string)setting('bn_pay_method', '')) return 'pay';
    if ($methodId === (string)setting('bn_bsc_method', '')) return 'bsc';
    return null;
}
/** Is auto-verification usable for this kind right now? (otherwise deposits fall back to manual review) */
function bn_ready(string $kind): bool {
    return $kind === 'pay' ? (bn_key() !== '' && bn_secret() !== '') : true;
}

/* ------------------------------ helpers ------------------------------ */
/** Decimal string -> integer in 1e-8 units (no floats). null when it is not a plain number. */
function bn_scaled($v): ?int {
    $v = trim((string)$v);
    if (!preg_match('/^(-)?(\d{1,18})(?:\.(\d+))?$/', $v, $m)) return null;
    $n = (int)($m[2] . substr(str_pad($m[3] ?? '', 8, '0'), 0, 8));
    return $m[1] === '-' ? -$n : $n;
}
/** "0x…" hex (up to 256 bit) -> decimal string. */
function bn_hex2dec(string $hex): string {
    $hex = strtolower(preg_replace('/^0x/', '', $hex)); $hex = ltrim($hex, '0');
    if ($hex === '') return '0';
    $dec = [0];   // little-endian base-10 digits
    foreach (str_split($hex) as $ch) {
        $carry = hexdec($ch);
        foreach ($dec as $i => $d) { $x = $d * 16 + $carry; $dec[$i] = $x % 10; $carry = intdiv($x, 10); }
        while ($carry > 0) { $dec[] = $carry % 10; $carry = intdiv($carry, 10); }
    }
    return implode('', array_reverse($dec));
}
/** wei-style integer string (18 decimals) -> "int.frac" */
function bn_wei18(string $wei): string {
    $wei = str_pad($wei, 19, '0', STR_PAD_LEFT);
    return substr($wei, 0, -18) . '.' . substr($wei, -18);
}

function bn_http(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    if ($out === false) { $e = curl_error($ch); curl_close($ch); throw new BnTempError('connection failed: ' . $e); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$out];
}

/** Table that makes sure one blockchain / Binance transaction can only ever be credited once. */
function bn_ensure_table(PDO $pdo): void {
    static $done = false; if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS auto_pay_used (
        txid VARCHAR(100) NOT NULL PRIMARY KEY, provider VARCHAR(10) NOT NULL, user_id INT NOT NULL,
        amount DECIMAL(18,8) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* ------------------------- Binance Pay (history) ------------------------- */
/** Signed GET /sapi/v1/pay/transactions. Returns the list of transaction arrays. */
function bn_pay_history(int $windowMs): array {
    $key = bn_key(); $secret = bn_secret();
    if ($key === '' || $secret === '') throw new RuntimeException('Binance API key / secret are not configured');
    $now = (int)round(microtime(true) * 1000);
    $query = http_build_query([
        'startTime' => $now - $windowMs, 'endTime' => $now, 'limit' => 100, 'timestamp' => $now, 'recvWindow' => 5000,
    ]);
    $sig = hash_hmac('sha256', $query, $secret);
    [$code, $body] = bn_http('GET', 'https://api.binance.com/sapi/v1/pay/transactions?' . $query . '&signature=' . $sig, ['X-MBX-APIKEY: ' . $key]);
    if ($code >= 400) throw new RuntimeException('Binance API ' . $code . ': ' . substr($body, 0, 300));   // body never contains the secret
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('Invalid Binance API response');
    if (isset($data['code']) && !in_array((string)$data['code'], ['000000', '200', '0'], true) && isset($data['msg'])) throw new RuntimeException('Binance API: ' . $data['msg']);

    $payload = $data['data'] ?? $data; $rows = [];
    if (is_array($payload)) {
        if (array_is_list($payload)) $rows = $payload;
        else {
            foreach (['list', 'data', 'rows', 'transactions', 'items', 'results'] as $k)
                if (isset($payload[$k]) && is_array($payload[$k])) { $rows = $payload[$k]; break; }
            if (!$rows && (isset($payload['transactionId']) || isset($payload['orderId']))) $rows = [$payload];
        }
    }
    return array_values(array_filter($rows, 'is_array'));
}

/**
 * Verify a Binance Pay transaction / order ID.
 * Returns ['state' => SUCCESS|NOT_FOUND|WRONG_AMOUNT|WRONG_TOKEN|FAILED, 'tx' => …]. Throws on network / API errors
 * (a temporary error is NOT proof that the payment failed).
 */
function bn_verify_pay(string $txid, string $expectedUsd): array {
    $submitted = strtolower(trim($txid));
    if ($submitted === '') return ['state' => 'NOT_FOUND'];
    $expected = bn_scaled($expectedUsd);
    if ($expected === null || $expected <= 0) throw new RuntimeException('bad expected amount');

    foreach ([2 * 86400000, 90 * 86400000] as $window) {   // recent window first (the history call returns max 100 rows), then 90 days
        foreach (bn_pay_history($window) as $tx) {
            $ids = [];
            foreach (['transactionId', 'orderId', 'transaction_id', 'order_id', 'id', 'merchantTradeNo', 'merchant_trade_no'] as $f)
                if (isset($tx[$f]) && trim((string)$tx[$f]) !== '') $ids[] = strtolower(trim((string)$tx[$f]));
            if (!in_array($submitted, $ids, true)) continue;

            $status = strtoupper((string)($tx['status'] ?? $tx['transactionStatus'] ?? $tx['transaction_status'] ?? ''));
            if ($status !== '' && !in_array($status, ['SUCCESS', 'SUCCEEDED', 'COMPLETED', 'COMPLETE', 'PAID', 'FINISHED'], true))
                return ['state' => 'FAILED', 'tx' => $tx];

            $funds = $tx['fundsDetail'] ?? null;
            if (is_array($funds) && !array_is_list($funds)) $funds = [$funds];
            if (!is_array($funds) || !$funds)
                $funds = [['amount' => $tx['amount'] ?? $tx['totalAmount'] ?? $tx['funds'] ?? $tx['totalFee'] ?? null, 'currency' => $tx['currency'] ?? $tx['asset'] ?? '']];

            $sawUsdt = false;
            foreach ($funds as $f) {
                if (!is_array($f) || strtoupper((string)($f['currency'] ?? $f['asset'] ?? '')) !== 'USDT') continue;
                $sawUsdt = true;
                $got = bn_scaled($f['amount'] ?? $f['totalFee'] ?? $tx['totalFee'] ?? '');
                if ($got !== null && abs($got - $expected) <= BN_TOLERANCE) return ['state' => 'SUCCESS', 'tx' => $tx];
            }
            return ['state' => $sawUsdt ? 'WRONG_AMOUNT' : 'WRONG_TOKEN', 'tx' => $tx];
        }
    }
    return ['state' => 'NOT_FOUND'];
}

/* ------------------------- BSC / BEP-20 USDT ------------------------- */
function bn_rpc(string $method, array $params) {
    $urls = [];
    $own = trim((string)setting('bn_bsc_rpc', '')); if (preg_match('#^https://#i', $own)) $urls[] = $own;
    array_push($urls, 'https://bsc-dataseed.binance.org/', 'https://bsc-dataseed1.defibit.io/', 'https://bsc-rpc.publicnode.com');
    $last = 'no RPC reachable';
    foreach ($urls as $u) {
        try {
            [$code, $body] = bn_http('POST', $u, ['Content-Type: application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
            $j = json_decode($body, true);
            if ($code < 400 && is_array($j) && !isset($j['error']) && array_key_exists('result', $j)) return $j['result'];
            $last = 'RPC ' . $code . ': ' . substr($body, 0, 150);
        } catch (BnTempError $e) { $last = $e->getMessage(); }
    }
    throw new BnTempError('BSC RPC error: ' . $last);
}

/**
 * Verify a BEP-20 USDT transfer to $wallet by transaction hash.
 * States: SUCCESS, NOT_FOUND, CONFIRMING, FAILED, WRONG_TOKEN (no USDT transfer to our wallet), WRONG_AMOUNT.
 * Overpaying is accepted (the credit is based on the amount the user chose, see deposits.php).
 */
function bn_verify_bsc(string $hash, string $wallet, string $expectedUsd): array {
    $hash = strtolower(trim($hash));
    if (!preg_match('/^0x[a-f0-9]{64}$/', $hash)) return ['state' => 'NOT_FOUND'];
    $w = strtolower(trim($wallet));
    if (!preg_match('/^0x[a-f0-9]{40}$/', $w)) throw new RuntimeException('Receiving wallet address of the BSC method is not a valid 0x… address');
    $expected = bn_scaled($expectedUsd);
    if ($expected === null || $expected <= 0) throw new RuntimeException('bad expected amount');

    $rc = bn_rpc('eth_getTransactionReceipt', [$hash]);
    if (!is_array($rc)) return ['state' => 'NOT_FOUND'];            // unknown or still pending
    if (strtolower((string)($rc['status'] ?? '')) !== '0x1') return ['state' => 'FAILED'];

    $need = max(1, min(200, (int)setting('bn_bsc_conf', '15')));
    $head = bn_rpc('eth_blockNumber', []);
    $conf = hexdec((string)$head) - hexdec((string)($rc['blockNumber'] ?? '0x0')) + 1;
    if ($conf < $need) return ['state' => 'CONFIRMING', 'confirmations' => $conf, 'need' => $need];

    $total = 0; $saw = false;
    foreach (($rc['logs'] ?? []) as $lg) {
        $t = $lg['topics'] ?? [];
        if (strtolower((string)($lg['address'] ?? '')) !== BN_USDT_BSC || count($t) < 3 || strtolower($t[0]) !== BN_TRANSFER_TOPIC) continue;
        if ('0x' . substr(strtolower($t[2]), -40) !== $w) continue;
        $saw = true;
        $total += bn_scaled(bn_wei18(bn_hex2dec((string)($lg['data'] ?? '0x0')))) ?? 0;
    }
    if (!$saw) return ['state' => 'WRONG_TOKEN'];
    return ['state' => $total + BN_TOLERANCE >= $expected ? 'SUCCESS' : 'WRONG_AMOUNT', 'received' => $total];
}

/* ------------------------- credit the user ------------------------- */
/**
 * Atomically: mark the transaction as used, add an APPROVED deposit, credit the balance, pay the referral bonus (same rules as the admin approve button).
 * Returns false when this transaction was already used (duplicate), true when credited.
 */
function bn_credit(PDO $pdo, int $uid, string $provider, string $txid, float $credit, string $note, float $refBonus): bool {
    bn_ensure_table($pdo);   // DDL commits, so it must run before the transaction starts
    return (bool)with_tx($pdo, function (PDO $pdo) use ($uid, $provider, $txid, $credit, $note, $refBonus) {
        try {
            $pdo->prepare('INSERT INTO auto_pay_used (txid, provider, user_id, amount) VALUES (?,?,?,?)')->execute([strtolower($txid), $provider, $uid, $credit]);
        } catch (PDOException $ex) {
            if ((string)$ex->getCode() === '23000') return false;
            throw $ex;
        }
        $pdo->prepare("INSERT INTO deposits (user_id, amount, note, status) VALUES (?,?,?, 'approved')")->execute([$uid, $credit, substr($note, 0, 255)]);
        $did = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$credit, $uid]);
        add_tx($pdo, $uid, 'deposit', $credit, 'Deposit #' . $did);
        $us = $pdo->prepare('SELECT referred_by, has_deposited FROM users WHERE id = ? FOR UPDATE'); $us->execute([$uid]); $usr = $us->fetch();
        if ($usr && !$usr['has_deposited']) {
            $pdo->prepare('UPDATE users SET has_deposited = 1 WHERE id = ?')->execute([$uid]);
            if ($usr['referred_by']) {
                $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$refBonus, $usr['referred_by']]);
                add_tx($pdo, (int)$usr['referred_by'], 'bonus', $refBonus, 'Referral bonus');
            }
        }
        return true;
    });
}

/** Admin "Test connection": one signed history call. Returns [ok, message]. */
function bn_test(): array {
    try { $rows = bn_pay_history(86400000); return [true, 'Connected to Binance. API key and secret are valid (' . count($rows) . ' Binance Pay transaction(s) in the last 24h).']; }
    catch (Throwable $e) { return [false, 'Binance rejected the request: ' . $e->getMessage()]; }
}
