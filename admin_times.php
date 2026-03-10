<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$me = current_user();

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול זמנים - מערכת השאלת ציוד</title>
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
        .nav-links {
            margin-top: 0.4rem;
        }
        .nav-links a {
            color: #e5e7eb;
            font-size: 0.82rem;
            margin-left: 0.75rem;
        }
        .nav-links a.active {
            font-weight: 600;
            text-decoration: underline;
        }
        main {
            max-width: 800px;
            margin: 1.5rem auto;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
            margin-bottom: 1.5rem;
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            color: #111827;
        }
        .muted-small {
            font-size: 0.85rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
<header>
    <div>
        <h1>ניהול זמנים</h1>
        <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        <div class="nav-links">
            <a href="admin.php">ניהול מערכת</a>
            <a href="admin_design.php">עיצוב ממשק</a>
            <a href="admin_times.php" class="active">ניהול זמנים</a>
            <a href="admin_users.php">ניהול משתמשים</a>
            <a href="admin_equipment.php">ניהול ציוד</a>
            <a href="admin_orders.php">ניהול הזמנות</a>
        </div>
    </div>
    <div class="user-info">
        מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?> (אדמין)
        <a href="logout.php">התנתק</a>
    </div>
</header>
<main>
    <div class="card">
        <h2>הגדרות זמנים</h2>
        <p class="muted-small">
            בעמוד זה ניתן יהיה להגדיר שעות פעילות המערכת, משך השאלה מקסימלי,
            חלונות הזמנה (למשל: עד 14 יום מראש) ומגבלות זמנים להזמנות.
        </p>
        <p class="muted-small" style="margin-top: 1rem;">
            פיצ'ר בהמשך: ימים ושעות פתיחה, אורך השאלה ברירת מחדל, חריגות בחגים.
        </p>
    </div>
</main>
</body>
</html>
