<?php
/**
 * Work Order Management System
 * Simple PHP app for managing list rental work orders
 */

session_start();
require_once __DIR__ . '/config.php';

$pdo = getDbConnection();
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleFormSubmission($pdo);
}

// Route to appropriate view
switch ($action) {
    case 'create':
        showForm($pdo);
        break;
    case 'edit':
        showForm($pdo, $id);
        break;
    case 'view':
        showOrder($pdo, $id);
        break;
    case 'list':
    default:
        showOrderList($pdo);
        break;
}

/**
 * Handle form submissions (create, update, status change)
 */
function handleFormSubmission(PDO $pdo): void
{
    $formAction = $_POST['form_action'] ?? '';

    switch ($formAction) {
        case 'create':
            createOrder($pdo);
            break;
        case 'update':
            updateOrder($pdo);
            break;
        case 'change_status':
            changeStatus($pdo);
            break;
    }
}

/**
 * Create a new work order
 */
function createOrder(PDO $pdo): void
{
    $stmt = $pdo->prepare('
        INSERT INTO public.orders
        (customer_id, database_id, external_ref, list_description, desired_quantity, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $_POST['customer_id'],
        $_POST['database_id'],
        $_POST['external_ref'] ?: null,
        $_POST['list_description'],
        $_POST['desired_quantity'],
        'open'
    ]);

    setFlashMessage('success', 'Work order created successfully.');
    header('Location: index.php');
    exit;
}

/**
 * Update an existing work order
 */
function updateOrder(PDO $pdo): void
{
    $stmt = $pdo->prepare('
        UPDATE public.orders SET
            customer_id = ?,
            database_id = ?,
            external_ref = ?,
            list_description = ?,
            desired_quantity = ?,
            actual_quantity = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');

    $actualQty = $_POST['actual_quantity'] !== '' ? $_POST['actual_quantity'] : null;

    $stmt->execute([
        $_POST['customer_id'],
        $_POST['database_id'],
        $_POST['external_ref'] ?: null,
        $_POST['list_description'],
        $_POST['desired_quantity'],
        $actualQty,
        $_POST['order_id']
    ]);

    setFlashMessage('success', 'Work order updated successfully.');
    header('Location: index.php?action=view&id=' . $_POST['order_id']);
    exit;
}

/**
 * Change order status
 */
function changeStatus(PDO $pdo): void
{
    $newStatus = $_POST['new_status'];
    $orderId = $_POST['order_id'];

    $closedAt = in_array($newStatus, ['fulfilled', 'closed']) ? 'CURRENT_TIMESTAMP' : 'NULL';

    $stmt = $pdo->prepare("
        UPDATE public.orders SET
            status = ?,
            closed_at = $closedAt,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmt->execute([$newStatus, $orderId]);

    $statusLabels = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'fulfilled' => 'Fulfilled',
        'closed' => 'Closed/Billed'
    ];

    setFlashMessage('success', 'Status changed to: ' . $statusLabels[$newStatus]);
    header('Location: index.php?action=view&id=' . $orderId);
    exit;
}

/**
 * Get all customers for dropdown
 */
function getCustomers(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM public.customers ORDER BY name')->fetchAll();
}

/**
 * Get all databases for dropdown
 */
function getDatabases(PDO $pdo): array
{
    return $pdo->query('SELECT id, list_code, name FROM public.databases ORDER BY name')->fetchAll();
}

/**
 * Get a single order by ID
 */
function getOrder(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT o.*, c.name as customer_name, d.name as database_name, d.list_code
        FROM public.orders o
        JOIN public.customers c ON o.customer_id = c.id
        JOIN public.databases d ON o.database_id = d.id
        WHERE o.id = ?
    ');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Show order list
 */
function showOrderList(PDO $pdo): void
{
    $statusFilter = $_GET['status'] ?? '';

    $sql = '
        SELECT o.*, c.name as customer_name, d.name as database_name, d.list_code
        FROM public.orders o
        JOIN public.customers c ON o.customer_id = c.id
        JOIN public.databases d ON o.database_id = d.id
    ';

    if ($statusFilter) {
        $sql .= ' WHERE o.status = ?';
        $stmt = $pdo->prepare($sql . ' ORDER BY o.created_at DESC');
        $stmt->execute([$statusFilter]);
    } else {
        $stmt = $pdo->query($sql . ' ORDER BY o.created_at DESC');
    }

    $orders = $stmt->fetchAll();
    $flash = getFlashMessage();

    renderHeader('Work Orders');
    ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="index.php?action=create" class="btn btn-primary">+ New Work Order</a>
        <div class="filters">
            <span>Filter:</span>
            <a href="index.php" class="<?= !$statusFilter ? 'active' : '' ?>">All</a>
            <a href="index.php?status=open" class="<?= $statusFilter === 'open' ? 'active' : '' ?>">Open</a>
            <a href="index.php?status=in_progress" class="<?= $statusFilter === 'in_progress' ? 'active' : '' ?>">In Progress</a>
            <a href="index.php?status=fulfilled" class="<?= $statusFilter === 'fulfilled' ? 'active' : '' ?>">Fulfilled</a>
            <a href="index.php?status=closed" class="<?= $statusFilter === 'closed' ? 'active' : '' ?>">Closed</a>
        </div>
    </div>

    <?php if (empty($orders)): ?>
        <p class="empty-state">No work orders found. <a href="index.php?action=create">Create one</a>.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>WO#</th>
                    <th>Customer</th>
                    <th>List Provider</th>
                    <th>External Ref</th>
                    <th>Desired Qty</th>
                    <th>Actual Qty</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><strong><?= $order['id'] ?></strong></td>
                        <td><?= htmlspecialchars($order['customer_name']) ?></td>
                        <td><?= htmlspecialchars($order['list_code']) ?> - <?= htmlspecialchars($order['database_name']) ?></td>
                        <td><?= htmlspecialchars($order['external_ref'] ?? '-') ?></td>
                        <td class="number"><?= number_format($order['desired_quantity']) ?></td>
                        <td class="number"><?= $order['actual_quantity'] !== null ? number_format($order['actual_quantity']) : '-' ?></td>
                        <td><span class="status status-<?= $order['status'] ?>"><?= formatStatus($order['status']) ?></span></td>
                        <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
                        <td>
                            <a href="index.php?action=view&id=<?= $order['id'] ?>" class="btn btn-sm">View</a>
                            <a href="index.php?action=edit&id=<?= $order['id'] ?>" class="btn btn-sm">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
    renderFooter();
}

/**
 * Show single order view
 */
function showOrder(PDO $pdo, ?int $id): void
{
    if (!$id) {
        header('Location: index.php');
        exit;
    }

    $order = getOrder($pdo, $id);
    if (!$order) {
        setFlashMessage('error', 'Order not found.');
        header('Location: index.php');
        exit;
    }

    $flash = getFlashMessage();

    renderHeader('Work Order #' . $order['id']);
    ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="index.php" class="btn">&larr; Back to List</a>
        <a href="index.php?action=edit&id=<?= $order['id'] ?>" class="btn btn-primary">Edit Order</a>
    </div>

    <div class="order-detail">
        <div class="detail-grid">
            <div class="detail-item">
                <label>Work Order #</label>
                <span><?= $order['id'] ?></span>
            </div>
            <div class="detail-item">
                <label>Status</label>
                <span class="status status-<?= $order['status'] ?>"><?= formatStatus($order['status']) ?></span>
            </div>
            <div class="detail-item">
                <label>Customer</label>
                <span><?= htmlspecialchars($order['customer_name']) ?></span>
            </div>
            <div class="detail-item">
                <label>List Provider</label>
                <span><?= htmlspecialchars($order['list_code']) ?> - <?= htmlspecialchars($order['database_name']) ?></span>
            </div>
            <div class="detail-item">
                <label>External Reference</label>
                <span><?= htmlspecialchars($order['external_ref'] ?? 'N/A') ?></span>
            </div>
            <div class="detail-item">
                <label>Desired Quantity</label>
                <span class="number"><?= number_format($order['desired_quantity']) ?></span>
            </div>
            <div class="detail-item">
                <label>Actual Quantity</label>
                <span class="number <?= $order['actual_quantity'] !== null && $order['actual_quantity'] < $order['desired_quantity'] ? 'text-warning' : '' ?>">
                    <?= $order['actual_quantity'] !== null ? number_format($order['actual_quantity']) : 'Not yet fulfilled' ?>
                </span>
            </div>
            <div class="detail-item full-width">
                <label>List Description</label>
                <span><?= nl2br(htmlspecialchars($order['list_description'])) ?></span>
            </div>
            <div class="detail-item">
                <label>Created</label>
                <span><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></span>
            </div>
            <div class="detail-item">
                <label>Last Updated</label>
                <span><?= date('M j, Y g:i A', strtotime($order['updated_at'])) ?></span>
            </div>
            <?php if ($order['closed_at']): ?>
            <div class="detail-item">
                <label>Closed</label>
                <span><?= date('M j, Y g:i A', strtotime($order['closed_at'])) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="status-actions">
            <h3>Change Status</h3>
            <form method="POST" class="status-form">
                <input type="hidden" name="form_action" value="change_status">
                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">

                <?php if ($order['status'] === 'open'): ?>
                    <button type="submit" name="new_status" value="in_progress" class="btn">Mark In Progress</button>
                <?php endif; ?>

                <?php if ($order['status'] === 'in_progress'): ?>
                    <button type="submit" name="new_status" value="open" class="btn">Reopen</button>
                    <button type="submit" name="new_status" value="fulfilled" class="btn btn-success">Mark Fulfilled</button>
                <?php endif; ?>

                <?php if ($order['status'] === 'fulfilled'): ?>
                    <button type="submit" name="new_status" value="in_progress" class="btn">Back to In Progress</button>
                    <button type="submit" name="new_status" value="closed" class="btn btn-primary">Close &amp; Bill</button>
                <?php endif; ?>

                <?php if ($order['status'] === 'closed'): ?>
                    <button type="submit" name="new_status" value="fulfilled" class="btn">Reopen (Unbill)</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php
    renderFooter();
}

/**
 * Show create/edit form
 */
function showForm(PDO $pdo, ?int $id = null): void
{
    $order = null;
    $isEdit = false;

    if ($id) {
        $order = getOrder($pdo, $id);
        if (!$order) {
            setFlashMessage('error', 'Order not found.');
            header('Location: index.php');
            exit;
        }
        $isEdit = true;
    }

    $customers = getCustomers($pdo);
    $databases = getDatabases($pdo);

    renderHeader($isEdit ? 'Edit Work Order #' . $order['id'] : 'New Work Order');
    ?>

    <div class="toolbar">
        <a href="index.php" class="btn">&larr; Back to List</a>
    </div>

    <form method="POST" class="order-form">
        <input type="hidden" name="form_action" value="<?= $isEdit ? 'update' : 'create' ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="customer_id">Customer (Renting Organization) *</label>
            <select name="customer_id" id="customer_id" required>
                <option value="">Select a customer...</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= $customer['id'] ?>" <?= ($order && $order['customer_id'] == $customer['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($customer['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="database_id">List Provider (Source Database) *</label>
            <select name="database_id" id="database_id" required>
                <option value="">Select a list provider...</option>
                <?php foreach ($databases as $db): ?>
                    <option value="<?= $db['id'] ?>" <?= ($order && $order['database_id'] == $db['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($db['list_code']) ?> - <?= htmlspecialchars($db['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="external_ref">External Reference / PO Number</label>
            <input type="text" name="external_ref" id="external_ref"
                   value="<?= htmlspecialchars($order['external_ref'] ?? '') ?>"
                   placeholder="e.g., SJ-04771">
        </div>

        <div class="form-group full-width">
            <label for="list_description">List Description / Selection Criteria *</label>
            <textarea name="list_description" id="list_description" rows="4" required
                      placeholder="Describe the target audience and selection criteria..."><?= htmlspecialchars($order['list_description'] ?? '') ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="desired_quantity">Desired Quantity *</label>
                <input type="number" name="desired_quantity" id="desired_quantity" min="0" required
                       value="<?= $order['desired_quantity'] ?? '' ?>"
                       placeholder="e.g., 55000">
            </div>

            <?php if ($isEdit): ?>
            <div class="form-group">
                <label for="actual_quantity">Actual Quantity</label>
                <input type="number" name="actual_quantity" id="actual_quantity" min="0"
                       value="<?= $order['actual_quantity'] ?? '' ?>"
                       placeholder="Fill after fulfillment">
            </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update Work Order' : 'Create Work Order' ?></button>
            <a href="index.php" class="btn">Cancel</a>
        </div>
    </form>

    <?php
    renderFooter();
}

/**
 * Format status for display
 */
function formatStatus(string $status): string
{
    return match ($status) {
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'fulfilled' => 'Fulfilled',
        'closed' => 'Closed/Billed',
        default => ucfirst($status)
    };
}

/**
 * Render page header
 */
function renderHeader(string $title): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> - Work Order System</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.5; }
            .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
            header { background: #2c3e50; color: white; padding: 20px 0; margin-bottom: 30px; }
            header h1 { max-width: 1200px; margin: 0 auto; padding: 0 20px; font-size: 1.5rem; }

            .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; }
            .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

            .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
            .filters { display: flex; gap: 10px; align-items: center; }
            .filters a { color: #666; text-decoration: none; padding: 4px 8px; border-radius: 4px; }
            .filters a:hover, .filters a.active { background: #2c3e50; color: white; }

            .btn { display: inline-block; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; background: #e0e0e0; color: #333; }
            .btn:hover { background: #d0d0d0; }
            .btn-primary { background: #3498db; color: white; }
            .btn-primary:hover { background: #2980b9; }
            .btn-success { background: #27ae60; color: white; }
            .btn-success:hover { background: #219a52; }
            .btn-sm { padding: 4px 10px; font-size: 12px; }

            table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #eee; }
            th { background: #f8f9fa; font-weight: 600; color: #555; }
            tr:hover { background: #f8f9fa; }
            .number { text-align: right; font-variant-numeric: tabular-nums; }

            .status { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
            .status-open { background: #e3f2fd; color: #1565c0; }
            .status-in_progress { background: #fff3e0; color: #e65100; }
            .status-fulfilled { background: #e8f5e9; color: #2e7d32; }
            .status-closed { background: #f3e5f5; color: #7b1fa2; }

            .empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: 8px; color: #666; }

            .order-detail { background: white; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .detail-item label { display: block; font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 4px; }
            .detail-item span { font-size: 16px; }
            .detail-item.full-width { grid-column: 1 / -1; }
            .text-warning { color: #e65100; }

            .status-actions { border-top: 1px solid #eee; padding-top: 20px; }
            .status-actions h3 { font-size: 14px; color: #666; margin-bottom: 12px; }
            .status-form { display: flex; gap: 10px; flex-wrap: wrap; }

            .order-form { background: white; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .form-group { margin-bottom: 20px; }
            .form-group label { display: block; font-weight: 500; margin-bottom: 6px; }
            .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
            .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #3498db; }
            .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
            .form-actions { margin-top: 24px; display: flex; gap: 10px; }
        </style>
    </head>
    <body>
        <header>
            <h1>List Rental Work Order System</h1>
        </header>
        <div class="container">
            <h2 style="margin-bottom: 20px;"><?= htmlspecialchars($title) ?></h2>
    <?php
}

/**
 * Render page footer
 */
function renderFooter(): void
{
    ?>
        </div>
    </body>
    </html>
    <?php
}
