<?php require __DIR__ . '/lib.php';
if (isset($_GET['ajax']) && !current_user()) { http_response_code(401); exit; }   // never answer the poller with the login page
$u = require_login();
$pdo = db();

/** Latest purchases + approved deposits (emails are masked: this feed is visible to every member). */
function live_data(PDO $pdo): array {
    $buy = [];
    $st = $pdo->query("SELECT o.id, o.product_name, o.qty, o.price, o.status, p.icon AS p_icon, c.icon AS c_icon, u.email,
                              TIMESTAMPDIFF(SECOND, o.created_at, NOW()) AS secs
                       FROM orders o JOIN users u ON u.id = o.user_id
                       LEFT JOIN products p ON p.id = o.product_id LEFT JOIN categories c ON c.id = p.category_id
                       WHERE o.status <> 'cancelled' ORDER BY o.id DESC LIMIT 30");
    foreach ($st as $r) {
        $iv = product_icon_value(['icon' => (string)$r['p_icon'], 'name' => $r['product_name']], ['icon' => (string)$r['c_icon']]);
        $buy[] = [
            'id'   => (int)$r['id'],
            'who'  => mask_email($r['email']),
            'what' => $r['product_name'],
            'qty'  => (int)$r['qty'],
            'amt'  => money($r['price']),
            'secs' => max(0, (int)$r['secs']),
            'ico'  => $iv !== '' ? ico_html($iv) : icon('cart'),
        ];
    }
    $dep = [];
    $st = $pdo->query("SELECT d.id, d.amount, u.email, TIMESTAMPDIFF(SECOND, d.created_at, NOW()) AS secs
                       FROM deposits d JOIN users u ON u.id = d.user_id
                       WHERE d.status = 'approved' ORDER BY d.id DESC LIMIT 30");
    foreach ($st as $r) {
        $dep[] = ['id' => (int)$r['id'], 'who' => mask_email($r['email']), 'amt' => money($r['amount']), 'secs' => max(0, (int)$r['secs'])];
    }
    return [
        'buy' => $buy, 'dep' => $dep,
    ];
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(live_data($pdo), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}

$init = live_data($pdo);
user_start('Live View', $u, 'live'); ?>

<section class="lv-hero">
  <div class="lv-hero-t">
    <span class="lv-pill"><i class="lv-dot"></i>LIVE</span>
    <h1>Live View</h1>
    <p>Real-time purchases and deposits from our community.</p>
  </div>
</section>

<div class="lv-tabs" role="tablist" aria-label="Live feeds">
  <button type="button" class="on" role="tab" data-p="buy" aria-selected="true"><?= icon('cart') ?>Live Purchase</button>
  <button type="button" role="tab" data-p="dep" aria-selected="false"><?= icon('coins') ?>Live Deposit</button>
</div>

<div class="lv-grid" data-show="buy">
  <section class="lv-card lv-buy" id="p-buy">
    <header class="lv-ch"><span class="lv-ci buy"><?= icon('cart') ?></span><div><b>Live Purchase</b><small>Latest orders placed</small></div><span class="lv-tag"><i class="lv-dot"></i>Updating</span></header>
    <div class="lv-list" id="l-buy" aria-live="polite"></div>
  </section>
  <section class="lv-card lv-dep" id="p-dep">
    <header class="lv-ch"><span class="lv-ci dep"><?= icon('coins') ?></span><div><b>Live Deposit</b><small>Latest approved deposits</small></div><span class="lv-tag"><i class="lv-dot"></i>Updating</span></header>
    <div class="lv-list" id="l-dep" aria-live="polite"></div>
  </section>
</div>

<script>
(function(){
  var data=<?= json_encode($init, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  var seen={buy:null,dep:null}, born=Date.now(), stamp={buy:{},dep:{}};
  var cols=['#4f46e5','#7c3aed','#2563eb','#0891b2','#059669','#d97706','#db2777','#dc2626'];
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
  function ago(s){
    if(s<10)return 'Just now'; if(s<60)return s+'s ago';
    var m=Math.floor(s/60); if(m<60)return m+'m ago';
    var h=Math.floor(m/60); if(h<24)return h+'h ago';
    var d=Math.floor(h/24); return d+'d ago';
  }
  function col(t){var h=0;for(var i=0;i<t.length;i++)h=(h*31+t.charCodeAt(i))>>>0;return cols[h%cols.length];}
  function row(k,r,isNew){
    var secs=r.secs+Math.floor((Date.now()-born)/1000);
    var av;
    if(k==='buy') av='<span class="lv-av ic">'+r.ico+'</span>';
    else av='<span class="lv-av" style="background:'+col(r.who)+'">'+esc(r.who.charAt(0).toUpperCase())+'</span>';
    var main=k==='buy'
      ? '<b><span class="n">'+esc(r.what)+'</span>'+(r.qty>1?'<em>&times;'+r.qty+'</em>':'')+'</b><small>'+esc(r.who)+'</small>'
      : '<b>'+esc(r.who)+'</b><small>Deposit approved</small>';
    var amt=k==='buy'?'<span class="lv-amt out">'+esc(r.amt)+'</span>':'<span class="lv-amt in">+'+esc(r.amt)+'</span>';
    return '<div class="lv-row'+(isNew?' new':'')+'" data-id="'+r.id+'" data-s="'+r.secs+'">'+av+'<div class="lv-m">'+main+'</div><div class="lv-r">'+amt+'<time>'+ago(secs)+'</time></div></div>';
  }
  function render(k,list){
    var box=document.getElementById('l-'+k);
    if(!list.length){box.innerHTML='<div class="lv-empty"><span>'+(k==='buy'?'🛒':'💰')+'</span><p>No activity yet. It will show up here instantly.</p></div>';seen[k]={};return;}
    var first=seen[k]===null, was=seen[k]||{}, now={}, html='';
    list.forEach(function(r){var isNew=!first&&!was[r.id];now[r.id]=1;html+=row(k,r,isNew);});
    box.innerHTML=html; seen[k]=now;
  }
  function apply(d){born=Date.now();render('buy',d.buy);render('dep',d.dep);}
  function tick(){
    document.querySelectorAll('.lv-row').forEach(function(r){
      var s=(+r.dataset.s)+Math.floor((Date.now()-born)/1000);
      r.querySelector('time').textContent=ago(s);
    });
  }
  var busy=false;
  function poll(){
    if(busy||document.hidden)return; busy=true;
    fetch('/live.php?ajax=1',{credentials:'same-origin',cache:'no-store'}).then(function(r){
      if(r.status===401){location.href='/login.php';return null;} return r.ok?r.json():null;
    }).then(function(d){if(d)apply(d);}).catch(function(){}).then(function(){busy=false;});
  }
  apply(data);
  setInterval(poll,6000); setInterval(tick,5000);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});
  var grid=document.querySelector('.lv-grid');
  document.querySelectorAll('.lv-tabs button').forEach(function(b){b.addEventListener('click',function(){
    document.querySelectorAll('.lv-tabs button').forEach(function(x){x.classList.toggle('on',x===b);x.setAttribute('aria-selected',x===b);});
    grid.dataset.show=b.dataset.p;
  });});
})();
</script>
<?php user_end();
