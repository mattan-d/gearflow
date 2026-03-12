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

// שמירת חתימה דיגיטלית (סטודנט או מנהל, ורק אם עדיין אין חתימה)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasSignature) {
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

    <?php
    // טעינת נוסח הסכם השאלה מתוך ניהול המסמכים
    $docDir        = __DIR__ . '/documents';
    $consentFile   = $docDir . '/consent_form.txt';
    $consentSample = "הסכם השאלת ציוד\n\nמספר הזמנה: {order_number}\nשם השואל: {borrower_name}\nציוד: {equipment_name} ({equipment_code})\nתקופת השאלה: {start_date} - {end_date}\n\nהנני מתחייב/ת לשמור על הציוד המפורט לעיל (להלן: \"הציוד\") ולהחזירו תקין ושלם במועד שסוכם, בהתאם לכללים הבאים:\n\n1. השואל אחראי באופן מלא לשלמות הציוד מרגע יציאתו מהמחסן ועד להחזרתו בפועל.\n2. אסור להעביר את הציוד לצד ג' ללא אישור מראש ממנהל המחסן/האחראי.\n3. השואל מתחייב להשתמש בציוד בהתאם להנחיות הבטיחות ולמפרט היצרן בלבד.\n4. במקרה של נזק, אובדן או גניבה, השואל ידווח מידית למנהל המחסן ויישא באחריות בהתאם לנהלי המוסד.\n5. החזרת הציוד תתבצע במועד שנקבע, במצב תקין ונקי, לרבות כלל האביזרים הנלווים.\n6. אי עמידה בתנאי ההשאלה או במועדי ההחזרה עלולה לגרור חסימה מהשאלות עתידיות ו/או חיוב כספי לפי נהלי המוסד.\n\nבחתימתי מטה אני מאשר/ת שקראתי והבנתי את תנאי ההשאלה, ואני מסכים/ה לפעול לפיהם.\n";

    $consentText = is_file($consentFile) ? (file_get_contents($consentFile) ?: $consentSample) : $consentSample;

    if ($order) {
        $replacements = [
            '{order_number}'   => (string)(int)$order['id'],
            '{borrower_name}'  => (string)$order['borrower_name'],
            '{equipment_name}' => (string)$order['equipment_name'],
            '{equipment_code}' => (string)$order['equipment_code'],
            '{start_date}'     => (string)$order['start_date'],
            '{end_date}'       => (string)$order['end_date'],
        ];
        $consentText = strtr($consentText, $replacements);
    }
    ?>

    <div class="agreement-text">
        <pre style="white-space: pre-wrap; font-family: inherit; font-size: 0.9rem; color: #111827;">
<?= htmlspecialchars($consentText, ENT_QUOTES, 'UTF-8') ?>
        </pre>
    </div>

    <div class="signature-blocks">
        <div class="signature-box">
            <div>חתימת השואל:</div>
            <?php if ($hasSignature): ?>
                <div style="margin-top: 0.75rem;">
                    <img src="signatures/order_<?= (int)$orderId ?>.png"
                         alt="חתימת השואל"
                         style="max-width: 100%; max-height: 180px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div class="signature-line">חתימה דיגיטלית נשמרה</div>
            <?php else: ?>
                <form method="post" action="agreement.php?order_id=<?= (int)$orderId ?>">
                    <canvas id="signature_pad" class="signature-pad"></canvas>
                    <input type="hidden" name="signature_data" id="signature_data">
                    <div class="signature-actions">
                        <button type="button" class="btn-small" onclick="saveSignature()">שמירת חתימה</button>
                        <button type="button" class="btn-small btn-secondary" onclick="clearSignature()">נקה</button>
                    </div>
                </form>
                <div class="signature-line">חתימת המשאיל / השואל</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-hint">
        מומלץ להדפיס את ההסכם ולצרף לעותק ההזמנה לתיעוד.
    </div>
</div>
<?php if (!$hasSignature): ?>
<script>
    (function () {
        var canvas = document.getElementById('signature_pad');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var drawing = false;
        var lastX = 0;
        var lastY = 0;

        function getPos(evt) {
            var rect = canvas.getBoundingClientRect();
            if (evt.touches && evt.touches.length > 0) {
                return {
                    x: evt.touches[0].clientX - rect.left,
                    y: evt.touches[0].clientY - rect.top
                };
            }
            return {
                x: evt.clientX - rect.left,
                y: evt.clientY - rect.top
            };
        }

        function startDraw(evt) {
            drawing = true;
            var pos = getPos(evt);
            lastX = pos.x;
            lastY = pos.y;
        }

        function moveDraw(evt) {
            if (!drawing) return;
            evt.preventDefault();
            var pos = getPos(evt);
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(pos.x, pos.y);
            ctx.strokeStyle = '#111827';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.stroke();
            lastX = pos.x;
            lastY = pos.y;
        }

        function endDraw() {
            drawing = false;
        }

        canvas.addEventListener('mousedown', startDraw);
        canvas.addEventListener('mousemove', moveDraw);
        window.addEventListener('mouseup', endDraw);

        canvas.addEventListener('touchstart', function (e) {
            startDraw(e);
        }, {passive: false});
        canvas.addEventListener('touchmove', function (e) {
            moveDraw(e);
        }, {passive: false});
        canvas.addEventListener('touchend', endDraw);

        window.clearSignature = function () {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        };

        window.saveSignature = function () {
            var input = document.getElementById('signature_data');
            if (!input) return;
            var dataUrl = canvas.toDataURL('image/png');
            input.value = dataUrl;
            input.form.submit();
        };
    })();
</script>
<?php endif; ?>
</body>
</html>

