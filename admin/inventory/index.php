<?php
/**
 * AquaTrack - Unified Inventory Management
 * Admin-only module | Native PHP 8.0+ | Existing aquatrack_db
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

function productEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function productRedirect(): void
{
    header('Location: index.php');
    exit;
}

function productPrice($price): string
{
    return '₱' . number_format((float) $price, 2);
}

function productPageUrl(int $page, string $query, string $status): string
{
    return 'index.php?' . http_build_query([
        'q' => $query,
        'status' => $status,
        'page' => $page,
    ]);
}

if (empty($_SESSION['products_csrf'])) {
    $_SESSION['products_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

function inventoryStatus(int $quantity, int $reorder): string {
    if ($quantity === 0) return 'Out of Stock';
    if ($quantity <= $reorder) return 'Low Stock';
    return 'Available';
}
$error = '';
$flash = (string) ($_SESSION['products_flash'] ?? '');
unset($_SESSION['products_flash']);

$form = [
    'product_id' => 0,
    'product_name' => '',
    'description' => '',
    'unit_price' => '',
    'status' => 'Active',
];
$isEditing = false;

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    // Trust current database permissions rather than an old session role alone.
    $accountStatement = $pdo->prepare(
        "SELECT user_id, full_name, role, status
         FROM users WHERE user_id = :id LIMIT 1"
    );
    $accountStatement->execute(['id' => (int) $_SESSION['user_id']]);
    $admin = $accountStatement->fetch();

    if (!$admin || $admin['status'] !== 'Active') {
        $_SESSION = [];
        session_destroy();
        header('Location: ../../auth/login.php');
        exit;
    }
    if ($admin['role'] !== 'Admin') {
        http_response_code(403);
        exit('Access denied. Product Management is for Admin accounts only.');
    }
} catch (Throwable $exception) {
    error_log('[AquaTrack products database] ' . $exception->getMessage());
    http_response_code(503);
    exit('Database unavailable. Check XAMPP MySQL and config/database.php.');
}

$fullName = (string) $admin['full_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals((string) $_SESSION['products_csrf'], $token)) {
        $error = 'Session expired. Refresh the page and try again.';
    } elseif ($action === 'create' || $action === 'update') {
        $rawId = (string) ($_POST['product_id'] ?? '');
        $productId = ctype_digit($rawId) ? (int) $rawId : 0;
        $name = trim((string) ($_POST['product_name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priceInput = trim((string) ($_POST['unit_price'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'Active');
        $isEditing = ($action === 'update');

        $form = [
            'product_id' => $productId,
            'product_name' => $name,
            'description' => $description,
            'unit_price' => $priceInput,
            'status' => $status,
        ];

        if ($name === '' || strlen($name) > 100) {
            $error = 'Product name is required (maximum 100 characters).';
        } elseif (strlen($description) > 255) {
            $error = 'Description must be 255 characters or fewer.';
        } elseif (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $priceInput)
                  || (float) $priceInput <= 0) {
            $error = 'Enter a valid price above zero (up to ₱99,999,999.99).';
        } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
            $error = 'Invalid product status.';
        } elseif ($isEditing && $productId < 1) {
            $error = 'Please choose a valid product to edit.';
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                // Prevent accidental duplicates, including different casing.
                $duplicate = $pdo->prepare(
                    'SELECT product_id FROM products
                     WHERE product_name = :name AND product_id <> :id LIMIT 1'
                );
                $duplicate->execute([
                    'name' => $name,
                    'id' => $isEditing ? $productId : 0,
                ]);
                if ($duplicate->fetch()) {
                    throw new RuntimeException('A product with that name already exists.');
                }

                if ($isEditing) {
                    $existing = $pdo->prepare(
                        'SELECT product_id FROM products WHERE product_id = :id FOR UPDATE'
                    );
                    $existing->execute(['id' => $productId]);
                    if (!$existing->fetch()) {
                        throw new RuntimeException('Product not found. Refresh the page.');
                    }
                    $save = $pdo->prepare(
                        'UPDATE products
                         SET product_name = :name, description = :description,
                             unit_price = :price, status = :status, updated_at = NOW()
                         WHERE product_id = :id'
                    );
                    $save->execute([
                        'name' => $name,
                        'description' => $description !== '' ? $description : null,
                        'price' => $priceInput,
                        'status' => $status,
                        'id' => $productId,
                    ]);
                    $logAction = 'Updated product';
                    $message = 'Product updated successfully.';
                } else {
                    $save = $pdo->prepare(
                        'INSERT INTO products
                         (product_name, description, unit_price, status, created_at, updated_at)
                         VALUES (:name, :description, :price, :status, NOW(), NOW())'
                    );
                    $save->execute([
                        'name' => $name,
                        'description' => $description !== '' ? $description : null,
                        'price' => $priceInput,
                        'status' => $status,
                    ]);
                    $productId = (int) $pdo->lastInsertId();
                    $logAction = 'Created product';
                    $message = 'New product added successfully.';
                }

                $log = $pdo->prepare(
                    'INSERT INTO activity_logs
                     (user_id, action, table_name, record_id, log_date, created_at, updated_at)
                     VALUES (:user_id, :action, :table_name, :record_id, NOW(), NOW(), NOW())'
                );
                $log->execute([
                    'user_id' => (int) $admin['user_id'],
                    'action' => $logAction,
                    'table_name' => 'products',
                    'record_id' => $productId,
                ]);

                $pdo->commit();
                $_SESSION['products_flash'] = $message;
                productRedirect();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log('[AquaTrack product save] ' . $exception->getMessage());
                    $error = 'Unable to save the product. Please try again.';
                }
            }
        }
    } elseif ($action === 'toggle') {
        $rawId = (string) ($_POST['product_id'] ?? '');
        $productId = ctype_digit($rawId) ? (int) $rawId : 0;

        if ($productId < 1) {
            $error = 'Invalid product selected.';
        } else {
            try {
                $pdo->beginTransaction();
                $find = $pdo->prepare(
                    'SELECT status FROM products WHERE product_id = :id FOR UPDATE'
                );
                $find->execute(['id' => $productId]);
                $product = $find->fetch();
                if (!$product) {
                    throw new RuntimeException('Product no longer exists.');
                }

                $newStatus = $product['status'] === 'Active' ? 'Inactive' : 'Active';
                $change = $pdo->prepare(
                    'UPDATE products SET status = :status, updated_at = NOW()
                     WHERE product_id = :id'
                );
                $change->execute(['status' => $newStatus, 'id' => $productId]);

                $log = $pdo->prepare(
                    'INSERT INTO activity_logs
                     (user_id, action, table_name, record_id, log_date, created_at, updated_at)
                     VALUES (:user_id, :action, :table_name, :record_id, NOW(), NOW(), NOW())'
                );
                $log->execute([
                    'user_id' => (int) $admin['user_id'],
                    'action' => $newStatus === 'Active' ? 'Activated product' : 'Deactivated product',
                    'table_name' => 'products',
                    'record_id' => $productId,
                ]);

                $pdo->commit();
                $_SESSION['products_flash'] = 'Product marked ' . strtolower($newStatus) . '.';
                productRedirect();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log('[AquaTrack product status] ' . $exception->getMessage());
                    $error = 'Unable to update the product status.';
                }
            }
        }

    } elseif (in_array($action, ['inventory_create', 'inventory_edit', 'stock_in', 'stock_out', 'stock_adjust'], true)) {
        try {
            $pdo->beginTransaction();
            $inventoryId = filter_var($_POST['inventory_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($action === 'inventory_create' || $action === 'inventory_edit') {
                $name = trim((string)($_POST['item_name'] ?? ''));
                $type = (string)($_POST['item_type'] ?? '');
                $unit = trim((string)($_POST['unit'] ?? ''));
                $reorder = filter_var($_POST['reorder_level'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>100000000]]);
                if ($name === '' || strlen($name) > 100 || !in_array($type, ['Water Container','Raw Material','Supply','Other'], true)
                    || strlen($unit) > 20 || $reorder === false || $reorder === null) {
                    throw new RuntimeException('Check the item name, type, unit and reorder level.');
                }
                if ($action === 'inventory_edit') {
                    if (!$inventoryId) throw new RuntimeException('Invalid inventory item.');
                    $stmt = $pdo->prepare('SELECT inventory_id, quantity FROM inventory WHERE inventory_id=? FOR UPDATE');
                    $stmt->execute([$inventoryId]);
                    $existing = $stmt->fetch();
                    if (!$existing) throw new RuntimeException('Inventory item not found.');
                    $status = inventoryStatus((int)$existing['quantity'], (int)$reorder);
                    $stmt = $pdo->prepare('UPDATE inventory SET item_name=?, item_type=?, unit=?, reorder_level=?, status=?, updated_at=NOW() WHERE inventory_id=?');
                    $stmt->execute([$name,$type,$unit ?: null,$reorder,$status,$inventoryId]);
                    $label = 'Updated inventory item';
                } else {
                    $status = inventoryStatus(0, (int)$reorder);
                    $stmt = $pdo->prepare('INSERT INTO inventory (item_name,item_type,quantity,reorder_level,unit,status,created_at,updated_at) VALUES (?,?,0,?,?,?,NOW(),NOW())');
                    $stmt->execute([$name,$type,$reorder,$unit ?: null,$status]);
                    $inventoryId = (int)$pdo->lastInsertId();
                    $label = 'Created inventory item';
                }
            } else {
                if (!$inventoryId) throw new RuntimeException('Select a valid inventory item.');
                $amount = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>100000000]]);
                $reference = trim((string)($_POST['reference'] ?? ''));
                if ($amount === false || $amount === null || strlen($reference) > 100
                    || ($action !== 'stock_adjust' && $amount === 0)) {
                    throw new RuntimeException('Enter a valid quantity and reference (100 characters maximum).');
                }
                $stmt = $pdo->prepare('SELECT quantity,reorder_level FROM inventory WHERE inventory_id=? FOR UPDATE');
                $stmt->execute([$inventoryId]);
                $item = $stmt->fetch();
                if (!$item) throw new RuntimeException('Inventory item not found.');
                $before = (int)$item['quantity'];
                $after = match ($action) {
                    'stock_in' => $before + $amount,
                    'stock_out' => $before - $amount,
                    default => $amount,
                };
                if ($after < 0 || $after > 100000000) throw new RuntimeException('Not enough stock, or quantity exceeds allowed limit.');
                $kind = match ($action) {'stock_in'=>'Stock In','stock_out'=>'Stock Out',default=>'Adjustment'};
                $movementAmount = $action === 'stock_adjust' ? abs($after - $before) : $amount;
                if ($action === 'stock_adjust' && $after === $before) throw new RuntimeException('Adjustment must change the current quantity.');
                $stmt = $pdo->prepare('UPDATE inventory SET quantity=?, status=?, updated_at=NOW() WHERE inventory_id=?');
                $stmt->execute([$after,inventoryStatus($after,(int)$item['reorder_level']),$inventoryId]);
                $stmt = $pdo->prepare('INSERT INTO inventory_transactions (inventory_id,transaction_type,quantity,transaction_date,reference,recorded_by,created_at,updated_at) VALUES (?,?,?,NOW(),?,?,NOW(),NOW())');
                $stmt->execute([$inventoryId,$kind,$movementAmount,$reference ?: null,(int)$admin['user_id']]);
                $label = $kind . ' inventory';
            }
            $log = $pdo->prepare('INSERT INTO activity_logs (user_id,action,table_name,record_id,log_date,created_at,updated_at) VALUES (?,?,?, ?,NOW(),NOW(),NOW())');
            $log->execute([(int)$admin['user_id'],$label,'inventory',$inventoryId]);
            $pdo->commit();
            $_SESSION['products_flash'] = $label . ' successfully.';
            header('Location: index.php#supplies');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to update inventory. Check the server log.';
            if (!($exception instanceof RuntimeException)) error_log('[AquaTrack inventory] '.$exception->getMessage());
        }
    } else {
        $error = 'Unknown request.';
    }
}

// Load a product into the edit form when requested.
$rawEdit = (string) ($_GET['edit'] ?? '');
$editId = ctype_digit($rawEdit) ? (int) $rawEdit : 0;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editId > 0) {
    $edit = $pdo->prepare(
        'SELECT product_id, product_name, description, unit_price, status
         FROM products WHERE product_id = :id LIMIT 1'
    );
    $edit->execute(['id' => $editId]);
    $row = $edit->fetch();
    if ($row) {
        $form = $row;
        $isEditing = true;
    } else {
        $error = 'Product not found.';
    }
}

$search = substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$statusFilter = (string) ($_GET['status'] ?? 'All');
if (!in_array($statusFilter, ['All', 'Active', 'Inactive'], true)) {
    $statusFilter = 'All';
}
$rawPage = (string) ($_GET['page'] ?? '1');
$page = ctype_digit($rawPage) ? max(1, (int) $rawPage) : 1;
$perPage = 10;

try {
    $where = ' WHERE (product_name LIKE :name OR description LIKE :description)';
    $params = [
        'name' => '%' . $search . '%',
        'description' => '%' . $search . '%',
    ];
    if ($statusFilter !== 'All') {
        $where .= ' AND status = :status';
        $params['status'] = $statusFilter;
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM products' . $where);
    $count->execute($params);
    $filteredCount = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($filteredCount / $perPage));
    $page = min($page, $pages);

    $listing = $pdo->prepare(
        'SELECT product_id, product_name, description, unit_price, status, created_at
         FROM products' . $where .
        ' ORDER BY product_id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $listing->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $listing->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listing->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $listing->execute();
    $products = $listing->fetchAll();

    $stats = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'Active'), 0) AS active,
                COALESCE(SUM(status = 'Inactive'), 0) AS inactive
         FROM products"
    )->fetch();
} catch (Throwable $exception) {
    error_log('[AquaTrack products list] ' . $exception->getMessage());
    http_response_code(503);
    exit('Product records unavailable. Check the database.');
}

try {
    $inventoryItems = $pdo->query("SELECT inventory_id,item_name,item_type,quantity,reorder_level,unit,status FROM inventory ORDER BY item_name ASC")->fetchAll();
    $inventoryMovements = $pdo->query("SELECT t.transaction_date,t.transaction_type,t.quantity,t.reference,i.item_name,u.full_name
        FROM inventory_transactions t JOIN inventory i ON i.inventory_id=t.inventory_id
        LEFT JOIN users u ON u.user_id=t.recorded_by
        ORDER BY t.transaction_id DESC LIMIT 40")->fetchAll();
    $inventoryStats = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(status='Low Stock'),0) low, COALESCE(SUM(status='Out of Stock'),0) out_stock FROM inventory")->fetch();
} catch (Throwable $exception) {
    error_log('[AquaTrack inventory listing] '.$exception->getMessage());
    http_response_code(503);
    exit('Inventory records unavailable. Check the database.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0e6073">
    <title>Inventory Management | AquaTrack Admin</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/inventory.css">
    <script src="../../assets/js/admin.js" defer></script>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

    <!-- Same Admin Dashboard sidebar -->
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
<a class="nav-item" href="../customers/index.php"><span class="nav-dot"></span> Customers</a>
<a class="nav-item" href="../orders/index.php"><span class="nav-dot"></span> Orders</a>
<a class="nav-item" href="../deliveries/index.php"><span class="nav-dot"></span> Deliveries</a>
<a class="nav-item active" href="index.php" aria-current="page"><span class="nav-dot"></span> Inventory</a>
<a class="nav-item" href="../payments/index.php"><span class="nav-dot"></span> Sales &amp; Payments</a>
<a class="nav-item" href="../reports/index.php"><span class="nav-dot"></span> Reports &amp; Forecasting</a>
</nav>

        <div class="sidebar-bottom">
            <div class="station-label"><span class="station-pulse"></span>Gaeven's Water Station</div>
            <form method="post" action="../../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= productEscape($_SESSION['logout_csrf']) ?>">
                <button class="logout-btn" type="submit">Sign out <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </aside>

    <div class="main-area">
        <!-- Same Admin Dashboard topbar -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" type="button"
                        aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                <span class="breadcrumb">AquaTrack <span class="breadcrumb-separator">/</span> Admin
                    <span class="breadcrumb-separator">/</span> Inventory</span>
            </div>
            <div class="profile">
                <span class="profile-avatar"><?= productEscape(strtoupper(substr($fullName, 0, 1))) ?></span>
                <span class="profile-name"><?= productEscape($fullName) ?><small>Administrator</small></span>
            </div>
        </header>

        <main class="content product-page">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">STOCK &amp; SELLABLE ITEMS</span>
                    <h1>Inventory Management</h1>
                    <p>Manage sellable items, supplies, stock movements, and pricing in one place.</p>
                </div>
                <a class="product-back" href="../dashboard.php">← Dashboard</a>
            </div>

            <div class="product-overview">
                <div class="product-summary"><span>Sellable Items</span><strong><?= number_format((int) $stats['total']) ?></strong></div>
                <div class="product-summary"><span>Active Sellable Items</span><strong><?= number_format((int) $stats['active']) ?></strong></div>
                <div class="product-summary product-summary-note">
                    <span>Order Management</span>
                    <p>Sellable items are available to Orders; supplies are tracked below.</p>
                </div>
            </div>

            <?php if ($flash !== ''): ?>
                <div class="product-alert success" role="status"><?= productEscape($flash) ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="product-alert danger" role="alert"><?= productEscape($error) ?></div>
            <?php endif; ?>

            <div class="inventory-anchor"><a href="#sellable">Sellable Items &amp; Prices</a><a href="#supplies">Supplies &amp; Stock</a><a href="#movements">Movement History</a></div>
            <div class="product-work-grid" id="sellable">
                <section class="panel product-editor" id="product-form">
                    <div class="product-panel-header">
                        <div>
                            <span class="product-eyebrow"><?= $isEditing ? 'UPDATE PRODUCT' : 'NEW PRODUCT' ?></span>
                            <h2><?= $isEditing ? 'Edit Product' : 'Add Product' ?></h2>
                        </div>
                        <?php if ($isEditing): ?><a href="index.php" class="product-cancel">Cancel edit</a><?php endif; ?>
                    </div>

                    <form class="product-form" method="post" action="index.php">
                        <input type="hidden" name="csrf_token" value="<?= productEscape($_SESSION['products_csrf']) ?>">
                        <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                        <?php if ($isEditing): ?>
                            <input type="hidden" name="product_id" value="<?= (int) $form['product_id'] ?>">
                        <?php endif; ?>

                        <label for="product_name">Product Name <span>*</span></label>
                        <input id="product_name" name="product_name" maxlength="100" required
                               placeholder="e.g. 5-Gallon Water Refill"
                               value="<?= productEscape($form['product_name']) ?>">

                        <label for="description">Description <small>(optional)</small></label>
                        <textarea id="description" name="description" maxlength="255" rows="3"
                                  placeholder="Short description of the product"><?= productEscape($form['description'] ?? '') ?></textarea>

                        <label for="unit_price">Selling Price (PHP) <span>*</span></label>
                        <div class="product-price-field">
                            <span aria-hidden="true">₱</span>
                            <input id="unit_price" name="unit_price" type="number" min="0.01" max="99999999.99"
                                   step="0.01" inputmode="decimal" required placeholder="0.00"
                                   value="<?= productEscape($form['unit_price']) ?>">
                        </div>

                        <label for="status">Availability</label>
                        <select id="status" name="status">
                            <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active (available for orders)</option>
                            <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive (hidden from new orders)</option>
                        </select>

                        <button class="product-save" type="submit">
                            <?= $isEditing ? 'Save Changes' : 'Add Product' ?>
                        </button>
                        <p class="product-form-note">Prices are used for new orders. Past order item prices stay saved.</p>
                    </form>
                </section>

                <section class="panel product-records">
                    <div class="product-panel-header">
                        <div><span class="product-eyebrow">SELLABLE ITEMS</span><h2>Product List</h2></div>
                        <span class="product-record-count"><?= number_format($filteredCount) ?> found</span>
                    </div>

                    <form class="product-filters" method="get" action="index.php">
                        <label for="q" class="product-sr-only">Search products</label>
                        <input id="q" name="q" maxlength="80" placeholder="Search product or description..."
                               value="<?= productEscape($search) ?>">
                        <label for="status_filter" class="product-sr-only">Filter product status</label>
                        <select id="status_filter" name="status">
                            <?php foreach (['All', 'Active', 'Inactive'] as $option): ?>
                                <option value="<?= productEscape($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= productEscape($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="product-search-button" type="submit">Search</button>
                    </form>

                    <?php if ($products): ?>
                        <div class="product-table-wrap">
                            <table>
                                <thead><tr><th>Product</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td>
                                            <strong><?= productEscape($product['product_name']) ?></strong>
                                            <small><?= productEscape($product['description'] ?: 'No description') ?></small>
                                        </td>
                                        <td class="product-price"><?= productEscape(productPrice($product['unit_price'])) ?></td>
                                        <td><span class="product-pill <?= $product['status'] === 'Active' ? 'active' : 'inactive' ?>">
                                            <?= productEscape($product['status']) ?>
                                        </span></td>
                                        <td>
                                            <div class="product-actions">
                                                <a href="index.php?edit=<?= (int) $product['product_id'] ?>#product-form">Edit</a>
                                                <form method="post" action="index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= productEscape($_SESSION['products_csrf']) ?>">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                                                    <button class="product-toggle" type="submit">
                                                        <?= $product['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="product-empty">
                            <span aria-hidden="true">◌</span>
                            <strong>No products found</strong>
                            <p>Add your first product or change the search filters.</p>
                        </div>
                    <?php endif; ?>

                    <?php if ($pages > 1): ?>
                        <nav class="product-pagination" aria-label="Product pages">
                            <?php if ($page > 1): ?>
                                <a href="<?= productEscape(productPageUrl($page - 1, $search, $statusFilter)) ?>">← Previous</a>
                            <?php endif; ?>
                            <span>Page <?= $page ?> of <?= $pages ?></span>
                            <?php if ($page < $pages): ?>
                                <a href="<?= productEscape(productPageUrl($page + 1, $search, $statusFilter)) ?>">Next →</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>
            </div>


            <section id="supplies" class="inventory-section">
                <div class="inventory-section-heading">
                    <div><span class="product-eyebrow">STOCK CONTROL</span><h2>Supplies &amp; Inventory</h2><p>Track containers, caps, seals, raw materials, and other stock.</p></div>
                    <div class="inventory-kpis"><span><?= (int)$inventoryStats['total'] ?> items</span><span><?= (int)$inventoryStats['low'] ?> low</span><span><?= (int)$inventoryStats['out_stock'] ?> out of stock</span></div>
                </div>
                <div class="product-work-grid">
                    <section class="panel product-editor">
                        <div class="product-panel-header"><div><span class="product-eyebrow">INVENTORY ITEM</span><h2>Add / Edit Item</h2></div></div>
                        <form class="product-form" method="post" action="index.php#supplies">
                            <input type="hidden" name="csrf_token" value="<?= productEscape($_SESSION['products_csrf']) ?>">
                            <input type="hidden" name="action" value="inventory_create" id="inventoryAction">
                            <input type="hidden" name="inventory_id" value="" id="inventoryEditId">
                            <label for="itemName">Item Name *</label><input id="itemName" name="item_name" maxlength="100" required placeholder="e.g. Bottle Caps">
                            <label for="itemType">Item Type *</label><select id="itemType" name="item_type" required>
                                <?php foreach (['Water Container','Raw Material','Supply','Other'] as $type): ?><option value="<?= productEscape($type) ?>"><?= productEscape($type) ?></option><?php endforeach; ?>
                            </select>
                            <label for="itemUnit">Unit</label><input id="itemUnit" name="unit" maxlength="20" placeholder="pcs, boxes, liters">
                            <label for="reorderLevel">Reorder Level *</label><input id="reorderLevel" name="reorder_level" type="number" min="0" max="100000000" value="10" required>
                            <button class="product-save" type="submit" id="inventorySave">Add Inventory Item</button>
                            <button class="inventory-reset" type="button" id="inventoryReset" hidden>Cancel Edit</button>
                            <p class="product-form-note">Initial stock is 0. Use Stock In to record starting quantities with a history entry.</p>
                        </form>
                    </section>
                    <section class="panel product-records">
                        <div class="product-panel-header"><div><span class="product-eyebrow">STOCK LEVELS</span><h2>Inventory List</h2></div></div>
                        <div class="product-table-wrap"><table class="inventory-table">
                            <thead><tr><th>Item</th><th>Stock</th><th>Reorder</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($inventoryItems as $item): ?>
                            <tr>
                                <td><strong><?= productEscape($item['item_name']) ?></strong><small><?= productEscape($item['item_type']) ?></small></td>
                                <td><?= number_format((int)$item['quantity']) ?> <?= productEscape($item['unit'] ?? '') ?></td>
                                <td><?= number_format((int)$item['reorder_level']) ?></td>
                                <td><span class="inventory-status <?= $item['status']==='Available'?'ok':($item['status']==='Low Stock'?'low':'out') ?>"><?= productEscape($item['status']) ?></span></td>
                                <td><button class="inventory-edit-btn" type="button"
                                    data-id="<?= (int)$item['inventory_id'] ?>"
                                    data-name="<?= productEscape($item['item_name']) ?>"
                                    data-type="<?= productEscape($item['item_type']) ?>"
                                    data-unit="<?= productEscape($item['unit'] ?? '') ?>"
                                    data-reorder="<?= (int)$item['reorder_level'] ?>">Edit</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$inventoryItems): ?><tr><td colspan="5" class="inventory-empty">No inventory items yet. Add your first supply on the left.</td></tr><?php endif; ?>
                            </tbody>
                        </table></div>
                    </section>
                </div>
                <section class="panel inventory-movement-panel">
                    <div class="product-panel-header"><div><span class="product-eyebrow">QUANTITY TRACKING</span><h2>Record Stock Movement</h2></div></div>
                    <form class="inventory-movement-form" method="post" action="index.php#supplies">
                        <input type="hidden" name="csrf_token" value="<?= productEscape($_SESSION['products_csrf']) ?>">
                        <label>Inventory Item<select name="inventory_id" required>
                            <option value="">Select item</option>
                            <?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['inventory_id'] ?>"><?= productEscape($item['item_name']) ?> (<?= (int)$item['quantity'] ?> <?= productEscape($item['unit'] ?? '') ?>)</option><?php endforeach; ?>
                        </select></label>
                        <label>Movement Type<select name="action" id="movementType" required>
                            <option value="stock_in">Stock In (+)</option><option value="stock_out">Stock Out (-)</option><option value="stock_adjust">Set Exact Stock (Adjustment)</option>
                        </select></label>
                        <label><span id="movementQuantityLabel">Quantity</span><input type="number" name="quantity" min="1" max="100000000" required></label>
                        <label>Reference (optional)<input name="reference" maxlength="100" placeholder="e.g. Delivery receipt #"></label>
                        <button class="product-save" type="submit">Save Movement</button>
                    </form>
                    <p class="inventory-hint">Stock Out cannot exceed available quantity. Adjustments record the absolute change in movement history.</p>
                </section>
            </section>
            <section id="movements" class="panel inventory-history">
                <div class="product-panel-header"><div><span class="product-eyebrow">AUDIT TRAIL</span><h2>Recent Stock Movements</h2></div></div>
                <div class="product-table-wrap"><table class="inventory-table">
                    <thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Quantity Changed</th><th>Reference</th><th>Recorded By</th></tr></thead>
                    <tbody>
                    <?php foreach ($inventoryMovements as $movement): ?>
                    <tr><td><?= productEscape(date('M j, Y g:i A',strtotime($movement['transaction_date']))) ?></td>
                        <td><?= productEscape($movement['item_name']) ?></td><td><?= productEscape($movement['transaction_type']) ?></td>
                        <td><?= number_format((int)$movement['quantity']) ?></td><td><?= productEscape($movement['reference'] ?? '—') ?></td>
                        <td><?= productEscape($movement['full_name'] ?? '—') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$inventoryMovements): ?><tr><td colspan="6" class="inventory-empty">No stock movements recorded yet.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </section>
            <footer class="product-footer">AquaTrack · Gaeven's Water Refilling Station · IT 314 Project</footer>
        </main>
    </div>
</div>
<script src="../../assets/js/inventory.js" defer></script>
</body>
</html>
