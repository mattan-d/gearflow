<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin();

$me  = current_user();
$pdo = get_db();

// טאב פעיל בדוחות (ברירת מחדל: דוחות הזמנות)
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'orders';
if (!in_array($activeTab, ['orders', 'equipment'], true)) {
    $activeTab = 'orders';
}

// פרמטרי טווח תאריכים לדוחות הזמנות
$reportStart = isset($_GET['orders_start']) ? trim((string)$_GET['orders_start']) : '';
$reportEnd   = isset($_GET['orders_end']) ? trim((string)$_GET['orders_end']) : '';

// בחירת סטודנטים לדוח הזמנות (לפי username של יוצר ההזמנה)
$selectedStudentsRaw = isset($_GET['orders_students']) ? (string)$_GET['orders_students'] : '';
$selectedStudents = array_values(array_filter(array_map('trim', explode(',', $selectedStudentsRaw)), static function ($v) {
    return $v !== '';
}));

// קטגוריית ציוד לדוח הזמנות
$reportCategory = isset($_GET['orders_category']) ? trim((string)$_GET['orders_category']) : '';

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

    $sql = "SELECT
             COUNT(*) AS total,
             SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
             SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
             SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
             SUM(CASE WHEN status = 'on_loan'  THEN 1 ELSE 0 END) AS on_loan_count,
             SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned_count,
             SUM(CASE WHEN status = 'approved' AND DATE(end_date) < DATE('now') THEN 1 ELSE 0 END) AS not_picked_count,
             SUM(CASE WHEN status = 'on_loan'  AND DATE(end_date) < DATE('now') THEN 1 ELSE 0 END) AS not_returned_late_count
         FROM orders o
         JOIN equipment e ON e.id = o.equipment_id
         WHERE DATE(o.start_date) BETWEEN :start AND :end";

    $params = [
        ':start' => $reportStart,
        ':end'   => $reportEnd,
    ];

    if (!empty($selectedStudents)) {
        $placeholders = [];
        foreach ($selectedStudents as $idx => $u) {
            $ph = ':u' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $u;
        }
        $sql .= ' AND o.creator_username IN (' . implode(',', $placeholders) . ')';
    }

    if ($reportCategory !== '') {
        $sql .= ' AND TRIM(COALESCE(e.category, '''')) = :cat';
        $params[':cat'] = $reportCategory;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

// מקסימום לערכי גרף (למניעת חלוקה ב-0)
$ordersChartMax = max(
    1,
    $ordersReport['total'],
    $ordersReport['pending'],
    $ordersReport['approved'],
    $ordersReport['rejected'],
    $ordersReport['on_loan'],
    $ordersReport['returned'],
    $ordersReport['not_picked'],
    $ordersReport['not_returned_late']
);

// רשימת סטודנטים לבחירה (כמו במסך הזמנות)
$students = [];
$studentsStmt = $pdo->prepare(
    "SELECT username, first_name, last_name
     FROM users
     WHERE role = 'student' AND is_active = 1
     ORDER BY username ASC"
);
$studentsStmt->execute();
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// רשימת קטגוריות ציוד לדוחות (נלקחת מטבלת equipment)
$reportCategories = [];
$catRows = $pdo->query("SELECT DISTINCT category FROM equipment WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category ASC")
    ->fetchAll(PDO::FETCH_COLUMN);
foreach ($catRows as $cName) {
    $cName = trim((string)$cName);
    if ($cName !== '') {
        $reportCategories[] = $cName;
    }
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
        .orders-report-bars {
            margin-top: 1rem;
            max-width: 520px;
        }
        .orders-report-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
        }
        .orders-report-bar-label {
            min-width: 140px;
            color: #374151;
        }
        .orders-report-bar-track {
            flex: 1;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
            height: 0.65rem;
        }
        .orders-report-bar-fill {
            height: 100%;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
        }
        .orders-report-bar-value {
            min-width: 36px;
            text-align: left;
            color: #111827;
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

        <div class="tabs">
            <a href="admin_reports.php?tab=orders" class="<?= $activeTab === 'orders' ? 'active' : '' ?>">דוחות הזמנות</a>
            <a href="admin_reports.php?tab=equipment" class="<?= $activeTab === 'equipment' ? 'active' : '' ?>">דוחות ציוד</a>
        </div>

        <div id="reports-orders" class="reports-section<?= $activeTab === 'orders' ? ' active' : '' ?>">
            <p class="muted-small" style="margin-bottom:0.5rem;">
                בחר טווח תאריכים וסטודנטים כדי להציג סיכום סטטוסים להזמנות.
            </p>
            <form method="get" action="admin_reports.php" id="orders_report_form">
                <input type="hidden" name="tab" value="orders">
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

                <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
                    <div>
                        <label class="muted-small" for="orders_category">קטגוריית ציוד:</label>
                        <select name="orders_category" id="orders_category"
                                style="margin-top:0.25rem;min-width:180px;padding:0.35rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="">כל הקטגוריות</option>
                            <?php foreach ($reportCategories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $reportCategory === $cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:0.5rem;">
                    <label class="muted-small" for="orders_student_search">סינון לפי סטודנטים:</label>
                    <input type="hidden" name="orders_students" id="orders_students"
                           value="<?= htmlspecialchars($selectedStudentsRaw, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text"
                           id="orders_student_search"
                           placeholder="הקלד שם פרטי / משפחה כדי להוסיף לרשימה"
                           style="margin-top:0.25rem;width:100%;max-width:320px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;direction:rtl;">
                    <div id="orders_student_suggestions"
                         style="position:relative;max-width:320px;">
                        <div id="orders_student_suggestions_inner"
                             style="position:absolute;top:0.15rem;right:0;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 10px 25px rgba(15,23,42,0.15);z-index:30;display:none;max-height:220px;overflow-y:auto;font-size:0.85rem;"></div>
                    </div>
                    <div id="orders_selected_students"
                         style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.35rem;font-size:0.85rem;"></div>
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
                <div class="orders-report-bars">
                    <?php
                    $bars = [
                        'כמות הזמנות (סה״כ)' => $ordersReport['total'],
                        'ממתין'              => $ordersReport['pending'],
                        'מאושר'              => $ordersReport['approved'],
                        'נדחה'               => $ordersReport['rejected'],
                        'בהשאלה'             => $ordersReport['on_loan'],
                        'עבר'                => $ordersReport['returned'],
                        'הוזמנו ולא נלקחו'   => $ordersReport['not_picked'],
                        'לא הושבו בזמן'      => $ordersReport['not_returned_late'],
                    ];
                    foreach ($bars as $label => $val):
                        $val = (int)$val;
                        $pct = $ordersChartMax > 0 ? max(2, ($val / $ordersChartMax) * 100) : 0;
                    ?>
                        <div class="orders-report-bar">
                            <div class="orders-report-bar-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="orders-report-bar-track">
                                <div class="orders-report-bar-fill" style="width: <?= $pct ?>%;"></div>
                            </div>
                            <div class="orders-report-bar-value"><?= $val ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="reports-equipment" class="reports-section<?= $activeTab === 'equipment' ? ' active' : '' ?>">
            <p class="muted-small">
                כאן יוצגו דוחות על ציוד (שימוש בפריטים, תקלות, זמינות ועוד).
            </p>
        </div>
    </div>
</main>
<script>
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
    // בחירת סטודנטים לדוח הזמנות
    (function () {
        var students = <?= json_encode($students, JSON_UNESCAPED_UNICODE) ?>;
        var hidden = document.getElementById('orders_students');
        var input = document.getElementById('orders_student_search');
        var suggestionsWrap = document.getElementById('orders_student_suggestions_inner');
        var selectedWrap = document.getElementById('orders_selected_students');
        if (!hidden || !input || !suggestionsWrap || !selectedWrap || !Array.isArray(students)) return;

        function parseSelected() {
            var raw = hidden.value || '';
            return raw.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s; });
        }
        function updateHidden(usernames) {
            hidden.value = usernames.join(',');
        }
        function renderSelected() {
            var sel = parseSelected();
            selectedWrap.innerHTML = '';
            sel.forEach(function (uname) {
                var s = students.find(function (u) { return (u.username || '') === uname; });
                var label = uname;
                if (s) {
                    var full = [s.first_name, s.last_name].filter(Boolean).join(' ');
                    if (full) label = full + ' (' + uname + ')';
                }
                var pill = document.createElement('span');
                pill.textContent = label + ' ✕';
                pill.style.background = '#e5e7eb';
                pill.style.borderRadius = '999px';
                pill.style.padding = '0.2rem 0.7rem';
                pill.style.cursor = 'pointer';
                pill.addEventListener('click', function () {
                    var current = parseSelected().filter(function (u) { return u !== uname; });
                    updateHidden(current);
                    renderSelected();
                });
                selectedWrap.appendChild(pill);
            });
        }

        renderSelected();

        input.addEventListener('input', function () {
            var q = input.value.trim();
            suggestionsWrap.innerHTML = '';
            suggestionsWrap.style.display = 'none';
            if (!q) return;
            var qLower = q.toLowerCase();
            var current = parseSelected();
            var matches = students.filter(function (u) {
                var full = ((u.first_name || '') + ' ' + (u.last_name || '') + ' ' + (u.username || '')).trim();
                return full.toLowerCase().indexOf(qLower) !== -1 && current.indexOf(u.username) === -1;
            }).slice(0, 20);
            if (!matches.length) return;
            matches.forEach(function (u) {
                var fullName = [u.first_name, u.last_name].filter(Boolean).join(' ') || u.username;
                var item = document.createElement('div');
                item.textContent = fullName + ' (' + u.username + ')';
                item.style.padding = '0.25rem 0.5rem';
                item.style.cursor = 'pointer';
                item.addEventListener('mouseover', function () {
                    item.style.background = '#f3f4f6';
                });
                item.addEventListener('mouseout', function () {
                    item.style.background = 'transparent';
                });
                item.addEventListener('click', function () {
                    var cur = parseSelected();
                    cur.push(u.username);
                    updateHidden(cur);
                    renderSelected();
                    input.value = '';
                    suggestionsWrap.innerHTML = '';
                    suggestionsWrap.style.display = 'none';
                });
                suggestionsWrap.appendChild(item);
            });
            suggestionsWrap.style.display = 'block';
        });
    })();
</script>
</body>
</html>

