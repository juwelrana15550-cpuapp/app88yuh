<?php require __DIR__ . '/lib.php';
$u = require_login();
$pdo = db();
$s = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(status='pending'),0) p FROM orders WHERE user_id = ?");
$s->execute([$u['id']]); $o = $s->fetch();
$s = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?'); $s->execute([$u['id']]);
$refs = (int)$s->fetchColumn();
$s = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5'); $s->execute([$u['id']]);
$recent = $s->fetchAll();
user_start('Dashboard', $u, 'dashboard'); ?>
<div class="card wallet"><small><?= e($u['email']) ?></small><div class="big"><?= money($u['coins']) ?></div><small>Wallet balance</small></div>
<div class="stats">
  <div class="stat"><span>Total orders</span><b><?= (int)$o['c'] ?></b></div>
  <div class="stat"><span>Pending orders</span><b><?= (int)$o['p'] ?></b></div>
  <div class="stat"><span>Referrals</span><b><?= $refs ?></b></div>
</div>
<div class="card"><h3>Quick actions</h3>
  <div class="row"><a class="btn sm" href="/shop.php">Browse shop</a><a class="btn sm ghost" href="/deposits.php">Add balance</a><a class="btn sm ghost" href="/otp.php">Read OTP</a></div></div>
<div class="card"><h3>Recent orders</h3>
<div class="tw"><table><tr><th>#</th><th>Product</th><th>Price</th><th>Status</th></tr>
<?php foreach ($recent as $r): ?>
<tr><td><?= (int)$r['id'] ?></td><td><?= e($r['product_name']) ?></td><td><?= money($r['price']) ?></td><td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td></tr>
<?php endforeach; if (!$recent): ?><tr><td colspan="4">No orders yet.</td></tr><?php endif; ?></table></div></div>
<?php user_end();
