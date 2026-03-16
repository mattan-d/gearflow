<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

const DB_PATH = __DIR__ . '/app.sqlite';

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        http_response_code(500);
        echo 'SQLite driver for PDO is not enabled on this server. Please enable pdo_sqlite in php.ini.';
        exit;
    }

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        initialize_database($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Database connection error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }

    return $pdo;
}

function initialize_database(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS equipment (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE,
            description TEXT,
            category TEXT,
            location TEXT,
            quantity_total INTEGER NOT NULL DEFAULT 0,
            quantity_available INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL,
            updated_at TEXT
        )
    ");

    // מיגרציה אוטומטית: הוספת עמודת picture אם אינה קיימת (לטובת שרתים ישנים)
    try {
        $columnsStmt = $pdo->query("PRAGMA table_info(equipment)");
        $columns     = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
        $hasPicture  = false;
        foreach ($columns as $col) {
            if (($col['name'] ?? '') === 'picture') {
                $hasPicture = true;
                break;
            }
        }
        if (!$hasPicture) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN picture TEXT");
        }
    } catch (PDOException $e) {
        // אם המיגרציה נכשלת לא נכשיל את הטעינה כולה – רק לא נוסיף את העמודה
    }

    // מיגרציה: הוספת עמודות שם פרטי, שם משפחה, מחסן, מייל וטלפון לטבלת users
    try {
        $userCols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($userCols, 'name');
        if (!in_array('first_name', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN first_name TEXT");
        }
        if (!in_array('last_name', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_name TEXT");
        }
        if (!in_array('warehouse', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN warehouse TEXT");
        }
        if (!in_array('email', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT");
        }
        if (!in_array('phone', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone TEXT");
        }
        if (!in_array('reset_token', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token TEXT");
        }
        if (!in_array('reset_token_expires_at', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at TEXT");
        }
    } catch (PDOException $e) {
        // דילוג בשקט אם העמודות כבר קיימות
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            equipment_id INTEGER NOT NULL,
            borrower_name TEXT NOT NULL,
            borrower_contact TEXT,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            start_time TEXT,
            end_time TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            notes TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT,
            creator_username TEXT,
            return_equipment_status TEXT
        )
    ");

    // מיגרציה: הוספת עמודות שחסרות בטבלת orders קיימת
    try {
        $orderCols = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $orderNames = array_column($orderCols, 'name');
        if (!in_array('creator_username', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN creator_username TEXT");
        }
        if (!in_array('start_time', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN start_time TEXT");
        }
        if (!in_array('end_time', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN end_time TEXT");
        }
        if (!in_array('return_equipment_status', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN return_equipment_status TEXT");
        }
    } catch (PDOException $e) {
        // מתעלמים משגיאות מיגרציה כדי לא להפיל את הטעינה
    }

    // טבלת מסמכים מותאמים אישית (לנהלים נוספים וכו')
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS documents_custom (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            content TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT,
            version_number INTEGER NOT NULL DEFAULT 1
        )
    ");
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doc_type TEXT NOT NULL,
            doc_ref TEXT NOT NULL,
            content TEXT NOT NULL,
            version_number INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )");
    try {
        $pdo->exec("ALTER TABLE documents_custom ADD COLUMN version_number INTEGER NOT NULL DEFAULT 1");
    } catch (Throwable $e) {
        // column may already exist
    }

    // טבלת רכיבי ציוד (Item components)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS equipment_components (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            equipment_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT
        )
    ");

    // טבלת שעות פתיחת מחסנים – שורות מייצגות שעות פתוחות בלבד
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS warehouse_hours (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            warehouse TEXT NOT NULL,
            day_of_week INTEGER NOT NULL,
            hour INTEGER NOT NULL
        )
    ");
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_warehouse_hours_unique
        ON warehouse_hours (warehouse, day_of_week, hour)
    ");

    // טבלת התראות
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            role TEXT,
            message TEXT NOT NULL,
            link TEXT,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications (user_id, is_read, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_role_read ON notifications (role, is_read, created_at)");

    // טבלת שעות ברירת מחדל לפתיחת מחסנים
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS default_hours (
            day_of_week INTEGER PRIMARY KEY,
            open_time TEXT NOT NULL,
            close_time TEXT NOT NULL
        )
    ");
    $rows = $pdo->query("SELECT COUNT(*) AS cnt FROM default_hours")->fetch(PDO::FETCH_ASSOC);
    if ((int)($rows['cnt'] ?? 0) === 0) {
        $ins = $pdo->prepare("INSERT INTO default_hours (day_of_week, open_time, close_time) VALUES (:d, :o, :c)");
        for ($d = 0; $d <= 4; $d++) {
            $ins->execute([
                ':d' => $d,
                ':o' => '09:00',
                ':c' => '16:00',
            ]);
        }
    }

    // טבלת קטגוריות ציוד
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS equipment_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        )
    ");
    $catRows = $pdo->query("SELECT COUNT(*) AS cnt FROM equipment_categories")->fetch(PDO::FETCH_ASSOC);
    if ((int)($catRows['cnt'] ?? 0) === 0) {
        // מילוי ראשוני מקטגוריות קיימות בציוד
        $existingCats = $pdo->query("SELECT DISTINCT category FROM equipment WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
        $insertCat = $pdo->prepare("INSERT OR IGNORE INTO equipment_categories (name) VALUES (:n)");
        foreach ($existingCats as $cName) {
            $insertCat->execute([':n' => (string)$cName]);
        }
        // ואם אין כלום – נוסיף קטגוריות בסיסיות
        if (empty($existingCats)) {
            foreach (['מצלמה', 'מיקרופון', 'חצובה', 'תאורה'] as $baseCat) {
                $insertCat->execute([':n' => $baseCat]);
            }
        }
    }

    // טבלת תוויות סטטוסי הזמנה (לשינוי שם בעברית מבלי לפגוע בקוד)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_status_labels (
            status TEXT PRIMARY KEY,
            label_he TEXT NOT NULL
        )
    ");
    $statusDefaults = [
        'pending'      => 'ממתין',
        'approved'     => 'מאושר',
        'on_loan'      => 'בהשאלה',
        'returned'     => 'עבר',
        'rejected'     => 'נדחה',
        'not_returned' => 'לא הוחזר',  // הזמנה בהשאלה שעבר תאריך ההחזרה
        'not_picked'   => 'לא נלקח',   // הזמנה מאושרת שעבר מועד ההשאלה ולא נלקחה
    ];
    foreach ($statusDefaults as $code => $label) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO order_status_labels (status, label_he) VALUES (:s, :l)');
        $stmt->execute([':s' => $code, ':l' => $label]);
    }

    // Ensure default admin user exists: admin / admin
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM users WHERE username = :username');
    $stmt->execute([':username' => 'admin']);
    $row = $stmt->fetch();

    if ((int)($row['cnt'] ?? 0) === 0) {
        $passwordHash = password_hash('admin', PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active, created_at)
             VALUES (:username, :password_hash, :role, :is_active, :created_at)'
        );
        $insert->execute([
            ':username'      => 'admin',
            ':password_hash' => $passwordHash,
            ':role'          => 'admin',
            ':is_active'     => 1,
            ':created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}

