<?php require __DIR__ . '/lib.php';
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['admin_logout'])) { unset($_SESSION['admin']); header('Location: /admin.php'); exit; }
    if (isset($_POST['admin_pw'])) {
        if (hash_equals($adminPw, $_POST['admin_pw'])) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: /admin.php'); exit; }
        sleep(1); $err = 'Wrong password.';
    } elseif (!empty($_SESSION['admin']) && isset($_POST['id'], $_POST['action'])) {
        $pdo = db(); $pdo->beginTransaction();
        $s = $pdo->prepare("SELECT * FROM deposits WHERE id = ? AND status = 'pending' FOR UPDATE");
        $s->execute([(int)$_POST['id']]);
        if ($d = $s->fetch()) {
            if ($_POST['action'] === 'approve') {
                $pdo->prepare("UPDATE deposits SET status='approved' WHERE id=?")->execute([$d['id']]);
                $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$d['amount'], $d['user_id']]);
                $us = $pdo->prepare('SELECT referred_by, has_deposited FROM users WHERE id = ? FOR UPDATE');
                $us->execute([$d['user_id']]); $usr = $us->fetch();
                if (!$usr['has_deposited']) {
                    $pdo->prepare('UPDATE users SET has_deposited = 1 WHERE id = ?')->execute([$d['user_id']]);
                    if ($usr['referred_by'])
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$bonus, $usr['referred_by']]);
                }
            } else {
                $pdo->prepare("UPDATE deposits SET status='rejected' WHERE id=?")->execute([$d['id']]);
            }
        }
        $pdo->commit();
        header('Location: /admin.php'); exit;
    }
}

if (empty($_SESSION['admin'])) {
    header_html('Admin Login', null, 'auth'); ?>
<div class="card">
<div class="logo">🛡️</div>
<h2>Admin Panel</h2>
<p class="sub">Enter the admin password to continue</p>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Password</label>
<div class="pw"><input type="password" id="ap" name="admin_pw" required><button type="button" data-toggle="#ap">Show</button></div>
<button class="btn">Login</button></form>
</div>
<?php footer_html(); exit; }

$pdo = db();
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pend = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM deposits WHERE status='pending'")->fetch();
$coins = (float)$pdo->query('SELECT COALESCE(SUM(coins),0) FROM users')->fetchColumn();
$approved = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='approved'")->fetchColumn();
$rows = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status='pending' ORDER BY d.id")->fetchAll();
$users = $pdo->query('SELECT u.id, u.email, u.coins, u.referral_code, u.created_at, (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.id) AS refs FROM users u ORDER BY u.id DESC LIMIT 100')->fetchAll();
$hist = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status <> 'pending' ORDER BY d.id DESC LIMIT 30")->fetchAll();
header_html('Admin', null, 'app', true); ?>
<div class="stats">
  <div class="stat"><span>Total users</span><b><?= $totalUsers ?></b></div>
  <div class="stat"><span>Pending deposits</span><b><?= (int)$pend['c'] ?></b></div>
  <div class="stat"><span>Coins in wallets</span><b><?= number_format($coins, 2) ?></b></div>
  <div class="stat"><span>Total approved</span><b><?= number_format($approved, 2) ?></b></div>
</div>

<div class="card"><h3>Pending deposits</h3>
<div class="tw"><table><tr><th>User</th><th>Amount</th><th>Note</th><th>Date</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['email']) ?></td><td><?= e($r['amount']) ?></td><td><?= e($r['note']) ?></td><td><?= e($r['created_at']) ?></td>
<td><form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<button class="btn sm" name="action" value="approve">Approve</button>
<button class="btn sm red" name="action" value="reject">Reject</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5">Nothing pending.</td></tr><?php endif; ?></table></div></div>

<div class="card"><h3>Users (latest 100)</h3>
<div class="tw"><table><tr><th>#</th><th>Email</th><th>Coins</th><th>Referrals</th><th>Joined</th></tr>
<?php foreach ($users as $x): ?>
<tr><td><?= (int)$x['id'] ?></td><td><?= e($x['email']) ?></td><td><?= number_format((float)$x['coins'], 2) ?></td><td><?= (int)$x['refs'] ?></td><td><?= e($x['created_at']) ?></td></tr>
<?php endforeach; if (!$users): ?><tr><td colspan="5">No users yet.</td></tr><?php endif; ?></table></div></div>

<div class="card"><h3>Recent decisions</h3>
<div class="tw"><table><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr>
<?php foreach ($hist as $h): ?>
<tr><td><?= e($h['email']) ?></td><td><?= e($h['amount']) ?></td><td><span class="badge <?= e($h['status']) ?>"><?= e($h['status']) ?></span></td><td><?= e($h['created_at']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No history yet.</td></tr><?php endif; ?></table></div></div>
<?php footer_html();
