<?php

// Simple Git webhook endpoint to auto-pull latest changes.

// 1) Optional: restrict by IP (GitHub example). Adjust/remove as needed.
// $allowed_ips = ['140.82.112.0/20', '185.199.108.0/22', '192.30.252.0/22'];
// You can implement IP range checks here if you want.

// 2) Secret used to verify signature from the Git provider (e.g. GitHub header HTTP_X_HUB_SIGNATURE_256).
// NOTE: This is hardcoded on purpose, keep in sync with the provider webhook configuration.
$secret = '$CA#Secret';

$logFile = __DIR__ . '/webhook.log';

function log_message($message)
{
    global $logFile;
    $date = date('Y-m-d H:i:s');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = "[$date] [IP:$ip] $message" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function respond($statusCode, $message)
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message . PHP_EOL;
    exit;
}

// Log incoming webhook
log_message('Incoming webhook: method=' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_message('Rejected: method not allowed');
    respond(405, 'Method Not Allowed');
}

// Read body
$payload = file_get_contents('php://input');

// If you configured a secret on the git provider, verify it here (GitHub style).
if ($secret !== '') {
    $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (strpos($signatureHeader, 'sha256=') === 0) {
        $theirSig = substr($signatureHeader, 7);
        $ourSig   = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($ourSig, $theirSig)) {
            log_message('Signature mismatch');
            respond(401, 'Invalid signature');
        }
    } else {
        log_message('Missing signature header');
        respond(400, 'Missing signature header');
    }
}

// Directory of the git repo on the server
$repoDir = __DIR__;

// Run git pull
$cmd = 'cd ' . escapeshellarg($repoDir) . ' && git pull 2>&1';
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);

if ($exitCode !== 0) {
    log_message('git pull failed: ' . implode(' | ', $output));
    respond(500, "git pull failed:\n" . implode("\n", $output));
}

log_message('git pull succeeded: ' . implode(' | ', $output));

respond(200, "git pull succeeded:\n" . implode("\n", $output));

