<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo     = get_db();
$error   = '';
$success = '';

// ספק בעריכה / צפייה (אם נבחר מהטבלה)
$editId          = isset($_GET['edit_id']) ? (int)($_GET['edit_id'] ?? 0) : 0;
$viewId          = isset($_GET['view_id']) ? (int)($_GET['view_id'] ?? 0) : 0;
$editingSupplier = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // שליפת איש הקשר הראשון מהמערך (contact_name[] וכו') לצורך שמירה בשדות הקיימים
    $postedContactNames  = $_POST['contact_name']  ?? [];
    $postedContactPhones = $_POST['contact_phone'] ?? [];
    $postedContactEmails = $_POST['contact_email'] ?? [];
    if (!is_array($postedContactNames))  $postedContactNames  = [];
    if (!is_array($postedContactPhones)) $postedContactPhones = [];
    if (!is_array($postedContactEmails)) $postedContactEmails = [];

    $primaryContactName  = trim((string)($postedContactNames[0]  ?? ''));
    $primaryContactPhone = trim((string)($postedContactPhones[0] ?? ''));
    $primaryContactEmail = trim((string)($postedContactEmails[0] ?? ''));

    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'יש לבחור קובץ CSV לייבוא.';
        } else {
            $tmpName = (string)($_FILES['csv_file']['tmp_name'] ?? '');
            $rawContent = file_get_contents($tmpName);
            $encoding = @mb_detect_encoding($rawContent, ['UTF-8', 'ISO-8859-1', 'ASCII'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $converted = @mb_convert_encoding($rawContent, 'UTF-8', $encoding);
                if ($converted !== false) {
                    $rawContent = $converted;
                }
            }
            $lines = preg_split('/\r\n|\r|\n/', $rawContent);
            $delimiter = ',';
            if (count($lines) > 0 && trim($lines[0]) !== '') {
                $firstLine = $lines[0];
                if (strpos($firstLine, "\t") !== false && strpos($firstLine, ',') === false) {
                    $delimiter = "\t";
                } elseif (strpos($firstLine, ';') !== false && strpos($firstLine, ',') === false) {
                    $delimiter = ';';
                }
            }
            $header = count($lines) > 0 ? str_getcsv($lines[0], $delimiter) : null;
            $rows = [];
            for ($i = 1; $i < count($lines); $i++) {
                if (trim($lines[$i]) === '') continue;
                $rows[] = str_getcsv($lines[$i], $delimiter);
            }
            $notCsv = ($header === null || count($header) < 1);
            if ($notCsv) {
                $error = 'הקובץ חייב להיות בפורמט CSV (עם שורת כותרת ונתונים מופרדים בפסיקים).';
                header('Location: admin_suppliers.php?import_error=' . urlencode($error));
                exit;
            }

            $systemCols   = ['company_name', 'company_code', 'contact_name', 'phone', 'email', 'address', 'website', 'service_type'];
            $requiredCols = ['company_name', 'company_code'];

            $headerNorm = [];
            foreach ($header as $idx => $col) {
                $key = strtolower(trim((string)$col));
                if ($key !== '') {
                    $headerNorm[$key] = $idx;
                }
            }

            $missingColumns = array_diff($requiredCols, array_keys($headerNorm));
            $unknownColumns = array_diff(array_keys($headerNorm), $systemCols);

            // בדיקת כפילויות לפי company_code אם קיים, אחרת לפי company_name
            $duplicateSuppliers = [];
            if (isset($headerNorm['company_code'])) {
                $codeIdx = $headerNorm['company_code'];
                $existingCodes = $pdo->query("SELECT company_code FROM suppliers WHERE company_code IS NOT NULL AND company_code <> ''")->fetchAll(PDO::FETCH_COLUMN);
                $existingSet = array_flip($existingCodes);
                foreach ($rows as $ri => $row) {
                    $code = isset($row[$codeIdx]) ? trim((string)$row[$codeIdx]) : '';
                    if ($code !== '' && isset($existingSet[$code])) {
                        $duplicateSuppliers[] = ['row' => $ri, 'company_code' => $code];
                    }
                }
            }

            $hasIssues = !empty($missingColumns) || !empty($unknownColumns) || !empty($duplicateSuppliers);
            if ($hasIssues) {
                $_SESSION['import_fix_type'] = 'suppliers';
                $_SESSION['import_fix_headers'] = $header;
                $_SESSION['import_fix_rows'] = $rows;
                $_SESSION['import_fix_raw'] = base64_encode($rawContent);
                $_SESSION['import_fix_issues'] = [
                    'missing_columns'      => array_values($missingColumns),
                    'unknown_columns'      => array_values($unknownColumns),
                    'duplicate_suppliers'  => $duplicateSuppliers,
                ];
                $_SESSION['import_fix_delimiter'] = $delimiter;
                header('Location: admin_suppliers.php?import_fix=1');
                exit;
            }

            // אין בעיות – נייבא ישירות
            $handle = fopen($tmpName, 'r');
            if ($handle === false) {
                $error = 'לא ניתן לקרוא את קובץ ה-CSV.';
            } else {
                $imported = 0;
                $header   = fgetcsv($handle);
                if (!is_array($header)) {
                    $error = 'קובץ ה-CSV ריק או בפורמט שגוי.';
                } else {
                    $map = [];
                    foreach ($header as $idx => $col) {
                        $col = strtolower(trim((string)$col));
                        $map[$col] = $idx;
                    }
                    $insert = $pdo->prepare("
                        INSERT INTO suppliers
                            (company_name, company_code, contact_name, phone, email, address, website, service_type, created_at)
                        VALUES
                            (:company_name, :company_code, :contact_name, :phone, :email, :address, :website, :service_type, :created_at)
                    ");
                    $now = date('Y-m-d H:i:s');
                    while (($row = fgetcsv($handle)) !== false) {
                        $get = function (string $key) use ($map, $row): string {
                            if (!isset($map[$key])) {
                                return '';
                            }
                            $i = $map[$key];
                            return isset($row[$i]) ? trim((string)$row[$i]) : '';
                        };
                        $companyName = $get('company_name');
                        $companyCode = $get('company_code');
                        if ($companyName === '' || $companyCode === '') {
                            continue;
                        }
                        try {
                            $insert->execute([
                                ':company_name' => $companyName,
                                ':company_code' => $companyCode,
                                ':contact_name' => $get('contact_name'),
                                ':phone'        => $get('phone'),
                                ':email'        => $get('email'),
                                ':address'      => $get('address'),
                                ':website'      => $get('website'),
                                ':service_type' => $get('service_type'),
                                ':created_at'   => $now,
                            ]);
                            $imported++;
                        } catch (PDOException $e) {
                            continue;
                        }
                    }
                    fclose($handle);
                    if ($imported > 0 && $error === '') {
                        $success = 'ייבוא הושלם. נוספו ' . $imported . ' ספקים.';
                    } elseif ($error === '') {
                        $error = 'לא נוספו ספקים מהקובץ.';
                    }
                }
            }
        }
    } elseif ($action === 'import_fixed') {
        if (isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'suppliers') {
            $headers = $_SESSION['import_fix_headers'] ?? [];
            $rows = $_SESSION['import_fix_rows'] ?? [];
            $columnMapping   = json_decode((string)($_POST['column_mapping'] ?? '{}'), true) ?: [];
            $missingDefaults = json_decode((string)($_POST['missing_defaults'] ?? '{}'), true) ?: [];
            $duplicateActions = json_decode((string)($_POST['duplicate_actions'] ?? '{}'), true) ?: [];

            $headerNorm = [];
            foreach ($headers as $idx => $col) {
                $key = strtolower(trim((string)$col));
                if ($key !== '') {
                    $headerNorm[$key] = $idx;
                }
            }
            foreach ($columnMapping as $fileCol => $systemCol) {
                if ($systemCol !== '' && $systemCol !== null && isset($headerNorm[$fileCol])) {
                    $headerNorm[$systemCol] = $headerNorm[$fileCol];
                }
            }

            $skipRows = [];
            foreach ($duplicateActions as $ri => $act) {
                if (isset($act['action']) && $act['action'] === 'skip') {
                    $skipRows[(int)$ri] = true;
                }
            }

            $imported = 0;
            $updated  = 0;

            foreach ($rows as $ri => $row) {
                if (isset($skipRows[$ri])) {
                    continue;
                }
                $get = function (string $col) use ($headerNorm, $row, $missingDefaults): string {
                    $idx = $headerNorm[$col] ?? null;
                    $val = ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
                    if ($val === '' && isset($missingDefaults[$col])) {
                        $val = (string)$missingDefaults[$col];
                    }
                    return $val;
                };

                $companyName = $get('company_name');
                $companyCode = $get('company_code');
                if ($companyName === '' || $companyCode === '') {
                    continue;
                }
                $contactName = $get('contact_name');
                $phone       = $get('phone');
                $email       = $get('email');
                $address     = $get('address');
                $website     = $get('website');
                $serviceType = $get('service_type');

                $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE company_code = :code LIMIT 1');
                $stmt->execute([':code' => $companyCode]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $id = (int)$existing['id'];
                    $upd = $pdo->prepare('UPDATE suppliers SET company_name=:company_name, contact_name=:contact_name, phone=:phone, email=:email, address=:address, website=:website, service_type=:service_type WHERE id=:id');
                    $upd->execute([
                        ':company_name' => $companyName,
                        ':contact_name' => $contactName,
                        ':phone'        => $phone,
                        ':email'        => $email,
                        ':address'      => $address,
                        ':website'      => $website,
                        ':service_type' => $serviceType,
                        ':id'           => $id,
                    ]);
                    $updated++;
                } else {
                    $ins = $pdo->prepare('INSERT INTO suppliers (company_name, company_code, contact_name, phone, email, address, website, service_type, created_at) VALUES (:company_name, :company_code, :contact_name, :phone, :email, :address, :website, :service_type, :created_at)');
                    $ins->execute([
                        ':company_name' => $companyName,
                        ':company_code' => $companyCode,
                        ':contact_name' => $contactName,
                        ':phone'        => $phone,
                        ':email'        => $email,
                        ':address'      => $address,
                        ':website'      => $website,
                        ':service_type' => $serviceType,
                        ':created_at'   => date('Y-m-d H:i:s'),
                    ]);
                    $imported++;
                }
            }

            unset($_SESSION['import_fix_type'], $_SESSION['import_fix_headers'], $_SESSION['import_fix_rows'], $_SESSION['import_fix_raw'], $_SESSION['import_fix_issues'], $_SESSION['import_fix_delimiter']);
            $_SESSION['admin_suppliers_success'] = 'ייבוא הושלם. נוספו ' . $imported . ' ספקים, עודכנו ' . $updated . ' ספקים.';
            header('Location: admin_suppliers.php');
            exit;
        }
    } elseif ($action === 'create') {
        $companyName = trim((string)($_POST['company_name'] ?? ''));
        $companyCode = trim((string)($_POST['company_code'] ?? ''));
        $contactName = $primaryContactName;
        $phone       = $primaryContactPhone;
        $email       = $primaryContactEmail;
        $address     = trim((string)($_POST['address'] ?? ''));
        $website     = trim((string)($_POST['website'] ?? ''));
        $serviceType = trim((string)($_POST['service_type'] ?? ''));

        if ($companyName === '') {
            $error = 'יש להזין שם חברה.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO suppliers
                        (company_name, company_code, contact_name, phone, email, address, website, service_type, created_at)
                    VALUES
                        (:company_name, :company_code, :contact_name, :phone, :email, :address, :website, :service_type, :created_at)
                ");
                $stmt->execute([
                    ':company_name' => $companyName,
                    ':company_code' => $companyCode,
                    ':contact_name' => $contactName,
                    ':phone'        => $phone,
                    ':email'        => $email,
                    ':address'      => $address,
                    ':website'      => $website,
                    ':service_type' => $serviceType,
                    ':created_at'   => date('Y-m-d H:i:s'),
                ]);
                $newId = (int)$pdo->lastInsertId();

                // שמירת אנשי קשר נוספים (אינדקס 1–2) בטבלת supplier_contacts
                if ($newId > 0) {
                    $insContact = $pdo->prepare("
                        INSERT INTO supplier_contacts (supplier_id, name, phone, email)
                        VALUES (:supplier_id, :name, :phone, :email)
                    ");
                    // מגבילים עד 3 אנשי קשר בסה\"כ – הראשון כבר שמור ב-suppliers
                    $maxContacts = 3;
                    $total = min(count($postedContactNames), $maxContacts);
                    for ($i = 1; $i < $total; $i++) {
                        $n = trim((string)($postedContactNames[$i]  ?? ''));
                        $p = trim((string)($postedContactPhones[$i] ?? ''));
                        $e = trim((string)($postedContactEmails[$i] ?? ''));
                        if ($n === '' && $p === '' && $e === '') {
                            continue;
                        }
                        $insContact->execute([
                            ':supplier_id' => $newId,
                            ':name'        => $n,
                            ':phone'       => $p,
                            ':email'       => $e,
                        ]);
                    }
                }
                $success = 'הספק נוסף בהצלחה.';
            } catch (PDOException $e) {
                $error = 'שגיאה בשמירת הספק.';
            }
        }
    } elseif ($action === 'update') {
        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $companyName = trim((string)($_POST['company_name'] ?? ''));
        $companyCode = trim((string)($_POST['company_code'] ?? ''));
        $contactName = $primaryContactName;
        $phone       = $primaryContactPhone;
        $email       = $primaryContactEmail;
        $address     = trim((string)($_POST['address'] ?? ''));
        $website     = trim((string)($_POST['website'] ?? ''));
        $serviceType = trim((string)($_POST['service_type'] ?? ''));

        if ($id <= 0 || $companyName === '') {
            $error = 'יש להזין שם חברה תקין.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE suppliers
                    SET company_name = :company_name,
                        company_code = :company_code,
                        contact_name = :contact_name,
                        phone        = :phone,
                        email        = :email,
                        address      = :address,
                        website      = :website,
                        service_type = :service_type,
                        updated_at   = :updated_at
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':company_name' => $companyName,
                    ':company_code' => $companyCode,
                    ':contact_name' => $contactName,
                    ':phone'        => $phone,
                    ':email'        => $email,
                    ':address'      => $address,
                    ':website'      => $website,
                    ':service_type' => $serviceType,
                    ':updated_at'   => date('Y-m-d H:i:s'),
                    ':id'           => $id,
                ]);

                // עדכון אנשי קשר נוספים בטבלת supplier_contacts
                if ($id > 0) {
                    // מוחקים אנשי קשר קיימים לאותו ספק ושומרים מחדש מהרשימה בטופס
                    $pdo->prepare("DELETE FROM supplier_contacts WHERE supplier_id = :sid")
                        ->execute([':sid' => $id]);

                    $insContact = $pdo->prepare("
                        INSERT INTO supplier_contacts (supplier_id, name, phone, email)
                        VALUES (:supplier_id, :name, :phone, :email)
                    ");
                    $maxContacts = 3;
                    $total = min(count($postedContactNames), $maxContacts);
                    for ($i = 1; $i < $total; $i++) {
                        $n = trim((string)($postedContactNames[$i]  ?? ''));
                        $p = trim((string)($postedContactPhones[$i] ?? ''));
                        $e = trim((string)($postedContactEmails[$i] ?? ''));
                        if ($n === '' && $p === '' && $e === '') {
                            continue;
                        }
                        $insContact->execute([
                            ':supplier_id' => $id,
                            ':name'        => $n,
                            ':phone'       => $p,
                            ':email'       => $e,
                        ]);
                    }
                }
                $success = 'הספק עודכן בהצלחה.';
                // לאחר עדכון מוצלח לא נפתח שוב את חלון העריכה
                $editId = 0;
            } catch (PDOException $e) {
                $error = 'שגיאה בעדכון הספק.';
            }
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $success = 'הספק נמחק בהצלחה.';
            } catch (PDOException $e) {
                $error = 'שגיאה במחיקת הספק.';
            }
        }
    }
}

// אם יש ספק בעריכה/צפייה (בקשת GET עם edit_id/view_id תקין) – נטען אותו למודאל
if (($editId > 0 || $viewId > 0) && $error === '') {
    $loadId = $editId > 0 ? $editId : $viewId;
    try {
        $stmt = $pdo->prepare("
            SELECT id, company_name, company_code, contact_name, phone, email, address, website, service_type
            FROM suppliers
            WHERE id = :id
        ");
        $stmt->execute([':id' => $loadId]);
        $editingSupplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // טעינת אנשי קשר נוספים מהטבלה הייעודית
        if ($editingSupplier) {
            $stmtC = $pdo->prepare("
                SELECT name, phone, email
                FROM supplier_contacts
                WHERE supplier_id = :sid
                ORDER BY id ASC
            ");
            $stmtC->execute([':sid' => (int)$editingSupplier['id']]);
            $extraContacts = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $editingSupplier['_extra_contacts'] = $extraContacts;
        }
    } catch (PDOException $e) {
        $editingSupplier = null;
    }
}

// טעינת רשימת ספקים
$suppliers = [];
try {
    $stmt = $pdo->query("
        SELECT id, company_name, company_code, contact_name, phone, email, address, website, service_type
        FROM suppliers
        ORDER BY company_name ASC
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $error = $error !== '' ? $error : 'שגיאה בטעינת ספקים.';
}

$show_import_fix_modal_sup = false;
$import_fix_data_sup = null;
if (!empty($_GET['import_fix']) && isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'suppliers') {
    $show_import_fix_modal_sup = true;
    $import_fix_data_sup = [
        'headers' => $_SESSION['import_fix_headers'] ?? [],
        'rows'    => $_SESSION['import_fix_rows'] ?? [],
        'issues'  => $_SESSION['import_fix_issues'] ?? [],
    ];
    $import_fix_system_columns_sup = ['company_name', 'company_code', 'contact_name', 'phone', 'email', 'address', 'website', 'service_type'];
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ספקים - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        main {
            max-width: 1100px;
            margin: 1.5rem auto 2rem;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.4rem;
        }
        .muted-small {
            font-size: 0.8rem;
            color: #6b7280;
        }
        .row-actions {
            display: flex;
            gap: 0.3rem;
            align-items: center;
        }
        .row-actions form {
            margin: 0;
        }
        .icon-btn {
            border: none;
            background: transparent;
            padding: 0.15rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .toolbar {
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: flex-start;
        }
        .btn {
            border: none;
            border-radius: 999px;
            padding: 0.4rem 0.9rem;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.5rem 0.75rem;
            margin-bottom: 0.75rem;
        }
        .form-grid label {
            display: block;
            font-size: 0.78rem;
            color: #4b5563;
            margin-bottom: 0.15rem;
        }
        .form-grid input {
            width: 100%;
            padding: 0.35rem 0.5rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.85rem;
            box-sizing: border-box;
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
            max-width: 700px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.25rem 1.5rem 1.25rem;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .modal-close {
        .contact-section {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            margin-bottom: 0.6rem;
            background: #f9fafb;
        }
        .contact-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.4rem;
        }
        .contact-header-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #374151;
        }
        .file-drop-zone {
            border-radius: 999px;
            border: 1px dashed #d1d5db;
            padding: 0.45rem 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            font-size: 0.8rem;
            color: #374151;
            background: #f9fafb;
            cursor: pointer;
        }
        .file-drop-zone:hover {
            border-color: #9ca3af;
            background: #f3f4f6;
        }
        .file-drop-input {
            display: none;
        }
        .file-drop-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 999px;
            background: #4f46e5;
            color: #ffffff;
        }
        .file-drop-text {
            font-weight: 500;
        }
        .file-drop-hint {
            font-size: 0.75rem;
            color: #6b7280;
        }
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <h2 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem;">ספקים</h2>

    <?php if ($error !== ''): ?>
        <div class="card" style="margin-bottom:0.75rem;">
            <div class="muted-small" style="color:#b91c1c;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php elseif ($success !== ''): ?>
        <div class="card" style="margin-bottom:0.75rem;">
            <div class="muted-small" style="color:#166534;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <div class="toolbar">
        <button type="button" class="btn" id="open_supplier_modal_btn">הוספת ספק</button>
    </div>

    <?php
    $isViewMode = ($viewId > 0 && $editingSupplier !== null);
    $showModal  = ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') || ($editingSupplier !== null && $_SERVER['REQUEST_METHOD'] === 'GET');
    $modalAction = $editingSupplier && !$isViewMode ? 'update' : 'create';
    ?>
    <div class="modal-backdrop" id="supplier_modal" style="display: <?= $showModal ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2 style="margin:0; font-size:1.2rem;"><?= $isViewMode ? 'צפייה בספק' : ($editingSupplier ? 'עריכת ספק' : 'הוספת ספק') ?></h2>
                <button type="button" class="modal-close" id="supplier_modal_close" aria-label="סגירת חלון">×</button>
            </div>
            <form method="post" action="admin_suppliers.php" id="supplier_form">
                <input type="hidden" name="action" id="supplier_action" value="<?= htmlspecialchars($modalAction, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="supplier_id" value="<?= (int)($editingSupplier['id'] ?? 0) ?>">
                <div class="form-grid">
                    <div>
                        <label for="company_name">חברה</label>
                        <input type="text" id="company_name" name="company_name" required <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="company_code">קוד חברה</label>
                        <input type="text" id="company_code" name="company_code" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['company_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="address">כתובת</label>
                        <input type="text" id="address" name="address" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="website">לינק לאתר</label>
                        <input type="text" id="website" name="website" placeholder="https://" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="service_type">סוג שירות</label>
                        <select id="service_type" name="service_type" <?= $isViewMode ? 'disabled' : '' ?>>
                            <?php $currentService = (string)($editingSupplier['service_type'] ?? ''); ?>
                            <option value="">בחר...</option>
                            <option value="ציוד"     <?= $currentService === 'ציוד' ? 'selected' : '' ?>>ציוד</option>
                            <option value="אחריות"  <?= $currentService === 'אחריות' ? 'selected' : '' ?>>אחריות</option>
                            <option value="מעבדה"   <?= $currentService === 'מעבדה' ? 'selected' : '' ?>>מעבדה</option>
                        </select>
                    </div>
                </div>

                <?php
                // בניית מערך אנשי קשר לתצוגה (איש קשר ראשי + נוספים)
                $contactsForForm = [];
                $primaryContact = [
                    'name'  => (string)($editingSupplier['contact_name'] ?? ''),
                    'phone' => (string)($editingSupplier['phone'] ?? ''),
                    'email' => (string)($editingSupplier['email'] ?? ''),
                ];
                if ($primaryContact['name'] !== '' || $primaryContact['phone'] !== '' || $primaryContact['email'] !== '') {
                    $contactsForForm[] = $primaryContact;
                }
                $extraContacts = (array)($editingSupplier['_extra_contacts'] ?? []);
                foreach ($extraContacts as $row) {
                    $n = (string)($row['name'] ?? '');
                    $p = (string)($row['phone'] ?? '');
                    $e = (string)($row['email'] ?? '');
                    if ($n === '' && $p === '' && $e === '') {
                        continue;
                    }
                    $contactsForForm[] = ['name' => $n, 'phone' => $p, 'email' => $e];
                }
                if (empty($contactsForForm)) {
                    $contactsForForm[] = ['name' => '', 'phone' => '', 'email' => ''];
                }
                $contactsForForm = array_slice($contactsForForm, 0, 3);
                ?>

                <div id="supplier_contacts_wrapper">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.35rem;">
                        <span style="font-weight:600;font-size:0.9rem;">איש קשר</span>
                    </div>
                    <div id="contact_sections">
                        <?php foreach ($contactsForForm as $idx => $c): ?>
                        <div class="contact-section" data-index="<?= (int)$idx ?>">
                            <div class="contact-header">
                                <span class="contact-header-title">איש קשר</span>
                                <button type="button"
                                        class="icon-btn contact-remove-btn"
                                        title="מחיקת איש קשר"
                                        aria-label="מחיקת איש קשר"
                                        style="<?= (count($contactsForForm) > 1 && !$isViewMode) ? 'display:inline-flex;' : 'display:none;' ?>">
                                    <i data-lucide="x" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="form-grid" style="margin-bottom:0;">
                                <div>
                                    <label>שם</label>
                                    <input type="text" name="contact_name[]" <?= $isViewMode ? 'readonly' : '' ?>
                                           value="<?= htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div>
                                    <label>טלפון</label>
                                    <?php if ($isViewMode): ?>
                                        <input type="text"
                                               value="<?= htmlspecialchars((string)$c['phone'], ENT_QUOTES, 'UTF-8') ?>"
                                               readonly
                                               style="width:100%;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;cursor:pointer;color:#2563eb;"
                                               onclick="if(this.value){window.location.href='tel:'+this.value;}">
                                        <input type="hidden" name="contact_phone[]" value="<?= htmlspecialchars((string)$c['phone'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <input type="text" name="contact_phone[]"
                                               value="<?= htmlspecialchars((string)$c['phone'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label>מייל</label>
                                    <?php if ($isViewMode): ?>
                                        <input type="text"
                                               value="<?= htmlspecialchars((string)$c['email'], ENT_QUOTES, 'UTF-8') ?>"
                                               readonly
                                               style="width:100%;padding:0.35rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;cursor:pointer;color:#2563eb;"
                                               onclick="if(this.value){window.location.href='mailto:'+this.value;}">
                                        <input type="hidden" name="contact_email[]" value="<?= htmlspecialchars((string)$c['email'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <input type="email" name="contact_email[]"
                                               value="<?= htmlspecialchars((string)$c['email'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$isViewMode): ?>
                    <button type="button" class="btn secondary" id="add_contact_btn" style="margin-top:0.4rem;">הוספת איש קשר</button>
                    <?php endif; ?>
                </div>
                <div class="toolbar" style="margin-top:0.5rem;">
                    <button type="button" class="btn secondary" id="supplier_modal_cancel"><?= $isViewMode ? 'סגירה' : 'ביטול' ?></button>
                    <?php if (!$isViewMode): ?>
                        <button type="submit" class="btn">שמירה</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <p class="muted-small" style="margin-bottom:0.5rem;">
            טבלת ספקים.
        </p>

        <form method="post" action="admin_suppliers.php" enctype="multipart/form-data" id="suppliers_import_form" style="margin:0 0 0.75rem 0; display:inline-block; float:left;">
            <input type="hidden" name="action" value="import_csv">
            <label class="file-drop-zone" for="suppliers_import_file" id="suppliers_import_zone" aria-label="העלאת קובץ CSV לייבוא ספקים">
                <input type="file" name="csv_file" id="suppliers_import_file" accept=".csv" required class="file-drop-input">
                <span class="file-drop-icon"><i data-lucide="upload" aria-hidden="true"></i></span>
                <span class="file-drop-text">גרור קובץ CSV לכאן או לחץ לבחירה</span>
                <span class="file-drop-hint">ייבוא ספקים מקובץ CSV</span>
            </label>
        </form>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.86rem;">
                <thead>
                <tr>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">חברה</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">קוד חברה</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">איש קשר</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">טלפון</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">מייל</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">כתובת</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">לינק לאתר</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">סוג שירות</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">פעולות</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="9" class="muted-small" style="padding:0.6rem 0.5rem; text-align:center; color:#9ca3af;">
                            אין עדיין נתונים להצגה.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($s['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['company_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php $url = trim((string)($s['website'] ?? '')); ?>
                                <?php if ($url !== ''): ?>
                                    <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">קישור</a>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)($s['service_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <div class="row-actions">
                                    <a href="admin_suppliers.php?view_id=<?= (int)($s['id'] ?? 0) ?>" class="icon-btn" title="צפייה בספק" aria-label="צפייה בספק">
                                        <i data-lucide="eye" aria-hidden="true"></i>
                                    </a>
                                    <a href="admin_suppliers.php?edit_id=<?= (int)($s['id'] ?? 0) ?>" class="icon-btn" title="עריכת ספק" aria-label="עריכת ספק"><i data-lucide="pencil" aria-hidden="true"></i></a>
                                    <form method="post" action="admin_suppliers.php" onsubmit="return confirm('למחוק את הספק הזה?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)($s['id'] ?? 0) ?>">
                                        <button type="submit" class="icon-btn" title="מחיקת ספק" aria-label="מחיקת ספק"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($show_import_fix_modal_sup && $import_fix_data_sup): $iss = $import_fix_data_sup['issues']; ?>
    <div class="modal-backdrop" id="import_fix_modal_sup" style="display: flex;">
        <div class="modal-card" style="max-width: 95%; width: 640px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h2>תיקון ייבוא ספקים</h2>
                <button type="button" class="modal-close" id="import_fix_modal_close_sup" aria-label="סגירה"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>
            <div id="import_fix_content_sup">
                <?php if (!empty($iss['missing_columns'])): ?>
                <div class="import-fix-section">
                    <p class="muted-small">טורים חסרים בקובץ – הזן ערך ברירת מחדל:</p>
                    <?php foreach ($iss['missing_columns'] as $col): ?>
                    <label style="display:block; margin:0.35rem 0;"><?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" name="missing_default_<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" class="import-fix-missing-sup" data-col="<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" placeholder="ערך ברירת מחדל" style="width:100%; max-width:280px; padding:0.35rem;">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($iss['unknown_columns'])): ?>
                <div class="import-fix-section">
                    <p class="muted-small">הטורים הללו קיימים ברשימת הייבוא אך לא במערכת. להמרת טורים:</p>
                    <?php foreach ($iss['unknown_columns'] as $uc): ?>
                    <div class="import-fix-map-row" style="display:flex; align-items:center; gap:0.5rem; margin:0.4rem 0;">
                        <span style="min-width:120px;"><?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?></span>
                        <select class="import-fix-map-select-sup" data-file-col="<?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?>" style="padding:0.35rem; min-width:160px;">
                            <option value="">— ביטול (התעלם מטור)</option>
                            <?php foreach ($import_fix_system_columns_sup as $sc): ?>
                            <option value="<?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($iss['duplicate_suppliers'])): ?>
                <div class="import-fix-section">
                    <?php foreach ($iss['duplicate_suppliers'] as $du): ?>
                    <div class="import-fix-dup-row-sup" style="margin:0.5rem 0; padding:0.5rem; background:#f9fafb; border-radius:8px;" data-row="<?= (int)$du['row'] ?>" data-code="<?= htmlspecialchars($du['company_code'], ENT_QUOTES, 'UTF-8') ?>">
                        <p class="muted-small" style="margin:0 0 0.35rem 0;">הספק עם קוד חברה <strong><?= htmlspecialchars($du['company_code'], ENT_QUOTES, 'UTF-8') ?></strong> כבר קיים במערכת.</p>
                        <label><input type="radio" name="dup_sup_<?= (int)$du['row'] ?>" value="update" class="dup-action-update-sup" checked> עדכן ספק קיים</label>
                        <label style="margin-right:0.75rem;"><input type="radio" name="dup_sup_<?= (int)$du['row'] ?>" value="skip" class="dup-action-skip-sup"> דלג על השורה</label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="import-fix-section" style="margin-top:1rem;">
                    <form method="post" action="admin_suppliers.php" id="import_fixed_form_sup">
                        <input type="hidden" name="action" value="import_fixed">
                        <input type="hidden" name="column_mapping" id="import_fix_column_mapping_sup" value="">
                        <input type="hidden" name="missing_defaults" id="import_fix_missing_defaults_sup" value="">
                        <input type="hidden" name="duplicate_actions" id="import_fix_duplicate_actions_sup" value="">
                        <button type="submit" class="btn">ייבא קובץ מתוקן</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var issuesSup = <?= json_encode($iss ?? []) ?>;
        var formSup = document.getElementById('import_fixed_form_sup');
        if (formSup) {
            formSup.addEventListener('submit', function() {
                var mapping = {};
                document.querySelectorAll('.import-fix-map-select-sup').forEach(function(sel) {
                    var fileCol = sel.getAttribute('data-file-col');
                    if (sel.value !== '') mapping[fileCol] = sel.value;
                });
                var missing = {};
                document.querySelectorAll('.import-fix-missing-sup').forEach(function(inp) {
                    var col = inp.getAttribute('data-col');
                    if (col && inp.value.trim() !== '') missing[col] = inp.value.trim();
                });
                var dups = {};
                document.querySelectorAll('.import-fix-dup-row-sup').forEach(function(row) {
                    var ri = row.getAttribute('data-row');
                    var skipRadio = row.querySelector('.dup-action-skip-sup');
                    dups[ri] = (skipRadio && skipRadio.checked) ? { action: 'skip' } : { action: 'update' };
                });
                document.getElementById('import_fix_column_mapping_sup').value = JSON.stringify(mapping);
                document.getElementById('import_fix_missing_defaults_sup').value = JSON.stringify(missing);
                document.getElementById('import_fix_duplicate_actions_sup').value = JSON.stringify(dups);
            });
        }
        function updateMapSelectOptionsSup() {
            var used = {};
            document.querySelectorAll('.import-fix-map-select-sup').forEach(function(s) {
                if (s.value !== '') used[s.value] = true;
            });
            document.querySelectorAll('.import-fix-map-select-sup').forEach(function(s) {
                var currentVal = s.value;
                for (var i = 0; i < s.options.length; i++) {
                    var opt = s.options[i];
                    if (opt.value === '') continue;
                    opt.disabled = used[opt.value] && opt.value !== currentVal;
                }
            });
        }
        document.querySelectorAll('.import-fix-map-select-sup').forEach(function(sel) {
            sel.addEventListener('change', updateMapSelectOptionsSup);
        });
        updateMapSelectOptionsSup();
        var closeBtnSup = document.getElementById('import_fix_modal_close_sup');
        if (closeBtnSup) {
            closeBtnSup.addEventListener('click', function() {
                window.location.href = 'admin_suppliers.php';
            });
        }
    })();
    </script>
    <?php endif; ?>
</main>
</body>
<script>
    (function () {
        var openBtn = document.getElementById('open_supplier_modal_btn');
        var modal = document.getElementById('supplier_modal');
        var closeBtn = document.getElementById('supplier_modal_close');
        var cancelBtn = document.getElementById('supplier_modal_cancel');
        var form = document.getElementById('supplier_form');
        var actionInput = document.getElementById('supplier_action');
        var idInput = document.getElementById('supplier_id');

        function openModal() {
            if (modal) {
                modal.style.display = 'flex';
            }
        }
        function closeModal() {
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function openForCreate() {
            if (!form) {
                openModal();
                return;
            }
            if (actionInput) actionInput.value = 'create';
            if (idInput) idInput.value = '0';

            var inputs = form.querySelectorAll('input[type="text"], input[type="email"]');
            inputs.forEach(function (inp) {
                if (inp.id === 'supplier_id' || inp.id === 'supplier_action') return;
                inp.value = '';
            });
            var select = document.getElementById('service_type');
            if (select) select.value = '';

            openModal();
        }

        if (openBtn) {
            openBtn.addEventListener('click', openForCreate);
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
        }
    })();

    (function () {
        var contactsContainer = document.getElementById('contact_sections');
        var addContactBtn = document.getElementById('add_contact_btn');
        if (!contactsContainer || !addContactBtn) return;

        function updateRemoveButtons() {
            var sections = contactsContainer.querySelectorAll('.contact-section');
            sections.forEach(function (sec, idx) {
                var removeBtn = sec.querySelector('.contact-remove-btn');
                if (!removeBtn) return;
                // הסתרת כפתור מחיקה אם יש רק איש קשר אחד
                removeBtn.style.display = (sections.length > 1) ? 'inline-flex' : 'none';
            });
        }

        function addContactSection() {
            var sections = contactsContainer.querySelectorAll('.contact-section');
            if (sections.length >= 3) return;
            var first = sections[0];
            if (!first) return;
            var clone = first.cloneNode(true);
            // ניקוי ערכים באיש קשר חדש
            var nameInput = clone.querySelector('input[name="contact_name[]"]');
            var phoneInput = clone.querySelector('input[name="contact_phone[]"]');
            var emailInput = clone.querySelector('input[name="contact_email[]"]');
            if (nameInput) nameInput.value = '';

            // אם במצב צפייה – אין צורך באיש קשר חדש
            if (phoneInput && phoneInput.tagName === 'INPUT') phoneInput.value = '';
            if (emailInput && emailInput.tagName === 'INPUT') emailInput.value = '';

            contactsContainer.appendChild(clone);
            updateRemoveButtons();
        }

        addContactBtn.addEventListener('click', function () {
            addContactSection();
        });

        contactsContainer.addEventListener('click', function (e) {
            var target = e.target;
            if (target.closest('.contact-remove-btn')) {
                var sections = contactsContainer.querySelectorAll('.contact-section');
                if (sections.length <= 1) return;
                var section = target.closest('.contact-section');
                if (section) {
                    contactsContainer.removeChild(section);
                    updateRemoveButtons();
                }
            }
        });

        updateRemoveButtons();
    })();
</script>
</html>

