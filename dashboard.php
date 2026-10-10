<?php require __DIR__ . '/lib.php';
$u = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        with_tx($pdo, function (PDO $pdo) use ($u) {
            $st = $pdo->prepare('SELECT coins FROM users WHERE id = ? FOR UPDATE');
            $st->execute([$u['id']]); $coins = (float)$st->fetchColumn();
            $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1 FOR UPDATE');
            $st->execute([(int)($_POST['product_id'] ?? 0)]); $p = $st->fetch();
            if (!$p) throw new RuntimeException('Product not found.');
            if ($p['stock'] !== null && (int)$p['stock'] < 1) throw new RuntimeException('Sorry, this product is out of stock.');
            if ((int)round($coins * 100) < (int)round((float)$p['price'] * 100)) throw new RuntimeException('Not enough balance. Please add funds first.');
            $pdo->prepare('UPDATE users SET coins = coins - ? WHERE id = ?')->execute([$p['price'], $u['id']]);
            if ($p['stock'] !== null) $pdo->prepare('UPDATE products SET stock = stock - 1 WHERE id = ?')->execute([$p['id']]);
            $pdo->prepare('INSERT INTO orders (user_id, product_id, product_name, price) VALUES (?,?,?,?)')
                ->execute([$u['id'], $p['id'], $p['name'], $p['price']]);
            add_tx($pdo, (int)$u['id'], 'purchase', -(float)$p['price'], 'Order: ' . $p['name']);
        });
        flash('ok', 'Order placed. You can track it in My Orders.');
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('BUY ERROR: ' . $ex->getMessage());
        flash('err', 'Something went wrong. Please try again.');
    }
    $back = array_filter(['c' => (int)($_POST['c'] ?? 0), 'q' => mb_substr(trim($_POST['q'] ?? ''), 0, 80)], fn($v) => $v !== 0 && $v !== '');
    header('Location: /dashboard.php' . ($back ? '?' . http_build_query($back) : '')); exit; // redirect: refresh must not buy again
}

$c = (int)($_GET['c'] ?? 0);
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 80);

$cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.active = 1) AS n
                     FROM categories c WHERE c.active = 1 ORDER BY c.sort_order, c.id")->fetchAll();
$total = (int)$pdo->query("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id
                           WHERE p.active = 1 AND (p.category_id IS NULL OR c.active = 1)")->fetchColumn();

$where = ['p.active = 1', '(p.category_id IS NULL OR c.active = 1)']; $args = [];
if ($c) { $where[] = 'p.category_id = ?'; $args[] = $c; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ? OR p.id = ?)';
    array_push($args, $like, $like, $like, ctype_digit($q) ? (int)$q : 0);
}
$s = $pdo->prepare('SELECT p.* FROM products p LEFT JOIN categories c ON c.id = p.category_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY (p.category_id IS NULL), c.sort_order, c.id, p.popular DESC, p.id DESC LIMIT 300');
$s->execute($args);
$rows = $s->fetchAll();

user_start('Home', $u, 'dashboard'); ?>
<div class="card wallet bbar"><div><small>Available balance</small><b><?= money($u['coins']) ?></b></div><a class="btn sm" href="/deposits.php">+ Add Funds</a></div>
<?php catalog_html($cats, $rows, $c, $q, $total); ?>
<script>var a=document.querySelector('.chip.on');if(a&&a.scrollIntoView)a.scrollIntoView({inline:'center',block:'nearest'});</script>
<?php user_end();
