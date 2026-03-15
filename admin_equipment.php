<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

const MAX_EQUIPMENT_COMPONENTS = 6;

$pdo = get_db();
$error = '';
$success = isset($_GET['success']) ? (string)$_GET['success'] : '';
if (isset($_GET['import_error']) && $_GET['import_error'] !== '') {
    $error = (string)$_GET['import_error'];
}
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

// רכיבי פריט לטופס הציוד (עריכה – טעינה; הוספה – ריק)
$formComponentsList = [];
if ($editingEquipment !== null && !empty($editingEquipment['id'])) {
    $stmtFc = $pdo->prepare('SELECT name, quantity FROM equipment_components WHERE equipment_id = :eid ORDER BY name ASC');
    $stmtFc->execute([':eid' => (int)$editingEquipment['id']]);
    $formComponentsList = $stmtFc->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
                    $id = (int)$pdo->lastInsertId();
                    $success = 'הציוד נוסף בהצלחה.';
                }
                // שמירת רכיבי פריט (מטופס הציוד) – עד MAX_EQUIPMENT_COMPONENTS, כמות תמיד 1
                $names = $_POST['component_name'] ?? [];
                if (!is_array($names)) $names = [];
                $names = array_values(array_filter(array_map('trim', array_map('strval', $names))));
                $names = array_slice($names, 0, MAX_EQUIPMENT_COMPONENTS);
                $eqId = $id > 0 ? $id : (int)($editingEquipment['id'] ?? 0);
                if ($eqId > 0) {
                    try {
                        $del = $pdo->prepare('DELETE FROM equipment_components WHERE equipment_id = :eid');
                        $del->execute([':eid' => $eqId]);
                        $ins = $pdo->prepare(
                            'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
                             VALUES (:equipment_id, :name, 1, :created_at)'
                        );
                        $now = date('Y-m-d H:i:s');
                        foreach ($names as $nameVal) {
                            if ($nameVal === '') continue;
                            $ins->execute([
                                ':equipment_id' => $eqId,
                                ':name'         => $nameVal,
                                ':created_at'   => $now,
                            ]);
                        }
                    } catch (Throwable $e) {
                        // לא מפילים את השמירה אם רכיבים נכשלו
                    }
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
            $rawContent = file_get_contents($tmpPath);
            $encoding = @mb_detect_encoding($rawContent, ['UTF-8', 'ISO-8859-1', 'ASCII'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $converted = @mb_convert_encoding($rawContent, 'UTF-8', $encoding);
                if ($converted !== false) {
                    $rawContent = $converted;
                }
            }
            $ext = strtolower(pathinfo($_FILES['csv_file']['name'] ?? '', PATHINFO_EXTENSION));
            $isCsvExt = ($ext === 'csv');
            $header = null;
            $rows = [];
            $delimiter = ',';
            if ($isCsvExt || true) {
                $lines = preg_split('/\r\n|\r|\n/', $rawContent);
                if (count($lines) > 0 && trim($lines[0]) !== '') {
                    $firstLine = $lines[0];
                    if (strpos($firstLine, "\t") !== false && strpos($firstLine, ',') === false) {
                        $delimiter = "\t";
                    } elseif (strpos($firstLine, ';') !== false && strpos($firstLine, ',') === false) {
                        $delimiter = ';';
                    }
                    $header = str_getcsv($firstLine, $delimiter);
                    for ($i = 1; $i < count($lines); $i++) {
                        if (trim($lines[$i]) === '') continue;
                        $rows[] = str_getcsv($lines[$i], $delimiter);
                    }
                }
            }
            $notCsv = ($header === null || count($header) < 1);
            $systemCols = ['name', 'code', 'description', 'category', 'location', 'status'];
            for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                $systemCols[] = 'component_' . $n . '_name';
            }
            $requiredCols = ['name', 'code'];
            $headerNorm = [];
            if ($header !== null) {
                foreach ($header as $idx => $col) {
                    $key = strtolower(trim((string)$col));
                    if ($key !== '') $headerNorm[$key] = $idx;
                }
            }
            $missingColumns = array_diff($requiredCols, array_keys($headerNorm));
            $unknownColumns = array_diff(array_keys($headerNorm), $systemCols);
            $duplicateCodes = [];
            if (!$notCsv && isset($headerNorm['code'])) {
                $codeIdx = $headerNorm['code'];
                $existingCodes = $pdo->query("SELECT code FROM equipment")->fetchAll(PDO::FETCH_COLUMN);
                $existingSet = array_flip($existingCodes);
                foreach ($rows as $ri => $row) {
                    $code = isset($row[$codeIdx]) ? trim((string)$row[$codeIdx]) : '';
                    if ($code !== '' && isset($existingSet[$code])) {
                        $duplicateCodes[] = ['row' => $ri, 'code' => $code];
                    }
                }
            }
            $hasIssues = $notCsv || !empty($missingColumns) || !empty($unknownColumns) || !empty($duplicateCodes);
            if ($hasIssues) {
                $_SESSION['import_fix_type'] = 'equipment';
                $_SESSION['import_fix_headers'] = $header ?: [];
                $_SESSION['import_fix_rows'] = $rows;
                $_SESSION['import_fix_raw'] = base64_encode($rawContent);
                $_SESSION['import_fix_issues'] = [
                    'not_csv' => $notCsv,
                    'missing_columns' => array_values($missingColumns),
                    'unknown_columns' => array_values($unknownColumns),
                    'duplicate_codes' => $duplicateCodes,
                ];
                $_SESSION['import_fix_delimiter'] = $delimiter;
                header('Location: admin_equipment.php?import_fix=1');
                exit;
            }

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
                    $insertComp = $pdo->prepare(
                        'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
                         VALUES (:equipment_id, :name, :quantity, :created_at)'
                    );

                    $imported = 0;
                    $skippedDuplicates = 0;
                    $now = date('Y-m-d H:i:s');

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
                                ':created_at'         => $now,
                            ]);
                            $newId = (int)$pdo->lastInsertId();
                            for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                                $cName = $get('component_' . $n . '_name');
                                if ($cName === '') continue;
                                try {
                                    $insertComp->execute([
                                        ':equipment_id' => $newId,
                                        ':name'         => $cName,
                                        ':quantity'     => 1,
                                        ':created_at'   => $now,
                                    ]);
                                } catch (Throwable $e) {
                                    // דילוג על רכיב בודד שנכשל
                                }
                            }
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
    } elseif ($action === 'convert_to_csv') {
        if (isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'equipment' && !empty($_SESSION['import_fix_raw'])) {
            $raw = base64_decode((string)$_SESSION['import_fix_raw'], true);
            if ($raw !== false) {
                $raw = str_replace(["\t", ';'], [',', ','], $raw);
                $_SESSION['import_fix_raw'] = base64_encode($raw);
                $lines = preg_split('/\r\n|\r|\n/', $raw);
                $header = count($lines) > 0 ? str_getcsv($lines[0], ',') : [];
                $rows = [];
                for ($i = 1; $i < count($lines); $i++) {
                    if (trim($lines[$i]) === '') continue;
                    $rows[] = str_getcsv($lines[$i], ',');
                }
                $_SESSION['import_fix_headers'] = $header;
                $_SESSION['import_fix_rows'] = $rows;
                $headerNorm = [];
                foreach ($header as $idx => $col) {
                    $key = strtolower(trim((string)$col));
                    if ($key !== '') $headerNorm[$key] = $idx;
                }
                $systemCols = ['name', 'code', 'description', 'category', 'location', 'status'];
                for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                    $systemCols[] = 'component_' . $n . '_name';
                }
                $requiredCols = ['name', 'code'];
                $missingColumns = array_diff($requiredCols, array_keys($headerNorm));
                $unknownColumns = array_diff(array_keys($headerNorm), $systemCols);
                $duplicateCodes = [];
                if (isset($headerNorm['code'])) {
                    $codeIdx = $headerNorm['code'];
                    $existingCodes = $pdo->query("SELECT code FROM equipment")->fetchAll(PDO::FETCH_COLUMN);
                    $existingSet = array_flip($existingCodes);
                    foreach ($rows as $ri => $row) {
                        $code = isset($row[$codeIdx]) ? trim((string)$row[$codeIdx]) : '';
                        if ($code !== '' && isset($existingSet[$code])) {
                            $duplicateCodes[] = ['row' => $ri, 'code' => $code];
                        }
                    }
                }
                $issues = [
                    'not_csv' => false,
                    'missing_columns' => array_values($missingColumns),
                    'unknown_columns' => array_values($unknownColumns),
                    'duplicate_codes' => $duplicateCodes,
                ];
                $_SESSION['import_fix_issues'] = $issues;
                if (empty($missingColumns) && empty($unknownColumns) && empty($duplicateCodes)) {
                    $_SESSION['import_fix_apply_direct'] = true;
                    header('Location: admin_equipment.php?import_fix=1');
                    exit;
                }
            }
        }
        header('Location: admin_equipment.php?import_fix=1');
        exit;
    } elseif ($action === 'import_fixed') {
        if (isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'equipment') {
            $headers = $_SESSION['import_fix_headers'] ?? [];
            $rows = $_SESSION['import_fix_rows'] ?? [];
            $columnMapping = json_decode((string)($_POST['column_mapping'] ?? '{}'), true) ?: [];
            $missingDefaults = json_decode((string)($_POST['missing_defaults'] ?? '{}'), true) ?: [];
            $duplicateActions = json_decode((string)($_POST['duplicate_actions'] ?? '{}'), true) ?: [];
            $headerNorm = [];
            foreach ($headers as $idx => $col) {
                $key = strtolower(trim((string)$col));
                if ($key !== '') $headerNorm[$key] = $idx;
            }
            foreach ($columnMapping as $fileCol => $systemColOrArray) {
                $fileColNorm = strtolower(trim((string)$fileCol));
                if ($fileColNorm === '' || !isset($headerNorm[$fileColNorm])) continue;
                $targets = is_array($systemColOrArray) ? $systemColOrArray : [$systemColOrArray];
                foreach ($targets as $sc) {
                    $sc = is_string($sc) ? trim($sc) : $sc;
                    if ($sc !== '' && $sc !== null) {
                        $headerNorm[$sc] = $headerNorm[$fileColNorm];
                    }
                }
            }
            $systemCols = ['name', 'code', 'description', 'category', 'location', 'status'];
            for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                $systemCols[] = 'component_' . $n . '_name';
            }
            $hasName = isset($headerNorm['name']);
            $hasCode = isset($headerNorm['code']);
            if (!$hasName && !isset($missingDefaults['name'])) {
                $error = 'טור "שם" חסר: יש למפות טור מהקובץ (למשל item→name) או להזין ערך ברירת מחדל.';
                header('Location: admin_equipment.php?import_fix=1&import_error=' . urlencode($error));
                exit;
            }
            if (!$hasCode && !isset($missingDefaults['code'])) {
                $error = 'טור "קוד" חסר: יש למפות טור מהקובץ (למשל item→code) או להזין ערך ברירת מחדל.';
                header('Location: admin_equipment.php?import_fix=1&import_error=' . urlencode($error));
                exit;
            }
            $skipRows = [];
            foreach ($duplicateActions as $ri => $act) {
                if (isset($act['action']) && $act['action'] === 'skip') {
                    $skipRows[(int)$ri] = true;
                }
            }
            $insert = $pdo->prepare(
                'INSERT INTO equipment (name, code, description, category, location, quantity_total, quantity_available, status, created_at)
                 VALUES (:name, :code, :description, :category, :location, 1, 1, :status, :created_at)'
            );
            $insertComp = $pdo->prepare(
                'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
                 VALUES (:equipment_id, :name, 1, :created_at)'
            );
            $now = date('Y-m-d H:i:s');
            $imported = 0;
            foreach ($rows as $ri => $row) {
                if (isset($skipRows[$ri])) continue;
                $row = array_values($row);
                $get = function ($col) use ($headerNorm, $row, $missingDefaults) {
                    $idx = $headerNorm[$col] ?? null;
                    if ($idx !== null && isset($row[$idx])) {
                        return trim((string)$row[$idx]);
                    }
                    return (string)($missingDefaults[$col] ?? '');
                };
                $code = $get('code');
                if (isset($duplicateActions[$ri]['action']) && $duplicateActions[$ri]['action'] === 'replace' && isset($duplicateActions[$ri]['newCode'])) {
                    $code = trim((string)$duplicateActions[$ri]['newCode']);
                }
                $name = $get('name');
                if ($name === '' || $code === '') continue;
                $statusVal = $get('status');
                if (!in_array($statusVal, ['active', 'out', 'disabled'], true)) {
                    $statusVal = 'active';
                }
                $desc = $get('description');
                $cat = $get('category');
                $loc = $get('location');
                try {
                    $insert->execute([
                        ':name' => $name,
                        ':code' => $code,
                        ':description' => $desc,
                        ':category' => $cat,
                        ':location' => $loc,
                        ':quantity_total' => 1,
                        ':quantity_available' => 1,
                        ':status' => $statusVal,
                        ':created_at' => $now,
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                        $cName = $get('component_' . $n . '_name');
                        if ($cName === '') continue;
                        try {
                            $insertComp->execute([':equipment_id' => $newId, ':name' => $cName, ':quantity' => 1, ':created_at' => $now]);
                        } catch (Throwable $e) {}
                    }
                    $imported++;
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'UNIQUE') || !str_contains($e->getMessage(), 'code')) {
                        $msg = $e->getMessage();
                        $detail = '';
                        if (str_contains($msg, 'NOT NULL')) {
                            $detail = ' (חסר ערך בשדה חובה – וודא שטורי שם וקוד ממופים או מולאו)';
                        } elseif (str_contains($msg, 'UNIQUE') || str_contains($msg, 'constraint')) {
                            $detail = ' (קוד כפול – החלף קוד או דלג על השורה)';
                        } else {
                            $short = preg_replace('/[^\p{L}\p{N}\s\-_]/u', ' ', $msg);
                            $short = trim(mb_substr($short, 0, 80));
                            $detail = $short !== '' ? ' (' . $short . ')' : '';
                        }
                        $error = $error ?: ('שגיאה בייבוא.' . $detail);
                    }
                }
            }
            if ($error === '') {
                unset($_SESSION['import_fix_type'], $_SESSION['import_fix_headers'], $_SESSION['import_fix_rows'], $_SESSION['import_fix_raw'], $_SESSION['import_fix_issues'], $_SESSION['import_fix_delimiter']);
                if ($imported === 0) {
                    $error = 'לא יובאו פריטים. וודא שטורים "שם" ו"קוד" ממופים (כולל "גם מפה ל") או מולאו ערכי ברירת מחדל בטורים החסרים.';
                    header('Location: admin_equipment.php?import_fix=1&import_error=' . urlencode($error));
                    exit;
                }
                $success = 'ייבוא הושלם. נוספו ' . $imported . ' פריטים.';
                header('Location: admin_equipment.php?success=' . urlencode($success));
                exit;
            }
            header('Location: admin_equipment.php?import_fix=1&import_error=' . urlencode($error));
            exit;
        }
    } elseif ($action === 'bulk_add') {
        $meTmp = current_user();
        $roleTmp = (string)($meTmp['role'] ?? '');
        $warehouseTmp = trim((string)($meTmp['warehouse'] ?? ''));
        $names = $_POST['bulk_name'] ?? [];
        $codes = $_POST['bulk_code'] ?? [];
        $descs = $_POST['bulk_description'] ?? [];
        $cats = $_POST['bulk_category'] ?? [];
        $locs = $_POST['bulk_location'] ?? [];
        $bulkComponents = $_POST['bulk_component'] ?? [];
        if (!is_array($names)) $names = [];
        if (!is_array($bulkComponents)) $bulkComponents = [];
        $inserted = 0;
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO equipment (name, code, description, category, location, quantity_total, quantity_available, status, created_at)
             VALUES (:name, :code, :description, :category, :location, 1, 1, :status, :created_at)'
        );
        $insComp = $pdo->prepare(
            'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
             VALUES (:equipment_id, :name, 1, :created_at)'
        );
        foreach ($names as $i => $name) {
            $name = trim((string)$name);
            $code = isset($codes[$i]) ? trim((string)$codes[$i]) : '';
            if ($name === '' || $code === '') continue;
            $desc = isset($descs[$i]) ? trim((string)$descs[$i]) : '';
            $cat = isset($cats[$i]) ? trim((string)$cats[$i]) : '';
            if ($roleTmp === 'admin') {
                $loc = isset($locs[$i]) ? trim((string)$locs[$i]) : '';
                if ($loc !== 'מחסן א' && $loc !== 'מחסן ב') $loc = 'מחסן א';
            } else {
                $loc = $warehouseTmp !== '' ? $warehouseTmp : 'מחסן א';
            }
            try {
                $ins->execute([
                    ':name' => $name,
                    ':code' => $code,
                    ':description' => $desc,
                    ':category' => $cat,
                    ':location' => $loc,
                    ':status' => 'active',
                    ':created_at' => $now,
                ]);
                $newId = (int)$pdo->lastInsertId();
                $rowComps = isset($bulkComponents[$i]) && is_array($bulkComponents[$i]) ? $bulkComponents[$i] : [];
                $rowComps = array_values(array_filter(array_map('trim', array_map('strval', $rowComps))));
                $rowComps = array_slice($rowComps, 0, MAX_EQUIPMENT_COMPONENTS);
                foreach ($rowComps as $cName) {
                    if ($cName === '') continue;
                    try {
                        $insComp->execute([
                            ':equipment_id' => $newId,
                            ':name' => $cName,
                            ':created_at' => $now,
                        ]);
                    } catch (Throwable $e) {}
                }
                $inserted++;
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE') || !str_contains($e->getMessage(), 'code')) {
                    $error = $error ?: 'שגיאה בהוספת פריטים. קוד כפול או שגיאה אחרת.';
                }
            }
        }
        if ($error === '' && $inserted > 0) {
            $success = 'נוספו ' . $inserted . ' פריטי ציוד.';
            header('Location: admin_equipment.php?success=' . urlencode($success));
            exit;
        }
    } elseif ($action === 'save_components') {
        $equipmentId = (int)($_POST['equipment_id'] ?? 0);
        if ($equipmentId > 0) {
            $names = $_POST['component_name'] ?? [];
            if (!is_array($names)) $names = [];
            $names = array_values(array_filter(array_map('trim', array_map('strval', $names))));
            $names = array_slice($names, 0, MAX_EQUIPMENT_COMPONENTS);

            // מוחקים רכיבים קיימים ומכניסים מחדש לפי הטופס (כמות תמיד 1)
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare('DELETE FROM equipment_components WHERE equipment_id = :eid');
                $del->execute([':eid' => $equipmentId]);

                $ins = $pdo->prepare(
                    'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
                     VALUES (:equipment_id, :name, 1, :created_at)'
                );
                $now = date('Y-m-d H:i:s');
                foreach ($names as $nameVal) {
                    if ($nameVal === '') continue;
                    $ins->execute([
                        ':equipment_id' => $equipmentId,
                        ':name'         => $nameVal,
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

$show_import_fix_modal = false;
$import_fix_data = null;
if (!empty($_GET['import_fix']) && isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'equipment') {
    if (!empty($_SESSION['import_fix_apply_direct']) || !empty($_GET['apply'])) {
        $headers = $_SESSION['import_fix_headers'] ?? [];
        $rows = $_SESSION['import_fix_rows'] ?? [];
        $headerNorm = [];
        foreach ($headers as $idx => $col) {
            $key = strtolower(trim((string)$col));
            if ($key !== '') $headerNorm[$key] = $idx;
        }
        $insert = $pdo->prepare(
            'INSERT INTO equipment (name, code, description, category, location, quantity_total, quantity_available, status, created_at)
             VALUES (:name, :code, :description, :category, :location, 1, 1, :status, :created_at)'
        );
        $insertComp = $pdo->prepare(
            'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
             VALUES (:equipment_id, :name, 1, :created_at)'
        );
        $now = date('Y-m-d H:i:s');
        $imported = 0;
        foreach ($rows as $row) {
            $row = array_values($row);
            $get = function ($col) use ($headerNorm, $row) {
                $idx = $headerNorm[$col] ?? null;
                return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
            };
            $name = $get('name');
            $code = $get('code');
            if ($name === '' || $code === '') continue;
            try {
                $s = $get('status');
                $statusVal = ($s !== '' && in_array($s, ['active', 'out', 'disabled'], true)) ? $s : 'active';
                $insert->execute([
                    ':name' => $name, ':code' => $code, ':description' => $get('description'),
                    ':category' => $get('category'), ':location' => $get('location'),
                    ':quantity_total' => 1, ':quantity_available' => 1,
                    ':status' => $statusVal, ':created_at' => $now,
                ]);
                $newId = (int)$pdo->lastInsertId();
                for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                    $cName = $get('component_' . $n . '_name');
                    if ($cName === '') continue;
                    try {
                        $insertComp->execute([':equipment_id' => $newId, ':name' => $cName, ':quantity' => 1, ':created_at' => $now]);
                    } catch (Throwable $e) {}
                }
                $imported++;
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE') || !str_contains($e->getMessage(), 'code')) {
                    $msg = $e->getMessage();
                    $short = preg_replace('/[^\p{L}\p{N}\s\-_]/u', ' ', $msg);
                    $short = trim(mb_substr($short, 0, 80));
                    $error = $error ?: ('שגיאה בייבוא.' . ($short !== '' ? ' (' . $short . ')' : ''));
                }
            }
        }
        if ($error === '') {
            unset($_SESSION['import_fix_type'], $_SESSION['import_fix_headers'], $_SESSION['import_fix_rows'], $_SESSION['import_fix_raw'], $_SESSION['import_fix_issues'], $_SESSION['import_fix_delimiter'], $_SESSION['import_fix_apply_direct']);
            $success = 'ייבוא הושלם. נוספו ' . $imported . ' פריטים.';
            header('Location: admin_equipment.php?success=' . urlencode($success));
            exit;
        }
        header('Location: admin_equipment.php?import_fix=1&import_error=' . urlencode($error));
        exit;
    }
    $show_import_fix_modal = true;
    $import_fix_data = [
        'headers' => $_SESSION['import_fix_headers'] ?? [],
        'rows' => $_SESSION['import_fix_rows'] ?? [],
        'issues' => $_SESSION['import_fix_issues'] ?? [],
    ];
    $import_fix_system_columns = array_merge(
        ['name', 'code', 'description', 'category', 'location', 'status'],
        array_map(function ($n) { return 'component_' . $n . '_name'; }, range(1, MAX_EQUIPMENT_COMPONENTS))
    );
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

// יצוא רשימת ציוד ל-CSV (כולל עד 6 רכיבי פריט – שם בלבד, ללא כמות)
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $maxComponents = MAX_EQUIPMENT_COMPONENTS;
    $equipmentIds = array_map(function ($item) { return (int)($item['id'] ?? 0); }, $equipmentList);
    $equipmentIds = array_values(array_filter($equipmentIds));
    $componentsByEquipment = [];
    if (!empty($equipmentIds)) {
        $placeholders = implode(',', array_fill(0, count($equipmentIds), '?'));
        $stmtComp = $pdo->prepare(
            "SELECT equipment_id, name, quantity FROM equipment_components WHERE equipment_id IN ($placeholders) ORDER BY equipment_id, name ASC"
        );
        $stmtComp->execute($equipmentIds);
        while ($r = $stmtComp->fetch(PDO::FETCH_ASSOC)) {
            $eid = (int)$r['equipment_id'];
            if (!isset($componentsByEquipment[$eid])) {
                $componentsByEquipment[$eid] = [];
            }
            $componentsByEquipment[$eid][] = [
                'name'     => (string)($r['name'] ?? ''),
                'quantity' => (int)($r['quantity'] ?? 1),
            ];
        }
    }
    $header = ['name', 'code', 'description', 'category', 'location', 'status', 'quantity_total', 'quantity_available'];
    for ($n = 1; $n <= $maxComponents; $n++) {
        $header[] = 'component_' . $n . '_name';
    }
    $header[] = 'created_at';
    $header[] = 'updated_at';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="equipment-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($equipmentList as $row) {
        $eid = (int)($row['id'] ?? 0);
        $comps = isset($componentsByEquipment[$eid]) ? $componentsByEquipment[$eid] : [];
        $data = [
            $row['name'] ?? '',
            $row['code'] ?? '',
            $row['description'] ?? '',
            $row['category'] ?? '',
            $row['location'] ?? '',
            $row['status'] ?? '',
            $row['quantity_total'] ?? 0,
            $row['quantity_available'] ?? 0,
        ];
        for ($n = 0; $n < $maxComponents; $n++) {
            $data[] = isset($comps[$n]) ? $comps[$n]['name'] : '';
        }
        $data[] = $row['created_at'] ?? '';
        $data[] = $row['updated_at'] ?? '';
        fputcsv($out, $data);
    }
    fclose($out);
    exit;
}

$me = current_user();
$bulkCategories = [];
try {
    $catStmt = $pdo->query("SELECT name FROM equipment_categories ORDER BY name");
    if ($catStmt) $bulkCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
if (empty($bulkCategories)) {
    $catStmt = $pdo->query("SELECT DISTINCT category FROM equipment WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category");
    if ($catStmt) $bulkCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
}
$isAdminForBulk = isset($me['role']) && ($me['role'] ?? '') === 'admin';
$bulkWarehouse = trim((string)($me['warehouse'] ?? ''));

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
            padding: 0.5rem 1.1rem;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
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
        .toolbar-top.toolbar-equipment {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            gap: 0.75rem;
        }
        .toolbar-equipment .toolbar-right {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .toolbar-equipment .toolbar-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toolbar-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .date-picker {
            font-size: 0.85rem;
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.6rem;
            border-radius: 999px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        .date-picker-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .date-picker-toggle-icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        .date-picker-panel {
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            padding: 0.6rem 0.7rem 0.7rem;
            position: absolute;
            top: 110%;
            right: 0;
            z-index: 40;
            min-width: 260px;
        }
        .date-mode-toggle {
            display: inline-flex;
            border-radius: 999px;
            background: #e5e7eb;
            padding: 0.15rem;
            margin-bottom: 0.4rem;
        }
        .date-mode-btn {
            border: none;
            background: transparent;
            padding: 0.15rem 0.7rem;
            border-radius: 999px;
            font-size: 0.8rem;
            cursor: pointer;
            color: #374151;
        }
        .date-mode-btn.active {
            background: #111827;
            color: #f9fafb;
        }
        .date-selected {
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
        .date-selected span {
            font-weight: 600;
        }
        .date-selected .clear-range-btn {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0.1rem 0.3rem;
            border-radius: 999px;
        }
        .date-selected .clear-range-btn:hover {
            background: #e5e7eb;
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
            border-radius: 999px;
            margin: 1px 0;
        }
        .date-day.disabled {
            color: #d1d5db;
            cursor: not-allowed;
        }
        .date-day.selectable {
            cursor: pointer;
        }
        .date-day.selectable:hover {
            background: rgba(15, 23, 42, 0.08);
        }
        .date-day.selected {
            background: #111827 !important;
            color: #f9fafb !important;
            font-weight: 600;
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
        .import-fix-section {
            margin-bottom: 1rem;
        }
        footer {
            background: var(--gf-footer-bg, #111827);
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
    <h2 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem;">ניהול ציוד</h2>
    <div class="toolbar-top toolbar-equipment">
        <div class="toolbar-right">
            <button type="button" class="btn" id="toggle_add_equipment_btn">הוספת פריט ציוד</button>
            <button type="button" class="btn" id="toggle_bulk_add_btn" title="הוספת מספר פריטים בבת אחת">הוספת מספר פריטים</button>
        </div>
        <div class="toolbar-left">
            <a href="admin_equipment.php?export=1" class="btn secondary">יצוא רשימת ציוד</a>
            <button type="button" class="btn secondary" id="toggle_import_equipment_btn">יבוא רשימת ציוד</button>
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
                    <div class="date-picker">
                        <div class="date-picker-toggle" id="eq_date_picker_toggle">
                            <span class="date-picker-toggle-icon">📅</span>
                        </div>
                        <span id="eq_date_range_label" class="muted-small">
                            <?php if ($availabilityStartRaw !== '' && $availabilityEndRaw !== ''): ?>
                                <?php
                                $labelStart = substr($availabilityStartRaw, 0, 10);
                                $labelEnd   = substr($availabilityEndRaw, 0, 10);
                                ?>
                                <?= htmlspecialchars($labelStart . ' - ' . $labelEnd, ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </span>
                        <span id="eq_clear_range_btn_outside"
                              class="clear-range-btn"
                              style="cursor: pointer; <?= ($availabilityStartRaw === '' || $availabilityEndRaw === '') ? 'display:none;' : '' ?>"
                              title="נקה טווח">✕</span>

                        <div class="date-picker-panel" id="eq_date_picker_panel" style="display: none;">
                            <div class="date-mode-toggle">
                                <button type="button" id="eq_mode_start" class="date-mode-btn active">השאלה</button>
                                <button type="button" id="eq_mode_end" class="date-mode-btn">החזרה</button>
                            </div>
                            <div class="date-selected">
                                <div>
                                    תאריך השאלה:
                                    <span id="eq_selected_start_label"><?= $availabilityStartRaw !== '' ? htmlspecialchars(substr($availabilityStartRaw, 0, 10), ENT_QUOTES, 'UTF-8') : '-' ?></span>
                                </div>
                                <div>
                                    תאריך החזרה:
                                    <span id="eq_selected_end_label"><?= $availabilityEndRaw !== '' ? htmlspecialchars(substr($availabilityEndRaw, 0, 10), ENT_QUOTES, 'UTF-8') : '-' ?></span>
                                </div>
                            </div>
                            <div class="date-calendar">
                                <div class="date-calendar-header">
                                    <button type="button" id="eq_cal_prev">&lt;</button>
                                    <div id="eq_cal_month_label"></div>
                                    <div style="display:flex;align-items:center;gap:4px;">
                                        <button type="button" id="eq_cal_close" class="icon-btn" title="סגירת לוח שנה">✕</button>
                                        <button type="button" id="eq_cal_next">&gt;</button>
                                    </div>
                                </div>
                                <div class="date-calendar-weekdays">
                                    <span>א</span><span>ב</span><span>ג</span><span>ד</span><span>ה</span><span>ו</span><span>ש</span>
                                </div>
                                <div class="date-calendar-grid" id="eq_cal_grid"></div>
                            </div>
                            <div class="muted-small" style="margin-top: 0.5rem;">
                                ימים שעברו וימי שישי/שבת מסומנים כלא זמינים.
                            </div>
                        </div>
                        <input type="hidden" name="availability_start" id="availability_start_h" value="<?= htmlspecialchars($availabilityStartRaw ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="availability_end" id="availability_end_h" value="<?= htmlspecialchars($availabilityEndRaw ?? '', ENT_QUOTES, 'UTF-8') ?>">
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
    $showFormCard = ($editingEquipment !== null || $error !== '') && empty($_GET['import_fix']);
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

                <div style="margin-top:1rem;">
                    <button type="button" class="btn secondary small" id="equipment_toggle_components_btn">הוספת פרטי ציוד</button>
                    <div id="equipment_form_components_section" style="display:none; margin-top:0.75rem; padding:0.75rem; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb;">
                        <div class="muted-small" style="margin-bottom:0.5rem;">רכיבי הפריט (עד <?= MAX_EQUIPMENT_COMPONENTS ?> רכיבים, כמות 1 לכל רכיב)</div>
                        <table style="width:100%; border-collapse:collapse; font-size:0.86rem; margin-bottom:0.5rem;">
                            <thead>
                            <tr>
                                <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">שם רכיב</th>
                            </tr>
                            </thead>
                            <tbody id="equipment_form_components_tbody">
                            <?php
                            $formComps = array_slice($formComponentsList, 0, MAX_EQUIPMENT_COMPONENTS);
                            if (empty($formComps)): ?>
                                <tr class="component-row">
                                    <td style="padding:0.35rem 0.5rem;">
                                        <input type="text" name="component_name[]" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($formComps as $fc): ?>
                                <tr class="component-row">
                                    <td style="padding:0.35rem 0.5rem;">
                                        <input type="text" name="component_name[]" value="<?= htmlspecialchars($fc['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                        <button type="button" class="btn secondary small" id="equipment_form_add_component_row" data-max="<?= MAX_EQUIPMENT_COMPONENTS ?>">הוסף שורה</button>
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

    <div class="modal-backdrop" id="equipment_bulk_add_modal" style="display: none;">
        <div class="modal-card" style="max-width: 95%; width: 900px;">
            <div class="modal-header">
                <h2>הוספת מספר פריטים</h2>
                <button type="button" class="modal-close" id="bulk_add_modal_close" aria-label="סגירה">✕</button>
            </div>
            <p class="muted-small" style="margin-bottom:0.75rem;">הוסף שורות ומילוי פרטי ציוד. שמירה תיצור פריט חדש לכל שורה עם שם וקוד. ניתן להוסיף עד <?= MAX_EQUIPMENT_COMPONENTS ?> רכיבי ציוד לכל שורה.</p>
            <form method="post" action="admin_equipment.php">
                <input type="hidden" name="action" value="bulk_add">
                <div style="overflow-x: auto;">
                    <table class="bulk-add-table" style="width:100%; border-collapse: collapse; font-size: 0.86rem;">
                        <thead>
                        <tr>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">שם ציוד</th>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">קוד זיהוי</th>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">תיאור</th>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">קטגוריה</th>
                            <?php if ($isAdminForBulk): ?>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">מחסן</th>
                            <?php endif; ?>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">רכיבי ציוד (עד <?= MAX_EQUIPMENT_COMPONENTS ?>)</th>
                        </tr>
                        </thead>
                        <tbody id="bulk_add_tbody">
                        <tr class="bulk-add-row" data-row-index="0">
                            <td style="padding:0.35rem 0.5rem;"><input type="text" name="bulk_name[]" placeholder="שם" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;"></td>
                            <td style="padding:0.35rem 0.5rem;"><input type="text" name="bulk_code[]" placeholder="קוד" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;"></td>
                            <td style="padding:0.35rem 0.5rem;"><input type="text" name="bulk_description[]" placeholder="תיאור" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;"></td>
                            <td style="padding:0.35rem 0.5rem;">
                                <select name="bulk_category[]" style="padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db; min-width:120px;">
                                    <option value="">—</option>
                                    <?php foreach ($bulkCategories as $bc): ?>
                                    <option value="<?= htmlspecialchars($bc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($bc, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <?php if ($isAdminForBulk): ?>
                            <td style="padding:0.35rem 0.5rem;">
                                <select name="bulk_location[]" style="padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db; min-width:100px;">
                                    <option value="מחסן א">מחסן א</option>
                                    <option value="מחסן ב">מחסן ב</option>
                                </select>
                            </td>
                            <?php endif; ?>
                            <td style="padding:0.35rem 0.5rem; vertical-align:top;">
                                <div class="bulk-components-cell">
                                    <?php for ($ci = 0; $ci < MAX_EQUIPMENT_COMPONENTS; $ci++): ?>
                                    <input type="text" name="bulk_component[0][]" placeholder="רכיב <?= $ci + 1 ?>" style="width:100%;padding:0.25rem 0.35rem;border-radius:4px;border:1px solid #d1d5db; margin-bottom:0.2rem; font-size:0.8rem;">
                                    <?php endfor; ?>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:0.75rem; display:flex; gap:0.5rem; align-items:center;">
                    <button type="button" class="btn secondary" id="bulk_add_row_btn">הוסף שורה</button>
                    <button type="submit" class="btn">שמירת כל הפריטים</button>
                </div>
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
            <input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" required>
            <button type="submit" class="btn">יבוא</button>
        </form>
    </div>

    <?php if ($show_import_fix_modal && $import_fix_data): ?>
    <div class="modal-backdrop" id="import_fix_modal" style="display: flex;">
        <div class="modal-card" style="max-width: 95%; width: 640px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h2>תיקון ייבוא ציוד</h2>
                <button type="button" class="modal-close" id="import_fix_modal_close" aria-label="סגירה">✕</button>
            </div>
            <?php if ($error !== ''): ?>
            <div class="flash error" style="margin-bottom:0.75rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div id="import_fix_content">
                <?php $iss = $import_fix_data['issues']; ?>
                <?php if (!empty($iss['not_csv'])): ?>
                <div class="import-fix-section">
                    <p class="flash error">הקובץ אינו בפורמט CSV.</p>
                    <form method="post" action="admin_equipment.php">
                        <input type="hidden" name="action" value="convert_to_csv">
                        <button type="submit" class="btn">המרה ל-CSV</button>
                    </form>
                </div>
                <?php endif; ?>
                <?php if (!empty($iss['missing_columns'])): ?>
                <div class="import-fix-section">
                    <p class="muted-small">טורים חסרים בקובץ – הזן ערך ברירת מחדל (או מפה טור מהקובץ למטה, למשל item→name):</p>
                    <?php foreach ($iss['missing_columns'] as $col): ?>
                    <label style="display:block; margin:0.35rem 0;"><?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" name="missing_default_<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" class="import-fix-missing" data-col="<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" placeholder="ערך ברירת מחדל" style="width:100%; max-width:280px; padding:0.35rem;">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($iss['unknown_columns'])): ?>
                <div class="import-fix-section">
                    <p class="muted-small">הטורים הללו קיימים ברשימת הייבוא אך לא במערכת. להמרת טורים: בחר טור מערכת לכל טור בקובץ. ניתן לבחור "גם מפה ל" כדי שמעמודה אחת בקובץ ימולאו שני טורים במערכת (למשל: מעמודה "item" למפות גם ל־code וגם ל־name).</p>
                    <?php foreach ($iss['unknown_columns'] as $uc): ?>
                    <div class="import-fix-map-row" style="display:flex; align-items:center; gap:0.5rem; margin:0.4rem 0; flex-wrap:wrap;">
                        <span style="min-width:100px;"><?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?></span>
                        <select class="import-fix-map-select" data-file-col="<?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?>" style="padding:0.35rem; min-width:140px;">
                            <option value="">— ביטול (התעלם מטור)</option>
                            <?php foreach ($import_fix_system_columns as $sc): ?>
                            <option value="<?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="muted-small" style="font-size:0.8rem;">גם מפה ל:</span>
                        <select class="import-fix-map-select-also" data-file-col="<?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?>" style="padding:0.35rem; min-width:140px;">
                            <option value="">—</option>
                            <?php foreach ($import_fix_system_columns as $sc): ?>
                            <option value="<?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($iss['duplicate_codes'])): ?>
                <div class="import-fix-section">
                    <?php foreach ($iss['duplicate_codes'] as $dc): ?>
                    <div class="import-fix-dup-row" style="margin:0.5rem 0; padding:0.5rem; background:#f9fafb; border-radius:8px;" data-row="<?= (int)$dc['row'] ?>" data-code="<?= htmlspecialchars($dc['code'], ENT_QUOTES, 'UTF-8') ?>">
                        <p class="muted-small" style="margin:0 0 0.35rem 0;">המספר <strong><?= htmlspecialchars($dc['code'], ENT_QUOTES, 'UTF-8') ?></strong> כבר קיים במערכת.</p>
                        <label><input type="radio" name="dup_<?= (int)$dc['row'] ?>" value="replace" class="dup-action-replace"> החלף במספר </label>
                        <input type="text" class="dup-new-code" placeholder="מספר חדש" style="padding:0.3rem; width:120px; margin:0 0.5rem;">
                        <label><input type="radio" name="dup_<?= (int)$dc['row'] ?>" value="skip" class="dup-action-skip" checked> דלג על השורה</label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="import-fix-section" style="margin-top:1rem;">
                    <form method="post" action="admin_equipment.php" id="import_fixed_form">
                        <input type="hidden" name="action" value="import_fixed">
                        <input type="hidden" name="column_mapping" id="import_fix_column_mapping" value="">
                        <input type="hidden" name="missing_defaults" id="import_fix_missing_defaults" value="">
                        <input type="hidden" name="duplicate_actions" id="import_fix_duplicate_actions" value="">
                        <button type="submit" class="btn">ייבא קובץ מתוקן</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var systemColumns = <?= json_encode($import_fix_system_columns ?? []) ?>;
        var unknownColumns = <?= json_encode($iss['unknown_columns'] ?? []) ?>;
        document.getElementById('import_fixed_form').addEventListener('submit', function(e) {
            var mapping = {};
            document.querySelectorAll('.import-fix-map-row').forEach(function(row) {
                var fileCol = row.querySelector('.import-fix-map-select').getAttribute('data-file-col');
                var mainVal = row.querySelector('.import-fix-map-select').value;
                var alsoSel = row.querySelector('.import-fix-map-select-also');
                var alsoVal = alsoSel ? alsoSel.value : '';
                var arr = [];
                if (mainVal !== '') arr.push(mainVal);
                if (alsoVal !== '' && alsoVal !== mainVal) arr.push(alsoVal);
                if (arr.length > 0) mapping[fileCol] = arr.length === 1 ? arr[0] : arr;
            });
            var missing = {};
            document.querySelectorAll('.import-fix-missing').forEach(function(inp) {
                var col = inp.getAttribute('data-col');
                if (col && inp.value.trim() !== '') missing[col] = inp.value.trim();
            });
            var dups = {};
            document.querySelectorAll('.import-fix-dup-row').forEach(function(row) {
                var ri = row.getAttribute('data-row');
                var replaceRadio = row.querySelector('.dup-action-replace');
                var skipRadio = row.querySelector('.dup-action-skip');
                var newCodeInp = row.querySelector('.dup-new-code');
                if (skipRadio && skipRadio.checked) {
                    dups[ri] = { action: 'skip' };
                } else if (replaceRadio && replaceRadio.checked && newCodeInp && newCodeInp.value.trim() !== '') {
                    dups[ri] = { action: 'replace', newCode: newCodeInp.value.trim() };
                } else {
                    dups[ri] = { action: 'skip' };
                }
            });
            document.getElementById('import_fix_column_mapping').value = JSON.stringify(mapping);
            document.getElementById('import_fix_missing_defaults').value = JSON.stringify(missing);
            document.getElementById('import_fix_duplicate_actions').value = JSON.stringify(dups);
        });
        function updateMapSelectOptions() {
            var used = {};
            function markUsed(sel) {
                if (sel && sel.value !== '') used[sel.value] = true;
            }
            document.querySelectorAll('.import-fix-map-row').forEach(function(row) {
                markUsed(row.querySelector('.import-fix-map-select'));
                markUsed(row.querySelector('.import-fix-map-select-also'));
            });
            document.querySelectorAll('.import-fix-map-select, .import-fix-map-select-also').forEach(function(s) {
                var currentVal = s.value;
                for (var i = 0; i < s.options.length; i++) {
                    var opt = s.options[i];
                    if (opt.value === '') continue;
                    opt.disabled = used[opt.value] && opt.value !== currentVal;
                }
            });
        }
        document.querySelectorAll('.import-fix-map-select, .import-fix-map-select-also').forEach(function(sel) {
            sel.addEventListener('change', updateMapSelectOptions);
        });
        updateMapSelectOptions();
        var closeBtn = document.getElementById('import_fix_modal_close');
        if (closeBtn) closeBtn.addEventListener('click', function() {
            window.location.href = 'admin_equipment.php';
        });
    })();
    </script>
    <?php endif; ?>

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

                    <p class="muted-small">עד <?= MAX_EQUIPMENT_COMPONENTS ?> רכיבים, כל רכיב בכמות 1.</p>
                    <table style="width:100%; border-collapse:collapse; font-size:0.86rem; margin-bottom:0.75rem;">
                        <thead>
                        <tr>
                            <th style="padding:0.4rem 0.5rem; text-align:right; border-bottom:1px solid #e5e7eb;">שם רכיב</th>
                        </tr>
                        </thead>
                        <tbody id="components_table_body">
                        <?php
                        $componentsListSlice = array_slice($componentsList, 0, MAX_EQUIPMENT_COMPONENTS);
                        if (count($componentsListSlice) === 0): ?>
                            <tr class="component-row">
                                <td style="padding:0.35rem 0.5rem;">
                                    <input type="text" name="component_name[]" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($componentsListSlice as $comp): ?>
                                <tr class="component-row">
                                    <td style="padding:0.35rem 0.5rem;">
                                        <input type="text" name="component_name[]" value="<?= htmlspecialchars($comp['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                               style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <button type="button" class="btn secondary small" id="components_add_row_btn" data-max="<?= MAX_EQUIPMENT_COMPONENTS ?>" style="margin-bottom:0.5rem;">הוסף שורה</button>

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
    var bulkAddBtn = document.getElementById('toggle_bulk_add_btn');
    var bulkAddTbody = document.getElementById('bulk_add_tbody');
    var bulkAddRowBtn = document.getElementById('bulk_add_row_btn');
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

    // לוח שנה לפילטר "פנוי בין התאריכים"
    var eqToggle = document.getElementById('eq_date_picker_toggle');
    var eqPanel = document.getElementById('eq_date_picker_panel');
    var eqModeStartBtn = document.getElementById('eq_mode_start');
    var eqModeEndBtn = document.getElementById('eq_mode_end');
    var eqStartLabel = document.getElementById('eq_selected_start_label');
    var eqEndLabel = document.getElementById('eq_selected_end_label');
    var eqRangeLabel = document.getElementById('eq_date_range_label');
    var eqCalMonthLabel = document.getElementById('eq_cal_month_label');
    var eqCalPrev = document.getElementById('eq_cal_prev');
    var eqCalNext = document.getElementById('eq_cal_next');
    var eqCalClose = document.getElementById('eq_cal_close');
    var eqCalGrid = document.getElementById('eq_cal_grid');
    var eqClearBtn = document.getElementById('eq_clear_range_btn');
    var eqClearBtnOutside = document.getElementById('eq_clear_range_btn_outside');
    var availabilityStartH = document.getElementById('availability_start_h');
    var availabilityEndH = document.getElementById('availability_end_h');

    if (eqToggle && eqPanel && eqModeStartBtn && eqModeEndBtn && eqCalGrid && eqCalMonthLabel && availabilityStartH && availabilityEndH) {
        var eqMode = 'start';
        var eqViewDate = new Date();

        function pad(n) {
            return n < 10 ? '0' + n : '' + n;
        }

        function toIso(d) {
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        }

        function parseDate(value) {
            if (!value) return null;
            var parts = value.split(' ')[0].split('-');
            if (parts.length !== 3) return null;
            var year = parseInt(parts[0], 10);
            var month = parseInt(parts[1], 10) - 1;
            var day = parseInt(parts[2], 10);
            var d = new Date(year, month, day);
            return isNaN(d.getTime()) ? null : d;
        }

        function isDisabledDay(date) {
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            if (date < today) return true;
            var day = date.getDay(); // 0=ראשון ... 6=שבת
            return day === 5 || day === 6;
        }

        function updateRangeLabel() {
            if (availabilityStartH.value && availabilityEndH.value) {
                var s = availabilityStartH.value.substring(0, 10);
                var e = availabilityEndH.value.substring(0, 10);
                eqRangeLabel.textContent = s + ' - ' + e;
                if (eqClearBtnOutside) {
                    eqClearBtnOutside.style.display = 'inline';
                }
            } else {
                eqRangeLabel.textContent = '-';
                if (eqClearBtnOutside) {
                    eqClearBtnOutside.style.display = 'none';
                }
            }
        }

        function buildEqCalendar() {
            eqCalGrid.innerHTML = '';
            var year = eqViewDate.getFullYear();
            var month = eqViewDate.getMonth();
            eqCalMonthLabel.textContent = year + '-' + pad(month + 1);

            var first = new Date(year, month, 1);
            var startWeekday = first.getDay(); // 0=ראשון
            var daysInMonth = new Date(year, month + 1, 0).getDate();

            for (var i = 0; i < startWeekday; i++) {
                var emptyCell = document.createElement('div');
                eqCalGrid.appendChild(emptyCell);
            }

            var selectedStartDate = parseDate(availabilityStartH.value);
            var selectedEndDate = parseDate(availabilityEndH.value);

            for (var day = 1; day <= daysInMonth; day++) {
                (function (dNum) {
                    var cell = document.createElement('div');
                    cell.textContent = dNum;
                    var dateObj = new Date(year, month, dNum);

                    var classes = ['date-day'];
                    if (isDisabledDay(dateObj)) {
                        classes.push('disabled');
                    } else {
                        classes.push('selectable');
                        cell.addEventListener('click', function () {
                            var iso = toIso(dateObj);

                            if (eqMode === 'start') {
                                // בחירת תאריך תחילה
                                availabilityStartH.value = iso + ' 00:00';

                                // לאחר בחירת תאריך התחלה – עבור אוטומטית למצב תאריך סיום
                                eqMode = 'end';
                                if (eqModeStartBtn && eqModeEndBtn) {
                                    eqModeStartBtn.classList.remove('active');
                                    eqModeEndBtn.classList.add('active');
                                }
                            } else {
                                // בחירת תאריך סיום
                                availabilityEndH.value = iso + ' 23:59';
                            }

                            eqStartLabel.textContent = availabilityStartH.value ? availabilityStartH.value.substring(0, 10) : '-';
                            eqEndLabel.textContent = availabilityEndH.value ? availabilityEndH.value.substring(0, 10) : '-';
                            updateRangeLabel();

                            // בנייה מחדש של הלוח כדי לצבוע את היום / הטווח הנבחר בשחור
                            buildEqCalendar();

                            // אם נבחר תאריך החזרה – סגור את הלוח לאחר שנייה
                            if (eqMode === 'end' && availabilityEndH.value) {
                                setTimeout(function () {
                                    eqPanel.style.display = 'none';
                                }, 1000);
                            }
                        });
                    }

                    // סימון בטווח שנבחר (מתאריך התחלה ועד תאריך סיום) בצבע שחור
                    if (selectedStartDate && selectedEndDate) {
                        if (dateObj >= selectedStartDate && dateObj <= selectedEndDate) {
                            classes.push('selected');
                        }
                    } else if (selectedStartDate && !selectedEndDate) {
                        if (dateObj.getTime() === selectedStartDate.getTime()) {
                            classes.push('selected');
                        }
                    } else if (!selectedStartDate && selectedEndDate) {
                        if (dateObj.getTime() === selectedEndDate.getTime()) {
                            classes.push('selected');
                        }
                    }

                    cell.className = classes.join(' ');
                    eqCalGrid.appendChild(cell);
                })(day);
            }
        }

        eqToggle.addEventListener('click', function () {
            eqPanel.style.display = eqPanel.style.display === 'none' || eqPanel.style.display === '' ? 'block' : 'none';
        });

        if (eqCalClose) {
            eqCalClose.addEventListener('click', function () {
                eqPanel.style.display = 'none';
            });
        }

        eqModeStartBtn.addEventListener('click', function () {
            eqMode = 'start';
            eqModeStartBtn.classList.add('active');
            eqModeEndBtn.classList.remove('active');
        });

        eqModeEndBtn.addEventListener('click', function () {
            eqMode = 'end';
            eqModeEndBtn.classList.add('active');
            eqModeStartBtn.classList.remove('active');
        });

        eqCalPrev.addEventListener('click', function () {
            eqViewDate = new Date(eqViewDate.getFullYear(), eqViewDate.getMonth() - 1, 1);
            buildEqCalendar();
        });

        eqCalNext.addEventListener('click', function () {
            eqViewDate = new Date(eqViewDate.getFullYear(), eqViewDate.getMonth() + 1, 1);
            buildEqCalendar();
        });

        function clearRange() {
            availabilityStartH.value = '';
            availabilityEndH.value = '';
            eqStartLabel.textContent = '-';
            eqEndLabel.textContent = '-';
            updateRangeLabel();
            buildEqCalendar();
        }

        if (eqClearBtn) {
            eqClearBtn.addEventListener('click', clearRange);
        }
        if (eqClearBtnOutside) {
            eqClearBtnOutside.addEventListener('click', clearRange);
        }

        buildEqCalendar();
        updateRangeLabel();
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
            if (bulkAddModal) bulkAddModal.style.display = 'none';
        });
    }
    var bulkAddModal = document.getElementById('equipment_bulk_add_modal');
    var bulkAddModalClose = document.getElementById('bulk_add_modal_close');
    if (bulkAddBtn && bulkAddModal) {
        bulkAddBtn.addEventListener('click', function () {
            bulkAddModal.style.display = 'flex';
            if (importCard) importCard.style.display = 'none';
        });
    }
    if (bulkAddModalClose && bulkAddModal) {
        bulkAddModalClose.addEventListener('click', function () {
            bulkAddModal.style.display = 'none';
        });
    }
    if (bulkAddRowBtn && bulkAddTbody) {
        bulkAddRowBtn.addEventListener('click', function () {
            var rows = bulkAddTbody.querySelectorAll('tr.bulk-add-row');
            var nextIndex = rows.length;
            var firstRow = rows[0];
            if (!firstRow) return;
            var clone = firstRow.cloneNode(true);
            clone.setAttribute('data-row-index', nextIndex);
            var inputs = clone.querySelectorAll('input[type="text"]');
            inputs.forEach(function (inp) { inp.value = ''; });
            var selects = clone.querySelectorAll('select');
            selects.forEach(function (sel) { sel.selectedIndex = 0; });
            var compInputs = clone.querySelectorAll('.bulk-components-cell input');
            compInputs.forEach(function (inp) {
                inp.name = 'bulk_component[' + nextIndex + '][]';
            });
            bulkAddTbody.appendChild(clone);
        });
    }

    // חלון רכיבי פריט – סגירה
    if (componentsClose && componentsModal) {
        componentsClose.addEventListener('click', function () {
            componentsModal.style.display = 'none';
            window.location.href = 'admin_equipment.php';
        });
    }

    // הוספת שורת רכיב חדשה (עד 6 רכיבים, ללא כמות)
    if (addComponentRowBtn && componentsTableBody) {
        addComponentRowBtn.addEventListener('click', function () {
            var max = parseInt(addComponentRowBtn.getAttribute('data-max') || '6', 10);
            var rows = componentsTableBody.querySelectorAll('tr.component-row');
            if (rows.length >= max) return;
            var tr = document.createElement('tr');
            tr.className = 'component-row';
            tr.innerHTML = '<td style="padding:0.35rem 0.5rem;">' +
                '<input type="text" name="component_name[]" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">' +
                '</td>';
            componentsTableBody.appendChild(tr);
        });
    }

    // בחלון הוספת/עריכת ציוד – כפתור "הוספת פרטי ציוד" וטבלת רכיבים
    var equipmentToggleComponentsBtn = document.getElementById('equipment_toggle_components_btn');
    var equipmentFormComponentsSection = document.getElementById('equipment_form_components_section');
    var equipmentFormComponentsTbody = document.getElementById('equipment_form_components_tbody');
    var equipmentFormAddRowBtn = document.getElementById('equipment_form_add_component_row');
    if (equipmentToggleComponentsBtn && equipmentFormComponentsSection) {
        equipmentToggleComponentsBtn.addEventListener('click', function () {
            var visible = equipmentFormComponentsSection.style.display !== 'none';
            equipmentFormComponentsSection.style.display = visible ? 'none' : 'block';
        });
    }
    if (equipmentFormAddRowBtn && equipmentFormComponentsTbody) {
        equipmentFormAddRowBtn.addEventListener('click', function () {
            var max = parseInt(equipmentFormAddRowBtn.getAttribute('data-max') || '6', 10);
            var rows = equipmentFormComponentsTbody.querySelectorAll('tr.component-row');
            if (rows.length >= max) return;
            var tr = document.createElement('tr');
            tr.className = 'component-row';
            tr.innerHTML = '<td style="padding:0.35rem 0.5rem;">' +
                '<input type="text" name="component_name[]" style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">' +
                '</td>';
            equipmentFormComponentsTbody.appendChild(tr);
        });
    }
});
</script>
</body>
</html>

