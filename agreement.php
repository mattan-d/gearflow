<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();
$me  = current_user();

if ($me === null) {
    header('Location: login.php');
    exit;
}

$order = null;

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($orderId > 0) {
    $stmt = $pdo->prepare(
        'SELECT o.*,
                e.name AS equipment_name,
                e.code AS equipment_code
         FROM orders o
         JOIN equipment e ON e.id = :id'
    );
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch() ?: null;
}

if (!$order) {
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

// נתיב קובץ החתימה להזמנה
$signDir      = __DIR__ . '/signatures';
$signaturePng = $signDir . '/order_' . $orderId . '.png';
$hasSignature = file_exists($signaturePng);

// שמירת חתימה דיגיטלית (סטודנט בלבד, ורק אם עדיין אין חתימה)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasSignature && ($me['role'] ?? '') === 'student') {
    $dataUrl = (string)($_POST['signature_data'] ?? '');
    if (strpos($dataUrl, 'data:image/png;base64,') === 0) {
        $base64 = substr($dataUrl, strlen('data:image/png;base64,'));
        $binary = base64_decode($base64, true);
        if ($binary !== false) {
            if (!is_dir($signDir)) {
                @mkdir($signDir, 0775, true);
            }
            file_put_contents($signaturePng, $binary);
            $hasSignature = true;

            // לאחר חתימה – מעדכנים סטטוס להזמנה במצב "בהשאלה"
            $stmt = $pdo->prepare('UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                ':status'     => 'on_loan',
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id'         => $orderId,
            ]);

            header('Location: agreement.php?order_id=' . $orderId . '&signed=1');
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>הסכם השאלת ציוד<?= $order ? ' #' . (int)$order['id'] : '' ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f9fafb;
            margin: 0;
            padding: 1.5rem;
        }
        .sheet {
            background: #ffffff;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12);
            color: #0f172a;
        }
        h1 {
            margin-top: 0;
            margin-bottom: 0.5rem;
            font-size: 1.6rem;
            text-align: center;
        }
        h2 {
            font-size: 1rem;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .meta {
            font-size: 0.9rem;
            color: #4b5563;
            margin-bottom: 1.5rem;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }
        .agreement-text {
            font-size: 0.9rem;
            line-height: 1.6;
            color: #111827;
        }
        ol {
            padding-right: 1.2rem;
        }
        li {
            margin-bottom: 0.4rem;
        }
        .signature-blocks {
            display: flex;
            justify-content: space-between;
            gap: 2rem;
            margin-top: 2rem;
            font-size: 0.9rem;
        }
        .signature-box {
            flex: 1;
        }
        .signature-line {
            margin-top: 1.5rem;
            border-top: 1px solid #9ca3af;
            padding-top: 0.2rem;
            text-align: center;
            color: #6b7280;
            font-size: 0.85rem;
        }
        .print-hint {
            margin-top: 1.5rem;
            font-size: 0.8rem;
            color: #6b7280;
            text-align: left;
        }
        .btn-bar {
            text-align: left;
            margin-bottom: 1rem;
        }
        .btn-print {
            border-radius: 999px;
            border: none;
            background: #111827;
            color: #f9fafb;
            padding: 0.4rem 0.9rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .signature-pad {
            border: 1px solid #9ca3af;
            border-radius: 8px;
            background: #f9fafb;
            width: 100%;
            max-width: 400px;
            height: 160px;
            cursor: crosshair;
        }
        .signature-actions {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .btn-small {
            border-radius: 999px;
            border: none;
            background: #111827;
            color: #f9fafb;
            padding: 0.35rem 0.8rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #111827;
        }
    </style>
    <script>
        function printAgreement() {
            window.print();
        }
    </script>
</head>
<body>
<div class="sheet">
    <div class="btn-bar">
        <button type="button" class="btn-print" onclick="printAgreement()">הדפס</button>
    </div>

    <h1>הסכם השאלת ציוד</h1>

    <div class="meta">
        <?php if ($order): ?>
            <div class="meta-row">
                <div>מספר הזמנה: <strong><?= (int)$order['id'] ?></strong></div>
                <div>שם השואל: <strong><?= htmlspecialchars($order['borrower_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
            <div class="meta-row" style="margin-top: 0.4rem;">
                <div>ציוד: <strong><?= htmlspecialchars($order['equipment_name'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars($order['equipment_code'], ENT_QUOTES, 'UTF-8') ?>)</strong></div>
                <div>
                    תקופת השאלה:
                    <strong>
                        <?= htmlspecialchars($order['start_date'], ENT_QUOTES, 'UTF-8') ?>
                        -
                        <?= htmlspecialchars($order['end_date'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                </div>
            </div>
        <?php else: ?>
            <div>מספר הזמנה: __________</div>
            <div style="margin-top: 0.3rem;">שם השואל: ______________________________</div>
            <div style="margin-top: 0.3rem;">ציוד: _________________________________________________</div>
            <div style="margin-top: 0.3rem;">תקופת השאלה: מ־ __________ עד __________</div>
        <?php endif; ?>
    </div>

    <div class="agreement-text">
        <p>
            הנני מתחייב/ת לשמור על הציוד המפורט לעיל (להלן: "הציוד") ולהחזירו תקין ושלם במועד שסוכם,
            בהתאם לכללים הבאים:
        </p>
        <ol>
            <li>השואל אחראי באופן מלא לשלמות הציוד מרגע יציאתו מהמחסן ועד להחזרתו בפועל.</li>
            <li>אסור להעביר את הציוד לצד ג' ללא אישור מראש ממנהל המחסן/האחראי.</li>
            <li>השואל מתחייב להשתמש בציוד בהתאם להנחיות הבטיחות ולמפרט היצרן בלבד.</li>
            <li>במקרה של נזק, אובדן או גניבה, השואל ידווח מידית למנהל המחסן ויישא באחריות בהתאם לנהלי המוסד.</li>
            <li>החזרת הציוד תתבצע במועד שנקבע, במצב תקין ונקי, לרבות כלל האביזרים הנלווים.</li>
            <li>אי עמידה בתנאי ההשאלה או במועדי ההחזרה עלולה לגרור חסימה מהשאלות עתידיות
                ו/או חיוב כספי לפי נהלי המוסד.</li>
        </ol>
        <p>
            בחתימתי מטה אני מאשר/ת שקראתי והבנתי את תנאי ההשאלה, ואני מסכים/ה לפעול לפיהם.
        </p>
    </div>

    <div class="signature-blocks">
        <div class="signature-box">
            <div>חתימת השואל:</div>
            <div class="signature-line">חתימת המשאיל / השואל</div>
        </div>
        <div class="signature-box">
            <div>אישור מנהל מחסן / אחראי:</div>
            <div class="signature-line">חתימת מנהל מחסן / אחראי</div>
        </div>
    </div>

    <div class="print-hint">
        מומלץ להדפיס את ההסכם ולצרף לעותק ההזמנה לתיעוד.
    </div>
</div>
</body>
</html>

