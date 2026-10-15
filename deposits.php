<?php require __DIR__ . '/lib.php';
require __DIR__ . '/payment_methods.php';
$u = require_login();

/* Payment methods, limits, fees and the on/off switch are all managed in Admin > Payment methods. */
$CFG = dep_cfg();
$usdRate = function_exists('usd_rate') ? (float)usd_rate() : 0.0;   // BDT per 1 USD (Admin > Settings)
$ALL = pm_all();                 // every method (used to recognise old deposits)
$METHODS = [];                   // methods the admin has switched ON
foreach ($ALL as $k => $m) {
    if (!(int)$m['enabled']) continue;
    $m['ok'] = pm_usable($m, $usdRate);
    [$m['lmin'], $m['lmax']] = pm_limits($m, $CFG);
    $METHODS[$k] = $m;
}
$nf = function (float $n): string { return number_format($n, floor($n) == $n ? 0 : 2); };

/* ============================== Submit ============================== */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amt = round((float)($_POST['amount'] ?? 0), 2);
    $mk  = (string)($_POST['method'] ?? '');
    $trx = strtoupper(preg_replace('/\s+/', '', (string)($_POST['trx'] ?? '')));
    if (!$CFG['on']) $err = 'Deposits are paused right now. Please try again later.';
    elseif (!isset($METHODS[$mk]) || !$METHODS[$mk]['ok']) $err = 'Please choose a payment method.';
    elseif ($amt < $METHODS[$mk]['lmin'] || $amt > $METHODS[$mk]['lmax']) $err = 'Amount for ' . $METHODS[$mk]['name'] . ' must be between ' . $nf($METHODS[$mk]['lmin']) . ' and ' . $nf($METHODS[$mk]['lmax']) . '.';
    elseif (!preg_match('/^[A-Z0-9]{6,40}$/', $trx)) $err = 'Enter a valid ' . $METHODS[$mk]['tid_label'] . ' (letters and numbers only, 6-40 characters).';
    else {
        $M = $METHODS[$mk];
        $pdo = db();
        $st = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id = ? AND status = 'pending'"); $st->execute([$u['id']]);
        if ((int)$st->fetchColumn() >= $CFG['pending']) {
            $err = 'You already have ' . $CFG['pending'] . ' requests waiting for review. Please wait until they are approved.';
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM deposits WHERE note LIKE ?'); $st->execute(['%TrxID: ' . $trx . ' |%']);
            if ((int)$st->fetchColumn() > 0) $err = 'This ' . $M['tid_label'] . ' was already submitted.';
            else {
                $paid = $M['is_usd'] ? '$' . number_format($amt / $usdRate, 2) : 'BDT ' . number_format($amt, 2);
                $credit = pm_credit($M, $amt);   // amount minus the method fee, plus the method bonus
                $note = $M['name'] . ' | TrxID: ' . $trx . ' | ' . $paid;   // shown to the admin as the deposit note
                if ((float)$M['fee_pct'] > 0)   $note .= ' | Fee ' . (float)$M['fee_pct'] . '%';
                if ((float)$M['bonus_pct'] > 0) $note .= ' | Bonus ' . (float)$M['bonus_pct'] . '%';
                $pdo->prepare('INSERT INTO deposits (user_id, amount, note) VALUES (?,?,?)')->execute([$u['id'], $credit, substr($note, 0, 255)]);
                flash('ok', 'Request received. Your balance is credited as soon as we confirm the payment.');
                header('Location: /deposits.php'); exit;
            }
        }
    }
}

$s = db()->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 30'); $s->execute([$u['id']]);
$deps = $s->fetchAll();
$tot = ['approved' => 0.0, 'pending' => 0, 'n' => 0];
foreach ($deps as $d) {
    if ($d['status'] === 'approved') { $tot['approved'] += (float)$d['amount']; $tot['n']++; }
    if ($d['status'] === 'pending') $tot['pending']++;
}
$methodOf = function (string $note) use ($ALL) {
    foreach ($ALL as $k => $m) if (strpos($note, $m['name'] . ' |') === 0) return $k;
    return null;
};
$jsMethods = [];
foreach ($METHODS as $k => $m) $jsMethods[$k] = [
    'name' => $m['name'], 'ok' => $m['ok'], 'acct' => $m['ok'] ? $m['acct'] : '', 'ussd' => $m['ussd'], 'usd' => (bool)$m['is_usd'],
    'tid' => $m['tid_label'], 'label' => $m['acct_label'], 'sub' => $m['sub'], 'note' => $m['notice'], 'color' => $m['color'],
    'steps' => $m['steps'] !== null && $m['steps'] !== '' ? explode("\n", $m['steps']) : [],
    'min' => (float)$m['lmin'], 'max' => (float)$m['lmax'], 'fee' => (float)$m['fee_pct'], 'bonus' => (float)$m['bonus_pct'],
    'logo' => pm_logo_html($m, 40),
];

user_start('Deposits', $u, 'deposits'); ?>
<?= pm_css() ?>
<style>
.dp{--bg:#fff;--bg2:#f5f6fb;--ink:#12142a;--mute:#6b7089;--line:#e6e8f2;--acc:#5b4bff;--acc2:#8b5cf6;--ok:#12a36b;--warn:#d98a00;--bad:#e5484d;--sh:0 1px 2px rgba(18,20,42,.05),0 4px 14px rgba(18,20,42,.05);
  color:var(--ink);max-width:520px;margin:0 auto;font-family:inherit;font-size:14px;line-height:1.4;-webkit-text-size-adjust:100%}
.dp *{box-sizing:border-box}
.dp [hidden]{display:none!important}
.dp button{font-family:inherit;-webkit-tap-highlight-color:transparent}

/* Balance card */
.dp .dp-hero{position:relative;overflow:hidden;border-radius:18px;padding:16px;color:#fff;background:linear-gradient(135deg,#3b2fd1 0%,#6d4bff 55%,#a35bff 100%);box-shadow:0 10px 24px rgba(91,75,255,.28)}
.dp .dp-hero:before{content:"";position:absolute;width:170px;height:170px;border-radius:50%;right:-50px;top:-80px;background:rgba(255,255,255,.12)}
.dp .dp-hero>*{position:relative;z-index:1}
.dp .dp-hero small{display:block;opacity:.8;font-size:11px;font-weight:600;letter-spacing:.08em}
.dp .dp-bal{font-size:28px;font-weight:800;letter-spacing:-.02em;margin:2px 0 12px}
.dp .dp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.dp .dp-stat{background:rgba(255,255,255,.16);border-radius:12px;padding:8px 10px;min-width:0}
.dp .dp-stat b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dp .dp-stat span{display:block;font-size:10.5px;opacity:.85;line-height:1.25}

/* Card */
.dp .dp-box{background:var(--bg);border:1px solid var(--line);border-radius:18px;box-shadow:var(--sh);padding:16px;margin-top:14px}
.dp h3{margin:0;font-size:16px;font-weight:800}
.dp .dp-lead{color:var(--mute);font-size:12.5px;margin:2px 0 0}

/* Step indicator */
.dp .dp-steps{display:flex;align-items:center;gap:8px;margin-bottom:16px}
.dp .dp-stp{display:flex;align-items:center;gap:7px;color:var(--mute);font-size:12px;font-weight:700;white-space:nowrap}
.dp .dp-stp i{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-style:normal;background:var(--bg2);border:1px solid var(--line);font-size:11.5px;transition:.2s}
.dp .dp-stp.on{color:var(--ink)}
.dp .dp-stp.on i{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;border-color:transparent;box-shadow:0 3px 8px rgba(91,75,255,.35)}
.dp .dp-stp.done i{background:var(--ok);color:#fff;border-color:transparent}
.dp .dp-bar{flex:1;height:2px;background:var(--line);border-radius:2px}
.dp .dp-pane{display:none;animation:dpin .25s ease}.dp .dp-pane.on{display:block}
@keyframes dpin{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* Fields */
.dp label.dp-l{display:block;font-size:11px;font-weight:800;color:var(--mute);margin:0 0 6px;text-transform:uppercase;letter-spacing:.06em}
.dp .dp-sec{margin-top:16px}
.dp .dp-amt{display:flex;align-items:center;border:1.5px solid var(--line);border-radius:14px;background:var(--bg2);padding:2px 14px;transition:.2s}
.dp .dp-amt:focus-within{border-color:var(--acc);box-shadow:0 0 0 3px rgba(91,75,255,.14);background:var(--bg)}
.dp .dp-amt span{font-size:22px;font-weight:800;color:var(--mute);margin-right:6px}
.dp .dp-amt input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:var(--ink);font-size:26px;font-weight:800;padding:8px 0;width:100%;margin:0;box-shadow:none}
.dp .dp-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.dp .dp-chip{border:1px solid var(--line);background:var(--bg);color:var(--ink);padding:6px 12px;border-radius:999px;font-weight:700;font-size:12.5px;cursor:pointer;transition:.15s}
.dp .dp-chip.on{background:var(--acc);border-color:var(--acc);color:#fff}
.dp .dp-hint{font-size:12px;color:var(--mute);margin-top:6px;min-height:0}
.dp .dp-hint.bad{color:var(--bad);font-weight:600}

/* Methods: always two per row */
.dp .dp-methods{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.dp .dp-mth{position:relative;text-align:left;border:1.5px solid var(--line);border-radius:14px;background:var(--bg);padding:10px;cursor:pointer;display:flex;gap:9px;align-items:center;color:var(--ink);transition:.15s;min-width:0}
.dp .dp-mth.on{border-color:var(--c);background:color-mix(in srgb,var(--c) 6%,#fff);box-shadow:0 0 0 3px color-mix(in srgb,var(--c) 16%,transparent)}
.dp .dp-mth:disabled{opacity:.5;cursor:not-allowed}
.dp .dp-mth>span:nth-child(2){min-width:0;flex:1}
.dp .dp-mth b{display:block;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dp .dp-mth small{display:block;color:var(--mute);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dp .dp-mth .dp-tick{position:absolute;right:6px;top:6px;width:16px;height:16px;border-radius:50%;background:var(--c);color:#fff;display:none;place-items:center;font-size:10px;flex:none}
.dp .dp-mth.on .dp-tick{display:grid}
.dp .dp-mth .dp-tags{display:flex;gap:4px;margin-top:3px}
.dp .dp-tag{display:inline-block;font-size:10px;font-weight:800;padding:1px 6px;border-radius:999px}
.dp .dp-tag.fee{background:rgba(217,138,0,.14);color:var(--warn)}.dp .dp-tag.bonus{background:rgba(18,163,107,.14);color:var(--ok)}

/* Summary (inline) */
.dp .dp-summary{margin-top:16px;background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:4px 14px;animation:dpin .25s ease}
.dp .dp-sum{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;font-size:13px;border-bottom:1px dashed var(--line)}
.dp .dp-sum span{color:var(--mute)}.dp .dp-sum b{font-weight:700;text-align:right}
.dp .dp-sum.dp-tt{border-bottom:0;padding:10px 0;font-size:15px}
.dp .dp-sum.dp-tt span{color:var(--ink);font-weight:700}.dp .dp-sum.dp-tt b{font-size:17px;font-weight:800;color:var(--acc)}
.dp .dp-note{display:flex;gap:8px;font-size:11.5px;color:var(--mute);margin-top:12px;line-height:1.45}

/* Buttons */
.dp .dp-acts{display:flex;gap:8px;margin-top:16px}
.dp .dp-b{border:0;border-radius:12px;padding:13px 18px;font-size:14.5px;font-weight:800;cursor:pointer;transition:.15s}
.dp .dp-b.p{flex:1;color:#fff;background:linear-gradient(135deg,var(--acc),var(--acc2));box-shadow:0 6px 14px rgba(91,75,255,.3)}
.dp .dp-b.p:active:not(:disabled){transform:scale(.99)}
.dp .dp-b.p:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.dp .dp-b.g{background:var(--bg2);color:var(--ink);border:1px solid var(--line)}

/* Alerts */
.dp .dp-alert{border-radius:12px;padding:10px 12px;margin-bottom:14px;font-size:13px;font-weight:600;display:flex;gap:8px;line-height:1.45}
.dp .dp-alert.ok{background:rgba(18,163,107,.12);color:var(--ok);border:1px solid rgba(18,163,107,.3)}
.dp .dp-alert.er{background:rgba(229,72,77,.1);color:var(--bad);border:1px solid rgba(229,72,77,.3)}
.dp .dp-alert.inf{background:rgba(91,75,255,.07);color:var(--ink);border:1px solid rgba(91,75,255,.22);font-weight:500}

/* Step 2 */
.dp .dp-t2{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.dp .dp-pay{background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:2px 14px;margin-bottom:12px}
.dp .dp-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px dashed var(--line)}
.dp .dp-row:last-child{border:0}
.dp .dp-row span{color:var(--mute);font-size:11.5px;display:block}
.dp .dp-row b{font-size:15px;word-break:break-all}
.dp .dp-cp{border:0;background:var(--acc);color:#fff;border-radius:9px;padding:6px 11px;font-size:12px;font-weight:700;cursor:pointer;flex:none}
.dp .dp-cp.done{background:var(--ok)}
.dp ol.dp-how{list-style:none;counter-reset:s;margin:0 0 14px;padding:0}
.dp ol.dp-how li{counter-increment:s;position:relative;padding:5px 0 5px 30px;font-size:13px}
.dp ol.dp-how li:before{content:counter(s);position:absolute;left:0;top:4px;width:21px;height:21px;border-radius:50%;background:var(--bg2);border:1px solid var(--line);display:grid;place-items:center;font-size:11px;font-weight:800;color:var(--acc)}
.dp input.dp-t{width:100%;border:1.5px solid var(--line);border-radius:12px;background:var(--bg2);color:var(--ink);padding:12px 14px;font-size:16px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;outline:0;transition:.2s;margin:0}
.dp input.dp-t:focus{border-color:var(--acc);background:var(--bg);box-shadow:0 0 0 3px rgba(91,75,255,.14)}

/* History */
.dp .dp-hi{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--line)}
.dp .dp-hi:last-child{border:0;padding-bottom:0}
.dp .dp-hi .dp-mid{flex:1;min-width:0}.dp .dp-hi .dp-mid b{display:block;font-size:13.5px}
.dp .dp-hi .dp-mid small{color:var(--mute);font-size:11.5px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dp .dp-hi .dp-rt{text-align:right;flex:none}.dp .dp-hi .dp-rt b{display:block;font-size:13.5px}
.dp .dp-bd{display:inline-block;margin-top:3px;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800;text-transform:capitalize}
.dp .dp-bd.pending{background:rgba(217,138,0,.14);color:var(--warn)}.dp .dp-bd.approved{background:rgba(18,163,107,.14);color:var(--ok)}.dp .dp-bd.rejected{background:rgba(229,72,77,.12);color:var(--bad)}
.dp .dp-empty{text-align:center;color:var(--mute);padding:22px 0;font-size:13px;grid-column:1/-1}

@media(max-width:380px){
  .dp .dp-box{padding:14px}.dp .dp-bal{font-size:25px}
  .dp .dp-mth{padding:9px 8px;gap:7px}.dp .dp-stat{padding:7px 8px}
}
</style>

<div class="dp" id="dp">
  <div class="dp-hero">
    <small>AVAILABLE BALANCE</small>
    <div class="dp-bal"><?= money((float)($u['coins'] ?? 0)) ?></div>
    <div class="dp-stats">
      <div class="dp-stat"><b><?= money($tot['approved']) ?></b><span>Total added</span></div>
      <div class="dp-stat"><b><?= (int)$tot['pending'] ?></b><span>Awaiting review</span></div>
      <div class="dp-stat"><b><?= (int)$tot['n'] ?></b><span>Successful</span></div>
    </div>
  </div>

  <?php if (!$CFG['on']): ?>
  <div class="dp-box"><div class="dp-alert er">⏸ <?= e($CFG['off_msg'] !== '' ? $CFG['off_msg'] : 'Deposits are paused right now. Please check back soon.') ?></div>
    <p class="dp-lead">Your balance and deposit history are still available below.</p></div>
  <?php else: ?>
  <form method="post" class="dp-box" id="dpForm" autocomplete="off"><?= csrf_field() ?>
    <?php if ($err): ?><div class="dp-alert er">! <?= e($err) ?></div><?php endif; ?>
    <?php if ($CFG['notice'] !== ''): ?><div class="dp-alert inf">ℹ️ <span><?= e($CFG['notice']) ?></span></div><?php endif; ?>

    <div class="dp-steps">
      <div class="dp-stp on" data-s="1"><i>1</i><span>Amount &amp; method</span></div><div class="dp-bar"></div>
      <div class="dp-stp" data-s="2"><i>2</i><span>Pay &amp; confirm</span></div>
    </div>

    <!-- STEP 1: amount, method, summary -->
    <div class="dp-pane on" data-p="1">
      <label class="dp-l" for="dpAmt">Amount</label>
      <div class="dp-amt"><span>৳</span><input type="number" inputmode="decimal" id="dpAmt" name="amount" min="<?= e((string)$CFG['min']) ?>" max="<?= e((string)$CFG['max']) ?>" step="0.01" placeholder="0" value="<?= $err ? e($_POST['amount'] ?? '') : '' ?>"></div>
      <div class="dp-chips"><?php foreach ($CFG['chips'] as $c): ?><button type="button" class="dp-chip" data-v="<?= $c ?>">+<?= number_format($c) ?></button><?php endforeach; ?></div>
      <div class="dp-hint" id="dpAmtHint">Min <?= $nf($CFG['min']) ?> · Max <?= $nf($CFG['max']) ?></div>

      <div class="dp-sec">
        <label class="dp-l">Payment method</label>
        <input type="hidden" name="method" id="dpMethod" value="<?= $err ? e($_POST['method'] ?? '') : '' ?>">
        <div class="dp-methods">
        <?php foreach ($METHODS as $k => $m): ?>
          <button type="button" class="dp-mth" data-m="<?= e($k) ?>" style="--c:<?= e($m['color']) ?>" <?= $m['ok'] ? '' : 'disabled' ?>>
            <?= pm_logo_html($m, 36) ?>
            <span><b><?= e($m['name']) ?></b><small data-sub><?= $m['ok'] ? e($m['sub']) : 'Unavailable' ?></small>
              <?php if ($m['ok'] && ((float)$m['fee_pct'] > 0 || (float)$m['bonus_pct'] > 0)): ?><span class="dp-tags">
                <?php if ((float)$m['fee_pct'] > 0): ?><span class="dp-tag fee"><?= e((string)(float)$m['fee_pct']) ?>% fee</span><?php endif; ?>
                <?php if ((float)$m['bonus_pct'] > 0): ?><span class="dp-tag bonus">+<?= e((string)(float)$m['bonus_pct']) ?>%</span><?php endif; ?></span><?php endif; ?></span>
            <span class="dp-tick">✓</span>
          </button>
        <?php endforeach; if (!$METHODS): ?><div class="dp-empty">No payment method is available right now. Please check back soon.</div><?php endif; ?>
        </div>
      </div>

      <div class="dp-summary" id="dpSummary" hidden>
        <div class="dp-sum"><span>Amount</span><b id="sAmt">—</b></div>
        <div class="dp-sum"><span>Method</span><b id="sMth">—</b></div>
        <div class="dp-sum" id="sUsdRow" hidden><span>You pay</span><b id="sUsd"></b></div>
        <div class="dp-sum"><span>Fee</span><b id="sFee">Free</b></div>
        <div class="dp-sum" id="sBonusRow" hidden><span>Bonus</span><b id="sBonus" style="color:var(--ok)"></b></div>
        <div class="dp-sum dp-tt"><span>You receive</span><b id="sTot">—</b></div>
      </div>

      <div class="dp-acts"><button type="button" class="dp-b p" data-next="2" id="n1" disabled>Continue</button></div>
      <div class="dp-note"><span>🔒</span><span>Every request is checked by our team before your balance is credited. Most are approved within minutes.</span></div>
    </div>

    <!-- STEP 2: pay & confirm -->
    <div class="dp-pane" data-p="2">
      <div class="dp-t2"><span id="dpLogoBox"></span><div><h3 id="dpTitle">Send the payment</h3><p class="dp-lead" id="dpLead"></p></div></div>
      <div class="dp-alert inf" id="dpMNote" hidden></div>
      <div class="dp-pay" id="dpPay"></div>
      <ol class="dp-how" id="dpHow"></ol>
      <label class="dp-l" for="dpTrx" id="dpTrxL">Transaction ID</label>
      <input class="dp-t" type="text" id="dpTrx" name="trx" maxlength="40" placeholder="e.g. 9K4M2X7Q1A" autocapitalize="characters" spellcheck="false" value="<?= $err ? e($_POST['trx'] ?? '') : '' ?>">
      <div class="dp-hint">Letters and numbers only. Copy it from the confirmation message of your payment app.</div>
      <div class="dp-acts"><button type="button" class="dp-b g" data-next="1">Back</button><button class="dp-b p" id="dpSubmit" disabled>Submit deposit</button></div>
    </div>
  </form>
  <?php endif; ?>

  <div class="dp-box">
    <h3>Deposit history</h3>
    <p class="dp-lead" style="margin-bottom:6px">Your latest 30 requests</p>
    <?php foreach ($deps as $d): $mk = $methodOf((string)$d['note']); $mm = $mk ? ($METHODS[$mk] ?? $ALL[$mk]) : null;
      $parts = explode(' | ', (string)$d['note']); $ref = isset($parts[1]) ? preg_replace('/^[^:]+:\s*/', '', $parts[1]) : (string)$d['note']; ?>
    <div class="dp-hi">
      <?= pm_logo_html($mm ?? ['id' => '', 'name' => '', 'color' => '#7a7f99'], 34) ?>
      <div class="dp-mid"><b><?= e($mm['name'] ?? 'Deposit') ?> <span style="color:var(--mute);font-weight:500">#<?= (int)$d['id'] ?></span></b>
        <small><?= e($ref) ?> · <?= e(date('d M, h:i A', strtotime($d['created_at']))) ?></small></div>
      <div class="dp-rt"><b><?= money((float)$d['amount']) ?></b><span class="dp-bd <?= e($d['status']) ?>"><?= e($d['status']) ?></span></div>
    </div>
    <?php endforeach; if (!$deps): ?><div class="dp-empty">No deposits yet — your first one takes under a minute.</div><?php endif; ?>
  </div>
</div>

<script>
(function(){
  if (!document.getElementById('dpForm')) return;   // deposits are switched off
  var M = <?= json_encode($jsMethods, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      MIN = <?= json_encode((float)$CFG['min']) ?>, MAX = <?= json_encode((float)$CFG['max']) ?>, RATE = <?= json_encode($usdRate) ?>,
      $ = function(s){return document.querySelector(s)}, $$ = function(s){return [].slice.call(document.querySelectorAll(s))};
  var amt = $('#dpAmt'), meth = $('#dpMethod'), trx = $('#dpTrx'), cur = 1;
  function fmt(n){return '৳' + Number(n).toLocaleString('en-US',{maximumFractionDigits:2})}
  function a(){var v = parseFloat(amt.value); return isFinite(v) ? v : 0}
  function okAmt(){var v = a(); return v >= MIN && v <= MAX}
  function esc(s){var d = document.createElement('div'); d.textContent = s; return d.innerHTML.replace(/"/g, '&quot;')}
  function show(n, scroll){
    cur = n;
    $$('.dp-pane').forEach(function(p){p.classList.toggle('on', +p.dataset.p === n)});
    $$('.dp-stp').forEach(function(s){var i = +s.dataset.s; s.classList.toggle('on', i === n); s.classList.toggle('done', i < n); s.firstChild.textContent = i < n ? '✓' : i});
    if (n === 2) build();
    if (scroll) $('#dpForm').scrollIntoView({behavior:'smooth', block:'start'});
  }
  function sum(){
    var v = a();
    // methods whose min/max does not fit the amount are greyed out (and deselected)
    $$('.dp-mth').forEach(function(b){
      var mm = M[b.dataset.m]; if (!mm) return;
      var bad = mm.ok && v > 0 && (v < mm.min || v > mm.max), sm = b.querySelector('[data-sub]');
      b.disabled = !mm.ok || bad;
      if (sm) sm.textContent = !mm.ok ? 'Unavailable' : bad ? (v < mm.min ? 'Min ' + fmt(mm.min) : 'Max ' + fmt(mm.max)) : mm.sub;
      if (b.disabled && b.classList.contains('on')) { b.classList.remove('on'); meth.value = ''; }
    });
    var m = M[meth.value];
    var fee = m ? v * m.fee / 100 : 0, bonus = m ? v * m.bonus / 100 : 0, got = v ? v - fee + bonus : 0;
    $('#dpSummary').hidden = !(v > 0 && okAmt());
    $('#sAmt').textContent = v ? fmt(v) : '—';
    $('#sTot').textContent = v ? fmt(got) : '—';
    $('#sMth').textContent = m ? m.name : 'Not selected';
    $('#sFee').textContent = fee > 0 ? '− ' + fmt(fee) + ' (' + m.fee + '%)' : 'Free';
    $('#sBonusRow').hidden = !(bonus > 0);
    if (bonus > 0) $('#sBonus').textContent = '+ ' + fmt(bonus) + ' (' + m.bonus + '%)';
    var u = m && m.usd && RATE > 0 && v;
    $('#sUsdRow').hidden = !u;
    if (u) $('#sUsd').textContent = '$' + (v / RATE).toFixed(2);
    $('#n1').disabled = !(okAmt() && m);
    var h = $('#dpAmtHint'), msg = !amt.value ? '' : (v < MIN ? 'Minimum deposit is ' + fmt(MIN) : v > MAX ? 'Maximum deposit is ' + fmt(MAX) : '');
    h.textContent = msg || ('Min ' + fmt(MIN) + ' · Max ' + fmt(MAX)); h.classList.toggle('bad', !!msg);
    $$('.dp-chip').forEach(function(c){c.classList.toggle('on', +c.dataset.v === v)});
    $('#dpSubmit').disabled = !(okAmt() && m && /^[A-Za-z0-9]{6,40}$/.test(trx.value.replace(/\s+/g,'')));
  }
  function row(label, val, copy){
    return '<div class="dp-row"><div><span>' + esc(label) + '</span><b>' + esc(val) + '</b></div>' +
      (copy ? '<button type="button" class="dp-cp" data-c="' + esc(copy) + '">Copy</button>' : '') + '</div>';
  }
  // admin-written steps: {number} {amount} {ussd} {name} are filled in, **text** becomes bold
  function tpl(s, m, v, usd){
    var map = {number: m.acct, amount: m.usd ? '$' + usd : fmt(v), ussd: m.ussd, name: m.name};
    return esc(s).replace(/\{(number|amount|ussd|name)\}/g, function(_, k){ return '<b>' + esc(map[k]) + '</b>'; }).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
  }
  function build(){
    var m = M[meth.value], v = a(); if (!m) return;
    var usd = m.usd && RATE > 0 ? (v / RATE).toFixed(2) : '', pay = '', how = [];
    $('#dpLogoBox').innerHTML = m.logo;
    $('#dpTitle').textContent = 'Pay with ' + m.name;
    var n = $('#dpMNote'); n.textContent = m.note || ''; n.hidden = !m.note;
    if (m.usd) {
      $('#dpLead').textContent = 'Send exactly the amount below.';
      pay = row(m.label, m.acct, m.acct) + row('Amount to send', '$' + usd, usd);
      how = ['Open the ' + esc(m.name) + ' app → <b>Pay</b> → <b>Send</b>.', 'Enter the ID above and the exact USD amount.', 'Confirm the payment, then copy the <b>' + esc(m.tid) + '</b> from the receipt.', 'Paste it below and submit.'];
    } else {
      $('#dpLead').textContent = 'Pay from your ' + m.name + ' account.';
      pay = row(m.label, m.acct, m.acct) + row('Amount', fmt(v), String(v));
      how = [(m.ussd ? 'Dial <b>' + esc(m.ussd) + '</b> or open' : 'Open') + ' the ' + esc(m.name) + ' app.', 'Choose <b>Send Money</b>.', 'Enter the number and amount above, then confirm with your PIN.', 'Copy the <b>' + esc(m.tid) + '</b> from the confirmation message and paste it below.'];
    }
    if (m.steps && m.steps.length) how = m.steps.map(function(s){ return tpl(s, m, v, usd); });
    $('#dpTrxL').textContent = m.tid === 'TrxID' ? 'Transaction ID (TrxID)' : m.tid;
    $('#dpPay').innerHTML = pay;
    $('#dpHow').innerHTML = how.map(function(h){return '<li>' + h + '</li>'}).join('');
    sum();
  }
  document.addEventListener('click', function(e){
    var t = e.target.closest('button'); if (!t || !$('#dp').contains(t)) return;
    if (t.dataset.next) { show(+t.dataset.next, true); return; }
    if (t.classList.contains('dp-chip')) { amt.value = t.dataset.v; sum(); return; }
    if (t.dataset.m) {
      meth.value = t.dataset.m;
      $$('.dp-mth').forEach(function(b){b.classList.toggle('on', b === t)}); sum(); return;
    }
    if (t.dataset.c) {
      var txt = t.dataset.c, done = function(){t.textContent = 'Copied ✓'; t.classList.add('done'); setTimeout(function(){t.textContent = 'Copy'; t.classList.remove('done')}, 1400)};
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(txt).then(done, done);
      else { var x = document.createElement('textarea'); x.value = txt; document.body.appendChild(x); x.select(); try{document.execCommand('copy')}catch(_){} x.remove(); done(); }
    }
  });
  amt.addEventListener('input', sum); trx.addEventListener('input', sum);
  $('#dpForm').addEventListener('submit', function(e){ if ($('#dpSubmit').disabled) e.preventDefault(); });
  // restore state after a server-side error, otherwise start fresh
  if (meth.value && M[meth.value]) { var b = document.querySelector('.dp-mth[data-m="' + meth.value + '"]'); if (b) b.classList.add('on'); }
  sum();
  <?php if ($err): ?>if (okAmt() && M[meth.value]) show(2, false);<?php endif; ?>
})();
</script>
<?php user_end();
