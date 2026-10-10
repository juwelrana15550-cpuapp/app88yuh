<?php
require __DIR__ . '/lib.php';
$u = current_user();

/* ====== এখান থেকে নিজের তথ্য বদলাও ====== */
$brand   = 'MySite';
$tagline = 'আপনার প্রয়োজনীয় ডিজিটাল টুল, এক জায়গায়';
$sub     = 'সাশ্রয়ী দামে প্রিমিয়াম সফটওয়্যার ও ডিজিটাল টুল সাবস্ক্রিপশন। দ্রুত অ্যাক্টিভেশন, নির্ভরযোগ্য সাপোর্ট।';
$support = 'https://t.me/your_username';   // নিজের Telegram লিংক দাও
$plans = [
  ['name' => 'Starter',  'price' => '৳৫০০',   'per' => '/ মাস', 'hot' => false,
   'items' => ['১টি টুল অ্যাক্সেস', 'ইমেইল সাপোর্ট', 'মাসিক রিনিউ']],
  ['name' => 'Pro',      'price' => '৳১,২০০', 'per' => '/ মাস', 'hot' => true,
   'items' => ['৫টি টুল অ্যাক্সেস', '২৪/৭ সাপোর্ট', 'দ্রুত অ্যাক্টিভেশন', 'রেফারেল বোনাস']],
  ['name' => 'Business', 'price' => '৳২,৫০০', 'per' => '/ মাস', 'hot' => false,
   'items' => ['সব টুল অ্যাক্সেস', 'প্রায়োরিটি সাপোর্ট', 'টিমের জন্য একাধিক সিট']],
];
$features = [
  ['⚡', 'দ্রুত অ্যাক্টিভেশন', 'পেমেন্ট কনফার্মের পর অল্প সময়ে সাবস্ক্রিপশন চালু।'],
  ['🛡️', 'নিরাপদ লেনদেন', 'আপনার ওয়ালেট ও অ্যাকাউন্ট সুরক্ষিত পাসওয়ার্ড হ্যাশিংয়ে রাখা হয়।'],
  ['🎧', '২৪/৭ সাপোর্ট', 'যেকোনো সমস্যায় Telegram-এ সরাসরি যোগাযোগ করুন।'],
  ['🎁', 'রেফারেল বোনাস', 'বন্ধুকে রেফার করুন, তার প্রথম ডিপোজিটে বোনাস কয়েন পান।'],
];
/* ======================================== */
?>
<!DOCTYPE html>
<html lang="bn"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($brand) ?> - <?= e($tagline) ?></title>
<style>
:root{--a:#6d28d9;--b:#db2777;--c:#2563eb;--dark:#0f172a}
*{box-sizing:border-box;margin:0}
html{scroll-behavior:smooth}
body{font-family:system-ui,"Noto Sans Bengali",sans-serif;color:#1e293b;background:#f8fafc;overflow-x:hidden}
a{text-decoration:none;color:inherit}
nav{position:fixed;top:0;left:0;right:0;z-index:10;display:flex;justify-content:space-between;align-items:center;padding:14px 20px;background:rgba(15,23,42,.55);backdrop-filter:blur(10px);color:#fff}
nav .brand{font-weight:800;font-size:1.2rem}
nav .links a{margin-left:14px;font-size:.92rem;opacity:.9}
nav .links .pill{background:#fff;color:var(--a);padding:7px 16px;border-radius:99px;font-weight:600;opacity:1}
nav button{background:none;border:0;color:#fff;font:inherit;cursor:pointer;margin-left:14px}
.hero{position:relative;min-height:100vh;display:flex;align-items:center;padding:100px 22px 80px;color:#fff;
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
.orb{position:absolute;border-radius:50%;filter:blur(2px);opacity:.28;background:#fff;animation:float 9s ease-in-out infinite}
.o1{width:220px;height:220px;top:8%;left:-60px}
.o2{width:140px;height:140px;bottom:12%;right:6%;animation-delay:-3s}
.o3{width:90px;height:90px;top:30%;right:18%;animation-delay:-6s}
@keyframes float{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-40px) scale(1.08)}}
section{padding:70px 20px;max-width:1000px;margin:auto}
h2{text-align:center;font-size:1.9rem;margin-bottom:8px}
.lead{text-align:center;color:#64748b;margin-bottom:36px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:18px}
.card{background:#fff;border-radius:16px;padding:26px 22px;box-shadow:0 4px 18px #0001;transition:transform .25s,box-shadow .25s}
.card:hover{transform:translateY(-8px);box-shadow:0 14px 30px #6d28d933}
.ic{font-size:2rem;margin-bottom:10px}
.card h3{margin-bottom:6px}.card p{color:#64748b;font-size:.93rem}
.plans{background:linear-gradient(180deg,#f1f5f9,#ede9fe);max-width:none}
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
@media(max-width:600px){nav .links a.hide{display:none}.plan.hot{transform:none}.plan.hot:hover{transform:translateY(-8px)}.plan.hot.rv.show{transform:none}}
</style></head><body>

<nav><a class="brand" href="/"><?= e($brand) ?></a>
<div class="links">
<a class="hide" href="#features">ফিচার</a><a class="hide" href="#plans">প্ল্যান</a>
<?php if ($u): ?>
  <a class="pill" href="/dashboard.php">Dashboard</a>
<?php else: ?>
  <a href="/login.php">Login</a><a class="pill" href="/register.php">Register</a>
<?php endif; ?>
</div></nav>

<header class="hero">
  <span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span>
  <div class="in">
    <h1><?= e($tagline) ?></h1>
    <p><?= e($sub) ?></p>
    <div class="btns">
      <a class="btn w" href="<?= $u ? '/dashboard.php' : '/register.php' ?>">শুরু করুন</a>
      <a class="btn o" href="#plans">প্ল্যান দেখুন</a>
    </div>
  </div>
</header>

<section id="features">
  <h2 class="rv">কেন <?= e($brand) ?>?</h2>
  <p class="lead rv">আপনার সুবিধার কথা ভেবেই সাজানো</p>
  <div class="grid">
  <?php foreach ($features as $f): ?>
    <div class="card rv"><div class="ic"><?= $f[0] ?></div><h3><?= e($f[1]) ?></h3><p><?= e($f[2]) ?></p></div>
  <?php endforeach; ?>
  </div>
</section>

<section id="plans" class="plans">
  <h2 class="rv">আমাদের প্ল্যান</h2>
  <p class="lead rv">আপনার প্রয়োজন অনুযায়ী বেছে নিন</p>
  <div class="grid">
  <?php foreach ($plans as $p): ?>
    <div class="card plan rv <?= $p['hot'] ? 'hot' : '' ?>">
      <?php if ($p['hot']): ?><span class="tag">জনপ্রিয়</span><?php endif; ?>
      <h3><?= e($p['name']) ?></h3>
      <div class="price"><?= e($p['price']) ?><small> <?= e($p['per']) ?></small></div>
      <ul><?php foreach ($p['items'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
      <a class="btn" href="<?= $u ? '/dashboard.php' : '/register.php' ?>">নিন</a>
    </div>
  <?php endforeach; ?>
  </div>
</section>

<section class="cta">
  <h2 class="rv">আজই শুরু করুন</h2>
  <p class="rv">অ্যাকাউন্ট খুলুন, কয়েন যোগ করুন, আর রেফারেল বোনাস নিন।</p>
  <a class="btn w rv" href="<?= $u ? '/dashboard.php' : '/register.php' ?>">অ্যাকাউন্ট খুলুন</a>
</section>

<footer>© <?= date('Y') ?> <?= e($brand) ?> · <a href="<?= e($support) ?>" style="color:var(--a)">Telegram সাপোর্ট</a></footer>

<script>
const io=new IntersectionObserver(es=>es.forEach(x=>{if(x.isIntersecting){x.target.classList.add('show');io.unobserve(x.target)}}),{threshold:.15});
document.querySelectorAll('.rv').forEach((el,i)=>{el.style.transitionDelay=(i%4)*90+'ms';io.observe(el)});
</script>
</body></html>
