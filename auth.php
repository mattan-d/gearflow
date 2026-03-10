<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? null,
        'role'     => $_SESSION['role'] ?? null,
    ];
}

function require_admin(): void
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'admin') {
        header('Location: login.php');
        exit;
    }
}

