<?php require __DIR__ . '/lib.php';
require __DIR__ . '/auth_ui.php';
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
        if (!empty($u['banned'])) {   // blocked by admin (Admin > Users)
            usleep(400000);
            $err = 'Your account has been suspended. Please contact support.';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            // remember last login time + IP for Admin > Users (ignored if the columns are not created yet)
            try {
                $ip = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]) ?: (string)($_SERVER['REMOTE_ADDR'] ?? '');
                if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                db()->prepare('UPDATE users SET last_login_at = NOW(), last_ip = ? WHERE id = ?')->execute([substr($ip, 0, 45), (int)$u['id']]);
            } catch (Throwable $ex) {}
            header('Location: /dashboard.php'); exit;
        }
    } else {
        usleep(400000);
        $err = 'Wrong email or password.';
    }
}
auth_start('Login', 'login'); ?>
<h2>Welcome back</h2>
<p class="sub">Login to your account</p>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="f"><input type="email" name="email" id="le" placeholder=" " required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"><label for="le">Email Address</label></div>
<div class="f"><input type="password" id="lp" name="password" placeholder=" " required autocomplete="current-password"><label for="lp">Password</label><button type="button" class="eye" data-t="#lp">Show</button></div>
<button class="btn"><span>Login</span></button>
</form>
<p class="alt">No account? <a href="/register.php">Register</a></p>
<?php auth_end();
