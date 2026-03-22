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

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
if ($action === 'order_json') {
    header('Content-Type: application/json; charset=UTF-8');
    $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT
                o.id,
                o.status,
                o.borrower_name,
                o.borrower_contact,
                o.start_date,
                o.end_date,
                o.start_time,
                o.end_time,
                o.notes,
                e.name AS equipment_name,
                e.code AS equipment_code
             FROM orders o
             JOIN equipment e ON e.id = o.equipment_id
             WHERE o.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'order' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$todayYmd = date('Y-m-d');
$day = isset($_GET['day']) ? trim((string)$_GET['day']) : '';
if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = $todayYmd;
}

$selectedCategory = isset($_GET['category']) ? trim((string)$_GET['category']) : 'all';
if ($selectedCategory === '') $selectedCategory = 'all';
$searchQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

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
        $sqlEq = "SELECT id, name, code, TRIM(COALESCE(category,'')) AS category
                  FROM equipment
                  WHERE 1=1";
        $paramsEq = [];
        if ($searchQ !== '') {
            $sqlEq .= " AND TRIM(COALESCE(name,'')) LIKE :q";
            $paramsEq[':q'] = '%' . $searchQ . '%';
        }
        $sqlEq .= " ORDER BY category ASC, name ASC, code ASC";
        $stmtEq = $pdo->prepare($sqlEq);
        $stmtEq->execute($paramsEq);
    } else {
        if ($selectedCategory === 'ללא קטגוריה') {
            $sqlEq = "SELECT id, name, code, TRIM(COALESCE(category,'')) AS category
                      FROM equipment
                      WHERE TRIM(COALESCE(category,'')) = ''";
            $paramsEq = [];
            if ($searchQ !== '') {
                $sqlEq .= " AND TRIM(COALESCE(name,'')) LIKE :q";
                $paramsEq[':q'] = '%' . $searchQ . '%';
            }
            $sqlEq .= " ORDER BY name ASC, code ASC";
            $stmtEq = $pdo->prepare($sqlEq);
            $stmtEq->execute($paramsEq);
        } else {
            $sqlEq = "SELECT id, name, code, TRIM(COALESCE(category,'')) AS category
                      FROM equipment
                      WHERE TRIM(COALESCE(category,'')) = :c";
            $paramsEq = [':c' => $selectedCategory];
            if ($searchQ !== '') {
                $sqlEq .= " AND TRIM(COALESCE(name,'')) LIKE :q";
                $paramsEq[':q'] = '%' . $searchQ . '%';
            }
            $sqlEq .= " ORDER BY name ASC, code ASC";
            $stmtEq = $pdo->prepare($sqlEq);
            $stmtEq->execute($paramsEq);
        }
    }
    $equipmentRows = $stmtEq->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $equipmentRows = [];
}

// הזמנות החופפות ליום הנבחר (כולל הזמנות רב-יומיות)
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
            WHERE o.start_date <= ? AND o.end_date >= ?";
    $params = [$day, $day];

    if (!empty($equipmentRows)) {
        $ids = array_map(static fn($r) => (int)($r['id'] ?? 0), $equipmentRows);
        $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND o.equipment_id IN ($ph)";
            $params = array_merge($params, $ids);
        }
    }
    $stmtO = $pdo->prepare($sql);
    $stmtO->execute($params);
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

// לוח זמנים: 07:00–22:00 במרווחי חצי שעה
$GRID_START_MIN = 7 * 60;
$GRID_END_MIN   = 22 * 60;
$SLOT_MINUTES   = 30;
$SLOT_COUNT     = (int)(($GRID_END_MIN - $GRID_START_MIN) / $SLOT_MINUTES);

/**
 * מקטע תצוגה של הזמנה ביום הנבחר בתוך הגריד (כולל חצים למשך מחוץ ליום).
 *
 * @return array{startIdx:int,endIdxExcl:int,hasBefore:bool,hasAfter:bool,order:array}|null
 */
function gf_daily_order_segment(array $o, string $day, int $gridStartMin, int $gridEndMin, int $slotMinutes): ?array
{
    $start_date = (string)($o['start_date'] ?? '');
    $end_date   = (string)($o['end_date'] ?? '');
    $start_time = trim((string)($o['start_time'] ?? '09:00'));
    $end_time   = trim((string)($o['end_time'] ?? '17:00'));
    if ($start_time === '') {
        $start_time = '09:00';
    }
    if ($end_time === '') {
        $end_time = '17:00';
    }

    $os = strtotime($start_date . ' ' . $start_time);
    $oe = strtotime($end_date . ' ' . $end_time);
    if ($os === false || $oe === false || $oe <= $os) {
        return null;
    }

    $dayVisStart = strtotime($day . ' 07:00:00');
    $dayVisEnd   = strtotime($day . ' 22:00:00');
    if ($dayVisStart === false || $dayVisEnd === false) {
        return null;
    }

    $visStart = max($os, $dayVisStart);
    $visEnd   = min($oe, $dayVisEnd);
    if ($visEnd <= $visStart) {
        return null;
    }

    $day0 = strtotime($day . ' 00:00:00');
    if ($day0 === false) {
        return null;
    }

    $sMin = (int)floor(($visStart - $day0) / 60);
    $eMin = (int)ceil(($visEnd - $day0) / 60);
    $sMin = max($gridStartMin, min($gridEndMin, $sMin));
    $eMin = max($gridStartMin, min($gridEndMin, $eMin));
    if ($eMin <= $sMin) {
        return null;
    }

    $hasBefore = $os < $dayVisStart;
    $hasAfter  = $oe > $dayVisEnd;

    $startIdx     = (int)floor(($sMin - $gridStartMin) / $slotMinutes);
    $endIdxExcl   = (int)ceil(($eMin - $gridStartMin) / $slotMinutes);
    $slotCount    = (int)(($gridEndMin - $gridStartMin) / $slotMinutes);
    $startIdx     = max(0, min($slotCount - 1, $startIdx));
    $endIdxExcl   = max($startIdx + 1, min($slotCount, $endIdxExcl));

    return [
        'startIdx'   => $startIdx,
        'endIdxExcl' => $endIdxExcl,
        'hasBefore'  => $hasBefore,
        'hasAfter'   => $hasAfter,
        'order'      => $o,
    ];
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

$dailyNavQuery = ['category' => $selectedCategory, 'q' => $searchQ];
$hrefDailyPrev = 'admin_daily.php?' . htmlspecialchars(http_build_query(array_merge(['day' => $prevDay], $dailyNavQuery)), ENT_QUOTES, 'UTF-8');
$hrefDailyNext = 'admin_daily.php?' . htmlspecialchars(http_build_query(array_merge(['day' => $nextDay], $dailyNavQuery)), ENT_QUOTES, 'UTF-8');

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
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.75rem; }
        .legend { display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center; margin-top:0.5rem; }
        .legend-item { display:flex; align-items:center; gap:0.35rem; font-size:0.82rem; color:#374151; }
        .swatch { width:12px; height:12px; border-radius:4px; border:1px solid rgba(0,0,0,0.12); }
        .muted { color:#6b7280; font-size:0.85rem; }
        .filters-row { display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; margin: 0.65rem 0 0.85rem; }
        .filter-block + .filter-block { margin-right: 0.5rem; }
        .filter-block { min-width: 160px; }
        .filter-block label { display:block; font-size:0.8rem; color:#374151; margin-bottom:0.25rem; }
        .filter-block input[type="date"],
        .filter-block select,
        .filter-block input[type="text"] {
            width:100%;
            padding:0.25rem 0.6rem;
            border-radius:999px;
            border:1px solid #e5e7eb;
            background:#f9fafb;
            font-size:0.85rem;
            font-family:inherit;
        }
        .icon-btn {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #111827;
            cursor: pointer;
            text-decoration:none;
        }
        .icon-btn:hover { background:#f3f4f6; }
        .nav-arrow {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: #111827;
            cursor: pointer;
            text-decoration: none;
            border-radius: 8px;
        }
        .nav-arrow:hover { background:#f3f4f6; }
        .day-nav { display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin: 0.5rem 0 0.75rem; }

        .grid-wrap { overflow-y:auto; overflow-x:hidden; border-radius:12px; border:1px solid #e5e7eb; }
        table.daily { border-collapse: collapse; width: 100%; min-width: 0; background:#fff; table-layout: fixed; }
        table.daily th, table.daily td { border-bottom:1px solid #eef2f7; border-left:1px solid #eef2f7; padding:0.18rem 0.22rem; text-align:center; font-size:0.74rem; }
        table.daily th:first-child, table.daily td:first-child { position: sticky; right: 0; background:#fff; z-index: 2; text-align:right; width: 190px; }
        table.daily th:not(:first-child), table.daily td:not(:first-child) { width: 28px; max-width: 32px; }
        table.daily th.slot-sub { font-weight: 600; color: #6b7280; font-size: 0.62rem; padding: 0.08rem 0.1rem; }
        table.daily thead th { position: sticky; top: 0; background:#f9fafb; z-index: 3; font-weight:700; }
        table.daily thead th:first-child { z-index: 4; }
        .eq-name { font-weight:600; color:#111827; }
        .eq-code { font-size:0.75rem; color:#6b7280; }
        .cell { height: 22px; border-radius: 6px; display:flex; align-items:center; justify-content:center; }
        .cell-inner { display:flex; align-items:center; justify-content:space-between; width:100%; min-height:100%; gap:1px; direction: rtl; }
        .daily-edge-arrow { flex:0 0 11px; display:flex; align-items:center; justify-content:center; opacity:0.9; color: inherit; }
        .daily-edge-arrow i { width:11px; height:11px; }
        a.daily-edge-arrow-link {
            display: flex;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            border-radius: 4px;
            align-items: center;
            justify-content: center;
        }
        a.daily-edge-arrow-link:hover { background: rgba(0,0,0,0.1); }
        a.daily-edge-arrow-link:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; }
        .daily-edge-spacer { flex:0 0 11px; width:11px; }
        .cell.occupied { box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06); padding: 0 1px; }
        .cell .tiny { font-size:0.62rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; min-width:0; text-align:center; }
        .order-cell-link { display:block; text-decoration:none; flex:1; min-width:0; color:inherit; cursor:pointer; }
        .order-cell-link:hover .tiny { filter: brightness(0.95); }
        td.js-daily-slot { cursor: pointer; }
        td.js-daily-slot:hover { background: #f9fafb; }
        td.js-daily-slot.selected { background: #eef2ff; }
        td.js-daily-slot.selected .cell { box-shadow: inset 0 0 0 1px rgba(79,70,229,0.35); }
        .order-details {
            margin-top: 0.9rem;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            background: #f9fafb;
            display: none;
        }
        .order-details .row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
        }
        .order-details h3 { margin:0; font-size:1rem; color:#111827; }
        .order-details .kv { display:flex; gap:0.5rem; flex-wrap:wrap; font-size:0.85rem; color:#374151; }
        .order-details .kv b { color:#111827; }
        .order-details .actions { display:flex; gap:0.4rem; align-items:center; }
        .order-details .close-x { border:none; background:transparent; cursor:pointer; padding:0.25rem; border-radius:8px; }
        .order-details .close-x:hover { background:#eef2f7; }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 80;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(15,23,42,0.45);
            width: 95%;
            max-width: 1100px;
            max-height: 90vh;
            overflow: hidden;
            padding: 1rem 1rem 0.75rem;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .modal-title {
            margin: 0;
            font-size: 1.05rem;
            color: #111827;
        }
        .modal-close {
            border: none;
            background: transparent;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 10px;
        }
        .modal-close:hover { background:#f3f4f6; }
        .modal-iframe {
            width: 100%;
            height: 78vh;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <div class="topbar">
            <div>
                <h2 style="margin:0 0 0.15rem;font-size:1.25rem;">ניהול יומי</h2>
                <div class="muted">תצוגת הזמנות לפי יום ושעות השאלה (07:00–22:00, חצי שעה)</div>
            </div>
        </div>

        <form method="get" action="admin_daily.php" class="filters-row" id="daily_filters_form">
            <div class="filter-block" style="min-width:170px;">
                <label for="day_input">בחירת תאריך</label>
                <input id="day_input" type="date" name="day" value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
            </div>
            <div class="filter-block" style="min-width:190px;">
                <label for="cat_input">בחירת קטגוריה</label>
                <select id="cat_input" name="category" onchange="this.form.submit()">
                    <option value="all" <?= ($selectedCategory === 'all' || $selectedCategory === '') ? 'selected' : '' ?>>הכל</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedCategory === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-block" style="max-width:360px; min-width:220px;">
                <label for="q_input">חיפוש פריט ציוד לפי שם</label>
                <input id="q_input" type="text" name="q" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="הקלד שם פריט...">
            </div>
            <div style="display:flex; gap:0.4rem; align-items:flex-end;">
                <a class="nav-arrow"
                   href="admin_daily.php?<?= htmlspecialchars(http_build_query(['day' => $prevDay, 'category' => $selectedCategory, 'q' => $searchQ]), ENT_QUOTES, 'UTF-8') ?>"
                   title="יום קודם"
                   aria-label="יום קודם">
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                </a>
                <a class="nav-arrow"
                   href="admin_daily.php?<?= htmlspecialchars(http_build_query(['day' => $nextDay, 'category' => $selectedCategory, 'q' => $searchQ]), ENT_QUOTES, 'UTF-8') ?>"
                   title="יום הבא"
                   aria-label="יום הבא">
                    <i data-lucide="chevron-left" aria-hidden="true"></i>
                </a>
            </div>
        </form>

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
                            <th rowspan="2">פריט ציוד</th>
                            <?php for ($h = 7; $h <= 21; $h++): ?>
                                <th colspan="2"><?= sprintf('%02d:00', $h) ?></th>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($s = 0; $s < $SLOT_COUNT; $s++): ?>
                                <th class="slot-sub"><?= ($s % 2 === 0) ? '00' : '30' ?></th>
                            <?php endfor; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($equipmentRows)): ?>
                            <tr>
                                <td colspan="<?= 1 + $SLOT_COUNT ?>" class="muted" style="text-align:center;padding:1rem;">
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
                                $segments = [];
                                foreach ($ordersForEq as $o) {
                                    $seg = gf_daily_order_segment($o, $day, $GRID_START_MIN, $GRID_END_MIN, $SLOT_MINUTES);
                                    if ($seg !== null) {
                                        $segments[] = $seg;
                                    }
                                }
                                $win = array_fill(0, $SLOT_COUNT, null);
                                foreach ($segments as $seg) {
                                    $oid = (int)($seg['order']['id'] ?? 0);
                                    for ($sj = $seg['startIdx']; $sj < $seg['endIdxExcl']; $sj++) {
                                        $cur = $win[$sj];
                                        if ($cur === null) {
                                            $win[$sj] = $seg;
                                            continue;
                                        }
                                        $curId = (int)($cur['order']['id'] ?? 0);
                                        if ($oid < $curId) {
                                            $win[$sj] = $seg;
                                        }
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="eq-name"><?= htmlspecialchars($eqName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="eq-code"><?= htmlspecialchars($eqCode, ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <?php
                                    $i = 0;
                                    while ($i < $SLOT_COUNT) {
                                        $w = $win[$i];
                                        if ($w === null) {
                                            $slotStartMin = $GRID_START_MIN + $i * $SLOT_MINUTES;
                                            echo '<td class="js-daily-slot" data-slot-min="' . (int)$slotStartMin . '" data-equipment-id="' . (int)$eid . '"><div class="cell"></div></td>';
                                            $i++;
                                            continue;
                                        }
                                        $oid = (int)($w['order']['id']);
                                        $blockStart = $i;
                                        while ($i < $SLOT_COUNT && $win[$i] !== null && (int)($win[$i]['order']['id']) === $oid) {
                                            $i++;
                                        }
                                        $span = $i - $blockStart;
                                        $hit = $w['order'];
                                        $hasBefore = $w['hasBefore'];
                                        $hasAfter = $w['hasAfter'];
                                        $st = (string)($hit['status'] ?? '');
                                        $c = gf_status_color($st);
                                        $borrower = trim((string)($hit['borrower_name'] ?? ''));
                                        $title = 'הזמנה #' . (int)($hit['id'] ?? 0) . ' · ' . $borrower . ' · ' . ($hit['start_date'] ?? '') . ' ' . ($hit['start_time'] ?? '') . ' – ' . ($hit['end_date'] ?? '') . ' ' . ($hit['end_time'] ?? '') . ' · ' . $c['label'];
                                        ?>
                                        <td colspan="<?= (int)$span ?>" title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
                                                <div class="cell occupied" style="background:<?= htmlspecialchars($c['bg'], ENT_QUOTES, 'UTF-8') ?>; color:<?= htmlspecialchars($c['fg'], ENT_QUOTES, 'UTF-8') ?>;">
                                                    <div class="cell-inner">
                                                        <?php if ($hasBefore): ?>
                                                            <a class="daily-edge-arrow daily-edge-arrow-link"
                                                               href="<?= $hrefDailyPrev ?>"
                                                               title="עבור ליום הקודם"
                                                               aria-label="עבור ליום הקודם — המשך ההזמנה מהיום שעבר">
                                                                <i data-lucide="chevron-right" aria-hidden="true"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="daily-edge-spacer" aria-hidden="true"></span>
                                                        <?php endif; ?>
                                                        <a class="order-cell-link js-order-open"
                                                           href="#"
                                                           data-order-id="<?= (int)($hit['id'] ?? 0) ?>"
                                                           aria-label="צפייה בהזמנה #<?= (int)($hit['id'] ?? 0) ?>">
                                                            <span class="tiny">#<?= (int)($hit['id'] ?? 0) ?> <?= htmlspecialchars($borrower, ENT_QUOTES, 'UTF-8') ?></span>
                                                        </a>
                                                        <?php if ($hasAfter): ?>
                                                            <a class="daily-edge-arrow daily-edge-arrow-link"
                                                               href="<?= $hrefDailyNext ?>"
                                                               title="עבור ליום הבא"
                                                               aria-label="עבור ליום הבא — המשך ההזמנה">
                                                                <i data-lucide="chevron-left" aria-hidden="true"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="daily-edge-spacer" aria-hidden="true"></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                        </td>
                                        <?php
                                    }
                                    ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="order-details" id="daily_order_details" aria-live="polite">
                    <div class="row" style="margin-bottom:0.5rem;">
                        <h3 id="daily_order_title">פרטי הזמנה</h3>
                        <div class="actions">
                            <a class="icon-btn" id="daily_order_open_full" href="#" target="_blank" rel="noopener noreferrer" title="פתיחה במנהל הזמנות" aria-label="פתיחה במנהל הזמנות">
                                <i data-lucide="external-link" aria-hidden="true"></i>
                            </a>
                            <button type="button" class="close-x" id="daily_order_close" title="סגירה" aria-label="סגירה">
                                <i data-lucide="x" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="kv" id="daily_order_kv"></div>
                </div>

                <div class="modal-backdrop" id="daily_order_modal" aria-hidden="true">
                    <div class="modal-card" role="dialog" aria-modal="true" aria-label="עריכת הזמנה">
                        <div class="modal-header">
                            <h3 class="modal-title" id="daily_order_modal_title">עריכת הזמנה</h3>
                            <button type="button" class="modal-close" id="daily_order_modal_close" title="סגירה" aria-label="סגירה">
                                <i data-lucide="x" aria-hidden="true"></i>
                            </button>
                        </div>
                        <iframe class="modal-iframe" id="daily_order_modal_iframe" src="about:blank"></iframe>
                    </div>
                </div>
    </div>
</main>
<?php include __DIR__ . '/admin_footer.php'; ?>
<script>
    (function () {
        var form = document.getElementById('daily_filters_form');
        var q = document.getElementById('q_input');
        if (!form || !q) return;
        var t = null;
        q.addEventListener('input', function () {
            if (t) window.clearTimeout(t);
            t = window.setTimeout(function () {
                form.submit();
            }, 500);
        });
    })();
</script>
<script>
    (function () {
        var panel = document.getElementById('daily_order_details');
        var kv = document.getElementById('daily_order_kv');
        var title = document.getElementById('daily_order_title');
        var closeBtn = document.getElementById('daily_order_close');
        var openFull = document.getElementById('daily_order_open_full');
        var modal = document.getElementById('daily_order_modal');
        var modalClose = document.getElementById('daily_order_modal_close');
        var modalFrame = document.getElementById('daily_order_modal_iframe');
        var modalTitle = document.getElementById('daily_order_modal_title');
        if (!panel || !kv || !title || !closeBtn || !openFull || !modal || !modalClose || !modalFrame || !modalTitle) return;

        function hide() {
            panel.style.display = 'none';
        }
        closeBtn.addEventListener('click', hide);

        function fmtHm(totalMin) {
            var m = Math.max(0, Math.min(24 * 60 - 1, parseInt(totalMin, 10) || 0));
            var h = Math.floor(m / 60);
            var mm = m % 60;
            return String(h).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
        }

        function openModal(orderId) {
            modalTitle.textContent = 'עריכת הזמנה #' + orderId;
            modalFrame.src = 'admin_orders.php?edit_id=' + encodeURIComponent(orderId);
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            if (window.lucide) lucide.createIcons();
        }

        function openNewOrderModal(day, startMin, endMin, equipmentId) {
            var hh1 = fmtHm(startMin);
            var hh2 = fmtHm(endMin);
            modalTitle.textContent = 'הזמנה חדשה · ' + day + ' · ' + hh1 + '–' + hh2;
            modalFrame.src = 'admin_orders.php?mode=new'
                + '&prefill_day=' + encodeURIComponent(day)
                + '&prefill_start_time=' + encodeURIComponent(hh1)
                + '&prefill_end_time=' + encodeURIComponent(hh2)
                + '&prefill_equipment_id=' + encodeURIComponent(String(equipmentId || ''));
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            if (window.lucide) lucide.createIcons();
        }

        function openNewOrderModalStartOnly(day, startMin, equipmentId) {
            var hh1 = fmtHm(startMin);
            modalTitle.textContent = 'הזמנה חדשה · ' + day + ' · ' + hh1;
            modalFrame.src = 'admin_orders.php?mode=new'
                + '&prefill_day=' + encodeURIComponent(day)
                + '&prefill_start_time=' + encodeURIComponent(hh1)
                + '&prefill_no_end=1'
                + '&prefill_equipment_id=' + encodeURIComponent(String(equipmentId || ''));
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            if (window.lucide) lucide.createIcons();
        }
        function closeModal() {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            // מנקה את ה-iframe כדי לעצור תהליכים פנימיים
            modalFrame.src = 'about:blank';
        }
        modalClose.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });

        document.querySelectorAll('.js-order-open').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var id = this.getAttribute('data-order-id') || '';
                if (!id) return;
                // הצגה בחלון קופץ במצב עריכה
                openModal(id);
            });
            a.addEventListener('dblclick', function (e) {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        var GRID_MIN = 420;
        var GRID_MAX = 1320;
        var SLOT_LEN = 30;

        // בחירת משבצות: קליק ראשון מסמן, קליק שני פותח טווח; דאבל־קליק פותח הזמנה עם שעת התחלה בלבד (prefill_no_end)
        var sel = { equipmentId: null, startMin: null };
        var clickTimer = null;

        function clearSelection() {
            sel.equipmentId = null;
            sel.startMin = null;
            document.querySelectorAll('td.js-daily-slot.selected').forEach(function (x) {
                x.classList.remove('selected');
            });
        }

        function handleSlotClick(td) {
            var slotMin = parseInt(td.getAttribute('data-slot-min') || '-1', 10);
            var eqId = parseInt(td.getAttribute('data-equipment-id') || '0', 10);
            if (slotMin < GRID_MIN || slotMin >= GRID_MAX || !eqId) return;

            if (sel.equipmentId && sel.equipmentId !== eqId) {
                clearSelection();
            }

            if (sel.startMin === null) {
                sel.equipmentId = eqId;
                sel.startMin = slotMin;
                td.classList.add('selected');
                return;
            }

            if (sel.equipmentId !== eqId) {
                clearSelection();
                sel.equipmentId = eqId;
                sel.startMin = slotMin;
                td.classList.add('selected');
                return;
            }

            var start = Math.min(sel.startMin, slotMin);
            var end = Math.max(sel.startMin, slotMin) + SLOT_LEN;

            clearSelection();
            openNewOrderModal('<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>', start, end, eqId);
        }

        document.querySelectorAll('td.js-daily-slot').forEach(function (td) {
            td.addEventListener('click', function () {
                if (clickTimer) window.clearTimeout(clickTimer);
                clickTimer = window.setTimeout(function () {
                    clickTimer = null;
                    handleSlotClick(td);
                }, 280);
            });
            td.addEventListener('dblclick', function (e) {
                e.preventDefault();
                if (clickTimer) {
                    window.clearTimeout(clickTimer);
                    clickTimer = null;
                }
                clearSelection();
                var slotMin = parseInt(td.getAttribute('data-slot-min') || '-1', 10);
                var eqId = parseInt(td.getAttribute('data-equipment-id') || '0', 10);
                if (slotMin < GRID_MIN || slotMin >= GRID_MAX || !eqId) return;
                openNewOrderModalStartOnly('<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>', slotMin, eqId);
            });
        });

        function escapeHtml(s) {
            s = String(s == null ? '' : s);
            return s
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    })();
</script>
</body>
</html>

