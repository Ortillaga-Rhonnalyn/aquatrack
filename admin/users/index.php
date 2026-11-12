<?php
/**
 * AquaTrack - Admin / Staff Accounts
 * Native PHP 8+ and existing aquatrack_db.users table.
 */
declare(strict_types=1);

session_name('aquatrack_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectToList(): void
{
    header('Location: index.php');
    exit;
}

$roles = ['Staff', 'Manager', 'Cashier', 'Delivery Staff'];
$error = '';
$flash = (string) ($_SESSION['staff_flash'] ?? '');
unset($_SESSION['staff_flash']);

if (empty($_SESSION['staff_csrf'])) {
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    // Session data alone is not sufficient for authorization.
    $check = $pdo->prepare(
        "SELECT full_name FROM users
         WHERE user_id = :id AND role = 'Admin' AND status = 'Active'
         LIMIT 1"
    );
    $check->execute(['id' => (int) $_SESSION['user_id']]);
    $admin = $check->fetch();
    if (!$admin) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../../auth/login.php');
        exit;
    }
} catch (Throwable $exception) {
    error_log('[AquaTrack staff connection] ' . $exception->getMessage());
    http_response_code(503);
    exit('Database unavailable. Check XAMPP MySQL and config/database.php.');
}

$adminName = (string) $admin['full_name'];
$editingId = max(0, (int) ($_GET['edit'] ?? 0));
$action = (string) ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals((string) $_SESSION['staff_csrf'], $token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif ($action === 'create' || $action === 'update') {
        $editingId = $action === 'update'
            ? max(0, (int) ($_POST['user_id'] ?? 0))
            : 0;
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '' || strlen($name) > 100) {
            $error = 'Enter a full name (up to 100 characters).';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $error = 'Username must be 3–50 characters: letters, numbers, _, . or -.';
        } elseif ($email !== '' && (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $error = 'Enter a valid email address or leave it blank.';
        } elseif (!in_array($role, $roles, true)) {
            $error = 'Please select a valid staff role.';
        } elseif (($action === 'create' || $password !== '') && (strlen($password) < 8 || strlen($password) > 72)) {
            $error = 'Password must be 8–72 bytes (bcrypt limit).';
        } elseif ($action === 'update' && $editingId < 1) {
            $error = 'Invalid staff account.';
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                if ($action === 'create') {
                    $stmt = $pdo->prepare(
                        "INSERT INTO users
                         (full_name, username, email, password, role, status, created_at, updated_at)
                         VALUES (:name, :username, :email, :password, :role, 'Active', NOW(), NOW())"
                    );
                    $stmt->execute([
                        'name' => $name,
                        'username' => $username,
                        'email' => $email !== '' ? $email : null,
                        'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                        'role' => $role,
                    ]);
                    $targetId = (int) $pdo->lastInsertId();
                    $logAction = 'Created staff account';
                    $message = 'Staff account created successfully.';
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT user_id FROM users
                         WHERE user_id = :id AND role <> 'Admin' LIMIT 1"
                    );
                    $stmt->execute(['id' => $editingId]);
                    if (!$stmt->fetch()) {
                        throw new RuntimeException('Staff account does not exist.');
                    }

                    $fields = [
                        'name' => $name,
                        'username' => $username,
                        'email' => $email !== '' ? $email : null,
                        'role' => $role,
                        'id' => $editingId,
                    ];
                    $passwordSql = '';
                    if ($password !== '') {
                        $passwordSql = ', password = :password';
                        $fields['password'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    }
                    $stmt = $pdo->prepare(
                        "UPDATE users SET full_name = :name,
                         username = :username, email = :email, role = :role,
                         updated_at = NOW() {$passwordSql}
                         WHERE user_id = :id AND role <> 'Admin'"
                    );
                    $stmt->execute($fields);
                    $targetId = $editingId;
                    $logAction = 'Updated staff account';
                    $message = 'Staff account updated successfully.';
                }

                $log = $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, action, table_name, record_id, created_at, updated_at)
                     VALUES (:actor, :action, 'users', :record, NOW(), NOW())"
                );
                $log->execute([
                    'actor' => (int) $_SESSION['user_id'],
                    'action' => $logAction,
                    'record' => $targetId,
                ]);

                $pdo->commit();
                $_SESSION['staff_flash'] = $message;
                redirectToList();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[AquaTrack staff save] ' . $exception->getMessage());
                $error = $exception instanceof PDOException && $exception->getCode() === '23000'
                    ? 'Username or email is already in use.'
                    : 'Unable to save the account. Please check the information and try again.';
            }
        }
    } elseif ($action === 'toggle') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId < 1) {
            $error = 'Invalid staff account.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "UPDATE users SET
                        status = CASE WHEN status = 'Active' THEN 'Inactive' ELSE 'Active' END,
                        updated_at = NOW()
                     WHERE user_id = :id AND role <> 'Admin'"
                );
                $stmt->execute(['id' => $targetId]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Staff account not found.');
                }
                $log = $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, action, table_name, record_id, created_at, updated_at)
                     VALUES (:actor, 'Changed staff account status', 'users', :record, NOW(), NOW())"
                );
                $log->execute([
                    'actor' => (int) $_SESSION['user_id'],
                    'record' => $targetId,
                ]);
                $pdo->commit();
                $_SESSION['staff_flash'] = 'Account status updated.';
                redirectToList();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[AquaTrack staff status] ' . $exception->getMessage());
                $error = 'Unable to update account status.';
            }
        }
    } else {
        $error = 'Unknown action.';
    }
}

$editStaff = null;
if ($editingId > 0) {
    $stmt = $pdo->prepare(
        "SELECT user_id, full_name, username, email, role
         FROM users WHERE user_id = :id AND role <> 'Admin' LIMIT 1"
    );
    $stmt->execute(['id' => $editingId]);
    $editStaff = $stmt->fetch() ?: null;
    if (!$editStaff && $error === '') {
        $error = 'Staff account not found.';
    }
}

$form = [
    'full_name' => (string) ($editStaff['full_name'] ?? ''),
    'username' => (string) ($editStaff['username'] ?? ''),
    'email' => (string) ($editStaff['email'] ?? ''),
    'role' => (string) ($editStaff['role'] ?? 'Staff'),
];
if ($error !== '' && in_array($action, ['create', 'update'], true)) {
    foreach (array_keys($form) as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$search = substr($search, 0, 100);
$where = "role <> 'Admin'";
$params = [];
if ($search !== '') {
    $where .= ' AND (full_name LIKE :name OR username LIKE :username OR email LIKE :email)';
    $params = [
        'name' => "%{$search}%",
        'username' => "%{$search}%",
        'email' => "%{$search}%",
    ];
}
$stmt = $pdo->prepare(
    "SELECT user_id, full_name, username, email, role, status
     FROM users WHERE {$where} ORDER BY user_id DESC"
);
$stmt->execute($params);
$staff = $stmt->fetchAll();

$totals = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'Active'), 0) AS active,
            COALESCE(SUM(status = 'Inactive'), 0) AS inactive
     FROM users WHERE role <> 'Admin'"
)->fetch();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Accounts | AquaTrack</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/users.css">
    <script src="../../assets/js/admin.js" defer></script>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
    <aside class="sidebar" id="adminSidebar" aria-label="Admin navigation">
        <a class="brand" href="../dashboard.php">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 40 40" fill="none"><path d="M20 4S8 18.5 8 26a12 12 0 0 0 24 0C32 18.5 20 4 20 4Z" fill="currentColor"/><path d="M12 27c4-4 9 4 16-1" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg>
            </span>
            <span class="brand-name">AquaTrack<small>Water Station System</small></span>
        </a>
        <div class="sidebar-label">WORKSPACE</div>
        <nav class="sidebar-nav" aria-label="Modules">
<a class="nav-item" href="../dashboard.php"><span class="nav-dot"></span> Dashboard</a>
<div class="sidebar-label sidebar-label-inner">MANAGEMENT</div>
<a class="nav-item active" href="index.php" aria-current="page"><span class="nav-dot"></span> Staff Accounts</a>
<a class="nav-item" href="../customers/index.php"><span class="nav-dot"></span> Customers</a>
<a class="nav-item" href="../orders/index.php"><span class="nav-dot"></span> Orders</a>
<a class="nav-item" href="../deliveries/index.php"><span class="nav-dot"></span> Deliveries</a>
<a class="nav-item" href="../inventory/index.php"><span class="nav-dot"></span> Inventory</a>
<a class="nav-item" href="../payments/index.php"><span class="nav-dot"></span> Sales &amp; Payments</a>
<a class="nav-item" href="../reports/index.php"><span class="nav-dot"></span> Reports &amp; Forecasting</a>
</nav>
        <div class="sidebar-bottom">
            <div class="station-label"><span class="station-pulse"></span>Gaeven's Water Station</div>
            <form method="post" action="../../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['logout_csrf']) ?>">
                <button class="logout-btn" type="submit">Sign out <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                <span class="breadcrumb">AquaTrack <span class="breadcrumb-separator">/</span> Staff Accounts</span>
            </div>
            <div class="profile">
                <span class="profile-avatar">A</span>
                <span class="profile-name"><?= e($adminName) ?><small>Administrator</small></span>
            </div>
        </header>

        <main class="content">
            <div class="page-heading staff-heading">
                <div>
                    <span class="eyebrow">ADMIN / USER MANAGEMENT</span>
                    <h1>Staff Accounts</h1>
                    <p>Create and manage accounts for your water station team.</p>
                </div>
                <a class="outline-link" href="../dashboard.php">← Dashboard</a>
            </div>

            <?php if ($flash !== ''): ?>
                <div class="notice notice-success" role="status"><?= e($flash) ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="notice notice-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="mini-stats">
                <div><span>Total staff</span><strong><?= (int) $totals['total'] ?></strong></div>
                <div><span>Active</span><strong><?= (int) $totals['active'] ?></strong></div>
                <div><span>Inactive</span><strong><?= (int) $totals['inactive'] ?></strong></div>
            </div>

            <div class="management-grid">
                <section class="panel account-panel" id="account-form">
                    <div class="panel-heading">
                        <div>
                            <h2><?= $editStaff ? 'Edit Staff Account' : 'Create Staff Account' ?></h2>
                            <p><?= $editStaff ? 'Update the account details below.' : 'Only administrators can add new accounts.' ?></p>
                        </div>
                    </div>

                    <form method="post" action="<?= $editStaff ? 'index.php?edit=' . (int) $editingId : 'index.php' ?>#account-form" class="account-form">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['staff_csrf']) ?>">
                        <input type="hidden" name="action" value="<?= $editStaff ? 'update' : 'create' ?>">
                        <?php if ($editStaff): ?>
                            <input type="hidden" name="user_id" value="<?= (int) $editingId ?>">
                        <?php endif; ?>

                        <label for="full_name">Full name</label>
                        <input id="full_name" name="full_name" maxlength="100" required value="<?= e($form['full_name']) ?>" placeholder="e.g. Juan Dela Cruz">

                        <label for="username">Username</label>
                        <input id="username" name="username" maxlength="50" pattern="[A-Za-z0-9_.-]{3,50}" required value="<?= e($form['username']) ?>" placeholder="e.g. juan.staff">

                        <label for="email">Email <span>(optional)</span></label>
                        <input id="email" name="email" type="email" maxlength="255" value="<?= e($form['email']) ?>" placeholder="name@example.com">

                        <label for="role">Staff role</label>
                        <select id="role" name="role" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role) ?>" <?= $form['role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="password">Password <?= $editStaff ? '<span>(leave blank to keep current)</span>' : '' ?></label>
                        <input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" <?= $editStaff ? '' : 'required' ?> placeholder="At least 8 characters">
                        <p class="field-hint">Stored securely using bcrypt with cost 12.</p>

                        <button class="primary-button" type="submit">
                            <?= $editStaff ? 'Save Changes' : 'Create Staff Account' ?>
                        </button>
                        <?php if ($editStaff): ?>
                            <a class="cancel-link" href="index.php#account-form">Cancel editing</a>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="panel listing-panel" aria-labelledby="staff-list-title">
                    <div class="panel-heading list-title">
                        <div>
                            <h2 id="staff-list-title">Team Members</h2>
                            <p>Accounts registered in AquaTrack</p>
                        </div>
                    </div>

                    <form class="search-row" method="get" action="index.php">
                        <input type="search" name="search" aria-label="Search staff" value="<?= e($search) ?>" placeholder="Search name, username or email">
                        <button type="submit">Search</button>
                        <?php if ($search !== ''): ?><a href="index.php">Clear</a><?php endif; ?>
                    </form>

                    <div class="table-wrap">
                        <table class="staff-table">
                            <thead>
                                <tr><th>Staff</th><th>Role</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($staff as $person): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($person['full_name']) ?></strong>
                                        <small>@<?= e($person['username']) ?><?= $person['email'] ? ' · ' . e($person['email']) : '' ?></small>
                                    </td>
                                    <td><?= e($person['role']) ?></td>
                                    <td><span class="status <?= $person['status'] === 'Active' ? 'is-success' : 'is-danger' ?>"><?= e($person['status']) ?></span></td>
                                    <td>
                                        <div class="row-actions">
                                            <a href="index.php?edit=<?= (int) $person['user_id'] ?>#account-form">Edit</a>
                                            <form method="post" action="index.php">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['staff_csrf']) ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="user_id" value="<?= (int) $person['user_id'] ?>">
                                                <button type="submit"><?= $person['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$staff): ?>
                                <tr><td colspan="4" class="no-staff">No staff accounts found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <footer class="page-footer">AquaTrack · Gaeven's Water Refilling Station · IT 314 Project</footer>
        </main>
    </div>
</div>
</body>
</html>
