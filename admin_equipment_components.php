<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$pdo = get_db();
$me  = current_user();

$equipmentId = isset($_GET['equipment_id']) ? (int)$_GET['equipment_id'] : 0;
if ($equipmentId <= 0) {
    header('Location: admin_equipment.php');
    exit;
}

// שליפת פריט הציוד עבור הכותרת
$stmtEq = $pdo->prepare('SELECT id, name, code FROM equipment WHERE id = :id');
$stmtEq->execute([':id' => $equipmentId]);
$equipment = $stmtEq->fetch(PDO::FETCH_ASSOC);
if (!$equipment) {
    header('Location: admin_equipment.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_component') {
        $name     = trim((string)($_POST['component_name'] ?? ''));
        $quantity = (int)($_POST['component_qty'] ?? 1);
        if ($name === '') {
            $error = 'יש להזין שם רכיב.';
        } else {
            if ($quantity <= 0) {
                $quantity = 1;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO equipment_components (equipment_id, name, quantity, created_at)
                 VALUES (:equipment_id, :name, :quantity, :created_at)'
            );
            $stmt->execute([
                ':equipment_id' => $equipmentId,
                ':name'         => $name,
                ':quantity'     => $quantity,
                ':created_at'   => date('Y-m-d H:i:s'),
            ]);
            $success = 'הרכיב נוסף בהצלחה.';
        }
    } elseif ($action === 'delete_component') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM equipment_components WHERE id = :id AND equipment_id = :equipment_id');
            $stmt->execute([':id' => $id, ':equipment_id' => $equipmentId]);
            $success = 'הרכיב נמחק.';
        }
    }
}

// שליפת רכיבים
$stmt = $pdo->prepare(
    'SELECT id, name, quantity, created_at, updated_at
     FROM equipment_components
     WHERE equipment_id = :equipment_id
     ORDER BY name ASC'
);
$stmt->execute([':equipment_id' => $equipmentId]);
$components = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>רכיבי פריט - <?= htmlspecialchars($equipment['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f3f4f6;
            margin: 0;
        }
        main {
            max-width: 900px;
            margin: 1.5rem auto 2rem;
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
            margin-bottom: 0.75rem;
            font-size: 1.2rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            margin-top: 1rem;
        }
        th, td {
            padding: 0.45rem 0.5rem;
            text-align: right;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
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
        input[type="text"],
        input[type="number"] {
            padding: 0.35rem 0.5rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.85rem;
            box-sizing: border-box;
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
        .flash.success {
            background: #ecfdf3;
            color: #166534;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h2>רכיבי פריט: <?= htmlspecialchars($equipment['name'] ?? '', ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($equipment['code'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</h2>
        <p class="muted-small">
            <a href="admin_equipment.php" class="muted-small" style="text-decoration:none;">← חזרה לרשימת הציוד</a>
        </p>

        <?php if ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($success !== ''): ?>
            <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="admin_equipment_components.php?equipment_id=<?= (int)$equipmentId ?>" style="margin-bottom: 1rem; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end;">
            <input type="hidden" name="action" value="add_component">
            <div>
                <label for="component_name" class="muted-small">שם רכיב</label><br>
                <input type="text" id="component_name" name="component_name" required>
            </div>
            <div>
                <label for="component_qty" class="muted-small">כמות</label><br>
                <input type="number" id="component_qty" name="component_qty" min="1" value="1" style="width:4rem;">
            </div>
            <div>
                <button type="submit" class="btn">הוסף רכיב</button>
            </div>
        </form>

        <?php if (count($components) === 0): ?>
            <p class="muted-small">לא הוגדרו עדיין רכיבים לפריט זה.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>שם רכיב</th>
                    <th>כמות</th>
                    <th>נוצר ב־</th>
                    <th>פעולות</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($components as $comp): ?>
                    <tr>
                        <td><?= (int)$comp['id'] ?></td>
                        <td><?= htmlspecialchars($comp['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)($comp['quantity'] ?? 1) ?></td>
                        <td class="muted-small"><?= htmlspecialchars((string)($comp['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div class="row-actions">
                                <form method="post" action="admin_equipment_components.php?equipment_id=<?= (int)$equipmentId ?>" onsubmit="return confirm('למחוק רכיב זה?');">
                                    <input type="hidden" name="action" value="delete_component">
                                    <input type="hidden" name="id" value="<?= (int)$comp['id'] ?>">
                                    <button type="submit" class="btn secondary">מחיקה</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

