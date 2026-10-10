<?php require __DIR__ . '/../src/lib.php';
$u = require_login();
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amt = round((float)($_POST['amount'] ?? 0), 2);
    if ($amt < 1 || $amt > 100000) $err = 'Enter a valid amount.';
    else {
        db()->prepare('INSERT INTO deposits (user_id, amount, note) VALUES (?,?,?)')
            ->execute([$u['id'], $amt, substr(trim($_POST['note'] ?? ''), 0, 255)]);
        $msg = 'Deposit request submitted. It will be credited after admin approval.';
    }
}
$s = db()->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?'); $s->execute([$u['id']]);
$refCount = (int)$s->fetchColumn();
$s = db()->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 20'); $s->execute([$u['id']]);
$deps = $s->fetchAll();
$scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
$link = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/register.php?ref=' . $u['referral_code'];
header_html('Dashboard', $u); ?>
<div class="card"><small><?= e($u['email']) ?></small><div class="big"><?= number_format((float)$u['coins'], 2) ?> coins</div></div>
<div class="card"><h3>Referral</h3>
<p>Your code: <b><?= e($u['referral_code']) ?></b> · Referred users: <?= $refCount ?></p>
<input type="text" readonly value="<?= e($link) ?>" onclick="this.select()"></div>
<div class="card"><h3>Add coins</h3>
<?php if ($msg): ?><div class="ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Amount</label><input type="number" name="amount" step="0.01" min="1" required>
<label>Payment reference / note</label><input type="text" name="note" maxlength="255">
<button class="btn">Submit deposit request</button></form></div>
<div class="card"><h3>Deposit history</h3>
<table><tr><th>#</th><th>Amount</th><th>Status</th><th>Date</th></tr>
<?php foreach ($deps as $d): ?>
<tr><td><?= $d['id'] ?></td><td><?= e($d['amount']) ?></td><td><?= e($d['status']) ?></td><td><?= e($d['created_at']) ?></td></tr>
<?php endforeach; if (!$deps): ?><tr><td colspan="4">No deposits yet.</td></tr><?php endif; ?></table></div>
<?php footer_html();
