<?php require __DIR__ . '/lib.php';
require __DIR__ . '/order_fmt.php';
$u = require_login();
$s = db()->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
$s->execute([(int)($_GET['id'] ?? 0), $u['id']]);
$o = $s->fetch();
if (!$o) { flash('err', 'Order not found.'); header('Location: /orders.php'); exit; }

$qty    = max(1, (int)($o['qty'] ?? 1));
$lines  = $o['status'] === 'delivered' ? order_lines($o['delivery']) : [];
$fmts   = order_formats();
$when   = date('F j, Y \a\t H:i', strtotime($o['created_at']));
$unit   = (float)$o['price'] / $qty;
$labels = ['pending' => 'Processing', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
user_start('Order #' . (int)$o['id'], $u, 'orders'); ?>
<nav class="crumb" aria-label="Breadcrumb"><a href="/dashboard.php">Home</a><span>&rsaquo;</span><a href="/orders.php">Orders</a><span>&rsaquo;</span><b>Order #<?= (int)$o['id'] ?></b></nav>
<a class="back" href="/orders.php"><?= icon('back') ?>Back to Orders</a>

<section class="ord-card">
  <header class="ord-top">
    <span class="ord-ico"><?= icon('bag') ?></span>
    <div><h1>Order Details</h1><p>Ordered on <?= e($when) ?></p></div>
  </header>

  <div class="ord-sec">
    <h4 class="cap">Product information</h4>
    <div class="ord-prod">
      <span class="ord-pi"><?= icon('box') ?></span>
      <div class="ord-pt"><b><?= e($o['product_name']) ?></b><small>ID <?= (int)$o['product_id'] ?> &middot; Qty <?= number_format($qty) ?><?= $qty > 1 ? ' &middot; ' . e(money($unit)) . ' each' : '' ?></small></div>
      <span class="badge <?= e($o['status']) ?>"><?= e($labels[$o['status']] ?? $o['status']) ?></span>
    </div>
    <div class="ord-paid"><span>Total Paid</span><b><?= e(money($o['price'])) ?></b></div>
  </div>

  <div class="ord-sec">
    <h4 class="cap">Your item data</h4>
<?php if ($lines): ?>
    <form method="get" action="/download.php" class="dl" id="dlF">
      <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
      <div class="fmt"><label for="fmt">Format:</label>
        <select name="fmt" id="fmt"><?php foreach ($fmts as $k => $f): ?><option value="<?= e($k) ?>"><?= e($f[0]) ?></option><?php endforeach; ?></select></div>
      <div class="dl-b">
        <button class="btn ghost"><?= icon('download') ?><span>Download</span></button>
        <button type="button" class="btn ghost" id="cpy"><?= icon('copy') ?><span>Copy</span></button>
      </div>
    </form>
    <div class="dv-h"><span><?= number_format(count($lines)) ?> item<?= count($lines) === 1 ? '' : 's' ?></span><?php if (count($lines) > 6): ?><small>First 6 shown &middot; Download/Copy for all</small><?php endif; ?></div>
    <pre class="dv mono" id="prev"><?= e(implode("\n", array_slice($lines, 0, 6))) ?></pre>
    <textarea id="rawData" class="sr" readonly tabindex="-1" aria-hidden="true"><?= e(implode("\n", $lines)) ?></textarea>
<?php elseif ($o['status'] === 'pending'): ?>
    <div class="ord-note wait"><?= icon('history') ?><div><b>Your order is being processed</b><p>Your items will appear here as soon as they are delivered. You can safely leave this page and come back later.</p></div></div>
<?php elseif ($o['status'] === 'cancelled'): ?>
    <div class="ord-note off"><?= icon('x') ?><div><b>This order was cancelled</b><p><?= e(money($o['price'])) ?> has been refunded to your wallet.</p></div></div>
<?php else: ?>
    <div class="ord-note wait"><?= icon('history') ?><div><b>No item data yet</b><p>Please contact support if you were expecting data here.</p></div></div>
<?php endif; ?>
  </div>
</section>
<?php if ($lines): ?>
<script>
document.getElementById('cpy').addEventListener('click',function(){
  var b=this,t=document.getElementById('rawData'),l=b.querySelector('span'),o=l.textContent;
  function done(ok){ l.textContent=ok?'Copied!':'Copy failed'; b.classList.toggle('done',ok); setTimeout(function(){ l.textContent=o; b.classList.remove('done'); },1600); }
  function legacy(){ try{ t.select(); t.setSelectionRange(0,t.value.length); done(document.execCommand('copy')); }catch(e){ done(false); } }
  if(navigator.clipboard&&window.isSecureContext){ navigator.clipboard.writeText(t.value).then(function(){done(true)},legacy); } else legacy();
});
</script>
<?php endif;
user_end();
