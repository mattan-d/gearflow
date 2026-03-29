<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google_mail.php';

require_admin();

$state = (string)($_GET['state'] ?? '');
$sess  = (string)($_SESSION['google_oauth_state'] ?? '');
if ($state === '' || $sess === '' || !hash_equals($sess, $state)) {
    header('Location: admin_settings.php?google_oauth=bad_state');
    exit;
}
unset($_SESSION['google_oauth_state']);

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    $err = (string)($_GET['error'] ?? 'unknown');
    header('Location: admin_settings.php?google_oauth=denied&reason=' . rawurlencode($err));
    exit;
}

$pdo = get_db();
$result = google_mail_exchange_code($pdo, $code);
if ($result['ok']) {
    header('Location: admin_settings.php?google_oauth=success');
    exit;
}

header('Location: admin_settings.php?google_oauth=fail&reason=' . rawurlencode($result['error'] ?? 'unknown'));
exit;
