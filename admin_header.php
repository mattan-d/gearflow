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
$titleStudent = 'מערכת הזמנות';
$titleAdmin = 'מערכת הזמנות ומלאי';
$navLabels = [
    'equipment' => 'ניהול ציוד',
    'orders'    => 'ניהול הזמנות',
    'users'     => 'ניהול משתמשים',
    'suppliers' => 'ספקים',
    'reports'   => 'דוחות',
    'system'    => 'ניהול מערכת',
];
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
        if (!empty($data['title_student'])) {
            $titleStudent = (string)$data['title_student'];
        }
        if (!empty($data['title_admin'])) {
            $titleAdmin = (string)$data['title_admin'];
        }
        if (!empty($data['nav_equipment'])) {
            $navLabels['equipment'] = (string)$data['nav_equipment'];
        }
        if (!empty($data['nav_orders'])) {
            $navLabels['orders'] = (string)$data['nav_orders'];
        }
        if (!empty($data['nav_users'])) {
            $navLabels['users'] = (string)$data['nav_users'];
        }
        if (!empty($data['nav_suppliers'])) {
            $navLabels['suppliers'] = (string)$data['nav_suppliers'];
        }
        if (!empty($data['nav_reports'])) {
            $navLabels['reports'] = (string)$data['nav_reports'];
        }
        if (!empty($data['nav_system'])) {
            $navLabels['system'] = (string)$data['nav_system'];
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

$dailyCalendarsNav = [];
try {
    $dailyCalendarsNav = gf_daily_calendars_for_nav($pdo, $role);
} catch (Throwable $e) {
    $dailyCalendarsNav = [];
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

// שליחת מייל מה-Header: נמענים מותרים לפי תפקיד ומחסן
$mailRecipients = [];
$meWarehouse = trim((string)($me['warehouse'] ?? ''));
try {
    if ($role === 'student') {
        $stmtMailRecipients = $pdo->prepare(
            "SELECT id, username, first_name, last_name, role, warehouse, email
             FROM users
             WHERE is_active = 1
               AND role = 'warehouse_manager'
               AND warehouse = :warehouse
               AND TRIM(COALESCE(email, '')) <> ''
             ORDER BY first_name ASC, last_name ASC, username ASC"
        );
        $stmtMailRecipients->execute([':warehouse' => $meWarehouse]);
        $mailRecipients = $stmtMailRecipients->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($role === 'warehouse_manager') {
        $stmtMailRecipients = $pdo->prepare(
            "SELECT id, username, first_name, last_name, role, warehouse, email
             FROM users
             WHERE is_active = 1
               AND role = 'student'
               AND warehouse = :warehouse
               AND TRIM(COALESCE(email, '')) <> ''
             ORDER BY first_name ASC, last_name ASC, username ASC"
        );
        $stmtMailRecipients->execute([':warehouse' => $meWarehouse]);
        $mailRecipients = $stmtMailRecipients->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($role === 'admin') {
        $stmtMailRecipients = $pdo->prepare(
            "SELECT id, username, first_name, last_name, role, warehouse, email
             FROM users
             WHERE is_active = 1
               AND id != :my_id
               AND TRIM(COALESCE(email, '')) <> ''
             ORDER BY role ASC, warehouse ASC, first_name ASC, last_name ASC, username ASC"
        );
        $stmtMailRecipients->execute([':my_id' => $userId]);
        $mailRecipients = $stmtMailRecipients->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $mailRecipients = [];
}

// Flash להודעות שליחת מייל
$mailFlashSuccess = '';
$mailFlashError = '';
if (isset($_SESSION['header_mail_success']) && is_string($_SESSION['header_mail_success'])) {
    $mailFlashSuccess = $_SESSION['header_mail_success'];
    unset($_SESSION['header_mail_success']);
}
if (isset($_SESSION['header_mail_error']) && is_string($_SESSION['header_mail_error'])) {
    $mailFlashError = $_SESSION['header_mail_error'];
    unset($_SESSION['header_mail_error']);
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
    header {
        padding: 1rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: nowrap;
    }
    header > div:first-of-type {
        flex-shrink: 0;
    }
    header .user-info {
        flex-shrink: 0;
        text-align: left;
    }
    header h1 {
        margin: 0;
        font-size: 1.3rem;
    }
    header .muted {
        font-size: 0.8rem;
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
        padding: 0.2rem 0.35rem;
        border-radius: 6px;
        transition: color 0.2s ease;
    }
    .main-nav a:hover {
        color: var(--gf-header-text);
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
        left: auto;
        top: 100%;
        background: <?= htmlspecialchars($headerBg, ENT_QUOTES, 'UTF-8') ?>;
        border-radius: 8px;
        padding: 0.4rem 0.6rem;
        display: none;
        min-width: 100%;
        width: max-content;
        z-index: 30;
        color: var(--gf-header-text);
        box-shadow: none !important;
        direction: rtl;
        text-align: right;
    }
    .main-nav-sub a {
        display: block;
        width: 100%;
        padding: 0.35rem 0.6rem;
        font-size: 0.8rem;
        color: var(--gf-header-link);
        transition: color 0.2s ease;
        text-align: right !important;
        direction: rtl;
        background: transparent !important;
    }
    .main-nav-sub a + a {
        border-top: 1px solid #f3f4f6;
        margin-inline: -0.6rem;
        padding-inline: 0.6rem;
    }
    .main-nav-sub a:hover {
        color: var(--gf-header-text);
        background: transparent !important;
    }
    .main-nav-item-wrapper:hover .main-nav-sub {
        display: block;
    }
    .notif-wrapper {
        position: relative;
        margin-left: 0.75rem;
    }
    .mail-wrapper {
        position: relative;
        margin-left: 0.35rem;
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
    .mail-btn {
        border: none;
        background: transparent;
        cursor: pointer;
        color: var(--gf-header-link);
        font-size: 1.1rem;
        padding: 0;
        display: inline-flex;
        align-items: center;
    }
    .mail-btn:hover {
        color: var(--gf-header-text);
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
    .header-mail-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(17, 24, 39, 0.55);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1200;
    }
    .header-mail-modal-backdrop.visible {
        display: flex;
    }
    .header-mail-modal {
        width: min(520px, calc(100vw - 2rem));
        background: #ffffff;
        color: #111827;
        border-radius: 12px;
        box-shadow: 0 24px 48px rgba(15, 23, 42, 0.28);
        padding: 1rem;
    }
    .header-mail-modal h3 {
        margin: 0 0 0.75rem 0;
        font-size: 1.1rem;
    }
    .header-mail-row {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        margin-bottom: 0.7rem;
    }
    .header-mail-row label {
        font-size: 0.85rem;
        color: #374151;
    }
    .header-mail-row input,
    .header-mail-row textarea {
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 0.45rem 0.6rem;
        font-size: 0.9rem;
        font-family: inherit;
    }
    .header-mail-row textarea {
        min-height: 110px;
        resize: vertical;
    }
    .header-mail-actions {
        display: flex;
        gap: 0.55rem;
        justify-content: flex-end;
        margin-top: 0.9rem;
    }
    .header-mail-actions button {
        border: none;
        border-radius: 999px;
        padding: 0.42rem 1rem;
        cursor: pointer;
        font-size: 0.85rem;
    }
    .header-mail-send {
        background: #111827;
        color: #f9fafb;
    }
    .header-mail-cancel {
        background: #e5e7eb;
        color: #111827;
    }
    .header-mail-flash {
        margin: 0.45rem 0 0.2rem;
        font-size: 0.8rem;
        padding: 0.35rem 0.55rem;
        border-radius: 7px;
    }
    .header-mail-flash.success {
        background: #ecfdf3;
        color: #166534;
    }
    .header-mail-flash.error {
        background: #fef2f2;
        color: #b91c1c;
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
        padding: 0.5rem;
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
    var openMailBtn = document.getElementById('header_open_mail_modal');
    var mailBackdrop = document.getElementById('header_mail_modal_backdrop');
    var closeMailBtn = document.getElementById('header_mail_close');
    var cancelMailBtn = document.getElementById('header_mail_cancel');
    var toInput = document.getElementById('header_mail_to');
    var toHidden = document.getElementById('header_mail_to_id');
    var subjectInput = document.getElementById('header_mail_subject');
    var recipientHint = document.getElementById('header_mail_recipient_hint');
    var recipientMap = {};
    document.querySelectorAll('#header_mail_recipients option').forEach(function(opt) {
        if (!opt || !opt.value) return;
        recipientMap[opt.value] = opt.getAttribute('data-user-id') || '';
    });
    function closeMailModal() {
        if (mailBackdrop) mailBackdrop.classList.remove('visible');
    }
    if (openMailBtn && mailBackdrop) {
        openMailBtn.addEventListener('click', function() {
            mailBackdrop.classList.add('visible');
            if (toInput) toInput.focus();
        });
    }
    if (closeMailBtn) closeMailBtn.addEventListener('click', closeMailModal);
    if (cancelMailBtn) cancelMailBtn.addEventListener('click', closeMailModal);
    if (mailBackdrop) {
        mailBackdrop.addEventListener('click', function(e) {
            if (e.target === mailBackdrop) closeMailModal();
        });
    }
    if (toInput && toHidden) {
        toInput.addEventListener('input', function() {
            var val = (toInput.value || '').trim();
            toHidden.value = recipientMap[val] || '';
            if (recipientHint) {
                recipientHint.textContent = toHidden.value ? '' : 'בחר נמען מהרשימה האוטומטית.';
            }
        });
    }
    var mailForm = document.getElementById('header_mail_form');
    if (mailForm && toInput && toHidden && subjectInput) {
        mailForm.addEventListener('submit', function(e) {
            var toVal = (toInput.value || '').trim();
            if (!toHidden.value) {
                e.preventDefault();
                if (recipientHint) recipientHint.textContent = 'יש לבחור נמען מהרשימה.';
                toInput.focus();
                return;
            }
            if (!subjectInput.value.trim()) {
                e.preventDefault();
                subjectInput.focus();
                return;
            }
            if (recipientMap[toVal] !== toHidden.value) {
                e.preventDefault();
                if (recipientHint) recipientHint.textContent = 'בחר נמען מהרשימה.';
                toInput.focus();
            }
        });
    }
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
                <?php
                $userWarehouse = trim((string)($me['warehouse'] ?? ''));
                $headerTitle = ($role === 'admin' || $role === 'warehouse_manager') ? $titleAdmin : $titleStudent;
                ?>
                <h1 style="margin:0;font-size:1.3rem;">
                    <?= htmlspecialchars($headerTitle, ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($userWarehouse !== ''): ?>
                        · <?= htmlspecialchars($userWarehouse, ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </h1>
                <div class="muted">
                    פלטפורמה לניהול השאלת ציוד
                </div>
            </div>
        </div>
        <nav class="main-nav">
            <div class="main-nav-primary">
                <?php if ($role === 'admin' || $role === 'warehouse_manager'): ?>
                    <div class="main-nav-item-wrapper">
                        <a href="admin_equipment.php"><?= htmlspecialchars($navLabels['equipment'], ENT_QUOTES, 'UTF-8') ?></a>
                        <div class="main-nav-sub">
                            <a href="admin_equipment.php">ניהול ציוד</a>
                            <a href="admin_inventory_count.php">ספירת מלאי</a>
                        </div>
                    </div>
                    <a href="admin_orders.php"><?= htmlspecialchars($navLabels['orders'], ENT_QUOTES, 'UTF-8') ?></a>
                    <a href="admin_users.php"><?= htmlspecialchars($navLabels['users'], ENT_QUOTES, 'UTF-8') ?></a>
                    <a href="admin_suppliers.php"><?= htmlspecialchars($navLabels['suppliers'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php if (!empty($dailyCalendarsNav)): ?>
                        <div class="main-nav-item-wrapper">
                            <a href="admin_daily.php?calendar_id=<?= (int)$dailyCalendarsNav[0]['id'] ?>">יומן</a>
                            <div class="main-nav-sub">
                                <?php foreach ($dailyCalendarsNav as $dcNav): ?>
                                    <a href="admin_daily.php?calendar_id=<?= (int)($dcNav['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string)($dcNav['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <a href="admin_reports.php"><?= htmlspecialchars($navLabels['reports'], ENT_QUOTES, 'UTF-8') ?></a>
                    <div class="main-nav-item-wrapper">
                        <a href="admin.php"><?= htmlspecialchars($navLabels['system'], ENT_QUOTES, 'UTF-8') ?></a>
                        <div class="main-nav-sub">
                            <a href="admin_settings.php">הגדרות</a>
                            <a href="admin_documents.php">מסמכים</a>
                            <a href="admin_design.php">עיצוב ממשק</a>
                            <a href="admin_times.php">ניהול זמנים</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!empty($dailyCalendarsNav)): ?>
                        <div class="main-nav-item-wrapper">
                            <a href="admin_daily.php?calendar_id=<?= (int)$dailyCalendarsNav[0]['id'] ?>">יומן</a>
                            <div class="main-nav-sub">
                                <?php foreach ($dailyCalendarsNav as $dcNav): ?>
                                    <a href="admin_daily.php?calendar_id=<?= (int)($dcNav['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string)($dcNav['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <a href="admin_orders.php"><?= htmlspecialchars($navLabels['orders'], ENT_QUOTES, 'UTF-8') ?></a>
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
            <div class="mail-wrapper">
                <button type="button" id="header_open_mail_modal" class="mail-btn" aria-label="שליחת מייל">
                    <i data-lucide="mail" aria-hidden="true"></i>
                </button>
            </div>
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
        <?php if ($mailFlashSuccess !== ''): ?>
            <div class="header-mail-flash success"><?= htmlspecialchars($mailFlashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($mailFlashError !== ''): ?>
            <div class="header-mail-flash error"><?= htmlspecialchars($mailFlashError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>
</header>

<div id="header_mail_modal_backdrop" class="header-mail-modal-backdrop" aria-hidden="true">
    <div class="header-mail-modal" role="dialog" aria-modal="true" aria-labelledby="header_mail_title">
        <h3 id="header_mail_title">שליחת מייל למשתמש</h3>
        <?php if (empty($mailRecipients)): ?>
            <div class="header-mail-flash error" style="margin:0;">לא נמצאו נמענים זמינים לשליחה לפי ההרשאות שלך.</div>
            <div class="header-mail-actions">
                <button type="button" id="header_mail_close" class="header-mail-cancel">סגירה</button>
            </div>
        <?php else: ?>
            <form id="header_mail_form" method="post" action="mail_action.php">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($notifRedirectUri, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="to_user_id" id="header_mail_to_id" value="">
                <div class="header-mail-row">
                    <label for="header_mail_to">אל (הקלד/י שם לבחירה אוטומטית)</label>
                    <input type="text" id="header_mail_to" list="header_mail_recipients" autocomplete="off" placeholder="התחל/י להקליד שם משתמש">
                    <datalist id="header_mail_recipients">
                        <?php foreach ($mailRecipients as $r): ?>
                            <?php
                            $fullName = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                            $displayName = $fullName !== '' ? $fullName : (string)($r['username'] ?? '');
                            $label = $displayName . ' (' . (string)($r['username'] ?? '') . ')';
                            if (trim((string)($r['warehouse'] ?? '')) !== '') {
                                $label .= ' - ' . (string)$r['warehouse'];
                            }
                            ?>
                            <option value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" data-user-id="<?= (int)($r['id'] ?? 0) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <small id="header_mail_recipient_hint" style="color:#6b7280;font-size:0.78rem;"></small>
                </div>
                <div class="header-mail-row">
                    <label for="header_mail_subject">נושא</label>
                    <input type="text" id="header_mail_subject" name="subject" maxlength="180" required>
                </div>
                <div class="header-mail-row">
                    <label for="header_mail_body">תוכן ההודעה</label>
                    <textarea id="header_mail_body" name="body" maxlength="10000" required></textarea>
                </div>
                <div class="header-mail-actions">
                    <button type="button" id="header_mail_cancel" class="header-mail-cancel">ביטול</button>
                    <button type="submit" class="header-mail-send">שליחת מייל</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

