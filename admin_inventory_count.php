<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

$me = current_user();
$role = (string)($me['role'] ?? 'student');
if ($me === null || !in_array($role, ['admin', 'warehouse_manager'], true)) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'create_new_count') {
        try {
            $now = date('Y-m-d H:i:s');
            $countDate = date('Y-m-d');
            $stmtCreate = $pdo->prepare(
                'INSERT INTO inventory_counts (count_date, created_at, created_by_user_id, created_by_username)
                 VALUES (:count_date, :created_at, :created_by_user_id, :created_by_username)'
            );
            $stmtCreate->execute([
                ':count_date' => $countDate,
                ':created_at' => $now,
                ':created_by_user_id' => (int)($me['id'] ?? 0),
                ':created_by_username' => (string)($me['username'] ?? ''),
            ]);
            $newId = (int)$pdo->lastInsertId();
            header('Location: admin_inventory_count.php?count_id=' . $newId . '&success=' . urlencode('נוצרה ספירת מלאי חדשה.'));
            exit;
        } catch (Throwable $e) {
            $error = 'לא ניתן ליצור ספירת מלאי חדשה.';
        }
    } elseif ($action === 'save_count') {
        $countId = (int)($_POST['count_id'] ?? 0);
        $statusByEquipment = $_POST['item_status'] ?? [];
        $qtyByEquipment = $_POST['counted_quantity'] ?? [];
        $notesByEquipment = $_POST['notes'] ?? [];
        if ($countId <= 0) {
            $error = 'לא נבחרה ספירת מלאי לשמירה.';
        } else {
            try {
                $allowedStatuses = ['תקין', 'תקול', 'חסר', 'יצא מהמלאי'];
                $stmtUpsert = $pdo->prepare(
                    'INSERT INTO inventory_count_items (count_id, equipment_id, item_status, counted_quantity, notes, updated_at)
                     VALUES (:count_id, :equipment_id, :item_status, :counted_quantity, :notes, :updated_at)
                     ON CONFLICT(count_id, equipment_id) DO UPDATE SET
                       item_status = excluded.item_status,
                       counted_quantity = excluded.counted_quantity,
                       notes = excluded.notes,
                       updated_at = excluded.updated_at'
                );
                $updatedAt = date('Y-m-d H:i:s');
                foreach ($statusByEquipment as $equipmentIdRaw => $statusRaw) {
                    $equipmentId = (int)$equipmentIdRaw;
                    if ($equipmentId <= 0) {
                        continue;
                    }
                    $status = trim((string)$statusRaw);
                    if (!in_array($status, $allowedStatuses, true)) {
                        $status = 'תקין';
                    }
                    $qtyRaw = $qtyByEquipment[$equipmentIdRaw] ?? 1;
                    $qty = (int)$qtyRaw;
                    if ($qty < 0) {
                        $qty = 0;
                    }
                    $notes = trim((string)($notesByEquipment[$equipmentIdRaw] ?? ''));
                    $stmtUpsert->execute([
                        ':count_id' => $countId,
                        ':equipment_id' => $equipmentId,
                        ':item_status' => $status,
                        ':counted_quantity' => $qty,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':updated_at' => $updatedAt,
                    ]);
                }
                header('Location: admin_inventory_count.php?count_id=' . $countId . '&success=' . urlencode('ספירת המלאי נשמרה בהצלחה.'));
                exit;
            } catch (Throwable $e) {
                $error = 'שמירת ספירת המלאי נכשלה.';
            }
        }
    }
}

if (isset($_GET['success']) && trim((string)$_GET['success']) !== '') {
    $success = (string)$_GET['success'];
}

$counts = [];
try {
    $stmtCounts = $pdo->query(
        'SELECT id, count_date, created_at, created_by_username
         FROM inventory_counts
         ORDER BY count_date DESC, id DESC'
    );
    $counts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $counts = [];
}

$countId = (int)($_GET['count_id'] ?? 0);
if ($countId <= 0 && !empty($counts)) {
    $countId = (int)($counts[0]['id'] ?? 0);
}

$activeCount = null;
if ($countId > 0) {
    $stmtActive = $pdo->prepare(
        'SELECT id, count_date, created_at, created_by_username
         FROM inventory_counts
         WHERE id = :id
         LIMIT 1'
    );
    $stmtActive->execute([':id' => $countId]);
    $activeCount = $stmtActive->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($activeCount === null) {
        $countId = 0;
    }
}

$categoryFilter = trim((string)($_GET['category'] ?? ''));
$queryFilter = trim((string)($_GET['q'] ?? ''));

$categories = [];
try {
    $stmtCats = $pdo->query("SELECT DISTINCT TRIM(COALESCE(category, '')) AS category FROM equipment ORDER BY category ASC");
    foreach ($stmtCats->fetchAll(PDO::FETCH_ASSOC) as $catRow) {
        $catVal = trim((string)($catRow['category'] ?? ''));
        if ($catVal !== '') {
            $categories[] = $catVal;
        }
    }
} catch (Throwable $e) {
    $categories = [];
}

$equipmentRows = [];
if ($countId > 0) {
    // דואג שלכל פריט ציוד תהיה שורה בספירה
    $pdo->exec(
        "INSERT OR IGNORE INTO inventory_count_items (count_id, equipment_id, item_status, counted_quantity, notes, updated_at)
         SELECT {$countId}, e.id, 'תקין', 1, NULL, NULL
         FROM equipment e"
    );

    $sql = "
        SELECT
            e.id,
            e.name,
            e.code,
            e.category,
            i.item_status,
            i.counted_quantity,
            i.notes
        FROM equipment e
        LEFT JOIN inventory_count_items i
          ON i.equipment_id = e.id
         AND i.count_id = :count_id
    ";
    $params = [':count_id' => $countId];
    $where = [];
    if ($categoryFilter !== '') {
        $where[] = 'TRIM(COALESCE(e.category, \'\')) = :category';
        $params[':category'] = $categoryFilter;
    }
    if ($queryFilter !== '') {
        $where[] = '(e.name LIKE :q OR e.code LIKE :q)';
        $params[':q'] = '%' . $queryFilter . '%';
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY e.category ASC, e.name ASC, e.code ASC';
    $stmtEq = $pdo->prepare($sql);
    $stmtEq->execute($params);
    $equipmentRows = $stmtEq->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$groupedByCategory = [];
foreach ($equipmentRows as $row) {
    $cat = trim((string)($row['category'] ?? ''));
    if ($cat === '') {
        $cat = 'ללא קטגוריה';
    }
    if (!isset($groupedByCategory[$cat])) {
        $groupedByCategory[$cat] = [];
    }
    $groupedByCategory[$cat][] = $row;
}

$isPrintMode = isset($_GET['print']) && (string)$_GET['print'] === '1';
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ספירת מלאי - מערכת השאלת ציוד</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#f3f4f6; margin:0; }
        .container { max-width: 1280px; margin: 1.2rem auto; padding: 0 1rem; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem; box-shadow:0 10px 24px rgba(15,23,42,0.06); }
        .toolbar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; justify-content:space-between; margin-bottom:1rem; }
        .btn { border:none; background:#111827; color:#fff; border-radius:999px; padding:.45rem .95rem; cursor:pointer; font-size:.85rem; text-decoration:none; display:inline-block; }
        .btn.secondary { background:#e5e7eb; color:#111827; }
        .flash { padding:.55rem .75rem; border-radius:8px; margin:.5rem 0; font-size:.85rem; }
        .flash.success { background:#ecfdf3; color:#166534; }
        .flash.error { background:#fef2f2; color:#b91c1c; }
        .counts-panel { display:none; margin-top:.5rem; border:1px solid #e5e7eb; border-radius:10px; background:#f9fafb; padding:.5rem; max-height:230px; overflow:auto; }
        .counts-panel.visible { display:block; }
        .counts-panel a { display:block; padding:.35rem .45rem; color:#111827; text-decoration:none; border-radius:7px; }
        .counts-panel a:hover { background:#eef2ff; }
        .count-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:.8rem; }
        .filters { display:flex; flex-wrap:wrap; gap:.55rem; align-items:flex-end; margin-bottom:1rem; }
        .filters label { display:flex; flex-direction:column; gap:.2rem; font-size:.8rem; color:#374151; }
        .filters input, .filters select { border:1px solid #d1d5db; border-radius:8px; padding:.35rem .5rem; min-width:180px; }
        .category-block { border:1px solid #e5e7eb; border-radius:10px; margin-bottom:.9rem; overflow:hidden; }
        .category-title { background:#f9fafb; padding:.5rem .7rem; font-weight:700; border-bottom:1px solid #e5e7eb; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:.45rem .5rem; border-bottom:1px solid #f3f4f6; text-align:right; font-size:.84rem; vertical-align:top; }
        th { background:#fff; color:#374151; font-size:.78rem; }
        td input[type="number"], td input[type="text"], td select { width:100%; border:1px solid #d1d5db; border-radius:7px; padding:.3rem .4rem; box-sizing:border-box; font-size:.82rem; }
        .muted { color:#6b7280; font-size:.8rem; }
        .save-wrap { margin-top:.8rem; display:flex; justify-content:flex-end; }
        @media print {
            header, .toolbar, .filters, .save-wrap, .counts-panel, .screen-only { display:none !important; }
            body { background:#fff; }
            .container { max-width:none; margin:0; padding:0; }
            .card { border:none; box-shadow:none; padding:0; }
        }
    </style>
</head>
<body>
<?php if (!$isPrintMode): ?>
    <?php include __DIR__ . '/admin_header.php'; ?>
<?php endif; ?>
<div class="container">
    <div class="card">
        <?php if ($error !== ''): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="toolbar screen-only">
            <div style="display:flex; gap:.5rem; align-items:center;">
                <form method="post" action="admin_inventory_count.php" style="margin:0;">
                    <input type="hidden" name="action" value="create_new_count">
                    <button type="submit" class="btn">יצירת ספירת מלאי חדשה</button>
                </form>
                <button type="button" class="btn secondary" id="toggle_counts_btn">בחירת ספירת מלאי קיימת</button>
            </div>
            <?php if ($countId > 0): ?>
                <a class="btn secondary" target="_blank" href="admin_inventory_count.php?count_id=<?= $countId ?>&print=1">הדפסת הספירה</a>
            <?php endif; ?>
        </div>

        <div class="counts-panel screen-only" id="counts_panel">
            <?php if (empty($counts)): ?>
                <div class="muted">עדיין לא נוצרו ספירות מלאי.</div>
            <?php else: ?>
                <?php foreach ($counts as $c): ?>
                    <a href="admin_inventory_count.php?count_id=<?= (int)$c['id'] ?>">
                        <?= htmlspecialchars((string)$c['count_date'], ENT_QUOTES, 'UTF-8') ?> ·
                        <?= htmlspecialchars((string)($c['created_by_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($countId <= 0): ?>
            <p class="muted" style="margin:.6rem 0 0;">יש ליצור או לבחור ספירת מלאי כדי להתחיל.</p>
        <?php else: ?>
            <div class="count-header">
                <div>
                    <h2 style="margin:0;">ספירת מלאי</h2>
                    <div class="muted">
                        תאריך ספירה:
                        <strong><?= htmlspecialchars((string)($activeCount['count_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>
                <div class="muted">מזהה ספירה: #<?= $countId ?></div>
            </div>

            <form method="get" action="admin_inventory_count.php" class="filters screen-only">
                <input type="hidden" name="count_id" value="<?= $countId ?>">
                <label>
                    קטגוריה
                    <select name="category">
                        <option value="">כל הקטגוריות</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    מילת חיפוש
                    <input type="text" name="q" value="<?= htmlspecialchars($queryFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="שם ציוד או מספר סידורי">
                </label>
                <button type="submit" class="btn secondary">סינון</button>
                <a href="admin_inventory_count.php?count_id=<?= $countId ?>" class="btn secondary">ניקוי</a>
            </form>

            <form method="post" action="admin_inventory_count.php">
                <input type="hidden" name="action" value="save_count">
                <input type="hidden" name="count_id" value="<?= $countId ?>">
                <?php if (empty($groupedByCategory)): ?>
                    <div class="muted">לא נמצאו פריטים לפי הסינון שנבחר.</div>
                <?php else: ?>
                    <?php foreach ($groupedByCategory as $catName => $rows): ?>
                        <div class="category-block">
                            <div class="category-title"><?= htmlspecialchars((string)$catName, ENT_QUOTES, 'UTF-8') ?></div>
                            <table>
                                <thead>
                                <tr>
                                    <th style="width:22%;">שם ציוד</th>
                                    <th style="width:13%;">מספר סידורי</th>
                                    <th style="width:18%;">סטטוס</th>
                                    <th style="width:12%;">מספר פריטים</th>
                                    <th>הערות</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $eqId = (int)($row['id'] ?? 0);
                                    $status = trim((string)($row['item_status'] ?? 'תקין'));
                                    if (!in_array($status, ['תקין', 'תקול', 'חסר', 'יצא מהמלאי'], true)) {
                                        $status = 'תקין';
                                    }
                                    $qty = (int)($row['counted_quantity'] ?? 1);
                                    if ($qty < 0) {
                                        $qty = 0;
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string)($row['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <select name="item_status[<?= $eqId ?>]">
                                                <option value="תקין" <?= $status === 'תקין' ? 'selected' : '' ?>>תקין</option>
                                                <option value="תקול" <?= $status === 'תקול' ? 'selected' : '' ?>>תקול</option>
                                                <option value="חסר" <?= $status === 'חסר' ? 'selected' : '' ?>>חסר</option>
                                                <option value="יצא מהמלאי" <?= $status === 'יצא מהמלאי' ? 'selected' : '' ?>>יצא מהמלאי</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" min="0" step="1" name="counted_quantity[<?= $eqId ?>]" value="<?= $qty ?>">
                                        </td>
                                        <td>
                                            <input type="text" name="notes[<?= $eqId ?>]" value="<?= htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="הערה לפריט">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                    <div class="save-wrap screen-only">
                        <button type="submit" class="btn">שמירת ספירת מלאי</button>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('toggle_counts_btn');
    var panel = document.getElementById('counts_panel');
    if (btn && panel) {
        btn.addEventListener('click', function () {
            panel.classList.toggle('visible');
        });
    }
    var isPrint = <?= $isPrintMode ? 'true' : 'false' ?>;
    if (isPrint) {
        window.setTimeout(function () { window.print(); }, 150);
    }
});
</script>
</body>
</html>

