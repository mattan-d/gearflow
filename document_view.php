<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$pdo = get_db();
$me  = current_user();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
            margin: 1.5rem auto 2.5rem;
            padding: 0 1rem;
        }
        .sheet {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.75rem 2rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
            color: #0f172a;
        }
        h1 {
            margin-top: 0;
            margin-bottom: 0.75rem;
            font-size: 1.4rem;
        }
        .content {
            font-size: 0.95rem;
            line-height: 1.7;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="sheet">
        <h1><?= htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="content">
            <?= nl2br(htmlspecialchars($doc['content'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
        </div>
    </div>
</main>
</body>
</html>

