<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_or_warehouse();

$pdo   = get_db();
$error = '';
$success = '';

/** @var list<array{id:int,name:string,code:string,category:string}> */
$cameras = [];
/** @var list<array{id:int,name:string,code:string,category:string}> */
$accessories = [];

try {
    $stmt = $pdo->query('SELECT id, name, code, TRIM(COALESCE(category, \'\')) AS category FROM equipment WHERE status = \'active\' ORDER BY name ASC');
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($all as $row) {
        $cat = trim((string)($row['category'] ?? ''));
        $main = gf_parse_stored_equipment_category($cat)['main'];
        if ($main === 'מצלמות') {
            $cameras[] = $row;
        } elseif (gf_is_accessories_equipment_category($cat)) {
            $accessories[] = $row;
        }
    }
} catch (Throwable $e) {
    $error = 'שגיאה בטעינת ציוד.';
}

/** @var list<array{id:int,name:string,code:string,category:string}> */
$accessoriesForGrid = $accessories;

$selectedCameraId = isset($_GET['camera_id']) ? (int)$_GET['camera_id'] : 0;
if ($selectedCameraId < 1 && !empty($cameras)) {
    $selectedCameraId = (int)($cameras[0]['id'] ?? 0);
}

$selectedKitIds = [];
/** @var array<int, true> פריט נלווה שכבר משויך לערכה של מצלמה אחרת (לא להציג בטופס של המצלמה הנוכחית) */
$accessoryReservedElsewhere = [];
if ($selectedCameraId > 0) {
    $selectedKitIds = gf_equipment_kit_item_ids($pdo, $selectedCameraId);
    try {
        $stReserve = $pdo->prepare(
            'SELECT eki.equipment_id
             FROM equipment_kit_items eki
             INNER JOIN equipment_kits ek ON ek.id = eki.kit_id
             WHERE ek.camera_equipment_id != :cid'
        );
        $stReserve->execute([':cid' => $selectedCameraId]);
        foreach ($stReserve->fetchAll(PDO::FETCH_COLUMN, 0) as $rid) {
            $accessoryReservedElsewhere[(int)$rid] = true;
        }
    } catch (Throwable $e) {
        $accessoryReservedElsewhere = [];
    }
    $accessoriesForGrid = [];
    foreach ($accessories as $a) {
        $aid = (int)($a['id'] ?? 0);
        if ($aid > 0 && isset($accessoryReservedElsewhere[$aid]) && !in_array($aid, $selectedKitIds, true)) {
            continue;
        }
        $accessoriesForGrid[] = $a;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_kit') {
    $camId = (int)($_POST['camera_equipment_id'] ?? 0);
    $raw   = $_POST['kit_item'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $ids = [];
    foreach ($raw as $r) {
        $eid = (int)$r;
        if ($eid > 0 && !in_array($eid, $ids, true)) {
            $ids[] = $eid;
        }
    }
    if ($camId < 1) {
        $error = 'יש לבחור מצלמה.';
    } else {
        $stmt = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $camId]);
        $cat = trim((string)$stmt->fetchColumn());
        if (gf_parse_stored_equipment_category($cat)['main'] !== 'מצלמות') {
            $error = 'הפריט שנבחר אינו מצלמה.';
        } else {
            foreach ($ids as $kid) {
                $stmt2 = $pdo->prepare('SELECT TRIM(COALESCE(category, \'\')) FROM equipment WHERE id = :id LIMIT 1');
                $stmt2->execute([':id' => $kid]);
                $c2 = trim((string)$stmt2->fetchColumn());
                if (!gf_is_accessories_equipment_category($c2)) {
                    $error = 'בערכה ניתן לכלול רק ציוד נלווה.';
                    break;
                }
                $stmtO = $pdo->prepare(
                    'SELECT ek.camera_equipment_id
                     FROM equipment_kit_items eki
                     INNER JOIN equipment_kits ek ON ek.id = eki.kit_id
                     WHERE eki.equipment_id = :eid AND ek.camera_equipment_id != :cam
                     LIMIT 1'
                );
                $stmtO->execute([':eid' => $kid, ':cam' => $camId]);
                $otherCam = (int)$stmtO->fetchColumn();
                if ($otherCam > 0) {
                    $error = 'פריט נלווה כבר משויך לערכה של מצלמה אחרת. יש להסיר אותו מהערכה הקודמת לפני השיוך כאן.';
                    break;
                }
            }
        }
    }
    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $now = date('Y-m-d H:i:s');
            $stmtFind = $pdo->prepare('SELECT id FROM equipment_kits WHERE camera_equipment_id = :cid LIMIT 1');
            $stmtFind->execute([':cid' => $camId]);
            $kitId = (int)($stmtFind->fetchColumn() ?: 0);
            if ($kitId < 1) {
                $pdo->prepare('INSERT INTO equipment_kits (camera_equipment_id, updated_at) VALUES (:cid, :u)')->execute([':cid' => $camId, ':u' => $now]);
                $kitId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE equipment_kits SET updated_at = :u WHERE id = :id')->execute([':u' => $now, ':id' => $kitId]);
            }
            if ($kitId < 1) {
                throw new RuntimeException('kit');
            }
            $pdo->prepare('DELETE FROM equipment_kit_items WHERE kit_id = :kid')->execute([':kid' => $kitId]);
            $ins = $pdo->prepare('INSERT INTO equipment_kit_items (kit_id, equipment_id) VALUES (:kid, :eid)');
            foreach ($ids as $eid) {
                $ins->execute([':kid' => $kitId, ':eid' => $eid]);
            }
            $pdo->commit();
            $success = 'הערכה נשמרה.';
            $selectedCameraId = $camId;
            $selectedKitIds     = $ids;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'שמירה נכשלה.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול ערכות ציוד</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f3f4f6; margin: 0; }
        main { max-width: 900px; margin: 1.5rem auto; padding: 0 1rem 2rem; }
        .card { background: #fff; border-radius: 12px; padding: 1.25rem; box-shadow: 0 8px 24px rgba(15,23,42,.08); }
        h1 { margin: 0 0 1rem; font-size: 1.25rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.35rem; font-size: 0.9rem; }
        select { width: 100%; max-width: 24rem; padding: 0.45rem; border-radius: 8px; border: 1px solid #d1d5db; margin-bottom: 1rem; }
        .kit-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.5rem; }
        .kit-item { display: flex; align-items: flex-start; gap: 0.4rem; padding: 0.35rem 0.5rem; border: 1px solid #e5e7eb; border-radius: 8px; background: #fafafa; font-size: 0.88rem; }
        .kit-item input { margin-top: 0.2rem; }
        .btn { border: none; border-radius: 999px; padding: 0.5rem 1.2rem; background: #4f46e5; color: #fff; font-weight: 600; cursor: pointer; margin-top: 1rem; }
        .flash { padding: 0.6rem 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .flash.ok { background: #ecfdf3; color: #166534; }
        .flash.err { background: #fef2f2; color: #b91c1c; }
        .muted { color: #6b7280; font-size: 0.85rem; margin-bottom: 0.75rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <h1>ניהול ערכות</h1>
        <p class="muted">לכל מצלמה ניתן להגדיר ערכה — חבילת ציוד נלווה (חצובה, מיקרופון וכו׳). בהזמנה ניתן לסמן «להזמין עם הערכה» כדי לכלול את כל הפריטים האלה. כל פריט נלווה יכול להשתייך לערכה של מצלמה אחת בלבד — פריטים שכבר משויכים למצלמה אחרת לא יופיעו ברשימה.</p>
        <?php if ($error !== ''): ?>
            <div class="flash err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash ok"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="get" action="admin_equipment_kits.php" style="margin-bottom:1rem;">
            <label for="camera_id">בחירת מצלמה</label>
            <select id="camera_id" name="camera_id" onchange="this.form.submit()">
                <?php foreach ($cameras as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $selectedCameraId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ((string)($c['code'] ?? '') !== ''): ?>
                            (<?= htmlspecialchars((string)$c['code'], ENT_QUOTES, 'UTF-8') ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (empty($cameras)): ?>
            <p class="muted">אין מצלמות פעילות במערכת.</p>
        <?php else: ?>
            <form method="post" action="admin_equipment_kits.php?camera_id=<?= (int)$selectedCameraId ?>">
                <input type="hidden" name="action" value="save_kit">
                <input type="hidden" name="camera_equipment_id" value="<?= (int)$selectedCameraId ?>">
                <label>פריטים בערכה (ציוד נלווה)</label>
                <?php if (empty($accessories)): ?>
                    <p class="muted">אין פריטי ציוד נלווה מוגדרים. הוסיפו קטגוריית נלווה ב«ניהול ציוד».</p>
                <?php elseif (empty($accessoriesForGrid)): ?>
                    <p class="muted">כל פריטי הנלווה כבר משויכים לערכות של מצלמות אחרות. הסירו פריט מערכה אחרת כדי לשייך אותו למצלמה זו.</p>
                <?php else: ?>
                    <div class="kit-grid">
                        <?php foreach ($accessoriesForGrid as $a): ?>
                            <?php $aid = (int)($a['id'] ?? 0); ?>
                            <label class="kit-item">
                                <input type="checkbox" name="kit_item[]" value="<?= $aid ?>"
                                    <?= in_array($aid, $selectedKitIds, true) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars((string)$a['name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ((string)($a['code'] ?? '') !== ''): ?>
                                        <span class="muted">(<?= htmlspecialchars((string)$a['code'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn">שמירת ערכה</button>
            </form>
        <?php endif; ?>
    </div>
</main>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
</body>
</html>
