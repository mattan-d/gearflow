<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$pdo = get_db();
$error = '';
$success = '';
$editingEquipment = null;

// Handle edit mode
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM equipment WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editingEquipment = $stmt->fetch() ?: null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name         = trim($_POST['name'] ?? '');
        $code         = trim($_POST['code'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $category     = trim($_POST['category'] ?? '');
        $location     = trim($_POST['location'] ?? '');
        $status       = $_POST['status'] ?? 'active';
        $picture      = trim($_POST['picture'] ?? '');

        // quantities are now internal only – keep previous values if editing, or default to 1
        if ($id > 0 && $editingEquipment !== null) {
            $quantityTotal     = (int)($editingEquipment['quantity_total'] ?? 1);
            $quantityAvailable = (int)($editingEquipment['quantity_available'] ?? 1);
        } else {
            $quantityTotal     = 1;
            $quantityAvailable = 1;
        }

        if ($name === '' || $code === '') {
            $error = 'יש למלא שם ציוד וקוד זיהוי.';
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE equipment
                         SET name = :name,
                             code = :code,
                             description = :description,
                             category = :category,
                             location = :location,
                             quantity_total = :quantity_total,
                             quantity_available = :quantity_available,
                             status = :status,
                             picture = :picture,
                             updated_at = :updated_at
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':name'              => $name,
                        ':code'              => $code,
                        ':description'        => $description,
                        ':category'           => $category,
                        ':location'           => $location,
                        ':quantity_total'     => $quantityTotal,
                        ':quantity_available' => $quantityAvailable,
                        ':status'             => $status,
                        ':picture'            => $picture,
                        ':updated_at'         => date('Y-m-d H:i:s'),
                        ':id'                 => $id,
                    ]);
                    $success = 'הציוד עודכן בהצלחה.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO equipment
                         (name, code, description, category, location, quantity_total, quantity_available, status, picture, created_at)
                         VALUES
                         (:name, :code, :description, :category, :location, :quantity_total, :quantity_available, :status, :picture, :created_at)'
                    );
                    $stmt->execute([
                        ':name'               => $name,
                        ':code'               => $code,
                        ':description'        => $description,
                        ':category'           => $category,
                        ':location'           => $location,
                        ':quantity_total'     => $quantityTotal,
                        ':quantity_available' => $quantityAvailable,
                        ':status'             => $status,
                        ':picture'            => $picture,
                        ':created_at'         => date('Y-m-d H:i:s'),
                    ]);
                    $success = 'הציוד נוסף בהצלחה.';
                }
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE') && str_contains($e->getMessage(), 'code')) {
                    $error = 'קוד הציוד כבר קיים במערכת.';
                } else {
                    $error = 'שגיאה בשמירת הציוד.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM equipment WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $success = 'הציוד נמחק.';
        }
    } elseif ($action === 'import') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'קובץ CSV לא הועלה בהצלחה.';
        } else {
            $tmpPath = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($tmpPath, 'r');
            if ($handle === false) {
                $error = 'לא ניתן לקרוא את קובץ ה-CSV.';
            } else {
                $header = fgetcsv($handle);
                if ($header === false) {
                    $error = 'קובץ CSV ריק.';
                    fclose($handle);
                } else {
                    // מיפוי שם עמודה -> אינדקס כך שהסדר לא משנה
                    $map = [];
                    foreach ($header as $idx => $col) {
                        $key = strtolower(trim((string)$col));
                        if ($key !== '') {
                            $map[$key] = $idx;
                        }
                    }

                    $insert = $pdo->prepare(
                        'INSERT INTO equipment
                         (name, code, description, category, location, quantity_total, quantity_available, status, created_at)
                         VALUES
                         (:name, :code, :description, :category, :location, :quantity_total, :quantity_available, :status, :created_at)'
                    );

                    $imported = 0;
                    $skippedDuplicates = 0;

                    while (($row = fgetcsv($handle)) !== false) {
                        $get = function (string $key) use ($map, $row): string {
                            if (!isset($map[$key])) {
                                return '';
                            }
                            $i = $map[$key];
                            return isset($row[$i]) ? trim((string)$row[$i]) : '';
                        };

                        $name        = $get('name');
                        $code        = $get('code');
                        $description = $get('description');
                        $category    = $get('category');
                        $location    = $get('location');
                        $status      = $get('status') ?: 'active';

                        if ($name === '' || $code === '') {
                            continue;
                        }

                        try {
                            $insert->execute([
                                ':name'               => $name,
                                ':code'               => $code,
                                ':description'        => $description,
                                ':category'           => $category,
                                ':location'           => $location,
                                ':quantity_total'     => 1,
                                ':quantity_available' => 1,
                                ':status'             => $status,
                                ':created_at'         => date('Y-m-d H:i:s'),
                            ]);
                            $imported++;
                        } catch (PDOException $e) {
                            // If code already exists (UNIQUE constraint), skip this row silently
                            if (str_contains($e->getMessage(), 'UNIQUE') && str_contains($e->getMessage(), 'code')) {
                                $skippedDuplicates++;
                                continue;
                            }
                            $error = 'אירעה שגיאה בעת יבוא הקובץ.';
                            break;
                        }
                    }

                    fclose($handle);

                    if ($error === '') {
                        if ($skippedDuplicates > 0) {
                            $success = "היבוא הושלם. נוספו {$imported} פריטים, {$skippedDuplicates} קודים קיימים דולגו.";
                        } else {
                            $success = "היבוא הושלם. נוספו {$imported} פריטים.";
                        }
                    }
                }
            }
        }
    }
}

// Reload list after changes
$stmt = $pdo->query(
    'SELECT id, name, code, description, category, location, quantity_total, quantity_available, status, picture, created_at, updated_at
     FROM equipment
     ORDER BY category ASC, name ASC'
);
$equipmentList = $stmt->fetchAll();

// יצוא רשימת ציוד ל-CSV
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="equipment-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'code', 'description', 'category', 'location', 'status', 'quantity_total', 'quantity_available', 'created_at', 'updated_at']);
    foreach ($equipmentList as $row) {
        fputcsv($out, [
            $row['name'] ?? '',
            $row['code'] ?? '',
            $row['description'] ?? '',
            $row['category'] ?? '',
            $row['location'] ?? '',
            $row['status'] ?? '',
            $row['quantity_total'] ?? 0,
            $row['quantity_available'] ?? 0,
            $row['created_at'] ?? '',
            $row['updated_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$me = current_user();

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול ציוד - מערכת השאלת ציוד</title>
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
        main {
            max-width: 1150px;
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
        .category-title {
            margin: 1.2rem 0 0.6rem;
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
        }
        label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 0.45rem 0.6rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.9rem;
            box-sizing: border-box;
            margin-bottom: 0.7rem;
        }
        textarea {
            min-height: 70px;
            resize: vertical;
        }
        .btn {
            border: none;
            border-radius: 999px;
            padding: 0.45rem 1.1rem;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }
        .btn.danger {
            background: #ef4444;
        }
        .btn.small {
            padding: 0.3rem 0.7rem;
            font-size: 0.8rem;
        }
        .grid {
            display: grid;
            grid-template-columns: 2fr 1.2fr;
            gap: 1.5rem;
        }
        .flash {
            padding: 0.6rem 0.8rem;
            border-radius: 8px;
            margin-bottom: 0.9rem;
            font-size: 0.85rem;
        }
        .flash.error {
            background: #fef2f2;
            color: #b91c1c;
        }
        .flash.success {
            background: #ecfdf3;
            color: #166534;
        }
        .toolbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .toolbar-buttons {
            display: flex;
            gap: 0.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        th, td {
            padding: 0.5rem 0.45rem;
            text-align: right;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        tr:nth-child(even) td {
            background: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 0.1rem 0.55rem;
            border-radius: 999px;
            font-size: 0.75rem;
        }
        .badge.status-active {
            background: #ecfdf3;
            color: #166534;
        }
        .badge.status-out {
            background: #fef3c7;
            color: #92400e;
        }
        .badge.status-disabled {
            background: #fee2e2;
            color: #b91c1c;
        }
        .muted-small {
            font-size: 0.78rem;
            color: #6b7280;
        }
        .row-actions {
            display: flex;
            gap: 0.3rem;
        }
        .row-actions form {
            margin: 0;
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
        footer {
            background: #111827;
            color: #9ca3af;
            text-align: center;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            border-top: 1px solid #1f2937;
        }
    </style>
</head>
<body>
<header>
    <div>
        <h1>ניהול ציוד</h1>
        <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        <nav class="main-nav">
            <div class="main-nav-primary">
                <div class="main-nav-item-wrapper">
                    <a href="admin.php">ניהול מערכת</a>
                    <div class="main-nav-sub">
                        <a href="admin_users.php">ניהול משתמשים</a>
                        <a href="#">ניהול מסמכים</a>
                        <a href="admin_design.php">עיצוב ממשק</a>
                        <a href="admin_times.php">ניהול זמנים</a>
                    </div>
                </div>
                <a href="admin_orders.php">ניהול הזמנות</a>
                <a href="admin_equipment.php">ניהול ציוד</a>
            </div>
        </nav>
    </div>
    <div class="user-info">
        מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?> (אדמין)
        <a href="logout.php">התנתק</a>
    </div>
</header>
<main>
    <div class="toolbar-top">
        <div class="toolbar-buttons">
            <button type="button" class="btn" id="toggle_add_equipment_btn">הוספת פריט ציוד</button>
            <button type="button" class="btn secondary" id="toggle_import_equipment_btn">יבוא רשימת ציוד</button>
            <a href="admin_equipment.php?export=1" class="btn neutral">יצוא רשימת ציוד</a>
        </div>
    </div>

    <?php
    $showFormCard = $editingEquipment !== null || $error !== '';
    ?>

    <div class="card" id="equipment_form_card" style="display: <?= $showFormCard ? 'block' : 'none' ?>;">
        <h2><?= $editingEquipment ? 'עריכת ציוד' : 'הוספת ציוד חדש' ?></h2>

        <?php if ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($success !== ''): ?>
            <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="admin_equipment.php<?= $editingEquipment ? '?edit_id=' . (int)$editingEquipment['id'] : '' ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editingEquipment ? (int)$editingEquipment['id'] : 0 ?>">

            <div class="grid">
                <div>
                    <label for="name">שם ציוד</label>
                    <input type="text" id="name" name="name" required
                           value="<?= $editingEquipment ? htmlspecialchars($editingEquipment['name'], ENT_QUOTES, 'UTF-8') : '' ?>">

                    <label for="code">קוד זיהוי / ברקוד</label>
                    <input type="text" id="code" name="code" required
                           value="<?= $editingEquipment ? htmlspecialchars($editingEquipment['code'], ENT_QUOTES, 'UTF-8') : '' ?>">

                    <label for="description">תיאור</label>
                    <textarea id="description" name="description"><?= $editingEquipment ? htmlspecialchars($editingEquipment['description'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></textarea>

                    <label for="picture">קישור לתמונת ציוד (URL)</label>
                    <input type="text" id="picture" name="picture"
                           placeholder="https://example.com/path/to/image.jpg"
                           value="<?= $editingEquipment ? htmlspecialchars($editingEquipment['picture'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
                <div>
                    <label for="category">קטגוריה</label>
                    <input type="text" id="category" name="category"
                           placeholder="מצלמות, עדשות, תאורה, אודיו..."
                           value="<?= $editingEquipment ? htmlspecialchars($editingEquipment['category'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                    <label for="location">מיקום במחסן</label>
                    <input type="text" id="location" name="location"
                           placeholder="מדף A3, ארון מצלמות..."
                           value="<?= $editingEquipment ? htmlspecialchars($editingEquipment['location'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                    <label for="quantity_total">כמות סה״כ</label>
                    <input type="number" id="quantity_total" name="quantity_total" min="0"
                           value="<?= $editingEquipment ? (int)$editingEquipment['quantity_total'] : 0 ?>">

                    <label for="quantity_available">כמות זמינה</label>
                    <input type="number" id="quantity_available" name="quantity_available" min="0"
                           value="<?= $editingEquipment ? (int)$editingEquipment['quantity_available'] : 0 ?>">

                    <label for="status">סטטוס</label>
                    <select id="status" name="status">
                        <?php
                        $statusValue = $editingEquipment['status'] ?? 'active';
                        ?>
                        <option value="active" <?= $statusValue === 'active' ? 'selected' : '' ?>>פעיל</option>
                        <option value="out_of_service" <?= $statusValue === 'out_of_service' ? 'selected' : '' ?>>לא כשיר</option>
                        <option value="disabled" <?= $statusValue === 'disabled' ? 'selected' : '' ?>>מושבת</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn">
                <?= $editingEquipment ? 'שמירת שינויים' : 'הוספת ציוד' ?>
            </button>
            <?php if ($editingEquipment): ?>
                <a href="admin_equipment.php" class="btn secondary">ביטול עריכה</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card" id="equipment_import_card" style="display: none;">
        <h2>יבוא רשימת ציוד (CSV)</h2>
        <p class="muted-small">
            יש לבחור קובץ CSV המכיל לפחות עמודות: שם ציוד, מספר סידורי, תיאור, קטגוריה, מיקום, סטטוס.
            השורה הראשונה תיחשב ככותרת.
        </p>
        <form method="post" action="admin_equipment.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <label for="csv_file">קובץ CSV</label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
            <button type="submit" class="btn">יבוא</button>
        </form>
    </div>

    <div class="card">
        <h2>רשימת ציוד במחסן</h2>
        <?php if (count($equipmentList) === 0): ?>
            <p class="muted-small">עדיין לא הוגדר ציוד במערכת.</p>
        <?php else: ?>
            <?php
            $groupedByCategory = [];
            foreach ($equipmentList as $item) {
                $cat = trim((string)($item['category'] ?? 'אחר'));
                if ($cat === '') {
                    $cat = 'אחר';
                }
                $groupedByCategory[$cat][] = $item;
            }
            ?>
            <?php foreach ($groupedByCategory as $categoryName => $items): ?>
                <div class="category-title">
                    קטגוריה: <?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <table>
                    <thead>
                    <tr>
                        <th>שם הציוד</th>
                        <th>מספר סידורי</th>
                        <th>סטטוס</th>
                        <th>הערות</th>
                        <th>פעולות</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php
                                $statusClass = 'status-active';
                                $statusLabel = 'תקין';
                                if ($item['status'] === 'out_of_service') {
                                    $statusClass = 'status-out';
                                    $statusLabel = 'לא כשיר';
                                } elseif ($item['status'] === 'disabled') {
                                    $statusClass = 'status-disabled';
                                    $statusLabel = 'מושבת';
                                }
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                            </td>
                            <td class="muted-small">
                                <?= htmlspecialchars($item['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a href="admin_equipment.php?edit_id=<?= (int)$item['id'] ?>" class="btn small secondary">עריכה</a>
                                    <form method="post" action="admin_equipment.php" onsubmit="return confirm('למחוק את הפריט הזה?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="btn small danger">מחיקה</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var addBtn = document.getElementById('toggle_add_equipment_btn');
    var importBtn = document.getElementById('toggle_import_equipment_btn');
    var formCard = document.getElementById('equipment_form_card');
    var importCard = document.getElementById('equipment_import_card');

    if (addBtn && formCard) {
        addBtn.addEventListener('click', function () {
            var isVisible = formCard.style.display !== 'none';
            formCard.style.display = isVisible ? 'none' : 'block';
        });
    }

    if (importBtn && importCard) {
        importBtn.addEventListener('click', function () {
            var isVisible = importCard.style.display !== 'none';
            importCard.style.display = isVisible ? 'none' : 'block';
        });
    }
});
</script>
</body>
</html>

