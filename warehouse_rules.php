<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// נהלים גלויים לפחות למשתמשים מחוברים (סטודנט / מנהל)
if (current_user() === null) {
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
        .sheet {
            max-width: 900px;
            margin: 2rem auto;
            background: #ffffff;
            padding: 1.75rem 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(15,23,42,0.08);
        }
        h1 {
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
<div class="sheet">
    <h1>נהלי מחסן</h1>
    <pre><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></pre>
</div>
</body>
</html>

