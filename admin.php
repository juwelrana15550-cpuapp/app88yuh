<?php require __DIR__ . '/lib.php';
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = ''; $setErr = ''; $prodErr = ''; $catErr = '';
$page = null;      // which admin page to show (set on errors so the user stays where they were)
$reopen = null;    // re-open the edit dialog with the submitted values after a validation error

const ADMIN_PAGES = ['overview', 'orders', 'deposits', 'products', 'categories', 'users', 'settings'];

/** Redirect to an admin page, optionally with a success message. */
function go(string $p, ?string $ok = null): void {
    if ($ok) flash('ok', $ok);
    header('Location: /admin.php' . ($p === 'overview' ? '' : '?p=' . $p));
    exit;
}

function product_fields(): array {
    $st = trim($_POST['stock'] ?? '');
    return [
        'name'    => trim($_POST['pname'] ?? ''),
        'desc'    => mb_substr(trim($_POST['pdesc'] ?? ''), 0, 500),
        'price'   => round((float)($_POST['pprice'] ?? 0), 2),
        'cat'     => ((int)($_POST['category_id'] ?? 0)) ?: null,
        'stock'   => $st === '' ? null : max(0, (int)$st),   // blank = unlimited
        'unit'    => mb_substr(trim($_POST['unit'] ?? ''), 0, 20),
        'popular' => empty($_POST['popular']) ? 0 : 1,
    ];
}
function category_fields(): array {
    $ic = trim($_POST['cicon'] ?? '');
    if (strncmp($ic, 'app:', 4) === 0) {
        if (!isset(app_icons()[substr($ic, 4)])) $ic = 'app:shop';   // unknown key -> default
    } else {
        $ic = mb_substr($ic, 0, 8) ?: 'app:shop';                    // plain emoji
    }
    return [
        'name' => trim($_POST['cname'] ?? ''),
        'icon' => $ic,
        'sort' => (int)($_POST['csort'] ?? 0),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['admin_logout'])) { unset($_SESSION['admin']); header('Location: /admin.php'); exit; }

    if (isset($_POST['admin_pw'])) {
        if (hash_equals($adminPw, (string)$_POST['admin_pw'])) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: /admin.php'); exit; }
        sleep(1); $err = 'Wrong password.';

    } elseif (!empty($_SESSION['admin'])) {
        $pdo = db();

        if (isset($_POST['save_settings'])) {
            $page = 'settings';
            $name = trim($_POST['site_name'] ?? '');
            $uploads = []; $removes = [];
            if ($name === '' || mb_strlen($name) > 40) {
                $setErr = 'Site name is required (max 40 characters).';
            } else {
                foreach (['logo' => 1048576, 'banner' => 2097152] as $k => $max) {
                    if (!empty($_POST['remove_' . $k])) { $removes[] = $k; continue; }
                    $f = $_FILES[$k] ?? null;
                    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) continue;
                    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > $max) { $setErr = ucfirst($k) . ' is too large (max ' . ($max / 1048576) . ' MB).'; break; }
                    $info = @getimagesize($f['tmp_name']);
                    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                        $setErr = ucfirst($k) . ' must be a PNG, JPG, WEBP or GIF image.'; break;
                    }
                    $uploads[$k] = [$info['mime'], file_get_contents($f['tmp_name'])];
                }
            }
            if (!$setErr) { // nothing is saved unless everything is valid
                save_setting('site_name', $name);
                save_setting('tagline', mb_substr(trim($_POST['tagline'] ?? ''), 0, 120));
                save_setting('subtitle', mb_substr(trim($_POST['subtitle'] ?? ''), 0, 300));
                foreach (['support_url', 'telegram_url', 'whatsapp_url'] as $k) {
                    $v = trim($_POST[$k] ?? '');
                    save_setting($k, preg_match('#^https?://#i', $v) ? $v : '');
                }
                foreach ($removes as $k) $pdo->prepare('DELETE FROM media WHERE k = ?')->execute([$k]);
                foreach ($uploads as $k => [$mime, $bin]) {
                    $st = $pdo->prepare('REPLACE INTO media (k, mime, data) VALUES (?,?,?)');
                    $st->bindValue(1, $k); $st->bindValue(2, $mime); $st->bindValue(3, $bin, PDO::PARAM_LOB);
                    $st->execute();
                }
                go('settings', 'Settings saved.');
            }

        } elseif (isset($_POST['add_product']) || isset($_POST['update_product'])) {
            $page = 'products';
            $isEdit = isset($_POST['update_product']);
            $f = product_fields();
            if ($f['name'] === '' || mb_strlen($f['name']) > 80 || $f['price'] <= 0) {
                $prodErr = 'Enter a product name (max 80 chars) and a price above 0.';
                $reopen = ['t' => 'product', 'id' => $isEdit ? (int)$_POST['update_product'] : 0, 'v' => [
                    'name' => $f['name'], 'desc' => $f['desc'], 'price' => (string)($_POST['pprice'] ?? ''), 'unit' => $f['unit'],
                    'cat' => $f['cat'] ?? '', 'stock' => $f['stock'] ?? '', 'pop' => $f['popular'],
                ]];
            } elseif ($isEdit) {
                $pdo->prepare('UPDATE products SET name=?, description=?, price=?, category_id=?, stock=?, unit=?, popular=? WHERE id=?')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], (int)$_POST['update_product']]);
                go('products', 'Product saved.');
            } else {
                $pdo->prepare('INSERT INTO products (name, description, price, category_id, stock, unit, popular) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular']]);
                go('products', 'Product added.');
            }

        } elseif (isset($_POST['add_category']) || isset($_POST['update_category'])) {
            $page = 'categories';
            $isEdit = isset($_POST['update_category']);
            $f = category_fields();
            if ($f['name'] === '' || mb_strlen($f['name']) > 40) {
                $catErr = 'Enter a category name (max 40 chars).';
                $reopen = ['t' => 'category', 'id' => $isEdit ? (int)$_POST['update_category'] : 0, 'v' => ['name' => $f['name'], 'icon' => $f['icon'], 'sort' => $f['sort']]];
            } elseif ($isEdit) {
                $pdo->prepare('UPDATE categories SET name=?, icon=?, sort_order=? WHERE id=?')->execute([$f['name'], $f['icon'], $f['sort'], (int)$_POST['update_category']]);
                go('categories', 'Category saved.');
            } else {
                $pdo->prepare('INSERT INTO categories (name, icon, sort_order) VALUES (?,?,?)')->execute([$f['name'], $f['icon'], $f['sort']]);
                go('categories', 'Category added.');
            }

        } elseif (isset($_POST['toggle_category'])) {
            $pdo->prepare('UPDATE categories SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['toggle_category']]);
            go('categories', 'Category visibility updated.');

        } elseif (isset($_POST['delete_category'])) {
            $cid = (int)$_POST['delete_category'];
            with_tx($pdo, function (PDO $pdo) use ($cid) {
                $pdo->prepare('UPDATE products SET category_id = NULL WHERE category_id = ?')->execute([$cid]);
                $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$cid]);
            });
            go('categories', 'Category deleted.');

        } elseif (isset($_POST['toggle_product'])) {
            $pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['toggle_product']]);
            go('products', 'Product visibility updated.');

        } elseif (isset($_POST['deliver']) || isset($_POST['cancel_order'])) {
            $oid = (int)($_POST['order_id'] ?? 0);
            with_tx($pdo, function (PDO $pdo) use ($oid) {
                $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending' FOR UPDATE");
                $st->execute([$oid]);
                if ($o = $st->fetch()) {
                    if (isset($_POST['deliver'])) {
                        $pdo->prepare("UPDATE orders SET status='delivered', delivery=? WHERE id=?")->execute([mb_substr(trim($_POST['delivery'] ?? ''), 0, 4000), $oid]);
                    } else {
                        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$o['price'], $o['user_id']]);
                        add_tx($pdo, (int)$o['user_id'], 'refund', (float)$o['price'], 'Refund for order #' . $oid);
                        $pdo->prepare('UPDATE products SET stock = stock + 1 WHERE id = ? AND stock IS NOT NULL')->execute([$o['product_id']]);
                    }
                }
            });
            go('orders', isset($_POST['deliver']) ? 'Order marked as delivered.' : 'Order cancelled and refunded.');

        } elseif (isset($_POST['id'], $_POST['action'])) {
            with_tx($pdo, function (PDO $pdo) use ($bonus) {
                $st = $pdo->prepare("SELECT * FROM deposits WHERE id = ? AND status = 'pending' FOR UPDATE");
                $st->execute([(int)$_POST['id']]);
                if ($d = $st->fetch()) {
                    if ($_POST['action'] === 'approve') {
                        $pdo->prepare("UPDATE deposits SET status='approved' WHERE id=?")->execute([$d['id']]);
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$d['amount'], $d['user_id']]);
                        add_tx($pdo, (int)$d['user_id'], 'deposit', (float)$d['amount'], 'Deposit #' . $d['id']);
                        $us = $pdo->prepare('SELECT referred_by, has_deposited FROM users WHERE id = ? FOR UPDATE');
                        $us->execute([$d['user_id']]); $usr = $us->fetch();
                        if (!$usr['has_deposited']) {
                            $pdo->prepare('UPDATE users SET has_deposited = 1 WHERE id = ?')->execute([$d['user_id']]);
                            if ($usr['referred_by']) {
                                $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$bonus, $usr['referred_by']]);
                                add_tx($pdo, (int)$usr['referred_by'], 'bonus', $bonus, 'Referral bonus');
                            }
                        }
                    } else {
                        $pdo->prepare("UPDATE deposits SET status='rejected' WHERE id=?")->execute([$d['id']]);
                    }
                }
            });
            go('deposits', $_POST['action'] === 'approve' ? 'Deposit approved.' : 'Deposit rejected.');
        }
    }
}

/* ============================== Login screen ============================== */
if (empty($_SESSION['admin'])) {
    header_html('Admin Login', null, 'auth'); ?>
<div class="card">
<?= logo_html('🛡️') ?>
<h2>Admin Panel</h2>
<p class="sub">Enter the admin password to continue</p>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Password</label>
<div class="pw"><input type="password" id="ap" name="admin_pw" required><button type="button" data-toggle="#ap">Show</button></div>
<button class="btn">Login</button></form>
</div>
<?php footer_html(); exit; }

/* ============================== Data ============================== */
$pdo = db();
if ($page === null) {
    $page = $_GET['p'] ?? 'overview';
    if (!in_array($page, ADMIN_PAGES, true)) $page = 'overview';
}
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pend = $pdo->query("SELECT COUNT(*) c FROM deposits WHERE status='pending'")->fetch();
$pendOrders = $pdo->query("SELECT o.*, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.status='pending' ORDER BY o.id")->fetchAll();
$coins = (float)$pdo->query('SELECT COALESCE(SUM(coins),0) FROM users')->fetchColumn();
$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY c.sort_order, c.id')->fetchAll();
$catById = []; foreach ($cats as $k) $catById[(int)$k['id']] = $k;
$catOpts = function ($sel) use ($cats) {
    $h = '<option value="">— No category —</option>';
    foreach ($cats as $k) {
        $h .= '<option value="' . (int)$k['id'] . '"' . ((int)$sel === (int)$k['id'] ? ' selected' : '') . '>' . e(trim(cat_icon_text($k['icon']) . ' ' . $k['name'])) . '</option>';
    }
    return $h;
};
$rows = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status='pending' ORDER BY d.id")->fetchAll();
$users = $pdo->query('SELECT u.id, u.email, u.coins, u.created_at, (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.id) AS refs FROM users u ORDER BY u.id DESC LIMIT 100')->fetchAll();
$hist = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status <> 'pending' ORDER BY d.id DESC LIMIT 30")->fetchAll();

/** Admin-only icons (everything else comes from icon() in lib.php). */
function ai(string $n): string {
    static $x = [
        'grid'    => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'sliders' => '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
        'pencil'  => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
        'trash'   => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'chev'    => '<path d="m6 9 6 6 6-6"/>',
        'ext'     => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    ];
    return isset($x[$n]) ? '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . $x[$n] . '</svg>' : icon($n);
}

$nav = [
    ''        => [['overview', 'Overview', 'gauge', 0]],
    'Sales'   => [['orders', 'Orders', 'cart', count($pendOrders)], ['deposits', 'Deposits', 'coins', (int)$pend['c']]],
    'Catalog' => [['products', 'Products', 'bag', 0], ['categories', 'Categories', 'grid', 0]],
    'Manage'  => [['users', 'Users', 'users', 0], ['settings', 'Site settings', 'sliders', 0]],
];
$titles = [
    'overview'   => ['Overview', 'Store activity at a glance'],
    'orders'     => ['Orders', 'Deliver or cancel pending orders'],
    'deposits'   => ['Deposits', 'Approve or reject wallet deposits'],
    'products'   => ['Products', 'Add, edit, hide and organise what you sell'],
    'categories' => ['Categories', 'Group products and choose their icons'],
    'users'      => ['Users', 'Latest 100 registered users'],
    'settings'   => ['Site settings', 'Name, headline, support links, logo and banner'],
];
$sv = fn(string $k) => e($_POST[$k] ?? setting($k));
$lg = media_url('logo');
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titles[$page][0]) ?> - Admin - <?= e(site_name()) ?></title>
<link rel="stylesheet" href="/admin.css?v=<?= is_file(__DIR__ . '/admin.css') ? filemtime(__DIR__ . '/admin.css') : 1 ?>">
<?= icon_tags() ?>
</head><body class="adm">
<?= app_icon_sprite() ?>
<div class="shell">
<div class="shade" onclick="document.body.classList.remove('menu')"></div>
<aside class="side">
  <div class="s-brand">
    <div class="lg"><?php if ($lg): ?><img src="<?= e($lg) ?>" alt=""><?php else: ?><?= cat_icon('app:shop', '40px') ?><?php endif; ?></div>
    <div><b><?= e(site_name()) ?></b><small>Admin panel</small></div>
  </div>
  <nav class="s-nav">
  <?php foreach ($nav as $grp => $items): ?>
    <?php if ($grp !== ''): ?><div class="s-grp"><?= e($grp) ?></div><?php endif; ?>
    <?php foreach ($items as [$key, $label, $ic, $count]): ?>
      <a href="/admin.php<?= $key === 'overview' ? '' : '?p=' . $key ?>" class="<?= $page === $key ? 'on' : '' ?>"<?= $page === $key ? ' aria-current="page"' : '' ?>><?= ai($ic) ?><span><?= e($label) ?></span><?php if ($count): ?><span class="cnt"><?= (int)$count ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </nav>
  <div class="s-foot">
    <a href="/" target="_blank" rel="noopener"><?= ai('ext') ?><span>View website</span></a>
    <form method="post" action="/admin.php"><?= csrf_field() ?><button class="lo" name="admin_logout" value="1"><?= ai('logout') ?><span>Logout</span></button></form>
  </div>
</aside>

<main class="main">
<div class="mtop"><button type="button" aria-label="Menu" onclick="document.body.classList.toggle('menu')"><?= ai('menu') ?></button><b><?= e($titles[$page][0]) ?></b></div>
<div class="ph">
  <div><h1><?= e($titles[$page][0]) ?></h1><p><?= e($titles[$page][1]) ?></p></div>
  <?php if ($page === 'products'): ?><button type="button" class="btn" id="addProduct"><?= ai('plus') ?>Add product</button><?php endif; ?>
  <?php if ($page === 'categories'): ?><button type="button" class="btn" id="addCategory"><?= ai('plus') ?>Add category</button><?php endif; ?>
</div>
<?php flash_html(); ?>

<?php /* ============================ OVERVIEW ============================ */ if ($page === 'overview'): ?>
<div class="stats">
  <a class="stat" href="/admin.php?p=users"><span>Total users</span><b><?= $totalUsers ?></b></a>
  <a class="stat <?= (int)$pend['c'] ? 'alert' : '' ?>" href="/admin.php?p=deposits"><span>Pending deposits</span><b><?= (int)$pend['c'] ?></b></a>
  <a class="stat <?= count($pendOrders) ? 'alert' : '' ?>" href="/admin.php?p=orders"><span>Pending orders</span><b><?= count($pendOrders) ?></b></a>
  <div class="stat"><span>Balance in wallets</span><b><?= money($coins) ?></b></div>
</div>
<div class="cols">
  <div class="card"><div class="card-h"><h3>Pending orders</h3><a href="/admin.php?p=orders">Open all</a></div>
  <?php foreach (array_slice($pendOrders, 0, 5) as $o): ?>
    <div class="mini"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?></b><small><?= e($o['email']) ?></small></div><span class="money"><?= money($o['price']) ?></span></div>
  <?php endforeach; if (!$pendOrders): ?><div class="empty">No pending orders.</div><?php endif; ?></div>
  <div class="card"><div class="card-h"><h3>Pending deposits</h3><a href="/admin.php?p=deposits">Open all</a></div>
  <?php foreach (array_slice($rows, 0, 5) as $r): ?>
    <div class="mini"><div><b><?= e($r['email']) ?></b><small><?= e($r['created_at']) ?></small></div><span class="money"><?= money($r['amount']) ?></span></div>
  <?php endforeach; if (!$rows): ?><div class="empty">Nothing pending.</div><?php endif; ?></div>
</div>
<div class="card" style="margin-top:18px"><div class="card-h"><h3>Recent deposit decisions</h3><a href="/admin.php?p=deposits">See more</a></div>
<div class="tw"><table><thead><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach (array_slice($hist, 0, 8) as $h): ?>
<tr><td><?= e($h['email']) ?></td><td class="money"><?= money($h['amount']) ?></td><td><span class="badge <?= e($h['status']) ?>"><?= e($h['status']) ?></span></td><td><?= e($h['created_at']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No history yet.</td></tr><?php endif; ?></tbody></table></div></div>

<?php /* ============================ ORDERS ============================ */ elseif ($page === 'orders'): ?>
<?php foreach ($pendOrders as $o): ?>
<div class="or
