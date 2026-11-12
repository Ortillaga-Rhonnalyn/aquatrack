<?php
/**
 * AquaTrack - Customer Management
 * Admin module; Native PHP 8 + existing aquatrack_db.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');
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

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectToCustomers(): void
{
    header('Location: index.php');
    exit;
}

$error = '';
$flash = (string) ($_SESSION['customer_flash'] ?? '');
unset($_SESSION['customer_flash']);

if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $accountQuery = $pdo->prepare(
        'SELECT user_id, full_name, role, status FROM users WHERE user_id = :id LIMIT 1'
    );
    $accountQuery->execute(['id' => (int) $_SESSION['user_id']]);
    $account = $accountQuery->fetch();

    if (!$account || $account['status'] !== 'Active') {
        $_SESSION = [];
        session_destroy();
        header('Location: ../../auth/login.php');
        exit;
    }

    // This module is located under Admin and is only accessible to Admins.
    if ($account['role'] !== 'Admin') {
        http_response_code(403);
        exit('Access denied. Customer Management is for administrators only.');
    }
} catch (Throwable $exception) {
    error_log('[AquaTrack customers connection] ' . $exception->getMessage());
    http_response_code(503);
    exit('Database unavailable. Check MySQL and config/database.php.');
}

$role = (string) $account['role'];
$fullName = (string) $account['full_name'];
$dashboardUrl = '../dashboard.php';

$form = [
    'customer_id' => 0,
    'customer_name' => '',
    'contact_number' => '',
    'address' => '',
    'status' => 'Active',
];
$isEditing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals((string) $_SESSION['customer_csrf'], $token)) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif ($action === 'create' || $action === 'update') {
        $idRaw = (string) ($_POST['customer_id'] ?? '');
        $id = ctype_digit($idRaw) ? (int) $idRaw : 0;
        $name = trim((string) ($_POST['customer_name'] ?? ''));
        $contact = trim((string) ($_POST['contact_number'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'Active');

        $form = [
            'customer_id' => $id,
            'customer_name' => $name,
            'contact_number' => $contact,
            'address' => $address,
            'status' => $status,
        ];
        $isEditing = $action === 'update';

        if ($name === '' || strlen($name) > 100) {
            $error = 'Customer name is required (maximum 100 characters).';
        } elseif (strlen($contact) > 20) {
            $error = 'Contact number must be 20 characters or fewer.';
        } elseif ($address === '' || strlen($address) > 255) {
            $error = 'Address is required (maximum 255 characters).';
        } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
            $error = 'Invalid customer status.';
        } elseif ($action === 'update' && $id < 1) {
            $error = 'Select a valid customer to update.';
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();
                if ($action === 'create') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO customers (customer_name, contact_number, address, status, created_at, updated_at)
                         VALUES (:name, :contact, :address, :status, NOW(), NOW())'
                    );
                    $stmt->execute([
                        'name' => $name,
                        'contact' => $contact !== '' ? $contact : null,
                        'address' => $address,
                        'status' => $status,
                    ]);
                    $targetId = (int) $pdo->lastInsertId();
                    $logAction = 'Created customer';
                    $success = 'Customer added successfully.';
                } else {
                    $exists = $pdo->prepare('SELECT customer_id FROM customers WHERE customer_id = :id LIMIT 1');
                    $exists->execute(['id' => $id]);
                    if (!$exists->fetch()) {
                        throw new RuntimeException('Customer record no longer exists.');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE customers SET customer_name = :name, contact_number = :contact,
                         address = :address, status = :status, updated_at = NOW()
                         WHERE customer_id = :id'
                    );
                    $stmt->execute([
                        'name' => $name,
                        'contact' => $contact !== '' ? $contact : null,
                        'address' => $address,
                        'status' => $status,
                        'id' => $id,
                    ]);
                    $targetId = $id;
                    $logAction = 'Updated customer';
                    $success = 'Customer details updated.';
                }

                $log = $pdo->prepare(
                    'INSERT INTO activity_logs (user_id, action, table_name, record_id, log_date, created_at, updated_at)
                     VALUES (:user_id, :action, :table_name, :record_id, NOW(), NOW(), NOW())'
                );
                $log->execute([
                    'user_id' => (int) $account['user_id'],
                    'action' => $logAction,
                    'table_name' => 'customers',
                    'record_id' => $targetId,
                ]);
                $pdo->commit();
                $_SESSION['customer_flash'] = $success;
                redirectToCustomers();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[AquaTrack customer save] ' . $exception->getMessage());
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to save customer. Please try again.';
            }
        }
    } elseif ($action === 'toggle') {
        $idRaw = (string) ($_POST['customer_id'] ?? '');
        $id = ctype_digit($idRaw) ? (int) $idRaw : 0;
        if ($id < 1) {
            $error = 'Invalid customer selected.';
        } else {
            try {
                $pdo->beginTransaction();
                $find = $pdo->prepare('SELECT status FROM customers WHERE customer_id = :id FOR UPDATE');
                $find->execute(['id' => $id]);
                $customer = $find->fetch();
                if (!$customer) {
                    throw new RuntimeException('Customer record not found.');
                }
                $newStatus = $customer['status'] === 'Active' ? 'Inactive' : 'Active';
                $update = $pdo->prepare(
                    'UPDATE customers SET status = :status, updated_at = NOW() WHERE customer_id = :id'
                );
                $update->execute(['status' => $newStatus, 'id' => $id]);
                $log = $pdo->prepare(
                    'INSERT INTO activity_logs (user_id, action, table_name, record_id, log_date, created_at, updated_at)
                     VALUES (:user_id, :action, :table_name, :record_id, NOW(), NOW(), NOW())'
                );
                $log->execute([
                    'user_id' => (int) $account['user_id'],
                    'action' => $newStatus === 'Active' ? 'Activated customer' : 'Deactivated customer',
                    'table_name' => 'customers',
                    'record_id' => $id,
                ]);
                $pdo->commit();
                $_SESSION['customer_flash'] = 'Customer ' . strtolower($newStatus) . ' successfully.';
                redirectToCustomers();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[AquaTrack customer status] ' . $exception->getMessage());
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to change status. Please try again.';
            }
        }
    } else {
        $error = 'Unknown action.';
    }
}

// Editing via GET does not overwrite data from a form that failed validation.
$editRaw = (string) ($_GET['edit'] ?? '');
$editId = ctype_digit($editRaw) ? (int) $editRaw : 0;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editId > 0) {
    $edit = $pdo->prepare(
        'SELECT customer_id, customer_name, contact_number, address, status
         FROM customers WHERE customer_id = :id LIMIT 1'
    );
    $edit->execute(['id' => $editId]);
    $record = $edit->fetch();
    if ($record) {
        $form = $record;
        $isEditing = true;
    } else {
        $error = 'Customer not found.';
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = substr($search, 0, 80);
$filter = (string) ($_GET['filter'] ?? 'All');
if (!in_array($filter, ['All', 'Active', 'Inactive'], true)) {
    $filter = 'All';
}
$pageRaw = (string) ($_GET['page'] ?? '1');
$page = ctype_digit($pageRaw) ? max(1, (int) $pageRaw) : 1;
$perPage = 10;
$where = ' WHERE (customer_name LIKE :search_name OR contact_number LIKE :search_phone OR address LIKE :search_address)';
$params = [
    'search_name' => '%' . $search . '%',
    'search_phone' => '%' . $search . '%',
    'search_address' => '%' . $search . '%',
];
if ($filter !== 'All') {
    $where .= ' AND status = :filter';
    $params['filter'] = $filter;
}

try {
    $count = $pdo->prepare('SELECT COUNT(*) FROM customers' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $list = $pdo->prepare(
        'SELECT customer_id, customer_name, contact_number, address, status, created_at
         FROM customers' . $where . ' ORDER BY customer_id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $list->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $list->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();
    $customers = $list->fetchAll();

    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'Active'")->fetchColumn();
    $allCount = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
} catch (Throwable $exception) {
    error_log('[AquaTrack customer list] ' . $exception->getMessage());
    http_response_code(503);
    exit('Customer records unavailable. Check database configuration.');
}

function pageUrl(int $number, string $search, string $filter): string
{
    return 'index.php?' . http_build_query(['q' => $search, 'filter' => $filter, 'page' => $number]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0e6073">
    <title>Customers | AquaTrack Admin</title>
    <!-- Reuse the exact Admin Dashboard styles and mobile sidebar behavior -->
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/customers.css">
    <script src="../../assets/js/admin.js" defer></script>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
    <aside class="sidebar" id="adminSidebar" aria-label="Admin navigation">
        <a class="brand" href="../dashboard.php">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 40 40" fill="none">
                    <path d="M20 4S8 18.5 8 26a12 12 0 0 0 24 0C32 18.5 20 4 20 4Z" fill="currentColor"/>
                    <path d="M12 27c4-4 9 4 16-1" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="brand-name">AquaTrack<small>Water Station System</small></span>
        </a>

        <div class="sidebar-label">WORKSPACE</div>
        <nav class="sidebar-nav" aria-label="Modules">
<a class="nav-item" href="../dashboard.php"><span class="nav-dot"></span> Dashboard</a>
<div class="sidebar-label sidebar-label-inner">MANAGEMENT</div>
<a class="nav-item" href="../users/index.php"><span class="nav-dot"></span> Staff Accounts</a>
<a class="nav-item active" href="index.php" aria-current="page"><span class="nav-dot"></span> Customers</a>
<a class="nav-item" href="../orders/index.php"><span class="nav-dot"></span> Orders</a>
<a class="nav-item" href="../deliveries/index.php"><span class="nav-dot"></span> Deliveries</a>
<a class="nav-item" href="../inventory/index.php"><span class="nav-dot"></span> Inventory</a>
<a class="nav-item" href="../payments/index.php"><span class="nav-dot"></span> Sales &amp; Payments</a>
<a class="nav-item" href="../reports/index.php"><span class="nav-dot"></span> Reports &amp; Forecasting</a>
</nav>

        <div class="sidebar-bottom">
            <div class="station-label"><span class="station-pulse"></span> Gaeven's Water Station</div>
            <form method="post" action="../../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['logout_csrf']) ?>">
                <button class="logout-btn" type="submit">Sign out <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" type="button"
                        aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                <span class="breadcrumb">AquaTrack <span class="breadcrumb-separator">/</span> Admin
                    <span class="breadcrumb-separator">/</span> Customers</span>
            </div>
            <div class="profile">
                <span class="profile-avatar"><?= h(strtoupper(substr($fullName, 0, 1))) ?></span>
                <span class="profile-name"><?= h($fullName) ?><small>Administrator</small></span>
            </div>
        </header>

        <main class="content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">CUSTOMER RECORDS</span>
                    <h1>Customer Management</h1>
                    <p>Keep customer information and delivery addresses organized.</p>
                </div>
                <a href="<?= h($dashboardUrl) ?>" class="back-btn">← Dashboard</a>
            </div>

            <div class="overview">
                <div class="overview-card"><span>Total Customers</span><strong><?= number_format($allCount) ?></strong></div>
                <div class="overview-card"><span>Active Customers</span><strong><?= number_format($activeCount) ?></strong></div>
                <div class="overview-card overview-note"><span>Station Directory</span><p>Customers are kept in the system even when marked inactive, so past orders remain linked.</p></div>
            </div>

            <?php if ($flash !== ''): ?><div class="alert success" role="status"><?= h($flash) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert danger" role="alert"><?= h($error) ?></div><?php endif; ?>

            <div class="work-grid">
                <section class="panel editor" id="customer-form">
                    <div class="panel-head">
                        <div>
                            <span class="mini-label"><?= $isEditing ? 'UPDATE RECORD' : 'NEW RECORD' ?></span>
                            <h2><?= $isEditing ? 'Edit Customer' : 'Add a Customer' ?></h2>
                        </div>
                        <?php if ($isEditing): ?><a class="cancel-link" href="index.php">Cancel edit</a><?php endif; ?>
                    </div>

                    <form method="post" action="index.php" class="customer-form">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customer_csrf']) ?>">
                        <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                        <?php if ($isEditing): ?>
                            <input type="hidden" name="customer_id" value="<?= (int) $form['customer_id'] ?>">
                        <?php endif; ?>
                        <label for="customer_name">Full Name <span>*</span></label>
                        <input id="customer_name" name="customer_name" value="<?= h($form['customer_name']) ?>" maxlength="100" required placeholder="e.g. Juan Dela Cruz">

                        <label for="contact_number">Contact Number <small>(optional)</small></label>
                        <input id="contact_number" name="contact_number" type="tel" value="<?= h($form['contact_number']) ?>" maxlength="20" placeholder="09XX XXX XXXX">

                        <label for="address">Delivery Address <span>*</span></label>
                        <textarea id="address" name="address" maxlength="255" rows="3" required placeholder="House no., street, barangay, municipality"><?= h($form['address']) ?></textarea>

                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <button type="submit" class="primary-btn"><?= $isEditing ? 'Save Changes' : '+ Add Customer' ?></button>
                        <p class="form-note">* Required information. No public customer registration is needed.</p>
                    </form>
                </section>

                <section class="panel records">
                    <div class="panel-head records-head">
                        <div>
                            <span class="mini-label">CUSTOMER DIRECTORY</span>
                            <h2>Customer List</h2>
                        </div>
                        <span class="record-total"><?= number_format($total) ?> result<?= $total === 1 ? '' : 's' ?></span>
                    </div>

                    <form method="get" class="filters">
                        <label class="sr-only" for="q">Search customers</label>
                        <input id="q" name="q" value="<?= h($search) ?>" maxlength="80" placeholder="Search name, contact, address...">
                        <label class="sr-only" for="filter">Filter status</label>
                        <select id="filter" name="filter">
                            <?php foreach (['All', 'Active', 'Inactive'] as $choice): ?>
                                <option value="<?= h($choice) ?>" <?= $filter === $choice ? 'selected' : '' ?>><?= h($choice) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="filter-btn">Search</button>
                    </form>

                    <?php if ($customers): ?>
                        <div class="table-scroll">
                            <table>
                                <thead><tr><th>Customer</th><th>Contact / Address</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($customer['customer_name']) ?></strong>
                                            <small>ID #<?= (int) $customer['customer_id'] ?></small>
                                        </td>
                                        <td>
                                            <span><?= h($customer['contact_number'] ?: 'No contact number') ?></span>
                                            <small class="address-text" title="<?= h($customer['address']) ?>"><?= h($customer['address']) ?></small>
                                        </td>
                                        <td><span class="status <?= $customer['status'] === 'Active' ? 'active' : 'inactive' ?>"><?= h($customer['status']) ?></span></td>
                                        <td>
                                            <div class="row-actions">
                                                <a href="index.php?edit=<?= (int) $customer['customer_id'] ?>#customer-form">Edit</a>
                                                <form method="post" action="index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customer_csrf']) ?>">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                                                    <button type="submit" class="toggle-btn"><?= $customer['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><span>💧</span><strong>No customers found</strong><p>Add a customer or adjust your search filters.</p></div>
                    <?php endif; ?>

                    <?php if ($pages > 1): ?>
                        <nav class="pagination" aria-label="Customer pages">
                            <?php if ($page > 1): ?><a href="<?= h(pageUrl($page - 1, $search, $filter)) ?>">← Previous</a><?php endif; ?>
                            <span>Page <?= $page ?> of <?= $pages ?></span>
                            <?php if ($page < $pages): ?><a href="<?= h(pageUrl($page + 1, $search, $filter)) ?>">Next →</a><?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>
            </div>
            <footer class="footer">AquaTrack · Gaeven's Water Refilling Station · IT 314 Project</footer>
        </main>
    </div>
</div>
</body>
</html>
