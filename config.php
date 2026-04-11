<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

const DB_PATH = __DIR__ . '/app.sqlite';

/**
 * כפיית https בכתובות ציבוריות (OAuth redirect וכו׳).
 * שימוש כשהאתר נגיש ב־HTTPS אך PHP רואה בקשה כ־http (פרוקסי בלי כותרות TLS).
 */
const APP_FORCE_PUBLIC_HTTPS = false;

/**
 * סכמת ה-URL הציבורי של הבקשה (https כשהגלישה מוצפנת, כולל מאחורי reverse proxy).
 */
function app_request_public_scheme(): string
{
    if (APP_FORCE_PUBLIC_HTTPS) {
        return 'https';
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    $fwdProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($fwdProto === 'https') {
        return 'https';
    }
    // לפעמים רשימה: "https,http"
    if (str_starts_with($fwdProto, 'https')) {
        return 'https';
    }
    if (strcasecmp((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''), 'on') === 0) {
        return 'https';
    }
    $req = strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? ''));
    if ($req === 'https') {
        return 'https';
    }
    return 'http';
}

/**
 * כתובת הבסיס של תיקיית האפליקציה ב־URL (ללא סלאש בסוף), לדוגמה https://example.com או https://example.com/gearflow.
 * משמש ל־OAuth redirect ולקישורים מוחלטים.
 */
function app_script_dir_url(): string
{
    $scheme = app_request_public_scheme();
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir    = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.') {
        return $scheme . '://' . $host;
    }
    return $scheme . '://' . $host . rtrim($dir, '/');
}

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

/**
 * מחזיר את דף הבית עבור תפקיד משתמש נתון.
 * התוצאה היא נתיב יחסי בתוך האפליקציה (ללא domain).
 */
function get_home_route_for_role(string $role): string
{
    $role = $role ?: 'student';
    $fallback = ($role === 'admin') ? 'admin_orders.php' : 'admin_orders.php';

    try {
        $pdo = get_db();
        $key = ($role === 'admin') ? 'home_admin' : 'home_student';
        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $val = is_string($row['value'] ?? '') ? trim((string)$row['value']) : '';

        // רשימת יעדים מותרים לפי תפקיד
        if ($role === 'admin') {
            $allowed = ['admin_orders.php', 'admin_equipment.php'];
        } else {
            $allowed = ['admin_orders.php'];
        }

        if ($val !== '' && in_array($val, $allowed, true)) {
            return $val;
        }
    } catch (Throwable $e) {
        // במקרה של שגיאה נחזור לברירת המחדל
    }

    return $fallback;
}

/**
 * יומנים יומיים לתפריט: למנהל/מחסן — כל היומנים; לסטודנט — רק יומנים מסומנים כגלויים.
 *
 * @return list<array{id:int|string,title:string,student_visible:int|string,sort_order:int|string}>
 */
function gf_daily_calendars_for_nav(PDO $pdo, string $role): array
{
    try {
        $stmt = $pdo->query('SELECT id, title, student_visible, sort_order FROM daily_calendars ORDER BY sort_order ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    if ($role === 'student') {
        return array_values(array_filter($rows, static function ($r) {
            return (int)($r['student_visible'] ?? 0) === 1;
        }));
    }
    if (in_array($role, ['admin', 'warehouse_manager'], true)) {
        return $rows;
    }

    return [];
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
        if (!in_array('id_number', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN id_number TEXT");
        }
        if (!in_array('study_year', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN study_year TEXT");
        }
        if (!in_array('allow_emails', $names, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN allow_emails INTEGER NOT NULL DEFAULT 0");
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
            return_equipment_status TEXT,
            equipment_return_condition TEXT
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
        if (!in_array('equipment_return_condition', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN equipment_return_condition TEXT");
        }
        if (!in_array('purpose', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN purpose TEXT");
        }
        if (!in_array('admin_notes', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN admin_notes TEXT");
        }
        if (!in_array('equipment_prepared', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN equipment_prepared INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('recurring_series_id', $orderNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN recurring_series_id INTEGER");
        }
    } catch (PDOException $e) {
        // מתעלמים משגיאות מיגרציה כדי לא להפיל את הטעינה
    }

    // תיקון נתונים: הזמנות בסטטוס "עבר" חייבות לכלול סטטוס ציוד מוחזר (ברירת מחדל: תקין)
    try {
        $pdo->exec("
            UPDATE orders
            SET equipment_return_condition = 'תקין'
            WHERE status = 'returned'
              AND (equipment_return_condition IS NULL OR TRIM(equipment_return_condition) = '')
        ");
    } catch (PDOException $e) {
        // לא מפילים טעינה בגלל תיקון נתונים
    }

    // תיקון נתונים: הזמנות בסטטוס "עבר" חייבות לכלול סטטוס החזרה (ברירת מחדל: תקין)
    try {
        $pdo->exec("
            UPDATE orders
            SET return_equipment_status = 'תקין'
            WHERE status = 'returned'
              AND (
                return_equipment_status IS NULL
                OR TRIM(return_equipment_status) = ''
                OR TRIM(return_equipment_status) NOT IN ('תקין', 'לא נאסף', 'לא הוחזר בזמן')
              )
        ");
    } catch (PDOException $e) {
        // לא מפילים טעינה בגלל תיקון נתונים
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

    // טבלת סטטוס רכיבי ציוד בהזמנה (האם קיים בזמן השאלה/החזרה)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_component_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            equipment_code TEXT NOT NULL,
            component_name TEXT NOT NULL,
            is_present INTEGER NOT NULL DEFAULT 1,
            returned INTEGER NOT NULL DEFAULT 0,
            checked_at TEXT NOT NULL
        )
    ");
    // מיגרציה: הוספת עמודת returned אם חסרה
    try {
        $occCols = $pdo->query("PRAGMA table_info(order_component_checks)")->fetchAll(PDO::FETCH_ASSOC);
        $occNames = array_column($occCols, 'name');
        if (!in_array('returned', $occNames, true)) {
            $pdo->exec("ALTER TABLE order_component_checks ADD COLUMN returned INTEGER NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {
        // דילוג בשקט אם העמודה כבר קיימת / טבלה לא קיימת
    }

    // טבלת קישור בין הזמנה לפריטי ציוד (לתמיכה בהזמנה אחת עם כמה פריטים)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_equipment (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            equipment_id INTEGER NOT NULL
        )
    ");
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_order_equipment_unique
        ON order_equipment (order_id, equipment_id)
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_order_equipment_equipment_id
        ON order_equipment (equipment_id)
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

    // טבלאות ספירת מלאי
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_counts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            count_date TEXT NOT NULL,
            created_at TEXT NOT NULL,
            created_by_user_id INTEGER,
            created_by_username TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_count_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            count_id INTEGER NOT NULL,
            equipment_id INTEGER NOT NULL,
            item_status TEXT NOT NULL DEFAULT 'תקין',
            counted_quantity INTEGER NOT NULL DEFAULT 1,
            notes TEXT,
            updated_at TEXT,
            UNIQUE(count_id, equipment_id)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inventory_count_items_count_id ON inventory_count_items (count_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inventory_count_items_equipment_id ON inventory_count_items (equipment_id)");

    // טבלת הגדרות מערכת כלליות (key/value) – כולל דף הבית לפי תפקיד
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ");
    $defaultSettings = [
        'home_student' => 'admin_orders.php',
        // מנהל – ברירת מחדל מנהל הזמנות, ניתן לשנות למנהל ציוד
        'home_admin'   => 'admin_orders.php',
        // מצב בדיקות: כאשר פעיל ניתן לנהל הזמנות בכל תאריך ושעה
        'warehouse_always_open' => '0',
    ];
    foreach ($defaultSettings as $k => $v) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO app_settings (key, value) VALUES (:k, :v)');
        $stmt->execute([':k' => $k, ':v' => $v]);
    }

    // יומנים יומיים (שם תצוגה, קטגוריית ציוד, נראות לסטודנטים)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_calendars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            equipment_category TEXT NOT NULL,
            student_visible INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
        )
    ");
    try {
        $dcCnt = (int)$pdo->query('SELECT COUNT(*) FROM daily_calendars')->fetchColumn();
        if ($dcCnt === 0) {
            $insDc = $pdo->prepare(
                'INSERT INTO daily_calendars (title, equipment_category, student_visible, sort_order) VALUES (:t, :c, :sv, :so)'
            );
            $insDc->execute([
                ':t' => 'יומן מצלמות', ':c' => 'מצלמה', ':sv' => 1, ':so' => 1,
            ]);
            $insDc->execute([
                ':t' => 'יומן חדרי עריכה', ':c' => 'חדרי עריכה', ':sv' => 1, ':so' => 2,
            ]);
        }
    } catch (Throwable $e) {
        // התעלמות — DB ישן או שגיאת מיגרציה
    }

    // טבלת תוויות סטטוסי הזמנה (לשינוי שם בעברית מבלי לפגוע בקוד)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_status_labels (
            status TEXT PRIMARY KEY,
            label_he TEXT NOT NULL,
            color_hex TEXT
        )
    ");
    // מיגרציה: הוספת color_hex אם חסר (לטובת DB קיימים)
    try {
        $stCols = $pdo->query("PRAGMA table_info(order_status_labels)")->fetchAll(PDO::FETCH_ASSOC);
        $stNames = array_column($stCols, 'name');
        if (!in_array('color_hex', $stNames, true)) {
            $pdo->exec("ALTER TABLE order_status_labels ADD COLUMN color_hex TEXT");
        }
    } catch (Throwable $e) {
        // ignore
    }
    $statusDefaults = [
        'pending'      => 'ממתין',
        'approved'     => 'מאושר',
        'ready'        => 'מוכנה',
        'on_loan'      => 'בהשאלה',
        'returned'     => 'עבר',
        'rejected'     => 'נדחה',
        'deleted'      => 'נמחק',
        'not_returned' => 'לא הוחזר',  // הזמנה בהשאלה שעבר תאריך ההחזרה
        'not_picked'   => 'לא נלקח',   // הזמנה מאושרת שעבר מועד ההשאלה ולא נלקחה
    ];
    foreach ($statusDefaults as $code => $label) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO order_status_labels (status, label_he) VALUES (:s, :l)');
        $stmt->execute([':s' => $code, ':l' => $label]);
    }
    // צבעי ברירת מחדל (אם לא נקבע צבע)
    try {
        $defaultStatusColors = [
            'pending' => '#f59e0b',
            'approved' => '#2563eb',
            'ready' => '#16a34a',
            'on_loan' => '#0ea5e9',
            'returned' => '#6b7280',
            'rejected' => '#ef4444',
            'deleted' => '#991b1b',
            'not_returned' => '#dc2626',
            'not_picked' => '#b45309',
        ];
        $updColor = $pdo->prepare("UPDATE order_status_labels SET color_hex = :c WHERE status = :s AND (color_hex IS NULL OR TRIM(color_hex) = '')");
        foreach ($defaultStatusColors as $s => $c) {
            $updColor->execute([':s' => $s, ':c' => $c]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    // טבלת ספקים
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT NOT NULL,
            company_code TEXT,
            contact_name TEXT,
            phone TEXT,
            email TEXT,
            address TEXT,
            website TEXT,
            service_type TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT
        )
    ");

    // טבלת אנשי קשר לספקים (עד שלושה לאותו ספק)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS supplier_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NOT NULL,
            name TEXT,
            phone TEXT,
            email TEXT
        )
    ");

    // עמודות שירות ואחריות בציוד (ספק/מעבדה/אחריות)
    try {
        $equipCols = $pdo->query("PRAGMA table_info(equipment)")->fetchAll(PDO::FETCH_ASSOC);
        $equipNames = array_column($equipCols, 'name');
        if (!in_array('service_supplier_id', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN service_supplier_id INTEGER");
        }
        if (!in_array('service_lab_id', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN service_lab_id INTEGER");
        }
        if (!in_array('service_warranty_mode', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN service_warranty_mode TEXT");
        }
        if (!in_array('service_warranty_supplier_id', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN service_warranty_supplier_id INTEGER");
        }
        if (!in_array('warranty_start', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN warranty_start TEXT");
        }
        if (!in_array('warranty_end', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN warranty_end TEXT");
        }
        if (!in_array('warranty_image', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN warranty_image TEXT");
        }
        if (!in_array('manufacturer_code', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN manufacturer_code TEXT");
        }
        if (!in_array('journal_id', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN journal_id TEXT");
        }
        if (!in_array('purchase_date', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN purchase_date TEXT");
        }
        if (!in_array('purchase_price', $equipNames, true)) {
            $pdo->exec("ALTER TABLE equipment ADD COLUMN purchase_price REAL");
        }
    } catch (Throwable $e) {
        // מתעלמים משגיאות מיגרציה של עמודות שירות/אחריות
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

