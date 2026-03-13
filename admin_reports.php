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
    <title>דוחות - מערכת השאלת ציוד</title>
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
        header .user-info {
            font-size: 0.9rem;
            color: #e5e7eb;
        }
        header a {
            color: #f9fafb;
            text-decoration: none;
            margin-right: 1rem;
            font-size: 0.85rem;
        }
        main {
            max-width: 1000px;
            margin: 1.5rem auto 2rem;
            padding: 0 1rem;
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
            font-size: 1.3rem;
            color: #111827;
        }
        .muted-small {
            font-size: 0.9rem;
            color: #4b5563;
        }
        .reports-tabs {
            display: inline-flex;
            gap: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1rem;
        }
        .reports-tab {
            padding: 0.4rem 0.9rem;
            border-radius: 999px 999px 0 0;
            font-size: 0.9rem;
            cursor: pointer;
            color: #4b5563;
            background: #f3f4f6;
            border: 1px solid transparent;
            border-bottom: none;
        }
        .reports-tab.active {
            background: #ffffff;
            color: #111827;
            border-color: #e5e7eb;
        }
        .reports-section {
            display: none;
        }
        .reports-section.active {
            display: block;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h2>דוחות</h2>

        <div class="reports-tabs">
            <button type="button" class="reports-tab active" data-target="reports-users">דוחות משתמשים</button>
            <button type="button" class="reports-tab" data-target="reports-orders">דוחות הזמנות</button>
            <button type="button" class="reports-tab" data-target="reports-equipment">דוחות ציוד</button>
        </div>

        <div id="reports-users" class="reports-section active">
            <p class="muted-small">
                כאן יוצגו דוחות על משתמשים (פעילים, סטודנטים לפי מחסן, כניסות למערכת ועוד).
            </p>
        </div>

        <div id="reports-orders" class="reports-section">
            <p class="muted-small">
                כאן יוצגו דוחות על הזמנות (השאלות לפי תאריכים, סטטוסים, מחסנים ועוד).
            </p>
        </div>

        <div id="reports-equipment" class="reports-section">
            <p class="muted-small">
                כאן יוצגו דוחות על ציוד (שימוש בפריטים, תקלות, זמינות ועוד).
            </p>
        </div>
    </div>
</main>
<script>
    (function () {
        var tabs = document.querySelectorAll('.reports-tab');
        var sections = document.querySelectorAll('.reports-section');
        if (!tabs.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var targetId = tab.getAttribute('data-target');
                tabs.forEach(function (t) { t.classList.remove('active'); });
                sections.forEach(function (s) { s.classList.remove('active'); });
                tab.classList.add('active');
                var sec = document.getElementById(targetId);
                if (sec) sec.classList.add('active');
            });
        });
    })();
</script>
</body>
</html>

