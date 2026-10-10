<?php require __DIR__ . '/lib.php';
$u = require_login();
$s = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50'); $s->execute([$u['id']]);
$rows = $s->fetchAll();
user_start('My Orders', $u, 'orders'); ?>
<div class="card"><h3>My Orders</h3>
<?php foreach ($rows as $r): ?>
<div class="prod" style="align-items:flex-start"><div>
  <b>#<?= (int)$r['id'] ?> · <?= e($r['product_name']) ?></b>
  <p><?= e($r['created_at']) ?> · <?= e($r['price']) ?> coins</p>
  <?php if ($r['status'] === 'delivered' && $r['delivery']): ?><div class="dv"><?= e($r['delivery']) ?></div><?php endif; ?>
</div><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></div>
<?php endforeach; if (!$rows): ?><p>No orders yet. <a href="/shop.php">Visit the shop</a>.</p><?php endif; ?>
</div>
<?php user_end();
