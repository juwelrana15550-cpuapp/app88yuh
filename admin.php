<?php require __DIR__ . '/lib.php';
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = ''; $setErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['admin_logout'])) { unset($_SESSION['admin']); header('Location: /admin.php'); exit; }
    if (isset($_POST['admin_pw'])) {
        if (hash_equals($adminPw, $_POST['admin_pw'])) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: /admin.php'); exit; }
        sleep(1); $err = 'Wrong password.';
    } elseif (!empty($_SESSION['admin']) && isset($_POST['save_settings'])) {
        $name = trim($_POST['site_name'] ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            $setErr = 'Site name is required (max 40 characters).';
        } else {
            save_setting('site_name', $name);
            save_setting('tagline', mb_substr(trim($_POST['tagline'] ?? ''), 0, 120));
            save_setting('subtitle', mb_substr(trim($_POST['subtitle'] ?? ''), 0, 300));
            $sup = trim($_POST['support_url'] ?? '');
            save_setting('support_url', preg_match('#^https?://#i', $sup) ? $sup : '');
            foreach (['logo' => 1048576, 'banner' => 2097152] as $k => $max) {
                if (!empty($_POST['remove_' . $k])) { db()->prepare('DELETE FROM media WHERE k = ?')->execute([$k]); continue; }
                $f = $_FILES[$k] ?? null;
                if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) continue;
                if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > $max) { $setErr = ucfirst($k) . ' is too large (max ' . ($max / 1048576) . ' MB).'; continue; }
                $info = @getimagesize($f['tmp_name']);
                if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                    $setErr = ucfirst($k) . ' must be a PNG, JPG, WEBP or GIF image.'; continue;
                }
                $st = db()->prepare('REPLACE INTO media (k, mime, data) VALUES (?,?,?)');
                $st->bindValue(1, $k);
                $st->bindValue(2, $info['mime']);
                $st->bindValue(3, file_get_contents($f['tmp_name']), PDO::PARAM_LOB);
                $st->execute();
            }
            if (!$setErr) { header('Location: /admin.php?saved=1'); exit; }
        }
    } elseif (!empty($_SESSION['admin']) && isset($_POST['id'], $_POST['action'])) {
        $pdo = db(); $pdo->beginTransaction();
        $s = $pdo->prepare("SELECT * FROM deposits WHERE id = ? AND status = 'pending' FOR UPDATE");
        $s->execute([(int)$_POST['id']]);
        if ($d = $s->fetch()) {
            if ($_POST['action'] === 'approve') {
                $pdo->prepare("UPDATE deposits SET status='approved' WHERE id=?")->execute([$d['id']]);
                $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$d['amount'], $d['user_id']]);
                $us = $pdo->prepare('SELECT referred_by, has_deposited FROM users WHERE id = ? FOR UPDATE');
                $us->execute([$d['user_id']]); $usr = $us->fetch();
                if (!$usr['has_deposited']) {
                    $pdo->prepare('UPDATE users SET has_deposited = 1 WHERE id = ?')->execute([$d['user_id']]);
                    if ($usr['referred_by'])
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$bonus, $usr['referred_by']]);
                }
            } else {
                $pdo->prepare("UPDATE deposits SET status='rejected' WHERE id=?")->execute([$d['id']]);
            }
        }
        $pdo->commit();
        header('Location: /admin.php'); exit;
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
$pend = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM deposits WHERE status='pending'")->fetch();
$coins = (float)$pdo->query('SELECT COALESCE(SUM(coins),0) FROM users')->fetchColumn();
$approved = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='approved'")->fetchColumn();
$rows = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status='pending' ORDER BY d.id")->fetchAll();
$users = $pdo->query('SELECT u.id, u.email, u.coins, u.referral_code, u.created_at, (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.id) AS refs FROM users u ORDER BY u.id DESC LIMIT 100')->fetchAll();
$hist = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status <> 'pending' ORDER BY d.id DESC LIMIT 30")->fetchAll();
header_html('Admin', null, 'app', true); ?>
<div class="stats">
  <div class="stat"><span>Total users</span><b><?= $totalUsers ?></b></div>
  <div class="stat"><span>Pending deposits</span><b><?= (int)$pend['c'] ?></b></div>
  <div class="stat"><span>Coins in wallets</span><b><?= number_format($coins, 2) ?></b></div>
  <div class="stat"><span>Total approved</span><b><?= number_format($approved, 2) ?></b></div>
</div>

<div class="card"><h3>Site settings</h3>
<?php if (!empty($_GET['saved'])): ?><div class="ok">Settings saved.</div><?php endif; ?>
<?php if ($setErr): ?><div class="err"><?= e($setErr) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<label>Site name</label><input type="text" name="site_name" maxlength="40" required value="<?= e(site_name()) ?>">
<label>Headline (homepage)</label><input type="text" name="tagline" maxlength="120" value="<?= e(setting('tagline')) ?>" placeholder="Shown big on the homepage">
<label>Sub-headline</label><input type="text" name="subtitle" maxlength="300" value="<?= e(setting('subtitle')) ?>" placeholder="One or two lines under the headline">
<label>Support link (optional)</label><input type="text" name="support_url" value="<?= e(setting('support_url')) ?>" placeholder="https://t.me/your_username">
<label>Logo <small>(PNG/JPG/WEBP, up to 1 MB, square works best)</small></label>
<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
<?php if ($lg = media_url('logo')): ?><img class="preview" src="<?= e($lg) ?>" alt="Logo"><label class="rm"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label><?php endif; ?>
<label>Homepage banner <small>(PNG/JPG/WEBP, up to 2 MB, wide image)</small></label>
<input type="file" name="banner" accept="image/png,image/jpeg,image/webp,image/gif">
<?php if ($bn = media_url('banner')): ?><img class="preview" src="<?= e($bn) ?>" alt="Banner"><label class="rm"><input type="checkbox" name="remove_banner" value="1"> Remove banner</label><?php endif; ?>
<button class="btn" name="save_settings" value="1">Save settings</button>
</form></div>

<div class="card"><h3>Pending deposits</h3>
<div class="tw"><table><tr><th>User</th><th>Amount</th><th>Note</th><th>Date</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['email']) ?></td><td><?= e($r['amount']) ?></td><td><?= e($r['note']) ?></td><td><?= e($r['created_at']) ?></td>
<td><form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<button class="btn sm" name="action" value="approve">Approve</button>
<button class="btn sm red" name="action" value="reject">Reject</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5">Nothing pending.</td></tr><?php endif; ?></table></div></div>

<div class="card"><h3>Users (latest 100)</h3>
<div class="tw"><table><tr><th>#</th><th>Email</th><th>Coins</th><th>Referrals</th><th>Joined</th></tr>
<?php foreach ($users as $x): ?>
<tr><td><?= (int)$x['id'] ?></td><td><?= e($x['email']) ?></td><td><?= number_format((float)$x['coins'], 2) ?></td><td><?= (int)$x['refs'] ?></td><td><?= e($x['created_at']) ?></td></tr>
<?php endforeach; if (!$users): ?><tr><td colspan="5">No users yet.</td></tr><?php endif; ?></table></div></div>

<div class="card"><h3>Recent decisions</h3>
<div class="tw"><table><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr>
<?php foreach ($hist as $h): ?>
<tr><td><?= e($h['email']) ?></td><td><?= e($h['amount']) ?></td><td><span class="badge <?= e($h['status']) ?>"><?= e($h['status']) ?></span></td><td><?= e($h['created_at']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No history yet.</td></tr><?php endif; ?></table></div></div>
<?php footer_html();
