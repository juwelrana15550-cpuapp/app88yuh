<?php
// Read-only JSON API. Auth: "Authorization: Bearer <key>" (or "X-API-Key: <key>").
//   GET /api.php?action=balance | products | orders
require __DIR__ . '/lib.php';
session_write_close();
header('Content-Type: application/json');
function out(int $code, array $d): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$key = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) $key = $m[1];
elseif (!empty($_SERVER['HTTP_X_API_KEY'])) $key = trim($_SERVER['HTTP_X_API_KEY']);
if ($key === '') out(401, ['ok' => false, 'error' => 'Missing API key']);

$pdo = db();
$s = $pdo->prepare('SELECT k.id AS kid, u.id, u.email, u.coins FROM api_keys k JOIN users u ON u.id = k.user_id WHERE k.key_hash = ?');
$s->execute([hash('sha256', $key)]);
$u = $s->fetch();
if (!$u) out(401, ['ok' => false, 'error' => 'Invalid API key']);
$pdo->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?')->execute([$u['kid']]);

switch ($_GET['action'] ?? '') {
    case 'balance':
        out(200, ['ok' => true, 'email' => $u['email'], 'balance' => (float)$u['coins'], 'currency' => 'BDT']);
    case 'products':
        $r = $pdo->query('SELECT id, name, description, price FROM products WHERE active = 1 ORDER BY id DESC')->fetchAll();
        out(200, ['ok' => true, 'products' => $r]);
    case 'orders':
        $q = $pdo->prepare('SELECT id, product_name, price, status, delivery, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $q->execute([$u['id']]);
        out(200, ['ok' => true, 'orders' => $q->fetchAll()]);
    default:
        out(400, ['ok' => false, 'error' => 'Unknown action. Use balance, products or orders.']);
}
