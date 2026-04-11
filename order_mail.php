<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_mail.php';

/**
 * @return array<string, mixed>|null
 */
function gf_order_mail_get_order_row(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT o.id, o.start_date, o.end_date, o.start_time, o.end_time, o.status, o.purpose,
                e.name AS equipment_name, e.code AS equipment_code
         FROM orders o
         JOIN equipment e ON e.id = o.equipment_id
         WHERE o.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param array<string, mixed> $row
 */
function gf_order_mail_format_plain_body(array $row, string $prefixLine): string
{
    $lines = [];
    if ($prefixLine !== '') {
        $lines[] = $prefixLine;
    }
    $lines[] = 'הזמנה #' . (int)$row['id'];
    $lines[] = 'ציוד (ראשי): ' . trim((string)($row['equipment_name'] ?? '')) . ' [' . trim((string)($row['equipment_code'] ?? '')) . ']';
    $lines[] = 'השאלה: ' . (string)($row['start_date'] ?? '') . ' ' . trim((string)($row['start_time'] ?? ''));
    $lines[] = 'החזרה: ' . (string)($row['end_date'] ?? '') . ' ' . trim((string)($row['end_time'] ?? ''));
    if (trim((string)($row['purpose'] ?? '')) !== '') {
        $lines[] = 'מטרה: ' . trim((string)$row['purpose']);
    }

    return implode("\n", $lines) . "\n";
}

/**
 * שליחת מייל אישור/עדכון לסטודנט (אם Gmail מוגדר, יש מייל, והמשתמש הסכים לקבלת מיילים).
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
    if ((int)($me['allow_emails'] ?? 0) !== 1) {
        return;
    }
    $to = trim((string)($me['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $row = gf_order_mail_get_order_row($pdo, $orderId);
    if ($row === null) {
        return;
    }
    if ($event === 'created') {
        $subject = 'אישור קבלת הזמנה #' . $orderId;
        $body = gf_order_mail_format_plain_body($row, 'ההזמנה נקלטה במערכת.');
    } elseif ($event === 'cancelled') {
        $subject = 'ביטול הזמנה #' . $orderId;
        $body = gf_order_mail_format_plain_body($row, 'ההזמנה בוטלה.');
    } else {
        $subject = 'עדכון הזמנה #' . $orderId;
        $body = gf_order_mail_format_plain_body($row, 'פרטי ההזמנה עודכנו. להלן הפרטים העדכניים:');
    }
    $reply = trim((string)($cfg['sender_email'] ?? ''));
    google_mail_send($pdo, $to, $subject, $body, 'מערכת השאלת ציוד', $reply);
}
