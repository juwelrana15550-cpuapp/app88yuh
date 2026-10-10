<?php
$__https = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0)
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.gc_maxlifetime', '2592000');
session_set_cookie_params(['lifetime' => 2592000, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $__https]);
session_start();

const SITE_NAME = 'MySite';
const SCHEMA_VERSION = '3';

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
    // Tables are created only once (not on every request).
    $ver = null;
    try { $ver = $pdo->query("SELECT v FROM settings WHERE k = 'schema_v'")->fetchColumn(); } catch (Throwable $ex) {}
    if ($ver !== SCHEMA_VERSION) migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(40) NOT NULL,
        key_hash CHAR(64) NOT NULL UNIQUE,
        key_prefix VARCHAR(12) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used_at TIMESTAMP NULL,
        INDEX (user_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        code VARCHAR(32) NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (email, id)
    ) ENGINE=InnoDB");
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_v', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([SCHEMA_VERSION]);
}

/** Run $fn inside a transaction; rolls back on any exception and re-throws. */
function with_tx(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try { $r = $fn($pdo); $pdo->commit(); return $r; }
    catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}

function add_tx(PDO $pdo, int $uid, string $type, float $amount, string $note = ''): void {
    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, note) VALUES (?,?,?,?)')->execute([$uid, $type, $amount, $note]);
}
function mask_email(string $m): string {
    $p = explode('@', $m, 2);
    return mb_substr($p[0], 0, 2) . '***@' . ($p[1] ?? '');
}
function money($n): string { return '৳' . number_format((float)$n, 2); }

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

/** style.css link with a version, so browsers never keep serving an old stylesheet. */
function css_link(): string {
    $f = __DIR__ . '/style.css';
    return '<link rel="stylesheet" href="/style.css?v=' . (is_file($f) ? filemtime($f) : 1) . '">';
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_html(): void {
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) echo '<div class="' . ($t === 'ok' ? 'ok' : 'err') . '">' . e($m) . '</div>';
    unset($_SESSION['flash']);
}

function icon(string $n): string {
    static $p = [
        'gauge'   => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
        'coins'   => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
        'cart'    => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'shield'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'users'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'swap'    => '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
        'key'     => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'usercog' => '<circle cx="18" cy="15" r="3"/><circle cx="9" cy="7" r="4"/><path d="M10 15H6a4 4 0 0 0-4 4v2"/><path d="m21.7 16.4-.9-.3"/><path d="m15.2 13.9-.9-.3"/><path d="m16.6 18.7.3-.9"/><path d="m19.1 12.2.3-.9"/><path d="m19.6 18.7-.4-1"/><path d="m16.8 12.3-.4-1"/><path d="m14.3 16.6 1-.4"/><path d="m20.7 13.8 1-.4"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'x'       => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'menu'    => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'send'    => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'phone'   => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'chat'    => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/><path d="M8 12h.01"/><path d="M12 12h.01"/><path d="M16 12h.01"/>',
        'plus'    => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'home'    => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'mail'    => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
        'bag'     =>'<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

function csrf_field(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function csrf_check(): void {
    if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
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

function page_head(string $title, string $bodyClass): void { ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e(site_name()) ?></title>
<?= css_link() ?>
</head><body class="<?= e($bodyClass) ?>">
<?php }

/** Public / auth / admin pages (top bar, no sidebar). */
function header_html(string $title, ?array $user = null, string $variant = 'app', bool $admin = false): void {
    page_head($title, $variant);
    if ($variant === 'auth') echo '<span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span>'; ?>
<nav class="nav"><a class="brand" href="/"><?php if ($lg = media_url('logo')): ?><img src="<?= e($lg) ?>" alt=""><?php endif; ?><?= e(site_name()) ?></a>
<div class="links">
<?php if ($admin): ?>
  <span class="hide">Admin Panel</span>
  <form method="post" action="/admin.php" class="inl"><?= csrf_field() ?><button class="link" name="admin_logout" value="1">Logout</button></form>
<?php elseif ($user): ?>
  <a href="/dashboard.php">Dashboard</a>
<?php else: ?>
  <a href="/">Home</a><a href="/login.php">Login</a><a class="pill" href="/register.php">Register</a>
<?php endif; ?>
</div></nav>
<main class="<?= $variant === 'auth' ? 'narrow' : 'wide' ?>">
<?php flash_html(); }

function footer_html(bool $closeMain = true): void { if ($closeMain) echo "</main>\n"; ?>
<script>
document.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){
  var t=document.querySelector(b.dataset.copy);t.select();
  try{navigator.clipboard.writeText(t.value)}catch(e){document.execCommand('copy')}
  var o=b.textContent;b.textContent='Copied!';setTimeout(function(){b.textContent=o},1500);});});
var fb=document.getElementById('fab');if(fb){fb.querySelector('.fab-main').addEventListener('click',function(){fb.classList.toggle('open')});}
document.querySelectorAll('[data-toggle]').forEach(function(b){b.addEventListener('click',function(){
  var i=document.querySelector(b.dataset.toggle);i.type=i.type==='password'?'text':'password';
  b.textContent=i.type==='password'?'Show':'Hide';});});
document.addEventListener('keydown',function(ev){if(ev.key==='Escape'){document.body.classList.remove('menu');if(fb)fb.classList.remove('open');}});
</script>
</body></html>
<?php }

function user_start(string $title, array $u, string $active): void {
    $items = [
        'dashboard'    => ['/dashboard.php', 'Dashboard', 'gauge'],
        'deposits'     => ['/deposits.php', 'Deposits', 'coins'],
        'orders'       => ['/orders.php', 'My Orders', 'cart'],
        'otp'          => ['/otp.php', 'Read OTP', 'shield'],
        'referrals'    => ['/referrals.php', 'Referrals', 'users'],
        'transactions' => ['/transactions.php', 'Transactions', 'swap'],
        'apikeys'      => ['/api_keys.php', 'API Keys', 'key'],
    ];
    $name = ucfirst(strstr($u['email'], '@', true) ?: $u['email']);
    $lg = media_url('logo');
    $GLOBALS['__active'] = $active;
    page_head($title, 'app'); ?>
<div class="top"><button class="burger" type="button" aria-label="Menu" onclick="document.body.classList.toggle('menu')"><?= icon('menu') ?></button>
<a class="brand" href="/dashboard.php"><?php if ($lg): ?><img src="<?= e($lg) ?>" alt=""><?php endif; ?><?= e(site_name()) ?></a></div>
<div class="shade" onclick="document.body.classList.remove('menu')"></div>
<aside class="side">
  <div class="sh">
    <div class="shr"><b>My Account</b><button type="button" class="x" aria-label="Close" onclick="document.body.classList.remove('menu')"><?= icon('x') ?></button></div>
    <div class="who"><div class="av"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></div><div><b><?= e($name) ?></b><small><?= e($u['email']) ?></small></div></div>
    <div class="bal"><span>Balance</span><b><?= money($u['coins']) ?></b></div>
  </div>
  <div class="mn">
  <?php foreach ($items as $k => $it): ?>
    <a href="<?= $it[0] ?>" class="<?= $k === $active ? 'on' : '' ?>"><?= icon($it[2]) ?><span><?= e($it[1]) ?></span></a>
  <?php endforeach; ?>
    <hr class="sep">
    <a href="/profile.php" class="<?= $active === 'profile' ? 'on' : '' ?>"><?= icon('usercog') ?><span>Profile</span></a>
  </div>
  <form method="post" action="/logout.php" class="lo"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?><span>Logout</span></button></form>
</aside>
<main class="wide with-side">
<?php flash_html(); }

function user_end(): void {
    $tg = setting('telegram_url'); $wa = setting('whatsapp_url');
    echo "</main>\n"; // the tab bar and support button must sit outside <main>, otherwise the menu overlay covers them
    $act = $GLOBALS['__active'] ?? '';
    $tabs = [
        'dashboard'    => ['/dashboard.php', 'Home', 'home'],
        'deposits'     => ['/deposits.php', 'Deposit', 'coins'],
        'orders'       => ['/orders.php', 'Orders', 'cart'],
        'referrals'    => ['/referrals.php', 'Referrals', 'users'],
        'transactions' => ['/transactions.php', 'History', 'history'],
        'otp'          => ['/otp.php', 'Gmail', 'mail'],
    ]; ?>
<nav class="tabbar" aria-label="Quick navigation">
<?php foreach ($tabs as $k => $t): ?>
  <a href="<?= $t[0] ?>" class="<?= $k === $act ? 'on' : '' ?>"<?= $k === $act ? ' aria-current="page"' : '' ?>><span class="ico"><?= icon($t[2]) ?></span><span class="tl"><?= e($t[1]) ?></span></a>
<?php endforeach; ?>
</nav>
<?php if ($tg || $wa): ?>
<div class="fab" id="fab">
  <?php if ($tg): ?><a class="fab-i tg" href="<?= e($tg) ?>" target="_blank" rel="noopener"><span class="lbl">Telegram Support</span><b><?= icon('send') ?></b></a><?php endif; ?>
  <?php if ($wa): ?><a class="fab-i wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><span class="lbl">WhatsApp Support</span><b><?= icon('phone') ?></b></a><?php endif; ?>
  <button type="button" class="fab-main" aria-label="Support"><span class="c-chat"><?= icon('chat') ?></span><span class="c-plus"><?= icon('plus') ?></span></button>
</div>
<?php endif;
    footer_html(false);
}
