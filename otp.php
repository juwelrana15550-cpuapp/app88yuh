<?php require __DIR__ . '/lib.php';
$u = require_login();

$tab = $_GET['tab'] ?? 'hotmail';          // hotmail | email | 2fa
$inputRaw = trim($_GET['data'] ?? '');
$found = null; $messages = []; $live = null;
$msg = ''; $apiErr = '';

// ═══ API Call by Tab ═══
if ($inputRaw !== '') {
    // multi-line হলে শুধু প্রথম non-empty line নিই (single account per request)
    $firstLine = '';
    foreach (preg_split('/\r\n|\r|\n/', $inputRaw) as $l) {
        $l = trim($l);
        if ($l !== '') { $firstLine = $l; break; }
    }

    $acc = mailgen_parse($firstLine, $tab);
    if (!$acc) {
        $msg = match($tab) {
            'hotmail' => 'Invalid format. Use: email|password|refresh_token|client_id',
            '2fa'     => 'Invalid format. Use: email|refresh_token|client_id|client_secret',
            'email'   => 'Invalid email address.',
            default   => 'Invalid input.',
        };
    } else {
        // ─── endpoint + body per tab ───
        if ($tab === 'hotmail') {
            $res = mailgen_call('/api/inbox-read', [
                'emailData'    => $acc['full'],
                'messageCount' => 20,
            ]);
            $live = mailgen_call('/api/live-check', ['emailData' => $acc['full']]);

        } elseif ($tab === 'email') {
            $res = mailgen_call('/api/edu-mail', ['email' => $acc['email']]);

        } else { // 2fa
            $res = mailgen_call('/api/get-inbox', [
                'data' => $acc['full'],
                'mode' => 'oauth',
            ]);
        }

        // ─── response পার্স ───
        if (!empty($res['messages']) && is_array($res['messages'])) {
            $messages = $res['messages'];
            // প্রথম code বের করি
            foreach ($messages as $m) {
                $c = $m['code'] ?? null;
                if (!$c) {
                    $txt = ($m['preview'] ?? '') . ' ' . ($m['message'] ?? '') . ' ' . ($m['subject'] ?? '');
                    $c = mailgen_extract_code($txt);
                }
                if ($c) { $found = ['code' => $c]; break; }
            }
        } elseif (!empty($res['error'])) {
            $apiErr = $res['error'];
        } else {
            $apiErr = $res['status'] ?? 'No messages returned.';
        }
    }
}

user_start('Read OTP', $u, 'otp'); ?>

<style>
/* ═══════ OTP Page ═══════ */
.otp-wrap{max-width:560px;margin:0 auto}
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
.otp-hero .hi{display:flex;align-items:center;gap:12px}
.otp-hero .hb{width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.32);display:flex;align-items:center;justify-content:center;flex:none}
.otp-hero .hb svg.i{width:22px;height:22px}
.otp-hero h2{margin:0;font-size:1.15rem;color:#fff;text-align:left}
.otp-hero p{margin:2px 0 0;font-size:.83rem;color:rgba(255,255,255,.85);line-height:1.45;text-align:left}

/* tabs — 3 equal buttons */
.otp-tabs{
  display:grid;grid-template-columns:repeat(3,1fr);gap:6px;
  background:#f1f5f9;padding:6px;border-radius:16px;margin-bottom:16px;
  border:1px solid #e2e8f0;
}
.otp-tabs a{
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;
  padding:11px 6px;border-radius:12px;color:#64748b;
  font-size:.78rem;font-weight:600;text-align:center;line-height:1.2;
  transition:all .2s;position:relative;
}
.otp-tabs a svg.i{width:20px;height:20px;stroke-width:1.9}
.otp-tabs a.on{background:#fff;color:#4338ca;box-shadow:0 3px 10px rgba(15,23,42,.1),0 0 0 1px rgba(79,70,229,.12)}
.otp-tabs a:not(.on):hover{color:#334155;background:rgba(255,255,255,.5)}
.otp-tabs a .tag{
  position:absolute;top:6px;right:6px;font-size:.6rem;font-weight:800;
  background:#4f46e5;color:#fff;padding:1px 5px;border-radius:99px;line-height:1.4;
}
.otp-tabs a.on .tag{background:#7c3aed}

/* form card */
.otp-card{
  background:#fff;border:1px solid rgba(148,163,184,.22);border-radius:20px;
  padding:20px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 12px 32px -14px rgba(30,27,75,.22);
  margin-bottom:16px;animation:up .5s both;
}
.otp-card h3{margin:0 0 4px;font-size:1rem;text-align:left;display:flex;align-items:center;gap:8px}
.otp-card h3 .ic{
  width:28px;height:28px;border-radius:9px;flex:none;
  background:linear-gradient(135deg,#eef2ff,#f5f3ff);
  border:1px solid #e0e7ff;display:inline-flex;align-items:center;justify-content:center;
}
.otp-card h3 .ic svg.i{width:15px;height:15px;color:#4f46e5}
.otp-card .hint{font-size:.83rem;color:#64748b;margin:0 0 14px;line-height:1.5}
.otp-card .hint b{color:#334155;font-weight:600}

/* textarea */
.otp-input{
  width:100%;min-height:96px;padding:14px;border:1.5px solid #cbd5e1;
  border-radius:14px;font:inherit;font-size:.88rem;line-height:1.5;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  background:#f8fafc;color:#1e293b;resize:vertical;
  transition:border .2s,box-shadow .2s,background .2s;
}
.otp-input:focus{outline:0;border-color:#4f46e5;background:#fff;box-shadow:0 0 0 4px rgba(79,70,229,.12)}
.otp-input::placeholder{color:#94a3b8}

/* format hint */
.otp-format{
  display:flex;align-items:flex-start;gap:10px;margin-top:10px;padding:11px 14px;
  background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;
  font-size:.8rem;color:#475569;line-height:1.5;
}
.otp-format svg.i{width:16px;height:16px;color:#4f46e5;flex:none;margin-top:2px}
.otp-format code{
  background:#fff;padding:2px 7px;border-radius:6px;
  font-family:ui-monospace,monospace;font-size:.78rem;color:#4338ca;border:1px solid #e0e7ff;
  display:inline-block;margin:2px 0;
}

/* big button */
.otp-go{
  display:flex;align-items:center;justify-content:center;gap:9px;
  width:100%;margin-top:14px;padding:15px;
  border:0;border-radius:14px;color:#fff;font:inherit;font-weight:700;font-size:.98rem;
  background:linear-gradient(135deg,#4f46e5,#7c3aed);
  box-shadow:0 12px 24px -10px rgba(79,70,229,.7),inset 0 1px 0 rgba(255,255,255,.3);
  cursor:pointer;transition:transform .15s,box-shadow .2s;
}
.otp-go:hover{transform:translateY(-1px);box-shadow:0 16px 30px -10px rgba(79,70,229,.8),inset 0 1px 0 rgba(255,255,255,.3)}
.otp-go:active{transform:scale(.98)}
.otp-go svg.i{width:20px;height:20px;stroke-width:2.2}
.otp-go:disabled{opacity:.75;cursor:wait}

/* code result */
.otp-result{
  background:linear-gradient(135deg,#dcfce7,#f0fdf4);
  border:1px solid #86efac;border-radius:20px;padding:24px 20px;
  text-align:center;margin-bottom:16px;position:relative;overflow:hidden;
  animation:up .5s both;
}
.otp-result::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#16a34a,#22c55e,#16a34a)}
.otp-result .lb{display:inline-flex;align-items:center;gap:6px;font-size:.74rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#15803d;margin-bottom:8px}
.otp-result .lb svg.i{width:14px;height:14px}
.otp-result .code{
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  font-size:2.6rem;font-weight:800;letter-spacing:.16em;line-height:1.1;
  background:linear-gradient(90deg,#15803d,#16a34a);
  -webkit-background-clip:text;background-clip:text;color:transparent;
  word-break:break-all;margin:6px 0 12px;
}
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
.otp-live{display:flex;align-items:center;gap:12px;padding:13px 16px;border-radius:14px;background:#fff;border:1px solid #e2e8f0;margin-bottom:14px}
.otp-live .dot{width:10px;height:10px;border-radius:50%;flex:none}
.otp-live.live{border-color:#86efac;background:#f0fdf4}
.otp-live.live .dot{background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,.2)}
.otp-live.dead{border-color:#fecaca;background:#fef2f2}
.otp-live.dead .dot{background:#dc2626;box-shadow:0 0 0 4px rgba(220,38,38,.2)}
.otp-live b{font-size:.92rem;color:#1e293b}
.otp-live small{display:block;color:#64748b;font-size:.78rem;margin-top:2px}

/* message list */
.otp-msg{display:flex;gap:12px;padding:13px 0;border-bottom:1px solid #f1f5f9}
.otp-msg:last-child{border-bottom:0}
.otp-msg .av{
  width:38px;height:38px;border-radius:11px;flex:none;
  background:linear-gradient(135deg,#eef2ff,#f5f3ff);
  border:1px solid #e0e7ff;display:flex;align-items:center;justify-content:center;
  font-size:1rem;font-weight:700;color:#4f46e5;
}
.otp-msg .body{flex:1;min-width:0}
.otp-msg .body b{display:block;font-size:.9rem;line-height:1.35;overflow-wrap:anywhere;color:#1e293b}
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

/* instructions */
.otp-info{
  margin-top:16px;padding:14px 16px;border-radius:14px;
  background:linear-gradient(135deg,#eef2ff,#f5f3ff);
  border:1px dashed #c7d2fe;font-size:.83rem;color:#4338ca;
}
.otp-info h4{margin:0 0 8px;font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;display:flex;align-items:center;gap:6px}
.otp-info h4 svg.i{width:14px;height:14px}
.otp-info ul{margin:0;padding:0;list-style:none}
.otp-info li{position:relative;padding:4px 0 4px 18px;color:#475569;line-height:1.55}
.otp-info li::before{content:"•";position:absolute;left:5px;color:#4f46e5;font-weight:800}
.otp-info code{background:#fff;padding:1px 6px;border-radius:5px;font-family:ui-monospace,monospace;color:#4338ca;border:1px solid #e0e7ff;font-size:.78rem}

.otp-c{display:block;text-align:center;color:#64748b;font-size:.85rem;margin:0 0 8px}
.otp-err{background:#fee2e2;color:#b91c1c;padding:12px 14px;border-radius:12px;margin-bottom:14px;font-size:.88rem;border:1px solid #fecaca}
.otp-err b{font-weight:700}
</style>

<div class="otp-wrap">

  <!-- HERO -->
  <div class="otp-hero">
    <div class="hi">
      <div class="hb"><?= icon('mail') ?></div>
      <div>
        <h2>Read OTP</h2>
        <p>Get verification codes from your inbox — instantly.</p>
      </div>
    </div>
  </div>

  <!-- TABS -->
  <div class="otp-tabs" id="otpTabs">
    <a href="?tab=hotmail" class="<?= $tab === 'hotmail' ? 'on' : '' ?>">
      <?= icon('mail') ?><span>Hotmail &amp; Outlook</span>
    </a>
    <a href="?tab=email" class="<?= $tab === 'email' ? 'on' : '' ?>">
      <?= icon('send') ?><span>Email Code</span>
    </a>
    <a href="?tab=2fa" class="<?= $tab === '2fa' ? 'on' : '' ?>">
      <?= icon('shield') ?><span>2FA Code</span>
    </a>
  </div>

  <!-- FORM -->
  <div class="otp-card">
    <?php if ($tab === 'hotmail'): ?>
      <h3><span class="ic"><?= icon('mail') ?></span> Hotmail &amp; Outlook</h3>
      <p class="hint">Paste your account details to read messages from the live inbox.</p>
      <form method="get" id="otpForm">
        <input type="hidden" name="tab" value="hotmail">
        <textarea class="otp-input" name="data" required
          placeholder="user@hotmail.com|mypassword|M.C509_BAY...|9e5f94bc-..."><?= e($inputRaw) ?></textarea>
        <div class="otp-format">
          <?= icon('shield') ?>
          <span>Format: <code>email|password|refresh_token|client_id</code></span>
        </div>
        <button type="submit" class="otp-go" id="otpBtn">
          <?= icon('send') ?><span>Get Verification Code</span>
        </button>
      </form>
      <div class="otp-info">
        <h4><?= icon('shield') ?> Instructions</h4>
        <ul>
          <li>Required: <code>email|password|token|client_id</code></li>
          <li>Supported hosts: <code>@hotmail</code>, <code>@outlook</code>, <code>@live</code></li>
          <li>Your credentials are never stored on our server.</li>
        </ul>
      </div>

    <?php elseif ($tab === 'email'): ?>
      <h3><span class="ic"><?= icon('send') ?></span> Email Code</h3>
      <p class="hint">Enter your Gmail Edu address to read the verification code.</p>
      <form method="get" id="otpForm">
        <input type="hidden" name="tab" value="email">
        <textarea class="otp-input" name="data" required
          placeholder="user@emailfake.com"><?= e($inputRaw) ?></textarea>
        <div class="otp-format">
          <?= icon('mail') ?>
          <span>Format: <code>email</code> &nbsp;(Gmail Edu only)</span>
        </div>
        <button type="submit" class="otp-go" id="otpBtn">
          <?= icon('send') ?><span>Get Email Code</span>
        </button>
      </form>
      <div class="otp-info">
        <h4><?= icon('shield') ?> Instructions</h4>
        <ul>
          <li>Example: <code>user@emailfake.com</code></li>
          <li>No password required for this service.</li>
          <li>Only works with Gmail Edu accounts.</li>
        </ul>
      </div>

    <?php else: ?>
      <h3><span class="ic"><?= icon('shield') ?></span> 2FA Code</h3>
      <p class="hint">Paste OAuth details to fetch the latest 2FA verification code.</p>
      <form method="get" id="otpForm">
        <input type="hidden" name="tab" value="2fa">
        <textarea class="otp-input" name="data" required
          placeholder="user@outlook.com|refresh_token|client_id|client_secret"><?= e($inputRaw) ?></textarea>
        <div class="otp-format">
          <?= icon('shield') ?>
          <span>Format: <code>email|refresh_token|client_id|client_secret</code></span>
        </div>
        <button type="submit" class="otp-go" id="otpBtn">
          <?= icon('send') ?><span>Get 2FA Code</span>
        </button>
      </form>
      <div class="otp-info">
        <h4><?= icon('shield') ?> Instructions</h4>
        <ul>
          <li>Required: <code>email|refresh_token|client_id|client_secret</code></li>
          <li>Uses OAuth2 mode for the fastest response.</li>
          <li>Secrets are sent directly to the API — never logged.</li>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <!-- ERRORS -->
  <?php if ($msg):    ?><div class="otp-err"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($apiErr): ?><div class="otp-err"><b>API:</b> <?= e($apiErr) ?></div><?php endif; ?>

  <!-- LIVE CHECK (hotmail only) -->
  <?php if ($live): ?>
    <div class="otp-live <?= !empty($live['isLive']) ? 'live' : 'dead' ?>">
      <span class="dot"></span>
      <div>
        <b><?= !empty($live['isLive']) ? 'Account is LIVE' : 'Account is DEAD' ?></b>
        <small><?= e($live['error'] ?? ($live['status'] ?? 'Checked just now')) ?></small>
      </div>
    </div>
  <?php endif; ?>

  <!-- RESULT CODE -->
  <?php if ($found): ?>
    <div class="otp-result">
      <div class="lb"><?= icon('shield') ?> Verification code</div>
      <div class="code" id="otpv"><?= e($found['code']) ?></div>
      <input type="text" id="otpc" readonly value="<?= e($found['code']) ?>" style="position:absolute;left:-9999px">
      <button type="button" class="cp" data-copy="#otpc" id="cpBtn">
        <?= icon('copy') ?><span>Copy code</span>
      </button>
    </div>
  <?php endif; ?>

  <!-- INBOX MESSAGES -->
  <?php if ($messages): ?>
    <div class="otp-card">
      <h3 style="margin-bottom:6px;display:flex;align-items:center;gap:8px">
        <span class="ic"><?= icon('mail') ?></span> Inbox messages
        <span style="margin-left:auto;font-size:.76rem;font-weight:700;background:#eef2ff;color:#4338ca;padding:3px 10px;border-radius:99px"><?= count($messages) ?></span>
      </h3>
      <?php foreach (array_slice($messages, 0, 15) as $m):
        $from = (string)($m['from'] ?? 'Unknown');
        $initial = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $from), 0, 1)) ?: '?';
        $code = $m['code'] ?? null;
        if (!$code) {
          $txt = ($m['preview'] ?? '') . ' ' . ($m['message'] ?? '') . ' ' . ($m['subject'] ?? '');
          $code = mailgen_extract_code($txt);
        }
      ?>
        <div class="otp-msg">
          <div class="av"><?= e($initial) ?></div>
          <div class="body">
            <b><?= e($m['subject'] ?? '(no subject)') ?></b>
            <div class="meta">
              <?= e($from) ?><?php if (!empty($m['date'])): ?> · <?= e($m['date']) ?><?php endif; ?>
            </div>
            <?php if (!empty($m['preview'])): ?><div class="prev"><?= e($m['preview']) ?></div>
            <?php elseif (!empty($m['message'])): ?><div class="prev"><?= e($m['message']) ?></div><?php endif; ?>
          </div>
          <?php if ($code): ?><div class="badge-c"><?= e($code) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($inputRaw !== '' && !$msg && !$apiErr): ?>
    <div class="otp-card" style="text-align:center;padding:32px 20px">
      <div style="font-size:2rem;margin-bottom:6px">📭</div>
      <b style="font-size:.95rem">No messages found</b>
      <p style="color:#64748b;font-size:.84rem;margin-top:6px">The inbox is empty or no verification code was received.</p>
    </div>
  <?php endif; ?>

</div>

<script>
/* Submit → loading state */
document.getElementById('otpForm')?.addEventListener('submit',function(){
  var b=document.getElementById('otpBtn');
  if(b){ b.disabled=true; var s=b.querySelector('span'); if(s) s.textContent='Fetching…'; }
});
/* Copy button feedback */
document.getElementById('cpBtn')?.addEventListener('click',function(){
  var s=this.querySelector('span'); var o=s.textContent;
  s.textContent='Copied!'; this.classList.add('done');
  setTimeout(function(){ s.textContent=o; document.getElementById('cpBtn').classList.remove('done'); },1600);
});
</script>

<?php user_end(); ?>