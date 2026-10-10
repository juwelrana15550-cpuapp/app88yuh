<?php require __DIR__ . '/lib.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amt = round((float)($_POST['amount'] ?? 0), 2);
    $s = db()->prepare("SELECT COUNT(*) FROM deposits WHERE user_id = ? AND status = 'pending'"); $s->execute([$u['id']]);
    if ($amt < 1 || $amt > 100000) flash('err', 'Enter a valid amount.');
    elseif ((int)$s->fetchColumn() >= 5) flash('err', 'You already have 5 pending requests. Please wait for approval.');
    else {
        db()->prepare('INSERT INTO deposits (user_id, amount, note) VALUES (?,?,?)')
            ->execute([$u['id'], $amt, mb_substr(trim($_POST['note'] ?? ''), 0, 255)]);
        flash('ok', 'Deposit request submitted. It will be credited after admin approval.');
    }
    header('Location: /deposits.php'); exit; // redirect so a page refresh cannot re-submit the form
}
$s = db()->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 30'); $s->execute([$u['id']]);
$deps = $s->fetchAll();
user_start('Deposits', $u, 'deposits'); ?>
<div class="card"><h3>Add balance</h3>
<form method="post"><?= csrf_field() ?>
<label>Amount (৳)</label><input type="number" name="amount" step="0.01" min="1" required>
<label>Payment reference / note</label><input type="text" name="note" maxlength="255" placeholder="Transaction ID or note">
<button class="btn">Submit deposit request</button></form></div>
<div class="card"><h3>Deposit history</h3>
<div class="tw"><table><tr><th>#</th><th>Amount</th><th>Status</th><th>Date</th></tr>
<?php foreach ($deps as $d): ?>
<tr><td><?= (int)$d['id'] ?></td><td><?= money($d['amount']) ?></td><td><span class="badge <?= e($d['status']) ?>"><?= e($d['status']) ?></span></td><td><?= e($d['created_at']) ?></td></tr>
<?php endforeach; if (!$deps): ?><tr><td colspan="4">No deposits yet.</td></tr><?php endif; ?></table></div></div>
<?php user_end();
