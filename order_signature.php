<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();
$me  = current_user();

if ($me === null) {
    header('Location: login.php');
    exit;
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo 'Bad request.';
    exit;
}

$stmt = $pdo->prepare('SELECT id, creator_username FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$order) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$role = (string)($me['role'] ?? 'student');
$username = (string)($me['username'] ?? '');
$canView = ($role === 'admin' || $role === 'warehouse_manager' || ($username !== '' && $username === (string)($order['creator_username'] ?? '')));
if (!$canView) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$path = __DIR__ . '/signatures/order_' . $orderId . '.png';
if (!is_file($path)) {
    http_response_code(404);
    echo 'Signature not found.';
    exit;
}

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="order_' . $orderId . '_signature.png"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);

