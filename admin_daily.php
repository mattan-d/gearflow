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
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.75rem; }
        .legend { display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center; margin-top:0.5rem; }
        .legend-item { display:flex; align-items:center; gap:0.35rem; font-size:0.82rem; color:#374151; }
        .swatch { width:12px; height:12px; border-radius:4px; border:1px solid rgba(0,0,0,0.12); }
        .muted { color:#6b7280; font-size:0.85rem; }
        .filters-row { display:flex; gap:0.75rem; align-items:flex-end; flex-wrap:wrap; margin: 0.5rem 0 0.75rem; }
        .filter-block { min-width: 160px; }
        .filter-block label { display:block; font-size:0.8rem; color:#374151; margin-bottom:0.25rem; }
        .filter-block input[type="date"],
        .filter-block select,
        .filter-block input[type="text"] {
            width:100%;
            padding:0.4rem 0.6rem;
            border-radius:8px;
            border:1px solid #d1d5db;
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
        table.daily th:not(:first-child), table.daily td:not(:first-child) { width: 64px; }
        table.daily thead th { position: sticky; top: 0; background:#f9fafb; z-index: 3; font-weight:700; }
        table.daily thead th:first-child { z-index: 4; }
        .eq-name { font-weight:600; color:#111827; }
        .eq-code { font-size:0.75rem; color:#6b7280; }
        .cell { height: 24px; border-radius: 8px; display:flex; align-items:center; justify-content:center; }
        .cell.occupied { box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06); }
        .cell .tiny { font-size:0.7rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; display:inline-block; vertical-align:middle; }
        .order-cell-link { display:block; text-decoration:none; }
        .order-cell-link .cell { cursor: pointer; }
        .order-cell-link:hover .cell { filter: brightness(0.98); }
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
    </style>
</head>
<body>
<?php include __DIR__ . '/admin_header.php'; ?>
<main>
    <div class="card">
        <div class="topbar">
            <div>
                <h2 style="margin:0 0 0.15rem;font-size:1.25rem;">ניהול יומי</h2>
                <div class="muted">תצוגת הזמנות לפי יום ושעות השאלה (09:00–17:00)</div>
            </div>
        </div>

        <form method="get" action="admin_daily.php" class="filters-row" id="daily_filters_form">
            <div class="filter-block" style="min-width:170px;">
                <label for="day_input">בחירת תאריך</label>
                <input id="day_input" type="date" name="day" value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()">
            </div>
            <div class="filter-block" style="min-width:200px;">
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
            <div class="filter-block" style="flex:1; min-width:220px;">
                <label for="q_input">חיפוש פריט ציוד לפי שם</label>
                <input id="q_input" type="text" name="q" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="הקלד שם פריט...">
            </div>
            <div style="display:flex; gap:0.25rem; align-items:flex-end;">
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
                <button type="submit" class="icon-btn" title="סינון" aria-label="סינון">
                    <i data-lucide="search" aria-hidden="true"></i>
                </button>
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
                                    <?php
                                    $colCount = count($hours);
                                    $i = 0;
                                    while ($i < $colCount) {
                                        $h = $hours[$i];
                                        $cellStart = $h * 60;
                                        $cellEnd   = ($h + 1) * 60;

                                        // מוצאים הזמנה שמתחילה/נמשכת בתא הנוכחי
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

                                        if (!$hit) {
                                            echo '<td><div class="cell"></div></td>';
                                            $i++;
                                            continue;
                                        }

                                        $sMin = gf_time_to_minutes((string)($hit['start_time'] ?? '09:00'));
                                        $eMin = gf_time_to_minutes((string)($hit['end_time'] ?? '17:00'));
                                        // גבולות התצוגה (09:00–18:00 בקצה העליון כדי לחשב colspan)
                                        $gridStart = 9 * 60;
                                        $gridEnd   = 18 * 60;
                                        $sMin = max($gridStart, min($gridEnd, $sMin));
                                        $eMin = max($gridStart, min($gridEnd, $eMin));
                                        if ($eMin <= $sMin) $eMin = min($gridEnd, $sMin + 60);

                                        $startIdx = (int)floor(($sMin - $gridStart) / 60);
                                        $endIdxExcl = (int)ceil(($eMin - $gridStart) / 60);
                                        $startIdx = max(0, min($colCount - 1, $startIdx));
                                        $endIdxExcl = max($startIdx + 1, min($colCount, $endIdxExcl));

                                        // אם ההזמנה התחילה לפני התא הנוכחי – זה "המשך" שכבר הוצג, אז נדלג
                                        if ($i > $startIdx) {
                                            echo '<td><div class="cell"></div></td>';
                                            $i++;
                                            continue;
                                        }

                                        $span = max(1, $endIdxExcl - $startIdx);
                                        $st = (string)($hit['status'] ?? '');
                                        $c = gf_status_color($st);
                                        $borrower = trim((string)($hit['borrower_name'] ?? ''));
                                        $title = 'הזמנה #' . (int)($hit['id'] ?? 0) . ' · ' . $borrower . ' · ' . ($hit['start_time'] ?? '') . '-' . ($hit['end_time'] ?? '') . ' · ' . $c['label'];
                                        ?>
                                        <td colspan="<?= (int)$span ?>" title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="order-cell-link js-order-open"
                                               href="#"
                                               data-order-id="<?= (int)($hit['id'] ?? 0) ?>"
                                               aria-label="צפייה בהזמנה #<?= (int)($hit['id'] ?? 0) ?>">
                                                <div class="cell occupied" style="background:<?= htmlspecialchars($c['bg'], ENT_QUOTES, 'UTF-8') ?>; color:<?= htmlspecialchars($c['fg'], ENT_QUOTES, 'UTF-8') ?>;">
                                                    <span class="tiny">#<?= (int)($hit['id'] ?? 0) ?> <?= htmlspecialchars($borrower, ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                            </a>
                                        </td>
                                        <?php
                                        $i += $span;
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
            }, 350);
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
        if (!panel || !kv || !title || !closeBtn || !openFull) return;

        function hide() {
            panel.style.display = 'none';
        }
        closeBtn.addEventListener('click', hide);

        document.querySelectorAll('.js-order-open').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var id = this.getAttribute('data-order-id') || '';
                if (!id) return;
                title.textContent = 'הזמנה #' + id;
                kv.textContent = 'טוען...';
                panel.style.display = 'block';
                if (window.lucide) lucide.createIcons();

                openFull.href = 'admin_orders.php?view_id=' + encodeURIComponent(id);

                fetch('admin_daily.php?action=order_json&order_id=' + encodeURIComponent(id), {
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); })
                  .then(function (data) {
                      if (!data || !data.ok || !data.order) {
                          kv.textContent = 'לא ניתן לטעון פרטי הזמנה.';
                          return;
                      }
                      var o = data.order;
                      var parts = [];
                      parts.push('<b>ציוד:</b> ' + escapeHtml((o.equipment_name || '') + ' (' + (o.equipment_code || '') + ')'));
                      parts.push('<b>סטטוס:</b> ' + escapeHtml(o.status || ''));
                      parts.push('<b>שואל:</b> ' + escapeHtml(o.borrower_name || ''));
                      if (o.borrower_contact) parts.push('<b>טלפון/מייל:</b> ' + escapeHtml(o.borrower_contact));
                      parts.push('<b>תאריך/שעה:</b> ' + escapeHtml((o.start_date || '') + ' ' + (o.start_time || '') + ' → ' + (o.end_date || '') + ' ' + (o.end_time || '')));
                      if (o.notes) parts.push('<b>הערות:</b> ' + escapeHtml(o.notes));
                      kv.innerHTML = parts.map(function (p) { return '<span>' + p + '</span>'; }).join(' · ');
                  })
                  .catch(function () {
                      kv.textContent = 'לא ניתן לטעון פרטי הזמנה.';
                  });

                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

