<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$pdo = get_db();
$error = '';
$success = '';
$editingEquipment = null;
$componentsEquipment = null;
$componentsList = [];

// Handle edit mode
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM equipment WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editingEquipment = $stmt->fetch() ?: null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name         = trim($_POST['name'] ?? '');
        $code         = trim($_POST['code'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $category     = trim($_POST['category'] ?? '');
        $location     = trim($_POST['location'] ?? '');
        $status       = $_POST['status'] ?? 'active';

        // ניהול תמונה: שמירה / מחיקה / החלפה של קובץ שהועלה לשרת
        $picturePath = '';
        if ($id > 0 && $editingEquipment !== null) {
            $picturePath = (string)($editingEquipment['picture'] ?? '');
        }
        // מחיקת תמונה קיימת לפי בקשת המשתמש
        $deletePicture = isset($_POST['delete_picture']) && $_POST['delete_picture'] === '1';
        if ($deletePicture && $picturePath !== '') {
            $fullOld = __DIR__ . '/' . ltrim($picturePath, '/\\');
            if (is_file($fullOld)) {
                @unlink($fullOld);
            }
            $picturePath = '';
        }
        if (isset($_FILES['picture_file']) && is_array($_FILES['picture_file']) && ($_FILES['picture_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpName = (string)($_FILES['picture_file']['tmp_name'] ?? '');
            $origName = (string)($_FILES['picture_file']['name'] ?? '');
            if ($tmpName !== '' && is_uploaded_file($tmpName)) {
                $uploadDir = __DIR__ . '/equipment_images';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                $ext = pathinfo($origName, PATHINFO_EXTENSION);
                $ext = $ext !== '' ? ('.' . strtolower($ext)) : '';
                try {
                    $random = bin2hex(random_bytes(4));
                } catch (Throwable $e) {
                    $random = (string)mt_rand(1000, 9999);
                }
                $fileName = 'eq_' . time() . '_' . $random . $ext;
                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                if (move_uploaded_file($tmpName, $targetPath)) {
                    // נשמור נתיב יחסי שנטען דרך הווב-סרבר
                    $picturePath = 'equipment_images/' . $fileName;
                } else {
                    $error = $error !== '' ? $error : 'שגיאה בהעלאת התמונה. ניתן לנסות שוב.';
                }
            }
        }

        // quantities are now internal only – keep previous values if editing, or default to 1
        if ($id > 0 && $editingEquipment !== null) {
            $quantityTotal     = (int)($editingEquipment['quantity_total'] ?? 1);
            $quantityAvailable = (int)($editingEquipment['quantity_available'] ?? 1);
        } else {
            $quantityTotal     = 1;
            $quantityAvailable = 1;
        }

        if ($name === '' || $code === '') {
            $error = 'יש למלא שם ציוד וקוד זיהוי.';
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE equipment
                         SET name = :name,
                             code = :code,
                             description = :description,
                             category = :category,
                             location = :location,
                             quantity_total = :quantity_total,
                             quantity_available = :quantity_available,
                             status = :status,
                             picture = :picture,
                             updated_at = :updated_at
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':name'              => $name,
                        ':code'              => $code,
                        ':description'        => $description,
                        ':category'           => $category,
                        ':location'           => $location,
                        ':quantity_total'     => $quantityTotal,
                        ':quantity_available' => $quantityAvailable,
                        ':status'             => $status,
                        ':picture'            => $picturePath,
                        ':updated_at'         => date('Y-m-d H:i:s'),
                        ':id'                 => $id,
                    ]);
                    $success = 'הציוד עודכן בהצלחה.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO equipment
                         (name, code, description, category, location, quantity_total, quantity_available, status, picture, created_at)
                         VALUES
                         (:name, :code, :description, :category, :location, :quantity_total, :quantity_available, :status, :picture, :created_at)'
                    );
                    $stmt->execute([
                        ':name'               => $name,
                        ':code'               => $code,
                        ':description'        => $description,
                        ':category'           => $category,
                        ':location'           => $location,
                        ':quantity_total'     => $quantityTotal,
                        ':quantity_available' => $quantityAvailable,
                        ':status'             => $status,
                        ':picture'            => $picturePath,
                        ':created_at'         => date('Y-m-d H:i:s'),
                    ]);
                    $success = 'הציוד נוסף בהצלחה.';
                }
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE') && str_contains($e->getMessage(), 'code')) {
                    $error = 'קוד הציוד כבר קיים במערכת.';
                } else {
                    $error = 'שגיאה בשמירת הציוד.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM equipment WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $success = 'הציוד נמחק.';
        }
    } elseif ($action === 'import') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'קובץ CSV לא הועלה בהצלחה.';
        } else {
            $tmpPath = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($tmpPath, 'r');
            if ($handle === false) {
                $error = 'לא ניתן לקרוא את קובץ ה-CSV.';
            } else {
                $header = fgetcsv($handle);
                if ($header === false) {
                    $error = 'קובץ CSV ריק.';
                    fclose($handle);
                } else {
                    // מיפוי שם עמודה -> אינדקס כך שהסדר לא משנה
                    $map = [];
                    foreach ($header as $idx => $col) {
                        $key = strtolower(trim((string)$col));
                        if ($key !== '') {
                            $map[$key] = $idx;
                        }
                    }

                    $insert = $pdo->prepare(
                        'INSERT INTO equipment
                         (name, code, description, category, location, quantity_total, quantity_available, status, created_at)
                         VALUES
                         (:name, :code, :description, :category, :location, :quantity_total, :quantity_available, :status, :created_at)'
                    );

                    $imported = 0;
                    $skippedDuplicates = 0;

                    while (($row = fgetcsv($handle)) !== false) {
                        $get = function (string $key) use ($map, $row): string {
                            if (!isset($map[$key])) {
                                return '';
                            }
                            $i = $map[$key];
                            return isset($row[$i]) ? trim((string)$row[$i]) : '';
                        };

                        $name        = $get('name');
                        $code        = $get('code');
                        $description = $get('description');
                        $category    = $get('category');
                        $location    = $get('location');
                        $status      = $get('status') ?: 'active';

                        if ($name === '' || $code === '') {
                            continue;
                        }

                        try {
                            $insert->execute([
                                ':name'               => $name,
                                ':code'               => $code,
                                ':description'        => $description,
                                ':category'           => $category,
                                ':location'           => $location,
                                ':quantity_total'     => 1,
                                ':quantity_available' => 1,
                                ':status'             => $status,
                                ':created_at'         => date('Y-m-d H:i:s'),
                            ]);
                            $imported++;
                        } catch (PDOException $e) {
                            // If code already exists (UNIQUE constraint), skip this row silently
                            if (str_contains($e->getMessage(), 'UNIQUE') && str_contains($e->getMessage(), 'code')) {
                                $skippedDuplicates++;
                                continue;
                            }
                            $error = 'אירעה שגיאה בעת יבוא הקובץ.';
                            break;
                        }
                    }

                    fclose($handle);

                    if ($error === '') {
                        if ($skippedDuplicates > 0) {
                            $success = "היבוא הושלם. נוספו {$imported} פריטים, {$skippedDuplicates} קודים קיימים דולגו.";
                        } else {
                            $success = "היבוא הושלם. נוספו {$imported} פריטים.";
                        }
                    }
                }
            }
        }
    } elseif ($action === 'save_components') {
        $equipmentId = (int)($_POST['equipment_id'] ?? 0);
        if ($equipmentId > 0) {
            $names = $_POST['component_name'] ?? [];
            $qtys  = $_POST['component_qty'] ?? [];
            if (!is_array($names)) $names = [];
            if (!is_array($qtys))  $qtys  = [];

            // מוחקים רכיבים קיימים ומכניסים מחדש לפי הטופס
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare('DELETE FROM equipment_components WHERE equipment_id = :eid');
                $del->execute([':eid' => $equipmentId]);

                $ins = $pdo->prepare(
                    'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
                     VALUES (:equipment_id, :name, :quantity, :created_at)'
                );
                $now = date('Y-m-d H:i:s');
                foreach ($names as $idx => $nameVal) {
                    $nameVal = trim((string)$nameVal);
                    if ($nameVal === '') {
                        continue;
                    }
                    $qtyVal = isset($qtys[$idx]) ? (int)$qtys[$idx] : 1;
                    if ($qtyVal <= 0) $qtyVal = 1;
                    $ins->execute([
                        ':equipment_id' => $equipmentId,
                        ':name'         => $nameVal,
                        ':quantity'     => $qtyVal,
                        ':created_at'   => $now,
                    ]);
                }
                $pdo->commit();
                $success = 'רכיבי הפריט נשמרו בהצלחה.';
                header('Location: admin_equipment.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'שגיאה בשמירת רכיבי הפריט.';
            }
        }
    }
}

// אם נבחר פריט לרכיבים – נטען אותו והרכיבים שלו עבור חלון קופץ
$componentsEquipmentId = isset($_GET['components_for']) ? (int)$_GET['components_for'] : 0;
if ($componentsEquipmentId > 0) {
    $stmtEq = $pdo->prepare('SELECT id, name, code FROM equipment WHERE id = :id');
    $stmtEq->execute([':id' => $componentsEquipmentId]);
    $componentsEquipment = $stmtEq->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($componentsEquipment) {
        $stmtComp = $pdo->prepare(
            'SELECT id, name, quantity
             FROM equipment_components
             WHERE equipment_id = :eid
             ORDER BY name ASC'
        );
        $stmtComp->execute([':eid' => $componentsEquipmentId]);
        $componentsList = $stmtComp->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// Reload list after changes + סינון
$searchTerm      = trim($_GET['q'] ?? '');
$filterStatus    = trim($_GET['filter_status'] ?? '');
$filterWarehouse = trim($_GET['filter_warehouse'] ?? '');
$equipmentTab    = trim((string)($_GET['equipment_tab'] ?? 'all'));

// פילטר זמינות לפי טווח תאריכים/שעות (פנוי בין התאריכים)
$availabilityStartRaw = trim($_GET['availability_start'] ?? '');
$availabilityEndRaw   = trim($_GET['availability_end'] ?? '');

$sql = 'SELECT id, name, code, description, category, location, quantity_total, quantity_available, status, picture, created_at, updated_at
        FROM equipment';
$conditions = [];
$params     = [];

if ($searchTerm !== '') {
    $conditions[]        = 'name LIKE :search';
    $params[':search']   = $searchTerm . '%';
}
if ($filterStatus !== '') {
    $conditions[]        = 'status = :st';
    $params[':st']       = $filterStatus;
}

// $me יוגדר מיד אחרי הבלוק הזה – אבל כאן עדיין לא נגיש, לכן נשתמש ב-role דרך session אם קיים
$roleForFilter = 'student';
if (!empty($_SESSION['user_id'] ?? null)) {
    // נשמור את זה פשוט: נסמך על admin_orders/auth שנטענו קודם
    $currentTmp = current_user();
    if (is_array($currentTmp) && isset($currentTmp['role'])) {
        $roleForFilter = (string)$currentTmp['role'];
    }
}
if ($filterWarehouse !== '' && $roleForFilter === 'admin') {
    $conditions[]        = 'location = :loc';
    $params[':loc']      = $filterWarehouse;
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY category ASC, name ASC';

// שליפת כל הציוד לפי פילטרים בסיסיים
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allEquipment = $stmt->fetchAll();

// חישוב קוד זיהוי מוצע חדש (מספר סידורי הבא)
$nextCode = '';
if (!empty($allEquipment)) {
    $maxNumeric = 0;
    foreach ($allEquipment as $row) {
        $codeVal = isset($row['code']) ? trim((string)$row['code']) : '';
        if ($codeVal === '') {
            continue;
        }
        if (ctype_digit($codeVal)) {
            $num = (int)$codeVal;
            if ($num > $maxNumeric) {
                $maxNumeric = $num;
            }
        }
    }
    if ($maxNumeric > 0) {
        $nextCode = (string)($maxNumeric + 1);
    }
}

// במידת הצורך – סינון לפי זמינות בין התאריכים
$unavailableIds = [];
if ($availabilityStartRaw !== '' && $availabilityEndRaw !== '') {
    $startTs = strtotime($availabilityStartRaw);
    $endTs   = strtotime($availabilityEndRaw);

    if ($startTs !== false && $endTs !== false && $startTs <= $endTs) {
        $reqStart = date('Y-m-d H:i', $startTs);
        $reqEnd   = date('Y-m-d H:i', $endTs);

        $sqlUnavailable = "
            SELECT DISTINCT equipment_id
            FROM orders
            WHERE status IN ('pending', 'approved', 'on_loan')
              AND (
                    (start_date || ' ' || COALESCE(start_time, '00:00')) <= :req_end
                AND (end_date   || ' ' || COALESCE(end_time,   '23:59')) >= :req_start
              )
        ";
        $stmtUn = $pdo->prepare($sqlUnavailable);
        $stmtUn->execute([
            ':req_start' => $reqStart,
            ':req_end'   => $reqEnd,
        ]);
        $rowsUn = $stmtUn->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rowsUn as $rowUn) {
            $eid = (int)($rowUn['equipment_id'] ?? 0);
            if ($eid > 0 && !in_array($eid, $unavailableIds, true)) {
                $unavailableIds[] = $eid;
            }
        }

        if (!empty($unavailableIds)) {
            $allEquipment = array_values(array_filter($allEquipment, function ($item) use ($unavailableIds) {
                return !in_array((int)($item['id'] ?? 0), $unavailableIds, true);
            }));
        }
    }
}

// Unique categories for tabs (empty => "אחר"), sorted; "אחר" last
$uniqueCategories = [];
foreach ($allEquipment as $item) {
    $cat = trim((string)($item['category'] ?? ''));
    $cat = $cat === '' ? 'אחר' : $cat;
    $uniqueCategories[$cat] = true;
}
$uniqueCategories = array_keys($uniqueCategories);
sort($uniqueCategories, SORT_LOCALE_STRING);
if (in_array('אחר', $uniqueCategories, true)) {
    $uniqueCategories = array_diff($uniqueCategories, ['אחר']);
    $uniqueCategories[] = 'אחר';
}

// Filter by selected category tab
if ($equipmentTab === '' || $equipmentTab === 'all') {
    $equipmentList = $allEquipment;
} else {
    $equipmentList = [];
    foreach ($allEquipment as $item) {
        $cat = trim((string)($item['category'] ?? ''));
        $cat = $cat === '' ? 'אחר' : $cat;
        if ($cat === $equipmentTab) {
            $equipmentList[] = $item;
        }
    }
}

// יצוא רשימת ציוד ל-CSV
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="equipment-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'code', 'description', 'category', 'location', 'status', 'quantity_total', 'quantity_available', 'created_at', 'updated_at']);
    foreach ($equipmentList as $row) {
        fputcsv($out, [
            $row['name'] ?? '',
            $row['code'] ?? '',
            $row['description'] ?? '',
            $row['category'] ?? '',
            $row['location'] ?? '',
            $row['status'] ?? '',
            $row['quantity_total'] ?? 0,
            $row['quantity_available'] ?? 0,
            $row['created_at'] ?? '',
            $row['updated_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$me = current_user();

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול ציוד - מערכת השאלת ציוד</title>
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
        .main-nav-sub {
            position: absolute;
            right: 0;
            top: 130%;
            background: #111827;
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            box-shadow: 0 12px 30px rgba(0,0,0,0.45);
            display: none;
            min-width: 170px;
            z-index: 20;
        }
        .main-nav-sub a {
            display: block;
            padding: 0.25rem 0.2rem;
            font-size: 0.8rem;
        }
        .main-nav-sub a + a {
            margin-top: 0.15rem;
        }
        .main-nav-item-wrapper:hover .main-nav-sub {
            display: block;
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
        .category-title {
            margin: 1.2rem 0 0.6rem;
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
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
        label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 0.45rem 0.6rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.9rem;
            box-sizing: border-box;
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
        .btn.danger {
            background: #ef4444;
        }
        .btn.small {
            padding: 0.3rem 0.7rem;
            font-size: 0.8rem;
        }
        .icon-btn {
            border: none;
            background: transparent;
            padding: 0.2rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .grid {
            display: grid;
            grid-template-columns: 2fr 1.2fr;
            gap: 1.5rem;
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
        .toolbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .toolbar-buttons {
            display: flex;
            gap: 0.5rem;
        }
        .equipment-filters {
            margin-bottom: 3px;
        }
        .equipment-filters-inner {
            background: #ffffff;
            border-radius: 10px;
            padding: 10px 10px 12px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .equipment-filters form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .equipment-filters label {
            margin-bottom: 5px;
            margin-right: 5px;
            font-size: 0.78rem;
            color: #4b5563;
        }
        .equipment-filters .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .equipment-filters input[type="text"],
        .equipment-filters select {
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            font-size: 0.8rem;
            background: #f9fafb;
            padding: 5px;
        }
        .equipment-filters .filter-title-pill {
            border-radius: 999px;
            padding: 0.3rem 0.9rem;
            background: #e5e7eb;
            color: #111827;
            font-size: 0.8rem;
            font-weight: 600;
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
        .badge.status-active {
            background: #ecfdf3;
            color: #166534;
        }
        .badge.status-out {
            background: #fef3c7;
            color: #92400e;
        }
        .badge.status-disabled {
            background: #fee2e2;
            color: #b91c1c;
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
            max-width: 900px;
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
        .main-nav-sub {
            position: absolute;
            right: 0;
            top: 130%;
            background: #111827;
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            box-shadow: 0 12px 30px rgba(0,0,0,0.45);
            display: none;
            min-width: 170px;
            z-index: 20;
        }
        .main-nav-sub a {
            display: block;
            padding: 0.25rem 0.2rem;
            font-size: 0.8rem;
        }
        .main-nav-sub a + a {
            margin-top: 0.15rem;
        }
        .main-nav-item-wrapper:hover .main-nav-sub {
            display: block;
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
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="toolbar-top">
        <div class="toolbar-buttons">
            <button type="button" class="btn" id="toggle_add_equipment_btn">הוספת פריט ציוד</button>
            <button type="button" class="btn secondary" id="toggle_import_equipment_btn">יבוא רשימת ציוד</button>
            <a href="admin_equipment.php?export=1" class="btn neutral">יצוא רשימת ציוד</a>
        </div>
    </div>

    <div class="equipment-filters">
        <div class="equipment-filters-inner">
            <form method="get" action="admin_equipment.php">
                <input type="hidden" name="equipment_tab" value="<?= htmlspecialchars($equipmentTab === 'all' ? '' : $equipmentTab, ENT_QUOTES, 'UTF-8') ?>">
                <div class="filter-group">
                    <label for="filter_q">חיפוש לפי שם ציוד</label>
                    <input type="text" id="filter_q" name="q"
                           value="<?= htmlspecialchars($searchTerm ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="הקלד תחילת שם הציוד">
                </div>
                <div class="filter-group">
                    <label for="filter_status">סטטוס</label>
                    <select id="filter_status" name="filter_status">
                        <option value="">כל הסטטוסים</option>
                        <option value="active"   <?= ($filterStatus ?? '') === 'active'   ? 'selected' : '' ?>>פעיל</option>
                        <option value="out"      <?= ($filterStatus ?? '') === 'out'      ? 'selected' : '' ?>>לא זמין</option>
                        <option value="disabled" <?= ($filterStatus ?? '') === 'disabled' ? 'selected' : '' ?>>מושבת</option>
                    </select>
                </div>
                <?php if (isset($me) && ($me['role'] ?? '') === 'admin'): ?>
                    <div class="filter-group">
                        <label for="filter_warehouse">מחסן</label>
                        <select id="filter_warehouse" name="filter_warehouse">
                            <option value="">כל המחסנים</option>
                            <option value="מחסן א" <?= ($filterWarehouse ?? '') === 'מחסן א' ? 'selected' : '' ?>>מחסן א</option>
                            <option value="מחסן ב" <?= ($filterWarehouse ?? '') === 'מחסן ב' ? 'selected' : '' ?>>מחסן ב</option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="filter-group">
                    <label>פנוי בין התאריכים</label>
                    <div style="display:flex;flex-direction:column;gap:0.25rem;min-width:220px;">
                        <div style="display:flex;align-items:center;gap:0.4rem;">
                            <span class="muted-small">מתאריך</span>
                            <input type="datetime-local"
                                   name="availability_start"
                                   value="<?= htmlspecialchars($availabilityStartRaw ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   style="max-width: 210px;">
                        </div>
                        <div style="display:flex;align-items:center;gap:0.4rem;">
                            <span class="muted-small">עד תאריך</span>
                            <input type="datetime-local"
                                   name="availability_end"
                                   value="<?= htmlspecialchars($availabilityEndRaw ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   style="max-width: 210px;">
                        </div>
                    </div>
                </div>
                <div class="filter-group">
                    <label style="visibility:hidden;">סינון</label>
                    <button type="submit" class="btn secondary" style="padding: 5px 0.9rem; font-size: 0.8rem; background: #cacaff;">סינון</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $showFormCard = $editingEquipment !== null || $error !== '';
    ?>

    <div class="modal-backdrop" id="equipment_modal" style="display: <?= $showFormCard ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2><?= $editingEquipment ? 'עריכת ציוד' : 'הוספת ציוד חדש' ?></h2>
                <button type="button" class="modal-close" id="equipment_modal_close" aria-label="סגירת חלון">✕</button>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif ($success !== ''): ?>
                <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="admin_equipment.php<?= $editingEquipment ? '?edit_id=' . (int)$editingEquipment['id'] : '' ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $editingEquipment ? (int)$editingEquipment['id'] : 0 ?>">

                <div class="grid">
                    <div>
                        <label for="name">שם ציוד</label>
                        <input type="text" id="name" name="name" required
                               value="<?= $editingEquipment ? htmlspecialchars($editingEquipment['name'], ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="code">קוד זיהוי / ברקוד</label>
                        <input type="text" id="code" name="code" required
                               value="<?= $editingEquipment
                                   ? htmlspecialchars($editingEquipment['code'], ENT_QUOTES, 'UTF-8')
                                   : htmlspecialchars($nextCode, ENT_QUOTES, 'UTF-8') ?>">

                        <label for="description">תיאור</label>
                        <textarea id="description" name="description"><?= $editingEquipment ? htmlspecialchars($editingEquipment['description'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></textarea>

                        <label for="picture_file">תמונה</label>
                        <?php
                        $existingPicture = $editingEquipment['picture'] ?? '';
                        $existingPicture = is_string($existingPicture) ? trim($existingPicture) : '';
                        ?>
                        <?php if ($editingEquipment && $existingPicture !== ''): ?>
                            <div class="muted-small" id="existing_picture_row" style="margin-bottom:0.35rem;display:flex;align-items:center;gap:0.4rem;">
                                <a href="<?= htmlspecialchars($existingPicture, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                    הצג תמונה קיימת
                                </a>
                                <button type="button" class="icon-btn" id="delete_picture_btn" title="מחיקת התמונה">✕</button>
                            </div>
                            <input type="hidden" name="delete_picture" id="delete_picture" value="0">
                        <?php endif; ?>
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <button type="button" class="btn secondary small" id="upload_picture_btn">טען תמונה</button>
                            <input type="file" id="picture_file" name="picture_file" accept="image/*" style="display:none;">
                        </div>
                    </div>
                    <div>
                        <label for="category">קטגוריה</label>
                        <?php
                        $currentCategory = trim((string)($editingEquipment['category'] ?? ''));
                        ?>
                        <select id="category" name="category">
                            <option value="">בחר קטגוריה...</option>
                            <option value="מצלמה"   <?= $currentCategory === 'מצלמה'   ? 'selected' : '' ?>>מצלמה</option>
                            <option value="מיקרופון" <?= $currentCategory === 'מיקרופון' ? 'selected' : '' ?>>מיקרופון</option>
                            <option value="חצובה"   <?= $currentCategory === 'חצובה'   ? 'selected' : '' ?>>חצובה</option>
                            <option value="תאורה"   <?= $currentCategory === 'תאורה'   ? 'selected' : '' ?>>תאורה</option>
                        </select>

                        <label for="location">מחסן</label>
                        <?php
                        $currentLocation = trim((string)($editingEquipment['location'] ?? ''));
                        ?>
                        <select id="location" name="location">
                            <option value="">בחר מחסן...</option>
                            <option value="מחסן א" <?= $currentLocation === 'מחסן א' ? 'selected' : '' ?>>מחסן א</option>
                            <option value="מחסן ב" <?= $currentLocation === 'מחסן ב' ? 'selected' : '' ?>>מחסן ב</option>
                        </select>

                        <label for="status">סטטוס</label>
                        <select id="status" name="status">
                            <?php
                            $statusValue = $editingEquipment['status'] ?? 'active';
                            ?>
                            <option value="active" <?= $statusValue === 'active' ? 'selected' : '' ?>>פעיל</option>
                            <option value="out_of_service" <?= $statusValue === 'out_of_service' ? 'selected' : '' ?>>לא כשיר</option>
                            <option value="disabled" <?= $statusValue === 'disabled' ? 'selected' : '' ?>>מושבת</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn">
                    <?= $editingEquipment ? 'שמירת שינויים' : 'הוספת ציוד' ?>
                </button>
                <?php if ($editingEquipment): ?>
                    <a href="admin_equipment.php" class="btn secondary">ביטול עריכה</a>
                <?php else: ?>
                    <button type="button" class="btn secondary" id="equipment_modal_cancel">ביטול</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card" id="equipment_import_card" style="display: none;">
        <h2>יבוא רשימת ציוד (CSV)</h2>
        <p class="muted-small">
            יש לבחור קובץ CSV המכיל לפחות עמודות: שם ציוד, מספר סידורי, תיאור, קטגוריה, מיקום, סטטוס.
            השורה הראשונה תיחשב ככותרת.
        </p>
        <form method="post" action="admin_equipment.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <label for="csv_file">קובץ CSV</label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
            <button type="submit" class="btn">יבוא</button>
        </form>
    </div>

    <?php $showComponentsModal = $componentsEquipment !== null; ?>
    <div class="modal-backdrop" id="components_modal" style="display: <?= $showComponentsModal ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2>רכיבי פריט: <?= $componentsEquipment ? htmlspecialchars($componentsEquipment['name'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></h2>
                <button type="button" class="modal-close" id="components_modal_close" aria-label="סגירת חלון">✕</button>
            </div>

            <?php if ($error !== '' && $componentsEquipment): ?>
                <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif ($success !== '' && $componentsEquipment): ?>
                <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($componentsEquipment): ?>
                <form method="post" action="admin_equipment.php?components_for=<?= (int)$componentsEquipment['id'] ?>">
                    <input type="hidden" name="action" value="save_components">
                    <input type="hidden" name="equipment_id" value="<?= (int)$componentsEquipment['id'] ?>">

                    <table style="width:100%; border-collapse:collapse; font-size:0.86rem; margin-bottom:0.75rem;">
                        <thead>
                        <tr>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">שם רכיב</th>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb; width:80px;">כמות</th>
                        </tr>
                        </thead>
                        <tbody id="components_table_body">
                        <?php if (count($componentsList) === 0): ?>
                            <tr>
                                <td style="padding:0.35rem 0.5rem;">
                                    <input type="text" name="component_name[]" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                </td>
                                <td style="padding:0.35rem 0.25rem;">
                                    <input type="number" name="component_qty[]" value="1" min="1" style="width:70px;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($componentsList as $comp): ?>
                                <tr>
                                    <td style="padding:0.35rem 0.5rem;">
                                        <input type="text" name="component_name[]" value="<?= htmlspecialchars($comp['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                               style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                    </td>
                                    <td style="padding:0.35rem 0.25rem;">
                                        <input type="number" name="component_qty[]" value="<?= (int)($comp['quantity'] ?? 1) ?>" min="1"
                                               style="width:70px;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <button type="button" class="btn secondary small" id="components_add_row_btn" style="margin-bottom:0.5rem;">הוסף שורה</button>

                    <div style="display:flex;justify-content:flex-end;gap:0.5rem;">
                        <button type="submit" class="btn">שמירה</button>
                        <a href="admin_equipment.php" class="btn secondary">ביטול</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>רשימת ציוד במחסן</h2>
        <?php
        $tabBaseParams = array_filter([
            'q' => $searchTerm !== '' ? $searchTerm : null,
            'filter_status' => $filterStatus !== '' ? $filterStatus : null,
            'filter_warehouse' => $filterWarehouse !== '' ? $filterWarehouse : null,
            'availability_start' => $availabilityStartRaw !== '' ? $availabilityStartRaw : null,
            'availability_end'   => $availabilityEndRaw !== '' ? $availabilityEndRaw : null,
        ]);
        $tabBaseQuery = http_build_query($tabBaseParams);
        $tabBaseUrl = 'admin_equipment.php' . ($tabBaseQuery !== '' ? '?' . $tabBaseQuery : '');
        ?>
        <?php if (count($allEquipment) === 0): ?>
            <p class="muted-small">עדיין לא הוגדר ציוד במערכת.</p>
        <?php else: ?>
            <div class="tabs">
                <a href="<?= htmlspecialchars($tabBaseUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="<?= ($equipmentTab === 'all' || $equipmentTab === '') ? 'active' : '' ?>">הכל</a>
                <?php foreach ($uniqueCategories as $catName): ?>
                    <?php
                    $tabUrl = $tabBaseUrl . (strpos($tabBaseUrl, '?') !== false ? '&' : '?') . 'equipment_tab=' . rawurlencode($catName);
                    ?>
                    <a href="<?= htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8') ?>"
                       class="<?= $equipmentTab === $catName ? 'active' : '' ?>"><?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (count($equipmentList) === 0): ?>
                <p class="muted-small">אין פריטים בקטגוריה זו.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>שם הציוד</th>
                        <th>מספר סידורי</th>
                        <th>תמונה</th>
                        <th>סטטוס</th>
                        <th>הערות</th>
                        <th>פעולות</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($equipmentList as $item): ?>
                        <tr>
                            <td>
                                <?php
                                $linkParams = array_merge(
                                    ['components_for' => (int)$item['id']],
                                    $tabBaseParams,
                                    ($equipmentTab !== 'all' && $equipmentTab !== '') ? ['equipment_tab' => $equipmentTab] : []
                                );
                                ?>
                                <a href="admin_equipment.php?<?= http_build_query($linkParams) ?>"
                                   class="muted-small"
                                   title="הצגת רכיבי הפריט">
                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (!empty($item['picture'])): ?>
                                    <img src="<?= htmlspecialchars($item['picture'], ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>"
                                         style="max-width: 60px; max-height: 60px; border-radius: 6px; object-fit: cover;">
                                <?php else: ?>
                                    <?php
                                    $uploadParams = array_merge(
                                        ['edit_id' => (int)$item['id']],
                                        $tabBaseParams,
                                        ($equipmentTab !== 'all' && $equipmentTab !== '') ? ['equipment_tab' => $equipmentTab] : []
                                    );
                                    ?>
                                    <a href="admin_equipment.php?<?= http_build_query($uploadParams) ?>"
                                       class="btn secondary small">טען תמונה</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statusClass = 'status-active';
                                $statusLabel = 'תקין';
                                if ($item['status'] === 'out_of_service') {
                                    $statusClass = 'status-out';
                                    $statusLabel = 'לא כשיר';
                                } elseif ($item['status'] === 'disabled') {
                                    $statusClass = 'status-disabled';
                                    $statusLabel = 'מושבת';
                                }
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                            </td>
                            <td class="muted-small">
                                <?= htmlspecialchars($item['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <?php $editParams = array_merge(['edit_id' => (int)$item['id']], $tabBaseParams, ($equipmentTab !== 'all' && $equipmentTab !== '') ? ['equipment_tab' => $equipmentTab] : []); ?>
                                    <a href="admin_equipment.php?<?= http_build_query($editParams) ?>" class="icon-btn" title="עריכה">
                                        ✏️
                                    </a>
                                    <form method="post" action="admin_equipment.php" onsubmit="return confirm('למחוק את הפריט הזה?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="icon-btn" title="מחיקה">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var addBtn = document.getElementById('toggle_add_equipment_btn');
    var importBtn = document.getElementById('toggle_import_equipment_btn');
    var formModal = document.getElementById('equipment_modal');
    var formClose = document.getElementById('equipment_modal_close');
    var formCancel = document.getElementById('equipment_modal_cancel');
    var importCard = document.getElementById('equipment_import_card');
    var componentsModal = document.getElementById('components_modal');
    var componentsClose = document.getElementById('components_modal_close');
    var addComponentRowBtn = document.getElementById('components_add_row_btn');
    var componentsTableBody = document.getElementById('components_table_body');
    var uploadPictureBtn = document.getElementById('upload_picture_btn');
    var pictureFileInput = document.getElementById('picture_file');
    var deletePictureBtn = document.getElementById('delete_picture_btn');
    var deletePictureInput = document.getElementById('delete_picture');
    var existingPictureRow = document.getElementById('existing_picture_row');

    if (uploadPictureBtn && pictureFileInput) {
        uploadPictureBtn.addEventListener('click', function () {
            pictureFileInput.click();
        });
    }

    if (deletePictureBtn && deletePictureInput) {
        deletePictureBtn.addEventListener('click', function () {
            deletePictureInput.value = '1';
            if (existingPictureRow) {
                existingPictureRow.style.display = 'none';
            }
        });
    }

    function openEquipmentModal() {
        if (formModal) {
            formModal.style.display = 'flex';
        }
    }

    function closeEquipmentModal() {
        if (formModal) {
            formModal.style.display = 'none';
        }
    }

    if (addBtn && formModal) {
        addBtn.addEventListener('click', function () {
            openEquipmentModal();
        });
    }

    if (formClose) {
        formClose.addEventListener('click', function () {
            closeEquipmentModal();
        });
    }
    if (formCancel) {
        formCancel.addEventListener('click', function () {
            closeEquipmentModal();
        });
    }

    if (importBtn && importCard) {
        importBtn.addEventListener('click', function () {
            var isVisible = importCard.style.display !== 'none';
            importCard.style.display = isVisible ? 'none' : 'block';
        });
    }

    // חלון רכיבי פריט – סגירה
    if (componentsClose && componentsModal) {
        componentsClose.addEventListener('click', function () {
            componentsModal.style.display = 'none';
            window.location.href = 'admin_equipment.php';
        });
    }

    // הוספת שורת רכיב חדשה
    if (addComponentRowBtn && componentsTableBody) {
        addComponentRowBtn.addEventListener('click', function () {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td style="padding:0.35rem 0.5rem;">' +
                '<input type="text" name="component_name[]" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">' +
                '</td>' +
                '<td style="padding:0.35rem 0.25rem;">' +
                '<input type="number" name="component_qty[]" value="1" min="1" style="width:70px;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">' +
                '</td>';
            componentsTableBody.appendChild(tr);
        });
    }
});
</script>
</body>
</html>

