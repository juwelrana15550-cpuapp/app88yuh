<?php
$__https = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0)
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.gc_maxlifetime', '2592000');
session_set_cookie_params(['lifetime' => 2592000, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $__https]);
session_start();
require_once __DIR__ . '/app_icons.php';

const SITE_NAME = 'MySite';
const SCHEMA_VERSION = '6';

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
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        icon VARCHAR(16) NOT NULL DEFAULT '📦',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");
    // Uploaded stock for instant delivery: one row = one sellable item (a line such as mail|password). order_id NULL = still unsold.
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        line VARCHAR(1000) NOT NULL,
        order_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prod_sold (product_id, order_id, id)
    ) ENGINE=InnoDB");
    // New product columns (stock NULL = unlimited, so existing products keep working). Errors 1060/1061 = already there.
    foreach ([
        "ALTER TABLE products ADD COLUMN category_id INT NULL",
        "ALTER TABLE products ADD COLUMN stock INT NULL",
        "ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT ''",
        "ALTER TABLE products ADD COLUMN popular TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE products ADD COLUMN auto_delivery TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE products ADD INDEX idx_category (category_id)",
        "ALTER TABLE orders ADD COLUMN qty INT NOT NULL DEFAULT 1",
        "ALTER TABLE orders MODIFY delivery MEDIUMTEXT NULL",   // bulk orders can carry thousands of lines
    ] as $sql) {
        try { $pdo->exec($sql); }
        catch (PDOException $ex) { if (!in_array((int)($ex->errorInfo[1] ?? 0), [1060, 1061], true)) throw $ex; }
    }
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_v', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([SCHEMA_VERSION]);
}

/** For instant-delivery products the shown stock is simply the number of unsold uploaded items. */
function stock_sync(PDO $pdo, int $pid): void {
    $pdo->prepare('UPDATE products SET stock = (SELECT COUNT(*) FROM stock_items WHERE product_id = ? AND order_id IS NULL) WHERE id = ?')->execute([$pid, $pid]);
}
function stock_sync_if_auto(PDO $pdo, int $pid): void {
    $st = $pdo->prepare('SELECT auto_delivery FROM products WHERE id = ?'); $st->execute([$pid]);
    if ((int)$st->fetchColumn() === 1) stock_sync($pdo, $pid);
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

/** Browser-tab icon, home-screen icon and theme colour. Uses the admin's uploaded logo for the tab icon when there is one. */
function icon_tags(): string {
    $fav = media_url('logo');
    return '<link rel="icon" href="' . e($fav ?: '/icon.svg') . '"' . ($fav ? '' : ' type="image/svg+xml"') . '>'
        . '<link rel="apple-touch-icon" href="/icon-180.png"><link rel="manifest" href="/manifest.php">'
        . '<meta name="theme-color" content="#4338ca"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">';
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
        'search'  => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'home'    => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'mail'    => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
        'back'    => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'download'=> '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'copy'    => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'box'     => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
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
<?= icon_tags() ?>
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
    <a href="<?= $it[0] ?>" class="<?= $k === $active ? 'on' : '' ?>"><span class="mi"><?= icon($it[2]) ?></span><span><?= e($it[1]) ?></span></a>
  <?php endforeach; ?>
    <hr class="sep">
    <a href="/profile.php" class="<?= $active === 'profile' ? 'on' : '' ?>"><span class="mi"><?= icon('usercog') ?></span><span>Profile</span></a>
  </div>
  <form method="post" action="/logout.php" class="lo"><?= csrf_field() ?><button type="submit"><span class="mi red"><?= icon('logout') ?></span><span>Logout</span></button></form>
</aside>
<main class="wide with-side">
<?php flash_html(); }

function user_end(): void {
    $tg = setting('telegram_url'); $wa = setting('whatsapp_url');
    echo "</main>\n"; // the tab bar and support button must sit outside <main>, otherwise the menu overlay covers them
    echo $GLOBALS['__modal'] ?? '';
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

function catalog_url(array $x): string {
    $x = array_filter($x, fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return '/dashboard.php' . ($x ? '?' . http_build_query($x) : '');
}

/** Product list (search-result line + groups of cards). Also returned on its own to the page's AJAX category switcher. */
function catalog_list_html(array $cats, array $rows, int $c, string $q): void {
    $byId = []; foreach ($cats as $k) $byId[(int)$k['id']] = $k;
    $groups = []; foreach ($rows as $p) $groups[(int)($p['category_id'] ?? 0)][] = $p;
    $card = function (array $p) use ($byId) {
        $k = $byId[(int)($p['category_id'] ?? 0)] ?? null;
        $icon = $k['icon'] ?? '';
        $stock = $p['stock'] === null ? null : (int)$p['stock'];
        if ($stock === null) { $sl = 'In Stock'; $sc = ''; }
        elseif ($stock <= 0) { $sl = 'Out of stock'; $sc = 'out'; }
        elseif ($stock <= 10) { $sl = 'Low stock · ' . number_format($stock) . ' pcs'; $sc = 'low'; }
        else { $sl = 'In Stock · ' . number_format($stock) . ' pcs'; $sc = ''; }
        $out = $stock !== null && $stock <= 0; ?>
    <div class="pc<?= !empty($p['popular']) ? ' pop' : '' ?>">
      <?php if (!empty($p['popular'])): ?><span class="ribbon">★ POPULAR</span><?php endif; ?>
      <div class="pc-h"><span class="pc-ic"><?= cat_icon($icon) ?></span><div class="pc-t"><h4><?= e($p['name']) ?></h4><?php if ($k): ?><span class="pc-c"><?= e($k['name']) ?></span><?php endif; ?></div></div>
      <?php if ($p['description']): ?><p class="pc-d"><?= e($p['description']) ?></p><?php endif; ?>
      <div class="pc-price"><?= money($p['price']) ?><?php if ($p['unit'] !== ''): ?><small> /<?= e($p['unit']) ?></small><?php endif; ?></div>
      <div class="pc-meta"><span class="pid">ID: <?= (int)$p['id'] ?></span><span class="stk <?= $sc ?>"><?= e($sl) ?></span></div>
      <?php if (!empty($p['auto_delivery'])): ?><div class="inst" style="margin:0 0 8px;color:#0f9d6b;font-weight:600;font-size:12.5px">&#9889; Instant delivery</div><?php endif; ?>
      <div class="pc-buy">
        <button type="button" class="btn buy" <?= $out ? 'disabled' : '' ?> data-buy data-id="<?= (int)$p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e(number_format((float)$p['price'], 2, '.', '')) ?>" data-unit="<?= e($p['unit']) ?>" data-stock="<?= $stock === null ? '' : $stock ?>" data-auto="<?= !empty($p['auto_delivery']) ? 1 : 0 ?>"><?= icon('cart') ?><span><?= $out ? 'Sold out' : 'Buy Now' ?></span></button>
      </div>
    </div>
<?php }; ?>
<?php if ($q !== ''): ?><p class="res"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?> for “<?= e($q) ?>” <a href="<?= e(catalog_url(['c' => $c])) ?>">Clear</a></p><?php endif; ?>
<?php foreach ($groups as $cid => $list): $k = $byId[$cid] ?? null; ?>
  <?php if (!$c): ?><div class="sec-h"><span class="ci"><?= cat_icon($k['icon'] ?? '') ?></span><b><?= e($k['name'] ?? 'Other') ?></b><em><?= count($list) ?></em></div><?php endif; ?>
  <div class="catalog"><?php foreach ($list as $p) $card($p); ?></div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card empty"><div class="ei">🔎</div><b>No products found</b><p><?= $q !== '' ? 'Try a different search or category.' : 'Products will appear here once they are added.' ?></p></div><?php endif; ?>
<?php }

/** Home catalog: search box, categories shown directly as tiles (no dropdown), and the product cards. */
function catalog_html(array $cats, array $rows, int $c, string $q, int $total, float $balance = 0.0): void {
    echo app_icon_sprite(); ?>
<style>
/* Category tiles - self-contained, does not depend on style.css */
.sx-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(98px,1fr));gap:10px;margin:14px 0 18px}
.sx-cat{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:6px;padding:12px 6px 10px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;text-align:center;color:#1e293b;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:transform .15s,border-color .15s,box-shadow .15s,background .15s}
.sx-cat:hover{transform:translateY(-2px);border-color:#a5b4fc}
.sx-cat:active{transform:scale(.97)}
.sx-cat .sx-ci{display:flex;align-items:center;justify-content:center;height:34px;font-size:26px;line-height:1;color:#4f46e5}
.sx-cat .sx-ci svg{width:34px;height:34px}
.sx-cat b{font-size:13px;font-weight:600;line-height:1.25;word-break:break-word}
.sx-cat em{font-style:normal;font-size:11px;color:#64748b}
.sx-cat.on{border-color:#4f46e5;background:#eef2ff;box-shadow:0 0 0 3px rgba(79,70,229,.15)}
.sx-cat.on b{color:#3730a3}
#cat-res{scroll-margin-top:72px}
#cat-res.busy{opacity:.55;transition:opacity .15s}
/* Buy Now dialog - self-contained, compact, centered */
.bx{position:fixed;left:0;right:0;top:0;bottom:0;z-index:3000;display:none;align-items:center;justify-content:center;padding:16px}
.bx.open{display:flex}
.bx-bd{position:absolute;left:0;right:0;top:0;bottom:0;background:rgba(15,23,42,.5);opacity:0;transition:opacity .2s}
.bx-sh{position:relative;width:100%;max-width:340px;max-height:calc(100vh - 32px);overflow:auto;background:#fff;color:#1e293b;border-radius:16px;padding:16px;box-shadow:0 20px 50px rgba(15,23,42,.3);opacity:0;transform:scale(.95) translateY(8px);transition:opacity .2s ease,transform .2s ease}
.bx.show .bx-bd{opacity:1}
.bx.show .bx-sh{opacity:1;transform:none}
.bx-h{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.bx-ic{flex:none;display:flex;align-items:center;justify-content:center;width:36px;height:36px;font-size:22px}
.bx-ic svg{width:36px;height:36px}
.bx-t{flex:1;min-width:0}
.bx-t b{display:block;font-size:15px;line-height:1.25;word-break:break-word}
.bx-t small{display:block;color:#64748b;font-size:12px;margin-top:1px}
.bx-x{flex:none;width:28px;height:28px;border:0;border-radius:50%;background:#f1f5f9;color:#475569;font-size:18px;line-height:1;cursor:pointer}
.bx-rows{background:#f8fafc;border-radius:10px;padding:2px 12px;margin-bottom:12px}
.bx-row{display:flex;justify-content:space-between;gap:10px;padding:7px 0;font-size:13px}
.bx-row+.bx-row{border-top:1px solid #e2e8f0}
.bx-row span{color:#64748b}
.bx-l{display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px}
.bx-qty{display:flex;gap:6px;margin-bottom:8px}
.bx-qty button{flex:none;width:38px;height:38px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#1e293b;font-size:18px;cursor:pointer}
.bx-qty button:disabled{opacity:.4;cursor:not-allowed}
.bx-qty input{flex:1;min-width:0;height:38px;text-align:center;border:1px solid #e2e8f0;border-radius:10px;font-size:15px;font-weight:600;background:#fff;color:#1e293b;-moz-appearance:textfield}
.bx-qty input::-webkit-outer-spin-button,.bx-qty input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.bx-qc{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.bx-qc:empty{display:none}
.bx-qc button{padding:4px 11px;border:1px solid #e2e8f0;border-radius:99px;background:#fff;color:#334155;font-size:12px;font-weight:600;cursor:pointer}
.bx-qc button.on{border-color:#4f46e5;background:#eef2ff;color:#3730a3}
.bx-tot{display:flex;justify-content:space-between;align-items:center;font-size:14px;padding:10px 0 2px;border-top:1px dashed #cbd5e1}
.bx-tot b{font-size:18px;color:#4f46e5}
.bx-bal{display:flex;justify-content:space-between;font-size:12px;color:#64748b;padding-bottom:10px}
.bx.low .bx-bal b{color:#dc2626}
.bx-msg{margin:0 0 10px;padding:8px 10px;border-radius:8px;background:#fef2f2;color:#b91c1c;font-size:12.5px}
.bx-msg[hidden]{display:none}
.bx-msg a{color:#4f46e5;font-weight:700;text-decoration:underline}
.bx-f{display:flex;gap:8px}
.bx-btn{flex:1;padding:11px 8px;border:0;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer}
.bx-no{background:#f1f5f9;color:#334155}
.bx-ok{flex:1.5;background:linear-gradient(90deg,#4f46e5,#7c3aed);color:#fff}
.bx-btn:disabled{opacity:.5;cursor:not-allowed}
</style>
<form class="search" id="catF" method="get" action="/dashboard.php">
  <?= icon('search') ?><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search products or ID" autocomplete="off" maxlength="80">
  <?php if ($c): ?><input type="hidden" name="c" value="<?= $c ?>"><?php endif; ?>
  <button>Search</button>
</form>
<div class="sx-cats" id="catRow">
  <a class="sx-cat <?= $c ? '' : 'on' ?>" data-c="0" href="<?= e(catalog_url(['q' => $q])) ?>"><span class="sx-ci"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span><b>All</b><em><?= (int)$total ?> items</em></a>
  <?php foreach ($cats as $k): ?>
  <a class="sx-cat <?= $c === (int)$k['id'] ? 'on' : '' ?>" data-c="<?= (int)$k['id'] ?>" href="<?= e(catalog_url(['c' => (int)$k['id'], 'q' => $q])) ?>"><span class="sx-ci"><?= cat_icon($k['icon'], '34px') ?></span><b><?= e($k['name']) ?></b><em><?= (int)$k['n'] ?> items</em></a>
  <?php endforeach; ?>
</div>
<div id="cat-res" aria-live="polite"><?php catalog_list_html($cats, $rows, $c, $q); ?></div>
<?php ob_start(); buy_modal_html($balance, $c, $q); $GLOBALS['__modal'] = ob_get_clean(); // printed by user_end(), outside <main>, so it sits above the tab bar ?>
<?php }

/** "Buy Now" confirmation sheet: quantity stepper, live total, wallet check. Opened by buttons carrying data-buy. */
function buy_modal_html(float $balance, int $c, string $q): void { ?>
<div class="bx" id="buyM" aria-hidden="true">
  <div class="bx-bd" data-x></div>
  <form method="post" action="/dashboard.php" class="bx-sh" id="buyF" role="dialog" aria-modal="true" aria-labelledby="bmT" autocomplete="off"><?= csrf_field() ?>
    <input type="hidden" name="product_id" id="bmId" value="">
    <input type="hidden" name="c" value="<?= $c ?: '' ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
    <div class="bx-h">
      <span class="bx-ic" id="bmIc"></span>
      <div class="bx-t"><b id="bmT">Product</b><small id="bmSub">Review your order</small></div>
      <button type="button" class="bx-x" data-x aria-label="Close">&times;</button>
    </div>
    <div class="bx-rows">
      <div class="bx-row"><span>Price</span><b id="bmP"></b></div>
      <div class="bx-row"><span>Available</span><b id="bmA"></b></div>
    </div>
    <label class="bx-l" for="bmQ">Quantity</label>
    <div class="bx-qty">
      <button type="button" id="bmMinus" aria-label="Decrease quantity">&minus;</button>
      <input type="number" id="bmQ" name="qty" value="1" min="1" step="1" inputmode="numeric" required>
      <button type="button" id="bmPlus" aria-label="Increase quantity">+</button>
    </div>
    <div class="bx-qc" id="bmChips"></div>
    <div class="bx-tot"><span>Total</span><b id="bmTot"></b></div>
    <div class="bx-bal"><span>Wallet balance</span><b><?= e(money($balance)) ?></b></div>
    <div class="bx-msg" id="bmMsg" role="alert" hidden></div>
    <div class="bx-f">
      <button type="button" class="bx-btn bx-no" data-x>Cancel</button>
      <button type="submit" class="bx-btn bx-ok" id="bmGo">Confirm Purchase</button>
    </div>
  </form>
</div>
<script>
(function(){
  var M=document.getElementById('buyM'); if(!M) return;
  var BAL=<?= json_encode(round($balance, 2)) ?>, CAP=10000;
  var $=function(i){return document.getElementById(i)};
  var F=$('buyF'), Q=$('bmQ'), go=$('bmGo'), msg=$('bmMsg'), cur=null, last=null, hideT=null;
  var fmt=function(n){return '\u09F3'+n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})};
  function maxQty(){ return cur.stock===null ? CAP : Math.max(0, Math.min(cur.stock, CAP)); }
  function showMsg(t,link){
    msg.hidden=!t; msg.innerHTML='';
    if(t){ msg.appendChild(document.createTextNode(t)); if(link){ var a=document.createElement('a'); a.href='/deposits.php'; a.textContent=' Add funds'; msg.appendChild(a); } }
  }
  function render(){
    var raw=parseInt(Q.value,10), q=isNaN(raw)?0:raw, mx=maxQty();
    var cents=Math.round(cur.price*100)*Math.max(q,0), total=cents/100;
    $('bmTot').textContent=fmt(total);
    $('bmMinus').disabled = q<=1;
    $('bmPlus').disabled = q>=mx;
    var bad=null, funds=false;
    if(mx<1) bad='This product is out of stock.';
    else if(q<1) bad='Enter a quantity of at least 1.';
    else if(q>mx) bad=(cur.stock!==null&&q>cur.stock)?'Only '+cur.stock.toLocaleString('en-US')+' available right now.':'You can buy up to '+CAP.toLocaleString('en-US')+' per order.';
    else if(cents>Math.round(BAL*100)){ bad='Not enough balance. You need '+fmt(total-BAL)+' more.'; funds=true; }
    showMsg(bad,funds);
    M.classList.toggle('low', funds);
    go.disabled=!!bad;
    Array.prototype.forEach.call($('bmChips').children,function(b){ b.classList.toggle('on', parseInt(b.dataset.q,10)===q); });
  }
  function addChip(box,n,t){
    var b=document.createElement('button'); b.type='button'; b.dataset.q=n; b.textContent=t;
    b.addEventListener('click',function(){ Q.value=n; render(); }); box.appendChild(b);
  }
  function chips(){
    var box=$('bmChips'), mx=maxQty(); box.innerHTML='';
    [1,5,10,50,100].forEach(function(n){ if(n<=mx) addChip(box,n,String(n)); });
    var afford=cur.price>0?Math.floor(Math.round(BAL*100)/Math.round(cur.price*100)):0, best=Math.min(mx,afford);
    if(best>1 && [1,5,10,50,100].indexOf(best)<0) addChip(box,best,'Max ('+best.toLocaleString('en-US')+')');
  }
  function open(btn){
    last=btn;
    cur={id:btn.dataset.id,name:btn.dataset.name,price:parseFloat(btn.dataset.price)||0,unit:btn.dataset.unit||'',stock:btn.dataset.stock===''?null:parseInt(btn.dataset.stock,10)};
    cur.auto=btn.dataset.auto==='1';
    if(hideT){ clearTimeout(hideT); hideT=null; }
    $('bmId').value=cur.id; $('bmT').textContent=cur.name;
    $('bmSub').textContent=cur.auto?'\u26A1 Instant delivery after purchase':'Review your order';
    $('bmP').textContent=fmt(cur.price)+(cur.unit?' / '+cur.unit:'');
    $('bmA').textContent=cur.stock===null?'In stock':cur.stock.toLocaleString('en-US')+' pcs';
    var card=btn.closest('.pc'), ic=card&&card.querySelector('.pc-ic'); $('bmIc').innerHTML=ic?ic.innerHTML:'';
    Q.value=1; Q.max=maxQty()||''; chips(); go.textContent='Confirm Purchase'; render();
    M.classList.add('open'); M.setAttribute('aria-hidden','false');
    document.documentElement.style.overflow='hidden';
    void M.offsetWidth;                       // force a reflow so the slide-up animation runs
    M.classList.add('show');
    setTimeout(function(){ try{ Q.focus({preventScroll:true}); Q.select(); }catch(e){} }, 150);
  }
  function close(){
    M.classList.remove('show'); M.setAttribute('aria-hidden','true');
    document.documentElement.style.overflow='';
    if(hideT) clearTimeout(hideT);
    hideT=setTimeout(function(){ M.classList.remove('open'); hideT=null; }, 230);
    if(last){ try{ last.focus({preventScroll:true}); }catch(e){} }
  }
  // Delegated click on the document: also works for cards added later by the category switcher.
  document.addEventListener('click',function(e){
    var b=e.target.closest&&e.target.closest('[data-buy]'); if(!b||b.disabled) return;
    e.preventDefault();
    try{ open(b); }
    catch(err){
      if(window.console) console.error(err);
      // last-resort fallback: plain confirm box, then post the order for quantity 1
      if(window.confirm('Buy 1 \u00D7 '+(b.dataset.name||'this product')+'?')){ $('bmId').value=b.dataset.id; Q.value=1; F.submit(); }
    }
  });
  M.querySelectorAll('[data-x]').forEach(function(x){ x.addEventListener('click',close); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape' && M.classList.contains('open')) close(); });
  $('bmMinus').addEventListener('click',function(){ Q.value=Math.max(1,(parseInt(Q.value,10)||1)-1); render(); });
  $('bmPlus').addEventListener('click',function(){ Q.value=Math.min(maxQty(),(parseInt(Q.value,10)||0)+1); render(); });
  Q.addEventListener('input',render);
  F.addEventListener('submit',function(e){
    if(!cur){ e.preventDefault(); return; }
    render(); if(go.disabled){ e.preventDefault(); return; }
    go.disabled=true; go.textContent='Processing\u2026';   // stops double-clicks from buying twice
  });
  window.addEventListener('pageshow',function(ev){
    if(ev.persisted){ M.classList.remove('open','show'); document.documentElement.style.overflow=''; go.disabled=false; go.textContent='Confirm Purchase'; }
  });
})();
</script>
<script>
/* Category tiles + search: swap the product list in place (no full page load) */
(function(){
  var res=document.getElementById('cat-res'), row=document.getElementById('catRow'), form=document.getElementById('catF'), buy=document.getElementById('buyF');
  if(!res||!row||!form||!window.fetch) return;   // without fetch the tiles are normal links and still work
  var qIn=form.elements.q, state={c:0,q:''}, ctl=null, seq=0, tmo=null;
  var on=row.querySelector('.sx-cat.on'); state.c=on?parseInt(on.dataset.c,10)||0:0; state.q=qIn.value.trim();
  function qs(c,q){ var p=[]; if(c) p.push('c='+c); if(q) p.push('q='+encodeURIComponent(q)); return p.join('&'); }
  function sync(){
    Array.prototype.forEach.call(row.children,function(a){ a.classList.toggle('on',(parseInt(a.dataset.c,10)||0)===state.c); });
    var h=form.elements.c; if(state.c){ if(!h){ h=document.createElement('input'); h.type='hidden'; h.name='c'; form.appendChild(h); } h.value=state.c; } else if(h){ h.remove(); }
    if(buy){ buy.elements.c.value=state.c||''; buy.elements.q.value=state.q; }
  }
  function toResults(){ try{ res.scrollIntoView({behavior:'smooth',block:'start'}); }catch(e){} }
  function load(c,q,push,scroll){
    state.c=c; state.q=q; qIn.value=q; sync();
    var s=qs(c,q), url='/dashboard.php'+(s?'?'+s:'');
    if(push!==false) try{ history.pushState({c:c,q:q},'',url); }catch(e){}
    var my=++seq, timedOut=false;
    if(ctl) ctl.abort(); ctl=window.AbortController?new AbortController():null;
    clearTimeout(tmo); tmo=setTimeout(function(){ timedOut=true; if(ctl) ctl.abort(); else if(my===seq) location.href=url; }, 12000);
    res.classList.add('busy'); res.setAttribute('aria-busy','true');
    fetch('/dashboard.php?'+(s?s+'&':'')+'ajax=1',{credentials:'same-origin',headers:{'X-Requested-With':'fetch'},signal:ctl?ctl.signal:undefined})
      .then(function(r){ if(!r.ok) throw new Error(r.status); return r.text(); })
      .then(function(h){
        if(my!==seq) return;
        clearTimeout(tmo); res.innerHTML=h; res.classList.remove('busy'); res.removeAttribute('aria-busy');
        if(scroll) toResults();
      })
      .catch(function(e){ if(my!==seq) return; if(e&&e.name==='AbortError'&&!timedOut) return; clearTimeout(tmo); location.href=url; });
  }
  row.addEventListener('click',function(e){
    var a=e.target.closest('.sx-cat'); if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.button) return;
    e.preventDefault(); var c=parseInt(a.dataset.c,10)||0;
    if(c===state.c&&qIn.value.trim()===state.q){ toResults(); return; }
    load(c,qIn.value.trim(),true,true);
  });
  form.addEventListener('submit',function(e){ e.preventDefault(); load(state.c,qIn.value.trim(),true,true); });
  res.addEventListener('click',function(e){ var a=e.target.closest('.res a'); if(!a) return; e.preventDefault(); load(state.c,'',true,false); });
  window.addEventListener('popstate',function(){
    var p=new URLSearchParams(location.search); load(parseInt(p.get('c'),10)||0,(p.get('q')||'').slice(0,80),false,false);
  });
})();
</script>
<?php }
