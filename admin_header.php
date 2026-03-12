<?php

declare(strict_types=1);

if (!function_exists('current_user')) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/config.php';

$me = $me ?? current_user();
$role = $me['role'] ?? 'student';

$pdo = get_db();
$customDocs = [];
try {
    $stmtDocs = $pdo->query('SELECT id, title FROM documents_custom ORDER BY title ASC');
    $customDocs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $customDocs = [];
}

?>
<style>
    .main-nav {
        margin-top: 0.5rem;
        display: flex;
        gap: 0.6rem;
        font-size: 0.85rem;
        white-space: nowrap;
        align-items: center;
    }
    .main-nav a {
        color: #e5e7eb;
        text-decoration: none;
        display: inline-block;
    }
    .main-nav-primary {
        display: flex;
        gap: 0.6rem;
    }
    .main-nav-item-wrapper {
        position: relative;
        display: inline-block;
    }
    .main-nav-sub {
        position: absolute;
        right: 0;
        top: 100%;
        background: #111827;
        border-radius: 8px;
        padding: 0.4rem 0.6rem;
        box-shadow: 0 12px 30px rgba(0,0,0,0.45);
        display: none;
        min-width: 170px;
        z-index: 30;
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
</style>
<header>
    <div>
        <h1>ניהול מערכת</h1>
        <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        <nav class="main-nav">
            <div class="main-nav-primary">
                <?php if ($role === 'admin' || $role === 'warehouse_manager'): ?>
                    <div class="main-nav-item-wrapper">
                        <a href="admin.php">ניהול מערכת</a>
                        <div class="main-nav-sub">
                            <a href="admin_documents.php">ניהול מסמכים</a>
                            <a href="admin_design.php">עיצוב ממשק</a>
                            <a href="admin_times.php">ניהול זמנים</a>
                        </div>
                    </div>
                    <a href="admin_users.php">ניהול משתמשים</a>
                    <a href="admin_orders.php">ניהול הזמנות</a>
                    <a href="admin_equipment.php">ניהול ציוד</a>
                <?php else: ?>
                    <a href="admin_orders.php">ניהול הזמנות</a>
                <?php endif; ?>
                <div class="main-nav-item-wrapper">
                    <a href="#">נהלים</a>
                    <div class="main-nav-sub">
                        <a href="warehouse_rules.php">נהלי מחסן</a>
                        <?php foreach ($customDocs as $doc): ?>
                            <a href="document_view.php?id=<?= (int)($doc['id'] ?? 0) ?>">
                                <?= htmlspecialchars((string)($doc['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </nav>
    </div>
    <div class="user-info">
        מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        (<?= $role === 'admin' ? 'אדמין' : ($role === 'warehouse_manager' ? 'מנהל מחסן' : 'סטודנט') ?>)
        <a href="logout.php">התנתק</a>
    </div>
</header>

