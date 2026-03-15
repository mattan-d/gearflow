<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$me = current_user();

// קובץ הגדרות עיצוב פשוט (JSON)
$designFile = __DIR__ . '/design_settings.json';
$defaultDesign = [
    'header_bg' => '#111827', // שחור כהה
    'footer_bg' => '#111827',
    'logo_path' => '',
];
$design = $defaultDesign;
if (is_file($designFile)) {
    $json = file_get_contents($designFile);
    $data = json_decode($json, true);
    if (is_array($data)) {
        $design = array_merge($design, $data);
    }
}

// אפשרויות צבע כהות
$colorOptions = [
    '#111827' => 'שחור כהה',
    '#1e3a8a' => 'כחול כהה',
    '#064e3b' => 'ירוק כהה',
    '#7f1d1d' => 'אדום כהה',
    '#1f2937' => 'אפור כהה',
];

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // בחירת צבעים ע"י כפתורי הצבע
    if (isset($_POST['header_color']) || isset($_POST['footer_color'])) {
        $headerColor = $_POST['header_color'] ?? $design['header_bg'];
        $footerColor = $_POST['footer_color'] ?? $design['footer_bg'];

        if (!isset($colorOptions[$headerColor]) || !isset($colorOptions[$footerColor])) {
            $error = 'נבחר צבע לא תקין.';
        } else {
            $design['header_bg'] = $headerColor;
            $design['footer_bg'] = $footerColor;
            file_put_contents($designFile, json_encode($design, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $success = 'הגדרות העיצוב נשמרו בהצלחה.';
        }
    }

    // הסרת לוגו
    if (isset($_POST['remove_logo']) && (string)$_POST['remove_logo'] === '1') {
        $design['logo_path'] = '';
        file_put_contents($designFile, json_encode($design, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $success = 'הלוגו הוסר. יוצג לוגו ברירת המחדל (GF).';
    }

    // טעינת לוגו חדש
    if (isset($_POST['upload_logo']) && isset($_FILES['logo_file']) && is_array($_FILES['logo_file']) && (($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $file = $_FILES['logo_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string)($file['tmp_name'] ?? '');
            $name = (string)($file['name'] ?? '');
            if ($tmp !== '' && is_uploaded_file($tmp)) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                    $error = 'יש להעלות קובץ תמונה בפורמט רגיל (png/jpg/gif/webp).';
                } else {
                    $logoDir = __DIR__ . '/logos';
                    if (!is_dir($logoDir)) {
                        @mkdir($logoDir, 0777, true);
                    }
                    $fileName = 'logo_' . date('Ymd_His') . '.' . $ext;
                    $target = $logoDir . DIRECTORY_SEPARATOR . $fileName;
                    if (move_uploaded_file($tmp, $target)) {
                        $design['logo_path'] = 'logos/' . $fileName;
                        file_put_contents($designFile, json_encode($design, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        $success = 'לוגו עודכן בהצלחה.';
                    } else {
                        $error = 'שגיאה בהעלאת קובץ הלוגו.';
                    }
                }
            }
        } else {
            $error = 'שגיאה בהעלאת הקובץ.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>עיצוב ממשק - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        header {
            background: <?= htmlspecialchars($design['header_bg'], ENT_QUOTES, 'UTF-8') ?>;
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
        .muted {
            color: #9ca3af;
            font-size: 0.8rem;
        }
        .user-info {
            font-size: 0.9rem;
            color: #e5e7eb;
            text-align: left;
        }
        header a {
            color: #f9fafb;
            text-decoration: none;
            margin-right: 1rem;
            font-size: 0.85rem;
        }
        .nav-links {
            margin-top: 0.4rem;
        }
        .nav-links a {
            color: #e5e7eb;
            font-size: 0.82rem;
            margin-left: 0.75rem;
        }
        .nav-links a.active {
            font-weight: 600;
            text-decoration: underline;
        }
        main {
            max-width: 800px;
            margin: 1.5rem auto;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
            margin-bottom: 1.5rem;
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            color: #111827;
        }
        .muted-small {
            font-size: 0.85rem;
            color: #6b7280;
        }
        .flash {
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }
        .flash.success {
            background: #ecfdf3;
            color: #166534;
        }
        .flash.error {
            background: #fef2f2;
            color: #b91c1c;
        }
        .main-nav {
            margin-top: 0.5rem;
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
        }
        .main-nav a {
            color: #e5e7eb;
            text-decoration: none;
        }
        .main-nav-primary {
            display: flex;
            gap: 0.8rem;
        }
        .main-nav-item-wrapper {
            position: relative;
        }
        .main-nav-sub {
            position: absolute;
            right: 0;
            top: 130%;
            background: #111827;
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            box-shadow: 0 12px 30px rgba(0,0,0,0.45);
            display: none;
            min-width: 170px;
            z-index: 20;
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
        .color-section {
            margin-bottom: 1.25rem;
        }
        .color-row {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.4rem;
        }
        .color-swatch {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 2px solid transparent;
            cursor: pointer;
            padding: 0;
        }
        .color-swatch.selected {
            border-color: #facc15;
            box-shadow: 0 0 0 2px rgba(250, 204, 21, 0.6);
        }
        .color-label {
            font-size: 0.8rem;
            color: #4b5563;
            margin-top: 0.15rem;
        }
        .logo-section {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        .logo-preview {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #f9fafb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.25);
        }
        .logo-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .logo-upload-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background: #e5e7eb;
            font-size: 0.8rem;
            color: #111827;
            cursor: pointer;
        }
        .logo-remove-x {
            font-size: 0.9rem;
            color: #6b7280;
            cursor: pointer;
        }
        footer {
            background: <?= htmlspecialchars($design['footer_bg'], ENT_QUOTES, 'UTF-8') ?>;
            color: #9ca3af;
            text-align: center;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            border-top: 1px solid #1f2937;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h2>הגדרות עיצוב</h2>
        <?php if ($success !== ''): ?>
            <div class="flash success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php elseif ($error !== ''): ?>
            <div class="flash error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="color-section">
            <label>צבע Header (תפריט עליון)</label>
            <form method="post" action="admin_design.php">
                <div class="color-row">
                    <?php foreach ($colorOptions as $value => $label): ?>
                        <button type="submit"
                                name="header_color"
                                value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                                class="color-swatch<?= $design['header_bg'] === $value ? ' selected' : '' ?>"
                                style="background: <?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>;"
                                title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="color-label muted-small">לחץ על צבע כדי לעדכן את ה-Header מיד.</div>
            </form>
        </div>

        <div class="color-section">
            <label>צבע Footer (תחתית העמוד)</label>
            <form method="post" action="admin_design.php">
                <div class="color-row">
                    <?php foreach ($colorOptions as $value => $label): ?>
                        <button type="submit"
                                name="footer_color"
                                value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                                class="color-swatch<?= $design['footer_bg'] === $value ? ' selected' : '' ?>"
                                style="background: <?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>;"
                                title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="color-label muted-small">לחץ על צבע כדי לעדכן את ה-Footer מיד.</div>
            </form>
        </div>

        <div class="logo-section">
            <label>לוגו (36×36 פיקסלים)</label>
            <div style="display:flex;align-items:center;gap:0.75rem;margin-top:0.4rem;">
                <div class="logo-preview">
                    <?php if (!empty($design['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($design['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="לוגו מערכת">
                    <?php else: ?>
                        <span style="font-size:0.8rem;color:#4b5563;">GF</span>
                    <?php endif; ?>
                </div>
                <form method="post" action="admin_design.php" enctype="multipart/form-data" style="display:flex;align-items:center;gap:0.5rem;">
                    <input type="hidden" name="upload_logo" value="1">
                    <input type="file" id="logo_file" name="logo_file" accept="image/*" style="display:none;" onchange="this.form.submit()">
                    <label for="logo_file" class="btn-file">בחירת קובץ</label>
                    <?php if (!empty($design['logo_path'])): ?>
                        <button type="submit" name="remove_logo" value="1" class="logo-remove-x" title="הסר לוגו">
                            ✕
                        </button>
                    <?php endif; ?>
                </form>
            </div>
            <p class="muted-small" style="margin-top:0.4rem;">
            מומלץ להעלות תמונת לוגו בגובה 36 פיקסלים (הרוחב יתאים אוטומטית לפי הפרופורציה).
            </p>
        </div>

        <p class="muted-small" style="margin-top:1rem;">
            הערה: ניתן לבחור רק צבעים כהים מאוד, כדי לשמור על ניגודיות טובה וקריאות גבוהה.
        </p>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
</body>
</html>
