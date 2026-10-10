<?php require __DIR__ . '/lib.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); $_SESSION = []; session_destroy(); }
header('Location: /'); exit;
