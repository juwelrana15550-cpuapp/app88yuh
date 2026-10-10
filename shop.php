<?php require __DIR__ . '/lib.php';
$u = require_login();
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db(); $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1');
    $st->execute([(int)($_POST['product_id'] ?? 0)]); $p = $st->fetch();
    $st = $pdo->prepare('SELECT coins FROM users WHERE id = ? FOR UPDATE');
    $st->execute([$u['id']]); $coins = (float)$st->fetchColumn();
    if (!$p) { $pdo->rollBack(); $err = 'Product not found.'; }
    elseif ($coins < (float)$p['price']) { $pdo->rollBack(); $err = 'Not enough coins. Please add a deposit first.'; }
    else {
        $pdo->prepare('UPDATE users SET coins = coins - ? WHERE id = ?')->execute([$p['price'], $u['id']]);
        $pdo->prepare('INSERT INTO orders (user_id, product_id, product_name, price) VALUES (?,?,?,?)')
            ->execute([$u['id'], $p['id'], $p['name'], $p['price']]);
        add_tx($pdo, (int)$u['id'], 'purchase', -(float)$p['price'], 'Order: ' . $p['name']);
        $pdo->commit();
        $msg = 'Order placed. You can track it in My Orders.';
        $u = current_user();
    }
}
$products = db()->query('SELECT * FROM products WHERE active = 1 ORDER BY id DESC')->fetchAll();
user_start('Shop', $u, 'shop'); ?>
<div class="card"><h3>Shop</h3>
<?php if ($msg): ?><div class="ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<?php foreach ($products as $p): ?>
<div class="prod"><div><b><?= e($p['name']) ?></b><?php if ($p['description']): ?><p><?= e($p['description']) ?></p><?php endif; ?></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
<div class="price"><?= e($p['price']) ?></div>
<button class="btn sm" style="margin-top:6px" onclick="return confirm('Buy this for <?= e($p['price']) ?> coins?')">Buy</button></form></div>
<?php endforeach; if (!$products): ?><p>No products available yet.</p><?php endif; ?>
</div>
<?php user_end();
