<?php
/**
 * SMS auto-verification helpers (bKash / Nagad / Rocket / Upay ...).
 *
 *  - sms.php        receives the forwarded SMS and stores it in `sms_inbox` (Admin > Transactions shows them)
 *  - deposits.php   matches the user's  method + amount + TrxID  against that table  -> instant credit
 *  - sms.php        also credits a PENDING request when the SMS arrives after the request (user is told on the deposit page)
 *
 * Needs lib.php (db(), add_tx(), with_tx()) to be loaded first.
 */

/** Wallets that are verified from SMS. The key is what we look for inside the method name / SMS sender. Add more here. */
const SMS_PROVIDERS = ['bkash', 'nagad', 'rocket', 'upay'];

/** Other spellings an admin may use for the method name (Admin > Payment methods). */
const SMS_NAME_ALIASES = ['roket' => 'rocket', 'bikash' => 'bkash', 'nogod' => 'nagad'];

/** 'bKash' / 'bkash Personal' / 'Nagad' ...  ->  'bkash' | 'nagad' | ... | '' (not an SMS wallet). */
function sms_slug(string $s): string {
    $l = strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
    foreach (SMS_PROVIDERS as $p) if ($l !== '' && strpos($l, $p) !== false) return $p;
    foreach (SMS_NAME_ALIASES as $alias => $p) if ($l !== '' && strpos($l, $alias) !== false) return $p;   // spelling variants of the method name
    return '';
}

/** SMS senders that are a number, not a name. Rocket sends from 16216. */
const SMS_SENDER_CODES = ['16216' => 'rocket'];

/** Who sent the SMS: 'bKash' / 'NAGAD' / '16216' / '+88016216' ...  ->  provider key or ''. */
function sms_sender_provider(string $sender): string {
    $digits = preg_replace('/\D+/', '', $sender);
    foreach (SMS_SENDER_CODES as $code => $p) if ($digits === $code) return $p;
    return sms_slug($sender);
}

/** Create the table / column once (cheap check afterwards). */
function sms_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($pdo->query("SELECT v FROM settings WHERE k = 'sms_schema_v'")->fetchColumn() === '1') return;
    } catch (Throwable $ex) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_inbox (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(20) NOT NULL DEFAULT '',
        kind VARCHAR(8) NOT NULL DEFAULT 'other',
        amount DECIMAL(12,2) NULL,
        trx VARCHAR(40) NULL,
        sender VARCHAR(80) NOT NULL DEFAULT '',
        contact VARCHAR(80) NOT NULL DEFAULT '',
        body TEXT NULL,
        raw TEXT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        deposit_id INT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_trx (provider, trx),
        INDEX idx_used (used, kind),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE deposits ADD COLUMN notified TINYINT(1) NOT NULL DEFAULT 1"); }   // 0 = credited later by an SMS, the user has not been told yet
    catch (PDOException $ex) { if ((int)($ex->errorInfo[1] ?? 0) !== 1060) throw $ex; }
    $pdo->exec("INSERT INTO settings (k, v) VALUES ('sms_schema_v', '1') ON DUPLICATE KEY UPDATE v = VALUES(v)");
}

/* ------------------------------------------------------------------ parsing ------------------------------------------------------------------ */

/** Read what the SMS-forward app sent: JSON, form fields or a raw text body. Returns [sender, contact, body, raw]. */
function sms_read_request(): array {
    $raw = (string)file_get_contents('php://input');
    $data = [];
    $j = json_decode($raw, true);
    if (is_array($j)) $data = $j;
    if (!$data && $_POST) $data = $_POST;
    if (!$data && $raw !== '' && preg_match('/^[A-Za-z0-9_]+=/', $raw)) { parse_str($raw, $pf); if (is_array($pf)) $data = $pf; }   // form body without a content-type
    if (!$data && $_GET) { $data = $_GET; unset($data['key']); }
    $flat = [];
    $walk = function ($a, $pre = '') use (&$walk, &$flat) {
        foreach ($a as $k => $v) {
            if (is_array($v)) $walk($v, strtolower((string)$k) . '.');
            else $flat[strtolower($pre . $k)] = (string)$v;
        }
    };
    $walk($data);
    $pick = function (array $keys) use ($flat): string {
        foreach ($keys as $k) if (isset($flat[$k]) && trim($flat[$k]) !== '') return trim($flat[$k]);
        return '';
    };
    $body   = $pick(['message', 'text', 'body', 'content', 'msg', 'sms', 'key', 'message body', 'messagebody', 'messagetext', 'payload.message', 'payload.text']);   // the SMS Forwarder app posts the message in a field called "key" (plus "time")
    $sender = $pick(['from', 'sender', 'sender number', 'sendernumber', 'number', 'address', 'phone', 'payload.from']);
    $contact = $pick(['contact', 'contact name', 'contactname', 'name']);
    if ($body === '') $body = trim($raw);   // app posted the plain template text
    // Template used in the app:  From : {Sender Number}({Contact name}) {Message Body}
    if (preg_match('/^\s*From\s*:\s*(.*?)\(([^)]*)\)\s*(.*)$/su', $body, $m)) {
        $sender = $sender !== '' ? $sender : trim($m[1]);
        $contact = $contact !== '' ? $contact : trim($m[2]);
        $body = trim($m[3]);
    } elseif (preg_match('/^\s*From\s*:\s*(\S+)\s+(.*)$/su', $body, $m)) {
        $sender = $sender !== '' ? $sender : trim($m[1]);
        $body = trim($m[2]);
    }
    return [mb_substr($sender, 0, 80), mb_substr($contact, 0, 80), $body, mb_substr($raw !== '' ? $raw : json_encode($data, JSON_UNESCAPED_UNICODE), 0, 2000)];
}

/**
 * Understand one SMS. provider comes ONLY from the sender name (bKash, NAGAD ...), so a normal phone number
 * that texts a fake "You have received Tk ..." message is never trusted.
 */
function sms_parse(string $sender, string $contact, string $body): array {
    $provider = sms_sender_provider($sender);
    if ($provider === '') $provider = sms_slug($contact);
    $t = preg_replace('/\s+/u', ' ', $body);
    $incoming = (bool)preg_match('/received|cash\s*-?in|credited|deposit/i', $t) && !preg_match('/\b(sent|cash\s*-?out|withdraw|paid|payment\s+to|bill|recharge|purchase)\b/i', $t);
    $amount = null;
    if (preg_match('/(?:Tk\.?|BDT|৳)\s*([0-9][0-9,]*(?:\.[0-9]+)?)/iu', $t, $m) || preg_match('/Amount\s*:?\s*([0-9][0-9,]*(?:\.[0-9]+)?)/i', $t, $m)) {
        $amount = round((float)str_replace(',', '', $m[1]), 2);
    }
    $trx = null;
    if (preg_match('/(?:Trx\s*ID|Txn\s*ID|Tnx\s*ID|Trnx\s*ID|Transaction\s*ID)\s*[:\-]?\s*([A-Za-z0-9]{6,30})/i', $t, $m)) $trx = strtoupper($m[1]);
    $kind = ($provider !== '' && $incoming && $amount !== null && $amount > 0 && $trx !== null) ? 'in' : 'other';
    return ['provider' => $provider, 'kind' => $kind, 'amount' => $amount, 'trx' => $trx];
}

/** Save one parsed SMS. Returns [id|0, isNew]. Same provider+TrxID twice is ignored (the app may retry). */
function sms_store(PDO $pdo, string $sender, string $contact, string $body, string $raw): array {
    $p = sms_parse($sender, $contact, $body);
    $st = $pdo->prepare('INSERT IGNORE INTO sms_inbox (provider, kind, amount, trx, sender, contact, body, raw) VALUES (?,?,?,?,?,?,?,?)');
    $st->execute([$p['provider'], $p['kind'], $p['amount'], $p['trx'], $sender, $contact, mb_substr($body, 0, 1000), $raw]);
    return $st->rowCount() ? [(int)$pdo->lastInsertId(), true, $p] : [0, false, $p];
}

/* ------------------------------------------------------------------ crediting ------------------------------------------------------------------ */

/** Approve a pending deposit and add the balance (same steps as Admin > Deposits > Approve, incl. referral bonus). Call inside with_tx. */
function sms_approve_deposit(PDO $pdo, int $depId, float $bonus, int $notified): bool {
    $st = $pdo->prepare("SELECT * FROM deposits WHERE id = ? AND status = 'pending' FOR UPDATE");
    $st->execute([$depId]);
    if (!($d = $st->fetch())) return false;
    $pdo->prepare("UPDATE deposits SET status = 'approved', notified = ? WHERE id = ?")->execute([$notified, $depId]);
    $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$d['amount'], $d['user_id']]);
    add_tx($pdo, (int)$d['user_id'], 'deposit', (float)$d['amount'], 'Deposit #' . $d['id']);
    $us = $pdo->prepare('SELECT referred_by, has_deposited FROM users WHERE id = ? FOR UPDATE');
    $us->execute([$d['user_id']]); $usr = $us->fetch();
    if ($usr && !$usr['has_deposited']) {
        $pdo->prepare('UPDATE users SET has_deposited = 1 WHERE id = ?')->execute([$d['user_id']]);
        if ($usr['referred_by']) {
            $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$bonus, $usr['referred_by']]);
            add_tx($pdo, (int)$usr['referred_by'], 'bonus', $bonus, 'Referral bonus');
        }
    }
    return true;
}

/**
 * The user pressed "Verify": look for the SMS.
 * Returns ['state' => SUCCESS | WRONG_AMOUNT | NOT_FOUND]. Nothing is changed unless SUCCESS.
 */
function sms_submit(PDO $pdo, int $uid, string $slug, string $trx, float $amt, float $credit, string $note, float $bonus): array {
    return with_tx($pdo, function (PDO $pdo) use ($uid, $slug, $trx, $amt, $credit, $note, $bonus) {
        $st = $pdo->prepare("SELECT * FROM sms_inbox WHERE provider = ? AND trx = ? AND kind = 'in' AND used = 0 FOR UPDATE");
        $st->execute([$slug, $trx]);
        $s = $st->fetch();
        if (!$s) return ['state' => 'NOT_FOUND'];
        if (abs((float)$s['amount'] - $amt) > 0.009) return ['state' => 'WRONG_AMOUNT'];
        $pdo->prepare("INSERT INTO deposits (user_id, amount, note, status, notified) VALUES (?,?,?, 'pending', 1)")->execute([$uid, $credit, substr($note . ' | SMS auto', 0, 255)]);
        $depId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE sms_inbox SET used = 1, deposit_id = ?, used_at = NOW() WHERE id = ? AND used = 0')->execute([$depId, $s['id']]);
        if (!sms_approve_deposit($pdo, $depId, $bonus, 1)) throw new RuntimeException('could not approve deposit');
        return ['state' => 'SUCCESS', 'dep_id' => $depId];
    });
}

/** Read "bKash | TrxID: ABC | BDT 1,000.00 | ..." back into [methodName, trx, bdtAmount|null]. */
function sms_note_parts(string $note): array {
    $p = explode(' | ', $note);
    $trx = isset($p[1]) ? strtoupper(trim(preg_replace('/^[^:]+:\s*/', '', $p[1]))) : '';
    $amt = null;
    if (isset($p[2]) && preg_match('/^BDT\s+([0-9][0-9,]*(?:\.[0-9]+)?)/', $p[2], $m)) $amt = round((float)str_replace(',', '', $m[1]), 2);
    return [trim($p[0] ?? ''), $trx, $amt];
}

/**
 * An SMS just arrived (or the admin pressed "Re-check"): is a PENDING request waiting for it?
 * If method + TrxID + amount all match, the balance is added now and the user is told on the deposit page (notified = 0).
 * Returns the deposit id that was credited, or 0.
 */
function sms_match_pending(PDO $pdo, int $smsId, float $bonus): int {
    return (int)with_tx($pdo, function (PDO $pdo) use ($smsId, $bonus) {
        $st = $pdo->prepare("SELECT * FROM sms_inbox WHERE id = ? AND used = 0 FOR UPDATE");
        $st->execute([$smsId]);
        if (!($s = $st->fetch())) return 0;
        if ($s['kind'] !== 'in') {   // saved before the parser knew this sender: read it again with the current rules
            $np = sms_parse((string)$s['sender'], (string)$s['contact'], (string)$s['body']);
            if ($np['kind'] !== 'in') return 0;
            try { $pdo->prepare('UPDATE sms_inbox SET provider = ?, kind = ?, amount = ?, trx = ? WHERE id = ?')->execute([$np['provider'], $np['kind'], $np['amount'], $np['trx'], $s['id']]); }
            catch (PDOException $ex) { return 0; }
            $s = array_merge($s, $np);
        }
        if ($s['trx'] === null) return 0;
        $q = $pdo->prepare("SELECT * FROM deposits WHERE status = 'pending' AND note LIKE ? ORDER BY id LIMIT 20");
        $q->execute(['%| TrxID: ' . $s['trx'] . ' |%']);
        foreach ($q->fetchAll() as $d) {
            [$mname, $trx, $amt] = sms_note_parts((string)$d['note']);
            if (sms_slug($mname) !== $s['provider'] || $trx !== $s['trx'] || $amt === null || abs($amt - (float)$s['amount']) > 0.009) continue;
            $pdo->prepare('UPDATE sms_inbox SET used = 1, deposit_id = ?, used_at = NOW() WHERE id = ? AND used = 0')->execute([$d['id'], $s['id']]);
            if (sms_approve_deposit($pdo, (int)$d['id'], $bonus, 0)) return (int)$d['id'];
            throw new RuntimeException('could not approve deposit');
        }
        return 0;
    });
}

/**
 * Deposits credited later by an SMS that the user has not been told about yet.
 * Marks them as told and returns the data for the "Payment Successful" screen (or null).
 */
function sms_take_notice(PDO $pdo, int $uid): ?array {
    $st = $pdo->prepare("SELECT * FROM deposits WHERE user_id = ? AND status = 'approved' AND notified = 0 ORDER BY id LIMIT 1");
    $st->execute([$uid]);
    if (!($d = $st->fetch())) return null;
    $pdo->prepare('UPDATE deposits SET notified = 1 WHERE id = ? AND notified = 0')->execute([$d['id']]);
    $p = explode(' | ', (string)$d['note']);
    $trx = isset($p[1]) ? trim(preg_replace('/^[^:]+:\s*/', '', $p[1])) : '';
    return ['amt' => (float)$d['amount'], 'method' => $p[0] ?? 'Deposit', 'trx' => $trx, 'paid' => $p[2] ?? '', 'at' => time()];
}
