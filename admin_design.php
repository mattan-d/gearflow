<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$me = current_user();

// קובץ הגדרות עיצוב פשוט (JSON)
$designFile = __DIR__ . '/design_settings.json';
$defaultDesign = [
    'header_bg' => '#111827',
    'footer_bg' => '#111827',
    'header_text' => '#f9fafb',
    'header_link' => '#e5e7eb',
    'header_muted' => '#9ca3af',
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

// תבניות צבע ברמת מערכת – רקע + צבע טקסט/קישורים מותאם לניגודיות
$colorTemplates = [
    'default'        => ['color' => '#111827', 'text' => '#f9fafb', 'link' => '#e5e7eb', 'muted' => '#9ca3af', 'name' => 'ברירת מחדל (כהה)'],
    'pastel_blue'    => ['color' => '#5B8FB9', 'text' => '#f9fafb', 'link' => '#e5e7eb', 'muted' => '#bfdbfe', 'name' => 'פסטל כחול'],
    'pastel_green'   => ['color' => '#5A9B8E', 'text' => '#f9fafb', 'link' => '#e5e7eb', 'muted' => '#a7f3d0', 'name' => 'פסטל ירוק־מנטה'],
    'pastel_pink'    => ['color' => '#C97B84', 'text' => '#1f2937', 'link' => '#374151', 'muted' => '#4b5563', 'name' => 'פסטל ורוד'],
    'pastel_lavender'=> ['color' => '#8B7B9E', 'text' => '#f9fafb', 'link' => '#e5e7eb', 'muted' => '#c4b5d4', 'name' => 'פסטל סגול'],
    'pastel_peach'   => ['color' => '#C4956A', 'text' => '#1f2937', 'link' => '#374151', 'muted' => '#4b5563', 'name' => 'פסטל אפרסק'],
    'pastel_sage'    => ['color' => '#6B9B7A', 'text' => '#f9fafb', 'link' => '#e5e7eb', 'muted' => '#a7f3d0', 'name' => 'פסטל ירוק־עשב'],
];

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // בחירת תבנית צבעים
    if (isset($_POST['template']) && is_string($_POST['template'])) {
        $templateId = $_POST['template'];
        if (isset($colorTemplates[$templateId])) {
            $tpl = $colorTemplates[$templateId];
            $design['header_bg'] = $tpl['color'];
            $design['footer_bg'] = $tpl['color'];
            $design['header_text'] = $tpl['text'];
            $design['footer_text'] = $tpl['text'];
            $design['header_link'] = $tpl['link'];
            $design['header_muted'] = $tpl['muted'];
            $design['template'] = $templateId;
            file_put_contents($designFile, json_encode($design, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $success = 'תבנית הצבעים נשמרה בהצלחה.';
        } else {
            $error = 'תבנית לא תקינה.';
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
        .btn {
            padding: 0.5rem 1.1rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: inherit;
            border: none;
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
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.75rem;
            margin-top: 0.5rem;
        }
        .template-swatch {
            border: 2px solid transparent;
            border-radius: 12px;
            padding: 0.75rem 0.5rem;
            cursor: pointer;
            min-height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .template-swatch:hover {
            border-color: rgba(255,255,255,0.6);
        }
        .template-swatch.selected {
            border-color: #facc15;
            box-shadow: 0 0 0 2px rgba(250, 204, 21, 0.6);
        }
        .template-name {
            text-align: center;
            line-height: 1.2;
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
            color: <?= htmlspecialchars($design['footer_text'] ?? $design['header_text'] ?? '#9ca3af', ENT_QUOTES, 'UTF-8') ?>;
            text-align: center;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            border-top: 1px solid rgba(0,0,0,0.15);
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
            <label>תבנית צבעים (מערכת)</label>
            <p class="muted-small" style="margin:0.25rem 0 0.5rem;">בחירת תבנית אחת מחילה צבע אחיד על כל המערכת (תפריט עליון ותחתית).</p>
            <form method="post" action="admin_design.php">
                <div class="template-grid">
                    <?php
                    $currentTemplate = $design['template'] ?? null;
                    $currentColor = $design['header_bg'] ?? $defaultDesign['header_bg'];
                    foreach ($colorTemplates as $id => $tpl):
                        $isSelected = ($currentTemplate === $id) || ($currentTemplate === null && $currentColor === $tpl['color']);
                    ?>
                        <button type="submit"
                                name="template"
                                value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                                class="template-swatch<?= $isSelected ? ' selected' : '' ?>"
                                style="background: <?= htmlspecialchars($tpl['color'], ENT_QUOTES, 'UTF-8') ?>;"
                                title="<?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="template-name"><?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="color-label muted-small">לחץ על תבנית כדי להחיל אותה על כל המערכת.</div>
            </form>
        </div>

        <div class="logo-section">
            <label>לוגו (36×36 פיקסלים)</label>
            <div style="display:flex;align-items:flex-start;gap:0.75rem;margin-top:0.4rem;flex-wrap:wrap;">
                <div class="logo-preview">
                    <?php if (!empty($design['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($design['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="לוגו מערכת">
                    <?php else: ?>
                        <span style="font-size:0.8rem;color:#4b5563;">GF</span>
                    <?php endif; ?>
                </div>
                <form method="post" action="admin_design.php" enctype="multipart/form-data" style="flex:1;min-width:200px;">
                    <input type="hidden" name="upload_logo" value="1">
                    <label class="file-drop-zone" for="logo_file" aria-label="העלאת תמונת לוגו">
                        <input type="file" id="logo_file" name="logo_file" accept="image/*" class="file-drop-input" onchange="this.form.submit()">
                        <span class="file-drop-icon"><i data-lucide="upload" aria-hidden="true"></i></span>
                        <span class="file-drop-text">גרור קובץ לכאן או לחץ לבחירה</span>
                        <span class="file-drop-hint">PNG, JPG, GIF או WebP (מומלץ 36×36 פיקסלים)</span>
                    </label>
                    <?php if (!empty($design['logo_path'])): ?>
                        <div style="margin-top:0.5rem;">
                            <button type="submit" name="remove_logo" value="1" class="logo-remove-x btn secondary" title="הסר לוגו" aria-label="הסר לוגו">
                                <i data-lucide="x" aria-hidden="true"></i> הסר לוגו
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
            <p class="muted-small" style="margin-top:0.4rem;">
            מומלץ להעלות תמונת לוגו בגובה 36 פיקסלים (הרוחב יתאים אוטומטית לפי הפרופורציה).
            </p>
        </div>

        <p class="muted-small" style="margin-top:1rem;">
            התבניות כוללות צבעי פסטל וברירת מחדל כהה. הצבע שנבחר מוחל על כל המערכת.
        </p>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
</body>
</html>
