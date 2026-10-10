<?php require __DIR__ . '/lib.php';
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = ''; $setErr = ''; $prodErr = ''; $catErr = '';

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
    return [
        'name' => trim($_POST['cname'] ?? ''),
        'icon' => mb_substr(trim($_POST['cicon'] ?? ''), 0, 8) ?: '📦',
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
                header('Location: /admin.php?saved=1#settings'); exit;
            }

        } elseif (isset($_POST['add_product'])) {
            $f = product_fields();
            if ($f['name'] === '' || mb_strlen($f['name']) > 80 || $f['price'] <= 0) { $prodErr = 'Enter a product name (max 80 chars) and a price above 0.'; }
            else {
                $pdo->prepare('INSERT INTO products (name, description, price, category_id, stock, unit, popular) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular']]);
                header('Location: /admin.php#products'); exit;
            }

        } elseif (isset($_POST['update_product'])) {
            $f = product_fields();
            if ($f['name'] === '' || mb_strlen($f['name']) > 80 || $f['price'] <= 0) { $prodErr = 'Enter a product name (max 80 chars) and a price above 0.'; }
            else {
                $pdo->prepare('UPDATE products SET name=?, description=?, price=?, category_id=?, stock=?, unit=?, popular=? WHERE id=?')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], (int)$_POST['update_product']]);
                header('Location: /admin.php#products'); exit;
            }

        } elseif (isset($_POST['add_category'])) {
            $f = category_fields();
            if ($f['name'] === '' || mb_strlen($f['name']) > 40) { $catErr = 'Enter a category name (max 40 chars).'; }
            else {
                $pdo->prepare('INSERT INTO categories (name, icon, sort_order) VALUES (?,?,?)')->execute([$f['name'], $f['icon'], $f['sort']]);
                header('Location: /admin.php#categories'); exit;
            }

        } elseif (isset($_POST['update_category'])) {
            $f = category_fields();
            if ($f['name'] === '' || mb_strlen($f['name']) > 40) { $catErr = 'Enter a category name (max 40 chars).'; }
            else {
                $pdo->prepare('UPDATE categories SET name=?, icon=?, sort_order=? WHERE id=?')->execute([$f['name'], $f['icon'], $f['sort'], (int)$_POST['update_category']]);
                header('Location: /admin.php#categories'); exit;
            }

        } elseif (isset($_POST['toggle_category'])) {
            $pdo->prepare('UPDATE categories SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['toggle_category']]);
            header('Location: /admin.php#categories'); exit;

        } elseif (isset($_POST['delete_category'])) {
            $cid = (int)$_POST['delete_category'];
            with_tx($pdo, function (PDO $pdo) use ($cid) {
                $pdo->prepare('UPDATE products SET category_id = NULL WHERE category_id = ?')->execute([$cid]);
                $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$cid]);
            });
            header('Location: /admin.php#categories'); exit;

        } elseif (isset($_POST['toggle_product'])) {
            $pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['toggle_product']]);
            header('Location: /admin.php#products'); exit;

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
            header('Location: /admin.php#orders'); exit;

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
            header('Location: /admin.php#deposits'); exit;
        }
    }
}

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

$pdo = db();
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pend = $pdo->query("SELECT COUNT(*) c FROM deposits WHERE status='pending'")->fetch();
$pendOrders = $pdo->query("SELECT o.*, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.status='pending' ORDER BY o.id")->fetchAll();
$coins = (float)$pdo->query('SELECT COALESCE(SUM(coins),0) FROM users')->fetchColumn();
$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY c.sort_order, c.id')->fetchAll();
$catOpts = function ($sel) use ($cats) {
    $h = '<option value="">— No category —</option>';
    foreach ($cats as $k) $h .= '<option value="' . (int)$k['id'] . '"' . ((int)$sel === (int)$k['id'] ? ' selected' : '') . '>' . e($k['icon'] . ' ' . $k['name']) . '</option>';
    return $h;
};
$rows = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status='pending' ORDER BY d.id")->fetchAll();
$users = $pdo->query('SELECT u.id, u.email, u.coins, u.created_at, (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.id) AS refs FROM users u ORDER BY u.id DESC LIMIT 100')->fetchAll();
$hist = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status <> 'pending' ORDER BY d.id DESC LIMIT 30")->fetchAll();
header_html('Admin', null, 'app', true); ?>
<div class="stats">
  <div class="stat"><span>Total users</span><b><?= $totalUsers ?></b></div>
  <div class="stat"><span>Pending deposits</span><b><?= (int)$pend['c'] ?></b></div>
  <div class="stat"><span>Pending orders</span><b><?= count($pendOrders) ?></b></div>
  <div class="stat"><span>Balance in wallets</span><b><?= money($coins) ?></b></div>
</div>
<div class="jump"><a href="#orders">Orders</a><a href="#deposits">Deposits</a><a href="#categories">Categories</a><a href="#products">Products</a><a href="#settings">Settings</a><a href="#users">Users</a></div>

<div class="card" id="orders"><h3>Pending orders</h3>
<?php foreach ($pendOrders as $o): ?>
<div class="prod" style="display:block">
  <b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?></b> <span class="price"><?= money($o['price']) ?></span>
  <p><?= e($o['email']) ?> · <?= e($o['created_at']) ?></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
  <textarea name="delivery" placeholder="Delivery details shown to the customer (account email/password, license key, instructions...)"></textarea>
  <div class="acts" style="margin-top:8px"><button class="btn sm" name="deliver" value="1">Mark delivered</button>
  <button class="btn sm red" name="cancel_order" value="1" onclick="return confirm('Cancel and refund?')">Cancel &amp; refund</button></div></form>
</div>
<?php endforeach; if (!$pendOrders): ?><p>No pending orders.</p><?php endif; ?></div>

<div class="card" id="deposits"><h3>Pending deposits</h3>
<div class="tw"><table><tr><th>User</th><th>Amount</th><th>Note</th><th>Date</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['email']) ?></td><td><?= money($r['amount']) ?></td><td><?= e($r['note']) ?></td><td><?= e($r['created_at']) ?></td>
<td><form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<button class="btn sm" name="action" value="approve">Approve</button>
<button class="btn sm red" name="action" value="reject">Reject</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5">Nothing pending.</td></tr><?php endif; ?></table></div></div>

<div class="card" id="categories"><h3>Categories</h3>
<?php if ($catErr): ?><div class="err"><?= e($catErr) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="grid2"><div><label>Name</label><input type="text" name="cname" maxlength="40" required placeholder="e.g. Gmail Account"></div>
<div><label>Icon (emoji)</label><input type="text" name="cicon" maxlength="8" placeholder="📧"></div></div>
<label>Sort order <small>(smaller shows first)</small></label><input type="number" name="csort" value="0">
<button class="btn" name="add_category" value="1">Add category</button></form>
<div style="margin-top:16px">
<?php foreach ($cats as $k): ?>
<details class="ed"><summary><span><?= e($k['icon']) ?> <b><?= e($k['name']) ?></b></span><small><?= (int)$k['n'] ?> products · <?= $k['active'] ? 'visible' : 'hidden' ?></small></summary>
<form method="post"><?= csrf_field() ?>
<div class="grid2"><div><label>Name</label><input type="text" name="cname" maxlength="40" required value="<?= e($k['name']) ?>"></div>
<div><label>Icon (emoji)</label><input type="text" name="cicon" maxlength="8" value="<?= e($k['icon']) ?>"></div></div>
<label>Sort order</label><input type="number" name="csort" value="<?= (int)$k['sort_order'] ?>">
<div class="acts" style="margin-top:12px;flex-wrap:wrap"><button class="btn sm" name="update_category" value="<?= (int)$k['id'] ?>">Save</button>
<button class="btn sm ghost" name="toggle_category" value="<?= (int)$k['id'] ?>"><?= $k['active'] ? 'Hide' : 'Show' ?></button>
<button class="btn sm red" name="delete_category" value="<?= (int)$k['id'] ?>" onclick="return confirm('Delete this category? Its products stay but become uncategorised.')">Delete</button></div></form></details>
<?php endforeach; if (!$cats): ?><p><small>No categories yet. Add one above, then assign products to it.</small></p><?php endif; ?></div></div>

<div class="card" id="products"><h3>Products</h3>
<?php if ($prodErr): ?><div class="err"><?= e($prodErr) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Name</label><input type="text" name="pname" maxlength="80" required>
<label>Description (optional)</label><input type="text" name="pdesc" maxlength="500">
<div class="grid2"><div><label>Price (৳)</label><input type="number" name="pprice" step="0.01" min="0.01" required></div>
<div><label>Unit <small>(e.g. email)</small></label><input type="text" name="unit" maxlength="20" placeholder="email"></div></div>
<div class="grid2"><div><label>Category</label><select name="category_id"><?= $catOpts(0) ?></select></div>
<div><label>Stock <small>(blank = unlimited)</small></label><input type="number" name="stock" min="0" step="1"></div></div>
<label class="chk"><input type="checkbox" name="popular" value="1"> <span>Mark as Popular</span></label>
<button class="btn" name="add_product" value="1">Add product</button></form>
<div style="margin-top:16px">
<?php foreach ($products as $p): ?>
<details class="ed"><summary><span><b><?= e($p['name']) ?></b> <?php if ($p['popular']): ?>⭐<?php endif; ?></span>
<small><?= money($p['price']) ?> · <?= $p['stock'] === null ? 'unlimited' : number_format((int)$p['stock']) . ' in stock' ?> · <?= $p['active'] ? 'visible' : 'hidden' ?></small></summary>
<form method="post"><?= csrf_field() ?>
<label>Name</label><input type="text" name="pname" maxlength="80" required value="<?= e($p['name']) ?>">
<label>Description</label><input type="text" name="pdesc" maxlength="500" value="<?= e($p['description']) ?>">
<div class="grid2"><div><label>Price (৳)</label><input type="number" name="pprice" step="0.01" min="0.01" required value="<?= e($p['price']) ?>"></div>
<div><label>Unit</label><input type="text" name="unit" maxlength="20" value="<?= e($p['unit']) ?>"></div></div>
<div class="grid2"><div><label>Category</label><select name="category_id"><?= $catOpts($p['category_id']) ?></select></div>
<div><label>Stock <small>(blank = unlimited)</small></label><input type="number" name="stock" min="0" step="1" value="<?= $p['stock'] === null ? '' : (int)$p['stock'] ?>"></div></div>
<label class="chk"><input type="checkbox" name="popular" value="1" <?= $p['popular'] ? 'checked' : '' ?>> <span>Mark as Popular</span></label>
<div class="acts" style="margin-top:12px"><button class="btn sm" name="update_product" value="<?= (int)$p['id'] ?>">Save</button></div></form>
<form method="post" style="padding-top:0"><?= csrf_field() ?><button class="btn sm ghost" name="toggle_product" value="<?= (int)$p['id'] ?>"><?= $p['active'] ? 'Hide from shop' : 'Show in shop' ?></button></form></details>
<?php endforeach; ?></div></div>

<div class="card" id="settings"><h3>Site settings</h3>
<?php if (!empty($_GET['saved'])): ?><div class="ok">Settings saved.</div><?php endif; ?>
<?php if ($setErr): ?><div class="err"><?= e($setErr) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<label>Site name</label><input type="text" name="site_name" maxlength="40" required value="<?= e($_POST['site_name'] ?? site_name()) ?>">
<label>Headline (homepage)</label><input type="text" name="tagline" maxlength="120" value="<?= e(setting('tagline')) ?>">
<label>Sub-headline</label><input type="text" name="subtitle" maxlength="300" value="<?= e(setting('subtitle')) ?>">
<label>Telegram support link</label><input type="text" name="telegram_url" value="<?= e(setting('telegram_url')) ?>" placeholder="https://t.me/your_username">
<label>WhatsApp support link</label><input type="text" name="whatsapp_url" value="<?= e(setting('whatsapp_url')) ?>" placeholder="https://wa.me/8801XXXXXXXXX">
<label>Footer support link (optional)</label><input type="text" name="support_url" value="<?= e(setting('support_url')) ?>">
<label>Logo <small>(PNG/JPG/WEBP, up to 1 MB)</small></label>
<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
<?php if ($lg = media_url('logo')): ?><img class="preview" src="<?= e($lg) ?>" alt="Logo"><label class="rm"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label><?php endif; ?>
<label>Homepage banner <small>(PNG/JPG/WEBP, up to 2 MB, wide image)</small></label>
<input type="file" name="banner" accept="image/png,image/jpeg,image/webp,image/gif">
<?php if ($bn = media_url('banner')): ?><img class="preview" src="<?= e($bn) ?>" alt="Banner"><label class="rm"><input type="checkbox" name="remove_banner" value="1"> Remove banner</label><?php endif; ?>
<button class="btn" name="save_settings" value="1">Save settings</button>
</form></div>

<div class="card" id="users"><h3>Users (latest 100)</h3>
<div class="tw"><table><tr><th>#</th><th>Email</th><th>Balance</th><th>Referrals</th><th>Joined</th></tr>
<?php foreach ($users as $x): ?>
<tr><td><?= (int)$x['id'] ?></td><td><?= e($x['email']) ?></td><td><?= money($x['coins']) ?></td><td><?= (int)$x['refs'] ?></td><td><?= e($x['created_at']) ?></td></tr>
<?php endforeach; if (!$users): ?><tr><td colspan="5">No users yet.</td></tr><?php endif; ?></table></div></div>

<div class="card"><h3>Recent deposit decisions</h3>
<div class="tw"><table><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr>
<?php foreach ($hist as $h): ?>
<tr><td><?= e($h['email']) ?></td><td><?= money($h['amount']) ?></td><td><span class="badge <?= e($h['status']) ?>"><?= e($h['status']) ?></span></td><td><?= e($h['created_at']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No history yet.</td></tr><?php endif; ?></table></div></div>
<?php footer_html();
