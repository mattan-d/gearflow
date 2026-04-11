<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

$me = current_user();
if ($me === null) {
    header('Location: login.php');
    exit;
}

$role = $me['role'] ?? 'student';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_opening') {
    if ($role === 'student' && gf_app_setting(get_db(), 'opening_allow_student_dismiss', '0') === '1') {
        setcookie('gf_opening_home_dismissed', '1', time() + 365 * 24 * 3600, '/', '', false, true);
    }
    header('Location: admin_orders.php');
    exit;
}

$pdo = get_db();
$docFile = __DIR__ . '/documents/opening_message.txt';
$default = "הודעת פתיחה\n\n";
$content = is_file($docFile) ? (string)file_get_contents($docFile) : $default;
if ($content === '') {
    $content = $default;
}

$showDismiss = ($role === 'student' && gf_app_setting($pdo, 'opening_allow_student_dismiss', '0') === '1');

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>הודעת פתיחה</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f3f4f6; margin: 0; }
        main { max-width: 720px; margin: 0 auto; padding: 1.5rem 1rem 2.5rem; }
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 1.35rem 1.5rem;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            white-space: pre-wrap;
            font-size: 0.95rem;
            line-height: 1.55;
            color: #1f2937;
        }
        .actions {
            margin-top: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: center;
            justify-content: center;
        }
        .btn {
            display: inline-block;
            border: none;
            border-radius: 999px;
            padding: 0.5rem 1.15rem;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
        }
        .btn-primary { background: #111827; color: #f9fafb; }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .btn-ghost { background: transparent; color: #6b7280; border: 1px solid #d1d5db; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="actions">
        <a class="btn btn-primary" href="admin_orders.php">המשך להזמנות</a>
        <?php if ($showDismiss): ?>
            <form method="post" action="opening_message.php" style="margin:0;">
                <input type="hidden" name="action" value="dismiss_opening">
                <button type="submit" class="btn btn-ghost">לא להציג שוב</button>
            </form>
        <?php endif; ?>
    </div>
</main>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
</body>
</html>
