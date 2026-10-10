<?php require __DIR__ . '/lib.php';
$u = require_login();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['create'])) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE user_id = ?'); $c->execute([$u['id']]);
        if ((int)$c->fetchColumn() >= 5) flash('err', 'You can have at most 5 API keys. Delete one first.');
        else {
            $key = 'ak_' . bin2hex(random_bytes(20));
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 40) ?: 'My key';
            $pdo->prepare('INSERT INTO api_keys (user_id, name, key_hash, key_prefix) VALUES (?,?,?,?)')
                ->execute([$u['id'], $name, hash('sha256', $key), substr($key, 0, 10)]);
            $_SESSION['new_key'] = $key; // shown once, then gone
        }
    } elseif (isset($_POST['delete'])) {
        $pdo->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')->execute([(int)$_POST['delete'], $u['id']]);
        flash('ok', 'API key deleted.');
    }
    header('Location: /api_keys.php'); exit;
}
$newKey = $_SESSION['new_key'] ?? null; unset($_SESSION['new_key']);
$s = $pdo->prepare('SELECT * FROM api_keys WHERE user_id = ? ORDER BY id DESC'); $s->execute([$u['id']]);
$keys = $s->fetchAll();
$scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0) ? 'https' : 'http';
$host = preg_match('/^[A-Za-z0-9.\-:]+$/', $_SERVER['HTTP_HOST'] ?? '') ? $_SERVER['HTTP_HOST'] : 'your-site';
user_start('API Keys', $u, 'apikeys'); ?>
<?php if ($newKey): ?>
<div class="card"><h3>Your new API key</h3>
<div class="ok">Copy it now — for security it will not be shown again.</div>
<div class="row"><input type="text" id="nk" readonly value="<?= e($newKey) ?>"><button type="button" class="btn sm" data-copy="#nk">Copy</button></div></div>
<?php endif; ?>
<div class="card"><h3>Create API key</h3>
<form method="post"><?= csrf_field() ?>
<label>Name</label><input type="text" name="name" maxlength="40" placeholder="e.g. My bot">
<button class="btn" name="create" value="1">Generate key</button></form></div>
<div class="card"><h3>Your keys</h3>
<div class="tw"><table><tr><th>Name</th><th>Key</th><th>Created</th><th>Last used</th><th></th></tr>
<?php foreach ($keys as $k): ?>
<tr><td><?= e($k['name']) ?></td><td class="mono"><?= e($k['key_prefix']) ?>…</td><td><?= e($k['created_at']) ?></td><td><?= e($k['last_used_at'] ?? 'never') ?></td>
<td><form method="post"><?= csrf_field() ?><button class="btn sm red" name="delete" value="<?= (int)$k['id'] ?>" onclick="return confirm('Delete this key?')">Delete</button></form></td></tr>
<?php endforeach; if (!$keys): ?><tr><td colspan="5">No API keys yet.</td></tr><?php endif; ?></table></div></div>
<div class="card"><h3>Using the API</h3>
<small>Send your key in the <b>Authorization</b> header. Endpoints: <b>balance</b>, <b>products</b>, <b>orders</b>.</small>
<div class="code">curl -H "Authorization: Bearer YOUR_KEY" \
  "<?= e($scheme . '://' . $host) ?>/api.php?action=balance"</div></div>
<?php user_end();
