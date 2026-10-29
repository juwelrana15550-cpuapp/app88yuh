<?php
// Shared animated layout for login.php and register.php
function auth_start(string $title, string $mode): void { ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<style>
:root{--p:#6366f1;--p2:#8b5cf6;--ink:#0f172a;--mut:#64748b;--line:#e2e8f0;--bg:#f1f5f9;--card:#fff;--err:#ef4444;--ok:#10b981}
@media(prefers-color-scheme:dark){:root{--ink:#f1f5f9;--mut:#94a3b8;--line:#334155;--bg:#0b1020;--card:#151b2e}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;overflow-x:hidden}
.blob{position:fixed;border-radius:50%;filter:blur(70px);opacity:.45;z-index:0;animation:float 14s ease-in-out infinite}
.b1{width:380px;height:380px;background:var(--p);top:-100px;left:-80px}
.b2{width:320px;height:320px;background:var(--p2);bottom:-90px;right:-60px;animation-delay:-5s}
.b3{width:220px;height:220px;background:#06b6d4;top:50%;left:60%;animation-delay:-9s}
@keyframes float{50%{transform:translate(40px,-50px) scale(1.12)}}
.wrap{position:relative;z-index:1;display:flex;width:100%;max-width:900px;background:var(--card);border-radius:24px;box-shadow:0 30px 80px -20px rgba(15,23,42,.35);overflow:hidden;animation:rise .8s cubic-bezier(.2,.8,.2,1) both}
@keyframes rise{from{opacity:0;transform:translateY(40px) scale(.96)}}
.brand{flex:1;padding:48px 40px;color:#fff;background:linear-gradient(135deg,var(--p),var(--p2),#ec4899);background-size:200% 200%;animation:grad 10s ease infinite;display:flex;flex-direction:column;justify-content:center;position:relative;overflow:hidden}
@keyframes grad{50%{background-position:100% 100%}}
.brand:before,.brand:after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.12)}
.brand:before{width:240px;height:240px;top:-70px;right:-70px;animation:float 9s infinite}
.brand:after{width:160px;height:160px;bottom:-40px;left:-40px;animation:float 11s infinite reverse}
.brand>*{position:relative;z-index:1}
.logo{width:58px;height:58px;border-radius:16px;background:rgba(255,255,255,.2);backdrop-filter:blur(6px);display:grid;place-items:center;font-size:28px;margin-bottom:24px;animation:pop 1s .3s both}
@keyframes pop{from{transform:scale(0) rotate(-90deg)}to{transform:none}}
.brand h1{margin:0 0 10px;font-size:30px;line-height:1.2}
.brand p{margin:0 0 26px;opacity:.88}
.brand li{list-style:none;margin:10px 0;display:flex;gap:10px;align-items:center;opacity:0;animation:slide .6s both}
.brand li:nth-child(1){animation-delay:.6s}.brand li:nth-child(2){animation-delay:.8s}.brand li:nth-child(3){animation-delay:1s}
.brand ul{padding:0;margin:0}
.brand li i{width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.25);display:grid;place-items:center;font-style:normal;font-size:12px}
@keyframes slide{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:none}}
.form{flex:1.1;padding:48px 40px}
.tabs{display:flex;position:relative;background:var(--bg);border-radius:12px;padding:4px;margin-bottom:28px}
.tabs a{flex:1;text-align:center;padding:9px;border-radius:9px;text-decoration:none;color:var(--mut);font-weight:600;position:relative;z-index:1;transition:color .3s}
.tabs a.on{color:var(--p)}
.tabs i{position:absolute;top:4px;bottom:4px;width:calc(50% - 4px);background:var(--card);border-radius:9px;box-shadow:0 2px 8px rgba(0,0,0,.1);left:4px;transition:transform .4s cubic-bezier(.2,.8,.2,1)}
.tabs.reg i{transform:translateX(100%)}
.form h2{margin:0;font-size:26px;animation:slide .6s .2s both}
.sub{margin:4px 0 22px;color:var(--mut);animation:slide .6s .3s both}
.err{background:rgba(239,68,68,.1);color:var(--err);border:1px solid rgba(239,68,68,.3);padding:10px 14px;border-radius:10px;margin-bottom:16px;animation:shake .5s}
@keyframes shake{20%,60%{transform:translateX(-8px)}40%,80%{transform:translateX(8px)}}
.f{position:relative;margin-bottom:16px;animation:slide .6s both}
.f:nth-of-type(1){animation-delay:.35s}.f:nth-of-type(2){animation-delay:.45s}.f:nth-of-type(3){animation-delay:.55s}.f:nth-of-type(4){animation-delay:.65s}
.f input{width:100%;padding:20px 48px 8px 16px;border:1.5px solid var(--line);border-radius:12px;background:transparent;color:var(--ink);font:inherit;outline:0;transition:border-color .25s,box-shadow .25s}
.f label{position:absolute;left:16px;top:14px;color:var(--mut);pointer-events:none;transition:all .2s;transform-origin:left}
.f input:focus{border-color:var(--p);box-shadow:0 0 0 4px rgba(99,102,241,.15)}
.f input:focus+label,.f input:not(:placeholder-shown)+label{top:5px;font-size:11.5px;color:var(--p);font-weight:600}
.f .eye{position:absolute;right:8px;top:9px;border:0;background:none;color:var(--mut);cursor:pointer;padding:6px 8px;font-size:12px;font-weight:600;border-radius:8px}
.f .eye:hover{color:var(--p)}
.meter{height:5px;background:var(--line);border-radius:5px;margin:-6px 0 6px;overflow:hidden}
.meter b{display:block;height:100%;width:0;border-radius:5px;transition:width .4s,background .4s}
.hint{font-size:12px;color:var(--mut);margin-bottom:14px;min-height:16px}
.btn{width:100%;padding:14px;border:0;border-radius:12px;color:#fff;font:600 16px inherit;font-family:inherit;cursor:pointer;background:linear-gradient(135deg,var(--p),var(--p2));box-shadow:0 10px 24px -8px rgba(99,102,241,.7);transition:transform .2s,box-shadow .2s;position:relative;overflow:hidden;animation:slide .6s .75s both}
.btn:hover{transform:translateY(-2px);box-shadow:0 14px 28px -8px rgba(99,102,241,.8)}
.btn:active{transform:scale(.98)}
.btn:after{content:"";position:absolute;top:0;left:-100%;width:60%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.35),transparent);transform:skewX(-20deg);transition:left .6s}
.btn:hover:after{left:140%}
.btn.load{pointer-events:none;opacity:.85}
.btn.load span{visibility:hidden}
.btn.load:before{content:"";position:absolute;inset:0;margin:auto;width:20px;height:20px;border:3px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.alt{text-align:center;color:var(--mut);margin:20px 0 0}
.alt a{color:var(--p);font-weight:600;text-decoration:none}
@media(max-width:760px){.brand{display:none}.form{padding:32px 24px}}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
.char{width:150px;margin:6px auto 0;display:block;animation:bob 3.5s ease-in-out infinite;filter:drop-shadow(0 12px 14px rgba(0,0,0,.25))}
@keyframes bob{50%{transform:translateY(-10px)}}
.wave{transform-origin:109px 92px;animation:wv 1.2s ease-in-out infinite}
@keyframes wv{0%,100%{transform:rotate(-8deg)}50%{transform:rotate(18deg)}}
.chk{display:flex;gap:10px;align-items:flex-start;margin:4px 0 16px;color:var(--mut);font-size:13px;cursor:pointer;animation:slide .6s .7s both}
.chk input{width:18px;height:18px;accent-color:var(--p);margin-top:2px;flex:none}
.note{display:block;font-size:12px;color:var(--mut);margin:-8px 0 14px 4px}
</style></head><body>
<div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div>
<div class="wrap">
<div class="brand">
  <div class="logo">🔐</div>
  <h1><?= $mode === 'reg' ? 'Join us today' : 'Welcome back' ?></h1>
  <p>Fast, secure and simple. Your account, protected end to end.</p>
  <svg class="char" viewBox="0 0 160 230" aria-hidden="true"><ellipse cx="80" cy="223" rx="44" ry="6" fill="rgba(0,0,0,.25)"/><rect x="57" y="140" width="21" height="74" rx="10" fill="#93c5fd"/><rect x="84" y="140" width="21" height="74" rx="10" fill="#60a5fa"/><ellipse cx="66" cy="216" rx="15" ry="7" fill="#fff"/><ellipse cx="96" cy="216" rx="15" ry="7" fill="#fff"/><rect x="50" y="82" width="62" height="70" rx="22" fill="#fbbf24"/><rect x="36" y="90" width="17" height="56" rx="8" fill="#f59e0b"/><circle cx="44" cy="150" r="8" fill="#8d5524"/><g class="wave"><rect x="101" y="38" width="17" height="58" rx="8" fill="#f59e0b"/><circle cx="109" cy="36" r="9" fill="#8d5524"/></g><rect x="72" y="70" width="16" height="16" fill="#8d5524"/><circle cx="80" cy="52" r="26" fill="#a0642c"/><circle cx="58" cy="38" r="13" fill="#1f1308"/><circle cx="70" cy="27" r="14" fill="#1f1308"/><circle cx="90" cy="27" r="14" fill="#1f1308"/><circle cx="102" cy="38" r="13" fill="#1f1308"/><circle cx="80" cy="30" r="14" fill="#1f1308"/><circle cx="71" cy="54" r="3.2" fill="#1f1308"/><circle cx="89" cy="54" r="3.2" fill="#1f1308"/><circle cx="63" cy="62" r="4" fill="#f9a8d4" opacity=".6"/><circle cx="97" cy="62" r="4" fill="#f9a8d4" opacity=".6"/><path d="M70 64 Q80 75 90 64" stroke="#1f1308" stroke-width="2.5" fill="none" stroke-linecap="round"/></svg>
</div>
<div class="form">
  <div class="tabs <?= $mode === 'reg' ? 'reg' : '' ?>"><i></i>
    <a href="/login.php" class="<?= $mode === 'login' ? 'on' : '' ?>">Login</a>
    <a href="/register.php" class="<?= $mode === 'reg' ? 'on' : '' ?>">Register</a>
  </div>
<?php }

function auth_end(): void { ?>
</div></div>
<script>
document.querySelectorAll('.eye').forEach(function(b){b.onclick=function(){
  var i=document.querySelector(b.dataset.t);var s=i.type==='password';
  i.type=s?'text':'password';b.textContent=s?'Hide':'Show';};});
document.querySelectorAll('form').forEach(function(f){f.addEventListener('submit',function(){
  var b=f.querySelector('.btn');if(f.checkValidity())b.classList.add('load');});});
var pw=document.getElementById('rp');
if(pw){var m=document.querySelector('.meter b'),h=document.querySelector('.hint');
  pw.addEventListener('input',function(){var v=pw.value,s=0;
    if(v.length>=8)s++;if(/[A-Z]/.test(v)&&/[a-z]/.test(v))s++;if(/\d/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
    var c=['#ef4444','#f59e0b','#eab308','#10b981','#059669'],t=['Too weak','Weak','Fair','Strong','Very strong'];
    m.style.width=v?(s+1)*20+'%':'0';m.style.background=c[s];h.textContent=v?t[s]:'Use 8+ characters, mixed case, numbers & symbols';});}
</script>
</body></html>
<?php }
