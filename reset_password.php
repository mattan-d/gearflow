<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$error  = '';
$done   = false;
$reset_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    if ($username === '') {
        $error = 'יש להזין שם משתמש.';
    } else {
        $pdo = get_db();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND is_active = 1 LIMIT 1');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = 'המשתמש אינו קיים או שהחשבון לא פעיל.';
        } else {
            $token = bin2hex(random_bytes(24));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            $upd = $pdo->prepare('UPDATE users SET reset_token = :token, reset_token_expires_at = :expires WHERE id = :id');
            $upd->execute([
                ':token'  => $token,
                ':expires' => $expires,
                ':id'     => (int)$user['id'],
            ]);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $reset_link = $scheme . '://' . $host . dirname($_SERVER['REQUEST_URI'] ?: '/') . '/set_password.php?token=' . urlencode($token);
            $done = true;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>איפוס סיסמה - מערכת השאלת ציוד</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f4f5fb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; padding: 2rem 2.5rem; border-radius: 12px; box-shadow: 0 12px 30px rgba(15,23,42,0.12); width: 100%; max-width: 380px; }
        h1 { margin-top: 0; font-size: 1.5rem; text-align: center; color: #111827; }
        label { display: block; margin-bottom: 0.35rem; font-weight: 600; color: #374151; font-size: 0.9rem; }
        input[type="text"] { width: 100%; padding: 0.6rem 0.75rem; border-radius: 8px; border: 1px solid #d1d5db; font-size: 0.95rem; box-sizing: border-box; margin-bottom: 0.9rem; }
        .btn { width: 100%; border: none; border-radius: 999px; padding: 0.7rem 1rem; background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; font-weight: 600; cursor: pointer; font-size: 0.95rem; margin-top: 0.5rem; }
        .btn:hover { background: linear-gradient(135deg, #4338ca, #4f46e5); }
        .error { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.85rem; margin-bottom: 0.75rem; }
        .success { background: #f0fdf4; color: #166534; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.85rem; margin-bottom: 0.75rem; }
        .reset-url { word-break: break-all; font-size: 0.85rem; background: #f3f4f6; padding: 0.5rem; border-radius: 6px; margin-top: 0.5rem; }
        a.back { display: block; text-align: center; margin-top: 1rem; color: #4f46e5; text-decoration: none; font-size: 0.9rem; }
        a.back:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <h1>איפוס סיסמה</h1>
    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($done && $reset_link !== ''): ?>
        <div class="success">השתמש בקישור הבא לאיפוס הסיסמה (תקף לשעה):</div>
        <div class="reset-url"><?= htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8') ?></div>
        <a href="login.php" class="back">← חזרה להתחברות</a>
    <?php else: ?>
        <form method="post" action="reset_password.php">
            <label for="username">שם משתמש</label>
            <input type="text" id="username" name="username" required>
            <button type="submit" class="btn">קבלת קישור לאיפוס סיסמה</button>
        </form>
        <a href="login.php" class="back">← חזרה להתחברות</a>
    <?php endif; ?>
</div>
</body>
</html>
