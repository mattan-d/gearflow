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
    <title>הגדרות מערכת - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        main {
            max-width: 900px;
            margin: 1.5rem auto 2rem;
            padding: 0 1rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        h1, h2 {
            margin-top: 0;
        }
        h2 {
            font-size: 1.3rem;
            color: #111827;
            margin-bottom: 1rem;
        }
        .muted-small {
            font-size: 0.9rem;
            color: #4b5563;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h2>הגדרות מערכת</h2>
        <p class="muted-small">
            כאן ירוכזו בהמשך הגדרות כלליות של המערכת (לדוגמה: הגדרות אבטחה, ברירות מחדל, מגבלות מערכת ועוד).
        </p>
    </div>
</main>
</body>
</html>

