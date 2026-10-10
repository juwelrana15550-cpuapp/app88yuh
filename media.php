<?php
session_cache_limiter('');
require __DIR__ . '/lib.php';
$k = $_GET['k'] ?? '';
if (!in_array($k, ['logo', 'banner'], true)) { http_response_code(404); exit; }
$st = db()->prepare('SELECT mime, data FROM media WHERE k = ?');
$st->execute([$k]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit; }
header('Content-Type: ' . $r['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
echo $r['data'];
