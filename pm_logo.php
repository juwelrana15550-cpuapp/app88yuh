<?php
/* Serves the logo uploaded for a payment method:  /pm_logo.php?id=bkash&v=<version>
 * The version in the URL changes whenever a new logo is uploaded, so browsers can cache it for a year. */
require __DIR__ . '/lib.php';
require_once __DIR__ . '/pay_methods.php';

$id = (string)($_GET['id'] ?? '');
$r = null;
if (preg_match('/^[a-z0-9_]{1,24}$/', $id)) {
    pm_ensure();
    $st = db()->prepare('SELECT logo_mime, logo FROM payment_methods WHERE id = ? AND logo IS NOT NULL');
    $st->execute([$id]);
    $r = $st->fetch();
}
if (!$r) { http_response_code(404); exit; }

if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) session_write_close();
header_remove('Pragma');
header_remove('Expires');
header('Content-Type: ' . $r['logo_mime']);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($r['logo']));
echo $r['logo'];
