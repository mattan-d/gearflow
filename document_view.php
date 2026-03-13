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

// לוגיקת "שעות פתיחת מחסן" מיוחדת
$isHoursDoc = isset($doc['title']) && trim((string)$doc['title']) === 'שעות פתיחת מחסן';
$role       = $me['role'] ?? 'student';
$canEdit    = in_array($role, ['admin', 'warehouse_manager'], true);
$warehouse  = trim((string)($me['warehouse'] ?? 'מחסן א'));

// טווח ימים (ראשון–חמישי) ושעות (09:00–16:00)
$days = ['א', 'ב', 'ג', 'ד', 'ה']; // Sunday–Thursday
$hours = [];
for ($h = 9; $h <= 16; $h++) {
    $hours[] = $h;
}

// פרשנות התוכן הקיים כמבנה JSON של שעות, אם יש
$hoursState = null;
if ($isHoursDoc) {
    $rawContent = (string)($doc['content'] ?? '');
    $decoded = json_decode($rawContent, true);
    if (is_array($decoded) && ($decoded['schema'] ?? '') === 'warehouse_hours_v1') {
        $hoursState = $decoded;
    } else {
        // ברירת מחדל: כל הימים פתוחים 9–16
        $defaultMatrix = [];
        foreach ($days as $dayIdx => $_) {
            foreach ($hours as $h) {
                $defaultMatrix[(string)$dayIdx][(string)$h] = 1;
            }
        }
        $hoursState = [
            'schema' => 'warehouse_hours_v1',
            'hours'  => [
                $warehouse => $defaultMatrix,
            ],
        ];
    }

    // עדכון שעות מהטופס (POST) – רק למנהלים
    if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = $_POST['hours_payload'] ?? '';
        $decodedPayload = json_decode((string)$payload, true);
        if (is_array($decodedPayload)) {
            if (!isset($hoursState['hours']) || !is_array($hoursState['hours'])) {
                $hoursState['hours'] = [];
            }
            $hoursState['hours'][$warehouse] = $decodedPayload;
            $doc['content'] = json_encode($hoursState, JSON_UNESCAPED_UNICODE);

            $stmtSave = $pdo->prepare('UPDATE documents_custom SET content = :content WHERE id = :id');
            $stmtSave->execute([
                ':content' => (string)$doc['content'],
                ':id'      => $id,
            ]);
        }
    }

    // לוודא שיש רשומה למחסן הנוכחי
    if (!isset($hoursState['hours'][$warehouse]) || !is_array($hoursState['hours'][$warehouse])) {
        $matrix = [];
        foreach ($days as $dayIdx => $_) {
            foreach ($hours as $h) {
                $matrix[(string)$dayIdx][(string)$h] = 1;
            }
        }
        $hoursState['hours'][$warehouse] = $matrix;
        $doc['content'] = json_encode($hoursState, JSON_UNESCAPED_UNICODE);
        $stmtSave = $pdo->prepare('UPDATE documents_custom SET content = :content WHERE id = :id');
        $stmtSave->execute([
            ':content' => (string)$doc['content'],
            ':id'      => $id,
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($isHoursDoc ? 'קביעת שעות פתיחת מחסן' : ($doc['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></title>
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
        <h2><?= htmlspecialchars($isHoursDoc ? 'קביעת שעות פתיחת מחסן' : ($doc['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>

        <?php if ($isHoursDoc): ?>
            <?php
            $matrix = $hoursState['hours'][$warehouse] ?? [];
            ?>
            <p style="font-size:0.9rem;color:#4b5563;margin-top:0;">
                במחסן: <?= htmlspecialchars($warehouse, ENT_QUOTES, 'UTF-8') ?>.
                <?php if ($canEdit): ?>
                    ניתן ללחוץ על משבצות השעות כדי לסמן פתוח / סגור.
                    לבחירת טווח שעות באותו יום: לחץ על תא ראשון ואז על תא אחר עם מקש Shift לחוץ.
                <?php else: ?>
                    התצוגה לקריאה בלבד (ללא אפשרות עריכה).
                <?php endif; ?>
            </p>

            <form method="post" action="" id="hours_form">
                <div class="hours-table-wrapper">
                    <table class="hours-table">
                        <thead>
                        <tr>
                            <th>שעה</th>
                            <?php foreach ($days as $idx => $label): ?>
                                <th><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($hours as $h): ?>
                            <tr>
                                <td><?= sprintf('%02d:00', $h) ?></td>
                                <?php foreach ($days as $dayIdx => $_label): ?>
                                    <?php
                                    $open = (int)($matrix[(string)$dayIdx][(string)$h] ?? 1) === 1;
                                    ?>
                                    <td class="slot-cell <?= $open ? 'slot-open' : 'slot-closed' ?>"
                                        data-day="<?= (int)$dayIdx ?>"
                                        data-hour="<?= (int)$h ?>">
                                        <?= $open ? 'פתוח' : 'סגור' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <input type="hidden" name="hours_payload" id="hours_payload" value="">
                <?php if ($canEdit): ?>
                    <div class="hours-actions">
                        <button type="submit" class="btn">שמירת שעות</button>
                    </div>
                <?php endif; ?>
            </form>
            <div class="hours-legend">
                כחול בהיר = שעה פתוחה, אפור בהיר = שעה סגורה.
            </div>

            <?php if ($canEdit): ?>
            <script>
                (function () {
                    var cells = Array.prototype.slice.call(document.querySelectorAll('.slot-cell'));
                    var lastClick = null; // {day, hour}
                    var canEdit = <?= $canEdit ? 'true' : 'false' ?>;
                    if (!canEdit) return;

                    function buildMatrix() {
                        var matrix = {};
                        cells.forEach(function (cell) {
                            var day = String(cell.getAttribute('data-day'));
                            var hour = String(cell.getAttribute('data-hour'));
                            if (!matrix[day]) {
                                matrix[day] = {};
                            }
                            var open = cell.classList.contains('slot-open') ? 1 : 0;
                            matrix[day][hour] = open;
                        });
                        return matrix;
                    }

                    function toggleCell(cell, makeOpen) {
                        var open = (typeof makeOpen === 'boolean') ? makeOpen : !cell.classList.contains('slot-open');
                        cell.classList.toggle('slot-open', open);
                        cell.classList.toggle('slot-closed', !open);
                        cell.textContent = open ? 'פתוח' : 'סגור';
                    }

                    cells.forEach(function (cell) {
                        cell.addEventListener('click', function (ev) {
                            var day = parseInt(cell.getAttribute('data-day'), 10);
                            var hour = parseInt(cell.getAttribute('data-hour'), 10);

                            if (ev.shiftKey && lastClick && lastClick.day === day) {
                                var from = Math.min(lastClick.hour, hour);
                                var to = Math.max(lastClick.hour, hour);
                                var baseOpen = !cell.classList.contains('slot-open');
                                cells.forEach(function (c2) {
                                    var d2 = parseInt(c2.getAttribute('data-day'), 10);
                                    var h2 = parseInt(c2.getAttribute('data-hour'), 10);
                                    if (d2 === day && h2 >= from && h2 <= to) {
                                        toggleCell(c2, baseOpen);
                                    }
                                });
                            } else {
                                toggleCell(cell);
                            }
                            lastClick = {day: day, hour: hour};
                        });
                    });

                    var form = document.getElementById('hours_form');
                    var payloadInput = document.getElementById('hours_payload');
                    if (form && payloadInput) {
                        form.addEventListener('submit', function () {
                            var matrix = buildMatrix();
                            payloadInput.value = JSON.stringify(matrix);
                        });
                    }
                })();
            </script>
            <?php endif; ?>
        <?php else: ?>
            <pre><?= $doc['content'] ?? '' ?></pre>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

