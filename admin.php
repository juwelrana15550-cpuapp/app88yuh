<?php require __DIR__ . '/lib.php';
require __DIR__ . '/stock_import.php';
$GLOBALS['__bdt_only'] = true;   // admin always sees real BDT amounts, whatever currency the browser picked on the shop
$adminPw = getenv('ADMIN_PASSWORD');
if (!$adminPw) { http_response_code(503); exit('Set ADMIN_PASSWORD env variable.'); }
$bonus = (float)(getenv('REFERRAL_BONUS') ?: 10);
$err = ''; $setErr = ''; $prodErr = ''; $catErr = ''; $noticeErr = ''; $apiErr = '';
$page = null;      // which admin page to show (set on errors so the user stays where they were)
$reopen = null;    // re-open the edit dialog with the submitted values after a validation error

const ADMIN_PAGES = ['overview', 'orders', 'deposits', 'products', 'categories', 'users', 'api', 'announce', 'settings'];

/** Redirect to an admin page, optionally with a success message. */
function go(string $p, ?string $ok = null, string $extra = ''): void {
    if ($ok) flash('ok', $ok);
    header('Location: /admin.php' . ($p === 'overview' ? '' : '?p=' . $p) . $extra);
    exit;
}
/** Back to one user's page (after an action). Optionally with a success message. */
function go_user(int $id, ?string $ok = null): void {
    $t = (string)($GLOBALS['__utab'] ?? '');   // tab to return to (set by each handler)
    go('users', $ok, '&u=' . $id . ($t !== '' ? '&t=' . $t : ''));
}

function product_fields(): array {
    $st = trim($_POST['stock'] ?? '');
    $sup = (int)($_POST['supplier_id'] ?? 0); $sup = $sup > 0 ? $sup : null;   // bulkmail.shop product ID
    return [
        'name'    => trim($_POST['pname'] ?? ''),
        'desc'    => mb_substr(trim($_POST['pdesc'] ?? ''), 0, 500),
        'price'   => round((float)($_POST['pprice'] ?? 0), 2),
        'cat'     => ((int)($_POST['category_id'] ?? 0)) ?: null,
        'sup'     => $sup,
        'sd'      => ($sup && !empty($_POST['sup_show_dom'])) ? 1 : 0,   // 1 = show "Possible Domain (...)" under the description
        'an'      => ($sup && !empty($_POST['sup_auto_name'])) ? 1 : 0,   // 1 = name follows bulkmail.shop
        'ad'      => ($sup && !empty($_POST['sup_auto_desc'])) ? 1 : 0,   // 1 = description (+ possible domains) follows bulkmail.shop
        'stock'   => $sup ? 0 : ($st === '' ? null : max(0, (int)$st)),   // linked product: real stock is copied from bulkmail.shop right after saving; blank = unlimited
        'unit'    => mb_substr(trim($_POST['unit'] ?? ''), 0, 20),
        'popular' => empty($_POST['popular']) ? 0 : 1,
        'auto'    => ($sup || empty($_POST['auto_delivery'])) ? 0 : 1,   // 1 = deliver uploaded stock instantly (never for linked products)
        'disc'    => min(99, max(0, round((float)($_POST['pdisc'] ?? 0), 2))),   // % off for everyone, 0 = none
        'icon'    => (function () {   // '' = automatic (guessed from the name, else the category icon)
            $ic = trim($_POST['picon'] ?? '');
            if ($ic === '') return '';
            if (strncmp($ic, 'app:', 4) === 0) return isset(app_icons()[substr($ic, 4)]) ? $ic : '';
            return mb_substr($ic, 0, 8);
        })(),
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !empty($_SESSION['admin'])) {
        flash('err', 'That upload is bigger than the server allows (limit ' . ini_get('post_max_size') . '). Please upload it in smaller parts.');
        header('Location: /admin.php?p=products'); exit;
    }
    csrf_check();
    if (isset($_POST['admin_logout'])) { unset($_SESSION['admin']); header('Location: /admin.php'); exit; }

    if (isset($_POST['admin_pw'])) {
        if (hash_equals($adminPw, (string)$_POST['admin_pw'])) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: /admin.php'); exit; }
        sleep(1); $err = 'Wrong password.';

    } elseif (!empty($_SESSION['admin'])) {
        $pdo = db();

        if (isset($_POST['save_settings'])) {
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
            $rate = null;
            if (!$setErr) {
                $rr = trim((string)($_POST['usd_rate'] ?? ''));
                if ($rr !== '') {
                    $rv = (float)str_replace(',', '', $rr);
                    if ($rv < 1 || $rv > 100000) $setErr = 'Dollar rate must be between 1 and 100000 (how many BDT equal 1 USD).';
                    else $rate = $rv;
                }
            }
            if (!$setErr) { // nothing is saved unless everything is valid
                save_setting('site_name', $name);
                save_setting('usd_rate', $rate === null ? '' : (string)$rate);
                save_setting('tagline', mb_substr(trim($_POST['tagline'] ?? ''), 0, 120));
                save_setting('subtitle', mb_substr(trim($_POST['subtitle'] ?? ''), 0, 300));
                foreach (['support_url', 'telegram_url', 'whatsapp_url'] as $k) {
                    $v = trim($_POST[$k] ?? '');
                    save_setting($k, preg_match('#^https?://#i', $v) ? $v : '');
                }
                foreach ($removes as $k) $pdo->prepare('DELETE FROM media WHERE k = ?')->execute([$k]);
                foreach ($uploads as $k => [$mime, $bin]) {
                    $st = $pdo->prepare('REPLACE INTO media (k, mime, data) VALUES (?,?,?)');
                    $st->bindValue(1, $k); $st->bindValue(2, $mime); $st->bindValue(3, $bin, PDO::PARAM_LOB);
                    $st->execute();
                }
                go('settings', 'Settings saved.');
            }

        } elseif (isset($_POST['add_product']) || isset($_POST['update_product'])) {
            $page = 'products';
            $isEdit = isset($_POST['update_product']);
            $f = product_fields();
            // Supplier ID new or changed: connect fully - name, description, possible domain and stock all follow the new supplier product.
            $oldSup0 = 0;
            if ($isEdit) { $q0 = $pdo->prepare('SELECT supplier_id FROM products WHERE id = ?'); $q0->execute([(int)$_POST['update_product']]); $oldSup0 = (int)$q0->fetchColumn(); }
            if ($f['sup'] && (int)$f['sup'] !== $oldSup0) { $f['an'] = 1; $f['ad'] = 1; }
            $sp0 = null; $sp0err = '';   // ONE lookup at bulkmail.shop per save (text + current stock); the public API has a small request limit
            if ($f['sup'] && bm_connected()) {
                $sp0 = bm_product((int)$f['sup']);
                if (isset($sp0['error'])) { $sp0err = $sp0['error']; $sp0 = null; }
                else {
                    if ($f['an']) { $nm0 = $sp0['domains'] !== '' ? mb_substr($sp0['domains'], 0, 40) : $sp0['name']; if ($nm0 !== '') $f['name'] = $nm0; }   // auto name: the Possible Domain (else the supplier's name)
                    if ($f['ad'] && $sp0['description'] !== '') $f['desc'] = $sp0['description'];
                }
            }
            $oldStock = null;
            if ($isEdit) { $q = $pdo->prepare('SELECT sup_stock FROM products WHERE id = ?'); $q->execute([(int)$_POST['update_product']]); $oldStock = $q->fetchColumn(); }
            $bad = '';
            if ($f['name'] === '' || mb_strlen($f['name']) > 80 || $f['price'] <= 0) {
                $bad = 'Enter a product name (max 80 chars) and a price above 0.';
            } elseif ($f['sup']) {   // linked to bulkmail.shop: the product must exist there (skipped when editing a product whose link did not change)
                $oldSup = 0;
                if ($isEdit) { $q = $pdo->prepare('SELECT supplier_id FROM products WHERE id = ?'); $q->execute([(int)$_POST['update_product']]); $oldSup = (int)$q->fetchColumn(); }
                if ($oldSup !== (int)$f['sup']) {
                    if (!bm_connected()) $bad = 'Connect your bulkmail.shop API key first (Admin > API Management), then link the product.';
                    elseif ($sp0 === null) $bad = 'Supplier product ID: ' . ($sp0err !== '' ? $sp0err : 'could not read the product.');
                }
            }
            if ($bad !== '') {
                $prodErr = $bad;
                $reopen = ['t' => 'product', 'id' => $isEdit ? (int)$_POST['update_product'] : 0, 'v' => [
                    'name' => $f['name'], 'desc' => $f['desc'], 'price' => (string)($_POST['pprice'] ?? ''), 'unit' => $f['unit'],
                    'cat' => $f['cat'] ?? '', 'stock' => $f['stock'] ?? '', 'pop' => $f['popular'], 'auto' => $f['auto'], 'icon' => $f['icon'], 'disc' => $f['disc'], 'sup' => $f['sup'] ?? '', 'an' => $f['an'], 'ad' => $f['ad'], 'sd' => $f['sd'],
                ]];
            } elseif ($isEdit) {
                $pid = (int)$_POST['update_product'];
                $pdo->prepare('UPDATE products SET name=?, description=?, price=?, category_id=?, stock=?, unit=?, popular=?, auto_delivery=?, icon=?, discount_pct=?, supplier_id=?, sup_auto_name=?, sup_auto_desc=?, sup_show_dom=? WHERE id=?')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['auto'], $f['icon'], $f['disc'], $f['sup'], $f['an'], $f['ad'], $f['sd'], $pid]);
                if ($f['auto']) stock_sync($pdo, $pid);   // stock = unsold uploaded items
                if (!$f['sup']) $pdo->prepare('UPDATE products SET sup_domain = NULL WHERE id = ?')->execute([$pid]);   // unlinked: no supplier domains
                if ($f['sup']) {
                    // show a real number at once (the stock we just read, else the previous one), then refresh it live
                    $keep = $sp0 ? (int)$sp0['stock'] : ($oldStock !== null && $oldStock !== false ? (int)$oldStock : 0);
                    $pdo->prepare('UPDATE products SET sup_stock = ?, sup_domain = ? WHERE id = ?')->execute([$keep, ($sp0 && $sp0['domains'] !== '') ? $sp0['domains'] : null, $pid]);
                    stock_sync($pdo, $pid);      // stock = bulkmail.shop stock + own (local) stock
                    bm_sync_stock($pdo, true);   // then refresh the bulkmail.shop part live
                    bm_sync_info($pdo, true);    // name / description / possible domain
                }
                go('products', $f['sup'] ? 'Product saved and linked to bulkmail.shop.' : 'Product saved.');
            } else {
                $pdo->prepare('INSERT INTO products (name, description, price, category_id, stock, unit, popular, auto_delivery, icon, discount_pct, supplier_id, sup_auto_name, sup_auto_desc, sup_show_dom) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$f['name'], $f['desc'], $f['price'], $f['cat'], $f['stock'], $f['unit'], $f['popular'], $f['auto'], $f['icon'], $f['disc'], $f['sup'], $f['an'], $f['ad'], $f['sd']]);
                $newId = (int)$pdo->lastInsertId();
                if ($f['auto']) stock_sync($pdo, $newId);
                if ($f['sup']) {
                    if ($sp0) { $pdo->prepare('UPDATE products SET sup_stock = ?, sup_domain = ? WHERE id = ?')->execute([(int)$sp0['stock'], $sp0['domains'] !== '' ? $sp0['domains'] : null, $newId]); stock_sync($pdo, $newId); }
                    bm_sync_stock($pdo, true);
                    bm_sync_info($pdo, true);
                }
                go('products', $f['sup'] ? 'Product added and linked to bulkmail.shop.' : 'Product added.');
            }

        } elseif (isset($_POST['bm_connect'])) {
            $page = 'api';
            [$okc, $msgc] = bm_connect($pdo, (string)($_POST['bm_key'] ?? ''));
            if ($okc) {
                $bal = bm_balance(); if ($bal) save_setting('bm_currency', $bal['currency']);
                go('api', $msgc . ' Linked products now use live stock and are bought from bulkmail.shop.');
            }
            $apiErr = $msgc;

        } elseif (isset($_POST['bm_disconnect'])) {
            bm_disconnect($pdo);
            go('api', 'Disconnected. The API key was removed and linked products show as out of stock until you connect again.');

        } elseif (isset($_POST['bm_refresh'])) {
            bm_sync_stock($pdo, true);
            bm_sync_info($pdo, true);
            go('api', 'Stock, names and descriptions refreshed from bulkmail.shop.');

        } elseif (isset($_POST['bm_clear_err'])) {
            save_setting('bm_last_error', '');
            go('api');

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

        } elseif (isset($_POST['upload_stock'])) {
            $page = 'products';
            try {
                $got = stock_collect((string)($_POST['stock_text'] ?? ''), $_FILES['stock_file'] ?? null, !empty($_POST['skip_header']));
                if (!$got['lines']) throw new RuntimeException('Nothing to add. Paste some items or choose a file.');
                [$added, $dup] = stock_add($pdo, (int)$_POST['upload_stock'], $got['lines'], !empty($_POST['dedupe']));
                stock_sync($pdo, (int)$_POST['upload_stock']);   // shown stock = local + API (linked products)
                $msg = number_format($added) . ' item' . ($added === 1 ? '' : 's') . ' added. These are sold first, before any bulkmail.shop stock.';
                if ($dup) $msg .= ' ' . number_format($dup) . ' duplicate' . ($dup === 1 ? '' : 's') . ' skipped.';
                if ($got['long']) $msg .= ' ' . number_format($got['long']) . ' line(s) skipped (over ' . STOCK_MAX_LEN . ' characters).';
                go('products', $msg);
            } catch (RuntimeException $ex) { flash('err', $ex->getMessage()); go('products'); }

        } elseif (isset($_POST['clear_stock'])) {
            $pid = (int)$_POST['clear_stock'];
            with_tx($pdo, function (PDO $pdo) use ($pid) {
                $pdo->prepare('DELETE FROM stock_items WHERE product_id = ? AND order_id IS NULL')->execute([$pid]);
                stock_sync_if_auto($pdo, $pid);
            });
            go('products', 'Unsold items removed.');

        } elseif (isset($_POST['toggle_product'])) {
            $pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['toggle_product']]);
            go('products', 'Product visibility updated.');

        } elseif (isset($_POST['delete_product'])) {
            $pid = (int)$_POST['delete_product'];
            try {
                $problem = with_tx($pdo, function (PDO $pdo) use ($pid) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE product_id = ? AND status = 'pending'");
                    $st->execute([$pid]);
                    if ((int)$st->fetchColumn() > 0) return 'This product has pending orders. Deliver or cancel them first, then delete it.';
                    $pdo->prepare('DELETE FROM stock_items WHERE product_id = ?')->execute([$pid]);
                    $pdo->prepare('DELETE FROM user_discounts WHERE product_id = ?')->execute([$pid]);
                    // Old orders keep their saved product name; just detach them from the product (ignored if the column cannot be NULL).
                    try { $pdo->prepare('UPDATE orders SET product_id = NULL WHERE product_id = ?')->execute([$pid]); } catch (PDOException $ignore) {}
                    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
                    return null;
                });
            } catch (PDOException $ex) {
                $problem = 'This product cannot be deleted because past orders still use it. Use Hide instead.';
            }
            if ($problem) { flash('err', $problem); go('products'); }
            go('products', 'Product deleted.');

        } elseif (isset($_POST['deliver']) || isset($_POST['cancel_order'])) {
            $oid = (int)($_POST['order_id'] ?? 0);
            with_tx($pdo, function (PDO $pdo) use ($oid) {
                $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending' FOR UPDATE");
                $st->execute([$oid]);
                if ($o = $st->fetch()) {
                    if (isset($_POST['deliver'])) {
                        $pdo->prepare("UPDATE orders SET status='delivered', delivery=? WHERE id=?")->execute([mb_substr(trim($_POST['delivery'] ?? ''), 0, 500000), $oid]);
                    } else {
                        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
                        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$o['price'], $o['user_id']]);
                        add_tx($pdo, (int)$o['user_id'], 'refund', (float)$o['price'], 'Refund for order #' . $oid);
                        $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL AND auto_delivery = 0 AND supplier_id IS NULL')->execute([max(1, (int)$o['qty']), $o['product_id']]);
                        $pdo->prepare('UPDATE stock_items SET order_id = NULL WHERE order_id = ?')->execute([$oid]);   // local items reserved by this order go back on the shelf
                        stock_sync_if_auto($pdo, (int)$o['product_id']);
                    }
                }
            });
            go('orders', isset($_POST['deliver']) ? 'Order marked as delivered.' : 'Order cancelled and refunded.');

        } elseif (isset($_POST['save_notice'])) {
            $page = 'announce';
            $txt  = mb_substr(trim($_POST['notice_text'] ?? ''), 0, 500);
            $type = in_array($_POST['notice_type'] ?? '', ['info', 'success', 'warning', 'danger'], true) ? $_POST['notice_type'] : 'info';
            $on   = !empty($_POST['notice_on']);
            if ($on && $txt === '') {
                $noticeErr = 'Write the notice text first, then turn it on.';
            } else {
                // A new version number makes users who closed the old notice see this one again.
                if ($txt !== trim(setting('notice_text')) || (setting('notice_on', '0') !== '1' && $on)) save_setting('notice_v', (string)time());
                save_setting('notice_text', $txt);
                save_setting('notice_type', $type);
                save_setting('notice_on', $on ? '1' : '0');
                save_setting('notice_dismiss', empty($_POST['notice_dismiss']) ? '0' : '1');
                go('announce', $on ? 'Notice saved and visible to users.' : 'Notice saved (currently hidden).');
            }

        } elseif (isset($_POST['toggle_maintenance']) || isset($_POST['save_maintenance'])) {
            if (isset($_POST['toggle_maintenance'])) {   // quick switch from the red bar
                $on = setting('maintenance_on', '0') !== '1';
                save_setting('maintenance_on', $on ? '1' : '0');
                $back = in_array($_POST['back'] ?? '', ADMIN_PAGES, true) ? $_POST['back'] : 'announce';
            } else {
                $on = !empty($_POST['maintenance_on']);
                save_setting('maintenance_on', $on ? '1' : '0');
                save_setting('maintenance_msg', mb_substr(trim($_POST['maintenance_msg'] ?? ''), 0, 300));
                save_setting('maintenance_eta', mb_substr(trim($_POST['maintenance_eta'] ?? ''), 0, 60));
                $back = 'announce';
            }
            go($back, $on ? 'Maintenance mode is ON. Visitors now see the maintenance page.' : 'Maintenance mode is OFF. The site is live again.');

        } elseif (isset($_POST['user_adjust'])) {
            $GLOBALS['__utab'] = 'balance';
            $uid  = (int)$_POST['user_adjust'];
            $amt  = round((float)str_replace(',', '', (string)($_POST['amount'] ?? '0')), 2);
            $dir  = ($_POST['dir'] ?? '') === 'deduct' ? 'deduct' : 'add';
            $note = mb_substr(trim($_POST['note'] ?? ''), 0, 120);
            if ($amt <= 0 || $amt > 10000000) { flash('err', 'Enter an amount above 0.'); go_user($uid); }
            $problem = with_tx($pdo, function (PDO $pdo) use ($uid, $amt, $dir, $note) {
                $st = $pdo->prepare('SELECT coins FROM users WHERE id = ? FOR UPDATE'); $st->execute([$uid]);
                $bal = $st->fetchColumn();
                if ($bal === false) return 'User not found.';
                if ($dir === 'deduct') {
                    if ((float)$bal < $amt) return 'Balance is only ' . money($bal) . ', so ' . money($amt) . ' cannot be deducted.';
                    $pdo->prepare('UPDATE users SET coins = coins - ? WHERE id = ?')->execute([$amt, $uid]);
                    add_tx($pdo, $uid, 'admin', -$amt, $note !== '' ? 'Admin: ' . $note : 'Admin deduction');
                } else {
                    $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$amt, $uid]);
                    add_tx($pdo, $uid, 'admin', $amt, $note !== '' ? 'Admin: ' . $note : 'Admin credit');
                }
                return null;
            });
            if ($problem) { flash('err', $problem); go_user($uid); }
            go_user($uid, ($dir === 'deduct' ? 'Deducted ' : 'Added ') . money($amt) . '.');

        } elseif (isset($_POST['user_password'])) {
            $GLOBALS['__utab'] = 'info';
            $uid = (int)$_POST['user_password']; $pw = (string)($_POST['new_password'] ?? '');
            if (strlen($pw) < 8 || strlen($pw) > 72) { flash('err', 'Password must be 8 to 72 characters.'); go_user($uid); }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
            go_user($uid, 'Password changed. Send the new password to the user.');

        } elseif (isset($_POST['user_email'])) {
            $GLOBALS['__utab'] = 'info';
            $uid = (int)$_POST['user_email']; $mail = trim((string)($_POST['new_email'] ?? ''));
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 190) { flash('err', 'Enter a valid email address.'); go_user($uid); }
            try { $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$mail, $uid]); }
            catch (PDOException $ex) {
                if ((string)$ex->getCode() !== '23000') throw $ex;
                flash('err', 'That email is already used by another account.'); go_user($uid);
            }
            go_user($uid, 'Email updated.');

        } elseif (isset($_POST['user_ban'])) {
            $GLOBALS['__utab'] = 'ban';
            $uid = (int)$_POST['user_ban']; $to = empty($_POST['to']) ? 0 : 1;
            $pdo->prepare('UPDATE users SET banned = ? WHERE id = ?')->execute([$to, $uid]);
            $msg = $to ? 'User banned. They are signed out and cannot use the account.' : 'User unbanned.';
            if (isset($_POST['ret'])) {   // quick Ban/Unban from the users list: go back to the same list
                $ret = (string)$_POST['ret'];
                if (!preg_match('/^(&(?:q|f|pg)=[A-Za-z0-9%._~+\-]*)*$/', $ret)) $ret = '';
                go('users', $msg, $ret);
            }
            go_user($uid, $msg);

        } elseif (isset($_POST['user_disc_set'])) {
            $GLOBALS['__utab'] = 'discount';
            $uid = (int)$_POST['user_disc_set']; $pid = max(0, (int)($_POST['disc_product'] ?? 0)); $pct = round((float)($_POST['disc_pct'] ?? 0), 2);
            if ($pct < 0.01 || $pct > 99) { flash('err', 'Discount must be between 0.01% and 99%.'); go_user($uid); }
            if ($pid > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE id = ?'); $st->execute([$pid]);
                if (!(int)$st->fetchColumn()) { flash('err', 'Product not found.'); go_user($uid); }
            }
            $pdo->prepare('INSERT INTO user_discounts (user_id, product_id, percent) VALUES (?,?,?) ON DUPLICATE KEY UPDATE percent = VALUES(percent)')->execute([$uid, $pid, $pct]);
            go_user($uid, 'Discount saved: ' . pct_fmt($pct) . '% off ' . ($pid ? 'this product.' : 'all products.'));

        } elseif (isset($_POST['user_disc_del'])) {
            $GLOBALS['__utab'] = 'discount';
            $uid = (int)($_POST['uid'] ?? 0);
            $pdo->prepare('DELETE FROM user_discounts WHERE id = ? AND user_id = ?')->execute([(int)$_POST['user_disc_del'], $uid]);
            go_user($uid, 'Discount removed.');

        } elseif (isset($_POST['user_delete'])) {
            $GLOBALS['__utab'] = 'ban';
            $uid = (int)$_POST['user_delete'];
            $n = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM orders WHERE user_id = ' . $uid . ') + (SELECT COUNT(*) FROM deposits WHERE user_id = ' . $uid . ')')->fetchColumn();
            if ($n > 0) { flash('err', 'This user has orders or deposits, so the account cannot be deleted (your records would break). Ban the user instead.'); go_user($uid); }
            with_tx($pdo, function (PDO $pdo) use ($uid) {
                $pdo->prepare('UPDATE users SET referred_by = NULL WHERE referred_by = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM api_keys WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM user_discounts WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            });
            go('users', 'User deleted.');

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

/* AJAX: product details from bulkmail.shop for the "Fetch" button in the product dialog (admin only). */
if (isset($_GET['bm_lookup'])) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if (empty($_SESSION['admin'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please log in again.']); exit; }
    $sp = bm_product((int)$_GET['bm_lookup']);
    echo json_encode(isset($sp['error']) ? ['ok' => false, 'error' => $sp['error']] : ['ok' => true, 'p' => $sp, 'cur' => setting('bm_currency', 'USD'), 'rate' => usd_rate()], JSON_UNESCAPED_UNICODE);
    exit;
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
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pend = $pdo->query("SELECT COUNT(*) c FROM deposits WHERE status='pending'")->fetch();
$pendOrders = $pdo->query("SELECT o.*, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.status='pending' ORDER BY o.id")->fetchAll();
$coins = (float)$pdo->query('SELECT COALESCE(SUM(coins),0) FROM users')->fetchColumn();
$products = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM stock_items si WHERE si.product_id = p.id AND si.order_id IS NULL) AS local_stock FROM products p ORDER BY p.id DESC')->fetchAll();
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
$maint = setting('maintenance_on', '0') === '1';
$userView = null; $users = []; $uq = ''; $uf = ''; $uPg = 1; $uPages = 1; $uMatch = 0;
$uStats = ['banned' => 0, 'week' => 0];
if ($page === 'users') {
    $vid = (int)($_GET['u'] ?? 0);
    $uTab = in_array($_GET['t'] ?? '', ['info', 'balance', 'discount', 'ban', 'orders'], true) ? $_GET['t'] : 'info';
    if ($vid > 0) {   // one user's page
        $st = $pdo->prepare('SELECT u.*, (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.id) AS refs, (SELECT r.email FROM users r WHERE r.id = u.referred_by) AS ref_email FROM users u WHERE u.id = ?');
        $st->execute([$vid]); $userView = $st->fetch() ?: null;
        if (!$userView) { flash('err', 'User not found.'); go('users'); }
        $st = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 15'); $st->execute([$vid]); $uOrders = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 15'); $st->execute([$vid]); $uTx = $st->fetchAll();
        $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN price END),0) spent FROM orders WHERE user_id = ?"); $st->execute([$vid]); $uOrd = $st->fetch();
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id = ? AND status = 'approved'"); $st->execute([$vid]); $uDep = (float)$st->fetchColumn();
        $st = $pdo->prepare('SELECT d.*, p.name AS pname FROM user_discounts d LEFT JOIN products p ON p.id = d.product_id WHERE d.user_id = ? ORDER BY d.product_id'); $st->execute([$vid]); $uDisc = $st->fetchAll();
    } else {          // searchable, paged list
        $uq = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
        $uf = in_array($_GET['f'] ?? '', ['banned', 'disc'], true) ? $_GET['f'] : '';
        $conds = []; $args = [];
        if ($uq !== '') { $conds[] = '(u.email LIKE ? OR u.id = ?)'; $args = ['%' . addcslashes($uq, '%_\\') . '%', (int)$uq]; }
        if ($uf === 'banned') $conds[] = 'u.banned = 1';
        if ($uf === 'disc') $conds[] = 'EXISTS (SELECT 1 FROM user_discounts d WHERE d.user_id = u.id)';
        $w = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
        $st = $pdo->prepare('SELECT COUNT(*) FROM users u' . $w); $st->execute($args); $uMatch = (int)$st->fetchColumn();
        $per = 50; $uPages = max(1, (int)ceil($uMatch / $per)); $uPg = min(max(1, (int)($_GET['pg'] ?? 1)), $uPages);
        $st = $pdo->prepare('SELECT u.id, u.email, u.coins, u.created_at, u.banned, (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.id) AS refs, (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS ords, (SELECT COUNT(*) FROM user_discounts d WHERE d.user_id = u.id) AS discs FROM users u' . $w . ' ORDER BY u.id DESC LIMIT ' . $per . ' OFFSET ' . (($uPg - 1) * $per));
        $st->execute($args); $users = $st->fetchAll();
        $uStats['banned'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE banned = 1')->fetchColumn();
        $uStats['week'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY')->fetchColumn();
    }
}
$hist = $pdo->query("SELECT d.*, u.email FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status <> 'pending' ORDER BY d.id DESC LIMIT 30")->fetchAll();

/** Icon picker (searchable app icons + emoji). $auto adds an "Automatic" choice (stored as an empty value). */
function icon_picker_html(string $pre, string $field, string $initial, bool $auto): string {
    ob_start(); ?>
<div class="ipk" id="<?= e($pre) ?>">
  <button type="button" class="ipk-btn" id="<?= e($pre) ?>Btn" aria-expanded="false" aria-controls="<?= e($pre) ?>Pop"><span class="ipk-cur" id="<?= e($pre) ?>Cur"></span><span class="ipk-name" id="<?= e($pre) ?>Name">Select icon</span><?= ai('chev') ?></button>
  <div class="ipk-pop" id="<?= e($pre) ?>Pop" hidden>
    <div class="srch"><?= ai('search') ?><input type="search" id="<?= e($pre) ?>Q" placeholder="Search icons (gmail, facebook, netflix…)" autocomplete="off"></div>
    <?php if ($auto): ?><button type="button" class="btn sm ghost" id="<?= e($pre) ?>Auto" style="margin-top:10px">&#10024; Automatic (from product name / category)</button><?php endif; ?>
    <div class="ipk-scroll" id="<?= e($pre) ?>List">
    <?php $lastG = null; $open = false;
    foreach (app_icons() as $key => $d):
      if ($d[1] !== $lastG) { if ($open) echo '</div>'; echo '<div class="ipk-g" data-g>' . e($d[1]) . '</div><div class="ipk-grid">'; $lastG = $d[1]; $open = true; } ?>
      <button type="button" class="ipk-o" data-v="app:<?= e($key) ?>" data-n="<?= e($d[0]) ?>" title="<?= e($d[0]) ?>"><?= app_icon_svg($key, '40px') ?><span><?= e($d[0]) ?></span></button>
    <?php endforeach; if ($open) echo '</div>'; ?>
      <div class="ipk-none" id="<?= e($pre) ?>None">No icon found. Use an emoji below.</div>
    </div>
    <div class="ipk-em"><label for="<?= e($pre) ?>Emoji">Or type an emoji <small>(optional)</small></label><input type="text" id="<?= e($pre) ?>Emoji" maxlength="8" placeholder="📧"></div>
  </div>
  <input type="hidden" name="<?= e($field) ?>" id="<?= e($pre) ?>Val" value="<?= e($initial) ?>">
</div>
<?php return ob_get_clean();
}

/** Admin-only icons (everything else comes from icon() in lib.php). */
function ai(string $n): string {
    static $x = [
        'bell'    => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
        'grid'    => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'sliders' => '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
        'pencil'  => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
        'trash'   => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'chev'    => '<path d="m6 9 6 6 6-6"/>',
        'ext'     => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    ];
    return isset($x[$n]) ? '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . $x[$n] . '</svg>' : icon($n);
}

$nav = [
    ''        => [['overview', 'Overview', 'gauge', 0]],
    'Sales'   => [['orders', 'Orders', 'cart', count($pendOrders)], ['deposits', 'Deposits', 'coins', (int)$pend['c']]],
    'Catalog' => [['products', 'Products', 'bag', 0], ['categories', 'Categories', 'grid', 0]],
    'Manage'  => [['users', 'Users', 'users', 0], ['api', 'API Management', 'key', 0], ['announce', 'Notice & Maintenance', 'bell', 0], ['settings', 'Site settings', 'sliders', 0]],
];
$titles = [
    'overview'   => ['Overview', 'Store activity at a glance'],
    'orders'     => ['Orders', 'Deliver or cancel pending orders'],
    'deposits'   => ['Deposits', 'Approve or reject wallet deposits'],
    'products'   => ['Products', 'Add, edit, hide and organise what you sell'],
    'categories' => ['Categories', 'Group products and choose their icons'],
    'users'      => ['Users', 'Search, top up, ban and manage accounts'],
    'api'        => ['API Management', 'Connect bulkmail.shop with an API key so linked products sell from its live stock'],
    'announce'   => ['Notice & Maintenance', 'Show a notice to users and switch the site into maintenance mode'],
    'settings'   => ['Site settings', 'Name, headline, dollar rate, support links, logo and banner'],
];
if ($page === 'users' && $userView) $titles['users'] = ['User #' . (int)$userView['id'], (string)$userView['email']];
$sv = fn(string $k) => e($_POST[$k] ?? setting($k));
$lg = media_url('logo');
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titles[$page][0]) ?> - Admin - <?= e(site_name()) ?></title>
<link rel="stylesheet" href="/admin.css?v=<?= is_file(__DIR__ . '/admin.css') ? filemtime(__DIR__ . '/admin.css') : 1 ?>">
<?= icon_tags() ?>
<style>*{-webkit-tap-highlight-color:transparent}a:focus,button:focus,summary:focus{outline:0}</style>
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
<div class="mtop"><button type="button" aria-label="Menu" onclick="document.body.classList.toggle('menu')"><?= ai('menu') ?></button><b><?= e($titles[$page][0]) ?></b></div>
<?php if ($maint): ?><div class="mbar"><span>🛠️ Maintenance mode is ON — visitors cannot use the site (you can, while logged in here).</span>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($page) ?>"><button class="btn sm ghost" name="toggle_maintenance" value="1">Turn off</button></form></div><?php endif; ?>
<div class="ph">
  <div><h1><?= e($titles[$page][0]) ?></h1><p><?= e($titles[$page][1]) ?></p></div>
  <?php if ($page === 'products'): ?><button type="button" class="btn" id="addProduct"><?= ai('plus') ?>Add product</button><?php endif; ?>
  <?php if ($page === 'categories'): ?><button type="button" class="btn" id="addCategory"><?= ai('plus') ?>Add category</button><?php endif; ?>
  <?php if ($page === 'users' && $userView): ?><a class="btn ghost" href="/admin.php?p=users"><?= ai('back') ?>All users</a><?php endif; ?>
</div>
<?php flash_html(); ?>

<?php /* ============================ OVERVIEW ============================ */ if ($page === 'overview'): ?>
<div class="stats">
  <a class="stat" href="/admin.php?p=users"><span>Total users</span><b><?= $totalUsers ?></b></a>
  <a class="stat <?= (int)$pend['c'] ? 'alert' : '' ?>" href="/admin.php?p=deposits"><span>Pending deposits</span><b><?= (int)$pend['c'] ?></b></a>
  <a class="stat <?= count($pendOrders) ? 'alert' : '' ?>" href="/admin.php?p=orders"><span>Pending orders</span><b><?= count($pendOrders) ?></b></a>
  <div class="stat"><span>Balance in wallets</span><b><?= money($coins) ?></b></div>
</div>
<div class="cols">
  <div class="card"><div class="card-h"><h3>Pending orders</h3><a href="/admin.php?p=orders">Open all</a></div>
  <?php foreach (array_slice($pendOrders, 0, 5) as $o): ?>
    <div class="mini"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?><?= (int)$o['qty'] > 1 ? ' × ' . (int)$o['qty'] : '' ?></b><small><?= e($o['email']) ?></small></div><span class="money"><?= money($o['price']) ?></span></div>
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
<?php foreach ($pendOrders as $o): ?>
<div class="ord">
  <div class="ord-h"><div><b>#<?= (int)$o['id'] ?> · <?= e($o['product_name']) ?><?= (int)$o['qty'] > 1 ? ' × ' . (int)$o['qty'] : '' ?></b><p><?= e($o['email']) ?> · <?= e($o['created_at']) ?></p></div><span class="money"><?= money($o['price']) ?></span></div>
  <?php if (!empty($o['supplier_order_id'])): ?><div class="err" style="margin:10px 0">⚠️ bulkmail.shop order <?= $o['supplier_order_id'] === 'sending' ? 'was sent but no clear answer came back' : '#' . e($o['supplier_order_id']) . ' was placed but no items came back' ?>. Open your orders at bulkmail.shop first: if it was charged, paste the items below and mark delivered; if not, cancel &amp; refund.</div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
  <textarea name="delivery" placeholder="Delivery data for the customer: one item per line, e.g. email|password (they can download it as TXT / CSV / XLSX)"></textarea>
  <div class="acts" style="margin-top:10px"><button class="btn sm ok-b" name="deliver" value="1">Mark delivered</button>
  <button class="btn sm red" name="cancel_order" value="1" onclick="return confirm('Cancel and refund?')">Cancel &amp; refund</button></div></form>
</div>
<?php endforeach; if (!$pendOrders): ?><div class="card"><div class="empty">No pending orders. 🎉</div></div><?php endif; ?>

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
  </div>
  <div class="tw"><table id="ptable" class="rt"><thead><tr><th>Product</th><th class="hm">Category</th><th>Price</th><th class="hm">Stock</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($products as $p): $k = $catById[(int)$p['category_id']] ?? null;
    $pj = ['id' => (int)$p['id'], 'name' => $p['name'], 'desc' => (string)$p['description'], 'price' => (string)$p['price'], 'unit' => (string)$p['unit'],
           'cat' => $p['category_id'] ?? '', 'stock' => $p['stock'] === null ? '' : (int)$p['stock'], 'pop' => (int)$p['popular'], 'auto' => (int)$p['auto_delivery'], 'icon' => (string)($p['icon'] ?? ''), 'disc' => (float)$p['discount_pct'], 'sup' => $p['supplier_id'] === null ? '' : (int)$p['supplier_id'], 'an' => (int)($p['sup_auto_name'] ?? 0), 'ad' => (int)($p['sup_auto_desc'] ?? 0), 'sd' => (int)($p['sup_show_dom'] ?? 1)];
    $sj = ['id' => (int)$p['id'], 'name' => $p['name'], 'left' => (int)$p['local_stock']]; ?>
  <tr data-s="<?= e(mb_strtolower($p['name'] . ' ' . $p['id'])) ?>" data-c="<?= (int)$p['category_id'] ?>">
    <td><div class="pn"><span class="ci"><?= cat_icon(product_icon_value($p, $k), '1em') ?></span><div><b><?= e($p['name']) ?></b><?php if ($p['popular']): ?><span class="tag">Popular</span><?php endif; ?><?php if ($p['auto_delivery']): ?><span class="tag auto">&#9889; Instant</span><?php endif; ?><?php if ($p['supplier_id'] !== null): ?><span class="tag auto">&#128279; bulkmail #<?= (int)$p['supplier_id'] ?></span><?php endif; ?><small>ID <?= (int)$p['id'] ?><?= $p['unit'] !== '' ? ' · per ' . e($p['unit']) : '' ?></small></div></div></td>
    <td class="hm"><?= $k ? e($k['name']) : '<small>—</small>' ?></td>
    <td class="money"><?php if ((float)$p['discount_pct'] > 0): ?><s style="color:var(--mute);font-weight:500;font-size:.85em"><?= money($p['price']) ?></s> <?= money(final_price((float)$p['price'], (float)$p['discount_pct'])) ?> <span class="tag">-<?= pct_fmt((float)$p['discount_pct']) ?>%</span><?php else: ?><?= money($p['price']) ?><?php endif; ?></td>
    <td class="hm"><?= $p['stock'] === null ? 'Unlimited' : number_format((int)$p['stock']) . ($p['supplier_id'] !== null ? ' <small>API ' . number_format((int)$p['sup_stock']) . ' + local ' . number_format((int)$p['local_stock']) . '</small>' : ($p['auto_delivery'] ? ' <small>unsold</small>' : '')) ?></td>
    <td><span class="badge <?= $p['active'] ? 'on' : 'off' ?>"><?= $p['active'] ? 'Visible' : 'Hidden' ?></span></td>
    <td class="ra"><div class="acts">
      <button type="button" class="btn sm ghost" data-stock="<?= e(json_encode($sj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('plus') ?>Stock</button>
      <button type="button" class="btn sm ghost" data-edit-p="<?= e(json_encode($pj, JSON_UNESCAPED_UNICODE)) ?>"><?= ai('pencil') ?>Edit</button>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="toggle_product" value="<?= (int)$p['id'] ?>"><?= $p['active'] ? 'Hide' : 'Show' ?></button></form>
      <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm red" name="delete_product" value="<?= (int)$p['id'] ?>" onclick="return confirm('Delete this product permanently? Its uploaded stock and user discounts are removed too. This cannot be undone.')" aria-label="Delete"><?= ai('trash') ?></button></form>
    </div></td>
  </tr>
  <?php endforeach; ?></tbody></table></div>
  <div class="empty" id="pnone" <?= $products ? 'style="display:none"' : '' ?>>No products yet. Click “Add product” to create the first one.</div>
</div>

<dialog id="pdlg"><form method="post" class="dlg"><?= csrf_field() ?>
  <div class="dlg-h"><h3 id="pTitle">Add product</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <?php if ($prodErr): ?><div class="err" style="margin-top:12px"><?= e($prodErr) ?></div><?php endif; ?>
  <label>Name</label><input type="text" name="pname" id="p_name" maxlength="80" required>
  <label>Description <small>(optional)</small></label>
  <textarea name="pdesc" id="p_desc" maxlength="500" rows="3" placeholder="Fast activation&#10;24/7 support&#10;Instant delivery"></textarea>
  <small style="display:block;margin-top:4px">One line = one feature. Add 2+ lines to show a checklist (like the plans on the homepage); a single line shows as plain text.</small>
  <div class="pc-prev" id="p_descPrevWrap" hidden><small style="display:block;margin:10px 0 6px">Preview on the shop</small><div id="p_descPrev"></div></div>
  <style>
    /* Mirrors .pc-d / .pc-dl from the shop's style.css, scoped here so the admin preview matches the real product card. */
    .pc-prev{background:#f8fafc;border:1px solid #eef0f4;border-radius:12px;padding:12px 14px}
    .pc-prev .pc-d{color:#64748b;font-size:.82rem;line-height:1.45;overflow-wrap:anywhere;margin:0}
    .pc-prev .pc-dl{list-style:none;padding:0;margin:0;font-size:.82rem;color:#1e293b}
    .pc-prev .pc-dl li{position:relative;padding:5px 0 5px 20px;border-bottom:1px solid #eef0f4;line-height:1.4}
    .pc-prev .pc-dl li:last-child{border-bottom:0}
    .pc-prev .pc-dl li:before{content:"✓";position:absolute;left:0;top:5px;color:#16a34a;font-weight:800}
  </style>
  <div class="grid2"><div><label>Price (৳)</label><input type="number" name="pprice" id="p_price" step="0.01" min="0.01" required></div>
  <div><label>Unit <small>(e.g. email)</small></label><input type="text" name="unit" id="p_unit" maxlength="20" placeholder="email"></div></div>
  <label>Discount % <small>(optional, shown to everyone as a sale · 0 = none)</small></label><input type="number" name="pdisc" id="p_disc" min="0" max="99" step="0.01" inputmode="decimal" placeholder="e.g. 20">
  <div class="grid2"><div><label>Category</label><select name="category_id" id="p_cat"><?= $catOpts(0) ?></select></div>
  <div><label>Stock <small>(blank = unlimited)</small></label><input type="number" name="stock" id="p_stock" min="0" step="1"></div></div>
  <label>App icon <small>(pick one, or leave Automatic - it follows the product name)</small></label>
  <style>
    .ipk-q{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px}
    .ipk-qb{width:46px;height:46px;padding:0;border:1.5px solid #e5e2f3;border-radius:12px;background:#fff;cursor:pointer;display:grid;place-items:center}
    .ipk-qb:hover{border-color:#cfc6f7}
    .ipk-qb.sel{border-color:var(--brand);background:var(--brand-soft)}
    .ipk-qb svg{width:30px;height:30px;display:block}
  </style>
  <div class="ipk-q" id="pikQuick" role="group" aria-label="Quick icons"><?php foreach (['gmail','facebook','instagram','tiktok','telegram','whatsapp','youtube','netflix','spotify','shield','gift','crown'] as $qk): if (!isset(app_icons()[$qk])) continue; ?><button type="button" class="ipk-qb" data-v="app:<?= e($qk) ?>" title="<?= e(app_icons()[$qk][0]) ?>" aria-label="<?= e(app_icons()[$qk][0]) ?>"><?= app_icon_svg($qk, '30px') ?></button><?php endforeach; ?></div>
  <?= icon_picker_html('pik', 'picon', '', true) ?>
  <label>bulkmail.shop product ID <small>(optional · links this product to the supplier)</small></label>
  <div style="display:flex;gap:8px"><input type="number" name="supplier_id" id="p_sup" min="1" step="1" inputmode="numeric" placeholder="e.g. 123"<?= bm_connected() ? '' : ' title="Connect the API first"' ?>><button type="button" class="btn ghost" id="p_supGo" style="flex:none">Fetch</button></div>
  <small id="p_supNote" style="display:block;margin-top:6px"><?= bm_connected() ? 'Stock is copied live from bulkmail.shop. When a customer buys, the order is placed there automatically and the items are delivered instantly.' : 'Not connected yet: open <a href="/admin.php?p=api">API Management</a> and connect your API key first.' ?></small>
  <div id="p_supAuto" style="display:none;margin-top:10px">
    <label class="chk"><input type="checkbox" name="sup_auto_name" id="p_an" value="1"> <span>Auto-set the name from the Possible Domain (typing your own name turns this off)</span></label>
    <label class="chk" style="margin-top:8px"><input type="checkbox" name="sup_show_dom" id="p_sd" value="1"> <span>Show “Possible Domain (…)” under the description</span></label>
    <label class="chk" style="margin-top:8px"><input type="checkbox" name="sup_auto_desc" id="p_ad" value="1"> <span>Auto-update the description (with possible domains) from the supplier</span></label>
    <small style="display:block;margin-top:4px">Ticked = refreshed automatically every 15 minutes. To use your own name or description, just type it: the box unticks itself and your text stays.</small>
  </div>
  <label class="chk" style="margin-top:16px"><input type="checkbox" name="popular" id="p_pop" value="1"> <span>Mark as Popular</span></label>
  <label class="chk" style="margin-top:12px"><input type="checkbox" name="auto_delivery" id="p_auto" value="1"> <span>Instant delivery from uploaded stock</span></label>
  <small id="p_autoNote" style="display:block;margin-top:4px">Stock = number of unsold uploaded items. Use the “Stock” button on the product to upload them.</small>
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="pSave" name="add_product" value="1">Save product</button></div>
</form></dialog>

<dialog id="sdlg"><form method="post" enctype="multipart/form-data" class="dlg"><?= csrf_field() ?>
  <div class="dlg-h"><h3>Add stock</h3><button type="button" class="dlg-x" data-close aria-label="Close"><?= ai('x') ?></button></div>
  <p id="sInfo" class="sinfo"></p>
  <label>Paste items <small>(one per line, e.g. <span class="mono">mail@gmail.com|password</span>)</small></label>
  <textarea name="stock_text" id="s_text" class="stk-ta" spellcheck="false" placeholder="mail1@gmail.com|password1&#10;mail2@gmail.com|password2"></textarea>
  <label>…or upload a file <small>(.txt, .csv or .xlsx — first sheet is used)</small></label>
  <input type="file" name="stock_file" id="s_file" accept=".txt,.csv,.tsv,.xlsx,text/plain,text/csv">
  <label class="chk" style="margin-top:14px"><input type="checkbox" name="dedupe" value="1" checked> <span>Skip items that are already in stock</span></label>
  <label class="chk" style="margin-top:8px"><input type="checkbox" name="skip_header" value="1"> <span>First row is a header — skip it</span></label>
  <small class="sinfo2">When a customer buys, the oldest unsold items are delivered to them <b>instantly</b> and removed from stock. For spreadsheets, each row becomes one item (columns joined with “|”).</small>
  <div class="dlg-f sf"><button type="submit" class="btn ghost red-t" id="sClear" name="clear_stock" value="" formnovalidate>Clear unsold</button><span class="grow"></span>
    <button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="sSave" name="upload_stock" value="">Add to stock</button></div>
</form></dialog>

<?php /* ============================ CATEGORIES ============================ */ elseif ($page === 'categories'): ?>
<div class="card">
  <div class="tw"><table class="rt"><thead><tr><th>Category</th><th class="hm">Products</th><th class="hm">Sort</th><th>Status</th><th></th></tr></thead><tbody>
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
  <?= icon_picker_html('ipk', 'cicon', 'app:shop', false) ?>
  <label>Sort order <small>(smaller shows first)</small></label><input type="number" name="csort" id="c_sort" value="0">
  <div class="dlg-f"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn" id="cSave" name="add_category" value="1">Save category</button></div>
</form></dialog>

<?php /* ============================ USERS ============================ */ elseif ($page === 'users'): ?>
<?php if (!$userView):
  $qBase = $uq !== '' ? '&q=' . urlencode($uq) : '';
  $qs = $qBase . ($uf !== '' ? '&f=' . $uf : '');
  $ret = $qs . ($uPg > 1 ? '&pg=' . $uPg : '');
  $uLink = fn(int $id, string $t = '') => '/admin.php?p=users&amp;u=' . $id . ($t !== '' ? '&amp;t=' . $t : ''); ?>
<div class="stats s3">
  <div class="stat"><span>Total users</span><b><?= $totalUsers ?></b></div>
  <div class="stat <?= $uStats['banned'] ? 'alert' : '' ?>"><span>Banned</span><b><?= $uStats['banned'] ?></b></div>
  <div class="stat"><span>New in 7 days</span><b><?= $uStats['week'] ?></b></div>
</div>
<div class="card">
  <form method="get" class="tb"><input type="hidden" name="p" value="users"><?php if ($uf !== ''): ?><input type="hidden" name="f" value="<?= e($uf) ?>"><?php endif; ?>
    <div class="srch grow"><?= ai('search') ?><input type="search" name="q" value="<?= e($uq) ?>" placeholder="Search by email or ID" autocomplete="off"></div>
    <button class="btn">Search</button><?php if ($uq !== '' || $uf !== ''): ?><a class="btn ghost" href="/admin.php?p=users">Clear</a><?php endif; ?>
  </form>
  <div class="fchips">
    <?php foreach (['' => 'All', 'banned' => 'Banned', 'disc' => 'With discount'] as $k => $l): ?>
    <a class="<?= $uf === $k ? 'on' : '' ?>" href="/admin.php?p=users<?= $qBase ?><?= $k !== '' ? '&amp;f=' . $k : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="tw"><table><thead><tr><th>#</th><th>Email</th><th>Balance</th><th class="hm">Orders</th><th class="hm">Joined</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $x): ?>
  <tr><td><?= (int)$x['id'] ?></td>
    <td><a href="<?= $uLink((int)$x['id']) ?>"><?= e($x['email']) ?></a><?php if ((int)$x['discs'] > 0): ?><span class="tag">Discount</span><?php endif; ?></td>
    <td class="money"><?= money($x['coins']) ?></td><td class="hm"><?= (int)$x['ords'] ?></td><td class="hm"><?= e($x['created_at']) ?></td>
    <td><span class="badge <?= $x['banned'] ? 'banned' : 'on' ?>"><?= $x['banned'] ? 'Banned' : 'Active' ?></span></td>
    <td class="ra"><div class="acts">
      <a class="btn sm ghost" href="<?= $uLink((int)$x['id'], 'balance') ?>"><?= ai('coins') ?>Balance</a>
      <a class="btn sm ghost" href="<?= $uLink((int)$x['id'], 'discount') ?>"><?= ai('sliders') ?>Discount</a>
      <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="to" value="<?= $x['banned'] ? 0 : 1 ?>"><input type="hidden" name="ret" value="<?= e($ret) ?>">
        <button class="btn sm <?= $x['banned'] ? 'ok-b' : 'red' ?>" name="user_ban" value="<?= (int)$x['id'] ?>"<?= $x['banned'] ? '' : ' onclick="return confirm(\'Ban this user?\')"' ?>><?= $x['banned'] ? 'Unban' : 'Ban' ?></button></form>
    </div></td></tr>
  <?php endforeach; if (!$users): ?><tr><td colspan="7"><?= ($uq !== '' || $uf !== '') ? 'No matching users.' : 'No users yet.' ?></td></tr><?php endif; ?></tbody></table></div>
  <?php if ($uPages > 1): ?>
  <div class="pager">
    <?php if ($uPg > 1): ?><a class="btn sm ghost" href="/admin.php?p=users<?= $qs ?>&amp;pg=<?= $uPg - 1 ?>">&larr; Previous</a><?php endif; ?>
    <span>Page <?= $uPg ?> of <?= $uPages ?> · <?= number_format($uMatch) ?> users</span>
    <?php if ($uPg < $uPages): ?><a class="btn sm ghost" href="/admin.php?p=users<?= $qs ?>&amp;pg=<?= $uPg + 1 ?>">Next &rarr;</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php else: $x = $userView; $xid = (int)$x['id'];
  $tabs = ['info' => ['Info', 'user'], 'balance' => ['Balance', 'coins'], 'discount' => ['Discount', 'sliders'], 'ban' => ['Ban', 'shield'], 'orders' => ['Orders', 'cart']]; ?>
<div class="card ucard">
  <div class="uav"><?= e(mb_strtoupper(mb_substr((string)$x['email'], 0, 1))) ?></div>
  <div class="ui"><b><?= e($x['email']) ?></b><small>ID <?= $xid ?> · Joined <?= e(substr((string)$x['created_at'], 0, 10)) ?></small></div>
  <div class="ur"><span class="badge <?= $x['banned'] ? 'banned' : 'on' ?>"><?= $x['banned'] ? 'Banned' : 'Active' ?></span><div class="money"><?= money($x['coins']) ?></div></div>
</div>
<nav class="utabs" aria-label="User sections">
  <?php foreach ($tabs as $k => [$l, $ic]): ?>
  <a href="/admin.php?p=users&amp;u=<?= $xid ?>&amp;t=<?= $k ?>" class="<?= $uTab === $k ? 'on' : '' ?><?= ($k === 'ban' && $x['banned']) ? ' dot' : '' ?>"<?= $uTab === $k ? ' aria-current="page"' : '' ?>><?= ai($ic) ?><span><?= e($l) ?></span><?php if ($k === 'discount' && $uDisc): ?><em><?= count($uDisc) ?></em><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($uTab === 'info'): ?>
<div class="cols">
  <div class="card"><h3>User info</h3>
    <div class="kv">
      <div><span>Email</span><b><?= e($x['email']) ?></b></div>
      <div><span>Balance</span><b class="money"><?= money($x['coins']) ?></b></div>
      <div><span>Joined</span><b><?= e($x['created_at']) ?></b></div>
      <div><span>Referral code</span><b><?= e($x['referral_code']) ?></b></div>
      <div><span>Referred by</span><b><?= $x['referred_by'] ? '<a href="/admin.php?p=users&amp;u=' . (int)$x['referred_by'] . '">' . e($x['ref_email'] ?? ('#' . $x['referred_by'])) . '</a>' : '—' ?></b></div>
      <div><span>People referred</span><b><?= (int)$x['refs'] ?></b></div>
      <div><span>Orders</span><b><?= (int)$uOrd['n'] ?> · <?= money($uOrd['spent']) ?></b></div>
      <div><span>Total deposited</span><b><?= money($uDep) ?></b></div>
    </div>
  </div>
  <div class="card"><h3>Change email</h3>
    <form method="post"><?= csrf_field() ?>
      <input type="text" name="new_email" maxlength="190" value="<?= e($x['email']) ?>" required>
      <div class="acts" style="margin-top:14px"><button class="btn" name="user_email" value="<?= $xid ?>">Save email</button></div>
    </form>
    <h3>Change password</h3>
    <form method="post"><?= csrf_field() ?>
      <label style="margin-top:0">New password <small>(8 to 72 characters)</small></label><input type="text" name="new_password" minlength="8" maxlength="72" autocomplete="off" required>
      <div class="acts" style="margin-top:14px"><button class="btn" name="user_password" value="<?= $xid ?>">Set password</button></div>
    </form>
  </div>
</div>

<?php elseif ($uTab === 'balance'): ?>
<div class="card"><div class="card-h"><h3>Add or deduct balance</h3><span class="money" style="font-size:20px"><?= money($x['coins']) ?></span></div>
  <form method="post"><?= csrf_field() ?>
    <label style="margin-top:0">Amount <small>(BDT)</small></label><input type="number" name="amount" id="amt" step="0.01" min="0.01" inputmode="decimal" required placeholder="e.g. 100">
    <div class="chips"><?php foreach ([50, 100, 200, 500, 1000] as $v): ?><button type="button" class="btn sm ghost" data-amt="<?= $v ?>"><?= $v ?></button><?php endforeach; ?></div>
    <label>Action</label><select name="dir"><option value="add">Add to balance</option><option value="deduct">Deduct from balance</option></select>
    <label>Note <small>(optional, the user sees it in Transactions)</small></label><input type="text" name="note" maxlength="120" placeholder="e.g. Bonus / correction">
    <div class="acts" style="margin-top:14px"><button class="btn" name="user_adjust" value="<?= $xid ?>">Apply</button></div>
  </form>
</div>
<div class="card"><h3>Recent transactions</h3>
  <div class="tw"><table><thead><tr><th>Type</th><th>Amount</th><th class="hm">Note</th><th class="hm">Date</th></tr></thead><tbody>
  <?php foreach ($uTx as $t): ?>
  <tr><td><?= e($t['type']) ?></td><td class="money"><?= money($t['amount']) ?></td><td class="hm"><?= e($t['note']) ?></td><td class="hm"><?= e($t['created_at']) ?></td></tr>
  <?php endforeach; if (!$uTx): ?><tr><td colspan="4">No transactions yet.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($uTab === 'discount'): ?>
<div class="card"><h3>Discounts for this user</h3>
  <?php foreach ($uDisc as $d): ?>
  <div class="mini"><div><b><?= (int)$d['product_id'] ? e($d['pname'] ?? ('Product #' . (int)$d['product_id'])) : 'All products' ?></b><small>Applies to every purchase</small></div>
    <form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $xid ?>"><span class="tag" style="margin:0">-<?= pct_fmt((float)$d['percent']) ?>%</span>
      <button class="btn sm red" name="user_disc_del" value="<?= (int)$d['id'] ?>" aria-label="Remove discount"><?= ai('trash') ?></button></form></div>
  <?php endforeach; if (!$uDisc): ?><div class="empty">No special discounts for this user yet.</div><?php endif; ?>
</div>
<div class="card"><h3>Add a discount</h3>
  <form method="post"><?= csrf_field() ?>
    <div class="grid2"><div><label style="margin-top:0">Applies to</label><select name="disc_product"><option value="0">All products</option>
      <?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= e($pr['name']) ?> (ID <?= (int)$pr['id'] ?>)</option><?php endforeach; ?></select></div>
    <div><label style="margin-top:0">Discount %</label><input type="number" name="disc_pct" min="0.01" max="99" step="0.01" inputmode="decimal" required placeholder="e.g. 10"></div></div>
    <div class="acts" style="margin-top:14px"><button class="btn" name="user_disc_set" value="<?= $xid ?>">Save discount</button></div>
    <small class="hint">Saving again for the same product replaces the old percent. If a product also has its own discount, the bigger one is used (they are not added together).</small>
  </form>
</div>

<?php elseif ($uTab === 'ban'): ?>
<div class="card"><div class="card-h"><h3>Ban or unban</h3><span class="badge <?= $x['banned'] ? 'banned' : 'on' ?>"><?= $x['banned'] ? 'Banned' : 'Active' ?></span></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="to" value="<?= $x['banned'] ? 0 : 1 ?>">
    <small class="hint" style="margin:0 0 14px"><?= $x['banned'] ? 'This user is banned: they are signed out and cannot log in or use the account.' : 'Banning signs the user out at once and blocks the account. Balance and history are kept.' ?></small>
    <button class="btn <?= $x['banned'] ? 'ok-b' : 'red' ?>" name="user_ban" value="<?= $xid ?>"<?= $x['banned'] ? '' : ' onclick="return confirm(\'Ban this user?\')"' ?>><?= $x['banned'] ? 'Unban user' : 'Ban user' ?></button>
  </form>
</div>
<div class="card"><h3>Delete account</h3>
  <form method="post"><?= csrf_field() ?>
    <small class="hint" style="margin:0 0 14px">Only possible for users with no orders and no deposits. Otherwise ban the user instead.</small>
    <button class="btn ghost red-t" name="user_delete" value="<?= $xid ?>" onclick="return confirm('Delete this account permanently? This cannot be undone.')"><?= ai('trash') ?>Delete user</button>
  </form>
</div>

<?php else: ?>
<div class="card"><h3>Recent orders</h3>
  <div class="tw"><table><thead><tr><th>#</th><th>Product</th><th>Price</th><th>Status</th><th class="hm">Date</th></tr></thead><tbody>
  <?php foreach ($uOrders as $o): ?>
  <tr><td><?= (int)$o['id'] ?></td><td><?= e($o['product_name']) ?><?= (int)$o['qty'] > 1 ? ' × ' . (int)$o['qty'] : '' ?></td><td class="money"><?= money($o['price']) ?></td><td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td><td class="hm"><?= e($o['created_at']) ?></td></tr>
  <?php endforeach; if (!$uOrders): ?><tr><td colspan="5">No orders yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php endif; ?>

<?php /* ============================ SETTINGS ============================ */ elseif ($page === 'settings'): ?>
<?php if ($setErr): ?><div class="err"><?= e($setErr) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="card"><h3>General</h3>
  <label style="margin-top:0">Site name</label><input type="text" name="site_name" maxlength="40" required value="<?= e($_POST['site_name'] ?? site_name()) ?>">
  <label>Headline <small>(homepage)</small></label><input type="text" name="tagline" maxlength="120" value="<?= $sv('tagline') ?>">
  <label>Sub-headline</label><input type="text" name="subtitle" maxlength="300" value="<?= $sv('subtitle') ?>">
</div>
<div class="card"><h3>Currency</h3>
  <label style="margin-top:0">Dollar rate <small>(1 USD = how many BDT)</small></label>
  <input type="number" name="usd_rate" step="0.0001" min="1" inputmode="decimal" placeholder="e.g. 120" value="<?= e($_POST['usd_rate'] ?? setting('usd_rate')) ?>">
  <small style="display:block;margin-top:6px">Users can switch between BDT and $ in the top bar. Everything is still charged in BDT; the $ view is converted with this rate. Leave blank to hide the $ option.</small>
</div>
<div class="card"><h3>Support links</h3>
  <label style="margin-top:0">Telegram support link</label><input type="text" name="telegram_url" value="<?= $sv('telegram_url') ?>" placeholder="https://t.me/your_username">
  <label>WhatsApp support link</label><input type="text" name="whatsapp_url" value="<?= $sv('whatsapp_url') ?>" placeholder="https://wa.me/8801XXXXXXXXX">
  <label>Footer support link <small>(optional)</small></label><input type="text" name="support_url" value="<?= $sv('support_url') ?>">
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
<?php /* ============================ API MANAGEMENT ============================ */ elseif ($page === 'api'):
  $bmOn = bm_connected(); $bmBal = $bmOn ? bm_balance() : null;
  if ($bmBal) save_setting('bm_currency', $bmBal['currency']);
  $linked = $pdo->query('SELECT id, name, supplier_id, stock, sup_stock, active, (SELECT COUNT(*) FROM stock_items si WHERE si.product_id = products.id AND si.order_id IS NULL) AS local_stock FROM products WHERE supplier_id IS NOT NULL ORDER BY id DESC')->fetchAll();
  $bmErr = trim(setting('bm_last_error', '')); ?>
<?php if ($apiErr): ?><div class="err"><?= e($apiErr) ?></div><?php endif; ?>
<div class="card"><div class="card-h"><h3>bulkmail.shop connection</h3><span class="badge <?= $bmOn ? 'on' : 'off' ?>"><?= $bmOn ? 'Connected' : 'Disconnected' ?></span></div>
<?php if ($bmOn): ?>
  <div class="kv">
    <div><span>API key</span><b class="mono"><?= e(bm_mask(bm_key())) ?></b></div>
    <div><span>Supplier wallet balance</span><b class="money"><?= $bmBal ? e(number_format($bmBal['balance'], 2) . ' ' . $bmBal['currency']) : '<span style="color:var(--red)">could not read (check the key)</span>' ?></b></div>
    <div><span>Linked products</span><b><?= count($linked) ?></b></div>
  </div>
  <small class="hint">Every sale of a linked product is bought at bulkmail.shop from this wallet balance, so keep it topped up. If it runs out, customers are refunded automatically and the product shows an error until you add funds.</small>
  <div class="acts" style="margin-top:16px">
    <form method="post" class="inl"><?= csrf_field() ?><button class="btn ghost" name="bm_refresh" value="1">Refresh stock &amp; details now</button></form>
    <form method="post" class="inl"><?= csrf_field() ?><button class="btn red" name="bm_disconnect" value="1" onclick="return confirm('Disconnect from bulkmail.shop? The API key is removed and linked products show as out of stock until you connect again.')">Disconnect</button></form>
  </div>
<?php else: ?>
  <form method="post" autocomplete="off"><?= csrf_field() ?>
    <label style="margin-top:0">API key <small>(from your bulkmail.shop dashboard)</small></label>
    <div class="pw" style="position:relative"><input type="password" id="bmk" name="bm_key" required autocomplete="off" spellcheck="false" placeholder="Paste your API key" style="padding-right:64px"><button type="button" data-toggle="#bmk" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--brand);font-weight:600;cursor:pointer">Show</button></div>
    <small class="hint">The key is checked with bulkmail.shop first and saved only if it works. You can disconnect at any time.</small>
    <div class="acts" style="margin-top:16px"><button class="btn" name="bm_connect" value="1">Connect</button></div>
  </form>
<?php endif; ?>
</div>
<?php if ($bmErr !== ''): ?><div class="card"><div class="card-h"><h3>Last problem</h3>
  <form method="post" class="inl"><?= csrf_field() ?><button class="btn sm ghost" name="bm_clear_err" value="1">Dismiss</button></form></div>
  <div class="err" style="margin:0"><?= e($bmErr) ?></div></div><?php endif; ?>
<div class="card"><div class="card-h"><h3>How to link a product</h3><a href="/admin.php?p=products">Open products</a></div>
  <div class="kv">
    <div><span>1</span><b style="font-weight:500">Connect the API key above</b></div>
    <div><span>2</span><b style="font-weight:500">Products → Add product → type the <u>bulkmail.shop product ID</u> and press Fetch</b></div>
    <div><span>3</span><b style="font-weight:500">Set your selling price and save</b></div>
  </div>
  <small class="hint">Linked products copy their stock live from bulkmail.shop and are delivered instantly after the customer pays. To unlink, clear the ID in the product and save.</small>
</div>
<div class="card"><h3>Linked products</h3>
  <div class="tw"><table class="rt"><thead><tr><th>Product</th><th>bulkmail ID</th><th>Stock</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($linked as $x): ?>
  <tr><td><b><?= e($x['name']) ?></b><small style="display:block">Shop ID <?= (int)$x['id'] ?></small></td><td>#<?= (int)$x['supplier_id'] ?></td><td><?= number_format((int)$x['stock']) ?> <small>(API <?= number_format((int)$x['sup_stock']) ?> + local <?= number_format((int)$x['local_stock']) ?>)</small></td><td><span class="badge <?= $x['active'] ? 'on' : 'off' ?>"><?= $x['active'] ? 'Visible' : 'Hidden' ?></span></td></tr>
  <?php endforeach; if (!$linked): ?><tr><td colspan="4">No linked products yet.</td></tr><?php endif; ?></tbody></table></div>
  <?php $rawS = trim(setting('bm_raw_sample', '')); if ($rawS !== ''): ?>
  <details style="margin-top:12px"><summary style="cursor:pointer;font-weight:600">What bulkmail.shop sends (sample, for checking the fields)</summary>
    <pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;background:#f8fafc;border-radius:10px;padding:10px;margin-top:8px"><?= e($rawS) ?></pre>
    <small class="hint">Press “Refresh stock &amp; details now” above to update this sample. Domains are copied from any field with “domain” in its name, or from text like “Possible Domain (…)”.</small>
  </details>
  <?php endif; ?>
</div>
<?php /* ============================ NOTICE & MAINTENANCE ============================ */ elseif ($page === 'announce'):
  $posted = isset($_POST['save_notice']);
  $nOn   = $posted ? !empty($_POST['notice_on']) : setting('notice_on', '0') === '1';
  $nTxt  = $posted ? (string)($_POST['notice_text'] ?? '') : setting('notice_text');
  $nType = $posted ? (string)($_POST['notice_type'] ?? 'info') : setting('notice_type', 'info');
  if (!in_array($nType, ['info', 'success', 'warning', 'danger'], true)) $nType = 'info';
  $nClose = $posted ? !empty($_POST['notice_dismiss']) : setting('notice_dismiss', '1') === '1';
  $nIcons = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'danger' => '🚨']; ?>
<?php if ($noticeErr): ?><div class="err"><?= e($noticeErr) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="card"><div class="card-h"><h3>Notice for users</h3><span class="badge <?= setting('notice_on', '0') === '1' ? 'on' : 'off' ?>"><?= setting('notice_on', '0') === '1' ? 'Visible' : 'Hidden' ?></span></div>
  <label class="sw"><input type="checkbox" name="notice_on" value="1"<?= $nOn ? ' checked' : '' ?>><span class="tg"></span><span>Show this notice on the website</span></label>
  <label style="margin-top:0">Message <small>(max 500 characters, new lines are kept)</small></label>
  <textarea name="notice_text" id="n_text" maxlength="500" rows="3" placeholder="e.g. Deposits are processed within 30 minutes. Thank you for your patience."><?= e($nTxt) ?></textarea>
  <label>Style</label>
  <select name="notice_type" id="n_type">
    <?php foreach (['info' => 'Info (blue)', 'success' => 'Good news (green)', 'warning' => 'Warning (yellow)', 'danger' => 'Important (red)'] as $k => $l): ?><option value="<?= $k ?>"<?= $nType === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <label class="chk" style="display:flex;align-items:center;gap:10px;font-weight:500"><input type="checkbox" name="notice_dismiss" value="1"<?= $nClose ? ' checked' : '' ?>> Let users close the notice <small>(it comes back when you change the text)</small></label>
  <label>Preview</label>
  <div class="nt nt-<?= e($nType) ?>" id="ntPrev" style="margin-top:0"><span class="nt-i"><?= $nIcons[$nType] ?></span><div class="nt-t"><?= e($nTxt !== '' ? $nTxt : 'Your notice will look like this.') ?></div></div>
  <div class="savebar"><button class="btn" name="save_notice" value="1">Save notice</button></div>
</div>
</form>

<form method="post" onsubmit="return !this.maintenance_on.checked || <?= $maint ? 'true' : 'false' ?> || confirm('Turn ON maintenance mode? Visitors will not be able to use the site until you turn it off.')"><?= csrf_field() ?>
<div class="card"><div class="card-h"><h3>Maintenance mode</h3><span class="badge <?= $maint ? 'off' : 'on' ?>"><?= $maint ? 'ON — site closed' : 'OFF — site live' ?></span></div>
  <label class="sw warn"><input type="checkbox" name="maintenance_on" value="1"<?= $maint ? ' checked' : '' ?>><span class="tg"></span><span>Maintenance mode</span></label>
  <small class="hint" style="margin:-6px 0 6px">While ON, visitors and logged-in users see a maintenance page. You (logged in to this admin panel) can still open the site and this panel. Switch it OFF here any time.</small>
  <label>Message shown to visitors</label>
  <textarea name="maintenance_msg" maxlength="300" rows="3" placeholder="We are doing some scheduled maintenance. Please check back soon."><?= e(setting('maintenance_msg')) ?></textarea>
  <label>Expected back <small>(optional, e.g. “in about 30 minutes” or “6:00 PM”)</small></label>
  <input type="text" name="maintenance_eta" maxlength="60" value="<?= e(setting('maintenance_eta')) ?>">
  <div class="savebar"><button class="btn<?= $maint ? '' : ' red' ?>" name="save_maintenance" value="1">Save maintenance settings</button></div>
</div>
</form>
<?php endif; ?>
</main>
</div>

<script>
(function(){
  var $=function(s,r){return (r||document).querySelector(s)}, $$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};
  var REOPEN = <?= json_encode($reopen, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  document.addEventListener('keydown',function(e){ if(e.key==='Escape') document.body.classList.remove('menu'); });
  $$('.s-nav a').forEach(function(a){ a.addEventListener('click',function(){ document.body.classList.remove('menu'); }); });

  /* dialogs: close buttons + click on backdrop */
  $$('dialog').forEach(function(d){
    $$('[data-close]',d).forEach(function(b){ b.addEventListener('click',function(){ d.close(); }); });
    d.addEventListener('click',function(e){ if(e.target===d) d.close(); });
  });


  /* icon picker: prefix = element id prefix, defVal = value used when nothing is chosen ('' = automatic) */
  function picker(pre,defVal,guess){
    var hid=$('#'+pre+'Val'), cur=$('#'+pre+'Cur'), nm=$('#'+pre+'Name'), pop=$('#'+pre+'Pop'), btn=$('#'+pre+'Btn'), em=$('#'+pre+'Emoji'),
        q=$('#'+pre+'Q'), list=$('#'+pre+'List'), none=$('#'+pre+'None'), auto=$('#'+pre+'Auto'), opts=$$('.ipk-o',list);
    function setIcon(v){
      v=(v===undefined||v===null)?defVal:String(v); hid.value=v;
      opts.forEach(function(o){ o.classList.toggle('sel',o.dataset.v===v); });
      function show(opt){
        cur.innerHTML=opt.querySelector('svg').outerHTML.replace(/ai[0-9a-f]+_\d+[gs]/g,function(m){return m+pre;});
        var sv=cur.firstChild; sv.setAttribute('width','32'); sv.setAttribute('height','32');
      }
      if(v===''){
        em.value='';
        var g=guess?guess():'', go=g?opts.filter(function(o){return o.dataset.v==='app:'+g;})[0]:null;
        if(go){ show(go); nm.textContent='Automatic \u00B7 '+go.dataset.n; } else { cur.textContent='\u2728'; nm.textContent='Automatic'; }
        return;
      }
      if(v.indexOf('app:')===0){
        var opt=opts.filter(function(o){return o.dataset.v===v;})[0];
        if(!opt){ if(v===defVal) return; return setIcon(defVal); }
        show(opt); nm.textContent=opt.dataset.n; em.value='';
      } else { cur.textContent=v; nm.textContent='Emoji'; em.value=v; }
    }
    function srch(t){
      t=t.trim().toLowerCase(); var any=false;
      opts.forEach(function(o){ var ok=!t||o.dataset.n.toLowerCase().indexOf(t)>-1||o.dataset.v.indexOf(t)>-1; o.style.display=ok?'':'none'; if(ok)any=true; });
      $$('.ipk-grid',list).forEach(function(g){ var vis=$$('.ipk-o',g).some(function(o){return o.style.display!=='none';}); g.style.display=vis?'':'none'; g.previousElementSibling.style.display=vis?'':'none'; });
      none.style.display=any?'none':'block';
    }
    function toggle(open){ pop.hidden=!open; btn.setAttribute('aria-expanded',open?'true':'false'); if(open){ q.value=''; srch(''); } }
    btn.addEventListener('click',function(){ toggle(pop.hidden); });
    q.addEventListener('input',function(){ srch(q.value); });
    opts.forEach(function(o){ o.addEventListener('click',function(){ setIcon(o.dataset.v); toggle(false); }); });
    if(auto) auto.addEventListener('click',function(){ setIcon(''); toggle(false); });
    em.addEventListener('input',function(){ var v=em.value.trim(); setIcon(v?v:defVal); });
    setIcon(hid.value===''?defVal:hid.value);
    return {set:setIcon, close:function(){ toggle(false); }, refresh:function(){ if(hid.value==='') setIcon(''); }};
  }

  /* ---------- products ---------- */
  var pd=$('#pdlg');
  if(pd){
    var ICONS=<?= json_encode(array_map(fn($d) => $d[0], app_icons()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var ALIAS={twitter:'x',vpn:'shield',hotmail:'outlook',office:'microsoft','prime video':'amazon','gift card':'gift'};
    var guessIcon=function(){   // same rule the shop uses when no icon is chosen
      var n=($('#p_name').value||'').toLowerCase(); if(!n) return '';
      for(var w in ALIAS){ if(n.indexOf(w)>-1 && ICONS[ALIAS[w]]) return ALIAS[w]; }
      for(var k in ICONS){ if(k==='shop') continue; var ws=[k,ICONS[k].toLowerCase()]; for(var i=0;i<2;i++){ if(ws[i].length>=3 && n.indexOf(ws[i])>-1) return k; } }
      return '';
    };
    var pp=picker('pik','',guessIcon);
    var quickSync=function(){ var cv=$('#pikVal').value; $$('#pikQuick .ipk-qb').forEach(function(b){ b.classList.toggle('sel',b.dataset.v===cv); }); };
    $$('#pikQuick .ipk-qb').forEach(function(b){ b.addEventListener('click',function(){ pp.set(b.dataset.v); pp.close(); quickSync(); }); });
    $('#pik').addEventListener('click',function(){ setTimeout(quickSync,0); });
    $('#p_name').addEventListener('input',function(){ pp.refresh(); $('#p_an').checked=false; });
    pd.addEventListener('close',function(){ pp.close(); });
    var autoSync=function(){
      var sup=$('#p_sup').value.trim()!=='', on=$('#p_auto').checked && !sup;
      $('#p_supAuto').style.display=sup?'block':'none';
      $('#p_auto').disabled=sup; if(sup) $('#p_auto').checked=false;
      $('#p_stock').disabled=on||sup; $('#p_autoNote').style.display=on?'block':'none'; if(on||sup) $('#p_stock').value='';
    };
    $('#p_auto').addEventListener('change',autoSync);
    $('#p_sup').addEventListener('input',autoSync);
    var supGo=$('#p_supGo'), supNote=$('#p_supNote'), supNote0=supNote.innerHTML, origSup='';
    var lookup=function(force){
      var id=$('#p_sup').value.trim();
      if(!id){ supNote.textContent='Type the bulkmail.shop product ID first.'; return; }
      supGo.disabled=true; supNote.textContent='Checking bulkmail.shop\u2026';
      fetch('/admin.php?bm_lookup='+encodeURIComponent(id),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        supGo.disabled=false;
        if(!j.ok){ supNote.textContent=j.error||'Not found.'; return; }
        var p=j.p;
        if(force){ $('#p_an').checked=true; $('#p_ad').checked=true; }   // ID changed: take everything from the new supplier product
        if($('#p_an').checked || !$('#p_name').value.trim()) $('#p_name').value=(p.domains||p.name).slice(0,p.domains?40:80);   // name = Possible Domain when there is one
        if(p.description && ($('#p_ad').checked || !$('#p_desc').value.trim())){ $('#p_desc').value=p.description.slice(0,500); renderDescPrev(); }
        if(!$('#p_price').value && j.cur==='USD' && +j.rate>0) $('#p_price').value=(Math.ceil(p.price*j.rate*100)/100).toFixed(2);
        pp.refresh();
        supNote.textContent='Found: '+p.name+(p.domains?' \u00B7 Possible Domain ('+p.domains+')':' \u00B7 no Possible Domain found')+' \u00B7 supplier price '+p.price+' '+j.cur+' \u00B7 '+p.stock.toLocaleString('en-US')+' in stock. Set your own selling price (add your profit).';
      }).catch(function(){ supGo.disabled=false; supNote.textContent='Could not reach the server. Try again.'; });
    };
    supGo.addEventListener('click',function(){ lookup($('#p_sup').value.trim()!==origSup); });
    $('#p_sup').addEventListener('change',function(){ var v=$('#p_sup').value.trim(); if(v!=='' && v!==origSup) lookup(true); });   // new / changed ID: fetch and fill automatically
    var descTa=$('#p_desc'), descPrev=$('#p_descPrev'), descWrap=$('#p_descPrevWrap');
    var descEsc=function(s){ return s.replace(/[&<>"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
    var renderDescPrev=function(){
      var lines=descTa.value.split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
      descWrap.hidden = lines.length===0;
      if(lines.length>1) descPrev.innerHTML='<ul class="pc-dl">'+lines.slice(0,6).map(function(l){ return '<li>'+descEsc(l)+'</li>'; }).join('')+'</ul>';
      else descPrev.innerHTML='<p class="pc-d">'+descEsc(lines[0]||'')+'</p>';
    };
    descTa.addEventListener('input',function(){ renderDescPrev(); $('#p_ad').checked=false; });
    var fill=function(v,id){
      $('#pTitle').textContent = id ? 'Edit product' : 'Add product';
      $('#p_name').value=v.name||''; $('#p_desc').value=v.desc||''; $('#p_price').value=v.price||''; $('#p_disc').value=+v.disc>0?v.disc:'';
      $('#p_unit').value=v.unit||''; $('#p_cat').value=(v.cat===null||v.cat===undefined)?'':v.cat;
      $('#p_stock').value=(v.stock===null||v.stock===undefined)?'':v.stock; $('#p_pop').checked=!!+v.pop;
      $('#p_sup').value=v.sup||''; origSup=String(v.sup||''); supNote.innerHTML=supNote0;
      $('#p_an').checked = (v.an===undefined||v.an===null) ? !id : !!+v.an; $('#p_ad').checked = (v.ad===undefined||v.ad===null) ? !id : !!+v.ad; $('#p_sd').checked = (v.sd===undefined||v.sd===null) ? true : !!+v.sd;   // new product: auto-sync on by default; old products keep what they had
      $('#p_auto').checked=!!+v.auto; autoSync(); pp.set(v.icon||''); pp.close(); quickSync(); renderDescPrev();
      var s=$('#pSave'); s.name = id ? 'update_product' : 'add_product'; s.value = id ? id : '1';
      pd.showModal();
    };
    $('#addProduct').addEventListener('click',function(){ fill({},0); });
    $$('[data-edit-p]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editP); fill({name:v.name,desc:v.desc,price:v.price,unit:v.unit,cat:v.cat,stock:v.stock,pop:v.pop,auto:v.auto,icon:v.icon,disc:v.disc,sup:v.sup,an:v.an,ad:v.ad,sd:v.sd}, v.id); }); });
    if(REOPEN && REOPEN.t==='product') fill(REOPEN.v, REOPEN.id);

    /* stock upload dialog */
    var sd=$('#sdlg'), sClear=$('#sClear');
    $$('[data-stock]').forEach(function(b){ b.addEventListener('click',function(){
      var v=JSON.parse(b.dataset.stock);
      $('#sInfo').innerHTML='<b></b> &middot; <span></span>'; $('#sInfo b').textContent=v.name; $('#sInfo span').textContent=v.left.toLocaleString('en-US')+' unsold item'+(v.left===1?'':'s')+' in stock';
      $('#s_text').value=''; $('#s_file').value='';
      $('#sSave').value=v.id; sClear.value=v.id; sClear.style.display=v.left>0?'':'none'; sClear.dataset.left=v.left;
      sd.showModal();
    }); });
    sClear.addEventListener('click',function(e){ if(!confirm('Delete all '+Number(sClear.dataset.left).toLocaleString('en-US')+' unsold items of this product?')) e.preventDefault(); });
    $('#sSave').addEventListener('click',function(e){ if(!$('#s_text').value.trim() && !$('#s_file').value){ e.preventDefault(); alert('Paste some items or choose a file first.'); } });

    var q=$('#pq'), c=$('#pcat'), none=$('#pnone');
    var filt=function(){
      var t=q.value.trim().toLowerCase(), cv=c.value, n=0, rows=$$('#ptable tbody tr');
      rows.forEach(function(r){ var ok=(!t||r.dataset.s.indexOf(t)>-1)&&(cv===''||r.dataset.c===cv); r.style.display=ok?'':'none'; if(ok)n++; });
      none.textContent='No products match your search.'; none.style.display = (rows.length && !n) ? '' : 'none';
    };
    q.addEventListener('input',filt); c.addEventListener('change',filt);
  }

  /* ---------- notice preview ---------- */
  var nt=$('#n_text');
  if(nt){
    var np=$('#ntPrev'), ntype=$('#n_type'), ICO={info:'\u2139\uFE0F',success:'\u2705',warning:'\u26A0\uFE0F',danger:'\uD83D\uDEA8'};
    var upd=function(){ var t=nt.value.trim(); np.className='nt nt-'+ntype.value; $('.nt-t',np).textContent=t||'Your notice will look like this.'; $('.nt-i',np).textContent=ICO[ntype.value]; };
    nt.addEventListener('input',upd); ntype.addEventListener('change',upd);
  }

  /* ---------- quick amounts ---------- */
  $$('[data-amt]').forEach(function(b){ b.addEventListener('click',function(){ var i=$('#amt'); i.value=b.dataset.amt; i.focus(); }); });

  /* ---------- categories ---------- */
  var cd=$('#cdlg');
  if(cd){
    var cp=picker('ipk','app:shop');
    cd.addEventListener('close',function(){ cp.close(); });
    var fillC=function(v,id){
      $('#cTitle').textContent = id ? 'Edit category' : 'Add category';
      $('#c_name').value=v.name||''; $('#c_sort').value=(v.sort===undefined||v.sort===null)?0:v.sort;
      cp.set(v.icon||'app:shop'); cp.close();
      var s=$('#cSave'); s.name = id ? 'update_category' : 'add_category'; s.value = id ? id : '1';
      cd.showModal();
    };
    $('#addCategory').addEventListener('click',function(){ fillC({},0); });
    $$('[data-edit-c]').forEach(function(b){ b.addEventListener('click',function(){ var v=JSON.parse(b.dataset.editC); fillC(v,v.id); }); });
    if(REOPEN && REOPEN.t==='category') fillC(REOPEN.v, REOPEN.id);
  }
})();
</script>
</body></html>
