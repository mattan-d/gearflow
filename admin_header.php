<?php

declare(strict_types=1);

if (!function_exists('current_user')) {
    require_once __DIR__ . '/auth.php';
}

$me = $me ?? current_user();
$role = $me['role'] ?? 'student';

?>
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

