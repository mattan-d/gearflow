<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo     = get_db();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $companyName = trim((string)($_POST['company_name'] ?? ''));
        $companyCode = trim((string)($_POST['company_code'] ?? ''));
        $contactName = trim((string)($_POST['contact_name'] ?? ''));
        $phone       = trim((string)($_POST['phone'] ?? ''));
        $email       = trim((string)($_POST['email'] ?? ''));
        $address     = trim((string)($_POST['address'] ?? ''));
        $website     = trim((string)($_POST['website'] ?? ''));
        $serviceType = trim((string)($_POST['service_type'] ?? ''));

        if ($companyName === '') {
            $error = 'יש להזין שם חברה.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO suppliers
                        (company_name, company_code, contact_name, phone, email, address, website, service_type, created_at)
                    VALUES
                        (:company_name, :company_code, :contact_name, :phone, :email, :address, :website, :service_type, :created_at)
                ");
                $stmt->execute([
                    ':company_name' => $companyName,
                    ':company_code' => $companyCode,
                    ':contact_name' => $contactName,
                    ':phone'        => $phone,
                    ':email'        => $email,
                    ':address'      => $address,
                    ':website'      => $website,
                    ':service_type' => $serviceType,
                    ':created_at'   => date('Y-m-d H:i:s'),
                ]);
                $success = 'הספק נוסף בהצלחה.';
            } catch (PDOException $e) {
                $error = 'שגיאה בשמירת הספק.';
            }
        }
    }
}

// טעינת רשימת ספקים
$suppliers = [];
try {
    $stmt = $pdo->query("
        SELECT id, company_name, company_code, contact_name, phone, email, address, website, service_type
        FROM suppliers
        ORDER BY company_name ASC
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $error = $error !== '' ? $error : 'שגיאה בטעינת ספקים.';
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ספקים - מערכת השאלת ציוד</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        main {
            max-width: 1100px;
            margin: 1.5rem auto 2rem;
            padding: 0 1rem 2rem;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.4rem;
        }
        .muted-small {
            font-size: 0.8rem;
            color: #6b7280;
        }
        .toolbar {
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: flex-end;
        }
        .btn {
            border: none;
            border-radius: 999px;
            padding: 0.4rem 0.9rem;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.5rem 0.75rem;
            margin-bottom: 0.75rem;
        }
        .form-grid label {
            display: block;
            font-size: 0.78rem;
            color: #4b5563;
            margin-bottom: 0.15rem;
        }
        .form-grid input {
            width: 100%;
            padding: 0.35rem 0.5rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.85rem;
            box-sizing: border-box;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <h2 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem;">ספקים</h2>

    <?php if ($error !== ''): ?>
        <div class="card" style="margin-bottom:0.75rem;">
            <div class="muted-small" style="color:#b91c1c;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php elseif ($success !== ''): ?>
        <div class="card" style="margin-bottom:0.75rem;">
            <div class="muted-small" style="color:#166534;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <p class="muted-small" style="margin-bottom:0.75rem;">
            טבלת ספקים.
        </p>

        <form method="post" action="admin_suppliers.php" style="margin-bottom:0.75rem;">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div>
                    <label for="company_name">חברה</label>
                    <input type="text" id="company_name" name="company_name" required>
                </div>
                <div>
                    <label for="company_code">קוד חברה</label>
                    <input type="text" id="company_code" name="company_code">
                </div>
                <div>
                    <label for="contact_name">איש קשר</label>
                    <input type="text" id="contact_name" name="contact_name">
                </div>
                <div>
                    <label for="phone">טלפון</label>
                    <input type="text" id="phone" name="phone">
                </div>
                <div>
                    <label for="email">מייל</label>
                    <input type="email" id="email" name="email">
                </div>
                <div>
                    <label for="address">כתובת</label>
                    <input type="text" id="address" name="address">
                </div>
                <div>
                    <label for="website">לינק לאתר</label>
                    <input type="text" id="website" name="website" placeholder="https://">
                </div>
                <div>
                    <label for="service_type">סוג שירות</label>
                    <input type="text" id="service_type" name="service_type">
                </div>
            </div>
            <div class="toolbar">
                <button type="submit" class="btn">הוספת ספק</button>
            </div>
        </form>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.86rem;">
                <thead>
                <tr>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">חברה</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">קוד חברה</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">איש קשר</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">טלפון</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">מייל</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">כתובת</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">לינק לאתר</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">סוג שירות</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="8" class="muted-small" style="padding:0.6rem 0.5rem; text-align:center; color:#9ca3af;">
                            אין עדיין נתונים להצגה.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($s['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['company_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($s['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php $url = trim((string)($s['website'] ?? '')); ?>
                                <?php if ($url !== ''): ?>
                                    <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">קישור</a>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)($s['service_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>

