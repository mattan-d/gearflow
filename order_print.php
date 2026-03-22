<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare(
    'SELECT o.*, e.name AS equipment_name, e.code AS equipment_code
     FROM orders o
     JOIN equipment e ON e.id = o.equipment_id
     WHERE o.id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo 'לא נמצאה הזמנה';
    exit;
}

$title = 'הזמנה #' . $orderId;

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1.5rem; color: #111827; }
        h1 { font-size: 1.35rem; margin: 0 0 1rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        th, td { border: 1px solid #e5e7eb; padding: 0.45rem 0.6rem; text-align: right; }
        th { background: #f9fafb; width: 28%; }
        @media print {
            body { margin: 0.5rem; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<p class="no-print" style="margin-bottom:1rem;">
    <button type="button" onclick="window.print()" style="padding:0.4rem 0.8rem;cursor:pointer;">הדפס</button>
    <button type="button" onclick="window.close()" style="padding:0.4rem 0.8rem;cursor:pointer;">סגור</button>
</p>
<h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
<table>
    <tr><th>שם שואל</th><td><?= htmlspecialchars((string)($row['borrower_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>פרטי קשר</th><td><?= htmlspecialchars((string)($row['borrower_contact'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>ציוד</th><td><?= htmlspecialchars((string)($row['equipment_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)($row['equipment_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</td></tr>
    <tr><th>מטרה</th><td><?= htmlspecialchars((string)($row['purpose'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>תאריך/שעת השאלה</th><td><?= htmlspecialchars(trim((string)($row['start_date'] ?? '') . ' ' . (string)($row['start_time'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>תאריך/שעת החזרה</th><td><?= htmlspecialchars(trim((string)($row['end_date'] ?? '') . ' ' . (string)($row['end_time'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>סטטוס</th><td><?= htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>הערות סטודנט</th><td><?= nl2br(htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td></tr>
    <tr><th>הערות מנהל</th><td><?= nl2br(htmlspecialchars((string)($row['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td></tr>
</table>
</body>
</html>
