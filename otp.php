<?php require __DIR__ . '/lib.php';
$u = require_login();
$pdo = db();

// ─── ইউজারের নিজের ইমেইল লিস্ট (আগের মতোই) ───
$s = $pdo->prepare("SELECT delivery FROM orders WHERE user_id = ? AND status = 'delivered' AND delivery IS NOT NULL ORDER BY id DESC LIMIT 200");
$s->execute([$u['id']]);
$mine = [];
foreach ($s->fetchAll() as $r) {
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $r['delivery'], $m)) {
        foreach ($m[0] as $x) $mine[strtolower($x)] = true;
    }
}
$mine = array_keys($mine);

$email   = strtolower(trim($_GET['email'] ?? ''));
$mode    = $_GET['mode'] ?? 'auto';        // auto | inbox | edu
$doLive  = !empty($_GET['live']);          // live-check চালাবে কি না
$found   = null;
$list    = [];
$apiMsgs = [];
$live    = null;
$msg     = '';
$apiErr  = '';

if ($email !== '') {
    if (!in_array($email, $mine, true)) {
        $msg = 'This email is not in your delivered orders.';
    } else {
        // ─── ১) নিজের DB থেকে OTP (dflt) ───
        $q = $pdo->prepare('SELECT code, received_at, TIMESTAMPDIFF(SECOND, received_at, NOW()) AS age FROM otp_codes WHERE email = ? ORDER BY id DESC LIMIT 5');
        $q->execute([$email]);
        $list  = $q->fetchAll();
        $found = $list[0] ?? null;

        // ─── ২) MailGen API কল ───
        $acc = mailgen_parse_account($email);
        if ($acc) {
            // (a) Gmail Edu হলে edu-mail endpoint
            if ($mode === 'edu' || ($mode === 'auto' && $acc['is_edu'])) {
                $res = mailgen_call('/api/edu-mail', ['email' => $acc['email']]);
                if (!empty($res['messages'])) {
                    $apiMsgs = $res['messages'];
                } elseif (!empty($res['error'])) {
                    $apiErr = 'Edu mail: ' . $res['error'];
                }
            }
            // (b) Outlook/Hotmail হলে inbox-read
            elseif ($acc['has_oauth']) {
                $res = mailgen_call('/api/inbox-read', [
                    'emailData'    => $acc['full'],
                    'messageCount' => 20,
                ]);
                if (!empty($res['messages'])) {
                    $apiMsgs = $res['messages'];
                } elseif (!empty($res['error'])) {
                    $apiErr = 'Inbox read: ' . $res['error'];
                }
                // (c) Live check চাইলে
                if ($doLive) {
                    $live = mailgen_call('/api/live-check', ['emailData' => $acc['full']]);
                }
            } else {
                $apiErr = 'No refresh_token/client_id found in this line — inbox-read skipped.';
            }
        } else {
            $apiErr = 'Account line not found in your delivered orders.';
        }

        // ─── ৩) API থেকে প্রথম code বের করা ───
        if (!$found && $apiMsgs) {
            foreach ($apiMsgs as $m) {
                if (!empty($m['code'])) {
                    $found = ['code' => $m['code'], 'age' => null, 'from_api' => true];
                    break;
                }
            }
        }

        if (!$found && !$apiMsgs && !$apiErr && !$list) {
            $msg = 'No OTP received yet for this account. Request the code, then press Check again.';
        }
    }
}

function ago(int $s): string {
    return $s < 60 ? $s . 's ago'
        : ($s < 3600 ? intdiv($s, 60) . ' min ago'
        : ($s < 86400 ? intdiv($s, 3600) . ' h ago'
        : intdiv($s, 86400) . ' d ago'));
}

user_start('Read OTP', $u, 'otp'); ?>

<div class="card">
  <h3>Read OTP</h3>
  <small>Enter the account email you received in your order to see its latest verification code — pulled from the live inbox.</small>

  <form method="get">
    <label>Account email</label>
    <input type="email" name="email" required value="<?= e($email) ?>" placeholder="account@outlook.com">

    <label>Source</label>
    <select name="mode">
      <option value="auto"  <?= $mode === 'auto'  ? 'selected' : '' ?>>Auto (recommended)</option>
      <option value="inbox" <?= $mode === 'inbox' ? 'selected' : '' ?>>Inbox read (Outlook/Hotmail)</option>
      <option value="edu"   <?= $mode === 'edu'   ? 'selected' : '' ?>>Edu mail (Gmail Edu)</option>
    </select>

    <label class="chk" style="margin-top:12px">
      <input type="checkbox" name="live" value="1" <?= $doLive ? 'checked' : '' ?>>
      <span>Also run <b>Live Check</b> (account active/dead)</span>
    </label>

    <button class="btn">Check OTP</button>
  </form>

  <?php if ($mine): ?>
    <div class="chips">
      <?php foreach ($mine as $x): ?>
        <a href="/otp.php?email=<?= urlencode($x) ?>&mode=<?= e($mode) ?>"><?= e($x) ?></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="margin-top:12px"><small>You have no delivered orders with an account email yet.</small></p>
  <?php endif; ?>
</div>

<?php if ($msg):     ?><div class="err"><?= e($msg) ?></div><?php endif; ?>
<?php if ($apiErr):  ?><div class="err"><b>API:</b> <?= e($apiErr) ?></div><?php endif; ?>

<?php if ($live): ?>
  <div class="card" style="border-top:4px solid <?= !empty($live['isLive']) ? '#16a34a' : '#dc2626' ?>">
    <h3>Live Check</h3>
    <p style="margin:4px 0">
      <?php if (!empty($live['isLive'])): ?>
        <span class="badge delivered">LIVE ✅</span>
      <?php else: ?>
        <span class="badge rejected">DEAD ❌</span>
      <?php endif; ?>
      <?php if (!empty($live['error'])): ?> — <?= e($live['error']) ?><?php endif; ?>
    </p>
  </div>
<?php endif; ?>

<?php if ($found): ?>
  <div class="card">
    <h3>Latest code <?= !empty($found['from_api']) ? '<small style="font-weight:400">(live inbox)</small>' : '' ?></h3>
    <div class="otp" id="otpv"><?= e($found['code']) ?></div>
    <p class="sub">
      <?php if (isset($found['age'])): ?>
        Received <?= e(ago((int)$found['age'])) ?><?= (int)$found['age'] > 600 ? ' — may have expired' : '' ?>
      <?php else: ?>
        Fetched from MailGen inbox just now
      <?php endif; ?>
    </p>
    <input type="text" id="otpc" readonly value="<?= e($found['code']) ?>" style="position:absolute;left:-9999px">
    <button type="button" class="btn" data-copy="#otpc">Copy code</button>

    <?php if (count($list) > 1): ?>
      <h3 style="margin-top:20px">Earlier codes (your DB)</h3>
      <div class="tw"><table><tr><th>Code</th><th>Received</th></tr>
        <?php foreach (array_slice($list, 1) as $r): ?>
          <tr><td class="mono"><?= e($r['code']) ?></td><td><?= e(ago((int)$r['age'])) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($apiMsgs): ?>
  <div class="card">
    <h3>Inbox messages (<?= count($apiMsgs) ?>)</h3>
    <?php foreach (array_slice($apiMsgs, 0, 15) as $m): ?>
      <div class="prod">
        <div style="min-width:0">
          <b><?= e($m['subject'] ?? '(no subject)') ?></b>
          <p style="margin:4px 0 0">
            <?= e($m['from'] ?? '') ?>
            <?php if (!empty($m['date'])): ?> · <small><?= e($m['date']) ?></small><?php endif; ?>
          </p>
          <?php if (!empty($m['preview'])): ?>
            <div class="dv"><?= e($m['preview']) ?></div>
          <?php elseif (!empty($m['message'])): ?>
            <div class="dv"><?= e($m['message']) ?></div>
          <?php endif; ?>
        </div>
        <?php if (!empty($m['code'])): ?>
          <span class="badge approved mono" style="white-space:nowrap"><?= e($m['code']) ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php user_end(); ?>