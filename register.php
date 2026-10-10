<?php require __DIR__ . '/../src/lib.php';
if (current_user()) { header('Location: /dashboard.php'); exit; }
$err = ''; $ref = trim($_GET['ref'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw = $_POST['password'] ?? ''; $pw2 = $_POST['confirm'] ?? '';
    $ref = trim($_POST['referral'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Invalid email address.';
    elseif (strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
    elseif ($pw !== $pw2) $err = 'Passwords do not match.';
    elseif (empty($_POST['terms'])) $err = 'You must accept the Terms of Service.';
    else {
        $referrer = null;
        if ($ref !== '') {
            $s = db()->prepare('SELECT id FROM users WHERE referral_code = ?'); $s->execute([$ref]);
            $referrer = $s->fetchColumn() ?: null;
        }
        try {
            $s = db()->prepare('INSERT INTO users (email, password_hash, referral_code, referred_by) VALUES (?,?,?,?)');
            $s->execute([$email, password_hash($pw, PASSWORD_DEFAULT), strtoupper(bin2hex(random_bytes(4))), $referrer]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            header('Location: /dashboard.php'); exit;
        } catch (PDOException $ex) {
            $err = $ex->getCode() === '23000' ? 'This email is already registered.' : 'Something went wrong. Try again.';
        }
    }
}
header_html('Register'); ?>
<div class="card">
<h2>Create Account</h2>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Email Address</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
<label>Password</label><input type="password" name="password" required minlength="8"><small>Must be at least 8 characters</small>
<label>Confirm Password</label><input type="password" name="confirm" required>
<label>Referral Code (Optional)</label><input type="text" name="referral" value="<?= e($ref) ?>">
<small>Get bonus coins when your referrer makes their first deposit!</small>
<label style="font-weight:400"><input type="checkbox" name="terms" value="1"> I agree to the Terms of Service and Privacy Policy</label>
<button class="btn">Register</button>
</form>
<p>Already have an account? <a href="/login.php">Login</a></p>
</div>
<?php footer_html();
