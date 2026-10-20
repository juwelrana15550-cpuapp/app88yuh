<?php require __DIR__ . '/lib.php';
$u = require_login();
$s = db()->prepare('SELECT o.*, p.icon AS p_icon, c.icon AS c_icon FROM orders o LEFT JOIN products p ON p.id = o.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 50'); $s->execute([$u['id']]);
$rows = $s->fetchAll();
$labels = ['pending' => 'Processing', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
$cnt = ['pending' => 0, 'delivered' => 0, 'cancelled' => 0];
foreach ($rows as $r) { if (isset($cnt[$r['status']])) $cnt[$r['status']]++; }
user_start('My Orders', $u, 'orders'); ?>
<div class="pg-h"><div><h2>My Orders</h2><small>Your latest purchases in one place</small></div></div>

<?php if ($rows): ?>
<div class="ostats">
  <div class="ost"><small>Total</small><b><?= number_format(count($rows)) ?></b></div>
  <div class="ost p"><small>Processing</small><b><?= number_format($cnt['pending']) ?></b></div>
  <div class="ost d"><small>Delivered</small><b><?= number_format($cnt['delivered']) ?></b></div>
</div>
<div class="otabs" id="otabs">
  <button type="button" class="on" data-f="all">All <b><?= count($rows) ?></b></button>
  <button type="button" data-f="pending">Processing <b><?= $cnt['pending'] ?></b></button>
  <button type="button" data-f="delivered">Delivered <b><?= $cnt['delivered'] ?></b></button>
  <button type="button" data-f="cancelled">Cancelled <b><?= $cnt['cancelled'] ?></b></button>
</div>
<div class="olst" id="olst">
<?php foreach ($rows as $r): $q = max(1, (int)($r['qty'] ?? 1)); ?>
  <a class="oc" data-st="<?= e($r['status']) ?>" href="/order.php?id=<?= (int)$r['id'] ?>">
    <span class="oc-ic"><?= ord_ico_html((string)($r['p_icon'] ?? ''), (string)($r['c_icon'] ?? ''), (string)$r['product_name']) ?></span>
    <span class="oc-m">
      <b><?= e($r['product_name']) ?><?= $q > 1 ? ' <em>&times; ' . number_format($q) . '</em>' : '' ?></b>
      <small>#<?= (int)$r['id'] ?> &middot; <?= e(date('M j, Y', strtotime($r['created_at']))) ?> &middot; <?= e(date('H:i', strtotime($r['created_at']))) ?></small>
    </span>
    <span class="oc-r">
      <b><?= e(money($r['price'])) ?></b>
      <span class="badge <?= e($r['status']) ?>"><?= e($labels[$r['status']] ?? $r['status']) ?></span>
    </span>
  </a>
<?php endforeach; ?>
</div>
<div class="oempty" id="oflt" hidden><?= icon('box') ?><p>No orders in this status.</p></div>
<script>
(function(){var t=document.getElementById('otabs'),l=document.getElementById('olst'),m=document.getElementById('oflt');
t.addEventListener('click',function(e){var b=e.target.closest('button[data-f]');if(!b)return;
  [].forEach.call(t.children,function(x){x.classList.toggle('on',x===b);});
  var f=b.getAttribute('data-f'),n=0;
  [].forEach.call(l.children,function(c){var ok=f==='all'||c.getAttribute('data-st')===f;c.style.display=ok?'':'none';if(ok)n++;});
  m.hidden=n>0;});})();
</script>
<?php else: ?>
<div class="oempty"><?= icon('cart') ?><h3>No orders yet</h3><p>When you buy something, it will show up here.</p><a class="btn" href="/dashboard.php">Browse products</a></div>
<?php endif; ?>
<?php user_end();
