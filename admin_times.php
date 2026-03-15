<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin_or_warehouse();

$me  = current_user();
$pdo = get_db();
$error = '';

// קביעת המחסן הנוכחי
$userWarehouse = trim((string)($me['warehouse'] ?? 'מחסן א'));
$selectedWarehouse = isset($_GET['warehouse']) ? trim((string)$_GET['warehouse']) : $userWarehouse;
if ($selectedWarehouse === '') {
    $selectedWarehouse = 'מחסן א';
}

// ימים ראשון–חמישי (0–4) ושעות 9–16
$days  = ['א', 'ב', 'ג', 'ד', 'ה'];
$hours = range(9, 16);

$knownWarehouses = ['מחסן א', 'מחסן ב'];
if (!in_array($userWarehouse, $knownWarehouses, true)) {
    $knownWarehouses[] = $userWarehouse;
}
if (!in_array($selectedWarehouse, $knownWarehouses, true)) {
    $knownWarehouses[] = $selectedWarehouse;
}

// טעינת שעות פתוחות מה-DB
$stmt = $pdo->prepare('SELECT day_of_week, hour FROM warehouse_hours WHERE warehouse = :w');
$stmt->execute([':w' => $selectedWarehouse]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$openMatrix = [];
foreach ($rows as $row) {
    $d = (int)($row['day_of_week'] ?? 0);
    $h = (int)($row['hour'] ?? 0);
    $openMatrix[$d][$h] = true;
}

// אם אין נתונים למחסן – ברירת מחדל לפי default_hours (או 9–16)
if (empty($rows)) {
    $pdo->beginTransaction();
    try {
        // קריאת שעות ברירת מחדל
        $def = [];
        $stmtDef = $pdo->query('SELECT day_of_week, open_time, close_time FROM default_hours ORDER BY day_of_week ASC');
        foreach ($stmtDef->fetchAll(PDO::FETCH_ASSOC) as $rowDef) {
            $d = (int)($rowDef['day_of_week'] ?? 0);
            $openT  = (string)($rowDef['open_time'] ?? '09:00');
            $closeT = (string)($rowDef['close_time'] ?? '16:00');
            $def[$d] = ['open' => $openT, 'close' => $closeT];
        }

        $ins = $pdo->prepare('INSERT OR IGNORE INTO warehouse_hours (warehouse, day_of_week, hour) VALUES (:w, :d, :h)');
        foreach (array_keys($days) as $d) {
            $openH  = isset($def[$d]) ? (int)substr($def[$d]['open'], 0, 2) : 9;
            $closeH = isset($def[$d]) ? (int)substr($def[$d]['close'], 0, 2) : 16;
            foreach ($hours as $h) {
                if ($h >= $openH && $h <= $closeH) {
                    $ins->execute([
                        ':w' => $selectedWarehouse,
                        ':d' => $d,
                        ':h' => $h,
                    ]);
                    $openMatrix[$d][$h] = true;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}

// החלת שעות ברירת מחדל מהגדרות (שעות ברירת מחדל לפתיחת מחסן)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_default']) && isset($_POST['warehouse'])) {
    $applyWarehouse = trim((string)$_POST['warehouse']);
    if ($applyWarehouse !== '' && in_array($applyWarehouse, $knownWarehouses, true)) {
        $def = [];
        $stmtDef = $pdo->query('SELECT day_of_week, open_time, close_time FROM default_hours ORDER BY day_of_week ASC');
        foreach ($stmtDef->fetchAll(PDO::FETCH_ASSOC) as $rowDef) {
            $d = (int)($rowDef['day_of_week'] ?? 0);
            $openT  = (string)($rowDef['open_time'] ?? '09:00');
            $closeT = (string)($rowDef['close_time'] ?? '16:00');
            $def[$d] = ['open' => $openT, 'close' => $closeT];
        }
        for ($d = 0; $d <= 4; $d++) {
            if (!isset($def[$d])) {
                $def[$d] = ['open' => '09:00', 'close' => '16:00'];
            }
        }
        try {
            $pdo->beginTransaction();
            $del = $pdo->prepare('DELETE FROM warehouse_hours WHERE warehouse = :w');
            $del->execute([':w' => $applyWarehouse]);
            $ins = $pdo->prepare('INSERT OR IGNORE INTO warehouse_hours (warehouse, day_of_week, hour) VALUES (:w, :d, :h)');
            foreach ($hours as $h) {
                foreach (array_keys($days) as $d) {
                    $openH  = (int)substr($def[$d]['open'], 0, 2);
                    $closeH = (int)substr($def[$d]['close'], 0, 2);
                    if ($h >= $openH && $h <= $closeH) {
                        $ins->execute([':w' => $applyWarehouse, ':d' => $d, ':h' => $h]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
        }
    }
    header('Location: admin_times.php?warehouse=' . urlencode($applyWarehouse));
    exit;
}

// שמירת עדכונים
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hours_payload'])) {
    $role = (string)($me['role'] ?? '');
    $canSave = ($role === 'admin') || (($role === 'warehouse_manager') && $selectedWarehouse === $userWarehouse);
    if (!$canSave) {
        $error = 'אין הרשאה לשמור שעות למחסן זה.';
    } else {
        $payload = (string)($_POST['hours_payload'] ?? '');
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare('DELETE FROM warehouse_hours WHERE warehouse = :w');
                $del->execute([':w' => $selectedWarehouse]);

            $ins = $pdo->prepare('INSERT OR IGNORE INTO warehouse_hours (warehouse, day_of_week, hour) VALUES (:w, :d, :h)');
            foreach ($decoded as $d => $hoursRow) {
                $dInt = (int)$d;
                if (!is_array($hoursRow)) {
                    continue;
                }
                foreach ($hoursRow as $h => $isOpen) {
                    if ((int)$isOpen === 1) {
                        $ins->execute([
                            ':w' => $selectedWarehouse,
                            ':d' => $dInt,
                            ':h' => (int)$h,
                        ]);
                    }
                }
            }
            $pdo->commit();
                header('Location: admin_times.php?warehouse=' . urlencode($selectedWarehouse));
                exit;
            }
        } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול זמנים - מערכת השאלת ציוד</title>
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
        .nav-links {
            margin-top: 0.4rem;
        }
        .nav-links a {
            color: #e5e7eb;
            font-size: 0.82rem;
            margin-left: 0.75rem;
        }
        .nav-links a.active {
            font-weight: 600;
            text-decoration: underline;
        }
        main {
            max-width: 800px;
            margin: 1.5rem auto;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
            margin-bottom: 1.5rem;
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            color: #111827;
        }
        .muted-small {
            font-size: 0.85rem;
            color: #6b7280;
        }
        .flash { padding: 0.6rem 1rem; border-radius: 8px; }
        .flash.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .main-nav {
            margin-top: 0.5rem;
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
        }
        .main-nav a {
            color: #e5e7eb;
            text-decoration: none;
        }
        .main-nav-primary {
            display: flex;
            gap: 0.8rem;
        }
        .main-nav-item-wrapper {
            position: relative;
        }
        .main-nav-sub {
            position: absolute;
            right: 0;
            top: 130%;
            background: #111827;
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            box-shadow: 0 12px 30px rgba(0,0,0,0.45);
            display: none;
            min-width: 170px;
            z-index: 20;
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
        footer {
            background: #111827;
            color: #9ca3af;
            text-align: center;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            border-top: 1px solid #1f2937;
        }
        .hours-table-wrapper {
            overflow-x: auto;
        }
        table.hours-table {
            border-collapse: collapse;
            width: 100%;
            max-width: 600px;
            font-size: 0.9rem;
            margin-top: 0.75rem;
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
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h2>קביעת שעות פתיחת מחסן</h2>
        <?php if ($error !== ''): ?>
        <div class="flash error" style="margin-bottom:0.75rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="get" action="admin_times.php" style="margin-bottom:0.75rem; display:flex;align-items:center;gap:0.5rem;">
            <label for="warehouse" class="muted-small">בחר מחסן:</label>
            <select id="warehouse" name="warehouse" onchange="this.form.submit()" style="padding:0.3rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;">
                <?php foreach ($knownWarehouses as $w): ?>
                    <option value="<?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?>" <?= $w === $selectedWarehouse ? 'selected' : '' ?>>
                        <?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <p class="muted-small">
            ברירת המחדל: ימים ראשון–חמישי בין השעות 09:00–16:00 פתוחות. באפשרותך לעדכן שעות פתיחה נפרדות לכל מחסן.
        </p>

        <form method="post" action="admin_times.php?warehouse=<?= htmlspecialchars($selectedWarehouse, ENT_QUOTES, 'UTF-8') ?>" id="hours_form">
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
                            <?php foreach (array_keys($days) as $d): ?>
                                <?php $open = !empty($openMatrix[$d][$h]); ?>
                                <td class="slot-cell <?= $open ? 'slot-open' : 'slot-closed' ?>"
                                    data-day="<?= (int)$d ?>"
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
            <div class="hours-actions">
                <button type="submit" class="btn">שמירת שעות</button>
            </div>
        </form>
        <form method="post" action="admin_times.php" style="display:inline; margin-right:0.5rem;">
            <input type="hidden" name="apply_default" value="1">
            <input type="hidden" name="warehouse" value="<?= htmlspecialchars($selectedWarehouse, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn secondary">ברירת מחדל</button>
        </form>
        <div class="hours-legend">
            כחול בהיר = שעה פתוחה, אפור בהיר = שעה סגורה. ניתן לבחור טווח שעות באותו יום: לחץ על תא ראשון ואז על תא אחר עם מקש Shift לחוץ.
        </div>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
<script>
(function () {
    var cells = Array.prototype.slice.call(document.querySelectorAll('.slot-cell'));
    var lastClick = null; // {day, hour}

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
</body>
</html>
