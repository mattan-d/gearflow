<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin();

$pdo = get_db();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'student';

        if ($username === '' || $password === '') {
            $error = 'יש למלא שם משתמש וסיסמה.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, role, is_active, created_at)
                     VALUES (:username, :password_hash, :role, :is_active, :created_at)'
                );
                $stmt->execute([
                    ':username'      => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':role'          => $role,
                    ':is_active'     => 1,
                    ':created_at'    => date('Y-m-d H:i:s'),
                ]);
                $success = 'המשתמש נוצר בהצלחה.';
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
    } elseif ($action === 'reset_password') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        if ($id > 0 && $password !== '') {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $stmt->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id'            => $id,
            ]);
            $success = 'הסיסמה אופסה בהצלחה.';
        } else {
            $error = 'יש לבחור משתמש ולהזין סיסמה חדשה.';
        }
    }
}

$usersStmt = $pdo->query('SELECT id, username, role, is_active, created_at FROM users ORDER BY id ASC');
$users = $usersStmt->fetchAll();

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
        }
        .row-actions form {
            margin: 0;
        }
        .muted {
            color: #6b7280;
            font-size: 0.8rem;
        }
        .nav-links {
            margin-top: 0.4rem;
        }
        .nav-links a {
            color: #e5e7eb;
            font-size: 0.82rem;
            margin-left: 0.75rem;
        }
        .nav-links a.active {
            font-weight: 600;
            text-decoration: underline;
        }
    </style>
</head>
<?php $me = current_user(); ?>
<body>
<header>
    <div>
        <h1>ניהול משתמשים</h1>
        <div class="muted">פלטפורמה לניהול השאלת ציוד</div>
        <div class="nav-links">
            <a href="admin_equipment.php">ניהול ציוד</a>
            <a href="admin_users.php" class="active">ניהול משתמשים</a>
        </div>
    </div>
    <div class="user-info">
        מחובר כ־<?= htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8') ?> (אדמין)
        <a href="logout.php">התנתק</a>
    </div>
</header>
<main>
    <div class="card">
        <h2>יצירת משתמש חדש</h2>

        <?php if ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($success !== ''): ?>
            <div class="flash success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="admin_users.php">
            <input type="hidden" name="action" value="create">
            <div class="grid">
                <div>
                    <label for="username">שם משתמש</label>
                    <input type="text" id="username" name="username" required>

                    <label for="password">סיסמה</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div>
                    <label for="role">תפקיד</label>
                    <select id="role" name="role">
                        <option value="student">סטודנט</option>
                        <option value="admin">אדמין</option>
                    </select>
                    <p class="muted">
                        משתמשים עתידיים ישמשו להזמנת ציוד, אישור הזמנות ועוד.
                    </p>
                </div>
            </div>
            <button type="submit" class="btn">שמירת משתמש</button>
        </form>
    </div>

    <div class="card">
        <h2>רשימת משתמשים</h2>
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>שם משתמש</th>
                <th>תפקיד</th>
                <th>סטטוס</th>
                <th>נוצר ב־</th>
                <th>פעולות</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= (int)$user['id'] ?></td>
                    <td><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($user['role'] === 'admin'): ?>
                            <span class="badge admin">אדמין</span>
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
                    <td class="muted"><?= htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div class="row-actions">
                            <form method="post" action="admin_users.php">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="btn small secondary">
                                    <?= (int)$user['is_active'] === 1 ? 'השבת' : 'הפעל' ?>
                                </button>
                            </form>
                            <form method="post" action="admin_users.php">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <input type="password" name="new_password" placeholder="סיסמה חדשה" style="width: 130px;">
                                <button type="submit" class="btn small">איפוס</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>

