<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin();

$me  = current_user();
$pdo = get_db();

// קריאת שעות ברירת מחדל
$defaults = [];
$stmt = $pdo->query('SELECT day_of_week, open_time, close_time FROM default_hours ORDER BY day_of_week ASC');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = (int)($row['day_of_week'] ?? 0);
    $defaults[$d] = [
        'open'  => (string)($row['open_time'] ?? '09:00'),
        'close' => (string)($row['close_time'] ?? '16:00'),
    ];
}

// אם חסרים ימים – משלימים ברירת מחדל 09:00–16:00
for ($d = 0; $d <= 4; $d++) {
    if (!isset($defaults[$d])) {
        $defaults[$d] = ['open' => '09:00', 'close' => '16:00'];
    }
}

$daysLabels = [
    0 => 'ראשון',
    1 => 'שני',
    2 => 'שלישי',
    3 => 'רביעי',
    4 => 'חמישי',
];

$success = '';
$error   = '';

// עדכון שעות ברירת מחדל (ימים א-ה – ערך אחד לכל השבוע)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['default_hours_submit'])) {
    $open  = trim((string)($_POST['default_open_weekdays'] ?? ''));
    $close = trim((string)($_POST['default_close_weekdays'] ?? ''));
    $validOpen  = ['09:00', '10:00', '11:00', '12:00'];
    $validClose = ['12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];
    if (!in_array($open, $validOpen, true))  $open = $defaults[0]['open'];
    if (!in_array($close, $validClose, true)) $close = $defaults[0]['close'];
    try {
        $pdo->beginTransaction();
        $up = $pdo->prepare('INSERT OR REPLACE INTO default_hours (day_of_week, open_time, close_time) VALUES (:d, :o, :c)');
        for ($d = 0; $d <= 4; $d++) {
            $up->execute([':d' => $d, ':o' => $open, ':c' => $close]);
            $defaults[$d] = ['open' => $open, 'close' => $close];
        }
        $pdo->commit();
        $success = 'שעות ברירת המחדל נשמרו בהצלחה.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $error = 'שגיאה בשמירת שעות ברירת המחדל.';
    }
}

// קטגוריות ציוד – קריאה ועריכה
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['category_action'])) {
    $catAction = $_POST['category_action'];
    if ($catAction === 'add') {
        $newName = trim((string)($_POST['new_category_name'] ?? ''));
        if ($newName === '') {
            $error = $error ?: 'יש להזין שם קטגוריה חדש.';
        } else {
            try {
                $stmtAdd = $pdo->prepare('INSERT OR IGNORE INTO equipment_categories (name) VALUES (:n)');
                $stmtAdd->execute([':n' => $newName]);
                $success = 'הקטגוריה נוספה בהצלחה.';
            } catch (Throwable $e) {
                $error = 'שגיאה בהוספת קטגוריה.';
            }
        }
    } elseif ($catAction === 'rename') {
        $id      = (int)($_POST['category_id'] ?? 0);
        $oldName = trim((string)($_POST['old_name'] ?? ''));
        $newName = trim((string)($_POST['category_name'] ?? ''));
        if ($id <= 0 || $oldName === '' || $newName === '') {
            $error = $error ?: 'יש להזין שם חדש תקין לקטגוריה.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmtUp = $pdo->prepare('UPDATE equipment_categories SET name = :n WHERE id = :id');
                $stmtUp->execute([':n' => $newName, ':id' => $id]);
                // עדכון בציוד קיים
                $stmtEq = $pdo->prepare('UPDATE equipment SET category = :new WHERE category = :old');
                $stmtEq->execute([':new' => $newName, ':old' => $oldName]);
                $pdo->commit();
                $success = 'שם הקטגוריה עודכן בהצלחה.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'שגיאה בעדכון שם הקטגוריה.';
            }
        }
    } elseif ($catAction === 'delete') {
        $id   = (int)($_POST['category_id'] ?? 0);
        $name = trim((string)($_POST['old_name'] ?? ''));
        if ($id > 0 && $name !== '') {
            try {
                $pdo->beginTransaction();
                $stmtDel = $pdo->prepare('DELETE FROM equipment_categories WHERE id = :id');
                $stmtDel->execute([':id' => $id]);
                // ניקוי ערך הקטגוריה בציוד
                $stmtEq = $pdo->prepare('UPDATE equipment SET category = NULL WHERE category = :name');
                $stmtEq->execute([':name' => $name]);
                $pdo->commit();
                $success = 'הקטגוריה נמחקה בהצלחה.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'שגיאה במחיקת קטגוריה.';
            }
        }
    }
}

// קריאת קטגוריות
$categories = [];
try {
    $stmtCats = $pdo->query('SELECT id, name FROM equipment_categories ORDER BY name ASC');
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $categories = [];
}

// סטטוסי הזמנה – קריאה ועריכה (שם + צבע)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status_action']) && $_POST['status_action'] === 'save_status') {
    $code     = trim((string)($_POST['status_code'] ?? ''));
    $newLabel = trim((string)($_POST['status_label'] ?? ''));
    $newColor = trim((string)($_POST['status_color'] ?? ''));
    if ($newColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $newColor)) {
        $newColor = '';
    }
    if ($code !== '' && $newLabel !== '') {
        try {
            $stmtS = $pdo->prepare('INSERT OR REPLACE INTO order_status_labels (status, label_he, color_hex) VALUES (:s, :l, :c)');
            $stmtS->execute([':s' => $code, ':l' => $newLabel, ':c' => ($newColor !== '' ? $newColor : null)]);
            $success = 'הסטטוס עודכן בהצלחה.';
        } catch (Throwable $e) {
            $error = 'שגיאה בעדכון הסטטוס.';
        }
    }
}

$statusLabels = [];
try {
    $stmtSL = $pdo->query('SELECT status, label_he, color_hex FROM order_status_labels ORDER BY status ASC');
    $statusLabels = $stmtSL->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $statusLabels = [];
}

// הגדרת דף הבית לפי תפקיד
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['homepage_submit'])) {
    $homeStudent = trim((string)($_POST['home_student'] ?? 'admin_orders.php'));
    $homeAdmin   = trim((string)($_POST['home_admin'] ?? 'admin_orders.php'));

    // ולידציה בסיסית לפי רשימת נתיבים מותרים
    $studentAllowed = ['admin_orders.php'];
    $adminAllowed   = ['admin_orders.php', 'admin_equipment.php'];

    if (!in_array($homeStudent, $studentAllowed, true)) {
        $homeStudent = 'admin_orders.php';
    }
    if (!in_array($homeAdmin, $adminAllowed, true)) {
        $homeAdmin = 'admin_orders.php';
    }

    try {
        $stmtHome = $pdo->prepare('INSERT OR REPLACE INTO app_settings (key, value) VALUES (:k, :v)');
        $stmtHome->execute([':k' => 'home_student', ':v' => $homeStudent]);
        $stmtHome->execute([':k' => 'home_admin',   ':v' => $homeAdmin]);
        $success = 'דפי הבית עודכנו בהצלחה.';
    } catch (Throwable $e) {
        $error = 'שגיאה בעדכון דפי הבית.';
    }
}

// מצב בדיקות: מחסן פתוח תמיד (עקיפת שעות פעילות)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['always_open_submit'])) {
    $alwaysOpenEnabled = isset($_POST['warehouse_always_open']) ? '1' : '0';
    try {
        $stmtAlwaysOpen = $pdo->prepare('INSERT OR REPLACE INTO app_settings (key, value) VALUES (:k, :v)');
        $stmtAlwaysOpen->execute([':k' => 'warehouse_always_open', ':v' => $alwaysOpenEnabled]);
        $success = 'הגדרת "מחסן פתוח תמיד" עודכנה בהצלחה.';
    } catch (Throwable $e) {
        $error = 'שגיאה בעדכון ההגדרה "מחסן פתוח תמיד".';
    }
}

// קריאת דפי בית נוכחיים
$homeStudentCurrent = 'admin_orders.php';
$homeAdminCurrent   = 'admin_orders.php';
$warehouseAlwaysOpen = false;
try {
    $stmtHomeRead = $pdo->query("SELECT key, value FROM app_settings WHERE key IN ('home_student','home_admin','warehouse_always_open')");
    foreach ($stmtHomeRead->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['key'] ?? '') === 'home_student' && is_string($row['value'] ?? null)) {
            $homeStudentCurrent = (string)$row['value'];
        } elseif (($row['key'] ?? '') === 'home_admin' && is_string($row['value'] ?? null)) {
            $homeAdminCurrent = (string)$row['value'];
        } elseif (($row['key'] ?? '') === 'warehouse_always_open') {
            $warehouseAlwaysOpen = trim((string)($row['value'] ?? '0')) === '1';
        }
    }
} catch (Throwable $e) {
    // נשאר עם ברירות המחדל
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>הגדרות מערכת - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        header {
            background: #111827;
            color: #f9fafb;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        header h1 {
            margin: 0;
            font-size: 1.3rem;
        }
        header .user-info {
            font-size: 0.9rem;
            color: #e5e7eb;
        }
        header a {
            color: #f9fafb;
            text-decoration: none;
            margin-right: 1rem;
            font-size: 0.85rem;
        }
        main.settings-main {
            max-width: 900px;
            margin: 1.5rem auto 2rem;
            padding: 0 1rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        h1, h2 {
            margin-top: 0;
        }
        h2 {
            font-size: 1.3rem;
            color: #111827;
            margin-bottom: 1rem;
        }
        .muted {
            color: #9ca3af;
            font-size: 0.8rem;
        }
        .muted-small {
            font-size: 0.9rem;
            color: #4b5563;
        }
        table.default-hours {
            width: 100%;
            max-width: 480px;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }
        table.default-hours th,
        table.default-hours td {
            border: 1px solid #e5e7eb;
            padding: 0.35rem 0.5rem;
            font-size: 0.9rem;
            text-align: center;
        }
        table.default-hours th {
            background: #f9fafb;
            font-weight: 600;
            color: #111827;
        }
        table.default-hours input[type="time"],
        table.default-hours select.default-hours-select {
            padding: 0.35rem 0.5rem;
            font-size: 0.9rem;
        }
        table.default-hours select.default-hours-select {
            min-width: 80px;
        }
        .flash {
            margin-bottom: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.85rem;
        }
        .flash.success {
            background: #ecfdf3;
            color: #166534;
        }
        .flash.error {
            background: #fef2f2;
            color: #b91c1c;
        }
        .btn {
            border-radius: 999px;
            border: none;
            background: #111827;
            color: #f9fafb;
            padding: 0.45rem 1.1rem;
            font-size: 0.85rem;
            cursor: pointer;
            margin-top: 0.75rem;
        }
        .btn.small {
            padding: 0.25rem 0.7rem;
            font-size: 0.8rem;
            margin-top: 0;
        }
        .category-section {
            margin-top: 1.75rem;
        }
        table.category-table {
            width: 100%;
            max-width: 520px;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }
        table.category-table th,
        table.category-table td {
            border: 1px solid #e5e7eb;
            padding: 0.35rem 0.5rem;
            font-size: 0.9rem;
            text-align: right;
        }
        table.category-table th {
            background: #f9fafb;
            font-weight: 600;
        }
        .status-section {
            margin-top: 1.75rem;
        }
        table.status-table {
            width: 100%;
            max-width: 520px;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }
        table.status-table th,
        table.status-table td {
            border: 1px solid #e5e7eb;
            padding: 0.35rem 0.5rem;
            font-size: 0.9rem;
            text-align: right;
        }
        table.status-table th {
            background: #f9fafb;
            font-weight: 600;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main class="settings-main">
    <div class="card">
        <h2>הגדרות מערכת</h2>
        <?php if ($success !== ''): ?>
            <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <h3 style="margin-top:0.5rem;margin-bottom:0.5rem;font-size:1.05rem;">שעות ברירת מחדל לפתיחת מחסן</h3>
        <p class="muted-small">
            שעות אלו ישמשו כברירת מחדל בעת הגדרת שעות פתיחה למחסנים חדשים, ובהיעדר הגדרה ספציפית למחסן.
        </p>

        <?php
            $weekdaysOpen  = $defaults[0]['open'] ?? '09:00';
            $weekdaysClose = $defaults[0]['close'] ?? '16:00';
            $openOptions  = ['09:00', '10:00', '11:00', '12:00'];
            $closeOptions = ['12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];
            ?>
        <form method="post" action="admin_settings.php">
            <table class="default-hours">
                <thead>
                <tr>
                    <th>ימים</th>
                    <th>שעת פתיחה</th>
                    <th>שעת סגירה</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>ימים א׳–ה׳</td>
                    <td>
                        <select name="default_open_weekdays" class="default-hours-select">
                            <?php foreach ($openOptions as $t): ?>
                            <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>" <?= $weekdaysOpen === $t ? 'selected' : '' ?>><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="default_close_weekdays" class="default-hours-select">
                            <?php foreach ($closeOptions as $t): ?>
                            <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>" <?= $weekdaysClose === $t ? 'selected' : '' ?>><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                </tbody>
            </table>
            <button type="submit" class="btn" name="default_hours_submit" value="1">שמירת שעות ברירת מחדל</button>
        </form>

        <div class="status-section">
            <h3 style="margin-top:1.5rem;margin-bottom:0.5rem;font-size:1.05rem;">מצב בדיקות מערכת</h3>
            <p class="muted-small">
                כאשר האפשרות פעילה ניתן לנהל הזמנות בכל תאריך ובכל שעה, ללא קשר לשעות הפעילות.
            </p>
            <form method="post" action="admin_settings.php" style="max-width:520px;margin-top:0.75rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;">
                    <input type="checkbox" name="warehouse_always_open" value="1" <?= $warehouseAlwaysOpen ? 'checked' : '' ?>>
                    <span>מחסן פתוח תמיד</span>
                </label>
                <button type="submit" name="always_open_submit" value="1" class="btn">שמירת הגדרה</button>
            </form>
        </div>

        <div class="category-section">
            <h3 style="margin-top:1.5rem;margin-bottom:0.5rem;font-size:1.05rem;">קטגוריות ציוד</h3>
            <p class="muted-small">
                רשימת קטגוריות הציוד הזמינות בטפסים. ניתן לשנות שם לקטגוריה, להוסיף קטגוריה חדשה או למחוק קטגוריה קיימת.
            </p>

            <table class="category-table">
                <thead>
                <tr>
                    <th style="width:60%;">שם קטגוריה</th>
                    <th>פעולות</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td>
                            <form method="post" action="admin_settings.php" style="display:flex;align-items:center;gap:0.4rem;margin:0;">
                                <input type="hidden" name="category_action" value="rename">
                                <input type="hidden" name="category_id" value="<?= (int)($cat['id'] ?? 0) ?>">
                                <input type="hidden" name="old_name" value="<?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="text"
                                       name="category_name"
                                       value="<?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       style="flex:1;padding:0.3rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;">
                                <button type="submit" class="btn small">שמור</button>
                            </form>
                        </td>
                        <td style="text-align:center;">
                            <form method="post" action="admin_settings.php" style="margin:0;"
                                  onsubmit="return confirm('למחוק את הקטגוריה \"<?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>\"? ציוד המשויך אליה יאבד את שיוך הקטגוריה.');">
                                <input type="hidden" name="category_action" value="delete">
                                <input type="hidden" name="category_id" value="<?= (int)($cat['id'] ?? 0) ?>">
                                <input type="hidden" name="old_name" value="<?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn small" style="background:#f87171;">מחיקה</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="2">
                        <form method="post" action="admin_settings.php" style="display:flex;align-items:center;gap:0.4rem;margin:0;">
                            <input type="hidden" name="category_action" value="add">
                            <input type="text" name="new_category_name"
                                   placeholder="שם קטגוריה חדש"
                                   style="flex:1;padding:0.3rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;">
                            <button type="submit" class="btn small">הוספת קטגוריה</button>
                        </form>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <div class="status-section">
            <h3 style="margin-top:1.5rem;margin-bottom:0.5rem;font-size:1.05rem;">סטטוסי הזמנה</h3>
            <p class="muted-small">
                ניתן לשנות את שם הסטטוס שמוצג למשתמשים (הקוד הפנימי של הסטטוס נשאר קבוע לצורכי המערכת).
            </p>
            <table class="status-table">
                <thead>
                <tr>
                    <th>קוד סטטוס</th>
                    <th>שם מוצג</th>
                    <th>צבע</th>
                    <th>שמירה</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($statusLabels as $st): ?>
                    <tr>
                        <td style="font-family:monospace;direction:ltr;"><?= htmlspecialchars((string)($st['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td colspan="3">
                            <?php
                            $colorVal = (string)($st['color_hex'] ?? '');
                            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorVal)) {
                                $colorVal = '#6b7280';
                            }
                            ?>
                            <form method="post" action="admin_settings.php" style="display:flex;align-items:center;gap:0.5rem;margin:0;flex-wrap:wrap;">
                                <input type="hidden" name="status_action" value="save_status">
                                <input type="hidden" name="status_code" value="<?= htmlspecialchars((string)($st['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="text"
                                       name="status_label"
                                       value="<?= htmlspecialchars((string)($st['label_he'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       style="flex:1;min-width:160px;padding:0.3rem 0.5rem;border-radius:8px;border:1px solid #d1d5db;">
                                <input type="color"
                                       name="status_color"
                                       value="<?= htmlspecialchars($colorVal, ENT_QUOTES, 'UTF-8') ?>"
                                       title="בחירת צבע"
                                       aria-label="בחירת צבע לסטטוס"
                                       style="width:42px;height:32px;border-radius:8px;border:1px solid #d1d5db;padding:0;">
                                <button type="submit" class="btn small">שמור</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="status-section">
            <h3 style="margin-top:1.5rem;margin-bottom:0.5rem;font-size:1.05rem;">קביעת דף הבית</h3>
            <p class="muted-small">
                בחר את דף הבית שיוטען לאחר התחברות ולביקור בכתובת הראשית של המערכת, לפי סוג המשתמש.
            </p>
            <form method="post" action="admin_settings.php" style="max-width:520px;margin-top:0.75rem;">
                <div style="display:flex;flex-direction:column;gap:0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <label for="home_student" style="width:160px;">דף בית לסטודנט</label>
                        <select id="home_student" name="home_student"
                                style="flex:1;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.9rem;">
                            <option value="admin_orders.php" <?= $homeStudentCurrent === 'admin_orders.php' ? 'selected' : '' ?>>
                                מנהל הזמנות
                            </option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <label for="home_admin" style="width:160px;">דף בית למנהל</label>
                        <select id="home_admin" name="home_admin"
                                style="flex:1;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;font-size:0.9rem;">
                            <option value="admin_orders.php" <?= $homeAdminCurrent === 'admin_orders.php' ? 'selected' : '' ?>>
                                מנהל הזמנות (ברירת מחדל)
                            </option>
                            <option value="admin_equipment.php" <?= $homeAdminCurrent === 'admin_equipment.php' ? 'selected' : '' ?>>
                                מנהל ציוד
                            </option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" name="homepage_submit" value="1" class="btn">
                            שמירת דפי הבית
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>

