<?php require __DIR__ . '/lib.php';
$u = require_login();

$st = db()->prepare('SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount END), 0) AS tin, COALESCE(SUM(CASE WHEN amount < 0 THEN -amount END), 0) AS tout, COUNT(*) AS n FROM transactions WHERE user_id = ?');
$st->execute([$u['id']]); $sum = $st->fetch() ?: ['tin' => 0, 'tout' => 0, 'n' => 0];
$total = (int)$sum['n'];

$per  = 25;
$pages = max(1, (int)ceil($total / $per));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$q = db()->prepare('SELECT id, type, amount, note, created_at FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$q->execute([$u['id']]);
$rows = $q->fetchAll();

$meta = [
    'deposit'  => ['Deposit',    'coins'],
    'refund'   => ['Refund',     'swap'],
    'bonus'    => ['Bonus',      'users'],
    'adjust'   => ['Adjustment', 'swap'],
    'purchase' => ['Purchase',   'cart'],
    'order'    => ['Purchase',   'cart'],
    'buy'      => ['Purchase',   'cart'],
];
$groups = [];
$today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day'));
foreach ($rows as $r) {
    $ts = strtotime((string)$r['created_at']); $day = date('Y-m-d', $ts);
    $lab = $day === $today ? 'Today' : ($day === $yest ? 'Yesterday' : date('F j, Y', $ts));
    $groups[$lab][] = $r;
}
user_start('Transactions', $u, 'transactions'); ?>
<div class="pg-h"><div><h2>Transactions</h2><small>Every deposit, purchase and refund on your wallet</small></div></div>

<section class="wt-hero">
  <div class="wt-bal"><span>Wallet balance</span><b><?= e(money($u['coins'])) ?></b></div>
  <a class="wt-add" href="/deposits.php"><?= icon('plus') ?>Add funds</a>
</section>

<div class="wt-stats">
  <div class="wt-s in"><small>Money in</small><b>+<?= e(money($sum['tin'])) ?></b></div>
  <div class="wt-s out"><small>Money out</small><b>&minus;<?= e(money($sum['tout'])) ?></b></div>
  <div class="wt-s"><small>Transactions</small><b><?= number_format($total) ?></b></div>
</div>

<?php if ($rows): ?>
<div class="otabs" id="wtabs">
  <button type="button" class="on" data-f="all">All</button>
  <button type="button" data-f="in">Money in</button>
  <button type="button" data-f="out">Money out</button>
</div>

<div id="wlist">
<?php foreach ($groups as $lab => $list): ?>
  <section class="wt-grp">
    <h4 class="wt-day"><?= e($lab) ?></h4>
    <div class="wt-lst">
<?php foreach ($list as $r):
    $amt = (float)$r['amount']; $in = $amt >= 0;
    $m = $meta[strtolower((string)$r['type'])] ?? [ucfirst(str_replace('_', ' ', (string)$r['type'])), $in ? 'coins' : 'cart'];
    $note = trim((string)$r['note']);
    $href = preg_match('/order\s*#(\d+)/i', $note, $mm) ? '/order.php?id=' . (int)$mm[1] : '';
    $tag = $href ? 'a' : 'div'; ?>
      <<?= $tag ?> class="wt-c <?= $in ? 'in' : 'out' ?>" data-d="<?= $in ? 'in' : 'out' ?>"<?= $href ? ' href="' . e($href) . '"' : '' ?>>
        <span class="wt-i"><?= icon($m[1]) ?></span>
        <span class="wt-m">
          <b><?= e($m[0]) ?></b>
          <small><?= $note !== '' ? e($note) : '&nbsp;' ?></small>
          <em>#<?= (int)$r['id'] ?> &middot; <?= e(date('H:i', strtotime((string)$r['created_at']))) ?></em>
        </span>
        <span class="wt-a"><b><?= $in ? '+' : '&minus;' ?><?= e(money(abs($amt))) ?></b><span class="wt-k"><?= $in ? 'Credited' : 'Debited' ?></span></span>
      </<?= $tag ?>>
<?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
<div class="oempty" id="wnone" hidden><?= icon('history') ?><p>Nothing in this view.</p></div>

<?php if ($pages > 1): ?>
<nav class="wt-pg" aria-label="Pages">
  <?php if ($page > 1): ?><a href="/transactions.php?page=<?= $page - 1 ?>">&lsaquo; Newer</a><?php else: ?><span>&lsaquo; Newer</span><?php endif; ?>
  <b>Page <?= $page ?> of <?= $pages ?></b>
  <?php if ($page < $pages): ?><a href="/transactions.php?page=<?= $page + 1 ?>">Older &rsaquo;</a><?php else: ?><span>Older &rsaquo;</span><?php endif; ?>
</nav>
<?php endif; ?>

<script>
(function(){var t=document.getElementById('wtabs'),l=document.getElementById('wlist'),m=document.getElementById('wnone');
t.addEventListener('click',function(e){var b=e.target.closest('button[data-f]');if(!b)return;
  [].forEach.call(t.children,function(x){x.classList.toggle('on',x===b);});
  var f=b.getAttribute('data-f'),any=0;
  [].forEach.call(l.querySelectorAll('.wt-grp'),function(g){var n=0;
    [].forEach.call(g.querySelectorAll('.wt-c'),function(c){var ok=f==='all'||c.getAttribute('data-d')===f;c.style.display=ok?'':'none';if(ok)n++;});
    g.style.display=n?'':'none';any+=n;});
  m.hidden=any>0;});})();
</script>
<?php else: ?>
<div class="oempty"><?= icon('history') ?><h3>No transactions yet</h3><p>Add funds or place an order and your activity will show up here.</p><a class="btn" href="/deposits.php">Add funds</a></div>
<?php endif; ?>
<?php user_end();
