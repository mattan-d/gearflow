<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo     = get_db();
$error   = '';
$success = '';

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ספקים - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        main {
            max-width: 1100px;
            margin: 1.5rem auto 2rem;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.4rem;
        }
        .muted-small {
            font-size: 0.8rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <h2 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem;">ספקים</h2>

    <div class="card">
        <p class="muted-small" style="margin-bottom:0.75rem;">
            טבלת ספקים.
        </p>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.86rem;">
                <thead>
                <tr>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">חברה</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">קוד חברה</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">איש קשר</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">טלפון</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">מייל</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">כתובת</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">לינק לאתר</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">סוג שירות</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td colspan="8" class="muted-small" style="padding:0.6rem 0.5rem; text-align:center; color:#9ca3af;">
                        אין עדיין נתונים להצגה.
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>

