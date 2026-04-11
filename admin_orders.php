<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/order_mail.php';
require_once __DIR__ . '/order_notifications.php';

require_admin_or_warehouse();

$me   = current_user();
$role = $me['role'] ?? 'student';

$pdo      = get_db();
$nowTs    = time();
$warehouseAlwaysOpen = false;
try {
    $stmtAlwaysOpen = $pdo->prepare("SELECT value FROM app_settings WHERE key = :k LIMIT 1");
    $stmtAlwaysOpen->execute([':k' => 'warehouse_always_open']);
    $valAlwaysOpen = $stmtAlwaysOpen->fetchColumn();
    $warehouseAlwaysOpen = trim((string)$valAlwaysOpen) === '1';
} catch (Throwable $e) {
    $warehouseAlwaysOpen = false;
}

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

function create_notification(PDO $pdo, ?int $userId, ?string $role, string $message, ?string $link = null): void
{
    gf_notification_create($pdo, $userId, $role, $message, $link);
}

function resolve_student_user_id_for_notification(PDO $pdo, string $creatorUsername, string $borrowerName): int
{
    return gf_resolve_student_user_id_for_notification($pdo, $creatorUsername, $borrowerName);
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
                SELECT DISTINCT t.equipment_id
                FROM (
                    SELECT o.id, o.equipment_id, o.status, o.start_date, o.start_time, o.end_date, o.end_time
                    FROM orders o
                    UNION ALL
                    SELECT o.id, oe.equipment_id, o.status, o.start_date, o.start_time, o.end_date, o.end_time
                    FROM orders o
                    JOIN order_equipment oe ON oe.order_id = o.id
                ) t
                WHERE t.status IN ('pending', 'approved', 'ready', 'on_loan')
                  AND (
                        (t.start_date || ' ' || COALESCE(t.start_time, '00:00')) <= :req_end
                    AND (t.end_date   || ' ' || COALESCE(t.end_time,   '23:59')) >= :req_start
                  )
            ";
            $params = [
                ':req_start' => $occStart,
                ':req_end'   => $occEnd,
            ];
            if ($excludeId > 0) {
                $sql .= " AND t.id != :exclude_id";
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
        SELECT DISTINCT t.equipment_id
        FROM (
            SELECT o.id, o.equipment_id, o.status, o.start_date, o.start_time, o.end_date, o.end_time
            FROM orders o
            UNION ALL
            SELECT o.id, oe.equipment_id, o.status, o.start_date, o.start_time, o.end_date, o.end_time
            FROM orders o
            JOIN order_equipment oe ON oe.order_id = o.id
        ) t
        WHERE t.status IN ('pending', 'approved', 'ready', 'on_loan')
          AND (
                (t.start_date || ' ' || COALESCE(t.start_time, '00:00')) <= :req_end
            AND (t.end_date   || ' ' || COALESCE(t.end_time,   '23:59')) >= :req_start
          )
    ";
    $params = [
        ':req_start' => $reqStart,
        ':req_end'   => $reqEnd,
    ];
    if ($excludeId > 0) {
        $sql .= " AND t.id != :exclude_id";
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

// AJAX: פריטי ציוד נלווה מהזמנות קודמות (אותו שואל / אותה מצלמה; מנהל מחסן + אדמין)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'prev_accessories') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!in_array($role, ['admin', 'warehouse_manager'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $orderId  = (int)($_GET['order_id'] ?? 0);
    $cameraId = (int)($_GET['camera_equipment_id'] ?? 0);
    if ($orderId < 1) {
        echo json_encode(['ok' => false, 'error' => 'bad'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmtCur = $pdo->prepare('SELECT borrower_name, equipment_id FROM orders WHERE id = :id LIMIT 1');
        $stmtCur->execute([':id' => $orderId]);
        $cur = $stmtCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $borrower = trim((string)($cur['borrower_name'] ?? ''));
        if ($cameraId < 1) {
            $cameraId = (int)($cur['equipment_id'] ?? 0);
        }
        if ($cameraId > 0) {
            $stmtCat = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id LIMIT 1');
            $stmtCat->execute([':id' => $cameraId]);
            $catMain = gf_parse_stored_equipment_category(trim((string)$stmtCat->fetchColumn()))['main'];
            if ($catMain !== 'מצלמות') {
                $cameraId = 0;
            }
        }
        if ($cameraId < 1 && $borrower !== '') {
            $stmtCam = $pdo->prepare(
                'SELECT oe.equipment_id FROM order_equipment oe
                 JOIN equipment e ON e.id = oe.equipment_id
                 WHERE oe.order_id = :oid'
            );
            $stmtCam->execute([':oid' => $orderId]);
            foreach ($stmtCam->fetchAll(PDO::FETCH_COLUMN, 0) as $eidRaw) {
                $eid = (int)$eidRaw;
                if ($eid < 1) {
                    continue;
                }
                $stmtCat = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id LIMIT 1');
                $stmtCat->execute([':id' => $eid]);
                $cm = gf_parse_stored_equipment_category(trim((string)$stmtCat->fetchColumn()))['main'];
                if ($cm === 'מצלמות') {
                    $cameraId = $eid;
                    break;
                }
            }
        }

        $pastIds = [];
        if ($borrower !== '' && $cameraId > 0) {
            $stmt = $pdo->prepare(
                "SELECT o.id FROM orders o
                 WHERE o.id != :oid
                 AND o.status NOT IN ('deleted', 'rejected')
                 AND TRIM(o.borrower_name) = :borrower
                 AND (
                     o.equipment_id = :cam
                     OR EXISTS (SELECT 1 FROM order_equipment ox WHERE ox.order_id = o.id AND ox.equipment_id = :cam2)
                 )
                 ORDER BY o.created_at DESC, o.id DESC
                 LIMIT 10"
            );
            $stmt->execute([
                ':oid' => $orderId,
                ':borrower' => $borrower,
                ':cam' => $cameraId,
                ':cam2' => $cameraId,
            ]);
            $pastIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
        }
        if ($pastIds === [] && $borrower !== '') {
            $stmt = $pdo->prepare(
                "SELECT o.id FROM orders o
                 WHERE o.id != :oid
                 AND o.status NOT IN ('deleted', 'rejected')
                 AND TRIM(o.borrower_name) = :borrower
                 ORDER BY o.created_at DESC, o.id DESC
                 LIMIT 10"
            );
            $stmt->execute([':oid' => $orderId, ':borrower' => $borrower]);
            $pastIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
        }

        $items = [];
        $seen  = [];
        foreach ($pastIds as $pastOid) {
            if ($pastOid < 1) {
                continue;
            }
            $stmt2 = $pdo->prepare(
                'SELECT e.id, e.name, e.code, TRIM(COALESCE(e.category, \'\')) AS category
                 FROM order_equipment oe
                 JOIN equipment e ON e.id = oe.equipment_id
                 WHERE oe.order_id = :oid'
            );
            $stmt2->execute([':oid' => $pastOid]);
            $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $cat = (string)($r['category'] ?? '');
                if (!gf_is_accessories_equipment_category($cat)) {
                    continue;
                }
                $eid = (int)($r['id'] ?? 0);
                if ($eid < 1 || isset($seen[$eid])) {
                    continue;
                }
                $seen[$eid] = true;
                $items[] = [
                    'id'   => $eid,
                    'name' => (string)($r['name'] ?? ''),
                    'code' => (string)($r['code'] ?? ''),
                ];
            }
        }
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    }
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
    if ($editingOrder) {
        $editingOrder['borrower_user_email'] = '';
        $editingOrder['borrower_user_phone'] = '';
        $bn = trim((string)($editingOrder['borrower_name'] ?? ''));
        if ($bn !== '') {
            try {
                $stmtBu = $pdo->prepare(
                    "SELECT email, phone FROM users
                     WHERE role = 'student'
                       AND (username = :bn OR TRIM(COALESCE(first_name,'') || ' ' || COALESCE(last_name,'')) = :bn2)
                     LIMIT 1"
                );
                $stmtBu->execute([':bn' => $bn, ':bn2' => $bn]);
                $buRow = $stmtBu->fetch(PDO::FETCH_ASSOC);
                if ($buRow) {
                    $editingOrder['borrower_user_email'] = trim((string)($buRow['email'] ?? ''));
                    $editingOrder['borrower_user_phone'] = trim((string)($buRow['phone'] ?? ''));
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}
$editingEquipmentRows = [];
$editingEquipmentIds = [];
if ($editingOrder) {
    try {
        $stmtEditEquip = $pdo->prepare(
            'SELECT e.id, e.name, e.code,
                    TRIM(COALESCE(e.category, \'\')) AS category,
                    COALESCE(oe.line_returned, 0) AS line_returned,
                    oe.line_condition AS line_condition
             FROM order_equipment oe
             JOIN equipment e ON e.id = oe.equipment_id
             WHERE oe.order_id = :oid
             ORDER BY CASE WHEN e.id = :primary_eid THEN 0 ELSE 1 END, e.name ASC'
        );
        $stmtEditEquip->execute([':oid' => (int)$editingOrder['id'], ':primary_eid' => (int)($editingOrder['equipment_id'] ?? 0)]);
        $editingEquipmentRows = $stmtEditEquip->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $editingEquipmentRows = [];
    }
    if (empty($editingEquipmentRows) && (int)($editingOrder['equipment_id'] ?? 0) > 0) {
        $stmtCatOne = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id LIMIT 1');
        $stmtCatOne->execute([':id' => (int)$editingOrder['equipment_id']]);
        $editingEquipmentRows[] = [
            'id'             => (int)$editingOrder['equipment_id'],
            'name'           => (string)($editingOrder['equipment_name'] ?? ''),
            'code'           => (string)($editingOrder['equipment_code'] ?? ''),
            'category'       => trim((string)$stmtCatOne->fetchColumn()),
            'line_returned'  => 0,
            'line_condition' => null,
        ];
    }
    foreach ($editingEquipmentRows as $eqRow) {
        $eid = (int)($eqRow['id'] ?? 0);
        if ($eid > 0 && !in_array($eid, $editingEquipmentIds, true)) {
            $editingEquipmentIds[] = $eid;
        }
    }
}

/** פריט ראשי (מצלמה/חדר) מול נלווה — לתצוגת «ציוד נבחר» */
$editingEquipmentPrimary   = null;
$editingEquipmentAccessories = [];
if ($editingOrder && $editingEquipmentRows !== []) {
    $primId = (int)($editingOrder['equipment_id'] ?? 0);
    foreach ($editingEquipmentRows as $er) {
        if ($primId > 0 && (int)($er['id'] ?? 0) === $primId) {
            $editingEquipmentPrimary = $er;
            break;
        }
    }
    if ($editingEquipmentPrimary === null) {
        foreach ($editingEquipmentRows as $er) {
            $m = gf_parse_stored_equipment_category(trim((string)($er['category'] ?? '')))['main'];
            if ($m === 'מצלמות' || $m === 'חדרי עריכה') {
                $editingEquipmentPrimary = $er;
                break;
            }
        }
    }
    if ($editingEquipmentPrimary === null) {
        $editingEquipmentPrimary = $editingEquipmentRows[0];
    }
    $pid = (int)($editingEquipmentPrimary['id'] ?? 0);
    foreach ($editingEquipmentRows as $er) {
        if ((int)($er['id'] ?? 0) !== $pid) {
            $editingEquipmentAccessories[] = $er;
        }
    }
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
        $equipmentPreparedPost = !empty($_POST['equipment_prepared']);
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

        // איסוף מזהי ציוד – מרובים במצב יצירה, ובעריכה תומך גם בהחלפת ציוד מרובה
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
            $rawEquipmentIds = $_POST['equipment_ids'] ?? [];
            if (is_array($rawEquipmentIds) && !empty($rawEquipmentIds)) {
                foreach ($rawEquipmentIds as $rawId) {
                    $eid = (int)$rawId;
                    if ($eid > 0 && !in_array($eid, $equipmentIds, true)) {
                        $equipmentIds[] = $eid;
                    }
                }
            }
            if (empty($equipmentIds)) {
                $singleEquipmentId = (int)($_POST['equipment_id'] ?? 0);
                if ($singleEquipmentId > 0) {
                    $equipmentIds[] = $singleEquipmentId;
                }
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

        if (!$isSpecialStatusUpdate && !empty($_POST['include_equipment_kit']) && ($action === 'create' || $action === 'update')) {
            $cameraForKit = 0;
            foreach ($equipmentIds as $eid) {
                $stmtCamKit = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id LIMIT 1');
                $stmtCamKit->execute([':id' => $eid]);
                $catCamKit = trim((string)$stmtCamKit->fetchColumn());
                if (gf_parse_stored_equipment_category($catCamKit)['main'] === 'מצלמות') {
                    $cameraForKit = $eid;
                    break;
                }
            }
            if ($cameraForKit > 0) {
                foreach (gf_equipment_kit_item_ids($pdo, $cameraForKit) as $kid) {
                    if ($kid > 0 && !in_array($kid, $equipmentIds, true)) {
                        $equipmentIds[] = $kid;
                    }
                }
            }
        }

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
            if (!$isSpecialStatusUpdate && ($action === 'create' || $action === 'update')) {
                $stErr = gf_validate_student_order_equipment_selection($pdo, $equipmentIds);
                if ($stErr !== null) {
                    $error = $stErr;
                }
            }
            $conflicted = [];
            if ($error === '' && !$isSpecialStatusUpdate && !$isRecurringCreate && count($equipmentIds) > 0) {
                $reqStart = $startDate . ' ' . ($startTime !== '' ? $startTime : '00:00');
                $reqEnd   = $endDate . ' ' . ($endTime !== '' ? $endTime : '23:59');
                $excludeId = ($action === 'update' && $id > 0) ? $id : 0;
                $placeholders = implode(',', array_fill(0, count($equipmentIds), '?'));
                $sql = "SELECT DISTINCT t.equipment_id
                        FROM (
                            SELECT o.id, o.equipment_id, o.status, o.start_date, o.start_time, o.end_date, o.end_time
                            FROM orders o
                            UNION ALL
                            SELECT o.id, oe.equipment_id, o.status, o.start_date, o.start_time, o.end_date, o.end_time
                            FROM orders o
                            JOIN order_equipment oe ON oe.order_id = o.id
                        ) t
                        WHERE t.equipment_id IN ($placeholders)
                        AND t.status IN ('pending', 'approved', 'ready', 'on_loan')
                        AND (t.start_date || ' ' || COALESCE(t.start_time, '00:00')) <= ?
                        AND (t.end_date || ' ' || COALESCE(t.end_time, '23:59')) >= ?
                        AND t.id != ?";
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
                    // יצירת הזמנות מחזוריות: הזמנה אחת לכל מופע (תאריך/שעה) עם כל פריטי הציוד שנבחרו
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
                            // מספר סידורי אחד לכל רצף ההזמנות שנוצר בבת אחת (איתור רצף מחזורי)
                            $seriesRow = $pdo->query('SELECT COALESCE(MAX(recurring_series_id), 0) + 1 AS n FROM orders')->fetch(PDO::FETCH_ASSOC);
                            $recurringSeriesId = (int)($seriesRow['n'] ?? 1);

                            $insertRecurring = $pdo->prepare(
                                'INSERT INTO orders (equipment_id, borrower_name, borrower_contact, start_date, end_date, start_time, end_time, status, notes, purpose, admin_notes, equipment_prepared, recurring_series_id, created_at, creator_username, return_equipment_status)
                                 VALUES (:equipment_id, :borrower_name, :borrower_contact, :start_date, :end_date, :start_time, :end_time, :status, :notes, :purpose, :admin_notes, :equipment_prepared, :recurring_series_id, :created_at, :creator_username, :return_equipment_status)'
                            );
                            $createdCount = 0;
                            $occurrenceIndex = 0;
                            $insertOrderEquipment = $pdo->prepare(
                                'INSERT OR IGNORE INTO order_equipment (order_id, equipment_id) VALUES (:order_id, :equipment_id)'
                            );
                            foreach ($occurrences as $occ) {
                                $occurrenceIndex++;
                                $insertRecurring->execute([
                                    ':equipment_id'           => (int)$equipmentIds[0],
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
                                    ':equipment_prepared'     => 0,
                                    ':recurring_series_id'    => $recurringSeriesId,
                                    ':created_at'             => date('Y-m-d H:i:s'),
                                    ':creator_username'       => (string)($me['username'] ?? ''),
                                    ':return_equipment_status'=> 'תקין',
                                ]);
                                $newOrderId = (int)$pdo->lastInsertId();
                                foreach ($equipmentIds as $equipmentId) {
                                    $insertOrderEquipment->execute([
                                        ':order_id' => $newOrderId,
                                        ':equipment_id' => (int)$equipmentId,
                                    ]);
                                }
                                $createdCount++;
                            }
                            $success = $createdCount > 1 ? "נוצרו {$createdCount} הזמנות בהצלחה." : 'הזמנה נוצרה בהצלחה.';

                            if ($role === 'student') {
                                $stmtFirst = $pdo->prepare('SELECT MIN(id) AS m FROM orders WHERE recurring_series_id = :s');
                                $stmtFirst->execute([':s' => $recurringSeriesId]);
                                $firstOid = (int)($stmtFirst->fetchColumn());
                                if ($firstOid > 0) {
                                    gf_try_mail_student_order_event($pdo, $me, $firstOid, 'created');
                                }
                            }

                            // התראה למנהלים על הזמנה מחזורית חדשה שיצר סטודנט
                            if ($role === 'student') {
                                $creatorName = (string)($me['username'] ?? ($me['first_name'] ?? 'סטודנט'));
                                $msg = 'סטודנט ' . $creatorName . ' יצר הזמנה מחזורית חדשה.';
                                $link = 'admin_orders.php?tab=pending';
                                gf_notify_admins_order_event($pdo, 'adm_new_order', $msg, $link, ['order_id' => (string)$firstOid]);
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
                         (equipment_id, borrower_name, borrower_contact, start_date, end_date, start_time, end_time, status, notes, purpose, admin_notes, equipment_prepared, recurring_series_id, created_at, creator_username, return_equipment_status)
                         VALUES
                         (:equipment_id, :borrower_name, :borrower_contact, :start_date, :end_date, :start_time, :end_time, :status, :notes, :purpose, :admin_notes, :equipment_prepared, :recurring_series_id, :created_at, :creator_username, :return_equipment_status)'
                    );
                    $stmt->execute([
                        // שומרים equipment_id ראשון לצורך תאימות לאזורים במערכת שמסתמכים על orders.equipment_id
                        ':equipment_id'            => (int)($equipmentIds[0] ?? 0),
                        ':borrower_name'           => $borrowerName,
                        ':borrower_contact'        => $borrowerContact,
                        ':start_date'              => $startDate,
                        ':end_date'                => $endDate,
                        ':start_time'              => $startTime !== '' ? $startTime : null,
                        ':end_time'                => $endTime !== '' ? $endTime : null,
                        ':status'                  => $initialStatus,
                        ':notes'                   => $notes,
                        ':purpose'                 => $purpose !== '' ? $purpose : null,
                        ':admin_notes'             => $adminNotesPost !== '' ? $adminNotesPost : null,
                        ':equipment_prepared'      => 0,
                        ':recurring_series_id'     => null,
                        ':created_at'              => date('Y-m-d H:i:s'),
                        ':creator_username'        => (string)($me['username'] ?? ''),
                        ':return_equipment_status' => 'תקין',
                    ]);
                    $newOrderId = (int)$pdo->lastInsertId();

                    $insertOrderEquipment = $pdo->prepare(
                        'INSERT OR IGNORE INTO order_equipment (order_id, equipment_id) VALUES (:order_id, :equipment_id)'
                    );
                    foreach ($equipmentIds as $equipmentId) {
                        $insertOrderEquipment->execute([
                            ':order_id' => $newOrderId,
                            ':equipment_id' => (int)$equipmentId,
                        ]);
                    }

                    $success = 'הזמנה נוצרה בהצלחה.';

                    if ($role === 'student') {
                        gf_try_mail_student_order_event($pdo, $me, $newOrderId, 'created');
                    }

                    // התראה למנהלים על הזמנה חדשה שיצר סטודנט
                    if ($role === 'student') {
                        $creatorName = (string)($me['username'] ?? ($me['first_name'] ?? 'סטודנט'));
                        $msg = 'סטודנט ' . $creatorName . ' יצר הזמנה חדשה.';
                        $link = 'admin_orders.php?tab=pending';
                        gf_notify_admins_order_event($pdo, 'adm_new_order', $msg, $link, ['order_id' => (string)$newOrderId]);
                    }

                    // הזמנה שנוצרה על ידי מנהל — התראה לסטודנט (שאול)
                    if ($role !== 'student') {
                        $sidPlaced = gf_resolve_student_user_id_for_notification($pdo, '', $borrowerName);
                        if ($sidPlaced > 0) {
                            gf_notify_student_order_event(
                                $pdo,
                                $sidPlaced,
                                'stu_admin_placed',
                                'נפתחה עבורך הזמנה חדשה על ידי המחסן.',
                                'admin_orders.php',
                                $newOrderId,
                                'הזמנה חדשה #' . $newOrderId,
                                'נפתחה עבורך הזמנה במערכת:'
                            );
                        }
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
                    $borrowerNameForNotification = (string)($orderRow['borrower_name'] ?? '');
                    $todayFormModePost = (string)($_POST['current_today_mode'] ?? '');
                    $returnCompletenessDb = (string)($orderRow['return_completeness'] ?? '');
                    $loanReturnLineReturned = [];
                    $loanReturnLineCondition = [];

                    if ($role === 'student' && !$isSpecialStatusUpdate) {
                        $fn = trim((string)($me['first_name'] ?? ''));
                        $ln = trim((string)($me['last_name'] ?? ''));
                        $bn = trim($fn . ' ' . $ln);
                        if ($bn === '') {
                            $bn = (string)($me['username'] ?? '');
                        }
                        if (trim((string)($orderRow['borrower_name'] ?? '')) !== $bn) {
                            $error = 'אין הרשאה לערוך הזמנה זו.';
                        }
                        if ($error === '' && !in_array($currentStatus, ['pending', 'approved'], true)) {
                            $error = 'לא ניתן לערוך הזמנה במצב זה.';
                        }
                        if ($error === '') {
                            $pickupCheck = gf_order_pickup_timestamp($startDate, $startTime);
                            if ($pickupCheck === false || $pickupCheck <= $nowTs) {
                                $error = 'לא ניתן לערוך הזמנה לאחר מועד הלקיחה.';
                            }
                        }
                    }

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
                        $equipmentPreparedSave = (int)($orderRow['equipment_prepared'] ?? 0);
                    } elseif (
                        $action === 'update'
                        && ($currentTab ?? '') === 'today'
                        && $todayFormModePost === 'prepare'
                        && $role !== 'student'
                    ) {
                        // טאב "היום / הכנת ציוד" – שדות מנוטרלים בצד הלקוח; נשמרים ערכי DB למעט סטטוס וציוד מוכן
                        $equipmentId     = (int)($orderRow['equipment_id'] ?? 0);
                        $borrowerName    = (string)($orderRow['borrower_name'] ?? '');
                        $borrowerContact = (string)($orderRow['borrower_contact'] ?? '');
                        $startDate       = (string)($orderRow['start_date'] ?? '');
                        $endDate         = (string)($orderRow['end_date'] ?? '');
                        $startTime       = (string)($orderRow['start_time'] ?? '');
                        $endTime         = (string)($orderRow['end_time'] ?? '');
                        $notes           = (string)($orderRow['notes'] ?? '');
                        $purpose         = (string)($orderRow['purpose'] ?? '');
                        $adminNotesToSave = $adminNotesPost;
                        if ($role === 'student') {
                            $adminNotesToSave = (string)($orderRow['admin_notes'] ?? '');
                        }
                        $equipmentPreparedSave = $equipmentPreparedPost ? 1 : 0;
                    } else {
                        $equipmentId = $equipmentIds[0] ?? (int)($orderRow['equipment_id'] ?? 0);
                        $adminNotesToSave = $adminNotesPost;
                        if ($role === 'student') {
                            $adminNotesToSave = (string)($orderRow['admin_notes'] ?? '');
                        }
                        $equipmentPreparedSave = $equipmentPreparedPost ? 1 : 0;
                    }
                    // טאב "היום / החזרה": שדות מנוטרלים – לא לדרוס מטרה/הערות מה-DB
                    if (
                        $action === 'update'
                        && ($currentTab ?? '') === 'today'
                        && $todayFormModePost === 'return'
                        && $role !== 'student'
                    ) {
                        $purpose = (string)($orderRow['purpose'] ?? '');
                        $notes = (string)($orderRow['notes'] ?? '');
                        $adminNotesToSave = (string)($orderRow['admin_notes'] ?? '');
                        $equipmentPreparedSave = (int)($orderRow['equipment_prepared'] ?? 0);
                    }
                    $allowedNext = [];
                    if ($currentStatus === 'pending') {
                        $allowedNext = ['approved', 'rejected', 'deleted', 'ready'];
                    } elseif ($currentStatus === 'approved') {
                        if ($currentTab === 'not_picked') {
                            $allowedNext = ['returned'];
                        } else {
                            $allowedNext = ['on_loan', 'ready', 'rejected', 'deleted'];
                        }
                    } elseif ($currentStatus === 'ready') {
                        $allowedNext = ['on_loan', 'approved', 'deleted'];
                    } elseif ($currentStatus === 'on_loan') {
                        $allowedNext = ['returned'];
                    } elseif ($currentStatus === 'rejected' || $currentStatus === 'deleted') {
                        $allowedNext = [];
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

                    $oeReturnedPost = $_POST['oe_returned'] ?? null;
                    $oeConditionPost = $_POST['oe_condition'] ?? null;
                    if (
                        $error === ''
                        && ($role === 'admin' || $role === 'warehouse_manager')
                        && is_array($oeReturnedPost)
                        && ($currentStatus === 'on_loan' || $newStatus === 'returned')
                    ) {
                        $stmtOeIds = $pdo->prepare('SELECT equipment_id FROM order_equipment WHERE order_id = :oid');
                        $stmtOeIds->execute([':oid' => $id]);
                        $allOeIds = array_map('intval', $stmtOeIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
                        if ($allOeIds === []) {
                            $prim = (int)($orderRow['equipment_id'] ?? 0);
                            if ($prim > 0) {
                                $allOeIds = [$prim];
                            }
                        }
                        $allOeIds = array_values(array_unique(array_filter($allOeIds, static fn ($x) => $x > 0)));
                        $lineReturned = [];
                        $lineCondition = [];
                        foreach ($allOeIds as $eid) {
                            $lr = !empty($oeReturnedPost[$eid]) ? 1 : 0;
                            $lc = trim((string)($oeConditionPost[$eid] ?? 'תקין'));
                            if (!in_array($lc, ['תקין', 'תקול', 'חסר'], true)) {
                                $lc = 'תקין';
                            }
                            if ($lr !== 1) {
                                $lc = null;
                            }
                            $lineReturned[$eid] = $lr;
                            $lineCondition[$eid] = $lc;
                            try {
                                $updOe = $pdo->prepare('UPDATE order_equipment SET line_returned = :lr, line_condition = :lc WHERE order_id = :oid AND equipment_id = :eid');
                                $updOe->execute([':lr' => $lr, ':lc' => $lc, ':oid' => $id, ':eid' => $eid]);
                            } catch (Throwable $e) {
                                // התעלמות
                            }
                        }
                        $nRet = count(array_filter($lineReturned, static fn ($v) => (int)$v === 1));
                        $nTot = count($allOeIds);
                        if ($nTot > 0) {
                            if ($nRet >= $nTot) {
                                $returnCompletenessDb = 'full';
                            } elseif ($nRet > 0) {
                                $returnCompletenessDb = 'partial';
                            } else {
                                $returnCompletenessDb = '';
                            }
                        }
                        $condsAgg = [];
                        foreach ($allOeIds as $eid) {
                            if (!empty($lineReturned[$eid]) && ($lineCondition[$eid] ?? null) !== null && ($lineCondition[$eid] ?? '') !== '') {
                                $condsAgg[] = (string)$lineCondition[$eid];
                            }
                        }
                        if ($condsAgg !== []) {
                            if (in_array('תקול', $condsAgg, true)) {
                                $equipmentReturnConditionDb = 'תקול';
                            } elseif (in_array('חסר', $condsAgg, true)) {
                                $equipmentReturnConditionDb = 'חסר';
                            } else {
                                $equipmentReturnConditionDb = 'תקין';
                            }
                        }
                        $loanReturnLineReturned = $lineReturned;
                        $loanReturnLineCondition = $lineCondition;
                        if ($newStatus === 'returned' && $currentStatus === 'on_loan' && $nRet < 1) {
                            $error = 'יש לסמן לפחות פריט ציוד אחד כהוחזר לפני סגירת ההזמנה.';
                        }
                    }

                    if ($error === '' && $newStatus === 'ready' && $currentStatus !== 'ready') {
                        if (($currentTab ?? '') === 'today' && $todayFormModePost === 'prepare') {
                            if ($equipmentPreparedSave !== 1) {
                                $error = 'יש לסמן שהציוד מוכן לפני מעבר לסטטוס "מוכנה".';
                            }
                        } else {
                            // מחוץ לטאב "הכנת ציוד" – מעבר ל"מוכנה" ללא צ'קבוקס (למשל מטאב אחר או עדכון מרובה)
                            $equipmentPreparedSave = 1;
                        }
                    }

                    if (
                        $error === ''
                        && ($currentTab ?? '') === 'today'
                        && $todayFormModePost === 'prepare'
                        && in_array($role, ['admin', 'warehouse_manager'], true)
                        && isset($_POST['equipment_ids'])
                        && is_array($_POST['equipment_ids'])
                    ) {
                        $newEquipIdsPrep = [];
                        foreach ($_POST['equipment_ids'] as $raw) {
                            $eid = (int)$raw;
                            if ($eid > 0 && !in_array($eid, $newEquipIdsPrep, true)) {
                                $newEquipIdsPrep[] = $eid;
                            }
                        }
                        $stmtOld = $pdo->prepare('SELECT equipment_id FROM order_equipment WHERE order_id = :oid');
                        $stmtOld->execute([':oid' => $id]);
                        $oldOe = array_map('intval', $stmtOld->fetchAll(PDO::FETCH_COLUMN));
                        $primaryIdPrep = (int)($orderRow['equipment_id'] ?? 0);
                        $existingIdsPrep = array_values(array_unique(array_filter(array_merge($primaryIdPrep > 0 ? [$primaryIdPrep] : [], $oldOe), static fn ($x) => $x > 0)));
                        if ($primaryIdPrep > 0 && !in_array($primaryIdPrep, $newEquipIdsPrep, true)) {
                            $error = 'יש לכלול את פריט הציוד הראשי (מצלמה/חדר עריכה) ברשימה.';
                        }
                        if ($error === '') {
                            $addedPrep = array_diff($newEquipIdsPrep, $existingIdsPrep);
                            foreach ($addedPrep as $eid) {
                                $stmt = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id');
                                $stmt->execute([':id' => $eid]);
                                $catPrep = trim((string)$stmt->fetchColumn());
                                if (!gf_is_accessories_equipment_category($catPrep)) {
                                    $error = 'בהכנת ציוד ניתן להוסיף רק ציוד נלווה.';
                                    break;
                                }
                            }
                        }
                        if ($error === '') {
                            $removedPrep = array_diff($existingIdsPrep, $newEquipIdsPrep);
                            foreach ($removedPrep as $eid) {
                                if ($eid === $primaryIdPrep) {
                                    $error = 'לא ניתן להסיר את פריט הציוד הראשי מההזמנה.';
                                    break;
                                }
                                $stmt = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id');
                                $stmt->execute([':id' => $eid]);
                                $catPrep = trim((string)$stmt->fetchColumn());
                                if (!gf_is_accessories_equipment_category($catPrep)) {
                                    $error = 'בהכנת ציוד ניתן להסיר רק ציוד נלווה.';
                                    break;
                                }
                            }
                        }
                    }

                    if ($error === '') {
                        $oldEquipIdsSorted = [];
                        $newEquipIdsSorted = [];
                        if ($action === 'update' && $id > 0) {
                            $stmtOldOe = $pdo->prepare('SELECT equipment_id FROM order_equipment WHERE order_id = :oid');
                            $stmtOldOe->execute([':oid' => $id]);
                            $oldEquipIdsSorted = array_map('intval', $stmtOldOe->fetchAll(PDO::FETCH_COLUMN) ?: []);
                            $primOld = (int)($orderRow['equipment_id'] ?? 0);
                            if ($primOld > 0) {
                                $oldEquipIdsSorted = array_values(array_unique(array_filter(array_merge([$primOld], $oldEquipIdsSorted), static fn ($x) => (int)$x > 0)));
                            }
                            sort($oldEquipIdsSorted);
                            $newEquipIdsSorted = $equipmentIds;
                            sort($newEquipIdsSorted);
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
                                 equipment_prepared         = :equipment_prepared,
                                 updated_at                 = :updated_at,
                                 return_equipment_status    = :return_equipment_status,
                                 equipment_return_condition = :equipment_return_condition,
                                 return_completeness        = :return_completeness
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
                            ':equipment_prepared'         => $equipmentPreparedSave,
                            ':updated_at'                 => date('Y-m-d H:i:s'),
                            ':return_equipment_status'    => $returnEquipStatusDb,
                            ':equipment_return_condition' => $equipmentReturnConditionDb,
                            ':return_completeness'        => $returnCompletenessDb !== '' ? $returnCompletenessDb : null,
                            ':id'                         => $id,
                        ]);

                        if (
                            $newStatus === 'returned'
                            && $currentStatus === 'on_loan'
                            && $loanReturnLineReturned !== []
                        ) {
                            foreach ($loanReturnLineReturned as $eidR => $lrR) {
                                if ((int)$lrR !== 1) {
                                    continue;
                                }
                                $condR = $loanReturnLineCondition[$eidR] ?? null;
                                if ($condR === 'תקול') {
                                    $pdo->prepare('UPDATE equipment SET status = \'out_of_service\', updated_at = :u WHERE id = :id')
                                        ->execute([':u' => date('Y-m-d H:i:s'), ':id' => (int)$eidR]);
                                } elseif ($condR === 'חסר') {
                                    $pdo->prepare('UPDATE equipment SET status = \'missing\', updated_at = :u WHERE id = :id')
                                        ->execute([':u' => date('Y-m-d H:i:s'), ':id' => (int)$eidR]);
                                }
                            }
                            gf_apply_component_shortages_from_order_checks($pdo, $id);
                        }

                        // החלפת ציוד בפועל: עדכון טבלת order_equipment לפי בחירת checkboxes (רק אם התקבלו מהטופס)
                        // במצבי "טאבים מיוחדים" / שדות disabled – לא נוגעים בקשרי הציוד.
                        if (!$isSpecialStatusUpdate && isset($_POST['equipment_ids']) && is_array($_POST['equipment_ids'])) {
                            $newEquipIds = [];
                            foreach ($_POST['equipment_ids'] as $raw) {
                                $eid = (int)$raw;
                                if ($eid > 0 && !in_array($eid, $newEquipIds, true)) {
                                    $newEquipIds[] = $eid;
                                }
                            }
                            if (!empty($newEquipIds)) {
                                $pdo->beginTransaction();
                                try {
                                    $pdo->prepare('DELETE FROM order_equipment WHERE order_id = :oid')->execute([':oid' => $id]);
                                    $insOe = $pdo->prepare('INSERT OR IGNORE INTO order_equipment (order_id, equipment_id) VALUES (:oid, :eid)');
                                    foreach ($newEquipIds as $eid) {
                                        $insOe->execute([':oid' => $id, ':eid' => $eid]);
                                    }
                                    $pdo->commit();
                                } catch (Throwable $e) {
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                }
                            }
                        }

                        $success = 'הזמנה עודכנה בהצלחה.';
                        if ($role === 'student') {
                            gf_try_mail_student_order_event($pdo, $me, $id, 'updated');
                            $creatorNameStu = (string)($me['username'] ?? ($me['first_name'] ?? 'סטודנט'));
                            gf_notify_admins_order_event(
                                $pdo,
                                'adm_student_changed',
                                'סטודנט ' . $creatorNameStu . ' עדכן הזמנה #' . $id . '.',
                                'admin_orders.php?tab=pending',
                                ['order_id' => (string)$id]
                            );
                        } elseif (
                            $action === 'update'
                            && $id > 0
                            && !$isSpecialStatusUpdate
                            && in_array($role, ['admin', 'warehouse_manager'], true)
                        ) {
                            $studentIdN = gf_resolve_student_user_id_for_notification($pdo, $creatorUsername, $borrowerNameForNotification);
                            if ($studentIdN > 0) {
                                $didApprove = ($currentStatus === 'pending' && $newStatus === 'approved');
                                $didReject  = ($currentStatus !== 'rejected' && $newStatus === 'rejected');
                                $didDelete  = ($currentStatus !== 'deleted' && $newStatus === 'deleted');
                                if ($didApprove) {
                                    gf_notify_student_order_event(
                                        $pdo,
                                        $studentIdN,
                                        'stu_approve',
                                        'ההזמנה שלך אושרה.',
                                        'admin_orders.php',
                                        $id,
                                        'הזמנה #' . $id . ' אושרה',
                                        'ההזמנה שלך אושרה.'
                                    );
                                } elseif ($didReject) {
                                    gf_notify_student_order_event(
                                        $pdo,
                                        $studentIdN,
                                        'stu_reject',
                                        'ההזמנה שלך נדחתה.',
                                        'admin_orders.php',
                                        $id,
                                        'הזמנה #' . $id . ' נדחתה',
                                        'ההזמנה שלך נדחתה.'
                                    );
                                } elseif ($didDelete) {
                                    gf_notify_student_order_event(
                                        $pdo,
                                        $studentIdN,
                                        'stu_delete',
                                        'ההזמנה שלך נמחקה.',
                                        'admin_orders.php',
                                        $id,
                                        'הזמנה #' . $id . ' נמחקה',
                                        'ההזמנה שלך נמחקה מהמערכת.'
                                    );
                                } elseif (
                                    gf_order_admin_meaningful_content_change(
                                        $orderRow,
                                        $borrowerName,
                                        $borrowerContact,
                                        $startDate,
                                        $endDate,
                                        $startTime,
                                        $endTime,
                                        $notes,
                                        $purpose,
                                        $adminNotesToSave,
                                        $equipmentId,
                                        $newEquipIdsSorted,
                                        $oldEquipIdsSorted
                                    )
                                ) {
                                    gf_notify_student_order_event(
                                        $pdo,
                                        $studentIdN,
                                        'stu_edit_admin',
                                        'פרטי ההזמנה שלך עודכנו על ידי המחסן.',
                                        'admin_orders.php',
                                        $id,
                                        'עדכון הזמנה #' . $id,
                                        'פרטי ההזמנה עודכנו על ידי המחסן:'
                                    );
                                }
                            }
                        }
                    }

                    if ($error === '') {
                        // התראה לסטודנט על שינוי סטטוס הזמנה
                        if ($newStatus !== $currentStatus && in_array($newStatus, ['ready', 'returned'], true)) {
                            $studentId = resolve_student_user_id_for_notification($pdo, $creatorUsername, $borrowerNameForNotification);
                            if ($studentId > 0) {
                                $statusMsg = ($newStatus === 'ready')
                                    ? 'הציוד להזמנה מוכן לאיסוף.'
                                    : 'הציוד הוחזר ונקלט במערכת.';
                                create_notification(
                                    $pdo,
                                    $studentId,
                                    null,
                                    $statusMsg,
                                    'admin_orders.php'
                                );
                            }
                        }

                        // ניווט לאחר שמירה:
                        // אם התקבל current_tab בטופס – נשארים בטאב שממנו נפתחה העריכה.
                        $allowedTabsForRedirect = ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history', 'rejected_deleted'];
                        if ($currentTab && in_array($currentTab, $allowedTabsForRedirect, true)) {
                            // במעבר מ"טאב לא הוחזר" לסטטוס "עבר" – עוברים לטאב היסטוריה
                            if ($currentTab === 'not_returned' && $newStatus === 'returned') {
                                header('Location: admin_orders.php?tab=history');
                                exit;
                            }

                            $redirectUrl = 'admin_orders.php?tab=' . urlencode($currentTab);
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
                        if ($newStatus === 'rejected' || $newStatus === 'deleted') {
                            header('Location: admin_orders.php?tab=rejected_deleted');
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
                        if ($newStatus === 'ready') {
                            header('Location: admin_orders.php?tab=today');
                            exit;
                        }
                        header('Location: admin_orders.php');
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $error = 'שגיאה בשמירת ההזמנה.';
            }
            }
        }
    } elseif ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $rejectionReasonQuick = trim((string)($_POST['rejection_reason'] ?? ''));

        if ($id > 0) {
            // מביאים את הסטטוס הנוכחי כדי לבדוק מעבר חוקי + שם יוצר ההזמנה
            $stmt = $pdo->prepare('SELECT status, creator_username, borrower_name, notes FROM orders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $currentStatus   = $row['status'] ?? null;
            $creatorUsername = (string)($row['creator_username'] ?? '');
            $borrowerNameForNotification = (string)($row['borrower_name'] ?? '');
            $notesDb = trim((string)($row['notes'] ?? ''));

            $allowedNext = [];
            if ($currentStatus === 'pending') {
                $allowedNext = ['approved', 'rejected', 'deleted', 'ready'];
            } elseif ($currentStatus === 'approved') {
                // כולל סגירה להוחזר (למשל טאב "לא נלקח")
                $allowedNext = ['on_loan', 'ready', 'rejected', 'deleted', 'returned'];
            } elseif ($currentStatus === 'ready') {
                $allowedNext = ['on_loan', 'approved', 'deleted'];
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
                $notesUpdate = $notesDb;
                if ($status === 'rejected' && $rejectionReasonQuick !== '') {
                    $notesUpdate = $notesDb !== '' ? $notesDb . "\n" . 'סיבה לדחייה: ' . $rejectionReasonQuick : 'סיבה לדחייה: ' . $rejectionReasonQuick;
                }
                $stmt = $pdo->prepare(
                    'UPDATE orders
                     SET status = :status,
                         notes = :notes,
                         equipment_prepared = CASE
                             WHEN :status = \'ready\' THEN 1
                             WHEN :status = \'on_loan\' THEN 0
                             ELSE equipment_prepared
                         END,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':status'     => $status,
                    ':notes'      => $notesUpdate,
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':id'         => $id,
                ]);
                $success = 'סטטוס ההזמנה עודכן.';

                if ($currentStatus !== null && $status !== $currentStatus) {
                    $studentIdQuick = resolve_student_user_id_for_notification($pdo, $creatorUsername, $borrowerNameForNotification);
                    if ($studentIdQuick > 0) {
                        if ($status === 'approved' && $currentStatus === 'pending') {
                            gf_notify_student_order_event(
                                $pdo,
                                $studentIdQuick,
                                'stu_approve',
                                'ההזמנה שלך אושרה.',
                                'admin_orders.php',
                                $id,
                                'הזמנה #' . $id . ' אושרה',
                                'ההזמנה שלך אושרה.'
                            );
                        } elseif ($status === 'rejected') {
                            gf_notify_student_order_event(
                                $pdo,
                                $studentIdQuick,
                                'stu_reject',
                                'ההזמנה שלך נדחתה.',
                                'admin_orders.php',
                                $id,
                                'הזמנה #' . $id . ' נדחתה',
                                'ההזמנה שלך נדחתה.'
                            );
                        } elseif ($status === 'deleted') {
                            gf_notify_student_order_event(
                                $pdo,
                                $studentIdQuick,
                                'stu_delete',
                                'ההזמנה שלך נמחקה.',
                                'admin_orders.php',
                                $id,
                                'הזמנה #' . $id . ' נמחקה',
                                'ההזמנה שלך נמחקה מהמערכת.'
                            );
                        }
                    }
                }

                // התראה לסטודנט על שינוי סטטוס (גם בעדכון מתוך הטבלה)
                if ($currentStatus !== null && $status !== $currentStatus && in_array($status, ['ready', 'returned'], true)) {
                    $studentId = resolve_student_user_id_for_notification($pdo, $creatorUsername, $borrowerNameForNotification);
                    if ($studentId > 0) {
                        $statusMsg = ($status === 'ready')
                            ? 'הציוד להזמנה מוכן לאיסוף.'
                            : 'הציוד הוחזר ונקלט במערכת.';
                        create_notification(
                            $pdo,
                            $studentId,
                            null,
                            $statusMsg,
                            'admin_orders.php'
                        );
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
                if ($status === 'ready') {
                    header('Location: admin_orders.php?tab=today');
                    exit;
                }
                if ($status === 'rejected' || $status === 'deleted') {
                    header('Location: admin_orders.php?tab=rejected_deleted');
                    exit;
                }
                if ($status === 'returned') {
                    header('Location: admin_orders.php?tab=history');
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

                $stmt = $pdo->prepare('SELECT status, creator_username, borrower_name FROM orders WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentStatus   = $row['status'] ?? null;
                $creatorUsername = (string)($row['creator_username'] ?? '');
                $borrowerNameForNotification = (string)($row['borrower_name'] ?? '');

                $allowedNext = [];
                if ($currentStatus === 'pending') {
                    $allowedNext = ['approved', 'rejected', 'deleted', 'ready'];
                } elseif ($currentStatus === 'approved') {
                    $allowedNext = ['on_loan', 'ready', 'rejected', 'deleted', 'returned'];
                } elseif ($currentStatus === 'ready') {
                    $allowedNext = ['on_loan', 'approved', 'deleted'];
                } elseif ($currentStatus === 'on_loan') {
                    $allowedNext = ['returned'];
                } else {
                    $allowedNext = [];
                }

                if ($currentStatus === null || !in_array($status, $allowedNext, true)) {
                    continue;
                }

                $stmt = $pdo->prepare(
                    'UPDATE orders
                     SET status = :status,
                         equipment_prepared = CASE
                             WHEN :status = \'ready\' THEN 1
                             WHEN :status = \'on_loan\' THEN 0
                             ELSE equipment_prepared
                         END,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':status'     => $status,
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':id'         => $id,
                ]);

                if ($currentStatus !== null && $status !== $currentStatus) {
                    $studentIdBulk = resolve_student_user_id_for_notification($pdo, $creatorUsername, $borrowerNameForNotification);
                    if ($studentIdBulk > 0) {
                        if ($status === 'approved' && $currentStatus === 'pending') {
                            gf_notify_student_order_event(
                                $pdo,
                                $studentIdBulk,
                                'stu_approve',
                                'ההזמנה שלך אושרה.',
                                'admin_orders.php',
                                $id,
                                'הזמנה #' . $id . ' אושרה',
                                'ההזמנה שלך אושרה.'
                            );
                        } elseif ($status === 'rejected') {
                            gf_notify_student_order_event(
                                $pdo,
                                $studentIdBulk,
                                'stu_reject',
                                'ההזמנה שלך נדחתה.',
                                'admin_orders.php',
                                $id,
                                'הזמנה #' . $id . ' נדחתה',
                                'ההזמנה שלך נדחתה.'
                            );
                        } elseif ($status === 'deleted') {
                            gf_notify_student_order_event(
                                $pdo,
                                $studentIdBulk,
                                'stu_delete',
                                'ההזמנה שלך נמחקה.',
                                'admin_orders.php',
                                $id,
                                'הזמנה #' . $id . ' נמחקה',
                                'ההזמנה שלך נמחקה מהמערכת.'
                            );
                        }
                    }
                }

                // התראה לסטודנט על שינוי סטטוס בעדכון מרובה
                if ($currentStatus !== null && $status !== $currentStatus && in_array($status, ['ready', 'returned'], true)) {
                    $studentId = resolve_student_user_id_for_notification($pdo, $creatorUsername, $borrowerNameForNotification);
                    if ($studentId > 0) {
                        $statusMsg = ($status === 'ready')
                            ? 'הציוד להזמנה מוכן לאיסוף.'
                            : 'הציוד הוחזר ונקלט במערכת.';
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

            $success = 'סטטוס ההזמנות עודכן.';
        }
    } elseif ($action === 'delete') {
        if ($role === 'student') {
            $error = 'לביטול הזמנה יש להשתמש בביטול מהרשימה (כולל סיבה).';
        } else {
        $id = (int)($_POST['id'] ?? 0);
        $deleteScope = (string)($_POST['delete_scope'] ?? 'single');
        if (!in_array($deleteScope, ['single', 'series', 'future'], true)) {
            $deleteScope = 'single';
        }
        // מחיקת רצף / עתידיות – למנהלים בלבד
        if (($deleteScope === 'series' || $deleteScope === 'future') && !in_array($role, ['admin', 'warehouse_manager'], true)) {
            $deleteScope = 'single';
        }
        if ($id > 0) {
            $now = date('Y-m-d H:i:s');
            $todayYmd = date('Y-m-d');

            if ($deleteScope === 'single') {
                $stmtPreDel = $pdo->prepare(
                    'SELECT creator_username, borrower_name FROM orders WHERE id = :id AND status != \'deleted\' LIMIT 1'
                );
                $stmtPreDel->execute([':id' => $id]);
                $delMeta = $stmtPreDel->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("UPDATE orders SET status = 'deleted', updated_at = :u WHERE id = :id AND status != 'deleted'");
                $stmt->execute([':u' => $now, ':id' => $id]);
                if ($delMeta && $stmt->rowCount() > 0) {
                    $stuDel = gf_resolve_student_user_id_for_notification(
                        $pdo,
                        (string)($delMeta['creator_username'] ?? ''),
                        (string)($delMeta['borrower_name'] ?? '')
                    );
                    if ($stuDel > 0) {
                        gf_notify_student_order_event(
                            $pdo,
                            $stuDel,
                            'stu_delete',
                            'ההזמנה שלך נמחקה.',
                            'admin_orders.php',
                            $id,
                            'הזמנה #' . $id . ' נמחקה',
                            'ההזמנה שלך נמחקה מהמערכת.'
                        );
                    }
                }
                $success = 'ההזמנה סומנה כנמחקה.';
            } else {
                $q = $pdo->prepare('SELECT recurring_series_id FROM orders WHERE id = :id LIMIT 1');
                $q->execute([':id' => $id]);
                $sid = $q->fetchColumn();
                $seriesId = ($sid !== false && $sid !== null) ? (int)$sid : 0;
                if ($seriesId <= 0) {
                    $stmtPreDel = $pdo->prepare(
                        'SELECT creator_username, borrower_name FROM orders WHERE id = :id AND status != \'deleted\' LIMIT 1'
                    );
                    $stmtPreDel->execute([':id' => $id]);
                    $delMeta = $stmtPreDel->fetch(PDO::FETCH_ASSOC);
                    $stmt = $pdo->prepare("UPDATE orders SET status = 'deleted', updated_at = :u WHERE id = :id AND status != 'deleted'");
                    $stmt->execute([':u' => $now, ':id' => $id]);
                    if ($delMeta && $stmt->rowCount() > 0) {
                        $stuDel = gf_resolve_student_user_id_for_notification(
                            $pdo,
                            (string)($delMeta['creator_username'] ?? ''),
                            (string)($delMeta['borrower_name'] ?? '')
                        );
                        if ($stuDel > 0) {
                            gf_notify_student_order_event(
                                $pdo,
                                $stuDel,
                                'stu_delete',
                                'ההזמנה שלך נמחקה.',
                                'admin_orders.php',
                                $id,
                                'הזמנה #' . $id . ' נמחקה',
                                'ההזמנה שלך נמחקה מהמערכת.'
                            );
                        }
                    }
                    $success = 'ההזמנה סומנה כנמחקה.';
                } elseif ($deleteScope === 'series') {
                    $stmtList = $pdo->prepare(
                        'SELECT id, creator_username, borrower_name FROM orders
                         WHERE recurring_series_id = :sid AND status != \'deleted\''
                    );
                    $stmtList->execute([':sid' => $seriesId]);
                    $delRows = $stmtList->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $stmt = $pdo->prepare(
                        "UPDATE orders SET status = 'deleted', updated_at = :u
                         WHERE recurring_series_id = :sid AND status != 'deleted'"
                    );
                    $stmt->execute([':u' => $now, ':sid' => $seriesId]);
                    $n = $stmt->rowCount();
                    foreach ($delRows as $dr) {
                        $oid = (int)($dr['id'] ?? 0);
                        if ($oid <= 0) {
                            continue;
                        }
                        $stuDel = gf_resolve_student_user_id_for_notification(
                            $pdo,
                            (string)($dr['creator_username'] ?? ''),
                            (string)($dr['borrower_name'] ?? '')
                        );
                        if ($stuDel > 0) {
                            gf_notify_student_order_event(
                                $pdo,
                                $stuDel,
                                'stu_delete',
                                'ההזמנה שלך נמחקה.',
                                'admin_orders.php',
                                $oid,
                                'הזמנה #' . $oid . ' נמחקה',
                                'ההזמנה שלך נמחקה מהמערכת.'
                            );
                        }
                    }
                    $success = $n > 1
                        ? "סומנו {$n} הזמנות ברצף מחזורי #{$seriesId} כנמחקות."
                        : 'ההזמנה סומנה כנמחקה.';
                } else {
                    // עתידיות: תאריך התחלה אחרי היום (באותו רצף)
                    $stmtList = $pdo->prepare(
                        'SELECT id, creator_username, borrower_name FROM orders
                         WHERE recurring_series_id = :sid
                           AND status != \'deleted\'
                           AND date(start_date) > :today'
                    );
                    $stmtList->execute([':sid' => $seriesId, ':today' => $todayYmd]);
                    $delRows = $stmtList->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $stmt = $pdo->prepare(
                        "UPDATE orders SET status = 'deleted', updated_at = :u
                         WHERE recurring_series_id = :sid
                           AND status != 'deleted'
                           AND date(start_date) > :today"
                    );
                    $stmt->execute([':u' => $now, ':sid' => $seriesId, ':today' => $todayYmd]);
                    $n = $stmt->rowCount();
                    foreach ($delRows as $dr) {
                        $oid = (int)($dr['id'] ?? 0);
                        if ($oid <= 0) {
                            continue;
                        }
                        $stuDel = gf_resolve_student_user_id_for_notification(
                            $pdo,
                            (string)($dr['creator_username'] ?? ''),
                            (string)($dr['borrower_name'] ?? '')
                        );
                        if ($stuDel > 0) {
                            gf_notify_student_order_event(
                                $pdo,
                                $stuDel,
                                'stu_delete',
                                'ההזמנה שלך נמחקה.',
                                'admin_orders.php',
                                $oid,
                                'הזמנה #' . $oid . ' נמחקה',
                                'ההזמנה שלך נמחקה מהמערכת.'
                            );
                        }
                    }
                    $success = $n > 0
                        ? "סומנו {$n} הזמנות עתידיות ברצף #{$seriesId} כנמחקות."
                        : 'לא נמצאו הזמנות עתידיות למחיקה ברצף זה.';
                }
            }

            // לאחר מחיקה – נשארים בטאב ממנו בוצעה הפעולה (נשלח בטופס)
            if ($currentTab && in_array($currentTab, ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history', 'rejected_deleted'], true)) {
                $redir = 'admin_orders.php?tab=' . urlencode($currentTab);
                header('Location: ' . $redir);
                exit;
            }
        }
        }
    } elseif ($action === 'student_cancel_order') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['cancellation_reason'] ?? ''));
        $currentTabCancel = $_POST['current_tab'] ?? null;
        if ($role !== 'student' || $id <= 0 || $reason === '') {
            $error = 'בקשה לא תקינה.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $or = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$or || !in_array((string)($or['status'] ?? ''), ['pending', 'approved'], true)) {
                $error = 'לא ניתן לבטל הזמנה זו.';
            } else {
                $fn = trim((string)($me['first_name'] ?? ''));
                $ln = trim((string)($me['last_name'] ?? ''));
                $bn = trim($fn . ' ' . $ln);
                if ($bn === '') {
                    $bn = (string)($me['username'] ?? '');
                }
                if (trim((string)($or['borrower_name'] ?? '')) !== $bn) {
                    $error = 'אין הרשאה.';
                } else {
                    $pickup = gf_order_pickup_timestamp((string)($or['start_date'] ?? ''), (string)($or['start_time'] ?? ''));
                    if ($pickup === false || $pickup <= $nowTs) {
                        $error = 'לא ניתן לבטל לאחר מועד הלקיחה.';
                    } elseif ($pickup - $nowTs <= 48 * 3600) {
                        $error = 'פחות מ-48 שעות לפני הלקיחה — יש לשלוח בקשת מחיקה מהרשימה.';
                    } else {
                        $prevNotes = trim((string)($or['notes'] ?? ''));
                        $line = 'ביטול על ידי סטודנט: ' . $reason;
                        $newNotes = $prevNotes !== '' ? $prevNotes . "\n" . $line : $line;
                        $upd = $pdo->prepare("UPDATE orders SET status = 'deleted', updated_at = :u, notes = :n WHERE id = :id AND status IN ('pending','approved')");
                        $upd->execute([':u' => date('Y-m-d H:i:s'), ':n' => $newNotes, ':id' => $id]);
                        gf_try_mail_student_order_event($pdo, $me, $id, 'cancelled');
                        $msg = 'סטודנט ביטל הזמנה #' . $id . ': ' . $reason;
                        gf_notify_admins_order_event($pdo, 'adm_student_cancelled', $msg, 'admin_orders.php?tab=pending', [
                            'order_id' => (string)$id,
                            'reason'   => $reason,
                        ]);
                        $success = 'ההזמנה בוטלה.';
                        if ($currentTabCancel && in_array($currentTabCancel, ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history', 'rejected_deleted'], true)) {
                            $redir = 'admin_orders.php?tab=' . urlencode((string)$currentTabCancel);
                            header('Location: ' . $redir);
                            exit;
                        }
                        header('Location: admin_orders.php?tab=pending');
                        exit;
                    }
                }
            }
        }
    } elseif ($action === 'student_deletion_request') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['deletion_request_reason'] ?? ''));
        $currentTabDel = $_POST['current_tab'] ?? null;
        if ($role !== 'student' || $id <= 0 || $reason === '') {
            $error = 'בקשה לא תקינה.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $or = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$or || !in_array((string)($or['status'] ?? ''), ['pending', 'approved'], true)) {
                $error = 'לא ניתן לשלוח בקשה זו.';
            } else {
                $fn = trim((string)($me['first_name'] ?? ''));
                $ln = trim((string)($me['last_name'] ?? ''));
                $bn = trim($fn . ' ' . $ln);
                if ($bn === '') {
                    $bn = (string)($me['username'] ?? '');
                }
                if (trim((string)($or['borrower_name'] ?? '')) !== $bn) {
                    $error = 'אין הרשאה.';
                } else {
                    $pickup = gf_order_pickup_timestamp((string)($or['start_date'] ?? ''), (string)($or['start_time'] ?? ''));
                    if ($pickup === false || $pickup <= $nowTs) {
                        $error = 'לא ניתן לשלוח בקשה לאחר מועד הלקיחה.';
                    } elseif ($pickup - $nowTs > 48 * 3600) {
                        $error = 'מעל 48 שעות לפני הלקיחה ניתן לבטל ישירות.';
                    } else {
                        $upd = $pdo->prepare('UPDATE orders SET deletion_request_reason = :r, deletion_requested_at = :t, updated_at = :u WHERE id = :id AND status IN (\'pending\',\'approved\')');
                        $upd->execute([
                            ':r' => $reason,
                            ':t' => date('Y-m-d H:i:s'),
                            ':u' => date('Y-m-d H:i:s'),
                            ':id' => $id,
                        ]);
                        $msg = 'בקשת מחיקה להזמנה #' . $id . ': ' . $reason;
                        gf_notify_admins_order_event($pdo, 'adm_cancel_request', $msg, 'admin_orders.php?tab=pending', [
                            'order_id' => (string)$id,
                            'reason'   => $reason,
                        ]);
                        $success = 'בקשת המחיקה נשלחה למנהל.';
                        if ($currentTabDel && in_array($currentTabDel, ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history', 'rejected_deleted'], true)) {
                            $redir = 'admin_orders.php?tab=' . urlencode((string)$currentTabDel);
                            header('Location: ' . $redir);
                            exit;
                        }
                        header('Location: admin_orders.php?tab=pending');
                        exit;
                    }
                }
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
$equipmentOptionsAll = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);
$equipmentOptions = $equipmentOptionsAll;
if ($role === 'student') {
    $equipmentOptions = array_values(array_filter($equipmentOptionsAll, static function ($item) {
        return gf_student_may_order_equipment_category(trim((string)($item['category'] ?? '')));
    }));
}
// טבלת בחירת ציוד: הזמנה חדשה / שכפול — רק מצלמות וחדרי עריכה (גם למנהל); בעריכת הזמנה קיימת — כל הציוד הרלוונטי
$equipmentPickerRows = $equipmentOptions;
if ($role !== 'student') {
    $narrowPicker = ($editingOrder === null) || $isDuplicateMode;
    if ($narrowPicker) {
        $equipmentPickerRows = array_values(array_filter($equipmentOptionsAll, static function ($item) {
            return gf_student_may_order_equipment_category(trim((string)($item['category'] ?? '')));
        }));
    }
}

// קטגוריות ייחודיות לצורך סינון (לפי מה שמוצג בטבלת הבחירה)
$equipmentCategories = [];
foreach ($equipmentPickerRows as $item) {
    $cat = trim((string)($item['category'] ?? ''));
    if ($cat === '') {
        $cat = 'ללא קטגוריה';
    }
    if (!in_array($cat, $equipmentCategories, true)) {
        $equipmentCategories[] = $cat;
    }
}
sort($equipmentCategories, SORT_NATURAL | SORT_FLAG_CASE);

$equipmentMainById = [];
foreach ($equipmentOptions as $item) {
    $equipmentMainById[(int)($item['id'] ?? 0)] = gf_parse_stored_equipment_category(trim((string)($item['category'] ?? '')))['main'];
}
$equipmentMainByIdJson = json_encode($equipmentMainById, JSON_UNESCAPED_UNICODE);
$equipmentKitsMapJson   = json_encode(gf_equipment_kits_map_all($pdo), JSON_UNESCAPED_UNICODE);

$orderKitCheckboxChecked = false;
if ($editingOrder && (int)($editingOrder['equipment_id'] ?? 0) > 0) {
    $camEidForKit = (int)$editingOrder['equipment_id'];
    $kitIdsOrder  = gf_equipment_kit_item_ids($pdo, $camEidForKit);
    if ($kitIdsOrder !== []) {
        $orderKitCheckboxChecked = true;
        foreach ($kitIdsOrder as $kid) {
            if (!in_array($kid, $editingEquipmentIds, true)) {
                $orderKitCheckboxChecked = false;
                break;
            }
        }
    }
}

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
// rejected_deleted – נדחו או נמחקו (רך)
$validTabs = ['today', 'pending', 'future', 'not_picked', 'active', 'not_returned', 'history', 'rejected_deleted'];

if (!in_array($tab, $validTabs, true)) {
    $tab = 'today';
}

// טאב "היום" מאוחד; מצב הטופס בעריכה נקבע לפי סטטוס ההזמנה
$todayFormMode = 'prepare';
if ($tab === 'today' && $editingOrder) {
    $esTf = (string)($editingOrder['status'] ?? '');
    if ($esTf === 'on_loan') {
        $todayFormMode = 'return';
    } elseif ($esTf === 'ready') {
        $todayFormMode = 'borrow';
    } else {
        $todayFormMode = 'prepare';
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
                   o.recurring_series_id,
                   u.id AS borrower_user_id,
                   u.email AS borrower_user_email,
                   u.phone AS borrower_user_phone,
                   o.notes,
                   o.created_at,
                   o.updated_at,
                   o.creator_username,
                   o.deletion_request_reason,
                   o.deletion_requested_at,
                   e.name AS equipment_name,
                   e.code AS equipment_code,
                   (SELECT COUNT(*) FROM order_equipment oe WHERE oe.order_id = o.id) AS equipment_items_count
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

    case 'rejected_deleted':
        $where = " WHERE o.status IN ('rejected', 'deleted')";
        break;

    case 'today':
    default:
        // יום אחד: כל סוגי ההזמנות הרלוונטיות (הכנה / מוכן ללקיחה / בהשאלה)
        $where = " WHERE (
            (o.status IN ('pending', 'approved') AND DATE(o.end_date) >= :today)
            OR (o.status = 'ready' AND DATE(o.end_date) >= :today2)
            OR (o.status = 'on_loan')
        )";
        $params[':today']  = $today;
        $params[':today2'] = $today;
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
    if ($where === '') {
        $where = " WHERE o.status != 'deleted'";
    } else {
        $where .= " AND o.status != 'deleted'";
    }
}

$orderByClause = ' ORDER BY o.created_at DESC, o.id DESC';
$todaySortBy  = 'time';
$todaySortDir = 'asc';
if ($tab === 'today') {
    $sortByGet = $_GET['sort'] ?? 'time';
    $sortDirGet = strtolower((string)($_GET['dir'] ?? 'asc'));
    if (!in_array($sortDirGet, ['asc', 'desc'], true)) {
        $sortDirGet = 'asc';
    }
    if (!in_array($sortByGet, ['borrower', 'status', 'time', 'date'], true)) {
        $sortByGet = 'time';
    }
    $todaySortBy  = $sortByGet;
    $todaySortDir = $sortDirGet;
    $dirSql = $sortDirGet === 'desc' ? 'DESC' : 'ASC';
    if ($sortByGet === 'borrower') {
        $orderByClause = ' ORDER BY o.borrower_name ' . $dirSql . ', o.id ASC';
    } elseif ($sortByGet === 'status') {
        $orderByClause = ' ORDER BY o.status ' . $dirSql . ', o.start_date ASC, COALESCE(o.start_time, \'00:00\') ASC, o.id ASC';
    } elseif ($sortByGet === 'date') {
        $orderByClause = ' ORDER BY o.start_date ' . $dirSql . ', o.id ASC';
    } else {
        // שעת השאלה: כרונולוגי לפי תאריך + שעה
        if ($sortDirGet === 'desc') {
            $orderByClause = ' ORDER BY o.start_date DESC, COALESCE(o.start_time, \'00:00\') DESC, o.id DESC';
        } else {
            $orderByClause = ' ORDER BY o.start_date ASC, COALESCE(o.start_time, \'00:00\') ASC, o.id ASC';
        }
    }
}
$ordersSql  = $baseSql . $where . $orderByClause;
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
if ($editingOrder && !empty($editingOrder['equipment_code'])) {
    $ecEdit = (string)$editingOrder['equipment_code'];
    if (!in_array($ecEdit, $orderEquipmentIds, true)) {
        $orderEquipmentIds[] = $ecEdit;
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
    if ($loadId > 0) {
        $orderIdsForChecks[] = $loadId;
    }
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
        .order-status-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            align-items: center;
            margin-top: 0.25rem;
        }
        .order-status-actions .btn.small {
            font-size: 0.8rem;
            padding: 0.25rem 0.55rem;
        }
        .order-status-row-btn {
            font-size: 0.68rem !important;
            padding: 0.2rem 0.4rem !important;
            margin-inline-start: 0.15rem;
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
        .form-section-title {
            margin: 0.85rem 0 0.35rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 0.25rem;
        }
        .selected-equipment-checklist-item {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.88rem;
            font-weight: 600;
            color: #111827;
            white-space: nowrap;
        }
        .equipment-column-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.35rem;
        }
        .equipment-column-toggle-btn {
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #111827;
            border-radius: 999px;
            font-size: 0.78rem;
            padding: 0.2rem 0.55rem;
            cursor: pointer;
        }
        .equipment-column-body.hidden {
            display: none;
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
        .badge.status-ready {
            background: #dbeafe;
            color: #1e40af;
        }
        .recurring-delete-wrap {
            position: relative;
            display: inline-block;
            vertical-align: middle;
        }
        .recurring-delete-popover {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 4px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            z-index: 60;
            min-width: 220px;
            padding: 0.25rem 0;
        }
        .recurring-delete-popover form {
            margin: 0;
        }
        .recurring-delete-popover button[type="submit"] {
            width: 100%;
            text-align: right;
            padding: 0.45rem 0.75rem;
            border: none;
            background: transparent;
            cursor: pointer;
            font: inherit;
            color: #111827;
        }
        .recurring-delete-popover button[type="submit"]:hover {
            background: #f3f4f6;
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
        /* טאב "היום" – צבע רקע לפי סטטוס הזמנה */
        .row-today-future {
            background-color: #fecaca; /* עתידי / לא מוכן – אדום בהיר */
        }
        .row-today-ready {
            background-color: #ffedd5; /* מוכן לפני השאלה – כתום בהיר */
        }
        .row-today-loan {
            background-color: #dcfce7; /* בהשאלה – ירוק בהיר */
        }
        .orders-list-table-today tbody tr:nth-child(even) td {
            background: transparent;
        }
        th .th-sort {
            text-decoration: none;
            font-size: inherit;
            font-weight: inherit;
            color: #2563eb;
        }
        th .th-sort:hover {
            text-decoration: underline;
        }
        th .th-sort.active-sort {
            font-weight: 700;
            color: #111827;
        }
        .camera-accessory-panel {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            background: #fafafa;
        }
        .camera-accessory-panel h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
        }
        .camera-accessory-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding: 0.35rem 0;
            border-bottom: 1px solid #eee;
        }
        .camera-accessory-row:last-child {
            border-bottom: none;
        }
        #camera_accessory_search_modal .camera-acc-modal-card {
            max-width: 22rem;
            width: 100%;
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
        .modal-header-actions {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
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
        .gf-phone-wrap {
            position: relative;
            display: inline-block;
        }
        .gf-phone-trigger {
            display: inline-block;
            padding: 0.35rem 0.5rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #111827;
            font: inherit;
            cursor: pointer;
            min-width: 0;
        }
        .gf-phone-wrap.open .gf-phone-trigger {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        .gf-phone-popover {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 6px;
            display: none;
            flex-direction: row;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 0.55rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.14);
            z-index: 100;
        }
        .gf-phone-wrap.open .gf-phone-popover {
            display: flex;
        }
        .gf-phone-popover-item {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #f3f4f6;
            color: #111827;
            text-decoration: none;
            transition: background 0.15s;
        }
        .gf-phone-popover-item:hover {
            background: #e5e7eb;
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

    $primaryEquipmentCategoryMain = '';
    if ($editingOrder && (int)($editingOrder['equipment_id'] ?? 0) > 0) {
        try {
            $stmtPc = $pdo->prepare('SELECT TRIM(COALESCE(category,\'\')) FROM equipment WHERE id = :id LIMIT 1');
            $stmtPc->execute([':id' => (int)$editingOrder['equipment_id']]);
            $primaryEquipmentCategoryMain = gf_parse_stored_equipment_category(trim((string)$stmtPc->fetchColumn()))['main'];
        } catch (Throwable $e) {
            $primaryEquipmentCategoryMain = '';
        }
    }
    $showAdminCameraAccessoryPanel = (
        $tab === 'today'
        && $editingOrder
        && $role === 'admin'
        && !$isViewModeOrder
        && $todayFormMode === 'prepare'
        && in_array((string)($editingOrder['status'] ?? ''), ['pending', 'approved'], true)
        && $primaryEquipmentCategoryMain === 'מצלמות'
    );
    $showPerItemReturnUi = (
        $editingOrder !== null
        && ($editingOrder['status'] ?? '') === 'on_loan'
        && !$isViewModeOrder
        && $role !== 'student'
    );
    $initialCameraAccessoryIds = [];
    $equipmentMetaForCamera      = [];
    $accessoryCatalogJson        = '[]';
    if ($showAdminCameraAccessoryPanel) {
        $primCam = (int)($editingOrder['equipment_id'] ?? 0);
        foreach ($editingEquipmentRows as $eq) {
            $eid = (int)($eq['id'] ?? 0);
            if ($eid > 0) {
                $equipmentMetaForCamera[$eid] = [
                    'name' => (string)($eq['name'] ?? ''),
                    'code' => (string)($eq['code'] ?? ''),
                ];
                if ($eid !== $primCam) {
                    $initialCameraAccessoryIds[] = $eid;
                }
            }
        }
        $accList = [];
        foreach ($equipmentOptions as $item) {
            $eid = (int)($item['id'] ?? 0);
            if ($eid < 1) {
                continue;
            }
            $cat = trim((string)($item['category'] ?? ''));
            if (!isset($equipmentMetaForCamera[$eid]) && gf_is_accessories_equipment_category($cat)) {
                $equipmentMetaForCamera[$eid] = [
                    'name' => (string)($item['name'] ?? ''),
                    'code' => (string)($item['code'] ?? ''),
                ];
            }
            if (gf_is_accessories_equipment_category($cat)) {
                $accList[] = [
                    'id'   => $eid,
                    'name' => (string)($item['name'] ?? ''),
                    'code' => (string)($item['code'] ?? ''),
                ];
            }
        }
        $accessoryCatalogJson = json_encode($accList, JSON_UNESCAPED_UNICODE);
    }
    $equipmentMetaForCameraJson = json_encode($equipmentMetaForCamera, JSON_UNESCAPED_UNICODE);
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
                <div class="modal-header-actions">
                    <?php if ($editingOrder && (string)($editingOrder['status'] ?? '') === 'ready'): ?>
                        <a href="order_print.php?id=<?= (int)$editingOrder['id'] ?>"
                           class="icon-btn"
                           title="הדפסת טופס השאלה"
                           aria-label="הדפסת טופס השאלה"
                           target="_blank" rel="noopener noreferrer">
                            <i data-lucide="printer" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="modal-close" id="order_modal_close" aria-label="סגירת חלון"><i data-lucide="x" aria-hidden="true"></i></button>
                </div>
            </div>
            <?php if ($editingOrder && !empty($editingOrder['recurring_series_id'])): ?>
                <p class="muted-small" style="margin:-0.25rem 0 0.75rem 0;padding:0 0.25rem;">
                    רצף הזמנות מחזוריות מספר <strong><?= (int)$editingOrder['recurring_series_id'] ?></strong>
                </p>
            <?php endif; ?>

            <form method="post" action="admin_orders.php<?= $editingOrder && !$isViewModeOrder ? '?edit_id=' . (int)$editingOrder['id'] : '' ?>" <?= $isViewModeOrder ? 'onsubmit="return false;"' : '' ?>>
                <input type="hidden" name="action" value="<?= $editingOrder && !$isViewModeOrder ? 'update' : 'create' ?>">
                <input type="hidden" name="id" value="<?= $editingOrder ? (int)$editingOrder['id'] : 0 ?>">
                <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($tab === 'today'): ?>
                    <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayFormMode, ENT_QUOTES, 'UTF-8') ?>">
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

                        <h3 class="form-section-title">תאריך</h3>
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
                                        <?php
                                        $recurringStartHourFrom = $warehouseAlwaysOpen ? 0 : 9;
                                        $recurringStartHourTo = $warehouseAlwaysOpen ? 23 : 15;
                                        for ($h = $recurringStartHourFrom; $h <= $recurringStartHourTo; $h++): ?>
                                            <option value="<?= sprintf('%02d:00', $h) ?>"><?= sprintf('%02d:00', $h) ?></option>
                                            <?php if ($h < $recurringStartHourTo): ?>
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
                                    <?php if ($warehouseAlwaysOpen): ?>
                                    מצב "מחסן פתוח תמיד" פעיל: כל התאריכים זמינים לבחירה.
                                    <?php else: ?>
                                    ימים שעברו וימי שישי/שבת מסומנים כלא זמינים.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($showRecurringBlock): ?>
                        </div>
                        <?php endif; ?>

                        <h3 class="form-section-title">ציוד נבחר</h3>
                        <!-- רשימת פריטי הציוד שנבחרו (מתעדכנת אחרי לחיצה על "הוסף") -->
                        <div id="selected_equipment_list" style="margin: 0.5rem 0;">
                            <?php if ($editingOrder):
                                $editCodeForm = (string)($editingOrder['equipment_code'] ?? '');
                                $formEquipHasComponents = ($editCodeForm !== '' && !empty($equipmentComponentsByCode[$editCodeForm] ?? []));
                                $__oePid = (int)($editingEquipmentPrimary['id'] ?? 0);
                                $__oeOrdered = [];
                                if ($editingEquipmentPrimary) {
                                    $__oeOrdered[] = $editingEquipmentPrimary;
                                }
                                foreach ($editingEquipmentAccessories as $__acc) {
                                    $__oeOrdered[] = $__acc;
                                }
                                $showEquipmentPreparedChecklist = (
                                    $tab === 'today' && $todayFormMode === 'prepare'
                                    && in_array((string)($editingOrder['status'] ?? ''), ['pending', 'approved'], true)
                                    && !$isViewModeOrder
                                    && $role !== 'student'
                                );
                                ?>
                                <?php if (!empty($showPerItemReturnUi) && count($editingEquipmentRows) > 0): ?>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.35rem;align-items:center;">
                                    <button type="button" class="btn small secondary oe-return-field" id="oe_select_all_btn">סמן הכל כהוחזר</button>
                                    <button type="button" class="btn small secondary oe-return-field" id="oe_select_none_btn">בטל סימון</button>
                                </div>
                                <?php endif; ?>
                                <?php foreach ($__oeOrdered as $eqIdx => $eq): ?>
                                <?php if ($eqIdx === 1 && count($editingEquipmentAccessories) > 0): ?>
                                <div class="gf-oe-accessory-heading muted-small" style="font-weight:600;margin:0.65rem 0 0.25rem 0;width:100%;flex-basis:100%;">ציוד נלווה</div>
                                <?php endif; ?>
                                <div class="selected-equipment-row" data-equipment-id="<?= (int)($eq['id'] ?? 0) ?>" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 4px;">
                                    <?php if ($showEquipmentPreparedChecklist): ?>
                                        <label class="selected-equipment-checklist-item" style="flex: 1;">
                                            <input type="checkbox"
                                                   <?= (int)($eq['id'] ?? 0) === $__oePid ? 'name="equipment_prepared" id="equipment_prepared" value="1"' : 'class="equipment-prepared-item-check"' ?>
                                                   <?= !empty($editingOrder['equipment_prepared']) ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars((string)($eq['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= ((string)($eq['code'] ?? '') !== '') ? ' (' . htmlspecialchars((string)$eq['code'], ENT_QUOTES, 'UTF-8') . ')' : '' ?></span>
                                        </label>
                                    <?php elseif (!empty($showPerItemReturnUi)): ?>
                                        <?php
                                        $eidRow = (int)($eq['id'] ?? 0);
                                        $lr = (int)($eq['line_returned'] ?? 0);
                                        $lc = trim((string)($eq['line_condition'] ?? ''));
                                        if ($lc === '' || !in_array($lc, ['תקין', 'תקול', 'חסר'], true)) {
                                            $lc = 'תקין';
                                        }
                                        $eqCodeRow = (string)($eq['code'] ?? '');
                                        $hasRowComponents = ($eqCodeRow !== '' && !empty($equipmentComponentsByCode[$eqCodeRow] ?? []));
                                        $oidEdit = (int)$editingOrder['id'];
                                        ?>
                                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;flex:1;">
                                            <label style="display:flex;align-items:center;gap:0.35rem;margin:0;">
                                                <input type="checkbox"
                                                       class="oe-return-field oe-returned-cb"
                                                       name="oe_returned[<?= $eidRow ?>]"
                                                       value="1"
                                                    <?= $lr ? 'checked' : '' ?>>
                                                <span><?= htmlspecialchars((string)($eq['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= $eqCodeRow !== '' ? ' (' . htmlspecialchars($eqCodeRow, ENT_QUOTES, 'UTF-8') . ')' : '' ?></span>
                                            </label>
                                            <select name="oe_condition[<?= $eidRow ?>]" class="oe-return-field oe-condition-select" style="padding:0.25rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;font-size:0.88rem;">
                                                <option value="תקין" <?= $lc === 'תקין' ? 'selected' : '' ?>>תקין</option>
                                                <option value="תקול" <?= $lc === 'תקול' ? 'selected' : '' ?>>תקול</option>
                                                <option value="חסר" <?= $lc === 'חסר' ? 'selected' : '' ?>>חסר</option>
                                            </select>
                                            <?php if ($hasRowComponents): ?>
                                                <a href="#" class="equipment-components-link oe-return-field" style="font-size:0.85rem;white-space:nowrap;"
                                                   data-equipment-code="<?= htmlspecialchars($eqCodeRow, ENT_QUOTES, 'UTF-8') ?>"
                                                   data-order-id="<?= $oidEdit ?>"
                                                   data-components-context="return">רכיבי ציוד</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="flex: 1;"><?= htmlspecialchars((string)($eq['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= ((string)($eq['code'] ?? '') !== '') ? ' (' . htmlspecialchars((string)$eq['code'], ENT_QUOTES, 'UTF-8') . ')' : '' ?></span>
                                    <?php endif; ?>
                                    <?php
                                    $eqCodePrepare = (string)($eq['code'] ?? '');
                                    $hasRowComponentsPrepare = ($eqCodePrepare !== '' && !empty($equipmentComponentsByCode[$eqCodePrepare] ?? []));
                                    ?>
                                    <?php if ($showEquipmentPreparedChecklist && $hasRowComponentsPrepare && (int)($eq['id'] ?? 0) === $__oePid): ?>
                                        <a href="#" class="equipment-components-link" style="font-size:0.85rem;white-space:nowrap;"
                                           data-equipment-code="<?= htmlspecialchars($eqCodePrepare, ENT_QUOTES, 'UTF-8') ?>"
                                           data-order-id="<?= (int)$editingOrder['id'] ?>"
                                           data-components-context="prepare">רכיבי ציוד</a>
                                    <?php endif; ?>
                                    <?php if (!$isViewModeOrder && empty($showPerItemReturnUi)): ?>
                                        <button type="button" class="equipment-list-trash" style="border: none; background: transparent; cursor: pointer; font-size: 0.85rem;" title="הסר ציוד" aria-label="הסר ציוד"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php
                            if ($editingOrder) {
                                $codesOut = [];
                                foreach ($editingEquipmentRows as $eqR) {
                                    $c = trim((string)($eqR['code'] ?? ''));
                                    if ($c === '' || isset($codesOut[$c])) {
                                        continue;
                                    }
                                    if (empty($equipmentComponentsByCode[$c] ?? [])) {
                                        continue;
                                    }
                                    $codesOut[$c] = true;
                                    ?>
                                <script type="application/json" data-components-for="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= json_encode($equipmentComponentsByCode[$c], JSON_UNESCAPED_UNICODE) ?>
                                </script>
                                    <?php
                                    $chk = $componentChecksByOrderAndCode[(int)$editingOrder['id']][$c] ?? [];
                                    if (!empty($chk)) {
                                        ?>
                                <script type="application/json" data-component-checks-for-order="<?= (int)$editingOrder['id'] ?>" data-component-checks-code="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= json_encode($chk, JSON_UNESCAPED_UNICODE) ?>
                                </script>
                                        <?php
                                    }
                                }
                            }
                            ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($editingOrder): ?>
                        <input type="hidden" name="equipment_id" id="equipment_id_hidden" value="<?= (int)$editingOrder['equipment_id'] ?>">
                        <?php endif; ?>

                        <h3 class="form-section-title">פרטי השואל</h3>
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
                            $initialEmail = trim((string)($editingOrder['borrower_user_email'] ?? ''));
                            $initialPhone = trim((string)($editingOrder['borrower_user_phone'] ?? ''));
                            $split = gf_split_borrower_contact((string)($editingOrder['borrower_contact'] ?? ''));
                            if ($initialEmail === '' && $split['email'] !== '') {
                                $initialEmail = $split['email'];
                            }
                            if ($initialPhone === '' && $split['phone'] !== '') {
                                $initialPhone = $split['phone'];
                            }
                        } else {
                            $initialEmail = (string)($me['email'] ?? '');
                            $initialPhone = (string)($me['phone'] ?? '');
                        }
                        $mailtoSubject = ($editingOrder && $isViewModeOrder) ? ('הזמנה #' . (int)$editingOrder['id']) : '';
                        ?>
                        <?php if ($isViewModeOrder): ?>
                            <?php if ($initialEmail !== '' && filter_var($initialEmail, FILTER_VALIDATE_EMAIL)): ?>
                            <a
                                href="<?= htmlspecialchars(gf_mailto_href($initialEmail, $mailtoSubject), ENT_QUOTES, 'UTF-8') ?>"
                                id="borrower_email"
                                style="display:inline-block;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;background:#f9fafb;text-decoration:none;color:#2563eb;font-weight:500;min-width:0;">
                                <?= htmlspecialchars($initialEmail, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?php else: ?>
                            <span id="borrower_email" class="muted-small">—</span>
                            <?php endif; ?>
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
                            <?php if ($initialPhone !== ''): ?>
                            <span id="borrower_phone" style="display:inline-block;">
                                <?= gf_html_phone_contact_menu($initialPhone) ?>
                            </span>
                            <?php else: ?>
                            <span id="borrower_phone" class="muted-small">—</span>
                            <?php endif; ?>
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

                        <h3 class="form-section-title">מטרה (שם פרויקט, מרצה, קורס)</h3>
                        <label for="order_purpose">מטרה (שם פרויקט, מרצה, קורס)</label>
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
                            $isTodayPrepareCtx = ($tab === 'today' && $todayFormMode === 'prepare');
                            $isTodayBorrowCtx = ($tab === 'today' && $todayFormMode === 'borrow');
                            $orderStatusOptions = [];
                            if ($currentStatus === 'pending') {
                                $orderStatusOptions = [
                                    'pending'  => 'ממתין (נוכחי)',
                                    'approved' => 'מאושר',
                                    'rejected' => 'נדחה',
                                    'deleted'  => 'נמחק',
                                    'ready'    => 'מוכנה',
                                ];
                            } elseif ($currentStatus === 'approved') {
                                if ($tab === 'not_picked') {
                                    // בטאב "לא נלקח" מאפשרים רק סגירה להוחזר
                                    $orderStatusOptions = [
                                        'returned' => 'הוחזר',
                                    ];
                                } elseif ($isTodayPrepareCtx) {
                                    $orderStatusOptions = [
                                        'approved' => 'מאושר (נוכחי)',
                                        'ready'    => 'מוכנה',
                                        'on_loan'  => 'בהשאלה',
                                        'rejected' => 'נדחה',
                                        'deleted'  => 'נמחק',
                                    ];
                                } elseif ($isTodayBorrowCtx) {
                                    $orderStatusOptions = [
                                        'approved' => 'מאושר (נוכחי)',
                                        'ready'    => 'מוכנה',
                                        'on_loan'  => 'בהשאלה',
                                        'rejected' => 'נדחה',
                                        'deleted'  => 'נמחק',
                                    ];
                                } else {
                                    $orderStatusOptions = [
                                        'approved' => 'מאושר (נוכחי)',
                                        'on_loan'  => 'בהשאלה',
                                        'ready'    => 'מוכנה',
                                        'rejected' => 'נדחה',
                                        'deleted'  => 'נמחק',
                                    ];
                                }
                            } elseif ($currentStatus === 'ready') {
                                $orderStatusOptions = [
                                    'ready'    => 'מוכנה (נוכחי)',
                                    'on_loan'  => 'בהשאלה',
                                    'approved' => 'מאושר',
                                    'deleted'  => 'נמחק',
                                ];
                            } elseif ($currentStatus === 'on_loan') {
                                if ($tab === 'not_returned') {
                                    // בטאב "לא הוחזר" מאפשרים רק סגירה להוחזר
                                    $orderStatusOptions = [
                                        'returned' => 'הוחזר',
                                    ];
                                } else {
                                    $orderStatusOptions = [
                                        'on_loan'  => 'בהשאלה (נוכחי)',
                                        'returned' => 'הוחזר',
                                    ];
                                }
                            } elseif ($currentStatus === 'rejected') {
                                $orderStatusOptions = ['rejected' => 'נדחה (נוכחי)'];
                            } elseif ($currentStatus === 'deleted') {
                                $orderStatusOptions = ['deleted' => 'נמחק (נוכחי)'];
                            } elseif ($currentStatus === 'returned') {
                                $orderStatusOptions = ['returned' => 'הוחזר (נוכחי)'];
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
                                || ($tab === 'today' && $todayFormMode === 'return')
                            );
                            ?>
                            <h3 class="form-section-title">מצב הזמנה</h3>
                            <?php
                            $currentStatusLabelForm = $orderStatusOptions[$currentStatus] ?? $currentStatus;
                            ?>
                            <p class="muted-small" style="margin:0 0 0.5rem 0;">
                                נוכחי: <strong><?= htmlspecialchars($currentStatusLabelForm, ENT_QUOTES, 'UTF-8') ?></strong>
                            </p>
                            <input type="hidden" name="order_status" id="order_status" value="<?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>">
                            <?php if (!$isViewModeOrder): ?>
                                <div class="order-status-actions" role="group" aria-label="שינוי סטטוס">
                                    <?php foreach ($orderStatusOptions as $val => $label) {
                                        if ($val === $currentStatus) {
                                            continue;
                                        }
                                        $btnLabel = preg_replace('/\s*\(נוכחי\)\s*$/u', '', (string)$label);
                                        $btnLabel = trim($btnLabel);
                                        ?>
                                        <button type="button" class="btn small neutral order-status-set-btn" data-status="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($btnLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    <?php } ?>
                                </div>
                                <?php if (!empty($showPerItemReturnUi)): ?>
                                    <?php
                                    $rcStored = (string)($editingOrder['return_completeness'] ?? '');
                                    $eqCondSumEarly = (string)($editingOrder['equipment_return_condition'] ?? '');
                                    if ($eqCondSumEarly === '') {
                                        $eqCondSumEarly = 'תקין';
                                    }
                                    ?>
                                    <div style="margin-top:0.65rem;padding-top:0.65rem;border-top:1px solid #e5e7eb;">
                                        <label for="return_completeness">היקף החזרה</label>
                                        <select id="return_completeness" name="return_completeness" class="oe-return-field" style="margin-bottom:0.45rem;">
                                            <option value="" <?= $rcStored === '' ? 'selected' : '' ?>>—</option>
                                            <option value="full" <?= $rcStored === 'full' ? 'selected' : '' ?>>הוחזר במלאו</option>
                                            <option value="partial" <?= $rcStored === 'partial' ? 'selected' : '' ?>>הוחזר חלקית</option>
                                        </select>
                                        <label for="equipment_return_condition">סטטוס ציוד מוחזר (סיכום לפי השורות)</label>
                                        <input type="hidden" name="equipment_return_condition" id="equipment_return_condition" value="<?= htmlspecialchars($eqCondSumEarly, ENT_QUOTES, 'UTF-8') ?>">
                                        <p id="equipment_return_condition_display" class="muted-small" style="margin:0.15rem 0 0.35rem 0;">
                                            <?= htmlspecialchars($eqCondSumEarly === 'תקין' ? 'תקין' : ($eqCondSumEarly === 'תקול' ? 'לא תקין' : 'חסר'), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                        <button type="button" class="btn oe-return-field" id="order_mark_returned_btn" disabled style="margin-top:0.35rem;">הוחזר</button>
                                        <p class="muted-small" style="margin:0.35rem 0 0 0;font-size:0.82rem;">יש לסמן לפחות פריט אחד כהוחזר. ניתן גם להשתמש בכפתור «הוחזר» במצב הזמנה למעלה.</p>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($showReturnStatusField): ?>
                                <label for="return_equipment_status">סטטוס החזרה</label>
                                <select id="return_equipment_status" name="return_equipment_status" <?= $isViewModeOrder ? 'disabled' : '' ?>>
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
                                    ($showReturnStatusField || ($tab === 'today' && $todayFormMode === 'return'))
                                    && empty($showPerItemReturnUi)
                                );
                                ?>
                                <?php if ($showEquipReturnCombo): ?>
                                    <label for="equipment_return_condition">סטטוס ציוד מוחזר</label>
                                    <select id="equipment_return_condition" name="equipment_return_condition" <?= $isViewModeOrder ? 'disabled' : '' ?>>
                                        <option value="תקין" <?= $equipmentReturnCondition === 'תקין' ? 'selected' : '' ?>>תקין</option>
                                        <option value="תקול" <?= $equipmentReturnCondition === 'תקול' ? 'selected' : '' ?>>לא תקין</option>
                                        <option value="חסר" <?= $equipmentReturnCondition === 'חסר' ? 'selected' : '' ?>>חסר</option>
                                    </select>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div id="rejection_reason_wrapper" style="margin-top: 0.5rem; display: <?= $currentStatus === 'rejected' ? 'block' : 'none' ?>;">
                                <label for="rejection_reason">סיבה לדחיית הבקשה</label>
                                <textarea id="rejection_reason" name="rejection_reason" <?= $isViewModeOrder ? 'readonly' : '' ?>
                                          placeholder="פרט את הסיבה לדחיית הבקשה"></textarea>
                            </div>
                        <?php } ?>

                        <h3 class="form-section-title">הערות</h3>
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
                                || in_array($orderStatus, ['ready', 'on_loan', 'returned'], true)
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

                    <!-- עמודת ציוד (זהה בהזמנה חדשה ובעריכה) / פאנל ציוד נלווה למצלמה (מנהל) — עמודת הבחירה תמיד זמינה גם כשיש פאנל נלווה -->
                    <?php if (!($editingOrder && ($tab === 'not_picked' || $tab === 'not_returned'))): ?>
                    <?php if (!empty($showAdminCameraAccessoryPanel)): ?>
                    <div id="camera_accessory_panel" class="camera-accessory-panel">
                        <h3>ציוד נלווה למצלמה</h3>
                        <p class="muted-small" style="margin:0 0 0.5rem 0;">
                            מצלמה נבחרת מטבלת «החלפת ציוד» למטה. ניתן להחליף מצלמה — יוצגו המצלמות הפנויות לתאריכים שנבחרו.
                        </p>
                        <div id="camera_accessory_list_ui"></div>
                        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin-top:0.5rem;">
                            <button type="button" class="btn secondary camera-accessory-interactive" id="camera_accessory_add_btn" title="הוספת פריט נלווה">+</button>
                            <button type="button" class="btn camera-accessory-interactive" id="camera_accessory_copy_prev_btn">העתק פריטים מהזמנה קודמת</button>
                        </div>
                        <div id="camera_accessory_mirror_host" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true"></div>
                    </div>
                    <?php endif; ?>
                    <?php
                    $isNewOrderMode = !$editingOrder;
                    $equipmentColumnBodyHidden = !$isNewOrderMode && empty($showAdminCameraAccessoryPanel);
                    ?>
                    <div id="equipment_column">
                        <div class="equipment-column-header">
                            <label style="margin:0;"><?= $isNewOrderMode ? 'בחירת ציוד' : 'החלפת ציוד' ?></label>
                            <?php if (!$isNewOrderMode): ?>
                                <button type="button" id="toggle_equipment_column_btn" class="equipment-column-toggle-btn" aria-expanded="<?= $equipmentColumnBodyHidden ? 'false' : 'true' ?>">
                                    <?= $equipmentColumnBodyHidden ? 'הצג' : 'הסתר' ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div id="equipment_column_body" class="equipment-column-body <?= $equipmentColumnBodyHidden ? 'hidden' : '' ?>">
                            <?php
                            $hideEquipmentCategoryUi = ($role === 'student')
                                || ($tab === 'today' && $todayFormMode === 'prepare' && in_array($role, ['admin', 'warehouse_manager'], true));
                            $showEquipmentBarcodeSearch = ($tab === 'today' && $todayFormMode === 'prepare' && in_array($role, ['admin', 'warehouse_manager'], true));
                            ?>
                            <?php if (!$hideEquipmentCategoryUi): ?>
                            <label for="equipment_category_filter">קטגוריית ציוד</label>
                            <select id="equipment_category_filter">
                                <option value="all">כל הקטגוריות</option>
                                <?php foreach ($equipmentCategories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <label for="equipment_search_filter">חיפוש (שם / קוד)</label>
                            <input type="search" id="equipment_search_filter" autocomplete="off" placeholder="הקלד לסינון…" style="width:100%;max-width:18rem;">
                            <?php if ($showEquipmentBarcodeSearch): ?>
                            <label for="equipment_barcode_input" style="margin-top:0.35rem;">ברקוד</label>
                            <input type="text" id="equipment_barcode_input" autocomplete="off" inputmode="numeric" placeholder="סריקה או הקלדה" style="width:100%;max-width:18rem;">
                            <?php endif; ?>
                            <?php endif; ?>

                            <table>
                                <thead>
                                <tr>
                                    <th style="width:40px;">בחר</th>
                                    <th>שם הציוד</th>
                                    <th>קוד</th>
                                    <?php if (!$hideEquipmentCategoryUi): ?>
                                    <th>קטגוריה</th>
                                    <?php endif; ?>
                                </tr>
                                </thead>
                                <tbody id="equipment_table_body">
                                <?php foreach ($equipmentPickerRows as $item): ?>
                                    <?php
                                    $cat = trim((string)($item['category'] ?? ''));
                                    if ($cat === '') {
                                        $cat = 'ללא קטגוריה';
                                    }
                                    $isChecked = $editingOrder
                                        ? in_array((int)$item['id'], $editingEquipmentIds, true)
                                        : false;
                                    $searchBlob = $item['name'] . ' ' . $item['code'] . ' ' . $cat;
                                    if (function_exists('mb_strtolower')) {
                                        $searchBlob = mb_strtolower($searchBlob, 'UTF-8');
                                    } else {
                                        $searchBlob = strtolower($searchBlob);
                                    }
                                    ?>
                                    <tr data-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                        data-equipment-id="<?= (int)$item['id'] ?>"
                                        data-search-text="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
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
                                        <?php if (!$hideEquipmentCategoryUi): ?>
                                        <td class="muted-small"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="button" class="btn secondary" id="add_equipment_btn" style="margin-top:0.5rem;">
                                הוסף
                            </button>
                            <div class="muted-small" id="selected_equipment_summary" style="margin-top:0.3rem;"></div>
                            <?php if (!$isViewModeOrder): ?>
                            <div id="order_equipment_kit_wrap" class="order-equipment-kit-wrap" style="display:none;margin-top:0.55rem;">
                                <label style="display:flex;align-items:flex-start;gap:0.45rem;font-size:0.88rem;cursor:pointer;">
                                    <input type="checkbox" name="include_equipment_kit" value="1" id="include_equipment_kit"
                                           <?= !empty($orderKitCheckboxChecked) ? 'checked' : '' ?>
                                           <?= $isViewModeOrder ? 'disabled' : '' ?>
                                           style="margin-top:0.15rem;">
                                    <span><strong>ערכה</strong> — להזמין את המצלמה יחד עם ציוד הנלווה המוגדר לערכה</span>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
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
                <?php if (!$isViewModeOrder): ?>
                    <button type="submit" class="btn" id="submit_order_btn" disabled>
                        <?= $submitLabel ?>
                    </button>
                <?php endif; ?>
                <?php if ($editingOrder): ?>
                    <?php
                    $cancelUrl = 'admin_orders.php?tab=' . urlencode($tab);
                    ?>
                    <a href="<?= $cancelUrl ?>" class="btn secondary"><?= $isViewModeOrder ? 'סגירה' : 'ביטול' ?></a>
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

    <?php if (!empty($showAdminCameraAccessoryPanel)): ?>
    <div class="modal-backdrop" id="camera_accessory_search_modal" style="display:none;z-index:1200;" aria-hidden="true">
        <div class="modal-card camera-acc-modal-card" role="dialog" aria-modal="true" aria-labelledby="camera_acc_modal_title">
            <div class="modal-header">
                <h2 id="camera_acc_modal_title" style="margin:0;font-size:1.05rem;">הוספת ציוד נלווה</h2>
                <button type="button" class="modal-close camera-accessory-interactive" id="camera_accessory_modal_close" aria-label="סגירה"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>
            <label for="camera_accessory_search_input">חיפוש לפי שם או ברקוד</label>
            <input type="search" id="camera_accessory_search_input" class="camera-accessory-interactive" autocomplete="off" placeholder="הקלד…" style="width:100%;margin-bottom:0.5rem;">
            <div id="camera_accessory_search_results" style="max-height:12rem;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:0.35rem;margin-bottom:0.75rem;"></div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;">
                <button type="button" class="btn secondary camera-accessory-interactive" id="camera_accessory_modal_cancel">ביטול</button>
                <button type="button" class="btn camera-accessory-interactive" id="camera_accessory_modal_confirm">אישור</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="toolbar" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">
            <h2>רשימת הזמנות</h2>
        </div>
        <div class="tabs">
            <a href="admin_orders.php?tab=today"   class="<?= $tab === 'today'   ? 'active' : '' ?>">היום</a>
            <a href="admin_orders.php?tab=pending" class="<?= $tab === 'pending' ? 'active' : '' ?>">ממתין</a>
            <a href="admin_orders.php?tab=future"       class="<?= $tab === 'future'       ? 'active' : '' ?>">עתידי</a>
            <a href="admin_orders.php?tab=not_picked"   class="<?= $tab === 'not_picked'   ? 'active' : '' ?>">לא נלקח</a>
            <a href="admin_orders.php?tab=active"       class="<?= $tab === 'active'       ? 'active' : '' ?>">בהשאלה</a>
            <a href="admin_orders.php?tab=not_returned" class="<?= $tab === 'not_returned' ? 'active' : '' ?>">לא הוחזר</a>
            <a href="admin_orders.php?tab=history"      class="<?= $tab === 'history'      ? 'active' : '' ?>">היסטוריה</a>
            <a href="admin_orders.php?tab=rejected_deleted" class="<?= $tab === 'rejected_deleted' ? 'active' : '' ?>">נמחק / נדחה</a>
        </div>
        <?php if (count($orders) === 0): ?>
            <p class="muted-small">עדיין לא נוצרו הזמנות במערכת לטאב זה.</p>
        <?php else: ?>
            <div class="orders-table-wrapper" style="max-height:60vh;overflow-y:auto;border-radius:12px;">
                <?php if ($tab === 'today') {
                    $todaySortHref = static function (string $key, string $curBy, string $curDir): string {
                        $dir = 'asc';
                        if ($curBy === $key) {
                            $dir = $curDir === 'asc' ? 'desc' : 'asc';
                        }

                        return 'admin_orders.php?tab=today&sort=' . rawurlencode($key) . '&dir=' . rawurlencode($dir);
                    };
                } ?>
                <table class="orders-list-table<?= $tab === 'today' ? ' orders-list-table-today' : '' ?>">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>רצף מחזורי</th>
                        <th><?php if ($tab === 'today'): ?>
                            <a href="<?= htmlspecialchars($todaySortHref('borrower', $todaySortBy, $todaySortDir), ENT_QUOTES, 'UTF-8') ?>"
                               class="th-sort<?= $todaySortBy === 'borrower' ? ' active-sort' : '' ?>">שם המזמין<?= $todaySortBy === 'borrower' ? ($todaySortDir === 'asc' ? ' ↑' : ' ↓') : '' ?></a>
                        <?php else: ?>שם המזמין<?php endif; ?></th>
                        <th>שם הפריט</th>
                        <th><?php if ($tab === 'today'): ?>
                            <a href="<?= htmlspecialchars($todaySortHref('status', $todaySortBy, $todaySortDir), ENT_QUOTES, 'UTF-8') ?>"
                               class="th-sort<?= $todaySortBy === 'status' ? ' active-sort' : '' ?>">סטטוס<?= $todaySortBy === 'status' ? ($todaySortDir === 'asc' ? ' ↑' : ' ↓') : '' ?></a>
                        <?php else: ?>סטטוס<?php endif; ?></th>
                        <th><?php if ($tab === 'today'): ?>
                            <a href="<?= htmlspecialchars($todaySortHref('date', $todaySortBy, $todaySortDir), ENT_QUOTES, 'UTF-8') ?>"
                               class="th-sort<?= $todaySortBy === 'date' ? ' active-sort' : '' ?>">תאריך השאלה<?= $todaySortBy === 'date' ? ($todaySortDir === 'asc' ? ' ↑' : ' ↓') : '' ?></a>
                        <?php else: ?>תאריך השאלה<?php endif; ?></th>
                        <th><?php if ($tab === 'today'): ?>
                            <a href="<?= htmlspecialchars($todaySortHref('time', $todaySortBy, $todaySortDir), ENT_QUOTES, 'UTF-8') ?>"
                               class="th-sort<?= $todaySortBy === 'time' ? ' active-sort' : '' ?>">שעת השאלה<?= $todaySortBy === 'time' ? ($todaySortDir === 'asc' ? ' ↑' : ' ↓') : '' ?></a>
                        <?php else: ?>שעת השאלה<?php endif; ?></th>
                        <th>תאריך החזרה</th>
                        <th>קשר</th>
                        <th>טופס השאלה</th>
                        <th>הערות</th>
                        <th>פעולות</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        // צביעת שורות: בטאב "היום" לפי סטטוס; ביתר הטאבים – pending לפי קרבת מועד (כבעבר)
                        $rowHighlightClass = '';
                        $orderStatusCode = (string)($order['status'] ?? '');
                        if ($tab === 'today') {
                            if (in_array($orderStatusCode, ['pending', 'approved'], true)) {
                                $rowHighlightClass = 'row-today-future';
                            } elseif ($orderStatusCode === 'ready') {
                                $rowHighlightClass = 'row-today-ready';
                            } elseif ($orderStatusCode === 'on_loan') {
                                $rowHighlightClass = 'row-today-loan';
                            }
                        } elseif ($orderStatusCode === 'pending') {
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
                            <td class="muted-small"><?= !empty($order['recurring_series_id']) ? (int)$order['recurring_series_id'] : '—' ?></td>
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
                            if ($tab === 'today' && $todayFormMode === 'prepare') {
                                $componentsContext = 'prepare';
                            } elseif (($tab === 'today' && $todayFormMode === 'return') || $tab === 'not_returned') {
                                $componentsContext = 'return';
                            } else {
                                $componentsContext = 'loan';
                            }
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
                            <?php
                            $itemsCount = (int)($order['equipment_items_count'] ?? 0);
                            if ($itemsCount <= 0) $itemsCount = 1;
                            ?>
                            <?php if ($itemsCount > 1): ?>
                                <br>
                                <span class="muted-small"><?= $itemsCount ?> פריטים בהזמנה</span>
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

                            // קריאת התווית + צבע מטבלת order_status_labels אם קיימת
                            static $statusMap = null;
                            if ($statusMap === null) {
                                try {
                                    $stmtSL = $pdo->query('SELECT status, label_he, color_hex FROM order_status_labels');
                                    $statusMap = [];
                                    foreach ($stmtSL->fetchAll(PDO::FETCH_ASSOC) as $rowSL) {
                                        $statusMap[(string)($rowSL['status'] ?? '')] = [
                                            'label' => (string)($rowSL['label_he'] ?? ''),
                                            'color' => (string)($rowSL['color_hex'] ?? ''),
                                        ];
                                    }
                                } catch (Throwable $e) {
                                    $statusMap = [];
                                }
                            }
                            $statusColor = '';
                            if (isset($statusMap[$statusCode]) && is_array($statusMap[$statusCode])) {
                                if (!empty($statusMap[$statusCode]['label'])) {
                                    $statusLabel = (string)$statusMap[$statusCode]['label'];
                                }
                                $statusColor = (string)($statusMap[$statusCode]['color'] ?? '');
                                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $statusColor)) {
                                    $statusColor = '';
                                }
                            }

                            if ($statusCode === 'approved') {
                                $statusClass = 'status-approved';
                            } elseif ($statusCode === 'on_loan') {
                                $statusClass = 'status-approved';
                            } elseif ($statusCode === 'returned') {
                                $statusClass = 'status-returned';
                            } elseif ($statusCode === 'rejected') {
                                $statusClass = 'status-rejected';
                            } elseif ($statusCode === 'deleted') {
                                $statusClass = 'status-rejected';
                            } elseif ($statusCode === 'ready') {
                                $statusClass = 'status-ready';
                            }
                            ?>
                            <?php
                            $badgeStyle = '';
                            if ($statusColor !== '') {
                                $hex = ltrim($statusColor, '#');
                                $r = hexdec(substr($hex, 0, 2));
                                $g = hexdec(substr($hex, 2, 2));
                                $b = hexdec(substr($hex, 4, 2));
                                // luminance (simple) כדי לבחור צבע טקסט קריא
                                $l = (0.299 * $r + 0.587 * $g + 0.114 * $b);
                                $text = ($l < 140) ? '#ffffff' : '#111827';
                                $badgeStyle = 'background:' . $statusColor . ';color:' . $text . ';border:1px solid rgba(0,0,0,0.06);';
                            }
                            ?>
                            <span class="badge <?= $statusClass ?>" <?= $badgeStyle !== '' ? 'style="' . htmlspecialchars($badgeStyle, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="muted-small">
                            <?php
                            $startDateDisplay = (string)($order['start_date'] ?? '');
                            echo $startDateDisplay !== '' ? htmlspecialchars($startDateDisplay, ENT_QUOTES, 'UTF-8') : '—';
                            ?>
                        </td>
                        <td class="muted-small">
                            <?php
                            $startTimeDisplay = (string)($order['start_time'] ?? '');
                            echo $startTimeDisplay !== '' ? htmlspecialchars($startTimeDisplay, ENT_QUOTES, 'UTF-8') : '—';
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
                            <?php
                            $emC = trim((string)($order['borrower_user_email'] ?? ''));
                            $phC = trim((string)($order['borrower_user_phone'] ?? ''));
                            $bcC = trim((string)($order['borrower_contact'] ?? ''));
                            $splitC = gf_split_borrower_contact($bcC);
                            if ($emC === '' && $splitC['email'] !== '') {
                                $emC = $splitC['email'];
                            }
                            if ($phC === '' && $splitC['phone'] !== '') {
                                $phC = $splitC['phone'];
                            }
                            $mailtoSubRow = 'הזמנה #' . (int)$order['id'];
                            $outContact = [];
                            if ($emC !== '' && filter_var($emC, FILTER_VALIDATE_EMAIL)) {
                                $outContact[] = '<a href="' . htmlspecialchars(gf_mailto_href($emC, $mailtoSubRow), ENT_QUOTES, 'UTF-8') . '" style="color:#2563eb;font-weight:500;">'
                                    . htmlspecialchars($emC, ENT_QUOTES, 'UTF-8') . '</a>';
                            }
                            if ($phC !== '') {
                                $outContact[] = gf_html_phone_contact_menu($phC);
                            }
                            if ($outContact !== []) {
                                echo implode('<br>', $outContact);
                            } elseif ($bcC !== '') {
                                echo htmlspecialchars($bcC, ENT_QUOTES, 'UTF-8');
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td class="muted-small">
                            <?php
                            $todayYmd = date('Y-m-d');
                            $signatureFile = __DIR__ . '/signatures/order_' . (int)$order['id'] . '.png';
                            $hasSignatureRow = is_file($signatureFile);
                            $orderStatusRow = (string)($order['status'] ?? '');
                            $showAgreementLink = (
                                $hasSignatureRow
                                || in_array($orderStatusRow, ['ready', 'on_loan', 'returned'], true)
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
                            <?php
                            if (!empty($order['deletion_requested_at']) && in_array($role, ['admin', 'warehouse_manager'], true)): ?>
                                <div class="muted-small" style="margin-top:0.25rem;color:#b45309;">
                                    בקשת מחיקה: <?= htmlspecialchars((string)($order['deletion_request_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            if ($role === 'student') {
                                $pickupTsRow = gf_order_pickup_timestamp((string)($order['start_date'] ?? ''), (string)($order['start_time'] ?? ''));
                                $stSt = (string)($order['status'] ?? '');
                                $canEditStudent = in_array($stSt, ['pending', 'approved'], true)
                                    && $pickupTsRow !== false && $pickupTsRow > $nowTs;
                                $secsUntilPickup = ($pickupTsRow !== false) ? ($pickupTsRow - $nowTs) : -1;
                                $canDirectCancel = $canEditStudent && $secsUntilPickup > 48 * 3600;
                                $canRequestDeletion = $canEditStudent && $secsUntilPickup > 0 && $secsUntilPickup <= 48 * 3600;
                                ?>
                                    <div class="row-actions">
                                        <a href="admin_orders.php?view_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>" class="icon-btn" title="צפייה בהזמנה" aria-label="צפייה בהזמנה">
                                            <i data-lucide="eye" aria-hidden="true"></i>
                                        </a>
                                        <?php if ($canEditStudent): ?>
                                        <a href="admin_orders.php?edit_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>" class="icon-btn" title="עריכה" aria-label="עריכה"><i data-lucide="pencil" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                        <?php if ($canDirectCancel): ?>
                                            <div class="recurring-delete-wrap">
                                                <button type="button" class="icon-btn recurring-delete-btn" aria-expanded="false" aria-haspopup="true" title="ביטול הזמנה" aria-label="ביטול הזמנה">
                                                    <i data-lucide="trash-2" aria-hidden="true"></i>
                                                </button>
                                                <div class="recurring-delete-popover" hidden role="menu">
                                                    <form method="post" action="admin_orders.php" onsubmit="return (document.getElementById('cx_<?= (int)$order['id'] ?>').value.trim() !== '');">
                                                        <input type="hidden" name="action" value="student_cancel_order">
                                                        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                        <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?php if ($tab === 'today'): ?>
                                                            <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayFormMode, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?php endif; ?>
                                                        <label for="cx_<?= (int)$order['id'] ?>">סיבת ביטול</label>
                                                        <textarea id="cx_<?= (int)$order['id'] ?>" name="cancellation_reason" required rows="2" style="width:100%;max-width:14rem;"></textarea>
                                                        <button type="submit" class="btn small" style="margin-top:0.35rem;">שלח ביטול</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php elseif ($canRequestDeletion): ?>
                                            <div class="recurring-delete-wrap">
                                                <button type="button" class="icon-btn recurring-delete-btn" aria-expanded="false" aria-haspopup="true" title="בקשת מחיקה" aria-label="בקשת מחיקה">
                                                    <i data-lucide="trash-2" aria-hidden="true"></i>
                                                </button>
                                                <div class="recurring-delete-popover" hidden role="menu">
                                                    <form method="post" action="admin_orders.php" onsubmit="return (document.getElementById('dr_<?= (int)$order['id'] ?>').value.trim() !== '');">
                                                        <input type="hidden" name="action" value="student_deletion_request">
                                                        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                        <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?php if ($tab === 'today'): ?>
                                                            <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayFormMode, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?php endif; ?>
                                                        <label for="dr_<?= (int)$order['id'] ?>">סיבה (נשלח למנהל)</label>
                                                        <textarea id="dr_<?= (int)$order['id'] ?>" name="deletion_request_reason" required rows="2" style="width:100%;max-width:14rem;"></textarea>
                                                        <button type="submit" class="btn small" style="margin-top:0.35rem;">שלח בקשה</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                            <?php } else {
                                // למנהל/מנהל מחסן: פעולות בטאבים today, pending, future, active, not_picked, not_returned, rejected_deleted
                                // בטאב "לא נלקח" ו"לא הוחזר" אין צורך בשכפול.
                                $adminTabsAllowed = in_array($tab, ['today', 'pending', 'future', 'active', 'not_picked', 'not_returned', 'rejected_deleted'], true);
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

                                        <a href="admin_orders.php?view_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>" class="icon-btn" title="צפייה בהזמנה" aria-label="צפייה בהזמנה">
                                            <i data-lucide="eye" aria-hidden="true"></i>
                                        </a>

                                        <a href="admin_orders.php?edit_id=<?= (int)$order['id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>" class="icon-btn" title="עריכה" aria-label="עריכה"><i data-lucide="pencil" aria-hidden="true"></i></a>

                                        <?php if (($tab === 'today' && $todayFormMode === 'borrow') || (string)($order['status'] ?? '') === 'ready'): ?>
                                            <a href="order_print.php?id=<?= (int)$order['id'] ?>"
                                               class="icon-btn" title="הדפסת טופס הזמנה" aria-label="הדפסת טופס הזמנה"
                                               target="_blank" rel="noopener noreferrer">
                                                <i data-lucide="printer" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php
                                        $canDeleteAdmin = ((string)($order['status'] ?? '') !== 'deleted');
                                        if ($canDeleteAdmin):
                                            $recSeries = isset($order['recurring_series_id']) ? (int)$order['recurring_series_id'] : 0;
                                            ?>
                                            <?php if ($recSeries > 0): ?>
                                                <div class="recurring-delete-wrap">
                                                    <button type="button" class="icon-btn recurring-delete-btn" aria-expanded="false" aria-haspopup="true" title="מחיקה (הזמנה מחזורית)" aria-label="מחיקה">
                                                        <i data-lucide="trash-2" aria-hidden="true"></i>
                                                    </button>
                                                    <div class="recurring-delete-popover" hidden role="menu">
                                                        <form method="post" action="admin_orders.php" onsubmit="return confirm('לסמן רק את ההזמנה הנוכחית כנמחקה?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="delete_scope" value="single">
                                                            <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                            <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php if ($tab === 'today'): ?>
                                                                <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayFormMode, ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php endif; ?>
                                                            <button type="submit">מחק הזמנה זו</button>
                                                        </form>
                                                        <form method="post" action="admin_orders.php" onsubmit="return confirm('לסמן את כל ההזמנות ברצף המחזורי (מספר <?= (int)$recSeries ?>) כנמחקות?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="delete_scope" value="series">
                                                            <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                            <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php if ($tab === 'today'): ?>
                                                                <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayFormMode, ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php endif; ?>
                                                            <button type="submit">מחק את רצף ההזמנות</button>
                                                        </form>
                                                        <form method="post" action="admin_orders.php" onsubmit="return confirm('לסמן כנמחקות רק הזמנות ברצף <?= (int)$recSeries ?> שתאריך ההשאלה שלהן אחרי היום?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="delete_scope" value="future">
                                                            <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                            <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php if ($tab === 'today'): ?>
                                                                <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayFormMode, ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php endif; ?>
                                                            <button type="submit">מחק ההזמנות העתידיות</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                            <form method="post" action="admin_orders.php"
                                                  onsubmit="return confirm('לסמן את ההזמנה כנמחקה? (הרשומה תישאר במערכת)');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="delete_scope" value="single">
                                                <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                <input type="hidden" name="current_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                                                <?php if ($tab === 'today'): ?>
                                                    <input type="hidden" name="current_today_mode" value="<?= htmlspecialchars($todayFormMode, ENT_QUOTES, 'UTF-8') ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="icon-btn" title="מחיקה (סטטוס נמחק)" aria-label="מחיקה"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php
                                        $stRow = (string)($order['status'] ?? '');
                                        $nextRow = [];
                                        if ($stRow === 'pending') {
                                            $nextRow = [
                                                'approved' => 'מאושר',
                                                'rejected' => 'נדחה',
                                                'deleted'  => 'נמחק',
                                                'ready'    => 'מוכנה',
                                            ];
                                        } elseif ($stRow === 'approved') {
                                            if ($tab === 'not_picked') {
                                                $nextRow = ['returned' => 'הוחזר'];
                                            } else {
                                                $nextRow = [
                                                    'on_loan'  => 'בהשאלה',
                                                    'ready'    => 'מוכנה',
                                                    'rejected' => 'נדחה',
                                                    'deleted'  => 'נמחק',
                                                ];
                                            }
                                        } elseif ($stRow === 'ready') {
                                            $nextRow = [
                                                'on_loan'  => 'בהשאלה',
                                                'approved' => 'מאושר',
                                                'deleted'  => 'נמחק',
                                            ];
                                        } elseif ($stRow === 'on_loan') {
                                            $nextRow = ['returned' => 'הוחזר'];
                                        }
                                        foreach ($nextRow as $nv => $nl) {
                                            $dataConfirm = 'לעדכן את סטטוס ההזמנה?';
                                            if ($nv === 'deleted') {
                                                $dataConfirm = 'לסמן את ההזמנה כנמחקה?';
                                            } elseif ($nv === 'rejected') {
                                                $dataConfirm = 'לדחות את הבקשה?';
                                            }
                                            ?>
                                            <form method="post" action="admin_orders.php" class="order-status-quick-form" style="display:inline;"
                                                  data-reject="<?= $nv === 'rejected' ? '1' : '0' ?>"
                                                  data-confirm-msg="<?= htmlspecialchars($dataConfirm, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                                <input type="hidden" name="status" value="<?= htmlspecialchars($nv, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="rejection_reason" value="">
                                                <button type="submit" class="btn small neutral order-status-row-btn"><?= htmlspecialchars($nl, ENT_QUOTES, 'UTF-8') ?></button>
                                            </form>
                                        <?php } ?>
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
    const equipmentMainById = <?= $equipmentMainByIdJson ?>;
    const equipmentKitsMap = <?= $equipmentKitsMapJson ?>;

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest && e.target.closest('.gf-phone-trigger');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            var wrap = trigger.closest('.gf-phone-wrap');
            if (!wrap) {
                return;
            }
            var wasOpen = wrap.classList.contains('open');
            document.querySelectorAll('.gf-phone-wrap.open').forEach(function (w) {
                w.classList.remove('open');
                var b = w.querySelector('.gf-phone-trigger');
                if (b) {
                    b.setAttribute('aria-expanded', 'false');
                }
            });
            if (!wasOpen) {
                wrap.classList.add('open');
                trigger.setAttribute('aria-expanded', 'true');
            }
            return;
        }
        if (!e.target.closest || !e.target.closest('.gf-phone-wrap')) {
            document.querySelectorAll('.gf-phone-wrap.open').forEach(function (w) {
                w.classList.remove('open');
                var b = w.querySelector('.gf-phone-trigger');
                if (b) {
                    b.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('.gf-phone-wrap.open').forEach(function (w) {
            w.classList.remove('open');
            var b = w.querySelector('.gf-phone-trigger');
            if (b) {
                b.setAttribute('aria-expanded', 'false');
            }
        });
    });

    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');
    const equipmentSelect = document.getElementById('equipment_id'); // לא בשימוש – אזור ציוד מאוחד
    const equipmentIdHidden = document.getElementById('equipment_id_hidden'); // במצב עריכה
    function getEquipmentCheckboxes() {
        return Array.from(document.querySelectorAll('#order_modal input[name="equipment_ids[]"]'));
    }
    const categoryFilter = document.getElementById('equipment_category_filter');
    const equipmentSearchFilter = document.getElementById('equipment_search_filter');
    const equipmentBarcodeInput = document.getElementById('equipment_barcode_input');
    const equipmentTableBody = document.getElementById('equipment_table_body');
    const addEquipmentBtn = document.getElementById('add_equipment_btn');
    const selectedEquipmentSummary = document.getElementById('selected_equipment_summary');
    const selectedEquipmentList = document.getElementById('selected_equipment_list');
    const equipmentColumnBody = document.getElementById('equipment_column_body');
    const toggleEquipmentColumnBtn = document.getElementById('toggle_equipment_column_btn');
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
    const originalStartInput = document.querySelector('input[name="original_start_date"]');
    const originalEndInput = document.querySelector('input[name="original_end_date"]');
    const isDuplicateMode = !!(originalStartInput || originalEndInput);
    const hasEquipComponentsInput = document.getElementById('has_equipment_components');

    // שימור חזרתיות לטאב/מצב נוכחי בעת פתיחת חלון ההזמנה
    const currentTabInput = document.querySelector('input[name="current_tab"]');
    const currentTabValue = '<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>';
    const isViewModeOrder = <?= $isViewModeOrder ? 'true' : 'false' ?>;
    const showLoanReturnUi = <?= !empty($showPerItemReturnUi) ? 'true' : 'false' ?>;

    function buildReturnUrl() {
        let url = 'admin_orders.php?tab=' + encodeURIComponent(currentTabValue || 'today');
        if (currentTabValue === 'today') {
            try {
                const qs = new URLSearchParams(window.location.search);
                const s = qs.get('sort');
                const d = qs.get('dir');
                if (s && ['time', 'borrower', 'status'].indexOf(s) !== -1) {
                    url += '&sort=' + encodeURIComponent(s);
                    if (d === 'asc' || d === 'desc') {
                        url += '&dir=' + encodeURIComponent(d);
                    }
                }
            } catch (e) {
                // ignore
            }
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
        const allowedIds = new Set(['order_status', 'return_equipment_status', 'equipment_return_condition', 'toggle_equipment_column_btn', 'return_completeness']);
        const fields = orderModal.querySelectorAll('input, select, textarea, button');
        fields.forEach(function (el) {
            if (el.type === 'hidden') return;
            if (allowedIds.has(el.id)) return;
            if (el.classList && el.classList.contains('oe-return-field')) return;
            if (el.id === 'order_mark_returned_btn' || el.id === 'oe_select_all_btn' || el.id === 'oe_select_none_btn') return;
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
    const isEditFromTodayReturn = <?= ($editingOrder && $tab === 'today' && $todayFormMode === 'return') ? 'true' : 'false' ?>;
    if (isEditFromTodayReturn && orderModal) {
        const allowedIdsTodayReturn = new Set(['order_status', 'equipment_return_condition', 'toggle_equipment_column_btn', 'return_completeness']);
        const fieldsToday = orderModal.querySelectorAll('input, select, textarea, button');
        fieldsToday.forEach(function (el) {
            if (el.type === 'hidden') return;
            if (allowedIdsTodayReturn.has(el.id)) return;
            if (el.classList && el.classList.contains('oe-return-field')) return;
            if (el.id === 'order_mark_returned_btn' || el.id === 'oe_select_all_btn' || el.id === 'oe_select_none_btn') return;
            if (el === orderModalClose || el === orderModalCancel || el.id === 'submit_order_btn') return;
            el.disabled = true;
        });
    }

    const isEditFromTodayPrepare = <?= ($editingOrder && $tab === 'today' && $todayFormMode === 'prepare' && !$isViewModeOrder) ? 'true' : 'false' ?>;
    const showEquipmentPreparedChecklist = <?= ($editingOrder
        && $tab === 'today'
        && $todayFormMode === 'prepare'
        && in_array((string)($editingOrder['status'] ?? ''), ['pending', 'approved'], true)
        && !$isViewModeOrder
        && $role !== 'student') ? 'true' : 'false' ?>;
    const preparedInitiallyChecked = <?= (!empty($editingOrder['equipment_prepared'])) ? 'true' : 'false' ?>;
    if (isEditFromTodayPrepare && orderModal) {
        const allowedIdsTodayPrepare = new Set(['order_status', 'equipment_prepared', 'rejection_reason', 'admin_notes', 'toggle_equipment_column_btn', 'include_equipment_kit']);
        const fieldsPrep = orderModal.querySelectorAll('input, select, textarea, button');
        fieldsPrep.forEach(function (el) {
            if (el.type === 'hidden') return;
            if (allowedIdsTodayPrepare.has(el.id)) return;
            if (el.name === 'equipment_ids[]') return;
            if (el.id === 'equipment_search_filter' || el.id === 'equipment_barcode_input') return;
            if (el.id === 'add_equipment_btn') return;
            if (el.classList && el.classList.contains('equipment-components-link')) return;
            if (el.classList && el.classList.contains('equipment-prepared-item-check')) return;
            if (el.classList && el.classList.contains('camera-accessory-interactive')) return;
            if (el.classList && el.classList.contains('equipment-list-trash')) return;
            if (el === orderModalClose || el === orderModalCancel || el.id === 'submit_order_btn') return;
            el.disabled = true;
        });
    }

    <?php if (!empty($showAdminCameraAccessoryPanel)): ?>
    (function initCameraAccessoryPanel() {
        var primaryId = <?= (int)($editingOrder['equipment_id'] ?? 0) ?>;
        var lastCameraIdForAccessories = primaryId;
        var orderIdAjax = <?= (int)($editingOrder['id'] ?? 0) ?>;
        var catalog = <?= $accessoryCatalogJson ?>;
        var meta = <?= $equipmentMetaForCameraJson ?>;
        var accessorySet = new Set(<?= json_encode($initialCameraAccessoryIds, JSON_UNESCAPED_UNICODE) ?>);
        var pendingPickId = 0;
        var host = document.getElementById('camera_accessory_mirror_host');
        var listUi = document.getElementById('camera_accessory_list_ui');
        var modalBackdrop = document.getElementById('camera_accessory_search_modal');
        var searchInput = document.getElementById('camera_accessory_search_input');
        var searchResults = document.getElementById('camera_accessory_search_results');

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function getTablePrimaryCameraId() {
            var tbody = document.getElementById('equipment_table_body');
            if (!tbody) {
                return 0;
            }
            var cbs = tbody.querySelectorAll('input[name="equipment_ids[]"]');
            var found = 0;
            cbs.forEach(function (inp) {
                if (!inp.checked) return;
                var id = parseInt(inp.value, 10);
                if (!id) return;
                if (equipmentMainById[id] === 'מצלמות') {
                    found = id;
                }
            });
            return found;
        }

        function syncMirror() {
            if (!host) return;
            host.innerHTML = '';
            Array.from(accessorySet).forEach(function (id) {
                if (!id || id < 1) return;
                var m = meta[id] || { name: '#' + id, code: '' };
                var inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.name = 'equipment_ids[]';
                inp.value = String(id);
                inp.checked = true;
                inp.setAttribute('data-name', m.name || '');
                inp.setAttribute('data-code', m.code || '');
                host.appendChild(inp);
            });
        }

        function renderList() {
            if (!listUi) return;
            listUi.innerHTML = '';
            var camId = getTablePrimaryCameraId();
            var cam = { name: '', code: '' };
            if (camId && equipmentTableBody) {
                var inpCam = equipmentTableBody.querySelector('input[name="equipment_ids[]"][value="' + String(camId) + '"]');
                if (inpCam) {
                    cam.name = inpCam.getAttribute('data-name') || '';
                    cam.code = inpCam.getAttribute('data-code') || '';
                }
            }
            if (!cam.name && meta[camId]) {
                cam = meta[camId];
            }
            var row0 = document.createElement('div');
            row0.className = 'camera-accessory-row';
            row0.innerHTML = '<span><strong>מצלמה</strong> — ' + esc(cam.name || '') + (cam.code ? ' (' + esc(cam.code) + ')' : '') + '</span><span></span>';
            listUi.appendChild(row0);
            Array.from(accessorySet).sort(function (a, b) {
                var na = (meta[a] && meta[a].name) ? meta[a].name : '';
                var nb = (meta[b] && meta[b].name) ? meta[b].name : '';
                return na.localeCompare(nb, 'he');
            }).forEach(function (aid) {
                var m = meta[aid] || { name: '#' + aid, code: '' };
                var row = document.createElement('div');
                row.className = 'camera-accessory-row';
                row.setAttribute('data-acc-id', String(aid));
                row.innerHTML = '<span>' + esc(m.name) + (m.code ? ' <span class="muted-small">(' + esc(m.code) + ')</span>' : '') + '</span>' +
                    '<button type="button" class="icon-btn camera-accessory-interactive camera-acc-trash" data-remove-id="' + aid + '" aria-label="הסר"><i data-lucide="trash-2" aria-hidden="true"></i></button>';
                listUi.appendChild(row);
            });
            if (window.lucide) lucide.createIcons();
        }

        function refreshEquipmentUi() {
            syncMirror();
            renderList();
            if (typeof updateSelectedEquipmentSummary === 'function') updateSelectedEquipmentSummary();
            if (typeof updateEquipmentState === 'function') updateEquipmentState();
        }

        function openModal() {
            if (modalBackdrop) {
                modalBackdrop.style.display = 'flex';
                modalBackdrop.setAttribute('aria-hidden', 'false');
            }
            pendingPickId = 0;
            if (searchInput) searchInput.value = '';
            if (searchResults) searchResults.innerHTML = '';
            filterCatalog();
            if (searchInput) searchInput.focus();
        }

        function closeModal() {
            if (modalBackdrop) {
                modalBackdrop.style.display = 'none';
                modalBackdrop.setAttribute('aria-hidden', 'true');
            }
        }

        function filterCatalog() {
            if (!searchResults || !Array.isArray(catalog)) return;
            var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
            searchResults.innerHTML = '';
            var picked = 0;
            catalog.forEach(function (it) {
                if (!it || accessorySet.has(it.id) || it.id === getTablePrimaryCameraId()) return;
                var blob = ((it.name || '') + ' ' + (it.code || '')).toLowerCase();
                if (q && blob.indexOf(q) === -1) return;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'camera-accessory-interactive';
                btn.style.cssText = 'display:block;width:100%;text-align:right;padding:0.35rem 0.5rem;border:none;background:transparent;cursor:pointer;font:inherit;';
                btn.setAttribute('data-pick-id', String(it.id));
                btn.textContent = (it.name || '') + (it.code ? ' (' + it.code + ')' : '');
                searchResults.appendChild(btn);
                if (!picked && q && (String(it.code || '').toLowerCase() === q || String(it.code || '') === q)) {
                    picked = it.id;
                }
            });
            if (q && picked) pendingPickId = picked;
        }

        document.getElementById('camera_accessory_add_btn') && document.getElementById('camera_accessory_add_btn').addEventListener('click', openModal);
        document.getElementById('camera_accessory_modal_close') && document.getElementById('camera_accessory_modal_close').addEventListener('click', closeModal);
        document.getElementById('camera_accessory_modal_cancel') && document.getElementById('camera_accessory_modal_cancel').addEventListener('click', closeModal);
        if (modalBackdrop) {
            modalBackdrop.addEventListener('click', function (e) {
                if (e.target === modalBackdrop) closeModal();
            });
        }
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                pendingPickId = 0;
                filterCatalog();
            });
        }
        if (searchResults) {
            searchResults.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('button[data-pick-id]') : null;
                if (!btn) return;
                var bid = btn.getAttribute('data-pick-id');
                if (!bid) return;
                pendingPickId = parseInt(bid, 10) || 0;
            });
        }
        document.getElementById('camera_accessory_modal_confirm') && document.getElementById('camera_accessory_modal_confirm').addEventListener('click', function () {
            var toAdd = pendingPickId;
            if (!toAdd && searchInput) {
                var v = searchInput.value.trim();
                if (v) {
                    for (var i = 0; i < catalog.length; i++) {
                        var it = catalog[i];
                        if (!it || accessorySet.has(it.id) || it.id === getTablePrimaryCameraId()) continue;
                        if (String(it.code || '') === v) {
                            toAdd = it.id;
                            break;
                        }
                    }
                }
            }
            if (toAdd > 0 && !accessorySet.has(toAdd) && toAdd !== getTablePrimaryCameraId()) {
                accessorySet.add(toAdd);
                if (!meta[toAdd]) {
                    var found = catalog.filter(function (x) { return x && x.id === toAdd; })[0];
                    if (found) meta[toAdd] = { name: found.name || '', code: found.code || '' };
                }
            }
            closeModal();
            refreshEquipmentUi();
        });
        document.getElementById('camera_accessory_copy_prev_btn') && document.getElementById('camera_accessory_copy_prev_btn').addEventListener('click', function () {
            var camForAjax = getTablePrimaryCameraId();
            if (camForAjax < 1) {
                camForAjax = primaryId;
            }
            if (camForAjax < 1) {
                alert('יש לבחור מצלמה בטבלת «החלפת ציוד» לפני העתקה מהזמנות קודמות.');
                return;
            }
            var u = 'admin_orders.php?ajax=prev_accessories&order_id=' + orderIdAjax + '&camera_equipment_id=' + camForAjax;
            fetch(u, { credentials: 'same-origin' }).then(function (r) {
                if (r.status === 403) {
                    throw new Error('forbidden');
                }
                return r.json();
            }).then(function (data) {
                if (!data || !data.ok) {
                    alert('לא ניתן לטעון הזמנות קודמות. נסו שוב או בדקו הרשאות.');
                    return;
                }
                var list = data.items || [];
                if (list.length === 0) {
                    alert('לא נמצאו פריטי ציוד נלווה מהזמנות קודמות של אותו שואל (או שאין הזמנות קודמות עם שורות ציוד נלווה).');
                    return;
                }
                var camNow = getTablePrimaryCameraId() || primaryId;
                var added = 0;
                list.forEach(function (it) {
                    if (!it || !it.id || it.id === camNow || accessorySet.has(it.id)) return;
                    accessorySet.add(it.id);
                    meta[it.id] = { name: it.name || '', code: it.code || '' };
                    added++;
                });
                refreshEquipmentUi();
                if (added === 0) {
                    alert('כל הפריטים מההזמנות הקודמות כבר מסומנים בהזמנה הנוכחית.');
                }
            }).catch(function () {
                alert('שגיאה בטעינת נתונים מהשרת.');
            });
        });
        if (listUi) {
            listUi.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('.camera-acc-trash') : null;
                if (!btn) return;
                var rid = parseInt(btn.getAttribute('data-remove-id') || '0', 10);
                if (rid > 0) accessorySet.delete(rid);
                refreshEquipmentUi();
            });
        }
        window.gfCameraAccessoryOnEquipmentChange = function () {
            var camNow = getTablePrimaryCameraId();
            if (camNow !== lastCameraIdForAccessories) {
                accessorySet.clear();
                lastCameraIdForAccessories = camNow;
            }
            refreshEquipmentUi();
        };
        refreshEquipmentUi();
    })();
    <?php endif; ?>

    if (orderModal) {
        orderModal.addEventListener('change', function (e) {
            var t = e.target;
            if (t && t.name === 'equipment_ids[]') {
                updateEquipmentState();
                updateOrderKitCheckboxVisibility();
                updateSelectedEquipmentSummary();
                if (typeof window.gfCameraAccessoryOnEquipmentChange === 'function') {
                    window.gfCameraAccessoryOnEquipmentChange();
                }
            }
            if (t && t.id === 'include_equipment_kit') {
                updateOrderKitCheckboxVisibility();
            }
        });
    }

    if (toggleEquipmentColumnBtn && equipmentColumnBody) {
        toggleEquipmentColumnBtn.addEventListener('click', function () {
            const hidden = equipmentColumnBody.classList.contains('hidden');
            if (hidden) {
                equipmentColumnBody.classList.remove('hidden');
                toggleEquipmentColumnBtn.textContent = 'הסתר';
                toggleEquipmentColumnBtn.setAttribute('aria-expanded', 'true');
            } else {
                equipmentColumnBody.classList.add('hidden');
                toggleEquipmentColumnBtn.textContent = 'הצג';
                toggleEquipmentColumnBtn.setAttribute('aria-expanded', 'false');
            }
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

    const warehouseAlwaysOpen = <?= $warehouseAlwaysOpen ? 'true' : 'false' ?>;

    function isDisabledDay(date) {
        if (warehouseAlwaysOpen) {
            return false;
        }
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
        return getEquipmentCheckboxes().some(function (cb) { return cb.checked; });
    }

    function hasPrimaryBorrowerEquipmentSelected() {
        return getEquipmentCheckboxes().some(function (cb) {
            if (!cb.checked) {
                return false;
            }
            const id = parseInt(cb.value, 10);
            if (!id) {
                return false;
            }
            const m = equipmentMainById[id];
            return m === 'מצלמות' || m === 'חדרי עריכה';
        });
    }

    function updateOrderKitCheckboxVisibility() {
        const wrap = document.getElementById('order_equipment_kit_wrap');
        const kitCb = document.getElementById('include_equipment_kit');
        if (!wrap || !kitCb) {
            return;
        }
        const checked = getEquipmentCheckboxes().filter(function (cb) { return cb.checked; });
        let camId = 0;
        checked.forEach(function (inp) {
            const id = parseInt(inp.value, 10);
            if (!id) {
                return;
            }
            const main = equipmentMainById[id];
            if (main === 'מצלמות') {
                camId = id;
            }
        });
        const kit = equipmentKitsMap[camId] || equipmentKitsMap[String(camId)];
        if (camId > 0 && kit && kit.length) {
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
            if (!kitCb.disabled) {
                kitCb.checked = false;
            }
        }
    }

    function updateSelectedEquipmentSummary() {
        const checked = getEquipmentCheckboxes().filter(function (cb) { return cb.checked; });

        // במצבים שבהם רשימת החלפת ציוד לא מוצגת (למשל עריכה בטאבים מסוימים),
        // שומרים את רשימת "ציוד נבחר" שכבר נטענה מהשרת ולא מוחקים אותה.
        if (getEquipmentCheckboxes().length === 0) {
            if (submitBtn && !isViewModeOrder) {
                submitBtn.disabled = true;
            }
            updateOrderKitCheckboxVisibility();
            return;
        }

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

                const labelText = name + (code ? ' (' + code + ')' : '');
                let label;
                if (showEquipmentPreparedChecklist) {
                    label = document.createElement('label');
                    label.className = 'selected-equipment-checklist-item';
                    label.style.flex = '1';
                    const itemCheck = document.createElement('input');
                    itemCheck.type = 'checkbox';
                    if (id === (equipmentIdHidden ? equipmentIdHidden.value : '')) {
                        itemCheck.id = 'equipment_prepared';
                        itemCheck.name = 'equipment_prepared';
                        itemCheck.value = '1';
                    } else {
                        itemCheck.className = 'equipment-prepared-item-check';
                    }
                    if (showEquipmentPreparedChecklist) itemCheck.checked = preparedInitiallyChecked;
                    const labelSpan = document.createElement('span');
                    labelSpan.textContent = labelText;
                    label.appendChild(itemCheck);
                    label.appendChild(labelSpan);
                } else {
                    label = document.createElement('span');
                    label.textContent = labelText;
                }

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
        updateOrderKitCheckboxVisibility();
    }

    if (selectedEquipmentList) {
        selectedEquipmentList.addEventListener('change', function (e) {
            const target = e.target;
            if (!target || !(target instanceof HTMLInputElement) || target.type !== 'checkbox') return;
            if (!showEquipmentPreparedChecklist) return;
            const allChecks = Array.from(selectedEquipmentList.querySelectorAll('input[type="checkbox"]'));
            if (allChecks.length === 0) return;
            const master = selectedEquipmentList.querySelector('#equipment_prepared');
            if (!master || !(master instanceof HTMLInputElement)) return;
            if (target === master) {
                allChecks.forEach(function (cb) { cb.checked = master.checked; });
                return;
            }
            master.checked = allChecks.every(function (cb) { return cb.checked; });
        });
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
        const searchQ = equipmentSearchFilter ? equipmentSearchFilter.value.trim() : '';

        getEquipmentCheckboxes().forEach(function (cb) {
            cb.disabled = !datesReady;
        });
        if (equipmentTableBody) {
            const rows = equipmentTableBody.querySelectorAll('tr');
            const useAvailability = !recurringReady;
            rows.forEach(function (row) {
                const cat = row.getAttribute('data-category');
                const eqId = parseInt(row.getAttribute('data-equipment-id'), 10);
                const categoryMatch = !categoryFilter ? true : (categoryValue === 'all' || categoryValue === cat);
                const blob = row.getAttribute('data-search-text') || '';
                const searchMatch = !searchQ ? true : (blob.indexOf(searchQ.toLowerCase()) !== -1);
                const available = useAvailability ? (unavailableEquipmentIds.indexOf(eqId) === -1) : true;
                // מציגים את רשימת הציוד גם לפני בחירת תאריכים (לפי בקשה),
                // אך מנטרלים בחירה בפועל עד שהטווח מוכן.
                const show = categoryMatch && searchMatch && (datesReady ? available : true);
                row.style.display = show ? '' : 'none';
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.disabled = !datesReady || !available;
                    if (!available) checkbox.checked = false;
                }
            });
        }

        const hasEquip = hasPrimaryBorrowerEquipmentSelected();
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
            if (statusVal === 'returned' && !showLoanReturnUi) {
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
        const startHour = warehouseAlwaysOpen ? 0 : 7;
        const endHour = warehouseAlwaysOpen ? 23 : 22;
        for (let h = startHour; h <= endHour; h++) {
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
                const cb = getEquipmentCheckboxes().find(function (input) { return input.value === id; });
                if (cb) cb.checked = false;
            }
            updateSelectedEquipmentSummary();
            updateEquipmentState();
        });
    }

    // סינון טבלת הציוד לפי קטגוריה / חיפוש טקסט
    if (categoryFilter && equipmentTableBody) {
        categoryFilter.addEventListener('change', function () {
            applyEquipmentVisibility();
        });
    }
    if (equipmentSearchFilter && equipmentTableBody) {
        equipmentSearchFilter.addEventListener('input', function () {
            applyEquipmentVisibility();
        });
    }
    if (equipmentBarcodeInput && equipmentTableBody) {
        equipmentBarcodeInput.addEventListener('change', function () {
            const v = equipmentBarcodeInput.value.trim();
            if (!v) {
                return;
            }
            const rows = equipmentTableBody.querySelectorAll('tr');
            rows.forEach(function (row) {
                const cb = row.querySelector('input[type="checkbox"][name="equipment_ids[]"]');
                if (!cb) {
                    return;
                }
                const code = cb.getAttribute('data-code') || '';
                if (code === v) {
                    if (!cb.disabled) {
                        cb.checked = true;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    row.scrollIntoView({ block: 'nearest' });
                }
            });
            equipmentBarcodeInput.value = '';
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
            const disabled = warehouseAlwaysOpen ? false : (cellDate.getDay() === 5 || cellDate.getDay() === 6 || cellDate < today);
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
    if (isDailyPrefillNew && prefillEquipmentId && getEquipmentCheckboxes().length) {
        const cb = getEquipmentCheckboxes().find(function (x) { return String(x.value) === String(prefillEquipmentId); });
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

    // הצגת/הסתרת שדה "סיבה לדחייה" בהתאם למצב הזמנה (כפתורי סטטוס / שדה נסתר)
    if (orderStatusSelect && rejectionWrapper) {
        function updateRejectionVisibility() {
            rejectionWrapper.style.display = orderStatusSelect.value === 'rejected' ? 'block' : 'none';
        }
        document.querySelectorAll('.order-status-set-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const v = btn.getAttribute('data-status') || '';
                orderStatusSelect.value = v;
                updateRejectionVisibility();
            });
        });
        updateRejectionVisibility();
    }

    (function initLoanReturnRowUi() {
        if (!showLoanReturnUi || !orderModal) return;
        var form = orderModal.querySelector('form');
        if (!form) return;
        function syncLoanReturnUi() {
            var cbs = form.querySelectorAll('.oe-returned-cb');
            var n = 0;
            var t = cbs.length;
            cbs.forEach(function (cb) {
                if (cb.checked) n++;
            });
            var markBtn = document.getElementById('order_mark_returned_btn');
            var retStatusBtns = form.querySelectorAll('.order-status-set-btn[data-status="returned"]');
            retStatusBtns.forEach(function (b) {
                b.disabled = n < 1;
            });
            if (markBtn) markBtn.disabled = n < 1;
            var rc = document.getElementById('return_completeness');
            if (rc && t > 0) {
                if (n >= t) rc.value = 'full';
                else if (n > 0) rc.value = 'partial';
                else rc.value = '';
            }
            var conds = [];
            form.querySelectorAll('.selected-equipment-row').forEach(function (row) {
                var cb = row.querySelector('.oe-returned-cb');
                var sel = row.querySelector('.oe-condition-select');
                if (cb && cb.checked && sel) conds.push(sel.value);
            });
            var agg = 'תקין';
            if (conds.indexOf('תקול') !== -1) agg = 'תקול';
            else if (conds.indexOf('חסר') !== -1) agg = 'חסר';
            var hid = document.getElementById('equipment_return_condition');
            if (hid) hid.value = agg;
            var disp = document.getElementById('equipment_return_condition_display');
            if (disp) {
                disp.textContent = agg === 'תקין' ? 'תקין' : (agg === 'תקול' ? 'לא תקין' : 'חסר');
            }
        }
        document.getElementById('oe_select_all_btn') && document.getElementById('oe_select_all_btn').addEventListener('click', function () {
            form.querySelectorAll('.oe-returned-cb').forEach(function (cb) {
                cb.checked = true;
            });
            syncLoanReturnUi();
        });
        document.getElementById('oe_select_none_btn') && document.getElementById('oe_select_none_btn').addEventListener('click', function () {
            form.querySelectorAll('.oe-returned-cb').forEach(function (cb) {
                cb.checked = false;
            });
            syncLoanReturnUi();
        });
        form.querySelectorAll('.oe-returned-cb, .oe-condition-select').forEach(function (el) {
            el.addEventListener('change', syncLoanReturnUi);
        });
        document.getElementById('order_mark_returned_btn') && document.getElementById('order_mark_returned_btn').addEventListener('click', function () {
            var os = document.getElementById('order_status');
            if (os) os.value = 'returned';
            form.submit();
        });
        syncLoanReturnUi();
    })();

    document.querySelectorAll('form.order-status-quick-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.getAttribute('data-reject') === '1') {
                const r = window.prompt('סיבת דחייה (אופציונלי):');
                if (r === null) {
                    e.preventDefault();
                    return;
                }
                const inp = form.querySelector('input[name="rejection_reason"]');
                if (inp) {
                    inp.value = r || '';
                }
            }
            const msg = form.getAttribute('data-confirm-msg') || '';
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
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

                            if (context === 'loan' || context === 'prepare') {
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
                    if (context === 'loan' || context === 'prepare') {
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
<script>
(function () {
    document.querySelectorAll('.recurring-delete-popover').forEach(function (pop) {
        pop.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.querySelectorAll('.recurring-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var wrap = btn.closest('.recurring-delete-wrap');
            var pop = wrap ? wrap.querySelector('.recurring-delete-popover') : null;
            if (!pop) return;
            var wasHidden = pop.hasAttribute('hidden');
            document.querySelectorAll('.recurring-delete-popover').forEach(function (p) {
                p.setAttribute('hidden', 'hidden');
            });
            document.querySelectorAll('.recurring-delete-btn').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
            if (wasHidden) {
                pop.removeAttribute('hidden');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.recurring-delete-popover').forEach(function (p) {
            p.setAttribute('hidden', 'hidden');
        });
        document.querySelectorAll('.recurring-delete-btn').forEach(function (b) {
            b.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>
<?php include __DIR__ . '/admin_footer.php'; ?>
</body>
</html>
