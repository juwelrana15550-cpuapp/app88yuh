<?php require __DIR__ . '/lib.php';
if (current_user()) { header('Location: /dashboard.php'); exit; }
$err = ''; $ref = trim($_GET['ref'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw = $_POST['password'] ?? ''; $pw2 = $_POST['confirm'] ?? '';
    $ref = trim($_POST['referral'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $err = 'Invalid email address.';
    elseif (strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
    elseif ($pw !== $pw2) $err = 'Passwords do not match.';
    elseif (empty($_POST['terms'])) $err = 'You must accept the Terms of Service.';
    else {
        $referrer = null;
        if ($ref !== '') {
            $s = db()->prepare('SELECT id FROM users WHERE referral_code = ?'); $s->execute([strtoupper($ref)]);
            $referrer = $s->fetchColumn() ?: null;
        }
        for ($try = 0; $try < 5; $try++) {
            try {
                $s = db()->prepare('INSERT INTO users (email, password_hash, referral_code, referred_by) VALUES (?,?,?,?)');
                $s->execute([$email, password_hash($pw, PASSWORD_DEFAULT), strtoupper(bin2hex(random_bytes(4))), $referrer]);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)db()->lastInsertId();
                header('Location: /dashboard.php'); exit;
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000' && stripos($ex->getMessage(), 'referral_code') !== false) continue; // code clash: retry
                error_log('REGISTER ERROR: ' . $ex->getMessage());
                $err = $ex->getCode() === '23000' ? 'This email is already registered.' : 'Something went wrong. Try again.';
                break;
            }
        }
        if (!$err) $err = 'Something went wrong. Try again.';
    }
}
header_html('Register', null, 'auth'); ?>
<div class="card">
<?= logo_html('✨') ?>
<h2>Create Account</h2>
<p class="sub">Join in less than a minute</p>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Email Address</label><input type="email" name="email" required autocomplete="email" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
<label>Password</label>
<div class="pw"><input type="password" id="p1" name="password" required minlength="8" autocomplete="new-password"><button type="button" data-toggle="#p1">Show</button></div>
<small>Must be at least 8 characters</small>
<label>Confirm Password</label>
<div class="pw"><input type="password" id="p2" name="confirm" required autocomplete="new-password"><button type="button" data-toggle="#p2">Show</button></div>
<label>Referral Code (Optional)</label><input type="text" name="referral" value="<?= e($ref) ?>">
<small>Get bonus when your referrer's friend makes the first deposit!</small>
<label class="chk"><input type="checkbox" name="terms" value="1"> <span>I agree to the Terms of Service and Privacy Policy</span></label>
<button class="btn">Register</button>
</form>
<p class="alt">Already have an account? <a href="/login.php">Login</a></p>
</div>
<?php footer_html();
