<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

$user = current_user();

if ($user === null) {
    header('Location: login.php');
    exit;
}

// הפניה לדף הבית בהתאם להגדרה במערכת (לפי תפקיד)
$role = $user['role'] ?? 'student';
$target = get_home_route_for_role($role);
header('Location: ' . $target);
exit;

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>מרכז ניהול - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #111827;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f9fafb;
        }
        .shell {
            background: #020617;
            border-radius: 18px;
            padding: 2rem 2.5rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.8);
            max-width: 520px;
            width: 100%;
        }
        h1 {
            margin: 0 0 0.25rem;
            font-size: 1.6rem;
        }
        .subtitle {
            margin: 0 0 1.5rem;
            font-size: 0.9rem;
            color: #9ca3af;
        }
        .user-line {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-bottom: 1.5rem;
        }
        .user-line strong {
            color: #e5e7eb;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        a.tile {
            display: block;
            text-decoration: none;
            border-radius: 14px;
            padding: 1.1rem 1rem;
            background: radial-gradient(circle at top left, #22c55e22, #1f2937);
            border: 1px solid #1f2937;
            color: #f9fafb;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease, background 0.15s ease;
        }
        a.tile.orders {
            background: radial-gradient(circle at top left, #38bdf822, #1f2937);
        }
        a.tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.9);
            border-color: #4f46e5;
            background: radial-gradient(circle at top left, #4f46e533, #020617);
        }
        .tile-title {
            font-size: 1.05rem;
            margin-bottom: 0.3rem;
        }
        .tile-desc {
            font-size: 0.8rem;
            color: #9ca3af;
        }
        .footer-links {
            margin-top: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #6b7280;
        }
        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
        }
        .footer-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="shell">
    <h1>מרכז ניהול</h1>
    <p class="subtitle">בחירת אזור עבודה במערכת השאלת הציוד.</p>

    <div class="user-line">
        משתמש מחובר: <strong><?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
        (<?= htmlspecialchars($user['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
    </div>

    <div class="grid">
        <a href="admin_equipment.php" class="tile">
            <div class="tile-title">ניהול ציוד</div>
            <div class="tile-desc">הוספה ועריכה של ציוד, סטטוסים וכמויות במחסן.</div>
        </a>
        <a href="admin_orders.php" class="tile orders">
            <div class="tile-title">ניהול הזמנות</div>
            <div class="tile-desc">יצירה, מעקב וסינון הזמנות השאלה פעילות ועתידיות.</div>
        </a>
    </div>

    <div class="footer-links">
        <span>© 2026 CentricApp LTD</span>
        <a href="logout.php">התנתק</a>
    </div>
</div>
</body>
</html>
