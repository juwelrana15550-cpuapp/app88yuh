<?php
/**
 * Webhook for the "SMS Forwarder" app.   URL in the app:   https://YOUR-SITE/sms.php?key=YOUR_SECRET
 * Set the secret in Railway > Variables:  SMS_SECRET=<long random text>
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/sms_inbox.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$secret = (string)getenv('SMS_SECRET');
if ($secret === '') { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'SMS_SECRET is not set on the server']); exit; }
$given = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_SMS_KEY'] ?? ''));
if (!hash_equals($secret, $given)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !isset($_GET['test'])) { echo json_encode(['ok' => true, 'msg' => 'SMS endpoint is working. The app must send a POST.']); exit; }

try {
    $pdo = db();
    sms_schema($pdo);
    [$sender, $contact, $body, $raw] = sms_read_request();
    if ($body === '' && $sender === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
    [$id, $isNew, $p] = sms_store($pdo, $sender, $contact, $body, $raw);
    $credited = 0;
    if ($isNew && $p['kind'] === 'in') {
        // the user may have sent the request BEFORE this SMS arrived: credit it now
        $credited = sms_match_pending($pdo, $id, (float)(getenv('REFERRAL_BONUS') ?: 10));
    }
    echo json_encode(['ok' => true, 'id' => $id, 'kind' => $p['kind'], 'credited_deposit' => $credited]);
} catch (Throwable $ex) {
    error_log('[sms] ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error']);
}
