<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo     = get_db();
$error   = '';
$success = '';

// טאב פעיל – ברירת מחדל: רשימת ספקים
$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'list';
if (!in_array($tab, ['list', 'settings'], true)) {
    $tab = 'list';
}

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
        .tabs {
            display: inline-flex;
            border-radius: 999px;
            background: #e5e7eb;
            padding: 0.2rem;
            margin-bottom: 1rem;
        }
        .tabs a {
            padding: 0.35rem 1.1rem;
            border-radius: 999px;
            font-size: 0.82rem;
            text-decoration: none;
            color: #374151;
            transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
        }
        .tabs a.active {
            background: #111827;
            color: #f9fafb;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(15,23,42,0.25);
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <h2 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem;">ספקים</h2>

    <?php if ($error !== ''): ?>
        <div class="card" style="margin-bottom: 0.75rem;">
            <div class="muted-small" style="color:#b91c1c;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php elseif ($success !== ''): ?>
        <div class="card" style="margin-bottom: 0.75rem;">
            <div class="muted-small" style="color:#166534;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <a href="admin_suppliers.php?tab=list"
           class="<?= $tab === 'list' ? 'active' : '' ?>">ציוד</a>
        <a href="admin_suppliers.php?tab=settings"
           class="<?= $tab === 'settings' ? 'active' : '' ?>">שירותים</a>
    </div>

    <div class="card">
        <?php if ($tab === 'list'): ?>
            <p class="muted-small">
                כאן תוצג רשימת הספקים, כולל אפשרות להוספה, עריכה ומחיקה.
            </p>
        <?php elseif ($tab === 'settings'): ?>
            <p class="muted-small">
                כאן יוגדרו פרמטרים כלליים הקשורים לספקים (לדוגמה: סוגי ספקים, ברירות מחדל וכדומה).
            </p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

