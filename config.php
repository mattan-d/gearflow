<?php

declare(strict_types=1);

session_start();

const DB_PATH = __DIR__ . '/app.sqlite';

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    initialize_database($pdo);

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

