<?php
$__https = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0)
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.gc_maxlifetime', '2592000');
session_set_cookie_params(['lifetime' => 2592000, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $__https]);
session_start();
// Currency switch: /any-page?cur=usd|bdt remembers the choice in a cookie, then returns to the same page without the parameter.
if (isset($_GET['cur']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && in_array($_GET['cur'], ['bdt', 'usd'], true)) {
    setcookie('cur', $_GET['cur'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => $__https]);
    $__qs = $_GET; unset($__qs['cur']);
    $__path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($__path === '' || $__path[0] !== '/' || strncmp($__path, '//', 2) === 0) $__path = '/';
    header('Location: ' . $__path . ($__qs ? '?' . http_build_query($__qs) : ''));
    exit;
}
require_once __DIR__ . '/app_icons.php';

const SITE_NAME = 'MySite';
const SCHEMA_VERSION = '17';
require_once __DIR__ . '/supplier.php';   // supplier API (Admin > API Management)
maintenance_gate();   // shows the maintenance page to visitors while the admin has maintenance mode ON (admin.php is never blocked)

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
    if ($ver !== SCHEMA_VERSION) {
        try { migrate($pdo); }
        catch (Throwable $ex) { error_log('MIGRATION FAILED: ' . $ex->getMessage()); }   // logged (was silent); retried on the next request
    }
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
    // Discounts the admin gives one user. product_id 0 = every product. percent is 0.01 - 99.
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_discounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL DEFAULT 0,
        percent DECIMAL(5,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_prod (user_id, product_id)
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
    $migFail = false;
    // New product columns (stock NULL = unlimited, so existing products keep working). Errors 1060/1061 = already there.
    foreach ([
        "ALTER TABLE products ADD COLUMN category_id INT NULL",
        "ALTER TABLE products ADD COLUMN stock INT NULL",
        "ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT ''",
        "ALTER TABLE products ADD COLUMN popular TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE products ADD COLUMN auto_delivery TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE products ADD INDEX idx_category (category_id)",
        "ALTER TABLE products ADD COLUMN icon VARCHAR(16) NOT NULL DEFAULT ''",   // optional per-product app icon ('' = automatic)
        "ALTER TABLE orders ADD COLUMN qty INT NOT NULL DEFAULT 1",
        "ALTER TABLE users ADD COLUMN banned TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE products ADD COLUMN discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0",   // product-wide discount for everyone   // 1 = account suspended by admin
        "ALTER TABLE orders MODIFY delivery MEDIUMTEXT NULL",   // bulk orders can carry thousands of lines
        "ALTER TABLE products ADD COLUMN supplier_id INT NULL",          // bulkmail.shop product ID (NULL = normal product)
        "ALTER TABLE products ADD INDEX idx_supplier (supplier_id)",
        "ALTER TABLE products ADD COLUMN sup_auto_name TINYINT(1) NOT NULL DEFAULT 0",   // 1 = name follows bulkmail.shop automatically (existing products stay 0 = untouched)
        "ALTER TABLE products ADD COLUMN sup_auto_desc TINYINT(1) NOT NULL DEFAULT 0",   // 1 = description (+ possible domains) follows bulkmail.shop
        "ALTER TABLE orders ADD COLUMN supplier_order_id VARCHAR(40) NULL",   // 'sending' = sent to bulkmail.shop, answer not known yet
        "ALTER TABLE products ADD COLUMN sup_show_dom TINYINT(1) NOT NULL DEFAULT 1",   // 1 = show the "Possible Domain (...)" line on the product card
        "ALTER TABLE products ADD COLUMN sup_domain VARCHAR(255) NULL",   // "Possible Domain" list copied from bulkmail.shop (refreshed automatically)
        "ALTER TABLE products ADD COLUMN sup_stock INT NOT NULL DEFAULT 0",
        "ALTER TABLE products ADD COLUMN sup_auto_price TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE products ADD COLUMN card_style VARCHAR(12) NOT NULL DEFAULT ''",   // '' = classic card, 'midnight' = dark card with Key: Value rows
        "ALTER TABLE products ADD COLUMN sup_markup DECIMAL(7,2) NOT NULL DEFAULT 0",   // live stock at bulkmail.shop (products.stock = sup_stock + unsold local items)
    ] as $sql) {
        try { $pdo->exec($sql); }
        catch (PDOException $ex) {
            if (!in_array((int)($ex->errorInfo[1] ?? 0), [1060, 1061], true)) { error_log('migrate: ' . $ex->getMessage() . ' | ' . $sql); $migFail = true; }
        }
    }
    // Product display order (smaller shows first). Added once; existing products start in id order so nothing jumps around.
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE products SET sort_order = id * 10");
    } catch (PDOException $ex) { if ((int)($ex->errorInfo[1] ?? 0) !== 1060) throw $ex; }
    // One-time: linked products used to keep the API number in `stock`; copy it to sup_stock (no local items exist yet at this point).
    try { $pdo->exec('UPDATE products SET sup_stock = stock WHERE supplier_id IS NOT NULL AND sup_stock = 0 AND stock > 0'); } catch (Throwable $ex) {}
    // One-time default categories, added only if the shop has none yet. Rename / hide / delete them any time in Admin > Categories.
    try {
        if (!$pdo->query("SELECT COUNT(*) FROM settings WHERE k = 'cats_seeded'")->fetchColumn()) {
            if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
                $ins = $pdo->prepare('INSERT INTO categories (name, icon, sort_order) VALUES (?,?,?)');
                foreach ([
                    ['Gmail', 'app:gmail'], ['Facebook', 'app:facebook'], ['Instagram', 'app:instagram'],
                    ['TikTok', 'app:tiktok'], ['Telegram', 'app:telegram'], ['WhatsApp', 'app:whatsapp'],
                    ['YouTube', 'app:youtube'], ['Netflix', 'app:netflix'], ['Spotify', 'app:spotify'],
                    ['VPN', 'app:shield'], ['Gift Card', 'app:gift'], ['Premium Tools', 'app:crown'],
                ] as $i => [$n, $ic]) $ins->execute([$n, $ic, ($i + 1) * 10]);
            }
            $pdo->exec("INSERT INTO settings (k, v) VALUES ('cats_seeded', '1') ON DUPLICATE KEY UPDATE v = VALUES(v)");
        }
    } catch (Throwable $ex) { error_log('Default categories: ' . $ex->getMessage()); }
    if ($migFail) { error_log('migrate: not all steps worked, schema_v not saved (will retry)'); return; }
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_v', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([SCHEMA_VERSION]);
}

/**
 * Shown stock = unsold local items (stock_items) + live bulkmail.shop stock (sup_stock, only for linked products).
 * For plain instant-delivery products that is simply the number of unsold uploaded items.
 */
function stock_sync(PDO $pdo, int $pid): void {
    $pdo->prepare('UPDATE products SET stock = (SELECT COUNT(*) FROM stock_items WHERE product_id = products.id AND order_id IS NULL) + IF(supplier_id IS NOT NULL, sup_stock, 0) WHERE id = ?')->execute([$pid]);
}
function stock_sync_if_auto(PDO $pdo, int $pid): void {
    $st = $pdo->prepare('SELECT auto_delivery, supplier_id FROM products WHERE id = ?'); $st->execute([$pid]);
    $r = $st->fetch();
    if ($r && ((int)$r['auto_delivery'] === 1 || $r['supplier_id'] !== null)) stock_sync($pdo, $pid);
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
/** Dollar rate set by the admin: how many BDT equal 1 USD. 0 = not set (the $ option is hidden). */
function usd_rate(): float { $r = (float)setting('usd_rate', '0'); return $r > 0 ? $r : 0.0; }
/** Currency to show: 'usd' only if the user picked it AND the admin has set a rate. The admin panel always shows BDT. */
function cur_code(): string {
    if (!empty($GLOBALS['__bdt_only'])) return 'bdt';
    return (($_COOKIE['cur'] ?? '') === 'usd' && usd_rate() > 0) ? 'usd' : 'bdt';
}
/** Amounts are always stored and charged in BDT; this only changes how they are displayed. */
function money($n): string {
    $v = (float)$n;
    if (cur_code() === 'usd') {
        $u = $v / usd_rate(); $a = abs($u);
        $d = $a == 0 ? 2 : ($a < 0.1 ? 4 : ($a < 1 ? 3 : 2));   // tiny dollar amounts keep extra decimals
        return '$' . number_format($u, $d);
    }
    return '৳' . number_format($v, 2);
}


/* ---------- discounts ---------- */
function pct_fmt(float $p): string { return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.'); }
/** Discounts the admin gave one user: [0 => % on all products, productId => % on that product]. */
function user_disc_map(int $uid): array {
    static $m = [];
    if ($uid <= 0) return [];
    if (!isset($m[$uid])) {
        $m[$uid] = [];
        try {
            $st = db()->prepare('SELECT product_id, percent FROM user_discounts WHERE user_id = ?'); $st->execute([$uid]);
            foreach ($st as $r) $m[$uid][(int)$r['product_id']] = (float)$r['percent'];
        } catch (Throwable $ex) {}
    }
    return $m[$uid];
}
/** Discount that applies: the biggest of the product's own %, the user's all-products % and the user's % on this product (never added together). */
function discount_pct(array $p, array $ud): float {
    return min(99.0, max(0.0, (float)($p['discount_pct'] ?? 0), (float)($ud[0] ?? 0), (float)($ud[(int)$p['id']] ?? 0)));
}
/** Unit price in cents after a percentage discount (never below 1 cent). The shop and the checkout both use this, so they always agree. */
function final_cents(int $cents, float $pct): int { return $pct > 0 ? max(1, (int)round($cents * (100 - $pct) / 100)) : $cents; }
function final_price(float $price, float $pct): float { return final_cents((int)round($price * 100), $pct) / 100; }

function setting(string $k, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (db()->query('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v']; }
        catch (Throwable $ex) { error_log('setting() DB error: ' . $ex->getMessage()); }
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

/* ---------- custom image icons (categories / products) ----------
 * Stored in the `media` table under the key "ic_<12 hex>" and referenced as "img:<12 hex>" (16 chars, fits the icon columns).
 * Served by /icon.php. Every upload is re-drawn as a 128x128 PNG (when GD is available) so all custom icons look the same size. */
const ICON_PX = 128;
function icon_is_img(string $v): bool { return (bool)preg_match('/^img:[a-f0-9]{12}$/', $v); }
function icon_img_exists(string $v): bool {
    if (!icon_is_img($v)) return false;
    $st = db()->prepare('SELECT 1 FROM media WHERE k = ?'); $st->execute(['ic_' . substr($v, 4)]);
    return (bool)$st->fetchColumn();
}
/** Takes one $_FILES entry. Returns [icon value or '' when nothing was uploaded, error message or '']. */
function icon_save_upload(?array $f): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['', ''];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1048576) return ['', 'Icon image is too large (max 1 MB).'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) return ['', 'Icon must be a PNG, JPG, WEBP or GIF image.'];
    if ($info[0] * $info[1] > 25000000) return ['', 'Icon image has too many pixels. Please use a smaller image.'];
    $bin = file_get_contents($f['tmp_name']); $mime = $info['mime'];
    if (function_exists('imagecreatefromstring') && function_exists('imagepng') && ($src = @imagecreatefromstring($bin))) {
        $w = imagesx($src); $h = imagesy($src); $s = ICON_PX;
        $dst = imagecreatetruecolor($s, $s);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        $r = min($s / $w, $s / $h); $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r));
        imagecopyresampled($dst, $src, (int)(($s - $nw) / 2), (int)(($s - $nh) / 2), 0, 0, $nw, $nh, $w, $h);   // whole image kept, centred on a transparent square
        ob_start(); imagepng($dst, null, 6); $png = ob_get_clean();
        if ($png) { $bin = $png; $mime = 'image/png'; }
    }
    $h12 = substr(sha1($bin), 0, 12);
    $st = db()->prepare('REPLACE INTO media (k, mime, data) VALUES (?,?,?)');
    $st->bindValue(1, 'ic_' . $h12); $st->bindValue(2, $mime); $st->bindValue(3, $bin, PDO::PARAM_LOB);
    $st->execute();
    return ['img:' . $h12, ''];
}
/** Removes uploaded icon images that no product or category uses any more. */
function icon_gc(PDO $pdo): void {
    try {
        $pdo->exec("DELETE FROM media WHERE k LIKE 'ic\\_%' AND CONCAT('img:', SUBSTRING(k, 4)) NOT IN (SELECT icon FROM products) AND CONCAT('img:', SUBSTRING(k, 4)) NOT IN (SELECT icon FROM categories)");
    } catch (Throwable $ex) { error_log('icon_gc: ' . $ex->getMessage()); }
}
/** Icon markup: custom image, app icon (SVG) or emoji. $size = CSS size such as '14px'; null = fill the parent / default size. */
function ico_html(string $v, ?string $size = null): string {
    if (icon_is_img($v)) {
        $st = $size !== null ? ' style="width:' . e($size) . ';height:' . e($size) . '"' : '';
        return '<img class="ico-img" src="/icon.php?k=ic_' . substr($v, 4) . '" alt="" loading="lazy" decoding="async"' . $st . '>';
    }
    return $size === null ? cat_icon($v) : cat_icon($v, $size);
}
function ico_text(string $v): string { return icon_is_img($v) ? '🖼️' : cat_icon_text($v); }
function ico_label(string $v): string { return icon_is_img($v) ? 'Custom image' : cat_icon_label($v); }

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

/** Maintenance mode. Visitors get a 503 page; the admin panel, media and manifest stay reachable, and so does anyone logged into admin. */
function maintenance_gate(): void {
    if (PHP_SAPI === 'cli') return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // Add any endpoint that must keep working during maintenance (e.g. an OTP / payment webhook) to this list.
    if (in_array($script, ['admin.php', 'media.php', 'icon.php', 'manifest.php', 'sms.php'], true)) return;
    if (!empty($_SESSION['admin'])) return;
    if (setting('maintenance_on', '0') !== '1') return;
    $msg = setting('maintenance_msg', 'We are doing some scheduled maintenance. Please check back soon.');
    $eta = setting('maintenance_eta', '');
    http_response_code(503);
    header('Retry-After: 1800'); header('Cache-Control: no-store');
    if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false || strncmp($script, 'api', 3) === 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'maintenance', 'message' => $msg], JSON_UNESCAPED_UNICODE); exit;
    }
    $lg = media_url('logo'); $tg = setting('telegram_url'); $wa = setting('whatsapp_url'); ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<meta http-equiv="refresh" content="60">
<script>try{if(localStorage.getItem('sx_theme')==='dark')document.documentElement.classList.add('sx-dark')}catch(e){}</script>
<title>Maintenance - <?= e(site_name()) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:linear-gradient(135deg,#eef2ff,#f5f3ff);color:#1e293b;font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Bengali","Hind Siliguri",sans-serif}
.box{width:100%;max-width:440px;background:#fff;border-radius:20px;padding:32px 26px;text-align:center;box-shadow:0 20px 50px -20px rgba(67,56,202,.35)}
.box img{width:64px;height:64px;border-radius:16px;object-fit:cover}.ic{font-size:52px;line-height:1}
h1{margin:14px 0 8px;font-size:23px}p{margin:0 0 14px;color:#475569;word-break:break-word}
.eta{display:inline-block;margin-bottom:16px;padding:8px 14px;border-radius:99px;background:#eef2ff;color:#3730a3;font-size:14px}
.sup{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:16px}.sup a{padding:8px 14px;border-radius:10px;background:#f1f5f9;color:#334155;text-decoration:none;font-weight:600;font-size:14px}
button{display:block;width:100%;padding:12px;border:0;border-radius:12px;background:linear-gradient(90deg,#4f46e5,#7c3aed);color:#fff;font:inherit;font-weight:600;cursor:pointer}
small{display:block;margin-top:12px;color:#94a3b8}
html.sx-dark{color-scheme:dark}html.sx-dark body{background:linear-gradient(135deg,#0f172a,#1e1b4b);color:#e2e8f0}
html.sx-dark .box{background:#1e293b;box-shadow:0 20px 50px -20px rgba(0,0,0,.7)}html.sx-dark p{color:#94a3b8}
html.sx-dark .eta{background:rgba(129,140,248,.16);color:#c7d2fe}html.sx-dark .sup a{background:#334155;color:#e2e8f0}
</style></head><body>
<div class="box">
<?php if ($lg): ?><img src="<?= e($lg) ?>" alt=""><?php else: ?><div class="ic">🛠️</div><?php endif; ?>
<h1>We'll be right back</h1>
<p><?= nl2br(e($msg)) ?></p>
<?php if ($eta !== ''): ?><div class="eta">Expected back: <b><?= e($eta) ?></b></div><?php endif; ?>
<?php if ($tg || $wa): ?><div class="sup"><?php if ($tg): ?><a href="<?= e($tg) ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?><?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></div><?php endif; ?>
<button type="button" onclick="location.reload()">Refresh</button>
<small>This page refreshes by itself every minute.</small>
</div></body></html>
<?php exit; }

/** Admin notice banner (Admin > Notice & Maintenance). Shown on public pages and the user dashboard. */
function notice_html(): void {
    if (setting('notice_on', '0') !== '1') return;
    $t = trim(setting('notice_text', ''));
    if ($t === '') return;
    $ty = setting('notice_type', 'info');
    if (!in_array($ty, ['info', 'success', 'warning', 'danger'], true)) $ty = 'info';
    $ic = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'danger' => '🚨'][$ty];
    $close = setting('notice_dismiss', '1') === '1'; ?>
<div class="nt nt-<?= $ty ?>" id="sxNotice" role="status" data-v="<?= e(setting('notice_v', '1')) ?>"><span class="nt-i"><?= $ic ?></span><div class="nt-t"><?= nl2br(e($t)) ?></div><?php if ($close): ?><button type="button" class="nt-x" aria-label="Close notice">&times;</button><?php endif; ?></div>
<script>(function(){var n=document.getElementById('sxNotice');if(!n)return;var k='sxn_'+n.getAttribute('data-v');try{if(localStorage.getItem(k)){n.parentNode.removeChild(n);return;}}catch(e){}var b=n.querySelector('.nt-x');if(b)b.addEventListener('click',function(){n.parentNode.removeChild(n);try{localStorage.setItem(k,'1');}catch(e){}});})();</script>
<?php }

/** Update / notice POPUP (Admin > Site settings > Update notice popup). A centred window with an OK button; shown once per update per browser. */
function notice_popup_html(): void {
    if (setting('popup_on', '0') !== '1') return;
    $msg = trim(setting('popup_text', ''));
    if ($msg === '') return;
    $title = trim(setting('popup_title', '')); if ($title === '') $title = 'Notice';
    $btn = trim(setting('popup_btn', '')); if ($btn === '') $btn = 'OK';
    $ty = setting('popup_type', 'info');
    if (!in_array($ty, ['info', 'success', 'warning', 'danger'], true)) $ty = 'info';
    $ic = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'danger' => '🚨'][$ty];
    $accent = ['info' => '#4f46e5', 'success' => '#16a34a', 'warning' => '#d97706', 'danger' => '#dc2626'][$ty]; ?>
<style>
.sxp-ov{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.55);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}
.sxp-ov.show{display:flex;animation:sxpF .18s ease-out}
.sxp-box{width:100%;max-width:420px;max-height:86vh;display:flex;flex-direction:column;background:#fff;color:#1e293b;border-radius:18px;box-shadow:0 25px 60px -12px rgba(0,0,0,.45);overflow:hidden;animation:sxpU .22s ease-out}
.sxp-bar{height:5px;background:var(--sxp,#4f46e5);flex:none}
.sxp-h{display:flex;align-items:center;gap:10px;padding:18px 20px 6px;flex:none}
.sxp-h i{font-style:normal;font-size:1.5rem;line-height:1}
.sxp-h h3{margin:0;font-size:1.12rem;font-weight:800;line-height:1.3;color:#0f172a}
.sxp-b{padding:6px 20px 14px;overflow:auto;font-size:.98rem;line-height:1.6;word-break:break-word;-webkit-overflow-scrolling:touch}
.sxp-f{padding:6px 20px 20px;flex:none}
.sxp-ok{display:block;width:100%;border:0;border-radius:12px;padding:12px 16px;font:inherit;font-weight:700;font-size:1rem;color:#fff;background:var(--sxp,#4f46e5);cursor:pointer}
.sxp-ok:hover{filter:brightness(1.08)}.sxp-ok:focus-visible{outline:3px solid rgba(79,70,229,.35);outline-offset:2px}
body.sxp-lock{overflow:hidden}
@keyframes sxpF{from{opacity:0}to{opacity:1}}@keyframes sxpU{from{transform:translateY(14px) scale(.97);opacity:0}to{transform:none;opacity:1}}
</style>
<div class="sxp-ov" id="sxPop" role="dialog" aria-modal="true" aria-labelledby="sxpT" data-v="<?= e(setting('popup_v', '1')) ?>" style="--sxp:<?= $accent ?>">
  <div class="sxp-box"><div class="sxp-bar"></div>
    <div class="sxp-h"><i><?= $ic ?></i><h3 id="sxpT"><?= e($title) ?></h3></div>
    <div class="sxp-b"><?= nl2br(e($msg)) ?></div>
    <div class="sxp-f"><button type="button" class="sxp-ok" id="sxpOk"><?= e($btn) ?></button></div>
  </div>
</div>
<script>(function(){var o=document.getElementById('sxPop');if(!o)return;var k='sxp_'+o.getAttribute('data-v');
try{if(localStorage.getItem(k)==='1'){o.parentNode.removeChild(o);return;}}catch(e){}
document.body.appendChild(o);document.body.classList.add('sxp-lock');o.classList.add('show');
var b=document.getElementById('sxpOk');try{b.focus();}catch(e){}
function close(){try{localStorage.setItem(k,'1');}catch(e){}document.body.classList.remove('sxp-lock');if(o.parentNode)o.parentNode.removeChild(o);document.removeEventListener('keydown',kd,true);}
function kd(ev){if(ev.key==='Escape'||ev.key==='Enter'){ev.preventDefault();ev.stopPropagation();close();}}
b.addEventListener('click',close);document.addEventListener('keydown',kd,true);})();</script>
<?php }

function icon(string $n): string {
    static $p = [
        'user'    => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
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
        'headset' => '<path d="M3 11h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5Zm0 0a9 9 0 1 1 18 0m0 0v5a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3Z"/><path d="M21 16v2a4 4 0 0 1-4 4h-5"/>',
        'plus'    => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'search'  => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'home'    => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'live'    => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
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
    $r = $s->fetch();
    if ($r && !empty($r['banned'])) {   // suspended by admin: sign out right away
        unset($_SESSION['uid']);
        flash('err', 'Your account has been suspended. Please contact support.');
        return null;
    }
    return $r ?: null;
}
function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: /login.php'); exit; }
    return $u;
}

/**
 * Night mode for everything that carries its own inline colours (catalog, buy dialog, popups, bottom bar,
 * deposits, Get OTP card ...). Every rule starts with html.sx-dark, so it beats the light rules without
 * !important and only applies while the Night switch is on. Pages that live in style.css keep using style.css.
 */
function dark_css(): string { return <<<'CSS'
<style id="sxDark">
/* ---- palette used below: page #0f172a · surface #1e293b · inset #162033 · line #2a3650/#334155 · text #e2e8f0 · muted #94a3b8 ---- */
html.sx-dark .sx-tm{background:rgba(129,140,248,.12);border-color:rgba(129,140,248,.22);color:#cbd5e1}
html.sx-dark .top .brand .bn b{color:#e0e7ff}

/* Bottom bar + support button */
html.sx-dark body .tabbar{background:rgba(15,23,42,.8);border-color:rgba(129,140,248,.22);box-shadow:0 12px 32px -12px rgba(0,0,0,.7),inset 0 1px 0 rgba(255,255,255,.05)}
html.sx-dark body .tabbar a{color:#94a3b8}
html.sx-dark body .tabbar a:hover{color:#c7d2fe}
html.sx-dark body .tabbar a.on{background:rgba(129,140,248,.16);color:#c7d2fe;box-shadow:inset 0 0 0 1px rgba(129,140,248,.22)}
html.sx-dark body .tabbar a.dep{color:#34d399}
html.sx-dark body .tabbar a.dep .tl{color:#34d399}
html.sx-dark body .tabbar a.dep .ico{box-shadow:0 10px 20px -6px rgba(5,150,105,.6),0 0 0 4px rgba(15,23,42,.9)}
html.sx-dark body .fab .fab-i b{background:rgba(30,41,59,.92);border-color:rgba(148,163,184,.25);box-shadow:0 8px 18px -8px rgba(0,0,0,.7)}

/* Notice popup */
html.sx-dark .sxp-box{background:#1e293b;color:#e2e8f0;box-shadow:0 25px 60px -12px rgba(0,0,0,.8)}
html.sx-dark .sxp-h h3{color:#f1f5f9}

/* Category chips */
html.sx-dark .sx-cat:not(.on){background:#1e293b;border-color:#334155;color:#cbd5e1;box-shadow:none}
html.sx-dark .sx-cat:not(.on):hover{border-color:#6366f1;color:#c7d2fe}
html.sx-dark .sx-cat:not(.on):active{background-color:#27324b;border-color:#6366f1}
html.sx-dark .sx-cat:not(.on) .sx-ci{background:#334155}

/* Product cards (classic style; the Midnight style is already dark) */
html.sx-dark .sx-stk{background:rgba(22,163,74,.16);color:#4ade80;border-color:rgba(74,222,128,.3)}
html.sx-dark .sx-stk.low{background:rgba(245,158,11,.16);color:#fbbf24;border-color:rgba(251,191,36,.3)}
html.sx-dark .sx-stk.out{background:rgba(220,38,38,.16);color:#f87171;border-color:rgba(248,113,113,.3)}
html.sx-dark .sx-pid{background:#27324b;color:#94a3b8}
html.sx-dark .catalog .pc:not(.pc-mid) .pc-dl li{border-bottom-color:#2a3650!important}
html.sx-dark .catalog .pc:not(.pc-mid) .pc-dom{color:#fbbf24}
html.sx-dark .catalog .pc:not(.pc-mid) .inst{color:#34d399}

/* Loading skeleton */
html.sx-dark .sx-ld{color:#a5b4fc}
html.sx-dark .sx-sp{border-color:#334155;border-top-color:#818cf8}
html.sx-dark .sx-sk .pc{background:#1e293b;border-color:#2a3650}
html.sx-dark .sx-b{background:linear-gradient(90deg,#27324b 0,#334155 100px,#27324b 200px);background-size:400px 100%}

/* Buy Now dialog */
html.sx-dark .bx-bd{background:rgba(2,6,23,.65)}
html.sx-dark .bx-sh{background:#1e293b;color:#e2e8f0;box-shadow:0 20px 50px rgba(0,0,0,.6)}
html.sx-dark .bx-t small,html.sx-dark .bx-row span,html.sx-dark .bx-bal{color:#94a3b8}
html.sx-dark .bx-x{background:#334155;color:#cbd5e1}
html.sx-dark .bx-rows{background:#162033}
html.sx-dark .bx-row+.bx-row{border-top-color:#2a3650}
html.sx-dark .bx-l{color:#cbd5e1}
html.sx-dark .bx-qty button,html.sx-dark .bx-qty input{background:#162033;border-color:#334155;color:#e2e8f0}
html.sx-dark .bx-qc button{background:#162033;border-color:#334155;color:#cbd5e1}
html.sx-dark .bx-qc button.on{background:rgba(99,102,241,.22);border-color:#818cf8;color:#c7d2fe}
html.sx-dark .bx-tot{border-top-color:#334155}
html.sx-dark .bx-tot b{color:#a5b4fc}
html.sx-dark .bx.low .bx-bal b{color:#f87171}
html.sx-dark .bx-msg{background:rgba(220,38,38,.14);color:#fca5a5}
html.sx-dark .bx-msg a{color:#a5b4fc}
html.sx-dark .bx-no{background:#334155;color:#e2e8f0}
html.sx-dark .bx-ld{background:rgba(30,41,59,.96)}
html.sx-dark .bx-ld b{color:#e2e8f0}
html.sx-dark .bx-ld small{color:#94a3b8}

/* Deposits page: the whole .dp block runs on variables, so redefining them is enough */
html.sx-dark .dp{--bg:#162033;--bg2:#1e293b;--ink:#e2e8f0;--mute:#94a3b8;--line:#2a3650;--acc:#7c6cff;--ok:#34d399;--warn:#fbbf24;--bad:#f87171;--sh:0 1px 2px rgba(0,0,0,.4),0 4px 14px rgba(0,0,0,.3)}
html.sx-dark .dp .dp-mth.on{background:color-mix(in srgb,var(--c) 18%,#162033)}
html.sx-dark .dp .dp-usd{color:#34d399;background:rgba(52,211,153,.1);border-color:rgba(52,211,153,.25)}
html.sx-dark .dp .dp-usd small{color:#6ee7b7}
/* Deposit result popup (sits outside .dp, so its colours are set directly) */
html.sx-dark .dp-ok{background:#1e293b;color:#e2e8f0;box-shadow:0 24px 60px rgba(0,0,0,.7)}
html.sx-dark .dp-ok .sub,html.sx-dark .dp-ok .rows span{color:#94a3b8}
html.sx-dark .dp-ok .sub b{color:#e2e8f0}
html.sx-dark .dp-ok .big{color:#34d399}
html.sx-dark .dp-ok .rows{background:#162033;border-color:#2a3650}
html.sx-dark .dp-ok .rows div{border-bottom-color:#2a3650}
html.sx-dark .dp-ok .badge2{color:#fbbf24}
html.sx-dark .dp-ok button.ghost{background:#27324b;color:#e2e8f0;border-color:#334155}
html.sx-dark .dp-ok .lock{color:#64748b}
html.sx-dark .stp{background:#162033;border-color:#2a3650}
html.sx-dark .stp li{color:#64748b}
html.sx-dark .stp li i{border-color:#475569}
html.sx-dark .stp li.on{color:#e2e8f0}
html.sx-dark .stp li.on i{border-color:#818cf8;border-top-color:transparent}
html.sx-dark .stp li.done{color:#34d399}
html.sx-dark .stp li.done i{background:#12a36b;border-color:#12a36b}
html.sx-dark .spin{border-color:rgba(129,140,248,.2);border-top-color:#818cf8}
html.sx-dark .spin-ic svg{stroke:#818cf8}

/* Get OTP card */
html.sx-dark .otp-card{background:#1e293b;color:#e2e8f0;box-shadow:0 4px 20px rgba(0,0,0,.45)}
html.sx-dark .otp-h small,html.sx-dark .otp-meta .mask{color:#94a3b8}
html.sx-dark .otp-ta{background:#162033;border-color:#334155;color:#e2e8f0}
html.sx-dark .otp-ta::placeholder{color:#64748b}
html.sx-dark .otp-ta:focus{background:#0f172a;border-color:#818cf8;box-shadow:0 0 0 4px rgba(129,140,248,.18)}
html.sx-dark .otp-meta .cnt{color:#64748b}
html.sx-dark .otp-note{background:rgba(220,38,38,.14);border-color:rgba(248,113,113,.35);color:#fca5a5}
html.sx-dark .otp-info{background:linear-gradient(180deg,rgba(99,102,241,.14),rgba(124,58,237,.1));border-color:rgba(129,140,248,.25)}
html.sx-dark .otp-info h4{color:#c7d2fe}
html.sx-dark .otp-info li{color:#cbd5e1}
html.sx-dark .otp-code,html.sx-dark .otp-host{background:#162033;border-color:#334155;color:#a5b4fc}
</style>
CSS;
}

function page_head(string $title, string $bodyClass): void { ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e(site_name()) ?></title>
<?= css_link() ?>
<?= icon_tags() ?>
<script>try{if(localStorage.getItem('sx_theme')==='dark')document.documentElement.classList.add('sx-dark')}catch(e){}</script>
<script>
/* Smooth-nav support: remembers timers/listeners a page registers so they can be cleaned up when the next page is swapped in */
(function(){var W=window,S=W.__sx={T:new Set(),L:[],P:location.pathname,on:true,run:false,err:false,st:W.setTimeout,ct:W.clearTimeout,ci:W.clearInterval,si:W.setInterval};
W.setTimeout=function(f,d){var a=[].slice.call(arguments),id;if(typeof f==='function')a[0]=function(){S.T.delete(id);return f.apply(this,arguments)};id=S.st.apply(W,a);S.T.add(id);return id};
W.setInterval=function(){var id=S.si.apply(W,arguments);S.T.add(id);return id};
[W,document].forEach(function(t){var o=t.addEventListener;t.addEventListener=function(ty,fn,op){
  if(S.run&&(ty==='DOMContentLoaded'||ty==='load')&&typeof fn==='function'){var th=this;S.st.call(W,function(){fn.call(th,new Event(ty))},0);return}
  if(S.on&&typeof fn==='function'){
    if(ty==='popstate'){var f0=fn;fn=function(e){if(location.pathname===S.P)return f0.call(this,e)}}
    S.L.push({t:t,ty:ty,fn:fn,op:op});
  }
  return o.call(this,ty,fn,op);};});
})();
</script>
<style>
/* Night mode colours live at the end of style.css (html.sx-dark ...) */
html.sx-dark{color-scheme:dark}
/* Day / Night switch (side menu) */
.sx-tm{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 8px;padding:6px 10px;border-radius:10px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.14);font-size:12px;font-weight:600;color:#475569}
.sx-tm .sx-tl{display:flex;align-items:center;gap:6px}
.sx-sw{position:relative;flex:none;width:38px;height:21px;border-radius:99px;border:0;padding:0;cursor:pointer;background:linear-gradient(135deg,#fde68a,#fbbf24);transition:background .25s;box-shadow:inset 0 1px 2px rgba(0,0,0,.18)}
.sx-sw:focus-visible{outline:2px solid #6366f1;outline-offset:2px}
.sx-sw i{position:absolute;top:2px;left:2px;width:17px;height:17px;border-radius:50%;background:#fff;display:grid;place-items:center;font-style:normal;font-size:10px;line-height:1;transition:transform .25s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.sx-sw[aria-checked="true"]{background:linear-gradient(135deg,#312e81,#4338ca)}
.sx-sw[aria-checked="true"] i{transform:translateX(17px)}
@media(prefers-reduced-motion:reduce){.sx-sw,.sx-sw i{transition:none}}
</style>
<?= dark_css() ?>
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
<?php if (!$admin) { notice_html(); notice_popup_html(); } flash_html(); }

function footer_html(bool $closeMain = true): void { if ($closeMain) echo "</main>\n"; ?>
<script>
if(window.__sx)__sx.on=false;   // from here on, listeners/timers belong to the page frame, not to a swappable page
window.sxBind=function(r){r=r||document;
r.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){
  var t=document.querySelector(b.dataset.copy);t.select();
  try{navigator.clipboard.writeText(t.value)}catch(e){document.execCommand('copy')}
  var o=b.textContent;b.textContent='Copied!';setTimeout(function(){b.textContent=o},1500);});});
r.querySelectorAll('[data-toggle]').forEach(function(b){b.addEventListener('click',function(){
  var i=document.querySelector(b.dataset.toggle);i.type=i.type==='password'?'text':'password';
  b.textContent=i.type==='password'?'Show':'Hide';});});
};
window.sxBind(document);
var fb=document.getElementById('fab');if(fb){fb.querySelector('.fab-main').addEventListener('click',function(){fb.classList.toggle('open')});}
(function(){var sw=document.getElementById('sxSw');if(!sw)return;var h=document.documentElement;
  function paint(){var d=h.classList.contains('sx-dark');sw.setAttribute('aria-checked',d?'true':'false');
    document.getElementById('sxKnob').textContent=d?'\uD83C\uDF19':'\u2600\uFE0F';
    document.getElementById('sxTmI').textContent=d?'\uD83C\uDF19':'\u2600\uFE0F';
    document.getElementById('sxTmT').textContent=d?'Night mode':'Day mode';}
  sw.addEventListener('click',function(){var d=h.classList.toggle('sx-dark');try{localStorage.setItem('sx_theme',d?'dark':'light')}catch(e){}paint();});
  paint();})();
document.addEventListener('keydown',function(ev){if(ev.key==='Escape'){document.body.classList.remove('menu');if(fb)fb.classList.remove('open');}});
document.querySelectorAll('.cur a').forEach(function(a){a.addEventListener('click',function(e){
  e.preventDefault();if(a.classList.contains('on'))return;
  var u=new URL(location.href);u.searchParams.set('cur',a.dataset.cur);location.href=u.toString();});});
</script>
<?php if (isset($GLOBALS['__active'])): ?>
<script>
/* Instant navigation: Home / Orders / Deposit / Live View / Get OTP (and the other account pages) swap in place, no page reload.
   Pages are prefetched in the background; any problem falls back to a normal page load. */
(function(){
var S=window.__sx;
if(!S||!window.fetch||!window.DOMParser||!window.URL||!history.pushState)return;
var D=document,B=D.body,main=D.querySelector('main.with-side'),bar=D.querySelector('.tabbar');
if(!main||!bar)return;
var PAGES={'/dashboard.php':1,'/orders.php':1,'/order.php':1,'/deposits.php':1,'/live.php':1,'/otp.php':1,'/transactions.php':1,'/referrals.php':1,'/api_keys.php':1,'/profile.php':1};
var cache={},pend={},scr={},seq=0,TTL=5000,noop=function(){};
var curKey=location.pathname+location.search;
var RM=window.matchMedia&&matchMedia('(prefers-reduced-motion:reduce)').matches;
try{history.scrollRestoration='manual'}catch(e){}

/* nodes that stay for the whole visit: everything except <main> and what sits between it and the tab bar */
var keep=new Set();
(function(){var r=new Set(),n=main;while(n&&n!==bar){r.add(n);n=n.nextElementSibling}
  Array.prototype.forEach.call(B.children,function(c){if(!r.has(c))keep.add(c)});})();

/* thin progress line (only appears if a page is not ready within 120ms) */
var pb=D.createElement('div');
pb.style.cssText='position:fixed;left:0;top:0;height:2px;width:0;background:#6366f1;z-index:99999;opacity:0;pointer-events:none;transition:width .35s ease,opacity .25s';
B.appendChild(pb);keep.add(pb);
var pt=null,shown=false;
function prog(on){
  if(pt){S.ct.call(window,pt);pt=null}
  if(on){shown=false;pt=S.st.call(window,function(){shown=true;pb.style.opacity='1';pb.style.width='70%'},120)}
  else if(shown){shown=false;pb.style.width='100%';pt=S.st.call(window,function(){pb.style.opacity='0';pt=S.st.call(window,function(){pb.style.width='0'},260)},120)}
}
function hard(u,rep){if(rep)location.replace(u);else location.href=u}
function keyOf(u){return u.pathname+u.search}

function fetchPage(key){
  if(pend[key])return pend[key];
  var p=fetch(key,{credentials:'same-origin',headers:{'X-Requested-With':'fetch','X-SX-Nav':'1'}}).then(function(r){
    var ru=new URL(r.url,location.href);
    if(!r.ok||!PAGES[ru.pathname])throw new Error('nav');
    if((r.headers.get('content-type')||'').indexOf('html')<0)throw new Error('nav');
    return r.text().then(function(t){var k2=keyOf(ru),e={html:t,t:Date.now(),key:k2};cache[key]=e;cache[k2]=e;return e});
  });
  pend[key]=p;var done=function(){delete pend[key]};p.then(done,done);
  return p;
}

function mark(p){
  Array.prototype.forEach.call(D.querySelectorAll('.tabbar a,.sd-nav a'),function(a){
    var on=false;try{on=new URL(a.href,location.href).pathname===p}catch(e){}
    a.classList.toggle('on',on);
    if(on)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');
  });
}

function teardown(){
  S.T.forEach(function(id){S.ct.call(window,id);S.ci.call(window,id)});S.T.clear();
  S.L.forEach(function(x){x.t.removeEventListener(x.ty,x.fn,x.op)});S.L.length=0;
  Array.prototype.slice.call(B.children).forEach(function(c){if(!keep.has(c))B.removeChild(c)});
  B.classList.remove('menu','sxp-lock');D.documentElement.style.overflow='';
}

function runScripts(list,i,done){
  for(;i<list.length;i++){
    var o=list[i],s=D.createElement('script'),t=o.getAttribute('data-sx-t')||'';
    for(var j=0;j<o.attributes.length;j++){var n=o.attributes[j].name;if(n!=='type'&&n!=='data-sx-t')s.setAttribute(n,o.attributes[j].value)}
    if(t)s.setAttribute('type',t);
    if(o.getAttribute('src')){
      var nx=i+1;s.onload=s.onerror=function(){runScripts(list,nx,done)};
      o.parentNode.replaceChild(s,o);return;
    }
    s.text=o.text;o.parentNode.replaceChild(s,o);
  }
  done();
}

function apply(e,push,y){
  var doc=new DOMParser().parseFromString(e.html,'text/html');
  var nm=doc.querySelector('main.with-side'),nb=doc.querySelector('.tabbar');
  if(!nm||!nb||!doc.querySelector('.sd-nav')){hard(e.key);return}
  var nn=[];for(var n=nm;n&&n!==nb;n=n.nextElementSibling)nn.push(n);
  /* park the scripts (so the browser does not run them on insert); they are run one by one below */
  nn.forEach(function(x){
    var l=x.tagName==='SCRIPT'?[x]:Array.prototype.slice.call(x.querySelectorAll('script'));
    l.forEach(function(s){s.setAttribute('data-sx-t',s.getAttribute('type')||'');s.setAttribute('type','text/x-sx')});
  });
  if(push)history.pushState({sx:1},'',e.key);
  S.P=location.pathname;
  teardown();
  if(doc.title)D.title=doc.title;
  ['.tabbar','.sd-nav','.sd-bal'].forEach(function(s){var a=D.querySelector(s),b=doc.querySelector(s);if(a&&b)a.innerHTML=b.innerHTML});
  var tp=D.querySelector('.tp-prof'),tn=doc.querySelector('.tp-prof');if(tp&&tn)tp.className=tn.className;
  var ins=nn.map(function(x){var c=D.importNode(x,true);bar.parentNode.insertBefore(c,bar);return c});
  var sc=[];ins.forEach(function(x){if(x.tagName==='SCRIPT')sc.push(x);else Array.prototype.push.apply(sc,x.querySelectorAll('script'))});
  S.err=false;S.on=true;S.run=true;
  runScripts(sc,0,function(){
    S.on=false;S.run=false;
    if(S.err){hard(location.href,true);return}
    ins.forEach(function(x){if(x.nodeType===1)window.sxBind(x)});
    window.scrollTo(0,y||0);
    if(!RM&&ins[0]&&ins[0].animate)try{ins[0].animate([{opacity:.45},{opacity:1}],{duration:160,easing:'ease-out'})}catch(er){}
  });
}

function refresh(key,my){
  var old=cache[key]&&cache[key].html;
  fetchPage(key).then(function(e){
    if(my!==seq||e.html===old)return;
    var a=D.activeElement;
    if(a&&/^(INPUT|TEXTAREA|SELECT|BUTTON)$/.test(a.tagName)&&a.closest&&a.closest('main'))return;
    var m=D.getElementById('buyM');if(m&&m.classList.contains('open'))return;
    apply(e,false,window.pageYOffset);
  }).catch(noop);
}

function go(url,push){
  var u=new URL(url,location.href),key=keyOf(u),my=++seq;
  scr[curKey]=window.pageYOffset;curKey=key;
  B.classList.remove('menu');
  mark(u.pathname);
  var y=push?0:(scr[key]||0);
  function show(e){if(my!==seq)return;curKey=e.key;apply(e,push,y)}
  var c=cache[key];
  if(c){show(c);if(Date.now()-c.t>TTL)refresh(key,my)}
  else{prog(true);fetchPage(key).then(function(e){prog(false);show(e)},function(){prog(false);if(my===seq)hard(u.href)})}
}

D.addEventListener('click',function(e){
  if(e.defaultPrevented||e.button||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  var a=e.target.closest&&e.target.closest('a[href]');
  if(!a||(a.target&&a.target!=='_self')||a.hasAttribute('download'))return;
  var u;try{u=new URL(a.href,location.href)}catch(er){return}
  if(u.origin!==location.origin||!PAGES[u.pathname])return;
  if(u.pathname===location.pathname&&u.search===location.search){
    e.preventDefault();if(u.hash)return;
    try{window.scrollTo({top:0,behavior:RM?'auto':'smooth'})}catch(er){window.scrollTo(0,0)}
    refresh(keyOf(u),seq);return;
  }
  e.preventDefault();go(u.href,true);
});

window.addEventListener('popstate',function(){
  if(location.pathname===S.P)return;                       /* same page: its own code (category tiles) handles it */
  if(!PAGES[location.pathname]){location.reload();return}
  go(location.href,false);
});

window.addEventListener('error',function(){if(S.run)S.err=true},true);

function pf(e){
  var a=e.target.closest&&e.target.closest('a[href]');if(!a)return;
  try{var u=new URL(a.href,location.href);if(u.origin===location.origin&&PAGES[u.pathname]&&!cache[keyOf(u)])fetchPage(keyOf(u)).catch(noop)}catch(er){}
}
D.addEventListener('touchstart',pf,{passive:true});D.addEventListener('mouseover',pf);

/* warm the cache with the bar pages (and this page, so coming back to it is instant) */
var warming=false;
function idle(){
  if(warming||(navigator.connection&&navigator.connection.saveData))return;
  warming=true;
  var ls=[],seen={};
  Array.prototype.forEach.call(D.querySelectorAll('.tabbar a,.sd-nav a,.sd-add'),function(a){
    try{var u=new URL(a.href,location.href);if(u.origin===location.origin&&PAGES[u.pathname]&&!seen[keyOf(u)]){seen[keyOf(u)]=1;if(keyOf(u)!==curKey)ls.push(keyOf(u))}}catch(er){}
  });
  ls.push(curKey);
  var i=0;(function nx(){
    if(i>=ls.length){warming=false;return}var k=ls[i++];
    if(cache[k]){nx();return}
    fetchPage(k).catch(noop).then(function(){S.st.call(window,nx,350)});
  })();
}
var bg=D.querySelector('.burger');if(bg)bg.addEventListener('click',function(){S.st.call(window,idle,0)});
if(D.readyState==='complete')S.st.call(window,idle,800);
else window.addEventListener('load',function(){S.st.call(window,idle,800)});
})();
</script>
<?php endif; ?>
</body></html>
<?php }

function user_start(string $title, array $u, string $active): void {
    $groups = [
        'Shop'    => [['dashboard', '/dashboard.php', 'Home', 'home'], ['orders', '/orders.php', 'My Orders', 'cart'], ['live', '/live.php', 'Live View', 'live'], ['otp', '/otp.php', 'Read OTP', 'mail']],
        'Wallet'  => [['deposits', '/deposits.php', 'Deposits', 'coins'], ['transactions', '/transactions.php', 'Transactions', 'history'], ['referrals', '/referrals.php', 'Referrals', 'users']],
        'Account' => [['apikeys', '/api_keys.php', 'API Keys', 'key'], ['profile', '/profile.php', 'Profile', 'usercog']],
    ];
    $name = ucfirst(strstr($u['email'], '@', true) ?: $u['email']);
    $lg = media_url('logo');
    $tg = setting('telegram_url'); $wa = setting('whatsapp_url');
    $GLOBALS['__active'] = $active;
    page_head($title, 'app'); ?>
<style>
.top .brand{gap:8px!important;letter-spacing:-.02em;font-size:1.1rem!important;line-height:1}
.top .brand .bm svg{display:block}
.top .brand .bm{flex:none;width:30px;height:30px;border-radius:10px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;letter-spacing:0;box-shadow:0 4px 10px -3px rgba(79,70,229,.6),inset 0 1px 0 rgba(255,255,255,.35)}
.top .brand img{height:30px!important}
.top .brand .bn b{font-weight:800;color:#1e1b4b}
.top .brand .bn i{font-style:normal;font-weight:800;background:linear-gradient(90deg,#4f46e5,#9333ea);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:#6d28d9}
</style>
<div class="top"><button class="burger" type="button" aria-label="Open menu" onclick="document.body.classList.toggle('menu')"><?= icon('menu') ?></button>
<?php $sn = trim((string)site_name()); $sp = strpos($sn, ' '); $bw1 = $sp === false ? $sn : substr($sn, 0, $sp); $bw2 = $sp === false ? '' : trim(substr($sn, $sp + 1)); ?>
<a class="brand" href="/dashboard.php"><?php if ($lg): ?><img src="<?= e($lg) ?>" alt=""><?php else: ?><span class="bm"><svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M9 8V7a3 3 0 0 1 6 0v1" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><path d="M5.2 8h13.6l-1 11.1a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5.2 8z" fill="#fff"/><path d="M12 11.6l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9z" fill="#6d28d9"/></svg></span><?php endif; ?><span class="bn"><b><?= e($bw1) ?></b><?php if ($bw2 !== ''): ?> <i><?= e($bw2) ?></i><?php endif; ?></span></a>
<?php if (usd_rate() > 0): $curNow = cur_code(); ?>
<div class="cur" role="group" aria-label="Currency"><a href="?cur=usd" data-cur="usd" class="<?= $curNow === 'usd' ? 'on' : '' ?>">$</a><a href="?cur=bdt" data-cur="bdt" class="<?= $curNow === 'bdt' ? 'on' : '' ?>">BDT</a></div>
<?php endif; ?>
<a class="tp-prof<?= $active === 'profile' ? ' on' : '' ?>" href="/profile.php" aria-label="Profile" title="Profile"><?= icon('user') ?></a></div>
<div class="shade" onclick="document.body.classList.remove('menu')"></div>
<aside class="side sd" aria-label="Account menu">
  <div class="sd-head">
    <div class="sd-top"><span>My account</span><button type="button" class="x sd-x" aria-label="Close menu" onclick="document.body.classList.remove('menu')"><?= icon('x') ?></button></div>
    <div class="sd-user"><div class="sd-av"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></div><div class="sd-id"><b><?= e($name) ?></b><small><?= e($u['email']) ?></small></div></div>
    <div class="sd-bal"><div><span>Wallet balance</span><b><?= money($u['coins']) ?></b></div><a class="sd-add" href="/deposits.php"><?= icon('plus') ?>Add funds</a></div>
  </div>
  <nav class="sd-nav">
  <?php foreach ($groups as $grp => $items): ?>
    <div class="sd-grp"><?= e($grp) ?></div>
    <?php foreach ($items as [$k, $href, $label, $ic]): ?>
    <a href="<?= $href ?>" class="<?= $k === $active ? 'on' : '' ?>"<?= $k === $active ? ' aria-current="page"' : '' ?>><span class="sd-ic"><?= icon($ic) ?></span><span class="sd-t"><?= e($label) ?></span></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </nav>
  <div class="sd-foot">
    <div class="sx-tm"><span class="sx-tl"><span id="sxTmI" aria-hidden="true">&#9728;&#65039;</span><span id="sxTmT">Day mode</span></span>
      <button type="button" class="sx-sw" id="sxSw" role="switch" aria-checked="false" aria-label="Night mode"><i id="sxKnob">&#9728;&#65039;</i></button></div>
  <?php if ($tg || $wa): ?>
    <div class="sd-sup"><span>Need help?</span>
      <?php if ($tg): ?><a href="<?= e($tg) ?>" target="_blank" rel="noopener"><?= icon('send') ?>Telegram</a><?php endif; ?>
      <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('phone') ?>WhatsApp</a><?php endif; ?>
    </div>
  <?php endif; ?>
    <form method="post" action="/logout.php" class="sd-lo"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?><span>Log out</span></button></form>
  </div>
</aside>
<main class="wide with-side">
<?php notice_html(); notice_popup_html(); flash_html(); }

function user_end(): void {
    $tg = setting('telegram_url'); $wa = setting('whatsapp_url');
    echo "</main>\n"; // the tab bar and support button must sit outside <main>, otherwise the menu overlay covers them
    echo $GLOBALS['__modal'] ?? '';
    $act = $GLOBALS['__active'] ?? '';
    $tabs = [
        'dashboard'    => ['/dashboard.php', 'Home', 'home'],
        'orders'       => ['/orders.php', 'Orders', 'cart'],
        'deposits'     => ['/deposits.php', 'Deposit', 'plus'],
        'live'         => ['/live.php', 'Live View', 'live'],
        'otp'          => ['/otp.php', 'Get OTP', 'key'],
    ]; ?>
<style>
/* Bottom bar: glass (transparent) buttons, highlighted "+" Deposit, bigger icon-only support button */
body .tabbar{overflow:visible;background:rgba(255,255,255,.72);-webkit-backdrop-filter:blur(18px) saturate(1.5);backdrop-filter:blur(18px) saturate(1.5);border:1px solid rgba(99,102,241,.16);box-shadow:0 12px 32px -12px rgba(30,27,75,.38),inset 0 1px 0 rgba(255,255,255,.7)}
body .tabbar a{background:transparent;box-shadow:none;color:#64748b;transition:color .2s,background .2s}
body .tabbar a .tl{color:inherit;font-weight:600}
body .tabbar a:hover{color:#4338ca}
body .tabbar a.on{background:rgba(79,70,229,.1);color:#4338ca;box-shadow:inset 0 0 0 1px rgba(79,70,229,.14)}
body .tabbar a.on .ico,body .tabbar a .ico{background:transparent;box-shadow:none}
body .tabbar a.dep{background:transparent;box-shadow:none;color:#059669}
body .tabbar a.dep .ico{position:relative;display:flex;align-items:center;justify-content:center;width:46px;height:46px;margin-top:-22px;border-radius:50%;color:#fff;
  background:linear-gradient(135deg,#10b981,#059669);box-shadow:0 10px 20px -6px rgba(5,150,105,.75),0 0 0 4px rgba(255,255,255,.75)}
body .tabbar a.dep .ico:after{content:"";position:absolute;inset:0;border-radius:50%;box-shadow:0 0 0 0 rgba(16,185,129,.55);animation:depPulse 2.2s ease-out infinite}
body .tabbar a.dep .ico svg.i{width:24px;height:24px;stroke-width:2.8}
body .tabbar a.dep .tl{color:#047857;font-weight:700}
body .tabbar a.dep.on{background:transparent;box-shadow:none}
@keyframes depPulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.5)}70%,100%{box-shadow:0 0 0 12px rgba(16,185,129,0)}}
@media(prefers-reduced-motion:reduce){body .tabbar a.dep .ico:after{animation:none}}
/* Tap feedback: no white square flash on touch; a soft rounded press effect instead */
body a,body button,body .tabbar a,body .fab *{-webkit-tap-highlight-color:rgba(0,0,0,0);-webkit-touch-callout:none}
body .tabbar a,body .fab .fab-main,body .fab .fab-i,body .fab .fab-i b{outline:0;user-select:none;-webkit-user-select:none;touch-action:manipulation}
body .tabbar a{border-radius:18px;transition:color .2s,background .2s,transform .12s}
body .tabbar a:focus,body .tabbar a:active,body .tabbar a:focus:not(.on){background-color:transparent}
body .tabbar a:active{transform:scale(.92);background:rgba(79,70,229,.12)}
body .tabbar a.on:active{background:rgba(79,70,229,.16)}
body .tabbar a.dep:active{background:transparent}
body .tabbar a.dep:active .ico{transform:scale(.9)}
body .tabbar a.dep .ico{transition:transform .12s}
body .tabbar a:focus-visible,body .fab .fab-main:focus-visible,body .fab .fab-i:focus-visible b{box-shadow:0 0 0 3px rgba(99,102,241,.45)}
body .fab .fab-main,body .fab .fab-i b{transition:transform .12s}
body .fab .fab-main:active,body .fab .fab-i:active b{transform:scale(.9)}
/* Support button: bigger icon, translucent, no text labels */
body .fab .fab-i .lbl{display:none}
body .fab .fab-main{animation:fabGlow 3s ease-in-out infinite}
body .fab.open .fab-main{animation:none;box-shadow:0 0 0 3px rgba(129,140,248,.3),0 6px 16px -6px rgba(79,70,229,.5)}
@keyframes fabGlow{
  0%,100%{box-shadow:0 0 0 0 rgba(99,102,241,0),0 4px 12px -4px rgba(79,70,229,.35)}
  50%{box-shadow:0 0 0 5px rgba(99,102,241,.16),0 4px 16px -2px rgba(99,102,241,.4)}
}
@media(prefers-reduced-motion:reduce){body .fab .fab-main{animation:none}}
body .fab .fab-main{width:54px;height:54px;background:rgba(79,70,229,.88);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.35)}
body .fab .fab-main svg.i{width:26px;height:26px}
body .fab .fab-i b{width:46px;height:46px;background:rgba(255,255,255,.88);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border:1px solid rgba(148,163,184,.3);box-shadow:0 8px 18px -8px rgba(15,23,42,.45)}
body .fab .fab-i b svg.i{width:22px;height:22px}
body .fab .fab-i.tg b{color:#229ed9}
body .fab .fab-i.wa b{color:#16a34a}
</style>
<nav class="tabbar" aria-label="Quick navigation">
<?php foreach ($tabs as $k => $t): ?>
  <a href="<?= $t[0] ?>" class="<?= trim(($k === $act ? 'on' : '') . ($k === 'deposits' ? ' dep' : '')) ?>"<?= $k === $act ? ' aria-current="page"' : '' ?>><span class="ico"><?= icon($t[2]) ?></span><span class="tl"><?= e($t[1]) ?></span></a>
<?php endforeach; ?>
</nav>
<?php if ($tg || $wa): ?>
<div class="fab" id="fab">
  <?php if ($tg): ?><a class="fab-i tg" href="<?= e($tg) ?>" target="_blank" rel="noopener"><span class="lbl">Telegram Support</span><b><?= icon('send') ?></b></a><?php endif; ?>
  <?php if ($wa): ?><a class="fab-i wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><span class="lbl">WhatsApp Support</span><b><?= icon('phone') ?></b></a><?php endif; ?>
  <button type="button" class="fab-main" aria-label="Support"><span class="c-chat"><?= icon('headset') ?></span><span class="c-plus"><?= icon('plus') ?></span></button>
</div>
<?php endif;
    footer_html(false);
}

/**
 * Icon to show for a product: 1) the icon the admin picked for it, 2) an automatic guess from the product name
 * (e.g. a name containing "Gmail" or "Netflix"), 3) the category icon. Returns "app:key", an emoji, or ''.
 */
function product_icon_value(array $p, ?array $cat = null): string {
    $own = trim((string)($p['icon'] ?? ''));
    if ($own !== '') return $own;
    $n = mb_strtolower((string)($p['name'] ?? ''));
    $alias = ['twitter' => 'x', 'vpn' => 'shield', 'hotmail' => 'outlook', 'office' => 'microsoft', 'prime video' => 'amazon', 'gift card' => 'gift'];
    foreach ($alias as $w => $key) if ($n !== '' && mb_strpos($n, $w) !== false && isset(app_icons()[$key])) return 'app:' . $key;
    if ($n !== '') foreach (app_icons() as $key => $d) {
        if ($key === 'shop') continue;
        foreach ([$key, mb_strtolower($d[0])] as $w) if (mb_strlen($w) >= 3 && mb_strpos($n, $w) !== false) return 'app:' . $key;
    }
    return (string)($cat['icon'] ?? '');
}

/** Product icon markup for order pages: the product's own icon, else an automatic guess from its name, else the category icon, else a box. */
function ord_ico_html(string $own, string $cat, string $name): string {
    $v = product_icon_value(['icon' => $own, 'name' => $name], ['icon' => $cat]);
    return $v !== '' ? ico_html($v) : icon('box');
}

function catalog_url(array $x): string {
    $x = array_filter($x, fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return '/dashboard.php' . ($x ? '?' . http_build_query($x) : '');
}

/** Product list (search-result line + groups of cards). Also returned on its own to the page's AJAX category switcher. */
function catalog_list_html(array $cats, array $rows, int $c, string $q): void {
    $byId = []; foreach ($cats as $k) $byId[(int)$k['id']] = $k;
    // Order comes from dashboard.php's query: category order, then the admin's product order (Admin > Products).
    $rows = array_values($rows);
    $groups = []; foreach ($cats as $k) $groups[(int)$k['id']] = [];
    foreach ($rows as $p) $groups[(int)($p['category_id'] ?? 0)][] = $p;
    $groups = array_filter($groups);
    if (isset($groups[0])) { $z = $groups[0]; unset($groups[0]); $groups[0] = $z; }   // uncategorised last
    // Slots that only exist when at least one card needs them, so every card in the list keeps exactly the same layout.
    $anyBody = false; $anyInst = false;
    foreach ($rows as $p) {
        if (trim((string)$p['description']) !== '' || (!empty($p['sup_domain']) && (int)($p['sup_show_dom'] ?? 1) === 1)) $anyBody = true;
        if (!empty($p['auto_delivery'])) $anyInst = true;
    }
    $ud = user_disc_map((int)($_SESSION['uid'] ?? 0));
    $card = function (array $p) use ($byId, $c, $q, $ud, $anyBody, $anyInst) {
        $k = $byId[(int)($p['category_id'] ?? 0)] ?? null;
        $icon = product_icon_value($p, $k);
        $stock = $p['stock'] === null ? null : (int)$p['stock'];
        if ($stock === null) { $sl = 'In Stock'; $sc = ''; }
        elseif ($stock <= 0) { $sl = 'Out of stock'; $sc = 'out'; }
        elseif ($stock <= 10) { $sl = 'Low stock · ' . number_format($stock) . ' pcs'; $sc = 'low'; }
        else { $sl = 'In Stock · ' . number_format($stock) . ' pcs'; $sc = ''; }
        $out = $stock !== null && $stock <= 0;
        $pct = discount_pct($p, $ud); $fin = final_price((float)$p['price'], $pct);
        $mine = $pct > 0 && (float)($p['discount_pct'] ?? 0) < $pct;   // own discount of this user is the one applied
        $domOn = !empty($p['sup_domain']) && (int)($p['sup_show_dom'] ?? 1) === 1;
        $mid = ($p['card_style'] ?? '') === 'midnight';   // admin picks the card look per product (Admin > Products)
        // One line = one bullet. 2+ non-empty lines render as a checklist; a single line stays plain text.
        $descLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$p['description'])), function ($l) { return $l !== ''; })); ?>
    <div class="pc<?= !empty($p['popular']) ? ' pop' : '' ?><?= $mid ? ' pc-mid' : '' ?>">
      <?php if (!empty($p['popular'])): ?><span class="ribbon">★ POPULAR</span><?php endif; ?>
      <div class="pc-h"><span class="pc-ic"><?= ico_html($icon) ?></span><div class="pc-t"><h4 title="<?= e($p['name']) ?>"><?= e($p['name']) ?></h4><span class="pc-c"><?= $k ? e($k['name']) : '&nbsp;' ?></span></div></div>
      <?php if ($anyBody): ?>
      <div class="pc-bd<?= $domOn ? ' hd' : '' ?><?= (!$descLines && !$domOn) ? ' em' : '' ?>">
        <?php if ($mid && $descLines): ?>
          <?php // Midnight rows: skip blank lines and "Key:" lines with no value, so only what the admin really wrote is shown
          $kvRows = [];
          foreach ($descLines as $dl) {
              $ci = strpos($dl, ':'); $hasK = $ci !== false && $ci > 0 && $ci <= 22;
              $kk = $hasK ? trim(substr($dl, 0, $ci)) : ''; $vv = $hasK ? trim(substr($dl, $ci + 1)) : $dl;
              if ($vv === '' || ($hasK && $kk === '')) continue;
              $kvRows[] = [$hasK ? $kk : '', $vv, $dl];
          } ?>
          <?php if ($kvRows): ?><ul class="pc-kv"><?php foreach (array_slice($kvRows, 0, $domOn ? 3 : 4) as $r): ?>
            <li title="<?= e($r[2]) ?>"><?php if ($r[0] !== ''): ?><span class="k"><?= e($r[0]) ?></span><b class="v"><?= e($r[1]) ?></b><?php else: ?><b class="v full"><?= e($r[1]) ?></b><?php endif; ?></li>
          <?php endforeach; ?></ul><?php endif; ?>
        <?php elseif (count($descLines) > 1): ?>
          <ul class="pc-dl"><?php foreach (array_slice($descLines, 0, $domOn ? 3 : 4) as $dl): ?><li title="<?= e($dl) ?>"><?= e($dl) ?></li><?php endforeach; ?></ul>
        <?php elseif ($descLines): ?>
          <p class="pc-d" title="<?= e($descLines[0]) ?>"><?= e($descLines[0]) ?></p>
        <?php endif; ?>
        <?php if ($domOn): ?><div class="pc-dom" title="<?= e($p['sup_domain']) ?>">Possible Domain (<?= e($p['sup_domain']) ?>)</div><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="pc-ft">
      <div class="pc-price"><?php if ($pct > 0): ?><span class="pc-old"><?= money($p['price']) ?></span><?= money($fin) ?><?php else: ?><?= money($p['price']) ?><?php endif; ?><?php if ($p['unit'] !== ''): ?><small> /<?= e($p['unit']) ?></small><?php endif; ?><?php if ($pct > 0): ?><em class="pc-off<?= $mine ? ' mine' : '' ?>">-<?= pct_fmt($pct) ?>%<?= $mine ? ' for you' : '' ?></em><?php endif; ?></div>
      <div class="sx-meta"><span class="sx-stk <?= $sc ?>"><?= e($sl) ?></span><span class="sx-pid">ID: <?= (int)$p['id'] ?></span></div>
      <?php if (!empty($p['auto_delivery'])): ?><div class="inst">&#9889; Instant delivery</div><?php elseif ($anyInst): ?><div class="inst ph" aria-hidden="true">&nbsp;</div><?php endif; ?>
      <form method="post" action="/dashboard.php" class="pc-buy" style="margin:0"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="qty" value="1"><input type="hidden" name="c" value="<?= $c ?: '' ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
        <button class="btn buy" <?= $out ? 'disabled' : '' ?> onclick="return window.sxBuy ? sxBuy(this,event) : true" data-buy data-id="<?= (int)$p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e(number_format($fin, 2, '.', '')) ?>" data-old="<?= $pct > 0 ? e(number_format((float)$p['price'], 2, '.', '')) : '' ?>" data-unit="<?= e($p['unit']) ?>" data-stock="<?= $stock === null ? '' : $stock ?>" data-auto="<?= !empty($p['auto_delivery']) ? 1 : 0 ?>"><?= icon('cart') ?><span><?= $out ? 'Sold out' : 'Buy Now' ?></span></button>
      </form>
      </div>
    </div>
<?php }; ?>
<?php if ($q !== ''): ?><p class="res"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?> for “<?= e($q) ?>” <a href="<?= e(catalog_url(['c' => $c])) ?>">Clear</a></p><?php endif; ?>
<?php foreach ($groups as $cid => $list): $k = $byId[$cid] ?? null; ?>
  <?php if (!$c): ?><div class="sec-h"><span class="ci"><?= ico_html((string)($k['icon'] ?? '')) ?></span><b><?= e($k['name'] ?? 'Other') ?></b><em><?= count($list) ?></em></div><?php endif; ?>
  <div class="catalog"><?php foreach ($list as $p) $card($p); ?></div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card empty"><div class="ei">🔎</div><b>No products found</b><p><?= $q !== '' ? 'Try a different search or category.' : 'Products will appear here once they are added.' ?></p></div><?php endif; ?>
<?php }

/** Home catalog: search box, categories shown directly as tiles (no dropdown), and the product cards. */
function catalog_html(array $cats, array $rows, int $c, string $q, int $total, float $balance = 0.0): void {
    echo app_icon_sprite(); ?>
<style>
/* Category tiles - self-contained, does not depend on style.css */
.sx-cats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px;margin:6px 0 4px;padding:0}
@media(min-width:700px){.sx-cats{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 14px}}
@media(max-width:699px){
#catF.search{margin:0!important;padding:3px 3px 3px 10px!important;height:38px!important;border-radius:12px!important;gap:6px!important}
#catF.search svg{width:16px!important;height:16px!important}
#catF.search input{padding:0!important;height:30px!important;font-size:14px!important}
#catF.search button{padding:0 14px!important;height:30px!important;font-size:13px!important;border-radius:9px!important}
.sec-h{margin:8px 2px 8px!important;gap:7px!important;font-size:.95rem!important}
.sec-h .ci{width:24px!important;height:24px!important;border-radius:7px!important;font-size:.9rem!important}
.sec-h em{padding:0 7px!important}
.catalog{gap:8px!important}
/* Compact product cards for phones */
.catalog{grid-template-columns:minmax(0,1fr)!important}
.pc{padding:11px 12px 12px!important;gap:7px!important;border-radius:14px!important}
.pc:hover{transform:none!important}
.pc .ribbon{top:10px!important;font-size:9.5px!important;padding:3px 8px 3px 10px!important;letter-spacing:.03em!important}
.pc-h{gap:9px!important}
.pc.pop .pc-h{padding-right:70px!important}
.pc-ic{width:36px!important;height:36px!important;border-radius:11px!important;font-size:1.1rem!important}
.pc-ic svg{width:22px!important;height:22px!important}
.pc h4{font-size:14px!important;line-height:1.28!important}
.pc-c{font-size:11px!important;margin-top:1px!important}
.pc-d{font-size:12px!important;line-height:1.4!important}
.pc-dl{font-size:12px!important;margin:0!important}
.pc-dl li{padding:3px 0 3px 17px!important;line-height:1.35!important}
.pc-dl li:before{top:3px!important;font-size:12px!important}
.pc-dom{font-size:11px!important;margin:0!important}
.pc-price{font-size:1.2rem!important;line-height:1.2!important}
.pc-price small{font-size:11px!important}
.pc-price .pc-old{font-size:.6em!important}
.pc-off{font-size:10.5px!important;padding:1px 7px!important;margin-left:6px!important}
.sx-meta{margin:0!important}
.sx-stk{padding:2px 8px!important;font-size:11px!important;gap:5px!important}
.sx-stk:before{width:6px!important;height:6px!important;box-shadow:0 0 0 2px rgba(22,163,74,.2)!important}
.sx-pid{font-size:11px!important;padding:2px 7px!important}
.pc .inst{font-size:11.5px!important;margin:0!important}
.pc .btn.buy{margin-top:0!important;padding:9px 12px!important;font-size:13.5px!important;border-radius:10px!important;gap:6px!important}
.pc .btn.buy svg{width:16px!important;height:16px!important}
}
.sx-cat,.sx-cat:hover,.sx-cat:focus,.sx-cat:active{text-decoration:none}
.sx-cat{display:inline-flex;align-items:center;gap:5px;min-width:0;max-width:100%;height:28px;padding:0 10px 0 4px;background:#fff;border:1px solid #e2e8f0;border-radius:99px;color:#475569;font-size:12.5px;font-weight:600;line-height:1;cursor:pointer;-webkit-tap-highlight-color:rgba(0,0,0,0);outline:0;user-select:none;-webkit-user-select:none;touch-action:manipulation;box-shadow:0 1px 2px rgba(15,23,42,.04);transition:background-color .25s ease,border-color .25s ease,color .25s ease,box-shadow .25s ease}
.sx-cat:hover{border-color:#c7d2fe;color:#3730a3}
.sx-cat:not(.on):active{background-color:#eef2ff;border-color:#c7d2fe}
.sx-cat:focus{outline:0}
.sx-cat:focus-visible{box-shadow:0 0 0 3px rgba(99,102,241,.35)}
.sx-cat.sx-all{padding:0 12px;justify-content:center}
.sx-cat .sx-ci{flex:none;display:flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#f1f5f9;font-size:12px;line-height:1;overflow:hidden}
.sx-cat .sx-ci svg{width:14px;height:14px;display:block}
.sx-cat b{min-width:0;max-width:200px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sx-cat.on{background:linear-gradient(135deg,#4f46e5,#6d28d9);border-color:transparent;color:#fff;box-shadow:0 2px 8px -3px rgba(79,70,229,.4)}
.sx-cat.on b{color:#fff}
.sx-cat.on .sx-ci{background:#fff}
#cat-res{scroll-margin-top:72px}
#cat-res{transition:opacity .2s ease}
#cat-res.busy{opacity:.8;pointer-events:none}
/* ---- Uniform product cards: every card has the same structure and the same height ---- */
.catalog{--rh:22px;align-items:stretch}
@media(max-width:699px){.catalog{--rh:20px}}
.catalog .pc{display:flex;flex-direction:column;height:100%;min-width:0;box-sizing:border-box}
.catalog .pc-h{flex:none;display:flex;align-items:center;min-width:0}
.catalog .pc-ic{flex:none;overflow:hidden}
.catalog .pc-t{min-width:0;flex:1}
.catalog .pc h4{margin:0;line-height:1.28!important;min-height:2.56em;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;overflow-wrap:anywhere}
.catalog .pc-c{display:block;min-height:1.4em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.catalog .pc-bd{flex:none;height:calc(var(--rh)*4);overflow:hidden;min-width:0}
.catalog .pc-d{margin:0!important;line-height:1.5!important;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:4;overflow:hidden;overflow-wrap:anywhere}
.catalog .pc-bd.hd .pc-d{-webkit-line-clamp:3}
.catalog .pc-dl{margin:0!important;padding:0;list-style:none}
.catalog .pc-dl li{position:relative;box-sizing:border-box;height:var(--rh);line-height:calc(var(--rh) - 1px)!important;padding:0 0 0 18px!important;border-bottom:1px solid #eef0f4!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.catalog .pc-dl li:last-child{border-bottom:0!important}
.catalog .pc-dl li:before{top:0!important;line-height:calc(var(--rh) - 1px)!important}
.catalog .pc-dom{margin:0!important;height:var(--rh);line-height:var(--rh)!important;font:600 12px/var(--rh) ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#b45309;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.catalog .pc-ft{margin-top:auto;display:flex;flex-direction:column;gap:8px;min-width:0}
.catalog .pc-price{display:flex;align-items:center;flex-wrap:wrap;gap:0 6px;min-height:34px;line-height:1.2}
.catalog .sx-meta{margin:0!important}
.catalog .inst{margin:0!important;min-height:1.3em;color:#0f9d6b;font-weight:600;font-size:12.5px}
.catalog .inst.ph{visibility:hidden}
.catalog .pc .btn.buy{width:100%;min-height:40px;display:flex;align-items:center;justify-content:center}
/* Phones only: no empty gaps. Cards shrink to their own content. Desktop keeps the equal-height cards above. */
@media(max-width:699px){
.catalog{align-items:start}
.catalog .pc{height:auto}
.catalog .pc-bd{height:auto;overflow:visible}
.catalog .pc-bd.em{display:none}
.catalog .inst.ph{display:none}
.catalog .pc h4{min-height:0}
.catalog .pc-ft{margin-top:0}
/* slightly smaller cards */
.catalog{--rh:18px;gap:7px!important}
.catalog .pc{padding:9px 11px 10px!important;gap:5px!important;border-radius:13px!important}
.catalog .pc-ic{width:32px!important;height:32px!important;border-radius:10px!important}
.catalog .pc-ic svg{width:20px!important;height:20px!important}
.catalog .pc h4{font-size:13.5px!important}
.catalog .pc-c{font-size:10.5px!important;min-height:0}
.catalog .pc-d{font-size:11.5px!important}
.catalog .pc-dl{font-size:11.5px!important}
.catalog .pc-price{font-size:1.1rem!important;min-height:0}
.catalog .pc-ft{gap:5px}
.catalog .pc .btn.buy,.pc .btn.buy{min-height:30px!important;height:30px!important;font-size:12.5px!important}
}
/* Desktop: a bit smaller too, all cards still the same size */
@media(min-width:700px){
.catalog{grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.catalog{--rh:20px}
.catalog .pc{padding:13px;gap:9px;border-radius:16px}
.catalog .pc-ic{width:38px;height:38px;border-radius:12px;font-size:1.2rem}
.catalog .pc-ic svg{width:24px;height:24px}
.catalog .pc h4{font-size:.9rem}
.catalog .pc-d,.catalog .pc-dl{font-size:.78rem}
.catalog .pc-price{font-size:1.3rem;min-height:30px}
.catalog .pc-ft{gap:6px}
.catalog .pc .btn.buy,.pc .btn.buy{min-height:30px!important;height:30px}
}
/* Custom (uploaded) image icons */
.ico-img{display:block;width:100%;height:100%;object-fit:contain;border-radius:inherit}
.bx-ic .ico-img{width:36px;height:36px;border-radius:9px}
/* Buy Now: the icon and text inside the button never catch the tap themselves - every tap lands on the button, so the label works exactly like the rest of it */
.btn.buy>*{pointer-events:none}
/* Product card: stock badge + ID */
.sx-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 10px}
.sx-stk{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;font-size:12px;font-weight:700;line-height:1.3;background:#dcfce7;color:#15803d;border:1px solid #86efac}
.sx-stk:before{content:"";flex:none;width:7px;height:7px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.2)}
.sx-stk.low{background:#fef3c7;color:#b45309;border-color:#fcd34d}
.sx-stk.low:before{background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.sx-stk.out{background:#fee2e2;color:#b91c1c;border-color:#fca5a5}
.sx-stk.out:before{background:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.2)}
.sx-pid{flex:none;font-size:12px;font-weight:600;color:#64748b;background:#f1f5f9;border-radius:6px;padding:3px 8px}
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
/* Buy Now button: same full width as before, slimmer height (overrides the older full-width / tall rules above) */
.catalog .pc .btn.buy,.pc .btn.buy{width:100%!important;min-height:32px!important;height:32px;padding:0 12px!important;font-size:13px!important;border-radius:9px!important;gap:6px!important;margin-top:0!important}
.catalog .pc .btn.buy svg,.pc .btn.buy svg{width:15px!important;height:15px!important}
/* Card style "Midnight" (picked per product in Admin): dark card, Key: Value rows */
.catalog .pc.pc-mid,.pc.pc-mid{background:#0b1226 radial-gradient(120% 80% at 100% 0%,rgba(37,99,235,.42),transparent 60%)!important;border:1px solid #1e2750!important;color:#e8ecff!important}
.pc-mid h4{color:#eef1ff!important}
.pc-mid .pc-c{color:#8e9ac0!important}
.pc-mid .pc-ic{background:rgba(255,255,255,.09)!important;color:#7db2ff!important}
.pc-mid .pc-d{color:#c3cbea!important}
.pc-mid .pc-dl li{color:#c3cbea!important;border-color:rgba(255,255,255,.08)!important}
.catalog .pc-mid .pc-kv{margin:0!important;padding:0;list-style:none}
.catalog .pc-mid .pc-kv li{display:flex;justify-content:space-between;align-items:center;gap:12px;box-sizing:border-box;height:var(--rh);line-height:calc(var(--rh) - 1px);border-bottom:1px solid rgba(255,255,255,.08);font-size:12.5px;overflow:hidden}
.catalog .pc-mid .pc-kv li:last-child{border-bottom:0}
.pc-mid .pc-kv .k{flex:none;color:#8e9ac0;white-space:nowrap}
.pc-mid .pc-kv .v{min-width:0;flex:0 1 auto;text-align:right;font-weight:700;color:#eef1ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pc-mid .pc-kv .v.full{flex:1;text-align:left;font-weight:600}
.catalog .pc-mid .pc-dom{color:#fbbf24!important}
.pc-mid .pc-price{color:#fff!important}
.pc-mid .pc-price small,.pc-mid .pc-old{color:#8e9ac0!important}
.pc-mid .pc-off{background:rgba(74,222,128,.15)!important;color:#4ade80!important}
.pc-mid .sx-pid{background:rgba(255,255,255,.08)!important;color:#8e9ac0!important}
.pc-mid .sx-stk{background:rgba(255,255,255,.08)!important;color:#4ade80!important;border-color:transparent!important}
.pc-mid .sx-stk.low{color:#fbbf24!important}
.pc-mid .sx-stk.out{color:#f87171!important}
.pc-mid .inst{color:#4ade80!important}
.catalog .pc.pc-mid .btn.buy{background:#60a5fa!important;color:#0b1226!important;border:0!important}
.catalog .pc.pc-mid .btn.buy:hover{filter:brightness(1.08)}
.catalog .pc.pc-mid .btn.buy:disabled{background:rgba(255,255,255,.1)!important;color:#8e9ac0!important}
/* Category switch: skeleton cards + spinner while products load */
@keyframes sxSpin{to{transform:rotate(360deg)}}
@keyframes sxShim{0%{background-position:-200px 0}100%{background-position:200px 0}}
.sx-ld{display:flex;align-items:center;justify-content:center;gap:10px;padding:10px 0 12px;color:#4338ca;font-size:13.5px;font-weight:600}
.sx-sp{flex:none;width:20px;height:20px;border-radius:50%;border:3px solid #e0e7ff;border-top-color:#4f46e5;animation:sxSpin .7s linear infinite}
.sx-sk{pointer-events:none}
.sx-sk .pc{display:flex;flex-direction:column;gap:10px;padding:14px;background:#fff;border:1px solid #eef0f4;border-radius:14px;min-height:170px}
.sx-b{display:block;height:12px;border-radius:6px;background:linear-gradient(90deg,#f1f5f9 0,#e2e8f0 100px,#f1f5f9 200px);background-size:400px 100%;animation:sxShim 1.2s linear infinite}
.sx-sk .sx-r{display:flex;align-items:center;gap:10px}
.sx-sk .sx-c{flex:none;width:36px;height:36px;border-radius:11px}
#cat-res.busy{opacity:1}
/* Purchase in progress: overlay inside the dialog */
.bx-ld{position:absolute;left:0;right:0;top:0;bottom:0;z-index:5;display:none;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:20px;text-align:center;background:rgba(255,255,255,.96);border-radius:16px}
.bx-sh.busy .bx-ld{display:flex;animation:sxFade .2s ease}
@keyframes sxFade{from{opacity:0}to{opacity:1}}
.bx-ld .sx-sp{width:46px;height:46px;border-width:4px}
.bx-ld b{font-size:15px;color:#1e293b}
.bx-ld small{display:block;color:#64748b;font-size:12.5px;min-height:1.3em;transition:opacity .25s}
.bx-ld .bx-dots{display:flex;gap:5px}
.bx-ld .bx-dots i{width:7px;height:7px;border-radius:50%;background:#818cf8;animation:sxBounce 1s ease-in-out infinite}
.bx-ld .bx-dots i:nth-child(2){animation-delay:.15s}.bx-ld .bx-dots i:nth-child(3){animation-delay:.3s}
@keyframes sxBounce{0%,80%,100%{transform:scale(.6);opacity:.5}40%{transform:scale(1);opacity:1}}
@media(prefers-reduced-motion:reduce){.sx-sp,.sx-b,.bx-ld .bx-dots i{animation-duration:2s}}
</style>
<form class="search" id="catF" method="get" action="/dashboard.php">
  <?= icon('search') ?><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search products or ID" autocomplete="off" maxlength="80">
  <?php if ($c): ?><input type="hidden" name="c" value="<?= $c ?>"><?php endif; ?>
  <button>Search</button>
</form>
<div class="sx-cats" id="catRow">
  <a class="sx-cat sx-all <?= $c ? '' : 'on' ?>" data-c="0" title="All (<?= (int)$total ?>)" href="<?= e(catalog_url(['q' => $q])) ?>"><b>All</b></a>
  <?php foreach ($cats as $k): ?>
  <a class="sx-cat <?= $c === (int)$k['id'] ? 'on' : '' ?>" data-c="<?= (int)$k['id'] ?>" title="<?= e($k['name']) ?> (<?= (int)$k['n'] ?>)" href="<?= e(catalog_url(['c' => (int)$k['id'], 'q' => $q])) ?>"><span class="sx-ci"><?= ico_html((string)$k['icon'], '14px') ?></span><b><?= e($k['name']) ?></b></a>
  <?php endforeach; ?>
</div>
<div id="cat-res" aria-live="polite"><?php catalog_list_html($cats, $rows, $c, $q); ?></div>
<?php buy_modal_html($balance, $c, $q); ?>
<?php }

/** "Buy Now": the dialog is built by JS on the first click and attached to <body> (always above everything, no server-side hand-off needed). */
function buy_modal_html(float $balance, int $c, string $q): void {
    csrf_field();   // makes sure the session has a CSRF token
    $cfg = ['csrf' => (string)($_SESSION['csrf'] ?? ''), 'bal' => round($balance, 2), 'c' => $c ?: '', 'q' => $q, 'cur' => cur_code(), 'rate' => usd_rate()]; ?>
<script>
(function(){
  var SX=window.SX=<?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var CAP=10000, M=null, F,Q,go,msg, cur=null, last=null, hideT=null;
  var $=function(i){return document.getElementById(i)};
  // All maths stay in BDT (what is charged); fmt only changes how an amount is shown.
  var fmt=function(n){
    if(SX.cur==='usd'&&SX.rate>0){ var u=n/SX.rate, a=Math.abs(u), d=a===0?2:(a<0.1?4:(a<1?3:2)); return '$'+u.toLocaleString('en-US',{minimumFractionDigits:d,maximumFractionDigits:d}); }
    return '\u09F3'+n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  };
  var TPL='<div class="bx-bd" data-x></div>'
   +'<form method="post" action="/dashboard.php" class="bx-sh" id="buyF" role="dialog" aria-modal="true" aria-labelledby="bmT" autocomplete="off">'
   +'<input type="hidden" name="csrf" value=""><input type="hidden" name="product_id" id="bmId" value=""><input type="hidden" name="c" value=""><input type="hidden" name="q" value="">'
   +'<div class="bx-h"><span class="bx-ic" id="bmIc"></span><div class="bx-t"><b id="bmT">Product</b><small id="bmSub">Review your order</small></div><button type="button" class="bx-x" data-x aria-label="Close">&times;</button></div>'
   +'<div class="bx-rows"><div class="bx-row"><span>Price</span><b id="bmP"></b></div><div class="bx-row"><span>Available</span><b id="bmA"></b></div></div>'
   +'<label class="bx-l" for="bmQ">Quantity</label>'
   +'<div class="bx-qty"><button type="button" id="bmMinus" aria-label="Decrease quantity">&minus;</button><input type="number" id="bmQ" name="qty" value="1" min="1" step="1" inputmode="numeric" required><button type="button" id="bmPlus" aria-label="Increase quantity">+</button></div>'
   +'<div class="bx-qc" id="bmChips"></div>'
   +'<div class="bx-tot"><span>Total</span><b id="bmTot"></b></div>'
   +'<div class="bx-bal"><span>Wallet balance</span><b id="bmBal"></b></div>'
   +'<div class="bx-msg" id="bmMsg" role="alert" hidden></div>'
   +'<div class="bx-f"><button type="button" class="bx-btn bx-no" data-x>Cancel</button><button type="submit" class="bx-btn bx-ok" id="bmGo">Confirm Purchase</button></div>'
   +'<div class="bx-ld" id="bmLd" role="status" aria-live="polite"><div class="sx-sp"></div><b>Processing your order</b><small id="bmLdT">Placing your order…</small><div class="bx-dots"><i></i><i></i><i></i></div></div>'
   +'</form>';
  var ldT=null;
  function startLoading(){
    F.classList.add('busy'); F.setAttribute('aria-busy','true');
    var steps=['Placing your order…','Checking stock…','Preparing your product…','Almost done, please wait…'], i=0, t=$('bmLdT');
    if(ldT) clearInterval(ldT);
    ldT=setInterval(function(){ i=Math.min(i+1,steps.length-1); t.textContent=steps[i]; },2500);
  }
  function stopLoading(){ if(ldT){ clearInterval(ldT); ldT=null; } F.classList.remove('busy'); F.removeAttribute('aria-busy'); $('bmLdT').textContent='Placing your order…'; }
  function toast(t){
    var d=document.createElement('div');
    d.style.cssText='position:fixed;left:12px;right:12px;top:12px;z-index:4000;background:#7f1d1d;color:#fff;padding:12px 14px;border-radius:12px;font:14px/1.4 system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35)';
    d.textContent=t; document.body.appendChild(d); setTimeout(function(){ if(d.parentNode) d.parentNode.removeChild(d); },9000);
  }
  window.addEventListener('error',function(e){ toast('Script error: '+(e&&e.message?e.message:'unknown')); });
  function maxQty(){ return cur.stock===null ? CAP : Math.max(0, Math.min(cur.stock, CAP)); }
  function showMsg(t,link){
    msg.hidden=!t; msg.innerHTML='';
    if(t){ msg.appendChild(document.createTextNode(t)); if(link){ var a=document.createElement('a'); a.href='/deposits.php'; a.textContent=' Add funds'; msg.appendChild(a); } }
  }
  function render(){
    var raw=parseInt(Q.value,10), q=isNaN(raw)?0:raw, mx=maxQty(), BAL=SX.bal;
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
    var box=$('bmChips'), mx=maxQty(), BAL=SX.bal; box.innerHTML='';
    [1,5,10,50,100].forEach(function(n){ if(n<=mx) addChip(box,n,String(n)); });
    var afford=cur.price>0?Math.floor(Math.round(BAL*100)/Math.round(cur.price*100)):0, best=Math.min(mx,afford);
    if(best>1 && [1,5,10,50,100].indexOf(best)<0) addChip(box,best,'Max ('+best.toLocaleString('en-US')+')');
  }
  function close(){
    if(F.classList.contains('busy')) return;   // order is being processed - don't close
    M.classList.remove('show'); M.setAttribute('aria-hidden','true');
    document.documentElement.style.overflow='';
    if(hideT) clearTimeout(hideT);
    hideT=setTimeout(function(){ M.classList.remove('open'); hideT=null; }, 230);
  }
  function build(){
    if(M) return;
    var d=document.createElement('div'); d.className='bx'; d.id='buyM'; d.setAttribute('aria-hidden','true'); d.innerHTML=TPL;
    document.body.appendChild(d);
    M=d; F=$('buyF'); Q=$('bmQ'); go=$('bmGo'); msg=$('bmMsg');
    Array.prototype.forEach.call(M.querySelectorAll('[data-x]'),function(x){ x.addEventListener('click',close); });
    $('bmMinus').addEventListener('click',function(){ Q.value=Math.max(1,(parseInt(Q.value,10)||1)-1); render(); });
    $('bmPlus').addEventListener('click',function(){ Q.value=Math.min(maxQty(),(parseInt(Q.value,10)||0)+1); render(); });
    Q.addEventListener('input',render);
    F.addEventListener('submit',function(e){
      if(!cur){ e.preventDefault(); return; }
      render(); if(go.disabled){ e.preventDefault(); return; }
      go.disabled=true; go.textContent='Processing\u2026';   // stops double-clicks from buying twice
      startLoading();                                        // loading screen until the next page arrives
    });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape' && M.classList.contains('open')) close(); });
    window.addEventListener('pageshow',function(ev){
      if(ev.persisted){ stopLoading(); M.classList.remove('open','show'); document.documentElement.style.overflow=''; go.disabled=false; go.textContent='Confirm Purchase'; }
    });
  }
  function open(btn){
    last=btn;
    cur={id:btn.dataset.id,name:btn.dataset.name||'',price:parseFloat(btn.dataset.price)||0,unit:btn.dataset.unit||'',stock:btn.dataset.stock===''?null:parseInt(btn.dataset.stock,10)};
    cur.auto=btn.dataset.auto==='1'; cur.old=parseFloat(btn.dataset.old)||0;
    if(hideT){ clearTimeout(hideT); hideT=null; }
    F.elements.csrf.value=SX.csrf; F.elements.c.value=SX.c||''; F.elements.q.value=SX.q||'';
    $('bmId').value=cur.id; $('bmT').textContent=cur.name; $('bmBal').textContent=fmt(SX.bal);
    $('bmSub').textContent=cur.auto?'\u26A1 Instant delivery after purchase':'Review your order';
    $('bmP').textContent=fmt(cur.price)+(cur.unit?' / '+cur.unit:'')+(cur.old>cur.price?' (was '+fmt(cur.old)+')':'');
    $('bmA').textContent=cur.stock===null?'In stock':cur.stock.toLocaleString('en-US')+' pcs';
    var card=btn.closest('.pc'), ic=card&&card.querySelector('.pc-ic'); $('bmIc').innerHTML=ic?ic.innerHTML:'';
    Q.value=1; Q.max=maxQty()||''; chips(); go.textContent='Confirm Purchase'; render();
    M.classList.add('open'); M.setAttribute('aria-hidden','false');
    document.documentElement.style.overflow='hidden';
    void M.offsetWidth;                       // force a reflow so the animation runs
    M.classList.add('show');
    if(!(window.matchMedia&&matchMedia('(pointer:coarse)').matches)) setTimeout(function(){ try{ Q.focus({preventScroll:true}); Q.select(); }catch(e){} }, 150);
  }
  // Called by the onclick of every Buy Now button. Returning false stops the button's own form from posting.
  window.sxBuy=function(b){
    if(b.disabled) return false;
    try{ build(); open(b); return false; }
    catch(err){
      toast('Buy Now error: '+(err&&err.message?err.message:err));
      if(window.console) console.error(err);
      return window.confirm('Buy 1 \u00D7 '+(b.dataset.name||'this product')+'?');   // OK = the card form buys 1 item
    }
  };
})();
</script>
<script>
/* Category tiles + search: swap the product list in place (no full page load) */
(function(){
  var res=document.getElementById('cat-res'), row=document.getElementById('catRow'), form=document.getElementById('catF'), buy=null;
  if(!res||!row||!form||!window.fetch) return;   // without fetch the tiles are normal links and still work
  var qIn=form.elements.q, state={c:0,q:''}, ctl=null, seq=0, tmo=null;
  var on=row.querySelector('.sx-cat.on'); state.c=on?parseInt(on.dataset.c,10)||0:0; state.q=qIn.value.trim();
  if(on) row.scrollLeft+=on.getBoundingClientRect().left-row.getBoundingClientRect().left-16;
  function qs(c,q){ var p=[]; if(c) p.push('c='+c); if(q) p.push('q='+encodeURIComponent(q)); return p.join('&'); }
  function sync(){
    Array.prototype.forEach.call(row.children,function(a){ a.classList.toggle('on',(parseInt(a.dataset.c,10)||0)===state.c); });
    var on2=row.querySelector('.sx-cat.on'); if(on2) row.scrollLeft+=on2.getBoundingClientRect().left-row.getBoundingClientRect().left-16;
    var h=form.elements.c; if(state.c){ if(!h){ h=document.createElement('input'); h.type='hidden'; h.name='c'; form.appendChild(h); } h.value=state.c; } else if(h){ h.remove(); }
    if(window.SX){ SX.c=state.c||''; SX.q=state.q; }
  }
  function skeleton(){
    var card='<div class="pc"><div class="sx-r"><span class="sx-b sx-c"></span><span style="flex:1"><span class="sx-b" style="width:70%;margin-bottom:6px"></span><span class="sx-b" style="width:40%;height:9px"></span></span></div><span class="sx-b" style="width:90%"></span><span class="sx-b" style="width:75%"></span><span class="sx-b" style="width:35%;height:18px;margin-top:6px"></span><span class="sx-b" style="width:100%;height:30px;border-radius:10px"></span></div>';
    return '<div class="sx-ld" role="status"><span class="sx-sp"></span>Loading products…</div><div class="catalog sx-sk">'+card+card+card+card+'</div>';
  }
  function toResults(){ try{ res.scrollIntoView({behavior:'smooth',block:'start'}); }catch(e){} }
  function load(c,q,push,scroll){
    state.c=c; state.q=q; qIn.value=q; sync();
    var s=qs(c,q), url='/dashboard.php'+(s?'?'+s:'');
    if(push!==false) try{ history.pushState({c:c,q:q},'',url); }catch(e){}
    var my=++seq, timedOut=false;
    if(ctl) ctl.abort(); ctl=window.AbortController?new AbortController():null;
    clearTimeout(tmo); tmo=setTimeout(function(){ timedOut=true; if(ctl) ctl.abort(); else if(my===seq) location.href=url; }, 12000);
    var y0=window.pageYOffset; res.style.minHeight=res.offsetHeight+'px'; res.classList.add('busy'); res.setAttribute('aria-busy','true');
    res.innerHTML=skeleton();   // loading interface until the products arrive
    fetch('/dashboard.php?'+(s?s+'&':'')+'ajax=1',{credentials:'same-origin',headers:{'X-Requested-With':'fetch'},signal:ctl?ctl.signal:undefined})
      .then(function(r){ if(!r.ok) throw new Error(r.status); return r.text(); })
      .then(function(h){
        if(my!==seq) return;
        clearTimeout(tmo); res.innerHTML=h; res.classList.remove('busy'); res.removeAttribute('aria-busy'); window.scrollTo(0,y0);
        res.style.minHeight=Math.max(160,Math.round(window.innerHeight-res.getBoundingClientRect().top))+'px';   // list never gets shorter than the screen, so the page can't jump up
      })
      .catch(function(e){ if(my!==seq) return; if(e&&e.name==='AbortError'&&!timedOut) return; clearTimeout(tmo); location.href=url; });
  }
  row.addEventListener('click',function(e){
    var a=e.target.closest('.sx-cat'); if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.button) return;
    e.preventDefault(); var c=parseInt(a.dataset.c,10)||0;
    if(c===state.c&&qIn.value.trim()===state.q) return;
    load(c,qIn.value.trim(),true,false);
  });
  form.addEventListener('submit',function(e){ e.preventDefault(); load(state.c,qIn.value.trim(),true,true); });
  res.addEventListener('click',function(e){ var a=e.target.closest('.res a'); if(!a) return; e.preventDefault(); load(state.c,'',true,false); });
  window.addEventListener('popstate',function(){
    var p=new URLSearchParams(location.search); load(parseInt(p.get('c'),10)||0,(p.get('q')||'').slice(0,80),false,false);
  });
})();
</script>
<?php }
/** MailGen API base URL (admin setting ,   default) */
function mailgen_base(): string {
    return rtrim(setting('mailgen_url', 'https://www.mailgen.shop'), '/');
}

/** MailGen API  POST request  */
function mailgen_call(string $endpoint, array $body, int $timeout = 25): array {
    $url = mailgen_base() . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['success' => false, 'error' => 'Connection failed: ' . $err, '_http' => 0];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Invalid JSON response', '_http' => $code, '_raw' => substr($raw, 0, 200)];
    }
    $data['_http'] = $code;
    if ($code === 429) {
        $data['success'] = false;
        $data['error']   = $data['error'] ?? 'Rate limit exceeded. Try again shortly.';
    }
    return $data;
}

/**
 * Order delivery text  email|password|refresh_token|client_id   
 *  MailGen inbox-read / live-check   
 */
function mailgen_parse_account(string $email): ?array {
    $u = require_login();
    $s = db()->prepare("SELECT delivery FROM orders WHERE user_id = ? AND status = 'delivered' AND delivery IS NOT NULL ORDER BY id DESC LIMIT 200");
    $s->execute([$u['id']]);
    $want = strtolower($email);
    foreach ($s->fetchAll() as $r) {
        foreach (preg_split('/\r\n|\r|\n/', (string)$r['delivery']) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, $want) === false) continue;
            //  email|...    ( | )
            if (substr_count($line, '|') < 1) continue;
            $parts = array_map('trim', explode('|', $line));
            if (strtolower($parts[0]) !== $want) continue;
            return [
                'email'         => $parts[0],
                'password'      => $parts[1] ?? '',
                'refresh_token' => $parts[2] ?? '',
                'client_id'     => $parts[3] ?? '',
                'full'          => $line,
                'has_oauth'     => !empty($parts[2]) && !empty($parts[3]),
                'is_edu'        => str_contains(strtolower($parts[0]), 'emailfake'),
            ];
        }
    }
    return null;
}

/**
 * Input line   3 format :
 *   email|password|refresh_token|client_id        Hotmail/Outlook
 *   email                                          Email Code (edu)
 *   email|refresh_token|client_id|client_secret    2FA (oauth mode)
 */
function mailgen_parse(string $line, string $mode): ?array {
    $line = trim($line);
    if ($line === '') return null;

    if ($mode === 'email') {
        // Edu mail:  email 
        if (!filter_var($line, FILTER_VALIDATE_EMAIL)) return null;
        return ['email' => $line, 'full' => $line, 'mode' => 'email'];
    }

    $parts = array_map('trim', explode('|', $line));
    if (count($parts) < 3) return null;

    if ($mode === 'hotmail') {
        // email|password|refresh_token|client_id
        if (!filter_var($parts[0], FILTER_VALIDATE_EMAIL)) return null;
        return [
            'email'         => $parts[0],
            'password'      => $parts[1] ?? '',
            'refresh_token' => $parts[2] ?? '',
            'client_id'     => $parts[3] ?? '',
            'full'          => implode('|', array_slice($parts, 0, 4)),
            'mode'          => 'hotmail',
        ];
    }

    if ($mode === '2fa') {
        // email|refresh_token|client_id|client_secret  (oauth mode)
        if (!filter_var($parts[0], FILTER_VALIDATE_EMAIL)) return null;
        return [
            'email'         => $parts[0],
            'refresh_token' => $parts[1] ?? '',
            'client_id'     => $parts[2] ?? '',
            'client_secret' => $parts[3] ?? '',
            'full'          => implode('|', array_slice($parts, 0, 4)),
            'mode'          => '2fa',
        ];
    }
    return null;
}

/**   regex    (backup,  API  ) */
function mailgen_extract_code(string $text): ?string {
    // 4-8 digit code
    if (preg_match('/\b(\d{4,8})\b/', $text, $m)) return $m[1];
    // alphanumeric 6-char
    if (preg_match('/\b([A-Z0-9]{6})\b/', $text, $m)) return $m[1];
    return null;
}
/** Base32 (RFC 4648) decode. Ignores spaces, dashes and '=' padding; returns null on invalid characters. */
function base32_decode_str(string $s): ?string {
    $s = strtoupper(preg_replace('/[\s\-=]+/', '', $s));
    if ($s === '' || preg_match('/[^A-Z2-7]/', $s)) return null;
    $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $bits = '';
    foreach (str_split($s) as $ch) $bits .= str_pad(decbin($map[$ch]), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out === '' ? null : $out;
}

/** TOTP (RFC 6238, HMAC-SHA1, 30s step) from a Base32 key such as JBSWY3DPEHPK3PXP. Returns null for an invalid key. */
function totp_code(string $secret, ?int $time = null, int $digits = 6, int $step = 30): ?string {
    $key = base32_decode_str($secret);
    if ($key === null) return null;
    $counter = intdiv($time ?? time(), $step);
    $hash = hash_hmac('sha1', pack('N2', $counter >> 32, $counter & 0xFFFFFFFF), $key, true);
    $o = ord($hash[19]) & 0x0F;
    $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Seconds left before the current TOTP code changes. */
function totp_remaining(int $step = 30): int { return $step - (time() % $step); }
