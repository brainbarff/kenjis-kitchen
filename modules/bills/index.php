<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'manager']);

$page = 'bills';
$title = 'Bills and Financials';
$heading = 'Dashboard, Financials & Bills';

$sales = $conn->query("SELECT COALESCE(SUM(total),0) total FROM orders WHERE status NOT IN ('Cancelled','Voided','Refunded')")->fetch();
$paid = $conn->query("SELECT COUNT(*) total FROM payments")->fetch();
$pending = $conn->query("SELECT COUNT(*) total FROM orders WHERE status IN ('Pending','Preparing','Ready')")->fetch();
$best = $conn->query("
    SELECT item_name, SUM(quantity) qty
    FROM order_items
    GROUP BY item_name
    ORDER BY qty DESC
    LIMIT 5
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<section class="grid grid-3">
    <article class="card stat"><div><span class="muted">Total Sales</span><strong><?= money($sales['total']) ?></strong></div><i class="bi bi-graph-up"></i></article>
    <article class="card stat"><div><span class="muted">Paid Bills</span><strong><?= e($paid['total']) ?></strong></div><i class="bi bi-check2-circle"></i></article>
    <article class="card stat"><div><span class="muted">Active Bills</span><strong><?= e($pending['total']) ?></strong></div><i class="bi bi-hourglass-split"></i></article>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Best Sellers</h2>
            <p class="muted">Top items by quantity sold.</p>
        </div>
        <button class="btn btn-secondary" onclick="window.print()"><i class="bi bi-printer"></i>Print Report</button>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Menu Item</th><th>Sold Qty</th><th>Movement</th></tr></thead>
            <tbody>
                <?php foreach ($best as $row): ?>
                    <tr><td><?= e($row['item_name']) ?></td><td><?= e($row['qty']) ?></td><td><span class="badge badge-success">Best-Seller</span></td></tr>
                <?php endforeach; ?>
                <?php if (!$best): ?>
                    <tr><td colspan="3" class="muted">No sales yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
