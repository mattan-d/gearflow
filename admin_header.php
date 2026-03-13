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
$logoPath = '';
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
        if (!empty($data['logo_path'])) {
            $logoPath = (string)$data['logo_path'];
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

// התראות – קריאה / מחיקה
$userId = isset($me['id']) ? (int)$me['id'] : 0;
if ($userId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_action'])) {
    $action = $_POST['notif_action'];
    if ($action === 'delete' && isset($_POST['notif_id'])) {
        $nid = (int)$_POST['notif_id'];
        $stmtDel = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND (user_id = :uid OR (user_id IS NULL AND role = :role))');
        $stmtDel->execute([':id' => $nid, ':uid' => $userId, ':role' => $role]);
    } elseif ($action === 'delete_all') {
        $stmtDelAll = $pdo->prepare('DELETE FROM notifications WHERE user_id = :uid OR (user_id IS NULL AND role = :role)');
        $stmtDelAll->execute([':uid' => $userId, ':role' => $role]);
    }
    // לאחר פעולה נחזור לאותו דף כדי למנוע שליחת טופס חוזרת
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'admin.php'));
    exit;
}

// טעינת התראות להצגה
$notifications = [];
$unreadCount   = 0;
if ($userId > 0) {
    $stmtN = $pdo->prepare('SELECT id, message, link, is_read, created_at FROM notifications WHERE (user_id = :uid OR (user_id IS NULL AND role = :role)) ORDER BY created_at DESC LIMIT 20');
    $stmtN->execute([':uid' => $userId, ':role' => $role]);
    $notifications = $stmtN->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($notifications as $n) {
        if ((int)($n['is_read'] ?? 0) === 0) {
            $unreadCount++;
        }
    }
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
    .notif-wrapper {
        position: relative;
        margin-left: 0.75rem;
    }
    .notif-bell-btn {
        border: none;
        background: transparent;
        cursor: pointer;
        filter: grayscale(1);
        font-size: 1.1rem;
        position: relative;
        padding: 0;
    }
    .notif-bell-btn.has-unread {
        color:rgb(201, 0, 0);
    }
    .notif-badge {
        position: absolute;
        top: -0.25rem;
        right: -0.35rem;
        background: #ef4444;
        color: #f9fafb;
        border-radius: 999px;
        font-size: 0.65rem;
        padding: 0 0.25rem;
        line-height: 1.3;
    }
    .notif-menu {
        position: absolute;
        right: -0.5rem;
        top: 130%;
        background: #111827;
        color: #f9fafb;
        min-width: 260px;
        max-width: 320px;
        border-radius: 10px;
        box-shadow: 0 14px 35px rgba(0,0,0,0.55);
        padding: 0.5rem 0;
        z-index: 40;
        display: none;
    }
    .notif-menu.visible {
        display: block;
    }
    .notif-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.25rem 0.75rem 0.4rem;
        border-bottom: 1px solid #374151;
        font-size: 0.85rem;
    }
    .notif-header button {
        border: none;
        background: transparent;
        color: #9ca3af;
        cursor: pointer;
        font-size: 0.75rem;
    }
    .notif-list {
        max-height: 260px;
        overflow-y: auto;
    }
    .notif-item {
        padding: 0.4rem 0.75rem;
        font-size: 0.8rem;
        border-bottom: 1px solid #1f2937;
        display: flex;
        justify-content: space-between;
        gap: 0.4rem;
        align-items: flex-start;
    }
    .notif-item:last-child {
        border-bottom: none;
    }
    .notif-item a {
        color: #e5e7eb;
        text-decoration: none;
    }
    .notif-item a:hover {
        text-decoration: underline;
    }
    .notif-item form {
        margin: 0;
    }
    .notif-item button {
        border: none;
        background: transparent;
        color: #9ca3af;
        cursor: pointer;
        font-size: 0.8rem;
    }
</style>
<header style="background: <?= htmlspecialchars($headerBg, ENT_QUOTES, 'UTF-8') ?>;">
    <div>
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <div style="min-width:36px;height:36px;border-radius:10px;background:transparent;display:flex;align-items:center;justify-content:center;color:#f9fafb;font-weight:700;font-size:0.85rem;box-shadow:0 4px 10px rgba(0,0,0,0.25);overflow:hidden;">
                <?php if ($logoPath !== ''): ?>
                    <img src="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>" alt="לוגו" style="height:100%;width:auto;object-fit:contain;">
                <?php else: ?>
                    GF
                <?php endif; ?>
            </div>
            <div>
                <h1 style="margin:0;font-size:1.3rem;">
                    <?= ($role === 'admin' || $role === 'warehouse_manager') ? 'מערכת הזמנות ומלאי' : 'מערכת הזמנות' ?>
                </h1>
                <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
            </div>
        </div>
        <nav class="main-nav">
            <div class="main-nav-primary">
                <?php if ($role === 'admin' || $role === 'warehouse_manager'): ?>
                    <a href="admin_equipment.php">ניהול ציוד</a>
                    <a href="admin_orders.php">ניהול הזמנות</a>
                    <a href="admin_users.php">ניהול משתמשים</a>
                    <a href="admin_reports.php">דוחות</a>
                    <div class="main-nav-item-wrapper">
                        <a href="admin.php">ניהול מערכת</a>
                        <div class="main-nav-sub">
                            <a href="admin_settings.php">הגדרות</a>
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
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <div class="notif-wrapper">
                <button type="button"
                        class="notif-bell-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>"
                        onclick="var m=document.getElementById('notif_menu'); if(m){m.classList.toggle('visible');}">
                    🔔
                    <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge"><?= (int)$unreadCount ?></span>
                    <?php endif; ?>
                </button>
                <div id="notif_menu" class="notif-menu">
                    <div class="notif-header">
                        <span>התראות</span>
                        <?php if ($unreadCount > 0 || !empty($notifications)): ?>
                            <form method="post" action="" style="margin:0;">
                                <input type="hidden" name="notif_action" value="delete_all">
                                <button type="submit">מחק את כל ההתראות</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list">
                        <?php if (empty($notifications)): ?>
                            <div class="notif-item">
                                <span>אין התראות חדשות.</span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                                <div class="notif-item">
                                    <div>
                                        <?php if (!empty($n['link'])): ?>
                                            <a href="<?= htmlspecialchars($n['link'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($n['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($n['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                    <form method="post" action="">
                                        <input type="hidden" name="notif_action" value="delete">
                                        <input type="hidden" name="notif_id" value="<?= (int)($n['id'] ?? 0) ?>">
                                        <button type="submit" title="מחיקת התראה">✕</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <span>
                מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                (<?= $role === 'admin' ? 'אדמין' : ($role === 'warehouse_manager' ? 'מנהל מחסן' : 'סטודנט') ?>)
            </span>
            <a href="logout.php">התנתק</a>
        </div>
    </div>
</header>

