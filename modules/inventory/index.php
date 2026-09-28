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

if ($inventoryPage < 1) {
    $inventoryPage = 1;
}

if ($historyPage < 1) {
    $historyPage = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $item = $stmt->fetch();

            if (!$item || (int) $item['is_archived'] === 1) {
                $conn->rollBack();
                $_SESSION['flash_msg'] = 'Menu item not found or archived.';
                header('Location: index.php');
                exit;
            }

            $currentStock = (int) $item['stock'];

            if ($type === 'Stock In') {
                $newStock = $currentStock + $qty;
                $logQuantity = $qty;
            } elseif ($type === 'Stock Out') {
                if ($qty > $currentStock) {
                    $conn->rollBack();
                    $_SESSION['flash_msg'] = 'Stock Out quantity cannot be greater than the current stock.';
                    header('Location: index.php');
                    exit;
                }
                $newStock = $currentStock - $qty;
                $logQuantity = $qty;
            } else {
                $newStock = $qty;
                $logQuantity = $newStock;
            }

            $update = $conn->prepare("UPDATE menu_items SET stock = ? WHERE id = ? AND is_archived = 0");
            $update->execute([$newStock, $id]);

            $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, ?, ?, ?)");
            $log->execute([$id, $_SESSION['u_id'], $type, $logQuantity, $remarks]);

            $availability = $conn->prepare("UPDATE menu_items SET availability = ? WHERE id = ? AND is_archived = 0");
            $availability->execute([$newStock <= 0 ? 'Unavailable' : 'Available', $id]);

            $conn->commit();
            $_SESSION['flash_msg'] = 'Stock updated.';
            header('Location: index.php?inventory_page=1&history_page=1');
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['flash_msg'] = 'Unable to update stock.';
            header('Location: index.php');
            exit;
        }
    }
}

$items = $conn->query("SELECT menu_items.*, categories.category_name FROM menu_items JOIN categories ON categories.id = menu_items.category_id WHERE menu_items.is_archived = 0 ORDER BY menu_items.item_name")->fetchAll();

$low = $conn->query("SELECT COUNT(*) total FROM menu_items WHERE is_archived = 0 AND stock <= 5 AND stock > 0")->fetch();
$out = $conn->query("SELECT COUNT(*) total FROM menu_items WHERE is_archived = 0 AND stock <= 0")->fetch();

$totalInventoryItems = count($items);
$totalInventoryPages = max(1, (int) ceil($totalInventoryItems / $inventoryPerPage));

if ($inventoryPage > $totalInventoryPages) {
    $inventoryPage = $totalInventoryPages;
}

$inventoryOffset = ($inventoryPage - 1) * $inventoryPerPage;
$inventoryItems = array_slice($items, $inventoryOffset, $inventoryPerPage);

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
                <div id="actionHelp" class="action-help">Stock In adds newly received or restocked items to the current stock.</div>
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
            <p class="inventory-help-text">Add a short reason for the stock movement.</p>
        </div>

        <button class="btn btn-secondary" type="submit" id="updateStockBtn"><i class="bi bi-arrow-left-right"></i>Update Stock</button>
    </form>
</section>

<section class="card" style="margin-top:24px">
    <div class="inventory-card-head">
        <div>
            <h2>Inventory List</h2>
            <p class="inventory-help-text">Showing <?= count($inventoryItems) ?> of <?= $totalInventoryItems ?> menu items.</p>
        </div>

        <div class="inventory-pagination">
            <a class="btn btn-secondary" href="?inventory_page=<?= $inventoryPage - 1 ?>&history_page=<?= $historyPage ?>" <?= $inventoryPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
            <strong class="inventory-page-info">Page <?= $inventoryPage ?> of <?= $totalInventoryPages ?></strong>
            <a class="btn btn-secondary" href="?inventory_page=<?= $inventoryPage + 1 ?>&history_page=<?= $historyPage ?>" <?= $inventoryPage >= $totalInventoryPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:18px;">
        <table class="inventory-list-table">
            <thead><tr><th>Menu Item</th><th>Category</th><th>Stock</th><th>Status</th></tr></thead>
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
                        <td><span class="badge <?= $statusClass ?>"><?= $status ?></span></td>
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
            <a class="btn btn-secondary" href="?history_page=<?= $historyPage - 1 ?>&inventory_page=<?= $inventoryPage ?>" <?= $historyPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
            <strong class="history-page-info">Page <?= $historyPage ?> of <?= $totalHistoryPages ?></strong>
            <a class="btn btn-secondary" href="?history_page=<?= $historyPage + 1 ?>&inventory_page=<?= $inventoryPage ?>" <?= $historyPage >= $totalHistoryPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
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
            actionHelp.textContent = 'Stock In adds newly received or restocked items to the current stock.';
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
            actionHelp.textContent = 'Stock Out removes items from the current stock. It can reduce the stock all the way to 0.';

            if (item) {
                stockQuantity.max = String(item.stock);
                if (item.stock <= 0) {
                    stockQuantity.disabled = true;
                    stockQuantity.value = '';
                    quantityHelp.textContent = 'This item has no stock available to remove.';
                } else {
                    stockQuantity.disabled = false;
                    quantityHelp.textContent = `Enter 1 to ${item.stock} to remove stock. You can remove all remaining stock to make the item Out of Stock.`;
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
        actionHelp.textContent = 'Adjustment replaces the recorded stock with the exact correct quantity.';
        quantityHelp.textContent = 'Enter the correct total stock. This number becomes the stock shown in POS. Use Stock Out when removing stock.';
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

        const confirmed = window.confirm(
            'Confirm Stock Update\n\n' +
            'Menu Item: ' + item.name + '\n' +
            'Action: ' + stockAction.value + '\n' +
            quantityLabelText + ': ' + quantity + ' pcs\n' +
            'Current Stock: ' + currentStock + ' pcs\n' +
            'New Stock: ' + newStock + ' pcs\n\n' +
            'Do you want to continue?'
        );

        if (!confirmed) {
            event.preventDefault();
        }
    });

    updateSelectedItem();
});
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
