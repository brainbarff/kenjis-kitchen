<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'inventory']);

$page = 'inventory';
$title = 'Inventory Management';
$heading = 'Inventory Management';
$msg = $_SESSION['flash_msg'] ?? '';
$msgType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$inventoryPerPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($inventoryPerPage, [10, 25, 50, 100], true)) $inventoryPerPage = 10;
$inventoryCategory = max(0, (int) ($_GET['category'] ?? 0));
$inventorySortOptions = [
    'name_asc' => ['Name: A to Z', 'menu_items.item_name ASC'],
    'name_desc' => ['Name: Z to A', 'menu_items.item_name DESC'],
    'stock_asc' => ['Stock: lowest first', 'menu_items.stock ASC'],
    'stock_desc' => ['Stock: highest first', 'menu_items.stock DESC'],
    'category' => ['Category', 'categories.category_name ASC, menu_items.item_name ASC']
];
$inventorySort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'name_asc';
if (!isset($inventorySortOptions[$inventorySort])) $inventorySort = 'name_asc';
$inventoryOrderBy = $inventorySortOptions[$inventorySort][1];
$inventoryCategories = $conn->query('SELECT id, category_name FROM categories ORDER BY category_name, id')->fetchAll();
// Keep the filter aligned with the restaurant's menu category sequence.
$inventoryCategoryOrder = ['add-ons' => 0, 'student meals' => 1, 'pulutan & pares' => 2, 'drinks' => 3, 'silog' => 4, 'silog meals' => 4, 'silog set meals' => 5, 'bilao favorites' => 6];
usort($inventoryCategories, static function (array $a, array $b) use ($inventoryCategoryOrder): int {
    $rankA = $inventoryCategoryOrder[strtolower(trim($a['category_name']))] ?? 99;
    $rankB = $inventoryCategoryOrder[strtolower(trim($b['category_name']))] ?? 99;
    return ($rankA <=> $rankB) ?: strcasecmp($a['category_name'], $b['category_name']) ?: ((int)$a['id'] <=> (int)$b['id']);
});
$historyPerPage = 10;

$inventoryPage = isset($_GET['inventory_page']) ? (int) $_GET['inventory_page'] : 1;
$historyPage = isset($_GET['history_page']) ? (int) $_GET['history_page'] : 1;
$inventorySearch = trim($_GET['inventory_search'] ?? '');
$stockFilter = $_GET['stock_filter'] ?? '';
if (!in_array($stockFilter, ['available', 'low', 'out'], true)) {
    $stockFilter = '';
}
$stockConditions = [
    'low' => 'menu_items.stock > 0 AND menu_items.stock <= 5',
    'out' => 'menu_items.stock = 0 OR menu_items.availability != \'Available\''
];
$inventoryListTitle = [
    'available' => 'Available Stock Items',
    'low' => 'Low Stock Items',
    'out' => 'Out of Stock Items'
][$stockFilter] ?? 'Product Inventory';
$inventoryEmptyText = [
    'available' => 'No available stock items found.',
    'low' => 'No low-stock items found.',
    'out' => 'No out-of-stock items found.'
][$stockFilter] ?? 'No inventory products found.';

if ($inventoryPage < 1) {
    $inventoryPage = 1;
}

if ($historyPage < 1) {
    $historyPage = 1;
}

$isAdmin = can_access('admin');
$isInventory = can_access('inventory');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $_SESSION['flash_msg'] = 'Invalid or expired security token. Please reload the page and try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'archive_request') {
        if (!$isInventory || $isAdmin) {
            $_SESSION['flash_msg'] = 'Only Inventory Staff can submit an archive request.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: index.php');
            exit;
        }

        $id = (int) ($_POST['menu_item_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item || (int) $item['is_archived'] === 1) {
            $_SESSION['flash_msg'] = 'Menu item not found or already archived.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $pending = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Archive' AND menu_item_id = ? AND status = 'Pending' LIMIT 1");
            $pending->execute([$id]);
            if ($pending->fetch()) {
                $_SESSION['flash_msg'] = 'An archive request for this menu item is already pending Admin approval.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $request = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Archive', ?, 'Menu archive request', ?)");
                $request->execute([$id, $_SESSION['u_id']]);
                $_SESSION['flash_msg'] = 'Archive request submitted for Admin approval.';
                $_SESSION['flash_type'] = 'success';
            }
        }

        header('Location: index.php?inventory_page=1&history_page=1');
        exit;
    }

    if ($action === 'stock') {
        $id = (int) ($_POST['menu_item_id'] ?? 0);
        $qtyRaw = trim((string)($_POST['quantity'] ?? ''));
        $type = trim($_POST['stock_action'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        $validQuantity = preg_match('/^\d+$/', $qtyRaw) === 1;
        $qty = $validQuantity ? (int) $qtyRaw : -1;

        if ($id <= 0 || $qty < 0 || $qty > 10000 || !in_array($type, ['Stock In', 'Stock Out', 'Adjustment'], true)) {
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

$totalProductsCount = (int) $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_archived = 0")->fetchColumn();
$availableStockCount = (int) $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_archived = 0 AND stock > 5 AND availability = 'Available'")->fetchColumn();
$lowStockCount = (int) $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_archived = 0 AND stock BETWEEN 1 AND 5")->fetchColumn();
$outOfStockCount = (int) $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_archived = 0 AND (stock = 0 OR availability != 'Available')")->fetchColumn();
$stockSummary = [
    'low_total' => $lowStockCount,
    'out_total' => $outOfStockCount
];

$inventoryWhere = 'WHERE menu_items.is_archived = 0';
if ($inventoryCategory > 0) $inventoryWhere .= ' AND menu_items.category_id = :category_id';
if ($stockFilter === 'low') {
    $inventoryWhere .= ' AND (' . $stockConditions['low'] . ')';
} elseif ($stockFilter === 'out') {
    $inventoryWhere .= ' AND (' . $stockConditions['out'] . ')';
} elseif ($stockFilter === 'available') {
    $inventoryWhere .= " AND menu_items.stock > 5 AND menu_items.availability = 'Available'";
}
if ($inventorySearch !== '') {
    $inventoryWhere .= ' AND (menu_items.item_name LIKE :search_name OR categories.category_name LIKE :search_category)';
}

$inventoryCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items JOIN categories ON categories.id = menu_items.category_id $inventoryWhere");
if ($inventorySearch !== '') {
    $inventorySearchLike = '%' . $inventorySearch . '%';
    $inventoryCountStmt->bindValue(':search_name', $inventorySearchLike, PDO::PARAM_STR);
    $inventoryCountStmt->bindValue(':search_category', $inventorySearchLike, PDO::PARAM_STR);
}
if ($inventoryCategory > 0) $inventoryCountStmt->bindValue(':category_id', $inventoryCategory, PDO::PARAM_INT);
$inventoryCountStmt->execute();
$totalInventoryItems = (int) $inventoryCountStmt->fetchColumn();
$totalInventoryPages = max(1, (int) ceil($totalInventoryItems / $inventoryPerPage));

if ($inventoryPage > $totalInventoryPages) {
    $inventoryPage = $totalInventoryPages;
}

$inventoryOffset = ($inventoryPage - 1) * $inventoryPerPage;

$inventoryStmt = $conn->prepare("SELECT menu_items.*, categories.category_name FROM menu_items JOIN categories ON categories.id = menu_items.category_id $inventoryWhere ORDER BY $inventoryOrderBy, menu_items.id LIMIT :limit OFFSET :offset");
if ($inventorySearch !== '') {
    $inventoryStmt->bindValue(':search_name', $inventorySearchLike, PDO::PARAM_STR);
    $inventoryStmt->bindValue(':search_category', $inventorySearchLike, PDO::PARAM_STR);
}
if ($inventoryCategory > 0) $inventoryStmt->bindValue(':category_id', $inventoryCategory, PDO::PARAM_INT);
$inventoryStmt->bindValue(':limit', $inventoryPerPage, PDO::PARAM_INT);
$inventoryStmt->bindValue(':offset', $inventoryOffset, PDO::PARAM_INT);
$inventoryStmt->execute();
$inventoryItems = $inventoryStmt->fetchAll();

$buildQuery = static function (array $params) use ($stockFilter, $inventoryCategory, $inventorySort, $inventoryPerPage): string {
    $params += ['stock_filter' => $stockFilter, 'category' => $inventoryCategory, 'sort' => $inventorySort, 'per_page' => $inventoryPerPage];
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

$logsStmt = $conn->prepare("SELECT inventory_logs.*, menu_items.item_name, inventory.ingredient_name, inventory.unit, users.full_name FROM inventory_logs LEFT JOIN menu_items ON menu_items.id = inventory_logs.menu_item_id LEFT JOIN inventory ON inventory.id = inventory_logs.inventory_id LEFT JOIN users ON users.id = inventory_logs.user_id ORDER BY inventory_logs.created_at DESC, inventory_logs.id DESC LIMIT :limit OFFSET :offset");
$logsStmt->bindValue(':limit', $historyPerPage, PDO::PARAM_INT);
$logsStmt->bindValue(':offset', $historyOffset, PDO::PARAM_INT);
$logsStmt->execute();
$logs = $logsStmt->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<style>
html {
    scroll-behavior: smooth;
}

#inventory-list {
    scroll-margin-top: 100px;
}

/* HueSuite / Modern Clean Inventory Layout */
.inventory-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.inv-stat-card {
    background: #ffffff;
    border: 1px solid #eef0f2;
    border-radius: 14px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    text-decoration: none;
    color: inherit;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    cursor: pointer;
}

.inv-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
    border-color: #e2e8f0;
}

.inv-stat-card[aria-current="true"] {
    border-color: #F2C12E;
    box-shadow: 0 0 0 2px rgba(242, 193, 46, 0.35), 0 8px 20px rgba(0, 0, 0, 0.05);
    background: #fffdf5;
}

.inv-stat-info {
    display: flex;
    flex-direction: column;
}

.inv-stat-val {
    font-size: 1.85rem;
    font-weight: 800;
    line-height: 1.1;
    color: #1e1e1e;
    font-family: 'Inter', sans-serif;
}

.inv-stat-lbl {
    font-size: 0.82rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #718096;
    margin-top: 6px;
}

.inv-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}

.inv-icon-total { background: #f1f5f9; color: #475569; }
.inv-icon-available { background: #ecfdf5; color: #10b981; }
.inv-icon-low { background: #fffbeb; color: #f59e0b; }
.inv-icon-out { background: #fef2f2; color: #ef4444; }

/* Filter pills bar */
.inv-tabs-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.inv-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    background: #ffffff;
    color: #4b5563;
    border: 1px solid #e5e7eb;
    transition: all 0.15s ease;
}

.inv-tab-btn:hover {
    background: #f9fafb;
    border-color: #d1d5db;
    color: #111827;
}

.inv-tab-btn.active {
    background: #1e1e1e;
    color: #ffffff;
    border-color: #1e1e1e;
}

.inv-tab-btn .badge-pill {
    background: rgba(0,0,0,0.07);
    color: inherit;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 12px;
}

.inv-tab-btn.active .badge-pill {
    background: rgba(255,255,255,0.22);
    color: #ffffff;
}

/* Modern Clean Inventory Table */
.inv-table-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #eef0f2;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
    padding: 24px;
}

.inv-table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.inv-table-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.inv-table-title h2 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: #1e1e1e;
}

.inv-table-title .inv-count {
    color: #94a3b8;
    font-size: 0.86rem;
    font-weight: 600;
}

.inv-tools {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.inv-search-box {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 12px;
    height: 40px;
    min-width: 260px;
    gap: 8px;
    transition: all 0.15s ease;
}

.inv-search-box:focus-within {
    background: #ffffff;
    border-color: #F2C12E;
    box-shadow: 0 0 0 3px rgba(242, 193, 46, 0.2);
}

.inv-search-box input {
    border: none;
    outline: none;
    background: transparent;
    font-size: 0.85rem;
    width: 100%;
    color: #1e1e1e;
}

.inv-search-box i {
    color: #94a3b8;
}

/* Table Design */
.clean-inv-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.clean-inv-table th {
    background: #fbfcfd;
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    padding: 12px 16px;
    border-top: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
}

.clean-inv-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f8fafc;
    vertical-align: middle;
    font-size: 0.88rem;
    color: #334155;
}

.clean-inv-table tbody tr:hover td {
    background: #fcfdfe;
}

.inv-product-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.inv-item-img {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    object-fit: cover;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    flex-shrink: 0;
}

.inv-item-info {
    display: flex;
    flex-direction: column;
}

.inv-item-name {
    font-weight: 700;
    color: #1e1e1e;
    line-height: 1.25;
}

.inv-item-category {
    font-size: 0.78rem;
    color: #64748b;
    margin-top: 2px;
}

/* Stock status meter pill */
.stock-meter-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}

.stock-meter-val {
    font-weight: 700;
    font-size: 0.95rem;
    min-width: 38px;
}

.stock-meter-bar {
    width: 70px;
    height: 6px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}

.stock-meter-fill {
    height: 100%;
    border-radius: 10px;
}

/* Status Badges */
.badge-inv-available {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #ecfdf5;
    color: #065f46;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid #a7f3d0;
}

.badge-inv-low {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fffbeb;
    color: #92400e;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid #fde68a;
}

.badge-inv-out {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fef2f2;
    color: #991b1b;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid #fecaca;
}

.btn-action-adjust {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-action-adjust:hover {
    background: #F2C12E;
    border-color: #D8AF18;
    color: #111111;
}

/* Stock Movement Drawer / Card */
.stock-drawer-card {
    background: #ffffff;
    border: 1px solid #eef0f2;
    border-radius: 14px;
    padding: 24px;
    margin-top: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
}

.stock-drawer-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.stock-drawer-head h2 {
    margin: 0;
    font-size: 28px;
    line-height: 1.3;
    font-weight: 800;
    color: #1e1e1e;
}

@media (max-width: 992px) {
    .inventory-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 576px) {
    .inventory-stats-grid {
        grid-template-columns: 1fr;
    }
    .inv-tools {
        width: 100%;
    }
    .inv-search-box {
        min-width: 100%;
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

.inv-item-img { object-fit: cover; background: #f8f7f3; }
.stock-drawer-head { align-items: center; gap: 16px; flex-wrap: wrap; padding-bottom: 18px; border-bottom: 1px solid var(--border); }
#stockMovementSection { scroll-margin-top: 92px; }
#stockMovementForm { gap: 18px; }
#stockMovementForm .grid { gap: 20px; align-items: start; }
#stockMovementForm .field { gap: 7px; }
#stockMovementForm .field label { font-size: 13px; }
#stockMovementForm input, #stockMovementForm select { min-height: 44px; font-size: 14px; }
#stockMovementForm textarea { min-height: 72px; font-size: 14px; line-height: 1.5; }
#stockMovementForm .action-help, #stockMovementForm .inventory-help-text { line-height: 1.5; margin-bottom: 0; }
#updateStockBtn { justify-self: end; width: auto; min-height: 42px; font-size: 13px; margin-top: 2px; }
.history-card-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
.history-card-head h2 { margin: 0; font-size: 28px; line-height: 1.3; }
.inv-section-caption { margin: 6px 0 0; color: var(--muted); font-size: 12px; }
.inventory-history { scroll-margin-top: 92px; }
.inventory-history table { min-width: 880px; table-layout: fixed; }
.inventory-history th { font-size: 11px; letter-spacing: .04em; text-transform: uppercase; background: #fafbfc; }
.inventory-history td { font-size: 13px; line-height: 1.6; overflow-wrap: anywhere; vertical-align: top; }
.inventory-history th, .inventory-history td { padding: 14px 16px; }
.inventory-history th:nth-child(1) { width: 13%; }
.inventory-history th:nth-child(2) { width: 19%; }
.inventory-history th:nth-child(3) { width: 13%; }
.inventory-history th:nth-child(4) { width: 9%; }
.inventory-history th:nth-child(5) { width: 16%; }
.inventory-history th:nth-child(6) { width: 30%; }
.inventory-history td:nth-child(4) { font-variant-numeric: tabular-nums; white-space: nowrap; }
.inventory-history td:last-child { color: var(--muted); }
@media (max-width: 600px) {
    .stock-drawer-head h2, .history-card-head h2 { font-size: 24px; }
    #stockMovementForm .grid { grid-template-columns: 1fr; }
    .history-card-head .kk-pagination { width: 100%; justify-content: flex-end; }
}
.kk-inventory-archive { width:min(480px,calc(100vw - 32px)); border:1px solid #e8e4d9; border-radius:18px; overflow:hidden; }
.kk-inventory-archive .kk-dialog-accent { display:none; }
.kk-inventory-archive .kk-dialog-body { padding:28px; }
.kk-inventory-archive .kk-dialog-icon { background:#fff1b8; color:#825d00; border-radius:12px; }
.kk-inventory-archive .kk-dialog-title { color:#242424; font-size:21px; }
.kk-inventory-archive .kk-dialog-message { color:#686253; font-size:14px; line-height:1.7; margin-bottom:0; }
.kk-inventory-archive .kk-dialog-actions { background:#faf9f5; padding:18px 24px; gap:10px; border-top:1px solid #eee9dd; }
.kk-inventory-archive .kk-dialog-actions .btn { min-height:42px; padding:10px 18px; border-radius:9px; font-weight:650; }
.kk-inventory-archive .btn-primary { background:#f7c62f; color:#242424; border:1px solid #e5b621; }
.kk-inventory-archive .btn-primary:hover { background:#eebc21; }
.kk-inventory-archive .btn-secondary { background:#fff; color:#514d43; border:1px solid #ded9ce; }

.inv-filter-form {display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin:0 0 20px;padding:16px;border:1px solid #eee3c5;border-radius:12px;background:#fffdf6}
.inv-filter-form label {display:flex;flex-direction:column;gap:6px;flex:1 1 140px;font-size:12px;font-weight:700;color:#5b513e}
.inv-filter-form .inv-filter-search {flex:2 1 200px}
.inv-filter-form input,.inv-filter-form select {width:100%;min-height:40px;padding:8px 10px;border:1px solid #ddd5c5;border-radius:8px;background:#fff;color:#29251f;font-size:13px}
.inv-filter-form input:focus-visible,.inv-filter-form select:focus-visible {outline:2px solid #b88400;outline-offset:2px}
.inv-filter-actions {display:flex;align-items:center;gap:8px;min-height:40px}
.inv-filter-actions .inv-filter-button {display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:9px 14px;border:1px solid transparent;border-radius:8px;font:inherit;font-size:13px;font-weight:700;line-height:20px;white-space:nowrap;text-decoration:none;cursor:pointer;transition:background-color .15s,border-color .15s,box-shadow .15s}
.inv-filter-actions .inv-filter-apply {background:#f2c12e;border-color:#e4b321;color:#29251f;box-shadow:0 2px 5px #9d740018}
.inv-filter-actions .inv-filter-apply:hover {background:#e9b920;border-color:#cda019}
.inv-filter-actions .inv-filter-reset {background:#fff;border-color:#ddd5c5;color:#62543b}
.inv-filter-actions .inv-filter-reset:hover {background:#fff5d8;border-color:#c9ab60;color:#352b18}
.inv-filter-actions .inv-filter-button:focus-visible {outline:2px solid #936800;outline-offset:3px}
.inv-filter-actions .inv-filter-button:active {box-shadow:inset 0 1px 3px #00000014}
@media(prefers-reduced-motion:reduce){.inv-filter-actions .inv-filter-button{transition:none}}
@media(max-width:600px){.inv-filter-form label{flex-basis:100%}.inv-filter-actions{width:100%}.inv-filter-actions button{flex:1}}
</style>

<?php if ($msg): ?><div class="alert alert-<?= $msgType === 'danger' ? 'danger' : 'success' ?>"><?= e($msg) ?></div><?php endif; ?>

<!-- Top 4 KPI Statistics Cards matching HueSuite Reference -->
<section class="inventory-stats-grid">
    <a class="inv-stat-card" href="<?= e($buildQuery(['stock_filter' => '', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list" <?= $stockFilter === '' ? 'aria-current="true"' : '' ?> title="View all products">
        <div class="inv-stat-info">
            <span class="inv-stat-val"><?= $totalProductsCount ?></span>
            <span class="inv-stat-lbl">Total Products</span>
        </div>
        <div class="inv-stat-icon inv-icon-total">
            <i class="bi bi-box-seam"></i>
        </div>
    </a>

    <a class="inv-stat-card" href="<?= e($buildQuery(['stock_filter' => 'available', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list" <?= $stockFilter === 'available' ? 'aria-current="true"' : '' ?> title="Filter Available Stock">
        <div class="inv-stat-info">
            <span class="inv-stat-val"><?= $availableStockCount ?></span>
            <span class="inv-stat-lbl">Available Stock</span>
        </div>
        <div class="inv-stat-icon inv-icon-available">
            <i class="bi bi-check-circle"></i>
        </div>
    </a>

    <a class="inv-stat-card" href="<?= e($buildQuery(['stock_filter' => 'low', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list" <?= $stockFilter === 'low' ? 'aria-current="true"' : '' ?> title="Filter Low Stock">
        <div class="inv-stat-info">
            <span class="inv-stat-val"><?= $lowStockCount ?></span>
            <span class="inv-stat-lbl">Low Stock</span>
        </div>
        <div class="inv-stat-icon inv-icon-low">
            <i class="bi bi-exclamation-triangle"></i>
        </div>
    </a>

    <a class="inv-stat-card" href="<?= e($buildQuery(['stock_filter' => 'out', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list" <?= $stockFilter === 'out' ? 'aria-current="true"' : '' ?> title="Filter Out of Stock">
        <div class="inv-stat-info">
            <span class="inv-stat-val"><?= $outOfStockCount ?></span>
            <span class="inv-stat-lbl">Out of Stock</span>
        </div>
        <div class="inv-stat-icon inv-icon-out">
            <i class="bi bi-x-circle"></i>
        </div>
    </a>
</section>

<!-- Filter Navigation Pills & Action Bar -->
<div class="inv-tabs-bar">
    <a class="inv-tab-btn <?= $stockFilter === '' ? 'active' : '' ?>" href="<?= e($buildQuery(['stock_filter' => '', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list">
        <span>All Products</span>
        <span class="badge-pill"><?= $totalProductsCount ?></span>
    </a>
    <a class="inv-tab-btn <?= $stockFilter === 'available' ? 'active' : '' ?>" href="<?= e($buildQuery(['stock_filter' => 'available', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list">
        <span>Available</span>
        <span class="badge-pill"><?= $availableStockCount ?></span>
    </a>
    <a class="inv-tab-btn <?= $stockFilter === 'low' ? 'active' : '' ?>" href="<?= e($buildQuery(['stock_filter' => 'low', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list">
        <span>Low Stock</span>
        <span class="badge-pill"><?= $lowStockCount ?></span>
    </a>
    <a class="inv-tab-btn <?= $stockFilter === 'out' ? 'active' : '' ?>" href="<?= e($buildQuery(['stock_filter' => 'out', 'inventory_page' => 1, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch])) ?>#inventory-list">
        <span>Out of Stock</span>
        <span class="badge-pill"><?= $outOfStockCount ?></span>
    </a>
    <?php if ($isAdmin): ?>
        <a class="btn btn-primary" href="<?= e(BASE_URL . '/modules/menu/index.php') ?>" style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:0.85rem;">
            <i class="bi bi-plus-lg"></i> Add / Edit Products
        </a>
    <?php endif; ?>
</div>

<!-- Product Inventory Table matching HueSuite reference -->
<section class="inv-table-card" id="inventory-list" aria-labelledby="inventory-list-title">
    <div class="inv-table-header">
        <div class="inv-table-title">
            <h2 id="inventory-list-title"><?= e($inventoryListTitle) ?></h2>
            <span class="inv-count">(<?= $totalInventoryItems ?> items)</span>
        </div>

        <div class="inv-tools">


            <?= render_pagination_controls($inventoryPage, $totalInventoryPages, static fn(int $p) => $buildQuery(['inventory_page' => $p, 'history_page' => $historyPage, 'inventory_search' => $inventorySearch]) . '#inventory-list') ?>
        </div>
    </div>

    <form class="inv-filter-form" method="get" action="index.php#inventory-list">
        <input type="hidden" name="inventory_page" value="1">
        <input type="hidden" name="history_page" value="<?= $historyPage ?>">
        <label class="inv-filter-search">Search<input type="search" name="inventory_search" value="<?= e($inventorySearch) ?>" placeholder="Product or category" autocomplete="off"></label>
        <label>Category<select name="category"><option value="0">All Categories</option><?php foreach ($inventoryCategories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $inventoryCategory === (int) $category['id'] ? 'selected' : '' ?>><?= e(strcasecmp(trim($category['category_name']), 'Silog Meals') === 0 ? 'Silog' : $category['category_name']) ?></option><?php endforeach; ?></select></label>
        <label>Stock status<select name="stock_filter"><?php foreach (['' => 'All stock', 'available' => 'Available', 'low' => 'Low stock', 'out' => 'Out of stock'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $stockFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Sort by<select name="sort"><?php foreach ($inventorySortOptions as $value => $option): ?><option value="<?= e($value) ?>" <?= $inventorySort === $value ? 'selected' : '' ?>><?= e($option[0]) ?></option><?php endforeach; ?></select></label>
        <label>Show<select name="per_page"><?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= $inventoryPerPage === $size ? 'selected' : '' ?>><?= $size ?> items</option><?php endforeach; ?></select></label>
        <div class="inv-filter-actions"><button class="inv-filter-button inv-filter-apply" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button><a class="inv-filter-button inv-filter-reset" href="index.php#inventory-list" aria-label="Reset all inventory filters"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset</a></div>
    </form>
    <div class="table-wrap" style="border:1px solid #f1f5f9;border-radius:10px;overflow:hidden;">
        <table class="clean-inv-table">
            <thead>
                <tr>
                    <th style="width:40%;">Product</th>
                    <th style="width:20%;">Category</th>
                    <th style="width:18%;">Stock</th>
                    <th style="width:12%;">Status</th>
                    <th style="width:10%;text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$inventoryItems): ?>
                    <tr><td colspan="5" style="text-align:center;padding:36px;color:#94a3b8;">No products match these filters. Try another category or reset the filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($inventoryItems as $row): ?>
                    <?php
                    $stockValue = (int) $row['stock'];
                    $isAvailable = $stockValue > 5 && $row['availability'] === 'Available';
                    $isLow = $stockValue > 0 && $stockValue <= 5;
                    $isOut = $stockValue <= 0 || $row['availability'] !== 'Available';

                    $fillPct = min(100, max(0, ($stockValue / 50) * 100));
                    $fillColor = $isAvailable ? '#10b981' : ($isLow ? '#f59e0b' : '#ef4444');
                    ?>
                    <tr>
                        <td>
                            <div class="inv-product-cell">
                                <img src="<?= e(menu_image_url($row['image'] ?? '') ?: BASE_URL . '/assets/img/food-placeholder.svg') ?>" alt="<?= e($row['item_name']) ?>" class="inv-item-img" loading="lazy" onerror="this.onerror=null;this.src='<?= BASE_URL ?>/assets/img/food-placeholder.svg';">
                                <div class="inv-item-info">
                                    <span class="inv-item-name"><?= e($row['item_name']) ?></span>
                                    <span class="inv-item-category">₱<?= number_format((float)$row['price'], 2) ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="font-weight:600;color:#475569;"><?= e($row['category_name']) ?></span>
                        </td>
                        <td>
                            <div class="stock-meter-wrap">
                                <span class="stock-meter-val" style="color:<?= $fillColor ?>;"><?= $stockValue ?></span>
                                <div class="stock-meter-bar">
                                    <div class="stock-meter-fill" style="width:<?= $fillPct ?>%;background:<?= $fillColor ?>;"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($isAvailable): ?>
                                <span class="badge-inv-available"><i class="bi bi-check-circle-fill" style="font-size:0.75rem;"></i> Available</span>
                            <?php elseif ($isLow): ?>
                                <span class="badge-inv-low"><i class="bi bi-exclamation-triangle-fill" style="font-size:0.75rem;"></i> Low Stock</span>
                            <?php else: ?>
                                <span class="badge-inv-out"><i class="bi bi-x-circle-fill" style="font-size:0.75rem;"></i> Out of Stock</span>
                            <?php endif; ?>

                            <?php if ($isInventory && !$isAdmin && isset($pendingInventoryRequests[(int) $row['id']])): ?>
                                <div style="margin-top:4px;"><span class="badge badge-warning" style="font-size:0.72rem;">Pending (<?= e($pendingInventoryRequests[(int) $row['id']]) ?>)</span></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex;gap:6px;justify-content:flex-end;">
                                <button type="button" class="btn-action-adjust" onclick="quickSelectProduct(<?= e(json_encode($row['item_name'])) ?>, <?= $stockValue ?>, <?= (int)$row['id'] ?>)" title="Adjust or Stock In/Out">
                                    <i class="bi bi-sliders"></i> Adjust
                                </button>
                                <?php if ($isAdmin): ?>
                                    <a href="<?= e(BASE_URL . '/modules/menu/index.php?menu_search=' . urlencode($row['item_name'])) ?>" class="btn-action-adjust" title="Edit in Menu">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </a>
                                <?php elseif ($isInventory): ?>
                                    <?php if (isset($pendingArchiveRequests[(int) $row['id']])): ?>
                                        <span class="badge badge-warning" style="font-size:0.72rem;">Archive Pending</span>
                                    <?php else: ?>
                                        <form method="post" style="margin:0;display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="archive_request">
                                            <input type="hidden" name="menu_item_id" value="<?= e($row['id']) ?>">
                                            <button class="btn btn-danger" type="submit" style="padding:6px 10px;font-size:0.78rem;" data-confirm-kind="inventory-archive" data-item-name="<?= e($row['item_name']) ?>" data-confirm="Submit an archive request for this item?" title="Request Archive"><i class="bi bi-archive"></i></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Stock Movement Form Card -->
<section class="stock-drawer-card" id="stockMovementSection">
    <div class="stock-drawer-head">
        <div><h2>Stock Movement &amp; Adjustments</h2></div>
        <span style="font-size:0.85rem;color:#64748b;"><?= $isInventory && !$isAdmin ? 'Staff Mode: Movements require Admin confirmation' : 'Quick Stock In, Stock Out, or Reconcile' ?></span>
    </div>

    <form class="form" method="post" action="index.php" id="stockMovementForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="stock">
        <input type="hidden" name="menu_item_id" id="menuItemId" value="">

        <div class="field">
            <label for="menuItemInput">Select Product</label>
            <input type="text" id="menuItemInput" class="stock-item-input" list="menuItemOptions" placeholder="Search a product, or use Adjust in the list above" autocomplete="off" required>

            <datalist id="menuItemOptions">
                <?php foreach ($items as $item): ?>
                    <option value="<?= e($item['item_name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <div id="selectedItemInfo" class="selected-item-info"></div>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label for="stockAction">Movement Type</label>
                <select name="stock_action" id="stockAction" required>
                    <option value="Stock In">Stock In</option>
                    <option value="Stock Out">Stock Out</option>
                    <option value="Adjustment">Adjustment</option>
                </select>
                <div id="actionHelp" class="action-help" style="font-size:0.8rem;color:#64748b;margin-top:6px;"><?= $isInventory && !$isAdmin ? 'Stock changes are submitted to Admin for approval before the menu stock is changed.' : 'Stock In adds newly received or restocked items to the current stock.' ?></div>
            </div>

            <div class="field">
                <label for="stockQuantity" id="quantityLabel">Quantity</label>
                <input type="number" id="stockQuantity" min="1" max="10000" step="1" name="quantity" inputmode="numeric" pattern="[0-9]+" required placeholder="Enter quantity (1–10,000)">
                <div id="quantityHelp" class="action-help" style="font-size:0.8rem;color:#64748b;margin-top:6px;">Enter whole pieces (1 to 10,000).</div>
            </div>
        </div>

        <div class="field">
            <label for="stockRemarks">Remarks / Notes</label>
            <textarea id="stockRemarks" name="remarks" class="remarks-input" rows="2" placeholder="e.g. Weekly supplier batch delivery, damage loss, or physical inventory count"></textarea>
            <p class="inventory-help-text" style="font-size:0.8rem;color:#64748b;margin-top:4px;"><?= $isInventory && !$isAdmin ? 'Your request will remain pending until an Admin reviews it.' : 'Add a brief note for tracking in the audit logs.' ?></p>
        </div>

        <button class="btn btn-primary" type="submit" id="updateStockBtn" style="padding:10px 22px;font-weight:700;"><i class="bi bi-arrow-left-right"></i> <?= $isInventory && !$isAdmin ? 'Submit Stock Request' : 'Record Stock Movement' ?></button>
    </form>
</section>

<section class="card inventory-history" id="stock-history" style="margin-top:24px">
    <div class="history-card-head">
        <div><h2>Recent Stock History</h2><p class="inv-section-caption">Stock changes, quantities, and staff activity.</p></div>
        <?= render_pagination_controls($historyPage, $totalHistoryPages, static fn(int $p) => $buildQuery(['history_page' => $p, 'inventory_page' => $inventoryPage, 'inventory_search' => $inventorySearch]) . '#stock-history') ?>
    </div>

    <div class="table-wrap" style="margin-top:18px;">
        <table>
            <thead><tr><th>Date &amp; Time</th><th>Item</th><th>Action</th><th>Movement / Level</th><th>User</th><th>Remarks</th></tr></thead>
            <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
<?php $logUnit = !empty($log['menu_item_id']) ? 'pcs' : (trim((string)($log['unit'] ?? '')) ?: null); $mv = kk_stock_movement_display((string)($log['action'] ?? ''), $log['quantity'] ?? 0, $logUnit); ?><tr>
                            <td><?= e(date('M d, h:i A', strtotime($log['created_at']))) ?></td>
                            <td><?= e($log['item_name'] ?? $log['ingredient_name'] ?? 'Unknown Item') ?></td>
                            <td><?= e($mv['action_label']) ?></td>
                            <td style="font-weight:700; color:<?= e($mv['color']) ?>; white-space:nowrap;"><?= e($mv['qty_text']) ?></td>
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

    const findMenuItem = (value, preferredId = null) => { if (preferredId) { const byId = menuItems.find(item => Number(item.id) === Number(preferredId)); if (byId && normalizeName(byId.name) === normalizeName(value)) return byId; }
        const normalized = normalizeName(value);
        return menuItems.find(item => normalizeName(item.name) === normalized);
    };

    const updateSelectedItem = () => {
        const preferredId = itemIdInput.dataset.preferredId ? Number(itemIdInput.dataset.preferredId) : null;
        const item = findMenuItem(itemInput.value, preferredId);

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
        const preferredId = itemIdInput.dataset.preferredId ? Number(itemIdInput.dataset.preferredId) : null;
        const item = findMenuItem(itemInput.value, preferredId);

        if (stockAction.value === 'Stock In') {
            quantityLabel.textContent = 'Quantity';
            stockQuantity.min = '1';
            stockQuantity.max = '10000';
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
                stockQuantity.max = String(Math.min(10000, item.stock));
                if (item.stock <= 0) {
                    stockQuantity.disabled = true;
                    stockQuantity.value = '';
                    quantityHelp.textContent = 'This item has no stock available to remove.';
                } else {
                    stockQuantity.disabled = false;
                    quantityHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Enter 1 to ' : '') ?> + item.stock + <?= json_encode($isInventory && !$isAdmin ? ' to request a stock removal. Admin approval is required.' : ' to remove stock. You can remove all remaining stock to make the item Out of Stock.') ?>;
                }
            } else {
                stockQuantity.max = '10000';
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
        stockQuantity.max = '10000';
        stockQuantity.disabled = false;
        stockQuantity.placeholder = 'Enter the correct total stock';
        actionHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Adjustment requests an Admin-approved correction to the exact stock quantity.' : 'Adjustment replaces the recorded stock with the exact correct quantity.') ?>;
        quantityHelp.textContent = <?= json_encode($isInventory && !$isAdmin ? 'Enter the corrected stock total for Admin review. Use Stock Out when removing stock.' : 'Enter the correct total stock. This number becomes the stock shown in POS. Use Stock Out when removing stock.') ?>;
    };

    itemInput.addEventListener('input', event => {
        if (event.isTrusted) {
            delete itemIdInput.dataset.preferredId;
        }
        updateSelectedItem();
    });
    itemInput.addEventListener('change', updateSelectedItem);
    stockAction.addEventListener('change', updateActionInfo);

    stockQuantity.addEventListener('keydown', event => {
        if (['e', 'E', '+', '-', '.'].includes(event.key)) {
            event.preventDefault();
        }
    });
    stockQuantity.addEventListener('input', () => {
        const cleaned = String(stockQuantity.value || '').replace(/\D+/g, '').slice(0, 5);
        stockQuantity.value = (cleaned !== '' && Number(cleaned) > 10000) ? '10000' : cleaned;
    });

    form.addEventListener('submit', event => {
        updateSelectedItem();

        const preferredId = itemIdInput.dataset.preferredId ? Number(itemIdInput.dataset.preferredId) : null;
        const item = findMenuItem(itemInput.value, preferredId);
        const quantity = /^\d+$/.test(String(stockQuantity.value || '').trim()) ? Number(stockQuantity.value.trim()) : NaN;

        if (!itemIdInput.value) {
            event.preventDefault();
            if (window.kkNotify) window.kkNotify('Please select a valid menu item.', 'warning', { title: 'Stock Validation' });
            itemInput.focus();
            return;
        }

        if (!Number.isInteger(quantity) || quantity < 0 || quantity > 10000) {
            event.preventDefault();
            if (window.kkNotify) window.kkNotify('Quantity must be a whole number between 1 and 10,000.', 'warning', { title: 'Stock Validation' });
            stockQuantity.focus();
            return;
        }

        if ((stockAction.value === 'Stock In' || stockAction.value === 'Stock Out') && quantity <= 0) {
            event.preventDefault();
            if (window.kkNotify) window.kkNotify('Quantity must be greater than 0 for Stock In and Stock Out.', 'warning', { title: 'Stock Validation' });
            stockQuantity.focus();
            return;
        }

        if (stockAction.value === 'Stock Out' && item && quantity > item.stock) {
            event.preventDefault();
            if (window.kkNotify) window.kkNotify(`Only ${item.stock} pcs are currently in stock.`, 'warning', { title: 'Insufficient Stock' });
            stockQuantity.focus();
            return;
        }

        if (stockAction.value === 'Adjustment' && quantity < 1) {
            event.preventDefault();
            if (window.kkNotify) window.kkNotify('Adjustment must set the stock to at least 1. Use Stock Out when removing all remaining stock.', 'warning', { title: 'Stock Validation' });
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

function quickSelectProduct(name, currentStock, itemId = null) {
    const itemInput = document.getElementById('menuItemInput');
    const stockQty = document.getElementById('stockQuantity');
    const movementSection = document.getElementById('stockMovementSection');

    if (itemInput) {
        const itemIdInput = document.getElementById('menuItemId'); if (itemIdInput) { if (itemId) itemIdInput.dataset.preferredId = String(itemId); else delete itemIdInput.dataset.preferredId; } itemInput.value = name;
        itemInput.dispatchEvent(new Event('input', { bubbles: true }));
        itemInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (movementSection) {
        movementSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (stockQty) {
        setTimeout(() => stockQty.focus(), 350);
    }
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
