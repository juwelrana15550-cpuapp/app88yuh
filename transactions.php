<?php require __DIR__ . '/lib.php';
$u = require_login();
$s = db()->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 100'); $s->execute([$u['id']]);
$rows = $s->fetchAll();
user_start('Transactions', $u, 'transactions'); ?>
<div class="card"><h3>Transactions</h3>
<div class="tw"><table><tr><th>Type</th><th>Amount</th><th>Note</th><th>Date</th></tr>
<?php foreach ($rows as $r): $a = (float)$r['amount']; ?>
<tr><td><?= e($r['type']) ?></td><td class="<?= $a >= 0 ? 'pos' : 'neg' ?>"><?= ($a >= 0 ? '+' : '-') . money(abs($a)) ?></td><td><?= e($r['note']) ?></td><td><?= e($r['created_at']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="4">No transactions yet.</td></tr><?php endif; ?></table></div></div>
<?php user_end();
