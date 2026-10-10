<?php require __DIR__ . '/lib.php';
$u = require_login();
$s = db()->prepare('SELECT email, has_deposited, created_at FROM users WHERE referred_by = ? ORDER BY id DESC LIMIT 50'); $s->execute([$u['id']]);
$rows = $s->fetchAll();
$scheme = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http';
$link = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/register.php?ref=' . $u['referral_code'];
user_start('Referrals', $u, 'referrals'); ?>
<div class="stats">
  <div class="stat"><span>Your code</span><b><?= e($u['referral_code']) ?></b></div>
  <div class="stat"><span>Referred users</span><b><?= count($rows) ?></b></div>
</div>
<div class="card"><h3>Invite &amp; earn</h3>
<small>Share your link. You get bonus coins when your friend makes their first approved deposit.</small>
<div class="row" style="margin-top:10px"><input type="text" id="reflink" readonly value="<?= e($link) ?>"><button type="button" class="btn sm" data-copy="#reflink">Copy</button></div></div>
<div class="card"><h3>Your referrals</h3>
<div class="tw"><table><tr><th>User</th><th>Deposited</th><th>Joined</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= e(mask_email($r['email'])) ?></td><td><?= $r['has_deposited'] ? 'Yes' : 'Not yet' ?></td><td><?= e($r['created_at']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="3">No referrals yet.</td></tr><?php endif; ?></table></div></div>
<?php user_end();
