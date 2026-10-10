<?php
// Serves the logo / banner images that the admin uploads (stored in the `media` table).
require __DIR__ . '/lib.php';
session_write_close();
$k = $_GET['k'] ?? '';
if (!in_array($k, ['logo', 'banner'], true)) { http_response_code(404); exit; }
$s = db()->prepare('SELECT mime, data FROM media WHERE k = ?');
$s->execute([$k]);
$m = $s->fetch();
if (!$m) { http_response_code(404); exit; }
header_remove('Pragma'); header_remove('Expires');
header('Content-Type: ' . $m['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable'); // URL carries ?v=<timestamp>, so changes bust the cache
echo $m['data'];
