<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'inventory']);

$page = 'inventory';
$title = 'Inventory Management';
$heading = 'Inventory Management';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $stmt = $conn->prepare("
            INSERT INTO inventory (ingredient_name, unit, current_stock, low_stock, supplier)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($_POST['ingredient_name']),
            trim($_POST['unit']),
            $_POST['current_stock'],
            $_POST['low_stock'],
            trim($_POST['supplier'])
        ]);
        $msg = 'Ingredient added.';
    }

    if ($action === 'stock') {
        $id = (int) $_POST['inventory_id'];
        $qty = (float) $_POST['quantity'];
        $type = $_POST['stock_action'];
        $signed = in_array($type, ['Stock Out', 'Waste/Spoilage'], true) ? -$qty : $qty;

        $stmt = $conn->prepare("UPDATE inventory SET current_stock = current_stock + ? WHERE id = ?");
        $stmt->execute([$signed, $id]);

        $stmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, user_id, action, quantity, remarks) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, $_SESSION['u_id'], $type, $qty, trim($_POST['remarks'])]);
        $msg = 'Stock updated.';
    }
}

if (isset($_GET['delete'])) {
    $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    redirect('/modules/inventory/index.php');
}

$items = $conn->query("SELECT * FROM inventory ORDER BY ingredient_name")->fetchAll();
$low = $conn->query("SELECT COUNT(*) total FROM inventory WHERE current_stock <= low_stock AND current_stock > 0")->fetch();
$out = $conn->query("SELECT COUNT(*) total FROM inventory WHERE current_stock <= 0")->fetch();
$logs = $conn->query("
    SELECT inventory_logs.*, inventory.ingredient_name, users.full_name
    FROM inventory_logs
    JOIN inventory ON inventory.id = inventory_logs.inventory_id
    JOIN users ON users.id = inventory_logs.user_id
    ORDER BY inventory_logs.created_at DESC
    LIMIT 8
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<section class="grid grid-3">
    <article class="card stat"><div><span class="muted">Ingredients</span><strong><?= count($items) ?></strong></div><i class="bi bi-boxes"></i></article>
    <article class="card stat"><div><span class="muted">Low Stock</span><strong><?= e($low['total']) ?></strong></div><i class="bi bi-exclamation-triangle"></i></article>
    <article class="card stat"><div><span class="muted">Out of Stock</span><strong><?= e($out['total']) ?></strong></div><i class="bi bi-x-circle"></i></article>
</section>

<div class="grid grid-2" style="margin-top:24px">
    <section class="card">
        <h2>Add Ingredient</h2>
        <form class="form" method="post">
            <input type="hidden" name="action" value="save">
            <div class="field"><label>Ingredient Name</label><input name="ingredient_name" required></div>
            <div class="grid grid-2">
                <div class="field"><label>Unit</label><input name="unit" placeholder="kg, pcs, packs" required></div>
                <div class="field"><label>Supplier</label><input name="supplier"></div>
            </div>
            <div class="grid grid-2">
                <div class="field"><label>Current Stock</label><input type="number" step="0.01" name="current_stock" required></div>
                <div class="field"><label>Low Stock Threshold</label><input type="number" step="0.01" name="low_stock" required></div>
            </div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Save Ingredient</button>
        </form>
    </section>

    <section class="card">
        <h2>Stock Movement</h2>
        <form class="form" method="post">
            <input type="hidden" name="action" value="stock">
            <div class="field"><label>Ingredient</label><select name="inventory_id" required><?php foreach ($items as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['ingredient_name']) ?></option><?php endforeach; ?></select></div>
            <div class="grid grid-2">
                <div class="field">
                    <label>Action</label>
                    <select name="stock_action">
                        <option>Stock In</option>
                        <option>Stock Out</option>
                        <option>Delivery</option>
                        <option>Waste/Spoilage</option>
                        <option>Adjustment</option>
                    </select>
                </div>
                <div class="field"><label>Quantity</label><input type="number" step="0.01" name="quantity" required></div>
            </div>
            <div class="field"><label>Remarks</label><input name="remarks"></div>
            <button class="btn btn-secondary" type="submit"><i class="bi bi-arrow-left-right"></i>Update Stock</button>
        </form>
    </section>
</div>

<section class="card" style="margin-top:24px">
    <div class="page-head"><h2>Inventory List</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Ingredient</th><th>Stock</th><th>Threshold</th><th>Supplier</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($items as $row): ?>
                <?php
                    $status = 'Good';
                    $class = 'badge-success';
                    if ($row['current_stock'] <= 0) { $status = 'Out of Stock'; $class = 'badge-danger'; }
                    elseif ($row['current_stock'] <= $row['low_stock']) { $status = 'Low Stock'; $class = 'badge-warning'; }
                ?>
                <tr>
                    <td><?= e($row['ingredient_name']) ?></td>
                    <td><?= e($row['current_stock']) ?> <?= e($row['unit']) ?></td>
                    <td><?= e($row['low_stock']) ?> <?= e($row['unit']) ?></td>
                    <td><?= e($row['supplier']) ?></td>
                    <td><span class="badge <?= $class ?>"><?= $status ?></span></td>
                    <td class="actions"><a class="btn btn-danger" data-confirm="Delete ingredient?" href="?delete=<?= e($row['id']) ?>"><i class="bi bi-trash"></i></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:24px">
    <h2>Recent Stock History</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Ingredient</th><th>Action</th><th>Qty</th><th>User</th><th>Remarks</th></tr></thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= e(date('M d, h:i A', strtotime($log['created_at']))) ?></td>
                    <td><?= e($log['ingredient_name']) ?></td>
                    <td><?= e($log['action']) ?></td>
                    <td><?= e($log['quantity']) ?></td>
                    <td><?= e($log['full_name']) ?></td>
                    <td><?= e($log['remarks']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
