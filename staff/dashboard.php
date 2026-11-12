<?php
/**
 * AquaTrack Staff Dashboard
 * Native PHP + MySQL / Gaeven's Water Refilling Station
 * Uses config/database.php, auth/logout.php and assets/js/admin.js.
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
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function niceDate(?string $value): string
{
    if (!$value) {
        return 'Not scheduled';
    }
    $time = strtotime($value);
    return $time === false ? '—' : date('M j, Y', $time);
}

function pillClass(string $status): string
{
    return match ($status) {
        'Paid', 'Completed' => 'status--green',
        'Pending', 'Confirmed', 'Preparing', 'Partial' => 'status--amber',
        'Cancelled', 'Overdue', 'Out of Stock' => 'status--red',
        default => 'status--blue',
    };
}

try {
    // Reuses the PDO connection created in the Admin Dashboard module.
    $pdo = require __DIR__ . '/../config/database.php';

    // The database, not just the session, decides whether this user can enter.
    $stmt = $pdo->prepare(
        'SELECT user_id, full_name, username, role, status
         FROM users WHERE user_id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $currentUser = $stmt->fetch();

    if (!$currentUser || $currentUser['status'] !== 'Active') {
        $_SESSION = [];
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }

    if ($currentUser['role'] === 'Admin') {
        header('Location: ../admin/dashboard.php');
        exit;
    }

    $allowedRoles = ['Staff', 'Manager', 'Cashier', 'Delivery Staff'];
    if (!in_array((string) $currentUser['role'], $allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }

    $_SESSION['role'] = (string) $currentUser['role'];
    $_SESSION['full_name'] = (string) $currentUser['full_name'];
    $fullName = (string) $currentUser['full_name'];
    $firstName = explode(' ', trim($fullName))[0] ?: 'Team Member';
    $role = (string) $currentUser['role'];
    $userId = (int) $currentUser['user_id'];

    // Real counts from aquatrack_db. Nothing is hard-coded or simulated.
    $myOrdersStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE created_by = :user_id');
    $myOrdersStmt->execute(['user_id' => $userId]);

    $stats = [
        'my_orders' => (int) $myOrdersStmt->fetchColumn(),
        'open_orders' => (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE order_status IN ('Pending', 'Confirmed', 'Preparing', 'Out for Delivery')"
        )->fetchColumn(),
        'active_deliveries' => (int) $pdo->query(
            "SELECT COUNT(*) FROM deliveries WHERE delivery_status IN ('Pending', 'Ongoing')"
        )->fetchColumn(),
        'stock_alerts' => (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level OR status IN ('Low Stock', 'Out of Stock')"
        )->fetchColumn(),
    ];

    $recentOrders = $pdo->query(
        'SELECT o.order_id, o.order_date, o.order_status, c.customer_name
         FROM orders o
         JOIN customers c ON c.customer_id = o.customer_id
         ORDER BY o.order_date DESC, o.order_id DESC
         LIMIT 5'
    )->fetchAll();

    $recentDeliveries = $pdo->query(
        'SELECT d.delivery_id, d.delivery_date, d.delivery_status, c.customer_name
         FROM deliveries d
         JOIN orders o ON o.order_id = d.order_id
         JOIN customers c ON c.customer_id = o.customer_id
         ORDER BY d.created_at DESC, d.delivery_id DESC
         LIMIT 4'
    )->fetchAll();

    $stockAlerts = $pdo->query(
        "SELECT item_name, quantity, unit, reorder_level
         FROM inventory
         WHERE quantity <= reorder_level OR status IN ('Low Stock', 'Out of Stock')
         ORDER BY quantity ASC, item_name ASC
         LIMIT 4"
    )->fetchAll();

} catch (Throwable $exception) {
    error_log('[AquaTrack staff dashboard] ' . $exception->getMessage());
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Dashboard unavailable | AquaTrack</title><link rel="stylesheet" href="../assets/css/staff.css"></head>
    <body class="error-body"><main class="error-card"><h1>Dashboard unavailable</h1>
    <p>Please check that XAMPP MySQL is running and the AquaTrack database settings are correct.</p>
    <a href="../auth/login.php">Back to login</a></main></body></html>
    <?php
    exit;
}

if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0e6073">
    <meta name="description" content="AquaTrack staff dashboard for Gaeven's Water Refilling Station.">
    <title>Staff Dashboard | AquaTrack</title>
    <link rel="stylesheet" href="../assets/css/staff.css">
    <!-- Reuse the working mobile sidebar JS from the Admin Dashboard. -->
    <script src="../assets/js/admin.js" defer></script>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

    <aside class="sidebar" id="adminSidebar" aria-label="Staff navigation">
        <a href="dashboard.php" class="brand" aria-label="AquaTrack dashboard">
            <span class="brand-drop" aria-hidden="true">
                <svg viewBox="0 0 42 42" fill="none">
                    <path d="M21 3S7 20 7 28a14 14 0 0 0 28 0C35 20 21 3 21 3Z" fill="currentColor"/>
                    <path d="M12 29c5-5 12 5 18-2" stroke="white" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="brand-text">AquaTrack<small>Water Station System</small></span>
        </a>

        <div class="station-chip"><span class="station-dot"></span> Gaeven's Water Station</div>
        <div class="side-heading">WORKSPACE</div>
        <nav class="nav-list" aria-label="Main menu">
            <a class="nav-item active" href="dashboard.php" aria-current="page">
                <span class="nav-icon" aria-hidden="true">▦</span> Dashboard
            </a>
            <div class="side-heading side-heading--nested">STATION MODULES</div>
            <?php if (in_array($role, ['Staff','Manager'], true)): ?><a class="nav-item" href="orders.php"><span class="nav-icon" aria-hidden="true">☷</span> My Orders</a><?php endif; ?>
            <?php if (in_array($role, ['Staff','Manager','Delivery Staff'], true)): ?><a class="nav-item" href="tasks.php"><span class="nav-icon" aria-hidden="true">⇢</span> Assigned Deliveries</a><?php endif; ?>
            <a class="nav-item" href="inventory.php"><span class="nav-icon" aria-hidden="true">▧</span> Inventory Stock</a>
            <?php if (in_array($role, ['Cashier','Manager'], true)): ?><a class="nav-item" href="payments.php"><span class="nav-icon" aria-hidden="true">◈</span> Cashier Payments</a><?php endif; ?>
            <a class="nav-item" href="profile.php"><span class="nav-icon" aria-hidden="true">◷</span> My Profile</a>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <span class="mini-avatar"><?= h(strtoupper(substr($firstName, 0, 1))) ?></span>
                <span><strong><?= h($fullName) ?></strong><small><?= h($role) ?></small></span>
            </div>
            <form method="post" action="../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['logout_csrf']) ?>">
                <button type="submit" class="signout-btn">Sign out <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                <span class="breadcrumb">AquaTrack <span>/</span> Staff Workspace</span>
            </div>
            <div class="topbar-right">
                <span class="today-label"><?= h(date('D, M j, Y')) ?></span>
                <span class="topbar-avatar" title="<?= h($role) ?>"><?= h(strtoupper(substr($firstName, 0, 1))) ?></span>
            </div>
        </header>

        <main class="content">
            <section class="page-title" aria-labelledby="welcome-title">
                <div>
                    <span class="eyebrow">STAFF WORKSPACE</span>
                    <h1 id="welcome-title">Hello, <?= h($firstName) ?> <span aria-hidden="true">👋</span></h1>
                    <p>Here’s your station overview for today.</p>
                </div>
                <span class="role-label"><?= h($role) ?> Account</span>
            </section>

            <section class="welcome-card" aria-label="Welcome to your workspace">
                <div class="welcome-text">
                    <span class="welcome-mini">GAEVEN'S WATER REFILLING STATION</span>
                    <h2>A smooth day starts here.</h2>
                    <p>Keep an eye on orders, deliveries, and supplies as your team serves every refill.</p>
                    <div class="quick-actions" aria-label="Staff actions">
                        <?php if (in_array($role, ['Staff','Manager'], true)): ?>
                            <a class="quick-action" href="orders.php">Manage Orders <span aria-hidden="true">→</span></a>
                        <?php endif; ?>
                        <?php if (in_array($role, ['Staff','Manager','Delivery Staff'], true)): ?>
                            <a class="quick-action quick-action--secondary" href="tasks.php">Manage Deliveries <span aria-hidden="true">→</span></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="water-art" aria-hidden="true">
                    <div class="ripple ripple-a"></div><div class="ripple ripple-b"></div>
                    <svg viewBox="0 0 160 160" fill="none"><path d="M80 16S35 68 35 99a45 45 0 0 0 90 0C125 68 80 16 80 16Z" fill="#B9EBEE"/><path d="M49 105c18-17 37 15 62-5" stroke="white" stroke-width="5" stroke-linecap="round"/></svg>
                </div>
            </section>

            <section aria-labelledby="overview-title" class="summary-section">
                <div class="section-header"><h2 id="overview-title">Station at a glance</h2><span>Live records</span></div>
                <div class="stats-grid">
                    <article class="stat-card">
                        <span class="stat-icon stat-icon--mint" aria-hidden="true">☷</span>
                        <div class="stat-name">Orders I've Recorded</div>
                        <strong class="stat-value"><?= number_format($stats['my_orders']) ?></strong>
                        <p>Your total recorded orders</p>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon stat-icon--blue" aria-hidden="true">◷</span>
                        <div class="stat-name">Open Orders</div>
                        <strong class="stat-value"><?= number_format($stats['open_orders']) ?></strong>
                        <p>Still being processed</p>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon stat-icon--peach" aria-hidden="true">⇢</span>
                        <div class="stat-name">Active Deliveries</div>
                        <strong class="stat-value"><?= number_format($stats['active_deliveries']) ?></strong>
                        <p>Pending or ongoing</p>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon stat-icon--rose" aria-hidden="true">!</span>
                        <div class="stat-name">Stock Alerts</div>
                        <strong class="stat-value"><?= number_format($stats['stock_alerts']) ?></strong>
                        <p>Items that may need restocking</p>
                    </article>
                </div>
            </section>

            <div class="two-column">
                <section class="panel" aria-labelledby="orders-title">
                    <div class="panel-header"><div><h2 id="orders-title">Recent Orders</h2><p>Latest customer orders at the station</p></div><span class="panel-tag">Latest 5</span></div>
                    <?php if ($recentOrders): ?>
                        <div class="table-scroll">
                            <table>
                                <thead><tr><th>Order</th><th>Customer</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td><strong>#<?= h($order['order_id']) ?></strong><small><?= h(niceDate($order['order_date'])) ?></small></td>
                                        <td><?= h($order['customer_name']) ?></td>
                                        <td><span class="status <?= h(pillClass((string) $order['order_status'])) ?>"><?= h($order['order_status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><div class="empty-icon">☷</div><strong>No orders recorded yet</strong><p>Orders will appear here once they are added to the system.</p></div>
                    <?php endif; ?>
                </section>

                <section class="panel" aria-labelledby="deliveries-title">
                    <div class="panel-header"><div><h2 id="deliveries-title">Recent Deliveries</h2><p>Updates on water deliveries</p></div><span class="panel-tag">Latest 4</span></div>
                    <?php if ($recentDeliveries): ?>
                        <div class="delivery-list">
                            <?php foreach ($recentDeliveries as $delivery): ?>
                                <div class="delivery-item">
                                    <span class="delivery-symbol" aria-hidden="true">⇢</span>
                                    <div class="delivery-description">
                                        <strong><?= h($delivery['customer_name']) ?></strong>
                                        <small>Delivery #<?= h($delivery['delivery_id']) ?> · <?= h(niceDate($delivery['delivery_date'])) ?></small>
                                    </div>
                                    <span class="status <?= h(pillClass((string) $delivery['delivery_status'])) ?>"><?= h($delivery['delivery_status']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><div class="empty-icon">⇢</div><strong>No deliveries recorded yet</strong><p>Delivery records will show up here after scheduling.</p></div>
                    <?php endif; ?>
                </section>
            </div>

            <section class="panel inventory-panel" aria-labelledby="inventory-title">
                <div class="panel-header"><div><h2 id="inventory-title">Inventory Watch</h2><p>Supplies that may need attention</p></div><span class="panel-tag">Up to 4 items</span></div>
                <?php if ($stockAlerts): ?>
                    <div class="stock-items">
                        <?php foreach ($stockAlerts as $item): ?>
                            <div class="stock-item"><span class="stock-alert-icon" aria-hidden="true">!</span>
                                <div><strong><?= h($item['item_name']) ?></strong><small>Available: <?= h($item['quantity']) ?> <?= h($item['unit'] ?: 'units') ?> · Reorder level: <?= h($item['reorder_level']) ?></small></div>
                                <span class="status <?= (int) $item['quantity'] <= 0 ? 'status--red' : 'status--amber' ?>"><?= (int) $item['quantity'] <= 0 ? 'Out of Stock' : 'Low Stock' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="inventory-ok"><span aria-hidden="true">✓</span> No low-stock supplies recorded right now.</div>
                <?php endif; ?>
            </section>
            <footer class="footer">AquaTrack · Gaeven's Water Refilling Station · IT 314 Project</footer>
        </main>
    </div>
</div>
</body>
</html>
