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
            background: #f3f4f6;
            margin: 0;
        }
        main {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem 2rem;
        }
        .sheet {
            background: #ffffff;
            padding: 1.75rem 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(15,23,42,0.08);
        }
        .sheet h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.4rem;
            color: #111827;
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
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="sheet">
        <h2><?= htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>
        <pre><?= htmlspecialchars($doc['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
</main>
</body>
</html>

