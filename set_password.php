<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$error = '';
$token = trim($_GET['token'] ?? '');
$valid = false;

if ($token !== '') {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE reset_token = :token AND reset_token_expires_at > datetime(\'now\') LIMIT 1');
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();
    if ($user) {
        $valid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $new  = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    if ($new === '' || strlen($new) < 4) {
        $error = 'הסיסמה חייבת להכיל לפחות 4 תווים.';
    } elseif ($new !== $conf) {
        $error = 'אימות הסיסמה לא תואם.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, reset_token = NULL, reset_token_expires_at = NULL WHERE reset_token = :token');
        $stmt->execute([
            ':hash'  => password_hash($new, PASSWORD_DEFAULT),
            ':token' => $token,
        ]);
        header('Location: login.php?reset=ok');
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>הגדרת סיסמה חדשה - מערכת השאלת ציוד</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f4f5fb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; padding: 2rem 2.5rem; border-radius: 12px; box-shadow: 0 12px 30px rgba(15,23,42,0.12); width: 100%; max-width: 380px; }
        h1 { margin-top: 0; font-size: 1.5rem; text-align: center; color: #111827; }
        label { display: block; margin-bottom: 0.35rem; font-weight: 600; color: #374151; font-size: 0.9rem; }
        input[type="password"] { width: 100%; padding: 0.6rem 0.75rem; border-radius: 8px; border: 1px solid #d1d5db; font-size: 0.95rem; box-sizing: border-box; margin-bottom: 0.9rem; }
        .btn { width: 100%; border: none; border-radius: 999px; padding: 0.7rem 1rem; background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; font-weight: 600; cursor: pointer; font-size: 0.95rem; margin-top: 0.5rem; }
        .btn:hover { background: linear-gradient(135deg, #4338ca, #4f46e5); }
        .error { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.85rem; margin-bottom: 0.75rem; }
        .invalid { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.85rem; }
        a.back { display: block; text-align: center; margin-top: 1rem; color: #4f46e5; text-decoration: none; font-size: 0.9rem; }
        a.back:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <h1>הגדרת סיסמה חדשה</h1>
    <?php if (!$valid): ?>
        <div class="invalid">קישור לא תקף או שפג תוקפו. נסה לבקש קישור חדש מאיפוס סיסמה.</div>
        <a href="reset_password.php" class="back">בקשת קישור חדש</a>
        <a href="login.php" class="back">← חזרה להתחברות</a>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" action="set_password.php?token=<?= htmlspecialchars(urlencode($token), ENT_QUOTES, 'UTF-8') ?>">
            <label for="new_password">סיסמה חדשה</label>
            <input type="password" id="new_password" name="new_password" required minlength="4" autocomplete="new-password">
            <label for="confirm_password">אימות סיסמה</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="4" autocomplete="new-password">
            <button type="submit" class="btn">שמירת סיסמה</button>
        </form>
        <a href="login.php" class="back">← חזרה להתחברות</a>
    <?php endif; ?>
</div>
</body>
</html>
