<?php
// Serves the custom icon images uploaded in Admin > Products / Categories. Keys are content hashes, so they can be cached forever.
require __DIR__ . '/lib.php';
session_write_close();
$k = (string)($_GET['k'] ?? '');
if (!preg_match('/^ic_[a-f0-9]{12}$/', $k)) { http_response_code(404); exit; }
$st = db()->prepare('SELECT mime, data FROM media WHERE k = ?');
$st->execute([$k]);
$r = $st->fetch();
if (!$r || !in_array($r['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) { http_response_code(404); exit; }
header_remove('Set-Cookie'); header_remove('Pragma'); header_remove('Expires');
header('Content-Type: ' . $r['mime']);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($r['data']));
echo $r['data'];
