<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin();

$me  = current_user();
$pdo = get_db();

// קריאת שעות ברירת מחדל
$defaults = [];
$stmt = $pdo->query('SELECT day_of_week, open_time, close_time FROM default_hours ORDER BY day_of_week ASC');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = (int)($row['day_of_week'] ?? 0);
    $defaults[$d] = [
        'open'  => (string)($row['open_time'] ?? '09:00'),
        'close' => (string)($row['close_time'] ?? '16:00'),
    ];
}

// אם חסרים ימים – משלימים ברירת מחדל 09:00–16:00
for ($d = 0; $d <= 4; $d++) {
    if (!isset($defaults[$d])) {
        $defaults[$d] = ['open' => '09:00', 'close' => '16:00'];
    }
}

$daysLabels = [
    0 => 'ראשון',
    1 => 'שני',
    2 => 'שלישי',
    3 => 'רביעי',
    4 => 'חמישי',
];

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['default_hours_submit'])) {
    try {
        $pdo->beginTransaction();
        $up = $pdo->prepare('INSERT OR REPLACE INTO default_hours (day_of_week, open_time, close_time) VALUES (:d, :o, :c)');
        for ($d = 0; $d <= 4; $d++) {
            $open  = trim((string)($_POST['default_open_'.$d] ?? ''));
            $close = trim((string)($_POST['default_close_'.$d] ?? ''));
            if ($open === '' || $close === '') {
                $open  = $defaults[$d]['open'];
                $close = $defaults[$d]['close'];
            }
            $up->execute([
                ':d' => $d,
                ':o' => $open,
                ':c' => $close,
            ]);
            $defaults[$d]['open']  = $open;
            $defaults[$d]['close'] = $close;
        }
        $pdo->commit();
        $success = 'שעות ברירת המחדל נשמרו בהצלחה.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $error = 'שגיאה בשמירת שעות ברירת המחדל.';
    }
}

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
        table.default-hours {
            width: 100%;
            max-width: 480px;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }
        table.default-hours th,
        table.default-hours td {
            border: 1px solid #e5e7eb;
            padding: 0.35rem 0.5rem;
            font-size: 0.9rem;
            text-align: center;
        }
        table.default-hours th {
            background: #f9fafb;
            font-weight: 600;
            color: #111827;
        }
        table.default-hours input[type="time"] {
            max-width: 110px;
        }
        .flash {
            margin-bottom: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.85rem;
        }
        .flash.success {
            background: #ecfdf3;
            color: #166534;
        }
        .flash.error {
            background: #fef2f2;
            color: #b91c1c;
        }
        .btn {
            border-radius: 999px;
            border: none;
            background: #111827;
            color: #f9fafb;
            padding: 0.45rem 1.1rem;
            font-size: 0.85rem;
            cursor: pointer;
            margin-top: 0.75rem;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h2>הגדרות מערכת</h2>
        <?php if ($success !== ''): ?>
            <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <h3 style="margin-top:0.5rem;margin-bottom:0.5rem;font-size:1.05rem;">שעות ברירת מחדל לפתיחת מחסן</h3>
        <p class="muted-small">
            שעות אלו ישמשו כברירת מחדל בעת הגדרת שעות פתיחה למחסנים חדשים, ובהיעדר הגדרה ספציפית למחסן.
        </p>

        <form method="post" action="admin_settings.php">
            <table class="default-hours">
                <thead>
                <tr>
                    <th>יום</th>
                    <th>שעת פתיחה</th>
                    <th>שעת סגירה</th>
                </tr>
                </thead>
                <tbody>
                <?php for ($d = 0; $d <= 4; $d++): ?>
                    <tr>
                        <td><?= htmlspecialchars($daysLabels[$d] ?? (string)$d, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <input type="time"
                                   name="default_open_<?= $d ?>"
                                   value="<?= htmlspecialchars($defaults[$d]['open'] ?? '09:00', ENT_QUOTES, 'UTF-8') ?>">
                        </td>
                        <td>
                            <input type="time"
                                   name="default_close_<?= $d ?>"
                                   value="<?= htmlspecialchars($defaults[$d]['close'] ?? '16:00', ENT_QUOTES, 'UTF-8') ?>">
                        </td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <button type="submit" class="btn" name="default_hours_submit" value="1">שמירת שעות ברירת מחדל</button>
        </form>
    </div>
</main>
</body>
</html>

