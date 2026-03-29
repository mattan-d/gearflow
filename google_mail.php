<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const GOOGLE_MAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email';

function google_mail_oauth_redirect_uri(): string
{
    return app_script_dir_url() . '/google_oauth_callback.php';
}

/**
 * @return array{client_id: string, client_secret: string, refresh_token: string, sender_email: string}
 */
function google_mail_load_config(PDO $pdo): array
{
    $keys = ['google_oauth_client_id', 'google_oauth_client_secret', 'google_oauth_refresh_token', 'google_mail_sender_email'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT key, value FROM app_settings WHERE key IN ($placeholders)");
    $stmt->execute($keys);
    $map = array_fill_keys($keys, '');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = (string)($row['key'] ?? '');
        if (array_key_exists($k, $map)) {
            $map[$k] = (string)($row['value'] ?? '');
        }
    }
    return [
        'client_id'     => $map['google_oauth_client_id'],
        'client_secret' => $map['google_oauth_client_secret'],
        'refresh_token' => $map['google_oauth_refresh_token'],
        'sender_email'  => $map['google_mail_sender_email'],
    ];
}

function google_mail_is_configured(array $cfg): bool
{
    return $cfg['client_id'] !== ''
        && $cfg['client_secret'] !== ''
        && $cfg['refresh_token'] !== ''
        && $cfg['sender_email'] !== ''
        && filter_var($cfg['sender_email'], FILTER_VALIDATE_EMAIL);
}

function google_mail_save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO app_settings (key, value) VALUES (:k, :v)');
    $stmt->execute([':k' => $key, ':v' => $value]);
}

/**
 * @param array<string, string> $fields
 * @return array{ok: bool, body: string, code: int}
 */
function google_mail_http_post_form(string $url, array $fields): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => 'cURL לא זמין בשרת.', 'code' => 0];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'body' => $err !== '' ? $err : 'שגיאת רשת', 'code' => $code];
    }
    return ['ok' => $code >= 200 && $code < 300, 'body' => (string)$body, 'code' => $code];
}

/**
 * @return array{ok: bool, access_token?: string, error?: string}
 */
function google_mail_refresh_access_token(array $cfg): array
{
    $res = google_mail_http_post_form('https://oauth2.googleapis.com/token', [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'refresh_token' => $cfg['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]);
    if (!$res['ok']) {
        $j = json_decode($res['body'], true);
        $msg = is_array($j) && isset($j['error_description']) ? (string)$j['error_description'] : $res['body'];
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'שגיאה בקבלת אסימון מגוגל'];
    }
    $j = json_decode($res['body'], true);
    if (!is_array($j) || empty($j['access_token'])) {
        return ['ok' => false, 'error' => 'תשובה לא תקינה מגוגל'];
    }
    return ['ok' => true, 'access_token' => (string)$j['access_token']];
}

/**
 * @return array{ok: bool, email?: string, error?: string}
 */
function google_mail_fetch_user_email(string $accessToken): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL לא זמין בשרת.'];
    }
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'לא ניתן לקרוא כתובת מייל מחשבון גוגל'];
    }
    $j = json_decode($body, true);
    if (!is_array($j) || empty($j['email'])) {
        return ['ok' => false, 'error' => 'חסרה הרשאת userinfo.email'];
    }
    return ['ok' => true, 'email' => (string)$j['email']];
}

function google_mail_mime_encode_phrase(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function google_mail_build_rfc822(
    string $to,
    string $subject,
    string $bodyPlain,
    string $fromDisplayName,
    string $fromEmail,
    string $replyToEmail
): string {
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromName   = google_mail_mime_encode_phrase(str_replace(["\r", "\n"], '', $fromDisplayName));
    $toSafe     = str_replace(["\r", "\n"], '', $to);
    $fromAddr   = str_replace(["\r", "\n"], '', $fromEmail);
    $replySafe  = str_replace(["\r", "\n"], '', $replyToEmail);
    $b64        = rtrim(chunk_split(base64_encode($bodyPlain), 76, "\r\n"));

    $lines = [
        'To: ' . $toSafe,
        'From: ' . $fromName . ' <' . $fromAddr . '>',
        'Reply-To: ' . $replySafe,
        'Subject: ' . $subjectEnc,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        $b64,
    ];

    return implode("\r\n", $lines);
}

function google_mail_base64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * @return array{ok: bool, error?: string}
 */
function google_mail_send_via_api(
    string $accessToken,
    string $rawRfc822
): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL לא זמין בשרת.'];
    }
    $payload = json_encode(['raw' => google_mail_base64url_encode($rawRfc822)], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'שגיאה בקידוד ההודעה'];
    }
    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'error' => 'שגיאת רשת בשליחה ל-Gmail'];
    }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true];
    }
    $j = json_decode($body, true);
    if (is_array($j) && isset($j['error']['message'])) {
        return ['ok' => false, 'error' => (string)$j['error']['message']];
    }
    return ['ok' => false, 'error' => 'שליחה נכשלה (קוד ' . $code . ')'];
}

/**
 * @return array{ok: bool, error?: string}
 */
function google_mail_send(
    PDO $pdo,
    string $to,
    string $subject,
    string $bodyPlain,
    string $fromDisplayName,
    string $replyToEmail
): array {
    $cfg = google_mail_load_config($pdo);
    if (!google_mail_is_configured($cfg)) {
        return ['ok' => false, 'error' => 'חיבור Google לשליחת מיילים לא הוגדר במלואו.'];
    }
    if ($replyToEmail === '' || !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $replyToEmail = $cfg['sender_email'];
    }
    $token = google_mail_refresh_access_token($cfg);
    if (!$token['ok']) {
        return ['ok' => false, 'error' => $token['error'] ?? 'אסימון לא תקין'];
    }
    $raw = google_mail_build_rfc822(
        $to,
        $subject,
        $bodyPlain,
        $fromDisplayName,
        $cfg['sender_email'],
        $replyToEmail
    );
    return google_mail_send_via_api($token['access_token'], $raw);
}

/**
 * @return array{ok: bool, error?: string}
 */
function google_mail_exchange_code(PDO $pdo, string $code): array
{
    $cfg = google_mail_load_config($pdo);
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
        return ['ok' => false, 'error' => 'חסרים Client ID או Client Secret בהגדרות'];
    }
    $res = google_mail_http_post_form('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'redirect_uri'  => google_mail_oauth_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
    if (!$res['ok']) {
        $j = json_decode($res['body'], true);
        $msg = is_array($j) && isset($j['error_description']) ? (string)$j['error_description'] : $res['body'];
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'החלפת קוד נכשלה'];
    }
    $j = json_decode($res['body'], true);
    if (!is_array($j) || empty($j['access_token'])) {
        return ['ok' => false, 'error' => 'תשובה לא תקינה מגוגל'];
    }
    $access = (string)$j['access_token'];
    $refresh = isset($j['refresh_token']) ? (string)$j['refresh_token'] : '';
    if ($refresh === '') {
        return ['ok' => false, 'error' => 'לא התקבל refresh token. נסה שוב (ייתכן שכבר אישרת בעבר — נתק את החיבור בהגדרות ואז התחבר מחדש עם prompt אישור).'];
    }
    $emailInfo = google_mail_fetch_user_email($access);
    if (!$emailInfo['ok'] || empty($emailInfo['email'])) {
        return ['ok' => false, 'error' => $emailInfo['error'] ?? 'לא ניתן לזהות כתובת המייל'];
    }
    google_mail_save_setting($pdo, 'google_oauth_refresh_token', $refresh);
    google_mail_save_setting($pdo, 'google_mail_sender_email', $emailInfo['email']);
    return ['ok' => true];
}
