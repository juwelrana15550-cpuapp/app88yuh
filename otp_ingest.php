<?php
// Receives OTP e-mails (e.g. from a Google Apps Script) and stores them for the "Read OTP" page.
//   POST /otp_ingest.php   fields: token, email, and either `code` or `body` (the code is picked out of the text)
// Set the OTP_INGEST_TOKEN environment variable to a long random secret and send the same value as `token`
// (or as an `X-Token` header).
require __DIR__ . '/lib.php';
session_write_close();
header('Content-Type: application/json');
$secret = getenv('OTP_INGEST_TOKEN');
if (!$secret) { http_response_code(503); exit('{"ok":false,"error":"OTP_INGEST_TOKEN is not set"}'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"ok":false}'); }
$tok = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ''));
if (!hash_equals($secret, $tok)) { http_response_code(403); exit('{"ok":false,"error":"bad token"}'); }
$email = strtolower(trim($_POST['email'] ?? ''));
$code  = trim($_POST['code'] ?? '');
if ($code === '' && preg_match('/(?<![\d])(\d{4,8})(?![\d])/', (string)($_POST['body'] ?? ''), $m)) $code = $m[1];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '' || mb_strlen($code) > 32) { http_response_code(422); exit('{"ok":false,"error":"email and code required"}'); }
$pdo = db();
$pdo->prepare('INSERT INTO otp_codes (email, code) VALUES (?,?)')->execute([$email, $code]);
if (random_int(1, 20) === 1) $pdo->exec('DELETE FROM otp_codes WHERE received_at < NOW() - INTERVAL 2 DAY');
echo '{"ok":true}';
