<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_mail.php';
require_once __DIR__ . '/order_mail.php';

/**
 * מפתחות הגדרה: notify_order_{stu_approve|stu_reject|...}_{internal|email}
 *
 * @return array{internal: bool, email: bool}
 */
function gf_order_notify_channels(PDO $pdo, string $key): array
{
    $internal = gf_app_setting($pdo, 'notify_order_' . $key . '_internal', '1') === '1';
    $email    = gf_app_setting($pdo, 'notify_order_' . $key . '_email', '0') === '1';

    return ['internal' => $internal, 'email' => $email];
}

/** @return list<string> */
function gf_order_notify_setting_keys(): array
{
    $stu = ['stu_approve', 'stu_reject', 'stu_delete', 'stu_edit_admin', 'stu_admin_placed'];
    $adm = ['adm_new_order', 'adm_student_changed', 'adm_cancel_request', 'adm_student_cancelled'];

    return array_merge($stu, $adm);
}

function gf_notification_create(PDO $pdo, ?int $userId, ?string $role, string $message, ?string $link = null): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, role, message, link, is_read, created_at)
             VALUES (:user_id, :role, :message, :link, 0, :created_at)'
        );
        $stmt->execute([
            ':user_id'    => $userId,
            ':role'       => $userId === null ? $role : null,
            ':message'    => $message,
            ':link'       => $link,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // לא מפילים את הדף במקרה של שגיאת התראה
    }
}

/**
 * איתור מזהה הסטודנט לקבלת התראה על שינוי סטטוס.
 */
function gf_resolve_student_user_id_for_notification(PDO $pdo, string $creatorUsername, string $borrowerName): int
{
    try {
        if ($creatorUsername !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u AND role = 'student' LIMIT 1");
            $stmt->execute([':u' => $creatorUsername]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        if ($borrowerName !== '') {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM users
                 WHERE role = 'student'
                   AND (
                       username = :borrower
                       OR TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) = :borrower
                   )
                 LIMIT 1"
            );
            $stmt->execute([':borrower' => $borrowerName]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }
    } catch (Throwable $e) {
        // לא מפילים את הזרימה אם לא מצאנו נמען
    }

    return 0;
}

function gf_mail_user_if_allowed(PDO $pdo, int $userId, string $subject, string $bodyPlain): void
{
    $cfg = google_mail_load_config($pdo);
    if (!google_mail_is_configured($cfg)) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT email, allow_emails, is_active FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['is_active'] ?? 0) !== 1) {
            return;
        }
        if ((int)($row['allow_emails'] ?? 0) !== 1) {
            return;
        }
        $to = trim((string)($row['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    $reply = trim((string)($cfg['sender_email'] ?? ''));
    google_mail_send($pdo, $to, $subject, $bodyPlain, 'מערכת השאלת ציוד', $reply);
}

/**
 * מייל למנהלי מערכת ולמנהלי מחסן עם כתובת מייל תקינה (ללא תלות ב-allow_emails).
 */
function gf_mail_admins_and_warehouse(PDO $pdo, string $subject, string $bodyPlain): void
{
    $cfg = google_mail_load_config($pdo);
    if (!google_mail_is_configured($cfg)) {
        return;
    }
    try {
        $stmt = $pdo->query(
            "SELECT id, email FROM users
             WHERE is_active = 1
               AND role IN ('admin', 'warehouse_manager')
               AND TRIM(COALESCE(email, '')) <> ''"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }
    $reply = trim((string)($cfg['sender_email'] ?? ''));
    foreach ($rows as $r) {
        $to = trim((string)($r['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        google_mail_send($pdo, $to, $subject, $bodyPlain, 'מערכת השאלת ציוד', $reply);
    }
}

function gf_notify_admins_order_event(PDO $pdo, string $eventKey, string $message, ?string $link): void
{
    $emailSubjects = [
        'adm_new_order'         => 'הזמנה חדשה — מערכת השאלת ציוד',
        'adm_student_changed'   => 'עדכון הזמנה על ידי סטודנט',
        'adm_cancel_request'    => 'בקשת ביטול הזמנה',
        'adm_student_cancelled'   => 'ביטול הזמנה על ידי סטודנט',
    ];
    $ch = gf_order_notify_channels($pdo, $eventKey);
    if ($ch['internal']) {
        gf_notification_create($pdo, null, 'admin', $message, $link);
        gf_notification_create($pdo, null, 'warehouse_manager', $message, $link);
    }
    if ($ch['email']) {
        $lines = [$message, ''];
        if ($link !== null && $link !== '') {
            $base = app_script_dir_url();
            $lines[] = 'קישור: ' . $base . '/' . ltrim($link, '/');
        }
        $subj = $emailSubjects[$eventKey] ?? 'התראת הזמנה — מערכת השאלת ציוד';
        gf_mail_admins_and_warehouse($pdo, $subj, implode("\n", $lines));
    }
}

function gf_notify_student_order_event(
    PDO $pdo,
    int $studentUserId,
    string $eventKey,
    string $internalMessage,
    ?string $link,
    int $orderId,
    string $emailSubject,
    string $emailIntroLine
): void {
    if ($studentUserId <= 0) {
        return;
    }
    $ch = gf_order_notify_channels($pdo, $eventKey);
    if ($ch['internal']) {
        gf_notification_create($pdo, $studentUserId, null, $internalMessage, $link);
    }
    if ($ch['email']) {
        $row = gf_order_mail_get_order_row($pdo, $orderId);
        if ($row !== null) {
            $body = gf_order_mail_format_plain_body($row, $emailIntroLine);
            gf_mail_user_if_allowed($pdo, $studentUserId, $emailSubject, $body);
        }
    }
}

/**
 * האם מנהל שינה פרטי הזמנה (לא מעבר לאישור/דחייה/מחיקה בלבד).
 */
function gf_order_admin_meaningful_content_change(
    array $orderRow,
    string $borrowerName,
    string $borrowerContact,
    string $startDate,
    string $endDate,
    string $startTime,
    string $endTime,
    string $notes,
    string $purpose,
    string $adminNotesToSave,
    int $equipmentId,
    array $newEquipIdsSorted,
    array $oldEquipIdsSorted
): bool {
    $norm = static function (string $s): string {
        return trim($s);
    };

    if ($norm((string)($orderRow['borrower_name'] ?? '')) !== $norm($borrowerName)) {
        return true;
    }
    if ($norm((string)($orderRow['borrower_contact'] ?? '')) !== $norm($borrowerContact)) {
        return true;
    }
    if ($norm((string)($orderRow['start_date'] ?? '')) !== $norm($startDate)) {
        return true;
    }
    if ($norm((string)($orderRow['end_date'] ?? '')) !== $norm($endDate)) {
        return true;
    }
    if ($norm((string)($orderRow['start_time'] ?? '')) !== $norm($startTime)) {
        return true;
    }
    if ($norm((string)($orderRow['end_time'] ?? '')) !== $norm($endTime)) {
        return true;
    }
    if ($norm((string)($orderRow['notes'] ?? '')) !== $norm($notes)) {
        return true;
    }
    if ($norm((string)($orderRow['purpose'] ?? '')) !== $norm($purpose)) {
        return true;
    }
    if ($norm((string)($orderRow['admin_notes'] ?? '')) !== $norm($adminNotesToSave)) {
        return true;
    }
    if ((int)($orderRow['equipment_id'] ?? 0) !== $equipmentId) {
        return true;
    }
    if ($newEquipIdsSorted !== $oldEquipIdsSorted) {
        return true;
    }

    return false;
}
