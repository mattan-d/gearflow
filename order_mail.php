<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_mail.php';

/**
 * שליחת מייל אישור/עדכון לסטודנט (אם Gmail מוגדר ולמשתמש יש מייל).
 *
 * @param 'created'|'updated'|'cancelled' $event
 */
function gf_try_mail_student_order_event(PDO $pdo, array $me, int $orderId, string $event): void
{
    if (($me['role'] ?? '') !== 'student') {
        return;
    }
    $cfg = google_mail_load_config($pdo);
    if (!google_mail_is_configured($cfg)) {
        return;
    }
    $to = trim((string)($me['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $stmt = $pdo->prepare(
        'SELECT o.id, o.start_date, o.end_date, o.start_time, o.end_time, o.status, o.purpose,
                e.name AS equipment_name, e.code AS equipment_code
         FROM orders o
         JOIN equipment e ON e.id = o.equipment_id
         WHERE o.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $lines = [];
    $lines[] = 'הזמנה #' . (int)$row['id'];
    $lines[] = 'ציוד (ראשי): ' . trim((string)($row['equipment_name'] ?? '')) . ' [' . trim((string)($row['equipment_code'] ?? '')) . ']';
    $lines[] = 'השאלה: ' . (string)($row['start_date'] ?? '') . ' ' . trim((string)($row['start_time'] ?? ''));
    $lines[] = 'החזרה: ' . (string)($row['end_date'] ?? '') . ' ' . trim((string)($row['end_time'] ?? ''));
    if (trim((string)($row['purpose'] ?? '')) !== '') {
        $lines[] = 'מטרה: ' . trim((string)$row['purpose']);
    }
    $body = implode("\n", $lines) . "\n";
    if ($event === 'created') {
        $subject = 'אישור קבלת הזמנה #' . $orderId;
        $body = "ההזמנה נקלטה במערכת.\n\n" . $body;
    } elseif ($event === 'cancelled') {
        $subject = 'ביטול הזמנה #' . $orderId;
        $body = "ההזמנה בוטלה.\n\n" . $body;
    } else {
        $subject = 'עדכון הזמנה #' . $orderId;
        $body = "פרטי ההזמנה עודכנו. להלן הפרטים העדכניים:\n\n" . $body;
    }
    $reply = trim((string)($cfg['sender_email'] ?? ''));
    google_mail_send($pdo, $to, $subject, $body, 'מערכת השאלת ציוד', $reply);
}
