<?php require __DIR__ . '/lib.php';
$u = require_login();
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $new2 = $_POST['confirm'] ?? '';
    if (!password_verify($cur, $u['password_hash'])) $err = 'Current password is wrong.';
    elseif (strlen($new) < 8) $err = 'New password must be at least 8 characters.';
    elseif ($new !== $new2) $err = 'New passwords do not match.';
    else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        $msg = 'Password updated.';
    }
}
user_start('Profile', $u, 'profile'); ?>
<div class="card"><h3>Profile</h3>
<label>Email</label><input type="text" value="<?= e($u['email']) ?>" readonly>
<label>Member since</label><input type="text" value="<?= e($u['created_at']) ?>" readonly></div>
<div class="card"><h3>Change password</h3>
<?php if ($msg): ?><div class="ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Current password</label><input type="password" name="current" required>
<label>New password</label><input type="password" name="new" required minlength="8">
<label>Confirm new password</label><input type="password" name="confirm" required>
<button class="btn">Update password</button></form></div>
<?php user_end();
