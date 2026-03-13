<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// מנסים לזהות משתמש מחובר – אבל גם בלי התחברות עדיין מציגים את המסמך
$me  = current_user();
$pdo = get_db();

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$doc = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT id, title, content FROM documents_custom WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$doc) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

// זיהוי מסמך "שעות פתיחת מחסן" והבאת שעות פתיחה מטבלת warehouse_hours
$title      = trim((string)($doc['title'] ?? ''));
$isHoursDoc = ($title === 'שעות פתיחת מחסן');
$warehouse  = trim((string)($me['warehouse'] ?? 'מחסן א'));
$days       = ['א', 'ב', 'ג', 'ד', 'ה']; // ראשון–חמישי
$hours      = range(9, 16);             // 09:00–16:00
$openMatrix = [];

if ($isHoursDoc) {
    $stmtHours = $pdo->prepare('SELECT day_of_week, hour FROM warehouse_hours WHERE warehouse = :w');
    $stmtHours->execute([':w' => $warehouse]);
    $rows = $stmtHours->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        foreach ($rows as $row) {
            $d = (int)($row['day_of_week'] ?? 0);
            $h = (int)($row['hour'] ?? 0);
            $openMatrix[$d][$h] = true;
        }
    } else {
        // ברירת מחדל – אם לא הוגדרו שעות: ראשון–חמישי 9–16 פתוח
        foreach (array_keys($days) as $d) {
            foreach ($hours as $h) {
                $openMatrix[$d][$h] = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
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
        <?php if ($isHoursDoc): ?>
        .hours-table-wrapper {
            overflow-x: auto;
        }
        table.hours-table {
            border-collapse: collapse;
            width: 100%;
            max-width: 600px;
            font-size: 0.9rem;
        }
        table.hours-table th,
        table.hours-table td {
            border: 1px solid #e5e7eb;
            text-align: center;
            padding: 0.3rem 0.4rem;
        }
        table.hours-table th {
            background: #f9fafb;
            font-weight: 600;
        }
        .slot-cell {
            cursor: pointer;
            user-select: none;
        }
        .slot-open {
            background: #dbeafe;
            color: #111827;
        }
        .slot-closed {
            background: #f3f4f6;
            color: #6b7280;
        }
        .hours-legend {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #4b5563;
        }
        .hours-actions {
            margin-top: 0.75rem;
        }
        .btn {
            border-radius: 999px;
            border: none;
            background: #111827;
            color: #f9fafb;
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }
        <?php endif; ?>
    </style>
</head>
<body>
<?php if ($me !== null): ?>
    <?php include __DIR__ . '/admin_header.php'; ?>
<?php endif; ?>
<main>
    <div class="sheet">
        <h2><?= htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>

        <?php if ($isHoursDoc): ?>
            <p style="font-size:0.9rem;color:#4b5563;margin-top:0;">
                שעות פתיחת <?= htmlspecialchars($warehouse, ENT_QUOTES, 'UTF-8') ?>.
            </p>
            <div class="hours-table-wrapper">
                <table class="hours-table">
                    <thead>
                    <tr>
                        <th>שעה</th>
                        <?php foreach ($days as $label): ?>
                            <th><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($hours as $h): ?>
                        <tr>
                            <td><?= sprintf('%02d:00', $h) ?></td>
                            <?php foreach (array_keys($days) as $d): ?>
                                <?php $open = !empty($openMatrix[$d][$h]); ?>
                                <td class="<?= $open ? 'slot-open' : 'slot-closed' ?>">
                                    <?= $open ? 'פתוח' : 'סגור' ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="hours-legend">
                כחול בהיר = שעה פתוחה, אפור בהיר = שעה סגורה.
            </div>
        <?php else: ?>
            <pre><?= htmlspecialchars($doc['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

