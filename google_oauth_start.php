<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google_mail.php';

require_admin();

$pdo = get_db();
$cfg = google_mail_load_config($pdo);
if (trim($cfg['client_id']) === '' || trim($cfg['client_secret']) === '') {
    header('Location: admin_settings.php?google_oauth=missing_credentials');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'     => $cfg['client_id'],
    'redirect_uri'  => google_mail_oauth_redirect_uri(),
    'response_type' => 'code',
    'scope'         => GOOGLE_MAIL_SCOPE,
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'state'         => $state,
];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $url);
exit;
