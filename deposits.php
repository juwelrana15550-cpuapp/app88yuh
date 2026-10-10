<?php require __DIR__ . '/lib.php';
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
$s = db()->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 30'); $s->execute([$u['id']]);
$deps = $s->fetchAll();
user_start('Deposits', $u, 'deposits'); ?>
<div class="card"><h3>Add coins</h3>
<?php if ($msg): ?><div class="ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Amount</label><input type="number" name="amount" step="0.01" min="1" required>
<label>Payment reference / note</label><input type="text" name="note" maxlength="255" placeholder="Transaction ID or note">
<button class="btn">Submit deposit request</button></form></div>
<div class="card"><h3>Deposit history</h3>
<div class="tw"><table><tr><th>#</th><th>Amount</th><th>Status</th><th>Date</th></tr>
<?php foreach ($deps as $d): ?>
<tr><td><?= (int)$d['id'] ?></td><td><?= e($d['amount']) ?></td><td><span class="badge <?= e($d['status']) ?>"><?= e($d['status']) ?></span></td><td><?= e($d['created_at']) ?></td></tr>
<?php endforeach; if (!$deps): ?><tr><td colspan="4">No deposits yet.</td></tr><?php endif; ?></table></div></div>
<?php user_end();
