<?php require __DIR__ . '/lib.php';
require_once __DIR__ . '/supplier_hot.php';
if (empty($_SESSION['admin'])) { header('Location: /admin.php'); exit; }   // log in through the normal admin page first
$GLOBALS['__bdt_only'] = true;
$pdo = db();
suphot_schema();

function hot_flash(string $t, string $m): void { $_SESSION['hot_flash'] = [$t, $m]; }
function hot_go(): void { header('Location: /admin_hot.php'); exit; }

$test = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['save_hot'])) {
        $u = rtrim(trim($_POST['hot_url'] ?? ''), '/');
        $k = trim($_POST['hot_key'] ?? '');
        if ($u !== '' && !preg_match('#^https://[^\s?\#]+$#i', $u)) {
            hot_flash('err', 'API URL must start with https:// (example: https://supplier-site.com/api/v1).');
        } else {
            save_setting('suphot_url', $u);
            if (!empty($_POST['remove_key'])) save_setting('suphot_key', '');
            elseif ($k !== '') save_setting('suphot_key', $k);
            save_setting('suphot_auto', empty($_POST['hot_auto']) ? '0' : '1');
            unset($_SESSION['sup_cat']);
            hot_flash('ok', 'Supplier Hot settings saved.');
        }
        hot_go();

    } elseif (isset($_POST['test_hot'])) {
        unset($_SESSION['sup_cat']);
        $test = suphot_call('GET', '/balance', [], null, 15);

    } elseif (isset($_POST['link_product'])) {
        $pid = (int)($_POST['product_id'] ?? 0);
        $lk  = trim($_POST['link'] ?? '');
        $t = $a = null;
        if ($lk !== '') {
            $sp = explode('|', $lk, 2);
            if (count($sp) === 2 && preg_match('/^[\w.\-]{1,40}$/', $sp[0]) && preg_match('/^[\w.\-]{1,40}$/', $sp[1])) { $t = $sp[0]; $a = $sp[1]; }
            else { hot_flash('err', 'Invalid supplier product.'); hot_go(); }
        }
        $st = $pdo->prepare('SELECT name, supplier_id FROM products WHERE id = ?');
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p) {
            hot_flash('err', 'Product not found.');
        } elseif ($t !== null && (int)($p['supplier_id'] ?? 0) > 0) {
            hot_flash('err', '"' . $p['name'] . '" is linked to bulkmail.shop. Unlink it there first, then link it here.');
        } else {
            $pdo->prepare('UPDATE products SET supplier_type = ?, supplier_account = ? WHERE id = ?')->execute([$t, $a, $pid]);
            if ($t !== null) suphot_sync_stock($pdo, true);
            hot_flash('ok', $t !== null ? '"' . $p['name'] . '" is now supplied by Supplier Hot.' : '"' . $p['name'] . '" is no longer linked to Supplier Hot.');
        }
        hot_go();

    } elseif (isset($_POST['hot_buy'])) {
        [$ok, $msg] = suphot_fulfill_order((int)($_POST['order_id'] ?? 0));
        if ($ok) { try { $pdo->prepare('UPDATE orders SET supplier_order_id = NULL WHERE id = ?')->execute([(int)$_POST['order_id']]); } catch (Throwable $ex) {} }
        hot_flash($ok ? 'ok' : 'err', $msg);
        hot_go();
    }
}

$fl = $_SESSION['hot_flash'] ?? null; unset($_SESSION['hot_flash']);
$url = (string)setting('suphot_url');
$hasKey = (string)setting('suphot_key') !== '';
$auto = setting('suphot_auto') === '1';

$products = $pdo->query('SELECT id, name, price, stock, active, supplier_id, supplier_type, supplier_account FROM products ORDER BY id DESC')->fetchAll();
$pending = $pdo->query("SELECT o.id, o.qty, o.product_name, o.price, o.supplier_order_id, u.email
                        FROM orders o JOIN users u ON u.id = o.user_id JOIN products p ON p.id = o.product_id
                        WHERE o.status = 'pending' AND p.supplier_type IS NOT NULL AND p.supplier_id IS NULL ORDER BY o.id")->fetchAll();
$cat = suphot_connected() ? suphot_catalog() : null;
$opts = suphot_options($products);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Supplier Hot</title>
<style>
:root{--bg:#f4f5f7;--card:#fff;--tx:#1a1d23;--mu:#6b7280;--bd:#e3e5ea;--ac:#2563eb;--ok:#15803d;--er:#b91c1c}
@media(prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#181b21;--tx:#e8eaee;--mu:#9aa1ad;--bd:#2a2f38;--ac:#4f8cff;--ok:#4ade80;--er:#f87171}}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--tx);padding:16px;max-width:900px;margin-inline:auto}
h1{font-size:20px;margin:0 0 4px}h3{margin:0 0 10px;font-size:16px}a{color:var(--ac)}
.card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:16px;margin:14px 0}
label{display:block;margin:12px 0 4px;font-weight:600;font-size:13px}
input[type=text],input[type=password],select{width:100%;padding:10px;border:1px solid var(--bd);border-radius:8px;background:var(--bg);color:var(--tx);font:inherit}
small,.mu{color:var(--mu);font-size:12px}
.btn{padding:9px 14px;border:0;border-radius:8px;background:var(--ac);color:#fff;font:inherit;font-weight:600;cursor:pointer}
.btn.g{background:transparent;color:var(--ac);border:1px solid var(--bd)}.btn.sm{padding:6px 10px;font-size:13px}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.msg{padding:10px 14px;border-radius:8px;margin:12px 0;border:1px solid}.msg.ok{color:var(--ok);border-color:var(--ok)}.msg.err{color:var(--er);border-color:var(--er)}
.tw{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--bd);vertical-align:top}th{font-size:12px;color:var(--mu)}
.tag{display:inline-block;padding:1px 8px;border-radius:99px;border:1px solid var(--bd);font-size:12px}
dialog{border:1px solid var(--bd);border-radius:12px;background:var(--card);color:var(--tx);width:min(92vw,520px);padding:18px}dialog::backdrop{background:rgba(0,0,0,.5)}
.chk{display:flex;gap:8px;align-items:flex-start;font-weight:500}.chk input{margin-top:4px}
</style></head><body>
<h1>Supplier Hot</h1>
<div class="mu"><a href="/admin.php">&larr; Back to admin</a> · each product comes from the one supplier it is linked to</div>

<?php if ($fl): ?><div class="msg <?= $fl[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($fl[1]) ?></div><?php endif; ?>
<?php if ($test): ?>
  <?php if ($test['ok']): ?><div class="msg ok">Connected. Supplier balance: <b><?= e((string)($test['data']['balance_formatted'] ?? $test['data']['balance'] ?? '?')) ?></b> <?= e((string)($test['data']['email'] ?? '')) ?></div>
  <?php else: ?><div class="msg err">Connection failed: <?= e($test['error']) ?></div><?php endif; ?>
<?php endif; ?>

<form method="post" class="card"><?= csrf_field() ?>
  <h3>Connection</h3>
  <label style="margin-top:0">Supplier API URL</label>
  <input type="text" name="hot_url" value="<?= e($url) ?>" placeholder="https://supplier-site.com/api/v1" autocomplete="off">
  <small>Base URL ending in /api/v1, no trailing slash.</small>
  <label>API key</label>
  <input type="password" name="hot_key" autocomplete="new-password" placeholder="<?= $hasKey ? 'Saved. Leave blank to keep it' : 'Paste your API key' ?>">
  <?php if ($hasKey): ?><label class="chk"><input type="checkbox" name="remove_key" value="1"><span>Remove saved key</span></label><?php endif; ?>
  <label class="chk"><input type="checkbox" name="hot_auto" value="1"<?= ($auto || (!$hasKey && $url === '')) ? ' checked' : '' ?>><span>Buy automatically from Supplier Hot when a customer orders a linked product<br><small>If off, those orders wait as Pending and you buy them below.</small></span></label>
  <div class="row"><button class="btn" name="save_hot" value="1">Save</button><button class="btn g" name="test_hot" value="1">Test connection</button></div>
</form>

<div class="card"><h3>Products</h3>
  <div class="tw"><table><thead><tr><th>#</th><th>Product</th><th>Stock</th><th>Supplied by</th><th></th></tr></thead><tbody>
  <?php foreach ($products as $p):
      $isBm  = (int)($p['supplier_id'] ?? 0) > 0;
      $isHot = !$isBm && !empty($p['supplier_type']) && !empty($p['supplier_account']);
      $cur   = $isHot ? $p['supplier_type'] . '|' . $p['supplier_account'] : ''; ?>
    <tr>
      <td><?= (int)$p['id'] ?></td>
      <td><?= e($p['name']) ?><?= $p['active'] ? '' : ' <span class="tag">hidden</span>' ?><br><small><?= money($p['price']) ?></small></td>
      <td><?= $p['stock'] === null ? 'Unlimited' : number_format((int)$p['stock']) ?></td>
      <td><?php if ($isBm): ?><span class="tag">bulkmail.shop</span>
          <?php elseif ($isHot): ?><span class="tag">Supplier Hot</span><br><small><?= e($p['supplier_type'] . ' / ' . $p['supplier_account']) ?></small>
          <?php else: ?><span class="mu">Own stock</span><?php endif; ?></td>
      <td><?php if (!$isBm): ?><button type="button" class="btn g sm" data-id="<?= (int)$p['id'] ?>" data-name="<?= e($p['name']) ?>" data-link="<?= e($cur) ?>" onclick="openLink(this)">Change</button><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$products): ?><tr><td colspan="5" class="mu">No products yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <small>To move a product between suppliers: unlink it from one first, then link it to the other. A product linked to bulkmail.shop is changed in the normal admin panel.</small>
</div>

<?php if ($pending): ?>
<div class="card"><h3>Waiting Supplier Hot orders</h3>
  <div class="tw"><table><thead><tr><th>Order</th><th>Customer</th><th>Item</th><th></th></tr></thead><tbody>
  <?php foreach ($pending as $o): $unc = $o['supplier_order_id'] === 'hot-unclear'; ?>
    <tr><td>#<?= (int)$o['id'] ?></td><td><?= e($o['email']) ?></td>
      <td><?= e($o['product_name']) ?> &times; <?= (int)$o['qty'] ?><?php if ($unc): ?><br><small style="color:var(--er)">Result unknown: check the supplier's order history before buying again.</small><?php endif; ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
        <button class="btn sm" name="hot_buy" value="1" onclick="return confirm('<?= $unc ? 'This order may already have been bought. Buy again anyway?' : 'Buy this from Supplier Hot now? Your supplier balance will be charged.' ?>')">Buy now</button></form></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <small>To refund a customer instead, use Cancel &amp; refund in Admin &rarr; Orders.</small>
</div>
<?php endif; ?>

<div class="card"><h3>Supplier Hot catalogue</h3>
<?php if ($cat === null): ?><span class="mu">Save the API URL and key first.</span>
<?php elseif (!$cat['ok']): ?><div class="msg err"><?= e($cat['error']) ?></div>
<?php else: ?><div class="tw"><table><thead><tr><th>Name</th><th>Type</th><th>Cost</th><th>Stock</th></tr></thead><tbody>
  <?php foreach ($cat['items'] as $it): ?>
    <tr><td><?= e((string)($it['name'] ?? '')) ?></td><td><small><?= e(($it['product_type'] ?? '') . ' / ' . ($it['account_type'] ?? '')) ?></small></td><td><?= e((string)($it['price'] ?? '')) ?></td><td><?= number_format((int)($it['stock'] ?? 0)) ?></td></tr>
  <?php endforeach; if (!$cat['items']): ?><tr><td colspan="4" class="mu">No products returned.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?></div>

<dialog id="dlg"><form method="post"><?= csrf_field() ?>
  <h3 id="dlgT">Link product</h3>
  <input type="hidden" name="product_id" id="dlgId">
  <label style="margin-top:0">Supplier Hot product</label>
  <select name="link" id="dlgSel"><?= $opts ?></select>
  <small>Choose "Not linked" to supply this product from your own stock again.</small>
  <div class="row"><button class="btn" name="link_product" value="1">Save</button><button type="button" class="btn g" onclick="dlg.close()">Cancel</button></div>
</form></dialog>
<script>
var dlg = document.getElementById('dlg');
function openLink(b) {
  document.getElementById('dlgId').value = b.dataset.id;
  document.getElementById('dlgT').textContent = b.dataset.name;
  document.getElementById('dlgSel').value = b.dataset.link || '';
  dlg.showModal();
}
</script>
</body></html>
