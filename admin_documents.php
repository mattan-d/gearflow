<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$me = current_user();

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול מסמכים - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        header {
            background: #111827;
            color: #f9fafb;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        header h1 {
            margin: 0;
            font-size: 1.3rem;
        }
        .muted {
            color: #9ca3af;
            font-size: 0.8rem;
        }
        .user-info {
            font-size: 0.9rem;
            color: #e5e7eb;
            text-align: left;
        }
        header a {
            color: #f9fafb;
            text-decoration: none;
            margin-right: 1rem;
            font-size: 0.85rem;
        }
        main {
            max-width: 1000px;
            margin: 1.5rem auto;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            color: #111827;
        }
        p {
            font-size: 0.9rem;
            color: #4b5563;
        }
    </style>
</head>
<body>
<header>
    <div>
        <h1>ניהול מסמכים</h1>
        <div class="muted">מסכים, טפסים והסכמים במערכת</div>
    </div>
    <div class="user-info">
        מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        (<?= ($me['role'] ?? '') === 'admin' ? 'אדמין' : 'מנהל מחסן' ?>)
        <a href="logout.php">התנתק</a>
    </div>
</header>
<main>
    <div class="card">
        <h2>ניהול מסמכים</h2>
        <p>
            כאן ניתן יהיה לנהל מסמכים כגון טפסי הסכמים, הוראות שימוש וקובצי עזר נוספים.
        </p>
        <p class="muted">
            (כרגע זהו מסך בסיסי. אפשר להרחיב אותו בהמשך לניהול קבצים מלא.)
        </p>
    </div>
</main>
</body>
</html>

