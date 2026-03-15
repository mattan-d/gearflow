<?php

declare(strict_types=1);

if (!function_exists('current_user')) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/config.php';

// משתמש מחובר
$me = $me ?? current_user();
$role = $me['role'] ?? 'student';

// הגדרות עיצוב (צבעי Header/Footer + טקסט)
$designFile = __DIR__ . '/design_settings.json';
$headerBg = '#111827';
$footerBg = '#111827';
$headerText = '#f9fafb';
$headerLink = '#e5e7eb';
$headerMuted = '#9ca3af';
$footerText = '#f9fafb';
$footerMuted = '#9ca3af';
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
        if (!empty($data['header_text'])) {
            $headerText = (string)$data['header_text'];
        }
        if (!empty($data['header_link'])) {
            $headerLink = (string)$data['header_link'];
        }
        if (!empty($data['header_muted'])) {
            $headerMuted = (string)$data['header_muted'];
        }
        if (!empty($data['footer_text'])) {
            $footerText = (string)$data['footer_text'];
        } else {
            $footerText = $headerText;
        }
        if (!empty($data['footer_muted'])) {
            $footerMuted = (string)$data['footer_muted'];
        } else {
            $footerMuted = $headerMuted;
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

// התראות – קריאה בלבד (מחיקה מטופלת ב־notification_action.php כדי למנוע headers already sent)
$userId = isset($me['id']) ? (int)$me['id'] : 0;
$notifRedirectUri = $_SERVER['REQUEST_URI'] ?? 'admin.php';

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
        --gf-footer-text: <?= htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8') ?>;
        --gf-footer-muted: <?= htmlspecialchars($footerMuted, ENT_QUOTES, 'UTF-8') ?>;
        --gf-header-text: <?= htmlspecialchars($headerText, ENT_QUOTES, 'UTF-8') ?>;
        --gf-header-link: <?= htmlspecialchars($headerLink, ENT_QUOTES, 'UTF-8') ?>;
        --gf-header-muted: <?= htmlspecialchars($headerMuted, ENT_QUOTES, 'UTF-8') ?>;
    }
    .main-nav {
        margin-top: 0.5rem;
        display: flex;
        gap: 0.6rem;
        font-size: 0.85rem;
        white-space: nowrap;
        align-items: center;
        color: var(--gf-header-text);
    }
    .main-nav a {
        color: var(--gf-header-link);
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
        background: <?= htmlspecialchars($headerBg, ENT_QUOTES, 'UTF-8') ?>;
        border-radius: 8px;
        padding: 0.4rem 0.6rem;
        display: none;
        min-width: 170px;
        z-index: 30;
        color: var(--gf-header-text);
    }
    .main-nav-sub a {
        display: block;
        padding: 0.25rem 0.2rem;
        font-size: 0.8rem;
        color: var(--gf-header-link);
    }
    .main-nav-sub a + a {
        margin-top: 2px;
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
        color:rgb(255, 0, 0);
        filter: hue-rotate(0deg);
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
        background: <?= htmlspecialchars($headerBg, ENT_QUOTES, 'UTF-8') ?>;
        color: var(--gf-header-text);
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
        color: var(--gf-header-muted);
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
        color: var(--gf-header-link);
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
        color: var(--gf-header-muted);
        cursor: pointer;
        font-size: 0.8rem;
    }
    .btn.btn-file {
        border: 1px solid #d4a5a5;
        background: #f8d7da;
        color: #721c24;
    }
    .btn.btn-file:hover {
        background: #f5c2c7;
    }
    .btn-file input[type="file"] {
        position: absolute;
        width: 0.1px;
        height: 0.1px;
        opacity: 0;
        overflow: hidden;
        z-index: -1;
    }
    .header-logo-wrap {
        overflow: visible;
        flex-shrink: 0;
    }
    .header-logo-wrap img {
        display: block;
    }
    [data-lucide] {
        width: 1.25em;
        height: 1.25em;
        vertical-align: -0.25em;
    }
    .icon-btn [data-lucide],
    .modal-close [data-lucide],
    .notif-bell-btn [data-lucide],
    .calendar-icon-btn [data-lucide] {
        width: 1.1em;
        height: 1.1em;
    }
    header .user-info {
        color: var(--gf-header-text);
    }
    header .user-info a {
        color: var(--gf-header-link);
    }
    header .muted {
        color: var(--gf-header-muted);
    }
    .file-drop-zone {
        display: block;
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        padding: 1.5rem;
        background: #f9fafb;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
        position: relative;
    }
    .file-drop-zone:hover {
        border-color: #9ca3af;
        background: #f3f4f6;
    }
    .file-drop-zone.drag-over {
        border-color: #4f46e5;
        background: #eef2ff;
    }
    .file-drop-input {
        position: absolute;
        width: 0.1px;
        height: 0.1px;
        opacity: 0;
        overflow: hidden;
        z-index: -1;
    }
    .file-drop-text {
        display: block;
        font-size: 0.95rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.25rem;
    }
    .file-drop-hint {
        display: block;
        font-size: 0.8rem;
        color: #6b7280;
    }
    .file-drop-zone .file-drop-icon {
        width: 2rem;
        height: 2rem;
        margin: 0 auto 0.5rem;
        color: #9ca3af;
    }
</style>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.lucide) lucide.createIcons();
    document.querySelectorAll('.file-drop-zone').forEach(function(zone) {
        var input = zone.querySelector('.file-drop-input') || document.getElementById(zone.getAttribute('for'));
        if (!input || input.type !== 'file') return;
        ['dragenter','dragover'].forEach(function(ev) {
            zone.addEventListener(ev, function(e) { e.preventDefault(); zone.classList.add('drag-over'); });
        });
        ['dragleave','drop'].forEach(function(ev) {
            zone.addEventListener(ev, function(e) {
                e.preventDefault();
                zone.classList.remove('drag-over');
                if (ev === 'drop' && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                    var dt = new DataTransfer();
                    for (var i = 0; i < e.dataTransfer.files.length; i++) dt.items.add(e.dataTransfer.files[i]);
                    input.files = dt.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    });
});
</script>
<header style="background: <?= htmlspecialchars($headerBg, ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($headerText, ENT_QUOTES, 'UTF-8') ?>;">
    <div>
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <div class="header-logo-wrap" style="min-width:36px;height:36px;border-radius:10px;background:transparent;display:flex;align-items:center;justify-content:center;color:<?= htmlspecialchars($headerText, ENT_QUOTES, 'UTF-8') ?>;font-weight:700;font-size:0.85rem;">
                <?php if ($logoPath !== ''): ?>
                    <img src="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>" alt="לוגו" style="height:100%;max-width:100%;width:auto;object-fit:contain;border-radius:10px;">
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
                        onclick="var m=document.getElementById('notif_menu'); if(m){m.classList.toggle('visible');}"
                        aria-label="התראות">
                    <i data-lucide="bell" aria-hidden="true"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge"><?= (int)$unreadCount ?></span>
                    <?php endif; ?>
                </button>
                <div id="notif_menu" class="notif-menu">
                    <div class="notif-header">
                        <span>התראות</span>
                        <?php if ($unreadCount > 0 || !empty($notifications)): ?>
                            <form method="post" action="notification_action.php" style="margin:0;">
                                <input type="hidden" name="notif_action" value="delete_all">
                                <input type="hidden" name="redirect" value="<?= htmlspecialchars($notifRedirectUri, ENT_QUOTES, 'UTF-8') ?>">
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
                                    <form method="post" action="notification_action.php">
                                        <input type="hidden" name="notif_action" value="delete">
                                        <input type="hidden" name="notif_id" value="<?= (int)($n['id'] ?? 0) ?>">
                                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($notifRedirectUri, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" title="מחיקת התראה" aria-label="מחיקת התראה"><i data-lucide="x" aria-hidden="true"></i></button>
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

