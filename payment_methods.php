<?php
/* ===========================================================================
 * payment_methods.php - shared by admin.php (manage) and deposits.php (use).
 * Payment methods live in the `payment_methods` table (created automatically
 * the first time this file is used; bKash / Nagad / Rocket / Binance are added
 * once, and any old pay_bkash / pay_nagad ... setting is copied in as the number).
 * Method logos: an uploaded image (stored in the existing `media` table under the
 * key pm_<id>) or, when none is uploaded, a built-in brand-coloured SVG mark.
 * =========================================================================== */

/** Built-in methods added the first time. */
function pm_defaults(): array {
    return [
        ['id' => 'bkash',   'name' => 'bKash',   'sub' => 'Send Money · Personal', 'color' => '#e2136e', 'ussd' => '*247#', 'acct_label' => 'Send money to',   'tid_label' => 'TrxID',    'is_usd' => 0],
        ['id' => 'nagad',   'name' => 'Nagad',   'sub' => 'Send Money · Personal', 'color' => '#f26a21', 'ussd' => '*167#', 'acct_label' => 'Send money to',   'tid_label' => 'TrxID',    'is_usd' => 0],
        ['id' => 'rocket',  'name' => 'Rocket',  'sub' => 'Send Money · Personal', 'color' => '#8c3494', 'ussd' => '*322#', 'acct_label' => 'Send money to',   'tid_label' => 'TrxID',    'is_usd' => 0],
        ['id' => 'binance', 'name' => 'Binance', 'sub' => 'Binance Pay · USD',     'color' => '#f0b90b', 'ussd' => '',      'acct_label' => 'Binance Pay ID', 'tid_label' => 'Order ID', 'is_usd' => 1],
    ];
}

/** Create the table on first use and add the built-in methods once. */
function pm_install(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id VARCHAR(24) NOT NULL PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        sub VARCHAR(60) NOT NULL DEFAULT '',
        color VARCHAR(7) NOT NULL DEFAULT '#5b4bff',
        ussd VARCHAR(20) NOT NULL DEFAULT '',
        acct VARCHAR(80) NOT NULL DEFAULT '',
        acct_label VARCHAR(40) NOT NULL DEFAULT 'Send money to',
        tid_label VARCHAR(30) NOT NULL DEFAULT 'TrxID',
        is_usd TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        min_amt DECIMAL(12,2) NULL,
        max_amt DECIMAL(12,2) NULL,
        fee_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        bonus_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        steps TEXT NULL,
        notice VARCHAR(200) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        has_logo TINYINT(1) NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4");
    if ((int)$pdo->query('SELECT COUNT(*) FROM payment_methods')->fetchColumn() === 0 && setting('pm_seeded', '0') !== '1') {
        $ins = $pdo->prepare('INSERT INTO payment_methods (id, name, sub, color, ussd, acct, acct_label, tid_label, is_usd, enabled, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $n = 0;
        foreach (pm_defaults() as $d) {
            $n++;
            $old = trim((string)setting('pay_' . $d['id'], ''));   // number saved by the old version of deposits.php
            $ins->execute([$d['id'], $d['name'], $d['sub'], $d['color'], $d['ussd'], $old, $d['acct_label'], $d['tid_label'], $d['is_usd'], 1, $n]);
        }
        save_setting('pm_seeded', '1');
    }
}

/** All methods in display order, keyed by id. */
function pm_all(bool $onlyEnabled = false): array {
    pm_install();
    $out = [];
    foreach (db()->query('SELECT * FROM payment_methods ORDER BY sort_order, id')->fetchAll() as $r) {
        if ($onlyEnabled && !(int)$r['enabled']) continue;
        $out[$r['id']] = $r;
    }
    return $out;
}

/** True when the number is a real one (not empty and not a placeholder like 01XXXXXXXXX). */
function pm_acct_ok(string $a): bool {
    $a = trim($a);
    return $a !== '' && !preg_match('/X{3,}/i', $a);
}

/** A method users can actually pay with right now. */
function pm_usable(array $m, float $usdRate): bool {
    return (int)$m['enabled'] === 1 && pm_acct_ok((string)$m['acct']) && (!(int)$m['is_usd'] || $usdRate > 0);
}

/** Global deposit settings (Admin > Payment methods). */
function dep_cfg(): array {
    $min = (float)setting('dep_min', '10');        if ($min < 1) $min = 10;
    $max = (float)setting('dep_max', '100000');    if ($max < $min) $max = max($min, 100000);
    $pend = (int)setting('dep_max_pending', '5');  if ($pend < 1 || $pend > 50) $pend = 5;
    $chips = [];
    foreach (explode(',', (string)setting('dep_chips', '100,200,500,1000,2000,5000')) as $c) {
        $c = (int)trim($c);
        if ($c >= 1 && $c <= 10000000 && !in_array($c, $chips, true)) $chips[] = $c;
    }
    if (!$chips) $chips = [100, 200, 500, 1000, 2000, 5000];
    return [
        'on'      => setting('dep_on', '1') !== '0',
        'min'     => $min,
        'max'     => $max,
        'pending' => $pend,
        'chips'   => array_slice($chips, 0, 8),
        'notice'  => trim((string)setting('dep_notice', '')),
        'off_msg' => trim((string)setting('dep_off_msg', '')),
    ];
}

/** Amount range for one method: the method's own limits can only narrow the global range. */
function pm_limits(array $m, array $cfg): array {
    $min = $cfg['min']; $max = $cfg['max'];
    if ($m['min_amt'] !== null && $m['min_amt'] !== '') $min = max($min, (float)$m['min_amt']);
    if ($m['max_amt'] !== null && $m['max_amt'] !== '' && (float)$m['max_amt'] > 0) $max = min($max, (float)$m['max_amt']);
    return [$min, $max];
}

/** What the user's wallet gets for a payment of $amt: minus the method fee, plus the method bonus. */
function pm_credit(array $m, float $amt): float {
    return round($amt - $amt * (float)$m['fee_pct'] / 100 + $amt * (float)$m['bonus_pct'] / 100, 2);
}

/* ------------------------------ logos ------------------------------ */

/** Simple brand-coloured marks used until a real logo image is uploaded. */
function pm_default_svg(string $id): string {
    $h = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
    switch ($id) {
        case 'bkash':
            return $h . '<rect width="64" height="64" fill="#e2136e"/><path d="M12 24 52 12 41 33l12 4-27 18 5-18-13-3z" fill="#fff"/><path d="M26 37l15-4-10 12z" fill="#e2136e" opacity=".35"/></svg>';
        case 'nagad':
            return $h . '<rect width="64" height="64" fill="#f26a21"/><path d="M32 10c12 0 22 9 22 22S44 54 32 54 10 45 10 32 20 10 32 10zm-9 33h5V29l9 14h6V21h-5v14l-9-14h-6z" fill="#fff" fill-rule="evenodd"/></svg>';
        case 'rocket':
            return $h . '<rect width="64" height="64" fill="#8c3494"/><path d="M32 9c9 6 13 17 11 31l-5 6h-12l-5-6c-2-14 2-25 11-31z" fill="#fff"/><circle cx="32" cy="27" r="5" fill="#8c3494"/><path d="M21 38 12 49l12-3zM43 38l9 11-12-3z" fill="#fff"/><path d="M28 49h8l-4 8z" fill="#ffd166"/></svg>';
        case 'binance':
            return $h . '<rect width="64" height="64" fill="#1e2026"/><g fill="#f0b90b"><path d="M32 11l8 8-4 4-4-4-4 4-4-4z"/><path d="M32 53l-8-8 4-4 4 4 4-4 4 4z"/><path d="M11 32l8-8 4 4-8 8-4-4z" transform="translate(0 0)"/><path d="M53 32l-8-8-4 4 8 8z"/><path d="M32 25.5 38.5 32 32 38.5 25.5 32z"/></g></svg>';
    }
    return '';
}

/** data: URI of the uploaded logo, or '' when the method has none. */
function pm_logo_src(array $m): string {
    static $cache = [];
    if (empty($m['has_logo']) || empty($m['id'])) return '';
    $id = (string)$m['id'];
    if (!isset($cache[$id])) {
        $st = db()->prepare('SELECT mime, data FROM media WHERE k = ?');
        $st->execute(['pm_' . $id]);
        $r = $st->fetch();
        $cache[$id] = $r ? 'data:' . $r['mime'] . ';base64,' . base64_encode((string)$r['data']) : '';
    }
    return $cache[$id];
}

/** The method's logo as a rounded square: uploaded image > built-in SVG mark > first letter. */
function pm_logo_html(array $m, int $px = 44): string {
    $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($m['color'] ?? '')) ? $m['color'] : '#7a7f99';
    $st = 'width:' . $px . 'px;height:' . $px . 'px;border-radius:' . (int)round($px * 0.3) . 'px;--c:' . $color;
    $name = (string)($m['name'] ?? '');
    $src = pm_logo_src($m);
    if ($src !== '') return '<span class="pm-lg has-img" style="' . $st . '"><img src="' . e($src) . '" alt="' . e($name) . '"></span>';
    $svg = pm_default_svg((string)($m['id'] ?? ''));
    if ($svg !== '') return '<span class="pm-lg" style="' . $st . '" title="' . e($name) . '">' . $svg . '</span>';
    return '<span class="pm-lg" style="' . $st . ';font-size:' . (int)round($px * 0.43) . 'px">' . e($name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '৳') . '</span>';
}

/** CSS for pm_logo_html(); echo once per page. */
function pm_css(): string {
    return '<style>.pm-lg{display:inline-grid;place-items:center;flex:none;overflow:hidden;background:var(--c,#7a7f99);color:#fff;font-weight:800;vertical-align:middle;line-height:1}'
        . '.pm-lg svg{width:100%;height:100%;display:block}.pm-lg.has-img{background:#fff;border:1px solid rgba(0,0,0,.1)}'
        . '.pm-lg img{width:100%;height:100%;object-fit:contain;display:block}</style>';
}

/** Save an uploaded logo (PNG/JPG/WEBP/GIF, up to 1 MB). Returns '' on success or when no file was chosen, else an error message. */
function pm_save_logo(string $id, ?array $f): string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1048576) return 'Logo is too large (max 1 MB).';
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) return 'Logo must be a PNG, JPG, WEBP or GIF image.';
    $bin = (string)file_get_contents($f['tmp_name']); $mime = $info['mime'];
    if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {   // shrink to a neat 192px square so pages stay light
        $src = @imagecreatefromstring($bin);
        if ($src) {
            $w = imagesx($src); $h = imagesy($src); $S = 192; $sc = min($S / $w, $S / $h, 1);
            $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
            $dst = imagecreatetruecolor($S, $S); imagealphablending($dst, false); imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagealphablending($dst, true);
            imagecopyresampled($dst, $src, (int)(($S - $nw) / 2), (int)(($S - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagepng($dst); $png = (string)ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
            if ($png !== '') { $bin = $png; $mime = 'image/png'; }
        }
    } elseif (strlen($bin) > 307200) return 'Logo is too large (max 300 KB on this server).';
    $st = db()->prepare('REPLACE INTO media (k, mime, data) VALUES (?,?,?)');
    $st->bindValue(1, 'pm_' . $id); $st->bindValue(2, $mime); $st->bindValue(3, $bin, PDO::PARAM_LOB);
    $st->execute();
    db()->prepare('UPDATE payment_methods SET has_logo = 1 WHERE id = ?')->execute([$id]);
    return '';
}

/* ------------------------------ admin actions ------------------------------ */

function pm_slug(string $name): string {
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
    $s = substr($s !== '' ? $s : 'method', 0, 18);
    $base = $s; $i = 1; $st = db()->prepare('SELECT COUNT(*) FROM payment_methods WHERE id = ?');
    while (true) { $st->execute([$s]); if (!(int)$st->fetchColumn()) return $s; $s = substr($base, 0, 20) . (++$i); }
}

/** Read and check the add/edit form. Returns [fields, error]. */
function pm_fields_from_post(?string $editId): array {
    $name = trim((string)($_POST['pm_name'] ?? ''));
    $f = [
        'name'       => $name,
        'acct'       => mb_substr(trim((string)($_POST['pm_acct'] ?? '')), 0, 80),
        'acct_label' => mb_substr(trim((string)($_POST['pm_acct_label'] ?? '')), 0, 40) ?: 'Send money to',
        'sub'        => mb_substr(trim((string)($_POST['pm_sub'] ?? '')), 0, 60),
        'ussd'       => mb_substr(trim((string)($_POST['pm_ussd'] ?? '')), 0, 20),
        'tid_label'  => mb_substr(trim((string)($_POST['pm_tid_label'] ?? '')), 0, 30) ?: 'TrxID',
        'color'      => preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['pm_color'] ?? '')) ? strtolower($_POST['pm_color']) : '#5b4bff',
        'is_usd'     => empty($_POST['pm_usd']) ? 0 : 1,
        'enabled'    => empty($_POST['pm_enabled']) ? 0 : 1,
        'notice'     => mb_substr(trim((string)($_POST['pm_notice'] ?? '')), 0, 200),
    ];
    if ($name === '' || mb_strlen($name) > 40) return [$f, 'Method name is required (max 40 characters).'];
    if (strpos($name, '|') !== false) return [$f, 'The name cannot contain the | character.'];
    $st = db()->prepare('SELECT COUNT(*) FROM payment_methods WHERE LOWER(name) = LOWER(?) AND id <> ?');
    $st->execute([$name, (string)$editId]);
    if ((int)$st->fetchColumn()) return [$f, 'Another method already uses this name.'];
    foreach (['min' => 'Minimum', 'max' => 'Maximum'] as $k => $l) {
        $v = trim((string)($_POST['pm_' . $k] ?? ''));
        if ($v === '') { $f[$k . '_amt'] = null; continue; }
        $n = (float)str_replace(',', '', $v);
        if ($n < 0 || $n > 100000000) return [$f, $l . ' amount is not valid.'];
        $f[$k . '_amt'] = $n > 0 ? round($n, 2) : null;
    }
    if ($f['min_amt'] !== null && $f['max_amt'] !== null && $f['max_amt'] < $f['min_amt']) return [$f, 'Maximum must be bigger than minimum.'];
    $fee = (float)($_POST['pm_fee'] ?? 0); $bonus = (float)($_POST['pm_bonus'] ?? 0);
    if ($fee < 0 || $fee > 50) return [$f, 'Fee must be between 0% and 50%.'];
    if ($bonus < 0 || $bonus > 100) return [$f, 'Bonus must be between 0% and 100%.'];
    $f['fee_pct'] = round($fee, 2); $f['bonus_pct'] = round($bonus, 2);
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['pm_steps'] ?? '')) as $ln) {
        $ln = trim($ln); if ($ln !== '') $lines[] = mb_substr($ln, 0, 160);
    }
    $f['steps'] = implode("\n", array_slice($lines, 0, 8));
    return [$f, ''];
}

/** Insert (editId = null) or update a method. Returns the id. */
function pm_store(?string $editId, array $f): string {
    $pdo = db(); pm_install();
    $cols = ['name', 'acct', 'acct_label', 'sub', 'ussd', 'tid_label', 'color', 'is_usd', 'enabled', 'notice', 'min_amt', 'max_amt', 'fee_pct', 'bonus_pct', 'steps'];
    if ($editId === null) {
        $id = pm_slug($f['name']);
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) + 1 FROM payment_methods')->fetchColumn();
        $pdo->prepare('INSERT INTO payment_methods (id, sort_order, ' . implode(', ', $cols) . ') VALUES (?,?,' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_merge([$id, $sort], array_map(fn($c) => $f[$c], $cols)));
        return $id;
    }
    $pdo->prepare('UPDATE payment_methods SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')
        ->execute(array_merge(array_map(fn($c) => $f[$c], $cols), [$editId]));
    return $editId;
}

function pm_toggle(string $id): void {
    db()->prepare('UPDATE payment_methods SET enabled = 1 - enabled WHERE id = ?')->execute([$id]);
}

/** Move a method one step up (-1) or down (+1) in the list. */
function pm_move(string $id, int $dir): void {
    $ids = array_keys(pm_all());
    $i = array_search($id, $ids, true);
    if ($i === false) return;
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($j < 0 || $j >= count($ids)) return;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $up = db()->prepare('UPDATE payment_methods SET sort_order = ? WHERE id = ?');
    foreach ($ids as $n => $x) $up->execute([$n + 1, $x]);
}

function pm_delete(string $id): void {
    db()->prepare('DELETE FROM payment_methods WHERE id = ?')->execute([$id]);
    db()->prepare('DELETE FROM media WHERE k = ?')->execute(['pm_' . $id]);
}

function pm_remove_logo(string $id): void {
    db()->prepare('DELETE FROM media WHERE k = ?')->execute(['pm_' . $id]);
    db()->prepare('UPDATE payment_methods SET has_logo = 0 WHERE id = ?')->execute([$id]);
}
