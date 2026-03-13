<?php

declare(strict_types=1);

if (!function_exists('current_user')) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/config.php';

// משתמש מחובר
$me = $me ?? current_user();
$role = $me['role'] ?? 'student';

// הגדרות עיצוב (צבעי Header/Footer)
$designFile = __DIR__ . '/design_settings.json';
$headerBg = '#111827';
$footerBg = '#111827';
if (is_file($designFile)) {
    $json = file_get_contents($designFile);
    $data = json_decode($json, true);
    if (is_array($data)) {
        if (!empty($data['header_bg'])) {
            $headerBg = (string)$data['header_bg'];
        }
        if (!empty($data['footer_bg'])) {
            $footerBg = (string)$data['footer_bg'];
        }
    }
}

// DB – מסמכים
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
    :root {
        --gf-footer-bg: <?= htmlspecialchars($footerBg, ENT_QUOTES, 'UTF-8') ?>;
    }
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
<header style="background: <?= htmlspecialchars($headerBg, ENT_QUOTES, 'UTF-8') ?>;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;border-radius:10px;background:#f9fafb;display:flex;align-items:center;justify-content:center;color:#111827;font-weight:700;font-size:0.85rem;box-shadow:0 4px 10px rgba(0,0,0,0.25);">
            GF
        </div>
        <div>
            <h1 style="margin:0;font-size:1.3rem;">
                <?= ($role === 'admin' || $role === 'warehouse_manager') ? 'מערכת הזמנות ומלאי' : 'מערכת הזמנות' ?>
            </h1>
            <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        </div>
        <nav class="main-nav">
            <div class="main-nav-primary">
                <?php if ($role === 'admin' || $role === 'warehouse_manager'): ?>
                    <a href="admin_equipment.php">ניהול ציוד</a>
                    <a href="admin_orders.php">ניהול הזמנות</a>
                    <a href="admin_users.php">ניהול משתמשים</a>
                    <div class="main-nav-item-wrapper">
                        <a href="admin.php">ניהול מערכת</a>
                        <div class="main-nav-sub">
                            <a href="admin_documents.php">מסמכים</a>
                            <a href="admin_design.php">עיצוב ממשק</a>
                            <a href="admin_times.php">ניהול זמנים</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="admin_orders.php">ניהול הזמנות</a>
                <?php endif; ?>
                <div class="main-nav-item-wrapper">
                    <a href="#">נהלים</a>
                    <div class="main-nav-sub">
                        <a href="warehouse_rules.php">נהלי מחסן</a>
                        <?php foreach ($customDocs as $doc): ?>
                            <?php $docId = (int)($doc['id'] ?? 0); ?>
                            <a href="admin_documents.php?custom_id=<?= $docId ?>">
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

