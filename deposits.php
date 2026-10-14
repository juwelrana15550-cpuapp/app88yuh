<?php require __DIR__ . '/lib.php';
$u = require_login();

/* ============================== Settings ==============================
 * Put YOUR receiving accounts here (or set them in the settings table with the keys pay_bkash, pay_nagad,
 * pay_rocket, pay_binance - a saved setting wins over this list). A method whose value still contains "X"
 * is shown as "Unavailable" so users never send money to a placeholder. */
const MIN_DEPOSIT = 10;
const MAX_DEPOSIT = 100000;
const MAX_PENDING = 5;     // a user can have at most this many unreviewed requests
$METHODS = [
    'bkash'   => ['name' => 'bKash',   'sub' => 'Send Money · Personal', 'color' => '#e2136e', 'ussd' => '*247#', 'acct' => '01XXXXXXXXX', 'key' => 'pay_bkash',   'usd' => false, 'tid' => 'TrxID'],
    'nagad'   => ['name' => 'Nagad',   'sub' => 'Send Money · Personal', 'color' => '#f26a21', 'ussd' => '*167#', 'acct' => '01XXXXXXXXX', 'key' => 'pay_nagad',   'usd' => false, 'tid' => 'TrxID'],
    'rocket'  => ['name' => 'Rocket',  'sub' => 'Send Money · Personal', 'color' => '#8c3494', 'ussd' => '*322#', 'acct' => '01XXXXXXXXX', 'key' => 'pay_rocket',  'usd' => false, 'tid' => 'TrxID'],
    'binance' => ['name' => 'Binance', 'sub' => 'Binance Pay · USD',      'color' => '#e0a800', 'ussd' => '',      'acct' => 'XXXXXXXXX',   'key' => 'pay_binance', 'usd' => true,  'tid' => 'Order ID'],
];
$usdRate = function_exists('usd_rate') ? (float)usd_rate() : 0.0;   // BDT per 1 USD (Admin > Settings)
foreach ($METHODS as $k => &$m) {
    $v = function_exists('setting') ? trim((string)setting($m['key'], '')) : '';
    if ($v !== '') $m['acct'] = $v;
    $m['ok'] = stripos($m['acct'], 'X') === false && (!$m['usd'] || $usdRate > 0);
}
unset($m);

/* ============================== Submit ============================== */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amt = round((float)($_POST['amount'] ?? 0), 2);
    $mk  = (string)($_POST['method'] ?? '');
    $trx = strtoupper(preg_replace('/\s+/', '', (string)($_POST['trx'] ?? '')));
    if (!isset($METHODS[$mk]) || !$METHODS[$mk]['ok']) $err = 'Please choose a payment method.';
    elseif ($amt < MIN_DEPOSIT || $amt > MAX_DEPOSIT) $err = 'Amount must be between ' . MIN_DEPOSIT . ' and ' . number_format(MAX_DEPOSIT) . '.';
    elseif (!preg_match('/^[A-Z0-9]{6,40}$/', $trx)) $err = 'Enter a valid ' . $METHODS[$mk]['tid'] . ' (letters and numbers only, 6-40 characters).';
    else {
        $pdo = db();
        $st = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id = ? AND status = 'pending'"); $st->execute([$u['id']]);
        if ((int)$st->fetchColumn() >= MAX_PENDING) {
            $err = 'You already have ' . MAX_PENDING . ' requests waiting for review. Please wait until they are approved.';
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM deposits WHERE note LIKE ?'); $st->execute(['%TrxID: ' . $trx . ' |%']);
            if ((int)$st->fetchColumn() > 0) $err = 'This ' . $METHODS[$mk]['tid'] . ' was already submitted.';
            else {
                $paid = $METHODS[$mk]['usd'] ? '$' . number_format($amt / $usdRate, 2) : 'BDT ' . number_format($amt, 2);
                $note = $METHODS[$mk]['name'] . ' | TrxID: ' . $trx . ' | ' . $paid;   // shown to the admin as the deposit note
                $pdo->prepare('INSERT INTO deposits (user_id, amount, note) VALUES (?,?,?)')->execute([$u['id'], $amt, substr($note, 0, 255)]);
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
$methodOf = function (string $note) use ($METHODS) {
    foreach ($METHODS as $k => $m) if (strpos($note, $m['name'] . ' |') === 0) return $k;
    return null;
};
$jsMethods = [];
foreach ($METHODS as $k => $m) $jsMethods[$k] = ['name' => $m['name'], 'ok' => $m['ok'], 'acct' => $m['ok'] ? $m['acct'] : '', 'ussd' => $m['ussd'], 'usd' => $m['usd'], 'tid' => $m['tid'], 'color' => $m['color']];

user_start('Deposits', $u, 'deposits'); ?>
<style>
.dp{--bg:#fff;--bg2:#f5f6fb;--ink:#12142a;--mute:#6b7089;--line:#e6e8f2;--acc:#5b4bff;--acc2:#8b5cf6;--ok:#12a36b;--warn:#d98a00;--bad:#e5484d;--sh:0 1px 2px rgba(18,20,42,.05),0 8px 24px rgba(18,20,42,.06);
  color:var(--ink);max-width:1040px;margin:0 auto;font-family:inherit}
.dp *{box-sizing:border-box}
.dp .dp-hero{position:relative;overflow:hidden;border-radius:22px;padding:26px;color:#fff;background:linear-gradient(135deg,#3b2fd1 0%,#6d4bff 55%,#a35bff 100%);box-shadow:0 18px 40px rgba(91,75,255,.35)}
.dp .dp-hero:before,.dp .dp-hero:after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.12)}
.dp .dp-hero:before{width:260px;height:260px;right:-70px;top:-110px}
.dp .dp-hero:after{width:180px;height:180px;right:90px;bottom:-120px;background:rgba(255,255,255,.08)}
.dp .dp-hero>*{position:relative;z-index:1}
.dp .dp-hero small{opacity:.8;font-size:13px;letter-spacing:.04em}
.dp .dp-hero .dp-bal{font-size:clamp(30px,6vw,42px);font-weight:800;letter-spacing:-.02em;margin:4px 0 18px}
.dp .dp-stats{display:flex;gap:10px;flex-wrap:wrap}
.dp .dp-stat{background:rgba(255,255,255,.16);backdrop-filter:blur(6px);border-radius:14px;padding:10px 14px;min-width:120px}
.dp .dp-stat b{display:block;font-size:17px}.dp .dp-stat span{font-size:12px;opacity:.8}
.dp .dp-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;margin-top:20px;align-items:start}
@media(max-width:860px){.dp .dp-grid{grid-template-columns:1fr}}
.dp [hidden]{display:none!important}
.dp .dp-box{background:var(--bg);border:1px solid var(--line);border-radius:20px;box-shadow:var(--sh);padding:22px}
.dp h3{margin:0 0 4px;font-size:18px}.dp .dp-lead{color:var(--mute);font-size:13.5px;margin:0 0 16px}
.dp .dp-steps{display:flex;align-items:center;gap:8px;margin-bottom:22px}
.dp .dp-stp{display:flex;align-items:center;gap:8px;color:var(--mute);font-size:13px;font-weight:600;white-space:nowrap}
.dp .dp-stp i{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-style:normal;background:var(--bg2);border:1px solid var(--line);font-size:13px;transition:.25s}
.dp .dp-stp.on{color:var(--ink)}.dp .dp-stp.on i{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(91,75,255,.4)}
.dp .dp-stp.done i{background:var(--ok);color:#fff;border-color:transparent}
.dp .dp-bar{flex:1;height:2px;background:var(--line);border-radius:2px;min-width:12px}
@media(max-width:520px){.dp .dp-stp span{display:none}.dp .dp-stp.on span{display:inline}}
.dp .dp-pane{display:none;animation:dpin .3s ease}.dp .dp-pane.on{display:block}
@keyframes dpin{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.dp label.dp-l{display:block;font-size:12.5px;font-weight:700;color:var(--mute);margin:0 0 8px;text-transform:uppercase;letter-spacing:.05em}
.dp .dp-amt{display:flex;align-items:center;border:2px solid var(--line);border-radius:16px;background:var(--bg2);padding:4px 16px;transition:.2s}
.dp .dp-amt:focus-within{border-color:var(--acc);box-shadow:0 0 0 4px rgba(91,75,255,.15);background:var(--bg)}
.dp .dp-amt span{font-size:26px;font-weight:800;color:var(--mute);margin-right:8px}
.dp .dp-amt input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:var(--ink);font-size:32px;font-weight:800;padding:10px 0;width:100%;margin:0;box-shadow:none}
.dp .dp-chips{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 4px}
.dp .dp-chip{border:1px solid var(--line);background:var(--bg);color:var(--ink);padding:9px 16px;border-radius:999px;font-weight:700;font-size:14px;cursor:pointer;transition:.15s}
.dp .dp-chip:hover{border-color:var(--acc);transform:translateY(-1px)}.dp .dp-chip.on{background:var(--acc);border-color:var(--acc);color:#fff}
.dp .dp-hint{font-size:12.5px;color:var(--mute);margin-top:8px}
.dp .dp-methods{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
@media(max-width:420px){.dp .dp-methods{grid-template-columns:1fr}}
.dp .dp-mth{position:relative;text-align:left;border:2px solid var(--line);border-radius:16px;background:var(--bg);padding:14px;cursor:pointer;display:flex;gap:12px;align-items:center;color:var(--ink);transition:.18s;font:inherit}
.dp .dp-mth:hover:not(:disabled){transform:translateY(-2px);box-shadow:var(--sh)}
.dp .dp-mth.on{border-color:var(--c);box-shadow:0 0 0 4px color-mix(in srgb,var(--c) 18%,transparent)}
.dp .dp-mth:disabled{opacity:.5;cursor:not-allowed}
.dp .dp-mth .dp-lg{width:44px;height:44px;border-radius:13px;background:var(--c);color:#fff;display:grid;place-items:center;font-weight:800;font-size:19px;flex:none}
.dp .dp-mth b{display:block;font-size:15px}.dp .dp-mth small{color:var(--mute);font-size:12px}
.dp .dp-mth .dp-tick{position:absolute;right:10px;top:10px;width:20px;height:20px;border-radius:50%;background:var(--c);color:#fff;display:none;place-items:center;font-size:12px}
.dp .dp-mth.on .dp-tick{display:grid}
.dp .dp-pay{background:var(--bg2);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:16px}
.dp .dp-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 0;border-bottom:1px dashed var(--line)}
.dp .dp-row:last-child{border:0}.dp .dp-row span{color:var(--mute);font-size:13px}
.dp .dp-row b{font-size:16px;word-break:break-all}
.dp .dp-cp{border:0;background:var(--acc);color:#fff;border-radius:10px;padding:7px 12px;font-size:12.5px;font-weight:700;cursor:pointer;flex:none}
.dp .dp-cp.done{background:var(--ok)}
.dp ol.dp-how{list-style:none;counter-reset:s;margin:0 0 18px;padding:0}
.dp ol.dp-how li{counter-increment:s;position:relative;padding:8px 0 8px 38px;font-size:14px;color:var(--ink)}
.dp ol.dp-how li:before{content:counter(s);position:absolute;left:0;top:6px;width:26px;height:26px;border-radius:50%;background:var(--bg2);border:1px solid var(--line);display:grid;place-items:center;font-size:12px;font-weight:800;color:var(--acc)}
.dp input.dp-t{width:100%;border:2px solid var(--line);border-radius:14px;background:var(--bg2);color:var(--ink);padding:14px 16px;font-size:16px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;outline:0;transition:.2s;margin:0}
.dp input.dp-t:focus{border-color:var(--acc);background:var(--bg);box-shadow:0 0 0 4px rgba(91,75,255,.15)}
.dp .dp-acts{display:flex;gap:10px;margin-top:20px}
.dp .dp-b{border:0;border-radius:14px;padding:14px 20px;font-size:15px;font-weight:800;cursor:pointer;transition:.18s;font-family:inherit}
.dp .dp-b.p{flex:1;color:#fff;background:linear-gradient(135deg,var(--acc),var(--acc2));box-shadow:0 8px 20px rgba(91,75,255,.35)}
.dp .dp-b.p:hover:not(:disabled){transform:translateY(-1px)}.dp .dp-b.p:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.dp .dp-b.g{background:var(--bg2);color:var(--ink);border:1px solid var(--line)}
.dp .dp-alert{border-radius:14px;padding:13px 16px;margin-bottom:16px;font-size:14px;font-weight:600;display:flex;gap:10px}
.dp .dp-alert.ok{background:rgba(18,163,107,.12);color:var(--ok);border:1px solid rgba(18,163,107,.3)}
.dp .dp-alert.er{background:rgba(229,72,77,.1);color:var(--bad);border:1px solid rgba(229,72,77,.3)}
.dp .dp-side .dp-sum{display:flex;justify-content:space-between;padding:9px 0;font-size:14px}
.dp .dp-side .dp-sum span{color:var(--mute)}.dp .dp-side .dp-tt{border-top:1px solid var(--line);margin-top:6px;padding-top:14px;font-size:18px;font-weight:800}
.dp .dp-note{display:flex;gap:10px;font-size:12.5px;color:var(--mute);margin-top:14px;line-height:1.5}
.dp .dp-hist{margin-top:20px}
.dp .dp-hi{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--line)}
.dp .dp-hi:last-child{border:0}
.dp .dp-hi .dp-lg{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;font-weight:800;flex:none;background:var(--c,#7a7f99)}
.dp .dp-hi .dp-mid{flex:1;min-width:0}.dp .dp-hi .dp-mid b{display:block;font-size:15px}
.dp .dp-hi .dp-mid small{color:var(--mute);font-size:12.5px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dp .dp-hi .dp-rt{text-align:right}.dp .dp-hi .dp-rt b{display:block;font-size:15px}
.dp .dp-bd{display:inline-block;margin-top:4px;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:800;text-transform:capitalize}
.dp .dp-bd.pending{background:rgba(217,138,0,.14);color:var(--warn)}.dp .dp-bd.approved{background:rgba(18,163,107,.14);color:var(--ok)}.dp .dp-bd.rejected{background:rgba(229,72,77,.12);color:var(--bad)}
.dp .dp-empty{text-align:center;color:var(--mute);padding:34px 0;font-size:14px}
</style>

<div class="dp" id="dp">
  <div class="dp-hero">
    <small>AVAILABLE BALANCE</small>
    <div class="dp-bal"><?= money((float)($u['coins'] ?? 0)) ?></div>
    <div class="dp-stats">
      <div class="dp-stat"><b><?= money($tot['approved']) ?></b><span>Total added</span></div>
      <div class="dp-stat"><b><?= (int)$tot['pending'] ?></b><span>Awaiting review</span></div>
      <div class="dp-stat"><b><?= (int)$tot['n'] ?></b><span>Successful deposits</span></div>
    </div>
  </div>

  <div class="dp-grid">
    <form method="post" class="dp-box" id="dpForm" autocomplete="off"><?= csrf_field() ?>
      <?php if ($err): ?><div class="dp-alert er">! <?= e($err) ?></div><?php endif; ?>

      <div class="dp-steps">
        <div class="dp-stp on" data-s="1"><i>1</i><span>Amount</span></div><div class="dp-bar"></div>
        <div class="dp-stp" data-s="2"><i>2</i><span>Method</span></div><div class="dp-bar"></div>
        <div class="dp-stp" data-s="3"><i>3</i><span>Pay &amp; confirm</span></div>
      </div>

      <div class="dp-pane on" data-p="1">
        <h3>How much do you want to add?</h3>
        <p class="dp-lead">Minimum <?= (int)MIN_DEPOSIT ?> · Maximum <?= number_format(MAX_DEPOSIT) ?></p>
        <label class="dp-l" for="dpAmt">Amount</label>
        <div class="dp-amt"><span>৳</span><input type="number" inputmode="decimal" id="dpAmt" name="amount" min="<?= (int)MIN_DEPOSIT ?>" max="<?= (int)MAX_DEPOSIT ?>" step="0.01" placeholder="0" value="<?= $err ? e($_POST['amount'] ?? '') : '' ?>"></div>
        <div class="dp-chips"><?php foreach ([100, 200, 500, 1000, 2000, 5000] as $c): ?><button type="button" class="dp-chip" data-v="<?= $c ?>">+<?= number_format($c) ?></button><?php endforeach; ?></div>
        <div class="dp-hint" id="dpAmtHint"></div>
        <div class="dp-acts"><button type="button" class="dp-b p" data-next="2" id="n1" disabled>Continue</button></div>
      </div>

      <div class="dp-pane" data-p="2">
        <h3>Choose a payment method</h3>
        <p class="dp-lead">Pick the app you will pay from.</p>
        <input type="hidden" name="method" id="dpMethod" value="<?= $err ? e($_POST['method'] ?? '') : '' ?>">
        <div class="dp-methods">
        <?php foreach ($METHODS as $k => $m): ?>
          <button type="button" class="dp-mth" data-m="<?= e($k) ?>" style="--c:<?= e($m['color']) ?>" <?= $m['ok'] ? '' : 'disabled' ?>>
            <span class="dp-lg"><?= e(mb_substr($m['name'], 0, 1)) ?></span>
            <span><b><?= e($m['name']) ?></b><small><?= $m['ok'] ? e($m['sub']) : 'Unavailable right now' ?></small></span>
            <span class="dp-tick">✓</span>
          </button>
        <?php endforeach; ?>
        </div>
        <div class="dp-acts"><button type="button" class="dp-b g" data-next="1">Back</button><button type="button" class="dp-b p" data-next="3" id="n2" disabled>Continue</button></div>
      </div>

      <div class="dp-pane" data-p="3">
        <h3 id="dpTitle">Send the payment</h3>
        <p class="dp-lead" id="dpLead"></p>
        <div class="dp-pay" id="dpPay"></div>
        <ol class="dp-how" id="dpHow"></ol>
        <label class="dp-l" for="dpTrx" id="dpTrxL">Transaction ID</label>
        <input class="dp-t" type="text" id="dpTrx" name="trx" maxlength="40" placeholder="e.g. 9K4M2X7Q1A" autocapitalize="characters" spellcheck="false" value="<?= $err ? e($_POST['trx'] ?? '') : '' ?>">
        <div class="dp-hint">Letters and numbers only. Copy it from the confirmation message of your payment app.</div>
        <div class="dp-acts"><button type="button" class="dp-b g" data-next="2">Back</button><button class="dp-b p" id="dpSubmit" disabled>Submit deposit</button></div>
      </div>
    </form>

    <aside class="dp-side">
      <div class="dp-box">
        <h3>Summary</h3>
        <div class="dp-sum"><span>Amount</span><b id="sAmt">—</b></div>
        <div class="dp-sum"><span>Method</span><b id="sMth">—</b></div>
        <div class="dp-sum" id="sUsdRow" hidden><span>You pay</span><b id="sUsd"></b></div>
        <div class="dp-sum"><span>Fee</span><b>Free</b></div>
        <div class="dp-sum dp-tt"><span style="color:inherit">You receive</span><b id="sTot">—</b></div>
        <div class="dp-note"><span>🔒</span><span>Every request is checked by our team before your balance is credited. Most are approved within minutes.</span></div>
      </div>
    </aside>
  </div>

  <div class="dp-box dp-hist">
    <h3>Deposit history</h3>
    <p class="dp-lead">Your latest 30 requests</p>
    <?php foreach ($deps as $d): $mk = $methodOf((string)$d['note']); $mm = $mk ? $METHODS[$mk] : null;
      $parts = explode(' | ', (string)$d['note']); $ref = isset($parts[1]) ? preg_replace('/^[^:]+:\s*/', '', $parts[1]) : (string)$d['note']; ?>
    <div class="dp-hi">
      <span class="dp-lg" style="--c:<?= e($mm['color'] ?? '#7a7f99') ?>"><?= e($mm ? mb_substr($mm['name'], 0, 1) : '৳') ?></span>
      <div class="dp-mid"><b><?= e($mm['name'] ?? 'Deposit') ?> <span style="color:var(--mute);font-weight:500">#<?= (int)$d['id'] ?></span></b>
        <small><?= e($ref) ?> · <?= e(date('d M Y, h:i A', strtotime($d['created_at']))) ?></small></div>
      <div class="dp-rt"><b><?= money((float)$d['amount']) ?></b><span class="dp-bd <?= e($d['status']) ?>"><?= e($d['status']) ?></span></div>
    </div>
    <?php endforeach; if (!$deps): ?><div class="dp-empty">No deposits yet — your first one takes under a minute.</div><?php endif; ?>
  </div>
</div>

<script>
(function(){
  var M = <?= json_encode($jsMethods, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      MIN = <?= (int)MIN_DEPOSIT ?>, MAX = <?= (int)MAX_DEPOSIT ?>, RATE = <?= json_encode($usdRate) ?>,
      $ = function(s){return document.querySelector(s)}, $$ = function(s){return [].slice.call(document.querySelectorAll(s))};
  var amt = $('#dpAmt'), meth = $('#dpMethod'), trx = $('#dpTrx'), cur = 1;
  function fmt(n){return '৳' + Number(n).toLocaleString('en-US',{maximumFractionDigits:2})}
  function a(){var v = parseFloat(amt.value); return isFinite(v) ? v : 0}
  function okAmt(){var v = a(); return v >= MIN && v <= MAX}
  function esc(s){var d = document.createElement('div'); d.textContent = s; return d.innerHTML}
  function show(n){
    cur = n;
    $$('.dp-pane').forEach(function(p){p.classList.toggle('on', +p.dataset.p === n)});
    $$('.dp-stp').forEach(function(s){var i = +s.dataset.s; s.classList.toggle('on', i === n); s.classList.toggle('done', i < n); s.firstChild.textContent = i < n ? '✓' : i});
    if (n === 3) build();
  }
  function sum(){
    var v = a(), m = M[meth.value];
    $('#sAmt').textContent = v ? fmt(v) : '—';
    $('#sTot').textContent = v ? fmt(v) : '—';
    $('#sMth').textContent = m ? m.name : '—';
    var u = m && m.usd && RATE > 0 && v;
    $('#sUsdRow').hidden = !u;
    if (u) $('#sUsd').textContent = '$' + (v / RATE).toFixed(2);
    $('#n1').disabled = !okAmt(); $('#n2').disabled = !m;
    var h = $('#dpAmtHint');
    h.textContent = !amt.value ? '' : (v < MIN ? 'Minimum deposit is ' + fmt(MIN) : v > MAX ? 'Maximum deposit is ' + fmt(MAX) : '');
    $$('.dp-chip').forEach(function(c){c.classList.toggle('on', +c.dataset.v === v)});
    $('#dpSubmit').disabled = !(okAmt() && m && /^[A-Za-z0-9]{6,40}$/.test(trx.value.replace(/\s+/g,'')));
  }
  function row(label, val, copy){
    return '<div class="dp-row"><div><span>' + label + '</span><br><b>' + esc(val) + '</b></div>' +
      (copy ? '<button type="button" class="dp-cp" data-c="' + esc(copy) + '">Copy</button>' : '') + '</div>';
  }
  function build(){
    var m = M[meth.value], v = a(); if (!m) return;
    var pay = '', how = [];
    if (m.usd) {
      var usd = (v / RATE).toFixed(2);
      $('#dpTitle').textContent = 'Pay with Binance';
      $('#dpLead').textContent = 'Send exactly the amount below using Binance Pay.';
      pay = row('Binance Pay ID', m.acct, m.acct) + row('Amount to send', '$' + usd, usd);
      how = ['Open the Binance app → <b>Pay</b> → <b>Send</b>.', 'Enter the Pay ID above and the exact USD amount.', 'Confirm the payment, then copy the <b>Order ID</b> from the receipt.', 'Paste the Order ID below and submit.'];
      $('#dpTrxL').textContent = 'Order ID';
    } else {
      $('#dpTitle').textContent = 'Pay with ' + m.name;
      $('#dpLead').textContent = 'Use Send Money from your personal ' + m.name + ' account.';
      pay = row('Send money to', m.acct, m.acct) + row('Amount', fmt(v), String(v));
      how = ['Dial <b>' + m.ussd + '</b> or open the ' + m.name + ' app.', 'Choose <b>Send Money</b>.', 'Enter the number and amount above, then confirm with your PIN.', 'Copy the <b>TrxID</b> from the confirmation SMS and paste it below.'];
      $('#dpTrxL').textContent = 'Transaction ID (TrxID)';
    }
    $('#dpPay').innerHTML = pay;
    $('#dpHow').innerHTML = how.map(function(h){return '<li>' + h + '</li>'}).join('');
    sum();
  }
  document.addEventListener('click', function(e){
    var t = e.target.closest('button'); if (!t || !$('#dp').contains(t)) return;
    if (t.dataset.next) { show(+t.dataset.next); return; }
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
  <?php if ($err): ?>if (okAmt() && M[meth.value]) show(3);<?php endif; ?>
})();
</script>
<?php user_end();
