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

// סטטוס הזמנה לדוח (ריק או 'הכל' = כל הסטטוסים)
$reportStatus = isset($_GET['orders_status']) ? trim((string)$_GET['orders_status']) : '';

// סינון נוסף לדוח הזמנות עבור הזמנות במצב "עבר"
$reportReturnStatus = isset($_GET['orders_return_status']) ? trim((string)$_GET['orders_return_status']) : '';
$reportEquipCondition = isset($_GET['orders_equip_condition']) ? trim((string)$_GET['orders_equip_condition']) : '';

// רשימת סטטוסים מהטבלה (לקומבו בוקס)
$orderStatusLabels = [];
$orderStatusStmt = $pdo->query('SELECT status, label_he FROM order_status_labels ORDER BY status ASC');
if ($orderStatusStmt) {
    $orderStatusLabels = $orderStatusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

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

// הדוח מוצג רק לאחר לחיצה על "הצג"
if (isset($_GET['orders_show'])) {
    $ordersReport['has_range'] = true;

    $sql = "SELECT
             COUNT(*) AS total,
             SUM(CASE WHEN o.status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
             SUM(CASE WHEN o.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
             SUM(CASE WHEN o.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
             SUM(CASE WHEN o.status = 'on_loan'  THEN 1 ELSE 0 END) AS on_loan_count,
             SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END) AS returned_count,
             SUM(CASE WHEN o.status = 'approved' AND DATE(o.end_date) < DATE('now') THEN 1 ELSE 0 END) AS not_picked_count,
             SUM(CASE WHEN o.status = 'on_loan'  AND DATE(o.end_date) < DATE('now') THEN 1 ELSE 0 END) AS not_returned_late_count
         FROM orders o
         JOIN equipment e ON e.id = o.equipment_id
         WHERE 1=1";
    $params = [];

    // טווח תאריכים: אם לא נבחר – הדוח על כל התאריכים
    if ($reportStart !== '' && $reportEnd !== '' && $reportStart <= $reportEnd) {
        $sql .= " AND DATE(o.start_date) BETWEEN :start AND :end";
        $params[':start'] = $reportStart;
        $params[':end']   = $reportEnd;
    }

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
        $sql .= " AND TRIM(COALESCE(e.category, '')) = :cat";
        $params[':cat'] = $reportCategory;
    }

    if ($reportStatus !== '' && $reportStatus !== 'הכל') {
        if ($reportStatus === 'not_returned') {
            $sql .= " AND o.status = 'on_loan' AND DATE(o.end_date) < DATE('now')";
        } elseif ($reportStatus === 'not_picked') {
            $sql .= " AND o.status = 'approved' AND DATE(o.start_date) < DATE('now')";
        } else {
            $sql .= " AND o.status = :status";
            $params[':status'] = $reportStatus;
        }
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

// --- דוח ציוד ---
$eqCategory     = isset($_GET['eq_category']) ? trim((string)$_GET['eq_category']) : '';
$eqEquipmentId  = isset($_GET['eq_equipment']) ? (int)$_GET['eq_equipment'] : 0;
$eqReportType   = isset($_GET['eq_report_type']) ? trim((string)$_GET['eq_report_type']) : '';
if (!in_array($eqReportType, ['status', 'orders', 'availability'], true)) {
    $eqReportType = 'status';
}
$eqStatus       = isset($_GET['eq_status']) ? trim((string)$_GET['eq_status']) : '';
$eqStart        = isset($_GET['eq_start']) ? trim((string)$_GET['eq_start']) : '';
$eqEnd          = isset($_GET['eq_end']) ? trim((string)$_GET['eq_end']) : '';
$eqAvailability = isset($_GET['eq_availability']) ? trim((string)$_GET['eq_availability']) : 'פנוי';
$eqShow         = isset($_GET['eq_show']);

$equipmentReport = ['show' => false, 'order_counts' => [], 'availability' => [], 'status_ok' => 0, 'status_not_ok' => 0, 'chart_max' => 1];
$equipmentListAll = [];
$eqStmt = $pdo->query("SELECT id, name, code, category, status FROM equipment ORDER BY category ASC, name ASC");
if ($eqStmt) {
    $equipmentListAll = $eqStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($eqShow) {
    $equipmentReport['show'] = true;
    $eqWhere = "1=1";
    $eqParams = [];
    if ($eqCategory !== '' && $eqCategory !== 'הכל') {
        $eqWhere .= " AND TRIM(COALESCE(e.category, '')) = :eq_cat";
        $eqParams[':eq_cat'] = $eqCategory;
    }
    if ($eqEquipmentId > 0) {
        $eqWhere .= " AND e.id = :eq_id";
        $eqParams[':eq_id'] = $eqEquipmentId;
    }
    if ($eqStatus === 'תקין') {
        $eqWhere .= " AND e.status = 'active'";
    } elseif ($eqStatus === 'לא תקין') {
        $eqWhere .= " AND (e.status IS NULL OR e.status != 'active')";
    }

    $eqWhereNoStatus = "1=1";
    $eqParamsStatus = [];
    if ($eqCategory !== '' && $eqCategory !== 'הכל') {
        $eqWhereNoStatus .= " AND TRIM(COALESCE(e.category, '')) = :eq_cat_s";
        $eqParamsStatus[':eq_cat_s'] = $eqCategory;
    }
    if ($eqEquipmentId > 0) {
        $eqWhereNoStatus .= " AND e.id = :eq_id_s";
        $eqParamsStatus[':eq_id_s'] = $eqEquipmentId;
    }
    if ($eqReportType === 'status') {
        $sqlStatus = "SELECT
                      SUM(CASE WHEN e.status = 'active' THEN 1 ELSE 0 END) AS ok_count,
                      SUM(CASE WHEN e.status IS NULL OR e.status != 'active' THEN 1 ELSE 0 END) AS not_ok_count
                      FROM equipment e WHERE " . $eqWhereNoStatus;
        $stStatus = $pdo->prepare($sqlStatus);
        $stStatus->execute($eqParamsStatus);
        $rowStatus = $stStatus->fetch(PDO::FETCH_ASSOC) ?: [];
        $equipmentReport['status_ok'] = (int)($rowStatus['ok_count'] ?? 0);
        $equipmentReport['status_not_ok'] = (int)($rowStatus['not_ok_count'] ?? 0);
    }

    if ($eqReportType === 'orders') {
        $joinCond = "o.equipment_id = e.id";
        if ($eqStart !== '' && $eqEnd !== '' && $eqStart <= $eqEnd) {
            $joinCond .= " AND DATE(o.start_date) <= :eq_end AND DATE(o.end_date) >= :eq_start";
            $eqParams[':eq_start'] = $eqStart;
            $eqParams[':eq_end']   = $eqEnd;
        }
        $sqlEq = "SELECT e.id, e.name, e.code, e.category,
                  COUNT(o.id) AS order_count
                  FROM equipment e
                  LEFT JOIN orders o ON " . $joinCond . "
                  WHERE " . $eqWhere . " GROUP BY e.id, e.name, e.code, e.category ORDER BY order_count DESC, e.name ASC";
        $stEq = $pdo->prepare($sqlEq);
        $stEq->execute($eqParams);
        $equipmentReport['order_counts'] = $stEq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $hasRange = $eqStart !== '' && $eqEnd !== '' && $eqStart <= $eqEnd;
    if ($eqReportType === 'availability' && $hasRange) {
        $avParams = [];
        if ($eqCategory !== '' && $eqCategory !== 'הכל') {
            $avParams[':eq_cat'] = $eqCategory;
        }
        if ($eqEquipmentId > 0) {
            $avParams[':eq_id'] = $eqEquipmentId;
        }
        $avParams[':av_start'] = $eqStart;
        $avParams[':av_end']   = $eqEnd;

        $wantAvailable = ($eqAvailability === 'פנוי');
        if ($wantAvailable) {
            $sqlAv = "SELECT e.id, e.name, e.code, e.category
                      FROM equipment e
                      WHERE " . $eqWhere . "
                      AND NOT EXISTS (
                          SELECT 1 FROM orders o
                          WHERE o.equipment_id = e.id
                          AND o.status IN ('pending', 'approved', 'on_loan')
                          AND DATE(o.start_date) <= :av_end AND DATE(o.end_date) >= :av_start
                      )
                      ORDER BY e.category ASC, e.name ASC";
        } else {
            $sqlAv = "SELECT DISTINCT e.id, e.name, e.code, e.category
                      FROM equipment e
                      INNER JOIN orders o ON o.equipment_id = e.id
                      WHERE " . $eqWhere . "
                      AND o.status IN ('pending', 'approved', 'on_loan')
                      AND DATE(o.start_date) <= :av_end AND DATE(o.end_date) >= :av_start
                      ORDER BY e.category ASC, e.name ASC";
        }
        $stAv = $pdo->prepare($sqlAv);
        $stAv->execute($avParams);
        $equipmentReport['availability'] = $stAv->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $equipmentReport['chart_max'] = 1;
    foreach ($equipmentReport['order_counts'] as $r) {
        $c = (int)($r['order_count'] ?? 0);
        if ($c > $equipmentReport['chart_max']) {
            $equipmentReport['chart_max'] = $c;
        }
    }
    if (count($equipmentReport['availability']) > $equipmentReport['chart_max']) {
        $equipmentReport['chart_max'] = max($equipmentReport['chart_max'], count($equipmentReport['availability']));
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
        .report-params-title {
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.75rem;
        }
        .report-params-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 1.25rem;
            margin-bottom: 0.75rem;
        }
        .report-param-block {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .report-param-block .param-label {
            font-size: 0.8rem;
            color: #4b5563;
            margin-bottom: 0.25rem;
            font-weight: 500;
            min-height: 1.35em;
            line-height: 1.35;
            white-space: nowrap;
            display: block;
        }
        .btn {
            border: none;
            border-radius: 999px;
            padding: 0.5rem 1.1rem;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .btn:hover {
            background: linear-gradient(135deg, #4338ca, #4f46e5);
        }
        .report-param-block.report-param-show-btn {
            flex-shrink: 0;
        }
        .report-param-block.eq-calendar-bar {
            position: relative;
        }
        .report-param-block .param-value-hint {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.2rem;
        }
        .calendar-icon-btn {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #374151;
            font-size: 1.15rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .calendar-icon-btn:hover {
            background: #e5e7eb;
        }
        .calendar-icon-btn.active {
            background: #111827;
            color: #f9fafb;
            border-color: #111827;
        }
        .calendar-icon-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .range-display {
            font-size: 0.85rem;
            color: #374151;
        }
        .range-display span {
            font-weight: 600;
        }
        .calendar-bar {
            position: relative;
            margin-bottom: 0;
        }
        .report-param-block.calendar-bar {
            position: relative;
        }
        .calendar-panel {
            position: absolute;
            top: 4rem;
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
        .cal-mode-btns {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.6rem;
            justify-content: flex-end;
        }
        .cal-mode-btn {
            padding: 0.3rem 0.65rem;
            font-size: 0.8rem;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #4b5563;
            cursor: default;
        }
        .cal-mode-btn.active {
            background: #111827;
            color: #f9fafb;
            border-color: #111827;
        }
        .orders-report-bars {
            margin-top: 0.75rem;
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
            <p class="muted-small" style="margin-bottom:0.75rem;">
                בחר טווח תאריכים וסטודנטים כדי להציג סיכום סטטוסים להזמנות.
            </p>
            <form method="get" action="admin_reports.php" id="orders_report_form">
                <input type="hidden" name="tab" value="orders">
                <input type="hidden" name="orders_start" id="orders_start" value="<?= htmlspecialchars($ordersReport['start'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="orders_end" id="orders_end" value="<?= htmlspecialchars($ordersReport['end'], ENT_QUOTES, 'UTF-8') ?>">

                <h3 class="report-params-title">פרמטרים להצגת דוח</h3>
                <div class="report-params-row">
                    <div class="report-param-block">
                        <label class="param-label" for="orders_status">סטטוס הזמנה</label>
                        <select name="orders_status" id="orders_status"
                                style="min-width:130px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="הכל" <?= ($reportStatus === '' || $reportStatus === 'הכל') ? 'selected' : '' ?>>הכל</option>
                            <?php foreach ($orderStatusLabels as $sl): ?>
                                <option value="<?= htmlspecialchars($sl['status'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $reportStatus === $sl['status'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sl['label_he'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($reportStatus === 'returned'): ?>
                        <div class="report-param-block">
                            <label class="param-label" for="orders_return_status">סטטוס החזרה</label>
                            <select name="orders_return_status" id="orders_return_status"
                                    style="min-width:130px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                                <option value="" <?= $reportReturnStatus === '' ? 'selected' : '' ?>>הכל</option>
                                <option value="לא נאסף" <?= $reportReturnStatus === 'לא נאסף' ? 'selected' : '' ?>>לא נאסף</option>
                                <option value="לא הוחזר בזמן" <?= $reportReturnStatus === 'לא הוחזר בזמן' ? 'selected' : '' ?>>לא הוחזר בזמן</option>
                            </select>
                        </div>
                        <div class="report-param-block">
                            <label class="param-label" for="orders_equip_condition">סטטוס ציוד מוחזר</label>
                            <select name="orders_equip_condition" id="orders_equip_condition"
                                    style="min-width:130px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                                <option value="" <?= $reportEquipCondition === '' ? 'selected' : '' ?>>הכל</option>
                                <option value="תקין" <?= $reportEquipCondition === 'תקין' ? 'selected' : '' ?>>תקין</option>
                                <option value="תקול" <?= $reportEquipCondition === 'תקול' ? 'selected' : '' ?>>תקול</option>
                                <option value="חסר" <?= $reportEquipCondition === 'חסר' ? 'selected' : '' ?>>חסר</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="report-param-block calendar-bar">
                        <span class="param-label">תאריך התחלה וסיום (לוח שנה אחד)</span>
                        <button type="button" id="orders_range_btn" class="calendar-icon-btn" title="בחירת תאריך התחלה ותאריך סיום" aria-label="לוח שנה – בחירת תאריך התחלה וסיום"><i data-lucide="calendar" aria-hidden="true"></i></button>
                        <span class="param-value-hint" id="orders_range_hint">
                            <?php if ($ordersReport['start'] !== '' && $ordersReport['end'] !== ''): ?>
                                מ־<?= htmlspecialchars($ordersReport['start'], ENT_QUOTES, 'UTF-8') ?> עד <?= htmlspecialchars($ordersReport['end'], ENT_QUOTES, 'UTF-8') ?>
                            <?php elseif ($ordersReport['start'] !== ''): ?>
                                מ־<?= htmlspecialchars($ordersReport['start'], ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                לא נבחר
                            <?php endif; ?>
                        </span>
                        <div id="orders_calendar_panel" class="calendar-panel" style="display:none;">
                            <div class="cal-mode-btns">
                                <button type="button" class="cal-mode-btn active" id="cal_btn_start">התחלה</button>
                                <button type="button" class="cal-mode-btn" id="cal_btn_end">סיום</button>
                            </div>
                            <div class="calendar-grid" id="orders_calendar_grid"></div>
                        </div>
                    </div>
                    <div class="report-param-block">
                        <label class="param-label" for="orders_category">קטגוריית ציוד</label>
                        <select name="orders_category" id="orders_category"
                                style="min-width:130px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="">כל הקטגוריות</option>
                            <?php foreach ($reportCategories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $reportCategory === $cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="report-param-block" style="flex:1;min-width:150px;max-width:200px;">
                        <label class="param-label" for="orders_student_search">סינון לפי סטודנטים</label>
                        <input type="hidden" name="orders_students" id="orders_students"
                               value="<?= htmlspecialchars($selectedStudentsRaw, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="text"
                               id="orders_student_search"
                               placeholder="הקלד שם כדי להוסיף לרשימה"
                               style="width:100%;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;direction:rtl;">
                        <div id="orders_student_suggestions" style="position:relative;">
                            <div id="orders_student_suggestions_inner"
                                 style="position:absolute;top:0.15rem;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 10px 25px rgba(15,23,42,0.15);z-index:30;display:none;max-height:220px;overflow-y:auto;font-size:0.85rem;"></div>
                        </div>
                        <div id="orders_selected_students"
                             style="margin-top:0.35rem;display:flex;flex-wrap:wrap;gap:0.35rem;font-size:0.85rem;"></div>
                    </div>
                    <div class="report-param-block report-param-show-btn">
                        <span class="param-label" aria-hidden="true">&nbsp;</span>
                        <button type="submit" name="orders_show" value="1" class="btn">הצג</button>
                    </div>
                </div>
            </form>

            <?php if ($ordersReport['has_range']): ?>
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
            <p class="muted-small" style="margin-bottom:0.75rem;">
                בחר פריט ציוד (קטגוריה ופריט) וסוג דוח. לפי סוג הדוח יוצגו הפקדים הרלוונטיים.
            </p>
            <form method="get" action="admin_reports.php" id="equipment_report_form">
                <input type="hidden" name="tab" value="equipment">
                <input type="hidden" name="eq_start" id="eq_start" value="<?= htmlspecialchars($eqStart, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="eq_end" id="eq_end" value="<?= htmlspecialchars($eqEnd, ENT_QUOTES, 'UTF-8') ?>">

                <h3 class="report-params-title">פרמטרים להצגת דוח</h3>
                <div class="report-params-row">
                    <div class="report-param-block">
                        <label class="param-label" for="eq_category">קטגוריית ציוד</label>
                        <select name="eq_category" id="eq_category"
                                style="min-width:130px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="הכל" <?= ($eqCategory === '' || $eqCategory === 'הכל') ? 'selected' : '' ?>>הכל</option>
                            <?php foreach ($reportCategories as $c): ?>
                                <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>" <?= $eqCategory === $c ? 'selected' : '' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="report-param-block">
                        <label class="param-label" for="eq_equipment">פריט ציוד</label>
                        <select name="eq_equipment" id="eq_equipment"
                                style="min-width:160px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="">הכל</option>
                            <?php foreach ($equipmentListAll as $eq): ?>
                                <option value="<?= (int)$eq['id'] ?>" data-category="<?= htmlspecialchars(trim((string)($eq['category'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $eqEquipmentId === (int)$eq['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($eq['name'] . ' (' . $eq['code'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="report-param-block">
                        <label class="param-label" for="eq_report_type">סוג דוח</label>
                        <select name="eq_report_type" id="eq_report_type"
                                style="min-width:160px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="status" <?= $eqReportType === 'status' ? 'selected' : '' ?>>דוח תקינות</option>
                            <option value="orders" <?= $eqReportType === 'orders' ? 'selected' : '' ?>>דוח כמות הזמנות</option>
                            <option value="availability" <?= $eqReportType === 'availability' ? 'selected' : '' ?>>דוח זמינות</option>
                        </select>
                    </div>
                    <div class="report-param-block" id="eq_type_status_wrap" style="<?= $eqReportType === 'status' ? '' : 'display:none;' ?>">
                        <label class="param-label" for="eq_status">תקין / לא תקין</label>
                        <select name="eq_status" id="eq_status"
                                style="min-width:110px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="הכל" <?= ($eqStatus === '' || $eqStatus === 'הכל') ? 'selected' : '' ?>>הכל</option>
                            <option value="תקין" <?= $eqStatus === 'תקין' ? 'selected' : '' ?>>תקין</option>
                            <option value="לא תקין" <?= $eqStatus === 'לא תקין' ? 'selected' : '' ?>>לא תקין</option>
                        </select>
                    </div>
                    <div class="report-param-block calendar-bar eq-calendar-bar" id="eq_type_availability_wrap" style="<?= $eqReportType === 'availability' ? '' : 'display:none;' ?>">
                        <span class="param-label">תאריך התחלה וסיום</span>
                        <button type="button" id="eq_range_btn" class="calendar-icon-btn" title="בחירת טווח" aria-label="טווח תאריכים"><i data-lucide="calendar" aria-hidden="true"></i></button>
                        <span class="param-value-hint" id="eq_range_hint">
                            <?php if ($eqStart !== '' && $eqEnd !== ''): ?>
                                מ־<?= htmlspecialchars($eqStart, ENT_QUOTES, 'UTF-8') ?> עד <?= htmlspecialchars($eqEnd, ENT_QUOTES, 'UTF-8') ?>
                            <?php elseif ($eqStart !== ''): ?>
                                מ־<?= htmlspecialchars($eqStart, ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                לא נבחר
                            <?php endif; ?>
                        </span>
                        <div id="eq_calendar_panel" class="calendar-panel" style="display:none;">
                            <div class="cal-mode-btns">
                                <button type="button" class="cal-mode-btn active" id="eq_cal_btn_start">התחלה</button>
                                <button type="button" class="cal-mode-btn" id="eq_cal_btn_end">סיום</button>
                            </div>
                            <div class="calendar-grid" id="eq_calendar_grid"></div>
                        </div>
                    </div>
                    <div class="report-param-block" id="eq_availability_wrap" style="<?= ($eqReportType === 'availability' && $eqStart !== '' && $eqEnd !== '') ? '' : 'display:none;' ?>">
                        <label class="param-label" for="eq_availability">פנוי / מוזמן</label>
                        <select name="eq_availability" id="eq_availability"
                                style="min-width:100px;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.85rem;">
                            <option value="פנוי" <?= $eqAvailability === 'פנוי' ? 'selected' : '' ?>>פנוי</option>
                            <option value="מוזמן" <?= $eqAvailability === 'מוזמן' ? 'selected' : '' ?>>מוזמן</option>
                        </select>
                    </div>
                    <div class="report-param-block report-param-show-btn">
                        <span class="param-label" aria-hidden="true">&nbsp;</span>
                        <button type="submit" name="eq_show" value="1" class="btn">הצג</button>
                    </div>
                </div>
            </form>

            <?php if ($equipmentReport['show']): ?>
                <?php if ($eqReportType === 'status'): ?>
                    <h3 style="margin:1rem 0 0.5rem;font-size:1rem;color:#374151;">דוח תקינות – תקין / לא תקין</h3>
                    <div class="orders-report-bars">
                        <?php
                        $stMax = max(1, $equipmentReport['status_ok'], $equipmentReport['status_not_ok']);
                        $pctOk = $stMax > 0 ? max(2, ($equipmentReport['status_ok'] / $stMax) * 100) : 0;
                        $pctNot = $stMax > 0 ? max(2, ($equipmentReport['status_not_ok'] / $stMax) * 100) : 0;
                        ?>
                        <div class="orders-report-bar">
                            <div class="orders-report-bar-label">תקין</div>
                            <div class="orders-report-bar-track">
                                <div class="orders-report-bar-fill" style="width: <?= $pctOk ?>%;"></div>
                            </div>
                            <div class="orders-report-bar-value"><?= (int)$equipmentReport['status_ok'] ?></div>
                        </div>
                        <div class="orders-report-bar">
                            <div class="orders-report-bar-label">לא תקין</div>
                            <div class="orders-report-bar-track">
                                <div class="orders-report-bar-fill" style="width: <?= $pctNot ?>%;"></div>
                            </div>
                            <div class="orders-report-bar-value"><?= (int)$equipmentReport['status_not_ok'] ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($eqReportType === 'orders' && !empty($equipmentReport['order_counts'])): ?>
                    <h3 style="margin:1rem 0 0.5rem;font-size:1rem;color:#374151;">כמות הזמנות לפי פריט ציוד</h3>
                    <div class="orders-report-bars">
                        <?php
                        $eqMax = max(1, $equipmentReport['chart_max']);
                        foreach ($equipmentReport['order_counts'] as $row):
                            $cnt = (int)($row['order_count'] ?? 0);
                            $pct = $eqMax > 0 ? max(2, ($cnt / $eqMax) * 100) : 0;
                            $label = htmlspecialchars($row['name'] . ' (' . $row['code'] . ')', ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="orders-report-bar">
                                <div class="orders-report-bar-label"><?= $label ?></div>
                                <div class="orders-report-bar-track">
                                    <div class="orders-report-bar-fill" style="width: <?= $pct ?>%;"></div>
                                </div>
                                <div class="orders-report-bar-value"><?= $cnt ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($eqReportType === 'orders'): ?>
                    <p class="muted-small" style="margin-top:0.75rem;">לא נמצאו פריטי ציוד התואמים לסינון.</p>
                <?php endif; ?>

                <?php
                $hasRange = $eqStart !== '' && $eqEnd !== '' && $eqStart <= $eqEnd;
                if ($eqReportType === 'availability' && $hasRange): ?>
                    <h3 style="margin:1rem 0 0.5rem;font-size:1rem;color:#374151;">פריטים <?= $eqAvailability === 'פנוי' ? 'פנויים' : 'מוזמנים' ?> בתקופה מ־<?= htmlspecialchars($eqStart, ENT_QUOTES, 'UTF-8') ?> עד <?= htmlspecialchars($eqEnd, ENT_QUOTES, 'UTF-8') ?></h3>
                    <?php if (!empty($equipmentReport['availability'])): ?>
                        <div class="orders-report-bars">
                            <?php
                            $avList = $equipmentReport['availability'];
                            $eqMaxAv = max(1, count($avList));
                            foreach ($avList as $idx => $row):
                                $pct = max(2, (1 / $eqMaxAv) * 100);
                                $label = htmlspecialchars($row['name'] . ' (' . $row['code'] . ')', ENT_QUOTES, 'UTF-8');
                            ?>
                                <div class="orders-report-bar">
                                    <div class="orders-report-bar-label"><?= $label ?></div>
                                    <div class="orders-report-bar-track">
                                        <div class="orders-report-bar-fill" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                    <div class="orders-report-bar-value">—</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="muted-small" style="margin-top:0.75rem;">לא נמצאו פריטים <?= $eqAvailability === 'פנוי' ? 'פנויים' : 'מוזמנים' ?> בתקופה הנבחרת.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($equipmentReport['show'] && $eqReportType === 'availability' && !$hasRange): ?>
                    <p class="muted-small" style="margin-top:0.75rem;">בחר טווח תאריכים ולחץ הצג.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>
    // לוח שנה לדוחות הזמנות
    (function () {
        var form       = document.getElementById('orders_report_form');
        var startInput = document.getElementById('orders_start');
        var endInput   = document.getElementById('orders_end');
        var rangeBtn   = document.getElementById('orders_range_btn');
        var rangeHint  = document.getElementById('orders_range_hint');
        var panel      = document.getElementById('orders_calendar_panel');
        var grid       = document.getElementById('orders_calendar_grid');
        var calBtnStart = document.getElementById('cal_btn_start');
        var calBtnEnd   = document.getElementById('cal_btn_end');
        if (!form || !startInput || !endInput || !rangeBtn || !panel || !grid) return;

        var startDate = startInput.value || '';
        var endDate = endInput.value || '';

        function updateHint() {
            if (!rangeHint) return;
            if (startDate && endDate) {
                rangeHint.textContent = 'מ־' + startDate + ' עד ' + endDate;
            } else if (startDate) {
                rangeHint.textContent = 'מ־' + startDate;
            } else {
                rangeHint.textContent = 'לא נבחר';
            }
        }
        updateHint();

        function updateCalModeButtons() {
            if (!calBtnStart || !calBtnEnd) return;
            var inStartMode = !startDate || (startDate && endDate);
            calBtnStart.classList.toggle('active', inStartMode);
            calBtnEnd.classList.toggle('active', startDate && !endDate);
        }

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

                    if (startDate && endDate && dateStr >= startDate && dateStr <= endDate) {
                        cell.classList.add('in-range');
                    } else if (startDate && !endDate && dateStr === startDate) {
                        cell.classList.add('selected');
                    }

                    cell.addEventListener('click', function () {
                        // בחירה ראשונה או התחלה חדשה
                        if (!startDate || (startDate && endDate)) {
                            startDate = dateStr;
                            endDate = '';
                            startInput.value = startDate;
                            endInput.value = '';
                            updateCalModeButtons();
                        } else {
                            // בחירה שנייה – קביעת תאריך סיום
                            if (dateStr < startDate) {
                                // אם נבחר תאריך מוקדם יותר – תהפוך אותו להתחלה
                                endDate = startDate;
                                startDate = dateStr;
                            } else {
                                endDate = dateStr;
                            }
                            startInput.value = startDate;
                            endInput.value = endDate;
                            setTimeout(function() {
                                panel.style.display = 'none';
                            }, 1000);
                        }
                        updateHint();
                        buildCalendar();
                    });

                    grid.appendChild(cell);
                })(day);
            }
            updateCalModeButtons();
        }

        rangeBtn.addEventListener('click', function () {
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
                pill.innerHTML = label + ' <i data-lucide="x" aria-hidden="true"></i>';
                pill.style.background = '#e5e7eb';
                pill.style.borderRadius = '999px';
                pill.style.padding = '0.2rem 0.7rem';
                pill.style.cursor = 'pointer';
                pill.setAttribute('role', 'button');
                pill.setAttribute('aria-label', 'הסר ' + label);
                pill.addEventListener('click', function () {
                    var current = parseSelected().filter(function (u) { return u !== uname; });
                    updateHidden(current);
                    renderSelected();
                });
                selectedWrap.appendChild(pill);
            });
            if (window.lucide) lucide.createIcons();
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

    (function() {
        var eqCat = document.getElementById('eq_category');
        var eqSelect = document.getElementById('eq_equipment');
        if (!eqCat || !eqSelect) return;
        var options = Array.prototype.slice.call(eqSelect.querySelectorAll('option'));
        function filterEqByCategory() {
            var cat = (eqCat.value || '').trim();
            var hasAll = eqSelect.querySelector('option[value=""]');
            options.forEach(function(opt) {
                if (opt.value === '') {
                    opt.style.display = '';
                    return;
                }
                var optCat = (opt.getAttribute('data-category') || '').trim();
                if (cat === '' || cat === 'הכל' || optCat === cat) {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });
            var visible = Array.prototype.filter.call(eqSelect.options, function(o) { return o.style.display !== 'none' && o.value !== ''; });
            if (visible.length && eqSelect.value && eqSelect.querySelector('option[value="' + eqSelect.value + '"]').style.display === 'none') {
                eqSelect.value = '';
            }
        }
        eqCat.addEventListener('change', filterEqByCategory);
        filterEqByCategory();
    })();

    (function() {
        var reportType = document.getElementById('eq_report_type');
        var statusWrap = document.getElementById('eq_type_status_wrap');
        var availabilityWrap = document.getElementById('eq_type_availability_wrap');
        var availWrap = document.getElementById('eq_availability_wrap');
        if (!reportType) return;
        function toggleByReportType() {
            var t = (reportType.value || '').trim();
            if (statusWrap) statusWrap.style.display = (t === 'status') ? '' : 'none';
            if (availabilityWrap) availabilityWrap.style.display = (t === 'availability') ? '' : 'none';
            if (availWrap) {
                var startInput = document.getElementById('eq_start');
                var endInput = document.getElementById('eq_end');
                var hasRange = startInput && endInput && startInput.value && endInput.value;
                availWrap.style.display = (t === 'availability' && hasRange) ? '' : 'none';
            }
        }
        reportType.addEventListener('change', toggleByReportType);
        toggleByReportType();
    })();

    (function() {
        var form = document.getElementById('equipment_report_form');
        var startInput = document.getElementById('eq_start');
        var endInput = document.getElementById('eq_end');
        var rangeBtn = document.getElementById('eq_range_btn');
        var rangeHint = document.getElementById('eq_range_hint');
        var panel = document.getElementById('eq_calendar_panel');
        var grid = document.getElementById('eq_calendar_grid');
        var availWrap = document.getElementById('eq_availability_wrap');
        var eqCalBtnStart = document.getElementById('eq_cal_btn_start');
        var eqCalBtnEnd = document.getElementById('eq_cal_btn_end');
        if (!form || !startInput || !endInput || !rangeBtn || !panel || !grid) return;

        var startDate = startInput.value || '';
        var endDate = endInput.value || '';
        function updateEqCalModeButtons() {
            if (!eqCalBtnStart || !eqCalBtnEnd) return;
            var inStartMode = !startDate || (startDate && endDate);
            eqCalBtnStart.classList.toggle('active', inStartMode);
            eqCalBtnEnd.classList.toggle('active', !!(startDate && !endDate));
        }
        function updateHint() {
            if (!rangeHint) return;
            if (startDate && endDate) rangeHint.textContent = 'מ־' + startDate + ' עד ' + endDate;
            else if (startDate) rangeHint.textContent = 'מ־' + startDate;
            else rangeHint.textContent = 'לא נבחר';
        }
        function updateAvailVisibility() {
            if (!availWrap) return;
            var rt = document.getElementById('eq_report_type');
            var isAvail = rt && (rt.value || '').trim() === 'availability';
            availWrap.style.display = (isAvail && startDate && endDate) ? '' : 'none';
        }
        updateHint();
        updateAvailVisibility();
        updateEqCalModeButtons();

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
            var startWeekday = (first.getDay() + 6) % 7;
            var daysInMonth = new Date(year, month + 1, 0).getDate();
            var weekdays = ['ב', 'ג', 'ד', 'ה', 'ו', 'ש', 'א'];
            weekdays.forEach(function(w) {
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
                (function(d) {
                    var date = new Date(year, month, d);
                    var dateStr = formatDate(date);
                    var cell = document.createElement('div');
                    cell.className = 'cal-day';
                    cell.textContent = d;
                    if (startDate && endDate && dateStr >= startDate && dateStr <= endDate) cell.classList.add('in-range');
                    else if (startDate && !endDate && dateStr === startDate) cell.classList.add('selected');
                    cell.addEventListener('click', function() {
                        if (!startDate || (startDate && endDate)) {
                            startDate = dateStr;
                            endDate = '';
                        } else {
                            if (dateStr < startDate) { endDate = startDate; startDate = dateStr; }
                            else endDate = dateStr;
                            setTimeout(function() {
                                panel.style.display = 'none';
                            }, 1000);
                        }
                        startInput.value = startDate;
                        endInput.value = endDate;
                        updateHint();
                        updateAvailVisibility();
                        updateEqCalModeButtons();
                        buildCalendar();
                    });
                    grid.appendChild(cell);
                })(day);
            }
            updateEqCalModeButtons();
        }
        rangeBtn.addEventListener('click', function() {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            buildCalendar();
        });
    })();
</script>
</body>
</html>

