<?php
/* Serves an icon image uploaded from Admin > Products / Categories (stored in the media table). */
require __DIR__ . '/lib.php';
session_write_close();                        // no session lock / cookie needed for an image
$k = (string)($_GET['k'] ?? '');
if (!preg_match('/^ic[a-f0-9]{8}$/', $k)) { http_response_code(404); exit; }
$st = db()->prepare('SELECT mime, data FROM media WHERE k = ?');
$st->execute([$k]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit; }
header_remove('Set-Cookie'); header_remove('Pragma'); header_remove('Expires');
header('Content-Type: ' . $r['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');   // every upload gets a new id, so it can be cached forever
echo $r['data'];
