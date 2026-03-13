<?php

declare(strict_types=1);

// סקריפט זה מיועד להרצה ע"י cron / Scheduled Task
// לדוגמה: פעם ב-15 דקות או פעם בשעה.

require_once __DIR__ . '/config.php';

$pdo = get_db();

/**
 * יצירת התראה חדשה, תוך מניעת כפילויות גסות:
 * לא יוצרים שוב את אותה הודעה לאותו משתמש / תפקיד אם קיימת התראה
 * עם אותו message ו-link שנוצרה ב-24 השעות האחרונות.
 */
function create_timed_notification(PDO $pdo, ?int $userId, ?string $role, string $message, ?string $link = null): void
{
    try {
        $check = $pdo->prepare(
            'SELECT COUNT(*) AS cnt
             FROM notifications
             WHERE message = :message
               AND IFNULL(link, "") = IFNULL(:link, "")
               AND IFNULL(user_id, 0) = IFNULL(:user_id, 0)
               AND IFNULL(role, "") = IFNULL(:role, "")
               AND created_at >= :since'
        );
        $check->execute([
            ':message' => $message,
            ':link'    => $link,
            ':user_id' => $userId,
            ':role'    => $userId === null ? (string)$role : '',
            ':since'   => date('Y-m-d H:i:s', time() - 24 * 3600),
        ]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ((int)($row['cnt'] ?? 0) > 0) {
            return;
        }

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
        // לא מפילים את הסקריפט במקרה של שגיאה
    }
}

$now      = new DateTimeImmutable('now');
$nowDate  = $now->format('Y-m-d');
$nowTime  = $now->format('H:i');

// --- 1. התראות למנהלים: סטודנט הגיש בקשה (מכוסה כבר ע"י admin_orders.php) ---
// לא מוסיפים כאן כדי לא לשכפל התראות.

// --- 2. שעה לפני תחילת השאלה / החזרה ---
// נחשב טווח של +/- 10 דקות סביב שעה לפני תחילת ההשאלה / ההחזרה,
// כדי שגם אם cron רץ כל 15 דקות עדיין נתפוס את המקרים.

$oneHourBefore = (clone $now)->modify('+1 hour')->format('H:i');

// התראות למנהלים ולסטודנטים שעה לפני תחילת יום השאלה / החזרה
$stmt = $pdo->prepare("
    SELECT o.id,
           o.borrower_name,
           o.creator_username,
           o.start_date,
           o.end_date,
           COALESCE(o.start_time, '09:00') AS start_time,
           COALESCE(o.end_time,   '16:00') AS end_time,
           o.status
    FROM orders o
    WHERE o.status IN ('approved', 'on_loan')
      AND (o.start_date = :today OR o.end_date = :today)
");
$stmt->execute([':today' => $nowDate]);
$ordersToday = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($ordersToday as $order) {
    $orderId   = (int)$order['id'];
    $creator   = (string)($order['creator_username'] ?? '');
    $startDate = (string)$order['start_date'];
    $endDate   = (string)$order['end_date'];
    $startTime = (string)$order['start_time'];
    $endTime   = (string)$order['end_time'];
    $status    = (string)$order['status'];

    // התראה שעה לפני תחילת השאלה
    if ($startDate === $nowDate && $startTime === $oneHourBefore) {
        $msgManager = 'שעה לפני השאלת ציוד בהזמנה #' . $orderId;
        $msgStudent = 'תזכורת: בעוד שעה מתחילה השאלת הציוד בהזמנה #' . $orderId;
        $link       = 'admin_orders.php?tab=today';

        create_timed_notification($pdo, null, 'admin', $msgManager, $link);
        create_timed_notification($pdo, null, 'warehouse_manager', $msgManager, $link);

        if ($creator !== '') {
            $u = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
            $u->execute([':u' => $creator]);
            if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
                create_timed_notification(
                    $pdo,
                    (int)$row['id'],
                    null,
                    $msgStudent,
                    'admin_orders.php?tab=today'
                );
            }
        }
    }

    // התראה שעה לפני סוף ההשאלה (החזרה)
    if ($endDate === $nowDate && $endTime === $oneHourBefore) {
        $msgManager = 'שעה לפני החזרת ציוד בהזמנה #' . $orderId;
        $msgStudent = 'תזכורת: בעוד שעה זמן ההחזרה של הציוד בהזמנה #' . $orderId;
        $link       = 'admin_orders.php?tab=today&today_mode=return';

        create_timed_notification($pdo, null, 'admin', $msgManager, $link);
        create_timed_notification($pdo, null, 'warehouse_manager', $msgManager, $link);

        if ($creator !== '') {
            $u = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
            $u->execute([':u' => $creator]);
            if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
                create_timed_notification(
                    $pdo,
                    (int)$row['id'],
                    null,
                    $msgStudent,
                    'admin_orders.php?tab=today&today_mode=return'
                );
            }
        }
    }
}

// --- 3. יום אחרי: סטודנט לא הגיע לאסוף ציוד / לא החזיר בזמן ---

$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');

// הזמנות שאושרו אבל הסטטוס לא הפך ל-on_loan עד סוף יום ההשאלה – ציוד לא נאסף
$stmt = $pdo->prepare("
    SELECT id, creator_username
    FROM orders
    WHERE status = 'approved'
      AND end_date < :today
");
$stmt->execute([':today' => $nowDate]);
$notPicked = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($notPicked as $order) {
    $orderId = (int)$order['id'];
    $creator = (string)($order['creator_username'] ?? '');

    $msgManager = 'סטודנט לא הגיע לאסוף ציוד להזמנה #' . $orderId;
    $link       = 'admin_orders.php?tab=history';

    create_timed_notification($pdo, null, 'admin', $msgManager, $link);
    create_timed_notification($pdo, null, 'warehouse_manager', $msgManager, $link);
}

// הזמנות במצב on_loan שעבר מועד ההחזרה – ציוד לא הוחזר
$stmt = $pdo->prepare("
    SELECT id, creator_username
    FROM orders
    WHERE status = 'on_loan'
      AND end_date < :today
");
$stmt->execute([':today' => $nowDate]);
$notReturned = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($notReturned as $order) {
    $orderId = (int)$order['id'];
    $creator = (string)($order['creator_username'] ?? '');

    $msgManager = 'סטודנט לא החזיר ציוד בזמן להזמנה #' . $orderId;
    $msgStudent = 'לא החזרת את הציוד בזמן להזמנה #' . $orderId;
    $link       = 'admin_orders.php?tab=not_returned';

    create_timed_notification($pdo, null, 'admin', $msgManager, $link);
    create_timed_notification($pdo, null, 'warehouse_manager', $msgManager, $link);

    if ($creator !== '') {
        $u = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $u->execute([':u' => $creator]);
        if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
            create_timed_notification(
                $pdo,
                (int)$row['id'],
                null,
                $msgStudent,
                'admin_orders.php?tab=not_returned'
            );
        }
    }
}

echo "Notifications cron finished at " . date('Y-m-d H:i:s') . PHP_EOL;

