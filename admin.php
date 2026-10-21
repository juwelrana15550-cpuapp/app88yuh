<?php require __DIR__ . '/lib.php';
require __DIR__ . '/supplier_hot.php';
require __DIR__ . '/payment_methods.php';
require_once __DIR__ . '/sms_inbox.php';     // SMS inbox (Admin > Transactions)
require_once __DIR__ . '/binance_pay.php';   // used Binance / BSC transaction IDs   // payment methods + deposit rules (Admin > Payment methods)
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = ''; $setErr = ''; $prodErr = ''; $catErr = ''; $ordErr = ''; $supErr = ''; $supTest = null; $pmErr = ''; $depErr = ''; $txMsg = ''; $bnErr = ''; $bnTest = null; $expErr = '';
$page = null;     // which admin page to show (set on errors so the user stays where they were)
$reopen = null;    // re-open the edit dialog with the submitted values after a validation error

const ADMIN_PAGES = ['overview', 'orders', 'deposits', 'products', 'categories', 'users', 'settings', 'supplier', 'payments', 'transactions', 'reports', 'allorders', 'expenses'];

/* ============================ ACCOUNTS (profit / cost / expenses) ============================ */

/** One-time schema upgrade: products.cost_price, orders.cost, expenses table. Safe to run repeatedly. */
function acct_schema(PDO $pdo): void {
    if (setting('acct_v', '') === '2') return;
    try {
        foreach (["ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL", "ALTER TABLE users ADD COLUMN last_ip VARCHAR(45) NULL DEFAULT NULL", "ALTER TABLE users ADD INDEX idx_last_ip (last_ip)"] as $q) {
            try { $pdo->exec($q); } catch (PDOException $ex) { if (!in_array((int)($ex->errorInfo[1] ?? 0), [1060, 1061], true)) throw $ex; }
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_notes (user_id INT PRIMARY KEY, note TEXT NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_log (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(30) NOT NULL, user_id INT NULL, detail VARCHAR(255) NOT NULL DEFAULT \'\', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (user_id)) DEFAULT CHARSET=utf8mb4');
        $has = function (string $t, string $c) use ($pdo): bool {
            $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $s->execute([$t, $c]);
            return (int)$s->fetchColumn() > 0;
        };
        if (!$has('products', 'cost_price')) $pdo->exec('ALTER TABLE products ADD COLUMN cost_price DECIMAL(12,2) NULL DEFAULT NULL');
        if (!$has('orders', 'cost'))         $pdo->exec('ALTER TABLE orders ADD COLUMN cost DECIMAL(12,2) NULL DEFAULT NULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS expenses (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL, amount DECIMAL(12,2) NOT NULL, spent_on DATE NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (spent_on)) DEFAULT CHARSET=utf8mb4');
        // v2 fix: orders with qty > 1 need cost = unit cost x qty, so recompute those once
        $pdo->exec('UPDATE orders SET cost = NULL WHERE qty > 1');
        save_setting('acct_v', '2');
    } catch (Throwable $ex) { error_log('[acct schema] ' . $ex->getMessage()); }
}

/**
 * Fill orders.cost for orders that do not have one yet.
 * Manual cost (products.cost_price) wins; otherwise a Supplier 2 product uses supplier USD price x rate.
 * (Cost is recorded the first time the admin panel sees the order, using the cost at that moment.)
 */
function acct_backfill(PDO $pdo): void {
    try {
        $rows = $pdo->query('SELECT o.id, GREATEST(1, o.qty) qty, p.supplier_id, p.cost_price FROM orders o LEFT JOIN products p ON p.id = o.product_id WHERE o.cost IS NULL ORDER BY o.id DESC LIMIT 300')->fetchAll();
        if (!$rows) return;
        $rate = function_exists('bm_rate') ? (float)bm_rate() : 0.0;
        $bmOk = $rate > 0 && function_exists('bm_connected') && bm_connected();
        $cache = []; $calls = 0;
        $up = $pdo->prepare('UPDATE orders SET cost = ? WHERE id = ? AND cost IS NULL');
        foreach ($rows as $r) {
            $c = $r['cost_price'] !== null ? (float)$r['cost_price'] : null;
            $sid = (int)($r['supplier_id'] ?? 0);
            if ($c === null && $sid > 0 && $bmOk) {
                if (!array_key_exists($sid, $cache)) {
                    $cache[$sid] = null;
                    if ($calls < 15) {
                        $calls++;
                        $d = bm_product($sid, 10);
                        if (!isset($d['error']) && (float)$d['price'] > 0) $cache[$sid] = round((float)$d['price'] * $rate, 2);
                    }
                }
                $c = $cache[$sid];
            }
            if ($c !== null) $up->execute([round($c * (int)$r['qty'], 2), (int)$r['id']]);   // price/cost on an order are the totals, so cost = unit cost x qty
        }
    } catch (Throwable $ex) { error_log('[acct backfill] ' . $ex->getMessage()); }
}

/** Write one line to the admin action log (shown on the user profile). */
function alog(PDO $pdo, string $action, ?int $uid, string $detail = ''): void {
    try { $pdo->prepare('INSERT INTO admin_log (action, user_id, detail) VALUES (?,?,?)')->execute([$action, $uid, mb_substr($detail, 0, 255)]); }
    catch (Throwable $ex) { error_log('[admin log] ' . $ex->getMessage()); }
}

/** Round avatar with the first letter of the email and a colour picked from the email. */
function user_avatar(string $email, int $px = 40): string {
    $h = crc32($email) % 360;
    return '<span class="ua" style="width:' . $px . 'px;height:' . $px . 'px;font-size:' . round($px * .42) . 'px;background:hsl(' . $h . ',70%,92%);color:hsl(' . $h . ',55%,30%)">' . e(mb_strtoupper(mb_substr($email, 0, 1))) . '</span>';
}

/** HTML for the quick-manage drawer on the Users list (Overview, Balance, Discount, Security tabs). */
function user_drawer_html(PDO $pdo, int $uid, string $ret): string {
    $q = function (string $sql, array $a = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(); };
    $r = $q(USERS_SELECT . ' WHERE u.id = ?', [$uid]);
    if (!$r) return '<div class="err" style="margin:20px">User not found.</div>';
    $u = $r[0] + $q('SELECT referred_by, has_deposited, last_ip FROM users WHERE id = ?', [$uid])[0];
    $note = (string)($q('SELECT note FROM user_notes WHERE user_id = ?', [$uid])[0]['note'] ?? '');
    $refBy = $u['referred_by'] ? ($q('SELECT id, email FROM users WHERE id = ?', [(int)$u['referred_by']])[0] ?? null) : null;
    $disc = $q('SELECT d.id, d.product_id, d.percent, p.name FROM user_discounts d LEFT JOIN products p ON p.id = d.product_id WHERE d.user_id = ? ORDER BY d.product_id', [$uid]);
    $ords = $q('SELECT id, product_name, price, status, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$uid]);
    $txs  = $q('SELECT type, amount, note, created_at FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 8', [$uid]);
    $prod = $q('SELECT id, name FROM products ORDER BY name LIMIT 500');
    $sameIp = $u['last_ip'] ? (int)$q('SELECT COUNT(*) c FROM users WHERE last_ip = ? AND id <> ?', [$u['last_ip'], $uid])[0]['c'] : 0;
    $canc = (int)$q("SELECT COUNT(*) c FROM orders WHERE user_id = ? AND status = 'cancelled'", [$uid])[0]['c'];
    $rej  = (int)$q("SELECT COUNT(*) c FROM deposits WHERE user_id = ? AND status = 'rejected'", [$uid])[0]['c'];
    $profit = (float)$q("SELECT COALESCE(SUM(CASE WHEN status = 'delivered' AND cost IS NOT NULL THEN price - cost ELSE 0 END),0) p FROM orders WHERE user_id = ?", [$uid])[0]['p'];
    $banned = (int)$u['banned'] === 1;
    $hid = '<input type="hidden" name="uid" value="' . $uid . '"><input type="hidden" name="ret" value="' . e($ret) . '">';
    ob_start(); ?>
<div class="dw-head"><?= user_avatar($u['email'], 52) ?>
  <div class="dw-who"><b><?= e($u['email']) ?></b><span>ID <?= $uid ?> · joined <?= e(substr((string)$u['created_at'], 0, 10)) ?></span></div>
  <span class="pill <?= $banned ? 'bad' : 'good' ?>"><i></i><?= $banned ? 'Blocked' : 'Active' ?></span></div>
<div class="dw-bal"><small>Current balance</small><b><?= money($u['coins']) ?></b></div>
<nav class="dw-tabs"><button type="button" data-tab="ov" class="on">Overview</button><button type="button" data-tab="bal">Balance</button><button type="button" data-tab="disc">Discount</button><button type="button" data-tab="sec">Security &amp; note</button></nav>

<section data-pane="ov">
  <div class="dw-tiles">
    <div><small>Deposited</small><b><?= money($u['dep']) ?></b></div>
    <div><small>Spent</small><b><?= money($u['spent']) ?></b></div>
    <div><small>Orders</small><b><?= (int)$u['ords'] ?></b></div>
    <div><small>Profit from user</small><b class="<?= $profit < 0 ? 'neg' : 'pos' ?>"><?= money($profit) ?></b></div>
    <div><small>Referrals</small><b><?= (int)$u['refs'] ?></b></div>
    <div><small>Cancelled / rejected</small><b><?= $canc ?> / <?= $rej ?></b></div>
  </div>
  <?php if ($sameIp || $canc >= 3 || $rej >= 2): ?><div class="dw-warn"><?php if ($sameIp) echo '⚠ ' . $sameIp . ' other account(s) on the same IP<br>'; if ($canc >= 3) echo '⚠ ' . $canc . ' cancelled orders<br>'; if ($rej >= 2) echo '⚠ ' . $rej . ' rejected deposits'; ?></div><?php endif; ?>
  <dl class="dw-info">
    <dt>Email</dt><dd><?= e($u['email']) ?></dd>
    <dt>Last login</dt><dd><?= $u['last_login_at'] ? e($u['last_login_at']) : '<span class="mut">not recorded yet</span>' ?></dd>
    <dt>IP address</dt><dd><?= $u['last_ip'] ? e($u['last_ip']) : '<span class="mut">—</span>' ?></dd>
    <dt>Referred by</dt><dd><?= $refBy ? '<a href="/admin.php?p=users&u=' . (int)$refBy['id'] . '">' . e($refBy['email']) . '</a>' : '<span class="mut">—</span>' ?></dd>
    <dt>First deposit</dt><dd><?= $u['has_deposited'] ? 'Done' : '<span class="mut">Not yet</span>' ?></dd>
    <dt>Discounts</dt><dd><?= $disc ? count($disc) . ' active' : '<span class="mut">none</span>' ?></dd>
  </dl>
  <h4>Latest orders</h4>
  <?php foreach ($ords as $o): ?><div class="dw-row"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?></b><small><?= e($o['created_at']) ?></small></div><div class="dw-r"><?= money($o['price']) ?><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></div></div>
  <?php endforeach; if (!$ords) echo '<p class="mut">No orders yet.</p>'; ?>
</section>

<section data-pane="bal" hidden>
  <form method="post"><?= csrf_field() . $hid ?>
    <div class="dw-seg"><label><input type="radio" name="dir" value="add" checked><span>＋ Add money</span></label><label><input type="radio" name="dir" value="sub"><span>− Take money</span></label></div>
    <label class="dw-l">Amount (৳)</label><input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required class="dw-big">
    <div class="dw-chips"><?php foreach ([50, 100, 500, 1000, 5000] as $c): ?><button type="button" data-dir="add" data-amt="<?= $c ?>">+<?= $c ?></button><?php endforeach; ?><?php foreach ([100, 500] as $c): ?><button type="button" data-dir="sub" data-amt="<?= $c ?>" class="m">−<?= $c ?></button><?php endforeach; ?></div>
    <label class="dw-l">Reason <small>(saved in the log)</small></label><input type="text" name="reason" maxlength="120" required placeholder="e.g. Manual deposit, refund, bonus">
    <button class="btn dw-btn" name="u_adjust" value="1" onclick="return confirm('Change this user\'s balance?')">Update balance</button>
  </form>
  <h4>Wallet history</h4>
  <?php foreach ($txs as $t): ?><div class="dw-row"><div><b><?= e($t['type']) ?></b><small><?= e((string)$t['note']) ?: e($t['created_at']) ?></small></div><div class="dw-r <?= (float)$t['amount'] < 0 ? 'neg' : 'pos' ?>"><?= (float)$t['amount'] > 0 ? '+' : '' ?><?= money($t['amount']) ?></div></div>
  <?php endforeach; if (!$txs) echo '<p class="mut">No wallet activity.</p>'; ?>
</section>

<section data-pane="disc" hidden>
  <?php foreach ($disc as $d): ?><div class="dw-row"><div><b><?= e(pct_fmt((float)$d['percent'])) ?>% off</b><small><?= (int)$d['product_id'] === 0 ? 'All products' : e($d['name'] ?? ('Product #' . (int)$d['product_id'])) ?></small></div>
    <form method="post" class="inl"><?= csrf_field() . $hid ?><button class="btn sm red" name="u_disc_del" value="<?= (int)$d['id'] ?>">Remove</button></form></div>
  <?php endforeach; if (!$disc) echo '<p class="mut">No discount for this user.</p>'; ?>
  <h4>Give a discount</h4>
  <form method="post"><?= csrf_field() . $hid ?>
    <label class="dw-l">Applies to</label><select name="product_id"><option value="0">All products</option><?php foreach ($prod as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <label class="dw-l">Percent off</label><input type="number" name="percent" step="0.01" min="0.01" max="99" placeholder="e.g. 10" required>
    <button class="btn dw-btn" name="u_disc_add" value="1">Save discount</button>
  </form>
</section>

<section data-pane="sec" hidden>
  <div class="dw-box <?= $banned ? 'bad' : '' ?>"><b><?= $banned ? 'This user is blocked' : 'Block this user' ?></b>
    <p><?= $banned ? 'They cannot log in or buy. Their balance stays.' : 'They are signed out at once and cannot log in or buy.' ?></p>
    <form method="post"><?= csrf_field() . $hid ?><input type="text" name="reason" maxlength="120" placeholder="Reason (optional)">
    <button class="btn <?= $banned ? 'ok-b' : 'red' ?> dw-btn" name="u_ban" value="1" onclick="return confirm('<?= $banned ? 'Unblock' : 'Block' ?> this user?')"><?= $banned ? 'Unblock user' : 'Block user' ?></button></form></div>
  <h4>Reset password</h4>
  <form method="post" autocomplete="off"><?= csrf_field() . $hid ?><input type="text" name="new_pw" minlength="8" placeholder="New password (8+ characters)" autocomplete="off" required>
  <button class="btn ghost dw-btn" name="u_pw" value="1" onclick="return confirm('Change this user\'s password?')">Change password</button></form>
  <h4>Private note</h4>
  <form method="post"><?= csrf_field() . $hid ?><textarea name="note" maxlength="2000" rows="4" placeholder="Only admins see this"><?= e($note) ?></textarea>
  <button class="btn dw-btn" name="u_note" value="1">Save note</button></form>
</section>
<a class="dw-full" href="/admin.php?p=users&u=<?= $uid ?>">Open full profile →</a>
<?php return ob_get_clean();
}

/** WHERE clause + params for the Users list (search + filter). */
function users_where(array $g): array {
    $w = []; $a = [];
    $q = trim((string)($g['q'] ?? ''));
    if ($q !== '') { $w[] = '(u.email LIKE ? OR u.id = ? OR u.last_ip = ?)'; array_push($a, '%' . $q . '%', (int)$q, $q); }
    switch ($g['f'] ?? '') {
        case 'banned': $w[] = 'u.banned = 1'; break;
        case 'nodep':  $w[] = 'u.has_deposited = 0'; break;
        case 'bal':    $w[] = 'u.coins > 0'; break;
        case 'nobuy':  $w[] = 'NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.status <> \'cancelled\')'; break;
        case 'disc':   $w[] = 'EXISTS (SELECT 1 FROM user_discounts d WHERE d.user_id = u.id)'; break;
        case 'new7':   $w[] = 'u.created_at >= (NOW() - INTERVAL 7 DAY)'; break;
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
}
const USERS_SELECT = "SELECT u.id, u.email, u.coins, u.banned, u.created_at, u.last_login_at,
    (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.id) refs,
    (SELECT COALESCE(SUM(amount),0) FROM deposits d WHERE d.user_id = u.id AND d.status = 'approved') dep,
    (SELECT COALESCE(SUM(price),0) FROM orders o WHERE o.user_id = u.id AND o.status <> 'cancelled') spent,
    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status <> 'cancelled') ords FROM users u";
const USERS_SORT = ['new' => 'u.id DESC', 'bal' => 'u.coins DESC', 'spent' => 'spent DESC', 'dep' => 'dep DESC', 'ords' => 'ords DESC', 'login' => 'u.last_login_at DESC'];

/** Delivered-order totals for an inclusive date range (Y-m-d .. Y-m-d). profit only counts orders whose cost is known. */
function acct_kpi(PDO $pdo, string $from, string $to): array {
    $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(price),0) rev, COALESCE(SUM(cost),0) cost,
        COALESCE(SUM(CASE WHEN cost IS NOT NULL THEN price - cost ELSE 0 END),0) profit, COALESCE(SUM(cost IS NULL),0) unk
        FROM orders WHERE status = 'delivered' AND created_at >= ? AND created_at < ?");
    $s->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
    return $s->fetch() ?: ['n' => 0, 'rev' => 0, 'cost' => 0, 'profit' => 0, 'unk' => 0];
}

function acct_date($v, string $def): string {
    $v = (string)$v;
    return (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false) ? $v : $def;
}

/** Orders list used by the "All orders" page and its CSV export. */
function acct_orders(PDO $pdo, array $g, int $limit): array {
    $w = []; $a = [];
    $st = in_array($g['st'] ?? '', ['pending', 'delivered', 'cancelled'], true) ? $g['st'] : '';
    if ($st !== '') { $w[] = 'o.status = ?'; $a[] = $st; }
    $q = trim((string)($g['q'] ?? ''));
    if ($q !== '') { $w[] = '(u.email LIKE ? OR o.product_name LIKE ? OR o.id = ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l, (int)$q); }
    if (($g['from'] ?? '') !== '') { $w[] = 'o.created_at >= ?'; $a[] = acct_date($g['from'], '1970-01-01') . ' 00:00:00'; }
    if (($g['to'] ?? '') !== '')   { $w[] = 'o.created_at < ?';  $a[] = date('Y-m-d', strtotime(acct_date($g['to'], date('Y-m-d')) . ' +1 day')) . ' 00:00:00'; }
    $s = $pdo->prepare('SELECT o.*, u.email FROM orders o LEFT JOIN users u ON u.id = o.user_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY o.id DESC LIMIT ' . (int)$limit);
    $s->execute($a);
    return $s->fetchAll();
}

/** Redirect to an admin page, optionally with a success message. */
function go(string $p, ?string $ok = null): void {
    if ($ok) flash('ok', $ok);
    header('Location: /admin.php' . ($p === 'overview' ? '' : '?p=' . $p));
    exit;
}

function product_fields(): array {
    $st = trim($_POST['stock'] ?? '');
    $sp = explode('|', trim($_POST['supplier_link'] ?? ''), 2);
    $src  = $_POST['supplier_src'] ?? '';                       // '' = manual, 'hot' = Supplier 1, 'bm' = Supplier 2 (bulkmail.shop)
    $okSp = $src === 'hot' && count($sp) === 2 && preg_match('/^[\w.\-]{1,40}$/', $sp[0]) && preg_match('/^[\w.\-]{1,40}$/', $sp[1]);
    $bmId = $src === 'bm' ? max(0, (int)($_POST['bm_id'] ?? 0)) : 0;
    return [
        'src'     => $src === 'hot' ? 'hot' : ($src === 'bm' ? 'bm' : ''),
        'sup_id'  => $bmId ?: null,
        'name'    => trim($_POST['pname'] ?? ''),
        'desc'    => mb_substr(trim($_POST['pdesc'] ?? ''), 0, 500),
        'price'   => round((float)($_POST['pprice'] ?? 0), 2),
        'cat'     => ((int)($_POST['category_id'] ?? 0)) ?: null,
        'stock'   => $st === '' ? null : max(0, (int)$st),   // blank = unlimited
        'unit'    => mb_substr(trim($_POST['unit'] ?? ''), 0, 20),
        'popular' => empty($_POST['popular']) ? 0 : 1,
        'style'   => (($_POST['card_style'] ?? '') === 'midnight') ? 'midnight' : '',   // '' = classic card
        'cost'    => trim((string)($_POST['pcost'] ?? '')) === '' ? null : max(0.0, round((float)$_POST['pcost'], 2)),   // blank = unknown / follow supplier
        'sup_t'   => $okSp ? $sp[0] : null,
        'sup_a'   => $okSp ? $sp[1] : null,
        // Supplier 2 only: keep name / description + Possible Domain in step with the supplier; show the domain line (default on)
        'an'      => ($src === 'bm' && !empty($_POST['sup_an'])) ? 1 : 0,
        'ad'      => ($src === 'bm' && !empty($_POST['sup_ad'])) ? 1 : 0,
        'sd'      => ($src === 'bm' && empty($_POST['sup_sd'])) ? 0 : 1,
        // price follows the supplier: supplier USD price x BDT rate x (1 + markup %)
        'ap'      => ($src === 'bm' && !empty($_POST['sup_ap'])) ? 1 : 0,
        'mk'      => ($src === 'bm') ? min(1000.0, max(0.0, round((float)($_POST['sup_mk'] ?? 0), 2))) : 0.0,
    ];
}
function category_fields(): array {
    $ic = trim($_POST['cicon'] ?? '');
    if (strncmp($ic, 'app:', 4) === 0) {
        if (!isset(app_icons()[substr($ic, 4)])) $ic = 'app:shop';   // unknown key -> default
    } else {
        $ic = mb_substr($ic, 0, 8) ?: 'app:shop';                    // plain emoji
    }
    return [
        'name' => trim($_POST['cname'] ?? ''),
        'icon' => $ic,
        'sort' => (int)($_POST['csort'] ?? 0),
    ];
}

if (!empty($_SESSION['admin'])) { suphot_schema(); bm_schema(db()); acct_schema(db()); }
// CSV export of orders (All orders page): cost and profit included, opens fine in Excel
if (!empty($_SESSION['admin']) && isset($_GET['uq'])) {   // quick-manage drawer content (loaded by the Users list)
    $retQ = (string)($_GET['ret'] ?? '');
    if (!preg_match('#^/admin\.php\?p=users(&[\w%.+=@\-]*)?$#', $retQ)) $retQ = '/admin.php?p=users';
    $retQ = preg_replace('/&(open|t)=[^&]*/', '', $retQ);
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo user_drawer_html(db(), (int)$_GET['uq'], $retQ); exit;
}
if (!empty($_SESSION['admin']) && ($_GET['export'] ?? '') === 'users') {
    $pdoU = db(); [$uw, $ua] = users_where($_GET);
    $so = USERS_SORT[$_GET['s'] ?? ''] ?? USERS_SORT['new'];
    $st = $pdoU->prepare(USERS_SELECT . $uw . ' ORDER BY ' . $so . ' LIMIT 50000'); $st->execute($ua);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['ID', 'Email', 'Balance', 'Total deposited', 'Total spent', 'Orders', 'Referrals', 'Blocked', 'Joined', 'Last login']);
    foreach ($st as $r) fputcsv($o, [$r['id'], $r['email'], $r['coins'], $r['dep'], $r['spent'], $r['ords'], $r['refs'], $r['banned'] ? 'yes' : '', $r['created_at'], $r['last_login_at']]);
    fclose($o); exit;
}
// CSV export of local stock (products without a supplier): one row per stock line. Filters: pid (one product), c (category id, 0 = uncategorised), s (unsold | sold | all)
if (!empty($_SESSION['admin']) && ($_GET['export'] ?? '') === 'stock') {
    $pdoS = db();
    $w = ["(p.supplier_id IS NULL OR p.supplier_id = 0)", "(p.supplier_type IS NULL OR p.supplier_type = '')"]; $a = [];
    if (($_GET['pid'] ?? '') !== '') { $w[] = 'p.id = ?'; $a[] = (int)$_GET['pid']; }
    if (($_GET['c'] ?? '') !== '') { if ((int)$_GET['c'] === 0) $w[] = '(p.category_id IS NULL OR p.category_id = 0)'; else { $w[] = 'p.category_id = ?'; $a[] = (int)$_GET['c']; } }
    $sf = $_GET['s'] ?? 'unsold';
    if ($sf === 'unsold') $w[] = 'si.order_id IS NULL'; elseif ($sf === 'sold') $w[] = 'si.order_id IS NOT NULL'; else $sf = 'all';
    $st = $pdoS->prepare('SELECT si.id, p.id pid, p.name, c.name cat, si.line, si.order_id, si.created_at FROM stock_items si JOIN products p ON p.id = si.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.id, si.id LIMIT 200000');
    $st->execute($a);
    header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="local-stock-' . $sf . '-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Product ID', 'Product', 'Category', 'Stock line', 'Status', 'Order ID', 'Added']);
    foreach ($st as $r) fputcsv($o, [$r['pid'], $r['name'], $r['cat'] ?? '', $r['line'], $r['order_id'] === null ? 'Unsold' : 'Sold', $r['order_id'] ?? '', $r['created_at']]);
    fclose($o); exit;
}
if (!empty($_SESSION['admin']) && ($_GET['export'] ?? '') === 'orders') {
    $pdoX = db(); acct_backfill($pdoX);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Order', 'Date', 'User', 'Product', 'Status', 'Price', 'Cost', 'Profit']);
    foreach (acct_orders($pdoX, $_GET, 50000) as $r) {
        fputcsv($o, [$r['id'], $r['created_at'], $r['email'], $r['product_name'], $r['status'], $r['price'], $r['cost'], $r['cost'] === null ? '' : round((float)$r['price'] - (float)$r['cost'], 2)]);
    }
    fclose($o); exit;
}
// Live lookup for the product form: Supplier 2 product id -> name / price / description (used to auto-fill the dialog)
if (!empty($_SESSION['admin']) && isset($_GET['bm_lookup'])) {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => 'Connect Supplier 2 first (Admin > Supplier API).'];
    if (bm_connected()) {
        $d = bm_product((int)$_GET['bm_lookup'], 10);
        if (isset($d['error'])) { $out = ['ok' => false, 'error' => $d['error']]; }
        else {
            $t = (string)$d['description'];
            if ($d['domain'] !== '') $t = trim(preg_replace('/\(?\s*possible\s+domains?\s*[:(\-][^)\n]*\)?/i', '', $t));
            $rate = bm_rate();
            $out = ['ok' => true, 'name' => mb_substr((string)$d['name'], 0, 80), 'desc' => mb_substr($t, 0, 500), 'domain' => (string)$d['domain'],
                    'usd' => $d['price'], 'rate_set' => $rate > 0, 'all' => bm_price_all(),
                    'price' => ($rate > 0 && $d['price'] > 0) ? round($d['price'] * $rate * (1 + bm_markup() / 100), 2) : null];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['admin_logout'])) { unset($_SESSION['admin']); header('Location: /admin.php'); exit; }

    if (isset($_POST['admin_pw'])) {
        if (hash_equals($adminPw, (string)$_POST['admin_pw'])) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: /admin.php'); exit; }
        sleep(1); $err = 'Wrong password.';

    } elseif (!empty($_SESSION['admin'])) {
        $pdo = db();

        if (isset($_POST['save_pm'])) {
            $page = 'payments';
            $editId = trim((string)($_POST['pm_edit_id'] ?? ''));
            $isEdit = $editId !== '';
            if ($isEdit && !isset(pm_all()[$editId])) go('payments');
            [$f, $pmErr] = pm_fields_from_post($isEdit ? $editId : null);
            $pmV = [   // values to put back in the dialog if something goes wrong
                'name' => $f['name'], 'acct' => $f['acct'], 'acct_label' => $f['acct_label'], 'sub' => $f['sub'], 'ussd' => $f['ussd'],
                'tid_label' => $f['tid_label'], 'color' => $f['color'], 'is_usd' => $f['is_usd'], 'enabled' => $f['enabled'],
                'min_amt' => trim((string)($_POST['pm_min'] ?? '')), 'max_amt' => trim((string)($_POST['pm_max'] ?? '')),
                'fee_pct' => trim((string)($_POST['pm_fee'] ?? '')), 'bonus_pct' => trim((string)($_POST['pm_bonus'] ?? '')),
                'steps' => (string)($_POST['pm_steps'] ?? ''), 'notice' => $f['notice'],
                'has_logo' => $isEdit ? (int)!empty(pm_all()[$editId]['has_logo']) : 0,
            ];
            if ($pmErr !== '') {
                $reopen = ['t' => 'pm', 'id' => $editId, 'v' => $pmV];
            } else {
                $pid = pm_store($isEdit ? $editId : null, $f);
                $hasFile = ($_FILES['pm_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($hasFile) {
                    $logoErr = pm_save_logo($pid, $_FILES['pm_logo']);
                    if ($logoErr !== '') {
                        $pmErr = 'The method was saved, but the logo was not: ' . $logoErr;
                        $pmV['has_logo'] = (int)!empty(pm_all()[$pid]['has_logo']);
                        $reopen = ['t' => 'pm', 'id' => $pid, 'v' => $pmV];
                    }
                } elseif ($isEdit && !empty($_POST['pm_remove_logo'])) {
                    pm_remove_logo($pid);
                }
                if ($pmErr === '') go('payments', $isEdit ? 'Payment method saved.' : 'Payment method added.');
            }

        } elseif (isset($_POST['pm_toggle'])) {
            pm_toggle((string)$_POST['pm_toggle']);
            go('payments', 'Payment method updated.');

        } elseif (isset($_POST['pm_up']) || isset($_POST['pm_down'])) {
            pm_move((string)($_POST['pm_up'] ?? $_POST['pm_down']), isset($_POST['pm_up']) ? -1 : 1);
            go('payments');

        } elseif (isset($_POST['pm_delete'])) {
            pm_delete((string)$_POST['pm_delete']);
            go('payments', 'Payment method deleted.');

        } elseif (isset($_POST['save_dep'])) {
            $page = 'payments';
            $mn = (float)str_replace(',', '', trim((string)($_POST['dep_min'] ?? '')));
            $mx = (float)str_replace(',', '', trim((string)($_POST['dep_max'] ?? '')));
            $pn = (int)($_POST['dep_pending'] ?? 0);
            $chips = [];
            foreach (explode(',', (string)($_POST['dep_chips'] ?? '')) as $c) {
                $c = (int)trim($c);
                if ($c >= 1 && $c <= 10000000 && !in_array($c, $chips, true)) $chips[] = $c;
            }
            if ($mn < 1) $depErr = 'Minimum deposit must be at least 1.';
            elseif ($mx < $mn) $depErr = 'Maximum deposit must be bigger than the minimum.';
            elseif ($pn < 1 || $pn > 50) $depErr = 'Pending deposits per user must be between 1 and 50.';
            else {
                save_setting('dep_on', empty($_POST['dep_on']) ? '0' : '1');
                save_setting('dep_min', (string)$mn);
                save_setting('dep_max', (string)$mx);
                save_setting('dep_max_pending', (string)$pn);
                save_setting('dep_chips', implode(',', array_slice($chips, 0, 8)));
                save_setting('dep_notice', mb_substr(trim((string)($_POST['dep_notice'] ?? '')), 0, 300));
                save_setting('dep_off_msg', mb_substr(trim((string)($_POST['dep_off_msg'] ?? '')), 0, 300));
                go('payments', 'Deposit rules saved.');
            }

        } elseif (isset($_POST['save_bn']) || isset($_POST['test_bn'])) {
            $page = 'payments';
            $rpc = trim((string)($_POST['bn_rpc'] ?? ''));
            if ($rpc !== '' && !preg_match('#^https://[^\s]+$#i', $rpc)) {
                $bnErr = 'The BSC RPC URL must start with https:// (or leave it empty).';
            } else {
                $k = trim((string)($_POST['bn_key'] ?? '')); $sc = trim((string)($_POST['bn_secret'] ?? ''));
                if (!empty($_POST['bn_remove_keys'])) { save_setting('bn_api_key', ''); save_setting('bn_api_secret', ''); }
                else {
                    if ($k !== '') save_setting('bn_api_key', $k);
                    if ($sc !== '') save_setting('bn_api_secret', $sc);
                }
                $ids = array_keys(pm_all());
                foreach (['bn_pay_method', 'bn_bsc_method'] as $sk) {
                    $v = (string)($_POST[$sk] ?? '');
                    save_setting($sk, in_array($v, $ids, true) ? $v : '');
                }
                save_setting('bn_bsc_conf', (string)max(1, min(200, (int)($_POST['bn_conf'] ?? 15) ?: 15)));
                save_setting('bn_bsc_rpc', $rpc);
                $bh = (string)($_POST['bn_base'] ?? '');
                save_setting('bn_api_base', in_array($bh, BN_HOSTS, true) ? $bh : 'api.binance.com');
                if (isset($_POST['test_bn'])) $bnTest = bn_test();
                else go('payments', 'Binance settings saved.');
            }

        } elseif (isset($_POST['sms_recheck'])) {
            $page = 'transactions';
            sms_schema($pdo);
            $sid = (int)$_POST['sms_recheck'];
            $did = 0;
            try { $did = sms_match_pending($pdo, $sid, $bonus); } catch (Throwable $ex) { error_log('[sms recheck] ' . $ex->getMessage()); }
            if ($did) go('transactions', 'SMS matched: deposit #' . $did . ' was approved and the balance added.');
            $txMsg = sms_diagnose($pdo, $sid);

        } elseif (isset($_POST['sms_recheck_all'])) {
            sms_schema($pdo);
            $n = 0;
            foreach ($pdo->query('SELECT id FROM sms_inbox WHERE used = 0 ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                try { if (sms_match_pending($pdo, (int)$sid, $bonus)) $n++; } catch (Throwable $ex) { error_log('[sms recheck] ' . $ex->getMessage()); }
            }
            go('transactions', $n ? $n . ' deposit(s) matched an SMS and were approved.' : 'No waiting deposit matches an unused SMS right now.');

        } elseif (isset($_POST['u_ban']) || isset($_POST['u_adjust']) || isset($_POST['u_pw']) || isset($_POST['u_note']) || isset($_POST['u_disc_add']) || isset($_POST['u_disc_del'])) {
            // ---- user management actions (all come from the user profile) ----
            $page = 'users';
            $tuid = (int)($_POST['uid'] ?? 0);
            $tu = $pdo->prepare('SELECT id, email, banned FROM users WHERE id = ?'); $tu->execute([$tuid]); $tu = $tu->fetch();
            if (!$tu) go('users', 'User not found.');
            $back = 'users&u=' . $tuid;
            $retP = (string)($_POST['ret'] ?? '');   // came from the quick-manage drawer: go back to the list and reopen the drawer
            if (preg_match('#^/admin\.php\?p=users(&[\w%.+=@\-]*)?$#', $retP)) {
                $retP = preg_replace('/&(open|t)=[^&]*/', '', $retP);
                $tabK = (isset($_POST['u_adjust'])) ? 'bal' : ((isset($_POST['u_disc_add']) || isset($_POST['u_disc_del'])) ? 'disc' : 'sec');
                $back = substr($retP, strlen('/admin.php?p=')) . '&open=' . $tuid . '&t=' . $tabK;
            }
            $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 120);

            if (isset($_POST['u_ban'])) {
                $nb = $tu['banned'] ? 0 : 1;
                $pdo->prepare('UPDATE users SET banned = ? WHERE id = ?')->execute([$nb, $tuid]);
                alog($pdo, $nb ? 'ban' : 'unban', $tuid, $reason);
                go($back, $nb ? 'User blocked. They are signed out and cannot log in.' : 'User unblocked.');

            } elseif (isset($_POST['u_adjust'])) {
                $amt = round((float)str_replace(',', '', (string)($_POST['amount'] ?? '0')), 2);
                $dir = ($_POST['dir'] ?? '') === 'sub' ? -1 : 1;
                $amt = abs($amt) * $dir;
                if ($amt == 0 || abs($amt) > 10000000) flash('err', 'Enter an amount above 0.');
                elseif ($reason === '') flash('err', 'Write a reason (it is saved in the log).');
                else {
                    $okAdj = with_tx($pdo, function (PDO $pdo) use ($tuid, $amt, $reason) {
                        $b = $pdo->prepare('SELECT coins FROM users WHERE id = ? FOR UPDATE'); $b->execute([$tuid]);
                        if ((float)$b->fetchColumn() + $amt < 0) return false;
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$amt, $tuid]);
                        add_tx($pdo, $tuid, 'adjust', $amt, 'Admin: ' . $reason);
                        return true;
                    });
                    if ($okAdj) { alog($pdo, 'balance', $tuid, ($amt > 0 ? '+' : '') . $amt . ' · ' . $reason); go($back, 'Balance updated.'); }
                    flash('err', 'Not enough balance to subtract that much.');
                }
                go($back);

            } elseif (isset($_POST['u_pw'])) {
                $np = (string)($_POST['new_pw'] ?? '');
                if (strlen($np) < 8) flash('err', 'Password must be at least 8 characters.');
                else {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($np, PASSWORD_DEFAULT), $tuid]);
                    alog($pdo, 'password', $tuid, 'password reset by admin');
                    go($back, 'Password changed. Tell the user the new password.');
                }
                go($back);

            } elseif (isset($_POST['u_note'])) {
                $nt = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000);
                if ($nt === '') $pdo->prepare('DELETE FROM user_notes WHERE user_id = ?')->execute([$tuid]);
                else $pdo->prepare('INSERT INTO user_notes (user_id, note) VALUES (?,?) ON DUPLICATE KEY UPDATE note = VALUES(note)')->execute([$tuid, $nt]);
                go($back, 'Note saved.');

            } elseif (isset($_POST['u_disc_add'])) {
                $pc = round((float)($_POST['percent'] ?? 0), 2); $pid = max(0, (int)($_POST['product_id'] ?? 0));
                if ($pc < 0.01 || $pc > 99) flash('err', 'Discount must be between 0.01 and 99 %.');
                else {
                    $pdo->prepare('INSERT INTO user_discounts (user_id, product_id, percent) VALUES (?,?,?) ON DUPLICATE KEY UPDATE percent = VALUES(percent)')->execute([$tuid, $pid, $pc]);
                    alog($pdo, 'discount', $tuid, $pc . '% on ' . ($pid ? 'product #' . $pid : 'all products'));
                    go($back, 'Discount saved.');
                }
                go($back);

            } else { // u_disc_del
                $pdo->prepare('DELETE FROM user_discounts WHERE id = ? AND user_id = ?')->execute([(int)$_POST['u_disc_del'], $tuid]);
                alog($pdo, 'discount', $tuid, 'discount removed');
                go($back, 'Discount removed.');
            }

        } elseif (isset($_POST['add_expense'])) {
            $page = 'expenses';
            $t = mb_substr(trim((string)($_POST['ex_title'] ?? '')), 0, 120);
            $a = round((float)str_replace(',', '', (string)($_POST['ex_amount'] ?? '0')), 2);
            $d = acct_date($_POST['ex_date'] ?? '', date('Y-m-d'));
            if ($t === '' || $a <= 0) $expErr = 'Enter a title and an amount above 0.';
            else {
                $pdo->prepare('INSERT INTO expenses (title, amount, spent_on) VALUES (?,?,?)')->execute([$t, $a, $d]);
                go('expenses', 'Expense added.');
            }

        } elseif (isset($_POST['del_expense'])) {
            $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([(int)$_POST['del_expense']]);
            go('expenses', 'Expense deleted.');

        } elseif (isset($_POST['save_settings'])) {
            $page = 'settings';
            $name = trim($_POST['site_name'] ?? '');
            $uploads = []; $removes = [];
            if ($name === '' || mb_strlen($name) > 40) {
                $setErr = 'Site name is required (max 40 characters).';
            } else {
                foreach (['logo' => 1048576, 'banner' => 2097152] as $k => $max) {
                    if (!empty($_POST['remove_' . $k])) { $removes[] = $k; continue; }
                    $f = $_FILES[$k] ?? null;
                    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) continue;
                    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > $max) { $setErr = ucfirst($k) . ' is too large (max ' . ($max / 1048576) . ' MB).'; break; }
                    $info = @getimagesize($f['tmp_name']);
                    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                        $setErr = ucfirst($k) . ' must be a PNG, JPG, WEBP or GIF image.'; break;
                    }
                    $uploads[$k] = [$info['mime'], file_get_contents($f['tmp_name'])];
                }
            }
            if (!$setErr) { // nothing is saved unless everything is valid
                save_setting('site_name', $name);
                save_setting('tagline', mb_substr(trim($_POST['tagline'] ?? ''), 0, 120));
                save_setting('subtitle', mb_substr(trim($_POST['subtitle'] ?? ''), 0, 300));
                foreach (['support_url', 'telegram_url', 'whatsapp_url'] as $k) {
                    $v = trim($_POST[$k] ?? '');
                    save_setting($k, preg_match('#^https?://#i', $v) ? $v : '');
                }
                // Update notice popup
                $pTitle = mb_substr(trim((string)($_POST['popup_title'] ?? '')), 0, 80);
                $pText  = mb_substr(trim((string)($_POST['popup_text'] ?? '')), 0, 1500);
                $pBtn   = mb_substr(trim((string)($_POST['popup_btn'] ?? '')), 0, 20);
                $pType  = in_array($_POST['popup_type'] ?? '', ['info', 'success', 'warning', 'danger'], true) ? $_POST['popup_type'] : 'info';
                $pOn    = (!empty($_POST['popup_on']) && $pText !== '') ? '1' : '0';
                $pChanged = $pTitle !== setting('popup_title', '') || $pText !== setting('popup_text', '') || $pType !== setting('popup_type', 'info');
                save_setting('popup_title', $pTitle); save_setting('popup_text', $pText);
                save_setting('popup_btn', $pBtn);     save_setting('popup_type', $pType);
                save_setting('popup_on', $pOn);
                // a new version number makes every visitor see the popup again (new text, or "show again" ticked)
                if ($pOn === '1' && ($pChanged || !empty($_POST['popup_again']) || setting('popup_v', '') === '')) save_setting('popup_v', (string)time());
                foreach ($removes as $k) $pdo->prepare('DELETE FROM media WHERE k = ?')->execute([$k]);
                foreach ($uploads as $k => [$mime, $bin]) {
                    $st = $pdo->prepare('REPLACE INTO media (k, mime, data) VALUES (?,?,?)');
                    $st->bindValue(1, $k); $st->bindValue(2, $mime); $st->bindValue(3, $bin, PDO::PARAM_LOB);
                    $st->execute();
                }
                go('settings', 'Settings saved.');
            }

        } elseif (isset($_POST['bm_sync_now']) || isset($_POST['bm_save_rate'])) {
            $page = 'supplier';
            if (isset($_POST['bm_save_rate'])) {
                $rt = round((float)str_replace(',', '.', (string)($_POST['bm_rate'] ?? '0')), 4);
                if ($rt < 0 || $rt > 100000) { $supErr = 'Enter a valid rate (৳ for 1 USD).'; }
                else {
                    $mkp = round(max(0.0, min(1000.0, (float)str_replace(',', '.', (string)($_POST['bm_markup'] ?? '0')))), 2);
                    save_setting('bm_rate', (string)$rt);
                    save_setting('bm_markup', (string)$mkp);
                    save_setting('bm_price_all', empty($_POST['bm_price_all']) ? '0' : '1');
                    if (bm_connected()) bm_sync_details($pdo, 1000);
                    go('supplier', $rt > 0 ? 'Saved. Prices updated.' : 'Saved (no rate set, so price sync is paused).');
                }
            } else {
                if (!bm_connected()) { $supErr = 'Connect Supplier 2 first.'; }
                else {
                    save_setting('bm_last_error', '');
                    bm_sync_stock($pdo, true);
                    $nChg = bm_sync_details($pdo, 1000);
                    $eNow = (string)setting('bm_last_error', '');
                    go('supplier', 'Sync finished. ' . $nChg . ' product(s) updated.' . ($eNow !== '' ? ' ' . $eNow : ''));
                }
            }

        } elseif (isset($_POST['bm_connect']) || isset($_POST['bm_disconnect'])) {
            $page = 'supplier';
            if (isset($_POST['bm_disconnect'])) { bm_disconnect($pdo); go('supplier', 'Supplier 2 disconnected.'); }
            [$okB, $msgB] = bm_connect($pdo, (string)($_POST['bm_key'] ?? ''));
            if ($okB) go('supplier', $msgB);
            $supErr = $msgB;

        } elseif (isset($_POST['save_supplier']) || isset($_POST['test_supplier'])) {
            $page = 'supplier';
            unset($_SESSION['sup_cat']);
            if (isset($_POST['save_supplier'])) {
                $u = rtrim(trim($_POST['supplier_url'] ?? ''), '/');
                $k = trim($_POST['supplier_key'] ?? '');
                if ($u !== '' && !preg_match('#^https://[^\s?\#]+$#i', $u)) {
                    $supErr = 'API URL must start with https:// (example: https://supplier-site.com/api/v1).';
                } else {
                    save_setting('suphot_url', $u);
                    if (!empty($_POST['remove_key'])) save_setting('suphot_key', '');
                    elseif ($k !== '') save_setting('suphot_key', $k);
                    save_setting('suphot_auto', empty($_POST['supplier_auto']) ? '0' : '1');
                    go('supplier', 'Supplier settings saved.');
                }
            } else {
                $supTest = suphot_call('GET', '/balance', [], null, 15);
            }

        } elseif (isset($_POST['add_product']) || isset($_POST['update_product'])) {
            $page = 'products';
            $isEdit = isset($_POST['update_product']);
            $f = product_fields();
            $linkErr = '';
            if ($f['src'] === 'hot' && !$f['sup_t']) $linkErr = 'Choose a Supplier 1 product.';
            if ($f['src'] === 'bm') {
                if (!$f['sup_id']) $linkErr = 'Enter the Supplier 2 (bulkmail.shop) product ID.';
                elseif (!bm_connected()) $linkErr = 'Connect Supplier 2 first (Admin > Supplier API).';
                else { $chk = bm_product((int)$f['sup_id']); if (isset($chk['error'])) $linkErr = $chk['error']; }
            }
            if ($f['name'] === '' || mb_strlen($f['name']) > 80 || $f['price'] <= 0 || $linkErr !== '') {
                $prodErr = $linkErr !== '' ? $linkErr : 'Enter a product name (max 80 chars) and a price above 0.';
                $reopen = ['t' => 'product', 'id' => $isEdit ? (int)$_POST['update_product'] : 0, 'v' => [
                    'name' => $f['name'], 'desc' => $f['desc'], 'price' => (string)($_POST['pprice'] ?? ''), 'unit' => $f['unit'],
                    'cat' => $f['cat'] ?? '', 'stock' => $f['stock'] ?? '', 'pop' => $f['popular'], 'cs' => $f['style'], 'cost' => $f['cost'] ?? '',
                    'src' => $f['src'], 'sup' => $f['sup_t'] ? $f['sup_t'] . '|' . $f['sup_a'] : '', 'bmid' => $f['sup_id'] ?? '', 'an' => $f['an'], 'ad' => $f['ad'], 'sd' => $f['sd'], 'ap' => $f['ap'], 'mk' => $f['mk'],
                ]];
            } else {
                // A product is linked to exactly one supplier: the other supplier's link is always cleared.
                if ($f['src'] === 'bm') { $f['sup_t'] = null; $f['sup_a'] = null; } else { $f['sup_id'] = null; }
                if ($f['src'] === 'bm' && $f['stock'] === null) $f['stock'] = (int)($chk['stock'] ?? 0);   // live stock is synced from the supplier anyway
                if ($isEdit) {
                    $pdo->prepare('UPDATE products SET name=?, description=?, price=?, category_id=?, stock=?, unit=?, popular=?, supplier_type=?, supplier_account=?, supplier_id=?, sup_auto_name=?, sup_auto_desc=?, sup_show_dom=?, sup_auto_price=?, sup_markup=?, cost_price=?, card_style=? WHERE id=?')
                        ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['sup_t'], $f['sup_a'], $f['sup_id'], $f['an'], $f['ad'], $f['sd'], $f['ap'], $f['mk'], $f['cost'], $f['style'], (int)$_POST['update_product']]);
                    $msg = 'Product saved.';
                    $_SESSION['recent_prod'] = ['id' => (int)$_POST['update_product'], 'l' => 'Recently edited', 't' => time()];
                } else {
                    $pdo->prepare('INSERT INTO products (name, description, price, category_id, stock, unit, popular, supplier_type, supplier_account, supplier_id, sup_auto_name, sup_auto_desc, sup_show_dom, sup_auto_price, sup_markup, cost_price, card_style) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['sup_t'], $f['sup_a'], $f['sup_id'], $f['an'], $f['ad'], $f['sd'], $f['ap'], $f['mk'], $f['cost'], $f['style']]);
                    $msg = 'Product added.';
                    $_SESSION['recent_prod'] = ['id' => (int)$pdo->lastInsertId(), 'l' => 'Just added', 't' => time()];
                }
                $syncBm = $f['src'] === 'bm'; $syncHot = $f['src'] === 'hot';
                $syncDet = $syncBm && ($f['an'] || $f['ad'] || $f['ap']) && function_exists('bm_sync_details');
                if ($syncBm || $syncHot) {
                    // Answer the browser first, then talk to the supplier, so saving does not wait for the supplier's server.
                    ignore_user_abort(true);
                    register_shutdown_function(function () use ($pdo, $syncBm, $syncHot, $syncDet) {
                        if (function_exists('session_write_close')) @session_write_close();   // release the session so the next page is not blocked
                        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                        @set_time_limit(60);
                        try {
                            if ($syncBm) bm_sync_stock($pdo, true);
                            if ($syncDet) bm_sync_details($pdo, 30);   // name / description / domain
                            if ($syncHot) suphot_sync_stock($pdo, true);
                        } catch (Throwable $ex) { error_log('[admin supplier sync] ' . $ex->getMessage()); }
                    });
                }
                go('products', $msg);
            }

        } elseif (isset($_POST['add_category']) || isset($_POST['update_category'])) {
            $page = 'categories';
            $isEdit = isset($_POST['update_category']);
            $f = category_fields();
            if ($f['name'] === '' || mb_strlen($f['name']) > 40) {
                $catErr = 'Enter a category name (max 40 chars).';
                $reopen = ['t' => 'category', 'id' => $isEdit ? (int)$_POST['update_category'] : 0, 'v' => ['name' => $f['name'], 'icon' => $f['icon'], 'sort' => $f['sort']]];
            } elseif ($isEdit) {
                $pdo->prepare('UPDATE categories SET name=?, icon=?, sort_order=? WHERE id=?')->execute([$f['name'], $f['icon'], $f['sort'], (int)$_POST['update_category']]);
                go('categories', 'Category saved.');
            } else {
                $pdo->prepare('INSERT INTO categories (name, icon, sort_order) VALUES (?,?,?)')->execute([$f['name'], $f['icon'], $f['sort']]);
                go('categories', 'Category added.');
            }

        } elseif (isset($_POST['toggle_category'])) {
            $pdo->prepare('UPDATE categories SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['toggle_category']]);
            go('categories', 'Category visibility updated.');

        } elseif (isset($_POST['delete_category'])) {
            $cid = (int)$_POST['delete_category'];
            with_tx($pdo, function (PDO $pdo) use ($cid) {
                $pdo->prepare('UPDATE products SET category_id = NULL WHERE category_id = ?')->execute([$cid]);
                $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$cid]);
            });
            go('categories', 'Category deleted.');

        } elseif (isset($_POST['toggle_product'])) {
            $pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['toggle_product']]);
            go('products', 'Product visibility updated.');

        } elseif (isset($_POST['delete_product'])) {
            $dpid = (int)$_POST['delete_product'];
            $pc = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE product_id = ? AND status = 'pending'");
            $pc->execute([$dpid]);
            if ((int)$pc->fetchColumn() > 0) {
                flash('err', 'This product still has pending orders. Deliver or cancel them first, or just hide the product.');
            } else {
                with_tx($pdo, function (PDO $pdo) use ($dpid) {
                    $pdo->prepare('DELETE FROM stock_items WHERE product_id = ? AND order_id IS NULL')->execute([$dpid]);   // unsold stock only; delivered items stay with their orders
                    $pdo->prepare('DELETE FROM user_discounts WHERE product_id = ?')->execute([$dpid]);
                    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$dpid]);
                });
                if (!empty($_SESSION['recent_prod']) && (int)$_SESSION['recent_prod']['id'] === $dpid) unset($_SESSION['recent_prod']);
                if (function_exists('icon_gc')) { try { icon_gc($pdo); } catch (Throwable $ex) {} }
                flash('ok', 'Product deleted. Past orders keep their details.');
            }
            go('products');

        } elseif (isset($_POST['supplier_deliver'])) {
            $oidS = (int)($_POST['order_id'] ?? 0);
            $ps = $pdo->prepare('SELECT p.supplier_id FROM orders o JOIN products p ON p.id = o.product_id WHERE o.id = ?');
            $ps->execute([$oidS]);
            if ((int)$ps->fetchColumn() > 0) {   // linked to Supplier 2 (bulkmail.shop)
                if (!bm_connected()) { $okS = false; $msgS = 'Supplier 2 is not connected (Admin > Supplier API).'; }
                else { $rb = bm_fulfil($pdo, $oidS); $okS = $rb['status'] !== 'pending'; $msgS = $rb['status'] === 'pending' ? ($rb['msg'] . ' Check Supplier 2 before retrying.') : $rb['msg']; }
            } else {                              // Supplier 1
                [$okS, $msgS] = suphot_fulfill_order($oidS);
            }
            if ($okS) go('orders', $msgS);
            $page = 'orders'; $ordErr = $msgS;

        } elseif (isset($_POST['deliver']) || isset($_POST['cancel_order'])) {
            $oid = (int)($_POST['order_id'] ?? 0);
            with_tx($pdo, function (PDO $pdo) use ($oid) {
                $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending' FOR UPDATE");
                $st->execute([$oid]);
                if ($o = $st->fetch()) {
                    if (isset($_POST['deliver'])) {
                        $pdo->prepare("UPDATE orders SET status='delivered', delivery=? WHERE id=?")->execute([mb_substr(trim($_POST['delivery'] ?? ''), 0, 4000), $oid]);
                    } else {
                        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$o['price'], $o['user_id']]);
                        add_tx($pdo, (int)$o['user_id'], 'refund', (float)$o['price'], 'Refund for order #' . $oid);
                        $pdo->prepare('UPDATE products SET stock = stock + 1 WHERE id = ? AND stock IS NOT NULL')->execute([$o['product_id']]);
                    }
                }
            });
            go('orders', isset($_POST['deliver']) ? 'Order marked as delivered.' : 'Order cancelled and refunded.');

        } elseif (isset($_POST['id'], $_POST['action'])) {
            with_tx($pdo, function (PDO $pdo) use ($bonus) {
                $st = $pdo->prepare("SELECT * FROM deposits WHERE id = ? AND status = 'pending' FOR UPDATE");
                $st->execute([(int)$_POST['id']]);
                if ($d = $st->fetch()) {
                    if ($_POST['action'] === 'approve') {
                        $pdo->prepare("UPDATE deposits SET status='approved' WHERE id=?")->execute([$d['id']]);
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$d['amount'], $d['user_id']]);
                        add_tx($pdo, (int)$d['user_id'], 'deposit', (float)$d['amount'], 'Deposit #' . $d['id']);
                        $us = $pdo->prepare('SELECT referred_by, has_deposited FROM users WHERE id = ? FOR UPDATE');
                        $us->execute([$d['user_id']]); $usr = $us->fetch();
                        if (!$usr['has_deposited']) {
                            $pdo->prepare('UPDATE users SET has_deposited = 1 WHERE id = ?')->execute([$d['user_id']]);
                            if ($usr['referred_by']) {
                                $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$bonus, $usr['referred_by']]);
                                add_tx($pdo, (int)$usr['referred_by'], 'bonus', $bonus, 'Referral bonus');
                            }
                        }
                    } else {
                        $pdo->prepare("UPDATE deposits SET status='rejected' WHERE id=?")->execute([$d['id']]);
                    }
                }
            });
            go('deposits', $_POST['action'] === 'approve' ? 'Deposit approved.' : 'Deposit rejected.');
        }
    }
}

/* ============================== Login screen ============================== */
if (empty($_SESSION['admin'])) {
    header_html('Admin Login', null, 'auth'); ?>
<div class="card">
<?= logo_html('🛡️') ?>
<h2>Admin Panel</h2>
<p class="sub">Enter the admin password to continue</p>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Password</label>
<div class="pw"><input type="password" id="ap" name="admin_pw" required><button type="button" data-toggle="#ap">Show</button></div>
<button class="btn">Login</button></form>
</div>
<?php footer_html(); exit; }

/* ============================== Data ============================== */
$pdo = db();
if ($page === null) {
    $page = $_GET['p'] ?? 'overview';
    if (!in_array($page, ADMIN_PAGES, true)) $page = 'overview';
}
/* ---- accounts data ---- */
$today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
$ac = []; $rp = [];
if (in_array($page, ['overview', 'reports', 'allorders', 'expenses'], true)) acct_backfill($pdo);
$acQ = function (string $sql, array $a = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(); };
$knownProfit = 'COALESCE(SUM(CASE WHEN o.cost IS NOT NULL THEN o.price - o.cost ELSE 0 END),0)';
if ($page === 'overview') {
    $ac['today'] = acct_kpi($pdo, $today, $today);
    $ac['month'] = acct_kpi($pdo, substr($today, 0, 8) . '01', $today);
    // hourly series for today's two graphs (sales = pending + delivered orders, deposits = approved)
    $t0 = $today . ' 00:00:00'; $t1 = date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00';
    $ac['hs'] = array_fill(0, 24, 0.0); $ac['hd'] = array_fill(0, 24, 0.0); $ac['ns'] = 0; $ac['nd'] = 0;
    foreach ($acQ("SELECT HOUR(created_at) h, COUNT(*) n, SUM(price) s FROM orders WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ? GROUP BY HOUR(created_at)", [$t0, $t1]) as $r) { $ac['hs'][(int)$r['h']] = (float)$r['s']; $ac['ns'] += (int)$r['n']; }
    foreach ($acQ("SELECT HOUR(created_at) h, COUNT(*) n, SUM(amount) s FROM deposits WHERE status = 'approved' AND created_at >= ? AND created_at < ? GROUP BY HOUR(created_at)", [$t0, $t1]) as $r) { $ac['hd'][(int)$r['h']] = (float)$r['s']; $ac['nd'] += (int)$r['n']; }
    $ac['nowH'] = (int)$pdo->query('SELECT HOUR(NOW())')->fetchColumn();
}
if ($page === 'reports') {
    $rp['from'] = acct_date($_GET['from'] ?? '', substr($today, 0, 8) . '01');
    $rp['to']   = acct_date($_GET['to'] ?? '', $today);
    if ($rp['from'] > $rp['to']) [$rp['from'], $rp['to']] = [$rp['to'], $rp['from']];
    $rf = $rp['from'] . ' 00:00:00'; $rt = date('Y-m-d', strtotime($rp['to'] . ' +1 day')) . ' 00:00:00';
    $rp['k'] = acct_kpi($pdo, $rp['from'], $rp['to']);
    $rg = "o.status = 'delivered' AND o.created_at >= ? AND o.created_at < ?";
    $rp['daily'] = $acQ("SELECT DATE(o.created_at) d, COUNT(*) n, SUM(o.price) rev, $knownProfit profit FROM orders o WHERE $rg GROUP BY DATE(o.created_at) ORDER BY d DESC", [$rf, $rt]);
    $rp['prod'] = $acQ("SELECT o.product_id, MAX(o.product_name) nm, COUNT(*) n, SUM(o.price) rev, $knownProfit profit, COALESCE(SUM(o.cost IS NULL),0) unk FROM orders o WHERE $rg GROUP BY o.product_id ORDER BY profit DESC, rev DESC LIMIT 50", [$rf, $rt]);
    $rp['cat'] = $acQ("SELECT c.name nm, COUNT(*) n, SUM(o.price) rev, $knownProfit profit FROM orders o LEFT JOIN products p ON p.id = o.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE $rg GROUP BY c.id, c.name ORDER BY rev DESC", [$rf, $rt]);
    $rp['sup'] = $acQ("SELECT CASE WHEN p.supplier_id > 0 THEN 'Supplier 2' WHEN p.supplier_type IS NOT NULL AND p.supplier_type <> '' THEN 'Supplier 1' ELSE 'Manual' END src, COUNT(*) n, SUM(o.price) rev, COALESCE(SUM(o.cost),0) cost, $knownProfit profit FROM orders o LEFT JOIN products p ON p.id = o.product_id WHERE $rg GROUP BY src ORDER BY rev DESC", [$rf, $rt]);
    $rp['buyers'] = $acQ("SELECT u.email, COUNT(*) n, SUM(o.price) rev, $knownProfit profit FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE $rg GROUP BY o.user_id, u.email ORDER BY rev DESC LIMIT 10", [$rf, $rt]);
    // cash flow
    $x = $acQ("SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM deposits WHERE status = 'approved' AND created_at >= ? AND created_at < ?", [$rf, $rt])[0];
    $rp['dep'] = ['n' => (int)$x['n'], 's' => (float)$x['s']];
    $x = $acQ("SELECT COUNT(*) n, COALESCE(SUM(price),0) s FROM orders WHERE status = 'cancelled' AND created_at >= ? AND created_at < ?", [$rf, $rt])[0];
    $rp['ref'] = ['n' => (int)$x['n'], 's' => (float)$x['s']];
    $x = $acQ("SELECT COUNT(*) n, COALESCE(SUM(price),0) s FROM orders WHERE status = 'pending'")[0];
    $rp['pend'] = ['n' => (int)$x['n'], 's' => (float)$x['s']];
    $rp['bonus'] = null;   // referral bonuses paid (only if the wallet log table is readable)
    try { $rp['bonus'] = (float)$acQ("SELECT COALESCE(SUM(amount),0) s FROM transactions WHERE type = 'bonus' AND created_at >= ? AND created_at < ?", [$rf, $rt])[0]['s']; } catch (Throwable $ex) { $rp['bonus'] = null; }
    $rp['pm'] = [];   // deposits by payment method (only if deposits has a method column)
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM deposits')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['method', 'payment_method', 'pm', 'method_id', 'gateway'] as $mc) {
            if (in_array($mc, $cols, true)) { $rp['pm'] = $acQ("SELECT `$mc` m, COUNT(*) n, SUM(amount) s FROM deposits WHERE status = 'approved' AND created_at >= ? AND created_at < ? GROUP BY `$mc` ORDER BY s DESC", [$rf, $rt]); break; }
        }
    } catch (Throwable $ex) { $rp['pm'] = []; }
    $rp['exp'] = (float)$acQ('SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE spent_on BETWEEN ? AND ?', [$rp['from'], $rp['to']])[0]['s'];
    $rp['stockval'] = (float)$acQ('SELECT COALESCE(SUM(stock * cost_price),0) s FROM products WHERE stock IS NOT NULL AND cost_price IS NOT NULL')[0]['s'];
    $rp['lowstock'] = $acQ('SELECT id, name, stock FROM products WHERE stock IS NOT NULL AND stock <= 5 AND active = 1 AND supplier_id IS NULL AND (supplier_type IS NULL OR supplier_type = \'\') ORDER BY stock, id LIMIT 20');
    $rp['dead'] = $acQ("SELECT p.id, p.name FROM products p WHERE p.active = 1 AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.product_id = p.id AND $rg) ORDER BY p.id DESC LIMIT 20", [$rf, $rt]);
}
$aoRows = []; $expRows = []; $expMonth = 0.0;
if ($page === 'allorders') $aoRows = acct_orders($pdo, $_GET, 300);
if ($page === 'expenses') {
    $expRows = $acQ('SELECT * FROM expenses ORDER BY spent_on DESC, id DESC LIMIT 200');
    $expMonth = (float)$acQ('SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE spent_on BETWEEN ? AND ?', [substr($today, 0, 8) . '01', $today])[0]['s'];
}
/* ---- users page data ---- */
$um = []; $pu = null; $ul = [];
if ($page === 'users') {
    $uid0 = (int)($_GET['u'] ?? 0);
    if ($uid0 > 0) {
        $s0 = $pdo->prepare(USERS_SELECT . ' WHERE u.id = ?'); $s0->execute([$uid0]); $pu = $s0->fetch() ?: null;
        if (!$pu) { flash('err', 'User not found.'); header('Location: /admin.php?p=users'); exit; }
        $full = $acQ('SELECT referred_by, has_deposited, last_ip FROM users WHERE id = ?', [$uid0])[0];
        $pu += $full;
        $um['note'] = (string)($acQ('SELECT note FROM user_notes WHERE user_id = ?', [$uid0])[0]['note'] ?? '');
        $um['ref_by'] = $pu['referred_by'] ? ($acQ('SELECT id, email FROM users WHERE id = ?', [(int)$pu['referred_by']])[0] ?? null) : null;
        $um['refs'] = $acQ('SELECT id, email, has_deposited, created_at FROM users WHERE referred_by = ? ORDER BY id DESC LIMIT 30', [$uid0]);
        $um['same_ip'] = $pu['last_ip'] ? $acQ('SELECT id, email, banned FROM users WHERE last_ip = ? AND id <> ? ORDER BY id DESC LIMIT 20', [$pu['last_ip'], $uid0]) : [];
        $um['disc'] = $acQ('SELECT d.id, d.product_id, d.percent, p.name FROM user_discounts d LEFT JOIN products p ON p.id = d.product_id WHERE d.user_id = ? ORDER BY d.product_id', [$uid0]);
        $um['orders'] = $acQ('SELECT id, product_name, price, cost, status, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$uid0]);
        $um['deps'] = $acQ('SELECT id, amount, status, note, created_at FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$uid0]);
        $um['tx'] = $acQ('SELECT type, amount, note, created_at FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$uid0]);
        $um['log'] = $acQ('SELECT action, detail, created_at FROM admin_log WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$uid0]);
        $x = $acQ("SELECT COALESCE(SUM(status = 'cancelled'),0) canc, COALESCE(SUM(CASE WHEN status = 'delivered' AND cost IS NOT NULL THEN price - cost ELSE 0 END),0) profit FROM orders WHERE user_id = ?", [$uid0])[0];
        $um['canc'] = (int)$x['canc']; $um['profit'] = (float)$x['profit'];
        $um['rej'] = (int)$acQ("SELECT COUNT(*) c FROM deposits WHERE user_id = ? AND status = 'rejected'", [$uid0])[0]['c'];
        $um['prods'] = $acQ('SELECT id, name FROM products ORDER BY name LIMIT 500');
    } else {
        $uPer = 50;
        $uPg = max(1, (int)($_GET['pg'] ?? 1));
        [$uw, $ua] = users_where($_GET);
        $uSort = array_key_exists($_GET['s'] ?? '', USERS_SORT) ? $_GET['s'] : 'new';
        $um['total'] = (int)$acQ('SELECT COUNT(*) c FROM users u' . $uw, $ua)[0]['c'];
        $um['pages'] = max(1, (int)ceil($um['total'] / $uPer));
        $uPg = min($uPg, $um['pages']);
        $ul = $acQ(USERS_SELECT . $uw . ' ORDER BY ' . USERS_SORT[$uSort] . ' LIMIT ' . $uPer . ' OFFSET ' . (($uPg - 1) * $uPer), $ua);
        $x = $acQ("SELECT COUNT(*) a, COALESCE(SUM(banned),0) b, COALESCE(SUM(created_at >= CURDATE()),0) t, COALESCE(SUM(coins > 0),0) c FROM users")[0];
        $um['st'] = $x;
        $um['log'] = $acQ('SELECT l.action, l.detail, l.created_at, u.email FROM admin_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 10');
    }
}
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pend = $pdo->query("SELECT COUNT(*) c FROM deposits WHERE status='pending'")->fetch();
$smsC = ['all' => 0, 'unused' => 0, 'used' => 0, 'other' => 0, 'bn' => 0, 'last' => null]; $smsRows = []; $bnRows = [];
$tf = in_array($_GET['f'] ?? '', ['all', 'unused', 'used', 'other', 'binance'], true) ? $_GET['f'] : 'all';
try {
    sms_schema($pdo);
    $x = $pdo->query("SELECT COUNT(*) a, COALESCE(SUM(kind='in' AND used=0),0) u, COALESCE(SUM(used=1),0) d, COALESCE(SUM(kind<>'in'),0) o, MAX(created_at) m FROM sms_inbox")->fetch();
    $smsC = ['all' => (int)$x['a'], 'unused' => (int)$x['u'], 'used' => (int)$x['d'], 'other' => (int)$x['o'], 'bn' => 0, 'last' => $x['m']];
    if ($page === 'transactions') {
        bn_ensure_table($pdo);
        $smsC['bn'] = (int)$pdo->query('SELECT COUNT(*) FROM auto_pay_used')->fetchColumn();
        if ($tf === 'binance') {
            $bnRows = $pdo->query('SELECT a.*, u.email FROM auto_pay_used a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 200')->fetchAll();
        } else {
            $w = ['unused' => "WHERE s.kind = 'in' AND s.used = 0", 'used' => 'WHERE s.used = 1', 'other' => "WHERE s.kind <> 'in'", 'all' => ''][$tf];
            $smsRows = $pdo->query("SELECT s.*, u.email FROM sms_inbox s LEFT JOIN deposits d ON d.id = s.deposit_id LEFT JOIN users u ON u.id = d.user_id $w ORDER BY s.id DESC LIMIT 200")->fetchAll();
        }
    }
} catch (Throwable $ex) { error_log('[admin transactions] ' . $ex->getMessage()); }
$pendOrders = $pdo->query("SELECT o.*, u.email, p.supplier_type AS sup_t, p.supplier_id AS sup_id FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN products p ON p.id = o.product_id WHERE o.status='pending' ORDER BY o.id")->fetchAll();
$coins = (float)$pdo->query('SELECT COALESCE(SUM(coins),0) FROM users')->fetchColumn();
$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$supOpts = $page === 'products' ? suphot_options($products) : '';
if ($page === 'products') { bm_sync_stock($pdo); suphot_sync_stock($pdo); }   // Supplier 2 products: live stock (at most once a minute)
$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY c.sort_order, c.id')->fetchAll();
$catById = []; foreach ($cats as $k) $catById[(int)$k['id']] = $k;
$catOpts = function ($sel) use ($cats) {
    $h = '<option value="">— No category —</option>';
    foreach ($cats as $k) {
        $h .= '<option value="' . (int)$k['id'] . '"' . ((int)$sel === (int)$k['id'] ? ' selected' : '') . '>' . e(trim(cat_icon_text($k['icon']) . ' ' . $k['name'])) . '</option>';
    }
    return $h;
};
$rows = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status='pending' ORDER BY d.id")->fetchAll();
$users = [];   // the Users page now loads its own paged list (see "users page data")
$hist = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status <> 'pending' ORDER BY d.id DESC LIMIT 30")->fetchAll();

/** Admin-only icons (everything else comes from icon() in lib.php). */
function ai(string $n): string {
    static $x = [
        'grid'    => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'sms'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'card'    => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        'sliders' => '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
        'pencil'  => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
        'trash'   => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'chev'    => '<path d="m6 9 6 6 6-6"/>',
        'plug'    => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'ext'     => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    ];
    return isset($x[$n]) ? '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . $x[$n] . '</svg>' : icon($n);
}

$nav = [
    ''        => [['overview', 'Overview', 'gauge', 0]],
    'Sales'   => [['orders', 'Orders', 'cart', count($pendOrders)], ['deposits', 'Deposits', 'coins', (int)$pend['c']], ['payments', 'Payment methods', 'card', 0], ['transactions', 'Transactions', 'sms', (int)$smsC['unused']]],
    'Accounts' => [['reports', 'Reports', 'gauge', 0], ['allorders', 'All orders', 'cart', 0], ['expenses', 'Expenses', 'coins', 0]],
    'Catalog' => [['products', 'Products', 'bag', 0], ['categories', 'Categories', 'grid', 0]],
    'Manage'  => [['users', 'Users', 'users', 0], ['settings', 'Site settings', 'sliders', 0], ['supplier', 'Supplier API', 'plug', 0]],
];
$titles = [
    'overview'   => ['Overview', 'Store activity at a glance'],
    'orders'     => ['Orders', 'Deliver or cancel pending orders'],
    'reports'    => ['Reports', 'Sales, cost, profit and cash flow for any date range'],
    'allorders'  => ['All orders', 'Every order with cost and profit, searchable, with CSV export'],
    'expenses'   => ['Expenses', 'Running costs (hosting, ads, SMS...) that are taken off your profit'],
    'deposits'   => ['Deposits', 'Approve or reject wallet deposits'],
    'products'   => ['Products', 'Add, edit, hide and organise what you sell'],
    'categories' => ['Categories', 'Group products and choose their icons'],
    'users'      => ['Users', 'Search, filter, block, adjust balance and manage every user'],
    'settings'   => ['Site settings', 'Name, headline, support links, logo and banner'],
    'transactions' => ['Transactions', 'SMS received from your wallets, with used and unused TrxIDs'],
    'payments'   => ['Payment methods', 'Accounts, limits, fees and the deposit on/off switch'],
    'supplier'   => ['Supplier API', 'Connect two suppliers; each product is bought from the supplier it is linked to'],
];
$sv = fn(string $k) => e($_POST[$k] ?? setting($k));
$lg = media_url('logo');
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titles[$page][0]) ?> - Admin - <?= e(site_name()) ?></title>
<link rel="stylesheet" href="/admin.css?v=<?= is_file(__DIR__ . '/admin.css') ? filemtime(__DIR__ . '/admin.css') : 1 ?>">
<?= icon_tags() ?>
<style>
body.ld .main{opacity:.55;transition:opacity .15s;pointer-events:none}
body.ld::after{content:"";position:fixed;top:0;left:-40%;height:3px;width:40%;background:#5b4bff;z-index:9999;animation:ldbar .9s ease-in-out infinite}
@keyframes ldbar{0%{left:-40%}100%{left:100%}}
</style>
</head><body class="adm">
<?= app_icon_sprite() ?>
<div class="shell">
<div class="shade" onclick="document.body.classList.remove('menu')"></div>
<aside class="side">
  <div class="s-brand">
    <div class="lg"><?php if ($lg): ?><img src="<?= e($lg) ?>" alt=""><?php else: ?><?= cat_icon('app:shop', '40px') ?><?php endif; ?></div>
    <div><b><?= e(site_name()) ?></b><small>Admin panel</small></div>
  </div>
  <nav class="s-nav">
  <?php foreach ($nav as $grp => $items): ?>
    <?php if ($grp !== ''): ?><div class="s-grp"><?= e($grp) ?></div><?php endif; ?>
    <?php foreach ($items as [$key, $label, $ic, $count]): ?>
      <a href="/admin.php<?= $key === 'overview' ? '' : '?p=' . $key ?>" class="<?= $page === $key ? 'on' : '' ?>"<?= $page === $key ? ' aria-current="page"' : '' ?>><?= ai($ic) ?><span><?= e($label) ?></span><?php if ($count): ?><span class="cnt"><?= (int)$count ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </nav>
  <div class="s-foot">
    <a href="/" target="_blank" rel="noopener"><?= ai('ext') ?><span>View website</span></a>
    <form method="post" action="/admin.php"><?= csrf_field() ?><button class="lo" name="admin_logout" value="1"><?= ai('logout') ?><span>Logout</span></button></form>
  </div>
</aside>

<main class="main">
<div id="reopenData" hidden data-v="<?= e(json_encode($reopen, JSON_UNESCAPED_UNICODE)) ?>"></div>
<div class="mtop"><button type="button" aria-label="Menu" onclick="document.body.classList.toggle('menu')"><?= ai('menu') ?></button><b><?= e($titles[$page][0]) ?></b></div>
<div class="ph">
  <div><h1><?= e($titles[$page][0]) ?></h1><p><?= e($titles[$page][1]) ?></p></div>
  <?php if ($page === 'products'): ?><button type="button" class="btn" id="addProduct"><?= ai('plus') ?>Add product</button><?php endif; ?>
  <?php if ($page === 'categories'): ?><button type="button" class="btn" id="addCategory"><?= ai('plus') ?>Add category</button><?php endif; ?>
  <?php if ($page === 'payments'): ?><button type="button" class="btn" id="addPm"><?= ai('plus') ?>Add method</button><?php endif; ?>
</div>
<?php flash_html(); ?>

<?php /* ============================ OVERVIEW ============================ */ if ($page === 'overview'): ?>
<div class="stats">
  <a class="stat" href="/admin.php?p=users"><span>Total users</span><b><?= $totalUsers ?></b></a>
  <a class="stat <?= (int)$pend['c'] ? 'alert' : '' ?>" href="/admin.php?p=deposits"><span>Pending deposits</span><b><?= (int)$pend['c'] ?></b></a>
  <a class="stat <?= count($pendOrders) ? 'alert' : '' ?>" href="/admin.php?p=orders"><span>Pending orders</span><b><?= count($pendOrders) ?></b></a>
  <div class="stat"><span>Balance in wallets</span><b><?= money($coins) ?></b></div>
</div>
<div class="stats" style="margin-top:14px">
  <a class="stat" href="/admin.php?p=reports&from=<?= e($today) ?>&to=<?= e($today) ?>"><span>Today's sales (<?= (int)$ac['today']['n'] ?> orders)</span><b><?= money($ac['today']['rev']) ?></b></a>
  <a class="stat" href="/admin.php?p=reports&from=<?= e($today) ?>&to=<?= e($today) ?>"><span>Today's profit</span><b><?= money($ac['today']['profit']) ?></b></a>
  <a class="stat" href="/admin.php?p=reports"><span>This month's sales (<?= (int)$ac['month']['n'] ?> orders)</span><b><?= money($ac['month']['rev']) ?></b></a>
  <a class="stat" href="/admin.php?p=reports"><span>This month's profit</span><b><?= money($ac['month']['profit']) ?></b></a>
</div>
<style>
.tg{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;margin-top:18px}
.tg .card h3{margin:0}.tg .tot{font-size:1.5rem;font-weight:700;margin:4px 0 12px}.tg .tot small{font-size:.8rem;font-weight:400;color:#6b7280}
.tgc{display:flex;align-items:flex-end;gap:3px;height:130px;border-bottom:1px solid #d9dce8}
.tgc i{flex:1;min-height:2px;border-radius:3px 3px 0 0;background:var(--c);opacity:.85}.tgc i.z{opacity:.18}.tgc i.now{opacity:1;outline:2px solid var(--c);outline-offset:1px}.tgc i.fut{opacity:.07}
.tgx{display:flex;justify-content:space-between;font-size:.72rem;color:#6b7280;margin-top:4px}
</style>
<?php foreach ([['Today\'s sales', 'hs', 'ns', '#5b4bff', 'orders'], ['Today\'s deposits', 'hd', 'nd', '#16a34a', 'deposits']] as [$gt, $gk, $gn, $gc, $gw]):
  $ser = $ac[$gk]; $gm = max($ser) ?: 1; ob_start(); ?>
<div class="card"><h3><?= $gt ?> by hour</h3><div class="tot"><?= money(array_sum($ser)) ?> <small>· <?= (int)$ac[$gn] ?> <?= $gw ?></small></div>
  <div class="tgc" style="--c:<?= $gc ?>"><?php foreach ($ser as $h => $v): ?><i class="<?= $v <= 0 ? ($h > $ac['nowH'] ? 'fut' : 'z') : ($h === $ac['nowH'] ? 'now' : '') ?>" style="height:<?= $v > 0 ? max(3, round($v / $gm * 100)) : 2 ?>%" title="<?= sprintf('%02d:00', $h) ?> — <?= e(strip_tags(money($v))) ?>"></i><?php endforeach; ?></div>
  <div class="tgx"><span>12 AM</span><span>6 AM</span><span>12 PM</span><span>6 PM</span><span>11 PM</span></div></div>
<?php $tgHtml[] = ob_get_clean(); endforeach; ?>
<div class="tg"><?= implode('', $tgHtml) ?></div>
<?php if ((int)$ac['month']['unk'] > 0): ?><p style="margin:10px 2px;color:#b45309;font-size:.85rem">⚠ <?= (int)$ac['month']['unk'] ?> delivered order(s) this month have no cost, so their profit is not counted. Set a Cost price on those products (Products → Edit).</p><?php endif; ?>
<div class="cols">
  <div class="card"><div class="card-h"><h3>Pending orders</h3><a href="/admin.php?p=orders">Open all</a></div>
  <?php foreach (array_slice($pendOrders, 0, 5) as $o): ?>
    <div class="mini"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?></b><small><?= e($o['email']) ?></small></div><span class="money"><?= money($o['price']) ?></span></div>
  <?php endforeach; if (!$pendOrders): ?><div class="empty">No pending orders.</div><?php endif; ?></div>
  <div class="card"><div class="card-h"><h3>Pending deposits</h3><a href="/admin.php?p=deposits">Open all</a></div>
  <?php foreach (array_slice($rows, 0, 5) as $r): ?>
    <div class="mini"><div><b><?= e($r['email']) ?></b><small><?= e($r['created_at']) ?></small></div><span class="money"><?= money($r['amount']) ?></span></div>
  <?php endforeach; if (!$rows): ?><div class="empty">Nothing pending.</div><?php endif; ?></div>
</div>
<div class="card" style="margin-top:18px"><div class="card-h"><h3>Recent deposit decisions</h3><a href="/admin.php?p=deposits">See more</a></div>
<div class="tw"><table><thead><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach (array_slice($hist, 0, 8) as $h): ?>
<tr><td><?= e($h['email']) ?></td><td class="money"><?= money($h['amount']) ?></td><td><span class="badge <?= e($h['status']) ?>"><?= e($h['status']) ?></span></td><td><?= e($h['created_at']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No history yet.</td></tr><?php endif; ?></tbody></table></div></div>

<?php /* ============================ ORDERS ============================ */ elseif ($page === 'orders'): ?>
<?php if ($ordErr): ?><div class="err"><?= e($ordErr) ?></div><?php endif; ?>
<?php foreach ($pendOrders as $o): ?>
<div class="ord">
  <div class="ord-h"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?></b><p><?= e($o['email']) ?> · <?= e($o['created_at']) ?></p></div><span class="money"><?= money($o['price']) ?></span></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
  <textarea name="delivery" placeholder="Delivery details shown to the customer (account email/password, license key, instructions...)"></textarea>
  <div class="acts" style="margin-top:10px"><?php if (!empty($o['sup_t']) || !empty($o['sup_id'])): ?><button class="btn sm" name="supplier_deliver" value="1" onclick="return confirm('Buy this from the supplier now? Your supplier balance will be charged.')">Auto-deliver (Supplier <?= !empty($o['sup_id']) ? '2' : '1' ?>)</button><?php endif; ?>
  <button class="btn sm ok-b" name="deliver" value="1">Mark delivered</button>
  <button class="btn sm red" name="cancel_order" value="1" onclick="return confirm('Cancel and refund?')">Cancel &amp; refund</button></div></form>
</div>
<?php endforeach; if (!$pendOrders): ?><div class="card"><div class="empty">No pending orders. 🎉</div></div><?php endif; ?>

<?php /* ============================ REPORTS ============================ */ elseif ($page === 'reports'):
  $k = $rp['k']; $net = (float)$k['profit'] - $rp['exp'];
  $d1 = fn($n) => date('Y-m-d', strtotime($today . ' ' . $n));
  $presets = ['Today' => [$today, $today], 'Yesterday' => [$d1('-1 day'), $d1('-1 day')], 'Last 7 days' => [$d1('-6 days'), $today], 'This month' => [substr($today, 0, 8) . '01', $today], 'Last 30 days' => [$d1('-29 days'), $today]];
  $maxRev = 0; foreach ($rp['daily'] as $dy) $maxRev = max($maxRev, (float)$dy['rev']);
  $pct = fn($p, $r) => (float)$r > 0 ? number_format($p / $r * 100, 1) . '%' : '—'; ?>
<style>
.rp-pre{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}.rp-pre a{padding:6px 12px;border-radius:999px;border:1px solid #d9dce8;font-size:.82rem;text-decoration:none;color:inherit}
.rp-pre a.on{background:#5b4bff;color:#fff;border-color:#5b4bff}
.rp-bar{height:10px;border-radius:5px;background:#e8e9f5;min-width:90px;position:relative;overflow:hidden}.rp-bar i{position:absolute;left:0;top:0;bottom:0;background:#c7c3ff}.rp-bar b{position:absolute;left:0;top:0;bottom:0;background:#16a34a}
.rp-neg{color:#dc2626}.rp-pos{color:#16a34a}.rp-note{font-size:.82rem;color:#6b7280;margin:8px 2px}
</style>
<div class="card"><form method="get" class="tb" style="flex-wrap:wrap;gap:10px;align-items:flex-end"><input type="hidden" name="p" value="reports">
  <div><label style="margin-top:0">From</label><input type="date" name="from" value="<?= e($rp['from']) ?>"></div>
  <div><label style="margin-top:0">To</label><input type="date" name="to" value="<?= e($rp['to']) ?>"></div>
  <button class="btn">Show</button>
  <a class="btn ghost" href="/admin.php?p=allorders&from=<?= e($rp['from']) ?>&to=<?= e($rp['to']) ?>">View orders</a></form>
  <div class="rp-pre"><?php foreach ($presets as $lb => [$pf, $pt]): ?><a class="<?= ($rp['from'] === $pf && $rp['to'] === $pt) ? 'on' : '' ?>" href="/admin.php?p=reports&from=<?= e($pf) ?>&to=<?= e($pt) ?>"><?= e($lb) ?></a><?php endforeach; ?></div></div>

<div class="stats" style="margin-top:14px">
  <div class="stat"><span>Sales (<?= (int)$k['n'] ?> delivered)</span><b><?= money($k['rev']) ?></b></div>
  <div class="stat"><span>Cost</span><b><?= money($k['cost']) ?></b></div>
  <div class="stat"><span>Gross profit · margin <?= $pct((float)$k['profit'], (float)$k['rev'] - 0) ?></span><b class="<?= (float)$k['profit'] < 0 ? 'rp-neg' : 'rp-pos' ?>"><?= money($k['profit']) ?></b></div>
  <div class="stat"><span>Expenses</span><b><?= money($rp['exp']) ?></b></div>
</div>
<div class="stats" style="margin-top:14px">
  <div class="stat"><span>NET PROFIT (gross − expenses)</span><b class="<?= $net < 0 ? 'rp-neg' : 'rp-pos' ?>"><?= money($net) ?></b></div>
  <div class="stat"><span>Avg profit / order</span><b><?= money((int)$k['n'] > (int)$k['unk'] ? (float)$k['profit'] / ((int)$k['n'] - (int)$k['unk']) : 0) ?></b></div>
  <div class="stat"><span>Cancelled &amp; refunded (<?= (int)$rp['ref']['n'] ?>)</span><b><?= money($rp['ref']['s']) ?></b></div>
  <div class="stat"><span>Pending orders now (<?= (int)$rp['pend']['n'] ?>)</span><b><?= money($rp['pend']['s']) ?></b></div>
</div>
<?php if ((int)$k['unk'] > 0): ?><p class="rp-note" style="color:#b45309">⚠ <?= (int)$k['unk'] ?> delivered order(s) have no cost (shown in orange below), so their profit is not counted. Set a Cost price on the product (Products → Edit) and they are filled in automatically.</p><?php endif; ?>

<div class="card" style="margin-top:18px"><h3>Daily sales</h3>
<div class="tw"><table><thead><tr><th>Date</th><th>Orders</th><th>Sales</th><th>Profit</th><th class="hm">Sales vs profit</th></tr></thead><tbody>
<?php foreach ($rp['daily'] as $dy): $w1 = $maxRev > 0 ? (float)$dy['rev'] / $maxRev * 100 : 0; $w2 = $maxRev > 0 ? max(0, (float)$dy['profit']) / $maxRev * 100 : 0; ?>
<tr><td><?= e($dy['d']) ?></td><td><?= (int)$dy['n'] ?></td><td class="money"><?= money($dy['rev']) ?></td><td class="money <?= (float)$dy['profit'] < 0 ? 'rp-neg' : '' ?>"><?= money($dy['profit']) ?></td>
<td class="hm"><div class="rp-bar"><i style="width:<?= round($w1, 1) ?>%"></i><b style="width:<?= round($w2, 1) ?>%"></b></div></td></tr>
<?php endforeach; if (!$rp['daily']): ?><tr><td colspan="5">No delivered orders in this range.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="cols" style="margin-top:18px">
<div class="card"><h3>By product <small>(best profit first)</small></h3>
<div class="tw"><table><thead><tr><th>Product</th><th>Sold</th><th>Sales</th><th>Profit</th></tr></thead><tbody>
<?php foreach ($rp['prod'] as $r): ?>
<tr><td><?= e($r['nm']) ?><?php if ((int)$r['unk']): ?> <small style="color:#b45309">(<?= (int)$r['unk'] ?> no cost)</small><?php endif; ?></td><td><?= (int)$r['n'] ?></td><td class="money"><?= money($r['rev']) ?></td><td class="money <?= (float)$r['profit'] < 0 ? 'rp-neg' : '' ?>"><?= money($r['profit']) ?></td></tr>
<?php endforeach; if (!$rp['prod']): ?><tr><td colspan="4">No sales.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h3>By category</h3>
<div class="tw"><table><thead><tr><th>Category</th><th>Sold</th><th>Sales</th><th>Profit</th></tr></thead><tbody>
<?php foreach ($rp['cat'] as $r): ?>
<tr><td><?= e($r['nm'] ?? 'Uncategorised') ?></td><td><?= (int)$r['n'] ?></td><td class="money"><?= money($r['rev']) ?></td><td class="money"><?= money($r['profit']) ?></td></tr>
<?php endforeach; if (!$rp['cat']): ?><tr><td colspan="4">No sales.</td></tr><?php endif; ?></tbody></table></div></div>
</div>

<div class="cols" style="margin-top:18px">
<div class="card"><h3>By supplier</h3>
<div class="tw"><table><thead><tr><th>Source</th><th>Sold</th><th>Sales</th><th>Cost</th><th>Profit</th></tr></thead><tbody>
<?php foreach ($rp['sup'] as $r): ?>
<tr><td><?= e($r['src']) ?></td><td><?= (int)$r['n'] ?></td><td class="money"><?= money($r['rev']) ?></td><td class="money"><?= money($r['cost']) ?></td><td class="money"><?= money($r['profit']) ?></td></tr>
<?php endforeach; if (!$rp['sup']): ?><tr><td colspan="5">No sales.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h3>Top buyers</h3>
<div class="tw"><table><thead><tr><th>User</th><th>Orders</th><th>Spent</th><th>Profit</th></tr></thead><tbody>
<?php foreach ($rp['buyers'] as $r): ?>
<tr><td><?= e((string)$r['email']) ?></td><td><?= (int)$r['n'] ?></td><td class="money"><?= money($r['rev']) ?></td><td class="money"><?= money($r['profit']) ?></td></tr>
<?php endforeach; if (!$rp['buyers']): ?><tr><td colspan="4">No sales.</td></tr><?php endif; ?></tbody></table></div></div>
</div>

<div class="card" style="margin-top:18px"><h3>Wallet &amp; cash flow</h3>
<div class="tw"><table><tbody>
<tr><td>Approved deposits in range (<?= (int)$rp['dep']['n'] ?>)</td><td class="money"><?= money($rp['dep']['s']) ?></td></tr>
<tr><td>Refunds to wallets (cancelled orders)</td><td class="money"><?= money($rp['ref']['s']) ?></td></tr>
<?php if ($rp['bonus'] !== null): ?><tr><td>Referral bonuses paid</td><td class="money"><?= money($rp['bonus']) ?></td></tr><?php endif; ?>
<tr><td>Total balance users hold right now (you owe this in goods)</td><td class="money"><?= money($coins) ?></td></tr>
<tr><td>Value of manual stock at cost</td><td class="money"><?= money($rp['stockval']) ?></td></tr>
</tbody></table></div>
<?php if ($rp['pm']): $pmN = pm_all(); ?>
<h3 style="margin-top:18px">Deposits by payment method</h3>
<div class="tw"><table><thead><tr><th>Method</th><th>Deposits</th><th>Amount</th></tr></thead><tbody>
<?php foreach ($rp['pm'] as $r): ?><tr><td><?= e((string)($pmN[(string)$r['m']]['name'] ?? $r['m'])) ?></td><td><?= (int)$r['n'] ?></td><td class="money"><?= money($r['s']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?></div>

<div class="cols" style="margin-top:18px">
<div class="card"><h3>Low stock <small>(manual products, 5 or fewer)</small></h3>
<?php foreach ($rp['lowstock'] as $r): ?><div class="mini"><div><b><?= e($r['name']) ?></b><small>ID <?= (int)$r['id'] ?></small></div><span class="money"><?= (int)$r['stock'] ?> left</span></div>
<?php endforeach; if (!$rp['lowstock']): ?><div class="empty">Nothing is running low.</div><?php endif; ?></div>
<div class="card"><h3>No sales in this range</h3>
<?php foreach ($rp['dead'] as $r): ?><div class="mini"><div><b><?= e($r['name']) ?></b><small>ID <?= (int)$r['id'] ?></small></div></div>
<?php endforeach; if (!$rp['dead']): ?><div class="empty">Every visible product sold at least once.</div><?php endif; ?></div>
</div>
<p class="rp-note">Sales = delivered orders only. Profit = price − cost, counted only for orders whose cost is known.</p>

<?php /* ============================ ALL ORDERS ============================ */ elseif ($page === 'allorders'):
  $qs = http_build_query(array_filter(['st' => $_GET['st'] ?? '', 'q' => $_GET['q'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? ''], fn($v) => $v !== '')); ?>
<div class="card">
  <form method="get" class="tb" style="flex-wrap:wrap;gap:10px;align-items:flex-end"><input type="hidden" name="p" value="allorders">
    <div class="srch grow"><?= ai('search') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search email, product or order #" autocomplete="off"></div>
    <select name="st" style="width:auto"><option value="">All statuses</option><?php foreach (['pending', 'delivered', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= ($_GET['st'] ?? '') === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>" style="width:auto"><input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>" style="width:auto">
    <button class="btn">Filter</button>
    <a class="btn ghost" href="/admin.php?export=orders<?= $qs ? '&' . e($qs) : '' ?>">Download CSV</a>
  </form>
  <div class="tw"><table><thead><tr><th>#</th><th class="hm">Date</th><th>User</th><th>Product</th><th>Status</th><th>Price</th><th class="hm">Cost</th><th>Profit</th></tr></thead><tbody>
  <?php $sumP = 0; $sumPr = 0; foreach ($aoRows as $o): $pr = $o['cost'] === null ? null : (float)$o['price'] - (float)$o['cost'];
    if ($o['status'] === 'delivered') { $sumP += (float)$o['price']; $sumPr += (float)$pr; } ?>
  <tr><td><?= (int)$o['id'] ?></td><td class="hm"><?= e($o['created_at']) ?></td><td><?= e((string)$o['email']) ?></td><td><?= e($o['product_name']) ?></td>
  <td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td><td class="money"><?= money($o['price']) ?></td>
  <td class="hm money"><?= $o['cost'] === null ? '<small>—</small>' : money($o['cost']) ?></td>
  <td class="money"><?= $pr === null ? '<small style="color:#b45309">no cost</small>' : ($o['status'] === 'cancelled' ? '<small>—</small>' : money($pr)) ?></td></tr>
  <?php endforeach; if (!$aoRows): ?><tr><td colspan="8">No orders match.</td></tr><?php endif; ?></tbody></table></div>
  <p class="rp-note">Showing the latest <?= count($aoRows) ?> (max 300). Delivered in this list: sales <b><?= money($sumP) ?></b>, profit <b><?= money($sumPr) ?></b>. The CSV download has every matching order.</p>
</div>

<?php /* ============================ EXPENSES ============================ */ elseif ($page === 'expenses'): ?>
<?php if ($expErr): ?><div class="err"><?= e($expErr) ?></div><?php endif; ?>
<div class="card"><h3>Add expense</h3>
<form method="post"><?= csrf_field() ?>
  <div class="grid2"><div><label style="margin-top:0">What for</label><input type="text" name="ex_title" maxlength="120" placeholder="e.g. Railway hosting, Facebook ads" required></div>
  <div><label style="margin-top:0">Amount (৳)</label><input type="number" name="ex_amount" step="0.01" min="0.01" required></div></div>
  <label>Date</label><input type="date" name="ex_date" value="<?= e($today) ?>">
  <div class="savebar"><button class="btn" name="add_expense" value="1">Add expense</button></div>
</form></div>
<div class="card" style="margin-top:18px"><div class="card-h"><h3>Expenses</h3><span class="money">This month: <?= money($expMonth) ?></span></div>
<div class="tw"><table><thead><tr><th>Date</th><th>What for</th><th>Amount</th><th></th></tr></thead><tbody>
<?php foreach ($expRows as $x2): ?>
<tr><td><?= e($x2['spent_on']) ?></td><td><?= e($x2['title']) ?></td><td class="money"><?= money($x2['amount']) ?></td>
<td class="ra"><form method="post" class="acts"><?= csrf_field() ?><button class="btn sm red" name="del_expense" value="<?= (int)$x2['id'] ?>" onclick="return confirm('Delete this expense?')" aria-label="Delete"><?= ai('trash') ?></button></form></td></tr>
<?php endforeach; if (!$expRows): ?><tr><td colspan="4">No expenses yet.</td></tr><?php endif; ?></tbody></table></div></div>

<?php /* ============================ DEPOSITS ============================ */ elseif ($page === 'deposits'): ?>
<div class="card"><h3>Pending deposits</h3>
<div class="tw"><table><thead><tr><th>User</th><th>Amount</th><th class="hm">Note</th><th class="hm">Date</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= e($r['email']) ?></td><td class="money"><?= money($r['amount']) ?></td><td class="hm"><?= e($r['note']) ?></td><td class="hm"><?= e($r['created_at']) ?></td>
<td class="ra"><form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<button class="btn sm ok-b" name="action" value="approve">Approve</button>
<button class="btn sm red" name="action" value="reject">Reject</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5">Nothing pending.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h3>Recent decisions</h3>
<div class="tw"><table><thead><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach ($hist as $h): ?>
<tr><td><?= e($h['email']) ?></td><td class="money"><?= money($h['amount']) ?></td><td><span class="badge <?= e($h['status']) ?>"><?= e($h['status']) ?></span></td><td><?= e($h['created_at']) ?></td></tr>
<?php endforeach; if (!$hist): ?><tr><td colspan="4">No history yet.</td></tr><?php endif; ?></tbody></table></div></div>

<?php /* ============================ PRODUCTS ============================ */ elseif ($page === 'products'): ?>
<div class="card">
  <div class="tb">
    <div class="srch grow"><?= ai('search') ?><input type="search" id="pq" placeholder="Search by name or ID" autocomplete="off"></div>
    <select id="pcat" style="width:auto;min-width:170px"><option value="">All categories</option><option value="0">Uncategorised</option>
      <?php foreach ($cats as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e(trim(cat_icon_text($k['icon']) . ' ' . $k['name'])) ?></option><?php endforeach; ?></select>
    <select id="pexs" style="width:auto" aria-label="Which stock lines to export"><option value="unsold">Unsold stock</option><option value="sold">Sold stock</option><option value="all">All stock</option></select>
    <a class="btn ghost" id="pexa" href="/admin.php?export=stock&amp;s=unsold" title="Download the local stock lines (current category filter applies)"><?= ai('download') ?>Export stock</a>
  </div>
  <div class="tw"><table id="ptable"><thead><tr><th>Product</th><th class="hm">Category</th><th>Price</th><th class="hm">Cost</th><th class="hm">Stock</th><th>Status</th><th></th></tr></thead><tbody>
  <?php $stkCnt = []; try { foreach ($pdo->query('SELECT product_id, COUNT(*) n FROM stock_items GROUP BY product_id') as $sr) $stkCnt[(int)$sr['product_id']] = (int)$sr['n']; } catch (Throwable $ex) {}
  $rcp = $_SESSION['recent_prod'] ?? null; if ($rcp && time() - (int)$rcp['t'] > 3600) $rcp = null;
  foreach ($products as $p): $k = $catById[(int)$p['category_id']] ?? null; $isRecent = $rcp && (int)$rcp['id'] === (int)$p['id'];
    $pj = ['id' => (int)$p['id'], 'name' => $p['name'], 'desc' => (string)$p['description'], 'price' => (string)$p['price'], 'unit' => (string)$p['unit'], 'cost' => $p['cost_price'] ?? '',
           'cat' => $p['category_id'] ?? '', 'stock' => $p['stock'] === null ? '' : (int)$p['stock'], 'pop' => (int)$p['popular'], 'cs' => (string)($p['card_style'] ?? ''),
           'src' => !empty($p['supplier_id']) ? 'bm' : (!empty($p['supplier_type']) ? 'hot' : ''),
           'sup' => !empty($p['supplier_type']) ? $p['supplier_type'] . '|' . $p['supplier_account'] : '',
           'bmid' => !empty($p['supplier_id']) ? (int)$p['supplier_id'] : '',
           'an' => (int)($p['sup_auto_name'] ?? 0), 'ad' => (int)($p['sup_auto_desc'] ?? 0), 'sd' => (int)($p['sup_show_dom'] ?? 1), 'ap' => (int)($p['sup_auto_price'] ?? 0), 'mk' => (string)($p['sup_markup'] ?? '0')]; ?>
  <tr data-s="<?= e(mb_strtolower($p['name'] . ' ' . $p['id'])) ?>" data-c="<?= (int)$p['category_id'] ?>"<?= $isRecent ? ' class="p-recent" data-recent="1"' : '' ?>>
    <td><div class="pn"><span class="ci"><?= cat_icon($k['icon'] ?? '', '1em') ?></span><div><b><?= e($p['name']) ?></b><?php if ($isRecent): ?><span class="tag p-rtag"><?= e($rcp['l']) ?></span><?php endif; ?><?php if ($p['popular']): ?><span class="tag">Popular</span><?php endif; ?><?php if (!empty($p['supplier_id'])): ?><span class="tag">Supplier 2</span><?php elseif (!empty($p['supplier_type'])): ?><span class="tag">Supplier 1</span><?php endif; ?><small>ID <?= (int)$p['id'] ?><?= $p['unit'] !== '' ? ' · per ' . e($p['unit']) : '' ?></small></div></div></td>
    <td class="hm"><?= $k ? e($k['name']) : '<small>—</small>' ?></td>
    <td class="money"><?= money($p['price']) ?></td>
    <td class="hm"><?= ($p['cost_price'] ?? null) !== null ? money($p['cost_price']) : '<small>' . (!empty($p['supplier_id']) ? 'auto' : '—') . '</small>' ?></td>
    <td class="hm"><?= $p['stock'] === null ? 'Unlimited' : number_format((int)$p['stock']) ?></td>
    <td><span class="badge <?= $p['active'] ? 'on' : 'off' ?>"><?= $p['active'] ? 'Visible' : 'Hidden' ?></span></td>
    <td class="ra"><div class="acts">
      <button type="button" class="btn sm ghost" data-edit-p="<?= e(json_encode($pj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('pencil') ?>Edit</button>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="toggle_product" value="<?= (int)$p['id'] ?>"><?= $p['active'] ? 'Hide' : 'Show' ?></button></form>
      <?php if (!empty($stkCnt[(int)$p['id']]) && empty($p['supplier_id']) && empty($p['supplier_type'])): ?><a class="btn sm ghost" data-pexp="<?= (int)$p['id'] ?>" href="/admin.php?export=stock&amp;pid=<?= (int)$p['id'] ?>&amp;s=unsold" title="Export this product's stock (<?= (int)$stkCnt[(int)$p['id']] ?> lines)" aria-label="Export stock"><?= ai('download') ?></a><?php endif; ?>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm red" name="delete_product" value="<?= (int)$p['id'] ?>" onclick="return confirm('Delete this product for good? Unsold stock lines are removed too. Past orders keep their details. (Use Hide if you only want it off the shop.)')" aria-label="Delete product" title="Delete product"><?= ai('trash') ?></button></form>
    </div></td>
  </tr>
  <?php endforeach; ?></tbody></table></div>
  <div class="empty" id="pnone" <?= $products ? 'style="display:none"' : '' ?>>No products yet. Click “Add product” to create the first one.</div>
</div>

<dialog id="pdlg"><form method="post" class="dlg"><?= csrf_field() ?>
  <div class="dlg-h"><h3 id="pTitle">Add product</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <?php if ($prodErr): ?><div class="err" style="margin-top:12px"><?= e($prodErr) ?></div><?php endif; ?>
  <label>Name</label><input type="text" name="pname" id="p_name" maxlength="80" required>
  <label>Description <small>(optional, one line per row)</small></label><textarea name="pdesc" id="p_desc" maxlength="500" rows="4" style="width:100%;box-sizing:border-box;font:inherit;resize:vertical" placeholder="One line per row. For the Midnight card use Key: Value, e.g.&#10;Age: 2019&#10;Format: mail|pass|token"></textarea>
  <div class="grid2"><div><label>Price (৳)</label><input type="number" name="pprice" id="p_price" step="0.01" min="0.01" required></div>
  <div><label>Unit <small>(e.g. email)</small></label><input type="text" name="unit" id="p_unit" maxlength="20" placeholder="email"></div></div>
  <label>Cost price (৳) <small>(what you pay to get it, used for profit reports. Blank = Supplier 2 products use supplier price × rate automatically)</small></label><input type="number" name="pcost" id="p_cost" step="0.01" min="0">
  <div class="grid2"><div><label>Category</label><select name="category_id" id="p_cat"><?= $catOpts(0) ?></select></div>
  <div><label>Stock <small>(blank = unlimited)</small></label><input type="number" name="stock" id="p_stock" min="0" step="1"></div></div>
  <label>Supplier <small>(orders for this product are bought from the chosen supplier)</small></label>
  <select name="supplier_src" id="p_src"><option value="">— None (manual delivery) —</option><option value="hot">Supplier 1</option><option value="bm">Supplier 2 (bulkmail.shop)</option></select>
  <div id="p_src_hot" hidden><label>Supplier 1 product</label><select name="supplier_link" id="p_sup"><?= $supOpts ?></select></div>
  <div id="p_src_bm" hidden><label>Supplier 2 product ID</label><input type="number" name="bm_id" id="p_bmid" min="1" step="1" placeholder="e.g. 12"><small>Stock is shown live from Supplier 2.</small> <small id="p_bmnote"></small>
    <label class="chk" style="margin-top:12px"><input type="checkbox" name="sup_an" id="p_an" value="1"> <span>Keep the name the same as Supplier 2</span></label>
    <label class="chk"><input type="checkbox" name="sup_ad" id="p_ad" value="1"> <span>Keep the description and Possible Domain the same as Supplier 2</span></label>
    <label class="chk"><input type="checkbox" name="sup_ap" id="p_ap" value="1"> <span>Keep the price in step with Supplier 2 (supplier price × rate + profit %, set in Supplier API)</span></label>
    <label class="chk"><input type="checkbox" name="sup_sd" id="p_sd" value="1" checked> <span>Show the Possible Domain line on the product card</span></label>
  </div>
  <label>Card style <small>(how this product looks in the shop)</small></label>
  <select name="card_style" id="p_cs"><option value="">Classic (current look)</option><option value="midnight">Midnight (dark card, Key: Value rows)</option></select>
  <label class="chk" style="margin-top:16px"><input type="checkbox" name="popular" id="p_pop" value="1"> <span>Mark as Popular</span></label>
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="pSave" name="add_product" value="1">Save product</button></div>
</form></dialog>

<?php /* ============================ CATEGORIES ============================ */ elseif ($page === 'categories'): ?>
<div class="card">
  <div class="tw"><table><thead><tr><th>Category</th><th class="hm">Products</th><th class="hm">Sort</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($cats as $k):
    $cj = ['id' => (int)$k['id'], 'name' => $k['name'], 'icon' => $k['icon'], 'sort' => (int)$k['sort_order']]; ?>
  <tr>
    <td><div class="pn"><span class="ci"><?= cat_icon($k['icon'], '1em') ?></span><div><b><?= e($k['name']) ?></b><small><?= e(cat_icon_label($k['icon'])) ?></small></div></div></td>
    <td class="hm"><?= (int)$k['n'] ?></td><td class="hm"><?= (int)$k['sort_order'] ?></td>
    <td><span class="badge <?= $k['active'] ? 'on' : 'off' ?>"><?= $k['active'] ? 'Visible' : 'Hidden' ?></span></td>
    <td class="ra"><form method="post" class="acts"><?= csrf_field() ?>
      <button type="button" class="btn sm ghost" data-edit-c="<?= e(json_encode($cj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('pencil') ?>Edit</button>
      <button class="btn sm ghost" name="toggle_category" value="<?= (int)$k['id'] ?>"><?= $k['active'] ? 'Hide' : 'Show' ?></button>
      <button class="btn sm red" name="delete_category" value="<?= (int)$k['id'] ?>" onclick="return confirm('Delete this category? Its products stay but become uncategorised.')" aria-label="Delete"><?= ai('trash') ?></button>
    </form></td>
  </tr>
  <?php endforeach; if (!$cats): ?><tr><td colspan="5">No categories yet. Click “Add category”, then assign products to it.</td></tr><?php endif; ?></tbody></table></div>
</div>

<dialog id="cdlg"><form method="post" class="dlg"><?= csrf_field() ?>
  <div class="dlg-h"><h3 id="cTitle">Add category</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <?php if ($catErr): ?><div class="err" style="margin-top:12px"><?= e($catErr) ?></div><?php endif; ?>
  <label>Name</label><input type="text" name="cname" id="c_name" maxlength="40" required placeholder="e.g. Gmail Account">
  <label>Icon</label>
  <div class="ipk" id="ipk">
    <button type="button" class="ipk-btn" id="ipkBtn" aria-expanded="false" aria-controls="ipkPop"><span class="ipk-cur" id="ipkCur"></span><span class="ipk-name" id="ipkName">Select icon</span><?= ai('chev') ?></button>
    <div class="ipk-pop" id="ipkPop" hidden>
      <div class="srch"><?= ai('search') ?><input type="search" id="ipkQ" placeholder="Search icons (gmail, facebook, netflix…)" autocomplete="off"></div>
      <div class="ipk-scroll" id="ipkList">
      <?php $lastG = null; $open = false;
      foreach (app_icons() as $key => $d):
        if ($d[1] !== $lastG) { if ($open) echo '</div>'; echo '<div class="ipk-g" data-g>' . e($d[1]) . '</div><div class="ipk-grid">'; $lastG = $d[1]; $open = true; } ?>
        <button type="button" class="ipk-o" data-v="app:<?= e($key) ?>" data-n="<?= e($d[0]) ?>" title="<?= e($d[0]) ?>"><?= app_icon_svg($key, '40px') ?><span><?= e($d[0]) ?></span></button>
      <?php endforeach; if ($open) echo '</div>'; ?>
        <div class="ipk-none" id="ipkNone">No icon found. Use an emoji below.</div>
      </div>
      <div class="ipk-em"><label for="ipkEmoji">Or type an emoji <small>(optional)</small></label><input type="text" id="ipkEmoji" maxlength="8" placeholder="📧"></div>
    </div>
    <input type="hidden" name="cicon" id="c_icon" value="app:shop">
  </div>
  <label>Sort order <small>(smaller shows first)</small></label><input type="number" name="csort" id="c_sort" value="0">
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="cSave" name="add_category" value="1">Save category</button></div>
</form></dialog>

<?php /* ============================ USERS ============================ */ elseif ($page === 'users'): ?>
<style>
.um-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;margin-top:18px}
.um-g .card h3{margin-top:0}.um-note{font-size:.82rem;color:#6b7280;margin:6px 0}
.p-recent{background:rgba(79,70,229,.07)}.p-recent td:first-child{box-shadow:inset 3px 0 0 #6366f1}
.tag.p-rtag{background:#e0e7ff;color:#3730a3;font-weight:600}
.badge.ban{background:#fee2e2;color:#b91c1c}.um-flag{display:inline-block;padding:2px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:.74rem;margin:2px 4px 2px 0}
.um-pg{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:14px}.um-pos{color:#16a34a}.um-neg{color:#dc2626}
/* avatar + status pill */
.ua{display:inline-flex;align-items:center;justify-content:center;border-radius:50%;font-weight:700;flex:none}
.pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap}
.pill i{width:7px;height:7px;border-radius:50%;background:currentColor}
.pill.good{background:#dcfce7;color:#15803d}.pill.bad{background:#fee2e2;color:#b91c1c}
/* user table */
.ut td{vertical-align:middle}.ut tr.is-ban{background:rgba(220,38,38,.04)}
.ut-u{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:12px;cursor:pointer;padding:2px 0;max-width:340px}
.ut-u span{display:flex;flex-direction:column;min-width:0}.ut-u b{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ut-u small{color:#6b7280;font-size:.76rem}
.ut-u:hover b{color:#5b4bff}.ut-u:focus-visible{outline:2px solid #5b4bff;outline-offset:3px;border-radius:8px}
/* quick-manage drawer */
.dw[hidden]{display:none}.dw{position:fixed;inset:0;z-index:1000}
.dw-bg{position:absolute;inset:0;background:rgba(15,18,40,.45);backdrop-filter:blur(2px);animation:dwf .15s ease}
.dw-p{position:absolute;top:0;right:0;bottom:0;width:min(460px,100%);background:#fff;color:#1f2340;box-shadow:-12px 0 40px rgba(15,18,40,.18);display:flex;flex-direction:column;animation:dws .2s ease;overflow:hidden}
#dwBody{overflow-y:auto;padding:22px 22px 26px;flex:1}
.dw-x{position:absolute;top:12px;right:14px;width:34px;height:34px;border-radius:50%;border:0;background:#f1f2f9;font-size:1.3rem;line-height:1;cursor:pointer;z-index:2}
.dw-x:hover{background:#e4e6f4}
@keyframes dws{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}@keyframes dwf{from{opacity:0}to{opacity:1}}
.dw-load{padding:40px 0;text-align:center;color:#6b7280}
.dw-head{display:flex;align-items:center;gap:12px;padding-right:36px}.dw-who{display:flex;flex-direction:column;min-width:0;flex:1}.dw-who b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dw-who span{font-size:.78rem;color:#6b7280}
.dw-bal{margin:18px 0 6px;padding:16px 18px;border-radius:14px;background:linear-gradient(135deg,#5b4bff,#8b6bff);color:#fff}
.dw-bal small{display:block;opacity:.8;font-size:.78rem}.dw-bal b{font-size:1.8rem;letter-spacing:-.5px}
.dw-tabs{display:flex;gap:4px;margin:16px 0;padding:4px;background:#f1f2f9;border-radius:12px}
.dw-tabs button{flex:1;border:0;background:none;padding:8px 4px;border-radius:9px;font:inherit;font-size:.82rem;font-weight:600;color:#5b6078;cursor:pointer}
.dw-tabs button.on{background:#fff;color:#5b4bff;box-shadow:0 1px 3px rgba(15,18,40,.12)}
.dw-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px}.dw-tiles>div{padding:12px 14px;border:1px solid #e6e8f3;border-radius:12px}
.dw-tiles small{display:block;color:#6b7280;font-size:.74rem}.dw-tiles b{font-size:1.05rem}
.dw .pos{color:#16a34a}.dw .neg{color:#dc2626}.dw .mut,.mut{color:#9aa0b8}
.dw-warn{margin:12px 0;padding:10px 12px;border-radius:10px;background:#fef3c7;color:#92400e;font-size:.82rem;line-height:1.5}
.dw-info{display:grid;grid-template-columns:110px 1fr;gap:8px 12px;margin:16px 0;font-size:.88rem}.dw-info dt{color:#6b7280}.dw-info dd{margin:0;word-break:break-all}
.dw h4{margin:22px 0 8px;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280}
.dw-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #eef0f8;font-size:.88rem}
.dw-row small{display:block;color:#6b7280;font-size:.75rem}.dw-r{text-align:right;font-weight:600;display:flex;flex-direction:column;align-items:flex-end;gap:3px}
.dw-l{display:block;margin:14px 0 6px;font-size:.8rem;font-weight:600;color:#4b5068}.dw-l small{font-weight:400;color:#9aa0b8}
.dw input[type=text],.dw input[type=number],.dw select,.dw textarea{width:100%;box-sizing:border-box}
.dw-big{font-size:1.5rem!important;font-weight:700;padding:12px 14px!important}
.dw-seg{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:4px;background:#f1f2f9;border-radius:12px}
.dw-seg input{position:absolute;opacity:0;pointer-events:none}.dw-seg span{display:block;text-align:center;padding:10px;border-radius:9px;font-weight:600;font-size:.88rem;cursor:pointer;color:#5b6078}
.dw-seg input:checked+span{background:#fff;color:#5b4bff;box-shadow:0 1px 3px rgba(15,18,40,.12)}
.dw-seg label:nth-child(2) input:checked+span{color:#dc2626}
.dw-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.dw-chips button{border:1px solid #d9dce8;background:#fff;border-radius:999px;padding:5px 12px;font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;color:#16a34a}
.dw-chips button.m{color:#dc2626}.dw-chips button:hover{background:#f6f7fd}
.dw-btn{width:100%;margin-top:16px;justify-content:center}
.dw-box{padding:14px;border:1px solid #e6e8f3;border-radius:12px}.dw-box.bad{border-color:#fecaca;background:#fff7f7}.dw-box p{margin:4px 0 10px;font-size:.84rem;color:#6b7280}
.dw-full{display:block;margin-top:24px;text-align:center;font-weight:600;color:#5b4bff;text-decoration:none}
@media (max-width:640px){#dwBody{padding:18px 16px 24px}.dw-tabs button{font-size:.76rem}}
</style>
<?php if ($pu): /* ---------- one user ---------- */
  $banned = (int)$pu['banned'] === 1;
  $flags = [];
  if ($um['same_ip']) $flags[] = count($um['same_ip']) . ' other account(s) use the same IP';
  if ($um['canc'] >= 3) $flags[] = $um['canc'] . ' cancelled orders';
  if ($um['rej'] >= 2) $flags[] = $um['rej'] . ' rejected deposits';
  if ($pu['referred_by'] && $um['ref_by'] && $pu['last_ip'] && $um['ref_by'] && $acQ('SELECT 1 FROM users WHERE id = ? AND last_ip = ?', [(int)$pu['referred_by'], $pu['last_ip']])) $flags[] = 'same IP as the user who referred them';
  $rfd = count(array_filter($um['refs'], fn($r) => (int)$r['has_deposited'])); ?>
<div class="card"><div class="card-h"><h3 style="display:flex;align-items:center;gap:12px"><?= user_avatar($pu['email'], 46) ?><span><?= e($pu['email']) ?></span> <span class="pill <?= $banned ? 'bad' : 'good' ?>"><i></i><?= $banned ? 'Blocked' : 'Active' ?></span></h3><a href="/admin.php?p=users">← All users</a></div>
  <p class="um-note">ID <?= (int)$pu['id'] ?> · Joined <?= e($pu['created_at']) ?> · Last login <?= $pu['last_login_at'] ? e($pu['last_login_at']) : 'not recorded yet' ?><?= $pu['last_ip'] ? ' · IP ' . e($pu['last_ip']) : '' ?><?= $um['ref_by'] ? ' · Referred by <a href="/admin.php?p=users&u=' . (int)$um['ref_by']['id'] . '">' . e($um['ref_by']['email']) . '</a>' : '' ?></p>
  <?php foreach ($flags as $fl): ?><span class="um-flag">⚠ <?= e($fl) ?></span><?php endforeach; ?></div>
<div class="stats" style="margin-top:14px">
  <div class="stat"><span>Balance</span><b><?= money($pu['coins']) ?></b></div>
  <div class="stat"><span>Total deposited</span><b><?= money($pu['dep']) ?></b></div>
  <div class="stat"><span>Total spent (<?= (int)$pu['ords'] ?> orders)</span><b><?= money($pu['spent']) ?></b></div>
  <div class="stat"><span>Profit from this user</span><b class="<?= $um['profit'] < 0 ? 'um-neg' : 'um-pos' ?>"><?= money($um['profit']) ?></b></div>
</div>

<div class="um-g">
<div class="card"><h3>Block / unblock</h3>
  <p class="um-note"><?= $banned ? 'This user is blocked: they cannot log in or buy.' : 'Blocking signs the user out right away and stops login, orders and deposits. Their balance stays.' ?></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$pu['id'] ?>">
  <input type="text" name="reason" maxlength="120" placeholder="Reason (saved in the log)">
  <div class="savebar"><button class="btn <?= $banned ? 'ok-b' : 'red' ?>" name="u_ban" value="1" onclick="return confirm('<?= $banned ? 'Unblock' : 'Block' ?> this user?')"><?= $banned ? 'Unblock user' : 'Block user' ?></button></div></form></div>

<div class="card"><h3>Add or remove balance</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$pu['id'] ?>">
  <div class="grid2"><div><select name="dir"><option value="add">Add (+)</option><option value="sub">Subtract (−)</option></select></div>
  <div><input type="number" name="amount" step="0.01" min="0.01" placeholder="Amount (৳)" required></div></div>
  <input type="text" name="reason" maxlength="120" placeholder="Reason (required, saved in the log)" required style="margin-top:8px">
  <div class="savebar"><button class="btn" name="u_adjust" value="1" onclick="return confirm('Change this user\'s balance?')">Update balance</button></div></form></div>

<div class="card"><h3>Discounts for this user</h3>
  <?php foreach ($um['disc'] as $d): ?><div class="mini"><div><b><?= e(pct_fmt((float)$d['percent'])) ?>% off</b><small><?= (int)$d['product_id'] === 0 ? 'All products' : e($d['name'] ?? ('Product #' . (int)$d['product_id'])) ?></small></div>
  <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$pu['id'] ?>"><button class="btn sm red" name="u_disc_del" value="<?= (int)$d['id'] ?>" aria-label="Remove"><?= ai('trash') ?></button></form></div>
  <?php endforeach; if (!$um['disc']) echo '<div class="empty">No discount.</div>'; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$pu['id'] ?>">
  <div class="grid2"><div><select name="product_id"><option value="0">All products</option><?php foreach ($um['prods'] as $p2): ?><option value="<?= (int)$p2['id'] ?>"><?= e($p2['name']) ?></option><?php endforeach; ?></select></div>
  <div><input type="number" name="percent" step="0.01" min="0.01" max="99" placeholder="% off" required></div></div>
  <div class="savebar"><button class="btn" name="u_disc_add" value="1">Save discount</button></div></form></div>

<div class="card"><h3>Reset password</h3>
  <p class="um-note">Sets a new password. Send it to the user yourself. Already open sessions stay logged in; use Block if you need to cut them off now.</p>
  <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$pu['id'] ?>">
  <input type="text" name="new_pw" minlength="8" placeholder="New password (8+ characters)" autocomplete="off" required>
  <div class="savebar"><button class="btn ghost" name="u_pw" value="1" onclick="return confirm('Change this user\'s password?')">Change password</button></div></form></div>

<div class="card"><h3>Private note</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$pu['id'] ?>">
  <textarea name="note" maxlength="2000" placeholder="Only admins see this. e.g. VIP reseller, wrong TrxID twice..."><?= e($um['note']) ?></textarea>
  <div class="savebar"><button class="btn" name="u_note" value="1">Save note</button></div></form></div>

<div class="card"><h3>Referrals <small>(<?= count($um['refs']) ?> invited, <?= $rfd ?> deposited)</small></h3>
  <?php foreach ($um['refs'] as $r): ?><div class="mini"><div><a href="/admin.php?p=users&u=<?= (int)$r['id'] ?>"><?= e($r['email']) ?></a><small><?= e($r['created_at']) ?></small></div><span class="badge <?= $r['has_deposited'] ? 'on' : 'off' ?>"><?= $r['has_deposited'] ? 'deposited' : 'no deposit' ?></span></div>
  <?php endforeach; if (!$um['refs']) echo '<div class="empty">Nobody invited yet.</div>'; ?>
  <?php if ($um['same_ip']): ?><h3 style="margin-top:16px">Same IP</h3><?php foreach ($um['same_ip'] as $r): ?><div class="mini"><div><a href="/admin.php?p=users&u=<?= (int)$r['id'] ?>"><?= e($r['email']) ?></a></div><?= $r['banned'] ? '<span class="badge ban">Blocked</span>' : '' ?></div><?php endforeach; endif; ?></div>
</div>

<div class="um-g">
<div class="card"><h3>Recent orders</h3><div class="tw"><table><thead><tr><th>#</th><th>Product</th><th>Price</th><th>Status</th></tr></thead><tbody>
<?php foreach ($um['orders'] as $o): ?><tr><td><?= (int)$o['id'] ?></td><td><?= e($o['product_name']) ?><small style="display:block"><?= e($o['created_at']) ?></small></td><td class="money"><?= money($o['price']) ?></td><td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td></tr>
<?php endforeach; if (!$um['orders']) echo '<tr><td colspan="4">No orders.</td></tr>'; ?></tbody></table></div></div>
<div class="card"><h3>Recent deposits</h3><div class="tw"><table><thead><tr><th>#</th><th>Amount</th><th>Status</th><th class="hm">Date</th></tr></thead><tbody>
<?php foreach ($um['deps'] as $d): ?><tr><td><?= (int)$d['id'] ?></td><td class="money"><?= money($d['amount']) ?></td><td><span class="badge <?= e($d['status']) ?>"><?= e($d['status']) ?></span></td><td class="hm"><?= e($d['created_at']) ?></td></tr>
<?php endforeach; if (!$um['deps']) echo '<tr><td colspan="4">No deposits.</td></tr>'; ?></tbody></table></div></div>
<div class="card"><h3>Wallet history</h3><div class="tw"><table><thead><tr><th>Type</th><th>Amount</th><th>Note</th></tr></thead><tbody>
<?php foreach ($um['tx'] as $t): ?><tr><td><?= e($t['type']) ?><small style="display:block"><?= e($t['created_at']) ?></small></td><td class="money"><?= money($t['amount']) ?></td><td><small><?= e((string)$t['note']) ?></small></td></tr>
<?php endforeach; if (!$um['tx']) echo '<tr><td colspan="3">No activity.</td></tr>'; ?></tbody></table></div></div>
<div class="card"><h3>Admin actions on this user</h3>
<?php foreach ($um['log'] as $l): ?><div class="mini"><div><b><?= e($l['action']) ?></b><small><?= e($l['detail']) ?></small></div><small><?= e($l['created_at']) ?></small></div>
<?php endforeach; if (!$um['log']) echo '<div class="empty">Nothing yet.</div>'; ?></div>
</div>

<?php else: /* ---------- list ---------- */
  $uf = (string)($_GET['f'] ?? ''); $uq2 = (string)($_GET['q'] ?? '');
  $uBase = ['p' => 'users', 'q' => $uq2, 'f' => $uf, 's' => $uSort];
  $uLink = fn(array $o) => '/admin.php?' . http_build_query(array_filter(array_merge($uBase, $o), fn($v) => $v !== '' && $v !== null)); ?>
<div class="stats">
  <div class="stat"><span>All users</span><b><?= (int)$um['st']['a'] ?></b></div>
  <div class="stat"><span>New today</span><b><?= (int)$um['st']['t'] ?></b></div>
  <div class="stat"><span>With balance</span><b><?= (int)$um['st']['c'] ?></b></div>
  <a class="stat <?= (int)$um['st']['b'] ? 'alert' : '' ?>" href="<?= e($uLink(['f' => 'banned'])) ?>"><span>Blocked</span><b><?= (int)$um['st']['b'] ?></b></a>
</div>
<div class="card" style="margin-top:14px">
  <form method="get" class="tb" style="flex-wrap:wrap;gap:10px;align-items:flex-end"><input type="hidden" name="p" value="users">
    <div class="srch grow"><?= ai('search') ?><input type="search" name="q" value="<?= e($uq2) ?>" placeholder="Email, user ID or IP" autocomplete="off"></div>
    <select name="f" style="width:auto"><?php foreach (['' => 'All users', 'banned' => 'Blocked', 'nodep' => 'Never deposited', 'bal' => 'Has balance', 'nobuy' => 'Never bought', 'disc' => 'Has a discount', 'new7' => 'Joined in last 7 days'] as $k2 => $lb): ?><option value="<?= $k2 ?>"<?= $uf === $k2 ? ' selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select>
    <select name="s" style="width:auto"><?php foreach (['new' => 'Newest first', 'bal' => 'Highest balance', 'spent' => 'Most spent', 'dep' => 'Most deposited', 'ords' => 'Most orders', 'login' => 'Last login'] as $k2 => $lb): ?><option value="<?= $k2 ?>"<?= $uSort === $k2 ? ' selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select>
    <button class="btn">Apply</button>
    <a class="btn ghost" href="<?= e('/admin.php?' . http_build_query(array_filter(['export' => 'users', 'q' => $uq2, 'f' => $uf, 's' => $uSort]))) ?>">Download CSV</a>
  </form>
  <p class="um-note"><?= number_format($um['total']) ?> user(s) · page <?= (int)$uPg ?> of <?= (int)$um['pages'] ?></p>
  <div class="tw"><table class="ut"><thead><tr><th>User</th><th>Balance</th><th class="hm">Deposited</th><th class="hm">Spent</th><th class="hm">Orders</th><th>Status</th><th class="hm">Last seen</th><th></th></tr></thead><tbody>
  <?php foreach ($ul as $x): ?>
  <tr class="<?= $x['banned'] ? 'is-ban' : '' ?>">
    <td><button type="button" class="ut-u" data-uq="<?= (int)$x['id'] ?>" data-t="ov"><?= user_avatar($x['email'], 38) ?><span><b><?= e($x['email']) ?></b><small>ID <?= (int)$x['id'] ?> · joined <?= e(substr((string)$x['created_at'], 0, 10)) ?><?= (int)$x['refs'] ? ' · ' . (int)$x['refs'] . ' referral' . ((int)$x['refs'] > 1 ? 's' : '') : '' ?></small></span></button></td>
    <td class="money"><b><?= money($x['coins']) ?></b></td><td class="hm money"><?= money($x['dep']) ?></td><td class="hm money"><?= money($x['spent']) ?></td><td class="hm"><?= (int)$x['ords'] ?></td>
    <td><span class="pill <?= $x['banned'] ? 'bad' : 'good' ?>"><i></i><?= $x['banned'] ? 'Blocked' : 'Active' ?></span></td>
    <td class="hm"><small><?= $x['last_login_at'] ? e(substr((string)$x['last_login_at'], 0, 16)) : '—' ?></small></td>
    <td class="ra"><div class="acts"><button type="button" class="btn sm" data-uq="<?= (int)$x['id'] ?>" data-t="bal">＋ Balance</button><button type="button" class="btn sm ghost" data-uq="<?= (int)$x['id'] ?>" data-t="ov">Manage</button></div></td></tr>
  <?php endforeach; if (!$ul): ?><tr><td colspan="8">No users match.</td></tr><?php endif; ?></tbody></table></div>
  <?php if ($um['pages'] > 1): ?><div class="um-pg">
    <?php if ($uPg > 1): ?><a class="btn sm ghost" href="<?= e($uLink(['pg' => $uPg - 1])) ?>">← Prev</a><?php endif; ?>
    <span><?= (int)$uPg ?> / <?= (int)$um['pages'] ?></span>
    <?php if ($uPg < $um['pages']): ?><a class="btn sm ghost" href="<?= e($uLink(['pg' => $uPg + 1])) ?>">Next →</a><?php endif; ?></div><?php endif; ?>
</div>
<div class="card" style="margin-top:18px"><h3>Latest admin actions</h3>
<?php foreach ($um['log'] as $l): ?><div class="mini"><div><b><?= e($l['action']) ?></b> · <?= e((string)$l['email']) ?><small><?= e($l['detail']) ?></small></div><small><?= e($l['created_at']) ?></small></div>
<?php endforeach; if (!$um['log']) echo '<div class="empty">No actions yet. Blocking a user, changing a balance or giving a discount is recorded here.</div>'; ?></div>

<div class="dw" id="dw" hidden><div class="dw-bg" data-dwclose></div>
  <aside class="dw-p" role="dialog" aria-label="Manage user"><button type="button" class="dw-x" data-dwclose aria-label="Close">×</button><div id="dwBody"></div></aside></div>
<?php endif; ?>

<?php /* ============================ TRANSACTIONS ============================ */ elseif ($page === 'transactions'):
  $provM = [];
  foreach (pm_all() as $pm_) { $sl = sms_slug((string)$pm_['name']); if ($sl !== '' && !isset($provM[$sl])) $provM[$sl] = $pm_; }
  $chip = function (string $prov) use ($provM): string {
      if ($prov === '') return '<small>—</small>';
      if (isset($provM[$prov])) return '<span class="tx-w">' . pm_logo_html($provM[$prov], 24) . '<b>' . e($provM[$prov]['name']) . '</b></span>';
      return '<span class="tx-w"><span class="pm-lg" style="width:24px;height:24px;border-radius:7px;font-size:12px;--c:#7a7f99">' . e(strtoupper(substr($prov, 0, 1))) . '</span><b>' . e(ucfirst($prov)) . '</b></span>';
  };
  $tabs = ['all' => ['All SMS', $smsC['all']], 'unused' => ['Unused TrxID', $smsC['unused']], 'used' => ['Used TrxID', $smsC['used']], 'other' => ['Other SMS', $smsC['other']], 'binance' => ['Binance / BSC used', $smsC['bn']]];
  $hook = (string)getenv('SMS_SECRET') !== ''; ?>
<?= pm_css() ?>
<style>
.tx-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.tx-tabs a{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid rgba(128,128,128,.35);text-decoration:none;color:inherit;font-weight:600;font-size:.92em}
.tx-tabs a.on{background:rgba(91,75,255,.14);border-color:rgba(91,75,255,.65)}
.tx-tabs a b{font-weight:800}
.tx-tabs a.al b{color:#e0861a}
.tx-bar{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center;margin-bottom:12px}
.tx-bar .srch{min-width:200px}
.tx-info{font-size:.88em;opacity:.8;display:flex;align-items:center;gap:6px}
.tx-sc{max-height:calc(100vh - 330px);min-height:240px;overflow:auto}
.tx-id{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:700;letter-spacing:.02em;word-break:break-all}
.tx-cp{border:0;background:none;color:inherit;opacity:.65;cursor:pointer;font-size:.82em;padding:2px 6px;border-radius:6px}
.tx-cp:hover{opacity:1;background:rgba(128,128,128,.18)}
.tx-w{display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
.tx-t{white-space:nowrap;font-size:.88em;opacity:.85}
.tx-meta{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin-top:14px;font-size:.95em}
.tx-meta span:nth-child(odd){opacity:.65}
.tx-msg{white-space:pre-wrap;word-break:break-word;margin:6px 0 0;padding:12px;border:1px solid rgba(128,128,128,.3);border-radius:10px;font:inherit;font-size:.93em}
</style>
<?php if ($txMsg): ?><div class="err"><?= e($txMsg) ?></div><?php endif; ?>
<nav class="tx-tabs">
<?php foreach ($tabs as $k => [$lb, $n]): ?>
  <a href="/admin.php?p=transactions&amp;f=<?= $k ?>" class="<?= $tf === $k ? 'on' : '' ?><?= $k === 'unused' && $n ? ' al' : '' ?>"><?= e($lb) ?> <b><?= (int)$n ?></b></a>
<?php endforeach; ?>
</nav>
<div class="tx-bar">
  <div class="srch grow"><?= ai('search') ?><input type="search" id="txq" placeholder="Search TrxID, amount, sender, user…" autocomplete="off"></div>
  <span class="tx-info"><?= $smsC['last'] ? 'Last SMS: ' . e(date('d M, h:i A', strtotime((string)$smsC['last']))) : 'No SMS received yet' ?></span>
  <label class="chk" style="margin:0"><input type="checkbox" id="txauto" checked> <span>Auto-refresh</span></label>
  <?php if ($tf !== 'binance' && $smsC['unused']): ?><form method="post" class="inl"><?= csrf_field() ?><button class="btn sm" name="sms_recheck_all" value="1">Re-check all unused</button></form><?php endif; ?>
</div>

<div class="card" style="padding-bottom:6px">
<?php if ($tf === 'binance'): ?>
  <div class="tw tx-sc"><table id="txtable"><thead><tr><th>Time</th><th>Type</th><th>Transaction ID</th><th>Credited</th><th class="hm">User</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($bnRows as $b): ?>
  <tr data-s="<?= e(mb_strtolower($b['txid'] . ' ' . $b['provider'] . ' ' . ($b['email'] ?? ''))) ?>">
    <td class="tx-t"><?= e(date('d M, h:i A', strtotime((string)$b['created_at']))) ?></td>
    <td><?= $b['provider'] === 'bsc' ? 'USDT BEP-20' : 'Binance Pay' ?></td>
    <td><span class="tx-id"><?= e($b['txid']) ?></span> <button type="button" class="tx-cp" data-copy="<?= e($b['txid']) ?>">copy</button></td>
    <td class="money"><?= money((float)$b['amount']) ?></td>
    <td class="hm"><?= e($b['email'] ?? ('#' . (int)$b['user_id'])) ?></td>
    <td><span class="badge on">Used</span></td>
  </tr>
  <?php endforeach; ?></tbody></table>
  <div class="empty" id="txnone" <?= $bnRows ? 'style="display:none"' : '' ?>>No Binance / BSC transaction has been credited yet.</div></div>
<?php else: ?>
  <div class="tw tx-sc"><table id="txtable"><thead><tr><th>Time</th><th>Wallet</th><th>TrxID</th><th>Amount</th><th>Status</th><th class="hm">Sender</th><th></th></tr></thead><tbody>
  <?php foreach ($smsRows as $s): $isIn = $s['kind'] === 'in'; $used = (int)$s['used'] === 1;
    $sj = ['id' => (int)$s['id'], 'time' => date('d M Y, h:i:s A', strtotime((string)$s['created_at'])), 'wallet' => ucfirst((string)$s['provider']) ?: '—',
           'trx' => (string)($s['trx'] ?? ''), 'amount' => $s['amount'] === null ? '' : money((float)$s['amount']), 'sender' => (string)$s['sender'], 'contact' => (string)$s['contact'],
           'status' => $used ? 'Used for deposit #' . (int)$s['deposit_id'] . ($s['used_at'] ? ' at ' . date('d M, h:i A', strtotime((string)$s['used_at'])) : '') : ($isIn ? 'Unused: waiting for the customer to submit this TrxID' : 'Not recognised as an incoming payment'),
           'body' => (string)$s['body']]; ?>
  <tr data-s="<?= e(mb_strtolower($s['provider'] . ' ' . ($s['trx'] ?? '') . ' ' . ($s['amount'] ?? '') . ' ' . $s['sender'] . ' ' . ($s['email'] ?? '') . ' ' . $s['body'])) ?>">
    <td class="tx-t"><?= e(date('d M, h:i A', strtotime((string)$s['created_at']))) ?></td>
    <td><?= $chip((string)$s['provider']) ?></td>
    <td><?php if ($s['trx'] !== null && $s['trx'] !== ''): ?><span class="tx-id"><?= e($s['trx']) ?></span> <button type="button" class="tx-cp" data-copy="<?= e($s['trx']) ?>">copy</button><?php else: ?><small>—</small><?php endif; ?></td>
    <td class="money"><?= $s['amount'] !== null ? money((float)$s['amount']) : '<small>—</small>' ?></td>
    <td><?php if ($used): ?><span class="badge on">Used</span><small style="display:block">#<?= (int)$s['deposit_id'] ?><?= !empty($s['email']) ? ' · ' . e($s['email']) : '' ?></small>
        <?php elseif ($isIn): ?><span class="badge pending">Unused</span>
        <?php else: ?><span class="badge off">Other</span><?php endif; ?></td>
    <td class="hm"><?= e($s['sender']) ?></td>
    <td class="ra"><div class="acts">
      <?php if (!$used): ?><form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="sms_recheck" value="<?= (int)$s['id'] ?>" title="Look for a waiting deposit that matches this SMS">Re-check</button></form><?php endif; ?>
      <button type="button" class="btn sm ghost" data-view="<?= e(json_encode($sj, JSON_UNESCAPED_UNICODE)) ?>">View</button>
    </div></td>
  </tr>
  <?php endforeach; ?></tbody></table>
  <div class="empty" id="txnone" <?= $smsRows ? 'style="display:none"' : '' ?>><?= $smsC['all'] ? 'Nothing here.' : 'No SMS yet. Once your SMS Forwarder app sends a message to sms.php it will appear here.' ?></div></div>
<?php endif; ?>
</div>

<details style="margin-top:14px"><summary style="cursor:pointer;font-weight:600">SMS forwarder setup</summary>
  <p style="margin:10px 0 4px">App URL: <b><?= e('https://' . ($_SERVER['HTTP_HOST'] ?? 'your-site') . '/sms.php?key=YOUR_SECRET') ?></b></p>
  <p style="margin:4px 0">Secret (<code>SMS_SECRET</code> variable on the server): <?= $hook ? '<span class="badge on">Set</span>' : '<span class="badge off">Not set: sms.php rejects every message</span>' ?></p>
  <small>Unused = the SMS arrived but no customer has submitted its TrxID yet. Used = it already credited a deposit (the same TrxID can never be used twice).</small>
</details>

<dialog id="sdlg"><div class="dlg">
  <div class="dlg-h"><h3>SMS details</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <div class="tx-meta" id="sdMeta"></div>
  <label>Message</label><pre class="tx-msg" id="sdText"></pre>
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Close</button></div>
</div></dialog>

<?php /* ============================ PAYMENT METHODS ============================ */ elseif ($page === 'payments'):
  $pms = pm_all(); $dc = dep_cfg();
  $num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
  $pv = fn(string $k, $d) => e($_POST[$k] ?? $d); ?>
<?= pm_css() ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><h3>Deposit rules</h3>
  <?php if ($depErr): ?><div class="err"><?= e($depErr) ?></div><?php endif; ?>
  <label class="chk" style="margin-top:0"><input type="checkbox" name="dep_on" value="1"<?= (isset($_POST['save_dep']) ? !empty($_POST['dep_on']) : $dc['on']) ? ' checked' : '' ?>> <span>Deposits are open (untick to close the deposit page for everyone)</span></label>
  <div class="grid2">
    <div><label>Minimum deposit (৳)</label><input type="number" name="dep_min" min="1" step="0.01" required value="<?= $pv('dep_min', $num($dc['min'])) ?>"></div>
    <div><label>Maximum deposit (৳)</label><input type="number" name="dep_max" min="1" step="0.01" required value="<?= $pv('dep_max', $num($dc['max'])) ?>"></div>
  </div>
  <div class="grid2">
    <div><label>Pending deposits allowed per user</label><input type="number" name="dep_pending" min="1" max="50" step="1" required value="<?= $pv('dep_pending', (int)$dc['pending']) ?>"></div>
    <div><label>Quick amount buttons <small>(comma separated, max 8)</small></label><input type="text" name="dep_chips" value="<?= $pv('dep_chips', implode(',', $dc['chips'])) ?>"></div>
  </div>
  <label>Notice on the deposit page <small>(optional)</small></label><input type="text" name="dep_notice" maxlength="300" value="<?= $pv('dep_notice', $dc['notice']) ?>">
  <label>Message shown while deposits are closed <small>(optional)</small></label><input type="text" name="dep_off_msg" maxlength="300" value="<?= $pv('dep_off_msg', $dc['off_msg']) ?>">
  <div class="savebar"><button class="btn" name="save_dep" value="1">Save deposit rules</button></div>
</div>
</form>

<div class="card"><h3>Payment methods</h3>
  <div class="tw"><table><thead><tr><th>Method</th><th class="hm">Account</th><th class="hm">Limits</th><th class="hm">Fee / Bonus</th><th>Status</th><th></th></tr></thead><tbody>
  <?php $pmIds = array_keys($pms); foreach ($pms as $m):
    $has = pm_acct_ok((string)$m['acct']); [$lo, $hi] = pm_limits($m, $dc); $pos = array_search($m['id'], $pmIds, true);
    $pj = ['id' => $m['id'], 'name' => $m['name'], 'acct' => (string)$m['acct'], 'acct_label' => $m['acct_label'], 'sub' => $m['sub'], 'ussd' => $m['ussd'],
           'tid_label' => $m['tid_label'], 'color' => $m['color'], 'is_usd' => (int)$m['is_usd'], 'enabled' => (int)$m['enabled'],
           'min_amt' => $m['min_amt'] === null ? '' : $num($m['min_amt']), 'max_amt' => $m['max_amt'] === null ? '' : $num($m['max_amt']),
           'fee_pct' => $num($m['fee_pct']), 'bonus_pct' => $num($m['bonus_pct']), 'steps' => (string)$m['steps'], 'notice' => (string)$m['notice'],
           'has_logo' => (int)$m['has_logo']]; ?>
  <tr>
    <td><div class="pn"><?= pm_logo_html($m, 36) ?><div><b><?= e($m['name']) ?></b><?php if ($m['is_usd']): ?><span class="tag">USD</span><?php endif; ?><small><?= e($m['sub']) ?></small></div></div></td>
    <td class="hm"><?= $has ? e($m['acct']) : '<small>— not set —</small>' ?></td>
    <td class="hm"><?= money($lo) ?> – <?= money($hi) ?></td>
    <td class="hm"><?= $num($m['fee_pct']) ?>% / <?= $num($m['bonus_pct']) ?>%</td>
    <td><?php if ((int)$m['enabled'] && $has): ?><span class="badge on">Live</span><?php elseif ((int)$m['enabled']): ?><span class="badge pending">Needs account</span><?php else: ?><span class="badge off">Hidden</span><?php endif; ?></td>
    <td class="ra"><div class="acts">
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="pm_up" value="<?= e($m['id']) ?>" title="Move up"<?= $pos === 0 ? ' disabled' : '' ?>>↑</button></form>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="pm_down" value="<?= e($m['id']) ?>" title="Move down"<?= $pos === count($pmIds) - 1 ? ' disabled' : '' ?>>↓</button></form>
      <button type="button" class="btn sm ghost" data-edit-m="<?= e(json_encode($pj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('pencil') ?>Edit</button>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="pm_toggle" value="<?= e($m['id']) ?>"><?= (int)$m['enabled'] ? 'Hide' : 'Show' ?></button></form>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm red" name="pm_delete" value="<?= e($m['id']) ?>" onclick="return confirm('Delete this payment method?')"><?= ai('trash') ?></button></form>
    </div></td>
  </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if (!$pms): ?><div class="empty">No payment methods yet. Click “Add method” to create the first one.</div><?php endif; ?>
  <small style="display:block;margin-top:12px">A method is shown to customers only when it is switched on <b>and</b> has a real account number (not a placeholder like 01XXXXXXXXX).</small>
</div>

<?php
  $bnKeySet = bn_key() !== ''; $bnSecSet = bn_secret() !== '';
  $bnEnv = $bnKeySet && trim((string)setting('bn_api_key', '')) === '';
  $bnLastErr = trim((string)setting('bn_last_error', ''));
  $bnOpt = function (string $name, string $cur) use ($pms): string {
      $h = '<option value="">— detect by name —</option>';
      foreach ($pms as $m) $h .= '<option value="' . e($m['id']) . '"' . ($cur === (string)$m['id'] ? ' selected' : '') . '>' . e($m['name']) . '</option>';
      return '<select name="' . $name . '">' . $h . '</select>';
  }; ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><h3>Binance auto-verification</h3>
  <p style="margin:0 0 6px"><span class="badge <?= ($bnKeySet && $bnSecSet) ? 'on' : 'off' ?>"><?= ($bnKeySet && $bnSecSet) ? 'API connected' : 'API key / secret missing' ?></span>
    <?php if ($bnKeySet): ?> Key: <b><?= e(bn_mask(bn_key())) ?></b><?= $bnEnv ? ' <small>(from server environment)</small>' : '' ?><?php endif; ?></p>
  <?php if ($bnErr): ?><div class="err"><?= e($bnErr) ?></div><?php endif; ?>
  <?php if ($bnTest): ?><div class="<?= $bnTest[0] ? 'ok' : 'err' ?>" <?= $bnTest[0] ? 'style="padding:12px 14px;border-radius:10px;background:rgba(40,170,90,.14);margin:10px 0"' : '' ?>><?= e($bnTest[1]) ?></div>
  <?php elseif ($bnLastErr !== ''): ?><div class="err">Last automatic check problem: <?= e($bnLastErr) ?></div><?php endif; ?>
  <div class="grid2">
    <div><label>API key <small>(leave empty to keep the saved one)</small></label><input type="password" name="bn_key" autocomplete="off" placeholder="<?= $bnKeySet ? e(bn_mask(bn_key())) : 'Paste the Binance API key' ?>"></div>
    <div><label>API secret <small>(leave empty to keep the saved one)</small></label><input type="password" name="bn_secret" autocomplete="off" placeholder="<?= $bnSecSet ? e(bn_mask(bn_secret())) : 'Paste the Binance API secret' ?>"></div>
  </div>
  <small>Read-only permission is enough. You can also set BINANCE_API_KEY / BINANCE_API_SECRET on the server.</small>
  <?php if ($bnKeySet && !$bnEnv): ?><label class="chk"><input type="checkbox" name="bn_remove_keys" value="1"> <span>Remove the saved API key and secret</span></label><?php endif; ?>
  <div class="grid2">
    <div><label>Which method is “Binance Pay”?</label><?= $bnOpt('bn_pay_method', (string)setting('bn_pay_method', '')) ?></div>
    <div><label>Which method is “USDT BEP-20”? <small>(its account = your receiving wallet)</small></label><?= $bnOpt('bn_bsc_method', (string)setting('bn_bsc_method', '')) ?></div>
  </div>
  <div class="grid2">
    <div><label>BSC confirmations required</label><input type="number" name="bn_conf" min="1" max="200" step="1" value="<?= (int)max(1, min(200, (int)setting('bn_bsc_conf', '15'))) ?>"></div>
    <div><label>Binance API server</label><select name="bn_base"><?php foreach (BN_HOSTS as $h): ?><option value="<?= e($h) ?>"<?= (string)setting('bn_api_base', 'api.binance.com') === $h ? ' selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?></select></div>
  </div>
  <label>Own BSC RPC URL <small>(optional, https://…)</small></label><input type="text" name="bn_rpc" value="<?= e($_POST['bn_rpc'] ?? (string)setting('bn_bsc_rpc', '')) ?>" placeholder="https://…">
  <div class="savebar" style="display:flex;flex-wrap:wrap;gap:8px"><button class="btn" name="save_bn" value="1">Save Binance settings</button><button class="btn ghost" name="test_bn" value="1">Save &amp; test connection</button></div>
  <small style="display:block;margin-top:10px">Binance Pay needs the key + secret and the dollar rate (Admin &gt; Settings). Each transaction ID can only be credited once.</small>
</div>
</form>

<dialog id="mdlg"><form method="post" enctype="multipart/form-data" class="dlg"><?= csrf_field() ?><input type="hidden" name="pm_edit_id" id="m_id" value="">
  <div class="dlg-h"><h3 id="mTitle">Add payment method</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <?php if ($pmErr): ?><div class="err" style="margin-top:12px"><?= e($pmErr) ?></div><?php endif; ?>
  <div class="grid2">
    <div><label>Name</label><input type="text" name="pm_name" id="m_name" maxlength="40" required placeholder="e.g. bKash"></div>
    <div><label>Brand colour</label><input type="color" name="pm_color" id="m_color" value="#5b4bff" style="height:42px;padding:3px"></div>
  </div>
  <div class="grid2">
    <div><label>Account number / ID <small>(customers send money here)</small></label><input type="text" name="pm_acct" id="m_acct" maxlength="80" placeholder="01XXXXXXXXX"></div>
    <div><label>Label above the number</label><input type="text" name="pm_acct_label" id="m_acct_label" maxlength="40" placeholder="Send money to"></div>
  </div>
  <div class="grid2">
    <div><label>Short description</label><input type="text" name="pm_sub" id="m_sub" maxlength="60" placeholder="Send Money · Personal"></div>
    <div><label>USSD code <small>(optional)</small></label><input type="text" name="pm_ussd" id="m_ussd" maxlength="20" placeholder="*247#"></div>
  </div>
  <label>Transaction ID label</label><input type="text" name="pm_tid_label" id="m_tid_label" maxlength="30" placeholder="TrxID">
  <div class="grid2">
    <div><label>Minimum (৳) <small>(blank = global)</small></label><input type="number" name="pm_min" id="m_min_amt" min="0" step="0.01"></div>
    <div><label>Maximum (৳) <small>(blank = global)</small></label><input type="number" name="pm_max" id="m_max_amt" min="0" step="0.01"></div>
  </div>
  <div class="grid2">
    <div><label>Fee % <small>(taken from the deposit)</small></label><input type="number" name="pm_fee" id="m_fee_pct" min="0" max="50" step="0.01" value="0"></div>
    <div><label>Bonus % <small>(added to the deposit)</small></label><input type="number" name="pm_bonus" id="m_bonus_pct" min="0" max="100" step="0.01" value="0"></div>
  </div>
  <label>Steps shown to the customer <small>(one per line, max 8)</small></label><textarea name="pm_steps" id="m_steps" rows="4" placeholder="Open the bKash app&#10;Choose Send Money&#10;Enter the number above"></textarea>
  <label>Notice <small>(optional)</small></label><input type="text" name="pm_notice" id="m_notice" maxlength="200">
  <label>Logo <small>(PNG/JPG/WEBP, up to 1 MB — optional)</small></label><input type="file" name="pm_logo" id="m_logo" accept="image/png,image/jpeg,image/webp,image/gif">
  <label class="chk" id="m_rmwrap" hidden><input type="checkbox" name="pm_remove_logo" id="m_rmlogo" value="1"> <span>Remove the current logo</span></label>
  <label class="chk" style="margin-top:14px"><input type="checkbox" name="pm_usd" id="m_is_usd" value="1"> <span>This is a dollar (USD) method, e.g. Binance Pay</span></label>
  <label class="chk"><input type="checkbox" name="pm_enabled" id="m_enabled" value="1" checked> <span>Show this method to customers</span></label>
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" name="save_pm" value="1">Save method</button></div>
</form></dialog>

<?php /* ============================ SETTINGS ============================ */ elseif ($page === 'settings'): ?>
<?php if ($setErr): ?><div class="err"><?= e($setErr) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="card"><h3>General</h3>
  <label style="margin-top:0">Site name</label><input type="text" name="site_name" maxlength="40" required value="<?= e($_POST['site_name'] ?? site_name()) ?>">
  <label>Headline <small>(homepage)</small></label><input type="text" name="tagline" maxlength="120" value="<?= $sv('tagline') ?>">
  <label>Sub-headline</label><input type="text" name="subtitle" maxlength="300" value="<?= $sv('subtitle') ?>">
</div>
<div class="card"><h3>Support links</h3>
  <label style="margin-top:0">Telegram support link</label><input type="text" name="telegram_url" value="<?= $sv('telegram_url') ?>" placeholder="https://t.me/your_username">
  <label>WhatsApp support link</label><input type="text" name="whatsapp_url" value="<?= $sv('whatsapp_url') ?>" placeholder="https://wa.me/8801XXXXXXXXX">
  <label>Footer support link <small>(optional)</small></label><input type="text" name="support_url" value="<?= $sv('support_url') ?>">
</div>
<div class="card"><h3>Update notice popup</h3>
  <small>Visitors see this as a window in the middle of the screen. After they press OK it closes and they stay on the same page.</small>
  <label class="chk"><input type="checkbox" name="popup_on" value="1"<?= (isset($_POST['save_settings']) ? !empty($_POST['popup_on']) : setting('popup_on', '0') === '1') ? ' checked' : '' ?>> <span>Show the popup to visitors</span></label>
  <label>Title <small>(e.g. New update)</small></label><input type="text" name="popup_title" maxlength="80" value="<?= $sv('popup_title') ?>" placeholder="Notice">
  <label>Message</label><textarea name="popup_text" rows="5" maxlength="1500" placeholder="Write your notice or update here…"><?= $sv('popup_text') ?></textarea>
  <label>Style</label><select name="popup_type"><?php $pty = (string)($_POST['popup_type'] ?? setting('popup_type', 'info')); foreach (['info' => 'ℹ️ Info (blue)', 'success' => '✅ Success (green)', 'warning' => '⚠️ Warning (orange)', 'danger' => '🚨 Important (red)'] as $pk => $pl): ?><option value="<?= $pk ?>"<?= $pty === $pk ? ' selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?></select>
  <label>Button text <small>(default: OK)</small></label><input type="text" name="popup_btn" maxlength="20" value="<?= $sv('popup_btn') ?>" placeholder="OK">
  <label class="chk"><input type="checkbox" name="popup_again" value="1"> <span>Show again to everyone who already pressed OK <small>(changing the title or message does this automatically)</small></span></label>
</div>
<div class="card"><h3>Branding</h3>
  <label style="margin-top:0">Logo <small>(PNG/JPG/WEBP, up to 1 MB)</small></label>
  <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
  <?php if ($l = media_url('logo')): ?><img class="preview" src="<?= e($l) ?>" alt="Logo"><label class="rm"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label><?php endif; ?>
  <label>Homepage banner <small>(PNG/JPG/WEBP, up to 2 MB, wide image)</small></label>
  <input type="file" name="banner" accept="image/png,image/jpeg,image/webp,image/gif">
  <?php if ($bn = media_url('banner')): ?><img class="preview" src="<?= e($bn) ?>" alt="Banner"><label class="rm"><input type="checkbox" name="remove_banner" value="1"> Remove banner</label><?php endif; ?>
  <div class="savebar"><button class="btn" name="save_settings" value="1">Save settings</button></div>
</div>
</form>

<?php /* ============================ SUPPLIER ============================ */ elseif ($page === 'supplier'):
  $supUrl = (string)setting('suphot_url'); $hasKey = (string)setting('suphot_key') !== '';
  $supAuto = setting('suphot_auto') === '1'; ?>
<?php if ($supErr): ?><div class="err"><?= e($supErr) ?></div><?php endif; ?>
<?php if ($supTest): ?>
  <?php if ($supTest['ok']): ?><div class="card"><span class="badge on">Connected</span> Supplier balance: <b><?= e((string)($supTest['data']['balance_formatted'] ?? $supTest['data']['balance'] ?? '?')) ?></b> <small><?= e((string)($supTest['data']['email'] ?? '')) ?></small></div>
  <?php else: ?><div class="err">Connection failed: <?= e($supTest['error']) ?></div><?php endif; ?>
<?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><h3>Supplier 1 — Connection</h3>
  <label style="margin-top:0">Supplier API URL</label><input type="text" name="supplier_url" value="<?= e($_POST['supplier_url'] ?? $supUrl) ?>" placeholder="https://supplier-site.com/api/v1">
  <small>Base URL ending in /api/v1, no trailing slash.</small>
  <label>API key</label><input type="password" name="supplier_key" autocomplete="off" placeholder="<?= $hasKey ? 'Saved. Leave blank to keep it' : 'Paste your API key' ?>">
  <?php if ($hasKey): ?><label class="rm"><input type="checkbox" name="remove_key" value="1"> Remove saved key</label><?php endif; ?>
  <label class="chk"><input type="checkbox" name="supplier_auto" value="1"<?= $supAuto ? ' checked' : '' ?>> <span>Auto-deliver new orders for supplier-linked products</span></label>
  <div class="savebar"><button class="btn" name="save_supplier" value="1">Save</button> <button class="btn ghost" name="test_supplier" value="1">Test connection</button></div>
</div>
</form>
<?php $sc = ($hasKey && $supUrl !== '') ? suphot_catalog() : null; ?>
<div class="card"><h3>Supplier 1 — catalogue</h3>
<?php if ($sc === null): ?><div class="empty">Save the API URL and key first.</div>
<?php elseif (!$sc['ok']): ?><div class="err"><?= e($sc['error']) ?></div>
<?php else: ?><div class="tw"><table><thead><tr><th>Name</th><th class="hm">Type</th><th>Cost</th><th>Stock</th></tr></thead><tbody>
  <?php foreach ($sc['items'] as $it): ?>
  <tr><td><?= e((string)($it['name'] ?? '')) ?></td><td class="hm"><small><?= e(($it['product_type'] ?? '') . ' / ' . ($it['account_type'] ?? '')) ?></small></td><td class="money"><?= e((string)($it['price'] ?? '')) ?></td><td><?= number_format((int)($it['stock'] ?? 0)) ?></td></tr>
  <?php endforeach; if (!$sc['items']): ?><tr><td colspan="4">No products returned.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?></div>

<?php $bmOn = bm_connected(); $bmBal = $bmOn ? bm_balance() : null; $bmErr = (string)setting('bm_last_error', ''); ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><h3>Supplier 2 — bulkmail.shop</h3>
  <?php if ($bmOn): ?>
    <p><span class="badge on">Connected</span> Key: <b><?= e(bm_mask(bm_key())) ?></b><?php if ($bmBal): ?> · Balance: <b><?= e(number_format($bmBal['balance'], 2) . ' ' . $bmBal['currency']) ?></b><?php endif; ?></p>
    <?php if ($bmErr !== ''): ?><div class="err"><?= e($bmErr) ?></div><?php endif; ?>
    <label>USD → ৳ rate <small>(how many ৳ equal 1 USD)</small></label>
    <input type="number" name="bm_rate" step="0.0001" min="0" value="<?= e(rtrim(rtrim(number_format(bm_rate(), 4, '.', ''), '0'), '.')) ?>" placeholder="e.g. 125">
    <label>Profit % on all Supplier 2 products <small>(e.g. 20 = supplier price + 20%)</small></label>
    <input type="number" name="bm_markup" step="0.01" min="0" max="1000" value="<?= e(rtrim(rtrim(number_format(bm_markup(), 2, '.', ''), '0'), '.')) ?>" placeholder="e.g. 20">
    <label class="chk"><input type="checkbox" name="bm_price_all" value="1"<?= bm_price_all() ? ' checked' : '' ?>> <span>Apply to ALL Supplier 2 products (price follows the supplier automatically)</span></label>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;position:static"><button class="btn" name="bm_save_rate" value="1">Save rate &amp; profit</button> <button class="btn ghost" name="bm_sync_now" value="1">Sync now (name · price · description · domain · stock)</button></div>
    <small>Linked products also refresh automatically in the background (stock about every minute, name / price / description about every 2 minutes).</small>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;position:static"><button class="btn red" name="bm_disconnect" value="1" onclick="return confirm('Disconnect Supplier 2? Its linked products will show as out of stock.')">Disconnect</button></div>
  <?php else: ?>
    <label style="margin-top:0">API key</label><input type="password" name="bm_key" autocomplete="off" placeholder="Paste the bulkmail.shop API key">
    <small>Then open Products and link each product to Supplier 2 by its product ID.</small>
    <div class="savebar"><button class="btn" name="bm_connect" value="1">Connect</button></div>
  <?php endif; ?>
</div>
</form>
<?php endif; ?>
</main>
</div>

<script>
(function(){
  var $=function(s,r){return (r||document).querySelector(s)}, $$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};
  var REOPEN = null;

  document.addEventListener('keydown',function(e){ if(e.key==='Escape') document.body.classList.remove('menu'); });

  /* ---------- no page reloads: links and forms are sent in the background and only the page content is swapped ----------
     Admin pages are also prefetched and kept in memory, so a menu click shows the page instantly (it is refreshed quietly afterwards). */
  var inflight=0, saving=false, gen=0, cgen=0, cache={}, pend={}, TTL=5000, warming=false;
  var readReopen=function(){ var el=$('#reopenData'); try{ return el&&el.dataset.v ? JSON.parse(el.dataset.v) : null; }catch(x){ return null; } };
  var keyOf=function(u){ var x=new URL(u,location.href); return x.pathname+x.search; };

  /* swap the page content */
  var render=function(t,u,opt){
    var doc=new DOMParser().parseFromString(t,'text/html'), sh=doc.querySelector('.shell'), cur=$('.shell');
    if(!sh||!cur){ location.href=u; return false; }   /* e.g. the login page: do a normal load */
    var y=window.pageYOffset;
    cur.innerHTML=sh.innerHTML;
    document.title=doc.title;
    document.body.classList.remove('menu');
    if(opt.push) history.pushState(null,'',u); else history.replaceState(null,'',u);
    initPage();
    window.scrollTo(0,opt.top?0:y);
    return true;
  };
  /* keep a fetched page (only real admin pages, and only if no data was changed in the meantime) */
  var store=function(k,r,cg){
    if(cg!==cgen||!r.ok||r.t.indexOf('class="shell"')<0) return null;
    try{ if(new URL(r.u,location.href).pathname!=='/admin.php') return null; }catch(x){ return null; }
    return (cache[k]={t:r.t,u:r.u,at:Date.now()});
  };
  var getPage=function(k){
    if(pend[k]) return pend[k];
    var cg=cgen;
    var p=fetch(k,{credentials:'same-origin',headers:{'X-Requested-With':'fetch'}}).then(function(r){
      return r.text().then(function(t){ return store(k,{t:t,u:r.url,ok:r.ok},cg); });
    });
    pend[k]=p; var d=function(){ delete pend[k]; }; p.then(d,d);
    return p;
  };

  var soft=function(url,opt){
    opt=opt||{};
    if((opt.quiet&&inflight>0)||(opt.body&&saving)) return;
    if(opt.body) saving=true;
    var g=++gen, cg=cgen, k=keyOf(url);
    inflight++; if(!opt.quiet&&!opt.body) document.body.classList.add('ld');
    if(opt.body){ cache={}; cgen++; }        /* data is about to change: forget every saved page */
    var pill=null, pillT=null;
    if(opt.body){ pillT=setTimeout(function(){ pill=document.createElement('div'); pill.textContent='Saving…'; pill.style.cssText='position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#111827;color:#fff;padding:8px 16px;border-radius:99px;font-size:13px;z-index:9999;opacity:.92;pointer-events:none'; document.body.appendChild(pill); },900); }
    var init={credentials:'same-origin',headers:{'X-Requested-With':'fetch'}};
    if(opt.body){ init.method='POST'; init.body=opt.body; }
    var fdone=false, fin=function(){ if(fdone) return; fdone=true; inflight--; if(opt.body) saving=false; if(!inflight) document.body.classList.remove('ld'); clearTimeout(pillT); if(pill&&pill.parentNode) pill.parentNode.removeChild(pill); };
    fetch(url,init).then(function(r){ return r.text().then(function(t){ return {t:t,u:r.url,ok:r.ok}; }); }).then(function(r){
      fin();
      if(g!==gen) return;                      /* a newer click took over */
      if(!opt.body) store(k,r,cg);
      if(render(r.t,r.u,opt)&&opt.body) setTimeout(warm,600);
    }).catch(function(){ fin(); if(g===gen&&!opt.quiet) alert('Connection problem. Please try again.'); if(opt.body) soft(location.href,{quiet:true}); });
  };

  /* highlight the menu item at once (before the page is even there) */
  var mark=function(k){
    var p; try{ p=new URL(k,location.href).searchParams.get('p')||''; }catch(x){ return; }
    $$('.s-nav a').forEach(function(a){
      var q; try{ q=new URL(a.href,location.href).searchParams.get('p')||''; }catch(x){ return; }
      a.classList.toggle('on',q===p); if(q===p) a.setAttribute('aria-current','page'); else a.removeAttribute('aria-current');
    });
  };
  /* show the saved copy now, then quietly fetch a fresh one and swap it in only if it changed */
  var reval=function(k,prev){
    var g=gen;
    getPage(k).then(function(e){
      if(!e||g!==gen||e.t===prev) return;
      var kc=keyOf(location.href); if(kc!==k&&kc!==keyOf(e.u)) return;
      var a=document.activeElement;
      if(a&&/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)&&a.closest&&a.closest('.main')) return;
      if(document.querySelector('dialog[open]')) return;
      var dwEl=$('#dw'); if(dwEl&&!dwEl.hidden) return;
      render(e.t,e.u,{push:false,top:false});
    }).catch(function(){});
  };
  var nav=function(url,opt){
    var k=keyOf(url), c=cache[k];
    document.body.classList.remove('menu'); mark(k);
    if(c){ gen++; if(render(c.t,c.u,opt)&&Date.now()-c.at>TTL) reval(k,c.t); return; }
    soft(url,opt);
  };
  /* warm the cache with every menu page, one at a time */
  var warm=function(){
    if(warming||(navigator.connection&&navigator.connection.saveData)) return;
    warming=true;
    var ks=[], seen={}, here=keyOf(location.href);
    $$('.s-nav a').forEach(function(a){ try{ var u=new URL(a.href,location.href); var k=u.pathname+u.search; if(u.pathname==='/admin.php'&&!seen[k]){ seen[k]=1; if(k!==here) ks.push(k); } }catch(x){} });
    ks.push(here);
    var i=0;
    (function nx(){
      if(i>=ks.length){ warming=false; return; }
      var k=ks[i++];
      if(cache[k]){ nx(); return; }
      getPage(k).catch(function(){}).then(function(){ setTimeout(nx,400); });
    })();
  };
  var pf=function(e){
    var a=e.target.closest&&e.target.closest('.s-nav a'); if(!a) return;
    try{ var k=keyOf(a.href); if(!cache[k]) getPage(k).catch(function(){}); }catch(x){}
  };
  document.addEventListener('touchstart',pf,{passive:true});
  document.addEventListener('mouseover',pf);

  /* ---------- user quick-manage drawer (Users page); works after in-place page swaps ---------- */
  var dwCur=null;
  var dwTab=function(n){ var b=$('#dwBody'); if(!b) return; $$('[data-tab]',b).forEach(function(x){ x.classList.toggle('on',x.dataset.tab===n); }); $$('[data-pane]',b).forEach(function(p){ p.hidden=p.dataset.pane!==n; }); };
  var dwOpen=function(id,t){
    var dw=$('#dw'), b=$('#dwBody'); if(!dw||!b) return;
    dwCur=id; dw.hidden=false; document.body.style.overflow='hidden'; b.innerHTML='<div class="dw-load">Loading…</div>';
    fetch('/admin.php?uq='+encodeURIComponent(id)+'&ret='+encodeURIComponent(location.pathname+location.search),{credentials:'same-origin'})
      .then(function(r){return r.text();}).then(function(h){ var b2=$('#dwBody'); if(dwCur!==id||!b2) return; b2.innerHTML=h; dwTab(t||'ov'); b2.scrollTop=0; })
      .catch(function(){ var b2=$('#dwBody'); if(b2) b2.innerHTML='<div class="err" style="margin:20px">Could not load this user. Try again.</div>'; });
  };
  var dwClose=function(){ var dw=$('#dw'); if(dw) dw.hidden=true; dwCur=null; document.body.style.overflow=''; };
  document.addEventListener('click',function(e){
    var o=e.target.closest('[data-uq]'); if(o){ e.preventDefault(); dwOpen(o.dataset.uq,o.dataset.t); return; }
    if(e.target.closest('[data-dwclose]')){ dwClose(); return; }
    var tb=e.target.closest('#dwBody [data-tab]'); if(tb){ dwTab(tb.dataset.tab); return; }
    var ch=e.target.closest('#dwBody [data-amt]');
    if(ch){ var b=$('#dwBody'), a=b.querySelector('input[name=amount]'), r=b.querySelector('input[name=dir][value='+ch.dataset.dir+']'); if(a) a.value=ch.dataset.amt; if(r) r.checked=true; }
  });
  document.addEventListener('keydown',function(e){ var dw=$('#dw'); if(e.key==='Escape'&&dw&&!dw.hidden) dwClose(); });

  document.addEventListener('click',function(e){
    if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey) return;
    var a=e.target.closest&&e.target.closest('a[href]');
    if(!a||a.target||a.hasAttribute('download')||a.getAttribute('href').charAt(0)==='#') return;
    var u; try{ u=new URL(a.href,location.href); }catch(x){ return; }
    if(u.origin!==location.origin||u.pathname!=='/admin.php') return;
    if(u.searchParams.has('export')) return;                       /* file downloads: normal browser handling */
    e.preventDefault();
    if(keyOf(u.href)===keyOf(location.href)){ window.scrollTo(0,0); reval(keyOf(u.href),cache[keyOf(u.href)]&&cache[keyOf(u.href)].t); if(!cache[keyOf(u.href)]) soft(u.href,{top:true}); return; }
    nav(u.href,{push:true,top:true});
  });
  document.addEventListener('submit',function(e){
    var f=e.target;
    if(!f||f.tagName!=='FORM'||(f.getAttribute('method')||'get').toLowerCase()!=='post'||(f.target&&f.target!=='_self')) return;
    var u; try{ u=new URL(f.action||location.href,location.href); }catch(x){ return; }
    if(u.origin!==location.origin||u.pathname!=='/admin.php') return;
    e.preventDefault();
    var fd=new FormData(f), s=e.submitter;
    if(s&&s.name&&!fd.has(s.name)) fd.append(s.name,s.value);
    if(saving) return;                                              /* a save is still running */
    optimistic(f,s);
    soft(u.href,{body:fd});
  });
  /* show the result of a save/edit/delete at once; the server's answer then replaces it with the real data (and fixes it if something failed) */
  var optimistic=function(f,s){
    var dl=f.closest&&f.closest('dialog'); if(dl&&dl.open){ try{ dl.close(); }catch(x){} return; }
    var n=s&&s.name; if(!n) return;
    var row;
    if(n==='deliver'||n==='cancel_order'){ row=f.closest('.ord'); if(row) row.style.display='none'; }
    else if(n==='del_expense'||n==='pm_delete'||n==='delete_category'||n==='u_disc_del'||n==='delete_product'){ row=f.closest('tr,.mini'); if(row) row.style.display='none'; }
    else if(n==='toggle_product'||n==='pm_toggle'||n==='toggle_category'){ var t=(s.textContent||'').trim(); if(t==='Hide') s.textContent='Show'; else if(t==='Show') s.textContent='Hide'; }
  };

  /* ---------- live search / filters: results appear while you type (the search box itself is never replaced, so the keyboard stays open) ---------- */
  var lt=null, lseq=0;
  var keepSwap=function(t,u,keep){
    var doc=new DOMParser().parseFromString(t,'text/html'), sh=doc.querySelector('.shell'), cur=$('.shell');
    if(!sh||!cur||!cur.contains(keep)) return false;
    var sync=function(o,n){
      var oc=Array.prototype.slice.call(o.childNodes), nc=Array.prototype.slice.call(n.childNodes), ki=-1, i, j;
      for(i=0;i<oc.length;i++){ if(oc[i]===keep||(oc[i].contains&&oc[i].contains(keep))){ ki=i; break; } }
      if(ki<0||ki>=nc.length||oc[ki].nodeName!==nc[ki].nodeName) return false;
      for(j=oc.length-1;j>ki;j--) o.removeChild(oc[j]);
      for(j=ki+1;j<nc.length;j++) o.appendChild(document.importNode(nc[j],true));
      for(j=0;j<ki;j++) o.removeChild(oc[j]);
      for(j=0;j<ki;j++) o.insertBefore(document.importNode(nc[j],true),oc[ki]);
      return oc[ki]===keep ? true : sync(oc[ki],nc[ki]);
    };
    var y=window.pageYOffset;
    if(!sync(cur,sh)) return false;
    document.title=doc.title;
    history.replaceState(null,'',u);
    initPage();
    window.scrollTo(0,y);
    return true;
  };
  var liveFilter=function(form,keep,now){
    clearTimeout(lt);
    lt=setTimeout(function(){
      var ps=new URLSearchParams(), fd=new FormData(form);
      fd.forEach(function(v,k){ if(typeof v==='string'&&v!=='') ps.append(k,v); });
      var url='/admin.php?'+ps.toString(), my=++lseq, cg=cgen;
      fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'fetch'}})
        .then(function(r){ return r.text().then(function(t){ return {t:t,u:r.url,ok:r.ok}; }); })
        .then(function(r){
          if(my!==lseq||!document.body.contains(keep)) return;
          store(keyOf(url),r,cg);
          if(!keepSwap(r.t,r.u,keep)) render(r.t,r.u,{push:false,top:false});
        }).catch(function(){});
    }, now?0:220);
  };
  var isFilterForm=function(f){ return f&&f.tagName==='FORM'&&(f.getAttribute('method')||'get').toLowerCase()==='get'&&f.querySelector('input[type=hidden][name=p]')&&$('.main')&&$('.main').contains(f); };
  document.addEventListener('input',function(e){
    var i=e.target; if(e.isComposing||!i||!i.matches||!i.matches('input[type=search][name]')||!isFilterForm(i.form)) return;
    liveFilter(i.form,i,false);
  });
  document.addEventListener('change',function(e){
    var i=e.target; if(!i||!i.matches||!i.matches('select,input[type=date]')||!isFilterForm(i.form)) return;
    liveFilter(i.form,i,true);
  });
  document.addEventListener('submit',function(e){
    var f=e.target; if(!isFilterForm(f)) return;
    e.preventDefault();
    var i=f.querySelector('input[type=search][name]')||f.elements[0];
    liveFilter(f,document.activeElement&&f.contains(document.activeElement)?document.activeElement:i,true);
  });
  window.addEventListener('popstate',function(){ nav(location.href,{top:true}); });

  function initPage(){
  REOPEN=readReopen();
  dwCur=null; document.body.style.overflow='';
  var dm=location.search.match(/[?&]open=(\d+)(?:&t=(\w+))?/); if(dm&&$('#dw')) dwOpen(dm[1],dm[2]||'ov');

  /* dialogs: close buttons + click on backdrop */
  $$('dialog').forEach(function(d){
    $$('[data-close]',d).forEach(function(b){ b.addEventListener('click',function(){ d.close(); }); });
    d.addEventListener('click',function(e){ if(e.target===d) d.close(); });
  });

  /* ---------- products ---------- */
  var pd=$('#pdlg');
  if(pd){
    var showSrc=function(){ var s=$('#p_src').value; $('#p_src_hot').hidden=s!=='hot'; $('#p_src_bm').hidden=s!=='bm'; };
    $('#p_src').addEventListener('change',showSrc);
    var fill=function(v,id){
      $('#pTitle').textContent = id ? 'Edit product' : 'Add product';
      $('#p_name').value=v.name||''; $('#p_desc').value=v.desc||''; $('#p_price').value=v.price||''; $('#p_cost').value=(v.cost===null||v.cost===undefined)?'':v.cost;
      $('#p_unit').value=v.unit||''; $('#p_cat').value=(v.cat===null||v.cat===undefined)?'':v.cat;
      $('#p_stock').value=(v.stock===null||v.stock===undefined)?'':v.stock; $('#p_pop').checked=!!+v.pop; $('#p_cs').value=(v.cs==='midnight')?'midnight':''; $('#p_sup').value=v.sup||'';
      $('#p_src').value=v.src||''; $('#p_bmid').value=v.bmid||'';
      $('#p_ap').checked=!!+v.ap; $('#p_an').checked=!!+v.an; $('#p_ad').checked=!!+v.ad; $('#p_sd').checked=(v.sd===undefined||v.sd===null)?true:!!+v.sd; showSrc();
      var s=$('#pSave'); s.name = id ? 'update_product' : 'add_product'; s.value = id ? id : '1';
      pd.showModal();
    };
    /* Supplier 2: after the product ID is typed (and the boxes ticked) fill name / price / description straight away */
    var bmSeq=0, bmTimer=null, bmNote=$('#p_bmnote');
    var bmLookup=function(){
      var id=parseInt($('#p_bmid').value,10);
      if($('#p_src').value!=='bm'||!id||id<1){ bmNote.textContent=''; return; }
      if(!$('#p_an').checked&&!$('#p_ad').checked&&!$('#p_ap').checked){ bmNote.textContent='Tick a box below to fill it from Supplier 2.'; return; }
      var my=++bmSeq; bmNote.textContent='Loading from Supplier 2…';
      fetch('/admin.php?bm_lookup='+id,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        if(my!==bmSeq) return;
        if(!j.ok){ bmNote.textContent=j.error||'Could not read the product.'; return; }
        var done=[];
        if($('#p_an').checked&&j.name){ $('#p_name').value=j.name; done.push('name'); }
        if($('#p_ad').checked&&j.desc){ $('#p_desc').value=j.desc; done.push('description'); }
        if($('#p_ap').checked||j.all){
          if(j.price){ $('#p_price').value=j.price; done.push('price'); }
          else if(!j.rate_set){ bmNote.textContent='Set the USD → ৳ rate in Supplier API to fill the price.'; return; }
        }
        bmNote.textContent=done.length?('Filled: '+done.join(', ')+'.'):'Nothing to fill.';
      }).catch(function(){ if(my===bmSeq) bmNote.textContent='Could not reach Supplier 2.'; });
    };
    var bmSoon=function(){ clearTimeout(bmTimer); bmTimer=setTimeout(bmLookup,500); };
    ['#p_bmid'].forEach(function(q){ $(q).addEventListener('input',bmSoon); });
    ['#p_an','#p_ad','#p_ap','#p_src'].forEach(function(q){ $(q).addEventListener('change',bmSoon); });
    $('#addProduct').addEventListener('click',function(){ fill({},0); bmNote.textContent=''; });
    $$('[data-edit-p]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editP); fill({name:v.name,desc:v.desc,price:v.price,cost:v.cost,unit:v.unit,cat:v.cat,stock:v.stock,pop:v.pop,src:v.src,sup:v.sup,bmid:v.bmid,an:v.an,ad:v.ad,sd:v.sd,ap:v.ap,mk:v.mk}, v.id); }); });
    if(REOPEN && REOPEN.t==='product') fill(REOPEN.v, REOPEN.id);

    var q=$('#pq'), c=$('#pcat'), none=$('#pnone');
    var filt=function(){
      var t=q.value.trim().toLowerCase(), cv=c.value, n=0, rows=$$('#ptable tbody tr');
      rows.forEach(function(r){ var ok=(!t||r.dataset.s.indexOf(t)>-1)&&(cv===''||r.dataset.c===cv); r.style.display=ok?'':'none'; if(ok)n++; });
      none.textContent='No products match your search.'; none.style.display = (rows.length && !n) ? '' : 'none';
    };
    var sx=$('#pexs'), xa=$('#pexa');
    var exSync=function(){
      var sv=sx.value; xa.href='/admin.php?export=stock&s='+sv+(c.value!==''?'&c='+encodeURIComponent(c.value):'');
      $$('[data-pexp]').forEach(function(a){ a.href='/admin.php?export=stock&pid='+a.dataset.pexp+'&s='+sv; });
    };
    sx.addEventListener('change',exSync); c.addEventListener('change',exSync);
    var PF='adm_pfilter', pst=null;
    try{ pst=JSON.parse(sessionStorage.getItem(PF)||'null'); }catch(x){}
    if(pst){ if(pst.q) q.value=pst.q; if(pst.c!==undefined&&pst.c!==null&&c.querySelector('option[value="'+pst.c+'"]')) c.value=pst.c; }
    var keep=function(){ try{ sessionStorage.setItem(PF,JSON.stringify({q:q.value,c:c.value})); }catch(x){} };
    q.addEventListener('input',function(){ filt(); keep(); }); c.addEventListener('change',function(){ filt(); keep(); });
    filt(); exSync();
    var rr=$('#ptable tr[data-recent]');
    if(rr){ if(rr.style.display==='none'){ q.value=''; c.value=''; keep(); filt(); } try{ rr.scrollIntoView({block:'center'}); }catch(x){} }
  }

  /* ---------- transactions ---------- */
  var tq=$('#txq');
  if(tq){
    var tn=$('#txnone');
    tq.addEventListener('input',function(){
      var t=tq.value.trim().toLowerCase(), n=0, rows=$$('#txtable tbody tr[data-s]');
      rows.forEach(function(r){ var ok=!t||r.dataset.s.indexOf(t)>-1; r.style.display=ok?'':'none'; if(ok)n++; });
      tn.textContent='No match for your search.'; tn.style.display=(rows.length&&!n)?'':'none';
    });
    $$('[data-copy]').forEach(function(b){ b.addEventListener('click',function(){
      var v=b.dataset.copy, done=function(){ var o=b.textContent; b.textContent='copied'; setTimeout(function(){ b.textContent=o; },1000); };
      if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(v).then(done,function(){}); }
      else { var ta=document.createElement('textarea'); ta.value=v; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy'); done();}catch(e){} document.body.removeChild(ta); }
    }); });
    var sd=$('#sdlg');
    $$('[data-view]').forEach(function(b){ b.addEventListener('click',function(){
      var v=JSON.parse(b.dataset.view), m=$('#sdMeta'); m.textContent='';
      [['Received',v.time],['Wallet',v.wallet],['TrxID',v.trx||'—'],['Amount',v.amount||'—'],['Sender',v.sender+(v.contact?' ('+v.contact+')':'')],['Status',v.status]].forEach(function(p){
        var k=document.createElement('span'), d=document.createElement('span'); k.textContent=p[0]; d.textContent=p[1]; m.appendChild(k); m.appendChild(d);
      });
      $('#sdText').textContent=v.body||'(empty)'; sd.showModal();
    }); });
    /* new SMS: refresh the list in the background every 30 s unless the admin is searching or has a window open */
    clearInterval(window.__txT);
    window.__txT=setInterval(function(){
      var au=$('#txauto'), q=$('#txq'), d=$('#sdlg');
      if(au && q && d && au.checked && !q.value && !d.open && !document.hidden) soft(location.href,{quiet:true});
    }, 30000);
  }

  /* ---------- payment methods ---------- */
  var md=$('#mdlg');
  if(md){
    var fillM=function(v,id){
      $('#mTitle').textContent = id ? 'Edit payment method' : 'Add payment method';
      $('#m_id').value = id || '';
      var set=function(k,d){ $('#m_'+k).value = (v[k]===undefined||v[k]===null) ? (d||'') : v[k]; };
      set('name'); set('acct'); set('acct_label'); set('sub'); set('ussd'); set('tid_label'); set('min_amt'); set('max_amt');
      set('fee_pct','0'); set('bonus_pct','0'); set('steps'); set('notice');
      $('#m_color').value = /^#[0-9a-f]{6}$/i.test(v.color||'') ? v.color : '#5b4bff';
      $('#m_is_usd').checked = !!+v.is_usd;
      $('#m_enabled').checked = (v.enabled===undefined||v.enabled===null) ? true : !!+v.enabled;
      $('#m_logo').value=''; $('#m_rmlogo').checked=false; $('#m_rmwrap').hidden = !(+v.has_logo);
      md.showModal();
    };
    $('#addPm').addEventListener('click',function(){ fillM({},0); });
    $$('[data-edit-m]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editM); fillM(v,v.id); }); });
    if(REOPEN && REOPEN.t==='pm') fillM(REOPEN.v, REOPEN.id);
  }

  /* ---------- users ---------- */
  var uq=$('#uq');
  if(uq){ uq.addEventListener('input',function(){
    var t=uq.value.trim().toLowerCase(), n=0, rows=$$('#utable tbody tr[data-s]');
    rows.forEach(function(r){ var ok=!t||r.dataset.s.indexOf(t)>-1; r.style.display=ok?'':'none'; if(ok)n++; });
    $('#unone').style.display = (rows.length && !n) ? '' : 'none';
  }); }

  /* ---------- categories + premium icon picker ---------- */
  var cd=$('#cdlg');
  if(cd){
    var hid=$('#c_icon'), cur=$('#ipkCur'), nm=$('#ipkName'), pop=$('#ipkPop'), btn=$('#ipkBtn'), em=$('#ipkEmoji');
    var esc=function(s){ return String(s).replace(/[&<>"']/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}); };
    var setIcon=function(v){
      v=v||'app:shop'; hid.value=v;
      var opt=null; $$('.ipk-o').forEach(function(o){ var on=o.dataset.v===v; o.classList.toggle('sel',on); if(on)opt=o; });
      if(v.indexOf('app:')===0){
        if(!opt){ opt=$('.ipk-o[data-v="app:shop"]'); hid.value='app:shop'; }
        cur.innerHTML=opt.querySelector('svg').outerHTML.replace(/ai\d+[gs]/g,function(m){return m+'p';});
        var sv=cur.firstChild; sv.setAttribute('width','32'); sv.setAttribute('height','32');
        nm.textContent=opt.dataset.n; em.value='';
      } else { cur.textContent=v; nm.textContent='Emoji'; em.value=v; }
    };
    var toggle=function(open){ pop.hidden=!open; btn.setAttribute('aria-expanded',open?'true':'false'); if(open){ $('#ipkQ').value=''; srch(''); } };
    var srch=function(t){
      t=t.trim().toLowerCase(); var any=false;
      $$('.ipk-o').forEach(function(o){ var ok=!t||o.dataset.n.toLowerCase().indexOf(t)>-1||o.dataset.v.indexOf(t)>-1; o.style.display=ok?'':'none'; if(ok)any=true; });
      $$('#ipkList .ipk-grid').forEach(function(g){ var vis=$$('.ipk-o',g).some(function(o){return o.style.display!=='none';}); g.style.display=vis?'':'none'; g.previousElementSibling.style.display=vis?'':'none'; });
      $('#ipkNone').style.display=any?'none':'block';
    };
    btn.addEventListener('click',function(){ toggle(pop.hidden); });
    $('#ipkQ').addEventListener('input',function(e){ srch(e.target.value); });
    $$('.ipk-o').forEach(function(o){ o.addEventListener('click',function(){ setIcon(o.dataset.v); toggle(false); }); });
    em.addEventListener('input',function(){ var v=em.value.trim(); if(v){ setIcon(v); } else { setIcon('app:shop'); } });
    cd.addEventListener('close',function(){ toggle(false); });

    var fillC=function(v,id){
      $('#cTitle').textContent = id ? 'Edit category' : 'Add category';
      $('#c_name').value=v.name||''; $('#c_sort').value=(v.sort===undefined||v.sort===null)?0:v.sort;
      setIcon(v.icon||'app:shop'); toggle(false);
      var s=$('#cSave'); s.name = id ? 'update_category' : 'add_category'; s.value = id ? id : '1';
      cd.showModal();
    };
    $('#addCategory').addEventListener('click',function(){ fillC({},0); });
    $$('[data-edit-c]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editC); fillC(v,v.id); }); });
    if(REOPEN && REOPEN.t==='category') fillC(REOPEN.v, REOPEN.id);
  }
  }
  initPage();
  setTimeout(warm,1200);
})();
</script>
</body></html>
