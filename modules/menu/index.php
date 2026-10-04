<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'inventory']);

$isAdmin = can_access('admin');
$isInventory = can_access('inventory');

$page = 'menu';
$title = 'Menu Management';
$heading = 'Menu Management';
$msg = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
$menuPerPage = 10;
$menuPage = isset($_GET['menu_page']) ? (int) $_GET['menu_page'] : 1;
$menuSearch = trim($_GET['menu_search'] ?? '');

if ($menuPage < 1) {
    $menuPage = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'category') {
        if (!$isAdmin) {
            $_SESSION['flash_msg'] = 'Only Admin can add categories.';
            header('Location: index.php');
            exit;
        }

        $name = trim($_POST['category_name'] ?? '');
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $stmt->execute([$name]);
            $_SESSION['flash_msg'] = 'Category added.';
        }
        header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
        exit;
    }

    if ($action === 'item') {
        if (!$isAdmin) {
            $_SESSION['flash_msg'] = 'Only Admin can add menu items.';
            header('Location: index.php');
            exit;
        }

        $image = upload_menu_image($_FILES['image'] ?? []);
        $stmt = $conn->prepare("
            INSERT INTO menu_items (category_id, item_name, description, price, image, availability, promo_price, promo_start, promo_end)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['category_id'],
            trim($_POST['item_name']),
            trim($_POST['description']),
            $_POST['price'],
            $image,
            $_POST['availability'],
            $_POST['promo_price'] ?: null,
            $_POST['promo_start'] ?: null,
            $_POST['promo_end'] ?: null
        ]);
        $_SESSION['flash_msg'] = 'Menu item saved.';
        header('Location: index.php');
        exit;
    }
}

if (isset($_GET['toggle'])) {
    if (!$isAdmin) {
        $_SESSION['flash_msg'] = 'Only Admin can change menu availability.';
        header('Location: index.php');
        exit;
    }

    $id = (int) $_GET['toggle'];
    $stmt = $conn->prepare("SELECT id, item_name, stock, availability FROM menu_items WHERE id = ? AND is_archived = 0 LIMIT 1");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found.';
    } elseif ($item['availability'] === 'Available' && (int) $item['stock'] > 0) {
        $_SESSION['flash_msg'] = $item['item_name'] . ' still has ' . (int) $item['stock'] . ' pcs in stock. Use Inventory → Stock Out first before marking it Out of Stock.';
    } elseif ($item['availability'] === 'Available') {
        $update = $conn->prepare("UPDATE menu_items SET availability = 'Unavailable' WHERE id = ? AND is_archived = 0 AND stock = 0");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item marked unavailable because stock is 0.';
    } elseif ((int) $item['stock'] <= 0) {
        $_SESSION['flash_msg'] = 'This item is still Out of Stock. Add stock first before making it available.';
    } else {
        $update = $conn->prepare("UPDATE menu_items SET availability = 'Available' WHERE id = ? AND is_archived = 0 AND stock > 0");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item is available again.';
    }

    header('Location: index.php');
    exit;
}

if (isset($_GET['archive'])) {
    $id = (int) $_GET['archive'];

    if (!$isInventory && !$isAdmin) {
        $_SESSION['flash_msg'] = 'You do not have permission to archive menu items.';
        header('Location: index.php');
        exit;
    }

    $check = $conn->prepare("SELECT id, item_name, stock FROM menu_items WHERE id = ? AND is_archived = 0 LIMIT 1");
    $check->execute([$id]);
    $item = $check->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found or already archived.';
        header('Location: index.php');
        exit;
    }

    if ($isAdmin) {
        $update = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ?");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has been moved to Archived Items.';
        header('Location: index.php?archived=1');
        exit;
    } else {
        if ((int) $item['stock'] > 0) {
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
        header('Location: index.php');
        exit;
    }
}

if (isset($_GET['restore'])) {
    $id = (int) $_GET['restore'];

    if (!$isInventory && !$isAdmin) {
        $_SESSION['flash_msg'] = 'You do not have permission to restore menu items.';
        header('Location: index.php?archived=1');
        exit;
    }

    $check = $conn->prepare("SELECT id, item_name, stock FROM menu_items WHERE id = ? AND is_archived = 1 LIMIT 1");
    $check->execute([$id]);
    $item = $check->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found or already active.';
        header('Location: index.php?archived=1');
        exit;
    }

    if ($isAdmin) {
        $update = $conn->prepare("UPDATE menu_items SET is_archived = 0, availability = IF(stock > 0, 'Available', 'Unavailable') WHERE id = ?");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has been restored to active menu items.';
        header('Location: index.php');
        exit;
    } else {
        $pending = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Unarchive' AND menu_item_id = ? AND status = 'Pending' LIMIT 1");
        $pending->execute([$id]);
        if ($pending->fetch()) {
            $_SESSION['flash_msg'] = 'An unarchive request for this menu item is already pending Admin approval.';
        } else {
            $request = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Unarchive', ?, 'Menu unarchive request', ?)");
            $request->execute([$id, $_SESSION['u_id']]);
            $_SESSION['flash_msg'] = 'Unarchive request submitted for Admin approval.';
        }
        header('Location: index.php?archived=1');
        exit;
    }
}

$categories = $conn->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

$menuWhere = 'WHERE menu_items.is_archived = :archived';
if ($menuSearch !== '') {
    $menuWhere .= ' AND (menu_items.item_name LIKE :search_name OR categories.category_name LIKE :search_category)';
}

$menuCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items JOIN categories ON categories.id = menu_items.category_id $menuWhere");
$menuCountStmt->bindValue(':archived', $showArchived ? 1 : 0, PDO::PARAM_INT);

if ($menuSearch !== '') {
    $menuSearchLike = '%' . $menuSearch . '%';
    $menuCountStmt->bindValue(':search_name', $menuSearchLike, PDO::PARAM_STR);
    $menuCountStmt->bindValue(':search_category', $menuSearchLike, PDO::PARAM_STR);
}

$menuCountStmt->execute();
$totalMenuItems = (int) $menuCountStmt->fetchColumn();
$totalMenuPages = max(1, (int) ceil($totalMenuItems / $menuPerPage));

if ($menuPage > $totalMenuPages) {
    $menuPage = $totalMenuPages;
}

$menuOffset = ($menuPage - 1) * $menuPerPage;

$itemsStmt = $conn->prepare("SELECT menu_items.*, categories.category_name FROM menu_items JOIN categories ON categories.id = menu_items.category_id $menuWhere ORDER BY menu_items.created_at DESC, menu_items.id DESC LIMIT :limit OFFSET :offset");
$itemsStmt->bindValue(':archived', $showArchived ? 1 : 0, PDO::PARAM_INT);

if ($menuSearch !== '') {
    $itemsStmt->bindValue(':search_name', $menuSearchLike, PDO::PARAM_STR);
    $itemsStmt->bindValue(':search_category', $menuSearchLike, PDO::PARAM_STR);
}

$itemsStmt->bindValue(':limit', $menuPerPage, PDO::PARAM_INT);
$itemsStmt->bindValue(':offset', $menuOffset, PDO::PARAM_INT);
$itemsStmt->execute();
$items = $itemsStmt->fetchAll();

$archivedCount = (int) $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_archived = 1")->fetchColumn();

$pendingMenuRequests = [];
if ($isInventory || $isAdmin) {
    $pendingStmt = $conn->query("SELECT menu_item_id, request_type FROM approval_requests WHERE status = 'Pending' AND request_type IN ('Archive','Unarchive')");
    foreach ($pendingStmt->fetchAll() as $pendingRow) {
        $pendingMenuRequests[(int) $pendingRow['menu_item_id']] = $pendingRow['request_type'];
    }
}

$buildQuery = static function (array $params): string {
    $params = array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    });
    return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

include ROOT_PATH . '/includes/header.php';
?>
<style>
.menu-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}

.menu-card-title {
    min-width: 220px;
}

.menu-card-title h2 {
    margin: 0;
}

.menu-help-text {
    margin-top: 6px;
}

.menu-tools {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.menu-search-form {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

.menu-search-input {
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

.menu-search-input:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(242, 193, 46, .16);
}

.menu-search-input i {
    color: var(--muted);
}

.menu-search-input input {
    border: 0;
    outline: 0;
    width: 100%;
    min-width: 0;
}

.menu-pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    white-space: nowrap;
}

.menu-page-info {
    min-width: 96px;
    text-align: center;
}

@media (max-width: 1100px) {
    .menu-tools {
        width: 100%;
        justify-content: flex-start;
    }

    .menu-search-form {
        justify-content: flex-start;
    }
}

@media (max-width: 640px) {
    .menu-search-form,
    .menu-search-input,
    .menu-tools,
    .menu-pagination {
        width: 100%;
    }

    .menu-search-input {
        min-width: 0;
    }

    .menu-pagination {
        justify-content: space-between;
    }
}

.menu-item-cell {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 220px;
}

.menu-item-thumb {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    border-radius: 10px;
    object-fit: cover;
    border: 1px solid var(--border);
    background: var(--soft);
}

.menu-item-copy {
    min-width: 0;
}

.menu-item-copy strong {
    display: block;
}

.menu-item-copy .muted {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.menu-action-icon {
    width: 42px;
    min-width: 42px;
    height: 42px;
    padding: 0;
    border-radius: 10px;
}

.menu-action-disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

</style>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($isAdmin): ?>
<div class="grid grid-2">
    <section class="card">
        <h2>Add Menu Item</h2>
        <form class="form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="item">
            <div class="grid grid-2">
                <div class="field"><label>Item Name</label><input name="item_name" required></div>
                <div class="field"><label>Category</label><select name="category_id" required><?php foreach ($categories as $cat): ?><option value="<?= e($cat['id']) ?>"><?= e($cat['category_name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field"><label>Description</label><textarea name="description"></textarea></div>
            <div class="grid grid-2">
                <div class="field"><label>Price</label><input type="number" step="0.01" name="price" required></div>
                <div class="field"><label>Availability</label><input value="Available" disabled><small class="muted">Availability follows stock. New menu items start with default stock.</small></div>
            </div>
            <div class="grid grid-3">
                <div class="field"><label>Promo Price</label><input type="number" step="0.01" name="promo_price"></div>
                <div class="field"><label>Promo Start</label><input type="date" name="promo_start"></div>
                <div class="field"><label>Promo End</label><input type="date" name="promo_end"></div>
            </div>
            <div class="field"><label>Image</label><input type="file" name="image" accept="image/*"></div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i>Add Item</button>
        </form>
    </section>

    <section class="card">
        <h2>Add Category</h2>
        <form class="form" method="post">
            <input type="hidden" name="action" value="category">
            <div class="field"><label>Category Name</label><input name="category_name" required></div>
            <button class="btn btn-secondary" type="submit"><i class="bi bi-folder-plus"></i>Save Category</button>
        </form>
    </section>
</div>
<?php endif; ?>

<section class="card" style="margin-top:24px">
    <div class="menu-card-head">
        <div class="menu-card-title">
            <h2><?= $showArchived ? 'Archived Menu Items' : 'Menu Items' ?></h2>
            <p class="menu-help-text">
                <?php if ($menuSearch !== ''): ?>
                    Showing <?= count($items) ?> of <?= $totalMenuItems ?> matching <?= $showArchived ? 'archived ' : '' ?>menu items.
                <?php else: ?>
                    Showing <?= count($items) ?> of <?= $totalMenuItems ?> <?= $showArchived ? 'archived ' : '' ?>menu items.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($showArchived): ?>
            <a class="btn btn-secondary" href="index.php"><i class="bi bi-arrow-left"></i>Active Items</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="?archived=1"><i class="bi bi-archive"></i>Archived Items (<?= $archivedCount ?>)</a>
        <?php endif; ?>
    </div>

    <div class="menu-tools">
        <form class="menu-search-form" method="get" action="index.php">
            <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
            <input type="hidden" name="menu_page" value="1">
            <div class="menu-search-input">
                <i class="bi bi-search"></i>
                <input type="search" name="menu_search" value="<?= e($menuSearch) ?>" placeholder="Search menu items" autocomplete="off">
            </div>
            <button class="btn btn-primary" type="submit">Search</button>
            <?php if ($menuSearch !== ''): ?>
                <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => 1])) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <div class="menu-pagination">
            <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => $menuPage - 1, 'menu_search' => $menuSearch])) ?>" <?= $menuPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
            <strong class="menu-page-info">Page <?= $menuPage ?> of <?= $totalMenuPages ?></strong>
            <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => $menuPage + 1, 'menu_search' => $menuSearch])) ?>" <?= $menuPage >= $totalMenuPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:18px;">
        <table>
            <thead><tr><th>Item</th><th>Category</th><th>Price</th><th>Promo</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($items): ?>
                    <?php foreach ($items as $row): ?>
                    <tr>
                        <td>
                            <div class="menu-item-cell">
                                <?php if (!empty($row['image'])): ?>
                                    <img class="menu-item-thumb" src="<?= BASE_URL ?>/uploads/menu/<?= e($row['image']) ?>" alt="<?= e($row['item_name']) ?>">
                                <?php else: ?>
                                    <img class="menu-item-thumb" src="<?= BASE_URL ?>/assets/img/food-placeholder.svg" alt="">
                                <?php endif; ?>
                                <div class="menu-item-copy">
                                    <strong><?= e($row['item_name']) ?></strong>
                                    <span class="muted"><?= e($row['description']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($row['category_name']) ?></td>
                        <td><?= money($row['price']) ?></td>
                        <td><?= $row['promo_price'] ? money($row['promo_price']) : '<span class="muted">None</span>' ?></td>
                        <td><?php if ($showArchived): ?><span class="badge badge-muted">Archived</span><?php elseif ((int) $row['stock'] <= 0): ?><span class="badge badge-danger">Out of Stock</span><?php elseif ((int) $row['stock'] <= 5): ?><span class="badge badge-warning">Low Stock</span><?php else: ?><span class="badge badge-success">Available</span><?php endif; ?></td>
                        <td class="actions">
                            <?php if ($showArchived): ?>
                                <?php if (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Unarchive'): ?>
                                    <span class="badge badge-warning">Awaiting Admin Approval</span>
                                <?php elseif ($isAdmin): ?>
                                    <a class="btn btn-secondary" title="Restore to Active Items" data-confirm="Restore <?= e($row['item_name']) ?> to active menu items?" href="?archived=1&restore=<?= e($row['id']) ?>"><i class="bi bi-arrow-counterclockwise"></i>Restore</a>
                                <?php elseif ($isInventory): ?>
                                    <a class="btn btn-secondary menu-action-icon" title="Request Unarchive" aria-label="Request Unarchive" data-confirm="Submit this unarchive request for Admin approval?" href="?archived=1&restore=<?= e($row['id']) ?>"><i class="bi bi-arrow-counterclockwise"></i></a>
                                <?php else: ?>
                                    <span class="badge badge-muted" title="Unarchive requires Inventory Staff request and Admin approval.">Admin Approval Required</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($isAdmin): ?>
                                    <a class="btn btn-secondary" href="?toggle=<?= e($row['id']) ?>"><i class="bi bi-arrow-repeat"></i>Availability</a>
                                    <a class="btn btn-secondary" title="Archive Menu Item" data-confirm="Archive <?= e($row['item_name']) ?>? It will be moved to archived items." href="?archive=<?= e($row['id']) ?>"><i class="bi bi-archive"></i>Archive</a>
                                <?php elseif ($isInventory): ?>
                                    <?php $stockValue = (int) $row['stock']; ?>
                                    <?php if (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Archive'): ?>
                                        <span class="badge badge-warning">Awaiting Admin Approval</span>
                                    <?php elseif ($stockValue <= 0): ?>
                                        <a class="btn btn-secondary" title="Request Archive" aria-label="Request Archive" data-confirm="Submit this archive request for Admin approval?" href="?archive=<?= e($row['id']) ?>"><i class="bi bi-archive"></i>Archive</a>
                                    <?php else: ?>
                                        <span class="btn btn-secondary menu-action-disabled" title="Archive requires 0 stock. Use Stock Out first." aria-label="Archive requires 0 stock"><i class="bi bi-archive"></i>Archive</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;"><?= $showArchived ? ($menuSearch !== '' ? 'No archived menu items match your search.' : 'No archived menu items.') : ($menuSearch !== '' ? 'No menu items match your search.' : 'No menu items found.') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
