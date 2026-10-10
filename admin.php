<?php require __DIR__ . '/lib.php';
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['admin_pw'])) {
        if (hash_equals($adminPw, $_POST['admin_pw'])) { session_regenerate_id(true); $_SESSION['admin'] = true; }
        else $err = 'Wrong password.';
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
    }
}
header_html('Admin');
if (empty($_SESSION['admin'])): ?>
<div class="card"><h2>Admin login</h2>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?><label>Password</label><input type="password" name="admin_pw" required><button class="btn">Login</button></form></div>
<?php else:
$rows = db()->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status='pending' ORDER BY d.id")->fetchAll(); ?>
<div class="card"><h2>Pending deposits</h2>
<table><tr><th>User</th><th>Amount</th><th>Note</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['email']) ?></td><td><?= e($r['amount']) ?></td><td><?= e($r['note']) ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
<button name="action" value="approve">Approve</button> <button name="action" value="reject">Reject</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="4">Nothing pending.</td></tr><?php endif; ?></table></div>
<?php endif; footer_html();
