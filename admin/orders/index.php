<?php
/** AquaTrack Admin — Order Management. Native PHP 8+ / PDO / existing aquatrack_db. */
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

session_name('aquatrack_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

function h(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money(mixed $value): string {
    return '₱' . number_format((float) $value, 2);
}
function dateLabel(string $value): string {
    $time = strtotime($value);
    return $time ? date('M j, Y · g:i A', $time) : '—';
}
function redirectOrders(): void {
    header('Location: index.php');
    exit;
}
// Convert DB DECIMAL(10,2) strings to integer cents to avoid float rounding.
function priceCents(string $value): int {
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value)) {
        throw new RuntimeException('Invalid product price in database.');
    }
    $parts = explode('.', $value, 2);
    return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
}
function centsAsDecimal(int $value): string {
    return intdiv($value, 100) . '.' . str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
}

$transitions = [
    'Pending' => ['Confirmed', 'Cancelled'],
    'Confirmed' => ['Preparing', 'Cancelled'],
    'Preparing' => ['Out for Delivery', 'Cancelled'],
    'Out for Delivery' => ['Completed', 'Cancelled'],
    'Completed' => [],
    'Cancelled' => [],
];
$error = '';
$success = (string) ($_SESSION['order_flash'] ?? '');
unset($_SESSION['order_flash']);
$_SESSION['order_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['logout_csrf'] ??= bin2hex(random_bytes(32));
$oldCustomer = (string) ($_POST['customer_id'] ?? '');
$oldProducts = (array) ($_POST['product_id'] ?? ['']);
$oldQuantities = (array) ($_POST['quantity'] ?? ['1']);

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../config/sales.php';
    $auth = $pdo->prepare(
        "SELECT user_id, full_name FROM users
         WHERE user_id = :id AND role = 'Admin' AND status = 'Active' LIMIT 1"
    );
    $auth->execute(['id' => (int) $_SESSION['user_id']]);
    $admin = $auth->fetch();
    if (!$admin) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../../auth/login.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_POST['csrf_token'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        if (!hash_equals((string) $_SESSION['order_csrf'], $csrf)) {
            throw new RuntimeException('Session expired. Refresh the page and try again.');
        }

        if ($action === 'create') {
            $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $productIds = $_POST['product_id'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            if (!$customerId || !is_array($productIds) || !is_array($quantities)
                || count($productIds) < 1 || count($productIds) > 15
                || count($productIds) !== count($quantities)) {
                throw new RuntimeException('Select a customer and 1–15 valid order items.');
            }

            $pdo->beginTransaction();
            $checkCustomer = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = :id AND status = 'Active' LIMIT 1");
            $checkCustomer->execute(['id' => $customerId]);
            if (!$checkCustomer->fetch()) {
                throw new RuntimeException('Please select an active customer.');
            }
            $getProduct = $pdo->prepare(
                "SELECT product_id, unit_price FROM products
                 WHERE product_id = :id AND status = 'Active' LIMIT 1"
            );
            $seen = [];
            $items = [];
            $totalCents = 0;
            foreach ($productIds as $i => $rawId) {
                if (!is_scalar($rawId) || !is_scalar($quantities[$i])) {
                    throw new RuntimeException('Invalid item information.');
                }
                $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $qty = filter_var($quantities[$i], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
                if (!$id || !$qty || isset($seen[$id])) {
                    throw new RuntimeException('Choose different products and quantities from 1 to 1000.');
                }
                $seen[$id] = true;
                $getProduct->execute(['id' => $id]);
                $product = $getProduct->fetch();
                if (!$product) {
                    throw new RuntimeException('A selected product is unavailable.');
                }
                $unitCents = priceCents((string) $product['unit_price']);
                $lineCents = $unitCents * $qty;
                $totalCents += $lineCents;
                if ($totalCents > 9999999999) {
                    throw new RuntimeException('Order total is too large.');
                }
                $items[] = [$id, $qty, $unitCents, $lineCents];
            }

            $insertOrder = $pdo->prepare(
                "INSERT INTO orders (customer_id, order_date, total_amount, order_status, created_by, created_at, updated_at)
                 VALUES (:customer, NOW(), :amount, 'Pending', :actor, NOW(), NOW())"
            );
            $insertOrder->execute([
                'customer' => $customerId,
                'amount' => centsAsDecimal($totalCents),
                'actor' => (int) $admin['user_id'],
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal, created_at, updated_at)
                 VALUES (:order_id, :product_id, :quantity, :unit_price, :subtotal, NOW(), NOW())'
            );
            foreach ($items as [$productId, $qty, $unitCents, $lineCents]) {
                $insertItem->execute([
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'unit_price' => centsAsDecimal($unitCents),
                    'subtotal' => centsAsDecimal($lineCents),
                ]);
            }
            $log = $pdo->prepare(
                "INSERT INTO activity_logs (user_id, action, table_name, record_id, log_date, created_at, updated_at)
                 VALUES (:actor, 'Created order', 'orders', :record, NOW(), NOW(), NOW())"
            );
            $log->execute(['actor' => (int) $admin['user_id'], 'record' => $orderId]);
            $pdo->commit();
            $_SESSION['order_flash'] = "Order #{$orderId} successfully created.";
            redirectOrders();

        } elseif ($action === 'status') {
            $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $newStatus = (string) ($_POST['new_status'] ?? '');
            if (!$orderId) {
                throw new RuntimeException('Invalid order selected.');
            }
            $pdo->beginTransaction();
            $find = $pdo->prepare('SELECT order_status FROM orders WHERE order_id = :id FOR UPDATE');
            $find->execute(['id' => $orderId]);
            $order = $find->fetch();
            if (!$order || !in_array($newStatus, $transitions[$order['order_status']] ?? [], true)) {
                throw new RuntimeException('Invalid status change for this order.');
            }
            // Do not cancel paid orders without a separate refund process.
            if ($newStatus === 'Cancelled') {
                $paid = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE order_id = :id');
                $paid->execute(['id'=>$orderId]);
                if ((int)$paid->fetchColumn() > 0) throw new RuntimeException('Paid orders require a refund workflow before cancellation.');
            }
            // Delivery completion takes precedence if a delivery exists.
            if ($newStatus === 'Completed') {
                $delivery = $pdo->prepare('SELECT delivery_status FROM deliveries WHERE order_id=:id ORDER BY delivery_id DESC LIMIT 1');
                $delivery->execute(['id'=>$orderId]);
                $status = $delivery->fetchColumn();
                if ($status !== false && $status !== 'Completed') throw new RuntimeException('Complete the scheduled delivery before completing this order.');
            }
            $update = $pdo->prepare('UPDATE orders SET order_status = :status, updated_at = NOW() WHERE order_id = :id');
            $update->execute(['status' => $newStatus, 'id' => $orderId]);
            if ($newStatus === 'Completed') at_ensure_sale($pdo, $orderId, (int)$admin['user_id']);
            $log = $pdo->prepare(
                "INSERT INTO activity_logs (user_id, action, table_name, record_id, log_date, created_at, updated_at)
                 VALUES (:actor, :action, 'orders', :record, NOW(), NOW(), NOW())"
            );
            $log->execute([
                'actor' => (int) $admin['user_id'],
                'action' => 'Updated order to ' . $newStatus,
                'record' => $orderId,
            ]);
            $pdo->commit();
            $_SESSION['order_flash'] = "Order #{$orderId} changed to {$newStatus}.";
            redirectOrders();
        } else {
            throw new RuntimeException('Unknown action.');
        }
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof RuntimeException && !($exception instanceof PDOException)) {
        $error = $exception->getMessage();
    } else {
        error_log('[AquaTrack orders] ' . $exception->getMessage());
        http_response_code(503);
        $error = 'Unable to load or save order data. Check MySQL and config/database.php.';
    }
}

// If the initial connection failed, do not try rendering data-dependent elements.
if (!isset($pdo, $admin) || !$admin) {
    http_response_code(503);
    exit(h($error ?: 'Database unavailable.'));
}

try {
    $customers = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status = 'Active' ORDER BY customer_name")->fetchAll();
    $products = $pdo->query("SELECT product_id, product_name, unit_price FROM products WHERE status = 'Active' ORDER BY product_name")->fetchAll();
    $statusFilter = (string) ($_GET['status'] ?? 'All');
    if ($statusFilter !== 'All' && !array_key_exists($statusFilter, $transitions)) {
        $statusFilter = 'All';
    }
    $search = substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
    $pageRaw = (string) ($_GET['page'] ?? '1');
    $page = ctype_digit($pageRaw) ? max(1, (int) $pageRaw) : 1;
    $perPage = 8;
    $where = ' WHERE (c.customer_name LIKE :name OR CAST(o.order_id AS CHAR) LIKE :number)';
    $params = ['name' => '%' . $search . '%', 'number' => '%' . $search . '%'];
    if ($statusFilter !== 'All') {
        $where .= ' AND o.order_status = :status';
        $params['status'] = $statusFilter;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM orders o JOIN customers c ON c.customer_id = o.customer_id' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $stmt = $pdo->prepare(
        'SELECT o.order_id, o.order_date, o.order_status, o.total_amount,
                c.customer_name, u.full_name AS recorded_by
         FROM orders o
         JOIN customers c ON c.customer_id = o.customer_id
         JOIN users u ON u.user_id = o.created_by' . $where .
        ' ORDER BY o.order_id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();

    $orderItems = [];
    if ($orders) {
        $ids = array_column($orders, 'order_id');
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $pdo->prepare(
            "SELECT oi.order_id, p.product_name, oi.quantity, oi.unit_price, oi.subtotal
             FROM order_items oi JOIN products p ON p.product_id = oi.product_id
             WHERE oi.order_id IN ({$slots}) ORDER BY oi.order_item_id"
        );
        $itemStmt->execute($ids);
        foreach ($itemStmt->fetchAll() as $item) {
            $orderItems[(int) $item['order_id']][] = $item;
        }
    }
    $counts = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(order_status = 'Pending'),0) AS pending,
                COALESCE(SUM(order_status IN ('Confirmed','Preparing','Out for Delivery')),0) AS processing,
                COALESCE(SUM(order_status = 'Completed'),0) AS completed
         FROM orders"
    )->fetch();
} catch (Throwable $exception) {
    error_log('[AquaTrack orders list] ' . $exception->getMessage());
    http_response_code(503);
    exit('Unable to display orders. Check the database schema.');
}

function orderListUrl(int $page, string $search, string $status): string {
    return 'index.php?' . http_build_query(['q' => $search, 'status' => $status, 'page' => $page]);
}
$fullName = (string) $admin['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#0e6073">
    <title>Orders | AquaTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/orders.css">
    <script src="../../assets/js/admin.js" defer></script>
    <script src="../../assets/js/orders.js" defer></script>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
    <aside class="sidebar" id="adminSidebar" aria-label="Admin navigation">
        <a class="brand" href="../dashboard.php">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 40 40" fill="none"><path d="M20 4S8 18.5 8 26a12 12 0 0 0 24 0C32 18.5 20 4 20 4Z" fill="currentColor"/><path d="M12 27c4-4 9 4 16-1" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg></span>
            <span class="brand-name">AquaTrack<small>Water Station System</small></span>
        </a>
        <div class="sidebar-label">WORKSPACE</div>
        <nav class="sidebar-nav" aria-label="Modules">
<a class="nav-item" href="../dashboard.php"><span class="nav-dot"></span> Dashboard</a>
<div class="sidebar-label sidebar-label-inner">MANAGEMENT</div>
<a class="nav-item" href="../users/index.php"><span class="nav-dot"></span> Staff Accounts</a>
<a class="nav-item" href="../customers/index.php"><span class="nav-dot"></span> Customers</a>
<a class="nav-item active" href="index.php" aria-current="page"><span class="nav-dot"></span> Orders</a>
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
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false"><span></span><span></span><span></span></button>
                <span class="breadcrumb">AquaTrack <span class="breadcrumb-separator">/</span> Admin <span class="breadcrumb-separator">/</span> Orders</span>
            </div>
            <div class="profile">
                <span class="profile-avatar"><?= h(strtoupper(substr($fullName, 0, 1))) ?></span>
                <span class="profile-name"><?= h($fullName) ?><small>Administrator</small></span>
            </div>
        </header>

        <main class="content orders-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">CUSTOMER TRANSACTIONS</span>
                    <h1>Order Management</h1>
                    <p>Record customer orders, view items, and update processing status.</p>
                </div>
                <a class="orders-back" href="../dashboard.php">← Dashboard</a>
            </div>

            <section class="orders-stats" aria-label="Order overview">
                <div class="orders-stat"><span>Total Orders</span><strong><?= number_format((int) $counts['total']) ?></strong></div>
                <div class="orders-stat"><span>Pending</span><strong><?= number_format((int) $counts['pending']) ?></strong></div>
                <div class="orders-stat"><span>In Progress</span><strong><?= number_format((int) $counts['processing']) ?></strong></div>
                <div class="orders-stat"><span>Completed</span><strong><?= number_format((int) $counts['completed']) ?></strong></div>
            </section>

            <?php if ($success !== ''): ?><div class="orders-alert good" role="status"><?= h($success) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="orders-alert bad" role="alert"><?= h($error) ?></div><?php endif; ?>

            <div class="orders-grid">
                <section class="orders-panel" id="new-order">
                    <div class="orders-panel-head">
                        <span class="orders-eyebrow">NEW TRANSACTION</span>
                        <h2>Create Order</h2>
                        <p>Prices come from Inventory → Sellable Items.</p>
                    </div>
                    <?php if (!$customers || !$products): ?>
                        <div class="orders-empty-message">Add at least one <strong>active customer</strong> and one <strong>active sellable item</strong> in Inventory before creating an order.</div>
                    <?php else: ?>
                    <form method="post" action="index.php#new-order" class="order-create-form" id="createOrderForm">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['order_csrf']) ?>">
                        <input type="hidden" name="action" value="create">
                        <label for="customer_id">Customer <span>*</span></label>
                        <select name="customer_id" id="customer_id" required>
                            <option value="">Select a customer</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['customer_id'] ?>" <?= $oldCustomer === (string) $customer['customer_id'] ? 'selected' : '' ?>><?= h($customer['customer_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label>Products and quantities <span>*</span></label>
                        <div class="order-lines" id="orderLines">
                            <?php foreach (array_slice($oldProducts, 0, 15) as $i => $oldProduct): ?>
                            <div class="order-line">
                                <select name="product_id[]" aria-label="Product" required>
                                    <option value="">Choose a product</option>
                                    <?php foreach ($products as $product): ?>
                                    <option value="<?= (int) $product['product_id'] ?>" data-price="<?= h($product['unit_price']) ?>" <?= (string) $oldProduct === (string) $product['product_id'] ? 'selected' : '' ?>><?= h($product['product_name']) ?> — <?= h(money($product['unit_price'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="quantity[]" aria-label="Quantity" min="1" max="1000" step="1" value="<?= h($oldQuantities[$i] ?? '1') ?>" required>
                                <button type="button" class="line-remove" aria-label="Remove item" title="Remove item">×</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="add-line" id="addOrderLine">+ Add another product</button>
                        <div class="order-preview-total"><span>Estimated Total</span><strong id="orderEstimatedTotal">₱0.00</strong></div>
                        <p class="order-hint">Final amount is recalculated securely from MySQL when saved. Maximum 15 different products.</p>
                        <button type="submit" class="order-submit">Save Order</button>
                    </form>
                    <template id="orderLineTemplate">
                        <div class="order-line">
                            <select name="product_id[]" aria-label="Product" required>
                                <option value="">Choose a product</option>
                                <?php foreach ($products as $product): ?>
                                <option value="<?= (int) $product['product_id'] ?>" data-price="<?= h($product['unit_price']) ?>"><?= h($product['product_name']) ?> — <?= h(money($product['unit_price'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="quantity[]" aria-label="Quantity" min="1" max="1000" value="1" required>
                            <button type="button" class="line-remove" aria-label="Remove item" title="Remove item">×</button>
                        </div>
                    </template>
                    <?php endif; ?>
                </section>

                <section class="orders-panel orders-records" id="order-records">
                    <div class="orders-panel-head">
                        <span class="orders-eyebrow">ORDER HISTORY</span>
                        <h2>Orders List</h2>
                        <p><?= number_format($total) ?> matching record<?= $total === 1 ? '' : 's' ?></p>
                    </div>
                    <form class="order-filters" method="get">
                        <label class="sr-only" for="q">Search orders</label>
                        <input type="search" id="q" name="q" value="<?= h($search) ?>" maxlength="80" placeholder="Customer name or order ID">
                        <label class="sr-only" for="status">Filter order status</label>
                        <select name="status" id="status">
                            <option value="All">All statuses</option>
                            <?php foreach (array_keys($transitions) as $status): ?>
                                <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Search</button>
                    </form>

                    <?php if ($orders): ?>
                    <div class="order-table-wrap">
                        <table class="order-table">
                            <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status / Update</th></tr></thead>
                            <tbody>
                            <?php foreach ($orders as $order):
                                $id = (int) $order['order_id'];
                                $allowed = $transitions[$order['order_status']] ?? [];
                            ?>
                            <tr>
                                <td><strong>#<?= $id ?></strong><small><?= h(dateLabel($order['order_date'])) ?></small></td>
                                <td><strong><?= h($order['customer_name']) ?></strong><small>By <?= h($order['recorded_by']) ?></small></td>
                                <td><?= h(money($order['total_amount'])) ?></td>
                                <td>
                                    <span class="order-status status-<?= h(strtolower(str_replace(' ', '-', $order['order_status']))) ?>"><?= h($order['order_status']) ?></span>
                                    <?php if ($allowed): ?>
                                    <form class="order-status-form" method="post" action="index.php#order-records">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['order_csrf']) ?>">
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="order_id" value="<?= $id ?>">
                                        <select name="new_status" aria-label="Update order #<?= $id ?>" required>
                                            <option value="">Next status...</option>
                                            <?php foreach ($allowed as $next): ?><option value="<?= h($next) ?>"><?= h($next) ?></option><?php endforeach; ?>
                                        </select>
                                        <button type="submit">Update</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="order-detail-row">
                                <td colspan="4">
                                    <details>
                                        <summary>View order #<?= $id ?> items</summary>
                                        <div class="order-detail-items">
                                            <?php foreach ($orderItems[$id] ?? [] as $item): ?>
                                            <div><span><?= h($item['product_name']) ?> × <?= (int) $item['quantity'] ?> <small>(<?= h(money($item['unit_price'])) ?> each)</small></span><strong><?= h(money($item['subtotal'])) ?></strong></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="orders-empty-message">No orders match your filters. New records will appear here after an order is created.</div>
                    <?php endif; ?>
                    <?php if ($pages > 1): ?>
                    <nav class="order-pagination" aria-label="Order pages">
                        <?php if ($page > 1): ?><a href="<?= h(orderListUrl($page - 1, $search, $statusFilter)) ?>">← Previous</a><?php endif; ?>
                        <span>Page <?= $page ?> of <?= $pages ?></span>
                        <?php if ($page < $pages): ?><a href="<?= h(orderListUrl($page + 1, $search, $statusFilter)) ?>">Next →</a><?php endif; ?>
                    </nav>
                    <?php endif; ?>
                </section>
            </div>
            <p class="orders-footnote">Order status updates do not create delivery records or record payments automatically. Those operations belong to their own modules.</p>
            <footer class="page-footer">AquaTrack · Gaeven's Water Refilling Station · IT 314 Project</footer>
        </main>
    </div>
</div>
</body>
</html>
