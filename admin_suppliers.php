<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo     = get_db();
$error   = '';
$success = '';

// ספק בעריכה / צפייה (אם נבחר מהטבלה)
$editId          = isset($_GET['edit_id']) ? (int)($_GET['edit_id'] ?? 0) : 0;
$viewId          = isset($_GET['view_id']) ? (int)($_GET['view_id'] ?? 0) : 0;
$editingSupplier = null;

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
    } elseif ($action === 'update') {
        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $companyName = trim((string)($_POST['company_name'] ?? ''));
        $companyCode = trim((string)($_POST['company_code'] ?? ''));
        $contactName = trim((string)($_POST['contact_name'] ?? ''));
        $phone       = trim((string)($_POST['phone'] ?? ''));
        $email       = trim((string)($_POST['email'] ?? ''));
        $address     = trim((string)($_POST['address'] ?? ''));
        $website     = trim((string)($_POST['website'] ?? ''));
        $serviceType = trim((string)($_POST['service_type'] ?? ''));

        if ($id <= 0 || $companyName === '') {
            $error = 'יש להזין שם חברה תקין.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE suppliers
                    SET company_name = :company_name,
                        company_code = :company_code,
                        contact_name = :contact_name,
                        phone        = :phone,
                        email        = :email,
                        address      = :address,
                        website      = :website,
                        service_type = :service_type,
                        updated_at   = :updated_at
                    WHERE id = :id
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
                    ':updated_at'   => date('Y-m-d H:i:s'),
                    ':id'           => $id,
                ]);
                $success = 'הספק עודכן בהצלחה.';
                // לאחר עדכון מוצלח לא נפתח שוב את חלון העריכה
                $editId = 0;
            } catch (PDOException $e) {
                $error = 'שגיאה בעדכון הספק.';
            }
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $success = 'הספק נמחק בהצלחה.';
            } catch (PDOException $e) {
                $error = 'שגיאה במחיקת הספק.';
            }
        }
    }
}

// אם יש ספק בעריכה/צפייה (בקשת GET עם edit_id/view_id תקין) – נטען אותו למודאל
if (($editId > 0 || $viewId > 0) && $error === '') {
    $loadId = $editId > 0 ? $editId : $viewId;
    try {
        $stmt = $pdo->prepare("
            SELECT id, company_name, company_code, contact_name, phone, email, address, website, service_type
            FROM suppliers
            WHERE id = :id
        ");
        $stmt->execute([':id' => $loadId]);
        $editingSupplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $editingSupplier = null;
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
        .row-actions {
            display: flex;
            gap: 0.3rem;
            align-items: center;
        }
        .row-actions form {
            margin: 0;
        }
        .icon-btn {
            border: none;
            background: transparent;
            padding: 0.15rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .toolbar {
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: flex-start;
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
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(15,23,42,0.45);
            max-width: 700px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.25rem 1.5rem 1.25rem;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .modal-close {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
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

    <div class="toolbar">
        <button type="button" class="btn" id="open_supplier_modal_btn">הוספת ספק</button>
    </div>

    <?php
    $isViewMode = ($viewId > 0 && $editingSupplier !== null);
    $showModal  = ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') || ($editingSupplier !== null && $_SERVER['REQUEST_METHOD'] === 'GET');
    $modalAction = $editingSupplier && !$isViewMode ? 'update' : 'create';
    ?>
    <div class="modal-backdrop" id="supplier_modal" style="display: <?= $showModal ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2 style="margin:0; font-size:1.2rem;"><?= $isViewMode ? 'צפייה בספק' : ($editingSupplier ? 'עריכת ספק' : 'הוספת ספק') ?></h2>
                <button type="button" class="modal-close" id="supplier_modal_close" aria-label="סגירת חלון">×</button>
            </div>
            <form method="post" action="admin_suppliers.php" id="supplier_form">
                <input type="hidden" name="action" id="supplier_action" value="<?= htmlspecialchars($modalAction, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="supplier_id" value="<?= (int)($editingSupplier['id'] ?? 0) ?>">
                <div class="form-grid">
                    <div>
                        <label for="company_name">חברה</label>
                        <input type="text" id="company_name" name="company_name" required <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="company_code">קוד חברה</label>
                        <input type="text" id="company_code" name="company_code" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['company_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="contact_name">איש קשר</label>
                        <input type="text" id="contact_name" name="contact_name" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="phone">טלפון</label>
                        <input type="text" id="phone" name="phone" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="email">מייל</label>
                        <input type="email" id="email" name="email" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="address">כתובת</label>
                        <input type="text" id="address" name="address" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="website">לינק לאתר</label>
                        <input type="text" id="website" name="website" placeholder="https://" <?= $isViewMode ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars((string)($editingSupplier['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="service_type">סוג שירות</label>
                        <select id="service_type" name="service_type" <?= $isViewMode ? 'disabled' : '' ?>>
                            <?php $currentService = (string)($editingSupplier['service_type'] ?? ''); ?>
                            <option value="">בחר...</option>
                            <option value="ציוד"     <?= $currentService === 'ציוד' ? 'selected' : '' ?>>ציוד</option>
                            <option value="אחריות"  <?= $currentService === 'אחריות' ? 'selected' : '' ?>>אחריות</option>
                            <option value="מעבדה"   <?= $currentService === 'מעבדה' ? 'selected' : '' ?>>מעבדה</option>
                        </select>
                    </div>
                </div>
                <div class="toolbar" style="margin-top:0.5rem;">
                    <button type="button" class="btn secondary" id="supplier_modal_cancel"><?= $isViewMode ? 'סגירה' : 'ביטול' ?></button>
                    <?php if (!$isViewMode): ?>
                        <button type="submit" class="btn">שמירה</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <p class="muted-small" style="margin-bottom:0.75rem;">
            טבלת ספקים.
        </p>

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
                    <th style="text-align:right; padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">פעולות</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="9" class="muted-small" style="padding:0.6rem 0.5rem; text-align:center; color:#9ca3af;">
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
                            <td>
                                <div class="row-actions">
                                    <a href="admin_suppliers.php?view_id=<?= (int)($s['id'] ?? 0) ?>" class="icon-btn" title="צפייה בספק" aria-label="צפייה בספק">
                                        <i data-lucide="eye" aria-hidden="true"></i>
                                    </a>
                                    <a href="admin_suppliers.php?edit_id=<?= (int)($s['id'] ?? 0) ?>" class="icon-btn" title="עריכת ספק" aria-label="עריכת ספק"><i data-lucide="pencil" aria-hidden="true"></i></a>
                                    <form method="post" action="admin_suppliers.php" onsubmit="return confirm('למחוק את הספק הזה?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)($s['id'] ?? 0) ?>">
                                        <button type="submit" class="icon-btn" title="מחיקת ספק" aria-label="מחיקת ספק"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
<script>
    (function () {
        var openBtn = document.getElementById('open_supplier_modal_btn');
        var modal = document.getElementById('supplier_modal');
        var closeBtn = document.getElementById('supplier_modal_close');
        var cancelBtn = document.getElementById('supplier_modal_cancel');
        var form = document.getElementById('supplier_form');
        var actionInput = document.getElementById('supplier_action');
        var idInput = document.getElementById('supplier_id');

        function openModal() {
            if (modal) {
                modal.style.display = 'flex';
            }
        }
        function closeModal() {
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function openForCreate() {
            if (!form) {
                openModal();
                return;
            }
            if (actionInput) actionInput.value = 'create';
            if (idInput) idInput.value = '0';

            var inputs = form.querySelectorAll('input[type="text"], input[type="email"]');
            inputs.forEach(function (inp) {
                if (inp.id === 'supplier_id' || inp.id === 'supplier_action') return;
                inp.value = '';
            });
            var select = document.getElementById('service_type');
            if (select) select.value = '';

            openModal();
        }

        if (openBtn) {
            openBtn.addEventListener('click', openForCreate);
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
        }
    })();
</script>
</html>

