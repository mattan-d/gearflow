<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo = get_db();
$error = '';
$success = '';
if (isset($_GET['import_error']) && $_GET['import_error'] !== '') {
    $error = (string)$_GET['import_error'];
}
if (isset($_SESSION['admin_users_success'])) {
    $success = $_SESSION['admin_users_success'];
    unset($_SESSION['admin_users_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $role       = $_POST['role'] ?? 'student';
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $warehouse  = trim($_POST['warehouse'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'יש למלא שם משתמש וסיסמה.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, is_active, first_name, last_name, warehouse, email, phone, created_at)
                     VALUES (:username, :password_hash, :role, :is_active, :first_name, :last_name, :warehouse, :email, :phone, :created_at)'
                );
                $stmt->execute([
                    ':username'      => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':role'          => $role,
                    ':is_active'     => 1,
                    ':first_name'    => $firstName,
                    ':last_name'     => $lastName,
                    ':warehouse'     => $warehouse,
                    ':email'        => $email,
                    ':phone'        => $phone,
                    ':created_at'   => date('Y-m-d H:i:s'),
                ]);
                $_SESSION['admin_users_success'] = 'המשתמש נוצר בהצלחה.';
                header('Location: admin_users.php');
                exit;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $error = 'שם המשתמש כבר קיים.';
                } else {
                    $error = 'שגיאה ביצירת המשתמש.';
                }
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $success = 'סטטוס המשתמש עודכן.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $success = 'המשתמש נמחק.';
            } catch (PDOException $e) {
                $error = 'לא ניתן למחוק את המשתמש.';
            }
        }
    } elseif ($action === 'update') {
        $id         = (int)($_POST['id'] ?? 0);
        $username   = trim($_POST['username'] ?? '');
        $password   = (string)($_POST['new_password'] ?? '');
        $role       = $_POST['role'] ?? 'student';
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $warehouse  = trim($_POST['warehouse'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');

        if ($id <= 0 || $username === '') {
            $error = 'יש לבחור משתמש ולמלא שם משתמש.';
        } else {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username,
                             password_hash = :password_hash,
                             role = :role,
                             first_name = :first_name,
                             last_name = :last_name,
                             warehouse = :warehouse,
                             email = :email,
                             phone = :phone
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':username'      => $username,
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':role'          => $role,
                        ':first_name'    => $firstName,
                        ':last_name'     => $lastName,
                        ':warehouse'     => $warehouse,
                        ':email'         => $email,
                        ':phone'         => $phone,
                        ':id'            => $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username,
                             role = :role,
                             first_name = :first_name,
                             last_name = :last_name,
                             warehouse = :warehouse,
                             email = :email,
                             phone = :phone
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':username'   => $username,
                        ':role'       => $role,
                        ':first_name' => $firstName,
                        ':last_name'  => $lastName,
                        ':warehouse'  => $warehouse,
                        ':email'      => $email,
                        ':phone'      => $phone,
                        ':id'         => $id,
                    ]);
                }
                $_SESSION['admin_users_success'] = 'פרטי המשתמש עודכנו בהצלחה.';
                header('Location: admin_users.php');
                exit;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $error = 'שם המשתמש כבר קיים.';
                } else {
                    $error = 'שגיאה בעדכון המשתמש.';
                }
            }
        }
    } elseif ($action === 'import_csv') {
        if (!isset($_FILES['import_file']) || ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'יש לבחור קובץ CSV לייבוא.';
        } else {
            $tmpName = $_FILES['import_file']['tmp_name'];
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
                header('Location: admin_users.php?import_error=' . urlencode($error));
                exit;
            }
            $systemCols = ['username', 'password', 'role', 'first_name', 'last_name', 'warehouse', 'email', 'phone', 'is_active'];
            $requiredCols = ['username'];
            $headerNorm = [];
            foreach ($header as $idx => $col) {
                $key = strtolower(trim((string)$col));
                if ($key !== '') $headerNorm[$key] = $idx;
            }
            $missingColumns = array_diff($requiredCols, array_keys($headerNorm));
            $unknownColumns = array_diff(array_keys($headerNorm), $systemCols);
            $duplicateUsernames = [];
            if (isset($headerNorm['username'])) {
                $userIdx = $headerNorm['username'];
                $existing = $pdo->query("SELECT username FROM users")->fetchAll(PDO::FETCH_COLUMN);
                $existingSet = array_flip($existing);
                foreach ($rows as $ri => $row) {
                    $u = isset($row[$userIdx]) ? trim((string)$row[$userIdx]) : '';
                    if ($u !== '' && isset($existingSet[$u])) {
                        $duplicateUsernames[] = ['row' => $ri, 'username' => $u];
                    }
                }
            }
            $hasIssues = !empty($missingColumns) || !empty($unknownColumns) || !empty($duplicateUsernames);
            if ($hasIssues) {
                $_SESSION['import_fix_type'] = 'users';
                $_SESSION['import_fix_headers'] = $header;
                $_SESSION['import_fix_rows'] = $rows;
                $_SESSION['import_fix_raw'] = base64_encode($rawContent);
                $_SESSION['import_fix_issues'] = [
                    'missing_columns' => array_values($missingColumns),
                    'unknown_columns' => array_values($unknownColumns),
                    'duplicate_usernames' => $duplicateUsernames,
                ];
                $_SESSION['import_fix_delimiter'] = $delimiter;
                header('Location: admin_users.php?import_fix=1');
                exit;
            }

            $handle = fopen($tmpName, 'r');
            if ($handle === false) {
                $error = 'לא ניתן לקרוא את קובץ ה-CSV.';
            } else {
                $imported = 0;
                $updated  = 0;
                $header   = fgetcsv($handle);
                if (!is_array($header)) {
                    $error = 'קובץ ה-CSV ריק או בפורמט שגוי.';
                } else {
                    // ננרמל שמות עמודות
                    $map = [];
                    foreach ($header as $idx => $col) {
                        $col = strtolower(trim((string)$col));
                        $map[$col] = $idx;
                    }
                    while (($row = fgetcsv($handle)) !== false) {
                        $get = function (string $col) use ($map, $row): string {
                            if (!isset($map[$col])) return '';
                            $i = $map[$col];
                            return isset($row[$i]) ? trim((string)$row[$i]) : '';
                        };
                        $username  = $get('username');
                        if ($username === '') {
                            continue;
                        }
                        $password  = $get('password');
                        $role      = $get('role') ?: 'student';
                        if (!in_array($role, ['admin', 'warehouse_manager', 'student'], true)) {
                            $role = 'student';
                        }
                        $firstName = $get('first_name');
                        $lastName  = $get('last_name');
                        $warehouse = $get('warehouse');
                        $email     = $get('email');
                        $phone     = $get('phone');
                        $isActive  = $get('is_active');
                        $activeVal = ($isActive === '0' || strtolower($isActive) === 'no') ? 0 : 1;

                        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                        $stmt->execute([':u' => $username]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($existing) {
                            $id = (int)$existing['id'];
                            // עדכון משתמש קיים (לא נדרוש סיסמה; אם יש סיסמה בעמודה – נעדכן)
                            if ($password !== '') {
                                $upd = $pdo->prepare(
                                    'UPDATE users
                                     SET role = :role,
                                         first_name = :first_name,
                                         last_name = :last_name,
                                         warehouse = :warehouse,
                                         email = :email,
                                         phone = :phone,
                                         is_active = :is_active,
                                         password_hash = :password_hash
                                     WHERE id = :id'
                                );
                                $upd->execute([
                                    ':role'          => $role,
                                    ':first_name'    => $firstName,
                                    ':last_name'     => $lastName,
                                    ':warehouse'     => $warehouse,
                                    ':email'         => $email,
                                    ':phone'         => $phone,
                                    ':is_active'     => $activeVal,
                                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                    ':id'            => $id,
                                ]);
                            } else {
                                $upd = $pdo->prepare(
                                    'UPDATE users
                                     SET role = :role,
                                         first_name = :first_name,
                                         last_name = :last_name,
                                         warehouse = :warehouse,
                                         email = :email,
                                         phone = :phone,
                                         is_active = :is_active
                                     WHERE id = :id'
                                );
                                $upd->execute([
                                    ':role'      => $role,
                                    ':first_name'=> $firstName,
                                    ':last_name' => $lastName,
                                    ':warehouse' => $warehouse,
                                    ':email'     => $email,
                                    ':phone'     => $phone,
                                    ':is_active' => $activeVal,
                                    ':id'        => $id,
                                ]);
                            }
                            $updated++;
                        } else {
                            // יצירת משתמש חדש – דורש סיסמה
                            if ($password === '') {
                                continue;
                            }
                            $ins = $pdo->prepare(
                                'INSERT INTO users (username, password_hash, role, is_active, first_name, last_name, warehouse, email, phone, created_at)
                                 VALUES (:username, :password_hash, :role, :is_active, :first_name, :last_name, :warehouse, :email, :phone, :created_at)'
                            );
                            $ins->execute([
                                ':username'      => $username,
                                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                ':role'          => $role,
                                ':is_active'     => $activeVal,
                                ':first_name'    => $firstName,
                                ':last_name'     => $lastName,
                                ':warehouse'     => $warehouse,
                                ':email'         => $email,
                                ':phone'         => $phone,
                                ':created_at'    => date('Y-m-d H:i:s'),
                            ]);
                            $imported++;
                        }
                    }
                    fclose($handle);
                    $success = 'ייבוא הושלם. נוספו ' . $imported . ' משתמשים חדשים, עודכנו ' . $updated . ' משתמשים קיימים.';
                }
            }
        }
    } elseif ($action === 'import_fixed') {
        if (isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'users') {
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
            $updated = 0;
            foreach ($rows as $ri => $row) {
                if (isset($skipRows[$ri])) continue;
                $get = function ($col) use ($headerNorm, $row, $missingDefaults) {
                    $idx = $headerNorm[$col] ?? null;
                    if ($idx !== null && isset($row[$idx])) return trim((string)$row[$idx]);
                    return (string)($missingDefaults[$col] ?? '');
                };
                $username = $get('username');
                if ($username === '') continue;
                $password = $get('password');
                $role = $get('role') ?: 'student';
                if (!in_array($role, ['admin', 'warehouse_manager', 'student'], true)) $role = 'student';
                $firstName = $get('first_name');
                $lastName = $get('last_name');
                $warehouse = $get('warehouse');
                $email = $get('email');
                $phone = $get('phone');
                $isActive = $get('is_active');
                $activeVal = ($isActive === '0' || strtolower($isActive) === 'no') ? 0 : 1;
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                $stmt->execute([':u' => $username]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $id = (int)$existing['id'];
                    if ($password !== '') {
                        $upd = $pdo->prepare('UPDATE users SET role=:role, first_name=:first_name, last_name=:last_name, warehouse=:warehouse, email=:email, phone=:phone, is_active=:is_active, password_hash=:password_hash WHERE id=:id');
                        $upd->execute([':role'=>$role, ':first_name'=>$firstName, ':last_name'=>$lastName, ':warehouse'=>$warehouse, ':email'=>$email, ':phone'=>$phone, ':is_active'=>$activeVal, ':password_hash'=>password_hash($password, PASSWORD_DEFAULT), ':id'=>$id]);
                    } else {
                        $upd = $pdo->prepare('UPDATE users SET role=:role, first_name=:first_name, last_name=:last_name, warehouse=:warehouse, email=:email, phone=:phone, is_active=:is_active WHERE id=:id');
                        $upd->execute([':role'=>$role, ':first_name'=>$firstName, ':last_name'=>$lastName, ':warehouse'=>$warehouse, ':email'=>$email, ':phone'=>$phone, ':is_active'=>$activeVal, ':id'=>$id]);
                    }
                    $updated++;
                } else {
                    if ($password === '') continue;
                    $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role, is_active, first_name, last_name, warehouse, email, phone, created_at) VALUES (:username, :password_hash, :role, :is_active, :first_name, :last_name, :warehouse, :email, :phone, :created_at)');
                    $ins->execute([':username'=>$username, ':password_hash'=>password_hash($password, PASSWORD_DEFAULT), ':role'=>$role, ':is_active'=>$activeVal, ':first_name'=>$firstName, ':last_name'=>$lastName, ':warehouse'=>$warehouse, ':email'=>$email, ':phone'=>$phone, ':created_at'=>date('Y-m-d H:i:s')]);
                    $imported++;
                }
            }
            unset($_SESSION['import_fix_type'], $_SESSION['import_fix_headers'], $_SESSION['import_fix_rows'], $_SESSION['import_fix_raw'], $_SESSION['import_fix_issues'], $_SESSION['import_fix_delimiter']);
            $_SESSION['admin_users_success'] = 'ייבוא הושלם. נוספו ' . $imported . ' משתמשים, עודכנו ' . $updated . ' משתמשים.';
            header('Location: admin_users.php');
            exit;
        }
    }
}

$show_import_fix_modal = false;
$import_fix_data_users = null;
if (!empty($_GET['import_fix']) && isset($_SESSION['import_fix_type']) && $_SESSION['import_fix_type'] === 'users') {
    if (!empty($_SESSION['import_fix_apply_direct']) || !empty($_GET['apply'])) {
        $headers = $_SESSION['import_fix_headers'] ?? [];
        $rows = $_SESSION['import_fix_rows'] ?? [];
        $headerNorm = [];
        foreach ($headers as $idx => $col) {
            $key = strtolower(trim((string)$col));
            if ($key !== '') $headerNorm[$key] = $idx;
        }
        $imported = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $get = function ($col) use ($headerNorm, $row) {
                $idx = $headerNorm[$col] ?? null;
                return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
            };
            $username = $get('username');
            if ($username === '') continue;
            $password = $get('password');
            $role = $get('role') ?: 'student';
            if (!in_array($role, ['admin', 'warehouse_manager', 'student'], true)) $role = 'student';
            $firstName = $get('first_name');
            $lastName = $get('last_name');
            $warehouse = $get('warehouse');
            $email = $get('email');
            $phone = $get('phone');
            $isActive = $get('is_active');
            $activeVal = ($isActive === '0' || strtolower($isActive) === 'no') ? 0 : 1;
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $id = (int)$existing['id'];
                if ($password !== '') {
                    $upd = $pdo->prepare('UPDATE users SET role=:role, first_name=:first_name, last_name=:last_name, warehouse=:warehouse, email=:email, phone=:phone, is_active=:is_active, password_hash=:password_hash WHERE id=:id');
                    $upd->execute([':role'=>$role, ':first_name'=>$firstName, ':last_name'=>$lastName, ':warehouse'=>$warehouse, ':email'=>$email, ':phone'=>$phone, ':is_active'=>$activeVal, ':password_hash'=>password_hash($password, PASSWORD_DEFAULT), ':id'=>$id]);
                } else {
                    $upd = $pdo->prepare('UPDATE users SET role=:role, first_name=:first_name, last_name=:last_name, warehouse=:warehouse, email=:email, phone=:phone, is_active=:is_active WHERE id=:id');
                    $upd->execute([':role'=>$role, ':first_name'=>$firstName, ':last_name'=>$lastName, ':warehouse'=>$warehouse, ':email'=>$email, ':phone'=>$phone, ':is_active'=>$activeVal, ':id'=>$id]);
                }
                $updated++;
            } else {
                if ($password === '') continue;
                $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role, is_active, first_name, last_name, warehouse, email, phone, created_at) VALUES (:username, :password_hash, :role, :is_active, :first_name, :last_name, :warehouse, :email, :phone, :created_at)');
                $ins->execute([':username'=>$username, ':password_hash'=>password_hash($password, PASSWORD_DEFAULT), ':role'=>$role, ':is_active'=>$activeVal, ':first_name'=>$firstName, ':last_name'=>$lastName, ':warehouse'=>$warehouse, ':email'=>$email, ':phone'=>$phone, ':created_at'=>date('Y-m-d H:i:s')]);
                $imported++;
            }
        }
        unset($_SESSION['import_fix_type'], $_SESSION['import_fix_headers'], $_SESSION['import_fix_rows'], $_SESSION['import_fix_raw'], $_SESSION['import_fix_issues'], $_SESSION['import_fix_delimiter'], $_SESSION['import_fix_apply_direct']);
        $_SESSION['admin_users_success'] = 'ייבוא הושלם. נוספו ' . $imported . ' משתמשים, עודכנו ' . $updated . ' משתמשים.';
        header('Location: admin_users.php');
        exit;
    }
    $show_import_fix_modal = true;
    $import_fix_data_users = [
        'headers' => $_SESSION['import_fix_headers'] ?? [],
        'rows' => $_SESSION['import_fix_rows'] ?? [],
        'issues' => $_SESSION['import_fix_issues'] ?? [],
    ];
    $import_fix_system_columns_users = ['username', 'password', 'role', 'first_name', 'last_name', 'warehouse', 'email', 'phone', 'is_active'];
}

$nameFilter = trim((string)($_GET['q'] ?? ''));
$usersSql   = 'SELECT id, username, role, is_active, first_name, last_name, warehouse, email, phone FROM users';
$usersParams = [];
if ($nameFilter !== '') {
    // תומך גם ברשימת אותיות מופרדת בפסיקים (למשל "א,ב")
    $letters = array_filter(array_map('trim', explode(',', $nameFilter)), static function ($v) {
        return $v !== '';
    });
    if (empty($letters)) {
        $letters = [$nameFilter];
    }
    $whereParts = [];
    foreach ($letters as $idx => $letter) {
        $paramFirst = ':fn' . $idx;
        $paramLast  = ':ln' . $idx;
        $usersParams[$paramFirst] = $letter . '%';
        $usersParams[$paramLast]  = $letter . '%';
        $whereParts[] = "(first_name LIKE $paramFirst OR last_name LIKE $paramLast)";
    }
    if (!empty($whereParts)) {
        $usersSql .= ' WHERE ' . implode(' OR ', $whereParts);
    }
}
$usersSql .= ' ORDER BY id ASC';
$usersStmt = $pdo->prepare($usersSql);
$usersStmt->execute($usersParams);
$users = $usersStmt->fetchAll();

// יצוא משתמשים ל-CSV
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="users-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['username', 'password', 'role', 'first_name', 'last_name', 'warehouse', 'email', 'phone', 'is_active']);
    foreach ($users as $row) {
        fputcsv($out, [
            $row['username'] ?? '',
            '', // לא מייצאים סיסמאות קיימות
            $row['role'] ?? '',
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['warehouse'] ?? '',
            $row['email'] ?? '',
            $row['phone'] ?? '',
            (int)($row['is_active'] ?? 0),
        ]);
    }
    fclose($out);
    exit;
}

// משתמש לעריכה בחלון קופץ
$editingUser = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT id, username, role, first_name, last_name, warehouse, email, phone FROM users WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editingUser = $stmt->fetch() ?: null;
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול משתמשים - מערכת השאלת ציוד</title>
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
        header .user-info {
            font-size: 0.9rem;
            color: #e5e7eb;
        }
        header a {
            color: #f9fafb;
            text-decoration: none;
            margin-right: 1rem;
            font-size: 0.85rem;
        }
        main {
            max-width: 1100px;
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
        .grid {
            display: grid;
            grid-template-columns: 1.3fr 2fr;
            gap: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 0.5rem 0.6rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.9rem;
            box-sizing: border-box;
            margin-bottom: 0.75rem;
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
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }
        .btn.small {
            padding: 0.3rem 0.7rem;
            font-size: 0.8rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            padding: 0.55rem 0.5rem;
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
            padding: 0.15rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
        }
        .badge.admin {
            background: #eef2ff;
            color: #3730a3;
        }
        .badge.student {
            background: #ecfdf3;
            color: #166534;
        }
        .badge.warehouse {
            background: #e0f2fe;
            color: #075985;
        }
        .badge.inactive {
            background: #fef2f2;
            color: #b91c1c;
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
        .import-fix-section {
            margin-bottom: 1rem;
        }
        .flash.success {
            background: #ecfdf3;
            color: #166534;
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
        .muted {
            color: #6b7280;
            font-size: 0.8rem;
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
            max-width: 650px;
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
    <h2 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem;">ניהול משתמשים</h2>
    <?php if ($success !== ''): ?>
        <div class="flash success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error" style="margin-bottom: 1rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; gap: 0.75rem;">
        <div>
            <button type="button" class="btn" id="open_user_modal_btn">משתמש חדש</button>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <a href="admin_users.php?export=1" class="btn secondary">יצוא CSV</a>
            <form method="post" action="admin_users.php" enctype="multipart/form-data" style="display:inline;">
                <input type="hidden" name="action" value="import_csv">
                <label class="btn btn-file" style="margin:0;">
                  ייבוא CSV
                    <input type="file" name="import_file" id="import_file" accept=".csv" required>
                </label>
            </form>
        </div>
    </div>

    <?php if ($show_import_fix_modal && $import_fix_data_users): $iss = $import_fix_data_users['issues']; ?>
    <div class="modal-backdrop" id="import_fix_modal" style="display: flex;">
        <div class="modal-card" style="max-width: 95%; width: 640px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h2>תיקון ייבוא משתמשים</h2>
                <button type="button" class="modal-close" id="import_fix_modal_close" aria-label="סגירה"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>
            <div id="import_fix_content">
                <?php if (!empty($iss['missing_columns'])): ?>
                <div class="import-fix-section">
                    <p class="muted-small">טורים חסרים בקובץ – הזן ערך ברירת מחדל:</p>
                    <?php foreach ($iss['missing_columns'] as $col): ?>
                    <label style="display:block; margin:0.35rem 0;"><?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" name="missing_default_<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" class="import-fix-missing" data-col="<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" placeholder="ערך ברירת מחדל" style="width:100%; max-width:280px; padding:0.35rem;">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($iss['unknown_columns'])): ?>
                <div class="import-fix-section">
                    <p class="muted-small">הטורים הללו קיימים ברשימת הייבוא אך לא במערכת. להמרת טורים:</p>
                    <?php foreach ($iss['unknown_columns'] as $uc): ?>
                    <div class="import-fix-map-row" style="display:flex; align-items:center; gap:0.5rem; margin:0.4rem 0;">
                        <span style="min-width:120px;"><?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?></span>
                        <select class="import-fix-map-select" data-file-col="<?= htmlspecialchars($uc, ENT_QUOTES, 'UTF-8') ?>" style="padding:0.35rem; min-width:160px;">
                            <option value="">— ביטול (התעלם מטור)</option>
                            <?php foreach ($import_fix_system_columns_users as $sc): ?>
                            <option value="<?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($iss['duplicate_usernames'])): ?>
                <div class="import-fix-section">
                    <?php foreach ($iss['duplicate_usernames'] as $du): ?>
                    <div class="import-fix-dup-row" style="margin:0.5rem 0; padding:0.5rem; background:#f9fafb; border-radius:8px;" data-row="<?= (int)$du['row'] ?>" data-username="<?= htmlspecialchars($du['username'], ENT_QUOTES, 'UTF-8') ?>">
                        <p class="muted-small" style="margin:0 0 0.35rem 0;">המשתמש <strong><?= htmlspecialchars($du['username'], ENT_QUOTES, 'UTF-8') ?></strong> כבר קיים במערכת.</p>
                        <label><input type="radio" name="dup_<?= (int)$du['row'] ?>" value="update" class="dup-action-update" checked> עדכן משתמש קיים</label>
                        <label style="margin-right:0.75rem;"><input type="radio" name="dup_<?= (int)$du['row'] ?>" value="skip" class="dup-action-skip"> דלג על השורה</label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="import-fix-section" style="margin-top:1rem;">
                    <form method="post" action="admin_users.php" id="import_fixed_form">
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
        var unknownColumns = <?= json_encode($iss['unknown_columns'] ?? []) ?>;
        document.getElementById('import_fixed_form').addEventListener('submit', function(e) {
            var mapping = {};
            document.querySelectorAll('.import-fix-map-select').forEach(function(sel) {
                var fileCol = sel.getAttribute('data-file-col');
                if (sel.value !== '') mapping[fileCol] = sel.value;
            });
            var missing = {};
            document.querySelectorAll('.import-fix-missing').forEach(function(inp) {
                var col = inp.getAttribute('data-col');
                if (col && inp.value.trim() !== '') missing[col] = inp.value.trim();
            });
            var dups = {};
            document.querySelectorAll('.import-fix-dup-row').forEach(function(row) {
                var ri = row.getAttribute('data-row');
                var skipRadio = row.querySelector('.dup-action-skip');
                dups[ri] = (skipRadio && skipRadio.checked) ? { action: 'skip' } : { action: 'update' };
            });
            document.getElementById('import_fix_column_mapping').value = JSON.stringify(mapping);
            document.getElementById('import_fix_missing_defaults').value = JSON.stringify(missing);
            document.getElementById('import_fix_duplicate_actions').value = JSON.stringify(dups);
        });
        function updateMapSelectOptions() {
            var used = {};
            document.querySelectorAll('.import-fix-map-select').forEach(function(s) {
                if (s.value !== '') used[s.value] = true;
            });
            document.querySelectorAll('.import-fix-map-select').forEach(function(s) {
                var currentVal = s.value;
                for (var i = 0; i < s.options.length; i++) {
                    var opt = s.options[i];
                    if (opt.value === '') continue;
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
            window.location.href = 'admin_users.php';
        });
    })();
    </script>
    <?php endif; ?>

    <?php $showUserModal = $editingUser !== null; ?>
    <div class="modal-backdrop" id="user_modal" style="display: <?= $showUserModal ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2><?= $editingUser ? 'עריכת משתמש' : 'משתמש חדש' ?></h2>
                <button type="button" class="modal-close" id="user_modal_close" aria-label="סגירת חלון"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif ($success !== ''): ?>
                <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="admin_users.php<?= $editingUser ? '?edit_id=' . (int)$editingUser['id'] : '' ?>">
                <input type="hidden" name="action" id="user_form_action" value="<?= $editingUser ? 'update' : 'create' ?>">
                <input type="hidden" name="id" id="user_id" value="<?= $editingUser ? (int)$editingUser['id'] : 0 ?>">
                <div class="grid">
                    <div>
                        <label for="modal_first_name">שם פרטי</label>
                        <input type="text" id="modal_first_name" name="first_name"
                               value="<?= $editingUser ? htmlspecialchars($editingUser['first_name'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_last_name">שם משפחה</label>
                        <input type="text" id="modal_last_name" name="last_name"
                               value="<?= $editingUser ? htmlspecialchars($editingUser['last_name'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_username">שם משתמש</label>
                        <input type="text" id="modal_username" name="username" required
                               value="<?= $editingUser ? htmlspecialchars($editingUser['username'], ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_password">סיסמה<?= $editingUser ? ' (השאר ריק כדי לא לשנות)' : '' ?></label>
                        <?php if ($editingUser): ?>
                        <input type="password" id="modal_password" name="new_password" autocomplete="new-password" placeholder="הזן סיסמה חדשה">
                        <?php else: ?>
                        <input type="password" id="modal_password" name="password" autocomplete="off" required>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="modal_email">אימייל</label>
                        <input type="text" id="modal_email" name="email"
                               value="<?= $editingUser ? htmlspecialchars($editingUser['email'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_phone">טלפון</label>
                        <input type="text" id="modal_phone" name="phone"
                               value="<?= $editingUser ? htmlspecialchars($editingUser['phone'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_role">תפקיד</label>
                        <?php $currentRole = $editingUser['role'] ?? 'student'; ?>
                        <select id="modal_role" name="role">
                            <option value="student" <?= $currentRole === 'student' ? 'selected' : '' ?>>סטודנט</option>
                            <option value="warehouse_manager" <?= $currentRole === 'warehouse_manager' ? 'selected' : '' ?>>מנהל מחסן</option>
                            <option value="admin" <?= $currentRole === 'admin' ? 'selected' : '' ?>>אדמין</option>
                        </select>

                        <label for="modal_warehouse">מחסן</label>
                        <?php $currentWarehouse = trim((string)($editingUser['warehouse'] ?? '')); ?>
                        <select id="modal_warehouse" name="warehouse">
                            <option value="">ללא מחסן</option>
                            <option value="מחסן א" <?= $currentWarehouse === 'מחסן א' ? 'selected' : '' ?>>מחסן א</option>
                            <option value="מחסן ב" <?= $currentWarehouse === 'מחסן ב' ? 'selected' : '' ?>>מחסן ב</option>
                        </select>

                        <p class="muted">
                            משתמשים עתידיים ישמשו להזמנת ציוד, אישור הזמנות ועוד.
                        </p>
                    </div>
                </div>
                <button type="submit" class="btn"><?= $editingUser ? 'שמירת שינויים' : 'שמירת משתמש' ?></button>
                <?php if (!$editingUser): ?>
                    <button type="button" class="btn secondary" id="user_modal_cancel">ביטול</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:0.75rem;gap:1rem;">
            <h2 style="margin-bottom:0;">רשימת משתמשים</h2>
            <form method="get" action="admin_users.php" id="user_search_form" style="margin:0;">
                <input
                    type="text"
                    id="user_search"
                    name="q"
                    value="<?= htmlspecialchars($nameFilter, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="חיפוש משתמש"
                    style="min-width:220px;padding:0.35rem 0.6rem;border-radius:999px;border:1px solid #d1d5db;font-size:0.85rem;direction:rtl;"
                    autocomplete="off"
                >
            </form>
        </div>
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>שם משתמש</th>
                <th>שם פרטי</th>
                <th>שם משפחה</th>
                <th>אימייל</th>
                <th>טלפון</th>
                <th>מחסן</th>
                <th>תפקיד</th>
                <th>סטטוס</th>
                <th>פעולות</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= (int)$user['id'] ?></td>
                    <td><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['warehouse'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($user['role'] === 'admin'): ?>
                            <span class="badge admin">אדמין</span>
                        <?php elseif ($user['role'] === 'warehouse_manager'): ?>
                            <span class="badge warehouse">מנהל מחסן</span>
                        <?php else: ?>
                            <span class="badge student">סטודנט</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$user['is_active'] === 1): ?>
                            פעיל
                        <?php else: ?>
                            <span class="badge inactive">לא פעיל</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a href="admin_users.php?edit_id=<?= (int)$user['id'] ?>" class="icon-btn" title="עריכת משתמש" aria-label="עריכת משתמש"><i data-lucide="pencil" aria-hidden="true"></i></a>
                            <form method="post" action="admin_users.php">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="icon-btn" title="<?= (int)$user['is_active'] === 1 ? 'השבת' : 'הפעל' ?>" aria-label="<?= (int)$user['is_active'] === 1 ? 'השבת' : 'הפעל' ?>"><i data-lucide="<?= (int)$user['is_active'] === 1 ? 'pause' : 'play' ?>" aria-hidden="true"></i></button>
                            </form>
                            <form method="post" action="admin_users.php" onsubmit="return confirm('למחוק את המשתמש הזה?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="icon-btn" title="מחיקת משתמש" aria-label="מחיקת משתמש"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var openBtn = document.getElementById('open_user_modal_btn');
    var modal = document.getElementById('user_modal');
    var closeBtn = document.getElementById('user_modal_close');
    var cancelBtn = document.getElementById('user_modal_cancel');
    var actionInput = document.getElementById('user_form_action');
    var idInput = document.getElementById('user_id');
    var usernameInput = document.getElementById('modal_username');
    var passwordInput = document.getElementById('modal_password');
    var roleSelect = document.getElementById('modal_role');
    var firstNameInput = document.getElementById('modal_first_name');
    var lastNameInput = document.getElementById('modal_last_name');
    var warehouseSelect = document.getElementById('modal_warehouse');
    var importFileInput = document.getElementById('import_file');
    var importForm = importFileInput ? importFileInput.closest('form') : null;
    if (importFileInput && importForm) {
        importFileInput.addEventListener('change', function () {
            if (importFileInput.files && importFileInput.files.length > 0) importForm.submit();
        });
    }

    function openForCreate() {
        if (!modal) return;
        if (actionInput) actionInput.value = 'create';
        if (idInput) idInput.value = '0';
        if (firstNameInput) firstNameInput.value = '';
        if (lastNameInput) lastNameInput.value = '';
        if (usernameInput) usernameInput.value = '';
        if (passwordInput) passwordInput.value = '';
        if (roleSelect) roleSelect.value = 'student';
        if (warehouseSelect) warehouseSelect.value = '';
        modal.style.display = 'flex';
        if (usernameInput) {
            setTimeout(function () { usernameInput.focus(); }, 50);
        }
    }

    function closeModal() {
        if (modal) {
            modal.style.display = 'none';
        }
    }

    if (openBtn && modal) {
        openBtn.addEventListener('click', function () {
            openForCreate();
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            closeModal();
        });
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            closeModal();
        });
    }

    // יבוא CSV – שליחת הטופס מיד לאחר בחירת קובץ
    if (importFileInput && importFileInput.form) {
        importFileInput.addEventListener('change', function () {
            if (importFileInput.files && importFileInput.files.length > 0) {
                importFileInput.form.submit();
            }
        });
    }
});

    // חיפוש משתמשים – דיליי של חצי שנייה אחרי הקלדה
    (function () {
        var searchInput = document.getElementById('user_search');
        var form = document.getElementById('user_search_form');
        if (!searchInput || !form) return;

        var timerId = null;
        searchInput.addEventListener('input', function () {
            if (timerId !== null) {
                clearTimeout(timerId);
            }
            timerId = setTimeout(function () {
                form.submit();
            }, 500);
        });
    })();
</script>
</body>
</html>
