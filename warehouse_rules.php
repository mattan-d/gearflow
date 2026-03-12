<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// נהלים גלויים לפחות למשתמשים מחוברים (סטודנט / מנהל)
if (($me = current_user()) === null) {
    header('Location: login.php');
    exit;
}

$docDir = __DIR__ . '/documents';
$file   = $docDir . '/warehouse_rules.txt';
$default = "נוהלי מחסן\n\n1. הוצאת ציוד מהמחסן מתבצעת רק לאחר הזמנה מאושרת במערכת.\n2. יש להגיע בזמן שנקבע לקבלת הציוד ולהזדהות באמצעות תעודה מתאימה.\n3. האחריות לשלמות הציוד מרגע קבלתו ועד החזרתו חלה על השואל.\n4. אין להעביר ציוד בין סטודנטים ללא תיאום ואישור מנהל המחסן.\n5. החזרת ציוד תתבצע נקי, תקין ובאריזה המקורית ככל הניתן.\n6. איחור בהחזרה או נזק לציוד עלולים להביא לחיוב כספי ולהגבלת השאלות עתידיות.\n7. יש לפעול בהתאם להוראות הבטיחות והשימוש שמופיעות על הציוד או במסמכי ההדרכה.\n";

if (!is_dir($docDir)) {
    @mkdir($docDir, 0775, true);
}

if (is_file($file)) {
    $content = file_get_contents($file) ?: $default;
} else {
    $content = $default;
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>נהלי מחסן - מערכת השאלת ציוד</title>
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
        .main-nav {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.6rem;
            font-size: 0.85rem;
            white-space: nowrap;
            align-items: center;
        }
        .main-nav a {
            color: #e5e7eb;
            text-decoration: none;
            display: inline-block;
        }
        .main-nav-primary {
            display: flex;
            gap: 0.6rem;
        }
        .main-nav-item-wrapper {
            position: relative;
        }
        .main-nav-sub {
            position: absolute;
            right: 0;
            top: 100%;
            background: #111827;
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            box-shadow: 0 12px 30px rgba(0,0,0,0.45);
            display: none;
            min-width: 170px;
            z-index: 30;
        }
        .main-nav-sub a {
            display: block;
            padding: 0.25rem 0.2rem;
            font-size: 0.8rem;
        }
        .main-nav-sub a + a {
            margin-top: 0.15rem;
        }
        .main-nav-item-wrapper:hover .main-nav-sub {
            display: block;
        }
        main {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem 2rem;
        }
        .sheet {
            background: #ffffff;
            padding: 1.75rem 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(15,23,42,0.08);
        }
        .sheet h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.4rem;
            color: #111827;
        }
        pre {
            white-space: pre-wrap;
            font-family: inherit;
            font-size: 0.95rem;
            color: #111827;
            margin: 0;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="sheet">
        <h2>נהלי מחסן</h2>
        <pre><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
</main>
</body>
</html>

