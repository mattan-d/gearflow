<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// נהלים גלויים רק למשתמש מחובר (סטודנט / מנהל)
$me = current_user();
if ($me === null) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$doc = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT id, title, content FROM documents_custom WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$doc) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .popup-card {
            background: #ffffff;
            padding: 1.5rem 1.75rem;
            border-radius: 12px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.55);
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-sizing: border-box;
        }
        .popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .popup-header h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.4rem;
            color: #111827;
        }
        .popup-close {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
            padding: 0.15rem 0.3rem;
            border-radius: 999px;
        }
        .popup-close:hover {
            background: #e5e7eb;
        }
        pre {
            white-space: pre-wrap;
            font-family: inherit;
            font-size: 0.95rem;
            color: #111827;
            margin: 0;
        }
    </style>
</head>
<body>
<div class="popup-card">
    <div class="popup-header">
        <h2><?= htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>
        <button type="button" class="popup-close" onclick="window.close();" aria-label="סגירת חלון">✕</button>
    </div>
    <pre><?= htmlspecialchars($doc['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></pre>
</div>
</body>
</html>

