<?php require __DIR__ . '/lib.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        with_tx(db(), function (PDO $pdo) use ($u) {
            $st = $pdo->prepare('SELECT coins FROM users WHERE id = ? FOR UPDATE');
            $st->execute([$u['id']]); $coins = (float)$st->fetchColumn();
            $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1');
            $st->execute([(int)($_POST['product_id'] ?? 0)]); $p = $st->fetch();
            if (!$p) throw new RuntimeException('Product not found.');
            if ((int)round($coins * 100) < (int)round((float)$p['price'] * 100)) throw new RuntimeException('Not enough balance. Please add a deposit first.');
            $pdo->prepare('UPDATE users SET coins = coins - ? WHERE id = ?')->execute([$p['price'], $u['id']]);
            $pdo->prepare('INSERT INTO orders (user_id, product_id, product_name, price) VALUES (?,?,?,?)')
                ->execute([$u['id'], $p['id'], $p['name'], $p['price']]);
            add_tx($pdo, (int)$u['id'], 'purchase', -(float)$p['price'], 'Order: ' . $p['name']);
        });
        flash('ok', 'Order placed. You can track it in My Orders.');
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('SHOP ERROR: ' . $ex->getMessage());
        flash('err', 'Something went wrong. Please try again.');
    }
    header('Location: /shop.php'); exit; // redirect: refreshing the page must not buy again
}
$products = db()->query('SELECT * FROM products WHERE active = 1 ORDER BY id DESC')->fetchAll();
user_start('Shop', $u, 'shop'); ?>
<div class="card"><h3>Shop</h3>
<?php foreach ($products as $p): ?>
<div class="prod"><div><b><?= e($p['name']) ?></b><?php if ($p['description']): ?><p><?= e($p['description']) ?></p><?php endif; ?></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
<div class="price"><?= money($p['price']) ?></div>
<button class="btn sm" style="margin-top:6px" onclick="return confirm('Buy this for <?= e(money($p['price'])) ?>?')">Buy</button></form></div>
<?php endforeach; if (!$products): ?><p>No products available yet.</p><?php endif; ?>
</div>
<?php user_end();
