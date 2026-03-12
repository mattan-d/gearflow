<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$me = current_user();

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
$currentDocKey = $_POST['doc_key'] ?? ($_GET['doc'] ?? '');

// שמירת מסמך מעורך
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $docKey  = $currentDocKey;
    $content = (string)($_POST['doc_content'] ?? '');

    if (!isset($documents[$docKey])) {
        $error = 'מסמך לא נמצא.';
    } else {
        // עבור טופס ההסכמה – מוודאים שלא שינו את שמות המשתנים
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
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול מסמכים - מערכת השאלת ציוד</title>
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
        .doc-list {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .doc-pill {
            border-radius: 999px;
            border: 1px solid #d1d5db;
            padding: 0.3rem 0.8rem;
            font-size: 0.85rem;
            cursor: pointer;
            background: #f9fafb;
        }
        .doc-pill.active {
            background: #111827;
            color: #f9fafb;
            border-color: #111827;
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
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h2>ניהול מסמכים</h2>
        <?php if ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($notice !== ''): ?>
            <div class="flash notice"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <button type="button" class="btn secondary" onclick="alert('הוספת מסמך חדש תתווסף בגרסה הבאה. כרגע ניתן לערוך רק מסמכים קיימים.')">
            הוספת מסמך
        </button>

        <div class="doc-list">
            <?php foreach ($documents as $key => $doc): ?>
                <a href="admin_documents.php?doc=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                   class="doc-pill <?= $key === $currentDocKey ? 'active' : '' ?>">
                    <?= htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($currentDocKey !== '' && isset($documents[$currentDocKey])): ?>
            <div class="doc-editor">
                <form method="post" action="admin_documents.php?doc=<?= htmlspecialchars($currentDocKey, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="doc_key" id="doc_key" value="<?= htmlspecialchars($currentDocKey, ENT_QUOTES, 'UTF-8') ?>">
                    <label for="doc_content" style="display:block; margin-bottom:0.3rem; font-size:0.9rem; font-weight:600;">
                        תוכן המסמך: <?= htmlspecialchars($documents[$currentDocKey]['title'], ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <textarea id="doc_content" name="doc_content"><?= htmlspecialchars($documents[$currentDocKey]['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <?php if ($currentDocKey === 'consent_form'): ?>
                        <p class="muted" style="margin-top:0.4rem;">
                            שים לב: במסמך "הסכם השאלה" אין לשנות את שמות המשתנים במבנה `{...}` (כגון `{borrower_name}`, `{equipment_name}`, `{equipment_code}`, `{start_date}`, `{end_date}`). אפשר לערוך רק את הטקסט סביבם.
                        </p>
                    <?php endif; ?>
                    <button type="submit" class="btn" style="margin-top:0.6rem;">שמירת מסמך</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

