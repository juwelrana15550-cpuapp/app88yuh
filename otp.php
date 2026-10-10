<?php require __DIR__ . '/lib.php';
$u = require_login();
$pdo = db();

// Accounts that belong to this user = e-mail addresses found in the delivery text of their delivered orders.
$s = $pdo->prepare("SELECT delivery FROM orders WHERE user_id = ? AND status = 'delivered' AND delivery IS NOT NULL ORDER BY id DESC LIMIT 200");
$s->execute([$u['id']]);
$mine = [];
foreach ($s->fetchAll() as $r) {
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $r['delivery'], $m)) {
        foreach ($m[0] as $x) $mine[strtolower($x)] = true;
    }
}
$mine = array_keys($mine);

$email = strtolower(trim($_GET['email'] ?? ''));
$found = null; $list = []; $msg = '';
if ($email !== '') {
    if (!in_array($email, $mine, true)) {
        $msg = 'This email is not in your delivered orders.';
    } else {
        $q = $pdo->prepare('SELECT code, received_at, TIMESTAMPDIFF(SECOND, received_at, NOW()) AS age FROM otp_codes WHERE email = ? ORDER BY id DESC LIMIT 5');
        $q->execute([$email]); $list = $q->fetchAll();
        $found = $list[0] ?? null;
        if (!$found) $msg = 'No OTP received yet for this account. Request the code on the login page, then press Check again.';
    }
}
function ago(int $s): string { return $s < 60 ? $s . 's ago' : ($s < 3600 ? intdiv($s, 60) . ' min ago' : ($s < 86400 ? intdiv($s, 3600) . ' h ago' : intdiv($s, 86400) . ' d ago')); }
user_start('Read OTP', $u, 'otp'); ?>
<div class="card"><h3>Read OTP</h3>
<small>Enter the account email you received in your order to see its latest verification code.</small>
<form method="get">
<label>Account email</label><input type="email" name="email" required value="<?= e($email) ?>" placeholder="account@example.com">
<button class="btn">Check OTP</button></form>
<?php if ($mine): ?><div class="chips"><?php foreach ($mine as $x): ?><a href="/otp.php?email=<?= urlencode($x) ?>"><?= e($x) ?></a><?php endforeach; ?></div>
<?php else: ?><p style="margin-top:12px"><small>You have no delivered orders with an account email yet.</small></p><?php endif; ?></div>
<?php if ($msg): ?><div class="err"><?= e($msg) ?></div><?php endif; ?>
<?php if ($found): ?>
<div class="card"><h3>Latest code</h3>
<div class="otp" id="otpv"><?= e($found['code']) ?></div>
<p class="sub">Received <?= e(ago((int)$found['age'])) ?><?= (int)$found['age'] > 600 ? ' — may have expired' : '' ?></p>
<input type="text" id="otpc" readonly value="<?= e($found['code']) ?>" style="position:absolute;left:-9999px">
<button type="button" class="btn" data-copy="#otpc">Copy code</button>
<?php if (count($list) > 1): ?><h3 style="margin-top:20px">Earlier codes</h3>
<div class="tw"><table><tr><th>Code</th><th>Received</th></tr>
<?php foreach (array_slice($list, 1) as $r): ?><tr><td class="mono"><?= e($r['code']) ?></td><td><?= e(ago((int)$r['age'])) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
</div>
<?php endif; ?>
<?php user_end();
