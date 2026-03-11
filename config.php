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

    // מיגרציה: הוספת עמודות שם פרטי, שם משפחה, מחסן לטבלת users
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
            status TEXT NOT NULL DEFAULT 'pending',
            notes TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT
        )
    ");

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

