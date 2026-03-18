<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// דף ניהול יומי – מנהל/מנהל מחסן בלבד
$me = current_user();
if ($me === null) {
    header('Location: login.php');
    exit;
}
$role = (string)($me['role'] ?? 'student');
if (!in_array($role, ['admin', 'warehouse_manager'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = get_db();

$todayYmd = date('Y-m-d');
$day = isset($_GET['day']) ? trim((string)$_GET['day']) : '';
if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = $todayYmd;
}

$selectedCategory = isset($_GET['category']) ? trim((string)$_GET['category']) : 'all';
if ($selectedCategory === '') $selectedCategory = 'all';

// קטגוריות ציוד (כולל "ללא קטגוריה")
$categories = [];
try {
    $rows = $pdo->query("SELECT DISTINCT TRIM(COALESCE(category,'')) AS category FROM equipment ORDER BY category ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $c = trim((string)($r['category'] ?? ''));
        $categories[] = $c === '' ? 'ללא קטגוריה' : $c;
    }
    $categories = array_values(array_unique($categories));
} catch (Throwable $e) {
    $categories = [];
}

// ציוד לרשימה (לפי קטגוריה)
$equipmentRows = [];
try {
    if ($selectedCategory === 'all') {
        $stmtEq = $pdo->prepare("SELECT id, name, code, TRIM(COALESCE(category,'')) AS category FROM equipment ORDER BY category ASC, name ASC, code ASC");
        $stmtEq->execute();
    } else {
        if ($selectedCategory === 'ללא קטגוריה') {
            $stmtEq = $pdo->prepare("SELECT id, name, code, TRIM(COALESCE(category,'')) AS category FROM equipment WHERE TRIM(COALESCE(category,'')) = '' ORDER BY name ASC, code ASC");
            $stmtEq->execute();
        } else {
            $stmtEq = $pdo->prepare("SELECT id, name, code, TRIM(COALESCE(category,'')) AS category FROM equipment WHERE TRIM(COALESCE(category,'')) = :c ORDER BY name ASC, code ASC");
            $stmtEq->execute([':c' => $selectedCategory]);
        }
    }
    $equipmentRows = $stmtEq->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $equipmentRows = [];
}

// הזמנות ביום הנבחר (לפי שעות השאלה)
// הערה: הדף הזה מציג הזמנות לפי start_date (יום השאלה)
$ordersByEquipment = [];
try {
    $sql = "SELECT
                o.id,
                o.equipment_id,
                o.borrower_name,
                o.start_date,
                o.end_date,
                COALESCE(o.start_time, '09:00') AS start_time,
                COALESCE(o.end_time,   '17:00') AS end_time,
                o.status
            FROM orders o
            WHERE DATE(o.start_date) = :d";
    $params = [':d' => $day];

    if (!empty($equipmentRows)) {
        $ids = array_map(static fn($r) => (int)($r['id'] ?? 0), $equipmentRows);
        $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // עוברים לפרמטרים positional כדי לתמוך ב-IN דינמי
            $sql = str_replace(':d', '?', $sql) . " AND o.equipment_id IN ($ph)";
            $stmtO = $pdo->prepare($sql);
            $stmtO->execute(array_merge([$day], $ids));
        } else {
            $stmtO = $pdo->prepare($sql);
            $stmtO->execute($params);
        }
    } else {
        $stmtO = $pdo->prepare($sql);
        $stmtO->execute($params);
    }
    $orders = $stmtO->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($orders as $o) {
        $eid = (int)($o['equipment_id'] ?? 0);
        if ($eid <= 0) continue;
        if (!isset($ordersByEquipment[$eid])) $ordersByEquipment[$eid] = [];
        $ordersByEquipment[$eid][] = $o;
    }
} catch (Throwable $e) {
    $ordersByEquipment = [];
}

// שעות הטבלה: 09:00–17:00 במרווח שעה
$hours = [];
for ($h = 9; $h <= 17; $h++) {
    $hours[] = $h;
}

function gf_time_to_minutes(string $t): int {
    $t = trim($t);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) return 0;
    $hh = (int)$m[1];
    $mm = (int)$m[2];
    return max(0, min(23, $hh)) * 60 + max(0, min(59, $mm));
}

function gf_status_color(string $status): array {
    // צבעים ייחודיים לכל סטטוס
    switch ($status) {
        case 'pending':
            return ['bg' => '#fde68a', 'fg' => '#111827', 'label' => 'ממתין'];
        case 'approved':
            return ['bg' => '#93c5fd', 'fg' => '#0b1220', 'label' => 'מאושר'];
        case 'on_loan':
            return ['bg' => '#86efac', 'fg' => '#052e16', 'label' => 'בהשאלה'];
        case 'returned':
            return ['bg' => '#e5e7eb', 'fg' => '#111827', 'label' => 'עבר'];
        case 'rejected':
            return ['bg' => '#fecaca', 'fg' => '#7f1d1d', 'label' => 'נדחה'];
        default:
            return ['bg' => '#ddd6fe', 'fg' => '#1f2937', 'label' => $status];
    }
}

$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול יומי - מערכת השאלת ציוד</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#f3f4f6; margin:0; }
        main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem 2rem; }
        .card { background:#fff; border-radius:12px; padding:1rem 1.25rem; box-shadow:0 10px 25px rgba(15,23,42,0.08); }
        .layout { display:flex; gap:1rem; align-items:flex-start; }
        .left { flex: 1; min-width: 0; }
        .right { width: 280px; flex: 0 0 280px; }
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.75rem; }
        .btn { padding:0.45rem 0.95rem; border-radius:999px; border:none; background:#111827; color:#f9fafb; cursor:pointer; font-size:0.85rem; font-family:inherit; }
        .btn.secondary { background:#e5e7eb; color:#111827; }
        .btn:disabled { opacity:0.6; cursor:not-allowed; }
        .pill-row { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; }
        .pill { padding:0.35rem 0.75rem; border-radius:999px; background:#f3f4f6; border:1px solid #e5e7eb; cursor:pointer; font-size:0.85rem; color:#111827; text-decoration:none; }
        .pill.active { background:#111827; color:#f9fafb; border-color:#111827; }
        .legend { display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center; margin-top:0.5rem; }
        .legend-item { display:flex; align-items:center; gap:0.35rem; font-size:0.82rem; color:#374151; }
        .swatch { width:12px; height:12px; border-radius:4px; border:1px solid rgba(0,0,0,0.12); }
        .muted { color:#6b7280; font-size:0.85rem; }
        .calendar-box input[type="date"] { width:100%; padding:0.5rem 0.6rem; border-radius:10px; border:1px solid #d1d5db; font-size:0.9rem; }
        .day-nav { display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin: 0.75rem 0; }

        .grid-wrap { overflow:auto; border-radius:12px; border:1px solid #e5e7eb; }
        table.daily { border-collapse: collapse; width: 100%; min-width: 980px; background:#fff; }
        table.daily th, table.daily td { border-bottom:1px solid #eef2f7; border-left:1px solid #eef2f7; padding:0.35rem 0.45rem; text-align:center; font-size:0.82rem; }
        table.daily th:first-child, table.daily td:first-child { position: sticky; right: 0; background:#fff; z-index: 2; text-align:right; min-width: 220px; }
        table.daily thead th { position: sticky; top: 0; background:#f9fafb; z-index: 3; font-weight:700; }
        table.daily thead th:first-child { z-index: 4; }
        .eq-name { font-weight:600; color:#111827; }
        .eq-code { font-size:0.75rem; color:#6b7280; }
        .cell { height: 28px; border-radius: 8px; }
        .cell.occupied { box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06); }
        .cell .tiny { font-size:0.72rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px; display:inline-block; vertical-align:middle; }
        .order-cell-link { display:block; text-decoration:none; }
        .order-cell-link .cell { cursor: pointer; }
        .order-cell-link:hover .cell { filter: brightness(0.98); }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="layout">
        <div class="left">
            <div class="card">
                <div class="topbar">
                    <div>
                        <h2 style="margin:0 0 0.15rem;font-size:1.25rem;">ניהול יומי</h2>
                        <div class="muted">תצוגת הזמנות לפי יום ושעות השאלה (09:00–17:00)</div>
                    </div>
                    <div class="pill-row">
                        <a class="btn secondary" href="admin_daily.php?<?= htmlspecialchars(http_build_query(['day' => $prevDay, 'category' => $selectedCategory]), ENT_QUOTES, 'UTF-8') ?>">יום קודם</a>
                        <a class="btn secondary" href="admin_daily.php?<?= htmlspecialchars(http_build_query(['day' => $nextDay, 'category' => $selectedCategory]), ENT_QUOTES, 'UTF-8') ?>">יום הבא</a>
                    </div>
                </div>

                <div class="pill-row" style="margin-top:0.25rem;">
                    <a class="pill<?= $selectedCategory === 'all' ? ' active' : '' ?>"
                       href="admin_daily.php?<?= htmlspecialchars(http_build_query(['day' => $day, 'category' => 'all']), ENT_QUOTES, 'UTF-8') ?>">
                        הכל
                    </a>
                    <?php foreach ($categories as $cat): ?>
                        <a class="pill<?= $selectedCategory === $cat ? ' active' : '' ?>"
                           href="admin_daily.php?<?= htmlspecialchars(http_build_query(['day' => $day, 'category' => $cat]), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="legend">
                    <?php foreach (['pending','approved','on_loan','returned','rejected'] as $st): ?>
                        <?php $c = gf_status_color($st); ?>
                        <div class="legend-item">
                            <span class="swatch" style="background:<?= htmlspecialchars($c['bg'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                            <span><?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="day-nav">
                    <div class="muted">יום נבחר: <strong style="color:#111827;"><?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="muted"><?= count($equipmentRows) ?> פריטים</div>
                </div>

                <div class="grid-wrap">
                    <table class="daily">
                        <thead>
                        <tr>
                            <th>פריט ציוד</th>
                            <?php foreach ($hours as $h): ?>
                                <th><?= sprintf('%02d:00', $h) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($equipmentRows)): ?>
                            <tr>
                                <td colspan="<?= 1 + count($hours) ?>" class="muted" style="text-align:center;padding:1rem;">
                                    אין ציוד להצגה בקטגוריה זו.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($equipmentRows as $eq): ?>
                                <?php
                                $eid = (int)($eq['id'] ?? 0);
                                $eqName = (string)($eq['name'] ?? '');
                                $eqCode = (string)($eq['code'] ?? '');
                                $ordersForEq = $ordersByEquipment[$eid] ?? [];
                                ?>
                                <tr>
                                    <td>
                                        <div class="eq-name"><?= htmlspecialchars($eqName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="eq-code"><?= htmlspecialchars($eqCode, ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <?php foreach ($hours as $h): ?>
                                        <?php
                                        $cellStart = $h * 60;
                                        $cellEnd   = ($h + 1) * 60;

                                        $hit = null;
                                        foreach ($ordersForEq as $o) {
                                            $sMin = gf_time_to_minutes((string)($o['start_time'] ?? '09:00'));
                                            $eMin = gf_time_to_minutes((string)($o['end_time'] ?? '17:00'));
                                            if ($eMin <= $cellStart || $sMin >= $cellEnd) {
                                                continue;
                                            }
                                            $hit = $o;
                                            break;
                                        }

                                        if ($hit) {
                                            $st = (string)($hit['status'] ?? '');
                                            $c = gf_status_color($st);
                                            $borrower = trim((string)($hit['borrower_name'] ?? ''));
                                            $title = 'הזמנה #' . (int)($hit['id'] ?? 0) . ' · ' . $borrower . ' · ' . ($hit['start_time'] ?? '') . '-' . ($hit['end_time'] ?? '') . ' · ' . $c['label'];
                                            ?>
                                            <td title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
                                                <a class="order-cell-link"
                                                   href="admin_orders.php?view_id=<?= (int)($hit['id'] ?? 0) ?>"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   aria-label="צפייה בהזמנה #<?= (int)($hit['id'] ?? 0) ?>">
                                                    <div class="cell occupied" style="background:<?= htmlspecialchars($c['bg'], ENT_QUOTES, 'UTF-8') ?>; color:<?= htmlspecialchars($c['fg'], ENT_QUOTES, 'UTF-8') ?>;">
                                                        <span class="tiny">#<?= (int)($hit['id'] ?? 0) ?> <?= htmlspecialchars($borrower, ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                </a>
                                            </td>
                                        <?php } else { ?>
                                            <td><div class="cell"></div></td>
                                        <?php } ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="right">
            <div class="card calendar-box">
                <div style="font-weight:700;margin-bottom:0.5rem;">לוח שנה</div>
                <form method="get" action="admin_daily.php">
                    <input type="date" name="day" value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
                    <input type="hidden" name="category" value="<?= htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8') ?>">
                </form>
                <div class="muted" style="margin-top:0.5rem;">
                    ברירת מחדל: היום הנוכחי.
                </div>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/admin_footer.php'; ?>
</body>
</html>

