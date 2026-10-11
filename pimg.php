<?php
// Serves a product image uploaded in Admin > Products (stored in the media table under the key "p<product id>").
require __DIR__ . '/lib.php';
session_write_close();   // many images load at once: never make them wait for each other on the session lock
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT mime, data FROM media WHERE k = ?');
$st->execute(['p' . $id]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit; }
header('Content-Type: ' . $r['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');   // the link changes (?v=) whenever the image changes
header('Pragma: cache');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
echo $r['data'];
