<?php require __DIR__ . '/lib.php';
require __DIR__ . '/payment_methods.php';
require __DIR__ . '/binance_pay.php';   // Binance Pay + BSC USDT automatic verification
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
$AJAX = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';   // the deposit page sends automatic checks in the background and shows a progress screen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = null;   // ['type' => success|pending|error, ...]  what the user is told
    $bad = fn(string $title, string $msg) => ['type' => 'error', 'title' => $title, 'msg' => $msg];
    $amt  = round((float)($_POST['amount'] ?? 0), 2);
    $mk   = (string)($_POST['method'] ?? '');
    $kind = isset($METHODS[$mk]) ? bn_kind($mk) : null;              // 'pay' | 'bsc' | null (manual method)
    $raw  = preg_replace('/\s+/', '', (string)($_POST['trx'] ?? ''));
    $trx  = $kind === 'bsc' ? strtolower($raw) : strtoupper($raw);   // a BSC hash is 0x + 64 hex characters
    $validTrx = $kind === 'bsc' ? (bool)preg_match('/^0x[a-f0-9]{64}$/', $trx) : (bool)preg_match('/^[A-Z0-9]{6,40}$/', $trx);
    if (!$CFG['on']) $res = $bad('Deposits paused', 'Deposits are paused right now. Please try again later.');
    elseif (!isset($METHODS[$mk]) || !$METHODS[$mk]['ok']) $res = $bad('Choose a method', 'Please choose a payment method.');
    elseif ($amt < $METHODS[$mk]['lmin'] || $amt > $METHODS[$mk]['lmax']) $res = $bad('Check the amount', 'Amount for ' . $METHODS[$mk]['name'] . ' must be between ' . $nf($METHODS[$mk]['lmin']) . ' and ' . $nf($METHODS[$mk]['lmax']) . '.');
    elseif (!$validTrx) $res = $bad('Check the ID', $kind === 'bsc' ? 'Enter the full transaction hash (starts with 0x, 66 characters).' : 'Enter a valid ' . $METHODS[$mk]['tid_label'] . ' (letters and numbers only, 6-40 characters).');
    else {
        $M = $METHODS[$mk];
        $pdo = db();
        $auto = $kind !== null && bn_ready($kind) && $usdRate > 0;   // automatic check possible? otherwise the normal manual review below
        if ($kind !== null && !$auto) bn_note_error($usdRate <= 0 ? 'Auto-verify skipped: dollar rate is not set (Admin > Settings).' : 'Auto-verify skipped: Binance API key / secret are not saved (Admin > Payment methods).');
        $st = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id = ? AND status = 'pending'"); $st->execute([$u['id']]);
        $st2 = $pdo->prepare('SELECT COUNT(*) FROM deposits WHERE note LIKE ?'); $st2->execute(['%TrxID: ' . $trx . ' |%']);
        $tries = array_values(array_filter($_SESSION['bn_try'] ?? [], fn($t) => $t > time() - 600));   // max 10 automatic checks / 10 min / session
        if ((int)$st2->fetchColumn() > 0) $res = $bad('Already submitted', 'This ' . $M['tid_label'] . ' was already submitted.');
        elseif (!$auto && (int)$st->fetchColumn() >= $CFG['pending']) $res = $bad('Too many pending', 'You already have ' . $CFG['pending'] . ' requests waiting for review. Please wait until they are approved.');
        elseif ($auto && count($tries) >= 10) $res = $bad('Too many attempts', 'Please wait a few minutes and try again.');
        else {
            $credit  = pm_credit($M, $amt);   // amount minus the method fee, plus the method bonus
            $usdAmt  = number_format(round($amt / max($usdRate, 0.0001), 2), 2, '.', '');
            $paid    = ($M['is_usd'] || $kind) && $usdRate > 0 ? '$' . number_format((float)$usdAmt, 2) : 'BDT ' . number_format($amt, 2);
            $note    = $M['name'] . ' | TrxID: ' . $trx . ' | ' . $paid;   // shown to the admin as the deposit note
            if ((float)$M['fee_pct'] > 0)   $note .= ' | Fee ' . (float)$M['fee_pct'] . '%';
            if ((float)$M['bonus_pct'] > 0) $note .= ' | Bonus ' . (float)$M['bonus_pct'] . '%';
            $manual = !$auto;   // true = create a pending request for the admin
            $autoDown = false;

            if ($auto) {
                $tries[] = time(); $_SESSION['bn_try'] = $tries;
                try {
                    $r = $kind === 'bsc' ? bn_verify_bsc($trx, (string)$M['acct'], $usdAmt) : bn_verify_pay($trx, $usdAmt);
                } catch (Throwable $ex) {
                    error_log('[binance-auto] ' . $kind . ': ' . $ex->getMessage());   // the API secret is never part of this message
                    bn_note_error(strtoupper($kind) . ': ' . $ex->getMessage());
                    $r = ['state' => 'ERROR'];
                }
                switch ($r['state']) {
                    case 'SUCCESS':
                        if (bn_credit($pdo, (int)$u['id'], $kind, $trx, (float)$credit, $note, (float)(getenv('REFERRAL_BONUS') ?: 10))) {
                            $q = $pdo->prepare('SELECT coins FROM users WHERE id = ?'); $q->execute([$u['id']]);
                            $res = ['type' => 'success', 'amt' => (float)$credit, 'amt_html' => money((float)$credit), 'bal_html' => money((float)$q->fetchColumn()),
                                    'method' => $M['name'], 'trx' => $trx, 'paid' => $paid, 'at' => time(), 'at_txt' => date('d M Y, h:i A')];
                        } else $res = $bad('Already used', 'This ' . $M['tid_label'] . ' was already used.');
                        break;
                    case 'NOT_FOUND':   $res = $bad('Payment not found', 'We could not find this payment yet. Check the ID and try again in a minute.'); break;
                    case 'CONFIRMING':  $res = $bad('Waiting for confirmation', 'Payment found, but it is still being confirmed on the network. Please try again in a minute.'); break;
                    case 'WRONG_AMOUNT':$res = $bad('Amount does not match', 'You must send exactly $' . $usdAmt . ' (USDT) for this deposit.'); break;
                    case 'WRONG_TOKEN': $res = $bad('Wrong token', $kind === 'bsc' ? 'No USDT (BEP-20) transfer to our address was found in this transaction.' : 'This payment was not made in USDT.'); break;
                    case 'FAILED':      $res = $bad('Transaction failed', 'This transaction failed or was not completed.'); break;
                    default:            $manual = true; $autoDown = true; $note .= ' | auto-check unavailable';   // temporary error: never reject a payment because of it
                }
            }
            if ($res === null && $manual) {
                $pdo->prepare('INSERT INTO deposits (user_id, amount, note) VALUES (?,?,?)')->execute([$u['id'], $credit, substr($note, 0, 255)]);
                $res = ['type' => 'pending', 'auto_down' => $autoDown, 'method' => $M['name'], 'trx' => $trx, 'paid' => $paid, 'amt_html' => money((float)$credit), 'at_txt' => date('d M Y, h:i A'),
                        'msg' => $autoDown ? 'Automatic verification is unavailable right now. Your request is saved and our team will confirm it shortly.' : 'Your request was received. Your balance is credited as soon as we confirm the payment.'];
            }
        }
    }
    if ($AJAX) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); exit; }
    if ($res['type'] === 'success') { $_SESSION['dep_success'] = ['amt' => $res['amt'], 'method' => $res['method'], 'trx' => $res['trx'], 'paid' => $res['paid'], 'at' => $res['at']]; header('Location: /deposits.php'); exit; }
    if ($res['type'] === 'pending') { flash('ok', $res['msg']); header('Location: /deposits.php'); exit; }
    $err = $res['msg'];
}

$ok = $_SESSION['dep_success'] ?? null; unset($_SESSION['dep_success']);   // success popup data (set after an automatic credit)
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
    'name' => $m['name'], 'ok' => $m['ok'], 'acct' => $m['ok'] ? $m['acct'] : '', 'ussd' => $m['ussd'], 'usd' => (bool)$m['is_usd'] || bn_kind((string)$k) !== null, 'auto' => bn_kind((string)$k) ?? '',
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

/* History toggle */
.dp details.dp-box>summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:8px;-webkit-tap-highlight-color:transparent}
.dp details.dp-box>summary::-webkit-details-marker{display:none}
.dp details.dp-box>summary:after{content:"▾";color:var(--mute);font-size:16px;transition:.2s}
.dp details.dp-box[open]>summary:after{transform:rotate(180deg)}
.dp details.dp-box[open]>summary{margin-bottom:6px}
.dp .dp-sum.dp-x{display:flex}

/* ===== Mobile: compact so the whole deposit flow fits one screen ===== */
@media(max-width:600px){
  .dp{font-size:13px;max-width:none}
  .dp .dp-hero{border-radius:14px;padding:10px 12px;box-shadow:0 6px 16px rgba(91,75,255,.22)}
  .dp .dp-hero:before{width:120px;height:120px;right:-40px;top:-60px}
  .dp .dp-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
  .dp .dp-hero small{font-size:9.5px}
  .dp .dp-bal{font-size:22px;margin:0}
  .dp .dp-stats{gap:6px}
  .dp .dp-stat{padding:5px 8px;border-radius:9px}
  .dp .dp-stat b{font-size:12.5px}
  .dp .dp-stat span{font-size:9.5px}
  .dp .dp-box{padding:10px 12px;margin-top:8px;border-radius:14px}
  .dp .dp-steps{margin-bottom:8px;gap:6px}
  .dp .dp-stp{font-size:11px;gap:5px}
  .dp .dp-stp i{width:18px;height:18px;font-size:10px}
  .dp .dp-sec{margin-top:8px}
  .dp label.dp-l{font-size:10px;margin-bottom:4px}
  .dp .dp-amt{padding:0 10px;border-radius:11px}
  .dp .dp-amt span{font-size:18px}
  .dp .dp-amt input{font-size:20px;padding:6px 0}
  .dp .dp-chips{flex-wrap:nowrap;overflow-x:auto;gap:5px;margin-top:6px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
  .dp .dp-chips::-webkit-scrollbar{display:none}
  .dp .dp-chip{padding:4px 10px;font-size:12px;flex:none}
  .dp .dp-hint{font-size:11px;margin-top:4px}
  .dp .dp-methods{gap:6px}
  .dp .dp-mth{padding:7px 8px;gap:7px;border-radius:11px}
  .dp .dp-mth b{font-size:12.5px}
  .dp .dp-mth small{font-size:10px}
  .dp .dp-mth .dp-tags{margin-top:1px}
  .dp .dp-tag{font-size:9px;padding:0 5px}
  .dp .dp-summary{margin-top:8px;padding:2px 12px;border-radius:11px}
  .dp .dp-sum{padding:4px 0;font-size:12px}
  .dp .dp-sum:not(.dp-tt){display:none}   /* fee/bonus already shown as tags on the method cards */
  .dp .dp-sum.dp-tt{padding:5px 0;font-size:13px}
  .dp .dp-sum.dp-tt b{font-size:15px}
  .dp .dp-acts{margin-top:8px;gap:6px}
  .dp .dp-b{padding:10px 14px;font-size:14px;border-radius:10px}
  .dp .dp-note{display:none}
  .dp .dp-alert{padding:6px 10px;margin-bottom:8px;font-size:12px;border-radius:10px}
  .dp h3{font-size:14px}
  .dp .dp-lead{font-size:11.5px;margin:0}
  /* step 2: hide balance card + history, tighten everything */
  .dp.s2 .dp-hero,.dp.s2 .dp-hist{display:none}
  .dp .dp-t2{margin-bottom:6px;gap:8px}
  .dp .dp-pay{padding:0 12px;margin-bottom:8px;border-radius:11px}
  .dp .dp-row{padding:6px 0}
  .dp .dp-row span{font-size:10.5px}
  .dp .dp-row b{font-size:14px}
  .dp .dp-cp{padding:4px 10px;font-size:11.5px}
  .dp ol.dp-how{margin-bottom:8px}
  .dp ol.dp-how li{padding:2px 0 2px 26px;font-size:12px;line-height:1.3}
  .dp ol.dp-how li:before{width:18px;height:18px;font-size:10px;top:2px}
  .dp input.dp-t{padding:9px 12px;font-size:15px;border-radius:10px}
  .dp .dp-hi{padding:7px 0}
}
/* short phones: squeeze a bit more */
@media(max-width:600px) and (max-height:700px){
  .dp .dp-hero{padding:8px 10px}
  .dp .dp-bal{font-size:20px}
  .dp .dp-top{margin-bottom:6px}
  .dp .dp-stat{padding:4px 7px}
  .dp .dp-box{padding:8px 10px;margin-top:6px}
  .dp .dp-steps{margin-bottom:6px}
  .dp .dp-amt input{padding:4px 0}
  .dp .dp-mth{padding:5px 8px}
  .dp .dp-b{padding:8px 12px}
}

/* USDT preview under the amount */
.dp .dp-usd{display:flex;align-items:center;gap:8px;margin-top:8px;padding:8px 12px;border-radius:12px;background:rgba(38,161,123,.1);border:1px solid rgba(38,161,123,.22);color:#0f7a5a;font-weight:600;font-size:13px;animation:dpin .2s ease}
.dp .dp-usd svg{width:20px;height:20px;flex:none}
.dp .dp-usd b{font-weight:800;font-size:14px}
.dp .dp-usd small{margin-left:auto;color:#5f8f80;font-size:11px;font-weight:600;white-space:nowrap}
/* Success popup */
.dp-ov{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(18,20,42,.55);backdrop-filter:blur(3px);animation:dpfade .25s ease}
.dp-ov[hidden]{display:none}
.dp-ok{width:100%;max-width:380px;background:#fff;color:#12142a;border-radius:22px;padding:26px 22px 20px;text-align:center;box-shadow:0 24px 60px rgba(18,20,42,.35);animation:dppop .45s cubic-bezier(.2,1.3,.4,1);font-family:inherit}
.dp-ok .chk{width:76px;height:76px;margin:0 auto 14px;border-radius:50%;background:#12a36b;display:grid;place-items:center;box-shadow:0 0 0 10px rgba(18,163,107,.14);animation:dpring 1.6s ease-out .3s 2}
.dp-ok .chk svg{width:40px;height:40px;stroke:#fff;stroke-width:4;fill:none;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:48;stroke-dashoffset:48;animation:dpdraw .5s ease .35s forwards}
.dp-ok h2{margin:0;font-size:20px;font-weight:800}
.dp-ok .sub{margin:4px 0 14px;color:#6b7089;font-size:13px}
.dp-ok .big{font-size:32px;font-weight:800;color:#12a36b;letter-spacing:-.02em;margin-bottom:14px}
.dp-ok .rows{background:#f5f6fb;border:1px solid #e6e8f2;border-radius:14px;padding:2px 14px;text-align:left}
.dp-ok .rows div{display:flex;justify-content:space-between;gap:12px;padding:8px 0;font-size:13px;border-bottom:1px dashed #e6e8f2}
.dp-ok .rows div:last-child{border:0}
.dp-ok .rows span{color:#6b7089;flex:none}.dp-ok .rows b{text-align:right;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dp-ok button{margin-top:16px;width:100%;border:0;border-radius:12px;padding:13px;font-size:15px;font-weight:800;color:#fff;cursor:pointer;background:linear-gradient(135deg,#12a36b,#0e8a5a);box-shadow:0 6px 14px rgba(18,163,107,.3)}
/* Processing / result states */
.dp-ok .chk.warn{background:#d98a00;box-shadow:0 0 0 10px rgba(217,138,0,.14);animation:none}
.dp-ok .chk.bad{background:#e5484d;box-shadow:0 0 0 10px rgba(229,72,77,.12);animation:none}
.dp-ok .chk.warn svg,.dp-ok .chk.bad svg{stroke-dasharray:none;stroke-dashoffset:0;animation:dppop .4s ease}
.dp-ok .sub b{color:#12142a}
.dp-ok .badge2{display:inline-block;margin:0 0 12px;padding:3px 12px;border-radius:999px;font-size:12px;font-weight:800;background:rgba(217,138,0,.14);color:#b36f00}
.dp-ok button.ghost{background:#f5f6fb;color:#12142a;border:1px solid #e6e8f2;box-shadow:none}
.dp-ok button.pri{background:linear-gradient(135deg,#5b4bff,#8b5cf6);box-shadow:0 6px 14px rgba(91,75,255,.3)}
.spin-wrap{position:relative;width:84px;height:84px;margin:0 auto 14px}
.spin{position:absolute;inset:0;border-radius:50%;border:5px solid rgba(91,75,255,.14);border-top-color:#5b4bff;animation:dpspin 1s linear infinite}
.spin-ic{position:absolute;inset:0;display:grid;place-items:center}
.spin-ic svg{width:34px;height:34px;stroke:#5b4bff;stroke-width:2;fill:none;stroke-linecap:round;stroke-linejoin:round;animation:dppulse 1.4s ease-in-out infinite}
.stp{list-style:none;margin:10px 0 2px;padding:10px 14px;background:#f5f6fb;border:1px solid #e6e8f2;border-radius:14px;text-align:left}
.stp li{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13.5px;color:#9aa0b8;transition:color .2s}
.stp li i{width:20px;height:20px;border-radius:50%;border:2px solid #d9dcea;flex:none;box-sizing:border-box;position:relative}
.stp li.on{color:#12142a;font-weight:700}
.stp li.on i{border-color:#5b4bff;border-top-color:transparent;animation:dpspin .8s linear infinite}
.stp li.done{color:#12a36b}
.stp li.done i{background:#12a36b;border-color:#12a36b}
.stp li.done i:after{content:"";position:absolute;left:5px;top:1px;width:4px;height:9px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
.dp-ok .lock{margin-top:12px;font-size:11.5px;color:#9aa0b8}
@keyframes dpspin{to{transform:rotate(360deg)}}
@keyframes dppulse{0%,100%{opacity:.55}50%{opacity:1}}
/* Animated halo around the result icons */
.dp-ok .chk{--c:#12a36b;position:relative}
.dp-ok .chk.warn{--c:#d98a00}
.dp-ok .chk.bad{--c:#e5484d}
.dp-ok .chk,.dp-ok .chk.warn,.dp-ok .chk.bad{animation:dpglow 2.4s ease-in-out infinite}
.dp-ok .chk:before,.dp-ok .chk:after{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid var(--c);opacity:0;pointer-events:none;animation:dpripple 2.4s ease-out infinite}
.dp-ok .chk:after{animation-delay:1.2s}
@keyframes dpglow{0%,100%{box-shadow:0 0 0 8px color-mix(in srgb,var(--c) 18%,transparent),0 0 18px color-mix(in srgb,var(--c) 25%,transparent)}50%{box-shadow:0 0 0 14px color-mix(in srgb,var(--c) 8%,transparent),0 0 30px color-mix(in srgb,var(--c) 38%,transparent)}}
@keyframes dpripple{0%{transform:scale(1);opacity:.5}100%{transform:scale(1.85);opacity:0}}
@media (prefers-reduced-motion:reduce){.dp-ok .chk,.dp-ok .chk:before,.dp-ok .chk:after{animation:none}}
@keyframes dpfade{from{opacity:0}to{opacity:1}}
@keyframes dppop{from{opacity:0;transform:scale(.8) translateY(14px)}to{opacity:1;transform:none}}
@keyframes dpdraw{to{stroke-dashoffset:0}}
@keyframes dpring{0%{box-shadow:0 0 0 0 rgba(18,163,107,.45)}100%{box-shadow:0 0 0 22px rgba(18,163,107,0)}}
</style>

<?php if ($ok): ?>
<div class="dp-ov" id="dpOk" role="dialog" aria-modal="true" aria-labelledby="dpOkT">
  <div class="dp-ok">
    <div class="chk"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
    <h2 id="dpOkT">Payment Successful</h2>
    <p class="sub">Your balance was added successfully.</p>
    <div class="big">+ <?= money($ok['amt']) ?></div>
    <div class="rows">
      <div><span>Method</span><b><?= e($ok['method']) ?></b></div>
      <div><span>You paid</span><b><?= e($ok['paid']) ?></b></div>
      <div><span>Transaction</span><b title="<?= e($ok['trx']) ?>"><?= e(strlen($ok['trx']) > 18 ? substr($ok['trx'], 0, 8) . '…' . substr($ok['trx'], -6) : $ok['trx']) ?></b></div>
      <div><span>New balance</span><b><?= money((float)($u['coins'] ?? 0)) ?></b></div>
      <div><span>Time</span><b><?= e(date('d M Y, h:i A', $ok['at'])) ?></b></div>
    </div>
    <button type="button" id="dpOkBtn">Done</button>
  </div>
</div>
<script>(function(){var o=document.getElementById('dpOk');function c(){o.hidden=true}document.getElementById('dpOkBtn').onclick=c;o.addEventListener('click',function(e){if(e.target===o)c()});document.addEventListener('keydown',function(e){if(e.key==='Escape')c()});})();</script>
<?php endif; ?>


<div class="dp-ov" id="dpRes" hidden role="dialog" aria-modal="true"><div class="dp-ok" id="dpResBox"></div></div>

<div class="dp" id="dp">
  <div class="dp-hero">
    <div class="dp-top"><small>AVAILABLE BALANCE</small>
    <div class="dp-bal"><?= money((float)($u['coins'] ?? 0)) ?></div></div>
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
      <div class="dp-usd" id="dpUsd" hidden></div>
      <div class="dp-chips"><?php foreach ($CFG['chips'] as $c): ?><button type="button" class="dp-chip" data-v="<?= $c ?>">+<?= number_format($c) ?></button><?php endforeach; ?></div>
      <div class="dp-hint" id="dpAmtHint">Min <?= $nf($CFG['min']) ?> · Max <?= $nf($CFG['max']) ?></div>

      <div class="dp-sec">
        <label class="dp-l">Payment method</label>
        <input type="hidden" name="method" id="dpMethod" value="<?= $err ? e($_POST['method'] ?? '') : '' ?>">
        <div class="dp-methods">
        <?php foreach ($METHODS as $k => $m): ?>
          <button type="button" class="dp-mth" data-m="<?= e($k) ?>" style="--c:<?= e($m['color']) ?>" <?= $m['ok'] ? '' : 'disabled' ?>>
            <?= pm_logo_html($m, 30) ?>
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
        <div class="dp-sum dp-x"><span>Amount</span><b id="sAmt">—</b></div>
        <div class="dp-sum dp-x"><span>Method</span><b id="sMth">—</b></div>
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
      <div class="dp-hint" id="dpTrxHint">Letters and numbers only. Copy it from the confirmation message of your payment app.</div>
      <div class="dp-acts"><button type="button" class="dp-b g" data-next="1">Back</button><button class="dp-b p" id="dpSubmit" disabled>Submit deposit</button></div>
    </div>
  </form>
  <?php endif; ?>

  <details class="dp-box dp-hist" id="dpHist" open>
    <summary><div><h3>Deposit history</h3>
    <p class="dp-lead">Your latest 30 requests</p></div></summary>
    <?php foreach ($deps as $d): $mk = $methodOf((string)$d['note']); $mm = $mk ? ($METHODS[$mk] ?? $ALL[$mk]) : null;
      $parts = explode(' | ', (string)$d['note']); $ref = isset($parts[1]) ? preg_replace('/^[^:]+:\s*/', '', $parts[1]) : (string)$d['note']; ?>
    <div class="dp-hi">
      <?= pm_logo_html($mm ?? ['id' => '', 'name' => '', 'color' => '#7a7f99'], 34) ?>
      <div class="dp-mid"><b><?= e($mm['name'] ?? 'Deposit') ?> <span style="color:var(--mute);font-weight:500">#<?= (int)$d['id'] ?></span></b>
        <small><?= e($ref) ?> · <?= e(date('d M, h:i A', strtotime($d['created_at']))) ?></small></div>
      <div class="dp-rt"><b><?= money((float)$d['amount']) ?></b><span class="dp-bd <?= e($d['status']) ?>"><?= e($d['status']) ?></span></div>
    </div>
    <?php endforeach; if (!$deps): ?><div class="dp-empty">No deposits yet — your first one takes under a minute.</div><?php endif; ?>
  </details>
</div>

<script>
(function(){
  if (!document.getElementById('dpForm')) return;   // deposits are switched off
  var M = <?= json_encode($jsMethods, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      MIN = <?= json_encode((float)$CFG['min']) ?>, MAX = <?= json_encode((float)$CFG['max']) ?>, RATE = <?= json_encode($usdRate) ?>,
      $ = function(s){return document.querySelector(s)}, $$ = function(s){return [].slice.call(document.querySelectorAll(s))};
  var amt = $('#dpAmt'), meth = $('#dpMethod'), trx = $('#dpTrx'), cur = 1;
  var USDT_IC = '<svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="16" fill="#26a17b"/><path fill="#fff" d="M17.9 17.4v-.01c-.1 0-.6.04-1.9.04-1.1 0-1.7-.03-1.9-.04v.01c-3.4-.15-5.9-.7-5.9-1.4s2.5-1.25 5.9-1.4v2.2c.2.01.8.05 1.9.05 1.3 0 1.8-.04 1.9-.05v-2.2c3.4.15 5.9.7 5.9 1.4s-2.5 1.25-5.9 1.4zm0-3v-2h4.7V9.3H9.4v3.1h4.7v2c-3.8.17-6.7.9-6.7 1.8s2.9 1.65 6.7 1.8v6.4h3.8v-6.4c3.8-.17 6.7-.9 6.7-1.8s-2.9-1.63-6.7-1.8z"/></svg>';
  function fmt(n){return '৳' + Number(n).toLocaleString('en-US',{maximumFractionDigits:2})}
  function a(){var v = parseFloat(amt.value); return isFinite(v) ? v : 0}
  function okAmt(){var v = a(); return v >= MIN && v <= MAX}
  function esc(s){var d = document.createElement('div'); d.textContent = s; return d.innerHTML.replace(/"/g, '&quot;')}
  function show(n, scroll){
    cur = n;
    $('#dp').classList.toggle('s2', n === 2);
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
    var ub = $('#dpUsd');
    if (RATE > 0 && v > 0) { ub.hidden = false; ub.innerHTML = USDT_IC + '<span>You pay about <b>$' + (v / RATE).toFixed(2) + ' USDT</b></span><small>' + fmt(RATE) + ' = $1</small>'; } else ub.hidden = true;
    $('#n1').disabled = !(okAmt() && m);
    var h = $('#dpAmtHint'), msg = !amt.value ? '' : (v < MIN ? 'Minimum deposit is ' + fmt(MIN) : v > MAX ? 'Maximum deposit is ' + fmt(MAX) : '');
    h.textContent = msg || ('Min ' + fmt(MIN) + ' · Max ' + fmt(MAX)); h.classList.toggle('bad', !!msg);
    $$('.dp-chip').forEach(function(c){c.classList.toggle('on', +c.dataset.v === v)});
    $('#dpSubmit').disabled = !(okAmt() && m && trxOk());
  }
  function trxOk(){
    var m = M[meth.value], t = trx.value.replace(/\s+/g,'');
    return m && m.auto === 'bsc' ? /^0x[A-Fa-f0-9]{64}$/.test(t) : /^[A-Za-z0-9]{6,40}$/.test(t);
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
    var bsc = m.auto === 'bsc';
    trx.maxLength = bsc ? 66 : 40; trx.style.textTransform = bsc ? 'none' : '';
    trx.placeholder = bsc ? '0x…  (66 characters)' : 'e.g. 9K4M2X7Q1A';
    $('#dpTrxHint').textContent = bsc ? 'Paste the full transaction hash (TxID) from your wallet or BscScan.' : m.auto === 'pay' ? 'Paste the Binance Pay Order / Transaction ID. Your balance is added automatically.' : 'Letters and numbers only. Copy it from the confirmation message of your payment app.';
    $('#dpSubmit').textContent = m.auto ? 'Verify & add balance' : 'Submit deposit';
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
  /* ---- automatic verification: progress screen + result screen ---- */
  var RES = $('#dpRes'), BOX = $('#dpResBox');
  var IC = {
    ok: '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
    clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    x: '<svg viewBox="0 0 24 24"><path d="M7.5 7.5l9 9M16.5 7.5l-9 9"/></svg>',
    shield: '<svg viewBox="0 0 24 24"><path d="M12 3l7 3v5.5c0 4.4-3 7.8-7 9.5-4-1.7-7-5.1-7-9.5V6z"/><path d="M9 12l2.2 2.2L15 10.4"/></svg>'
  };
  function rowsHtml(l){ return '<div class="rows">' + l.map(function(r){ return '<div><span>' + r[0] + '</span><b>' + r[1] + '</b></div>'; }).join('') + '</div>'; }
  function shortT(t){ return t.length > 18 ? t.slice(0, 8) + '…' + t.slice(-6) : t; }
  function openRes(h){ BOX.innerHTML = h; RES.hidden = false; }
  function setStep(n){ $$('.stp li').forEach(function(li, i){ li.className = i < n ? 'done' : i === n ? 'on' : ''; }); }
  function procHtml(m){
    var st = m.auto
      ? ['Connecting to ' + (m.auto === 'bsc' ? 'BNB Smart Chain' : 'Binance'), 'Finding your transaction', 'Checking amount and token', 'Adding balance to your account']
      : ['Connecting to ' + m.name, 'Finding your transaction', 'Checking amount and method', 'Confirming your deposit'];
    return '<div class="spin-wrap"><div class="spin"></div><div class="spin-ic">' + IC.shield + '</div></div>' +
      '<h2>Verifying your payment</h2><p class="sub">This takes a few seconds. Please do not close or refresh this page.</p>' +
      '<ul class="stp">' + st.map(function(t){ return '<li><i></i><span>' + t + '</span></li>'; }).join('') + '</ul><div class="lock">🔒 Secure automatic verification</div>';
  }
  function resHtml(d){
    if (d.type === 'success') return '<div class="chk">' + IC.ok + '</div><h2>Payment Successful</h2><p class="sub">Your balance was added successfully.</p><div class="big">+ ' + d.amt_html + '</div>' +
      rowsHtml([['Method', esc(d.method)], ['You paid', esc(d.paid)], ['Transaction', esc(shortT(d.trx))], ['New balance', d.bal_html], ['Time', esc(d.at_txt)]]) + '<button type="button" data-act="reload">Done</button>';
    if (d.type === 'pending') return '<div class="chk warn">' + IC.clock + '</div><h2>' + (d.auto_down ? 'Verification delayed' : 'Request received') + '</h2><p class="sub">' + esc(d.msg) + '</p><span class="badge2">Pending review</span>' +
      rowsHtml([['Method', esc(d.method)], ['Amount', d.amt_html], ['Transaction', esc(shortT(d.trx))], ['Time', esc(d.at_txt)]]) + '<button type="button" class="pri" data-act="reload">OK, got it</button>';
    return '<div class="chk bad">' + IC.x + '</div><h2>' + esc(d.title || 'Could not verify') + '</h2><p class="sub">' + esc(d.msg || 'Something went wrong.') + '</p>' +
      '<button type="button" class="pri" data-act="close">Try again</button>' + (d.reload ? '<button type="button" class="ghost" data-act="reload">Check deposit history</button>' : '');
  }
  BOX.addEventListener('click', function(e){
    var b = e.target.closest('button'); if (!b) return;
    if (b.dataset.act === 'reload') location.href = location.pathname;
    else if (b.dataset.act === 'close') RES.hidden = true;
  });
  function runAuto(m){
    var t0 = Date.now(), step = 0;
    openRes(procHtml(m)); setStep(0);
    var iv = setInterval(function(){ if (step < 2) setStep(++step); }, 900);
    var fin = function(d){ setTimeout(function(){ clearInterval(iv); if (d.type === 'success') setStep(4); else if (d.type === 'pending') setStep(2); openRes(resHtml(d)); }, Math.max(0, 2600 - (Date.now() - t0))); };
    fetch(location.pathname, {method: 'POST', body: new FormData($('#dpForm')), credentials: 'same-origin', headers: {'X-Requested-With': 'fetch'}})
      .then(function(r){ return r.json(); })
      .then(fin, function(){ fin({type: 'error', title: 'Connection problem', msg: 'We could not get an answer from the server. Your deposit may already have been processed, so check your deposit history before trying again.', reload: 1}); });
  }
  $('#dpForm').addEventListener('submit', function(e){
    if ($('#dpSubmit').disabled) { e.preventDefault(); return; }
    var m = M[meth.value];
    if (m && window.fetch && window.FormData) { e.preventDefault(); runAuto(m); }   // every method now uses the loading / result screens (falls back to a normal post if fetch is unavailable)
  });
  // restore state after a server-side error, otherwise start fresh
  if (meth.value && M[meth.value]) { var b = document.querySelector('.dp-mth[data-m="' + meth.value + '"]'); if (b) b.classList.add('on'); }
  sum();
  <?php if ($err): ?>if (okAmt() && M[meth.value]) show(2, false);<?php endif; ?>
})();
</script>
<script>
// on phones the deposit history starts collapsed so the page fits one screen
(function(){var h=document.getElementById('dpHist');if(h&&window.matchMedia&&window.matchMedia('(max-width:600px)').matches)h.removeAttribute('open')})();
</script>
<?php user_end();
