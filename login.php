<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$error   = '';
$success = (isset($_GET['reset']) && $_GET['reset'] === 'ok') ? 'הסיסמה עודכנה. אפשר להתחבר עם הסיסמה החדשה.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'יש למלא שם משתמש וסיסמה.';
    } else {
        $pdo = get_db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'המשתמש אינו קיים.';
        } elseif ((int)$user['is_active'] !== 1) {
            $error = 'החשבון לא פעיל. פנה למנהל המערכת.';
        } else {
            $loginOk = false;
            $hash    = $user['password_hash'] ?? '';

            // 1. סיסמאות חדשות/רגילות עם password_hash
            if (is_string($hash) && $hash !== '' && password_verify($password, $hash)) {
                $loginOk = true;
            }

            // 2. תמיכה במקרה שבו נשמרה סיסמה כטקסט גלוי בעמודה password_hash (מערכת ישנה / ייבוא)
            if (!$loginOk && is_string($hash) && $hash !== '' && hash_equals($hash, $password)) {
                $loginOk = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $update  = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                $update->execute([
                    ':password_hash' => $newHash,
                    ':id'            => (int)$user['id'],
                ]);
            }

            // 3. טיפול מיוחד למשתמש הדמו שביקשת
            if (
                !$loginOk
                && $username === 'studentdemo'
                && $password === '123456'
            ) {
                $loginOk = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $update  = $pdo->prepare('UPDATE users SET password_hash = :password_hash, is_active = 1 WHERE id = :id');
                $update->execute([
                    ':password_hash' => $newHash,
                    ':id'            => (int)$user['id'],
                ]);
            }

            if (!$loginOk) {
                $error = 'הסיסמה אינה נכונה.';
            } else {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];

                $role = $user['role'] ?? 'student';
                // דף הבית לפי תפקיד – ניתן להגדרה ב"הגדרות מערכת"
                $target = get_home_route_for_role($role);

                header('Location: ' . $target);
                exit;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>התחברות - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4f5fb;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .card {
            background: #fff;
            padding: 2rem 2.5rem;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
            width: 100%;
            max-width: 380px;
        }
        h1 {
            margin-top: 0;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
            text-align: center;
            color: #111827;
        }
        p.subtitle {
            margin-top: 0;
            margin-bottom: 1.5rem;
            text-align: center;
            color: #6b7280;
            font-size: 0.9rem;
        }
        label {
            display: block;
            margin-bottom: 0.35rem;
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.95rem;
            box-sizing: border-box;
            margin-bottom: 0.9rem;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 1px rgba(79, 70, 229, 0.15);
        }
        .btn {
            width: 100%;
            border: none;
            border-radius: 999px;
            padding: 0.7rem 1rem;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.95rem;
            margin-top: 0.5rem;
        }
        .btn:hover {
            background: linear-gradient(135deg, #4338ca, #4f46e5);
        }
        .error {
            background: #fef2f2;
            color: #b91c1c;
            border-radius: 8px;
            padding: 0.6rem 0.75rem;
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }
        .success {
            background: #f0fdf4;
            color: #166534;
            border-radius: 8px;
            padding: 0.6rem 0.75rem;
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }
        .reset-link {
            display: block;
            text-align: center;
            margin-top: 0.75rem;
            font-size: 0.9rem;
            color: #4f46e5;
            text-decoration: none;
        }
        .reset-link:hover {
            text-decoration: underline;
        }
        .hint {
            margin-top: 0.75rem;
            font-size: 0.8rem;
            color: #6b7280;
            text-align: center;
        }
        .hint code {
            background: #f3f4f6;
            padding: 0.1rem 0.3rem;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        .login-footer {
            margin-top: 1rem;
            font-size: 0.75rem;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>התחברות</h1>
    <p class="subtitle">מערכת ניהול השאלת ציוד</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" accept-charset="UTF-8">
        <label for="username">שם משתמש</label>
        <input type="text" id="username" name="username" required>

        <label for="password">סיסמה</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn">כניסה</button>
    </form>

    <a href="reset_password.php" class="reset-link">שכחתי סיסמה</a>

    <!--p class="hint">
        התחברות ראשונית: <code>admin / admin</code>
    </p-->
    <p class="login-footer">
        © 2026 CentricApp LTD
    </p>
</div>
</body>
</html>

