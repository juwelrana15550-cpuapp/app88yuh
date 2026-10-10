<?php require __DIR__ . '/lib.php';
if (current_user()) { header('Location: /dashboard.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $s = db()->prepare('SELECT * FROM users WHERE email = ?');
    $s->execute([strtolower(trim($_POST['email'] ?? ''))]);
    $u = $s->fetch();
    // verify against a dummy hash when the user is unknown, so timing doesn't reveal which emails exist
    $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltuQ3m5o0Hk1e0b8rXz9nq2x8a1u2YbG';
    if (password_verify((string)($_POST['password'] ?? ''), $hash) && $u) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        header('Location: /dashboard.php'); exit;
    }
    usleep(400000);
    $err = 'Wrong email or password.';
}
header_html('Login', null, 'auth'); ?>
<div class="card">
<?= logo_html('🔐') ?>
<h2>Welcome back</h2>
<p class="sub">Login to your account</p>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Email Address</label><input type="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
<label>Password</label>
<div class="pw"><input type="password" id="lp" name="password" required autocomplete="current-password"><button type="button" data-toggle="#lp">Show</button></div>
<button class="btn">Login</button>
</form>
<p class="alt">No account? <a href="/register.php">Register</a></p>
</div>
<?php footer_html();
