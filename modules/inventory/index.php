<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'inventory']);

$page = 'inventory';
$title = 'Inventory Management';
$heading = 'Inventory Management';
$msg = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

$inventoryPerPage = 10;
$historyPerPage = 10;

$inventoryPage = isset($_GET['inventory_page']) ? (int) $_GET['inventory_page'] : 1;
$historyPage = isset($_GET['history_page']) ? (int) $_GET['history_page'] : 1;
$inventorySearch = trim($_GET['inventory_search'] ?? '');

if ($inventoryPage < 1) {
    $inventoryPage = 1;
}

if ($historyPage < 1) {
    $historyPage = 1;
}

$isAdmin = can_access('admin');
$isInventory = can_access('inventory');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'archive_request') {
        if (!$isInventory || $isAdmin) {
            $_SESSION['flash_msg'] = 'Only Inventory Staff can submit an archive request.';
            header('Location: index.php');
            exit;
        }

        $id = (int) ($_POST['menu_item_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item || (int) $item['is_archived'] === 1) {
            $_SESSION['flash_msg'] = 'Menu item not found or already archived.';
        } elseif ((int) $item['stock'] > 0) {
            $_SESSION['flash_msg'] = 'Only menu items with 0 stock can be archived. Use Stock Out first.';
        } else {
            $pending = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Archive' AND menu_item_id = ? AND status = 'Pending' LIMIT 1");
            $pending->execute([$id]);
            if ($pending->fetch()) {
                $_SESSION['flash_msg'] = 'An archive request for this menu item is already pending Admin approval.';
            } else {
                $request = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Archive', ?, 'Out-of-stock menu archive request', ?)");
                $request->execute([$id, $_SESSION['u_id']]);
                $_SESSION['flash_msg'] = 'Archive request submitted for Admin approval.';
            }
        }

        header('Location: index.php?inventory_page=1&history_page=1');
        exit;
    }

    if ($action === 'stock') {
        $id = (int) ($_POST['menu_item_id'] ?? 0);
        $qtyRaw = $_POST['quantity'] ?? '';
        $type = trim($_POST['stock_action'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        $validQuantity = is_numeric($qtyRaw) && (string) ((int) $qtyRaw) === (string) $qtyRaw;
        $qty = $validQuantity ? (int) $qtyRaw : -1;

        if ($id <= 0 || $qty < 0 || !in_array($type, ['Stock In', 'Stock Out', 'Adjustment'], true)) {
            $_SESSION['flash_msg'] = 'Please provide valid stock movement details.';
            header('Location: index.php');
            exit;
        }

        if (($type === 'Stock In' || $type === 'Stock Out') && $qty <= 0) {
            $_SESSION['flash_msg'] = 'Quantity must be greater than 0 for Stock In and Stock Out.';
            header('Location: index.php');
            exit;
        }

        if ($type === 'Adjustment' && $qty < 1) {
            $_SESSION['flash_msg'] = 'Adjustment must set the stock to at least 1.';
            header('Location: index.php');
            exit;
        }

        try {
            $stmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $item = $stmt->fetch();

            if (!$item || (int) $item['is_archived'] === 1) {
                $_SESSION['flash_msg'] = 'Menu item not found or archived.';
                header('Location: index.php');
                exit;
            }

            $currentStock = (int) $item['stock'];

            if ($type === 'Stock Out' && $qty > $currentStock) {
                $_SESSION['flash_msg'] = 'Stock Out quantity cannot be greater than the current stock.';
                header('Location: index.php');
                exit;
            }

            if ($isInventory && !$isAdmin) {
                $pendingStmt = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = ? AND menu_item_id = ? AND status = 'Pending' LIMIT 1");
                $pendingStmt->execute([$type, $id]);
                if ($pendingStmt->fetch()) {
                    $_SESSION['flash_msg'] = 'A ' . $type . ' request for this menu item is already pending Admin approval.';
                    header('Location: index.php');
                    exit;
                }

                $requestRemarks = $remarks !== '' ? $remarks : 'Inventory ' . $type . ' request.';
                $requestStmt = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, quantity, remarks, requested_by) VALUES (?, ?, ?, ?, ?)");
                $requestStmt->execute([$type, $id, $qty, substr($requestRemarks, 0, 255), $_SESSION['u_id']]);
                $_SESSION['flash_msg'] = $type . ' request submitted. Stock will change after Admin approval.';
                header('Location: index.php?inventory_page=1&history_page=1');
                exit;
            }

            $conn->beginTransaction();

            $lockStmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$id]);
            $item = $lockStmt->fetch();

            if (!$item || (int) $item['is_archived'] === 1) {
                throw new RuntimeException('Menu item not found or archived.');
            }

            $currentStock = (int) $item['stock'];

            if ($type === 'Stock In') {
                $newStock = $currentStock + $qty;
                $logQuantity = $qty;
            } elseif ($type === 'Stock Out') {
                if ($qty > $currentStock) {
                    throw new RuntimeException('Stock Out quantity cannot be greater than the current stock.');
                }
                $newStock = $currentStock - $qty;
                $logQuantity = $qty;
            } else {
                $newStock = $qty;
                $logQuantity = $newStock;
            }

            $update = $conn->prepare("UPDATE menu_items SET stock = ?, availability = ? WHERE id = ? AND is_archived = 0");
            $update->execute([$newStock, $newStock <= 0 ? 'Unavailable' : 'Available', $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Stock could not be updated.');
            }

            $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, ?, ?, ?)");
            $log->execute([$id, $_SESSION['u_id'], $type, $logQuantity, $remarks]);

            $conn->commit();
            $_SESSION['flash_msg'] = 'Stock updated.';
            header('Location: index.php?inventory_page=1&history_page=1');
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['flash_msg'] = $isInventory && !$isAdmin ? 'Unable to submit the stock request.' : 'Unable to update stock.';
            header('Location: index.php');
            exit;
        }
    }
}

$items = $conn->query("SELECT menu_items.*, categories.category_name FROM menu_items JOIN categories ON categories.id = menu_items.category_id WHERE menu_items.is_archived = 0 ORDER BY menu_items.item_name, menu_items.id")->fetchAll();

$pendingInventoryRequests = [];
if ($isInventory && !$isAdmin) {
    $pendingStmt = $conn->prepare("SELECT menu_item_id, request_type FROM approval_requests WHERE requested_by = ? AND status = 'Pending' AND request_type IN ('Stock In','Stock Out','Adjustment')");
    $pendingStmt->execute([$_SESSION['u_id']]);
    foreach ($pendingStmt->fetchAll() as $request) {
        $pendingInventoryRequests[(int) $request['menu_item_id']] = $request['request_type'];
    }
}

$pendingArchiveRequests = [];
if ($isInventory && !$isAdmin) {
    $pendingArchiveStmt = $conn->query("SELECT menu_item_id FROM approval_requests WHERE status = 'Pending' AND request_type = 'Archive'");
    foreach ($pendingArchiveStmt->fetchAll() as $request) {
        $pendingArchiveRequests[(int) $request['menu_item_id']] = true;
    }
}

$low = $conn->query("SELECT COUNT(*) total FROM menu_items WHERE is_archived = 0 AND stock <= 5 AND stock > 0")->fetch();
$out = $conn->query("SELECT COUNT(*) total FROM menu_items WHERE is_archived = 0 AND stock <= 0")->fetch();

$inventoryWhere = 'WHERE menu_items.is_archived = 0';
if ($inventorySearch !== '') {
    $inventoryWhere .= ' AND (menu_items.item_name LIKE :search_name OR categories.category_name LIKE :search_category)';
}

$inventoryCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items JOIN categories ON categories.id = menu_items.category_id $inventoryWhere");
if ($inventorySearch !== '') {
    $inventorySearchLike = '%' . $inventorySearch . '%';
    $inventoryCountStmt->bindValue(':search_name', $inventorySearchLike, PDO::PARAM_STR);
    $inventoryCountStmt->bindValue(':search_category', $inventorySearchLike, PDO::PARAM_STR);
}
$inventoryCountStmt->execute();
$totalInventoryItems = (int) $inventoryCountStmt->fetchColumn();
$totalInventoryPages = max(1, (int) ceil($totalInventoryItems / $inventoryPerPage));

if ($inventoryPage > $totalInventoryPages) {
    $inventoryPage = $totalInventoryPages;
}

$inventoryOffset = ($inventoryPage - 1) * $inventoryPerPage;

$inventoryStmt = $conn->prepare("SELECT menu_items.*, categories.category_name FROM menu_items JOIN categories ON categories.id = menu_items.category_id $inventoryWhere ORDER BY menu_items.item_name, menu_items.id LIMIT :limit OFFSET :offset");
if ($inventorySearch !== '') {
    $inventoryStmt->bindValue(':search_name', $inventorySearchLike, PDO::PARAM_STR);
    $inventoryStmt->bindValue(':search_category', $inventorySearchLike, PDO::PARAM_STR);
}
$inventoryStmt->bindValue(':limit', $inventoryPerPage, PDO::PARAM_INT);
$inventoryStmt->bindValue(':offset', $inventoryOffset, PDO::PARAM_INT);
$inventoryStmt->execute();
$inventoryItems = $inventoryStmt->fetchAll();

$buildQuery = static function (array $params): string {
    $params = array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    });
    return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

$totalLogs = (int) $conn->query("SELECT COUNT(*) FROM inventory_logs")->fetchColumn();
$totalHistoryPages = max(1, (int) ceil($totalLogs / $historyPerPage));

if ($historyPage > $totalHistoryPages) {
    $historyPage = $totalHistoryPages;
}

$historyOffset = ($historyPage - 1) * $historyPerPage;

$logsStmt = $conn->prepare("SELECT inventory_logs.*, menu_items.item_name, inventory.ingredient_name, inventory.unit, users.full_name FROM inventory_logs LEFT JOIN menu_items ON menu_items.id = inventory_logs.menu_item_id LEFT JOIN inventory ON inventory.id = inventory_logs.inventory_id LEFT JOIN users ON users.id = inventory_logs.user_id ORDER BY inventory_logs.created_at DESC LIMIT :limit OFFSET :offset");
$logsStmt->bindValue(':limit', $historyPerPage, PDO::PARAM_INT);
$logsStmt->bindValue(':offset', $historyOffset, PDO::PARAM_INT);
$logsStmt->execute();
$logs = $logsStmt->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<style>
.inventory-card-head,
.history-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}

.inventory-card-title {
    min-width: 220px;
}

.inventory-card-title h2,
.history-card-head h2 {
    margin: 0;
}

.inventory-tools {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 16px;
    flex-wrap: wrap;
    margin-left: auto;
}

.inventory-search-form {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

.inventory-search-input {
    min-width: 250px;
    height: 44px;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 0 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--white);
}

.inventory-search-input:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(242, 193, 46, .16);
}

.inventory-search-input i {
    color: var(--muted);
}

.inventory-search-input input {
    border: 0;
    outline: 0;
    width: 100%;
    min-width: 0;
}

.inventory-pagination,
.history-pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    white-space: nowrap;
}

.inventory-page-info,
.history-page-info {
    min-width: 96px;
    text-align: center;
}

@media (max-width: 1100px) {
    .inventory-tools {
        width: 100%;
        justify-content: flex-start;
    }

    .inventory-search-form {
        justify-content: flex-start;
    }
}

@media (max-width: 640px) {
    .inventory-search-form,
    .inventory-search-input,
    .inventory-tools,
    .inventory-pagination,
    .history-pagination {
        width: 100%;
    }

    .inventory-search-input {
        min-width: 0;
    }

    .inventory-pagination,
    .history-pagination {
        justify-content: space-between;
    }
}

.inventory-confirm-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, 0.58);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

.inventory-confirm-overlay.show {
    opacity: 1;
    visibility: visible;
}

.inventory-confirm-modal {
    position: relative;
    width: min(440px, 100%);
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.28);
    transform: translateY(16px) scale(0.97);
    transition: transform 0.2s ease;
}

.inventory-confirm-overlay.show .inventory-confirm-modal {
    transform: translateY(0) scale(1);
}

.inventory-confirm-accent {
    height: 7px;
    background: #F2C12E;
}

.inventory-confirm-close {
    position: absolute;
    top: 13px;
    right: 13px;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 50%;
    background: #f5f5f5;
    color: #111111;
    font-size: 1rem;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
    z-index: 2;
}

.inventory-confirm-close:hover {
    background: #F2C12E;
    transform: scale(1.04);
}

.inventory-confirm-body {
    padding: 30px 28px 26px;
    text-align: center;
}

.inventory-confirm-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #FFF1B8;
    color: #111111;
    font-size: 2.15rem;
}

.inventory-confirm-title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
    color: #111111;
}

.inventory-confirm-message {
    margin: 8px auto 18px;
    max-width: 340px;
    font-size: 0.84rem;
    line-height: 1.5;
    color: #666666;
}

.inventory-confirm-details {
    display: grid;
    gap: 8px;
    margin: 0 auto 20px;
    padding: 14px 16px;
    max-width: 360px;
    border: 1px solid #eeeeee;
    border-radius: 10px;
    background: #fafafa;
    text-align: left;
}

.inventory-confirm-detail-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    font-size: 0.8rem;
    line-height: 1.4;
}

.inventory-confirm-detail-row span {
    color: #666666;
}

.inventory-confirm-detail-row strong {
    color: #111111;
    text-align: right;
}

.inventory-confirm-actions {
    display: flex;
    gap: 10px;
}

.inventory-confirm-actions button {
    flex: 1;
    min-height: 42px;
    border-radius: 7px;
    font-size: 0.82rem;
    font-weight: 800;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
}

.inventory-confirm-cancel {
    border: 1px solid #d7d7d7;
    background: #ffffff;
    color: #222222;
}

.inventory-confirm-cancel:hover {
    background: #f5f5f5;
}

.inventory-confirm-submit {
    border: 1px solid #D8AF18;
    background: #F2C12E;
    color: #111111;
}

.inventory-confirm-submit:hover {
    background: #E8B717;
}

.inventory-confirm-actions button:active {
    transform: translateY(1px);
}

@media (max-width: 520px) {
    .inventory-confirm-body {
        padding: 28px 20px 22px;
    }

    .inventory-confirm-actions {
        flex-direction: column-reverse;
    }
}
</style>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>

<section class="grid grid-3">
    <article class="card stat">
        <div><span class="muted">Menu Items</span><strong><?= count($items) ?></strong></div>
        <i class="bi bi-boxes"></i>
    </article>

    <article class="card stat">
        <div><span class="muted">Low Stock</span><strong><?= e($low['total']) ?></strong><span class="inventory-help-text">5 pcs or below</span></div>
        <i class="bi bi-exclamation-triangle"></i>
    </article>

    <article class="card stat">
        <div><span class="muted">Out of Stock</span><strong><?= e($out['total']) ?></strong><span class="inventory-help-text">0 pcs available</span></div>
        <i class="bi bi-x-circle"></i>
    </article>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head"><h2>Stock Movement</h2></div>

    <form class="form" method="post" action="index.php" id="stockMovementForm">
        <input type="hidden" name="action" value="stock">
        <input type="hidden" name="menu_item_id" id="menuItemId" value="">

        <div class="field">
            <label for="menuItemInput">Menu Item</label>
            <input type="text" id="menuItemInput" class="stock-item-input" list="menuItemOptions" placeholder="Type or select a menu item" autocomplete="off" required>

            <datalist id="menuItemOptions">
                <?php foreach ($items as $item): ?>
                    <option value="<?= e($item['item_name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <div id="selectedItemInfo" class="selected-item-info"></div>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label for="stockAction">Action</label>
                <select name="stock_action" id="stockAction" required>
                    <option value="Stock In">Stock In</option>
                    <option value="Stock Out">Stock Out</option>
                    <option value="Adjustment">Adjustment</option>
                </select>
                <div id="actionHelp" class="action-help"><?= $isInventory && !$isAdmin ? 'Stock changes are submitted to Admin for approval before the menu stock is changed.' : 'Stock In adds newly received or restocked items to the current stock.' ?></div>
            </div>

            <div class="field">
                <label for="stockQuantity" id="quantityLabel">Quantity</label>
                <input type="number" id="stockQuantity" min="1" step="1" name="quantity" inputmode="numeric" required>
                <div id="quantityHelp" class="action-help">Enter the number of whole items to add.</div>
            </div>
        </div>

        <div class="field">
            <label for="stockRemarks">Remarks</label>
            <textarea id="stockRemarks" name="remarks" class="remarks-input" rows="3" placeholder="Example: New delivery, damaged stock, sold out, manual correction"></textarea>
            <p class="inventory-help-text"><?= $isInventory && !$isAdmin ? 'Your request will remain pending until an Admin reviews it.' : 'Add a short reason for the stock movement.' ?></p>
        </div>

        <button class="btn btn-secondary" type="submit" id="updateStockBtn"><i class="bi bi-arrow-left-right"></i><?= $isInventory && !$isAdmin ? 'Submit Stock Request' : 'Update Stock' ?></button>
    </form>
</section>

<section class="card" style="margin-top:24px">
    <div class="inventory-card-head">
        <div class="inventory-card-title">
            <h2>Inventory List</h2>
            <p class="inventory-help-text">
                <?php if ($inventorySearch !== ''): ?>
                    Showing <?= count($inventoryItems) ?> of <?= $totalInventoryItems ?> matching menu items.
                <?php else: ?>
                    Showing <?= count($inventoryItems) ?> of <?= $totalInventoryItems ?> menu items.
                <?php endif; ?>
            </p>
        </div>

        <div class="inventory-tools">
            <form class="inventory-search-form" method="get" action="index.php">
                <input type="hidden" name="inventory_page" value="1">
                <input type="hidden" name="history_page" value="<?= $historyPage ?>">
                <div class="inventory-search-input">
                    <i class="bi bi-search"></i>
                    <input type="search" name="inventory_search" value="<?= e($inventorySearch) ?>" placeholder="Search menu items" autocomplete="off">
                </div>
                <button class="btn btn-primary" type="submit">Search</button>
                <?php if ($inventorySearch !== ''): ?>
                    <a class="btn btn-secondary" href="<?= e($buildQuery(['inventory_page' => 1, 'history_page' => $historyPage])) ?>">Clear</a>
                <?php endif; ?>
            </form>

            <div class="inventory-pagination">
                <a class="btn btn-secondary" href="<?= e($buildQuery(['inventory_page' => $inventoryPage - 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>" <?= $inventoryPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
                <strong class="inventory-page-info">Page <?= $inventoryPage ?> of <?= $totalInventoryPages ?></strong>
                <a class="btn btn-secondary" href="<?= e($buildQuery(['inventory_page' => $inventoryPage + 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>" <?= $inventoryPage >= $totalInventoryPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:18px;">
        <table class="inventory-list-table">
            <thead><tr><th>Menu Item</th><th>Category</th><th>Stock</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($inventoryItems as $row): ?>
                    <?php
                    $stockValue = (int) $row['stock'];
                    $status = 'In Stock';
                    $statusClass = 'badge-muted';
                    $rowClass = '';
                    if ($stockValue <= 0) {
                        $status = 'Out of Stock';
                        $statusClass = 'badge-danger';
                        $rowClass = 'stock-row-out';
                    } elseif ($stockValue <= 5) {
                        $status = 'Low Stock';
                        $statusClass = 'badge-warning';
                        $rowClass = 'stock-row-low';
                    }
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= e($row['item_name']) ?></td>
                        <td><?= e($row['category_name']) ?></td>
                        <td><?= $stockValue ?> pcs</td>
                        <td>
                            <span class="badge <?= $statusClass ?>"><?= $status ?></span>
                            <?php if ($isInventory && !$isAdmin && isset($pendingInventoryRequests[(int) $row['id']])): ?>
                                <div style="margin-top:6px;"><span class="badge badge-warning">Approval Pending</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isInventory && !$isAdmin && $stockValue <= 0): ?>
                                <?php if (isset($pendingArchiveRequests[(int) $row['id']])): ?>
                                    <span class="badge badge-warning">Archive Pending</span>
                                <?php else: ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="archive_request">
                                        <input type="hidden" name="menu_item_id" value="<?= e($row['id']) ?>">
                                        <button class="btn btn-danger" type="submit" data-confirm="Submit an archive request for this out-of-stock menu item?"><i class="bi bi-archive"></i>Request Archive</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:24px">
    <div class="history-card-head">
        <h2>Recent Stock History</h2>
        <div class="history-pagination">
            <a class="btn btn-secondary" href="<?= e($buildQuery(['history_page' => $historyPage - 1, 'inventory_page' => $inventoryPage, 'inventory_search' => $inventorySearch])) ?>" <?= $historyPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
            <strong class="history-page-info">Page <?= $historyPage ?> of <?= $totalHistoryPages ?></strong>
            <a class="btn btn-secondary" href="<?= e($buildQuery(['history_page' => $historyPage + 1, 'inventory_page' => $inventoryPage, 'inventory_search' => $inventorySearch])) ?>" <?= $historyPage >= $totalHistoryPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:18px;">
        <table>
            <thead><tr><th>Date</th><th>Item</th><th>Action</th><th>Quantity</th><th>User</th><th>Remarks</th></tr></thead>
            <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= e(date('M d, h:i A', strtotime($log['created_at']))) ?></td>
                            <td><?= e($log['item_name'] ?? $log['ingredient_name'] ?? 'Unknown Item') ?></td>
                            <td><?= e($log['action']) ?></td>
                            <td><?= (int) $log['quantity'] ?> pcs</td>
                            <td><?= e($log['full_name'] ?? 'Unknown User') ?></td>
                            <td><?= e($log['remarks']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;">No stock history found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="inventory-confirm-overlay" id="inventoryConfirmOverlay" aria-hidden="true">
    <div class="inventory-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="inventoryConfirmTitle">
        <div class="inventory-confirm-accent"></div>

        <button type="button" class="inventory-confirm-close" id="inventoryConfirmClose" aria-label="Close" title="Close">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="inventory-confirm-body">
            <div class="inventory-confirm-icon">
                <i class="bi bi-question-lg"></i>
            </div>

            <h2 class="inventory-confirm-title" id="inventoryConfirmTitle">Confirm Stock Update</h2>
            <p class="inventory-confirm-message">Please review the stock change before continuing.</p>

            <div class="inventory-confirm-details">
                <div class="inventory-confirm-detail-row">
                    <span>Menu Item</span>
                    <strong id="confirmItemName">-</strong>
                </div>
                <div class="inventory-confirm-detail-row">
                    <span>Action</span>
                    <strong id="confirmAction">-</strong>
                </div>
                <div class="inventory-confirm-detail-row">
                    <span id="confirmQuantityLabel">Quantity</span>
                    <strong id="confirmQuantity">-</strong>
                </div>
                <div class="inventory-confirm-detail-row">
                    <span>Current Stock</span>
                    <strong id="confirmCurrentStock">-</strong>
                </div>
                <div class="inventory-confirm-detail-row">
                    <span>New Stock</span>
                    <strong id="confirmNewStock">-</strong>
                </div>
            </div>

            <div class="inventory-confirm-actions">
                <button type="button" class="inventory-confirm-cancel" id="inventoryConfirmCancel">Cancel</button>
                <button type="button" class="inventory-confirm-submit" id="inventoryConfirmSubmit"><?= $isInventory && !$isAdmin ? 'Submit Request' : 'Confirm Update' ?></button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('stockMovementForm');
    const itemInput = document.getElementById('menuItemInput');
    const itemIdInput = document.getElementById('menuItemId');
    const selectedItemInfo = document.getElementById('selectedItemInfo');
    const stockAction = document.getElementById('stockAction');
    const stockQuantity = document.getElementById('stockQuantity');
    const quantityLabel = document.getElementById('quantityLabel');
    const actionHelp = document.getElementById('actionHelp');
    const quantityHelp = document.getElementById('quantityHelp');

    const menuItems = <?= json_encode(array_map(static function ($item) {
        return [
            'id' => (int) $item['id'],
            'name' => $item['item_name'],
            'stock' => (int) $item['stock']
        ];
    }, $items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const normalizeName = value => String(value || '').trim().toLowerCase();

    const findMenuItem = value => {
        const normalized = normalizeName(value);
        return menuItems.find(item => normalizeName(item.name) === normalized);
    };

    const updateSelectedItem = () => {
        const item = findMenuItem(itemInput.value);

        if (item) {
            itemIdInput.value = item.id;
            selectedItemInfo.textContent = `Current stock: ${item.stock} pcs`;
            selectedItemInfo.classList.add('show');
        } else {
            itemIdInput.value = '';
            selectedItemInfo.textContent = '';
            selectedItemInfo.classList.remove('show');
        }

        updateActionInfo();
    };

    const updateActionInfo = () => {
        const item = findMenuItem(itemInput.value);

        if (stockAction.value === 'Stock In') {
            quantityLabel.textContent = 'Quantity';
            stockQuantity.min = '1';
            stockQuantity.removeAttribute('max');
            stockQuantity.disabled = false;
            stockQuantity.placeholder = 'Enter quantity to add';
            actionHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Stock In requests an Admin-approved increase to the current stock.' : 'Stock In adds newly received or restocked items to the current stock.') ?>;
            quantityHelp.textContent = 'Enter the number of whole items to add.';
            if (stockQuantity.value !== '' && Number(stockQuantity.value) < 1) {
                stockQuantity.value = '';
            }
            return;
        }

        if (stockAction.value === 'Stock Out') {
            quantityLabel.textContent = 'Quantity';
            stockQuantity.min = '1';
            stockQuantity.placeholder = 'Enter quantity to remove';
            actionHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Stock Out requests an Admin-approved removal from the current stock. It can reduce stock to 0.' : 'Stock Out removes items from the current stock. It can reduce the stock all the way to 0.') ?>;

            if (item) {
                stockQuantity.max = String(item.stock);
                if (item.stock <= 0) {
                    stockQuantity.disabled = true;
                    stockQuantity.value = '';
                    quantityHelp.textContent = 'This item has no stock available to remove.';
                } else {
                    stockQuantity.disabled = false;
                    quantityHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Enter 1 to ' : '') ?> + item.stock + <?= json_encode($isInventory && !$isAdmin ? ' to request a stock removal. Admin approval is required.' : ' to remove stock. You can remove all remaining stock to make the item Out of Stock.') ?>;
                }
            } else {
                stockQuantity.removeAttribute('max');
                stockQuantity.disabled = false;
                quantityHelp.textContent = 'Enter the number of whole items to remove. The quantity cannot be greater than the current stock.';
            }

            if (stockQuantity.value !== '' && Number(stockQuantity.value) < 1) {
                stockQuantity.value = '';
            }
            return;
        }

        quantityLabel.textContent = 'New Stock Quantity';
        stockQuantity.min = '1';
        stockQuantity.removeAttribute('max');
        stockQuantity.disabled = false;
        stockQuantity.placeholder = 'Enter the correct total stock';
        actionHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Adjustment requests an Admin-approved correction to the exact stock quantity.' : 'Adjustment replaces the recorded stock with the exact correct quantity.') ?>;
        quantityHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Enter the corrected stock total for Admin review. Use Stock Out when removing stock.' : 'Enter the correct total stock. This number becomes the stock shown in POS. Use Stock Out when removing stock.') ?>;
    };

    itemInput.addEventListener('input', updateSelectedItem);
    itemInput.addEventListener('change', updateSelectedItem);
    stockAction.addEventListener('change', updateActionInfo);

    stockQuantity.addEventListener('input', () => {
        if (stockQuantity.value === '') {
            return;
        }

        stockQuantity.value = String(Math.max(0, Math.floor(Number(stockQuantity.value))));
    });

    form.addEventListener('submit', event => {
        updateSelectedItem();

        const item = findMenuItem(itemInput.value);
        const quantity = Number(stockQuantity.value);

        if (!itemIdInput.value) {
            event.preventDefault();
            alert('Please select a valid menu item.');
            itemInput.focus();
            return;
        }

        if (!Number.isInteger(quantity) || quantity < 0) {
            event.preventDefault();
            alert('Quantity must be a whole number and cannot be negative.');
            stockQuantity.focus();
            return;
        }

        if ((stockAction.value === 'Stock In' || stockAction.value === 'Stock Out') && quantity <= 0) {
            event.preventDefault();
            alert('Quantity must be greater than 0 for Stock In and Stock Out.');
            stockQuantity.focus();
            return;
        }

        if (stockAction.value === 'Stock Out' && item && quantity > item.stock) {
            event.preventDefault();
            alert(`Only ${item.stock} pcs are currently in stock.`);
            stockQuantity.focus();
            return;
        }

        if (stockAction.value === 'Adjustment' && quantity < 1) {
            event.preventDefault();
            alert('Adjustment must set the stock to at least 1. Use Stock Out when removing all remaining stock.');
            stockQuantity.focus();
            return;
        }

        const currentStock = item ? item.stock : 0;
        let newStock = currentStock;
        let quantityLabelText = 'Quantity';

        if (stockAction.value === 'Stock In') {
            newStock = currentStock + quantity;
        } else if (stockAction.value === 'Stock Out') {
            newStock = currentStock - quantity;
        } else {
            newStock = quantity;
            quantityLabelText = 'New Stock Quantity';
        }

        event.preventDefault();

        const overlay = document.getElementById('inventoryConfirmOverlay');
        const closeButton = document.getElementById('inventoryConfirmClose');
        const cancelButton = document.getElementById('inventoryConfirmCancel');
        const confirmButton = document.getElementById('inventoryConfirmSubmit');
        const itemName = document.getElementById('confirmItemName');
        const confirmAction = document.getElementById('confirmAction');
        const confirmQuantityLabel = document.getElementById('confirmQuantityLabel');
        const confirmQuantity = document.getElementById('confirmQuantity');
        const confirmCurrentStock = document.getElementById('confirmCurrentStock');
        const confirmNewStock = document.getElementById('confirmNewStock');

        itemName.textContent = item.name;
        confirmAction.textContent = stockAction.value;
        confirmQuantityLabel.textContent = quantityLabelText;
        confirmQuantity.textContent = `${quantity} pcs`;
        confirmCurrentStock.textContent = `${currentStock} pcs`;
        confirmNewStock.textContent = `${newStock} pcs`;

        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
        confirmButton.focus();

        const handleEscape = keyboardEvent => {
            if (keyboardEvent.key === 'Escape') {
                closeConfirmation();
            }
        };

        const closeConfirmation = () => {
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            document.removeEventListener('keydown', handleEscape);
            form.querySelector('#updateStockBtn')?.focus();
        };

        const submitConfirmed = () => {
            document.removeEventListener('keydown', handleEscape);
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            form.submit();
        };

        closeButton.onclick = closeConfirmation;
        cancelButton.onclick = closeConfirmation;
        confirmButton.onclick = submitConfirmed;

        document.addEventListener('keydown', handleEscape);
    });

    updateSelectedItem();
});
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
