<?php
require_once __DIR__ . '/lib.php';
$u = require_login();
$pdo = db();

/* ---------- Change password ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_check();
    $cur = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $rep = (string)($_POST['confirm_password'] ?? '');
    if (!password_verify($cur, $u['password_hash'])) {
        flash('err', 'Your current password is incorrect.');
    } elseif (strlen($new) < 8) {
        flash('err', 'New password must be at least 8 characters.');
    } elseif ($new !== $rep) {
        flash('err', 'New password and confirmation do not match.');
    } elseif (password_verify($new, $u['password_hash'])) {
        flash('err', 'New password must be different from the current one.');
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        flash('ok', 'Password updated successfully.');
    }
    header('Location: /profile.php#security');
    exit;
}

/* ---------- Account numbers ---------- */
$q = function (string $sql) use ($pdo, $u) { $s = $pdo->prepare($sql); $s->execute([$u['id']]); return $s->fetchColumn(); };
$ordersAll  = (int)$q('SELECT COUNT(*) FROM orders WHERE user_id = ?');
$ordersDone = (int)$q("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'delivered'");
$deposited  = (float)$q("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id = ? AND status = 'approved'");
$keys       = (int)$q('SELECT COUNT(*) FROM api_keys WHERE user_id = ?');

$name    = ucfirst(strstr($u['email'], '@', true) ?: $u['email']);
$initial = mb_strtoupper(mb_substr($name, 0, 1));
$since   = !empty($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '-';
$uid     = 'UID-' . str_pad((string)$u['id'], 5, '0', STR_PAD_LEFT);

user_start('Profile', $u, 'profile'); ?>

<section class="pf-hero">
  <div class="pf-av"><?= e($initial) ?><i class="pf-on" title="Active"></i></div>
  <div class="pf-who">
    <h1><?= e($name) ?></h1>
    <p><?= e($u['email']) ?></p>
    <div class="pf-tags"><span class="pf-tag ok"><?= icon('shield') ?>Active account</span><span class="pf-tag"><?= e($uid) ?></span></div>
  </div>
  <div class="pf-bal">
    <span>Wallet balance</span>
    <b><?= money($u['coins']) ?></b>
    <a href="/deposits.php"><?= icon('plus') ?>Add funds</a>
  </div>
</section>

<section class="pf-stats">
  <a class="pf-st" href="/orders.php"><span class="pf-si a"><?= icon('cart') ?></span><b><?= $ordersAll ?></b><small>Total orders</small></a>
  <a class="pf-st" href="/orders.php"><span class="pf-si g"><?= icon('box') ?></span><b><?= $ordersDone ?></b><small>Delivered</small></a>
  <a class="pf-st" href="/deposits.php"><span class="pf-si b"><?= icon('coins') ?></span><b><?= money($deposited) ?></b><small>Total deposited</small></a>
  <a class="pf-st" href="/api_keys.php"><span class="pf-si p"><?= icon('key') ?></span><b><?= $keys ?></b><small>API keys</small></a>
</section>

<div class="pf-grid">
  <section class="pf-card">
    <header class="pf-ch"><span class="pf-ci"><?= icon('user') ?></span><div><h2>Account details</h2><p>Your sign-in identity and membership.</p></div></header>
    <dl class="pf-dl">
      <div><dt>Email address</dt><dd><?= e($u['email']) ?></dd></div>
      <div><dt>Account ID</dt><dd><?= e($uid) ?></dd></div>
      <div><dt>Member since</dt><dd><?= e($since) ?></dd></div>
      <div><dt>Status</dt><dd><span class="pf-tag ok">Active</span></dd></div>
      <?php if (!empty($u['referral_code'])): ?>
      <div><dt>Referral code</dt><dd class="pf-ref"><input id="pfRef" type="text" readonly value="<?= e($u['referral_code']) ?>" aria-label="Referral code"><button type="button" class="pf-cp" data-copy="#pfRef"><?= icon('copy') ?>Copy</button></dd></div>
      <?php endif; ?>
    </dl>
    <p class="pf-note"><?= icon('shield') ?>For security, your email can only be changed by our support team.</p>
  </section>

  <section class="pf-card" id="security">
    <header class="pf-ch"><span class="pf-ci s"><?= icon('key') ?></span><div><h2>Password &amp; security</h2><p>Use a strong password you don't use anywhere else.</p></div></header>
    <form method="post" autocomplete="off" class="pf-form" id="pfForm">
      <?= csrf_field() ?>
      <input type="hidden" name="change_password" value="1">
      <label for="pf_cur">Current password</label>
      <div class="pw pf-pw"><input id="pf_cur" type="password" name="current_password" required autocomplete="current-password"><button type="button" data-toggle="#pf_cur">Show</button></div>
      <label for="pf_new">New password</label>
      <div class="pw pf-pw"><input id="pf_new" type="password" name="new_password" required minlength="8" autocomplete="new-password"><button type="button" data-toggle="#pf_new">Show</button></div>
      <div class="pf-meter" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
      <small id="pfHint">At least 8 characters. Mix letters, numbers and symbols.</small>
      <label for="pf_rep">Confirm new password</label>
      <div class="pw pf-pw"><input id="pf_rep" type="password" name="confirm_password" required minlength="8" autocomplete="new-password"><button type="button" data-toggle="#pf_rep">Show</button></div>
      <small id="pfMatch"></small>
      <button class="btn pf-save" type="submit">Update password</button>
    </form>
  </section>
</div>

<section class="pf-card pf-links">
  <a href="/transactions.php"><span class="pf-ci"><?= icon('history') ?></span><b>Transactions</b><small>Wallet history</small></a>
  <a href="/referrals.php"><span class="pf-ci"><?= icon('users') ?></span><b>Referrals</b><small>Invite &amp; earn</small></a>
  <a href="/api_keys.php"><span class="pf-ci"><?= icon('key') ?></span><b>API keys</b><small>Developer access</small></a>
</section>

<form method="post" action="/logout.php" class="pf-out"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?>Log out of this device</button></form>

<script>
(function(){
  var n=document.getElementById('pf_new'),r=document.getElementById('pf_rep'),
      bars=document.querySelectorAll('.pf-meter i'),h=document.getElementById('pfHint'),m=document.getElementById('pfMatch'),
      L=['Too short','Weak','Fair','Good','Strong'],C=['#94a3b8','#ef4444','#f59e0b','#3b82f6','#10b981'];
  function score(v){var s=0;if(v.length>=8)s++;if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++;if(/\d/.test(v))s++;if(/[^A-Za-z0-9]/.test(v)||v.length>=12)s++;return v.length<8?0:Math.max(1,s);}
  function upd(){
    var v=n.value,s=v?score(v):0;
    bars.forEach(function(b,i){b.style.background=i<s?C[s]:'';});
    h.textContent=v?'Strength: '+L[s]:'At least 8 characters. Mix letters, numbers and symbols.';
    h.style.color=v?C[s]:'';
    if(r.value){var ok=r.value===v;m.textContent=ok?'Passwords match':'Passwords do not match';m.style.color=ok?'#059669':'#dc2626';}else m.textContent='';
  }
  n.addEventListener('input',upd);r.addEventListener('input',upd);
})();
</script>
<?php user_end();
