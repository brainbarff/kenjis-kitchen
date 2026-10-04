<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'inventory']);

// Automatically apply any scheduled prices that have matured
if (file_exists(ROOT_PATH . '/api/price_helpers.php')) {
    require_once ROOT_PATH . '/api/price_helpers.php';
    processPendingPriceUpdates($conn);
}

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

    if ($action === 'update_image') {
        if (!$isAdmin) {
            $_SESSION['flash_msg'] = 'Only Admin can update menu images.';
            header('Location: index.php');
            exit;
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0 && !empty($_FILES['image']['name'])) {
            $image = upload_menu_image($_FILES['image']);
            if ($image) {
                $stmt = $conn->prepare("UPDATE menu_items SET image = ? WHERE id = ?");
                $stmt->execute([$image, $itemId]);
                $_SESSION['flash_msg'] = 'Photo updated successfully.';
            } else {
                $_SESSION['flash_msg'] = 'Failed to upload photo. Please ensure it is a valid JPG/PNG/WebP under 3 MB.';
            }
        }
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

    $check = $conn->prepare("SELECT id, item_name, stock FROM menu_items WHERE id = ? AND is_archived = 0 LIMIT 1");
    $check->execute([$id]);
    $item = $check->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found or already archived.';
    } elseif ($isAdmin) {
        // Admin direct archive
        $update = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ?");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has been archived.';
    } elseif ($isInventory) {
        // Inventory staff approval request flow
        if ((int) $item['stock'] > 0) {
            $_SESSION['flash_msg'] = 'Only menu items with 0 stock can be requested for archiving. Use Stock Out first.';
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
    } else {
        $_SESSION['flash_msg'] = 'Unauthorized action.';
    }

    header('Location: index.php');
    exit;
}

if (isset($_GET['restore'])) {
    $id = (int) $_GET['restore'];

    $check = $conn->prepare("SELECT id, item_name, stock FROM menu_items WHERE id = ? AND is_archived = 1 LIMIT 1");
    $check->execute([$id]);
    $item = $check->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found or already active.';
    } elseif ($isAdmin) {
        // Admin direct restore
        $update = $conn->prepare("UPDATE menu_items SET is_archived = 0 WHERE id = ?");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has been restored to active menu.';
    } elseif ($isInventory) {
        // Inventory staff approval request flow
        $pending = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Unarchive' AND menu_item_id = ? AND status = 'Pending' LIMIT 1");
        $pending->execute([$id]);
        if ($pending->fetch()) {
            $_SESSION['flash_msg'] = 'An unarchive request for this menu item is already pending Admin approval.';
        } else {
            $request = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Unarchive', ?, 'Menu unarchive request', ?)");
            $request->execute([$id, $_SESSION['u_id']]);
            $_SESSION['flash_msg'] = 'Unarchive request submitted for Admin approval.';
        }
    } else {
        $_SESSION['flash_msg'] = 'Unauthorized action.';
    }

    header('Location: index.php?archived=1');
    exit;
}

$categories = $conn->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

$selectedCategory = trim($_GET['category_id'] ?? '');

$menuWhere = 'WHERE menu_items.is_archived = :archived';
if ($selectedCategory !== '' && $selectedCategory !== 'all') {
    $menuWhere .= ' AND menu_items.category_id = :category_id';
}
if ($menuSearch !== '') {
    $menuWhere .= ' AND (menu_items.item_name LIKE :search_name OR categories.category_name LIKE :search_category)';
}

$menuCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items JOIN categories ON categories.id = menu_items.category_id $menuWhere");
$menuCountStmt->bindValue(':archived', $showArchived ? 1 : 0, PDO::PARAM_INT);

if ($selectedCategory !== '' && $selectedCategory !== 'all') {
    $menuCountStmt->bindValue(':category_id', (int)$selectedCategory, PDO::PARAM_INT);
}
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

if ($selectedCategory !== '' && $selectedCategory !== 'all') {
    $itemsStmt->bindValue(':category_id', (int)$selectedCategory, PDO::PARAM_INT);
}
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
    min-width: 42px;
    height: 42px;
    padding: 0 14px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

/* Icon-only variant (no text) keeps the square shape */
.menu-action-icon:not(:has(span)):not(:has(a)) {
    width: 42px;
    padding: 0;
}

.menu-action-disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.custom-file-upload {
    display: flex;
    align-items: center;
    min-height: 50px;
    height: 50px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--white);
    padding: 0 10px;
    width: 100%;
    box-sizing: border-box;
    cursor: pointer;
    overflow: hidden;
    gap: 10px;
}

.custom-file-upload input[type="file"] {
    display: none;
}

.custom-file-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--soft, #f4f4f4);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--dark);
    white-space: nowrap;
    cursor: pointer;
    flex-shrink: 0;
}

.custom-file-text {
    font-size: 0.88rem;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

</style>
<script>
function previewMenuImage(input) {
    var preview = document.getElementById('menuImagePreview');
    var textSpan = document.getElementById('menuImageFileName');
    if (input.files && input.files[0]) {
        var file = input.files[0];
        if (textSpan) textSpan.textContent = file.name;
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        if (textSpan) textSpan.textContent = 'No file chosen';
        preview.src = '';
        preview.style.display = 'none';
    }
}

function submitQuickImageUpload(input, itemId) {
    if (!input.files || !input.files[0]) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';
    form.enctype = 'multipart/form-data';
    form.style.display = 'none';

    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'update_image';
    form.appendChild(actionInput);

    var idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'item_id';
    idInput.value = itemId;
    form.appendChild(idInput);

    var fileClone = input.cloneNode(true);
    // Move the actual file input to form to preserve files property
    input.parentNode.appendChild(fileClone);
    form.appendChild(input);

    document.body.appendChild(form);
    form.submit();
}
</script>

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
                <div class="field">
                    <label>Item Photo <span class="muted" style="font-weight:400; font-size: 0.8rem;">(jpg/png/webp, max 3 MB)</span></label>
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <label class="custom-file-upload" for="menuImageInput">
                            <span class="custom-file-btn"><i class="bi bi-cloud-arrow-up"></i> Browse</span>
                            <span class="custom-file-text" id="menuImageFileName">No file chosen</span>
                            <input type="file" name="image" accept="image/*" id="menuImageInput" onchange="previewMenuImage(this)">
                        </label>
                        <img id="menuImagePreview" src="" alt="" style="display:none; width:48px; height:48px; object-fit:cover; border-radius:10px; border:1px solid var(--border); flex:0 0 48px;">
                    </div>
                    <small class="muted">Add a photo so customers can see what this item looks like on the online menu.</small>
                </div>
            </div>
            <div class="grid grid-3">
                <div class="field"><label>Promo Price</label><input type="number" step="0.01" name="promo_price"></div>
                <div class="field"><label>Promo Start</label><input type="date" name="promo_start"></div>
                <div class="field"><label>Promo End</label><input type="date" name="promo_end"></div>
            </div>
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
        <?php elseif ($archivedCount > 0): ?>
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
            <select name="category_id" class="form-select" onchange="this.form.submit()" style="padding: 9px 14px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.88rem; font-weight: 600; background: var(--white); color: var(--text);">
                <option value="all">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['id']) ?>" <?= $selectedCategory === (string)$cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Filter</button>
            <?php if ($isAdmin): ?>
                <button type="button" class="btn btn-warning" onclick="openBulkPriceModal()" style="display:inline-flex; align-items:center; gap:6px; background:#f59e0b; color:#111; font-weight:700; border:none;">
                    <i class="bi bi-tag-fill"></i>Bulk Adjust Prices
                </button>
            <?php endif; ?>
            <?php if ($menuSearch !== '' || ($selectedCategory !== '' && $selectedCategory !== 'all')): ?>
                <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => 1])) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <div class="menu-pagination">
            <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => $menuPage - 1, 'menu_search' => $menuSearch, 'category_id' => $selectedCategory])) ?>" <?= $menuPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
            <strong class="menu-page-info">Page <?= $menuPage ?> of <?= $totalMenuPages ?></strong>
            <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => $menuPage + 1, 'menu_search' => $menuSearch, 'category_id' => $selectedCategory])) ?>" <?= $menuPage >= $totalMenuPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
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
                        <td>
                            <div><strong><?= money($row['price']) ?></strong></div>
                            <?php if (!empty($row['pending_price']) && !empty($row['pending_price_effective'])): ?>
                                <span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:0.75rem; display:inline-block; margin-top:4px; padding:2px 6px; border-radius:6px;" title="Scheduled price change">
                                    <i class="bi bi-clock-history"></i> Pending Price Increase: <?= money($row['pending_price']) ?> on <?= date('M j, Y g:i A', strtotime($row['pending_price_effective'])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['promo_price'] ? money($row['promo_price']) : '<span class="muted">None</span>' ?></td>
                        <td><?php if ($showArchived): ?><span class="badge badge-muted">Archived</span><?php elseif ((int) $row['stock'] <= 0): ?><span class="badge badge-danger">Out of Stock</span><?php elseif ((int) $row['stock'] <= 5): ?><span class="badge badge-warning">Low Stock</span><?php else: ?><span class="badge badge-success">Available</span><?php endif; ?></td>
                        <td class="actions">
                            <?php if ($showArchived): ?>
                                <?php if ($isAdmin): ?>
                                    <a class="btn btn-secondary menu-action-icon" title="Restore to Active Menu" aria-label="Restore" data-confirm="Restore this item to the active customer menu?" href="?archived=1&restore=<?= e($row['id']) ?>"><i class="bi bi-arrow-counterclockwise"></i>Restore</a>
                                <?php elseif (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Unarchive'): ?>
                                    <span class="badge badge-warning">Awaiting Admin Approval</span>
                                <?php elseif ($isInventory): ?>
                                    <a class="btn btn-secondary menu-action-icon" title="Request Unarchive" aria-label="Request Unarchive" data-confirm="Submit this unarchive request for Admin approval?" href="?archived=1&restore=<?= e($row['id']) ?>"><i class="bi bi-arrow-counterclockwise"></i></a>
                                <?php else: ?>
                                    <span class="badge badge-muted">Archived</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($isAdmin): ?>
                                    <button type="button" class="btn btn-secondary" onclick="openItemPriceModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['item_name'])) ?>', <?= (float)$row['price'] ?>, '<?= !empty($row['pending_price']) ? (float)$row['pending_price'] : '' ?>', '<?= !empty($row['pending_price_effective']) ? date('Y-m-d\TH:i', strtotime($row['pending_price_effective'])) : '' ?>')" title="Update or Schedule Base Price">
                                        <i class="bi bi-pencil-square"></i>Edit / Price
                                    </button>
                                    <a class="btn btn-secondary" href="?toggle=<?= e($row['id']) ?>"><i class="bi bi-arrow-repeat"></i>Availability</a>
                                    <label class="btn btn-secondary" style="cursor: pointer; margin: 0;" title="Upload or Change Photo">
                                        <i class="bi bi-image"></i>Photo
                                        <input type="file" name="image" accept="image/*" style="display:none;" onchange="submitQuickImageUpload(this, <?= (int)$row['id'] ?>)">
                                    </label>
                                    <a class="btn btn-danger" title="Archive Menu Item" aria-label="Archive" data-confirm="Archive this item? It will be hidden from customer ordering." href="?archive=<?= e($row['id']) ?>"><i class="bi bi-archive"></i>Archive</a>
                                <?php else: ?>
                                    <?php $stockValue = (int) $row['stock']; ?>
                                    <?php if (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Archive'): ?>
                                        <span class="badge badge-warning">Awaiting Admin Approval</span>
                                    <?php elseif ($stockValue <= 0 && $isInventory): ?>
                                        <a class="btn btn-danger" title="Request Archive" aria-label="Request Archive" data-confirm="Submit this archive request for Admin approval?" href="?archive=<?= e($row['id']) ?>"><i class="bi bi-archive"></i>Request Archive</a>
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

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRICE ADJUSTMENT MODALS & JAVASCRIPT
     ═══════════════════════════════════════════════════════════════════════════ -->

<!-- 1. Single Item Price Modal -->
<div id="itemPriceModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:var(--white); border-radius:14px; max-width:440px; width:100%; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.25); position:relative; border:1px solid var(--border);">
        <button type="button" onclick="closeItemPriceModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--muted);">&times;</button>
        <h3 style="margin-top:0; margin-bottom:6px; font-size:1.2rem; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-tag-fill" style="color:#f59e0b;"></i> Edit / Adjust Price
        </h3>
        <p class="muted" style="margin-bottom:18px; font-size:0.85rem;" id="itemPriceModalSubtitle">Update base price for item.</p>

        <form id="itemPriceForm" onsubmit="submitItemPrice(event)">
            <input type="hidden" id="priceItemId">
            
            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Current Price</label>
                <input type="text" id="priceCurrentDisplay" readonly style="background:var(--soft); font-weight:700; color:var(--muted);">
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">New Base Price (₱) <span style="color:var(--danger)">*</span></label>
                <input type="number" step="0.01" min="0" id="priceNewInput" required placeholder="e.g. 150.00" style="font-size:1.05rem; font-weight:700;">
            </div>

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">
                    Effective Date &amp; Time <span class="muted" style="font-weight:400; font-size:0.75rem;">(Optional — leave blank for immediate)</span>
                </label>
                <input type="datetime-local" id="priceEffectiveInput">
                <small class="muted" style="display:block; margin-top:4px; font-size:0.75rem;">
                    If set in the future, it will be saved as a pending scheduled price update and display a badge in the table.
                </small>
            </div>

            <div id="pendingPriceNotice" style="display:none; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:10px; margin-bottom:16px; font-size:0.82rem; color:#92400e;">
                <div style="font-weight:700; margin-bottom:2px;"><i class="bi bi-info-circle"></i> Currently Scheduled:</div>
                <div id="pendingPriceNoticeText"></div>
                <button type="button" onclick="cancelPendingPrice()" style="margin-top:6px; background:#fff; border:1px solid #b45309; color:#b45309; padding:4px 8px; border-radius:6px; font-size:0.75rem; font-weight:700; cursor:pointer;">
                    Cancel Scheduled Update
                </button>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeItemPriceModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveItemPrice" style="background:#f59e0b; border:none; color:#111; font-weight:700;">
                    Save Price
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Bulk Price Adjustment Modal -->
<div id="bulkPriceModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:var(--white); border-radius:14px; max-width:480px; width:100%; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.25); position:relative; border:1px solid var(--border);">
        <button type="button" onclick="closeBulkPriceModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--muted);">&times;</button>
        <h3 style="margin-top:0; margin-bottom:6px; font-size:1.2rem; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-tags-fill" style="color:#f59e0b;"></i> Bulk Adjust Prices
        </h3>
        <p class="muted" style="margin-bottom:18px; font-size:0.85rem;">Adjust base prices across an entire category or all menu items.</p>

        <form id="bulkPriceForm" onsubmit="submitBulkPrice(event)">
            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Target Selection</label>
                <select id="bulkCategorySelect" style="width:100%; padding:9px; border:1px solid var(--border); border-radius:var(--radius); font-weight:600;">
                    <option value="all">★ All Menu Items (Entire Menu)</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']) ?>">Category: <?= e($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-2" style="margin-bottom:14px;">
                <div class="field">
                    <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Operation</label>
                    <select id="bulkOperation" style="width:100%; padding:9px; border:1px solid var(--border); border-radius:var(--radius); font-weight:600;">
                        <option value="increase">Increase (+)</option>
                        <option value="decrease">Decrease (-)</option>
                    </select>
                </div>
                <div class="field">
                    <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Adjustment Type</label>
                    <select id="bulkAdjustType" style="width:100%; padding:9px; border:1px solid var(--border); border-radius:var(--radius); font-weight:600;">
                        <option value="fixed">Fixed Amount (₱)</option>
                        <option value="percent">Percentage (%)</option>
                    </select>
                </div>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Adjustment Value <span style="color:var(--danger)">*</span></label>
                <input type="number" step="0.01" min="0.01" id="bulkAdjustValue" required placeholder="e.g. 10.00 or 5 for 5%" style="font-size:1.05rem; font-weight:700;">
            </div>

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">
                    Effective Date &amp; Time <span class="muted" style="font-weight:400; font-size:0.75rem;">(Optional — leave blank for immediate)</span>
                </label>
                <input type="datetime-local" id="bulkEffectiveInput">
                <small class="muted" style="display:block; margin-top:4px; font-size:0.75rem;">
                    Future dates schedule price updates for automatic activation at that timestamp.
                </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeBulkPriceModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveBulkPrice" style="background:#f59e0b; border:none; color:#111; font-weight:700;">
                    Apply Bulk Adjustments
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openItemPriceModal(id, name, price, pendingPrice, pendingEffective) {
    document.getElementById('priceItemId').value = id;
    document.getElementById('itemPriceModalSubtitle').textContent = name;
    document.getElementById('priceCurrentDisplay').value = '₱' + Number(price).toFixed(2);
    document.getElementById('priceNewInput').value = pendingPrice ? Number(pendingPrice).toFixed(2) : Number(price).toFixed(2);
    document.getElementById('priceEffectiveInput').value = pendingEffective || '';

    var notice = document.getElementById('pendingPriceNotice');
    var noticeText = document.getElementById('pendingPriceNoticeText');
    if (pendingPrice && pendingEffective) {
        noticeText.textContent = '₱' + Number(pendingPrice).toFixed(2) + ' effective on ' + pendingEffective.replace('T', ' ');
        notice.style.display = 'block';
    } else {
        notice.style.display = 'none';
    }

    document.getElementById('itemPriceModal').style.display = 'flex';
}

function closeItemPriceModal() {
    document.getElementById('itemPriceModal').style.display = 'none';
}

function openBulkPriceModal() {
    document.getElementById('bulkPriceModal').style.display = 'flex';
}

function closeBulkPriceModal() {
    document.getElementById('bulkPriceModal').style.display = 'none';
}

async function submitItemPrice(e) {
    e.preventDefault();
    var btn = document.getElementById('btnSaveItemPrice');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var payload = {
        action: 'single',
        item_id: document.getElementById('priceItemId').value,
        new_price: document.getElementById('priceNewInput').value,
        effective_at: document.getElementById('priceEffectiveInput').value
    };

    try {
        var res = await fetch('<?= BASE_URL ?>/api/update_price.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (data.ok) {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Failed to update price.');
            btn.disabled = false;
            btn.textContent = 'Save Price';
        }
    } catch (err) {
        alert('Network error while saving price.');
        btn.disabled = false;
        btn.textContent = 'Save Price';
    }
}

async function cancelPendingPrice() {
    if (!confirm('Cancel this scheduled price update?')) return;
    var itemId = document.getElementById('priceItemId').value;
    try {
        var res = await fetch('<?= BASE_URL ?>/api/update_price.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel_scheduled', item_id: itemId })
        });
        var data = await res.json();
        if (data.ok) {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Failed to cancel scheduled update.');
        }
    } catch (_) {
        alert('Network error.');
    }
}

async function submitBulkPrice(e) {
    e.preventDefault();
    var btn = document.getElementById('btnSaveBulkPrice');
    btn.disabled = true;
    btn.textContent = 'Applying…';

    var payload = {
        action: 'bulk',
        category_id: document.getElementById('bulkCategorySelect').value,
        operation: document.getElementById('bulkOperation').value,
        adjust_type: document.getElementById('bulkAdjustType').value,
        adjust_value: document.getElementById('bulkAdjustValue').value,
        effective_at: document.getElementById('bulkEffectiveInput').value
    };

    try {
        var res = await fetch('<?= BASE_URL ?>/api/update_price.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (data.ok) {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Bulk adjustment failed.');
            btn.disabled = false;
            btn.textContent = 'Apply Bulk Adjustments';
        }
    } catch (err) {
        alert('Network error while submitting bulk adjustments.');
        btn.disabled = false;
        btn.textContent = 'Apply Bulk Adjustments';
    }
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
