<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

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
    $id = (int) $_GET['toggle'];
    $stmt = $conn->prepare("UPDATE menu_items SET availability = IF(availability = 'Available', 'Unavailable', 'Available') WHERE id = ? AND is_archived = 0");
    $stmt->execute([$id]);
    header('Location: index.php');
    exit;
}

if (isset($_GET['archive'])) {
    $id = (int) $_GET['archive'];
    $stmt = $conn->prepare("UPDATE menu_items SET is_archived = 1 WHERE id = ? AND is_archived = 0");
    $stmt->execute([$id]);
    $_SESSION['flash_msg'] = 'Menu item archived.';
    header('Location: index.php');
    exit;
}

if (isset($_GET['restore'])) {
    $id = (int) $_GET['restore'];
    $stmt = $conn->prepare("UPDATE menu_items SET is_archived = 0 WHERE id = ? AND is_archived = 1");
    $stmt->execute([$id]);
    $_SESSION['flash_msg'] = 'Menu item restored.';
    header('Location: index.php?archived=1');
    exit;
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
</style>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
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
                <div class="field"><label>Availability</label><select name="availability"><option>Available</option><option>Unavailable</option></select></div>
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
                        <td><strong><?= e($row['item_name']) ?></strong><br><span class="muted"><?= e($row['description']) ?></span></td>
                        <td><?= e($row['category_name']) ?></td>
                        <td><?= money($row['price']) ?></td>
                        <td><?= $row['promo_price'] ? money($row['promo_price']) : '<span class="muted">None</span>' ?></td>
                        <td><span class="badge <?= $row['availability'] === 'Available' && !$showArchived ? 'badge-success' : 'badge-muted' ?>"><?= $showArchived ? 'Archived' : e($row['availability']) ?></span></td>
                        <td class="actions">
                            <?php if ($showArchived): ?>
                                <a class="btn btn-secondary" data-confirm="Restore this menu item?" href="?archived=1&restore=<?= e($row['id']) ?>"><i class="bi bi-arrow-counterclockwise"></i></a>
                            <?php else: ?>
                                <a class="btn btn-secondary" href="?toggle=<?= e($row['id']) ?>"><i class="bi bi-arrow-repeat"></i></a>
                                <a class="btn btn-danger" data-confirm="Archive this menu item?" href="?archive=<?= e($row['id']) ?>"><i class="bi bi-archive"></i></a>
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
