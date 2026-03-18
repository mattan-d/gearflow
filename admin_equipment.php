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

// ספקים לשדות שירות
$serviceSuppliers = [
    'equipment' => [],
    'lab'       => [],
    'warranty'  => [],
];
$serviceSuppliersById = [];
try {
    $stmtSup = $pdo->prepare("
        SELECT id, company_name, service_type
        FROM suppliers
        WHERE service_type IN ('ציוד', 'מעבדה', 'אחריות')
        ORDER BY company_name ASC
    ");
    $stmtSup->execute();
    $rowsSup = $stmtSup->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rowsSup as $row) {
        $st = (string)($row['service_type'] ?? '');
        $sid = (int)($row['id'] ?? 0);
        if ($sid > 0) {
            $serviceSuppliersById[$sid] = $row;
        }
        if ($st === 'ציוד') {
            $serviceSuppliers['equipment'][] = $row;
        } elseif ($st === 'מעבדה') {
            $serviceSuppliers['lab'][] = $row;
        } elseif ($st === 'אחריות') {
            $serviceSuppliers['warranty'][] = $row;
        }
    }
} catch (Throwable $e) {
    // מתעלמים משגיאה בטעינת ספקים לשירות
}

// Handle edit / view mode
$editingEquipment = null;
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;
$loadId = $editId > 0 ? $editId : $viewId;
if ($loadId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM equipment WHERE id = :id');
    $stmt->execute([':id' => $loadId]);
    $editingEquipment = $stmt->fetch() ?: null;
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
        $manufacturerCode = trim($_POST['manufacturer_code'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $category     = trim($_POST['category'] ?? '');
        $location     = trim($_POST['location'] ?? '');
        $status       = $_POST['status'] ?? 'active';
        $serviceSupplierId = isset($_POST['service_supplier_id']) ? (int)$_POST['service_supplier_id'] : 0;
        $serviceLabId      = isset($_POST['service_lab_id']) ? (int)$_POST['service_lab_id'] : 0;
        $serviceWarrantyMode = trim((string)($_POST['service_warranty_mode'] ?? ''));
        $serviceWarrantySupplierId = isset($_POST['service_warranty_supplier_id']) ? (int)$_POST['service_warranty_supplier_id'] : 0;
        $warrantyStart = trim((string)($_POST['warranty_start'] ?? ''));
        $warrantyEnd   = trim((string)($_POST['warranty_end'] ?? ''));
        // אם נבחר ספק אחריות מהרשימה (sup_ID), נשמור גם את ה-ID בעמודה הייעודית
        if (str_starts_with($serviceWarrantyMode, 'sup_')) {
            $serviceWarrantySupplierId = (int)substr($serviceWarrantyMode, 4);
        }

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

        // ניהול תמונת אחריות
        $warrantyImagePath = '';
        if ($id > 0 && $editingEquipment !== null) {
            $warrantyImagePath = (string)($editingEquipment['warranty_image'] ?? '');
        }
        if (isset($_FILES['warranty_file']) && is_array($_FILES['warranty_file']) && ($_FILES['warranty_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpNameW = (string)($_FILES['warranty_file']['tmp_name'] ?? '');
            $origNameW = (string)($_FILES['warranty_file']['name'] ?? '');
            if ($tmpNameW !== '' && is_uploaded_file($tmpNameW)) {
                $uploadDirW = __DIR__ . '/equipment_warranty';
                if (!is_dir($uploadDirW)) {
                    @mkdir($uploadDirW, 0777, true);
                }
                $extW = pathinfo($origNameW, PATHINFO_EXTENSION);
                $extW = $extW !== '' ? ('.' . strtolower($extW)) : '';
                try {
                    $randomW = bin2hex(random_bytes(4));
                } catch (Throwable $e) {
                    $randomW = (string)mt_rand(1000, 9999);
                }
                $fileNameW = 'warranty_' . time() . '_' . $randomW . $extW;
                $targetPathW = $uploadDirW . DIRECTORY_SEPARATOR . $fileNameW;
                if (move_uploaded_file($tmpNameW, $targetPathW)) {
                    $warrantyImagePath = 'equipment_warranty/' . $fileNameW;
                } else {
                    $error = $error !== '' ? $error : 'שגיאה בהעלאת תמונת האחריות. ניתן לנסות שוב.';
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

        // פריט חדש: אם לא הוקלד קוד זיהוי – נייצר מספר סידורי אוטומטי (המקס' עבור אותו שם ציוד + 1)
        if ($id <= 0 && $code === '' && $name !== '') {
            try {
                $stmtMax = $pdo->prepare("
                    SELECT MAX(
                        CASE
                            WHEN TRIM(COALESCE(code,'')) GLOB '[0-9]*' THEN CAST(code AS INTEGER)
                            ELSE NULL
                        END
                    ) AS mx
                    FROM equipment
                    WHERE TRIM(COALESCE(name,'')) = :n
                ");
                $stmtMax->execute([':n' => $name]);
                $mxRow = $stmtMax->fetch(PDO::FETCH_ASSOC) ?: [];
                $mx = (int)($mxRow['mx'] ?? 0);
                $code = (string)($mx + 1);
            } catch (Throwable $e) {
                // fallback: נשאיר ריק ונטפל בהמשך ולידציה
            }
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
                             manufacturer_code = :manufacturer_code,
                             description = :description,
                             category = :category,
                             location = :location,
                             quantity_total = :quantity_total,
                             quantity_available = :quantity_available,
                             status = :status,
                             picture = :picture,
                             service_supplier_id = :service_supplier_id,
                             service_lab_id = :service_lab_id,
                             service_warranty_mode = :service_warranty_mode,
                             service_warranty_supplier_id = :service_warranty_supplier_id,
                             warranty_start = :warranty_start,
                             warranty_end = :warranty_end,
                             warranty_image = :warranty_image,
                             updated_at = :updated_at
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':name'              => $name,
                        ':code'              => $code,
                        ':manufacturer_code' => $manufacturerCode !== '' ? $manufacturerCode : null,
                        ':description'       => $description,
                        ':category'          => $category,
                        ':location'          => $location,
                        ':quantity_total'    => $quantityTotal,
                        ':quantity_available'=> $quantityAvailable,
                        ':status'            => $status,
                        ':picture'           => $picturePath,
                        ':service_supplier_id'         => $serviceSupplierId ?: null,
                        ':service_lab_id'              => $serviceLabId ?: null,
                        ':service_warranty_mode'       => $serviceWarrantyMode,
                        ':service_warranty_supplier_id'=> $serviceWarrantySupplierId ?: null,
                        ':warranty_start'    => $warrantyStart !== '' ? $warrantyStart : null,
                        ':warranty_end'      => $warrantyEnd !== '' ? $warrantyEnd : null,
                        ':warranty_image'    => $warrantyImagePath,
                        ':updated_at'        => date('Y-m-d H:i:s'),
                        ':id'                => $id,
                    ]);
                    $success = 'הציוד עודכן בהצלחה.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO equipment
                         (name, code, manufacturer_code, description, category, location, quantity_total, quantity_available, status, picture,
                          service_supplier_id, service_lab_id, service_warranty_mode, service_warranty_supplier_id,
                          warranty_start, warranty_end, warranty_image, created_at)
                         VALUES
                         (:name, :code, :manufacturer_code, :description, :category, :location, :quantity_total, :quantity_available, :status, :picture,
                          :service_supplier_id, :service_lab_id, :service_warranty_mode, :service_warranty_supplier_id,
                          :warranty_start, :warranty_end, :warranty_image, :created_at)'
                    );
                    $stmt->execute([
                        ':name'               => $name,
                        ':code'               => $code,
                        ':manufacturer_code'  => $manufacturerCode !== '' ? $manufacturerCode : null,
                        ':description'        => $description,
                        ':category'           => $category,
                        ':location'           => $location,
                        ':quantity_total'     => $quantityTotal,
                        ':quantity_available' => $quantityAvailable,
                        ':status'             => $status,
                        ':picture'            => $picturePath,
                        ':service_supplier_id'         => $serviceSupplierId ?: null,
                        ':service_lab_id'              => $serviceLabId ?: null,
                        ':service_warranty_mode'       => $serviceWarrantyMode,
                        ':service_warranty_supplier_id'=> $serviceWarrantySupplierId ?: null,
                        ':warranty_start'     => $warrantyStart !== '' ? $warrantyStart : null,
                        ':warranty_end'       => $warrantyEnd !== '' ? $warrantyEnd : null,
                        ':warranty_image'     => $warrantyImagePath,
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

                // אחרי שמירה מוצלחת נסגור את חלון העריכה (לא נטען שוב ציוד לעריכה)
                if ($error === '') {
                    $editingEquipment = null;
                    $editId = 0;
                    $viewId = 0;
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
            if ($notCsv) {
                $error = 'הקובץ חייב להיות בפורמט CSV (עם שורת כותרת ונתונים מופרדים בפסיקים).';
                header('Location: admin_equipment.php?import_error=' . urlencode($error));
                exit;
            }
            $systemCols = ['name', 'code', 'description', 'category', 'location', 'status'];
            for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                $systemCols[] = 'component_' . $n . '_name';
            }
            $requiredCols = ['name', 'code'];
            $headerNorm = [];
            foreach ($header as $idx => $col) {
                $key = strtolower(trim((string)$col));
                $key = preg_replace('/^\x{FEFF}/u', '', $key);
                if ($key !== '') $headerNorm[$key] = $idx;
            }
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
            $hasIssues = !empty($missingColumns) || !empty($unknownColumns) || !empty($duplicateCodes);
            if ($hasIssues) {
                $_SESSION['import_fix_type'] = 'equipment';
                $_SESSION['import_fix_headers'] = $header;
                $_SESSION['import_fix_rows'] = $rows;
                $_SESSION['import_fix_raw'] = base64_encode($rawContent);
                $_SESSION['import_fix_issues'] = [
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
    } elseif ($action === 'import_fixed') {
        if (isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'equipment') {
            $headers = $_SESSION['import_fix_headers'] ?? [];
            $rows = $_SESSION['import_fix_rows'] ?? [];
            $columnMapping = json_decode((string)($_POST['column_mapping'] ?? '{}'), true) ?: [];
            $missingDefaultsRaw = json_decode((string)($_POST['missing_defaults'] ?? '{}'), true) ?: [];
            $missingDefaults = [];
            foreach ($missingDefaultsRaw as $k => $v) {
                $key = strtolower(trim((string)$k));
                if ($key !== '' && (string)$v !== '') {
                    $missingDefaults[$key] = trim((string)$v);
                }
            }
            $duplicateActions = json_decode((string)($_POST['duplicate_actions'] ?? '{}'), true) ?: [];
            $headerNorm = [];
            foreach ($headers as $idx => $col) {
                $key = strtolower(trim((string)$col));
                $key = preg_replace('/^\x{FEFF}/u', '', $key);
                if ($key !== '') $headerNorm[$key] = (int)$idx;
            }
            foreach ($columnMapping as $fileCol => $systemColOrArray) {
                $fileColNorm = strtolower(trim((string)$fileCol));
                $fileColNorm = preg_replace('/^\x{FEFF}/u', '', $fileColNorm);
                if ($fileColNorm === '' || !array_key_exists($fileColNorm, $headerNorm)) continue;
                $sc = is_array($systemColOrArray) ? (string)($systemColOrArray[0] ?? '') : (string)$systemColOrArray;
                $sc = trim($sc);
                if ($sc !== '' && $sc !== '_default_') {
                    $headerNorm[$sc] = $headerNorm[$fileColNorm];
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
            // גישה חלופית: בונים מערך שורות מתוקנות (מפתחות קבועים) ואז מייבאים רק ממנו
            $rowsFixed = [];
            foreach ($rows as $ri => $row) {
                if (isset($skipRows[$ri])) continue;
                if (!is_array($row)) continue;
                $row = array_values($row);
                $fixed = [];
                foreach ($systemCols as $col) {
                    $idx = $headerNorm[$col] ?? null;
                    if ($idx !== null && array_key_exists($idx, $row)) {
                        $fixed[$col] = trim((string)$row[$idx]);
                    } else {
                        $fixed[$col] = (string)($missingDefaults[$col] ?? '');
                    }
                }
                if (isset($duplicateActions[$ri]['action']) && $duplicateActions[$ri]['action'] === 'replace' && isset($duplicateActions[$ri]['newCode'])) {
                    $fixed['code'] = trim((string)$duplicateActions[$ri]['newCode']);
                }
                if ($fixed['name'] !== '' && $fixed['code'] !== '') {
                    $rowsFixed[] = $fixed;
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
            foreach ($rowsFixed as $r) {
                $name = $r['name'] ?? '';
                $code = $r['code'] ?? '';
                $statusVal = $r['status'] ?? '';
                if (!in_array($statusVal, ['active', 'out', 'disabled'], true)) {
                    $statusVal = 'active';
                }
                try {
                    $insert->execute([
                        ':name' => $name,
                        ':code' => $code,
                        ':description' => $r['description'] ?? '',
                        ':category' => $r['category'] ?? '',
                        ':location' => $r['location'] ?? '',
                        ':status' => $statusVal,
                        ':created_at' => $now,
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                        $cName = trim((string)($r['component_' . $n . '_name'] ?? ''));
                        if ($cName === '') continue;
                        try {
                            $insertComp->execute([':equipment_id' => $newId, ':name' => $cName, ':created_at' => $now]);
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
                    $error = 'לא יובאו פריטים. וודא שטורים "שם" ו"קוד" ממופים או מולאו ערך ברירת מחדל.';
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
            $key = preg_replace('/^\x{FEFF}/u', '', $key);
            if ($key !== '') $headerNorm[$key] = (int)$idx;
        }
        $systemColsDirect = ['name', 'code', 'description', 'category', 'location', 'status'];
        for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
            $systemColsDirect[] = 'component_' . $n . '_name';
        }
        $rowsFixed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $row = array_values($row);
            $fixed = [];
            foreach ($systemColsDirect as $col) {
                $idx = $headerNorm[$col] ?? null;
                $fixed[$col] = ($idx !== null && array_key_exists($idx, $row)) ? trim((string)$row[$idx]) : '';
            }
            if ($fixed['name'] !== '' && $fixed['code'] !== '') {
                $rowsFixed[] = $fixed;
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
        foreach ($rowsFixed as $r) {
            $statusVal = ($r['status'] ?? '') !== '' && in_array($r['status'], ['active', 'out', 'disabled'], true) ? $r['status'] : 'active';
            try {
                $insert->execute([
                    ':name' => $r['name'] ?? '', ':code' => $r['code'] ?? '', ':description' => $r['description'] ?? '',
                    ':category' => $r['category'] ?? '', ':location' => $r['location'] ?? '',
                    ':status' => $statusVal, ':created_at' => $now,
                ]);
                $newId = (int)$pdo->lastInsertId();
                for ($n = 1; $n <= MAX_EQUIPMENT_COMPONENTS; $n++) {
                    $cName = trim((string)($r['component_' . $n . '_name'] ?? ''));
                    if ($cName === '') continue;
                    try {
                        $insertComp->execute([':equipment_id' => $newId, ':name' => $cName, ':created_at' => $now]);
                    } catch (Throwable $e) {}
                }
                $imported++;
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE') || !str_contains($e->getMessage(), 'code')) {
                    $msg = $e->getMessage();
                    $short = preg_replace('/[^\p{L}\p{N}\s\-_]/u', ' ', $msg);
                    $error = $error ?: ('שגיאה בייבוא.' . (trim(mb_substr($short, 0, 80)) !== '' ? ' (' . trim(mb_substr($short, 0, 80)) . ')' : ''));
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

$sql = 'SELECT id, name, code, description, category, location, quantity_total, quantity_available, status, picture, created_at, updated_at,
               (SELECT COUNT(*) FROM equipment_components ec WHERE ec.equipment_id = equipment.id) AS components_count
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
    if ($filterWarehouse === '__none__') {
        // ציוד ללא מחסן (NULL או ריק)
        $conditions[] = "(location IS NULL OR TRIM(location) = '')";
    } else {
        $conditions[]   = 'location = :loc';
        $params[':loc'] = $filterWarehouse;
    }
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
            justify-content: center;
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
            padding: 0.5rem 1.1rem;
        }
        .btn.danger {
            background: #ef4444;
        }
        .toolbar-equipment .toolbar-right .btn {
            margin-bottom: 0.5rem;
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
        .has-components-link {
            font-weight: 600;
            color: #1d4ed8;
        }
        .has-components-link:hover {
            color: #1e40af;
        }
        .components-badge {
            display: inline-block;
            margin-right: 0.25rem;
            padding: 0.05rem 0.4rem;
            border-radius: 999px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.7rem;
            vertical-align: middle;
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
        /* .main-nav-sub מוגדר ב־admin_header.php */
        .import-fix-section {
            margin-bottom: 1rem;
        }
        footer {
            background: var(--gf-footer-bg, #111827);
            color: var(--gf-footer-text, #9ca3af);
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
            <form method="post" action="admin_equipment.php" enctype="multipart/form-data" id="equipment_import_form" style="margin-top:0.5rem;">
                <input type="hidden" name="action" value="import">
                <label class="file-drop-zone" for="equipment_csv_file_input" aria-label="העלאת קובץ CSV ליבוא ציוד">
                    <input type="file" name="csv_file" accept=".csv" required id="equipment_csv_file_input" class="file-drop-input">
                    <span class="file-drop-icon"><i data-lucide="upload" aria-hidden="true"></i></span>
                    <span class="file-drop-text">גרור קובץ CSV לכאן או לחץ לבחירה</span>
                    <span class="file-drop-hint">ייבוא רשימת ציוד מקובץ CSV</span>
                </label>
            </form>
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
                            <option value="__none__" <?= ($filterWarehouse ?? '') === '__none__' ? 'selected' : '' ?>>ללא מחסן</option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="filter-group">
                    <label>פנוי בין התאריכים</label>
                    <div class="date-picker">
                        <div class="date-picker-toggle" id="eq_date_picker_toggle">
                            <i data-lucide="calendar" class="date-picker-toggle-icon" aria-hidden="true"></i>
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
                              role="button"
                              title="נקה טווח"
                              aria-label="נקה טווח"><i data-lucide="x" aria-hidden="true"></i></span>

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
                                        <button type="button" id="eq_cal_close" class="icon-btn" title="סגירת לוח שנה" aria-label="סגירת לוח שנה"><i data-lucide="x" aria-hidden="true"></i></button>
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
    $isViewModeEq = ($viewId > 0 && $editingEquipment !== null);
    $isNewEquipment = isset($_GET['new']) && $_GET['new'] === '1';
    // טופס "הוספת פריט" נפתח אוטומטית אם יש שגיאה, עריכה קיימת, או בקשה מפורשת להוספה חדשה (?new=1)
    $showFormCard = ($editingEquipment !== null || $error !== '' || $isNewEquipment) && empty($_GET['import_fix']);
    ?>

    <div class="modal-backdrop" id="equipment_modal" style="display: <?= $showFormCard ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2><?= $isViewModeEq ? 'צפייה בציוד' : ($editingEquipment ? 'עריכת ציוד' : 'הוספת ציוד חדש') ?></h2>
                <button type="button" class="modal-close" id="equipment_modal_close" aria-label="סגירת חלון"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif ($success !== ''): ?>
                <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="admin_equipment.php<?= $editingEquipment && !$isViewModeEq ? '?edit_id=' . (int)$editingEquipment['id'] : '' ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $editingEquipment ? (int)$editingEquipment['id'] : 0 ?>">

                <div class="grid">
                    <div>
                        <label for="name">שם ציוד</label>
                        <input type="text" id="name" name="name" required <?= $isViewModeEq ? 'readonly' : '' ?>
                               value="<?= $editingEquipment ? htmlspecialchars($editingEquipment['name'], ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="code">קוד זיהוי / ברקוד</label>
                        <input type="text" id="code" name="code" required <?= $isViewModeEq ? 'readonly' : '' ?>
                               value="<?= $editingEquipment
                                   ? htmlspecialchars($editingEquipment['code'], ENT_QUOTES, 'UTF-8')
                                   : htmlspecialchars($nextCode, ENT_QUOTES, 'UTF-8') ?>">

                        <label for="manufacturer_code">קוד יצרן</label>
                        <input type="text"
                               id="manufacturer_code"
                               name="manufacturer_code"
                               <?= $isViewModeEq ? 'readonly' : '' ?>
                               value="<?= $editingEquipment ? htmlspecialchars((string)($editingEquipment['manufacturer_code'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="description">תיאור</label>
                        <textarea id="description" name="description" <?= $isViewModeEq ? 'readonly' : '' ?>><?= $editingEquipment ? htmlspecialchars($editingEquipment['description'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></textarea>

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
                                <button type="button" class="icon-btn" id="delete_picture_btn" title="מחיקת התמונה" aria-label="מחיקת התמונה"><i data-lucide="x" aria-hidden="true"></i></button>
                            </div>
                            <input type="hidden" name="delete_picture" id="delete_picture" value="0">
                        <?php endif; ?>
                        <?php if (!$isViewModeEq): ?>
                        <div class="file-drop-zone-wrap" style="max-width:280px;">
                            <label class="file-drop-zone" for="picture_file" aria-label="העלאת תמונת פריט">
                                <input type="file" id="picture_file" name="picture_file" accept="image/*" class="file-drop-input">
                                <span class="file-drop-icon"><i data-lucide="upload" aria-hidden="true"></i></span>
                                <span class="file-drop-text">גרור תמונה לכאן או לחץ לבחירה</span>
                                <span class="file-drop-hint">תמונת פריט (תמונה)</span>
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="category">קטגוריה</label>
                        <?php
                        $currentCategory = trim((string)($editingEquipment['category'] ?? ''));
                        ?>
                        <select id="category" name="category" <?= $isViewModeEq ? 'disabled' : '' ?>>
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
                        <select id="location" name="location" <?= $isViewModeEq ? 'disabled' : '' ?>>
                            <option value="">בחר מחסן...</option>
                            <option value="מחסן א" <?= $currentLocation === 'מחסן א' ? 'selected' : '' ?>>מחסן א</option>
                            <option value="מחסן ב" <?= $currentLocation === 'מחסן ב' ? 'selected' : '' ?>>מחסן ב</option>
                        </select>

                        <label for="status">סטטוס</label>
                        <select id="status" name="status" <?= $isViewModeEq ? 'disabled' : '' ?>>
                            <?php
                            $statusValue = $editingEquipment['status'] ?? 'active';
                            ?>
                            <option value="active" <?= $statusValue === 'active' ? 'selected' : '' ?>>תקין</option>
                            <option value="out_of_service" <?= $statusValue === 'out_of_service' ? 'selected' : '' ?>>תקול</option>
                            <option value="missing" <?= $statusValue === 'missing' ? 'selected' : '' ?>>חסר</option>
                            <option value="disabled" <?= $statusValue === 'disabled' ? 'selected' : '' ?>>מושבת</option>
                        </select>
                    </div>

                    <?php
                    $currentServiceSupplierId         = (int)($editingEquipment['service_supplier_id'] ?? 0);
                    $currentServiceLabId              = (int)($editingEquipment['service_lab_id'] ?? 0);
                    $currentServiceWarrantyMode       = (string)($editingEquipment['service_warranty_mode'] ?? '');
                    $currentServiceWarrantySupplierId = (int)($editingEquipment['service_warranty_supplier_id'] ?? 0);
                    ?>
                    <div style="margin-top:0.75rem;">
                        <div class="muted-small" style="margin-bottom:0.25rem;font-weight:600;font-size:0.95rem;">שירות</div>
                        <div style="display: flex; justify-content: space-between;">
                            <div>
                                <label for="service_supplier_id">ספק</label>
                                <?php if ($isViewModeEq && $currentServiceSupplierId > 0 && isset($serviceSuppliersById[$currentServiceSupplierId])): ?>
                                    <a href="admin_suppliers.php?view_id=<?= $currentServiceSupplierId ?>"
                                       style="display:inline-block;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;background:#f9fafb;text-decoration:none;color:#2563eb;min-width:0;"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= htmlspecialchars((string)($serviceSuppliersById[$currentServiceSupplierId]['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <select id="service_supplier_id" name="service_supplier_id" <?= $isViewModeEq ? 'disabled' : '' ?>>
                                        <option value="0">ללא</option>
                                        <?php foreach ($serviceSuppliers['equipment'] as $sup): ?>
                                            <?php $sid = (int)($sup['id'] ?? 0); ?>
                                            <option value="<?= $sid ?>" <?= $currentServiceSupplierId === $sid ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)($sup['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label for="service_lab_id">מעבדה</label>
                                <?php if ($isViewModeEq && $currentServiceLabId > 0 && isset($serviceSuppliersById[$currentServiceLabId])): ?>
                                    <a href="admin_suppliers.php?view_id=<?= $currentServiceLabId ?>"
                                       style="display:inline-block;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;background:#f9fafb;text-decoration:none;color:#2563eb;min-width:0;"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= htmlspecialchars((string)($serviceSuppliersById[$currentServiceLabId]['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <select id="service_lab_id" name="service_lab_id" <?= $isViewModeEq ? 'disabled' : '' ?>>
                                        <option value="0">ללא</option>
                                        <?php foreach ($serviceSuppliers['lab'] as $sup): ?>
                                            <?php $sid = (int)($sup['id'] ?? 0); ?>
                                            <option value="<?= $sid ?>" <?= $currentServiceLabId === $sid ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)($sup['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label for="service_warranty_mode">אחריות</label>
                                <?php
                                $warrantySupplierId = 0;
                                if ($currentServiceWarrantyMode === 'by_supplier') {
                                    $warrantySupplierId = $currentServiceSupplierId;
                                } elseif ($currentServiceWarrantyMode === 'by_lab') {
                                    $warrantySupplierId = $currentServiceLabId;
                                } elseif (str_starts_with($currentServiceWarrantyMode, 'sup_')) {
                                    $warrantySupplierId = (int)substr($currentServiceWarrantyMode, 4);
                                } elseif ($currentServiceWarrantySupplierId > 0) {
                                    $warrantySupplierId = $currentServiceWarrantySupplierId;
                                }
                                ?>
                                <?php if ($isViewModeEq && $warrantySupplierId > 0 && isset($serviceSuppliersById[$warrantySupplierId])): ?>
                                    <a href="admin_suppliers.php?view_id=<?= $warrantySupplierId ?>"
                                       style="display:inline-block;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;background:#f9fafb;text-decoration:none;color:#2563eb;min-width:0;"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= htmlspecialchars((string)($serviceSuppliersById[$warrantySupplierId]['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <select id="service_warranty_mode" name="service_warranty_mode" <?= $isViewModeEq ? 'disabled' : '' ?>>
                                        <option value="">ללא</option>
                                        <option value="by_supplier" <?= $currentServiceWarrantyMode === 'by_supplier' ? 'selected' : '' ?>>על פי ספק</option>
                                        <option value="by_lab" <?= $currentServiceWarrantyMode === 'by_lab' ? 'selected' : '' ?>>על פי מעבדה</option>
                                        <?php foreach ($serviceSuppliers['warranty'] as $sup): ?>
                                            <?php $sid = (int)($sup['id'] ?? 0); ?>
                                            <option value="sup_<?= $sid ?>" <?= $currentServiceWarrantyMode === 'sup_'.$sid ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)($sup['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <input type="hidden" id="service_warranty_supplier_id" name="service_warranty_supplier_id"
                                       value="<?= $currentServiceWarrantySupplierId > 0 ? (int)$currentServiceWarrantySupplierId : 0 ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                $warrantyStartVal = (string)($editingEquipment['warranty_start'] ?? '');
                $warrantyEndVal   = (string)($editingEquipment['warranty_end'] ?? '');
                $warrantyImageVal = (string)($editingEquipment['warranty_image'] ?? '');
                ?>
                <div style="margin-top:0.75rem; margin-bottom:0.5rem;">
                    <div class="muted-small" style="margin-bottom:0.25rem;font-weight:600;font-size:0.95rem;">אחריות</div>
                    <div style="display:flex; gap:0.75rem; align-items:flex-start; flex-wrap:wrap;">
                        <div style="min-width:160px;">
                            <label for="warranty_start">התחלת אחריות</label>
                            <input type="date" id="warranty_start" name="warranty_start"
                                   value="<?= htmlspecialchars($warrantyStartVal, ENT_QUOTES, 'UTF-8') ?>"
                                   <?= $isViewModeEq ? 'readonly' : '' ?>>
                        </div>
                        <div style="min-width:160px;">
                            <label for="warranty_end">סיום אחריות</label>
                            <input type="date" id="warranty_end" name="warranty_end"
                                   value="<?= htmlspecialchars($warrantyEndVal, ENT_QUOTES, 'UTF-8') ?>"
                                   <?= $isViewModeEq ? 'readonly' : '' ?>>
                        </div>
                        <div style="min-width:290px;">
                            <?php if ($warrantyImageVal !== ''): ?>
                                <label>תמונת אחריות נוכחית</label>
                                <div style="margin-bottom:0.25rem;">
                                    <a href="<?= htmlspecialchars($warrantyImageVal, ENT_QUOTES, 'UTF-8') ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       style="color:#2563eb; text-decoration:underline; font-size:0.85rem;">
                                        הצג תמונת אחריות
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (!$isViewModeEq): ?>
                            <div class="file-drop-zone-wrap" style="max-width:290px;">
                                <label class="file-drop-zone" for="warranty_file" aria-label="העלאת תמונת אחריות">
                                    <input type="file" id="warranty_file" name="warranty_file" accept="image/*" class="file-drop-input">
                                    <span class="file-drop-icon"><i data-lucide="upload" aria-hidden="true"></i></span>
                                    <span class="file-drop-text">גרור תמונת אחריות לכאן או לחץ לבחירה</span>
                                    <span class="file-drop-hint">תמונת אחריות (תמונה)</span>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top:1rem; margin-bottom:1rem;">
                    <?php if (!$isViewModeEq): ?>
                    <button type="button" class="btn secondary small" id="equipment_toggle_components_btn">הוספת פרטי ציוד</button>
                    <?php endif; ?>
                    <div id="equipment_form_components_section" style="<?= $isViewModeEq ? '' : 'display:none;' ?> margin-top:0.75rem; padding:0.75rem; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb;">
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
                                        <input type="text" name="component_name[]" <?= $isViewModeEq ? 'readonly' : '' ?> style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($formComps as $fc): ?>
                                <tr class="component-row">
                                    <td style="padding:0.35rem 0.5rem;">
                                        <input type="text" name="component_name[]" value="<?= htmlspecialchars($fc['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= $isViewModeEq ? 'readonly' : '' ?> style="width:100%;padding:0.3rem 0.4rem;border-radius:6px;border:1px solid #d1d5db;">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                        <?php if (!$isViewModeEq): ?>
                        <button type="button" class="btn secondary small" id="equipment_form_add_component_row" data-max="<?= MAX_EQUIPMENT_COMPONENTS ?>">הוסף שורה</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$isViewModeEq): ?>
                    <button type="submit" class="btn">
                        <?= $editingEquipment ? 'שמירת שינויים' : 'הוספת ציוד' ?>
                    </button>
                <?php endif; ?>
                <?php if ($editingEquipment && !$isViewModeEq): ?>
                    <a href="admin_equipment.php" class="btn secondary">ביטול עריכה</a>
                <?php else: ?>
                    <button type="button" class="btn secondary" id="equipment_modal_cancel"><?= $isViewModeEq ? 'סגירה' : 'ביטול' ?></button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="modal-backdrop" id="equipment_bulk_add_modal" style="display: none;">
        <div class="modal-card" style="max-width: 95%; width: 900px;">
            <div class="modal-header">
                <h2>הוספת מספר פריטים</h2>
                <button type="button" class="modal-close" id="bulk_add_modal_close" aria-label="סגירה"><i data-lucide="x" aria-hidden="true"></i></button>
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

    <?php if ($show_import_fix_modal && $import_fix_data): ?>
    <div class="modal-backdrop" id="import_fix_modal" style="display: flex;">
        <div class="modal-card" style="max-width: 95%; width: 640px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h2>תיקון ייבוא ציוד</h2>
                <button type="button" class="modal-close" id="import_fix_modal_close" aria-label="סגירה"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>
            <?php if ($error !== ''): ?>
            <div class="flash error" style="margin-bottom:0.75rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div id="import_fix_content">
                <?php $iss = $import_fix_data['issues'];
                $defaultTargets = array_diff($iss['missing_columns'] ?? [], ['code']);
                ?>
                <?php if (!empty($iss['unknown_columns'])): ?>
                <div class="import-fix-section">
                    <p class="muted-small">טורים בקובץ שלא במערכת: מפה לטור מערכת, או בחר "ערך ברירת מחדל" והזן ערך (לא זמין לטור קוד – ייחודי).</p>
                    <?php foreach ($iss['unknown_columns'] as $uc): ?>
                    <div class="import-fix-map-row" style="display:flex; align-items:center; gap:0.5rem; margin:0.4rem 0; flex-wrap:wrap;">
                        <span style="min-width:100px;"><?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?></span>
                        <select class="import-fix-map-select" data-file-col="<?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?>" style="padding:0.35rem; min-width:160px;">
                            <option value="">— ביטול (התעלם מטור)</option>
                            <?php foreach ($import_fix_system_columns as $sc): ?>
                            <option value="<?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                            <?php if (!empty($defaultTargets)): ?>
                            <option value="_default_">ערך ברירת מחדל</option>
                            <?php endif; ?>
                        </select>
                        <span class="import-fix-default-wrap" style="display:none; align-items:center; gap:0.35rem;">
                            <span class="muted-small">עבור טור:</span>
                            <select class="import-fix-default-target" style="padding:0.35rem; min-width:120px;">
                                <?php foreach ($defaultTargets as $dt): ?>
                                <option value="<?= htmlspecialchars($dt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dt, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="import-fix-default-value" placeholder="ערך ברירת מחדל" style="padding:0.35rem; width:140px;" data-required-for="name">
                        </span>
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
        var form = document.getElementById('import_fixed_form');
        document.querySelectorAll('.import-fix-map-select').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var row = sel.closest('.import-fix-map-row');
                var wrap = row ? row.querySelector('.import-fix-default-wrap') : null;
                if (wrap) wrap.style.display = sel.value === '_default_' ? 'flex' : 'none';
            });
        });
        document.querySelectorAll('.import-fix-map-row').forEach(function(row) {
            var sel = row.querySelector('.import-fix-map-select');
            var wrap = row.querySelector('.import-fix-default-wrap');
            if (wrap) wrap.style.display = (sel && sel.value === '_default_') ? 'flex' : 'none';
        });
        form.addEventListener('submit', function(e) {
            var mapping = {};
            var missing = {};
            document.querySelectorAll('.import-fix-map-row').forEach(function(row) {
                var fileCol = row.querySelector('.import-fix-map-select').getAttribute('data-file-col');
                var mainVal = row.querySelector('.import-fix-map-select').value;
                if (mainVal === '_default_') {
                    var targetSel = row.querySelector('.import-fix-default-target');
                    var valInp = row.querySelector('.import-fix-default-value');
                    if (targetSel && valInp) {
                        var target = targetSel.value;
                        var val = valInp.value.trim();
                        if (target === 'name' && val === '') {
                            e.preventDefault();
                            valInp.style.borderColor = '#dc2626';
                            valInp.setAttribute('title', 'שדה חובה עבור טור שם');
                            return;
                        }
                        if (target && val !== '') missing[target] = val;
                    }
                } else if (mainVal !== '') {
                    mapping[fileCol] = mainVal;
                }
            });
            document.querySelectorAll('.import-fix-default-value').forEach(function(inp) {
                inp.style.borderColor = '';
                inp.removeAttribute('title');
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
            document.querySelectorAll('.import-fix-map-row').forEach(function(row) {
                var sel = row.querySelector('.import-fix-map-select');
                if (sel && sel.value !== '' && sel.value !== '_default_') used[sel.value] = true;
            });
            document.querySelectorAll('.import-fix-map-select').forEach(function(s) {
                var currentVal = s.value;
                for (var i = 0; i < s.options.length; i++) {
                    var opt = s.options[i];
                    if (opt.value === '' || opt.value === '_default_') continue;
                    opt.disabled = used[opt.value] && opt.value !== currentVal;
                }
            });
        }
        document.querySelectorAll('.import-fix-map-select').forEach(function(sel) {
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
                <button type="button" class="modal-close" id="components_modal_close" aria-label="סגירת חלון"><i data-lucide="x" aria-hidden="true"></i></button>
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
                                $hasComponents = (int)($item['components_count'] ?? 0) > 0;
                                $linkParams = array_merge(
                                    ['components_for' => (int)$item['id']],
                                    $tabBaseParams,
                                    ($equipmentTab !== 'all' && $equipmentTab !== '') ? ['equipment_tab' => $equipmentTab] : []
                                );
                                ?>
                                <a href="admin_equipment.php?<?= http_build_query($linkParams) ?>"
                                   class="<?= $hasComponents ? 'muted-small has-components-link' : 'muted-small' ?>"
                                   title="<?= $hasComponents ? 'לצפייה ברכיבי הפריט' : 'פריט ללא רכיבים נלווים' ?>">
                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($hasComponents): ?>
                                        <span class="components-badge">+ רכיבים נלווים</span>
                                    <?php endif; ?>
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
                                    $statusLabel = 'תקול';
                                } elseif ($item['status'] === 'missing') {
                                    $statusClass = 'status-out';
                                    $statusLabel = 'חסר';
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
                                    <?php $viewParams = array_merge(['view_id' => (int)$item['id']], $tabBaseParams, ($equipmentTab !== 'all' && $equipmentTab !== '') ? ['equipment_tab' => $equipmentTab] : []); ?>
                                    <a href="admin_equipment.php?<?= http_build_query($viewParams) ?>" class="icon-btn" title="צפייה בפריט" aria-label="צפייה בפריט">
                                        <i data-lucide="eye" aria-hidden="true"></i>
                                    </a>
                                    <?php $editParams = array_merge(['edit_id' => (int)$item['id']], $tabBaseParams, ($equipmentTab !== 'all' && $equipmentTab !== '') ? ['equipment_tab' => $equipmentTab] : []); ?>
                                    <a href="admin_equipment.php?<?= http_build_query($editParams) ?>" class="icon-btn" title="עריכה" aria-label="עריכה"><i data-lucide="pencil" aria-hidden="true"></i></a>
                                    <form method="post" action="admin_equipment.php" onsubmit="return confirm('למחוק את הפריט הזה?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="icon-btn" title="מחיקה" aria-label="מחיקה"><i data-lucide="trash-2" aria-hidden="true"></i></button>
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
<?php include __DIR__ . '/admin_footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var addBtn = document.getElementById('toggle_add_equipment_btn');
    var formModal = document.getElementById('equipment_modal');
    var formClose = document.getElementById('equipment_modal_close');
    var formCancel = document.getElementById('equipment_modal_cancel');
    var bulkAddBtn = document.getElementById('toggle_bulk_add_btn');
    var bulkAddTbody = document.getElementById('bulk_add_tbody');
    var bulkAddRowBtn = document.getElementById('bulk_add_row_btn');
    var componentsModal = document.getElementById('components_modal');
    var componentsClose = document.getElementById('components_modal_close');
    var addComponentRowBtn = document.getElementById('components_add_row_btn');
    var componentsTableBody = document.getElementById('components_table_body');
    var pictureFileInput = document.getElementById('picture_file');
    var deletePictureBtn = document.getElementById('delete_picture_btn');
    var deletePictureInput = document.getElementById('delete_picture');
    var existingPictureRow = document.getElementById('existing_picture_row');

    // מספר סידורי אוטומטי: בעת הזנת שם ציוד בפריט חדש – קוד הזיהוי יקבע ל-(המקס' עבור אותו שם + 1)
    (function () {
        var isNew = <?= (!$editingEquipment && !$isViewModeEq) ? 'true' : 'false' ?>;
        if (!isNew) return;
        var nameInput = document.getElementById('name');
        var codeInput = document.getElementById('code');
        if (!nameInput || !codeInput) return;

        // בניית מפה name->max מתוך כל הציוד הטעון (לא מדויק לכל פילטרים אבל מספיק כברירת מחדל)
        var maxByName = {};
        try {
            var rows = <?= json_encode(array_map(function ($r) {
                return [
                    'name' => trim((string)($r['name'] ?? '')),
                    'code' => trim((string)($r['code'] ?? '')),
                ];
            }, $allEquipment ?? []), JSON_UNESCAPED_UNICODE) ?>;
            if (Array.isArray(rows)) {
                rows.forEach(function (r) {
                    var n = (r && r.name) ? String(r.name).trim() : '';
                    var c = (r && r.code) ? String(r.code).trim() : '';
                    if (!n || !c) return;
                    if (!/^\d+$/.test(c)) return;
                    var num = parseInt(c, 10);
                    if (!maxByName[n] || num > maxByName[n]) maxByName[n] = num;
                });
            }
        } catch (e) {
            maxByName = {};
        }

        function setNextIfEmpty() {
            if (String(codeInput.value || '').trim() !== '') return;
            var n = String(nameInput.value || '').trim();
            if (!n) return;
            var mx = maxByName[n] || 0;
            codeInput.value = String(mx + 1);
        }

        nameInput.addEventListener('blur', setNextIfEmpty);
        nameInput.addEventListener('change', setNextIfEmpty);
    })();

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

    function closeEquipmentModal() {
        if (formModal) {
            formModal.style.display = 'none';
        }
    }

    // כפתור "הוספת פריט ציוד" תמיד פותח מסך הוספה חדש ונקי
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            window.location.href = 'admin_equipment.php?new=1';
        });
    }

    if (formClose) {
        formClose.addEventListener('click', function () {
            closeEquipmentModal();
            if (addBtn) addBtn.textContent = 'הוספת פריט ציוד';
        });
    }
    if (formCancel) {
        formCancel.addEventListener('click', function () {
            closeEquipmentModal();
            if (addBtn) addBtn.textContent = 'הוספת פריט ציוד';
        });
    }

    var csvImportInput = document.getElementById('equipment_csv_file_input');
    var importForm = document.getElementById('equipment_import_form');
    if (csvImportInput && importForm) {
        csvImportInput.addEventListener('change', function () {
            if (csvImportInput.files && csvImportInput.files.length > 0) importForm.submit();
        });
    }
    var bulkAddModal = document.getElementById('equipment_bulk_add_modal');
    var bulkAddModalClose = document.getElementById('bulk_add_modal_close');
    if (bulkAddBtn && bulkAddModal) {
        bulkAddBtn.addEventListener('click', function () {
            bulkAddModal.style.display = 'flex';
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

