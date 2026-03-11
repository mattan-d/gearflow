<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo = get_db();
$error = '';
$success = '';
if (isset($_SESSION['admin_users_success'])) {
    $success = $_SESSION['admin_users_success'];
    unset($_SESSION['admin_users_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $role       = $_POST['role'] ?? 'student';
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $warehouse  = trim($_POST['warehouse'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'יש למלא שם משתמש וסיסמה.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, is_active, first_name, last_name, warehouse, created_at)
                     VALUES (:username, :password_hash, :role, :is_active, :first_name, :last_name, :warehouse, :created_at)'
                );
                $stmt->execute([
                    ':username'      => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':role'          => $role,
                    ':is_active'     => 1,
                    ':first_name'    => $firstName,
                    ':last_name'     => $lastName,
                    ':warehouse'     => $warehouse,
                    ':created_at'   => date('Y-m-d H:i:s'),
                ]);
                $_SESSION['admin_users_success'] = 'המשתמש נוצר בהצלחה.';
                header('Location: admin_users.php');
                exit;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $error = 'שם המשתמש כבר קיים.';
                } else {
                    $error = 'שגיאה ביצירת המשתמש.';
                }
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $success = 'סטטוס המשתמש עודכן.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $success = 'המשתמש נמחק.';
            } catch (PDOException $e) {
                $error = 'לא ניתן למחוק את המשתמש.';
            }
        }
    } elseif ($action === 'update') {
        $id         = (int)($_POST['id'] ?? 0);
        $username   = trim($_POST['username'] ?? '');
        $password   = (string)($_POST['new_password'] ?? '');
        $role       = $_POST['role'] ?? 'student';
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $warehouse  = trim($_POST['warehouse'] ?? '');

        if ($id <= 0 || $username === '') {
            $error = 'יש לבחור משתמש ולמלא שם משתמש.';
        } else {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username,
                             password_hash = :password_hash,
                             role = :role,
                             first_name = :first_name,
                             last_name = :last_name,
                             warehouse = :warehouse
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':username'      => $username,
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':role'          => $role,
                        ':first_name'    => $firstName,
                        ':last_name'     => $lastName,
                        ':warehouse'     => $warehouse,
                        ':id'            => $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username,
                             role = :role,
                             first_name = :first_name,
                             last_name = :last_name,
                             warehouse = :warehouse
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':username'   => $username,
                        ':role'       => $role,
                        ':first_name' => $firstName,
                        ':last_name'  => $lastName,
                        ':warehouse'  => $warehouse,
                        ':id'         => $id,
                    ]);
                }
                $_SESSION['admin_users_success'] = 'פרטי המשתמש עודכנו בהצלחה.';
                header('Location: admin_users.php');
                exit;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $error = 'שם המשתמש כבר קיים.';
                } else {
                    $error = 'שגיאה בעדכון המשתמש.';
                }
            }
        }
    }
}

$usersStmt = $pdo->query('SELECT id, username, role, is_active, first_name, last_name, warehouse FROM users ORDER BY id ASC');
$users = $usersStmt->fetchAll();

// משתמש לעריכה בחלון קופץ
$editingUser = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT id, username, role, first_name, last_name, warehouse FROM users WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $editingUser = $stmt->fetch() ?: null;
    }
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ניהול משתמשים - מערכת השאלת ציוד</title>
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
        header .user-info {
            font-size: 0.9rem;
            color: #e5e7eb;
        }
        header a {
            color: #f9fafb;
            text-decoration: none;
            margin-right: 1rem;
            font-size: 0.85rem;
        }
        main {
            max-width: 1100px;
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
        .grid {
            display: grid;
            grid-template-columns: 1.3fr 2fr;
            gap: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 0.5rem 0.6rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.9rem;
            box-sizing: border-box;
            margin-bottom: 0.75rem;
        }
        .btn {
            border: none;
            border-radius: 999px;
            padding: 0.5rem 1.1rem;
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
        .btn.small {
            padding: 0.3rem 0.7rem;
            font-size: 0.8rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            padding: 0.55rem 0.5rem;
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
            padding: 0.15rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
        }
        .badge.admin {
            background: #eef2ff;
            color: #3730a3;
        }
        .badge.student {
            background: #ecfdf3;
            color: #166534;
        }
        .badge.warehouse {
            background: #e0f2fe;
            color: #075985;
        }
        .badge.inactive {
            background: #fef2f2;
            color: #b91c1c;
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
        .muted {
            color: #6b7280;
            font-size: 0.8rem;
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
            max-width: 650px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem 1.5rem 1.25rem;
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
<?php $me = current_user(); ?>
<body>
<header>
    <div>
        <h1>ניהול משתמשים</h1>
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
    <?php if ($success !== ''): ?>
        <div class="flash success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error" style="margin-bottom: 1rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
        <button type="button" class="btn" id="open_user_modal_btn">משתמש חדש</button>
    </div>

    <?php $showUserModal = $editingUser !== null; ?>
    <div class="modal-backdrop" id="user_modal" style="display: <?= $showUserModal ? 'flex' : 'none' ?>;">
        <div class="modal-card">
            <div class="modal-header">
                <h2><?= $editingUser ? 'עריכת משתמש' : 'משתמש חדש' ?></h2>
                <button type="button" class="modal-close" id="user_modal_close" aria-label="סגירת חלון">✕</button>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif ($success !== ''): ?>
                <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="admin_users.php<?= $editingUser ? '?edit_id=' . (int)$editingUser['id'] : '' ?>">
                <input type="hidden" name="action" id="user_form_action" value="<?= $editingUser ? 'update' : 'create' ?>">
                <input type="hidden" name="id" id="user_id" value="<?= $editingUser ? (int)$editingUser['id'] : 0 ?>">
                <div class="grid">
                    <div>
                        <label for="modal_first_name">שם פרטי</label>
                        <input type="text" id="modal_first_name" name="first_name"
                               value="<?= $editingUser ? htmlspecialchars($editingUser['first_name'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_last_name">שם משפחה</label>
                        <input type="text" id="modal_last_name" name="last_name"
                               value="<?= $editingUser ? htmlspecialchars($editingUser['last_name'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_username">שם משתמש</label>
                        <input type="text" id="modal_username" name="username" required
                               value="<?= $editingUser ? htmlspecialchars($editingUser['username'], ENT_QUOTES, 'UTF-8') : '' ?>">

                        <label for="modal_password">סיסמה<?= $editingUser ? ' (השאר ריק כדי לא לשנות)' : '' ?></label>
                        <?php if ($editingUser): ?>
                        <input type="password" id="modal_password" name="new_password" autocomplete="new-password" placeholder="הזן סיסמה חדשה">
                        <?php else: ?>
                        <input type="password" id="modal_password" name="password" autocomplete="off" required>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="modal_role">תפקיד</label>
                        <?php $currentRole = $editingUser['role'] ?? 'student'; ?>
                        <select id="modal_role" name="role">
                            <option value="student" <?= $currentRole === 'student' ? 'selected' : '' ?>>סטודנט</option>
                            <option value="warehouse_manager" <?= $currentRole === 'warehouse_manager' ? 'selected' : '' ?>>מנהל מחסן</option>
                            <option value="admin" <?= $currentRole === 'admin' ? 'selected' : '' ?>>אדמין</option>
                        </select>

                        <label for="modal_warehouse">מחסן</label>
                        <?php $currentWarehouse = trim((string)($editingUser['warehouse'] ?? '')); ?>
                        <select id="modal_warehouse" name="warehouse">
                            <option value="">ללא מחסן</option>
                            <option value="מחסן א" <?= $currentWarehouse === 'מחסן א' ? 'selected' : '' ?>>מחסן א</option>
                            <option value="מחסן ב" <?= $currentWarehouse === 'מחסן ב' ? 'selected' : '' ?>>מחסן ב</option>
                        </select>

                        <p class="muted">
                            משתמשים עתידיים ישמשו להזמנת ציוד, אישור הזמנות ועוד.
                        </p>
                    </div>
                </div>
                <button type="submit" class="btn"><?= $editingUser ? 'שמירת שינויים' : 'שמירת משתמש' ?></button>
                <?php if (!$editingUser): ?>
                    <button type="button" class="btn secondary" id="user_modal_cancel">ביטול</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>רשימת משתמשים</h2>
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>שם משתמש</th>
                <th>שם פרטי</th>
                <th>שם משפחה</th>
                <th>מחסן</th>
                <th>תפקיד</th>
                <th>סטטוס</th>
                <th>פעולות</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= (int)$user['id'] ?></td>
                    <td><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['warehouse'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($user['role'] === 'admin'): ?>
                            <span class="badge admin">אדמין</span>
                        <?php elseif ($user['role'] === 'warehouse_manager'): ?>
                            <span class="badge warehouse">מנהל מחסן</span>
                        <?php else: ?>
                            <span class="badge student">סטודנט</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$user['is_active'] === 1): ?>
                            פעיל
                        <?php else: ?>
                            <span class="badge inactive">לא פעיל</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a href="admin_users.php?edit_id=<?= (int)$user['id'] ?>" class="icon-btn" title="עריכת משתמש">✏️</a>
                            <form method="post" action="admin_users.php">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="btn small secondary">
                                    <?= (int)$user['is_active'] === 1 ? 'השבת' : 'הפעל' ?>
                                </button>
                            </form>
                            <form method="post" action="admin_users.php" onsubmit="return confirm('למחוק את המשתמש הזה?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="icon-btn" title="מחיקת משתמש">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
<footer>
    © 2026 CentricApp LTD
</footer>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var openBtn = document.getElementById('open_user_modal_btn');
    var modal = document.getElementById('user_modal');
    var closeBtn = document.getElementById('user_modal_close');
    var cancelBtn = document.getElementById('user_modal_cancel');
    var actionInput = document.getElementById('user_form_action');
    var idInput = document.getElementById('user_id');
    var usernameInput = document.getElementById('modal_username');
    var passwordInput = document.getElementById('modal_password');
    var roleSelect = document.getElementById('modal_role');
    var firstNameInput = document.getElementById('modal_first_name');
    var lastNameInput = document.getElementById('modal_last_name');
    var warehouseSelect = document.getElementById('modal_warehouse');

    function openForCreate() {
        if (!modal) return;
        if (actionInput) actionInput.value = 'create';
        if (idInput) idInput.value = '0';
        if (firstNameInput) firstNameInput.value = '';
        if (lastNameInput) lastNameInput.value = '';
        if (usernameInput) usernameInput.value = '';
        if (passwordInput) passwordInput.value = '';
        if (roleSelect) roleSelect.value = 'student';
        if (warehouseSelect) warehouseSelect.value = '';
        modal.style.display = 'flex';
        if (usernameInput) {
            setTimeout(function () { usernameInput.focus(); }, 50);
        }
    }

    function closeModal() {
        if (modal) {
            modal.style.display = 'none';
        }
    }

    if (openBtn && modal) {
        openBtn.addEventListener('click', function () {
            openForCreate();
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            closeModal();
        });
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            closeModal();
        });
    }
});
</script>
</body>
</html>
