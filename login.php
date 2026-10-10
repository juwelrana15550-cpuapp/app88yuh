<?php require __DIR__ . '/lib.php';
if (current_user()) { header('Location: /dashboard.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $s = db()->prepare('SELECT * FROM users WHERE email = ?');
    $s->execute([strtolower(trim($_POST['email'] ?? ''))]);
    $u = $s->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        header('Location: /dashboard.php'); exit;
    }
    $err = 'Wrong email or password.';
}
header_html('Login'); ?>
<div class="card">
<h2>Login</h2>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Email Address</label><input type="email" name="email" required>
<label>Password</label><input type="password" name="password" required>
<button class="btn">Login</button>
</form>
<p>No account? <a href="/register.php">Register</a></p>
</div>
<?php footer_html();
