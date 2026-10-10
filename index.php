<?php require __DIR__ . '/lib.php';
$u = current_user();
header_html('Home', $u); ?>
<div class="card">
  <h1>Welcome to <?= SITE_NAME ?></h1>
  <p>Create an account, add coins to your wallet, and invite friends with your referral code to earn bonus coins.</p>
  <?php if ($u): ?><a href="/dashboard.php">Go to dashboard</a>
  <?php else: ?><a href="/register.php">Create account</a> · <a href="/login.php">Login</a><?php endif; ?>
</div>
<?php footer_html();
