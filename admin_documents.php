<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

// כל משתמש מחובר יכול לצפות במסמכים
$me = current_user();
if ($me === null) {
    header('Location: login.php');
    exit;
}
$role    = $me['role'] ?? 'student';
$canEdit = in_array($role, ['admin', 'warehouse_manager'], true);

$pdo = get_db();

// הגדרת מסמכים בסיסיים ושמירתם לקבצים פשוטים
$docDir = __DIR__ . '/documents';
if (!is_dir($docDir)) {
    @mkdir($docDir, 0775, true);
}

$documents = [
    'consent_form' => [
        'title'   => 'הסכם השאלה',
        'file'    => $docDir . '/consent_form.txt',
        'default' => "טופס הסכמה לשאלת ציוד\n\nאני, {borrower_name}, מאשר/ת שקראתי והבנתי את תנאי ההשאלה לגבי הציוד {equipment_name} ({equipment_code}) לתקופה מ־{start_date} עד {end_date}, ואני מתחייב/ת להחזירו תקין ובמועד.",
    ],
    'warehouse_rules' => [
        'title'   => 'נוהלי מחסן',
        'file'    => $docDir . '/warehouse_rules.txt',
        'default' => "נוהלי מחסן - טיוטה בסיסית\n\n1. הוצאת ציוד מהמחסן מתבצעת רק לאחר הזמנה מאושרת במערכת.\n2. יש להגיע בזמן שנקבע לקבלת הציוד ולהזדהות באמצעות תעודה מתאימה.\n3. האחריות לשלמות הציוד מרגע קבלתו ועד החזרתו חלה על השואל.\n4. אין להעביר ציוד בין סטודנטים ללא תיאום ואישור מנהל המחסן.\n5. החזרת ציוד תתבצע נקי, תקין ובאריזה המקורית ככל הניתן.\n6. איחור בהחזרה או נזק לציוד עלולים להביא לחיוב כספי ולהגבלת השאלות עתידיות.\n7. יש לפעול בהתאם להוראות הבטיחות והשימוש שמופיעות על הציוד או במסמכי ההדרכה.\n",
    ],
];

$error  = '';
$notice = '';

// טעינת התוכן הקיים או ברירת המחדל
foreach ($documents as $key => &$doc) {
    if (is_file($doc['file'])) {
        $doc['content'] = file_get_contents($doc['file']) ?: $doc['default'];
    } else {
        $doc['content'] = $doc['default'];
    }
}
unset($doc);

// איזה מסמך פעיל לעריכה (אם בכלל)
$currentDocKey   = $_POST['doc_key'] ?? ($_GET['doc'] ?? '');
$currentCustomId = isset($_GET['custom_id']) ? (int)$_GET['custom_id'] : 0;
$currentCustom   = null;

// סטודנט לא צריך לגשת למסמך "הסכם השאלה" מתוך מסך המסמכים
if (!$canEdit && $currentDocKey === 'consent_form') {
    $currentDocKey = '';
}

// קריאת מסמכים מותאמים אישית מה-DB
$customDocs = [];
try {
    $stmt = $pdo->query('SELECT id, title FROM documents_custom ORDER BY title ASC');
    $customDocs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $customDocs = [];
}

// טיפול בשמירה / יצירה / מחיקה – רק למנהלים
if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_builtin') {
        $docKey  = $currentDocKey;
        $content = (string)($_POST['doc_content'] ?? '');

        if (!isset($documents[$docKey])) {
            $error = 'מסמך לא נמצא.';
        } else {
            if ($docKey === 'consent_form') {
                $requiredTokens = ['{borrower_name}', '{equipment_name}', '{equipment_code}', '{start_date}', '{end_date}'];
                foreach ($requiredTokens as $token) {
                    if (strpos($content, $token) === false) {
                        $error = 'אין לשנות או להסיר את המשתנים הקבועים (למשל ' . $token . '). ניתן לערוך רק את הטקסט סביבם.';
                        break;
                    }
                }
            }

            if ($error === '') {
                file_put_contents($documents[$docKey]['file'], $content);
                $documents[$docKey]['content'] = $content;
                $notice = 'המסמך "' . $documents[$docKey]['title'] . '" נשמר בהצלחה.';
            }
        }
    } elseif ($action === 'create_custom' || $action === 'update_custom') {
        $title   = trim((string)($_POST['custom_title'] ?? ''));
        $content = (string)($_POST['custom_content'] ?? '');
        $id      = (int)($_POST['custom_id'] ?? 0);

        if ($title === '') {
            $error = 'יש להזין שם למסמך.';
        } elseif ($content === '') {
            $error = 'יש להזין תוכן למסמך.';
        } else {
            $slugSource = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            $slug = preg_replace('~[^a-zA-Z0-9_]+~', '-', strtolower($slugSource ?: 'doc'));
            if ($slug === '' || $slug === '-') {
                $slug = 'doc-' . date('Ymd-His');
            }
            try {
                if ($action === 'create_custom') {
                    $stmt = $pdo->prepare('INSERT INTO documents_custom (title, slug, content, created_at) VALUES (:title, :slug, :content, :created_at)');
                    $stmt->execute([
                        ':title'      => $title,
                        ':slug'       => $slug,
                        ':content'    => $content,
                        ':created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    $notice = 'המסמך נוצר ונשמר בהצלחה.';
                    header('Location: admin_documents.php?custom_id=' . $newId);
                    exit;
                }
                if ($action === 'update_custom' && $id > 0) {
                    $stmt = $pdo->prepare('UPDATE documents_custom SET title = :title, content = :content, updated_at = :updated_at WHERE id = :id');
                    $stmt->execute([
                        ':title'      => $title,
                        ':content'    => $content,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id'         => $id,
                    ]);
                    $notice = 'המסמך עודכן בהצלחה.';
                    header('Location: admin_documents.php?custom_id=' . $id);
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'שגיאה בשמירת המסמך.';
            }
        }
    } elseif ($action === 'delete_custom') {
        $id = (int)($_POST['custom_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM documents_custom WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $notice = 'המסמך נמחק.';
            header('Location: admin_documents.php');
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>מסמכים - מערכת השאלת ציוד</title>
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
        main {
            max-width: 1000px;
            margin: 1.5rem auto;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            color: #111827;
        }
        p {
            font-size: 0.9rem;
            color: #4b5563;
        }
        .btn {
            border-radius: 999px;
            border: none;
            background: #111827;
            color: #f9fafb;
            padding: 0.45rem 1.1rem;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }
        .doc-table-wrap {
            margin-top: 1rem;
            overflow-x: auto;
        }
        .doc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .doc-table th,
        .doc-table td {
            padding: 0.55rem 0.6rem;
            text-align: right;
            border-bottom: 1px solid #e5e7eb;
        }
        .doc-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        .doc-table tbody tr:hover td {
            background: #f9fafb;
        }
        .doc-table tbody tr.selected td {
            background: #eef2ff;
        }
        .doc-table .row-actions {
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
        }
        .doc-table .row-actions a,
        .doc-table .row-actions button {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            background: #e5e7eb;
            color: #111827;
        }
        .doc-table .row-actions a:hover,
        .doc-table .row-actions button:hover {
            background: #d1d5db;
        }
        .doc-table .row-actions a.btn-view {
            background: #4f46e5;
            color: #fff;
        }
        .doc-table .row-actions a.btn-view:hover {
            background: #4338ca;
        }
        .doc-table .badge {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-size: 0.75rem;
        }
        .doc-table .badge.builtin {
            background: #dbeafe;
            color: #1e40af;
        }
        .doc-table .badge.custom {
            background: #d1fae5;
            color: #065f46;
        }
        .doc-editor {
            margin-top: 1rem;
        }
        .doc-editor textarea {
            width: 100%;
            min-height: 260px;
            font-family: inherit;
            font-size: 0.9rem;
            padding: 0.6rem 0.7rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            box-sizing: border-box;
            resize: vertical;
            direction: rtl;
        }
        .flash {
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }
        .flash.error {
            background: #fef2f2;
            color: #b91c1c;
        }
        .flash.notice {
            background: #ecfdf3;
            color: #166534;
        }
        .doc-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
        }
        .edit-toggle-btn {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1rem;
            padding: 0.2rem;
            border-radius: 999px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .edit-toggle-btn.inactive {
            filter: grayscale(1);
        }
        .edit-toggle-btn.active {
            color: #facc15;
        }
        .doc-view {
            margin-top: 1rem;
            padding: 1rem 1.1rem;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            font-size: 0.9rem;
            white-space: pre-wrap;
        }
        .hours-table-wrapper {
            overflow-x: auto;
            margin-top: 0.5rem;
            display: flex;
            justify-content: center;
        }
        table.hours-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            max-width: 520px;
            font-size: 0.8rem;
            text-align: center;
        }
        table.hours-table th,
        table.hours-table td {
            text-align: center;
            vertical-align: middle;
            padding: 0.25rem 0.35rem;
            border: 1px solid rgba(255,255,255,0.8);
        }
        table.hours-table th {
            background: #111827;
            color: #f9fafb;
            font-weight: 700;
        }
        table.hours-table td:first-child {
            background: #e5e7eb;
            font-weight: 600;
            color: #111827;
        }
        .slot-open {
            background: #60a5fa; /* כחול בהיר */
            color: #0f172a;
        }
        .slot-closed {
            background: #e5e7eb; /* אפור בהיר */
            color: #4b5563;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <?php
        $baseParams = [];
        if ($currentDocKey !== '') {
            $baseParams['doc'] = $currentDocKey;
        }
        if ($currentCustomId > 0) {
            $baseParams['custom_id'] = $currentCustomId;
        }
        $baseQuery   = http_build_query($baseParams);
        $viewUrl     = 'admin_documents.php' . ($baseQuery ? '?' . $baseQuery : '');
        $editUrl     = 'admin_documents.php' . ($baseQuery ? '?' . $baseQuery . '&edit=1' : '?edit=1');
        $editMode    = $canEdit && isset($_GET['edit']) && $_GET['edit'] === '1';
        ?>
        <div class="doc-header-row">
            <h2>מסמכים</h2>
            <?php if ($canEdit): ?>
                <a href="<?= htmlspecialchars($editMode ? $viewUrl : $editUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="edit-toggle-btn <?= $editMode ? 'active' : 'inactive' ?>"
                   title="<?= $editMode ? 'מצב עריכה פעיל – לחץ לביטול' : 'הפעל מצב עריכה' ?>"
                   aria-label="<?= $editMode ? 'מצב עריכה פעיל' : 'הפעל מצב עריכה' ?>">
                    <i data-lucide="pencil" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php if ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($notice !== ''): ?>
            <div class="flash notice"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <h3 style="margin:0 0 0.5rem 0;font-size:1rem;color:#374151;">רשימת מסמכים</h3>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-bottom:0.75rem;">
            <?php if ($canEdit && $editMode): ?>
                <form method="get" action="admin_documents.php" style="margin:0;">
                    <button type="submit" class="btn secondary" name="new" value="1">
                        הוספת מסמך
                    </button>
                </form>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        </div>

        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>שם המסמך</th>
                    <th>סוג</th>
                    <th>פעולות</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $rowNum = 0;
                foreach ($documents as $key => $doc):
                    if (!$canEdit && $key === 'consent_form') continue;
                    $rowNum++;
                    $isSelected = ($key === $currentDocKey && $currentCustomId === 0);
                    $viewLink = 'admin_documents.php?doc=' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                    $editLink = 'admin_documents.php?doc=' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '&edit=1';
                ?>
                    <tr class="<?= $isSelected ? 'selected' : '' ?>">
                        <td><?= $rowNum ?></td>
                        <td><?= htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge builtin">מובנה</span></td>
                        <td>
                            <div class="row-actions">
                                <a href="<?= $viewLink ?>" class="btn-view">צפייה</a>
                                <?php if ($canEdit): ?>
                                    <a href="<?= $editLink ?>">עריכה</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($customDocs as $c):
                    $rowNum++;
                    $cid = (int)$c['id'];
                    $isSelected = ($currentCustomId === $cid);
                    $viewLink = 'admin_documents.php?custom_id=' . $cid;
                    $editLink = 'admin_documents.php?custom_id=' . $cid . '&edit=1';
                ?>
                    <tr class="<?= $isSelected ? 'selected' : '' ?>">
                        <td><?= $rowNum ?></td>
                        <td><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge custom">מותאם אישית</span></td>
                        <td>
                            <div class="row-actions">
                                <a href="<?= $viewLink ?>" class="btn-view">צפייה</a>
                                <?php if ($canEdit): ?>
                                    <a href="<?= $editLink ?>">עריכה</a>
                                    <form method="post" action="admin_documents.php" style="display:inline;margin:0;" onsubmit="return confirm('למחוק את המסמך?');">
                                        <input type="hidden" name="action" value="delete_custom">
                                        <input type="hidden" name="custom_id" value="<?= $cid ?>">
                                        <button type="submit" aria-label="מחיקת מסמך"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canEdit && $editMode && isset($_GET['new']) && (int)$_GET['new'] === 1): ?>
            <div class="doc-editor">
                <form method="post" action="admin_documents.php">
                    <input type="hidden" name="action" value="create_custom">
                    <label for="custom_title" style="display:block; margin-bottom:0.3rem; font-size:0.9rem; font-weight:600;">
                        שם המסמך
                    </label>
                    <input type="text" id="custom_title" name="custom_title" style="width:100%;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;margin-bottom:0.5rem;">
                    <label for="custom_content" style="display:block; margin-bottom:0.3rem; font-size:0.9rem; font-weight:600;">
                        תוכן המסמך
                    </label>
                    <textarea id="custom_content" name="custom_content"></textarea>
                    <button type="submit" class="btn" style="margin-top:0.6rem;">שמירת מסמך</button>
                </form>
            </div>
        <?php elseif ($currentCustomId > 0): ?>
            <?php
            $stmtView = $pdo->prepare('SELECT id, title, content FROM documents_custom WHERE id = :id');
            $stmtView->execute([':id' => $currentCustomId]);
            $currentCustom = $stmtView->fetch(PDO::FETCH_ASSOC) ?: null;
            ?>
            <?php if ($currentCustom): ?>
                <?php
                $isHoursDoc = trim((string)($currentCustom['title'] ?? '')) === 'שעות פתיחת מחסן';
                ?>
                <?php if ($isHoursDoc): ?>
                    <?php
                    // טבלת שעות פתיחה לפי מחסן – כמו ב-document_view
                    $days  = ['א', 'ב', 'ג', 'ד', 'ה']; // ראשון–חמישי
                    $hours = range(9, 16);             // 09:00–16:00

                    $warehousesToShow = [];
                    if ($role === 'admin') {
                        $warehousesToShow = ['מחסן א', 'מחסן ב'];
                    } else {
                        $warehousesToShow = [trim((string)($me['warehouse'] ?? 'מחסן א'))];
                    }
                    ?>
                    <div class="doc-view">
                        <?php foreach ($warehousesToShow as $warehouseName): ?>
                            <?php
                            $openMatrix = [];
                            try {
                                $stmtH = $pdo->prepare('SELECT day_of_week, hour FROM warehouse_hours WHERE warehouse = :w');
                                $stmtH->execute([':w' => $warehouseName]);
                                $rowsH = $stmtH->fetchAll(PDO::FETCH_ASSOC);
                                if ($rowsH) {
                                    foreach ($rowsH as $rowH) {
                                        $d = (int)($rowH['day_of_week'] ?? 0);
                                        $h = (int)($rowH['hour'] ?? 0);
                                        $openMatrix[$d][$h] = true;
                                    }
                                } else {
                                    foreach (array_keys($days) as $d) {
                                        foreach ($hours as $h) {
                                            $openMatrix[$d][$h] = true;
                                        }
                                    }
                                }
                            } catch (Throwable $e) {
                                $openMatrix = [];
                            }
                            ?>
                            <div style="margin-bottom:0.75rem;text-align:center;">
                                <div class="muted-small" style="margin-bottom:0.25rem;font-size:1rem;font-weight:700;">
                                    שעות פתיחת מחסן <?= htmlspecialchars($warehouseName, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if (!empty($openMatrix)): ?>
                                    <div class="hours-table-wrapper">
                                        <table class="hours-table">
                                            <thead>
                                            <tr>
                                                <th>שעה</th>
                                                <?php foreach ($days as $label): ?>
                                                    <th><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($hours as $h): ?>
                                                <tr>
                                                    <td><?= sprintf('%02d:00', $h) ?></td>
                                                    <?php foreach (array_keys($days) as $d): ?>
                                                        <?php $open = !empty($openMatrix[$d][$h]); ?>
                                                        <td class="<?= $open ? 'slot-open' : 'slot-closed' ?>">
                                                            <?= $open ? 'פתוח' : 'סגור' ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="muted-small">לא נמצאו הגדרות שעות פתיחה למחסן זה.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="hours-legend">
                            כחול = שעה פתוחה, אפור כהה = שעה סגורה.
                        </div>
                    </div>
                <?php elseif ($canEdit && $editMode): ?>
                    <div class="doc-editor">
                        <form method="post" action="admin_documents.php?custom_id=<?= (int)$currentCustom['id'] ?>">
                            <input type="hidden" name="action" value="update_custom">
                            <input type="hidden" name="custom_id" value="<?= (int)$currentCustom['id'] ?>">
                            <label for="custom_title" style="display:block; margin-bottom:0.3rem; font-size:0.9rem; font-weight:600;">
                                שם המסמך
                            </label>
                            <input type="text" id="custom_title" name="custom_title"
                                   value="<?= htmlspecialchars($currentCustom['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   style="width:100%;padding:0.4rem 0.6rem;border-radius:8px;border:1px solid #d1d5db;margin-bottom:0.5rem;">
                            <label for="custom_content" style="display:block; margin-bottom:0.3rem; font-size:0.9rem; font-weight:600;">
                                תוכן המסמך
                            </label>
                            <textarea id="custom_content" name="custom_content"><?= htmlspecialchars($currentCustom['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <button type="submit" class="btn" style="margin-top:0.6rem;">שמירת מסמך</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="doc-view">
                        <?= nl2br(htmlspecialchars($currentCustom['content'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php elseif ($currentDocKey !== '' && isset($documents[$currentDocKey])): ?>
            <?php $builtinContent = $documents[$currentDocKey]['content'] ?? ''; ?>
            <?php if ($canEdit && $editMode): ?>
                <div class="doc-editor">
                    <form method="post" action="admin_documents.php?doc=<?= htmlspecialchars($currentDocKey, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="save_builtin">
                        <input type="hidden" name="doc_key" id="doc_key" value="<?= htmlspecialchars($currentDocKey, ENT_QUOTES, 'UTF-8') ?>">
                        <label for="doc_content" style="display:block; margin-bottom:0.3rem; font-size:0.9rem; font-weight:600;">
                            תוכן המסמך: <?= htmlspecialchars($documents[$currentDocKey]['title'], ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <textarea id="doc_content" name="doc_content"><?= htmlspecialchars($builtinContent, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <?php if ($currentDocKey === 'consent_form'): ?>
                            <p class="muted" style="margin-top:0.4rem;">
                                שים לב: במסמך "הסכם השאלה" אין לשנות את שמות המשתנים במבנה `{...}` (כגון `{borrower_name}`, `{equipment_name}`, `{equipment_code}`, `{start_date}`, `{end_date}`). אפשר לערוך רק את הטקסט סביבם.
                            </p>
                        <?php endif; ?>
                        <button type="submit" class="btn" style="margin-top:0.6rem;">שמירת מסמך</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="doc-view">
                    <?= nl2br(htmlspecialchars($builtinContent, ENT_QUOTES, 'UTF-8')) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

