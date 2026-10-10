<?php require __DIR__ . '/lib.php';
$u = require_login();
$s = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50'); $s->execute([$u['id']]);
$rows = $s->fetchAll();
$labels = ['pending' => 'Processing', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
user_start('My Orders', $u, 'orders'); ?>
<div class="card"><h3>My Orders</h3>
<?php foreach ($rows as $r): $q = max(1, (int)($r['qty'] ?? 1)); ?>
<a class="olist" href="/order.php?id=<?= (int)$r['id'] ?>">
  <span class="ord-pi"><?= icon('box') ?></span>
  <span class="olist-t"><b>#<?= (int)$r['id'] ?> &middot; <?= e($r['product_name']) ?><?= $q > 1 ? ' &times; ' . number_format($q) : '' ?></b>
    <small><?= e(date('M j, Y H:i', strtotime($r['created_at']))) ?> &middot; <?= e(money($r['price'])) ?></small></span>
  <span class="badge <?= e($r['status']) ?>"><?= e($labels[$r['status']] ?? $r['status']) ?></span>
</a>
<?php endforeach; if (!$rows): ?><p>No orders yet. <a href="/dashboard.php">Browse products</a>.</p><?php endif; ?>
</div>
<?php user_end();
