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
    <title>ניהול מערכת - מערכת השאלת ציוד</title>
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
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem 2rem;
        }
        .menu-title {
            font-size: 1.5rem;
            color: #111827;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1.25rem;
        }
        .menu-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
            text-decoration: none;
            color: inherit;
            display: block;
            border: 1px solid #e5e7eb;
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .menu-card:hover {
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
            border-color: #c7d2fe;
        }
        .menu-card h2 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
            color: #111827;
        }
        .menu-card p {
            margin: 0;
            font-size: 0.9rem;
            color: #6b7280;
            line-height: 1.4;
        }
        .menu-card .icon {
            font-size: 2rem;
            margin-bottom: 0.75rem;
            opacity: 0.9;
        }
        footer {
            background: #111827;
            color: #9ca3af;
            text-align: center;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            border-top: 1px solid #1f2937;
        }
    </style>
</head>
<body>
<header>
    <div>
        <h1>ניהול מערכת</h1>
        <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        <div class="nav-links">
            <a href="admin.php" class="active">ניהול מערכת</a>
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
    <h2 class="menu-title">תפריט ניהול מערכת</h2>
    <div class="menu-grid">
        <a href="admin_design.php" class="menu-card">
            <div class="icon">🎨</div>
            <h2>עיצוב ממשק</h2>
            <p>הגדרת ערכת צבעים, לוגו, וסגנון הממשק של המערכת.</p>
        </a>
        <a href="admin_times.php" class="menu-card">
            <div class="icon">🕐</div>
            <h2>ניהול זמנים</h2>
            <p>הגדרת שעות פעילות, חלונות השאלה ומגבלות זמנים.</p>
        </a>
        <a href="admin_users.php" class="menu-card">
            <div class="icon">👥</div>
            <h2>ניהול משתמשים</h2>
            <p>הוספת משתמשים, הרשאות, הפעלה והשבתה של חשבונות.</p>
        </a>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
</body>
</html>
