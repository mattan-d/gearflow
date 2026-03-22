<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$me   = current_user();
$role = $me['role'] ?? 'student';

$pdo      = get_db();
$nowTs    = time();

// Prefill עבור פתיחה דרך "ניהול יומי"
$prefillDay = trim((string)($_GET['prefill_day'] ?? ''));
$prefillStartTime = trim((string)($_GET['prefill_start_time'] ?? ''));
$prefillEndTimeParam = trim((string)($_GET['prefill_end_time'] ?? ''));
$prefillEquipmentId = (int)($_GET['prefill_equipment_id'] ?? 0);
$prefillNoEnd = isset($_GET['prefill_no_end']) && (string)$_GET['prefill_no_end'] === '1';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefillDay)) {
    $prefillDay = '';
}
if (!preg_match('/^\d{2}:\d{2}$/', $prefillStartTime)) {
    $prefillStartTime = '';
}
if (!preg_match('/^\d{2}:\d{2}$/', $prefillEndTimeParam)) {
    $prefillEndTimeParam = '';
}
if ($prefillEquipmentId < 1) {
    $prefillEquipmentId = 0;
}

// end_time ברירת מחדל: שעה אחרי start_time (עם תקרה 22:00) או הפרמטר שהגיע; prefill_no_end=1 משאיר ריק (למשל לבחירת יום החזרה אחר)
$prefillEndTime = '';
if ($prefillStartTime !== '' && !$prefillNoEnd) {
    [$ph, $pm] = array_map('intval', explode(':', $prefillStartTime));
    $mins = max(0, min(23, $ph)) * 60 + max(0, min(59, $pm));
    $mins2 = null;
    if ($prefillEndTimeParam !== '') {
        [$eh, $em] = array_map('intval', explode(':', $prefillEndTimeParam));
        $mins2 = max(0, min(23, $eh)) * 60 + max(0, min(59, $em));
        if ($mins2 <= $mins) {
            $mins2 = null;
        }
    }
    if ($mins2 === null) {
        $mins2 = min(22 * 60, $mins + 60);
    }
    $prefillEndTime = sprintf('%02d:%02d', intdiv((int)$mins2, 60), ((int)$mins2) % 60);
}

/**
 * יצירת התראה חדשה.
 *
 * @param PDO         $pdo
 * @param int|null    $userId  אם לא null – התראה למשתמש ספציפי
 * @param string|null $role    אם userId null – התראה לכל בעלי התפקיד הזה
 * @param string      $message טקסט ההתראה
 * @param string|null $link    קישור רלוונטי (אופציונלי)
 */
function create_notification(PDO $pdo, ?int $userId, ?string $role, string $message, ?string $link = null): void
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
$error    = '';
$success  = '';
$editingOrder = null;

// AJAX: בדיקת זמינות ציוד לטווח תאריכים/שעות נבחר (כולל הזמנה מחזורית)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'available_equipment') {
    header('Content-Type: application/json; charset=UTF-8');

    $excludeId  = (int)($_GET['exclude_order_id'] ?? 0);
    $recurringEnabled = !empty($_GET['recurring_enabled']);

    $unavailableIds = [];

    if ($recurringEnabled) {
        // הזמנה מחזורית – בדיקת זמינות לכל המופעים
        $recStartDate   = trim($_GET['recurring_start_date'] ?? '');
        $recStartTime   = trim($_GET['recurring_start_time'] ?? '');
        $recFreq        = $_GET['recurring_freq'] ?? 'day';
        $recDuration    = (float)($_GET['recurring_duration'] ?? 1);
        $recEndType     = $_GET['recurring_end_type'] ?? 'count';
        $recCount       = (int)($_GET['recurring_count'] ?? 1);
        $recEndDate     = trim($_GET['recurring_end_date'] ?? '');

        if ($recStartDate === '' || $recStartTime === '' || !in_array($recFreq, ['day', 'week'], true)) {
            echo json_encode(['unavailable_ids' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $startTs = strtotime($recStartDate . ' ' . $recStartTime);
        if ($startTs === false) {
            echo json_encode(['unavailable_ids' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stepDays        = ($recFreq === 'week') ? 7 : 1;
        $durationMinutes = (int)round($recDuration * 60);
        $n               = ($recEndType === 'count') ? max(1, $recCount) : 0;

        $current = $startTs;
        $count   = 0;
        while (true) {
            if ($recEndType === 'count' && $count >= $n) {
                break;
            }
            if ($recEndType === 'end_date' && $recEndDate !== '' && date('Y-m-d', $current) > $recEndDate) {
                break;
            }

            $occStart = date('Y-m-d H:i', $current);
            $endOccTs = $current + $durationMinutes * 60;
            $occEnd   = date('Y-m-d H:i', $endOccTs);

            $sql = "
                SELECT DISTINCT equipment_id
                FROM orders
                WHERE status IN ('pending', 'approved', 'on_loan')
                  AND (
                        (start_date || ' ' || COALESCE(start_time, '00:00')) <= :req_end
                    AND (end_date   || ' ' || COALESCE(end_time,   '23:59')) >= :req_start
                  )
            ";
            $params = [
                ':req_start' => $occStart,
                ':req_end'   => $occEnd,
            ];
            if ($excludeId > 0) {
                $sql .= " AND id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $eid = (int)($row['equipment_id'] ?? 0);
                if ($eid > 0 && !in_array($eid, $unavailableIds, true)) {
                    $unavailableIds[] = $eid;
                }
            }

            $count++;
            $current = strtotime('+' . $stepDays . ' days', $current);
        }

        echo json_encode(['unavailable_ids' => $unavailableIds], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // מצב רגיל – הזמנה בודדת
    $startDate  = trim($_GET['start_date'] ?? '');
    $endDate    = trim($_GET['end_date'] ?? '');
    $startTime  = trim($_GET['start_time'] ?? '');
    $endTime    = trim($_GET['end_time'] ?? '');

    if ($startDate === '' || $endDate === '') {
        echo json_encode(['unavailable_ids' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $reqStart = $startDate . ' ' . ($startTime !== '' ? $startTime : '00:00');
    $reqEnd   = $endDate . ' ' . ($endTime !== '' ? $endTime : '23:59');

    $sql = "
        SELECT DISTINCT equipment_id
        FROM orders
        WHERE status IN ('pending', 'approved', 'on_loan')
          AND (
                (start_date || ' ' || COALESCE(start_time, '00:00')) <= :req_end
            AND (end_date   || ' ' || COALESCE(end_time,   '23:59')) >= :req_start
          )
    ";
    $params = [
        ':req_start' => $reqStart,
        ':req_end'   => $reqEnd,
    ];
    if ($excludeId > 0) {
        $sql .= " AND id != :exclude_id";
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids  = [];
    foreach ($rows as $row) {
        $ids[] = (int)($row['equipment_id'] ?? 0);
    }

    echo json_encode(['unavailable_ids' => $ids], JSON_UNESCAPED_UNICODE);
    exit;
}

// עריכת / צפייה בהזמנה קיימת - טעינת נתונים לטופס / שכפול
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;
$loadId = $editId > 0 ? $editId : $viewId;
if ($loadId > 0) {
    $stmt = $pdo->prepare(
        'SELECT o.*,
                e.name AS equipment_name,
                e.code AS equipment_code
         FROM orders o
         JOIN equipment e ON e.id = o.equipment_id
         WHERE o.id = :id'
    );
    $stmt->execute([':id' => $loadId]);
    $editingOrder = $stmt->fetch() ?: null;
}
$isDuplicateMode = false;
if (isset($_GET['duplicate_id']) && !$editingOrder) {
    $dupId = (int)$_GET['duplicate_id'];
    if ($dupId > 0) {
        $stmt = $pdo->prepare(
            'SELECT o.*,
                    e.name AS equipment_name,
                    e.code AS equipment_code
             FROM orders o
             JOIN equipment e ON e.id = o.equipment_id
             WHERE o.id = :id'
        );
        $stmt->execute([':id' => $dupId]);
        $editingOrder = $stmt->fetch() ?: null;
        if ($editingOrder) {
            $isDuplicateMode = true;
            // אם ההזמנה המקורית כבר עברה – מאפסים תאריכים
            $todayYmd = date('Y-m-d');
            if (!empty($editingOrder['end_date']) && $editingOrder['end_date'] < $todayYmd) {
                $editingOrder['start_date'] = '';
                $editingOrder['end_date']   = '';
            }
        }
    }
}

// טיפול ביצירה / עדכון / סטטוס / מחיקה / שכפול וגם שמירת רכיבי פריט
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $currentTab = $_POST['current_tab'] ?? null;

    if ($action === 'save_components') {
        // שמירת סטטוס רכיבי פריט להזמנה (AJAX)
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $code    = trim((string)($_POST['equipment_code'] ?? ''));
        $rawJson = (string)($_POST['components'] ?? '[]');
        $items   = json_decode($rawJson, true);

        if ($orderId > 0 && $code !== '' && is_array($items)) {
            try {
                $pdo->beginTransaction();
                $del = $pdo->prepare('DELETE FROM order_component_checks WHERE order_id = :oid AND equipment_code = :code');
                $del->execute([':oid' => $orderId, ':code' => $code]);
                $ins = $pdo->prepare(
                    'INSERT INTO order_component_checks (order_id, equipment_code, component_name, is_present, returned, checked_at)
                     VALUES (:order_id, :equipment_code, :component_name, :is_present, :returned, :checked_at)'
                );
                $now = date('Y-m-d H:i:s');
                foreach ($items as $row) {
                    if (!isset($row['name'])) {
                        continue;
                    }
                    $name = trim((string)$row['name']);
                    if ($name === '') {
                        continue;
                    }
                    $present  = !empty($row['present']) ? 1 : 0;
                    $returned = !empty($row['returned']) ? 1 : 0;
                    $ins->execute([
                        ':order_id'       => $orderId,
                        ':equipment_code' => $code,
                        ':component_name' => $name,
                        ':is_present'     => $present,
                        ':returned'       => $returned,
                        ':checked_at'     => $now,
                    ]);
                }
                $pdo->commit();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json; charset=utf-8', true, 500);
                echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            }
        } else {
            header('Content-Type: application/json; charset=utf-8', true, 400);
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $id              = (int)($_POST['id'] ?? 0);
        $borrowerName    = trim($_POST['borrower_name'] ?? '');
        $borrowerContact = trim($_POST['borrower_contact'] ?? '');
        $startDate       = trim($_POST['start_date'] ?? '');
        $endDate         = trim($_POST['end_date'] ?? '');
        $startTime       = trim($_POST['start_time'] ?? '');
        $endTime         = trim($_POST['end_time'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');
        $adminNotesPost  = trim($_POST['admin_notes'] ?? '');
        $purpose         = trim($_POST['purpose'] ?? '');
        $orderStatus     = $_POST['order_status'] ?? null; // מצב הזמנה (למנהל בעריכה)
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        $returnEquipStatusInput = trim($_POST['return_equipment_status'] ?? '');
        $equipmentReturnConditionInput = trim($_POST['equipment_return_condition'] ?? '');
        $recurringEnabled = !empty($_POST['recurring_enabled']);
        $recurringStartDate = trim($_POST['recurring_start_date'] ?? '');
        $recurringStartTime = trim($_POST['recurring_start_time'] ?? '');
        $recurringFreq = $_POST['recurring_freq'] ?? 'day';
        $recurringDuration = (float)($_POST['recurring_duration'] ?? 1);
        $recurringEndType = $_POST['recurring_end_type'] ?? 'count';
        $recurringCount = (int)($_POST['recurring_count'] ?? 1);
        $recurringEndDate = trim($_POST['recurring_end_date'] ?? '');

        // איסוף מזהי ציוד – מרובים במצב יצירה, יחיד במצב עדכון
        $equipmentIds = [];
        if ($action === 'create') {
            $rawEquipmentIds = $_POST['equipment_ids'] ?? [];
            if (!is_array($rawEquipmentIds)) {
                $rawEquipmentIds = [$rawEquipmentIds];
            }
            foreach ($rawEquipmentIds as $rawId) {
                $eid = (int)$rawId;
                if ($eid > 0 && !in_array($eid, $equipmentIds, true)) {
                    $equipmentIds[] = $eid;
                }
            }
        } else { // update
            $singleEquipmentId = (int)($_POST['equipment_id'] ?? 0);
            if ($singleEquipmentId > 0) {
                $equipmentIds[] = $singleEquipmentId;
            }
        }

        // במקרה של שכפול הזמנה – לא ניתן לשמור ללא שינוי תאריכים ביחס למקור
        $originalStart = $_POST['original_start_date'] ?? '';
        $originalEnd   = $_POST['original_end_date'] ?? '';

        // אם המשתמש המחובר הוא סטודנט – לא מאפשרים להחליף שם שואל מהטופס, אלא נועלים אותו על שם המשתמש הנוכחי
        if ($role === 'student' && $me) {
            $fn = trim((string)($me['first_name'] ?? ''));
            $ln = trim((string)($me['last_name'] ?? ''));
            if ($fn !== '' || $ln !== '') {
                $borrowerName = trim($fn . ' ' . $ln);
            } else {
                $borrowerName = (string)($me['username'] ?? '');
            }
        }

        $isRecurringCreate = ($action === 'create' && $recurringEnabled && $recurringStartDate !== '' && $recurringStartTime !== ''
            && in_array($recurringFreq, ['day', 'week'], true) && $recurringDuration >= 0.5 && $recurringDuration <= 3
            && (($recurringEndType === 'count' && $recurringCount >= 1) || ($recurringEndType === 'end_date' && $recurringEndDate !== '')));

        // עדכון מיוחד מטאבים "לא נלקח"/"לא הוחזר" – מאפשר שינוי סטטוסים בלבד מבלי לאכוף ולידציה מלאה על ציוד/תאריכים
        $isSpecialStatusUpdate = (
            $action === 'update'
            && $currentTab !== null
            && in_array($currentTab, ['not_picked', 'not_returned'], true)
        );

        if (!$isSpecialStatusUpdate && (count($equipmentIds) === 0 || $borrowerName === '')) {
            $error = 'יש למלא ציוד ושם שואל.';
        } elseif (!$isSpecialStatusUpdate && $recurringEnabled && !$isRecurringCreate && $action === 'create') {
            $error = 'יש להשלים את כל פרטי ההזמנה המחזורית (תאריך ושעה התחלה, מחזוריות, משך וסיום).';
        } elseif (!$isSpecialStatusUpdate && !$isRecurringCreate && ($startDate === '' || $endDate === '')) {
            $error = 'יש למלא תאריכי התחלה וסיום.';
        } elseif (!$isSpecialStatusUpdate && $action === 'create' && !$isRecurringCreate && $originalStart !== '' && $originalEnd !== '' && $originalStart === $startDate && $originalEnd === $endDate) {
            // שכפול: חייבים לשנות לפחות אחד מהתאריכים לפני שמירה
            $error = 'בשכפול הזמנה יש לשנות את תאריכי ההשאלה ו/או ההחזרה לפני שמירה.';
        } elseif (!$isSpecialStatusUpdate && !$isRecurringCreate && $startDate !== '' && $endDate !== '' && strcmp($endDate, $startDate) < 0) {
            $error = 'תאריך החזרה לא יכול להיות מוקדם מתאריך ההשאלה.';
        } elseif (!$isSpecialStatusUpdate && !$isRecurringCreate && $startDate !== '' && $endDate !== '' && $startDate === $endDate && $startTime !== '' && $endTime !== '' && strcmp($endTime, $startTime) <= 0) {
            $error = 'באותו יום, שעת החזרה חייבת להיות מאוחרת משעת ההשאלה.';
        } else {
            $conflicted = [];
            if (!$isSpecialStatusUpdate && !$isRecurringCreate && count($equipmentIds) > 0) {
                $reqStart = $startDate . ' ' . ($startTime !== '' ? $startTime : '00:00');
                $reqEnd   = $endDate . ' ' . ($endTime !== '' ? $endTime : '23:59');
                $excludeId = ($action === 'update' && $id > 0) ? $id : 0;
                $placeholders = implode(',', array_fill(0, count($equipmentIds), '?'));
                $sql = "SELECT DISTINCT equipment_id FROM orders
                        WHERE equipment_id IN ($placeholders)
                        AND status IN ('pending', 'approved', 'on_loan')
                        AND (start_date || ' ' || COALESCE(start_time, '00:00')) <= ?
                        AND (end_date || ' ' || COALESCE(end_time, '23:59')) >= ?
                        AND id != ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge($equipmentIds, [$reqEnd, $reqStart, $excludeId]));
                $conflicted = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            if (!empty($conflicted)) {
                $error = 'חלק מפריטי הציוד הנבחרים תפוסים בפרק הזמן הנבחר. נא לבחור תאריכים אחרים או להסיר פריטים תפוסים.';
            }
            if ($error === '') {
            try {
                if ($action === 'create' && $isRecurringCreate) {
                    // יצירת הזמנות מחזוריות: חישוב מופעים והכנסה לכל (מופע × ציוד)
                    $occurrences = [];
                    $startTs = strtotime($recurringStartDate . ' ' . $recurringStartTime);
                    if ($startTs === false) {
                        $error = 'תאריך או שעת התחלה לא תקינים.';
                    } else {
                        $stepDays = ($recurringFreq === 'week') ? 7 : 1;
                        $durationMinutes = (int)round($recurringDuration * 60);
                        $n = ($recurringEndType === 'count') ? $recurringCount : 0;
                        $count = 0;
                        $current = $startTs;
                        while (true) {
                            if ($recurringEndType === 'count' && $count >= $n) break;
                            if ($recurringEndType === 'end_date' && $recurringEndDate !== '' && date('Y-m-d', $current) > $recurringEndDate) break;
                            $startDateOcc = date('Y-m-d', $current);
                            $startTimeOcc = date('H:i', $current);
                            $endTsOcc = $current + $durationMinutes * 60;
                            $endDateOcc = date('Y-m-d', $endTsOcc);
                            $endTimeOcc = date('H:i', $endTsOcc);
                            $occurrences[] = [$startDateOcc, $startTimeOcc, $endDateOcc, $endTimeOcc];
                            $count++;
                            $current = strtotime('+' . $stepDays . ' days', $current);
                        }
                        if (empty($occurrences)) {
                            $error = 'לא נוצר אף מופע בהזמנה המחזורית. בדוק תאריך סיום או כמות.';
                        } else {
                            // בדרישת מערכת: בהזמנה מחזורית נוצרת הזמנה בודדת מאושרת לכל מופע
                            $initialStatus = 'approved';
                            $insertRecurring = $pdo->prepare(
                                'INSERT INTO orders (equipment_id, borrower_name, borrower_contact, start_date, end_date, start_time, end_time, status, notes, purpose, admin_notes, created_at, creator_username, return_equipment_status)
                                 VALUES (:equipment_id, :borrower_name, :borrower_contact, :start_date, :end_date, :start_time, :end_time, :status, :notes, :purpose, :admin_notes, :created_at, :creator_username, :return_equipment_status)'
                            );
                            $createdCount = 0;
                            $occurrenceIndex = 0;
                            foreach ($equipmentIds as $equipmentId) {
                                foreach ($occurrences as $occ) {
                                    $occurrenceIndex++;
                                    $insertRecurring->execute([
                                        ':equipment_id'           => $equipmentId,
                                        // "שם ההזמנה": שומרים מספור עוקב לכל מופע מחזורי
                                        ':borrower_name'          => trim($borrowerName . ' ' . $occurrenceIndex),
                                        ':borrower_contact'       => $borrowerContact,
                                        ':start_date'             => $occ[0],
                                        ':end_date'               => $occ[2],
                                        ':start_time'             => $occ[1],
                                        ':end_time'               => $occ[3],
                                        ':status'                 => $initialStatus,
                                        ':notes'                  => $notes,
                                        ':purpose'                => $purpose !== '' ? $purpose : null,
                                        ':admin_notes'            => $adminNotesPost !== '' ? $adminNotesPost : null,
                                        ':created_at'             => date('Y-m-d H:i:s'),
                                        ':creator_username'       => (string)($me['username'] ?? ''),
                                        ':return_equipment_status'=> 'תקין',
                                    ]);
                                    $createdCount++;
                                }
                            }
                            $success = $createdCount > 1 ? "נוצרו {$createdCount} הזמנות בהצלחה." : 'הזמנה נוצרה בהצלחה.';

                            // התראה למנהלים על הזמנה מחזורית חדשה שיצר סטודנט
                            if ($role === 'student') {
                                $creatorName = (string)($me['username'] ?? ($me['first_name'] ?? 'סטודנט'));
                                $msg = 'סטודנט ' . $creatorName . ' יצר הזמנה מחזורית חדשה.';
                                $link = 'admin_orders.php?tab=pending';
                                create_notification($pdo, null, 'admin', $msg, $link);
                                create_notification($pdo, null, 'warehouse_manager', $msg, $link);
                            }

                            if ($role === 'student') {
                                header('Location: admin_orders.php?tab=pending');
                            } else {
                                header('Location: admin_orders.php');
                            }
                            exit;
                        }
                    }
                } elseif ($action === 'create') {
                    // הזמנה שנפתחת ע\"י סטודנט מתחילה כ\"ממתין\"; ע\"י אדמין/מנהל מחסן – מאושרת כברירת מחדל
                    $initialStatus = ($role === 'student') ? 'pending' : 'approved';
                    $stmt = $pdo->prepare(
                        'INSERT INTO orders
                         (equipment_id, borrower_name, borrower_contact, start_date, end_date, start_time, end_time, status, notes, purpose, admin_notes, created_at, creator_username, return_equipment_status)
                         VALUES
                         (:equipment_id, :borrower_name, :borrower_contact, :start_date, :end_date, :start_time, :end_time, :status, :notes, :purpose, :admin_notes, :created_at, :creator_username, :return_equipment_status)'
                    );
                    $createdCount = 0;
                    foreach ($equipmentIds as $equipmentId) {
                        $stmt->execute([
                            ':equipment_id'           => $equipmentId,
                            ':borrower_name'          => $borrowerName,
                            ':borrower_contact'       => $borrowerContact,
                            ':start_date'             => $startDate,
                            ':end_date'               => $endDate,
                            ':start_time'             => $startTime !== '' ? $startTime : null,
                            ':end_time'               => $endTime !== '' ? $endTime : null,
                            ':status'                 => $initialStatus,
                            ':notes'                  => $notes,
                            ':purpose'                => $purpose !== '' ? $purpose : null,
                            ':admin_notes'            => $adminNotesPost !== '' ? $adminNotesPost : null,
                            ':created_at'             => date('Y-m-d H:i:s'),
                            ':creator_username'       => (string)($me['username'] ?? ''),
                            ':return_equipment_status'=> 'תקין',
                        ]);
                        $createdCount++;
                    }
                    $success = $createdCount > 1
                        ? "נוצרו {$createdCount} הזמנות בהצלחה."
                        : 'הזמנה נוצרה בהצלחה.';

                    // התראה למנהלים על הזמנה חדשה שיצר סטודנט
                    if ($role === 'student') {
                        $creatorName = (string)($me['username'] ?? ($me['first_name'] ?? 'סטודנט'));
                        $msg = 'סטודנט ' . $creatorName . ' יצר הזמנה חדשה.';
                        $link = 'admin_orders.php?tab=pending';
                        create_notification($pdo, null, 'admin', $msg, $link);
                        create_notification($pdo, null, 'warehouse_manager', $msg, $link);
                    }

                    // לאחר יצירת הזמנה – סוגרים את הטופס תמיד ע\"י רענון לדף הרשימה
                    if ($role === 'student') {
                        header('Location: admin_orders.php?tab=pending');
                    } else {
                        header('Location: admin_orders.php');
                    }
                    exit;
                } elseif ($action === 'update' && $id > 0) {
                    // אם מנהל שינה את מצב ההזמנה – בודקים שהמעבר חוקי ומעדכנים
                    $currentStatusRow = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
                    $currentStatusRow->execute([':id' => $id]);
                    $orderRow = $currentStatusRow->fetch(PDO::FETCH_ASSOC) ?: [];
                    $currentStatus = (string)($orderRow['status'] ?? '');
                    $creatorUsername = (string)($orderRow['creator_username'] ?? '');

                    // במצב עדכון מיוחד מטאבים "לא נלקח"/"לא הוחזר" – שומרים על פרטי ההזמנה המקוריים
                    // כדי לא לדרוס תאריכים וציוד בשדות שלא נשלחים מהטופס (inputs במצב disabled).
                    if ($isSpecialStatusUpdate) {
                        $equipmentId     = (int)($orderRow['equipment_id'] ?? 0);
                        $borrowerName    = (string)($orderRow['borrower_name'] ?? '');
                        $borrowerContact = (string)($orderRow['borrower_contact'] ?? '');
                        $startDate       = (string)($orderRow['start_date'] ?? '');
                        $endDate         = (string)($orderRow['end_date'] ?? '');
                        $startTime       = (string)($orderRow['start_time'] ?? '');
                        $endTime         = (string)($orderRow['end_time'] ?? '');
                        $notes           = (string)($orderRow['notes'] ?? '');
                        $purpose         = (string)($orderRow['purpose'] ?? '');
                        $adminNotesToSave = (string)($orderRow['admin_notes'] ?? '');
                    } else {
                        $equipmentId = $equipmentIds[0] ?? (int)($orderRow['equipment_id'] ?? 0);
                        $adminNotesToSave = $adminNotesPost;
                        if ($role === 'student') {
                            $adminNotesToSave = (string)($orderRow['admin_notes'] ?? '');
                        }
                    }
                    // טאב "היום / החזרה": שדות מנוטרלים – לא לדרוס מטרה/הערות מה-DB
                    $todayModePost = (string)($_POST['current_today_mode'] ?? '');
                    if (
                        $action === 'update'
                        && ($currentTab ?? '') === 'today'
                        && $todayModePost === 'return'
                    ) {
                        $purpose = (string)($orderRow['purpose'] ?? '');
                        $notes = (string)($orderRow['notes'] ?? '');
                        $adminNotesToSave = (string)($orderRow['admin_notes'] ?? '');
                    }
                    $allowedNext = [];
                    if ($currentStatus === 'pending') {
                        $allowedNext = ['approved', 'rejected'];
                    } elseif ($currentStatus === 'approved') {
                        $allowedNext = ['on_loan'];
                        // בטאב "לא נלקח" מאפשרים סגירה ישירה ל"עבר"
                        if ($currentTab === 'not_picked') {
                            $allowedNext[] = 'returned';
                        }
                    } elseif ($currentStatus === 'on_loan') {
                        $allowedNext = ['returned'];
                    }
                    $newStatus = $currentStatus;
                    if ($orderStatus !== null && $orderStatus !== '') {
                        if (in_array($orderStatus, $allowedNext, true)) {
                            $newStatus = $orderStatus;
                        } elseif ($orderStatus === $currentStatus) {
                            $newStatus = $currentStatus;
                        }
                    }
                    if ($newStatus === 'rejected' && $rejectionReason !== '') {
                        if ($notes !== '') {
                            $notes .= "\n";
                        }
                        $notes .= 'סיבה לדחייה: ' . $rejectionReason;
                    }

                    // סטטוס החזרת ציוד – בסיס קיים + קלט מנהל + חוקים אוטומטיים
                    $existingReturnStatus = (string)($orderRow['return_equipment_status'] ?? '');
                    $returnEquipStatusDb = $existingReturnStatus;

                    // קלט מפורש של מנהל/מנהל מחסן גובר על ברירת מחדל
                    if ($role === 'admin' || $role === 'warehouse_manager') {
                        if ($returnEquipStatusInput !== '') {
                            $returnEquipStatusDb = $returnEquipStatusInput;
                        }
                    }

                    $todayStatusYmd = date('Y-m-d');

                    // סטטוס "לא נאסף" – הזמנה שלא נאספה בזמן
                    // נוצר אוטומטית כאשר ההזמנה נשארת מאושרת אחרי יום ההשאלה
                    if (
                        $newStatus === 'approved'
                        && $startDate !== ''
                        && $startDate < $todayStatusYmd
                        && $returnEquipStatusInput === ''
                        && ($returnEquipStatusDb === '' || $returnEquipStatusDb === 'תקין')
                    ) {
                        $returnEquipStatusDb = 'לא נאסף';
                    }

                    // סטטוס "לא הוחזר בזמן" – ציוד שהיה בסטטוס "לא הוחזר" ועובר ל"הוחזר/עבר"
                    // נקבע כאשר סטטוס בפועל היה בהשאלה והוחזר לאחר מועד ההחזרה
                    $orderEndDateFromDb = (string)($orderRow['end_date'] ?? '');
                    if (
                        $currentStatus === 'on_loan'
                        && $newStatus === 'returned'
                        && $orderEndDateFromDb !== ''
                        && $orderEndDateFromDb < $todayStatusYmd
                        && $returnEquipStatusInput === ''
                        && ($returnEquipStatusDb === '' || $returnEquipStatusDb === 'תקין')
                    ) {
                        $returnEquipStatusDb = 'לא הוחזר בזמן';
                    }

                    // אם ההזמנה במצב "עבר" ולא נקבע סטטוס החזרה (או שהערך לא תקין) – ברירת מחדל היא "תקין"
                    if ($newStatus === 'returned') {
                        $allowedReturnStatuses = ['תקין', 'לא נאסף', 'לא הוחזר בזמן'];
                        if (!in_array($returnEquipStatusDb, $allowedReturnStatuses, true)) {
                            $returnEquipStatusDb = 'תקין';
                        }
                    }

                    $equipmentReturnConditionDb = (string)($orderRow['equipment_return_condition'] ?? '');
                    if ($role === 'admin' || $role === 'warehouse_manager') {
                        $allowedReturnConditions = ['תקין', 'תקול', 'חסר'];
                        if ($equipmentReturnConditionInput !== '' && in_array($equipmentReturnConditionInput, $allowedReturnConditions, true)) {
                            $equipmentReturnConditionDb = $equipmentReturnConditionInput;
                        }

                        // אם ההזמנה במצב "עבר" ולא נבחר מצב ציוד מוחזר – ברירת מחדל היא "תקין"
                        if ($newStatus === 'returned') {
                            if (!in_array($equipmentReturnConditionDb, $allowedReturnConditions, true)) {
                                $equipmentReturnConditionDb = 'תקין';
                            }
                        }
                    }

                    $stmt = $pdo->prepare(
                        'UPDATE orders
                         SET equipment_id               = :equipment_id,
                             borrower_name              = :borrower_name,
                             borrower_contact           = :borrower_contact,
                             start_date                 = :start_date,
                             end_date                   = :end_date,
                             start_time                 = :start_time,
                             end_time                   = :end_time,
                             status                     = :status,
                             notes                      = :notes,
                             purpose                    = :purpose,
                             admin_notes                = :admin_notes,
                             updated_at                 = :updated_at,
                             return_equipment_status    = :return_equipment_status,
                             equipment_return_condition = :equipment_return_condition
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':equipment_id'               => $equipmentId,
                        ':borrower_name'              => $borrowerName,
                        ':borrower_contact'           => $borrowerContact,
                        ':start_date'                 => $startDate,
                        ':end_date'                   => $endDate,
                        ':start_time'                 => $startTime !== '' ? $startTime : null,
                        ':end_time'                   => $endTime !== '' ? $endTime : null,
                        ':status'                     => $newStatus,
                        ':notes'                      => $notes,
                        ':purpose'                    => $purpose !== '' ? $purpose : null,
                        ':admin_notes'                => $adminNotesToSave !== '' ? $adminNotesToSave : null,
                        ':updated_at'                 => date('Y-m-d H:i:s'),
                        ':return_equipment_status'    => $returnEquipStatusDb,
                        ':equipment_return_condition' => $equipmentReturnConditionDb,
                        ':id'                         => $id,
                    ]);

                    $success = 'הזמנה עודכנה בהצלחה.';

                    // התראה לסטודנט על שינוי סטטוס הזמנה
                    if ($creatorUsername !== '' && $newStatus !== $currentStatus) {
                        $userStmt = $pdo->prepare('SELECT id, role FROM users WHERE username = :username LIMIT 1');
                        $userStmt->execute([':username' => $creatorUsername]);
                        $creatorUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                        if ($creatorUser && (int)$creatorUser['id'] > 0) {
                            $studentId = (int)$creatorUser['id'];
                            $statusMsg = '';
                            if ($newStatus === 'approved') {
                                $statusMsg = 'הבקשה להזמנה אושרה.';
                            } elseif ($newStatus === 'rejected') {
                                $statusMsg = 'הבקשה להזמנה נדחתה.';
                            } elseif ($newStatus === 'on_loan') {
                                $statusMsg = 'הציוד יצא להשאלה.';
                            } elseif ($newStatus === 'returned') {
                                $statusMsg = 'הציוד הוחזר ונקלט במערכת.';
                            }
                            if ($statusMsg !== '') {
                                create_notification(
                                    $pdo,
                                    $studentId,
                                    null,
                                    $statusMsg,
                                    'admin_orders.php'
                                );
                            }
                        }
                    }

                    // ניווט לאחר שמירה:
                    // אם התקבל current_tab בטופס – נשארים בטאב שממנו נפתחה העריכה.
                    $allowedTabsForRedirect = ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history'];
                    $currentTodayMode = $_POST['current_today_mode'] ?? null;
                    if ($currentTab && in_array($currentTab, $allowedTabsForRedirect, true)) {
                        // במעבר מ"טאב לא הוחזר" לסטטוס "עבר" – עוברים לטאב היסטוריה
                        if ($currentTab === 'not_returned' && $newStatus === 'returned') {
                            header('Location: admin_orders.php?tab=history');
                            exit;
                        }

                        $redirectUrl = 'admin_orders.php?tab=' . urlencode($currentTab);
                        if ($currentTab === 'today' && $currentTodayMode) {
                            $redirectUrl .= '&today_mode=' . urlencode($currentTodayMode);
                        }
                        header('Location: ' . $redirectUrl);
                        exit;
                    }

                    // אחרת – שומרים את הלוגיקה הקיימת לפי סטטוס
                    $today = date('Y-m-d');
                    $targetTab = 'pending';
                    if ($newStatus === 'approved') {
                        if ($startDate > $today) {
                            $targetTab = 'future';
                        } elseif ($startDate <= $today && $endDate >= $today) {
                            $targetTab = 'today';
                        } else {
                            $targetTab = 'not_picked'; // עבר מועד ההשאלה – לא נלקח
                        }
                        header('Location: admin_orders.php?tab=' . $targetTab);
                        exit;
                    }
                    if ($newStatus === 'rejected') {
                        header('Location: admin_orders.php?tab=pending');
                        exit;
                    }
                    if ($newStatus === 'on_loan') {
                        header('Location: admin_orders.php?tab=active');
                        exit;
                    }
                    if ($newStatus === 'returned') {
                        header('Location: admin_orders.php?tab=history');
                        exit;
                    }
                    header('Location: admin_orders.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'שגיאה בשמירת ההזמנה.';
            }
            }
        }
    } elseif ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        if ($id > 0) {
            // מביאים את הסטטוס הנוכחי כדי לבדוק מעבר חוקי + שם יוצר ההזמנה
            $stmt = $pdo->prepare('SELECT status, creator_username FROM orders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $currentStatus   = $row['status'] ?? null;
            $creatorUsername = (string)($row['creator_username'] ?? '');

            $allowedNext = [];
            if ($currentStatus === 'pending') {
                $allowedNext = ['approved', 'rejected'];
            } elseif ($currentStatus === 'approved') {
                $allowedNext = ['on_loan'];
            } elseif ($currentStatus === 'on_loan') {
                $allowedNext = ['returned'];
            } else {
                $allowedNext = [];
            }

            if ($currentStatus === null) {
                $error = 'ההזמנה לא נמצאה.';
            } elseif (!in_array($status, $allowedNext, true)) {
                $error = 'שינוי סטטוס זה אינו מותר לפי כללי המעבר.';
            } else {
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

                // התראה לסטודנט על שינוי סטטוס (גם בעדכון מתוך הטבלה)
                if ($creatorUsername !== '' && $currentStatus !== null && $status !== $currentStatus) {
                    $userStmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                    $userStmt->execute([':u' => $creatorUsername]);
                    $creatorUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                    if ($creatorUser && (int)$creatorUser['id'] > 0) {
                        $studentId = (int)$creatorUser['id'];
                        $statusMsg = '';
                        if ($status === 'approved') {
                            $statusMsg = 'הבקשה להזמנה אושרה.';
                        } elseif ($status === 'rejected') {
                            $statusMsg = 'הבקשה להזמנה נדחתה.';
                        } elseif ($status === 'on_loan') {
                            $statusMsg = 'הציוד יצא להשאלה.';
                        } elseif ($status === 'returned') {
                            $statusMsg = 'הציוד הוחזר ונקלט במערכת.';
                        }
                        if ($statusMsg !== '') {
                            create_notification(
                                $pdo,
                                $studentId,
                                null,
                                $statusMsg,
                                'admin_orders.php'
                            );
                        }
                    }
                }

                // ניתוב אחרי שינוי סטטוס:
                // מ"pending" ל"approved" – נשארים בטאב "ממתין"
                if ($currentStatus === 'pending' && $status === 'approved') {
                    header('Location: admin_orders.php?tab=pending');
                    exit;
                }
                // מ"approved" ל"on_loan" – עוברים לטאב "בהשאלה"
                if ($currentStatus === 'approved' && $status === 'on_loan') {
                    header('Location: admin_orders.php?tab=active');
                    exit;
                }
            }
        }
    } elseif ($action === 'update_status_bulk') {
        $raw = $_POST['changes_json'] ?? '';
        $changes = json_decode((string)$raw, true);
        if (!is_array($changes)) {
            $error = 'מבנה נתונים לא תקין לעדכון סטטוס.';
        } else {
            foreach ($changes as $change) {
                $id     = isset($change['id']) ? (int)$change['id'] : 0;
                $status = $change['status'] ?? '';
                if ($id <= 0 || $status === '') {
                    continue;
                }

                $stmt = $pdo->prepare('SELECT status, creator_username FROM orders WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentStatus   = $row['status'] ?? null;
                $creatorUsername = (string)($row['creator_username'] ?? '');

                $allowedNext = [];
                if ($currentStatus === 'pending') {
                    $allowedNext = ['approved', 'rejected'];
                } elseif ($currentStatus === 'approved') {
                    $allowedNext = ['on_loan'];
                } elseif ($currentStatus === 'on_loan') {
                    $allowedNext = ['returned'];
                }

                if ($currentStatus === null || !in_array($status, $allowedNext, true)) {
                    continue;
                }

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

                // התראה לסטודנט על שינוי סטטוס בעדכון מרובה
                if ($creatorUsername !== '' && $currentStatus !== null && $status !== $currentStatus) {
                    $userStmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                    $userStmt->execute([':u' => $creatorUsername]);
                    $creatorUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                    if ($creatorUser && (int)$creatorUser['id'] > 0) {
                        $studentId = (int)$creatorUser['id'];
                        $statusMsg = '';
                        if ($status === 'approved') {
                            $statusMsg = 'הבקשה להזמנה אושרה.';
                        } elseif ($status === 'rejected') {
                            $statusMsg = 'הבקשה להזמנה נדחתה.';
                        } elseif ($status === 'on_loan') {
                            $statusMsg = 'הציוד יצא להשאלה.';
                        } elseif ($status === 'returned') {
                            $statusMsg = 'הציוד הוחזר ונקלט במערכת.';
                        }
                        if ($statusMsg !== '') {
                            create_notification(
                                $pdo,
                                $studentId,
                                null,
                                $statusMsg,
                                'admin_orders.php'
                            );
                        }
                    }
                }
            }

            $success = 'סטטוס ההזמנות עודכן.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $success = 'הזמנה נמחקה.';

            // לאחר מחיקה – נשארים בטאב ממנו בוצעה הפעולה (נשלח בטופס)
            if ($currentTab && in_array($currentTab, ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history'], true)) {
                header('Location: admin_orders.php?tab=' . urlencode($currentTab));
                exit;
            }
        }
    } elseif ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // במקום שכפול מידי – מעבר למסך \"שכפול הזמנה\"
            header('Location: admin_orders.php?duplicate_id=' . $id);
            exit;
        }
    }
}

// ציוד לבחירה בטופס – רק ציוד פעיל ופנוי בטווח התאריכים שנבחרו, ובמחסן של המשתמש (אם הוגדר)
$userWarehouse = '';
if ($me) {
    $userWarehouse = trim((string)($me['warehouse'] ?? ''));
}

$equipmentSql = "SELECT id, name, code, category, location
                 FROM equipment
                 WHERE status = 'active'";
$equipmentParams = [];
if ($userWarehouse !== '') {
    $equipmentSql .= " AND location = :warehouse";
    $equipmentParams[':warehouse'] = $userWarehouse;
}
$equipmentSql .= " ORDER BY name ASC";

$equipmentStmt = $pdo->prepare($equipmentSql);
$equipmentStmt->execute($equipmentParams);
$equipmentOptions = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// קטגוריות ייחודיות לצורך סינון
$equipmentCategories = [];
foreach ($equipmentOptions as $item) {
    $cat = trim((string)($item['category'] ?? ''));
    if ($cat === '') {
        $cat = 'ללא קטגוריה';
    }
    if (!in_array($cat, $equipmentCategories, true)) {
        $equipmentCategories[] = $cat;
    }
}
sort($equipmentCategories, SORT_NATURAL | SORT_FLAG_CASE);

// טאבים וסינון
$today     = date('Y-m-d');
$tab       = $_GET['tab'] ?? 'today';
// today    – הזמנות שיום ההשאלה/ההחזרה שלהן הוא היום (מאושרות / בהשאלה)
// pending  – הזמנות ממתינות לאישור אדמין
// future   – הזמנות מאושרות לעתיד (לפני יום ההשאלה)
// active   – בהשאלה (status = on_loan) ועדיין לא עבר מועד ההחזרה
// not_returned – בהשאלה (status = on_loan) שעבר מועד ההחזרה – לא הוחזר
// not_picked  – מאושר אך עבר מועד ההשאלה ולא נלקח
// history  – הזמנות שהסתיימו (status = returned)
$validTabs = ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history'];

if (!in_array($tab, $validTabs, true)) {
    $tab = 'today';
}

// תתי־טאבים ל"טאב היום": השאלה/החזרה
$todayMode = 'borrow';
if ($tab === 'today') {
    $todayMode = $_GET['today_mode'] ?? 'borrow';
    if (!in_array($todayMode, ['borrow', 'return'], true)) {
        $todayMode = 'borrow';
    }
}

// טעינת הזמנות לפי טאב
$baseSql = 'SELECT o.id,
                   o.borrower_name,
                   o.borrower_contact,
                   o.start_date,
                   o.end_date,
                   o.start_time,
                   o.end_time,
                   o.status,
                   u.id AS borrower_user_id,
                   o.notes,
                   o.created_at,
                   o.updated_at,
                   o.creator_username,
                   e.name AS equipment_name,
                   e.code AS equipment_code
            FROM orders o
            JOIN equipment e ON e.id = o.equipment_id
            LEFT JOIN users u
                   ON u.username = o.borrower_name
                   OR TRIM(COALESCE(u.first_name, \'\') || \' \' || COALESCE(u.last_name, \'\')) = o.borrower_name';

$where  = '';
$params = [];

switch ($tab) {
    case 'pending':
        // בקשות שממתינות לאישור אדמין
        $where = " WHERE o.status = 'pending'";
        break;

    case 'future':
        // בקשות מאושרות שהן לעתיד (לפני יום ההשאלה)
        $where = " WHERE o.status = 'approved'
                   AND DATE(o.start_date) > :today";
        $params[':today'] = $today;
        break;

    case 'not_picked':
        // מאושר אך עבר מועד ההשאלה ולא נלקח
        $where = " WHERE o.status = 'approved'
                   AND DATE(o.start_date) < :today";
        $params[':today'] = $today;
        break;

    case 'active':
        // בקשות שנמצאות כרגע בסטטוס 'בהשאלה' ועדיין לא עבר מועד ההחזרה
        $where = " WHERE o.status = 'on_loan'
                   AND DATE(o.end_date) >= :today";
        $params[':today'] = $today;
        break;

    case 'not_returned':
        // בקשות שבסטטוס 'בהשאלה' אך עבר מועד ההחזרה – לא הוחזר
        $where = " WHERE o.status = 'on_loan'
                   AND DATE(o.end_date) < :today";
        $params[':today'] = $today;
        break;

    case 'history':
        // בקשות שהסתיימו – הוחזר (עבר)
        $where = " WHERE o.status = 'returned'";
        break;

    case 'today':
    default:
        // היום – מפוצל לתת־מצבים:
        // השאלה: כל בקשה שהיום הוא יום ההשאלה
        // החזרה: כל בקשה שהיום הוא יום ההחזרה
        // הזמנות בסטטוס "בהשאלה" נראות בטאבים "בהשאלה"/"לא הוחזר" בלבד
        if ($todayMode === 'return') {
            // החזרה: ניתן להחזיר רק הזמנות שכבר יצאו להשאלה (on_loan) והחזרה שלהן היום
            $where = " WHERE o.status = 'on_loan'
                       AND DATE(o.end_date) = :today";
        } else {
            $where = " WHERE o.status = 'approved'
                       AND DATE(o.start_date) = :today";
        }
        $params[':today'] = $today;
        break;
}

// אם המשתמש הוא סטודנט – מציגים רק הזמנות של עצמו (לפי אותו שם שנשמר ב-borrower_name)
if ($role === 'student' && $me) {
    $fn = trim((string)($me['first_name'] ?? ''));
    $ln = trim((string)($me['last_name'] ?? ''));
    $currentBorrower = '';
    if ($fn !== '' || $ln !== '') {
        $currentBorrower = trim($fn . ' ' . $ln);
    } else {
        $currentBorrower = (string)($me['username'] ?? '');
    }

    if ($currentBorrower !== '') {
        if ($where === '') {
            $where = ' WHERE o.borrower_name = :current_student';
        } else {
            $where .= ' AND o.borrower_name = :current_student';
        }
        $params[':current_student'] = $currentBorrower;
    }
}

$ordersSql  = $baseSql . $where . ' ORDER BY o.created_at DESC, o.id DESC';
$ordersStmt = $pdo->prepare($ordersSql);
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll();

// רכיבי ציוד – נטען לכל הציוד שמופיע ברשימת ההזמנות (לטובת טאב "היום")
$orderEquipmentIds = [];
foreach ($orders as $row) {
    if (isset($row['equipment_code']) && $row['equipment_code'] !== null) {
        // נזהה לפי code, אחר כך נמצא id
        $orderEquipmentIds[] = (string)$row['equipment_code'];
    }
}
$orderEquipmentIds = array_values(array_unique($orderEquipmentIds));

$equipmentComponentsByCode = [];
if (!empty($orderEquipmentIds)) {
    $placeholders = implode(',', array_fill(0, count($orderEquipmentIds), '?'));
    $stmtEqForComp = $pdo->prepare(
        "SELECT e.code AS equipment_code, c.name, c.quantity
         FROM equipment_components c
         JOIN equipment e ON e.id = c.equipment_id
         WHERE e.code IN ($placeholders)
         ORDER BY c.name ASC"
    );
    $stmtEqForComp->execute($orderEquipmentIds);
    $rowsComp = $stmtEqForComp->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsComp as $cRow) {
        $code = (string)($cRow['equipment_code'] ?? '');
        if ($code === '') {
            continue;
        }
        if (!isset($equipmentComponentsByCode[$code])) {
            $equipmentComponentsByCode[$code] = [];
        }
        $equipmentComponentsByCode[$code][] = [
            'name'     => (string)($cRow['name'] ?? ''),
            'quantity' => (int)($cRow['quantity'] ?? 1),
        ];
    }
}

// טעינת סטטוס רכיבי פריט שנשמרו להזמנות
$componentChecksByOrderAndCode = [];
try {
    $orderIdsForChecks = array_column($orders, 'id');
    $orderIdsForChecks = array_values(array_unique(array_map('intval', $orderIdsForChecks)));
    if (!empty($orderIdsForChecks)) {
        $placeholders = implode(',', array_fill(0, count($orderIdsForChecks), '?'));
        $stmtChecks = $pdo->prepare(
            "SELECT order_id, equipment_code, component_name, is_present, returned
             FROM order_component_checks
             WHERE order_id IN ($placeholders)"
        );
        $stmtChecks->execute($orderIdsForChecks);
        $rowsChecks = $stmtChecks->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rowsChecks as $r) {
            $oid  = (int)($r['order_id'] ?? 0);
            $code = (string)($r['equipment_code'] ?? '');
            $name = (string)($r['component_name'] ?? '');
            if ($oid <= 0 || $code === '' || $name === '') {
                continue;
            }
            if (!isset($componentChecksByOrderAndCode[$oid])) {
                $componentChecksByOrderAndCode[$oid] = [];
            }
            if (!isset($componentChecksByOrderAndCode[$oid][$code])) {
                $componentChecksByOrderAndCode[$oid][$code] = [];
            }
            $componentChecksByOrderAndCode[$oid][$code][$name] = [
                'present'  => (int)($r['is_present'] ?? 0) === 1,
                'returned' => (int)($r['returned'] ?? 0) === 1,
            ];
        }
    }
} catch (Throwable $e) {
    $componentChecksByOrderAndCode = [];
}

// רשימת סטודנטים (משתתפים) לשדה החיפוש של "שם שואל"
// רשימת סטודנטים לבחירה של אדמין / מנהל מחסן בלבד (כולל פרטי קשר)
$students = [];
if ($role === 'admin' || $role === 'warehouse_manager') {
    $studentsStmt = $pdo->prepare(
        "SELECT username, first_name, last_name, email, phone
         FROM users
         WHERE role = 'student' AND is_active = 1
         ORDER BY username ASC"
    );
    $studentsStmt->execute();
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
}

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
        .main-nav {
            margin-top: 0.5rem;
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
        }
        .main-nav a {
            color: #e5e7eb;
            text-decoration: none;
        }
        .main-nav-primary {
            display: flex;
            gap: 0.8rem;
        }
        .main-nav-item-wrapper {
            position: relative;
        }
        .equipment-components-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }
        .equipment-components-link:hover {
            text-decoration: underline;
        }
        /* .main-nav-sub מוגדר ב־admin_header.php */
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
        .btn[disabled] {
            opacity: 0.5;
            cursor: default;
            background: #e5e7eb;
            color: #9ca3af;
        }
        #bulk_update_btn:not([disabled]) {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #ffffff;
        }
        .order-status-select {
            width: 90px;
            max-width: 90px;
            font-size: 0.7rem;
            padding-right: 0.25rem;
            padding-left: 0.25rem;
        }
        .icon-btn {
            border: none;
            background: transparent;
            padding: 0;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            line-height: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            min-width: 1.75rem;
            min-height: 1.75rem;
            box-sizing: border-box;
        }
        .icon-btn svg,
        .icon-btn i {
            display: block;
            line-height: 0;
        }
        .recurring-toggle-row .toggle-label { display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; }
        .recurring-toggle-row input[type="checkbox"] { width: 1.1rem; height: 1.1rem; }
        .recurring-section { border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; background: #f9fafb; margin-bottom: 0.75rem; }
        .recurring-row { margin-bottom: 0.6rem; }
        .recurring-row label { display: block; font-size: 0.9rem; margin-bottom: 0.2rem; }
        .recurring-date-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .recurring-date-row input, .recurring-date-row select { padding: 0.35rem 0.5rem; border-radius: 6px; border: 1px solid #d1d5db; }
        .recurring-end-radio { display: flex; gap: 1rem; }
        .recurring-end-radio label { display: inline-flex; align-items: center; gap: 0.35rem; margin-bottom: 0; }
        .recurring-mini-cal { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.5rem; max-width: 280px; }
        .recurring-mini-cal .day-cell { display: inline-block; width: 2rem; text-align: center; padding: 0.25rem; margin: 2px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
        .recurring-mini-cal .day-cell:hover { background: #e5e7eb; }
        .recurring-mini-cal .day-cell.selected { background: #4f46e5; color: #fff; }
        .recurring-mini-cal .day-cell.disabled { color: #9ca3af; cursor: not-allowed; }
        .time-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .time-modal {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.35);
            padding: 1rem 1.25rem;
            min-width: 260px;
        }
        .time-modal h3 {
            margin: 0 0 0.5rem;
            font-size: 0.95rem;
        }
        .time-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.4rem;
            margin-bottom: 0.8rem;
        }
        .time-option-btn {
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            padding: 0.25rem 0.4rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .time-option-btn.selected {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            border-color: transparent;
            color: #ffffff;
        }
        .time-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.4rem;
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
            position: sticky;
            top: 0;
            z-index: 1;
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
        /* צביעת שורות להזמנות במצב בקשה (pending) לפי קרבת מועד ההשאלה */
        .row-pending-soon {
            background-color: #fffbea; /* צהוב בהיר – יום עבודה לפני */
        }
        .row-pending-today {
            background-color: #ffedd5; /* כתום בהיר – ביום ההשאלה */
        }
        .row-pending-late {
            background-color: #fee2e2; /* אדום בהיר – לאחר מועד ההשאלה */
        }
        .muted-small {
            font-size: 0.78rem;
            color: #6b7280;
        }
        .row-actions {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        .row-actions form {
            margin: 0;
            display: inline-flex;
            align-items: center;
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
        .toolbar {
            margin-bottom: 0.5rem;
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
            background: var(--gf-footer-bg, #111827);
            color: var(--gf-footer-text, #9ca3af);
            text-align: center;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            border-top: 1px solid #1f2937;
        }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(15,23,42,0.45);
            max-width: 1000px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem 1.5rem 1.25rem;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .modal-close {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
        }
        .suggestions {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            max-height: 160px;
            overflow-y: auto;
            margin-top: 0.2rem;
            font-size: 0.85rem;
            box-shadow: 0 4px 10px rgba(15,23,42,0.08);
        }
        .suggestion-item {
            padding: 0.3rem 0.5rem;
            cursor: pointer;
        }
        .suggestion-item:hover {
            background: #f3f4f6;
        }
        /* מטרה + הערות מנהל/סטודנט */
        #order_purpose {
            width: 100%;
            max-width: 100%;
            padding: 0.35rem 0.55rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.9rem;
        }
        .notes-combined-box {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.65rem 0.75rem;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .notes-block { margin: 0; }
        .notes-badge {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }
        .notes-badge-icon { width: 14px; height: 14px; flex-shrink: 0; }
        .notes-badge-admin { color: #1e3a8a; }
        .notes-badge-student { color: #14532d; }
        .textarea-admin-notes {
            width: 100%;
            min-height: 4rem;
            border-radius: 8px;
            border: 1px solid #93c5fd;
            background: #eff6ff;
            color: #1e3a8a;
            font-size: 0.88rem;
            padding: 0.45rem 0.55rem;
            font-family: inherit;
        }
        .textarea-admin-notes::placeholder { color: #3b82f6; opacity: 0.7; }
        .textarea-admin-notes[readonly] {
            opacity: 0.92;
            cursor: default;
            background: #f0f9ff;
        }
        .textarea-student-notes {
            width: 100%;
            min-height: 4rem;
            border-radius: 8px;
            border: 1px solid #86efac;
            background: #f0fdf4;
            color: #14532d;
            font-size: 0.88rem;
            padding: 0.45rem 0.55rem;
            font-family: inherit;
        }
        .textarea-student-notes::placeholder { color: #15803d; opacity: 0.65; }
        .textarea-student-notes[readonly] {
            opacity: 0.92;
            cursor: default;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <h2 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem;">ניהול הזמנות</h2>
    <div class="toolbar">
        <div></div>
        <button type="button" class="btn" id="open_order_modal_btn">הזמנה חדשה</button>
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
    // במצב צפייה (view_id) גם נפתח את הטופס, אך נגדיר את השדות כקריאה בלבד.
    // טופס ההזמנה נפתח רק אם ביקשו במפורש (mode=new), יש שגיאה, או עורכים / משכפלים / צופים בהזמנה.
    $showForm = $mode === 'new' || $editingOrder !== null || $error !== '';
    $isViewModeOrder = ($viewId > 0 && $editingOrder !== null && !$isDuplicateMode);
    ?>

    <div class="modal-backdrop" id="order_modal" style="display: <?= $showForm ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2>
                    <?php if ($editingOrder && $isDuplicateMode): ?>
                        שכפול הזמנה
                    <?php elseif ($isViewModeOrder): ?>
                        צפייה בהזמנה
                    <?php elseif ($editingOrder): ?>
                        עריכת הזמנה
                    <?php else: ?>
                        הזמנה חדשה
                    <?php endif; ?>
                </h2>
                <button type="button" class="modal-close" id="order_modal_close" aria-label="סגירת חלון"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>

            <form method="post" action="admin_orders.php<?= $editingOrder && !$isViewModeOrder ? '?edit_id=' . (int)$editingOrder['id'] : '' ?>">
                <input type="hidden" name="action" value="<?= $editingOrder && !$isViewModeOrder ? 'update' : 'create' ?>">
                <input type="hidden" name="id" value="<?= $editingOrder ? (int)$editingOrder['id'] : 0 ?>">
                <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($tab === 'today'): ?>
                    <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayMode, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <?php
                // האם לפריט ההזמנה הנוכחי יש רכיבי ציוד נלווים?
                $editingHasComponents = false;
                if ($editingOrder) {
                    $editCode = (string)($editingOrder['equipment_code'] ?? '');
                    if ($editCode !== '' && !empty($equipmentComponentsByCode[$editCode] ?? [])) {
                        $editingHasComponents = true;
                    }
                }
                ?>
                <?php if ($editingHasComponents): ?>
                    <input type="hidden" id="has_equipment_components" value="1">
                <?php endif; ?>

                <div class="grid">
                    <!-- עמודת תאריכים + רשימת ציוד שנבחר + שם שואל + הערות (בצד ימין ב-RTL) -->
                    <div>
                        <!-- שדות נסתרים לתאריכים בפורמט YYYY-MM-DD לצורך שליחה לשרת -->
                        <input type="hidden" id="start_date" name="start_date"
                               value="<?= $editingOrder
                                   ? htmlspecialchars($editingOrder['start_date'], ENT_QUOTES, 'UTF-8')
                                   : (($mode === 'new' && $prefillDay !== '') ? htmlspecialchars($prefillDay, ENT_QUOTES, 'UTF-8') : '') ?>">
                        <input type="hidden" id="end_date" name="end_date"
                               value="<?= $editingOrder
                                   ? htmlspecialchars($editingOrder['end_date'], ENT_QUOTES, 'UTF-8')
                                   : (($mode === 'new' && $prefillDay !== '') ? htmlspecialchars($prefillDay, ENT_QUOTES, 'UTF-8') : '') ?>">
                        <!-- שדות חבויים לשעת התחלה/סיום (לבחירה בחלון שעות) -->
                        <input type="hidden" id="start_time" name="start_time"
                               value="<?= ($mode === 'new' && $prefillStartTime !== '') ? htmlspecialchars($prefillStartTime, ENT_QUOTES, 'UTF-8') : '' ?>">
                        <input type="hidden" id="end_time" name="end_time"
                               value="<?= ($mode === 'new' && $prefillEndTime !== '') ? htmlspecialchars($prefillEndTime, ENT_QUOTES, 'UTF-8') : '' ?>">
                        <?php if ($editingOrder && $isDuplicateMode): ?>
                            <input type="hidden" name="original_start_date"
                                   value="<?= htmlspecialchars((string)($editingOrder['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="original_end_date"
                                   value="<?= htmlspecialchars((string)($editingOrder['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>

                        <?php
                        $showRecurringBlock = !$editingOrder && ($role === 'admin' || $role === 'warehouse_manager');
                        if ($showRecurringBlock): ?>
                        <div class="recurring-toggle-row" id="regular_toggle_row" style="margin-bottom: 0.35rem;">
                            <label class="toggle-label">
                                <input type="checkbox" id="regular_toggle" autocomplete="off">
                                <span>הזמנה רגילה</span>
                            </label>
                        </div>
                        <div class="recurring-toggle-row" id="recurring_toggle_row" style="margin-bottom: 0.75rem;">
                            <label class="toggle-label">
                                <input type="checkbox" id="recurring_toggle" name="recurring_enabled" value="1" autocomplete="off">
                                <span>הזמנה מחזורית</span>
                            </label>
                        </div>
                        <div id="recurring_section" class="recurring-section" style="display: none;">
                            <div class="recurring-row">
                                <label>תאריך התחלה ושעת התחלה</label>
                                <div class="recurring-date-row">
                                    <input type="hidden" name="recurring_start_date" id="recurring_start_date_h" value="">
                        <input type="text" id="recurring_start_date" readonly placeholder="תאריך" style="max-width: 7rem;" title="לחץ לבחירת תאריך">
                                    <select id="recurring_start_time" name="recurring_start_time">
                                        <option value="">שעה</option>
                                        <?php for ($h = 9; $h <= 15; $h++): ?>
                                            <option value="<?= sprintf('%02d:00', $h) ?>"><?= sprintf('%02d:00', $h) ?></option>
                                            <?php if ($h < 15): ?>
                                            <option value="<?= sprintf('%02d:30', $h) ?>"><?= sprintf('%02d:30', $h) ?></option>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div id="recurring_calendar_wrapper" class="recurring-mini-cal" style="display: none; margin-top: 0.5rem;"></div>
                            </div>
                            <div class="recurring-row">
                                <label for="recurring_freq">מחזוריות</label>
                                <select id="recurring_freq" name="recurring_freq">
                                    <option value="day">יום</option>
                                    <option value="week">שבוע</option>
                                </select>
                            </div>
                            <div class="recurring-row">
                                <label for="recurring_duration">משך הזמן</label>
                                <select id="recurring_duration" name="recurring_duration">
                                    <option value="1">שעה</option>
                                    <option value="1.5">שעה וחצי</option>
                                    <option value="2">שעתיים</option>
                                    <option value="2.5">שעתיים וחצי</option>
                                    <option value="3">שלוש שעות</option>
                                </select>
                            </div>
                            <div class="recurring-row">
                                <label>סיום</label>
                                <div class="recurring-end-radio">
                                    <label><input type="radio" name="recurring_end_type" value="count" checked> מספר פעמים</label>
                                    <label><input type="radio" name="recurring_end_type" value="end_date"> תאריך סיום</label>
                                </div>
                            </div>
                            <div id="recurring_count_wrapper" class="recurring-row">
                                <label for="recurring_count">כמות פעמים</label>
                                <input type="number" id="recurring_count" name="recurring_count" min="1" max="999" value="1" style="width: 5rem;">
                            </div>
                            <div id="recurring_end_date_wrapper" class="recurring-row" style="display: none;">
                                <label>תאריך סיום</label>
                                <input type="hidden" name="recurring_end_date" id="recurring_end_date_h" value="">
                                <input type="text" id="recurring_end_date" readonly placeholder="תאריך סיום" style="max-width: 7rem;" title="לחץ לבחירת תאריך">
                                <div id="recurring_end_calendar_wrapper" class="recurring-mini-cal" style="display: none; margin-top: 0.5rem;"></div>
                            </div>
                        </div>
                        <div id="normal_date_section">
                        <?php endif; ?>

                        <label>
                            בחירת תאריכים
                            <span id="date_range_label" class="muted-small">-</span>
                        </label>
                        <div class="date-picker">
                            <div class="date-picker-toggle" id="date_picker_toggle">
                                <i data-lucide="calendar" class="date-picker-toggle-icon" aria-hidden="true"></i>
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
                                        <div style="display:flex;align-items:center;gap:4px;">
                                            <button type="button" id="cal_close" class="icon-btn" title="סגירת לוח שנה" aria-label="סגירת לוח שנה"><i data-lucide="x" aria-hidden="true"></i></button>
                                            <button type="button" id="cal_next" aria-label="חודש הבא"><i data-lucide="chevron-left" aria-hidden="true"></i></button>
                                        </div>
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
                        <?php if ($showRecurringBlock): ?>
                        </div>
                        <?php endif; ?>

                        <!-- רשימת פריטי הציוד שנבחרו (מתעדכנת אחרי לחיצה על "הוסף") -->
                        <div id="selected_equipment_list" style="margin: 0.5rem 0;">
                            <?php if ($editingOrder): ?>
                            <div class="selected-equipment-row" data-equipment-id="<?= (int)$editingOrder['equipment_id'] ?>" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span><?= htmlspecialchars($editingOrder['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?><?= ($editingOrder['equipment_code'] ?? '') ? ' (' . htmlspecialchars($editingOrder['equipment_code'], ENT_QUOTES, 'UTF-8') . ')' : '' ?></span>
                                <button type="button" class="equipment-list-trash" style="border: none; background: transparent; cursor: pointer; font-size: 0.85rem;" title="הסר ציוד" aria-label="הסר ציוד"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($editingOrder): ?>
                        <input type="hidden" name="equipment_id" id="equipment_id_hidden" value="<?= (int)$editingOrder['equipment_id'] ?>">
                        <?php endif; ?>

                        <label for="borrower_search">שם שואל</label>
                        <?php
                        $defaultBorrower = '';
                        // פתיחה דרך "ניהול יומי" – לא ממלאים שם שואל אוטומטית
                        $isDailyPrefillNew = (!$editingOrder && $mode === 'new' && $prefillDay !== '' && $prefillStartTime !== '');
                        if (!$isDailyPrefillNew && !$editingOrder && $me) {
                            $fn = trim((string)($me['first_name'] ?? ''));
                            $ln = trim((string)($me['last_name'] ?? ''));
                            if ($fn !== '' || $ln !== '') {
                                $defaultBorrower = trim($fn . ' ' . $ln);
                            } else {
                                $defaultBorrower = (string)($me['username'] ?? '');
                            }
                        }
                        $isStudent = ($role === 'student');
                        $isNotPickedContext = ($editingOrder && ($tab === 'not_picked' || $tab === 'not_returned') && !$isStudent);
                        $canEditAdminNotes = ($role === 'admin' || $role === 'warehouse_manager');
                        $purposeVal = $editingOrder ? (string)($editingOrder['purpose'] ?? '') : '';
                        $adminNotesVal = $editingOrder ? (string)($editingOrder['admin_notes'] ?? '') : '';
                        $purposeReadonly = $isViewModeOrder || $isNotPickedContext;
                        ?>
                        <input
                            type="text"
                            id="borrower_search"
                            autocomplete="off"
                            value="<?= $editingOrder ? htmlspecialchars($editingOrder['borrower_name'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($defaultBorrower, ENT_QUOTES, 'UTF-8') ?>"
                            <?= ($isStudent || $isNotPickedContext) ? 'readonly' : '' ?>
                        >
                        <input
                            type="hidden"
                            id="borrower_name"
                            name="borrower_name"
                            value="<?= $editingOrder ? htmlspecialchars($editingOrder['borrower_name'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($defaultBorrower, ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <?php if (!$isStudent): ?>
                            <div id="borrower_suggestions" class="suggestions"></div>
                        <?php endif; ?>

                        <label for="borrower_email">מייל</label>
                        <?php
                        $initialEmail = '';
                        $initialPhone = '';
                        if ($editingOrder) {
                            $initialEmail = '';
                            $initialPhone = '';
                        } else {
                            $initialEmail = (string)($me['email'] ?? '');
                            $initialPhone = (string)($me['phone'] ?? '');
                        }
                        ?>
                        <?php if ($isViewModeOrder): ?>
                            <a
                                href="mailto:<?= htmlspecialchars($initialEmail, ENT_QUOTES, 'UTF-8') ?>"
                                id="borrower_email"
                                style="display:inline-block;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;background:#f9fafb;text-decoration:none;color:#111827;min-width:0;">
                                <?= htmlspecialchars($initialEmail, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php else: ?>
                            <input
                                type="text"
                                id="borrower_email"
                                autocomplete="off"
                                value="<?= htmlspecialchars($initialEmail, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($isStudent || $isNotPickedContext) ? 'readonly' : '' ?>
                            >
                        <?php endif; ?>

                        <label for="borrower_phone">טלפון</label>
                        <?php if ($isViewModeOrder): ?>
                            <a
                                href="tel:<?= htmlspecialchars($initialPhone, ENT_QUOTES, 'UTF-8') ?>"
                                id="borrower_phone"
                                style="display:inline-block;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;background:#f9fafb;text-decoration:none;color:#111827;min-width:0;">
                                <?= htmlspecialchars($initialPhone, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php else: ?>
                            <input
                                type="text"
                                id="borrower_phone"
                                autocomplete="off"
                                value="<?= htmlspecialchars($initialPhone, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($isStudent || $isNotPickedContext) ? 'readonly' : '' ?>
                            >
                        <?php endif; ?>

                        <input type="hidden" id="borrower_contact" name="borrower_contact"
                               value="<?= htmlspecialchars($editingOrder ? (string)($editingOrder['borrower_contact'] ?? '') : '', ENT_QUOTES, 'UTF-8') ?>">

                        <label for="order_purpose">מטרה</label>
                        <input
                            type="text"
                            id="order_purpose"
                            name="purpose"
                            maxlength="500"
                            placeholder="למשל: צילומי סרט גמר, הרצאה..."
                            value="<?= htmlspecialchars($purposeVal, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $purposeReadonly ? 'readonly' : '' ?>
                        >

                        <?php
                        if ($editingOrder && $role !== 'student') {
                            $currentStatus = (string)($editingOrder['status'] ?? '');
                            $orderStatusOptions = [];
                            if ($currentStatus === 'pending') {
                                $orderStatusOptions = [
                                    'pending'  => 'ממתין (נוכחי)',
                                    'approved' => 'מאושר',
                                    'rejected' => 'נדחה',
                                ];
                            } elseif ($currentStatus === 'approved') {
                                if ($tab === 'not_picked') {
                                    // בטאב "לא נלקח" מאפשרים רק סגירה ל"עבר"
                                    $orderStatusOptions = [
                                        'returned' => 'עבר',
                                    ];
                                } else {
                                    $orderStatusOptions = [
                                        'approved' => 'מאושר (נוכחי)',
                                        'on_loan'  => 'בהשאלה',
                                    ];
                                }
                            } elseif ($currentStatus === 'on_loan') {
                                if ($tab === 'not_returned') {
                                    // בטאב "לא הוחזר" מאפשרים רק סגירה ל"עבר"
                                    $orderStatusOptions = [
                                        'returned' => 'עבר',
                                    ];
                                } else {
                                    $orderStatusOptions = [
                                        'on_loan'  => 'בהשאלה (נוכחי)',
                                        'returned' => 'עבר',
                                    ];
                                }
                            } elseif ($currentStatus === 'rejected') {
                                $orderStatusOptions = ['rejected' => 'נדחה (נוכחי)'];
                            } elseif ($currentStatus === 'returned') {
                                $orderStatusOptions = ['returned' => 'עבר (נוכחי)'];
                            }

                            $returnStatusValue = (string)($editingOrder['return_equipment_status'] ?? '');
                            if ($returnStatusValue === '') {
                                // ברירת מחדל – בטאב "לא נלקח" נשתמש ב"לא נאסף", בטאב "לא הוחזר" נשתמש ב"לא הוחזר בזמן", אחרת ללא סטטוס
                                if ($tab === 'not_picked') {
                                    $returnStatusValue = 'לא נאסף';
                                } elseif ($tab === 'not_returned') {
                                    $returnStatusValue = 'לא הוחזר בזמן';
                                } else {
                                    $returnStatusValue = '';
                                }
                            } else {
                                // בטאב "לא נלקח" מנרמלים כל ערך שאינו "לא הוחזר בזמן" ל"לא נאסף"
                                if ($tab === 'not_picked' && $returnStatusValue !== 'לא הוחזר בזמן') {
                                    $returnStatusValue = 'לא נאסף';
                                }
                            }
                            $todayYmdForReturn = date('Y-m-d');
                            $isLateNotReturned = (
                                $currentStatus === 'on_loan'
                                && !empty($editingOrder['end_date'])
                                && $editingOrder['end_date'] < $todayYmdForReturn
                            );
                            // קומבו "סטטוס החזרה" מוצג בלוגיקות "לא הוחזר" / "לא נלקח"
                            $showReturnStatusField = (
                                $tab === 'not_picked'
                                || $tab === 'not_returned'
                                || $currentStatus === 'returned'
                                || $isLateNotReturned
                                || ($tab === 'today' && $todayMode === 'return')
                            );
                            ?>
                            <label for="order_status">מצב הזמנה</label>
                            <select id="order_status" name="order_status">
                                <?php foreach ($orderStatusOptions as $val => $label): ?>
                                    <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $val === $currentStatus || ($tab === 'not_picked' && $val === 'returned') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if ($showReturnStatusField): ?>
                                <label for="return_equipment_status">סטטוס החזרה</label>
                                <select id="return_equipment_status" name="return_equipment_status">
                                    <option value="" <?= $returnStatusValue === '' ? 'selected' : '' ?>>ללא</option>
                                    <option value="לא נאסף" <?= $returnStatusValue === 'לא נאסף' ? 'selected' : '' ?>>לא נאסף</option>
                                    <option value="לא הוחזר בזמן" <?= $returnStatusValue === 'לא הוחזר בזמן' ? 'selected' : '' ?>>לא הוחזר בזמן</option>
                                </select>

                                <?php
                                // סטטוס ציוד מוחזר – מופיע כאשר ההזמנה בהשאלה והיום הוא יום ההחזרה,
                                // או כאשר נמצאים בטאב "לא הוחזר" (on_loan ותאריך ההחזרה עבר),
                                // וגם במסך "היום / החזרה" לצורך עדכון מהיר.
                                $equipmentReturnCondition = (string)($editingOrder['equipment_return_condition'] ?? '');
                                if ($equipmentReturnCondition === '') {
                                    $equipmentReturnCondition = 'תקין';
                                }
                                $todayYmdEquip = date('Y-m-d');
                                $isReturnToday = (
                                    $currentStatus === 'on_loan'
                                    && (string)($editingOrder['end_date'] ?? '') === $todayYmdEquip
                                );
                                $isInNotReturnedTab = ($tab === 'not_returned' && $currentStatus === 'on_loan');
                                // שדה "סטטוס ציוד מוחזר" יוצג בכל ההקשרים שבהם מוצג שדה "סטטוס החזרה"
                                $showEquipReturnCombo = (
                                    $showReturnStatusField
                                    || ($tab === 'today' && $todayMode === 'return')
                                );
                                ?>
                                <?php if ($showEquipReturnCombo): ?>
                                    <label for="equipment_return_condition">סטטוס ציוד מוחזר</label>
                                    <select id="equipment_return_condition" name="equipment_return_condition">
                                        <option value="תקין" <?= $equipmentReturnCondition === 'תקין' ? 'selected' : '' ?>>תקין</option>
                                        <option value="תקול" <?= $equipmentReturnCondition === 'תקול' ? 'selected' : '' ?>>לא תקין</option>
                                        <option value="חסר" <?= $equipmentReturnCondition === 'חסר' ? 'selected' : '' ?>>חסר</option>
                                    </select>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div id="rejection_reason_wrapper" style="margin-top: 0.5rem; display: <?= $currentStatus === 'rejected' ? 'block' : 'none' ?>;">
                                <label for="rejection_reason">סיבה לדחיית הבקשה</label>
                                <textarea id="rejection_reason" name="rejection_reason"
                                          placeholder="פרט את הסיבה לדחיית הבקשה"></textarea>
                            </div>
                        <?php } ?>

                        <label for="notes">הערות</label>
                        <div class="notes-combined-box">
                            <div class="notes-block notes-block-admin">
                                <div class="notes-badge notes-badge-admin">
                                    <i data-lucide="shield" class="notes-badge-icon" aria-hidden="true"></i>
                                    <span>הערות מנהל</span>
                                </div>
                                <textarea
                                    id="admin_notes"
                                    name="admin_notes"
                                    class="textarea-admin-notes"
                                    rows="3"
                                    placeholder="הערות פנימיות למנהלים..."
                                    <?= ($isViewModeOrder || !$canEditAdminNotes) ? 'readonly' : '' ?>
                                ><?= htmlspecialchars($adminNotesVal, ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                            <div class="notes-block notes-block-student">
                                <div class="notes-badge notes-badge-student">הערות סטודנט</div>
                                <textarea
                                    id="notes"
                                    name="notes"
                                    class="textarea-student-notes"
                                    rows="3"
                                    placeholder="שעות איסוף / החזרה, שימוש מיוחד וכו׳"
                                    <?= $isViewModeOrder ? 'readonly' : '' ?>
                                ><?= $editingOrder ? htmlspecialchars($editingOrder['notes'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></textarea>
                            </div>
                        </div>

                        <?php
                        $todayYmd = date('Y-m-d');
                                $canShowAgreementButton = false;
                        $agreementSigned = false;
                        if ($editingOrder) {
                            $orderStatus = (string)($editingOrder['status'] ?? '');
                            $orderStart  = (string)($editingOrder['start_date'] ?? '');
                            $signatureFileEdit = __DIR__ . '/signatures/order_' . (int)$editingOrder['id'] . '.png';
                            $agreementSigned = is_file($signatureFileEdit);
                            // מציגים את "הסכם השאלה" לכל הזמנה שבה כבר יש חתימה,
                            // או בהקשרים רלוונטיים (בהשאלה / לא הוחזר / עבר), וגם ביום ההשאלה עבור "מאושר".
                            if (
                                $agreementSigned
                                || in_array($orderStatus, ['on_loan', 'returned'], true)
                                || (in_array($orderStatus, ['approved', 'on_loan'], true) && $orderStart === $todayYmd)
                            ) {
                                $canShowAgreementButton = true;
                            }
                        }
                        ?>
                        <?php if ($canShowAgreementButton): ?>
                            <button type="button" class="btn secondary"
                                    onclick="window.open('agreement.php?order_id=<?= (int)$editingOrder['id'] ?>', 'agreement', 'width=900,height=700')">
                                הסכם השאלה<?= $agreementSigned ? ' V' : '' ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- עמודת ציוד (זהה בהזמנה חדשה ובעריכה) -->
                    <?php if (!($editingOrder && ($tab === 'not_picked' || $tab === 'not_returned'))): ?>
                    <div id="equipment_column">
                        <label for="equipment_category_filter">קטגוריית ציוד</label>
                        <select id="equipment_category_filter">
                            <option value="all">כל הקטגוריות</option>
                            <?php foreach ($equipmentCategories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <table>
                            <thead>
                            <tr>
                                <th style="width:40px;">בחר</th>
                                <th>שם הציוד</th>
                                <th>קוד</th>
                                <th>קטגוריה</th>
                            </tr>
                            </thead>
                            <tbody id="equipment_table_body">
                            <?php foreach ($equipmentOptions as $item): ?>
                                <?php
                                $cat = trim((string)($item['category'] ?? ''));
                                if ($cat === '') {
                                    $cat = 'ללא קטגוריה';
                                }
                                $isChecked = $editingOrder && (int)$editingOrder['equipment_id'] === (int)$item['id'];
                                ?>
                                <tr data-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" data-equipment-id="<?= (int)$item['id'] ?>">
                                    <td>
                                        <input type="checkbox"
                                               name="equipment_ids[]"
                                               value="<?= (int)$item['id'] ?>"
                                               <?= $isChecked ? 'checked' : '' ?>
                                               data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>"
                                               data-code="<?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?>">
                                    </td>
                                    <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="muted-small"><?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="muted-small"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="btn secondary" id="add_equipment_btn" style="margin-top:0.5rem;">
                            הוסף
                        </button>
                        <div class="muted-small" id="selected_equipment_summary" style="margin-top:0.3rem;"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                $submitLabel = 'הזמנה';
                if ($editingOrder) {
                    if ($role !== 'student' && $editingOrder['status'] === 'pending') {
                        $submitLabel = 'שמירה';
                    } else {
                        $submitLabel = 'שמירת שינויים';
                    }
                }
                ?>
                <button type="submit" class="btn" id="submit_order_btn" disabled>
                    <?= $submitLabel ?>
                </button>
                <?php if ($editingOrder): ?>
                    <?php
                    $cancelUrl = 'admin_orders.php?tab=' . urlencode($tab);
                    if ($tab === 'today') {
                        $cancelUrl .= '&today_mode=' . urlencode($todayMode);
                    }
                    ?>
                    <a href="<?= $cancelUrl ?>" class="btn secondary">ביטול</a>
                <?php else: ?>
                    <button type="button" class="btn secondary" id="order_modal_cancel">ביטול</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- חלון בחירת שעות (השאלה / החזרה) -->
    <div class="time-modal-backdrop" id="time_modal_backdrop">
        <div class="time-modal">
            <h3 id="time_modal_title">בחירת שעה</h3>
            <div class="time-grid" id="time_options_container">
                <!-- ימולא דינמית בשעות מ-09:00 עד 15:00 -->
            </div>
            <div class="time-modal-footer">
                <button type="button" class="btn secondary" id="time_modal_cancel">ביטול</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="toolbar" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">
            <h2>רשימת הזמנות</h2>
            <?php if ($role === 'admin' || $role === 'warehouse_manager'): ?>
                <form method="post" action="admin_orders.php" id="bulk_status_form">
                    <input type="hidden" name="action" value="update_status_bulk">
                    <input type="hidden" name="changes_json" id="changes_json" value="">
                    <button type="submit" class="btn small neutral" id="bulk_update_btn" disabled>
                        עדכון שינויים
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="tabs">
            <a href="admin_orders.php?tab=today"   class="<?= $tab === 'today'   ? 'active' : '' ?>">היום</a>
            <a href="admin_orders.php?tab=pending" class="<?= $tab === 'pending' ? 'active' : '' ?>">ממתין</a>
            <a href="admin_orders.php?tab=future"       class="<?= $tab === 'future'       ? 'active' : '' ?>">עתידי</a>
            <a href="admin_orders.php?tab=not_picked"   class="<?= $tab === 'not_picked'   ? 'active' : '' ?>">לא נלקח</a>
            <a href="admin_orders.php?tab=active"       class="<?= $tab === 'active'       ? 'active' : '' ?>">בהשאלה</a>
            <a href="admin_orders.php?tab=not_returned" class="<?= $tab === 'not_returned' ? 'active' : '' ?>">לא הוחזר</a>
            <a href="admin_orders.php?tab=history"      class="<?= $tab === 'history'      ? 'active' : '' ?>">היסטוריה</a>
        </div>
        <?php if ($tab === 'today'): ?>
            <div style="margin-top: 0.5rem;">
                <div class="tabs" style="display: flex; width: 100%; justify-content: flex-start;">
                    <a href="admin_orders.php?tab=today&today_mode=borrow"
                       class="<?= $todayMode === 'borrow' ? 'active' : '' ?>">קבלת ציוד</a>
                    <a href="admin_orders.php?tab=today&today_mode=return"
                       class="<?= $todayMode === 'return' ? 'active' : '' ?>">החזרה</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if (count($orders) === 0): ?>
            <p class="muted-small">עדיין לא נוצרו הזמנות במערכת לטאב זה.</p>
        <?php else: ?>
            <div class="orders-table-wrapper" style="max-height:60vh;overflow-y:auto;border-radius:12px;">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>שם המזמין</th>
                        <th>שם הפריט</th>
                        <th>סטטוס</th>
                        <th>תאריך השאלה</th>
                        <th>תאריך החזרה</th>
                        <th>החברה</th>
                        <th>טופס השאלה</th>
                        <th>הערות</th>
                        <th>פעולות</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        // צביעת שורות להזמנות במצב "בקשה" (pending) לפי קרבת מועד ההשאלה
                        $rowHighlightClass = '';
                        $orderStatusCode = (string)($order['status'] ?? '');
                        if ($orderStatusCode === 'pending') {
                            $orderStartDate = (string)($order['start_date'] ?? '');
                            if ($orderStartDate !== '') {
                                $todayYmd = date('Y-m-d');
                                $startDt = DateTime::createFromFormat('Y-m-d', $orderStartDate);
                                if ($startDt instanceof DateTime) {
                                    // חישוב "יום עבודה קודם" – ראשון עד חמישי בלבד (0–4)
                                    $prevBusiness = clone $startDt;
                                    while (true) {
                                        $prevBusiness->modify('-1 day');
                                        $dayOfWeek = (int)$prevBusiness->format('w'); // 0=א, 5=ו, 6=ש
                                        if ($dayOfWeek >= 0 && $dayOfWeek <= 4) {
                                            break;
                                        }
                                    }
                                    $prevBusinessYmd = $prevBusiness->format('Y-m-d');

                                    if ($todayYmd === $prevBusinessYmd) {
                                        $rowHighlightClass = 'row-pending-soon';
                                    } elseif ($todayYmd === $orderStartDate) {
                                        $rowHighlightClass = 'row-pending-today';
                                    } elseif ($todayYmd > $orderStartDate) {
                                        $rowHighlightClass = 'row-pending-late';
                                    }
                                }
                            }
                        }
                        ?>
                        <tr data-order-id="<?= (int)$order['id'] ?>" class="<?= htmlspecialchars($rowHighlightClass, ENT_QUOTES, 'UTF-8') ?>">
                            <td><?= (int)$order['id'] ?></td>
                        <td>
                            <?php
                            $borrowerUserId = (int)($order['borrower_user_id'] ?? 0);
                            $canSeeUserLink = in_array($role, ['admin', 'warehouse_manager'], true);
                            ?>
                            <?php if ($borrowerUserId > 0 && $canSeeUserLink): ?>
                                <a href="admin_users.php?view_id=<?= $borrowerUserId ?>"
                                   style="text-decoration:none;color:#2563eb!important;font-weight:600;"
                                   target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars($order['borrower_name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($order['borrower_name'], ENT_QUOTES, 'UTF-8') ?><br>
                                <?php if ($order['borrower_contact'] !== null && $order['borrower_contact'] !== ''): ?>
                                    <span class="muted-small">
                                        <?= htmlspecialchars($order['borrower_contact'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $code = (string)($order['equipment_code'] ?? '');
                            $components = $equipmentComponentsByCode[$code] ?? [];
                            $hasComponents = !empty($components);
                            $compChecks = [];
                            if (!empty($componentChecksByOrderAndCode[(int)$order['id']] ?? [])) {
                                $compChecks = $componentChecksByOrderAndCode[(int)$order['id']][$code] ?? [];
                            }
                            $componentsContext = ($tab === 'today' && $todayMode === 'return') || $tab === 'not_returned' ? 'return' : 'loan';
                            ?>
                            <?php if ($hasComponents): ?>
                                <a href="#"
                                   class="equipment-components-link"
                                   data-equipment-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                   data-order-id="<?= (int)$order['id'] ?>"
                                   data-components-context="<?= htmlspecialchars($componentsContext, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($order['equipment_name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($order['equipment_name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                            <br>
                            <span class="muted-small">
                                <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if ($hasComponents): ?>
                                <script type="application/json" data-components-for="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= json_encode($components, JSON_UNESCAPED_UNICODE) ?>
                                </script>
                                <?php if (!empty($compChecks)): ?>
                                    <script type="application/json"
                                            data-component-checks-for-order="<?= (int)$order['id'] ?>"
                                            data-component-checks-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= json_encode($compChecks, JSON_UNESCAPED_UNICODE) ?>
                                    </script>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusClass = 'status-pending';
                            $statusCode  = (string)($order['status'] ?? 'pending');
                            $statusLabel = $statusCode;

                            // קריאת התווית מטבלת order_status_labels אם קיימת
                            static $statusMap = null;
                            if ($statusMap === null) {
                                try {
                                    $stmtSL = $pdo->query('SELECT status, label_he FROM order_status_labels');
                                    $statusMap = [];
                                    foreach ($stmtSL->fetchAll(PDO::FETCH_ASSOC) as $rowSL) {
                                        $statusMap[(string)($rowSL['status'] ?? '')] = (string)($rowSL['label_he'] ?? '');
                                    }
                                } catch (Throwable $e) {
                                    $statusMap = [];
                                }
                            }
                            if (isset($statusMap[$statusCode]) && $statusMap[$statusCode] !== '') {
                                $statusLabel = $statusMap[$statusCode];
                            }

                            // בטאב "היום" – כל עוד ההזמנה מאושרת אך הציוד טרם נלקח, מציגים סטטוס "קבלת ציוד"
                            if ($tab === 'today' && $statusCode === 'approved') {
                                $statusLabel = 'קבלת ציוד';
                            }

                            if ($statusCode === 'approved') {
                                $statusClass = 'status-approved';
                            } elseif ($statusCode === 'on_loan') {
                                $statusClass = 'status-approved';
                            } elseif ($statusCode === 'returned') {
                                $statusClass = 'status-returned';
                            } elseif ($statusCode === 'rejected') {
                                $statusClass = 'status-rejected';
                            }
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td class="muted-small">
                            <?php
                            $startDateDisplay = (string)($order['start_date'] ?? '');
                            $startTimeDisplay = (string)($order['start_time'] ?? '');
                            $startCombined = $startDateDisplay;
                            if ($startTimeDisplay !== '') {
                                $startCombined .= ' ' . $startTimeDisplay;
                            }
                            echo htmlspecialchars($startCombined, ENT_QUOTES, 'UTF-8');
                            ?>
                        </td>
                        <td class="muted-small">
                            <?php
                            $endDateDisplay = (string)($order['end_date'] ?? '');
                            $endTimeDisplay = (string)($order['end_time'] ?? '');
                            $endCombined = $endDateDisplay;
                            if ($endTimeDisplay !== '') {
                                $endCombined .= ' ' . $endTimeDisplay;
                            }
                            echo htmlspecialchars($endCombined, ENT_QUOTES, 'UTF-8');
                            ?>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['borrower_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="muted-small">
                            <?php
                            $todayYmd = date('Y-m-d');
                            $signatureFile = __DIR__ . '/signatures/order_' . (int)$order['id'] . '.png';
                            $hasSignatureRow = is_file($signatureFile);
                            $orderStatusRow = (string)($order['status'] ?? '');
                            $showAgreementLink = (
                                $hasSignatureRow
                                || in_array($orderStatusRow, ['on_loan', 'returned'], true)
                                || (in_array($orderStatusRow, ['approved', 'on_loan'], true) && (string)($order['start_date'] ?? '') === $todayYmd)
                            );
                            if ($showAgreementLink): ?>
                                <a href="agreement.php?order_id=<?= (int)$order['id'] ?>" target="_blank">
                                    הסכם השאלה<?= $hasSignatureRow ? ' V' : '' ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td class="muted-small">
                            <?= htmlspecialchars($order['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?php
                            // סטודנט: יכול לערוך/למחוק רק הזמנות שלו בטאב "ממתין" ועד שעה מזמן יצירת ההזמנה.
                            if ($role === 'student') {
                                $canEditOrDelete = false;
                                if ($tab === 'pending') {
                                    $createdAtTs = strtotime((string)($order['created_at'] ?? ''));
                                    if ($createdAtTs !== false && $createdAtTs >= time() - 3600) {
                                        $canEditOrDelete = true;
                                    }
                                }
                                if ($canEditOrDelete): ?>
                                    <div class="row-actions">
                                        <a href="admin_orders.php?view_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?><?= $tab === 'today' ? '&today_mode=' . urlencode($todayMode) : '' ?>" class="icon-btn" title="צפייה בהזמנה" aria-label="צפייה בהזמנה">
                                            <i data-lucide="eye" aria-hidden="true"></i>
                                        </a>
                                        <a href="admin_orders.php?edit_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?><?= $tab === 'today' ? '&today_mode=' . urlencode($todayMode) : '' ?>" class="icon-btn" title="עריכה" aria-label="עריכה"><i data-lucide="pencil" aria-hidden="true"></i></a>
                                        <form method="post" action="admin_orders.php"
                                              onsubmit="return confirm('למחוק את ההזמנה הזו?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                            <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="icon-btn" title="מחיקה" aria-label="מחיקה"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                        </form>
                                    </div>
                                <?php endif;
                            } else {
                                // למנהל/מנהל מחסן: פעולות בטאבים today, pending, future, active, not_picked, not_returned
                                // בטאב "לא נלקח" ו"לא הוחזר" אין צורך בשכפול.
                                $adminTabsAllowed = in_array($tab, ['today', 'pending', 'future', 'active', 'not_picked', 'not_returned'], true);
                                if ($adminTabsAllowed): ?>
                                    <div class="row-actions">
                                        <?php if ($tab !== 'not_picked' && $tab !== 'not_returned'): ?>
                                            <form method="post" action="admin_orders.php">
                                                <input type="hidden" name="action" value="duplicate">
                                                <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="icon-btn" title="שכפול" aria-label="שכפול"><i data-lucide="copy" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>

                                        <a href="admin_orders.php?view_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?><?= $tab === 'today' ? '&today_mode=' . urlencode($todayMode) : '' ?>" class="icon-btn" title="צפייה בהזמנה" aria-label="צפייה בהזמנה">
                                            <i data-lucide="eye" aria-hidden="true"></i>
                                        </a>

                                        <a href="admin_orders.php?edit_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?><?= $tab === 'today' ? '&today_mode=' . urlencode($todayMode) : '' ?>" class="icon-btn" title="עריכה" aria-label="עריכה"><i data-lucide="pencil" aria-hidden="true"></i></a>

                                        <?php
                                        // מנהל יכול למחוק רק הזמנות שהוא יצר בעצמו (creator_username)
                                        $canDelete = isset($order['creator_username'], $me['username'])
                                            && $order['creator_username'] === $me['username'];
                                        if ($canDelete): ?>
                                            <form method="post" action="admin_orders.php"
                                                  onsubmit="return confirm('למחוק את ההזמנה הזו?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="icon-btn" title="מחיקה" aria-label="מחיקה"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>

                                        <?php
                                        // קומבו בוקס שינוי סטטוס – בסוף טור הפעולות
                                        // בטאבים "לא נלקח" ו"לא הוחזר" לא מציגים קומבו סטטוס.
                                        if ($tab !== 'not_picked' && $tab !== 'not_returned') {
                                            $options = [];
                                            if ($order['status'] === 'pending') {
                                                $options = [
                                                    'pending'  => 'ממתין (נוכחי)',
                                                    'approved' => 'מאושר',
                                                    'rejected' => 'נדחה',
                                                ];
                                            } elseif ($order['status'] === 'approved') {
                                                $options = [
                                                    'approved' => 'מאושר (נוכחי)',
                                                    'on_loan'  => 'בהשאלה',
                                                ];
                                            } elseif ($order['status'] === 'on_loan') {
                                                $options = [
                                                    'on_loan'  => 'בהשאלה (נוכחי)',
                                                    'returned' => 'עבר',
                                                ];
                                            }
                                            if (!empty($options)): ?>
                                                <select name="status"
                                                        class="muted-small order-status-select"
                                                        data-order-id="<?= (int)$order['id'] ?>"
                                                        data-current-status="<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?php foreach ($options as $value => $label): ?>
                                                        <option value="<?= $value ?>" <?= $value === $order['status'] ? 'selected' : '' ?>>
                                                            <?= $label ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif;
                                        } ?>
                                    </div>
                                <?php endif;
                            } ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>
<script>
(function () {
    const students = <?= json_encode($students, JSON_UNESCAPED_UNICODE) ?>;

    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');
    const equipmentSelect = document.getElementById('equipment_id'); // לא בשימוש – אזור ציוד מאוחד
    const equipmentIdHidden = document.getElementById('equipment_id_hidden'); // במצב עריכה
    const equipmentCheckboxes = Array.from(document.querySelectorAll('input[name="equipment_ids[]"]'));
    const categoryFilter = document.getElementById('equipment_category_filter');
    const equipmentTableBody = document.getElementById('equipment_table_body');
    const addEquipmentBtn = document.getElementById('add_equipment_btn');
    const selectedEquipmentSummary = document.getElementById('selected_equipment_summary');
    const selectedEquipmentList = document.getElementById('selected_equipment_list');
    const borrowerEmailInput = document.getElementById('borrower_email');
    const borrowerPhoneInput = document.getElementById('borrower_phone');
    const borrowerContactHidden = document.getElementById('borrower_contact');
    const submitBtn = document.getElementById('submit_order_btn');
    const borrowerSearch = document.getElementById('borrower_search');
    const borrowerHidden = document.getElementById('borrower_name');
    const borrowerSuggestions = document.getElementById('borrower_suggestions');
    const orderStatusSelect = document.getElementById('order_status');
    const rejectionWrapper = document.getElementById('rejection_reason_wrapper');
    const modeStartBtn = document.getElementById('mode_start');
    const modeEndBtn = document.getElementById('mode_end');
    const startLabel = document.getElementById('selected_start_label');
    const endLabel = document.getElementById('selected_end_label');
    const calMonthLabel = document.getElementById('cal_month_label');
    const calPrev = document.getElementById('cal_prev');
    const calNext = document.getElementById('cal_next');
    const calClose = document.getElementById('cal_close');
    const calGrid = document.getElementById('cal_grid');
    const toggle = document.getElementById('date_picker_toggle');
    const panel = document.getElementById('date_picker_panel');
    const orderModal = document.getElementById('order_modal');
    const openOrderModalBtn = document.getElementById('open_order_modal_btn');
    const orderModalClose = document.getElementById('order_modal_close');
    const orderModalCancel = document.getElementById('order_modal_cancel');
    const bulkUpdateBtn = document.getElementById('bulk_update_btn');
    const changesJsonInput = document.getElementById('changes_json');
    const originalStartInput = document.querySelector('input[name="original_start_date"]');
    const originalEndInput = document.querySelector('input[name="original_end_date"]');
    const isDuplicateMode = !!(originalStartInput || originalEndInput);
    const hasEquipComponentsInput = document.getElementById('has_equipment_components');

    // שימור חזרתיות לטאב/מצב נוכחי בעת פתיחת חלון ההזמנה
    const currentTabInput = document.querySelector('input[name="current_tab"]');
    const currentTabValue = '<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>';
    const todayModeValue = '<?= htmlspecialchars($todayMode, ENT_QUOTES, 'UTF-8') ?>';

    function buildReturnUrl() {
        let url = 'admin_orders.php?tab=' + encodeURIComponent(currentTabValue || 'today');
        if (currentTabValue === 'today' && todayModeValue) {
            url += '&today_mode=' + encodeURIComponent(todayModeValue);
        }
        return url;
    }

    function closeOrderModalAndReturn() {
        const targetUrl = buildReturnUrl();
        window.location.href = targetUrl;
    }

    const startTimeInput = document.getElementById('start_time');
    const endTimeInput = document.getElementById('end_time');
    const timeModalBackdrop = document.getElementById('time_modal_backdrop');
    const timeModalTitle = document.getElementById('time_modal_title');
    const timeOptionsContainer = document.getElementById('time_options_container');
    const timeModalCancel = document.getElementById('time_modal_cancel');

    let currentTimeTarget = null; // 'start' | 'end' | null

    const regularToggle = document.getElementById('regular_toggle');
    const recurringToggle = document.getElementById('recurring_toggle');
    const regularToggleRow = document.getElementById('regular_toggle_row');
    const recurringToggleRow = document.getElementById('recurring_toggle_row');
    const recurringSection = document.getElementById('recurring_section');
    const normalDateSection = document.getElementById('normal_date_section');
    const recurringStartDateInput = document.getElementById('recurring_start_date');
    const recurringStartDateH = document.getElementById('recurring_start_date_h');
    const recurringStartTimeSelect = document.getElementById('recurring_start_time');
    const recurringCountWrapper = document.getElementById('recurring_count_wrapper');
    const recurringEndDateWrapper = document.getElementById('recurring_end_date_wrapper');
    const recurringEndDateInput = document.getElementById('recurring_end_date');
    const recurringEndDateH = document.getElementById('recurring_end_date_h');
    const recurringCountInput = document.getElementById('recurring_count');

    if (orderModalClose) {
        orderModalClose.addEventListener('click', function () {
            closeOrderModalAndReturn();
        });
    }
    if (orderModalCancel) {
        orderModalCancel.addEventListener('click', function () {
        closeOrderModalAndReturn();
        });
    }

    // מצב עריכת הזמנה מטאבים "לא נלקח" / "לא הוחזר" – נעילה של רוב הפקדים
    const isEditFromSpecial = <?= ($editingOrder && ($tab === 'not_picked' || $tab === 'not_returned')) ? 'true' : 'false' ?>;
    if (isEditFromSpecial && orderModal) {
        const allowedIds = new Set(['order_status', 'return_equipment_status', 'equipment_return_condition']);
        const fields = orderModal.querySelectorAll('input, select, textarea, button');
        fields.forEach(function (el) {
            if (el.type === 'hidden') return;
            if (allowedIds.has(el.id)) return;
            // כפתורי סגירה/ביטול ושמירה נשארים פעילים
            if (el === orderModalClose || el === orderModalCancel || el.id === 'submit_order_btn') return;
            el.disabled = true;
        });

        // מסתירים לגמרי את אזור בחירת הציוד במצב זה
        const equipmentColumn = document.getElementById('equipment_column');
        if (equipmentColumn) {
            equipmentColumn.style.display = 'none';
        }
    }

    // מצב עריכת הזמנה בטאב "היום" במצב החזרה – רק שדות סטטוס פעילים
    const isEditFromTodayReturn = <?= ($editingOrder && $tab === 'today' && $todayMode === 'return') ? 'true' : 'false' ?>;
    if (isEditFromTodayReturn && orderModal) {
        const allowedIdsTodayReturn = new Set(['order_status', 'equipment_return_condition']);
        const fieldsToday = orderModal.querySelectorAll('input, select, textarea, button');
        fieldsToday.forEach(function (el) {
            if (el.type === 'hidden') return;
            if (allowedIdsTodayReturn.has(el.id)) return;
            if (el === orderModalClose || el === orderModalCancel || el.id === 'submit_order_btn') return;
            el.disabled = true;
        });
    }

    if (!startInput || !endInput || !modeStartBtn || !modeEndBtn || !calGrid || !calMonthLabel || !toggle || !panel) {
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

    function anyEquipmentChecked() {
        return equipmentCheckboxes.some(function (cb) { return cb.checked; });
    }

    function updateSelectedEquipmentSummary() {
        const checked = equipmentCheckboxes.filter(function (cb) { return cb.checked; });

        if (selectedEquipmentSummary) {
            const count = checked.length;
            if (count === 0) {
                selectedEquipmentSummary.textContent = '';
            } else {
                selectedEquipmentSummary.textContent = 'נבחרו ' + count + ' פריטי ציוד להזמנה.';
            }
        }

        if (selectedEquipmentList) {
            selectedEquipmentList.innerHTML = '';
            checked.forEach(function (cb) {
                const id = cb.value || '';
                const name = cb.getAttribute('data-name') || '';
                const code = cb.getAttribute('data-code') || '';
                const row = document.createElement('div');
                row.className = 'selected-equipment-row';
                row.setAttribute('data-equipment-id', id);
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.justifyContent = 'space-between';
                row.style.marginBottom = '4px';

                const label = document.createElement('span');
                label.textContent = name + (code ? ' (' + code + ')' : '');

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'equipment-list-trash';
                removeBtn.setAttribute('aria-label', 'הסר ציוד');
                removeBtn.title = 'הסר ציוד';
                removeBtn.style.border = 'none';
                removeBtn.style.background = 'transparent';
                removeBtn.style.cursor = 'pointer';
                removeBtn.style.fontSize = '0.85rem';
                removeBtn.innerHTML = '<i data-lucide="trash-2" aria-hidden="true"></i>';

                row.appendChild(label);
                row.appendChild(removeBtn);
                selectedEquipmentList.appendChild(row);
            });
            if (window.lucide) lucide.createIcons();
            if (equipmentIdHidden) {
                const firstRow = selectedEquipmentList.querySelector('.selected-equipment-row');
                equipmentIdHidden.value = firstRow ? (firstRow.getAttribute('data-equipment-id') || '') : '';
            }
        }
    }

    let unavailableEquipmentIds = [];

    function isRecurringReady() {
        if (!recurringToggle || !recurringToggle.checked) return false;
        const startD = recurringStartDateH ? recurringStartDateH.value : '';
        const startT = recurringStartTimeSelect ? recurringStartTimeSelect.value : '';
        const endTypeEl = document.querySelector('input[name="recurring_end_type"]:checked');
        const endType = endTypeEl ? endTypeEl.value : 'count';
        if (endType === 'count') {
            const n = recurringCountInput ? parseInt(recurringCountInput.value, 10) : 0;
            return !!(startD && startT && !isNaN(n) && n >= 1);
        }
        return !!(startD && startT && recurringEndDateH && recurringEndDateH.value);
    }

    function applyEquipmentVisibility() {
        const normalDatesReady = !!startInput.value && !!endInput.value;
        const recurringReady = isRecurringReady();
        const datesReady = normalDatesReady || recurringReady;
        const categoryValue = categoryFilter ? categoryFilter.value : 'all';

        equipmentCheckboxes.forEach(function (cb) {
            cb.disabled = !datesReady;
        });
        if (equipmentTableBody) {
            const rows = equipmentTableBody.querySelectorAll('tr');
            const useAvailability = !recurringReady;
            rows.forEach(function (row) {
                const cat = row.getAttribute('data-category');
                const eqId = parseInt(row.getAttribute('data-equipment-id'), 10);
                const categoryMatch = categoryValue === 'all' || categoryValue === cat;
                const available = useAvailability ? (unavailableEquipmentIds.indexOf(eqId) === -1) : true;
                const show = datesReady && categoryMatch && available;
                row.style.display = show ? '' : 'none';
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.disabled = !datesReady || !available;
                    if (!available) checkbox.checked = false;
                }
            });
        }

        const hasEquipFromList = equipmentIdHidden ? !!equipmentIdHidden.value : false;
        const hasEquipCheckbox = anyEquipmentChecked();
        const hasEquip = hasEquipFromList || hasEquipCheckbox;
        let allowByDuplicate = true;
        if (isDuplicateMode && submitBtn) {
            const origStart = originalStartInput ? originalStartInput.value : '';
            const origEnd = originalEndInput ? originalEndInput.value : '';
            const datesChanged = (startInput.value !== origStart) || (endInput.value !== origEnd);
            allowByDuplicate = datesChanged;
        }
        if (submitBtn) {
            submitBtn.disabled = !(datesReady && hasEquip && allowByDuplicate);
        }
    }

    function refreshEquipmentAvailability() {
        const recurringOn = recurringToggle && recurringToggle.checked;
        const startDate = startInput ? startInput.value : '';
        const endDate = endInput ? endInput.value : '';

        // הזמנה מחזורית – משתמשים בפרמטרים המחזוריים בלבד
        if (recurringOn) {
            const rStartDate = recurringStartDateH ? recurringStartDateH.value : '';
            const rStartTime = recurringStartTimeSelect ? recurringStartTimeSelect.value : '';
            if (!rStartDate || !rStartTime) {
                unavailableEquipmentIds = [];
                return Promise.resolve();
            }
            const endTypeEl = document.querySelector('input[name=\"recurring_end_type\"]:checked');
            const endType = endTypeEl ? endTypeEl.value : 'count';
            const rCount = recurringCountInput ? parseInt(recurringCountInput.value, 10) : 1;
            const rEndDate = recurringEndDateH ? recurringEndDateH.value : '';
            const rFreq = document.getElementById('recurring_freq') ? document.getElementById('recurring_freq').value : 'day';
            const rDur = document.getElementById('recurring_duration') ? document.getElementById('recurring_duration').value : '1';

            const orderIdInput = orderModal ? orderModal.querySelector('input[name=\"id\"]') : null;
            const excludeOrderId = orderIdInput ? parseInt(orderIdInput.value, 10) : 0;

            const params = new URLSearchParams({
                ajax: 'available_equipment',
                recurring_enabled: '1',
                recurring_start_date: rStartDate,
                recurring_start_time: rStartTime,
                recurring_freq: rFreq,
                recurring_duration: rDur.toString(),
                recurring_end_type: endType,
                recurring_count: isNaN(rCount) ? '1' : rCount.toString(),
                recurring_end_date: rEndDate || '',
                exclude_order_id: excludeOrderId.toString()
            });

            return fetch('admin_orders.php?' + params.toString())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    unavailableEquipmentIds = data.unavailable_ids || [];
                })
                .catch(function () {
                    unavailableEquipmentIds = [];
                });
        }

        // הזמנה רגילה
        if (startDate === '' || endDate === '') {
            unavailableEquipmentIds = [];
            return Promise.resolve();
        }
        const startTime = startTimeInput ? startTimeInput.value : '';
        const endTime = endTimeInput ? endTimeInput.value : '';
        const orderIdInput = orderModal ? orderModal.querySelector('input[name="id"]') : null;
        const excludeOrderId = orderIdInput ? parseInt(orderIdInput.value, 10) : 0;

        const params = new URLSearchParams({
            ajax: 'available_equipment',
            start_date: startDate,
            end_date: endDate,
            start_time: startTime,
            end_time: endTime,
            exclude_order_id: excludeOrderId.toString()
        });
        return fetch('admin_orders.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                unavailableEquipmentIds = data.unavailable_ids || [];
            })
            .catch(function () {
                unavailableEquipmentIds = [];
            });
    }

    // לפני שליחת הטופס – אם משנים סטטוס ל"עבר" ויש רכיבי ציוד נלווים, דורשים אישור מפורש
    if (orderModal && orderStatusSelect && hasEquipComponentsInput && hasEquipComponentsInput.value === '1') {
        orderModal.querySelector('form')?.addEventListener('submit', function (e) {
            var statusVal = orderStatusSelect.value;
            if (statusVal === 'returned') {
                var ok = window.confirm('האם כל רכיבי הציוד הנלווים הוחזרו במלואם?');
                if (!ok) {
                    e.preventDefault();
                }
            }
        });
    }

    // ולידציית תאריכים/שעות (התאמה לשרת)
    if (orderModal) {
        const orderFormValidate = orderModal.querySelector('form');
        if (orderFormValidate && startInput && endInput) {
            orderFormValidate.addEventListener('submit', function (e) {
                if (recurringToggle && recurringToggle.checked) return;
                var sd = startInput.value || '';
                var ed = endInput.value || '';
                if (sd && ed && ed < sd) {
                    e.preventDefault();
                    alert('תאריך החזרה לא יכול להיות מוקדם מתאריך ההשאלה.');
                    return false;
                }
                if (sd && ed && sd === ed) {
                    var st = startTimeInput ? startTimeInput.value : '';
                    var et = endTimeInput ? endTimeInput.value : '';
                    if (st && et && et <= st) {
                        e.preventDefault();
                        alert('באותו יום, שעת החזרה חייבת להיות מאוחרת משעת ההשאלה.');
                        return false;
                    }
                }
            });
        }
    }

    function updateEquipmentState() {
        const recurringOn = recurringToggle && recurringToggle.checked;
        const hasStart = !!startInput.value;
        const hasEnd = !!endInput.value;
        const datesReady = hasStart && hasEnd;

        if (recurringOn) {
            unavailableEquipmentIds = [];
            applyEquipmentVisibility();
        } else if (datesReady && equipmentTableBody) {
            refreshEquipmentAvailability().then(function () {
                applyEquipmentVisibility();
            });
        } else {
            unavailableEquipmentIds = [];
            applyEquipmentVisibility();
        }
    }

    function updateLabels() {
        const startDateText = startInput.value || '-';
        const endDateText = endInput.value || '-';

        const startTimeText = startTimeInput && startTimeInput.value ? (' ' + startTimeInput.value) : '';
        const endTimeText = endTimeInput && endTimeInput.value ? (' ' + endTimeInput.value) : '';

        startLabel.textContent = startDateText === '-' ? '-' : (startDateText + startTimeText);
        endLabel.textContent = endDateText === '-' ? '-' : (endDateText + endTimeText);

        const rangeLabel = document.getElementById('date_range_label');
        if (rangeLabel) {
            if (startInput.value && endInput.value) {
                rangeLabel.textContent = ' (' + startDateText + startTimeText + ' עד ' + endDateText + endTimeText + ')';
            } else if (startInput.value) {
                rangeLabel.textContent = ' (' + startDateText + startTimeText + ' - )';
            } else {
                rangeLabel.textContent = '-';
            }
        }
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
                    const isoVal = toIso(cellDate);
                    openTimePicker(mode, isoVal);
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
            // ברירת מחדל: תאריך החזרה = תאריך ההשאלה (אם עדיין לא נקבע)
            if (!endInput.value) {
                endInput.value = toIso(date);
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

        // בוטלה הסגירה האוטומטית של לוח השנה; המשתמש יסגור ידנית עם כפתור הסגירה
    }

    function timeStrToMinutes(t) {
        if (!t) return -1;
        const m = /^(\d{1,2}):(\d{2})$/.exec(String(t).trim());
        if (!m) return -1;
        return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    }

    function closeTimeModalAndSync() {
        if (!timeModalBackdrop) return;
        timeModalBackdrop.style.display = 'none';
        // סגירה בלי שעה: עדיין נשמר תאריך החזרה שנקבע ב-selectDate
        if (currentTimeTarget === 'start' && startInput && endInput && startInput.value && !endInput.value) {
            endInput.value = startInput.value;
        }
        currentTimeTarget = null;
        updateLabels();
        updateEquipmentState();
    }

    function openTimePicker(target, isoDate) {
        if (!timeModalBackdrop || !timeModalTitle || !timeOptionsContainer) {
            return;
        }
        currentTimeTarget = target; // 'start' או 'end'

        const isStart = target === 'start';
        timeModalTitle.textContent = isStart ? 'בחירת שעת השאלה' : 'בחירת שעת החזרה';

        // ניקוי אופציות קודמות
        timeOptionsContainer.innerHTML = '';

        let hours = [];
        for (let h = 7; h <= 22; h++) {
            hours.push(h);
        }
        if (!isStart && startInput && endInput && startTimeInput
            && startInput.value === endInput.value && isoDate === startInput.value) {
            const sm = timeStrToMinutes(startTimeInput.value);
            if (sm >= 0) {
                hours = hours.filter(function (h) {
                    return h * 60 > sm;
                });
            }
        }
        if (hours.length === 0) {
            alert('אין שעת החזרה חוקית באותו יום אחרי שעת ההשאלה. בחר תאריך החזרה מאוחר יותר.');
            currentTimeTarget = null;
            return;
        }

        const currentValue = isStart && startTimeInput ? startTimeInput.value :
            (!isStart && endTimeInput ? endTimeInput.value : '');

        hours.forEach(function (h) {
            const label = (h < 10 ? '0' + h : '' + h) + ':00';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'time-option-btn';
            btn.textContent = label;
            if (currentValue === label) {
                btn.classList.add('selected');
            }
            btn.addEventListener('click', function () {
                if (isStart && startTimeInput) {
                    startTimeInput.value = label;
                    if (endInput && startInput && (!endInput.value || endInput.value === startInput.value)) {
                        endInput.value = startInput.value;
                    }
                    // לאחר בחירת שעת השאלה – מעבר למצב תאריך/שעת החזרה
                    setMode('end');
                } else if (!isStart && endTimeInput) {
                    endTimeInput.value = label;
                }
                updateLabels();
                updateEquipmentState();
                timeModalBackdrop.style.display = 'none';
                currentTimeTarget = null;
            });
            timeOptionsContainer.appendChild(btn);
        });

        timeModalBackdrop.style.display = 'flex';
    }

    // סגירת חלון השעות בלחיצה על "ביטול" או מחוץ לחלון
    if (timeModalBackdrop && timeModalCancel) {
        timeModalCancel.addEventListener('click', function () {
            closeTimeModalAndSync();
        });
        timeModalBackdrop.addEventListener('click', function (e) {
            if (e.target === timeModalBackdrop) {
                closeTimeModalAndSync();
            }
        });
    }

    // סגירת לוח השנה בכפתור X
    if (calClose && panel) {
        calClose.addEventListener('click', function () {
            panel.style.display = 'none';
        });
    }

    // לחיצה על פח אשפה ברשימת הציוד (הזמנה חדשה ועריכה)
    if (selectedEquipmentList) {
        selectedEquipmentList.addEventListener('click', function (e) {
            if (!e.target || !e.target.classList || !e.target.classList.contains('equipment-list-trash')) {
                return;
            }
            const row = e.target.closest('.selected-equipment-row');
            if (!row) return;
            const id = row.getAttribute('data-equipment-id');
            if (id) {
                const cb = equipmentCheckboxes.find(function (input) { return input.value === id; });
                if (cb) cb.checked = false;
            }
            updateSelectedEquipmentSummary();
            updateEquipmentState();
        });
    }

    equipmentCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            // רק מעדכנים את מצב הכפתור; הרשימה המוצגת מתעדכנת אחרי לחיצה על "הוסף"
            updateEquipmentState();
        });
    });

    // סינון טבלת הציוד לפי קטגוריה
    if (categoryFilter && equipmentTableBody) {
        categoryFilter.addEventListener('change', function () {
            applyEquipmentVisibility();
        });
    }

    // הזמנה מחזורית: טוגל, רדיו, לוחות שנה מיני
    function syncOrderModeVisibility() {
        const recurringOn = recurringToggle && recurringToggle.checked;
        const regularOn = regularToggle && regularToggle.checked;

        if (regularToggleRow && recurringToggleRow) {
            if (regularOn && !recurringOn) {
                regularToggleRow.style.display = '';
                recurringToggleRow.style.display = 'none';
            } else if (recurringOn && !regularOn) {
                regularToggleRow.style.display = 'none';
                recurringToggleRow.style.display = '';
            } else {
                // אף אחד לא מסומן – מציגים את שתי האפשרויות (רק כותרות)
                regularToggleRow.style.display = '';
                recurringToggleRow.style.display = '';
            }
        }

        if (recurringSection && normalDateSection) {
            if (recurringOn && !regularOn) {
                recurringSection.style.display = 'block';
                normalDateSection.style.display = 'none';
            } else if (regularOn && !recurringOn) {
                recurringSection.style.display = 'none';
                normalDateSection.style.display = 'block';
            } else {
                // אף אחד לא מסומן – מציגים את שתי האפשרויות (כותרות) אבל מסתירים את התוכן עד בחירה
                recurringSection.style.display = 'none';
                normalDateSection.style.display = 'none';
            }
        }
        updateEquipmentState();
    }

    if (recurringToggle && recurringSection && normalDateSection) {
        recurringToggle.addEventListener('change', function () {
            if (recurringToggle.checked && regularToggle) {
                regularToggle.checked = false;
                startInput.value = '';
                endInput.value = '';
                if (startTimeInput) startTimeInput.value = '';
                if (endTimeInput) endTimeInput.value = '';
            }
            syncOrderModeVisibility();
        });
    }
    if (regularToggle && recurringSection && normalDateSection) {
        regularToggle.addEventListener('change', function () {
            if (regularToggle.checked && recurringToggle) {
                recurringToggle.checked = false;
            }
            syncOrderModeVisibility();
        });
    }
    const recurringEndTypeRadios = document.querySelectorAll('input[name="recurring_end_type"]');
    if (recurringCountWrapper && recurringEndDateWrapper) {
        recurringEndTypeRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                const useCount = document.querySelector('input[name="recurring_end_type"]:checked').value === 'count';
                recurringCountWrapper.style.display = useCount ? 'block' : 'none';
                recurringEndDateWrapper.style.display = useCount ? 'none' : 'block';
                updateEquipmentState();
            });
        });
    }
    if (recurringCountInput) {
        recurringCountInput.addEventListener('input', function () { updateEquipmentState(); });
    }

    function buildMiniCalendar(containerId, currentYmd, onSelect) {
        const container = document.getElementById(containerId);
        if (!container) return;
        const parts = (currentYmd || '').split('-');
        let year = parts.length === 3 ? parseInt(parts[0], 10) : new Date().getFullYear();
        let month = (parts.length === 3 ? parseInt(parts[1], 10) : new Date().getMonth() + 1) - 1;
        if (isNaN(year)) year = new Date().getFullYear();
        if (isNaN(month) || month < 0) month = new Date().getMonth();
        const first = new Date(year, month, 1);
        const last = new Date(year, month + 1, 0);
        const firstDay = first.getDay();
        const daysInMonth = last.getDate();
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        let html = '<div class="recurring-cal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem;">';
        html += '<button type="button" class="icon-btn" data-dir="-1" aria-label="חודש קודם"><i data-lucide="chevron-right" aria-hidden="true"></i></button>';
        html += '<span>' + year + '-' + (month + 1) + '</span>';
        html += '<button type="button" class="icon-btn" data-dir="1" aria-label="חודש הבא"><i data-lucide="chevron-left" aria-hidden="true"></i></button></div>';
        html += '<div class="recurring-cal-weekdays" style="display:flex;gap:2px;margin-bottom:4px;font-size:0.75rem;">';
        ['א','ב','ג','ד','ה','ו','ש'].forEach(function (w) { html += '<span style="width:2rem;text-align:center;">' + w + '</span>'; });
        html += '</div><div class="recurring-cal-grid" style="display:flex;flex-wrap:wrap;gap:2px;">';
        for (let i = 0; i < firstDay; i++) html += '<span style="width:2rem;height:2rem;"></span>';
        for (let d = 1; d <= daysInMonth; d++) {
            const cellDate = new Date(year, month, d);
            const disabled = cellDate.getDay() === 5 || cellDate.getDay() === 6 || cellDate < today;
            const ymd = year + '-' + (month + 1 < 10 ? '0' : '') + (month + 1) + '-' + (d < 10 ? '0' : '') + d;
            const sel = ymd === currentYmd ? ' selected' : '';
            const dis = disabled ? ' disabled' : '';
            html += '<span class="day-cell' + sel + dis + '" data-ymd="' + ymd + '" style="width:2rem;height:2rem;text-align:center;line-height:2rem;border-radius:6px;cursor:' + (disabled ? 'not-allowed' : 'pointer') + ';font-size:0.85rem;">' + d + '</span>';
        }
        html += '</div>';
        container.innerHTML = html;
        if (window.lucide) lucide.createIcons();
        container.querySelectorAll('.day-cell:not(.disabled)').forEach(function (cell) {
            cell.addEventListener('click', function () {
                const ymd = cell.getAttribute('data-ymd');
                if (ymd) onSelect(ymd);
            });
        });
        container.querySelectorAll('.recurring-cal-header button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const dir = parseInt(btn.getAttribute('data-dir'), 10);
                const next = new Date(year, month + dir, 1);
                const nextYmd = next.getFullYear() + '-' + (next.getMonth() + 1 < 10 ? '0' : '') + (next.getMonth() + 1) + '-01';
                buildMiniCalendar(containerId, nextYmd, onSelect);
            });
        });
    }
    if (recurringStartDateInput && recurringStartDateH && document.getElementById('recurring_calendar_wrapper')) {
        recurringStartDateInput.addEventListener('focus', function () {
            const wrap = document.getElementById('recurring_calendar_wrapper');
            if (wrap.style.display === 'none') {
                wrap.style.display = 'block';
                buildMiniCalendar('recurring_calendar_wrapper', recurringStartDateH.value, function (ymd) {
                    recurringStartDateH.value = ymd;
                    recurringStartDateInput.value = ymd;
                    wrap.style.display = 'none';
                    updateEquipmentState();
                });
            } else wrap.style.display = 'none';
        });
    }
    if (recurringEndDateInput && recurringEndDateH && document.getElementById('recurring_end_calendar_wrapper')) {
        recurringEndDateInput.addEventListener('focus', function () {
            const wrap = document.getElementById('recurring_end_calendar_wrapper');
            if (wrap.style.display === 'none') {
                wrap.style.display = 'block';
                buildMiniCalendar('recurring_end_calendar_wrapper', recurringEndDateH.value, function (ymd) {
                    recurringEndDateH.value = ymd;
                    recurringEndDateInput.value = ymd;
                    wrap.style.display = 'none';
                    updateEquipmentState();
                });
            } else wrap.style.display = 'none';
        });
    }
    if (recurringStartTimeSelect) recurringStartTimeSelect.addEventListener('change', function () { updateEquipmentState(); });

    // פתיחה דרך "ניהול יומי": ברירת מחדל הזמנה רגילה + פתיחת לוח שנה + שעת התחלה מוכנה
    const isDailyPrefillNew = <?= (!$editingOrder && $mode === 'new' && $prefillDay !== '' && $prefillStartTime !== '') ? 'true' : 'false' ?>;
    const prefillEquipmentId = <?= (int)$prefillEquipmentId ?>;
    if (isDailyPrefillNew) {
        if (regularToggle) regularToggle.checked = true;
        if (recurringToggle) recurringToggle.checked = false;
        if (borrowerSearch) borrowerSearch.value = '';
        if (borrowerHidden) borrowerHidden.value = '';
        if (borrowerContactHidden) borrowerContactHidden.value = '';
    }

    // הפעלה ראשונית של מצב ההזמנה (ברירת מחדל: הזמנה רגילה)
    syncOrderModeVisibility();

    // כפתור "הוסף" – מעדכן את רשימת הפריטים שנבחרו בטופס (מעל שם השואל), לא מגיש את הטופס
    if (addEquipmentBtn) {
        addEquipmentBtn.addEventListener('click', function () {
            if (!anyEquipmentChecked()) {
                alert('יש לבחור לפחות פריט ציוד אחד.');
                return;
            }
            updateEquipmentState();
            updateSelectedEquipmentSummary();
        });
    }

    // פתיחה דרך "ניהול יומי": סימון אוטומטי של פריט ציוד לפי השורה שנבחרה בניהול היומי
    if (isDailyPrefillNew && prefillEquipmentId && equipmentCheckboxes && equipmentCheckboxes.length) {
        const cb = equipmentCheckboxes.find(function (x) { return String(x.value) === String(prefillEquipmentId); });
        if (cb) {
            cb.checked = true;
            if (addEquipmentBtn) addEquipmentBtn.click();
        }
    }

    // עדכון פרטי קשר (מייל + טלפון) לשדה החבוי borrower_contact
    function updateBorrowerContact(email, phone) {
        if (borrowerEmailInput) {
            borrowerEmailInput.value = email || '';
        }
        if (borrowerPhoneInput) {
            borrowerPhoneInput.value = phone || '';
        }
        if (borrowerContactHidden) {
            const parts = [];
            if (email) parts.push(email);
            if (phone) parts.push(phone);
            borrowerContactHidden.value = parts.join(' | ');
        }
    }

    // חיפוש "שם שואל" לפי רשימת הסטודנטים + מילוי פרטי קשר אוטומטי
    if (borrowerSearch && borrowerHidden && borrowerSuggestions && Array.isArray(students) && students.length > 0) {
        borrowerSearch.addEventListener('input', function () {
            const q = borrowerSearch.value.trim();

            // ברירת מחדל – מה שמוקלד נכנס לשדה הנסתר
            borrowerHidden.value = q;

            borrowerSuggestions.innerHTML = '';
            if (!q) {
                return;
            }

            const qLower = q.toLowerCase();
            const matches = students.filter(function (u) {
                const full = ((u.first_name || '') + ' ' + (u.last_name || '') + ' ' + (u.username || '')).trim();
                return full.toLowerCase().indexOf(qLower) !== -1;
            }).slice(0, 20);

            matches.forEach(function (u) {
                const fullName = [u.first_name, u.last_name].filter(Boolean).join(' ') || u.username;
                const item = document.createElement('div');
                item.className = 'suggestion-item';
                item.textContent = fullName;
                item.addEventListener('click', function () {
                    borrowerSearch.value = fullName;
                    borrowerHidden.value = fullName;
                    borrowerSuggestions.innerHTML = '';
                    updateBorrowerContact(u.email || '', u.phone || '');
                });
                borrowerSuggestions.appendChild(item);
            });
        });

        borrowerSearch.addEventListener('blur', function () {
            setTimeout(function () {
                borrowerSuggestions.innerHTML = '';
            }, 150);
        });
    }

    // מעקב אחרי שינויים בסטטוסי הזמנות לטובת כפתור "עדכון שינויים"
    const statusChanges = {};
    const statusSelects = Array.from(document.querySelectorAll('.order-status-select'));
    if (bulkUpdateBtn && changesJsonInput && statusSelects.length > 0) {
        statusSelects.forEach(function (sel) {
            sel.addEventListener('change', function () {
                const id = sel.getAttribute('data-order-id');
                const current = sel.getAttribute('data-current-status') || '';
                const value = sel.value;
                if (!id) return;
                if (value === current) {
                    delete statusChanges[id];
                } else {
                    statusChanges[id] = value;
                }
                const hasChanges = Object.keys(statusChanges).length > 0;
                bulkUpdateBtn.disabled = !hasChanges;
                if (hasChanges) {
                    const payload = Object.keys(statusChanges).map(function (orderId) {
                        return {id: parseInt(orderId, 10), status: statusChanges[orderId]};
                    });
                    changesJsonInput.value = JSON.stringify(payload);
                } else {
                    changesJsonInput.value = '';
                }
            });
        });
    }

    // הצגת/הסתרת שדה "סיבה לדחייה" בהתאם למצב הזמנה שנבחר
    if (orderStatusSelect && rejectionWrapper) {
        function updateRejectionVisibility() {
            rejectionWrapper.style.display = orderStatusSelect.value === 'rejected' ? 'block' : 'none';
        }
        orderStatusSelect.addEventListener('change', updateRejectionVisibility);
        updateRejectionVisibility();
    }

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

    // פתיחת/סגירת מודאל הזמנה חדשה / עריכה
    function openOrderModal() {
        if (orderModal) {
            orderModal.style.display = 'flex';
        }
    }
    function closeOrderModal() {
        if (orderModal) {
            orderModal.style.display = 'none';
        }
    }
    if (openOrderModalBtn && orderModal) {
        openOrderModalBtn.addEventListener('click', function () {
            openOrderModal();
        });
    }
    if (orderModalClose) {
        orderModalClose.addEventListener('click', function () {
            closeOrderModal();
        });
    }
    if (orderModalCancel) {
        orderModalCancel.addEventListener('click', function () {
            closeOrderModal();
        });
    }

    // אתחול
    setMode('start');
    updateLabels();
    // אם נפתח דרך "ניהול יומי" – לוח השנה יישאר פתוח כברירת מחדל, והחודש יתיישר לתאריך שנבחר
    if (isDailyPrefillNew) {
        try {
            viewDate = new Date('<?= htmlspecialchars($prefillDay, ENT_QUOTES, 'UTF-8') ?>T00:00:00');
        } catch (e) {
            // ignore
        }
        panel.style.display = 'block';
    }
    updateEquipmentState();
    updateSelectedEquipmentSummary();
    renderCalendar();
})();
</script>
<script>
    // מודאל לרכיבי פריט (צ'ק ליסט)
    (function () {
        var links = document.querySelectorAll('.equipment-components-link');
        if (!links.length) return;

        var modal = document.getElementById('order_components_modal');
        var backdrop, card, listContainer, closeBtn, saveBtn;
        var componentChecksCache = {};

        // מילוי cache ראשוני מנתוני ה-HTML (שנטענו מה-DB)
        (function initCacheFromDom() {
            var nodes = document.querySelectorAll('script[data-component-checks-for-order]');
            nodes.forEach(function (node) {
                var oid = node.getAttribute('data-component-checks-for-order');
                var code = node.getAttribute('data-component-checks-code') || '';
                if (!oid || !code) return;
                var data = {};
                try {
                    data = JSON.parse(node.textContent || '{}') || {};
                } catch (e) {
                    data = {};
                }
                if (!componentChecksCache[oid]) {
                    componentChecksCache[oid] = {};
                }
                componentChecksCache[oid][code] = data;
            });
        })();

        function ensureModal() {
            if (modal) return;
            backdrop = document.createElement('div');
            backdrop.id = 'order_components_modal';
            backdrop.style.position = 'fixed';
            backdrop.style.inset = '0';
            backdrop.style.background = 'rgba(15,23,42,0.45)';
            backdrop.style.display = 'flex';
            backdrop.style.alignItems = 'center';
            backdrop.style.justifyContent = 'center';
            backdrop.style.zIndex = '60';

            card = document.createElement('div');
            card.style.background = '#ffffff';
            card.style.borderRadius = '16px';
            card.style.boxShadow = '0 25px 60px rgba(15,23,42,0.45)';
            card.style.maxWidth = '480px';
            card.style.width = '95%';
            card.style.maxHeight = '80vh';
            card.style.overflowY = 'auto';
            card.style.padding = '1.25rem 1.5rem 1.25rem';

            var header = document.createElement('div');
            header.style.display = 'flex';
            header.style.justifyContent = 'space-between';
            header.style.alignItems = 'center';
            header.style.marginBottom = '0.75rem';

            var title = document.createElement('h2');
            title.textContent = 'רכיבי פריט';
            title.style.margin = '0';
            title.style.fontSize = '1.1rem';
            header.appendChild(title);

            closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.setAttribute('aria-label', 'סגירה');
            closeBtn.innerHTML = '<i data-lucide="x" aria-hidden="true"></i>';
            closeBtn.style.border = 'none';
            closeBtn.style.background = 'transparent';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.fontSize = '1.1rem';
            header.appendChild(closeBtn);

            card.appendChild(header);

            listContainer = document.createElement('div');
            card.appendChild(listContainer);

            var footer = document.createElement('div');
            footer.style.display = 'flex';
            footer.style.justifyContent = 'flex-start';
            footer.style.marginTop = '0.75rem';
            saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.textContent = 'שמירת סימון';
            saveBtn.className = 'btn secondary';
            footer.appendChild(saveBtn);
            card.appendChild(footer);

            backdrop.appendChild(card);
            document.body.appendChild(backdrop);
            if (window.lucide) lucide.createIcons();

            function hide() {
                backdrop.style.display = 'none';
            }

            closeBtn.addEventListener('click', hide);
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) hide();
            });

            modal = backdrop;
            modal._listContainer = listContainer;
            modal._saveBtn = saveBtn;
        }

        function openModalForCode(code, orderId, context) {
            ensureModal();
            var container = modal._listContainer;
            container.innerHTML = '';

            var dataEl = document.querySelector('script[data-components-for="' + code + '"]');
            if (!dataEl) {
                var p = document.createElement('p');
                p.textContent = 'אין רכיבים מוגדרים לפריט זה.';
                container.appendChild(p);
            } else {
                try {
                    var components = JSON.parse(dataEl.textContent || '[]') || [];
                    if (!components.length) {
                        var p2 = document.createElement('p');
                        p2.textContent = 'אין רכיבים מוגדרים לפריט זה.';
                        container.appendChild(p2);
                    } else {
                        var ul = document.createElement('ul');
                        ul.style.listStyle = 'none';
                        ul.style.padding = '0';
                        ul.style.margin = '0';
                        // בדיקה אם קיימת שמירה קודמת עבור הזמנה זו (מה-cache הזיכרוני)
                        var savedChecks = {};
                        if (componentChecksCache[orderId] && componentChecksCache[orderId][code]) {
                            savedChecks = componentChecksCache[orderId][code];
                        }
                        var hasSavedChecks = false;
                        try {
                            hasSavedChecks = savedChecks && Object.keys(savedChecks).length > 0;
                        } catch (e2) {
                            hasSavedChecks = false;
                        }

                        components.forEach(function (c) {
                            var li = document.createElement('li');
                            li.style.display = 'flex';
                            li.style.alignItems = 'center';
                            li.style.marginBottom = '0.25rem';

                            var qty = c.quantity && c.quantity > 1 ? ' (' + c.quantity + ')' : '';
                            // אם אין שמירה קודמת (בעיקר במצב החזרה) – מציגים את כל הרכיבים כברירת מחדל כ"נלקח"
                            var present = hasSavedChecks ? !!(savedChecks[c.name] && savedChecks[c.name].present) : true;
                            var returned = hasSavedChecks ? !!(savedChecks[c.name] && savedChecks[c.name].returned) : false;

                            if (context === 'return' && hasSavedChecks && !present) {
                                // בשלב החזרה מציגים רק רכיבים שסומנו כ"נלקח"
                                return;
                            }

                            if (context === 'loan') {
                                var cbLoan = document.createElement('input');
                                cbLoan.type = 'checkbox';
                                cbLoan.style.marginLeft = '0.4rem';
                                cbLoan.setAttribute('data-component-name', c.name);
                                if (present) {
                                    cbLoan.checked = true;
                                }
                                var labelLoan = document.createElement('span');
                                labelLoan.textContent = c.name + qty;
                                li.appendChild(cbLoan);
                                li.appendChild(labelLoan);
                            } else {
                                // הקשר החזרה – עמודה "נלקח" (נעול) ועמודה "הוחזר"
                                var cbTaken = document.createElement('input');
                                cbTaken.type = 'checkbox';
                                cbTaken.checked = present;
                                cbTaken.disabled = true;
                                cbTaken.style.marginLeft = '0.4rem';

                                var labelName = document.createElement('span');
                                labelName.textContent = c.name + qty;
                                labelName.style.marginLeft = '0.75rem';

                                var cbReturned = document.createElement('input');
                                cbReturned.type = 'checkbox';
                                cbReturned.style.marginLeft = '0.4rem';
                                cbReturned.setAttribute('data-component-name', c.name);
                                cbReturned.setAttribute('data-role', 'returned');
                                if (returned) {
                                    cbReturned.checked = true;
                                }

                                var labelReturned = document.createElement('span');
                                labelReturned.textContent = 'הוחזר';
                                labelReturned.style.marginLeft = '0.25rem';
                                labelReturned.style.fontSize = '0.85rem';

                                li.appendChild(cbTaken);
                                li.appendChild(labelName);
                                li.appendChild(cbReturned);
                                li.appendChild(labelReturned);
                            }

                            ul.appendChild(li);
                        });
                        container.appendChild(ul);
                    }
                } catch (e) {
                    var p3 = document.createElement('p');
                    p3.textContent = 'שגיאה בטעינת רכיבי הפריט.';
                    container.appendChild(p3);
                }
            }

            modal.style.display = 'flex';

            // חיבור כפתור שמירה
            if (modal._saveBtn) {
                modal._saveBtn.onclick = function () {
                    var payload = [];
                    var mapForCache = {};
                    if (context === 'loan') {
                        var cbLoanList = modal._listContainer.querySelectorAll('input[type="checkbox"][data-component-name]');
                        cbLoanList.forEach(function (cb) {
                            var n = cb.getAttribute('data-component-name') || '';
                            var present = cb.checked ? 1 : 0;
                            payload.push({
                                name: n,
                                present: present,
                                returned: present // בשלב ההשאלה אין מידע החזרה – נגדיר שווה לנלקח
                            });
                            if (n) {
                                mapForCache[n] = {present: !!present, returned: !!present};
                            }
                        });
                    } else {
                        // הקשר החזרה – מסתמכים על present מה-cache, ושומרים returned מהצ'קבוקסים החדשים
                        var cbReturnList = modal._listContainer.querySelectorAll('input[type="checkbox"][data-role="returned"][data-component-name]');
                        cbReturnList.forEach(function (cb) {
                            var n = cb.getAttribute('data-component-name') || '';
                            var returned = cb.checked ? 1 : 0;
                            var base = (componentChecksCache[orderId] && componentChecksCache[orderId][code] && componentChecksCache[orderId][code][n]) || {present: true, returned: false};
                            var presentVal = base.present ? 1 : 0;
                            payload.push({
                                name: n,
                                present: presentVal,
                                returned: returned
                            });
                            if (n) {
                                mapForCache[n] = {present: !!presentVal, returned: !!returned};
                            }
                        });
                    }
                    var formData = new FormData();
                    formData.append('action', 'save_components');
                    formData.append('order_id', String(orderId));
                    formData.append('equipment_code', code);
                    formData.append('components', JSON.stringify(payload));
                    fetch('admin_orders.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    }).then(function (res) {
                        if (!res.ok) throw new Error('save failed');
                        return res.json();
                    }).then(function () {
                        // עדכון cache בזיכרון כך שפתיחה חוזרת בלי רענון תשמור את ה-V
                        if (!componentChecksCache[orderId]) {
                            componentChecksCache[orderId] = {};
                        }
                        componentChecksCache[orderId][code] = mapForCache;
                        alert('הרכיבים נשמרו.');
                    }).catch(function () {
                        alert('שמירת הרכיבים נכשלה.');
                    });
                };
            }
        }

        links.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var code = link.getAttribute('data-equipment-code');
                var oid  = link.getAttribute('data-order-id');
                var ctx  = link.getAttribute('data-components-context') || 'loan';
                if (!code || !oid) return;
                openModalForCode(code, oid, ctx);
            });
        });
    })();
</script>
<?php include __DIR__ . '/admin_footer.php'; ?>
</body>
</html>
