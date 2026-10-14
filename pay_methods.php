<?php
/* =====================================================================
 * Payment methods - shared by deposits.php, admin.php and pm_logo.php.
 * Everything is managed from  Admin > Payment methods.
 * The table is created automatically the first time it is needed, and the
 * old pay_bkash / pay_nagad / pay_rocket / pay_binance settings are copied
 * into it once, so nothing you configured before is lost.
 * ===================================================================== */

/** 1500 -> "1,500"   10.5 -> "10.50" */
function pm_fmt(float $n): string {
    return $n == floor($n) ? number_format($n, 0) : number_format($n, 2);
}

/** A value that still looks like a placeholder (01XXXXXXXXX) is never shown to users. */
function pm_placeholder(string $acct): bool {
    return $acct === '' || (bool)preg_match('/X{4,}/i', $acct);
}

function pm_defaults(): array {
    return [
        ['id' => 'bkash',   'name' => 'bKash',   'sub' => 'Send Money · Personal', 'color' => '#e2136e', 'ussd' => '*247#', 'usd' => 0, 'tid' => 'TrxID',    'key' => 'pay_bkash'],
        ['id' => 'nagad',   'name' => 'Nagad',   'sub' => 'Send Money · Personal', 'color' => '#f26a21', 'ussd' => '*167#', 'usd' => 0, 'tid' => 'TrxID',    'key' => 'pay_nagad'],
        ['id' => 'rocket',  'name' => 'Rocket',  'sub' => 'Send Money · Personal', 'color' => '#8c3494', 'ussd' => '*322#', 'usd' => 0, 'tid' => 'TrxID',    'key' => 'pay_rocket'],
        ['id' => 'binance', 'name' => 'Binance', 'sub' => 'Binance Pay · USD',     'color' => '#e0a800', 'ussd' => '',      'usd' => 1, 'tid' => 'Order ID', 'key' => 'pay_binance'],
    ];
}

/** Creates the table (once) and copies the old settings into it (once). */
function pm_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id           VARCHAR(24)  NOT NULL PRIMARY KEY,
        name         VARCHAR(40)  NOT NULL,
        sub          VARCHAR(60)  NOT NULL DEFAULT '',
        account      VARCHAR(120) NOT NULL DEFAULT '',
        color        VARCHAR(7)   NOT NULL DEFAULT '#5b4bff',
        ussd         VARCHAR(20)  NOT NULL DEFAULT '',
        tid_label    VARCHAR(30)  NOT NULL DEFAULT 'TrxID',
        is_usd       TINYINT(1)   NOT NULL DEFAULT 0,
        enabled      TINYINT(1)   NOT NULL DEFAULT 1,
        min_amt      DECIMAL(12,2) NULL,
        max_amt      DECIMAL(12,2) NULL,
        fee_pct      DECIMAL(5,2) NOT NULL DEFAULT 0,
        sort_order   INT          NOT NULL DEFAULT 0,
        instructions VARCHAR(300) NOT NULL DEFAULT '',
        logo_mime    VARCHAR(30)  NULL,
        logo         MEDIUMBLOB   NULL,
        logo_v       INT          NOT NULL DEFAULT 0,
        created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (setting('pm_seeded', '') !== '1') {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM payment_methods')->fetchColumn();
        if ($n === 0) {
            $ins = $pdo->prepare('INSERT INTO payment_methods (id, name, sub, account, color, ussd, tid_label, is_usd, enabled, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $i = 0;
            foreach (pm_defaults() as $d) {
                $acct = trim((string)setting($d['key'], ''));
                $ok = !pm_placeholder($acct);
                $ins->execute([$d['id'], $d['name'], $d['sub'], $ok ? $acct : '', $d['color'], $d['ussd'], $d['tid'], $d['usd'], $ok ? 1 : 0, (++$i) * 10]);
            }
        }
        save_setting('pm_seeded', '1');
    }
}

/** All methods in display order, keyed by id (logo bytes are NOT loaded, only logo_v). */
function pm_all(): array {
    pm_ensure();
    $rows = db()->query('SELECT id, name, sub, account, color, ussd, tid_label, is_usd, enabled, min_amt, max_amt, fee_pct, sort_order, instructions, logo_v FROM payment_methods ORDER BY sort_order, created_at, id')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $r['is_usd']  = (int)$r['is_usd'];
        $r['enabled'] = (int)$r['enabled'];
        $r['min_amt'] = $r['min_amt'] === null ? null : (float)$r['min_amt'];
        $r['max_amt'] = $r['max_amt'] === null ? null : (float)$r['max_amt'];
        $r['fee_pct'] = (float)$r['fee_pct'];
        $r['logo_v']  = (int)$r['logo_v'];
        $out[$r['id']] = $r;
    }
    return $out;
}

/** Global deposit rules (Admin > Payment methods > Deposits). */
function pm_global(): array {
    $mn = (float)setting('dep_min', '10');
    $mx = (float)setting('dep_max', '100000');
    $pn = (int)setting('dep_pending', '5');
    if ($mn < 1) $mn = 10;
    if ($mx < $mn) $mx = max($mn, 100000);
    if ($pn < 1) $pn = 5;
    return ['on' => setting('dep_on', '1') !== '0', 'msg' => trim((string)setting('dep_off_msg', '')), 'min' => $mn, 'max' => $mx, 'pending' => $pn];
}

/** Can users pay with this method right now? */
function pm_ok(array $m, float $usdRate): bool {
    return $m['enabled'] === 1 && !pm_placeholder((string)$m['account']) && (!$m['is_usd'] || $usdRate > 0);
}

/** [min, max] for this method: its own limits, else the global ones. */
function pm_limits(array $m, array $g): array {
    return [$m['min_amt'] ?? $g['min'], $m['max_amt'] ?? $g['max']];
}

function pm_logo_url(array $m): string {
    return $m['logo_v'] > 0 ? '/pm_logo.php?id=' . rawurlencode($m['id']) . '&v=' . (int)$m['logo_v'] : '';
}

/** Square badge: the uploaded logo, or a coloured letter when there is none. Self-contained (inline styles). */
function pm_badge(?array $m, int $px = 40, string $fallback = '৳'): string {
    $base = 'display:inline-grid;place-items:center;flex:none;overflow:hidden;width:' . $px . 'px;height:' . $px . 'px;border-radius:' . (int)round($px * .28) . 'px;';
    $url = $m ? pm_logo_url($m) : '';
    if ($url !== '') {
        return '<span style="' . $base . 'background:#fff;border:1px solid #e5e2f3;padding:' . max(2, (int)round($px * .1)) . 'px"><img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:contain;display:block"></span>';
    }
    $c = $m ? $m['color'] : '#7a7f99';
    $t = $m ? mb_substr($m['name'], 0, 1) : $fallback;
    return '<span style="' . $base . 'background:' . htmlspecialchars($c, ENT_QUOTES) . ';color:#fff;font-weight:800;font-size:' . (int)round($px * .45) . 'px">' . htmlspecialchars($t, ENT_QUOTES) . '</span>';
}

/**
 * Validate + shrink an uploaded logo (PNG / JPG / WEBP / GIF, up to 1 MB).
 * Returns [mime, bytes, error]. Large images are scaled down to 192px and saved as PNG
 * (needs the GD extension; without it the original file is stored as it is).
 */
function pm_process_logo(?array $f): array {
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return ['', '', ''];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1048576) return ['', '', 'Logo is too large (max 1 MB).'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
        return ['', '', 'Logo must be a PNG, JPG, WEBP or GIF image.'];
    }
    $bin = (string)file_get_contents($f['tmp_name']);
    if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
        $src = @imagecreatefromstring($bin);
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $s = min(1, 192 / max($w, $h));
            $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagepng($dst, null, 8); $png = (string)ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
            if ($png !== '') return ['image/png', $png, ''];
        }
    }
    return [$info['mime'], $bin, ''];
}

function pm_slug(string $name): string {
    $s = substr(preg_replace('/[^a-z0-9]+/', '', strtolower($name)), 0, 18);
    if ($s === '') $s = 'method';
    $st = db()->prepare('SELECT COUNT(*) FROM payment_methods WHERE id = ?');
    $id = $s; $i = 1;
    while (true) {
        $st->execute([$id]);
        if ((int)$st->fetchColumn() === 0) return $id;
        $id = $s . (++$i);
    }
}

/**
 * Insert (id = '') or update a method. $d keys: name sub account color ussd tid usd enabled min max fee note.
 * $logo = [mime, bytes] to replace the logo; $removeLogo = true to delete it. Returns the id.
 */
function pm_save(string $id, array $d, ?array $logo, bool $removeLogo): string {
    pm_ensure();
    $pdo = db();
    if ($id === '') {
        $id = pm_slug($d['name']);
        $ord = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM payment_methods')->fetchColumn();
        $pdo->prepare('INSERT INTO payment_methods (id, name, sort_order) VALUES (?,?,?)')->execute([$id, $d['name'], $ord]);
    }
    $pdo->prepare('UPDATE payment_methods SET name=?, sub=?, account=?, color=?, ussd=?, tid_label=?, is_usd=?, enabled=?, min_amt=?, max_amt=?, fee_pct=?, instructions=? WHERE id=?')
        ->execute([$d['name'], $d['sub'], $d['account'], $d['color'], $d['ussd'], $d['tid'], $d['usd'] ? 1 : 0, $d['enabled'] ? 1 : 0, $d['min'], $d['max'], $d['fee'], $d['note'], $id]);
    if ($logo) {
        $st = $pdo->prepare('UPDATE payment_methods SET logo_mime=?, logo=?, logo_v=? WHERE id=?');
        $st->bindValue(1, $logo[0]); $st->bindValue(2, $logo[1], PDO::PARAM_LOB); $st->bindValue(3, time(), PDO::PARAM_INT); $st->bindValue(4, $id);
        $st->execute();
    } elseif ($removeLogo) {
        $pdo->prepare('UPDATE payment_methods SET logo_mime=NULL, logo=NULL, logo_v=0 WHERE id=?')->execute([$id]);
    }
    return $id;
}

/** Move a method one step up or down in the list shown to users. */
function pm_move(string $id, string $dir): void {
    $ids = array_keys(pm_all());
    $i = array_search($id, $ids, true);
    if ($i === false) return;
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($ids)) return;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $st = db()->prepare('UPDATE payment_methods SET sort_order = ? WHERE id = ?');
    foreach ($ids as $n => $mid) $st->execute([($n + 1) * 10, $mid]);
}
