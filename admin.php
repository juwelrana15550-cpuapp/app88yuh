<?php require __DIR__ . '/lib.php';
require __DIR__ . '/stock_import.php';
$GLOBALS['__bdt_only'] = true;   // admin always sees real BDT amounts, whatever currency the browser picked on the shop
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
        'auto'    => empty($_POST['auto_delivery']) ? 0 : 1,   // 1 = deliver uploaded stock instantly
        'icon'    => (function () {   // '' = automatic (guessed from the name, else the category icon)
            $ic = trim($_POST['picon'] ?? '');
            if ($ic === '') return '';
            if (strncmp($ic, 'app:', 4) === 0) return isset(app_icons()[substr($ic, 4)]) ? $ic : '';
            return mb_substr($ic, 0, 8);
        })(),
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
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !empty($_SESSION['admin'])) {
        flash('err', 'That upload is bigger than the server allows (limit ' . ini_get('post_max_size') . '). Please upload it in smaller parts.');
        header('Location: /admin.php?p=products'); exit;
    }
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
            $rate = null;
            if (!$setErr) {
                $rr = trim((string)($_POST['usd_rate'] ?? ''));
                if ($rr !== '') {
                    $rv = (float)str_replace(',', '', $rr);
                    if ($rv < 1 || $rv > 100000) $setErr = 'Dollar rate must be between 1 and 100000 (how many BDT equal 1 USD).';
                    else $rate = $rv;
                }
            }
            if (!$setErr) { // nothing is saved unless everything is valid
                save_setting('site_name', $name);
                save_setting('usd_rate', $rate === null ? '' : (string)$rate);
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
                    'cat' => $f['cat'] ?? '', 'stock' => $f['stock'] ?? '', 'pop' => $f['popular'], 'auto' => $f['auto'], 'icon' => $f['icon'],
                ]];
            } elseif ($isEdit) {
                $pid = (int)$_POST['update_product'];
                $pdo->prepare('UPDATE products SET name=?, description=?, price=?, category_id=?, stock=?, unit=?, popular=?, auto_delivery=?, icon=? WHERE id=?')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['auto'], $f['icon'], $pid]);
                if ($f['auto']) stock_sync($pdo, $pid);   // stock = unsold uploaded items
                go('products', 'Product saved.');
            } else {
                $pdo->prepare('INSERT INTO products (name, description, price, category_id, stock, unit, popular, auto_delivery, icon) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['auto'], $f['icon']]);
                if ($f['auto']) stock_sync($pdo, (int)$pdo->lastInsertId());
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

        } elseif (isset($_POST['upload_stock'])) {
            $page = 'products';
            try {
                $got = stock_collect((string)($_POST['stock_text'] ?? ''), $_FILES['stock_file'] ?? null, !empty($_POST['skip_header']));
                if (!$got['lines']) throw new RuntimeException('Nothing to add. Paste some items or choose a file.');
                [$added, $dup] = stock_add($pdo, (int)$_POST['upload_stock'], $got['lines'], !empty($_POST['dedupe']));
                $msg = number_format($added) . ' item' . ($added === 1 ? '' : 's') . ' added. This product now delivers instantly.';
                if ($dup) $msg .= ' ' . number_format($dup) . ' duplicate' . ($dup === 1 ? '' : 's') . ' skipped.';
                if ($got['long']) $msg .= ' ' . number_format($got['long']) . ' line(s) skipped (over ' . STOCK_MAX_LEN . ' characters).';
                go('products', $msg);
            } catch (RuntimeException $ex) { flash('err', $ex->getMessage()); go('products'); }

        } elseif (isset($_POST['clear_stock'])) {
            $pid = (int)$_POST['clear_stock'];
            with_tx($pdo, function (PDO $pdo) use ($pid) {
                $pdo->prepare('DELETE FROM stock_items WHERE product_id = ? AND order_id IS NULL')->execute([$pid]);
                stock_sync_if_auto($pdo, $pid);
            });
            go('products', 'Unsold items removed.');

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
                        $pdo->prepare("UPDATE orders SET status='delivered', delivery=? WHERE id=?")->execute([mb_substr(trim($_POST['delivery'] ?? ''), 0, 500000), $oid]);
                    } else {
                        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$o['price'], $o['user_id']]);
                        add_tx($pdo, (int)$o['user_id'], 'refund', (float)$o['price'], 'Refund for order #' . $oid);
                        $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL AND auto_delivery = 0')->execute([max(1, (int)$o['qty']), $o['product_id']]);
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

/** Icon picker (searchable app icons + emoji). $auto adds an "Automatic" choice (stored as an empty value). */
function icon_picker_html(string $pre, string $field, string $initial, bool $auto): string {
    ob_start(); ?>
<div class="ipk" id="<?= e($pre) ?>">
  <button type="button" class="ipk-btn" id="<?= e($pre) ?>Btn" aria-expanded="false" aria-controls="<?= e($pre) ?>Pop"><span class="ipk-cur" id="<?= e($pre) ?>Cur"></span><span class="ipk-name" id="<?= e($pre) ?>Name">Select icon</span><?= ai('chev') ?></button>
  <div class="ipk-pop" id="<?= e($pre) ?>Pop" hidden>
    <div class="srch"><?= ai('search') ?><input type="search" id="<?= e($pre) ?>Q" placeholder="Search icons (gmail, facebook, netflix…)" autocomplete="off"></div>
    <?php if ($auto): ?><button type="button" class="btn sm ghost" id="<?= e($pre) ?>Auto" style="margin-top:10px">&#10024; Automatic (from product name / category)</button><?php endif; ?>
    <div class="ipk-scroll" id="<?= e($pre) ?>List">
    <?php $lastG = null; $open = false;
    foreach (app_icons() as $key => $d):
      if ($d[1] !== $lastG) { if ($open) echo '</div>'; echo '<div class="ipk-g" data-g>' . e($d[1]) . '</div><div class="ipk-grid">'; $lastG = $d[1]; $open = true; } ?>
      <button type="button" class="ipk-o" data-v="app:<?= e($key) ?>" data-n="<?= e($d[0]) ?>" title="<?= e($d[0]) ?>"><?= app_icon_svg($key, '40px') ?><span><?= e($d[0]) ?></span></button>
    <?php endforeach; if ($open) echo '</div>'; ?>
      <div class="ipk-none" id="<?= e($pre) ?>None">No icon found. Use an emoji below.</div>
    </div>
    <div class="ipk-em"><label for="<?= e($pre) ?>Emoji">Or type an emoji <small>(optional)</small></label><input type="text" id="<?= e($pre) ?>Emoji" maxlength="8" placeholder="📧"></div>
  </div>
  <input type="hidden" name="<?= e($field) ?>" id="<?= e($pre) ?>Val" value="<?= e($initial) ?>">
</div>
<?php return ob_get_clean();
}

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
    'settings'   => ['Site settings', 'Name, headline, dollar rate, support links, logo and banner'],
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
<style>*{-webkit-tap-highlight-color:transparent}a:focus,button:focus,summary:focus{outline:0}</style>
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
    <div class="mini"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?><?= (int)$o['qty'] > 1 ? ' × ' . (int)$o['qty'] : '' ?></b><small><?= e($o['email']) ?></small></div><span class="money"><?= money($o['price']) ?></span></div>
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
<div class="ord">
  <div class="ord-h"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?><?= (int)$o['qty'] > 1 ? ' × ' . (int)$o['qty'] : '' ?></b><p><?= e($o['email']) ?> · <?= e($o['created_at']) ?></p></div><span class="money"><?= money($o['price']) ?></span></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
  <textarea name="delivery" placeholder="Delivery data for the customer: one item per line, e.g. email|password (they can download it as TXT / CSV / XLSX)"></textarea>
  <div class="acts" style="margin-top:10px"><button class="btn sm ok-b" name="deliver" value="1">Mark delivered</button>
  <button class="btn sm red" name="cancel_order" value="1" onclick="return confirm('Cancel and refund?')">Cancel &amp; refund</button></div></form>
</div>
<?php endforeach; if (!$pendOrders): ?><div class="card"><div class="empty">No pending orders. 🎉</div></div><?php endif; ?>

<?php /* ============================ DEPOSITS ============================ */ elseif ($page === 'deposits'): ?>
<div class="card"><h3>Pending deposits</h3>
<div class="tw"><table><thead><tr><th>User</th><th>Amount</th><th class="hm">Note</th><th class="hm">Date</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['email']) ?></td><td class="money"><?= money($r['amount']) ?></td><td class="hm"><?= e($r['note']) ?></td><td class="hm"><?= e($r['created_at']) ?></td>
<td class="ra"><form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<button class="btn sm ok-b" name="action" value="approve">Approve</button>
<button class="btn sm red" name="action" value="reject">Reject</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5">Nothing pending.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h3>Recent decisions</h3>
<div class="tw"><table><thead><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach ($hist as $h): ?>
<tr><td><?= e($h['email']) ?></td><td class="money"><?= money($h['amount']) ?></td><td><span class="badge <?= e($h['status']) ?>"><?= e($h['status']) ?></span></td><td><?= e($h['created_at']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No history yet.</td></tr><?php endif; ?></tbody></table></div></div>

<?php /* ============================ PRODUCTS ============================ */ elseif ($page === 'products'): ?>
<div class="card">
  <div class="tb">
    <div class="srch grow"><?= ai('search') ?><input type="search" id="pq" placeholder="Search by name or ID" autocomplete="off"></div>
    <select id="pcat" style="width:auto;min-width:170px"><option value="">All categories</option><option value="0">Uncategorised</option>
      <?php foreach ($cats as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e(trim(cat_icon_text($k['icon']) . ' ' . $k['name'])) ?></option><?php endforeach; ?></select>
  </div>
  <div class="tw"><table id="ptable" class="rt"><thead><tr><th>Product</th><th class="hm">Category</th><th>Price</th><th class="hm">Stock</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($products as $p): $k = $catById[(int)$p['category_id']] ?? null;
    $pj = ['id' => (int)$p['id'], 'name' => $p['name'], 'desc' => (string)$p['description'], 'price' => (string)$p['price'], 'unit' => (string)$p['unit'],
           'cat' => $p['category_id'] ?? '', 'stock' => $p['stock'] === null ? '' : (int)$p['stock'], 'pop' => (int)$p['popular'], 'auto' => (int)$p['auto_delivery'], 'icon' => (string)($p['icon'] ?? '')];
    $sj = ['id' => (int)$p['id'], 'name' => $p['name'], 'left' => (int)$p['stock']]; ?>
  <tr data-s="<?= e(mb_strtolower($p['name'] . ' ' . $p['id'])) ?>" data-c="<?= (int)$p['category_id'] ?>">
    <td><div class="pn"><span class="ci"><?= cat_icon(product_icon_value($p, $k), '1em') ?></span><div><b><?= e($p['name']) ?></b><?php if ($p['popular']): ?><span class="tag">Popular</span><?php endif; ?><?php if ($p['auto_delivery']): ?><span class="tag auto">&#9889; Instant</span><?php endif; ?><small>ID <?= (int)$p['id'] ?><?= $p['unit'] !== '' ? ' · per ' . e($p['unit']) : '' ?></small></div></div></td>
    <td class="hm"><?= $k ? e($k['name']) : '<small>—</small>' ?></td>
    <td class="money"><?= money($p['price']) ?></td>
    <td class="hm"><?= $p['stock'] === null ? 'Unlimited' : number_format((int)$p['stock']) . ($p['auto_delivery'] ? ' <small>unsold</small>' : '') ?></td>
    <td><span class="badge <?= $p['active'] ? 'on' : 'off' ?>"><?= $p['active'] ? 'Visible' : 'Hidden' ?></span></td>
    <td class="ra"><div class="acts">
      <button type="button" class="btn sm ghost" data-stock="<?= e(json_encode($sj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('plus') ?>Stock</button>
      <button type="button" class="btn sm ghost" data-edit-p="<?= e(json_encode($pj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('pencil') ?>Edit</button>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="toggle_product" value="<?= (int)$p['id'] ?>"><?= $p['active'] ? 'Hide' : 'Show' ?></button></form>
    </div></td>
  </tr>
  <?php endforeach; ?></tbody></table></div>
  <div class="empty" id="pnone" <?= $products ? 'style="display:none"' : '' ?>>No products yet. Click “Add product” to create the first one.</div>
</div>

<dialog id="pdlg"><form method="post" class="dlg"><?= csrf_field() ?>
  <div class="dlg-h"><h3 id="pTitle">Add product</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <?php if ($prodErr): ?><div class="err" style="margin-top:12px"><?= e($prodErr) ?></div><?php endif; ?>
  <label>Name</label><input type="text" name="pname" id="p_name" maxlength="80" required>
  <label>Description <small>(optional)</small></label>
  <textarea name="pdesc" id="p_desc" maxlength="500" rows="3" placeholder="Fast activation&#10;24/7 support&#10;Instant delivery"></textarea>
  <small style="display:block;margin-top:4px">One line = one feature. Add 2+ lines to show a checklist (like the plans on the homepage); a single line shows as plain text.</small>
  <div class="pc-prev" id="p_descPrevWrap" hidden><small style="display:block;margin:10px 0 6px">Preview on the shop</small><div id="p_descPrev"></div></div>
  <style>
    /* Mirrors .pc-d / .pc-dl from the shop's style.css, scoped here so the admin preview matches the real product card. */
    .pc-prev{background:#f8fafc;border:1px solid #eef0f4;border-radius:12px;padding:12px 14px}
    .pc-prev .pc-d{color:#64748b;font-size:.82rem;line-height:1.45;overflow-wrap:anywhere;margin:0}
    .pc-prev .pc-dl{list-style:none;padding:0;margin:0;font-size:.82rem;color:#1e293b}
    .pc-prev .pc-dl li{position:relative;padding:5px 0 5px 20px;border-bottom:1px solid #eef0f4;line-height:1.4}
    .pc-prev .pc-dl li:last-child{border-bottom:0}
    .pc-prev .pc-dl li:before{content:"✓";position:absolute;left:0;top:5px;color:#16a34a;font-weight:800}
  </style>
  <div class="grid2"><div><label>Price (৳)</label><input type="number" name="pprice" id="p_price" step="0.01" min="0.01" required></div>
  <div><label>Unit <small>(e.g. email)</small></label><input type="text" name="unit" id="p_unit" maxlength="20" placeholder="email"></div></div>
  <div class="grid2"><div><label>Category</label><select name="category_id" id="p_cat"><?= $catOpts(0) ?></select></div>
  <div><label>Stock <small>(blank = unlimited)</small></label><input type="number" name="stock" id="p_stock" min="0" step="1"></div></div>
  <label>App icon <small>(pick one, or leave Automatic - it follows the product name)</small></label>
  <style>
    .ipk-q{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px}
    .ipk-qb{width:46px;height:46px;padding:0;border:1.5px solid #e5e2f3;border-radius:12px;background:#fff;cursor:pointer;display:grid;place-items:center}
    .ipk-qb:hover{border-color:#cfc6f7}
    .ipk-qb.sel{border-color:var(--brand);background:var(--brand-soft)}
    .ipk-qb svg{width:30px;height:30px;display:block}
  </style>
  <div class="ipk-q" id="pikQuick" role="group" aria-label="Quick icons"><?php foreach (['gmail','facebook','instagram','tiktok','telegram','whatsapp','youtube','netflix','spotify','shield','gift','crown'] as $qk): if (!isset(app_icons()[$qk])) continue; ?><button type="button" class="ipk-qb" data-v="app:<?= e($qk) ?>" title="<?= e(app_icons()[$qk][0]) ?>" aria-label="<?= e(app_icons()[$qk][0]) ?>"><?= app_icon_svg($qk, '30px') ?></button><?php endforeach; ?></div>
  <?= icon_picker_html('pik', 'picon', '', true) ?>
  <label class="chk" style="margin-top:16px"><input type="checkbox" name="popular" id="p_pop" value="1"> <span>Mark as Popular</span></label>
  <label class="chk" style="margin-top:12px"><input type="checkbox" name="auto_delivery" id="p_auto" value="1"> <span>Instant delivery from uploaded stock</span></label>
  <small id="p_autoNote" style="display:block;margin-top:4px">Stock = number of unsold uploaded items. Use the “Stock” button on the product to upload them.</small>
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="pSave" name="add_product" value="1">Save product</button></div>
</form></dialog>

<dialog id="sdlg"><form method="post" enctype="multipart/form-data" class="dlg"><?= csrf_field() ?>
  <div class="dlg-h"><h3>Add stock</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <p id="sInfo" class="sinfo"></p>
  <label>Paste items <small>(one per line, e.g. <span class="mono">mail@gmail.com|password</span>)</small></label>
  <textarea name="stock_text" id="s_text" class="stk-ta" spellcheck="false" placeholder="mail1@gmail.com|password1&#10;mail2@gmail.com|password2"></textarea>
  <label>…or upload a file <small>(.txt, .csv or .xlsx — first sheet is used)</small></label>
  <input type="file" name="stock_file" id="s_file" accept=".txt,.csv,.tsv,.xlsx,text/plain,text/csv">
  <label class="chk" style="margin-top:14px"><input type="checkbox" name="dedupe" value="1" checked> <span>Skip items that are already in stock</span></label>
  <label class="chk" style="margin-top:8px"><input type="checkbox" name="skip_header" value="1"> <span>First row is a header — skip it</span></label>
  <small class="sinfo2">When a customer buys, the oldest unsold items are delivered to them <b>instantly</b> and removed from stock. For spreadsheets, each row becomes one item (columns joined with “|”).</small>
  <div class="dlg-f sf"><button type="submit" class="btn ghost red-t" id="sClear" name="clear_stock" value="" formnovalidate>Clear unsold</button><span class="grow"></span>
    <button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="sSave" name="upload_stock" value="">Add to stock</button></div>
</form></dialog>

<?php /* ============================ CATEGORIES ============================ */ elseif ($page === 'categories'): ?>
<div class="card">
  <div class="tw"><table class="rt"><thead><tr><th>Category</th><th class="hm">Products</th><th class="hm">Sort</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($cats as $k):
    $cj = ['id' => (int)$k['id'], 'name' => $k['name'], 'icon' => $k['icon'], 'sort' => (int)$k['sort_order']]; ?>
  <tr>
    <td><div class="pn"><span class="ci"><?= cat_icon($k['icon'], '1em') ?></span><div><b><?= e($k['name']) ?></b><small><?= e(cat_icon_label($k['icon'])) ?></small></div></div></td>
    <td class="hm"><?= (int)$k['n'] ?></td><td class="hm"><?= (int)$k['sort_order'] ?></td>
    <td><span class="badge <?= $k['active'] ? 'on' : 'off' ?>"><?= $k['active'] ? 'Visible' : 'Hidden' ?></span></td>
    <td class="ra"><form method="post" class="acts"><?= csrf_field() ?>
      <button type="button" class="btn sm ghost" data-edit-c="<?= e(json_encode($cj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('pencil') ?>Edit</button>
      <button class="btn sm ghost" name="toggle_category" value="<?= (int)$k['id'] ?>"><?= $k['active'] ? 'Hide' : 'Show' ?></button>
      <button class="btn sm red" name="delete_category" value="<?= (int)$k['id'] ?>" onclick="return confirm('Delete this category? Its products stay but become uncategorised.')" aria-label="Delete"><?= ai('trash') ?></button>
    </form></td>
  </tr>
  <?php endforeach; if (!$cats): ?><tr><td colspan="5">No categories yet. Click “Add category”, then assign products to it.</td></tr><?php endif; ?></tbody></table></div>
</div>

<dialog id="cdlg"><form method="post" class="dlg"><?= csrf_field() ?>
  <div class="dlg-h"><h3 id="cTitle">Add category</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <?php if ($catErr): ?><div class="err" style="margin-top:12px"><?= e($catErr) ?></div><?php endif; ?>
  <label>Name</label><input type="text" name="cname" id="c_name" maxlength="40" required placeholder="e.g. Gmail Account">
  <label>Icon</label>
  <?= icon_picker_html('ipk', 'cicon', 'app:shop', false) ?>
  <label>Sort order <small>(smaller shows first)</small></label><input type="number" name="csort" id="c_sort" value="0">
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="cSave" name="add_category" value="1">Save category</button></div>
</form></dialog>

<?php /* ============================ USERS ============================ */ elseif ($page === 'users'): ?>
<div class="card">
  <div class="tb"><div class="srch grow"><?= ai('search') ?><input type="search" id="uq" placeholder="Search by email or ID" autocomplete="off"></div></div>
  <div class="tw"><table id="utable"><thead><tr><th>#</th><th>Email</th><th>Balance</th><th class="hm">Referrals</th><th class="hm">Joined</th></tr></thead><tbody>
  <?php foreach ($users as $x): ?>
  <tr data-s="<?= e(mb_strtolower($x['email'] . ' ' . $x['id'])) ?>"><td><?= (int)$x['id'] ?></td><td><?= e($x['email']) ?></td><td class="money"><?= money($x['coins']) ?></td><td class="hm"><?= (int)$x['refs'] ?></td><td class="hm"><?= e($x['created_at']) ?></td></tr>
  <?php endforeach; if (!$users): ?><tr><td colspan="5">No users yet.</td></tr><?php endif; ?></tbody></table></div>
  <div class="empty" id="unone" style="display:none">No matching users.</div>
</div>

<?php /* ============================ SETTINGS ============================ */ elseif ($page === 'settings'): ?>
<?php if ($setErr): ?><div class="err"><?= e($setErr) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="card"><h3>General</h3>
  <label style="margin-top:0">Site name</label><input type="text" name="site_name" maxlength="40" required value="<?= e($_POST['site_name'] ?? site_name()) ?>">
  <label>Headline <small>(homepage)</small></label><input type="text" name="tagline" maxlength="120" value="<?= $sv('tagline') ?>">
  <label>Sub-headline</label><input type="text" name="subtitle" maxlength="300" value="<?= $sv('subtitle') ?>">
</div>
<div class="card"><h3>Currency</h3>
  <label style="margin-top:0">Dollar rate <small>(1 USD = how many BDT)</small></label>
  <input type="number" name="usd_rate" step="0.0001" min="1" inputmode="decimal" placeholder="e.g. 120" value="<?= e($_POST['usd_rate'] ?? setting('usd_rate')) ?>">
  <small style="display:block;margin-top:6px">Users can switch between BDT and $ in the top bar. Everything is still charged in BDT; the $ view is converted with this rate. Leave blank to hide the $ option.</small>
</div>
<div class="card"><h3>Support links</h3>
  <label style="margin-top:0">Telegram support link</label><input type="text" name="telegram_url" value="<?= $sv('telegram_url') ?>" placeholder="https://t.me/your_username">
  <label>WhatsApp support link</label><input type="text" name="whatsapp_url" value="<?= $sv('whatsapp_url') ?>" placeholder="https://wa.me/8801XXXXXXXXX">
  <label>Footer support link <small>(optional)</small></label><input type="text" name="support_url" value="<?= $sv('support_url') ?>">
</div>
<div class="card"><h3>Branding</h3>
  <label style="margin-top:0">Logo <small>(PNG/JPG/WEBP, up to 1 MB)</small></label>
  <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
  <?php if ($l = media_url('logo')): ?><img class="preview" src="<?= e($l) ?>" alt="Logo"><label class="rm"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label><?php endif; ?>
  <label>Homepage banner <small>(PNG/JPG/WEBP, up to 2 MB, wide image)</small></label>
  <input type="file" name="banner" accept="image/png,image/jpeg,image/webp,image/gif">
  <?php if ($bn = media_url('banner')): ?><img class="preview" src="<?= e($bn) ?>" alt="Banner"><label class="rm"><input type="checkbox" name="remove_banner" value="1"> Remove banner</label><?php endif; ?>
  <div class="savebar"><button class="btn" name="save_settings" value="1">Save settings</button></div>
</div>
</form>
<?php endif; ?>
</main>
</div>

<script>
(function(){
  var $=function(s,r){return (r||document).querySelector(s)}, $$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};
  var REOPEN = <?= json_encode($reopen, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  document.addEventListener('keydown',function(e){ if(e.key==='Escape') document.body.classList.remove('menu'); });
  $$('.s-nav a').forEach(function(a){ a.addEventListener('click',function(){ document.body.classList.remove('menu'); }); });

  /* dialogs: close buttons + click on backdrop */
  $$('dialog').forEach(function(d){
    $$('[data-close]',d).forEach(function(b){ b.addEventListener('click',function(){ d.close(); }); });
    d.addEventListener('click',function(e){ if(e.target===d) d.close(); });
  });


  /* icon picker: prefix = element id prefix, defVal = value used when nothing is chosen ('' = automatic) */
  function picker(pre,defVal,guess){
    var hid=$('#'+pre+'Val'), cur=$('#'+pre+'Cur'), nm=$('#'+pre+'Name'), pop=$('#'+pre+'Pop'), btn=$('#'+pre+'Btn'), em=$('#'+pre+'Emoji'),
        q=$('#'+pre+'Q'), list=$('#'+pre+'List'), none=$('#'+pre+'None'), auto=$('#'+pre+'Auto'), opts=$$('.ipk-o',list);
    function setIcon(v){
      v=(v===undefined||v===null)?defVal:String(v); hid.value=v;
      opts.forEach(function(o){ o.classList.toggle('sel',o.dataset.v===v); });
      function show(opt){
        cur.innerHTML=opt.querySelector('svg').outerHTML.replace(/ai[0-9a-f]+_\d+[gs]/g,function(m){return m+pre;});
        var sv=cur.firstChild; sv.setAttribute('width','32'); sv.setAttribute('height','32');
      }
      if(v===''){
        em.value='';
        var g=guess?guess():'', go=g?opts.filter(function(o){return o.dataset.v==='app:'+g;})[0]:null;
        if(go){ show(go); nm.textContent='Automatic \u00B7 '+go.dataset.n; } else { cur.textContent='\u2728'; nm.textContent='Automatic'; }
        return;
      }
      if(v.indexOf('app:')===0){
        var opt=opts.filter(function(o){return o.dataset.v===v;})[0];
        if(!opt){ if(v===defVal) return; return setIcon(defVal); }
        show(opt); nm.textContent=opt.dataset.n; em.value='';
      } else { cur.textContent=v; nm.textContent='Emoji'; em.value=v; }
    }
    function srch(t){
      t=t.trim().toLowerCase(); var any=false;
      opts.forEach(function(o){ var ok=!t||o.dataset.n.toLowerCase().indexOf(t)>-1||o.dataset.v.indexOf(t)>-1; o.style.display=ok?'':'none'; if(ok)any=true; });
      $$('.ipk-grid',list).forEach(function(g){ var vis=$$('.ipk-o',g).some(function(o){return o.style.display!=='none';}); g.style.display=vis?'':'none'; g.previousElementSibling.style.display=vis?'':'none'; });
      none.style.display=any?'none':'block';
    }
    function toggle(open){ pop.hidden=!open; btn.setAttribute('aria-expanded',open?'true':'false'); if(open){ q.value=''; srch(''); } }
    btn.addEventListener('click',function(){ toggle(pop.hidden); });
    q.addEventListener('input',function(){ srch(q.value); });
    opts.forEach(function(o){ o.addEventListener('click',function(){ setIcon(o.dataset.v); toggle(false); }); });
    if(auto) auto.addEventListener('click',function(){ setIcon(''); toggle(false); });
    em.addEventListener('input',function(){ var v=em.value.trim(); setIcon(v?v:defVal); });
    setIcon(hid.value===''?defVal:hid.value);
    return {set:setIcon, close:function(){ toggle(false); }, refresh:function(){ if(hid.value==='') setIcon(''); }};
  }

  /* ---------- products ---------- */
  var pd=$('#pdlg');
  if(pd){
    var ICONS=<?= json_encode(array_map(fn($d) => $d[0], app_icons()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var ALIAS={twitter:'x',vpn:'shield',hotmail:'outlook',office:'microsoft','prime video':'amazon','gift card':'gift'};
    var guessIcon=function(){   // same rule the shop uses when no icon is chosen
      var n=($('#p_name').value||'').toLowerCase(); if(!n) return '';
      for(var w in ALIAS){ if(n.indexOf(w)>-1 && ICONS[ALIAS[w]]) return ALIAS[w]; }
      for(var k in ICONS){ if(k==='shop') continue; var ws=[k,ICONS[k].toLowerCase()]; for(var i=0;i<2;i++){ if(ws[i].length>=3 && n.indexOf(ws[i])>-1) return k; } }
      return '';
    };
    var pp=picker('pik','',guessIcon);
    var quickSync=function(){ var cv=$('#pikVal').value; $$('#pikQuick .ipk-qb').forEach(function(b){ b.classList.toggle('sel',b.dataset.v===cv); }); };
    $$('#pikQuick .ipk-qb').forEach(function(b){ b.addEventListener('click',function(){ pp.set(b.dataset.v); pp.close(); quickSync(); }); });
    $('#pik').addEventListener('click',function(){ setTimeout(quickSync,0); });
    $('#p_name').addEventListener('input',function(){ pp.refresh(); });
    pd.addEventListener('close',function(){ pp.close(); });
    var autoSync=function(){ var on=$('#p_auto').checked; $('#p_stock').disabled=on; $('#p_autoNote').style.display=on?'block':'none'; if(on) $('#p_stock').value=''; };
    $('#p_auto').addEventListener('change',autoSync);
    var descTa=$('#p_desc'), descPrev=$('#p_descPrev'), descWrap=$('#p_descPrevWrap');
    var descEsc=function(s){ return s.replace(/[&<>"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
    var renderDescPrev=function(){
      var lines=descTa.value.split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
      descWrap.hidden = lines.length===0;
      if(lines.length>1) descPrev.innerHTML='<ul class="pc-dl">'+lines.slice(0,6).map(function(l){ return '<li>'+descEsc(l)+'</li>'; }).join('')+'</ul>';
      else descPrev.innerHTML='<p class="pc-d">'+descEsc(lines[0]||'')+'</p>';
    };
    descTa.addEventListener('input',renderDescPrev);
    var fill=function(v,id){
      $('#pTitle').textContent = id ? 'Edit product' : 'Add product';
      $('#p_name').value=v.name||''; $('#p_desc').value=v.desc||''; $('#p_price').value=v.price||'';
      $('#p_unit').value=v.unit||''; $('#p_cat').value=(v.cat===null||v.cat===undefined)?'':v.cat;
      $('#p_stock').value=(v.stock===null||v.stock===undefined)?'':v.stock; $('#p_pop').checked=!!+v.pop;
      $('#p_auto').checked=!!+v.auto; autoSync(); pp.set(v.icon||''); pp.close(); quickSync(); renderDescPrev();
      var s=$('#pSave'); s.name = id ? 'update_product' : 'add_product'; s.value = id ? id : '1';
      pd.showModal();
    };
    $('#addProduct').addEventListener('click',function(){ fill({},0); });
    $$('[data-edit-p]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editP); fill({name:v.name,desc:v.desc,price:v.price,unit:v.unit,cat:v.cat,stock:v.stock,pop:v.pop,auto:v.auto,icon:v.icon}, v.id); }); });
    if(REOPEN && REOPEN.t==='product') fill(REOPEN.v, REOPEN.id);

    /* stock upload dialog */
    var sd=$('#sdlg'), sClear=$('#sClear');
    $$('[data-stock]').forEach(function(b){ b.addEventListener('click',function(){
      var v=JSON.parse(b.dataset.stock);
      $('#sInfo').innerHTML='<b></b> &middot; <span></span>'; $('#sInfo b').textContent=v.name; $('#sInfo span').textContent=v.left.toLocaleString('en-US')+' unsold item'+(v.left===1?'':'s')+' in stock';
      $('#s_text').value=''; $('#s_file').value='';
      $('#sSave').value=v.id; sClear.value=v.id; sClear.style.display=v.left>0?'':'none'; sClear.dataset.left=v.left;
      sd.showModal();
    }); });
    sClear.addEventListener('click',function(e){ if(!confirm('Delete all '+Number(sClear.dataset.left).toLocaleString('en-US')+' unsold items of this product?')) e.preventDefault(); });
    $('#sSave').addEventListener('click',function(e){ if(!$('#s_text').value.trim() && !$('#s_file').value){ e.preventDefault(); alert('Paste some items or choose a file first.'); } });

    var q=$('#pq'), c=$('#pcat'), none=$('#pnone');
    var filt=function(){
      var t=q.value.trim().toLowerCase(), cv=c.value, n=0, rows=$$('#ptable tbody tr');
      rows.forEach(function(r){ var ok=(!t||r.dataset.s.indexOf(t)>-1)&&(cv===''||r.dataset.c===cv); r.style.display=ok?'':'none'; if(ok)n++; });
      none.textContent='No products match your search.'; none.style.display = (rows.length && !n) ? '' : 'none';
    };
    q.addEventListener('input',filt); c.addEventListener('change',filt);
  }

  /* ---------- users ---------- */
  var uq=$('#uq');
  if(uq){ uq.addEventListener('input',function(){
    var t=uq.value.trim().toLowerCase(), n=0, rows=$$('#utable tbody tr[data-s]');
    rows.forEach(function(r){ var ok=!t||r.dataset.s.indexOf(t)>-1; r.style.display=ok?'':'none'; if(ok)n++; });
    $('#unone').style.display = (rows.length && !n) ? '' : 'none';
  }); }

  /* ---------- categories ---------- */
  var cd=$('#cdlg');
  if(cd){
    var cp=picker('ipk','app:shop');
    cd.addEventListener('close',function(){ cp.close(); });
    var fillC=function(v,id){
      $('#cTitle').textContent = id ? 'Edit category' : 'Add category';
      $('#c_name').value=v.name||''; $('#c_sort').value=(v.sort===undefined||v.sort===null)?0:v.sort;
      cp.set(v.icon||'app:shop'); cp.close();
      var s=$('#cSave'); s.name = id ? 'update_category' : 'add_category'; s.value = id ? id : '1';
      cd.showModal();
    };
    $('#addCategory').addEventListener('click',function(){ fillC({},0); });
    $$('[data-edit-c]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editC); fillC(v,v.id); }); });
    if(REOPEN && REOPEN.t==='category') fillC(REOPEN.v, REOPEN.id);
  }
})();
</script>
</body></html>
