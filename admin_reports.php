<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin();

$me  = current_user();
$pdo = get_db();

// פרמטרי טווח תאריכים לדוחות הזמנות
$reportStart = isset($_GET['orders_start']) ? trim((string)$_GET['orders_start']) : '';
$reportEnd   = isset($_GET['orders_end']) ? trim((string)$_GET['orders_end']) : '';

$ordersReport = [
    'has_range'        => false,
    'start'            => $reportStart,
    'end'              => $reportEnd,
    'total'            => 0,
    'pending'          => 0,
    'approved'         => 0,
    'rejected'         => 0,
    'on_loan'          => 0,
    'returned'         => 0,
    'not_picked'       => 0,
    'not_returned_late'=> 0,
];

if ($reportStart !== '' && $reportEnd !== '' && $reportStart <= $reportEnd) {
    $ordersReport['has_range'] = true;
    $stmt = $pdo->prepare(
        "SELECT
             COUNT(*) AS total,
             SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
             SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
             SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
             SUM(CASE WHEN status = 'on_loan'  THEN 1 ELSE 0 END) AS on_loan_count,
             SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned_count,
             SUM(CASE WHEN status = 'approved' AND DATE(end_date) < DATE('now') THEN 1 ELSE 0 END) AS not_picked_count,
             SUM(CASE WHEN status = 'on_loan'  AND DATE(end_date) < DATE('now') THEN 1 ELSE 0 END) AS not_returned_late_count
         FROM orders
         WHERE DATE(start_date) BETWEEN :start AND :end"
    );
    $stmt->execute([
        ':start' => $reportStart,
        ':end'   => $reportEnd,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $ordersReport['total']             = (int)($row['total'] ?? 0);
    $ordersReport['pending']           = (int)($row['pending_count'] ?? 0);
    $ordersReport['approved']          = (int)($row['approved_count'] ?? 0);
    $ordersReport['rejected']          = (int)($row['rejected_count'] ?? 0);
    $ordersReport['on_loan']           = (int)($row['on_loan_count'] ?? 0);
    $ordersReport['returned']          = (int)($row['returned_count'] ?? 0);
    $ordersReport['not_picked']        = (int)($row['not_picked_count'] ?? 0);
    $ordersReport['not_returned_late'] = (int)($row['not_returned_late_count'] ?? 0);
}

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
            border-radius: 999px;
            background: #e5e7eb;
            padding: 0.2rem;
            margin-bottom: 1rem;
        }
        .reports-tab {
            padding: 0.35rem 1.1rem;
            border-radius: 999px;
            font-size: 0.82rem;
            cursor: pointer;
            color: #374151;
            background: transparent;
            border: none;
            text-decoration: none;
            transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
        }
        .reports-tab.active {
            background: #111827;
            color: #f9fafb;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(15,23,42,0.25);
        }
        .reports-section {
            display: none;
        }
        .reports-section.active {
            display: block;
        }
        .range-display {
            font-size: 0.85rem;
            color: #374151;
        }
        .range-display span {
            font-weight: 600;
        }
        .calendar-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .calendar-toggle-btn {
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background: #e5e7eb;
            color: #111827;
            padding: 0.3rem 0.9rem;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .calendar-toggle-btn.active {
            background: #111827;
            color: #f9fafb;
        }
        .calendar-panel {
            position: absolute;
            top: 2.2rem;
            right: 0;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.35);
            padding: 0.75rem;
            z-index: 40;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 2rem);
            gap: 0.25rem;
            font-size: 0.8rem;
        }
        .cal-day {
            text-align: center;
            padding: 0.3rem 0;
            border-radius: 6px;
            cursor: pointer;
        }
        .cal-day.header {
            font-weight: 600;
            cursor: default;
        }
        .cal-day.disabled {
            color: #9ca3af;
            cursor: not-allowed;
        }
        .cal-day.selected,
        .cal-day.in-range {
            background: #111827;
            color: #f9fafb;
        }
        .orders-report-table {
            width: 100%;
            max-width: 520px;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .orders-report-table th,
        .orders-report-table td {
            border: 1px solid #e5e7eb;
            padding: 0.35rem 0.5rem;
            text-align: right;
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
            <p class="muted-small" style="margin-bottom:0.5rem;">
                בחר טווח תאריכים כדי להציג סיכום סטטוסים להזמנות.
            </p>
            <form method="get" action="admin_reports.php" id="orders_report_form">
                <div class="calendar-bar" style="position:relative;">
                    <input type="hidden" name="orders_start" id="orders_start" value="<?= htmlspecialchars($ordersReport['start'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="orders_end" id="orders_end" value="<?= htmlspecialchars($ordersReport['end'], ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" id="orders_start_btn" class="calendar-toggle-btn active">תאריך התחלה</button>
                    <button type="button" id="orders_end_btn" class="calendar-toggle-btn" disabled>תאריך סיום</button>
                    <div class="range-display">
                        <?php if ($ordersReport['has_range']): ?>
                            טווח נבחר:
                            <span><?= htmlspecialchars($ordersReport['start'], ENT_QUOTES, 'UTF-8') ?></span>
                            –
                            <span><?= htmlspecialchars($ordersReport['end'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            טרם נבחר טווח תאריכים.
                        <?php endif; ?>
                    </div>
                    <div id="orders_calendar_panel" class="calendar-panel" style="display:none;">
                        <div class="calendar-grid" id="orders_calendar_grid"></div>
                    </div>
                </div>
            </form>

            <?php if ($ordersReport['has_range']): ?>
                <table class="orders-report-table">
                    <tbody>
                    <tr>
                        <th>כמות הזמנות (סה״כ)</th>
                        <td><?= (int)$ordersReport['total'] ?></td>
                    </tr>
                    <tr>
                        <th>בסטטוס "ממתין"</th>
                        <td><?= (int)$ordersReport['pending'] ?></td>
                    </tr>
                    <tr>
                        <th>בסטטוס "מאושר"</th>
                        <td><?= (int)$ordersReport['approved'] ?></td>
                    </tr>
                    <tr>
                        <th>בסטטוס "נדחה"</th>
                        <td><?= (int)$ordersReport['rejected'] ?></td>
                    </tr>
                    <tr>
                        <th>בסטטוס "בהשאלה"</th>
                        <td><?= (int)$ordersReport['on_loan'] ?></td>
                    </tr>
                    <tr>
                        <th>בסטטוס "עבר"</th>
                        <td><?= (int)$ordersReport['returned'] ?></td>
                    </tr>
                    <tr>
                        <th>הוזמנו ולא נלקחו</th>
                        <td><?= (int)$ordersReport['not_picked'] ?></td>
                    </tr>
                    <tr>
                        <th>לא הושבו בזמן</th>
                        <td><?= (int)$ordersReport['not_returned_late'] ?></td>
                    </tr>
                    </tbody>
                </table>
            <?php endif; ?>
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
    // לוח שנה לדוחות הזמנות
    (function () {
        var form       = document.getElementById('orders_report_form');
        var startInput = document.getElementById('orders_start');
        var endInput   = document.getElementById('orders_end');
        var startBtn   = document.getElementById('orders_start_btn');
        var endBtn     = document.getElementById('orders_end_btn');
        var panel      = document.getElementById('orders_calendar_panel');
        var grid       = document.getElementById('orders_calendar_grid');
        if (!form || !startInput || !endInput || !startBtn || !endBtn || !panel || !grid) return;

        var mode = 'start';
        var startDate = startInput.value || '';
        var endDate = endInput.value || '';

        function formatDate(d) {
            var y = d.getFullYear();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + day;
        }

        function buildCalendar() {
            grid.innerHTML = '';
            var today = new Date();
            var year = today.getFullYear();
            var month = today.getMonth();
            var first = new Date(year, month, 1);
            var startWeekday = (first.getDay() + 6) % 7; // להפוך את יום ראשון לסוף
            var daysInMonth = new Date(year, month + 1, 0).getDate();

            var weekdays = ['ב', 'ג', 'ד', 'ה', 'ו', 'ש', 'א'];
            weekdays.forEach(function (w) {
                var h = document.createElement('div');
                h.className = 'cal-day header';
                h.textContent = w;
                grid.appendChild(h);
            });

            for (var i = 0; i < startWeekday; i++) {
                var empty = document.createElement('div');
                empty.className = 'cal-day disabled';
                grid.appendChild(empty);
            }

            for (var day = 1; day <= daysInMonth; day++) {
                (function (d) {
                    var date = new Date(year, month, d);
                    var dateStr = formatDate(date);
                    var cell = document.createElement('div');
                    cell.className = 'cal-day';
                    cell.textContent = d;

                    if (startDate && !endDate && dateStr === startDate) {
                        cell.classList.add('selected');
                    }
                    if (startDate && endDate && dateStr >= startDate && dateStr <= endDate) {
                        cell.classList.add('in-range');
                    }

                    cell.addEventListener('click', function () {
                        if (mode === 'start') {
                            startDate = dateStr;
                            endDate = '';
                            startInput.value = startDate;
                            endInput.value = '';
                            startBtn.classList.add('active');
                            endBtn.classList.remove('active');
                            endBtn.disabled = false;
                            mode = 'end';
                        } else {
                            if (!startDate || dateStr < startDate) {
                                startDate = dateStr;
                                endDate = '';
                                startInput.value = startDate;
                                endInput.value = '';
                                mode = 'end';
                            } else {
                                endDate = dateStr;
                                endInput.value = endDate;
                                mode = 'start';
                                startBtn.classList.add('active');
                                endBtn.classList.remove('active');
                                setTimeout(function () {
                                    panel.style.display = 'none';
                                    form.submit();
                                }, 800);
                            }
                        }
                        buildCalendar();
                    });

                    grid.appendChild(cell);
                })(day);
            }
        }

        startBtn.addEventListener('click', function () {
            mode = 'start';
            startBtn.classList.add('active');
            endBtn.classList.remove('active');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            buildCalendar();
        });

        endBtn.addEventListener('click', function () {
            if (endBtn.disabled) return;
            mode = 'end';
            startBtn.classList.remove('active');
            endBtn.classList.add('active');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            buildCalendar();
        });
    })();
</script>
</body>
</html>

