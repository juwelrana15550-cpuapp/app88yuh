<?php require __DIR__ . '/lib.php';
if (isset($_GET['ajax']) && !current_user()) { http_response_code(401); exit; }   // category switcher: never answer with the login page
$u = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $oid = 0;
    try {
        $qty = max(1, min(10000, (int)($_POST['qty'] ?? 1)));
        $oid = with_tx($pdo, function (PDO $pdo) use ($u, $qty) {
            $st = $pdo->prepare('SELECT coins FROM users WHERE id = ? FOR UPDATE');
            $st->execute([$u['id']]); $coins = (float)$st->fetchColumn();
            $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1 FOR UPDATE');
            $st->execute([(int)($_POST['product_id'] ?? 0)]); $p = $st->fetch();
            if (!$p) throw new RuntimeException('Product not found.');
            $sup   = (int)($p['supplier_id'] ?? 0);   // linked to a bulkmail.shop product: bought there after payment
            if ($sup && !bm_connected()) throw new RuntimeException('This product is temporarily unavailable.');
            $auto  = !$sup && !empty($p['auto_delivery']);
            $items = [];
            if ($auto) {
                // Instant delivery: take the oldest unsold uploaded items. The product row is locked above, so two buyers can never get the same item.
                $it = $pdo->prepare('SELECT id, line FROM stock_items WHERE product_id = ? AND order_id IS NULL ORDER BY id LIMIT ' . (int)$qty . ' FOR UPDATE');
                $it->execute([$p['id']]); $items = $it->fetchAll();
                if (!$items) throw new RuntimeException('Sorry, this product is out of stock.');
                if (count($items) < $qty) throw new RuntimeException('Only ' . number_format(count($items)) . ' available right now. Please lower the quantity.');
            } elseif ($p['stock'] !== null) {
                $have = (int)$p['stock'];
                if ($have < 1) throw new RuntimeException('Sorry, this product is out of stock.');
                if ($have < $qty) throw new RuntimeException('Only ' . number_format($have) . ' available right now. Please lower the quantity.');
            }
            $pct = discount_pct($p, user_disc_map((int)$u['id']));   // product discount or this user's discount, whichever is bigger
            $cents = final_cents((int)round((float)$p['price'] * 100), $pct) * $qty;   // work in cents: no float drift
            if ((int)round($coins * 100) < $cents) throw new RuntimeException('Not enough balance. Please add funds first.');
            $total = number_format($cents / 100, 2, '.', '');
            $pdo->prepare('UPDATE users SET coins = coins - ? WHERE id = ?')->execute([$total, $u['id']]);
            if ($auto) {
                $pdo->prepare("INSERT INTO orders (user_id, product_id, product_name, price, qty, status, delivery) VALUES (?,?,?,?,?,'delivered',?)")
                    ->execute([$u['id'], $p['id'], $p['name'], $total, $qty, implode("\n", array_column($items, 'line'))]);
            } else {
                // Supplier products keep their stock number from bulkmail.shop (refreshed after the purchase), so nothing is subtracted here.
                if (!$sup && $p['stock'] !== null) $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$qty, $p['id']]);
                $pdo->prepare('INSERT INTO orders (user_id, product_id, product_name, price, qty) VALUES (?,?,?,?,?)')
                    ->execute([$u['id'], $p['id'], $p['name'], $total, $qty]);
            }
            $id = (int)$pdo->lastInsertId();   // read before add_tx inserts another row
            if ($auto) {
                $pdo->exec('UPDATE stock_items SET order_id = ' . (int)$id . ' WHERE id IN (' . implode(',', array_map('intval', array_column($items, 'id'))) . ')');
                stock_sync($pdo, (int)$p['id']);
                $GLOBALS['__instant'] = true;
            }
            add_tx($pdo, (int)$u['id'], 'purchase', -(float)$total, 'Order #' . $id . ': ' . $p['name'] . ($qty > 1 ? ' x' . $qty : '') . ($pct > 0 ? ' (' . pct_fmt($pct) . '% off)' : ''));
            if ($sup) $GLOBALS['__sup'] = true;
            return $id;
        });
        if (!empty($GLOBALS['__sup'])) {
            // The payment is saved. Now buy the items at bulkmail.shop (outside the database transaction, so no locks are held while waiting for the network).
            $res = bm_fulfil($pdo, $oid);
            if ($res['status'] === 'refunded') { flash('err', $res['msg']); $oid = 0; }
            else flash('ok', $res['msg']);
        } else {
            flash('ok', !empty($GLOBALS['__instant']) ? 'Purchase successful! Your items are ready below.' : 'Purchase successful! Your order is below.');
        }
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('BUY ERROR: ' . $ex->getMessage());
        flash('err', 'Something went wrong. Please try again.');
    }
    if ($oid) { header('Location: /order.php?id=' . $oid); exit; }   // redirect: refresh must not buy again
    $back = array_filter(['c' => (int)($_POST['c'] ?? 0), 'q' => mb_substr(trim($_POST['q'] ?? ''), 0, 80)], fn($v) => $v !== 0 && $v !== '');
    header('Location: /dashboard.php' . ($back ? '?' . http_build_query($back) : '')); exit; // redirect: refresh must not buy again
}

bm_sync_stock($pdo);   // linked bulkmail.shop products: refresh live stock (at most once a minute)

$c = (int)($_GET['c'] ?? 0);
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 80);

$cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.active = 1) AS n
                     FROM categories c WHERE c.active = 1 ORDER BY c.sort_order, c.id")->fetchAll();
$total = (int)$pdo->query("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id
                           WHERE p.active = 1 AND (p.category_id IS NULL OR c.active = 1)")->fetchColumn();

$where = ['p.active = 1', '(p.category_id IS NULL OR c.active = 1)']; $args = [];
if ($c) { $where[] = 'p.category_id = ?'; $args[] = $c; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ? OR p.id = ?)';
    array_push($args, $like, $like, $like, ctype_digit($q) ? (int)$q : 0);
}
$s = $pdo->prepare('SELECT p.* FROM products p LEFT JOIN categories c ON c.id = p.category_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY (p.category_id IS NULL), c.sort_order, c.id, (p.sort_order = 0), p.sort_order, p.popular DESC, p.id DESC LIMIT 300');
$s->execute($args);
$rows = $s->fetchAll();

if (isset($_GET['ajax'])) { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); catalog_list_html($cats, $rows, $c, $q); exit; }

user_start('Home', $u, 'dashboard'); ?>
<div class="card wallet bbar"><div><small>Available balance</small><b><?= money($u['coins']) ?></b></div><a class="btn sm" href="/deposits.php">+ Add Funds</a></div>
<?php catalog_html($cats, $rows, $c, $q, $total, (float)$u['coins']); ?>
<script>
/* Live refresh: stock numbers and "possible domain" follow the supplier without reloading the page (every 20 s, only while the tab is open and no Buy window is showing). */
(function(){
  var res=document.getElementById('cat-res'); if(!res) return;
  setInterval(function(){
    if(document.hidden||document.querySelector('.bx.open,.mdl.show,body.modal')||res.classList.contains('busy')) return;
    var s=location.search.replace(/^\?/,'').split('&').filter(function(x){return x&&x.indexOf('ajax=')!==0&&x.indexOf('cur=')!==0;});
    fetch('/dashboard.php?'+s.concat('ajax=1').join('&'),{credentials:'same-origin',headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.ok?r.text():null;}).then(function(h){
        if(h&&h.trim()!==res.innerHTML.trim()&&!document.querySelector('.bx.open')){ var y=window.pageYOffset; res.innerHTML=h; window.scrollTo(0,y); }
      }).catch(function(){});
  },20000);
})();
</script>
<?php user_end();
