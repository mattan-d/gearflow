<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$pdo      = get_db();
$error    = '';
$success  = '';
$editingOrder = null;

// עריכת הזמנה קיימת - טעינת נתונים לטופס
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    if ($editId > 0) {
        $stmt = $pdo->prepare(
            'SELECT o.*,
                    e.name AS equipment_name,
                    e.code AS equipment_code
             FROM orders o
             JOIN equipment e ON e.id = o.equipment_id
             WHERE o.id = :id'
        );
        $stmt->execute([':id' => $editId]);
        $editingOrder = $stmt->fetch() ?: null;
    }
}

// טיפול ביצירה / עדכון / סטטוס / מחיקה / שכפול
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id              = (int)($_POST['id'] ?? 0);
        $equipmentId     = (int)($_POST['equipment_id'] ?? 0);
        $borrowerName    = trim($_POST['borrower_name'] ?? '');
        $borrowerContact = trim($_POST['borrower_contact'] ?? '');
        $startDate       = trim($_POST['start_date'] ?? '');
        $endDate         = trim($_POST['end_date'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');

        if ($equipmentId <= 0 || $borrowerName === '' || $startDate === '' || $endDate === '') {
            $error = 'יש למלא ציוד, שם שואל, ותאריכי התחלה וסיום.';
        } else {
            try {
                if ($action === 'create') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO orders
                         (equipment_id, borrower_name, borrower_contact, start_date, end_date, status, notes, created_at)
                         VALUES
                         (:equipment_id, :borrower_name, :borrower_contact, :start_date, :end_date, :status, :notes, :created_at)'
                    );
                    $stmt->execute([
                        ':equipment_id'     => $equipmentId,
                        ':borrower_name'    => $borrowerName,
                        ':borrower_contact' => $borrowerContact,
                        ':start_date'       => $startDate,
                        ':end_date'         => $endDate,
                        ':status'           => 'pending',
                        ':notes'            => $notes,
                        ':created_at'       => date('Y-m-d H:i:s'),
                    ]);
                    $success = 'הזמנה נוצרה בהצלחה.';
                } elseif ($action === 'update' && $id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE orders
                         SET equipment_id     = :equipment_id,
                             borrower_name    = :borrower_name,
                             borrower_contact = :borrower_contact,
                             start_date       = :start_date,
                             end_date         = :end_date,
                             notes            = :notes,
                             updated_at       = :updated_at
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':equipment_id'     => $equipmentId,
                        ':borrower_name'    => $borrowerName,
                        ':borrower_contact' => $borrowerContact,
                        ':start_date'       => $startDate,
                        ':end_date'         => $endDate,
                        ':notes'            => $notes,
                        ':updated_at'       => date('Y-m-d H:i:s'),
                        ':id'               => $id,
                    ]);
                    $success = 'הזמנה עודכנה בהצלחה.';
                }
            } catch (PDOException $e) {
                $error = 'שגיאה בשמירת ההזמנה.';
            }
        }
    } elseif ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        if ($id > 0 && in_array($status, ['pending', 'approved', 'rejected', 'returned'], true)) {
            $stmt = $pdo->prepare(
                'UPDATE orders
                 SET status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status'     => $status,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id'         => $id,
            ]);
            $success = 'סטטוס ההזמנה עודכן.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $success = 'הזמנה נמחקה.';
        }
    } elseif ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'SELECT equipment_id, borrower_name, borrower_contact, start_date, end_date, notes
                 FROM orders
                 WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            $orderToCopy = $stmt->fetch();
            if ($orderToCopy) {
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO orders
                     (equipment_id, borrower_name, borrower_contact, start_date, end_date, status, notes, created_at)
                     VALUES
                     (:equipment_id, :borrower_name, :borrower_contact, :start_date, :end_date, :status, :notes, :created_at)'
                );
                $stmtInsert->execute([
                    ':equipment_id'     => (int)$orderToCopy['equipment_id'],
                    ':borrower_name'    => $orderToCopy['borrower_name'],
                    ':borrower_contact' => $orderToCopy['borrower_contact'],
                    ':start_date'       => $orderToCopy['start_date'],
                    ':end_date'         => $orderToCopy['end_date'],
                    ':status'           => 'pending',
                    ':notes'            => $orderToCopy['notes'],
                    ':created_at'       => date('Y-m-d H:i:s'),
                ]);
                $success = 'הזמנה שוכפלה בהצלחה.';
            }
        }
    }
}

// ציוד לבחירה בטופס
$equipmentStmt = $pdo->query(
    "SELECT id, name, code
     FROM equipment
     WHERE status = 'active'
     ORDER BY name ASC"
);
$equipmentOptions = $equipmentStmt->fetchAll();

// טאבים וסינון
$today     = date('Y-m-d');
$tab       = $_GET['tab'] ?? 'today';
$validTabs = ['today', 'pending', 'future', 'active', 'history'];

if (!in_array($tab, $validTabs, true)) {
    $tab = 'today';
}

// טעינת הזמנות לפי טאב
$baseSql = 'SELECT o.id,
                   o.borrower_name,
                   o.borrower_contact,
                   o.start_date,
                   o.end_date,
                   o.status,
                   o.notes,
                   o.created_at,
                   o.updated_at,
                   e.name AS equipment_name,
                   e.code AS equipment_code
            FROM orders o
            JOIN equipment e ON e.id = o.equipment_id';

$where  = '';
$params = [];

switch ($tab) {
    case 'pending':
        $where = " WHERE o.status = 'pending'";
        break;
    case 'future':
        $where = " WHERE o.status = 'approved' AND DATE(o.start_date) > :today";
        $params[':today'] = $today;
        break;
    case 'active':
        $where = " WHERE o.status = 'approved'
                   AND DATE(o.start_date) <= :today
                   AND DATE(o.end_date)   >= :today";
        $params[':today'] = $today;
        break;
    case 'history':
        $where = " WHERE o.status IN ('returned', 'rejected')";
        break;
    case 'today':
    default:
        $where = " WHERE DATE(o.start_date) <= :today
                   AND DATE(o.end_date)   >= :today";
        $params[':today'] = $today;
        break;
}

$ordersSql  = $baseSql . $where . ' ORDER BY o.created_at DESC, o.id DESC';
$ordersStmt = $pdo->prepare($ordersSql);
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll();

$me = current_user();

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול הזמנות - מערכת השאלת ציוד</title>
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
            max-width: 1150px;
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
        label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        input[type="text"],
        input[type="date"],
        textarea,
        select {
            width: 100%;
            padding: 0.45rem 0.6rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.9rem;
            box-sizing: border-box;
            margin-bottom: 0.7rem;
        }
        textarea {
            min-height: 70px;
            resize: vertical;
        }
        .btn {
            border: none;
            border-radius: 999px;
            padding: 0.45rem 1.1rem;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }
        .btn.small {
            padding: 0.3rem 0.7rem;
            font-size: 0.8rem;
        }
        .btn.neutral {
            background: #f3f4f6;
            color: #111827;
        }
        #submit_order_btn {
            margin-top: 10px;
        }
        .flash {
            padding: 0.6rem 0.8rem;
            border-radius: 8px;
            margin-bottom: 0.9rem;
            font-size: 0.85rem;
        }
        .flash.error {
            background: #fef2f2;
            color: #b91c1c;
        }
        .flash.success {
            background: #ecfdf3;
            color: #166534;
        }
        .grid {
            display: grid;
            grid-template-columns: 1.2fr 2fr; /* אזור תאריכים צר יותר, אזור ציוד רחב יותר */
            gap: 1.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        th, td {
            padding: 0.5rem 0.45rem;
            text-align: right;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        tr:nth-child(even) td {
            background: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 0.1rem 0.55rem;
            border-radius: 999px;
            font-size: 0.75rem;
        }
        .badge.status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .badge.status-approved {
            background: #ecfdf3;
            color: #166534;
        }
        .badge.status-rejected {
            background: #fee2e2;
            color: #b91c1c;
        }
        .badge.status-returned {
            background: #e0f2fe;
            color: #075985;
        }
        .muted-small {
            font-size: 0.78rem;
            color: #6b7280;
        }
        .row-actions {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        .row-actions form {
            margin: 0;
        }
        .tabs {
            display: inline-flex;
            border-radius: 999px;
            background: #e5e7eb;
            padding: 0.15rem;
            margin-bottom: 1rem;
        }
        .tabs a {
            padding: 0.3rem 0.9rem;
            border-radius: 999px;
            font-size: 0.82rem;
            text-decoration: none;
            color: #374151;
        }
        .tabs a.active {
            background: #111827;
            color: #f9fafb;
            font-weight: 600;
        }
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .date-picker {
            background: #f9fafb;
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            border: 1px solid #e5e7eb;
            font-size: 0.85rem;
            position: relative;
        }
        .date-picker-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            cursor: pointer;
            font-size: 0.85rem;
            color: #374151;
            margin-bottom: 0.6rem;
        }
        .date-picker-toggle-icon {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 1px solid #9ca3af;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            background: #f3f4f6;
        }
        .date-picker-panel {
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            padding: 0.6rem 0.7rem 0.7rem;
            margin-top: 0.4rem;
        }
        .date-mode-toggle {
            display: inline-flex;
            border-radius: 999px;
            background: #e5e7eb;
            padding: 0.1rem;
            margin-bottom: 0.6rem;
        }
        .date-mode-btn {
            border: none;
            background: transparent;
            padding: 0.25rem 0.8rem;
            border-radius: 999px;
            font-size: 0.8rem;
            cursor: pointer;
            color: #374151;
        }
        .date-mode-btn.active {
            background: #111827;
            color: #f9fafb;
            font-weight: 600;
        }
        .date-selected {
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .date-selected span {
            font-weight: 600;
        }
        .date-calendar {
            border-radius: 8px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            padding: 0.5rem;
        }
        .date-calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
        }
        .date-calendar-header button {
            border: none;
            background: #e5e7eb;
            border-radius: 999px;
            width: 22px;
            height: 22px;
            font-size: 0.75rem;
            cursor: pointer;
        }
        .date-calendar-weekdays,
        .date-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-size: 0.75rem;
        }
        .date-calendar-weekdays span {
            font-weight: 600;
            color: #6b7280;
            padding: 0.15rem 0;
        }
        .date-day {
            padding: 0.25rem 0;
            margin: 1px;
            border-radius: 6px;
            cursor: pointer;
        }
        .date-day.empty {
            cursor: default;
        }
        .date-day.disabled {
            color: #d1d5db;
            background: #f3f4f6;
            cursor: not-allowed;
        }
        .date-day.selectable:hover {
            background: rgba(15, 23, 42, 0.08);
        }
        .date-day.in-range {
            background: #dbeafe;
            color: #1e3a8a;
        }
        .date-day.selected-start,
        .date-day.selected-end {
            background: #111827;
            color: #f9fafb;
            font-weight: 600;
        }
        footer {
            background: #111827;
            color: #9ca3af;
            text-align: center;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            border-top: 1px solid #1f2937;
        }
    </style>
</head>
<body>
<header>
    <div>
        <h1>ניהול הזמנות</h1>
        <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        <div class="nav-links">
            <a href="admin.php">ניהול מערכת</a>
        </div>
    </div>
    <div class="user-info">
        מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?> (אדמין)
        <a href="logout.php">התנתק</a>
    </div>
</header>
<main>
    <div class="toolbar">
        <div></div>
        <a href="admin_orders.php?mode=new" class="btn">הזמנה חדשה</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="card">
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php elseif ($success !== ''): ?>
        <div class="card">
            <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <?php
    $mode     = $_GET['mode'] ?? null;
    $showForm = $mode === 'new' || $editingOrder !== null;
    ?>

    <?php if ($showForm): ?>
        <div class="card" id="new-order">
            <h2><?= $editingOrder ? 'עריכת הזמנה' : 'הזמנה חדשה' ?></h2>

            <form method="post" action="admin_orders.php<?= $editingOrder ? '?edit_id=' . (int)$editingOrder['id'] : '' ?>">
                <input type="hidden" name="action" value="<?= $editingOrder ? 'update' : 'create' ?>">
                <input type="hidden" name="id" value="<?= $editingOrder ? (int)$editingOrder['id'] : 0 ?>">

                <div class="grid">
                    <!-- עמודת תאריכים + שם שואל + הערות (בצד ימין ב-RTL) -->
                    <div>
                        <!-- שדות נסתרים לתאריכים בפורמט YYYY-MM-DD לצורך שליחה לשרת -->
                        <input type="hidden" id="start_date" name="start_date"
                               value="<?= $editingOrder ? htmlspecialchars($editingOrder['start_date'], ENT_QUOTES, 'UTF-8') : '' ?>">
                        <input type="hidden" id="end_date" name="end_date"
                               value="<?= $editingOrder ? htmlspecialchars($editingOrder['end_date'], ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label>בחירת תאריכים</label>
                        <div class="date-picker">
                            <div class="date-picker-toggle" id="date_picker_toggle">
                                <span class="date-picker-toggle-icon">📅</span>
                                <span>פתח לוח שנה</span>
                            </div>
                            <div class="date-picker-panel" id="date_picker_panel" style="display: none;">
                                <div class="date-mode-toggle">
                                    <button type="button" id="mode_start" class="date-mode-btn active">השאלה</button>
                                    <button type="button" id="mode_end" class="date-mode-btn">החזרה</button>
                                </div>
                                <div class="date-selected">
                                    <div>תאריך השאלה: <span id="selected_start_label">-</span></div>
                                    <div>תאריך החזרה: <span id="selected_end_label">-</span></div>
                                </div>
                                <div class="date-calendar">
                                    <div class="date-calendar-header">
                                        <button type="button" id="cal_prev">&lt;</button>
                                        <div id="cal_month_label"></div>
                                        <button type="button" id="cal_next">&gt;</button>
                                    </div>
                                    <div class="date-calendar-weekdays">
                                        <span>א</span><span>ב</span><span>ג</span><span>ד</span><span>ה</span><span>ו</span><span>ש</span>
                                    </div>
                                    <div class="date-calendar-grid" id="cal_grid"></div>
                                </div>
                                <div class="muted-small" style="margin-top: 0.5rem;">
                                    ימים שעברו וימי שישי/שבת מסומנים כלא זמינים.
                                </div>
                            </div>
                        </div>

                        <label for="borrower_name">שם שואל</label>
                        <input
                            type="text"
                            id="borrower_name"
                            name="borrower_name"
                            required
                            value="<?= $editingOrder ? htmlspecialchars($editingOrder['borrower_name'], ENT_QUOTES, 'UTF-8') : '' ?>"
                        >

                        <label for="notes">הערות</label>
                        <textarea
                            id="notes"
                            name="notes"
                            placeholder="שעות איסוף / החזרה, שימוש מיוחד וכו׳"
                        ><?= $editingOrder ? htmlspecialchars($editingOrder['notes'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></textarea>

                        <button type="button" class="btn secondary"
                                onclick="window.open('agreement.php<?= $editingOrder ? '?order_id=' . (int)$editingOrder['id'] : '' ?>', 'agreement', 'width=900,height=700')">
                            הסכם השאלה
                        </button>
                    </div>

                    <!-- עמודת ציוד בלבד (שמאל ב-RTL) -->
                    <div>
                        <label for="equipment_id">ציוד</label>
                        <select id="equipment_id" name="equipment_id" <?= $editingOrder ? '' : 'disabled' ?>>
                            <option value="">בחר ציוד...</option>
                            <?php foreach ($equipmentOptions as $item): ?>
                                <option value="<?= (int)$item['id'] ?>"
                                    <?= $editingOrder && (int)$editingOrder['equipment_id'] === (int)$item['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>
                                    (<?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn" id="submit_order_btn" disabled>
                    <?= $editingOrder ? 'שמירת שינויים' : 'הזמנה' ?>
                </button>
                <?php if ($editingOrder): ?>
                    <a href="admin_orders.php" class="btn secondary">ביטול</a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="toolbar">
            <h2>רשימת הזמנות</h2>
            <div class="tabs">
                <a href="admin_orders.php?tab=today"   class="<?= $tab === 'today'   ? 'active' : '' ?>">היום</a>
                <a href="admin_orders.php?tab=pending" class="<?= $tab === 'pending' ? 'active' : '' ?>">ממתין</a>
                <a href="admin_orders.php?tab=future"  class="<?= $tab === 'future'  ? 'active' : '' ?>">עתידי</a>
                <a href="admin_orders.php?tab=active"  class="<?= $tab === 'active'  ? 'active' : '' ?>">בהשאלה</a>
                <a href="admin_orders.php?tab=history" class="<?= $tab === 'history' ? 'active' : '' ?>">היסטוריה</a>
            </div>
        </div>
        <?php if (count($orders) === 0): ?>
            <p class="muted-small">עדיין לא נוצרו הזמנות במערכת לטאב זה.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>שם המזמין</th>
                    <th>שם הפריט</th>
                    <th>סטטוס</th>
                    <th>תאריך השאלה</th>
                    <th>תאריך</th>
                    <th>החברה</th>
                    <th>טופס השאלה</th>
                    <th>הערות</th>
                    <th>פעולות</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= (int)$order['id'] ?></td>
                        <td>
                            <?= htmlspecialchars($order['borrower_name'], ENT_QUOTES, 'UTF-8') ?><br>
                            <?php if ($order['borrower_contact'] !== null && $order['borrower_contact'] !== ''): ?>
                                <span class="muted-small">
                                    <?= htmlspecialchars($order['borrower_contact'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($order['equipment_name'], ENT_QUOTES, 'UTF-8') ?><br>
                            <span class="muted-small">
                                <?= htmlspecialchars($order['equipment_code'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusClass = 'status-pending';
                            $statusLabel = 'ממתין';
                            if ($order['status'] === 'approved') {
                                $statusClass = 'status-approved';
                                $statusLabel = 'מאושר';
                            } elseif ($order['status'] === 'rejected') {
                                $statusClass = 'status-rejected';
                                $statusLabel = 'נדחה';
                            } elseif ($order['status'] === 'returned') {
                                $statusClass = 'status-returned';
                                $statusLabel = 'הוחזר';
                            }
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['start_date'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['end_date'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['borrower_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="muted-small">
                            <a href="agreement.php?order_id=<?= (int)$order['id'] ?>" target="_blank">
                                הסכם השאלה
                            </a>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a href="admin_orders.php?edit_id=<?= (int)$order['id'] ?>" class="btn small secondary">עריכה</a>

                                <form method="post" action="admin_orders.php"
                                      onsubmit="return confirm('למחוק את ההזמנה הזו?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                    <button type="submit" class="btn small">מחיקה</button>
                                </form>

                                <form method="post" action="admin_orders.php">
                                    <input type="hidden" name="action" value="duplicate">
                                    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                    <button type="submit" class="btn small neutral">שכפול</button>
                                </form>

                                <form method="post" action="admin_orders.php">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                    <select name="status" class="muted-small">
                                        <option value="pending"  <?= $order['status'] === 'pending'  ? 'selected' : '' ?>>ממתין</option>
                                        <option value="approved" <?= $order['status'] === 'approved' ? 'selected' : '' ?>>מאושר</option>
                                        <option value="rejected" <?= $order['status'] === 'rejected' ? 'selected' : '' ?>>נדחה</option>
                                        <option value="returned" <?= $order['status'] === 'returned' ? 'selected' : '' ?>>הוחזר</option>
                                    </select>
                                    <button type="submit" class="btn small neutral">עדכון</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
<script>
(function () {
    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');
    const equipmentSelect = document.getElementById('equipment_id');
    const submitBtn = document.getElementById('submit_order_btn');
    const modeStartBtn = document.getElementById('mode_start');
    const modeEndBtn = document.getElementById('mode_end');
    const startLabel = document.getElementById('selected_start_label');
    const endLabel = document.getElementById('selected_end_label');
    const calMonthLabel = document.getElementById('cal_month_label');
    const calPrev = document.getElementById('cal_prev');
    const calNext = document.getElementById('cal_next');
    const calGrid = document.getElementById('cal_grid');
    const toggle = document.getElementById('date_picker_toggle');
    const panel = document.getElementById('date_picker_panel');

    if (!startInput || !endInput || !equipmentSelect || !modeStartBtn || !modeEndBtn || !calGrid || !calMonthLabel || !toggle || !panel) {
        return;
    }

    let mode = 'start'; // 'start' or 'end'
    let viewDate = new Date();

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function toIso(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function parseDate(value) {
        if (!value) return null;
        const parts = value.split('-');
        if (parts.length !== 3) return null;
        const year = parseInt(parts[0], 10);
        const month = parseInt(parts[1], 10) - 1;
        const day = parseInt(parts[2], 10);
        const d = new Date(year, month, day);
        return isNaN(d.getTime()) ? null : d;
    }

    function isDisabledDay(date) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Past days
        if (date < today) {
            return true;
        }

        // ימי מנוחה: שישי (5) ושבת (6) בלבד
        // getDay(): 0=ראשון, 1=שני, 2=שלישי, 3=רביעי, 4=חמישי, 5=שישי, 6=שבת
        const day = date.getDay();
        return day === 5 || day === 6;
    }

    function updateEquipmentState() {
        const hasStart = !!startInput.value;
        const hasEnd = !!endInput.value;
        equipmentSelect.disabled = !(hasStart && hasEnd);

        const hasEquip = !!equipmentSelect.value;
        if (submitBtn) {
            submitBtn.disabled = !(hasStart && hasEnd && hasEquip);
        }
    }

    function updateLabels() {
        startLabel.textContent = startInput.value || '-';
        endLabel.textContent = endInput.value || '-';
    }

    function setMode(newMode) {
        mode = newMode;
        if (mode === 'start') {
            modeStartBtn.classList.add('active');
            modeEndBtn.classList.remove('active');
        } else {
            modeEndBtn.classList.add('active');
            modeStartBtn.classList.remove('active');
        }
    }

    function renderCalendar() {
        const year = viewDate.getFullYear();
        const month = viewDate.getMonth();
        const firstOfMonth = new Date(year, month, 1);
        // getDay(): 0=ראשון, 1=שני, 2=שלישי, 3=רביעי, 4=חמישי, 5=שישי, 6=שבת
        // כאן אנחנו מיישרים ישירות לפי getDay, כך שהעמודה "א" היא יום ראשון
        const firstDay = firstOfMonth.getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        calMonthLabel.textContent = year + '-' + pad(month + 1);
        calGrid.innerHTML = '';

        const startDate = parseDate(startInput.value);
        const endDate = parseDate(endInput.value);

        // leading empty cells
        for (let i = 0; i < firstDay; i++) {
            const cell = document.createElement('div');
            cell.className = 'date-day empty';
            calGrid.appendChild(cell);
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const cellDate = new Date(year, month, day);
            const iso = toIso(cellDate);
            const cell = document.createElement('div');
            cell.textContent = day.toString();
            cell.dataset.date = iso;

            const disabled = isDisabledDay(cellDate);
            if (disabled) {
                cell.className = 'date-day disabled';
            } else {
                cell.className = 'date-day selectable';
                cell.addEventListener('click', function () {
                    selectDate(cellDate);
                });
            }

            // סימון טווח ותאריכים נבחרים
            if (startDate && !disabled) {
                if (cellDate.getTime() === startDate.getTime()) {
                    cell.classList.add('selected-start');
                }
            }
            if (endDate && !disabled) {
                if (cellDate.getTime() === endDate.getTime()) {
                    cell.classList.add('selected-end');
                }
            }
            if (startDate && endDate && !disabled) {
                if (cellDate > startDate && cellDate < endDate) {
                    cell.classList.add('in-range');
                }
            }

            calGrid.appendChild(cell);
        }
    }

    function selectDate(date) {
        if (mode === 'start') {
            startInput.value = toIso(date);
            // אם תאריך הסיום לפני תאריך ההתחלה – ננקה אותו
            const end = parseDate(endInput.value);
            if (end && end < date) {
                endInput.value = '';
            }
        } else {
            const start = parseDate(startInput.value);
            if (!start) {
                // אם אין תאריך התחלה עדיין – נגדיר קודם אותו
                startInput.value = toIso(date);
            } else {
                if (date < start) {
                    // אם בוחר תאריך החזרה לפני ההתחלה – נחליף
                    endInput.value = toIso(start);
                    startInput.value = toIso(date);
                } else {
                    endInput.value = toIso(date);
                }
            }
        }
        updateLabels();
        updateEquipmentState();
        renderCalendar();

        const hasStart = !!startInput.value;
        const hasEnd = !!endInput.value;
        if (hasStart && hasEnd) {
            setTimeout(function () {
                panel.style.display = 'none';
            }, 1000);
        }
    }

    // שינוי ציוד
    equipmentSelect.addEventListener('change', function () {
        updateEquipmentState();
    });

    // חיבור אירועים למעבר חודשים
    calPrev.addEventListener('click', function () {
        viewDate.setMonth(viewDate.getMonth() - 1);
        renderCalendar();
    });
    calNext.addEventListener('click', function () {
        viewDate.setMonth(viewDate.getMonth() + 1);
        renderCalendar();
    });

    // כפתור פתיחת/סגירת לוח השנה
    toggle.addEventListener('click', function () {
        const isVisible = panel.style.display === 'block';
        panel.style.display = isVisible ? 'none' : 'block';
    });

    // כפתורי מצב
    modeStartBtn.addEventListener('click', function () {
        setMode('start');
    });
    modeEndBtn.addEventListener('click', function () {
        setMode('end');
    });

    // אתחול
    setMode('start');
    updateLabels();
    updateEquipmentState();
    renderCalendar();
})();
</script>
<footer>
    © 2026 CentricApp LTD
</footer>
</body>
</html>
