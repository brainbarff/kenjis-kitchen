<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'menu';
$title = 'Menu Management';
$heading = 'Menu Management';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'category') {
        $name = trim($_POST['category_name'] ?? '');
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $stmt->execute([$name]);
            $msg = 'Category added.';
        }
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
        $msg = 'Menu item saved.';
    }
}

if (isset($_GET['toggle'])) {
    $id = (int) $_GET['toggle'];
    $stmt = $conn->prepare("UPDATE menu_items SET availability = IF(availability = 'Available', 'Unavailable', 'Available') WHERE id = ?");
    $stmt->execute([$id]);
    redirect('/modules/menu/index.php');
}

if (isset($_GET['delete'])) {
    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    redirect('/modules/menu/index.php');
}

$categories = $conn->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();
$items = $conn->query("
    SELECT menu_items.*, categories.category_name
    FROM menu_items
    JOIN categories ON categories.id = menu_items.category_id
    ORDER BY menu_items.created_at DESC
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
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
    <div class="page-head"><h2>Menu Items</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Item</th><th>Category</th><th>Price</th><th>Promo</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($items as $row): ?>
                <tr>
                    <td><strong><?= e($row['item_name']) ?></strong><br><span class="muted"><?= e($row['description']) ?></span></td>
                    <td><?= e($row['category_name']) ?></td>
                    <td><?= money($row['price']) ?></td>
                    <td><?= $row['promo_price'] ? money($row['promo_price']) : '<span class="muted">None</span>' ?></td>
                    <td><span class="badge <?= $row['availability'] === 'Available' ? 'badge-success' : 'badge-muted' ?>"><?= e($row['availability']) ?></span></td>
                    <td class="actions">
                        <a class="btn btn-secondary" href="?toggle=<?= e($row['id']) ?>"><i class="bi bi-arrow-repeat"></i></a>
                        <a class="btn btn-danger" data-confirm="Delete this item?" href="?delete=<?= e($row['id']) ?>"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
