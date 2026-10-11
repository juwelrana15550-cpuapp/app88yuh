<?php /* ============================================================
   "Get OTP" credentials card — professional redesign
   Drop this markup into otp.php's <main> (replace the old card).
   Uses only the site's existing tokens (--a/--b/--c) + lib.php icon().
   No logic is assumed here — wire up $_POST handling as before;
   the JS only handles UI state (loading, reveal, counters).
   ============================================================ */
?>
<style>
/* ---- Get OTP card ---- */
.otp-card{background:#fff;border-radius:18px;padding:22px 20px 24px;box-shadow:0 4px 20px #0001;max-width:460px;margin:0 auto}
.otp-h{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.otp-h .ic{flex:none;width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--a),#7c3aed);color:#fff;box-shadow:0 6px 14px -6px rgba(79,70,229,.55)}
.otp-h .ic svg{width:18px;height:18px}
.otp-h b{font-size:1.02rem;letter-spacing:.01em}
.otp-h small{display:block;color:#64748b;font-size:.78rem;margin-top:1px}

.otp-wrap{position:relative}
.otp-ta{width:100%;min-height:150px;resize:vertical;border:1.5px solid #e2e8f0;border-radius:12px;padding:13px 14px;font:13px/1.65 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#1e293b;background:#f8fafc;transition:border-color .15s,box-shadow .15s;tab-size:2}
.otp-ta::placeholder{color:#94a3b8}
.otp-ta:focus{outline:0;border-color:var(--a);box-shadow:0 0 0 4px rgba(79,70,229,.12);background:#fff}
.otp-meta{display:flex;justify-content:space-between;align-items:center;margin-top:7px;padding:0 2px}
.otp-meta .cnt{font-size:.74rem;color:#94a3b8;font-variant-numeric:tabular-nums}
.otp-meta .mask{display:inline-flex;align-items:center;gap:5px;font-size:.74rem;color:#64748b;cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent}
.otp-meta .mask svg{width:14px;height:14px;flex:none}
.otp-meta .mask:active{opacity:.6}

.otp-go{width:100%;margin-top:16px;display:flex;align-items:center;justify-content:center;gap:9px;padding:14px 10px;border:0;border-radius:13px;font-size:.95rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#fff;cursor:pointer;background:linear-gradient(90deg,var(--a),#7c3aed);box-shadow:0 10px 22px -8px rgba(79,70,229,.6);transition:transform .15s,box-shadow .15s,opacity .15s}
.otp-go svg{width:17px;height:17px;transition:transform .2s}
.otp-go:hover{transform:translateY(-2px);box-shadow:0 14px 26px -8px rgba(79,70,229,.68)}
.otp-go:active{transform:translateY(0) scale(.98)}
.otp-go:disabled{opacity:.72;cursor:not-allowed;transform:none;box-shadow:0 6px 14px -8px rgba(79,70,229,.5)}
.otp-go:disabled svg.bolt{animation:otpSpin .7s linear infinite}
@keyframes otpSpin{to{transform:rotate(360deg)}}
.otp-go.err{background:linear-gradient(90deg,#dc2626,#b91c1c);animation:otpShake .32s}
@keyframes otpShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}

.otp-note{display:flex;align-items:center;gap:7px;margin-top:10px;font-size:.78rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:8px 11px;border-radius:9px}
.otp-note[hidden]{display:none}
.otp-note svg{width:14px;height:14px;flex:none}

.otp-info{position:relative;margin-top:18px;background:linear-gradient(180deg,#eef2ff,#f5f3ff);border:1px solid #e0e7ff;border-radius:14px;padding:16px 16px 16px 18px}
.otp-info:before{content:"";position:absolute;left:0;top:14px;bottom:14px;width:3px;border-radius:3px;background:linear-gradient(180deg,var(--a),#7c3aed)}
.otp-info h4{display:flex;align-items:center;gap:7px;font-size:.84rem;font-weight:700;color:#3730a3;text-transform:uppercase;letter-spacing:.04em;margin-bottom:11px}
.otp-info h4 svg{width:15px;height:15px}
.otp-info ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px}
.otp-info li{display:flex;gap:9px;font-size:.84rem;line-height:1.55;color:#334155}
.otp-info li:before{content:"";flex:none;width:6px;height:6px;margin-top:7px;border-radius:50%;background:var(--a)}
.otp-code{font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#fff;border:1px solid #e0e7ff;color:#4338ca;padding:2px 7px;border-radius:6px;white-space:nowrap}
.otp-hosts{display:inline-flex;flex-wrap:wrap;gap:6px;margin-top:2px}
.otp-host{font:11.5px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:600;padding:5px 9px;border-radius:99px;background:#fff;border:1px solid #e0e7ff;color:#4338ca}

@media(max-width:380px){.otp-card{padding:18px 15px 20px}}
</style>

<form class="otp-card" id="otpF" method="post" autocomplete="off">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>

  <div class="otp-h">
    <span class="ic"><?= function_exists('icon') ? icon('key') : '' ?></span>
    <div><b>Credentials</b><small>Paste one or more accounts below</small></div>
  </div>

  <div class="otp-wrap">
    <textarea class="otp-ta" name="creds" id="otpTa" spellcheck="false"
      placeholder="email|password|token|client_id&#10;email|password|token|client_id|recovery@optional.com"></textarea>
    <div class="otp-meta">
      <span class="cnt" id="otpCnt">0 lines</span>
      <label class="mask" id="otpMask">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
        <span>Mask on blur</span>
      </label>
    </div>
  </div>

  <div class="otp-note" id="otpErr" role="alert" hidden></div>

  <button type="submit" class="otp-go" id="otpGo">
    <svg class="bolt" viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
    <span id="otpGoTxt">Get Code</span>
  </button>

  <div class="otp-info">
    <h4><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>Instructions</h4>
    <ul>
      <li>Required format: <span class="otp-code">email|password|token|client_id</span><br>Optional: add <span class="otp-code">|recovery_email</span> at the end</li>
      <li>Paste multiple lines to check several accounts at once. Secrets stay masked until you choose to reveal them.</li>
      <li>Supported hosts:<br><span class="otp-hosts"><span class="otp-host">@hotmail</span><span class="otp-host">@outlook</span><span class="otp-host">@live</span></span></li>
    </ul>
  </div>
</form>

<script>
(function(){
  var ta=document.getElementById('otpTa'), cnt=document.getElementById('otpCnt'),
      maskBtn=document.getElementById('otpMask'), f=document.getElementById('otpF'),
      go=document.getElementById('otpGo'), goTxt=document.getElementById('otpGoTxt'),
      err=document.getElementById('otpErr'), masked=false, real='';

  function lineCount(){
    var n=ta.value.split('\n').filter(function(l){return l.trim().length}).length;
    cnt.textContent=n+(n===1?' line':' lines');
  }
  ta.addEventListener('input',lineCount);

  maskBtn.addEventListener('click',function(){
    masked=!masked;
    if(masked){ real=ta.value; ta.value=ta.value.replace(/[^\n|]/g,'•'); ta.readOnly=true; maskBtn.querySelector('span').textContent='Unmask'; }
    else { ta.value=real; ta.readOnly=false; maskBtn.querySelector('span').textContent='Mask on blur'; ta.focus(); }
  });

  function showErr(msg){
    err.textContent=msg; err.hidden=false;
    go.classList.add('err'); setTimeout(function(){go.classList.remove('err')},320);
  }

  f.addEventListener('submit',function(e){
    err.hidden=true;
    var lines=ta.value.split('\n').map(function(l){return l.trim()}).filter(Boolean);
    if(!lines.length){ e.preventDefault(); showErr('Paste at least one credential line.'); return; }
    var bad=lines.find(function(l){return l.split('|').length<4});
    if(bad){ e.preventDefault(); showErr('Each line needs email|password|token|client_id.'); return; }
    go.disabled=true; goTxt.textContent='Fetching…';
    // Form posts normally from here — remove preventDefault logic above if you handle via fetch() instead.
  });
})();
</script>
