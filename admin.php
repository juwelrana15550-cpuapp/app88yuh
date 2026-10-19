<?php require __DIR__ . '/lib.php';
require __DIR__ . '/supplier_hot.php';
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = ''; $setErr = ''; $prodErr = ''; $catErr = ''; $ordErr = ''; $supErr = ''; $supTest = null;
$page = null;      // which admin page to show (set on errors so the user stays where they were)
$reopen = null;    // re-open the edit dialog with the submitted values after a validation error

const ADMIN_PAGES = ['overview', 'orders', 'deposits', 'products', 'categories', 'users', 'settings', 'supplier'];

/** Redirect to an admin page, optionally with a success message. */
function go(string $p, ?string $ok = null): void {
    if ($ok) flash('ok', $ok);
    header('Location: /admin.php' . ($p === 'overview' ? '' : '?p=' . $p));
    exit;
}

function product_fields(): array {
    $st = trim($_POST['stock'] ?? '');
    $sp = explode('|', trim($_POST['supplier_link'] ?? ''), 2);
    $src  = $_POST['supplier_src'] ?? '';                       // '' = manual, 'hot' = Supplier 1, 'bm' = Supplier 2 (bulkmail.shop)
    $okSp = $src === 'hot' && count($sp) === 2 && preg_match('/^[\w.\-]{1,40}$/', $sp[0]) && preg_match('/^[\w.\-]{1,40}$/', $sp[1]);
    $bmId = $src === 'bm' ? max(0, (int)($_POST['bm_id'] ?? 0)) : 0;
    return [
        'src'     => $src === 'hot' ? 'hot' : ($src === 'bm' ? 'bm' : ''),
        'sup_id'  => $bmId ?: null,
        'name'    => trim($_POST['pname'] ?? ''),
        'desc'    => mb_substr(trim($_POST['pdesc'] ?? ''), 0, 500),
        'price'   => round((float)($_POST['pprice'] ?? 0), 2),
        'cat'     => ((int)($_POST['category_id'] ?? 0)) ?: null,
        'stock'   => $st === '' ? null : max(0, (int)$st),   // blank = unlimited
        'unit'    => mb_substr(trim($_POST['unit'] ?? ''), 0, 20),
        'popular' => empty($_POST['popular']) ? 0 : 1,
        'sup_t'   => $okSp ? $sp[0] : null,
        'sup_a'   => $okSp ? $sp[1] : null,
        // Supplier 2 only: keep name / description + Possible Domain in step with the supplier; show the domain line (default on)
        'an'      => ($src === 'bm' && !empty($_POST['sup_an'])) ? 1 : 0,
        'ad'      => ($src === 'bm' && !empty($_POST['sup_ad'])) ? 1 : 0,
        'sd'      => ($src === 'bm' && empty($_POST['sup_sd'])) ? 0 : 1,
        // price follows the supplier: supplier USD price x BDT rate x (1 + markup %)
        'ap'      => ($src === 'bm' && !empty($_POST['sup_ap'])) ? 1 : 0,
        'mk'      => ($src === 'bm') ? min(1000.0, max(0.0, round((float)($_POST['sup_mk'] ?? 0), 2))) : 0.0,
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

if (!empty($_SESSION['admin'])) { suphot_schema(); bm_schema(db()); }
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

        } elseif (isset($_POST['bm_sync_now']) || isset($_POST['bm_save_rate'])) {
            $page = 'supplier';
            if (isset($_POST['bm_save_rate'])) {
                $rt = round((float)str_replace(',', '.', (string)($_POST['bm_rate'] ?? '0')), 4);
                if ($rt < 0 || $rt > 100000) { $supErr = 'Enter a valid rate (৳ for 1 USD).'; }
                else {
                    $mkp = round(max(0.0, min(1000.0, (float)str_replace(',', '.', (string)($_POST['bm_markup'] ?? '0')))), 2);
                    save_setting('bm_rate', (string)$rt);
                    save_setting('bm_markup', (string)$mkp);
                    save_setting('bm_price_all', empty($_POST['bm_price_all']) ? '0' : '1');
                    if (bm_connected()) bm_sync_details($pdo, 1000);
                    go('supplier', $rt > 0 ? 'Saved. Prices updated.' : 'Saved (no rate set, so price sync is paused).');
                }
            } else {
                if (!bm_connected()) { $supErr = 'Connect Supplier 2 first.'; }
                else {
                    save_setting('bm_last_error', '');
                    bm_sync_stock($pdo, true);
                    $nChg = bm_sync_details($pdo, 1000);
                    $eNow = (string)setting('bm_last_error', '');
                    go('supplier', 'Sync finished. ' . $nChg . ' product(s) updated.' . ($eNow !== '' ? ' ' . $eNow : ''));
                }
            }

        } elseif (isset($_POST['bm_connect']) || isset($_POST['bm_disconnect'])) {
            $page = 'supplier';
            if (isset($_POST['bm_disconnect'])) { bm_disconnect($pdo); go('supplier', 'Supplier 2 disconnected.'); }
            [$okB, $msgB] = bm_connect($pdo, (string)($_POST['bm_key'] ?? ''));
            if ($okB) go('supplier', $msgB);
            $supErr = $msgB;

        } elseif (isset($_POST['save_supplier']) || isset($_POST['test_supplier'])) {
            $page = 'supplier';
            unset($_SESSION['sup_cat']);
            if (isset($_POST['save_supplier'])) {
                $u = rtrim(trim($_POST['supplier_url'] ?? ''), '/');
                $k = trim($_POST['supplier_key'] ?? '');
                if ($u !== '' && !preg_match('#^https://[^\s?\#]+$#i', $u)) {
                    $supErr = 'API URL must start with https:// (example: https://supplier-site.com/api/v1).';
                } else {
                    save_setting('suphot_url', $u);
                    if (!empty($_POST['remove_key'])) save_setting('suphot_key', '');
                    elseif ($k !== '') save_setting('suphot_key', $k);
                    save_setting('suphot_auto', empty($_POST['supplier_auto']) ? '0' : '1');
                    go('supplier', 'Supplier settings saved.');
                }
            } else {
                $supTest = suphot_call('GET', '/balance', [], null, 15);
            }

        } elseif (isset($_POST['add_product']) || isset($_POST['update_product'])) {
            $page = 'products';
            $isEdit = isset($_POST['update_product']);
            $f = product_fields();
            $linkErr = '';
            if ($f['src'] === 'hot' && !$f['sup_t']) $linkErr = 'Choose a Supplier 1 product.';
            if ($f['src'] === 'bm') {
                if (!$f['sup_id']) $linkErr = 'Enter the Supplier 2 (bulkmail.shop) product ID.';
                elseif (!bm_connected()) $linkErr = 'Connect Supplier 2 first (Admin > Supplier API).';
                else { $chk = bm_product((int)$f['sup_id']); if (isset($chk['error'])) $linkErr = $chk['error']; }
            }
            if ($f['name'] === '' || mb_strlen($f['name']) > 80 || $f['price'] <= 0 || $linkErr !== '') {
                $prodErr = $linkErr !== '' ? $linkErr : 'Enter a product name (max 80 chars) and a price above 0.';
                $reopen = ['t' => 'product', 'id' => $isEdit ? (int)$_POST['update_product'] : 0, 'v' => [
                    'name' => $f['name'], 'desc' => $f['desc'], 'price' => (string)($_POST['pprice'] ?? ''), 'unit' => $f['unit'],
                    'cat' => $f['cat'] ?? '', 'stock' => $f['stock'] ?? '', 'pop' => $f['popular'],
                    'src' => $f['src'], 'sup' => $f['sup_t'] ? $f['sup_t'] . '|' . $f['sup_a'] : '', 'bmid' => $f['sup_id'] ?? '', 'an' => $f['an'], 'ad' => $f['ad'], 'sd' => $f['sd'], 'ap' => $f['ap'], 'mk' => $f['mk'],
                ]];
            } else {
                // A product is linked to exactly one supplier: the other supplier's link is always cleared.
                if ($f['src'] === 'bm') { $f['sup_t'] = null; $f['sup_a'] = null; } else { $f['sup_id'] = null; }
                if ($f['src'] === 'bm' && $f['stock'] === null) $f['stock'] = (int)($chk['stock'] ?? 0);   // live stock is synced from the supplier anyway
                if ($isEdit) {
                    $pdo->prepare('UPDATE products SET name=?, description=?, price=?, category_id=?, stock=?, unit=?, popular=?, supplier_type=?, supplier_account=?, supplier_id=?, sup_auto_name=?, sup_auto_desc=?, sup_show_dom=?, sup_auto_price=?, sup_markup=? WHERE id=?')
                        ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['sup_t'], $f['sup_a'], $f['sup_id'], $f['an'], $f['ad'], $f['sd'], $f['ap'], $f['mk'], (int)$_POST['update_product']]);
                    $msg = 'Product saved.';
                } else {
                    $pdo->prepare('INSERT INTO products (name, description, price, category_id, stock, unit, popular, supplier_type, supplier_account, supplier_id, sup_auto_name, sup_auto_desc, sup_show_dom, sup_auto_price, sup_markup) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['sup_t'], $f['sup_a'], $f['sup_id'], $f['an'], $f['ad'], $f['sd'], $f['ap'], $f['mk']]);
                    $msg = 'Product added.';
                }
                if ($f['src'] === 'bm') bm_sync_stock($pdo, true);
                if ($f['src'] === 'bm' && ($f['an'] || $f['ad'] || $f['ap']) && function_exists('bm_sync_details')) bm_sync_details($pdo, 30);   // name / description / domain now
                if ($f['src'] === 'hot') suphot_sync_stock($pdo, true);
                go('products', $msg);
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

        } elseif (isset($_POST['supplier_deliver'])) {
            $oidS = (int)($_POST['order_id'] ?? 0);
            $ps = $pdo->prepare('SELECT p.supplier_id FROM orders o JOIN products p ON p.id = o.product_id WHERE o.id = ?');
            $ps->execute([$oidS]);
            if ((int)$ps->fetchColumn() > 0) {   // linked to Supplier 2 (bulkmail.shop)
                if (!bm_connected()) { $okS = false; $msgS = 'Supplier 2 is not connected (Admin > Supplier API).'; }
                else { $rb = bm_fulfil($pdo, $oidS); $okS = $rb['status'] !== 'pending'; $msgS = $rb['status'] === 'pending' ? ($rb['msg'] . ' Check Supplier 2 before retrying.') : $rb['msg']; }
            } else {                              // Supplier 1
                [$okS, $msgS] = suphot_fulfill_order($oidS);
            }
            if ($okS) go('orders', $msgS);
            $page = 'orders'; $ordErr = $msgS;

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
$pendOrders = $pdo->query("SELECT o.*, u.email, p.supplier_type AS sup_t, p.supplier_id AS sup_id FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN products p ON p.id = o.product_id WHERE o.status='pending' ORDER BY o.id")->fetchAll();
$coins = (float)$pdo->query('SELECT COALESCE(SUM(coins),0) FROM users')->fetchColumn();
$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$supOpts = $page === 'products' ? suphot_options($products) : '';
if ($page === 'products') { bm_sync_stock($pdo); suphot_sync_stock($pdo); }   // Supplier 2 products: live stock (at most once a minute)
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
        'plug'    => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'ext'     => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    ];
    return isset($x[$n]) ? '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . $x[$n] . '</svg>' : icon($n);
}

$nav = [
    ''        => [['overview', 'Overview', 'gauge', 0]],
    'Sales'   => [['orders', 'Orders', 'cart', count($pendOrders)], ['deposits', 'Deposits', 'coins', (int)$pend['c']]],
    'Catalog' => [['products', 'Products', 'bag', 0], ['categories', 'Categories', 'grid', 0]],
    'Manage'  => [['users', 'Users', 'users', 0], ['settings', 'Site settings', 'sliders', 0], ['supplier', 'Supplier API', 'plug', 0]],
];
$titles = [
    'overview'   => ['Overview', 'Store activity at a glance'],
    'orders'     => ['Orders', 'Deliver or cancel pending orders'],
    'deposits'   => ['Deposits', 'Approve or reject wallet deposits'],
    'products'   => ['Products', 'Add, edit, hide and organise what you sell'],
    'categories' => ['Categories', 'Group products and choose their icons'],
    'users'      => ['Users', 'Latest 100 registered users'],
    'settings'   => ['Site settings', 'Name, headline, support links, logo and banner'],
    'supplier'   => ['Supplier API', 'Connect two suppliers; each product is bought from the supplier it is linked to'],
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
<?php if ($ordErr): ?><div class="err"><?= e($ordErr) ?></div><?php endif; ?>
<?php foreach ($pendOrders as $o): ?>
<div class="ord">
  <div class="ord-h"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?></b><p><?= e($o['email']) ?> · <?= e($o['created_at']) ?></p></div><span class="money"><?= money($o['price']) ?></span></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
  <textarea name="delivery" placeholder="Delivery details shown to the customer (account email/password, license key, instructions...)"></textarea>
  <div class="acts" style="margin-top:10px"><?php if (!empty($o['sup_t']) || !empty($o['sup_id'])): ?><button class="btn sm" name="supplier_deliver" value="1" onclick="return confirm('Buy this from the supplier now? Your supplier balance will be charged.')">Auto-deliver (Supplier <?= !empty($o['sup_id']) ? '2' : '1' ?>)</button><?php endif; ?>
  <button class="btn sm ok-b" name="deliver" value="1">Mark delivered</button>
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
  <div class="tw"><table id="ptable"><thead><tr><th>Product</th><th class="hm">Category</th><th>Price</th><th class="hm">Stock</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($products as $p): $k = $catById[(int)$p['category_id']] ?? null;
    $pj = ['id' => (int)$p['id'], 'name' => $p['name'], 'desc' => (string)$p['description'], 'price' => (string)$p['price'], 'unit' => (string)$p['unit'],
           'cat' => $p['category_id'] ?? '', 'stock' => $p['stock'] === null ? '' : (int)$p['stock'], 'pop' => (int)$p['popular'],
           'src' => !empty($p['supplier_id']) ? 'bm' : (!empty($p['supplier_type']) ? 'hot' : ''),
           'sup' => !empty($p['supplier_type']) ? $p['supplier_type'] . '|' . $p['supplier_account'] : '',
           'bmid' => !empty($p['supplier_id']) ? (int)$p['supplier_id'] : '',
           'an' => (int)($p['sup_auto_name'] ?? 0), 'ad' => (int)($p['sup_auto_desc'] ?? 0), 'sd' => (int)($p['sup_show_dom'] ?? 1), 'ap' => (int)($p['sup_auto_price'] ?? 0), 'mk' => (string)($p['sup_markup'] ?? '0')]; ?>
  <tr data-s="<?= e(mb_strtolower($p['name'] . ' ' . $p['id'])) ?>" data-c="<?= (int)$p['category_id'] ?>">
    <td><div class="pn"><span class="ci"><?= cat_icon($k['icon'] ?? '', '1em') ?></span><div><b><?= e($p['name']) ?></b><?php if ($p['popular']): ?><span class="tag">Popular</span><?php endif; ?><?php if (!empty($p['supplier_id'])): ?><span class="tag">Supplier 2</span><?php elseif (!empty($p['supplier_type'])): ?><span class="tag">Supplier 1</span><?php endif; ?><small>ID <?= (int)$p['id'] ?><?= $p['unit'] !== '' ? ' · per ' . e($p['unit']) : '' ?></small></div></div></td>
    <td class="hm"><?= $k ? e($k['name']) : '<small>—</small>' ?></td>
    <td class="money"><?= money($p['price']) ?></td>
    <td class="hm"><?= $p['stock'] === null ? 'Unlimited' : number_format((int)$p['stock']) ?></td>
    <td><span class="badge <?= $p['active'] ? 'on' : 'off' ?>"><?= $p['active'] ? 'Visible' : 'Hidden' ?></span></td>
    <td class="ra"><div class="acts">
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
  <label>Description <small>(optional)</small></label><input type="text" name="pdesc" id="p_desc" maxlength="500">
  <div class="grid2"><div><label>Price (৳)</label><input type="number" name="pprice" id="p_price" step="0.01" min="0.01" required></div>
  <div><label>Unit <small>(e.g. email)</small></label><input type="text" name="unit" id="p_unit" maxlength="20" placeholder="email"></div></div>
  <div class="grid2"><div><label>Category</label><select name="category_id" id="p_cat"><?= $catOpts(0) ?></select></div>
  <div><label>Stock <small>(blank = unlimited)</small></label><input type="number" name="stock" id="p_stock" min="0" step="1"></div></div>
  <label>Supplier <small>(orders for this product are bought from the chosen supplier)</small></label>
  <select name="supplier_src" id="p_src"><option value="">— None (manual delivery) —</option><option value="hot">Supplier 1</option><option value="bm">Supplier 2 (bulkmail.shop)</option></select>
  <div id="p_src_hot" hidden><label>Supplier 1 product</label><select name="supplier_link" id="p_sup"><?= $supOpts ?></select></div>
  <div id="p_src_bm" hidden><label>Supplier 2 product ID</label><input type="number" name="bm_id" id="p_bmid" min="1" step="1" placeholder="e.g. 12"><small>Stock is shown live from Supplier 2.</small>
    <label class="chk" style="margin-top:12px"><input type="checkbox" name="sup_an" id="p_an" value="1"> <span>Keep the name the same as Supplier 2</span></label>
    <label class="chk"><input type="checkbox" name="sup_ad" id="p_ad" value="1"> <span>Keep the description and Possible Domain the same as Supplier 2</span></label>
    <label class="chk"><input type="checkbox" name="sup_ap" id="p_ap" value="1"> <span>Keep the price in step with Supplier 2 (supplier price × rate + profit %, set in Supplier API)</span></label>
    <label class="chk"><input type="checkbox" name="sup_sd" id="p_sd" value="1" checked> <span>Show the Possible Domain line on the product card</span></label>
  </div>
  <label class="chk" style="margin-top:16px"><input type="checkbox" name="popular" id="p_pop" value="1"> <span>Mark as Popular</span></label>
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="pSave" name="add_product" value="1">Save product</button></div>
</form></dialog>

<?php /* ============================ CATEGORIES ============================ */ elseif ($page === 'categories'): ?>
<div class="card">
  <div class="tw"><table><thead><tr><th>Category</th><th class="hm">Products</th><th class="hm">Sort</th><th>Status</th><th></th></tr></thead><tbody>
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
  <div class="ipk" id="ipk">
    <button type="button" class="ipk-btn" id="ipkBtn" aria-expanded="false" aria-controls="ipkPop"><span class="ipk-cur" id="ipkCur"></span><span class="ipk-name" id="ipkName">Select icon</span><?= ai('chev') ?></button>
    <div class="ipk-pop" id="ipkPop" hidden>
      <div class="srch"><?= ai('search') ?><input type="search" id="ipkQ" placeholder="Search icons (gmail, facebook, netflix…)" autocomplete="off"></div>
      <div class="ipk-scroll" id="ipkList">
      <?php $lastG = null; $open = false;
      foreach (app_icons() as $key => $d):
        if ($d[1] !== $lastG) { if ($open) echo '</div>'; echo '<div class="ipk-g" data-g>' . e($d[1]) . '</div><div class="ipk-grid">'; $lastG = $d[1]; $open = true; } ?>
        <button type="button" class="ipk-o" data-v="app:<?= e($key) ?>" data-n="<?= e($d[0]) ?>" title="<?= e($d[0]) ?>"><?= app_icon_svg($key, '40px') ?><span><?= e($d[0]) ?></span></button>
      <?php endforeach; if ($open) echo '</div>'; ?>
        <div class="ipk-none" id="ipkNone">No icon found. Use an emoji below.</div>
      </div>
      <div class="ipk-em"><label for="ipkEmoji">Or type an emoji <small>(optional)</small></label><input type="text" id="ipkEmoji" maxlength="8" placeholder="📧"></div>
    </div>
    <input type="hidden" name="cicon" id="c_icon" value="app:shop">
  </div>
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

<?php /* ============================ SUPPLIER ============================ */ elseif ($page === 'supplier'):
  $supUrl = (string)setting('suphot_url'); $hasKey = (string)setting('suphot_key') !== '';
  $supAuto = setting('suphot_auto') === '1'; ?>
<?php if ($supErr): ?><div class="err"><?= e($supErr) ?></div><?php endif; ?>
<?php if ($supTest): ?>
  <?php if ($supTest['ok']): ?><div class="card"><span class="badge on">Connected</span> Supplier balance: <b><?= e((string)($supTest['data']['balance_formatted'] ?? $supTest['data']['balance'] ?? '?')) ?></b> <small><?= e((string)($supTest['data']['email'] ?? '')) ?></small></div>
  <?php else: ?><div class="err">Connection failed: <?= e($supTest['error']) ?></div><?php endif; ?>
<?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><h3>Supplier 1 — Connection</h3>
  <label style="margin-top:0">Supplier API URL</label><input type="text" name="supplier_url" value="<?= e($_POST['supplier_url'] ?? $supUrl) ?>" placeholder="https://supplier-site.com/api/v1">
  <small>Base URL ending in /api/v1, no trailing slash.</small>
  <label>API key</label><input type="password" name="supplier_key" autocomplete="off" placeholder="<?= $hasKey ? 'Saved. Leave blank to keep it' : 'Paste your API key' ?>">
  <?php if ($hasKey): ?><label class="rm"><input type="checkbox" name="remove_key" value="1"> Remove saved key</label><?php endif; ?>
  <label class="chk"><input type="checkbox" name="supplier_auto" value="1"<?= $supAuto ? ' checked' : '' ?>> <span>Auto-deliver new orders for supplier-linked products</span></label>
  <div class="savebar"><button class="btn" name="save_supplier" value="1">Save</button> <button class="btn ghost" name="test_supplier" value="1">Test connection</button></div>
</div>
</form>
<?php $sc = ($hasKey && $supUrl !== '') ? suphot_catalog() : null; ?>
<div class="card"><h3>Supplier 1 — catalogue</h3>
<?php if ($sc === null): ?><div class="empty">Save the API URL and key first.</div>
<?php elseif (!$sc['ok']): ?><div class="err"><?= e($sc['error']) ?></div>
<?php else: ?><div class="tw"><table><thead><tr><th>Name</th><th class="hm">Type</th><th>Cost</th><th>Stock</th></tr></thead><tbody>
  <?php foreach ($sc['items'] as $it): ?>
  <tr><td><?= e((string)($it['name'] ?? '')) ?></td><td class="hm"><small><?= e(($it['product_type'] ?? '') . ' / ' . ($it['account_type'] ?? '')) ?></small></td><td class="money"><?= e((string)($it['price'] ?? '')) ?></td><td><?= number_format((int)($it['stock'] ?? 0)) ?></td></tr>
  <?php endforeach; if (!$sc['items']): ?><tr><td colspan="4">No products returned.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?></div>

<?php $bmOn = bm_connected(); $bmBal = $bmOn ? bm_balance() : null; $bmErr = (string)setting('bm_last_error', ''); ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><h3>Supplier 2 — bulkmail.shop</h3>
  <?php if ($bmOn): ?>
    <p><span class="badge on">Connected</span> Key: <b><?= e(bm_mask(bm_key())) ?></b><?php if ($bmBal): ?> · Balance: <b><?= e(number_format($bmBal['balance'], 2) . ' ' . $bmBal['currency']) ?></b><?php endif; ?></p>
    <?php if ($bmErr !== ''): ?><div class="err"><?= e($bmErr) ?></div><?php endif; ?>
    <label>USD → ৳ rate <small>(how many ৳ equal 1 USD)</small></label>
    <input type="number" name="bm_rate" step="0.0001" min="0" value="<?= e(rtrim(rtrim(number_format(bm_rate(), 4, '.', ''), '0'), '.')) ?>" placeholder="e.g. 125">
    <label>Profit % on all Supplier 2 products <small>(e.g. 20 = supplier price + 20%)</small></label>
    <input type="number" name="bm_markup" step="0.01" min="0" max="1000" value="<?= e(rtrim(rtrim(number_format(bm_markup(), 2, '.', ''), '0'), '.')) ?>" placeholder="e.g. 20">
    <label class="chk"><input type="checkbox" name="bm_price_all" value="1"<?= bm_price_all() ? ' checked' : '' ?>> <span>Apply to ALL Supplier 2 products (price follows the supplier automatically)</span></label>
    <div class="savebar"><button class="btn" name="bm_save_rate" value="1">Save rate &amp; profit</button> <button class="btn ghost" name="bm_sync_now" value="1">Sync now (name · price · description · domain · stock)</button></div>
    <small>Linked products also refresh automatically in the background (stock about every minute, name / price / description about every 2 minutes).</small>
    <div class="savebar"><button class="btn red" name="bm_disconnect" value="1" onclick="return confirm('Disconnect Supplier 2? Its linked products will show as out of stock.')">Disconnect</button></div>
  <?php else: ?>
    <label style="margin-top:0">API key</label><input type="password" name="bm_key" autocomplete="off" placeholder="Paste the bulkmail.shop API key">
    <small>Then open Products and link each product to Supplier 2 by its product ID.</small>
    <div class="savebar"><button class="btn" name="bm_connect" value="1">Connect</button></div>
  <?php endif; ?>
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

  /* ---------- products ---------- */
  var pd=$('#pdlg');
  if(pd){
    var showSrc=function(){ var s=$('#p_src').value; $('#p_src_hot').hidden=s!=='hot'; $('#p_src_bm').hidden=s!=='bm'; };
    $('#p_src').addEventListener('change',showSrc);
    var fill=function(v,id){
      $('#pTitle').textContent = id ? 'Edit product' : 'Add product';
      $('#p_name').value=v.name||''; $('#p_desc').value=v.desc||''; $('#p_price').value=v.price||'';
      $('#p_unit').value=v.unit||''; $('#p_cat').value=(v.cat===null||v.cat===undefined)?'':v.cat;
      $('#p_stock').value=(v.stock===null||v.stock===undefined)?'':v.stock; $('#p_pop').checked=!!+v.pop; $('#p_sup').value=v.sup||'';
      $('#p_src').value=v.src||''; $('#p_bmid').value=v.bmid||'';
      $('#p_ap').checked=!!+v.ap; $('#p_an').checked=!!+v.an; $('#p_ad').checked=!!+v.ad; $('#p_sd').checked=(v.sd===undefined||v.sd===null)?true:!!+v.sd; showSrc();
      var s=$('#pSave'); s.name = id ? 'update_product' : 'add_product'; s.value = id ? id : '1';
      pd.showModal();
    };
    $('#addProduct').addEventListener('click',function(){ fill({},0); });
    $$('[data-edit-p]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editP); fill({name:v.name,desc:v.desc,price:v.price,unit:v.unit,cat:v.cat,stock:v.stock,pop:v.pop,src:v.src,sup:v.sup,bmid:v.bmid,an:v.an,ad:v.ad,sd:v.sd,ap:v.ap,mk:v.mk}, v.id); }); });
    if(REOPEN && REOPEN.t==='product') fill(REOPEN.v, REOPEN.id);

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

  /* ---------- categories + premium icon picker ---------- */
  var cd=$('#cdlg');
  if(cd){
    var hid=$('#c_icon'), cur=$('#ipkCur'), nm=$('#ipkName'), pop=$('#ipkPop'), btn=$('#ipkBtn'), em=$('#ipkEmoji');
    var esc=function(s){ return String(s).replace(/[&<>"']/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}); };
    var setIcon=function(v){
      v=v||'app:shop'; hid.value=v;
      var opt=null; $$('.ipk-o').forEach(function(o){ var on=o.dataset.v===v; o.classList.toggle('sel',on); if(on)opt=o; });
      if(v.indexOf('app:')===0){
        if(!opt){ opt=$('.ipk-o[data-v="app:shop"]'); hid.value='app:shop'; }
        cur.innerHTML=opt.querySelector('svg').outerHTML.replace(/ai\d+[gs]/g,function(m){return m+'p';});
        var sv=cur.firstChild; sv.setAttribute('width','32'); sv.setAttribute('height','32');
        nm.textContent=opt.dataset.n; em.value='';
      } else { cur.textContent=v; nm.textContent='Emoji'; em.value=v; }
    };
    var toggle=function(open){ pop.hidden=!open; btn.setAttribute('aria-expanded',open?'true':'false'); if(open){ $('#ipkQ').value=''; srch(''); } };
    var srch=function(t){
      t=t.trim().toLowerCase(); var any=false;
      $$('.ipk-o').forEach(function(o){ var ok=!t||o.dataset.n.toLowerCase().indexOf(t)>-1||o.dataset.v.indexOf(t)>-1; o.style.display=ok?'':'none'; if(ok)any=true; });
      $$('#ipkList .ipk-grid').forEach(function(g){ var vis=$$('.ipk-o',g).some(function(o){return o.style.display!=='none';}); g.style.display=vis?'':'none'; g.previousElementSibling.style.display=vis?'':'none'; });
      $('#ipkNone').style.display=any?'none':'block';
    };
    btn.addEventListener('click',function(){ toggle(pop.hidden); });
    $('#ipkQ').addEventListener('input',function(e){ srch(e.target.value); });
    $$('.ipk-o').forEach(function(o){ o.addEventListener('click',function(){ setIcon(o.dataset.v); toggle(false); }); });
    em.addEventListener('input',function(){ var v=em.value.trim(); if(v){ setIcon(v); } else { setIcon('app:shop'); } });
    cd.addEventListener('close',function(){ toggle(false); });

    var fillC=function(v,id){
      $('#cTitle').textContent = id ? 'Edit category' : 'Add category';
      $('#c_name').value=v.name||''; $('#c_sort').value=(v.sort===undefined||v.sort===null)?0:v.sort;
      setIcon(v.icon||'app:shop'); toggle(false);
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
