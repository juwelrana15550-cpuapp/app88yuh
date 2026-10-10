<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https']);
session_start();

const SITE_NAME = 'MySite';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $url = getenv('MYSQL_PUBLIC_URL') ?: getenv('MYSQL_URL');
    if ($url && ($c = parse_url($url)) && !empty($c['host'])) {
        $h = $c['host'];
        $p = (string)($c['port'] ?? 3306);
        $n = ltrim($c['path'] ?? '/railway', '/') ?: 'railway';
        $u = urldecode($c['user'] ?? 'root');
        $w = urldecode($c['pass'] ?? '');
    } else {
        $h = getenv('MYSQLHOST') ?: '127.0.0.1';
        $p = getenv('MYSQLPORT') ?: '3306';
        $n = getenv('MYSQLDATABASE') ?: 'railway';
        $u = getenv('MYSQLUSER') ?: 'root';
        $w = getenv('MYSQLPASSWORD') ?: '';
    }
    $pdo = new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", $u, $w, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        coins DECIMAL(12,2) NOT NULL DEFAULT 0,
        referral_code VARCHAR(16) NOT NULL UNIQUE,
        referred_by INT NULL,
        has_deposited TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        note VARCHAR(255) NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(60) PRIMARY KEY,
        v TEXT NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS media (
        k VARCHAR(30) PRIMARY KEY,
        mime VARCHAR(40) NOT NULL,
        data MEDIUMBLOB NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        description VARCHAR(500) NULL,
        price DECIMAL(12,2) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(80) NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        status ENUM('pending','delivered','cancelled') NOT NULL DEFAULT 'pending',
        delivery TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(20) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB");
    return $pdo;
}

function add_tx(PDO $pdo, int $uid, string $type, float $amount, string $note = ''): void {
    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, note) VALUES (?,?,?,?)')->execute([$uid, $type, $amount, $note]);
}
function mask_email(string $m): string {
    $p = explode('@', $m, 2);
    return mb_substr($p[0], 0, 2) . '***@' . ($p[1] ?? '');
}

function setting(string $k, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (db()->query('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v']; } catch (Throwable $ex) {}
    }
    return (isset($cache[$k]) && $cache[$k] !== '') ? $cache[$k] : $default;
}
function save_setting(string $k, string $v): void {
    db()->prepare('INSERT INTO settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}
function site_name(): string { return setting('site_name', SITE_NAME); }
function media_url(string $k): ?string {
    static $m = null;
    if ($m === null) {
        $m = [];
        try { foreach (db()->query('SELECT k, UNIX_TIMESTAMP(updated_at) AS t FROM media') as $r) $m[$r['k']] = $r['t']; } catch (Throwable $ex) {}
    }
    return isset($m[$k]) ? '/media.php?k=' . urlencode($k) . '&v=' . $m[$k] : null;
}
function logo_html(string $fallback): string {
    $u = media_url('logo');
    return $u ? '<div class="logo has-img"><img src="' . e($u) . '" alt=""></div>' : '<div class="logo">' . $fallback . '</div>';
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_field(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function csrf_check(): void {
    if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(400); exit('Invalid request');
    }
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$_SESSION['uid']]);
    return $s->fetch() ?: null;
}
function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: /login.php'); exit; }
    return $u;
}

function header_html(string $title, ?array $user = null, string $variant = 'app', bool $admin = false): void { ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e(site_name()) ?></title>
<link rel="stylesheet" href="/style.css">
</head><body class="<?= e($variant) ?>">
<?php if ($variant === 'auth'): ?><span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span><?php endif; ?>
<nav><a class="brand" href="/"><?php if ($lg = media_url('logo')): ?><img src="<?= e($lg) ?>" alt=""><?php endif; ?><?= e(site_name()) ?></a>
<div class="links">
<?php if ($admin): ?>
  <span class="hide">Admin Panel</span>
  <form method="post" action="/admin.php" class="inl"><?= csrf_field() ?><button class="link" name="admin_logout" value="1">Logout</button></form>
<?php elseif ($user): ?>
  <a href="/dashboard.php">Dashboard</a>
  <form method="post" action="/logout.php" class="inl"><?= csrf_field() ?><button class="link">Logout</button></form>
<?php else: ?>
  <a href="/">Home</a><a href="/login.php">Login</a><a class="pill" href="/register.php">Register</a>
<?php endif; ?>
</div></nav>
<main class="<?= $variant === 'auth' ? 'narrow' : 'wide' ?>">
<?php }

function footer_html(): void { ?>
</main>
<script>
document.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){
  var t=document.querySelector(b.dataset.copy);t.select();
  try{navigator.clipboard.writeText(t.value)}catch(e){document.execCommand('copy')}
  var o=b.textContent;b.textContent='Copied!';setTimeout(function(){b.textContent=o},1500);});});
var fb=document.getElementById('fab');if(fb){fb.querySelector('.fab-main').addEventListener('click',function(){fb.classList.toggle('open')});}
document.querySelectorAll('[data-toggle]').forEach(function(b){b.addEventListener('click',function(){
  var i=document.querySelector(b.dataset.toggle);i.type=i.type==='password'?'text':'password';
  b.textContent=i.type==='password'?'Show':'Hide';});});
</script>
</body></html>
<?php }

function user_start(string $title, array $u, string $active): void {
    $items = [
        'dashboard'    => ['/dashboard.php', 'Dashboard', '📊'],
        'shop'         => ['/shop.php', 'Shop', '🛍️'],
        'deposits'     => ['/deposits.php', 'Deposits', '💰'],
        'orders'       => ['/orders.php', 'My Orders', '🛒'],
        'referrals'    => ['/referrals.php', 'Referrals', '👥'],
        'transactions' => ['/transactions.php', 'Transactions', '🔁'],
        'profile'      => ['/profile.php', 'Profile', '⚙️'],
    ];
    $name = ucfirst(strstr($u['email'], '@', true) ?: $u['email']);
    $lg = media_url('logo'); ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e(site_name()) ?></title>
<link rel="stylesheet" href="/style.css">
</head><body class="app">
<div class="top"><button class="burger" type="button" onclick="document.body.classList.toggle('menu')">☰</button>
<a class="brand" href="/"><?php if ($lg): ?><img src="<?= e($lg) ?>" alt=""><?php endif; ?><?= e(site_name()) ?></a></div>
<div class="shade" onclick="document.body.classList.remove('menu')"></div>
<aside class="side">
  <div class="sh">
    <div class="shr"><b>My Account</b><button type="button" class="x" onclick="document.body.classList.remove('menu')">✕</button></div>
    <div class="who"><div class="av"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></div><div><b><?= e($name) ?></b><small><?= e($u['email']) ?></small></div></div>
    <div class="bal"><span>Balance</span><b><?= number_format((float)$u['coins'], 2) ?></b></div>
  </div>
  <nav class="mn">
  <?php foreach ($items as $k => $it): ?>
    <a href="<?= $it[0] ?>" class="<?= $k === $active ? 'on' : '' ?>"><span><?= $it[2] ?></span><?= e($it[1]) ?></a>
  <?php endforeach; ?>
  </nav>
  <form method="post" action="/logout.php" class="lo"><?= csrf_field() ?><button type="submit">⏻ Logout</button></form>
</aside>
<main class="wide with-side">
<?php }

function user_end(): void {
    $tg = setting('telegram_url'); $wa = setting('whatsapp_url');
    if ($tg || $wa): ?>
<div class="fab" id="fab">
  <?php if ($tg): ?><a class="fab-i tg" href="<?= e($tg) ?>" target="_blank" rel="noopener"><span class="lbl">Telegram Support</span><b>✈</b></a><?php endif; ?>
  <?php if ($wa): ?><a class="fab-i wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><span class="lbl">WhatsApp Support</span><b>✆</b></a><?php endif; ?>
  <button type="button" class="fab-main" aria-label="Support">+</button>
</div>
<?php endif;
    footer_html();
}
