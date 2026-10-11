<?php
require __DIR__ . '/lib.php';
$u = current_user();

/* Everything below can be changed from Admin > Site settings. The text here is only the default. */
$brand   = site_name();
$tagline = setting('tagline', 'Premium Digital Tools, All in One Place');
$sub     = setting('subtitle', 'Affordable software and digital tool subscriptions. Fast activation and reliable support.');
$support = setting('telegram_url') ?: setting('support_url');
$logo    = media_url('logo');
$banner  = media_url('banner');
$features = [
  ['⚡', 'Fast Activation', 'Your subscription goes live shortly after payment is confirmed.'],
  ['🛡️', 'Secure Payments', 'Your wallet and account are protected with secure password hashing.'],
  ['🎧', '24/7 Support', 'Contact us directly on Telegram for any issue.'],
  ['🎁', 'Referral Bonus', 'Refer a friend and earn a bonus on their first deposit.'],
];
$heroStyle = $banner
  ? "background-image:linear-gradient(rgba(15,23,42,.55),rgba(15,23,42,.55)),url('" . e($banner) . "');background-size:cover;background-position:center;animation:none"
  : '';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($brand) ?> - <?= e($tagline) ?></title>
<?= icon_tags() ?>
<style>
:root{--a:#4f46e5;--b:#db2777;--c:#2563eb;--dark:#0f172a}
*{box-sizing:border-box;margin:0}
html{scroll-behavior:smooth}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1e293b;background:#f8fafc;overflow-x:hidden}
a{text-decoration:none;color:inherit}
nav{position:fixed;top:0;left:0;right:0;z-index:10;display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 18px;background:linear-gradient(180deg,rgba(15,23,42,.92),rgba(30,27,75,.88));backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);color:#fff;border-bottom:1px solid rgba(255,255,255,.14);box-shadow:0 8px 28px -12px rgba(0,0,0,.55)}
nav:after{content:"";position:absolute;left:0;right:0;bottom:-1px;height:2px;background:linear-gradient(90deg,transparent,#a5b4fc,#f472b6,#a5b4fc,transparent);opacity:.9}
nav .brand{font-weight:800;font-size:1.2rem;display:inline-flex;align-items:center;gap:10px;letter-spacing:.2px;min-width:0}
nav .brand span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:linear-gradient(90deg,#fff,#c7d2fe);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
nav .brand img{height:34px;width:34px;object-fit:cover;border-radius:10px;box-shadow:0 0 0 2px rgba(255,255,255,.25)}
nav .links{display:flex;align-items:center;gap:8px;flex:none}
nav .links a{font-size:.92rem;font-weight:600;border-radius:99px;padding:8px 16px;transition:background .2s,transform .2s,box-shadow .2s}
nav .links a.lk{color:rgba(255,255,255,.85);padding:8px 12px}
nav .links a.lk:hover{color:#fff;background:rgba(255,255,255,.12)}
nav .links a.lg{color:#fff;border:1.5px solid rgba(255,255,255,.55);background:rgba(255,255,255,.1)}
nav .links a.lg:hover{background:rgba(255,255,255,.22);transform:translateY(-1px)}
nav .links .pill{background:linear-gradient(135deg,#fff,#e0e7ff);color:#3730a3;font-weight:800;box-shadow:0 6px 18px -4px rgba(165,180,252,.8),inset 0 -2px 0 rgba(79,70,229,.15)}
nav .links .pill:hover{transform:translateY(-2px);box-shadow:0 10px 24px -4px rgba(165,180,252,.95)}
.hero{position:relative;min-height:100vh;display:flex;align-items:center;padding:96px 22px 210px;color:#fff;
 background:linear-gradient(120deg,var(--a),var(--b),var(--c),var(--a));background-size:300% 300%;animation:flow 12s ease infinite;overflow:hidden}
@keyframes flow{0%{background-position:0 50%}50%{background-position:100% 50%}100%{background-position:0 50%}}
.hero .in{max-width:760px;margin:auto;text-align:center;position:relative;z-index:2}
.hero h1{font-size:clamp(2rem,7vw,3.6rem);line-height:1.2;margin-bottom:16px;animation:up .9s both}
.hero p{font-size:1.1rem;opacity:.92;margin-bottom:28px;animation:up .9s .15s both}
.btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;animation:up .9s .3s both}
.btn{padding:13px 28px;border-radius:99px;font-weight:700;transition:transform .2s,box-shadow .2s}
.btn:hover{transform:translateY(-3px);box-shadow:0 10px 25px #0004}
.btn.w{background:#fff;color:var(--a)}
.btn.o{border:2px solid #fff;color:#fff}
@keyframes up{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:none}}
.orb{position:absolute;display:grid;place-items:center;border-radius:50%;background:radial-gradient(circle at 30% 28%,rgba(255,255,255,.38),rgba(255,255,255,.14) 62%);border:1px solid rgba(255,255,255,.28);box-shadow:0 12px 34px -12px rgba(0,0,0,.28),inset 0 0 22px rgba(255,255,255,.14);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);animation:float 9s ease-in-out infinite;pointer-events:none}
.orb svg,.orb .em{width:52%!important;height:52%!important;display:block;filter:blur(1.6px) saturate(1.1);opacity:.7}
.orb .em{display:grid;place-items:center;font-size:2.2rem;line-height:1}
.orb.hl{background:radial-gradient(circle at 30% 28%,rgba(255,255,255,.6),rgba(255,255,255,.26) 64%);border:1.5px solid rgba(255,255,255,.65);box-shadow:0 0 0 6px rgba(255,255,255,.1),0 0 44px rgba(255,255,255,.42),0 16px 40px -10px rgba(0,0,0,.35),inset 0 0 26px rgba(255,255,255,.3)}
.orb.hl svg,.orb.hl .em{width:56%!important;height:56%!important;filter:blur(.3px) drop-shadow(0 4px 10px rgba(0,0,0,.3));opacity:1}
.o1{width:190px;height:190px;top:8%;left:-45px}
.o2{width:150px;height:150px;bottom:9%;right:4%;animation-delay:-3s}
.o3{width:140px;height:140px;top:14%;right:-30px;animation-delay:-2s}
.o4{width:130px;height:130px;bottom:10%;left:6%;animation-delay:-5s}
.o5{width:90px;height:90px;top:32%;right:22%;animation-delay:-6s}
.o6{width:84px;height:84px;top:50%;left:3%;animation-delay:-7s}
.o7{width:76px;height:76px;top:20%;left:46%;animation-delay:-8s}
.o8{width:90px;height:90px;bottom:28%;right:40%;animation-delay:-4s}
.o9{width:70px;height:70px;bottom:4%;left:44%;animation-delay:-1s}
@keyframes float{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-40px) scale(1.08)}}
section{padding:70px 20px;max-width:1000px;margin:auto}
h2{text-align:center;font-size:1.9rem;margin-bottom:8px}
.lead{text-align:center;color:#64748b;margin-bottom:36px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:18px}
.card{background:#fff;border-radius:16px;padding:26px 22px;box-shadow:0 4px 18px #0001;transition:transform .25s,box-shadow .25s}
.card:hover{transform:translateY(-8px);box-shadow:0 14px 30px #4f46e533}
.ic{font-size:2rem;margin-bottom:10px}
.card h3{margin-bottom:6px}.card p{color:#64748b;font-size:.93rem}
.plans{background:linear-gradient(180deg,#f1f5f9,#e0e7ff);max-width:none}
.plans .grid{max-width:1000px;margin:auto}
.plan{text-align:center;position:relative}
.plan .price{font-size:2.2rem;font-weight:800;color:var(--a);margin:10px 0}
.plan .price small{font-size:.9rem;color:#64748b;font-weight:400}
.plan ul{list-style:none;padding:0;margin:14px 0 20px;text-align:left}
.plan li{padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:.93rem}
.plan li:before{content:"✓ ";color:#16a34a;font-weight:700}
.plan.hot{border:2px solid var(--b);transform:scale(1.04)}
.plan.hot:hover{transform:scale(1.04) translateY(-8px)}
.tag{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:linear-gradient(90deg,var(--a),var(--b));color:#fff;font-size:.75rem;padding:4px 14px;border-radius:99px}
.plan .btn{display:block;background:linear-gradient(90deg,var(--a),var(--b));color:#fff}
.cta{text-align:center;color:#fff;background:linear-gradient(120deg,var(--a),var(--b));max-width:none;padding:60px 20px}
.cta .btn{display:inline-block;margin-top:18px}
footer{text-align:center;padding:24px;color:#64748b;font-size:.9rem}
.rv{opacity:0;transform:translateY(34px);transition:all .7s}
.rv.show{opacity:1;transform:none}
.plan.hot.rv.show{transform:scale(1.04)}
@media(max-width:600px){nav .links a.hide{display:none}nav .links a{padding:7px 14px;font-size:.88rem}nav .brand{font-size:1.05rem}.plan.hot{transform:none}.plan.hot:hover{transform:translateY(-8px)}.plan.hot.rv.show{transform:none}}
</style></head><body>
<?= app_icon_sprite() ?>

<nav><a class="brand" href="/"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?><span><?= e($brand) ?></span></a>
<div class="links">
<a class="hide lk" href="/">Home</a><a class="hide lk" href="#features">Features</a>
<?php if ($u): ?>
  <a class="pill" href="/dashboard.php">Dashboard</a>
<?php else: ?>
  <a class="lg" href="/login.php">Login</a><a class="pill" href="/register.php">Register</a>
<?php endif; ?>
</div></nav>

<header class="hero" <?= $heroStyle ? 'style="' . $heroStyle . '"' : '' ?>>
  <?php
  // Bubble icons: app icon (blurred, soft) when the shop's icon set has it, otherwise an emoji.
  $bubbles = [   // [icon key, fallback emoji, highlighted?]
      ['gmail', '✉️', 1], ['facebook', '👍', 1], ['instagram', '📸', 1], ['outlook', '📧', 1],
      ['youtube', '▶️', 0], ['tiktok', '🎵', 0], ['snapchat', '👻', 0], ['shield', '🛡️', 0], ['proxy', '🌐', 0],
  ];
  foreach ($bubbles as $bi => [$bk, $be, $hl]) {
      if ($bk === 'outlook' && !isset(app_icons()['outlook']) && isset(app_icons()['hotmail'])) $bk = 'hotmail';
      echo '<span class="orb o' . ($bi + 1) . ($hl ? ' hl' : '') . '" aria-hidden="true">';
      echo isset(app_icons()[$bk]) ? app_icon_svg($bk, '40px') : '<span class="em">' . $be . '</span>';
      echo '</span>';
  }
  ?>
  <div class="in">
    <h1><?= e($tagline) ?></h1>
    <p><?= e($sub) ?></p>
    <div class="btns">
      <a class="btn w" href="<?= $u ? '/dashboard.php' : '/register.php' ?>">Get Started</a>
    </div>
  </div>
</header>

<section id="features">
  <h2 class="rv">Why Choose <?= e($brand) ?>?</h2>
  <p class="lead rv">Built with your convenience in mind</p>
  <div class="grid">
  <?php foreach ($features as $f): ?>
    <div class="card rv"><div class="ic"><?= $f[0] ?></div><h3><?= e($f[1]) ?></h3><p><?= e($f[2]) ?></p></div>
  <?php endforeach; ?>
  </div>
</section>

<section class="cta">
  <h2 class="rv">Start Today</h2>
  <p class="rv">Create an account, add balance, and earn referral bonuses.</p>
  <a class="btn w rv" href="<?= $u ? '/dashboard.php' : '/register.php' ?>">Create Account</a>
</section>

<footer>© <?= date('Y') ?> <?= e($brand) ?><?php if ($support): ?> · <a href="<?= e($support) ?>" style="color:var(--a)" target="_blank" rel="noopener">Support</a><?php endif; ?></footer>

<script>
const io=new IntersectionObserver(es=>es.forEach(x=>{if(x.isIntersecting){x.target.classList.add('show');io.unobserve(x.target)}}),{threshold:.15});
document.querySelectorAll('.rv').forEach((el,i)=>{el.style.transitionDelay=(i%4)*90+'ms';io.observe(el)});
</script>
</body></html>
