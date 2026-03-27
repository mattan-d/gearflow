<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin_or_warehouse();

$me = current_user();
$role = (string)($me['role'] ?? 'student');
$myId = (int)($me['id'] ?? 0);
$myWarehouse = trim((string)($me['warehouse'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_orders.php');
    exit;
}

$redirect = trim((string)($_POST['redirect'] ?? 'admin_orders.php'));
if ($redirect === '' || str_starts_with($redirect, 'http://') || str_starts_with($redirect, 'https://')) {
    $redirect = 'admin_orders.php';
}

$toUserId = (int)($_POST['to_user_id'] ?? 0);
$subject = trim((string)($_POST['subject'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));

if ($toUserId <= 0 || $subject === '' || $body === '') {
    $_SESSION['header_mail_error'] = 'יש למלא נמען, נושא ותוכן לפני שליחה.';
    header('Location: ' . $redirect);
    exit;
}

$pdo = get_db();

try {
    $stmtUser = $pdo->prepare(
        'SELECT id, username, first_name, last_name, role, warehouse, email, is_active, allow_emails
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmtUser->execute([':id' => $toUserId]);
    $target = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $target = null;
}

if (!$target || (int)($target['is_active'] ?? 0) !== 1) {
    $_SESSION['header_mail_error'] = 'הנמען שנבחר אינו זמין.';
    header('Location: ' . $redirect);
    exit;
}

$allowEmails = (int)($target['allow_emails'] ?? 0) === 1;
if (!$allowEmails) {
    $_SESSION['header_mail_error'] = 'לנמען אין הסכמה לקבלת מיילים.';
    header('Location: ' . $redirect);
    exit;
}

$targetEmail = trim((string)($target['email'] ?? ''));
$targetRole = trim((string)($target['role'] ?? ''));
$targetWarehouse = trim((string)($target['warehouse'] ?? ''));

if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['header_mail_error'] = 'לנמען אין כתובת מייל תקינה.';
    header('Location: ' . $redirect);
    exit;
}

$allowed = false;
if ($role === 'student') {
    $allowed = ($targetRole === 'warehouse_manager' && $myWarehouse !== '' && $targetWarehouse === $myWarehouse);
} elseif ($role === 'warehouse_manager') {
    $allowed = ($targetRole === 'student' && $myWarehouse !== '' && $targetWarehouse === $myWarehouse);
} elseif ($role === 'admin') {
    $allowed = ((int)($target['id'] ?? 0) !== $myId);
}

if (!$allowed) {
    $_SESSION['header_mail_error'] = 'אין הרשאה לשלוח מייל לנמען שנבחר.';
    header('Location: ' . $redirect);
    exit;
}

$senderName = trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? ''));
if ($senderName === '') {
    $senderName = (string)($me['username'] ?? 'מערכת');
}
$senderEmail = trim((string)($me['email'] ?? ''));
if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
    $senderEmail = 'no-reply@gearflow.local';
}

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'From: ' . str_replace(["\r", "\n"], '', $senderName) . ' <' . $senderEmail . '>';
$headers[] = 'Reply-To: ' . $senderEmail;

$mailOk = @mail($targetEmail, $encodedSubject, $body, implode("\r\n", $headers));

if ($mailOk) {
    $_SESSION['header_mail_success'] = 'המייל נשלח בהצלחה.';
} else {
    $_SESSION['header_mail_error'] = 'שליחת המייל נכשלה בשרת. יש לבדוק הגדרות דוא"ל.';
}

header('Location: ' . $redirect);
exit;

