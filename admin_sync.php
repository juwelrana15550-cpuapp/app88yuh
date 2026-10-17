<?php require __DIR__ . '/lib.php';
if (empty($_SESSION['admin'])) { header('Location: /admin.php'); exit; }   // log in through the normal admin page first
$GLOBALS['__bdt_only'] = true;
$pdo = db();

function sy_flash(string $t, string $m): void { $_SESSION['sy_flash'] = [$t, $m]; }
function sy_go(): void { header('Location: /admin_sync.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['save_flags'])) {
        $upd = $pdo->prepare('UPDATE products SET sup_auto_name = ?, sup_auto_desc = ?, sup_show_dom = ? WHERE id = ? AND supplier_id IS NOT NULL');
        foreach ((array)($_POST['ids'] ?? []) as $id) {
            $id = (int)$id;
            $upd->execute([isset($_POST['an'][$id]) ? 1 : 0, isset($_POST['ad'][$id]) ? 1 : 0, isset($_POST['sd'][$id]) ? 1 : 0, $id]);
        }
        sy_flash('ok', 'Saved. Changes reach the shop within a couple of minutes, or press "Sync now".');
    } elseif (isset($_POST['all_on'])) {
        $pdo->exec('UPDATE products SET sup_auto_name = 1, sup_auto_desc = 1 WHERE supplier_id IS NOT NULL');
        sy_flash('ok', 'Name and description now follow bulkmail.shop for every linked product.');
    } elseif (isset($_POST['all_off'])) {
        $pdo->exec('UPDATE products SET sup_auto_name = 0, sup_auto_desc = 0 WHERE supplier_id IS NOT NULL');
        sy_flash('ok', 'Automatic name/description sync switched off for every product.');
    } elseif (isset($_POST['sync_now'])) {
        if (!bm_connected()) { sy_flash('err', 'bulkmail.shop is not connected (Admin > API Management).'); sy_go(); }
        bm_sync_stock($pdo, true);
        $n = bm_sync_details($pdo, 30);
        sy_flash('ok', 'Stock refreshed. ' . $n . ' product(s) changed in this round (up to 30 products are checked per press).');
    }
    sy_go();
}

$fl = $_SESSION['sy_flash'] ?? null; unset($_SESSION['sy_flash']);
$rows = $pdo->query('SELECT id, name, supplier_id, stock, sup_stock, sup_domain, sup_auto_name, sup_auto_desc, sup_show_dom FROM products WHERE supplier_id IS NOT NULL ORDER BY id DESC')->fetchAll();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>bulkmail.shop sync</title>
<style>
:root{--bg:#f4f5f7;--card:#fff;--tx:#1a1d23;--mu:#6b7280;--bd:#e3e5ea;--ac:#2563eb;--ok:#15803d;--er:#b91c1c}
@media(prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#181b21;--tx:#e8eaee;--mu:#9aa1ad;--bd:#2a2f38;--ac:#4f8cff;--ok:#4ade80;--er:#f87171}}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--tx);padding:16px;max-width:900px;margin-inline:auto}
h1{font-size:20px;margin:0 0 4px}a{color:var(--ac)}.mu,small{color:var(--mu);font-size:12px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:16px;margin:14px 0}
.btn{padding:9px 14px;border:0;border-radius:8px;background:var(--ac);color:#fff;font:inherit;font-weight:600;cursor:pointer}.btn.g{background:transparent;color:var(--ac);border:1px solid var(--bd)}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.msg{padding:10px 14px;border-radius:8px;margin:12px 0;border:1px solid}.msg.ok{color:var(--ok);border-color:var(--ok)}.msg.err{color:var(--er);border-color:var(--er)}
.tw{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--bd);vertical-align:top}th{font-size:12px;color:var(--mu)}
td.c,th.c{text-align:center}
</style></head><body>
<h1>bulkmail.shop sync</h1>
<div class="mu"><a href="/admin.php">&larr; Back to admin</a></div>
<?php if ($fl): ?><div class="msg <?= $fl[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($fl[1]) ?></div><?php endif; ?>

<div class="card">
  <b>What is automatic</b>
  <ul style="margin:8px 0 0;padding-left:20px">
    <li><b>Stock</b>: always, about once a minute, for every linked product.</li>
    <li><b>Name</b> and <b>description + Possible Domain</b>: only for products ticked below, a few products every couple of minutes.</li>
    <li><b>Price</b>: never changes by itself. You set your own selling price.</li>
  </ul>
  <form method="post" class="row"><?= csrf_field() ?>
    <button class="btn" name="sync_now" value="1">Sync now</button>
    <button class="btn g" name="all_on" value="1" onclick="return confirm('Names and descriptions you wrote yourself will be replaced by the bulkmail.shop ones. Continue?')">Turn on for all</button>
    <button class="btn g" name="all_off" value="1">Turn off for all</button>
  </form>
</div>

<form method="post" class="card"><?= csrf_field() ?>
  <div class="tw"><table><thead><tr><th>Product</th><th class="c">Name</th><th class="c">Description</th><th class="c">Show domain</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $id = (int)$r['id']; ?>
    <tr>
      <td><input type="hidden" name="ids[]" value="<?= $id ?>"><?= e($r['name']) ?><br>
        <small>bulkmail ID <?= (int)$r['supplier_id'] ?> · stock <?= number_format((int)$r['stock']) ?><?= $r['sup_domain'] ? ' · domain: ' . e($r['sup_domain']) : '' ?></small></td>
      <td class="c"><input type="checkbox" name="an[<?= $id ?>]" value="1"<?= (int)$r['sup_auto_name'] ? ' checked' : '' ?>></td>
      <td class="c"><input type="checkbox" name="ad[<?= $id ?>]" value="1"<?= (int)$r['sup_auto_desc'] ? ' checked' : '' ?>></td>
      <td class="c"><input type="checkbox" name="sd[<?= $id ?>]" value="1"<?= (int)($r['sup_show_dom'] ?? 1) ? ' checked' : '' ?>></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="mu">No product is linked to bulkmail.shop yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($rows): ?><div class="row"><button class="btn" name="save_flags" value="1">Save</button></div><?php endif; ?>
</form>
</body></html>
