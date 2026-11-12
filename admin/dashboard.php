<?php
/**
 * AquaTrack Admin Dashboard
 * Gaeven's Water Refilling Station - Native PHP + MySQL
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

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Access denied. This page is for AquaTrack administrators only.');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function peso(mixed $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function readableDate(?string $value): string
{
    if (empty($value)) {
        return 'Not scheduled';
    }
    $time = strtotime($value);
    return $time === false ? '—' : date('M j, Y', $time);
}

function statusClass(string $status): string
{
    return match ($status) {
        'Completed', 'Paid', 'Available' => 'is-success',
        'Pending', 'Preparing', 'Partial', 'Low Stock', 'Confirmed' => 'is-warning',
        'Cancelled', 'Overdue', 'Unpaid', 'Out of Stock' => 'is-danger',
        default => 'is-info',
    };
}

try {
    $pdo = require __DIR__ . '/../config/database.php';

    // Confirm the account is still active and still an Admin.
    $check = $pdo->prepare(
        "SELECT user_id, full_name FROM users
         WHERE user_id = :id AND role = 'Admin' AND status = 'Active'
         LIMIT 1"
    );
    $check->execute(['id' => (int) $_SESSION['user_id']]);
    $admin = $check->fetch();

    if (!$admin) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }

    $fullName = (string) $admin['full_name'];
    $firstName = explode(' ', trim($fullName))[0] ?: 'Admin';
    $stats = [
        'customers' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'Active'")->fetchColumn(),
        'open_orders' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('Pending','Confirmed','Preparing','Out for Delivery')")->fetchColumn(),
        'deliveries' => (int) $pdo->query("SELECT COUNT(*) FROM deliveries WHERE delivery_status IN ('Pending','Ongoing')")->fetchColumn(),
        'low_stock' => (int) $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level OR status IN ('Low Stock','Out of Stock')")->fetchColumn(),
        'sales' => (float) $pdo->query("SELECT COALESCE(SUM(s.total_amount),0) FROM sales s JOIN orders o ON o.order_id=s.order_id WHERE o.order_status='Completed'")->fetchColumn(),
        'balance' => (float) $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM sales')->fetchColumn(),
        'active_staff' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'Admin' AND status = 'Active'")->fetchColumn(),
    ];

    $recentOrders = $pdo->query(
        'SELECT o.order_id, o.order_date, o.total_amount, o.order_status, c.customer_name
         FROM orders o
         INNER JOIN customers c ON c.customer_id = o.customer_id
         ORDER BY o.order_date DESC, o.order_id DESC
         LIMIT 5'
    )->fetchAll();

    $recentDeliveries = $pdo->query(
        'SELECT d.delivery_id, d.delivery_date, d.delivery_status, c.customer_name
         FROM deliveries d
         INNER JOIN orders o ON o.order_id = d.order_id
         INNER JOIN customers c ON c.customer_id = o.customer_id
         ORDER BY d.created_at DESC, d.delivery_id DESC
         LIMIT 5'
    )->fetchAll();

    $stockAlerts = $pdo->query(
        "SELECT item_name, quantity, unit, reorder_level, status
         FROM inventory
         WHERE quantity <= reorder_level OR status IN ('Low Stock','Out of Stock')
         ORDER BY quantity ASC, item_name ASC
         LIMIT 4"
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('[AquaTrack dashboard] ' . $exception->getMessage());
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>AquaTrack | Dashboard unavailable</title><link rel="stylesheet" href="../assets/css/admin.css"></head>
    <body class="error-page"><main class="error-panel"><h1>Dashboard temporarily unavailable</h1>
    <p>Check that MySQL is running and <code>aquatrack_db</code> is imported. If needed, review your database settings in <code>config/database.php</code>.</p>
    <a href="../auth/login.php">Return to login</a></main></body></html>
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0e6073">
    <title>Admin Dashboard | AquaTrack</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="../assets/js/admin.js" defer></script>
</head>
<body>
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
        <aside class="sidebar" id="adminSidebar" aria-label="Admin navigation">
            <a class="brand" href="dashboard.php">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 40 40" fill="none"><path d="M20 4S8 18.5 8 26a12 12 0 0 0 24 0C32 18.5 20 4 20 4Z" fill="currentColor"/><path d="M12 27c4-4 9 4 16-1" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg>
                </span>
                <span class="brand-name">AquaTrack<small>Water Station System</small></span>
            </a>

            <div class="sidebar-label">WORKSPACE</div>
            <nav class="sidebar-nav" aria-label="Modules">
<a class="nav-item active" href="dashboard.php" aria-current="page"><span class="nav-dot"></span> Dashboard</a>
<div class="sidebar-label sidebar-label-inner">MANAGEMENT</div>
<a class="nav-item" href="users/index.php"><span class="nav-dot"></span> Staff Accounts</a>
<a class="nav-item" href="customers/index.php"><span class="nav-dot"></span> Customers</a>
<a class="nav-item" href="orders/index.php"><span class="nav-dot"></span> Orders</a>
<a class="nav-item" href="deliveries/index.php"><span class="nav-dot"></span> Deliveries</a>
<a class="nav-item" href="inventory/index.php"><span class="nav-dot"></span> Inventory</a>
<a class="nav-item" href="payments/index.php"><span class="nav-dot"></span> Sales &amp; Payments</a>
<a class="nav-item" href="reports/index.php"><span class="nav-dot"></span> Reports &amp; Forecasting</a>
</nav>

            <div class="sidebar-bottom">
                <div class="station-label"><span class="station-pulse"></span> Gaeven's Water Station</div>
                <form method="post" action="../auth/logout.php">
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
                    <span class="breadcrumb">AquaTrack <span class="breadcrumb-separator">/</span> Admin</span>
                </div>
                <div class="profile"><span class="profile-avatar"><?= e(strtoupper(substr($firstName, 0, 1))) ?></span>
                    <span class="profile-name"><?= e($fullName) ?><small>Administrator</small></span>
                </div>
            </header>

            <main class="content">
                <div class="page-heading">
                    <div><span class="eyebrow">ADMIN OVERVIEW</span><h1>Good day, <?= e($firstName) ?>!</h1>
                        <p>Here's what's happening at Gaeven's Water Refilling Station.</p>
                    </div>
                    <div class="date-label" aria-label="Current date"><?= e(date('l, F j, Y')) ?></div>
                </div>

                <section class="welcome-banner" aria-label="Welcome">
                    <div><span class="banner-kicker">AQUATRACK MANAGEMENT</span><h2>Keep the station running smoothly.</h2>
                        <p>View orders, deliveries, supplies, and sales in one place.</p></div>
                    <svg class="banner-art" viewBox="0 0 180 160" fill="none" aria-hidden="true">
                        <circle cx="94" cy="84" r="72" stroke="#A5E5EB" stroke-width="1.5" opacity=".45"/>
                        <circle cx="94" cy="84" r="50" stroke="#A5E5EB" stroke-width="1.5" opacity=".45"/>
                        <path d="M94 33S58 74 58 98a36 36 0 1 0 72 0c0-24-36-65-36-65Z" fill="#8BDCE4" fill-opacity=".75" stroke="#D8FBFD" stroke-width="2"/>
                        <path d="M70 105c15-11 33 13 51-2" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </section>

                <section aria-labelledby="overview-heading">
                    <div class="section-title"><h2 id="overview-heading">Station overview</h2><span>Live database totals</span></div>
                    <div class="stats-grid">
                        <article class="stat-card"><div class="stat-head"><span>Active Customers</span><span class="stat-symbol">01</span></div>
                            <strong><?= number_format($stats['customers']) ?></strong><small>Customer records marked Active</small></article>
                        <article class="stat-card"><div class="stat-head"><span>Open Orders</span><span class="stat-symbol">02</span></div>
                            <strong><?= number_format($stats['open_orders']) ?></strong><small>Orders not yet completed</small></article>
                        <article class="stat-card"><div class="stat-head"><span>Active Deliveries</span><span class="stat-symbol">03</span></div>
                            <strong><?= number_format($stats['deliveries']) ?></strong><small>Pending or ongoing</small></article>
                        <article class="stat-card"><div class="stat-head"><span>Inventory Alerts</span><span class="stat-symbol">04</span></div>
                            <strong><?= number_format($stats['low_stock']) ?></strong><small>Low stock or out of stock</small></article>
                    </div>
                </section>

                <section class="summary-grid" aria-label="Other figures">
                    <div class="summary-card"><span>Recorded Sales</span><strong><?= e(peso($stats['sales'])) ?></strong><small>Total recorded in the sales table</small></div>
                    <div class="summary-card"><span>Outstanding Sales Balance</span><strong><?= e(peso($stats['balance'])) ?></strong><small>Unpaid balances from recorded sales</small></div>
                    <div class="summary-card"><span>Active Team Members</span><strong><?= number_format($stats['active_staff']) ?></strong><small>Non-admin accounts marked Active</small></div>
                </section>

                <div class="data-grid">
                    <section class="panel" aria-labelledby="orders-heading">
                        <div class="panel-heading"><div><h2 id="orders-heading">Recent Orders</h2><p>Latest customer transactions</p></div><span class="panel-pill">Last 5</span></div>
                        <?php if ($recentOrders): ?>
                            <div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead><tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                <tr><td><strong>#<?= e($order['order_id']) ?></strong><small><?= e(readableDate($order['order_date'])) ?></small></td>
                                    <td><?= e($order['customer_name']) ?></td><td><?= e(peso($order['total_amount'])) ?></td>
                                    <td><span class="status <?= e(statusClass((string) $order['order_status'])) ?>"><?= e($order['order_status']) ?></span></td></tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                        <?php else: ?>
                            <div class="empty-state"><span class="empty-mark">○</span><strong>No orders yet</strong><p>New orders will appear here after they are recorded.</p></div>
                        <?php endif; ?>
                    </section>

                    <section class="panel" aria-labelledby="deliveries-heading">
                        <div class="panel-heading"><div><h2 id="deliveries-heading">Recent Deliveries</h2><p>Delivery activity at a glance</p></div><span class="panel-pill">Last 5</span></div>
                        <?php if ($recentDeliveries): ?>
                            <div class="table-wrap"><table><thead><tr><th>Delivery</th><th>Customer</th><th>Schedule</th><th>Status</th></tr></thead><tbody>
                                <?php foreach ($recentDeliveries as $delivery): ?>
                                <tr><td><strong>#<?= e($delivery['delivery_id']) ?></strong></td><td><?= e($delivery['customer_name']) ?></td>
                                    <td><?= e(readableDate($delivery['delivery_date'])) ?></td>
                                    <td><span class="status <?= e(statusClass((string) $delivery['delivery_status'])) ?>"><?= e($delivery['delivery_status']) ?></span></td></tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                        <?php else: ?>
                            <div class="empty-state"><span class="empty-mark">↗</span><strong>No deliveries yet</strong><p>Scheduled deliveries will show up in this section.</p></div>
                        <?php endif; ?>
                    </section>
                </div>

                <section class="panel stock-panel" aria-labelledby="inventory-heading">
                    <div class="panel-heading"><div><h2 id="inventory-heading">Inventory Watch</h2><p>Supplies that may need restocking</p></div><span class="panel-pill">Up to 4 alerts</span></div>
                    <?php if ($stockAlerts): ?>
                        <div class="stock-list">
                            <?php foreach ($stockAlerts as $item):
                                $stockStatus = (int) $item['quantity'] <= 0
                                    ? 'Out of Stock'
                                    : ((int) $item['quantity'] <= (int) $item['reorder_level'] ? 'Low Stock' : (string) $item['status']);
                            ?>
                            <div class="stock-row"><span class="stock-marker">!</span><div><strong><?= e($item['item_name']) ?></strong>
                                <small>Current: <?= e($item['quantity']) ?> <?= e($item['unit'] ?: 'units') ?> · Reorder level: <?= e($item['reorder_level']) ?></small></div>
                                <span class="status <?= e(statusClass($stockStatus)) ?>"><?= e($stockStatus) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="stock-okay"><span aria-hidden="true">✓</span> No low-stock items recorded right now.</div>
                    <?php endif; ?>
                </section>

                <footer class="page-footer">AquaTrack · Gaeven's Water Refilling Station · IT 314 Project</footer>
            </main>
        </div>
    </div>
</body>
</html>
