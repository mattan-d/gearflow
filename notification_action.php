<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

$me = current_user();
if ($me === null) {
    header('Location: login.php');
    exit;
}

$role = $me['role'] ?? 'student';
$userId = (int)($me['id'] ?? 0);
$pdo = get_db();

$redirect = trim((string)($_POST['redirect'] ?? ''));
// רק נתיב יחסי (מתחיל ב-/ או שם קובץ) – מניעת open redirect
if ($redirect !== '' && strpos($redirect, 'http') === 0) {
    $redirect = '';
}
if ($redirect === '') {
    $redirect = 'admin.php';
}
if (strpos($redirect, '/') !== 0) {
    $redirect = '/' . ltrim($redirect, '/');
}

if ($userId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_action'])) {
    $action = $_POST['notif_action'];
    if ($action === 'delete' && isset($_POST['notif_id'])) {
        $nid = (int)$_POST['notif_id'];
        $stmtDel = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND (user_id = :uid OR (user_id IS NULL AND role = :role))');
        $stmtDel->execute([':id' => $nid, ':uid' => $userId, ':role' => $role]);
    } elseif ($action === 'delete_all') {
        $stmtDelAll = $pdo->prepare('DELETE FROM notifications WHERE user_id = :uid OR (user_id IS NULL AND role = :role)');
        $stmtDelAll->execute([':uid' => $userId, ':role' => $role]);
    }
}

header('Location: ' . $redirect);
exit;
