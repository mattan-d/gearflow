<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo = get_db();
$error = '';
$success = '';

// Handle create / status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
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
            } catch (PDOException $e) {
                $error = 'שגיאה ביצירת ההזמנה.';
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
                ':status'    => $status,
                ':updated_at'=> date('Y-m-d H:i:s'),
                ':id'        => $id,
            ]);
            $success = 'סטטוס ההזמנה עודכן.';
        }
    }
}

// Load equipment options
$equipmentStmt = $pdo->query(
    "SELECT id, name, code
     FROM equipment
     WHERE status = 'active'
     ORDER BY name ASC"
);
$equipmentOptions = $equipmentStmt->fetchAll();

// Load orders list
$ordersStmt = $pdo->query(
    'SELECT o.id,
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
     JOIN equipment e ON e.id = o.equipment_id
     ORDER BY o.created_at DESC, o.id DESC'
);
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
            grid-template-columns: 2fr 1.2fr;
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
        }
        .row-actions form {
            margin: 0;
        }
    </style>
</head>
<body>
<header>
    <div>
        <h1>ניהול הזמנות</h1>
        <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        <div class="nav-links">
            <a href="admin_equipment.php">ניהול ציוד</a>
            <a href="admin_orders.php" class="active">ניהול הזמנות</a>
            <a href="admin_users.php">ניהול משתמשים</a>
        </div>
    </div>
    <div class="user-info">
        מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?> (אדמין)
        <a href="logout.php">התנתק</a>
    </div>
</header>
<main>
    <div class="card">
        <h2>יצירת הזמנת ציוד חדשה</h2>

        <?php if ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($success !== ''): ?>
            <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="admin_orders.php">
            <input type="hidden" name="action" value="create">

            <div class="grid">
                <div>
                    <label for="equipment_id">ציוד</label>
                    <select id="equipment_id" name="equipment_id" required>
                        <option value="">בחר ציוד...</option>
                        <?php foreach ($equipmentOptions as $item): ?>
                            <option value="<?= (int)$item['id'] ?>">
                                <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="borrower_name">שם שואל</label>
                    <input type="text" id="borrower_name" name="borrower_name" required>

                    <label for="borrower_contact">פרטי יצירת קשר (טלפון / מייל)</label>
                    <input type="text" id="borrower_contact" name="borrower_contact">
                </div>
                <div>
                    <label for="start_date">תאריך התחלה</label>
                    <input type="date" id="start_date" name="start_date" required>

                    <label for="end_date">תאריך סיום</label>
                    <input type="date" id="end_date" name="end_date" required>

                    <label for="notes">הערות</label>
                    <textarea id="notes" name="notes" placeholder="שעות איסוף / החזרה, שימוש מיוחד וכו׳"></textarea>
                </div>
            </div>

            <button type="submit" class="btn">שמירת הזמנה</button>
        </form>
    </div>

    <div class="card">
        <h2>רשימת הזמנות</h2>
        <?php if (count($orders) === 0): ?>
            <p class="muted-small">עדיין לא נוצרו הזמנות במערכת.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>ציוד</th>
                    <th>שואל</th>
                    <th>תאריכים</th>
                    <th>סטטוס</th>
                    <th>הערות</th>
                    <th>נוצר ב־</th>
                    <th>פעולות</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= (int)$order['id'] ?></td>
                        <td>
                            <?= htmlspecialchars($order['equipment_name'], ENT_QUOTES, 'UTF-8') ?><br>
                            <span class="muted-small"><?= htmlspecialchars($order['equipment_code'], ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td>
                            <?= htmlspecialchars($order['borrower_name'], ENT_QUOTES, 'UTF-8') ?><br>
                            <?php if ($order['borrower_contact'] !== null && $order['borrower_contact'] !== ''): ?>
                                <span class="muted-small">
                                    <?= htmlspecialchars($order['borrower_contact'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['start_date'], ENT_QUOTES, 'UTF-8') ?>
                            -
                            <?= htmlspecialchars($order['end_date'], ENT_QUOTES, 'UTF-8') ?>
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
                            <?= htmlspecialchars($order['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['created_at'], ENT_QUOTES, 'UTF-8') ?><br>
                            <?php if (!empty($order['updated_at'])): ?>
                                עודכן: <?= htmlspecialchars($order['updated_at'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="post" action="admin_orders.php">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                    <select name="status" class="muted-small">
                                        <option value="pending" <?= $order['status'] === 'pending' ? 'selected' : '' ?>>ממתין</option>
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
</body>
</html>

