<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/db.php';
require_role('admin');

$page = 'dashboard';
$title = 'Dashboard';
$heading = 'Dashboard';

$sales = $conn->query("SELECT COALESCE(SUM(total),0) total FROM orders WHERE DATE(created_at) = CURDATE() AND status <> 'Cancelled'")->fetch();
$orders = $conn->query("SELECT COUNT(*) total FROM orders WHERE DATE(created_at) = CURDATE()")->fetch();
$low = $conn->query("SELECT COUNT(*) total FROM inventory WHERE current_stock <= low_stock")->fetch();
$ready = $conn->query("SELECT COUNT(*) total FROM orders WHERE status = 'Ready'")->fetch();
$recent = $conn->query("
    SELECT order_no, order_type, total, status, created_at
    FROM orders
    ORDER BY created_at DESC
    LIMIT 8
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<section class="grid grid-4">
    <article class="card stat"><div><span class="muted">Today's Sales</span><strong><?= money($sales['total']) ?></strong></div><i class="bi bi-cash-stack"></i></article>
    <article class="card stat"><div><span class="muted">Today's Orders</span><strong><?= e($orders['total']) ?></strong></div><i class="bi bi-bag-check"></i></article>
    <article class="card stat"><div><span class="muted">Low Stock</span><strong><?= e($low['total']) ?></strong></div><i class="bi bi-exclamation-triangle"></i></article>
    <article class="card stat"><div><span class="muted">Ready Orders</span><strong><?= e($ready['total']) ?></strong></div><i class="bi bi-bell"></i></article>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Recent Orders</h2>
            <p class="muted">Latest transactions from POS and online ordering.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Order No.</th><th>Type</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?= e($row['order_no']) ?></td>
                    <td><?= e($row['order_type']) ?></td>
                    <td><?= money($row['total']) ?></td>
                    <td><span class="badge badge-info"><?= e($row['status']) ?></span></td>
                    <td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
