l<?php require __DIR__ . '/lib.php';
$u = require_login();
$pdo = db();

// ─── ইউজারের নিজের ইমেইল লিস্ট ───
$s = $pdo->prepare("SELECT delivery FROM orders WHERE user_id = ? AND status = 'delivered' AND delivery IS NOT NULL ORDER BY id DESC LIMIT 200");
$s->execute([$u['id']]);
$mine = [];
foreach ($s->fetchAll() as $r) {
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $r['delivery'], $m)) {
        foreach ($m[0] as $x) $mine[strtolower($x)] = true;
    }
}
$mine = array_keys($mine);

$tab   = $_GET['tab']   ?? 'outlook';   // email | hotmail | outlook
$email = strtolower(trim($_GET['email'] ?? ''));
$doLive = !empty($_GET['live']);

$found = null; $list = []; $apiMsgs = []; $live = null;
$msg = ''; $apiErr = '';

if ($email !== '') {
    if (!in_array($email, $mine, true)) {
        $msg = 'This email is not in your delivered orders.';
    } else {
        // নিজের DB
        $q = $pdo->prepare('SELECT code, received_at, TIMESTAMPDIFF(SECOND, received_at, NOW()) AS age FROM otp_codes WHERE email = ? ORDER BY id DESC LIMIT 5');
        $q->execute([$email]);
        $list = $q->fetchAll();
        $found = $list[0] ?? null;

        // MailGen API
        $acc = mailgen_parse_account($email);
        if ($acc) {
            if ($acc['is_edu']) {
                $res = mailgen_call('/api/edu-mail', ['email' => $acc['email']]);
            } elseif ($acc['has_oauth']) {
                $res = mailgen_call('/api/inbox-read', ['emailData' => $acc['full'], 'messageCount' => 20]);
                if ($doLive) $live = mailgen_call('/api/live-check', ['emailData' => $acc['full']]);
            } else {
                $res = null; $apiErr = 'Missing refresh_token/client_id in the account line.';
            }
            if (!empty($res['messages'])) $apiMsgs = $res['messages'];
            elseif (!empty($res['error'])) $apiErr = $res['error'];
        } else {
            $apiErr = 'Account line not found in your delivered orders.';
        }

        if (!$found && $apiMsgs) {
            foreach ($apiMsgs as $m) if (!empty($m['code'])) { $found = ['code' => $m['code'], 'age' => null, 'from_api' => true]; break; }
        }
        if (!$found && !$apiMsgs && !$apiErr && !$list) $msg = 'No OTP received yet for this account.';
    }
}

function ago(int $s): string { return $s < 60 ? $s . 's ago' : ($s < 3600 ? intdiv($s, 60) . ' min ago' : ($s < 86400 ? intdiv($s, 3600) . ' h ago' : intdiv($s, 86400) . ' d ago')); }
user_start('Read OTP', $u, 'otp'); ?>

<style>
/* ═══ OTP Page — self-contained ═══ */
.otp-hero{
  background:linear-gradient(135deg,#4f46e5 0,#6d28d9 55%,#7c3aed);
  border-radius:22px;padding:22px 20px;color:#fff;margin-bottom:18px;
  position:relative;overflow:hidden;
  box-shadow:0 20px 40px -18px rgba(79,70,229,.6);
}
.otp-hero::before,.otp-hero::after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.10);pointer-events:none}
.otp-hero::before{width:180px;height:180px;right:-60px;top:-80px}
.otp-hero::after{width:110px;height:110px;left:-40px;bottom:-60px;background:rgba(255,255,255,.07)}
.otp-hero>*{position:relative;z-index:1}
.otp-hero .hi{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.otp-hero .hi .hb{width:42px;height:42px;border-radius:13px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.32);display:flex;align-items:center;justify-content:center}
.otp-hero .hi .hb svg.i{width:22px;height:22px}
.otp-hero h3{margin:0;font-size:1.15rem;color:#fff}
.otp-hero p{margin:0;font-size:.85rem;color:rgba(255,255,255,.85);line-height:1.45}

/* tabs */
.otp-tabs{display:flex;gap:4px;background:#f1f5f9;padding:5px;border-radius:14px;margin:18px 0 14px;border:1px solid #e2e8f0}
.otp-tabs a{
  flex:1;display:flex;align-items:center;justify-content:center;gap:6px;
  padding:10px 8px;border-radius:10px;color:#64748b;font-size:.85rem;font-weight:600;
  transition:all .2s;text-align:center;line-height:1.2;
}
.otp-tabs a svg.i{width:16px;height:16px}
.otp-tabs a.on{background:#fff;color:#4338ca;box-shadow:0 2px 6px rgba(15,23,42,.08)}
.otp-tabs a:not(.on):hover{color:#334155}

/* textarea */
.otp-input{
  width:100%;min-height:110px;padding:14px;border:1.5px solid #cbd5e1;
  border-radius:14px;font:inherit;font-size:.9rem;line-height:1.5;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  background:#f8fafc;color:#1e293b;resize:vertical;
  transition:border .2s,box-shadow .2s,background .2s;
}
.otp-input:focus{outline:0;border-color:#4f46e5;background:#fff;box-shadow:0 0 0 4px rgba(79,70,229,.12)}
.otp-input::placeholder{color:#94a3b8}

/* format hint */
.otp-format{
  display:flex;align-items:center;gap:10px;margin-top:10px;padding:11px 14px;
  background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;font-size:.82rem;color:#475569;
}
.otp-format svg.i{width:16px;height:16px;color:#4f46e5;flex:none}
.otp-format code{background:#fff;padding:2px 8px;border-radius:6px;font-family:ui-monospace,monospace;font-size:.82rem;color:#4338ca;border:1px solid #e0e7ff}

/* primary button */
.otp-go{
  display:flex;align-items:center;justify-content:center;gap:9px;
  width:100%;margin-top:14px;padding:15px;
  border:0;border-radius:14px;color:#fff;font:inherit;font-weight:700;font-size:1rem;
  background:linear-gradient(135deg,#4f46e5,#7c3aed);
  box-shadow:0 12px 24px -10px rgba(79,70,229,.7),inset 0 1px 0 rgba(255,255,255,.3);
  cursor:pointer;transition:transform .15s,box-shadow .2s;
}
.otp-go:hover{transform:translateY(-1px);box-shadow:0 16px 30px -10px rgba(79,70,229,.8),inset 0 1px 0 rgba(255,255,255,.3)}
.otp-go:active{transform:scale(.98)}
.otp-go svg.i{width:20px;height:20px;stroke-width:2.2}
.otp-go:disabled{opacity:.7;cursor:wait}

/* instructions */
.otp-info{
  margin-top:14px;padding:14px 16px;border-radius:14px;
  background:linear-gradient(135deg,#eef2ff,#f5f3ff);
  border:1px dashed #c7d2fe;font-size:.85rem;color:#4338ca;
}
.otp-info h4{margin:0 0 8px;font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;display:flex;align-items:center;gap:6px}
.otp-info h4 svg.i{width:15px;height:15px}
.otp-info ul{margin:0;padding-left:0;list-style:none}
.otp-info li{position:relative;padding:4px 0 4px 18px;color:#475569;line-height:1.5}
.otp-info li::before{content:"•";position:absolute;left:4px;color:#4f46e5;font-weight:800}
.otp-info code{background:#fff;padding:1px 7px;border-radius:6px;font-family:ui-monospace,monospace;color:#4338ca;border:1px solid #e0e7ff;font-size:.8rem}

/* code result */
.otp-result{
  background:linear-gradient(135deg,#dcfce7,#f0fdf4);
  border:1px solid #86efac;border-radius:20px;padding:22px 20px;
  text-align:center;margin-bottom:18px;position:relative;overflow:hidden;
  animation:up .5s both;
}
.otp-result::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#16a34a,#22c55e,#16a34a)}
.otp-result .lb{display:inline-flex;align-items:center;gap:6px;font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#15803d;margin-bottom:8px}
.otp-result .lb svg.i{width:14px;height:14px}
.otp-result .code{
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  font-size:2.6rem;font-weight:800;letter-spacing:.18em;line-height:1.1;
  background:linear-gradient(90deg,#15803d,#16a34a);
  -webkit-background-clip:text;background-clip:text;color:transparent;
  word-break:break-all;margin:4px 0 6px;
}
.otp-result .when{color:#166534;font-size:.85rem;margin-bottom:14px}
.otp-result .cp{
  display:inline-flex;align-items:center;gap:8px;
  padding:11px 22px;border-radius:12px;
  background:#16a34a;color:#fff;border:0;font:inherit;font-weight:600;font-size:.9rem;
  box-shadow:0 10px 20px -8px rgba(22,163,74,.7);cursor:pointer;transition:transform .15s;
}
.otp-result .cp:active{transform:scale(.97)}
.otp-result .cp svg.i{width:16px;height:16px}
.otp-result .cp.done{background:#15803d}

/* live badge */
.otp-live{
  display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:14px;
  background:#fff;border:1px solid #e2e8f0;margin-bottom:14px;
}
.otp-live .dot{width:10px;height:10px;border-radius:50%;flex:none}
.otp-live.live .dot{background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,.2)}
.otp-live.dead .dot{background:#dc2626;box-shadow:0 0 0 4px rgba(220,38,38,.2)}
.otp-live b{font-size:.95rem}
.otp-live small{display:block;color:#64748b;font-size:.8rem;margin-top:2px}

/* message list */
.otp-msg{
  display:flex;gap:12px;padding:13px 0;border-bottom:1px solid #f1f5f9;
}
.otp-msg:last-child{border-bottom:0}
.otp-msg .av{
  width:38px;height:38px;border-radius:11px;flex:none;
  background:linear-gradient(135deg,#eef2ff,#f5f3ff);
  border:1px solid #e0e7ff;
  display:flex;align-items:center;justify-content:center;
  font-size:1rem;font-weight:700;color:#4f46e5;
}
.otp-msg .body{flex:1;min-width:0}
.otp-msg .body b{display:block;font-size:.9rem;line-height:1.35;overflow-wrap:anywhere}
.otp-msg .body .meta{color:#64748b;font-size:.76rem;margin:3px 0 0}
.otp-msg .body .prev{
  margin-top:7px;padding:8px 10px;border-radius:9px;background:#f8fafc;
  font-size:.8rem;color:#475569;line-height:1.5;
  border-left:3px solid #c7d2fe;overflow-wrap:anywhere;
}
.otp-msg .badge-c{
  align-self:flex-start;flex:none;
  font-family:ui-monospace,monospace;font-weight:700;font-size:.88rem;
  padding:5px 11px;border-radius:99px;
  background:#dcfce7;color:#166534;border:1px solid #86efac;
}
.otp-c{display:block;text-align:center;color:#64748b;font-size:.85rem;margin-bottom:6px}
.otp-quick{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.otp-quick a{
  padding:6px 12px;border-radius:99px;font-size:.78rem;font-weight:600;
  background:#eef2ff;color:#4338ca;border:1px solid #e0e7ff;transition:background .15s;
}
.otp-quick a:hover{background:#e0e7ff}
</style>

<!-- ═══ HERO ═══ -->
<div class="otp-hero">
  <div class="hi">
    <div class="hb"><?= icon('mail') ?></div>
    <div>
      <h3>Read OTP</h3>
      <p>Get the latest verification code from your account inbox — instantly.</p>
    </div>
  </div>
</div>

<!-- ═══ TABS ═══ -->
<div class="otp-tabs">
  <a href="?tab=email" class="<?= $tab === 'email' ? 'on' : '' ?>"><?= icon('mail') ?> Email Code</a>
  <a href="?tab=hotmail" class="<?= $tab === 'hotmail' ? 'on' : '' ?>"><?= icon('mail') ?> Hotmail</a>
  <a href="?tab=outlook" class="<?= $tab === 'outlook' ? 'on' : '' ?>"><?= icon('mail') ?> Outlook</a>
</div>

<!-- ═══ FORM CARD ═══ -->
<div class="card">
  <h3 style="margin-bottom:6px">Get Verification Code</h3>
  <p class="sub" style="text-align:left;margin-bottom:14px;font-size:.85rem">
    Paste one or more accounts in the format below. Secrets stay hidden on your device.
  </p>

  <form method="get" id="otpForm">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label style="margin-top:0">Account details</label>
    <textarea class="otp-input" name="email" required placeholder="email|password|refresh_token|client_id
user@hotmail.com|mypass|M.C509_BAY...|9e5f94bc-..."><?= e($email) ?></textarea>

    <div class="otp-format">
      <?= icon('shield') ?>
      <span>Format: <code>email|password|refresh_token|client_id</code></span>
    </div>

    <label class="chk" style="margin-top:14px">
      <input type="checkbox" name="live" value="1" <?= $doLive ? 'checked' : '' ?>>
      <span>Also run <b>Live Check</b> (account active/dead)</span>
    </label>

    <button type="submit" class="otp-go" id="otpBtn">
      <?= icon('send') ?> <span>Get Verification Code</span>
    </button>
  </form>

  <div class="otp-info">
    <h4><?= icon('shield') ?> Instructions</h4>
    <ul>
      <li>Required: <code>email|password|token|client_id</code></li>
      <li>Hosts supported: <code>@hotmail</code>, <code>@outlook</code>, <code>@live</code></li>
      <li>Your credentials are never stored on our server.</li>
    </ul>
  </div>

  <?php if ($mine): ?>
    <label style="margin-top:18px;margin-bottom:6px;font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8">Your accounts</label>
    <div class="otp-quick">
      <?php foreach (array_slice($mine, 0, 12) as $x): ?>
        <a href="?email=<?= urlencode($x) ?>&tab=<?= e($tab) ?>"><?= e($x) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ═══ ERROR ═══ -->
<?php if ($msg):    ?><div class="err"><?= e($msg) ?></div><?php endif; ?>
<?php if ($apiErr): ?><div class="err"><b>API:</b> <?= e($apiErr) ?></div><?php endif; ?>

<!-- ═══ LIVE CHECK ═══ -->
<?php if ($live): ?>
  <div class="otp-live <?= !empty($live['isLive']) ? 'live' : 'dead' ?>">
    <span class="dot"></span>
    <div>
      <b><?= !empty($live['isLive']) ? 'Account is LIVE' : 'Account is DEAD' ?></b>
      <small><?= e($live['error'] ?? ($live['status'] ?? 'Checked just now')) ?></small>
    </div>
  </div>
<?php endif; ?>

<!-- ═══ RESULT ═══ -->
<?php if ($found): ?>
  <div class="otp-result">
    <div class="lb"><?= icon('shield') ?> <?= !empty($found['from_api']) ? 'Live inbox' : 'Latest code' ?></div>
    <div class="code" id="otpv"><?= e($found['code']) ?></div>
    <div class="when">
      <?php if (isset($found['age'])): ?>
        Received <?= e(ago((int)$found['age'])) ?><?= (int)$found['age'] > 600 ? ' — may have expired' : '' ?>
      <?php else: ?>Fetched just now<?php endif; ?>
    </div>
    <input type="text" id="otpc" readonly value="<?= e($found['code']) ?>" style="position:absolute;left:-9999px">
    <button type="button" class="cp" data-copy="#otpc" id="cpBtn"><?= icon('copy') ?> <span>Copy code</span></button>
  </div>
<?php endif; ?>

<!-- ═══ INBOX MESSAGES ═══ -->
<?php if ($apiMsgs): ?>
  <div class="card">
    <h3 style="margin-bottom:14px;display:flex;align-items:center;gap:8px">
      <?= icon('mail') ?> Inbox messages
      <span style="margin-left:auto;font-size:.78rem;font-weight:600;background:#eef2ff;color:#4338ca;padding:3px 10px;border-radius:99px"><?= count($apiMsgs) ?></span>
    </h3>
    <?php foreach (array_slice($apiMsgs, 0, 15) as $m):
        $initial = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $m['from'] ?? '?'), 0, 1)) ?: '?';
    ?>
      <div class="otp-msg">
        <div class="av"><?= e($initial) ?></div>
        <div class="body">
          <b><?= e($m['subject'] ?? '(no subject)') ?></b>
          <div class="meta">
            <?= e($m['from'] ?? 'Unknown') ?>
            <?php if (!empty($m['date'])): ?> · <?= e($m['date']) ?><?php endif; ?>
          </div>
          <?php if (!empty($m['preview'])): ?><div class="prev"><?= e($m['preview']) ?></div>
          <?php elseif (!empty($m['message'])): ?><div class="prev"><?= e($m['message']) ?></div><?php endif; ?>
        </div>
        <?php if (!empty($m['code'])): ?><div class="badge-c"><?= e($m['code']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ═══ EARLIER CODES ═══ -->
<?php if (count($list) > 1): ?>
  <div class="card">
    <h3 style="margin-bottom:12px">Earlier codes</h3>
    <div class="tw"><table>
      <tr><th>Code</th><th>Received</th></tr>
      <?php foreach (array_slice($list, 1) as $r): ?>
        <tr><td class="mono"><b><?= e($r['code']) ?></b></td><td><?= e(ago((int)$r['age'])) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
<?php endif; ?>

<script>
/* Submit button loading state */
document.getElementById('otpForm')?.addEventListener('submit',function(){
  var b=document.getElementById('otpBtn');
  if(b){ b.disabled=true; b.querySelector('span').textContent='Fetching…'; }
});
/* Copy button feedback */
document.getElementById('cpBtn')?.addEventListener('click',function(){
  var s=this.querySelector('span'); var o=s.textContent;
  s.textContent='Copied!'; this.classList.add('done');
  setTimeout(function(){ s.textContent=o; document.getElementById('cpBtn').classList.remove('done'); },1600);
});
/* Auto-detect tab from typed email domain */
(function(){
  var ta=document.querySelector('.otp-input'); var tabs=document.querySelector('.otp-tabs');
  if(!ta||!tabs) return;
  ta.addEventListener('input',function(){
    var v=ta.value.toLowerCase();
    var t=v.includes('@hotmail')?'hotmail':(v.includes('@outlook')||v.includes('@live')?'outlook':'');
    if(!t) return;
    Array.prototype.forEach.call(tabs.children,function(a){
      a.classList.toggle('on', a.getAttribute('href').indexOf('tab='+t)>=0);
    });
  });
})();
</script>

<?php user_end(); ?>