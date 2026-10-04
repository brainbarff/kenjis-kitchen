<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'reports';
$title = 'Sales Report';
$heading = 'Sales Report';

$today = date('Y-m-d');
$fromDate = $_GET['from_date'] ?? $today;
$toDate = $_GET['to_date'] ?? $fromDate;

$fromObject = DateTime::createFromFormat('Y-m-d', $fromDate);
$toObject = DateTime::createFromFormat('Y-m-d', $toDate);

if (!$fromObject || $fromObject->format('Y-m-d') !== $fromDate) {
    $fromDate = $today;
    $fromObject = new DateTime($fromDate);
}

if (!$toObject || $toObject->format('Y-m-d') !== $toDate) {
    $toDate = $fromDate;
    $toObject = new DateTime($toDate);
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$reportFrom = $fromDate . ' 00:00:00';
$reportTo = (new DateTime($toDate))->modify('+1 day')->format('Y-m-d 00:00:00');
$isSingleDay = $fromDate === $toDate;

$salesPerPage = 10;
$salesPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;

if ($salesPage < 1) {
    $salesPage = 1;
}

$latestPayment = "
    SELECT p1.order_id, p1.payment_method, p1.payment_status, p1.payment_reference
    FROM payments p1
    INNER JOIN (
        SELECT order_id, MAX(id) AS max_id
        FROM payments
        GROUP BY order_id
    ) latest ON latest.max_id = p1.id
";

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(o.id) AS total_orders,
        COALESCE(SUM(o.total), 0) AS total_sales,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(p.payment_method)) = 'cash' THEN o.total ELSE 0 END), 0) AS cash_sales,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(p.payment_method)) = 'gcash' THEN o.total ELSE 0 END), 0) AS gcash_sales
    FROM orders o
    INNER JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :report_from
      AND o.paid_at < :report_to
      AND o.status <> 'Cancelled'
");
$summaryStmt->bindValue(':report_from', $reportFrom, PDO::PARAM_STR);
$summaryStmt->bindValue(':report_to', $reportTo, PDO::PARAM_STR);
$summaryStmt->execute();
$summary = $summaryStmt->fetch();

$totalSalesOrders = (int) $summary['total_orders'];
$totalSalesPages = max(1, (int) ceil($totalSalesOrders / $salesPerPage));

if ($salesPage > $totalSalesPages) {
    $salesPage = $totalSalesPages;
}

$salesOffset = ($salesPage - 1) * $salesPerPage;

$ordersStmt = $conn->prepare("
    SELECT
        o.id,
        o.order_no,
        o.order_type,
        p.payment_method,
        o.total,
        o.paid_at
    FROM orders o
    INNER JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :report_from
      AND o.paid_at < :report_to
      AND o.status <> 'Cancelled'
    ORDER BY o.paid_at DESC, o.id DESC
    LIMIT :limit OFFSET :offset
");
$ordersStmt->bindValue(':report_from', $reportFrom, PDO::PARAM_STR);
$ordersStmt->bindValue(':report_to', $reportTo, PDO::PARAM_STR);
$ordersStmt->bindValue(':limit', $salesPerPage, PDO::PARAM_INT);
$ordersStmt->bindValue(':offset', $salesOffset, PDO::PARAM_INT);
$ordersStmt->execute();
$orders = $ordersStmt->fetchAll();

$printOrdersStmt = $conn->prepare("
    SELECT
        o.order_no,
        o.order_type,
        p.payment_method,
        o.total,
        o.paid_at
    FROM orders o
    INNER JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :report_from
      AND o.paid_at < :report_to
      AND o.status <> 'Cancelled'
    ORDER BY o.paid_at DESC, o.id DESC
");
$printOrdersStmt->bindValue(':report_from', $reportFrom, PDO::PARAM_STR);
$printOrdersStmt->bindValue(':report_to', $reportTo, PDO::PARAM_STR);
$printOrdersStmt->execute();
$printOrders = $printOrdersStmt->fetchAll();

$bestSellersStmt = $conn->prepare("
    SELECT
        oi.menu_item_id,
        MAX(oi.item_name) AS item_name,
        SUM(oi.quantity) AS quantity_sold
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :best_from
      AND o.paid_at < :best_to
      AND o.status <> 'Cancelled'
    GROUP BY oi.menu_item_id
    ORDER BY quantity_sold DESC, item_name ASC
    LIMIT 10
");
$bestSellersStmt->bindValue(':best_from', $reportFrom, PDO::PARAM_STR);
$bestSellersStmt->bindValue(':best_to', $reportTo, PDO::PARAM_STR);
$bestSellersStmt->execute();
$bestSellers = $bestSellersStmt->fetchAll();


$menuSalesPerPage = 10;
$menuSalesPage = isset($_GET['menu_page']) ? (int) $_GET['menu_page'] : 1;

if ($menuSalesPage < 1) {
    $menuSalesPage = 1;
}

$menuSalesCountStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM (
        SELECT oi.menu_item_id
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.paid_at IS NOT NULL
          AND o.paid_at >= :menu_count_from
          AND o.paid_at < :menu_count_to
          AND o.status <> 'Cancelled'
        GROUP BY oi.menu_item_id
    ) menu_sales_count
");
$menuSalesCountStmt->bindValue(':menu_count_from', $reportFrom, PDO::PARAM_STR);
$menuSalesCountStmt->bindValue(':menu_count_to', $reportTo, PDO::PARAM_STR);
$menuSalesCountStmt->execute();
$totalMenuSalesItems = (int) $menuSalesCountStmt->fetchColumn();
$totalMenuSalesPages = max(1, (int) ceil($totalMenuSalesItems / $menuSalesPerPage));

if ($menuSalesPage > $totalMenuSalesPages) {
    $menuSalesPage = $totalMenuSalesPages;
}

$menuSalesOffset = ($menuSalesPage - 1) * $menuSalesPerPage;

$menuSalesStmt = $conn->prepare("
    SELECT
        oi.menu_item_id,
        MAX(oi.item_name) AS item_name,
        SUM(oi.quantity) AS quantity_sold,
        COALESCE(SUM(oi.quantity * oi.price), 0) AS item_sales
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :menu_from
      AND o.paid_at < :menu_to
      AND o.status <> 'Cancelled'
    GROUP BY oi.menu_item_id
    ORDER BY quantity_sold DESC, item_sales DESC, item_name ASC
    LIMIT :menu_limit OFFSET :menu_offset
");
$menuSalesStmt->bindValue(':menu_from', $reportFrom, PDO::PARAM_STR);
$menuSalesStmt->bindValue(':menu_to', $reportTo, PDO::PARAM_STR);
$menuSalesStmt->bindValue(':menu_limit', $menuSalesPerPage, PDO::PARAM_INT);
$menuSalesStmt->bindValue(':menu_offset', $menuSalesOffset, PDO::PARAM_INT);
$menuSalesStmt->execute();
$menuSales = $menuSalesStmt->fetchAll();

$printMenuSalesStmt = $conn->prepare("
    SELECT
        oi.menu_item_id,
        MAX(oi.item_name) AS item_name,
        SUM(oi.quantity) AS quantity_sold,
        COALESCE(SUM(oi.quantity * oi.price), 0) AS item_sales
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :print_menu_from
      AND o.paid_at < :print_menu_to
      AND o.status <> 'Cancelled'
    GROUP BY oi.menu_item_id
    ORDER BY quantity_sold DESC, item_sales DESC, item_name ASC
");
$printMenuSalesStmt->bindValue(':print_menu_from', $reportFrom, PDO::PARAM_STR);
$printMenuSalesStmt->bindValue(':print_menu_to', $reportTo, PDO::PARAM_STR);
$printMenuSalesStmt->execute();
$printMenuSales = $printMenuSalesStmt->fetchAll();

$orderTypeSummaryStmt = $conn->prepare("    SELECT        CASE            WHEN UPPER(TRIM(o.order_type)) = 'DINE-IN' THEN 'Dine-In'            WHEN UPPER(TRIM(o.order_type)) = 'TAKE-OUT' THEN 'Take-Out'            WHEN UPPER(TRIM(o.order_type)) = 'ONLINE' THEN 'Online'            ELSE 'Other'        END AS order_type_label,        COUNT(o.id) AS total_orders,        COALESCE(SUM(o.total), 0) AS total_sales    FROM orders o    INNER JOIN ($latestPayment) p ON p.order_id = o.id    WHERE o.paid_at IS NOT NULL      AND o.paid_at >= :order_type_from      AND o.paid_at < :order_type_to      AND o.status <> 'Cancelled'    GROUP BY order_type_label    ORDER BY FIELD(order_type_label, 'Dine-In', 'Take-Out', 'Online', 'Other')");
$orderTypeSummaryStmt->bindValue(':order_type_from', $reportFrom, PDO::PARAM_STR);
$orderTypeSummaryStmt->bindValue(':order_type_to', $reportTo, PDO::PARAM_STR);
$orderTypeSummaryStmt->execute();
$orderTypeSummary = $orderTypeSummaryStmt->fetchAll();

$paymentSummaryStmt = $conn->prepare("    SELECT        CASE            WHEN LOWER(TRIM(p.payment_method)) IN ('cash', 'pay on pickup', 'cash on delivery') THEN 'Cash'            WHEN LOWER(TRIM(p.payment_method)) = 'gcash' THEN 'GCash'            ELSE 'Other'        END AS payment_method_label,        COUNT(o.id) AS total_orders,        COALESCE(SUM(o.total), 0) AS total_sales    FROM orders o    INNER JOIN ($latestPayment) p ON p.order_id = o.id    WHERE o.paid_at IS NOT NULL      AND o.paid_at >= :payment_from      AND o.paid_at < :payment_to      AND o.status <> 'Cancelled'    GROUP BY payment_method_label    ORDER BY FIELD(payment_method_label, 'Cash', 'GCash', 'Other')");
$paymentSummaryStmt->bindValue(':payment_from', $reportFrom, PDO::PARAM_STR);
$paymentSummaryStmt->bindValue(':payment_to', $reportTo, PDO::PARAM_STR);
$paymentSummaryStmt->execute();
$paymentSummary = $paymentSummaryStmt->fetchAll();

$onlineSummaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS online_orders,
        COALESCE(SUM(CASE WHEN o.status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_online_orders
    FROM orders o
    WHERE o.order_channel = 'ONLINE'
      AND o.created_at >= :online_from
      AND o.created_at < :online_to
      AND o.status <> 'Cancelled'
");
$onlineSummaryStmt->bindValue(':online_from', $reportFrom, PDO::PARAM_STR);
$onlineSummaryStmt->bindValue(':online_to', $reportTo, PDO::PARAM_STR);
$onlineSummaryStmt->execute();
$onlineSummary = $onlineSummaryStmt->fetch();

$onlineOrdersPerPage = 10;
$onlineOrdersPage = isset($_GET['online_page']) ? (int) $_GET['online_page'] : 1;
if ($onlineOrdersPage < 1) {
    $onlineOrdersPage = 1;
}

$onlineCountStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM orders o
    WHERE o.order_channel = 'ONLINE'
      AND o.created_at >= :online_count_from
      AND o.created_at < :online_count_to
      AND o.status <> 'Cancelled'
");
$onlineCountStmt->bindValue(':online_count_from', $reportFrom, PDO::PARAM_STR);
$onlineCountStmt->bindValue(':online_count_to', $reportTo, PDO::PARAM_STR);
$onlineCountStmt->execute();
$totalOnlineOrders = (int) $onlineCountStmt->fetchColumn();
$totalOnlinePages = max(1, (int) ceil($totalOnlineOrders / $onlineOrdersPerPage));
if ($onlineOrdersPage > $totalOnlinePages) {
    $onlineOrdersPage = $totalOnlinePages;
}
$onlineOrdersOffset = ($onlineOrdersPage - 1) * $onlineOrdersPerPage;

$onlineOrdersStmt = $conn->prepare("
    SELECT
        o.id,
        o.order_no,
        o.customer_name,
        o.order_type,
        COALESCE(p.payment_method, '—') AS payment_method,
        COALESCE(p.payment_status, 'Pending') AS payment_status,
        p.payment_reference,
        o.total,
        o.status,
        o.created_at
    FROM orders o
    LEFT JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.order_channel = 'ONLINE'
      AND o.created_at >= :online_from_list
      AND o.created_at < :online_to_list
      AND o.status <> 'Cancelled'
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT :online_limit OFFSET :online_offset
");
$onlineOrdersStmt->bindValue(':online_from_list', $reportFrom, PDO::PARAM_STR);
$onlineOrdersStmt->bindValue(':online_to_list', $reportTo, PDO::PARAM_STR);
$onlineOrdersStmt->bindValue(':online_limit', $onlineOrdersPerPage, PDO::PARAM_INT);
$onlineOrdersStmt->bindValue(':online_offset', $onlineOrdersOffset, PDO::PARAM_INT);
$onlineOrdersStmt->execute();
$onlineOrders = $onlineOrdersStmt->fetchAll();

$printOnlineOrdersStmt = $conn->prepare("
    SELECT
        o.order_no,
        o.customer_name,
        o.order_type,
        COALESCE(p.payment_method, '—') AS payment_method,
        COALESCE(p.payment_status, 'Pending') AS payment_status,
        p.payment_reference,
        o.total,
        o.status,
        o.created_at
    FROM orders o
    LEFT JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.order_channel = 'ONLINE'
      AND o.created_at >= :online_print_from
      AND o.created_at < :online_print_to
      AND o.status <> 'Cancelled'
    ORDER BY o.created_at DESC, o.id DESC
");
$printOnlineOrdersStmt->bindValue(':online_print_from', $reportFrom, PDO::PARAM_STR);
$printOnlineOrdersStmt->bindValue(':online_print_to', $reportTo, PDO::PARAM_STR);
$printOnlineOrdersStmt->execute();
$printOnlineOrders = $printOnlineOrdersStmt->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>

<section class="page-head">
    <div>
        <h2><?= $isSingleDay ? 'Daily Sales Report' : 'Sales Report' ?></h2>
        <p class="muted">Paid sales for the selected period, with online order activity shown separately.</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary" href="sales_performance.php?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>">
            <i class="bi bi-graph-up"></i>Sales Performance
        </a>
        <button type="button" class="btn btn-secondary" onclick="printReport()">
            <i class="bi bi-printer"></i>Print Report
        </button>
    </div>
</section>

<section class="card" style="margin-bottom:24px;">
    <form method="get" class="form" style="grid-template-columns:minmax(180px,260px) minmax(180px,260px) auto; align-items:end;">
        <div class="field">
            <label for="fromDate">From Date</label>
            <input type="date" id="fromDate" name="from_date" value="<?= e($fromDate) ?>" max="<?= e($today) ?>">
        </div>
        <div class="field">
            <label for="toDate">To Date</label>
            <input type="date" id="toDate" name="to_date" value="<?= e($toDate) ?>" max="<?= e($today) ?>">
        </div>
        <div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i>View Report
            </button>
        </div>
    </form>
</section>

<section class="grid grid-4">
    <article class="card stat">
        <div>
            <span class="muted">Total Sales</span>
            <strong><?= money($summary['total_sales']) ?></strong>
        </div>
        <i class="bi bi-cash-stack"></i>
    </article>

    <article class="card stat">
        <div>
            <span class="muted">Total Orders</span>
            <strong><?= e($summary['total_orders']) ?></strong>
        </div>
        <i class="bi bi-receipt"></i>
    </article>

    <article class="card stat">
        <div>
            <span class="muted">Cash Sales</span>
            <strong><?= money($summary['cash_sales']) ?></strong>
        </div>
        <i class="bi bi-wallet2"></i>
    </article>

    <article class="card stat">
        <div>
            <span class="muted">GCash Sales</span>
            <strong><?= money($summary['gcash_sales']) ?></strong>
        </div>
        <i class="bi bi-phone"></i>
    </article>

    <article class="card stat">
        <div>
            <span class="muted">Online Orders</span>
            <strong><?= e($onlineSummary['online_orders']) ?></strong>
        </div>
        <i class="bi bi-globe2"></i>
    </article>

    <article class="card stat">
        <div>
            <span class="muted">Pending Online</span>
            <strong><?= e($onlineSummary['pending_online_orders']) ?></strong>
        </div>
        <i class="bi bi-hourglass-split"></i>
    </article>
</section>

<style>
.online-payment-action { padding:7px 10px; font-size:0.78rem; white-space:nowrap; }
</style>

<section class="card" style="margin-top:24px;">
    <div style="margin-bottom:16px;">
        <h2 style="font-size:24px; margin-bottom:4px;">Best-Selling Menu Items</h2>
        <p class="muted">Top 10 menu items by quantity sold for the selected period.</p>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Menu Item</th>
                    <th>Quantity Sold</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bestSellers as $index => $item): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= e($item['item_name']) ?></td>
                        <td><?= e($item['quantity_sold']) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$bestSellers): ?>
                    <tr>
                        <td colspan="3" class="muted" style="text-align:center; padding:28px;">No item sales found for this date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>


<section class="card" style="margin-top:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:24px; margin-bottom:4px;">Menu Item Sales Details</h2>
            <p class="muted">Quantity sold and item sales for each menu item in the selected period.</p>
        </div>

        <?php if ($totalMenuSalesItems > 0): ?>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $salesPage ?>&menu_page=<?= $menuSalesPage - 1 ?>" <?= $menuSalesPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
                <strong style="white-space:nowrap;">Page <?= $menuSalesPage ?> of <?= $totalMenuSalesPages ?></strong>
                <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $salesPage ?>&menu_page=<?= $menuSalesPage + 1 ?>" <?= $menuSalesPage >= $totalMenuSalesPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Menu Item</th>
                    <th>Quantity Sold</th>
                    <th>Item Sales</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($menuSales as $item): ?>
                    <tr>
                        <td><?= e($item['item_name']) ?></td>
                        <td><?= e($item['quantity_sold']) ?></td>
                        <td><?= money($item['item_sales']) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$menuSales): ?>
                    <tr>
                        <td colspan="3" class="muted" style="text-align:center; padding:28px;">No menu item sales found for this date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>


<section class="grid grid-2" style="margin-top:24px;">
    <section class="card">
        <div style="margin-bottom:16px;">
            <h2 style="font-size:24px; margin-bottom:4px;">Order Type Summary</h2>
            <p class="muted">Sales and order count by order type.</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order Type</th>
                        <th>Orders</th>
                        <th>Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderTypeSummary as $row): ?>
                        <tr>
                            <td><?= e($row['order_type_label']) ?></td>
                            <td><?= e($row['total_orders']) ?></td>
                            <td><?= money($row['total_sales']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$orderTypeSummary): ?>
                        <tr>
                            <td colspan="3" class="muted" style="text-align:center; padding:28px;">No order type data found for this date range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div style="margin-bottom:16px;">
            <h2 style="font-size:24px; margin-bottom:4px;">Payment Summary</h2>
            <p class="muted">Sales and order count by payment method.</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th>Orders</th>
                        <th>Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paymentSummary as $row): ?>
                        <tr>
                            <td><?= e($row['payment_method_label']) ?></td>
                            <td><?= e($row['total_orders']) ?></td>
                            <td><?= money($row['total_sales']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$paymentSummary): ?>
                        <tr>
                            <td colspan="3" class="muted" style="text-align:center; padding:28px;">No payment data found for this date range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>

<section class="card" style="margin-top:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:24px; margin-bottom:4px;">Online Order Activity</h2>
            <p class="muted">Online orders created during the selected period, including payment and order status.</p>
        </div>

        <?php if ($totalOnlineOrders > 0): ?>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $salesPage ?>&menu_page=<?= $menuSalesPage ?>&online_page=<?= $onlineOrdersPage - 1 ?>" <?= $onlineOrdersPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
                <strong style="white-space:nowrap;">Page <?= $onlineOrdersPage ?> of <?= $totalOnlinePages ?></strong>
                <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $salesPage ?>&menu_page=<?= $menuSalesPage ?>&online_page=<?= $onlineOrdersPage + 1 ?>" <?= $onlineOrdersPage >= $totalOnlinePages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order Number</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Payment</th>
                    <th>Payment Status</th>
                    <th>Total</th>
                    <th>Order Status</th>
                    <th>Payment Action</th>
                    <th>Date / Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($onlineOrders as $order): ?>
                    <tr>
                        <td><?= e($order['order_no']) ?></td>
                        <td><?= e($order['customer_name'] ?: 'Online Customer') ?></td>
                        <td><?= e($order['order_type']) ?></td>
                        <td><?= e($order['payment_method']) ?><?php if (!empty($order['payment_reference'])): ?><br><small class="muted">Ref: <?= e($order['payment_reference']) ?></small><?php endif; ?></td>
                        <td><?= e($order['payment_status']) ?></td>
                        <td><?= money($order['total']) ?></td>
                        <td><?= e($order['status']) ?></td>
                        <td>
                            <?php if (strcasecmp((string)$order['payment_status'], 'Paid') === 0): ?>
                                <span class="muted">Paid</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary online-payment-action" data-order-id="<?= e($order['id'] ?? '') ?>" data-payment-method="<?= e($order['payment_method']) ?>">
                                    <i class="bi bi-check2-circle"></i>
                                    <?= strcasecmp((string)$order['payment_method'], 'GCash') === 0 ? 'Verify GCash' : 'Mark Paid' ?>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td><?= e(date('M j, Y h:i A', strtotime($order['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$onlineOrders): ?>
                    <tr>
                        <td colspan="9" class="muted" style="text-align:center; padding:28px;">No online orders found for this date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:24px; margin-bottom:4px;">Sales Details</h2>
            <p class="muted">Paid transactions only<?= $isSingleDay ? ' · ' . e(date('F j, Y', strtotime($fromDate))) : ' · ' . e(date('F j, Y', strtotime($fromDate)) . ' - ' . date('F j, Y', strtotime($toDate))) ?></p>
        </div>

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
            <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $salesPage - 1 ?>" <?= $salesPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
            <strong style="white-space:nowrap;">Page <?= $salesPage ?> of <?= $totalSalesPages ?></strong>
            <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $salesPage + 1 ?>" <?= $salesPage >= $totalSalesPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order Number</th>
                    <th>Order Type</th>
                    <th>Payment Method</th>
                    <th>Total</th>
                    <th>Date / Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= e($order['order_no']) ?></td>
                        <td><?= e($order['order_type']) ?></td>
                        <td><?= e($order['payment_method']) ?></td>
                        <td><?= money($order['total']) ?></td>
                        <td><?= e(date('M j, Y h:i A', strtotime($order['paid_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$orders): ?>
                    <tr>
                        <td colspan="5" class="muted" style="text-align:center; padding:28px;">No paid sales found for this date.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="printTemplate" style="display:none;">
    <div class="print-header">
        <h1>Kenji's Kitchen</h1>
        <p><?= $isSingleDay ? 'Daily Sales Report - ' . e(date('F j, Y', strtotime($fromDate))) : 'Sales Report - ' . e(date('F j, Y', strtotime($fromDate)) . ' to ' . date('F j, Y', strtotime($toDate))) ?></p>
    </div>

    <div class="print-stats">
        <div><span>Total Sales</span><strong><?= money($summary['total_sales']) ?></strong></div>
        <div><span>Total Orders</span><strong><?= e($summary['total_orders']) ?></strong></div>
        <div><span>Cash Sales</span><strong><?= money($summary['cash_sales']) ?></strong></div>
        <div><span>GCash Sales</span><strong><?= money($summary['gcash_sales']) ?></strong></div>
        <div><span>Online Orders</span><strong><?= e($onlineSummary['online_orders']) ?></strong></div>
        <div><span>Pending Online</span><strong><?= e($onlineSummary['pending_online_orders']) ?></strong></div>
    </div>

    <h2>Online Order Activity</h2>
    <table style="margin-bottom:24px;">
        <thead>
            <tr>
                <th>Order Number</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Payment</th>
                <th>Payment Status</th>
                <th>Total</th>
                <th>Order Status</th>
                <th>Date / Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printOnlineOrders as $order): ?>
                <tr>
                    <td><?= e($order['order_no']) ?></td>
                    <td><?= e($order['customer_name'] ?: 'Online Customer') ?></td>
                    <td><?= e($order['order_type']) ?></td>
                    <td><?= e($order['payment_method']) ?></td>
                    <td><?= e($order['payment_status']) ?></td>
                    <td><?= money($order['total']) ?></td>
                    <td><?= e($order['status']) ?></td>
                    <td><?= e(date('M j, Y h:i A', strtotime($order['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$printOnlineOrders): ?>
                <tr><td colspan="8">No online orders found for this date range.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Best-Selling Menu Items</h2>
    <table style="margin-bottom:24px;">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Menu Item</th>
                <th>Quantity Sold</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bestSellers as $index => $item): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= e($item['item_name']) ?></td>
                    <td><?= e($item['quantity_sold']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$bestSellers): ?>
                <tr>
                    <td colspan="3" style="text-align:center; padding:20px;">No item sales found for this date range.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>


    <h2>Menu Item Sales Details</h2>
    <table style="margin-bottom:24px;">
        <thead>
            <tr>
                <th>Menu Item</th>
                <th>Quantity Sold</th>
                <th>Item Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printMenuSales as $item): ?>
                <tr>
                    <td><?= e($item['item_name']) ?></td>
                    <td><?= e($item['quantity_sold']) ?></td>
                    <td><?= money($item['item_sales']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$printMenuSales): ?>
                <tr>
                    <td colspan="3" style="text-align:center; padding:20px;">No menu item sales found for this date range.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Order Type Summary</h2>
    <table style="margin-bottom:24px;">
        <thead>
            <tr>
                <th>Order Type</th>
                <th>Orders</th>
                <th>Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orderTypeSummary as $row): ?>
                <tr>
                    <td><?= e($row['order_type_label']) ?></td>
                    <td><?= e($row['total_orders']) ?></td>
                    <td><?= money($row['total_sales']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orderTypeSummary): ?>
                <tr>
                    <td colspan="3" style="text-align:center; padding:20px;">No order type data found for this date range.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Payment Summary</h2>
    <table style="margin-bottom:24px;">
        <thead>
            <tr>
                <th>Payment Method</th>
                <th>Orders</th>
                <th>Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($paymentSummary as $row): ?>
                <tr>
                    <td><?= e($row['payment_method_label']) ?></td>
                    <td><?= e($row['total_orders']) ?></td>
                    <td><?= money($row['total_sales']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$paymentSummary): ?>
                <tr>
                    <td colspan="3" style="text-align:center; padding:20px;">No payment data found for this date range.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Sales Details</h2>
    <table>
        <thead>
            <tr>
                <th>Order Number</th>
                <th>Order Type</th>
                <th>Payment Method</th>
                <th>Total</th>
                <th>Date / Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printOrders as $order): ?>
                <tr>
                    <td><?= e($order['order_no']) ?></td>
                    <td><?= e($order['order_type']) ?></td>
                    <td><?= e($order['payment_method']) ?></td>
                    <td><?= money($order['total']) ?></td>
                    <td><?= e(date('M j, Y h:i A', strtotime($order['paid_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$printOrders): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:20px;">No paid sales found for this date.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function printReport() {
    const reportHtml = document.getElementById('printTemplate').innerHTML;
    const printWindow = window.open('', '_blank', 'width=1000,height=800');

    if (!printWindow) {
        return;
    }

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title><?= $isSingleDay ? 'Daily Sales Report' : 'Sales Report' ?> - Kenji's Kitchen</title>
            <style>
                @page { size: landscape; margin: 12mm; }
                * { box-sizing: border-box; }
                body { font-family: Arial, sans-serif; color: #232323; margin: 0; }
                .print-header { border-bottom: 2px solid #232323; padding-bottom: 12px; margin-bottom: 20px; }
                .print-header h1 { margin: 0; font-size: 26px; }
                .print-header p { margin: 4px 0 0; color: #666; font-size: 13px; }
                .print-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
                .print-stats div { border: 1px solid #ddd; padding: 14px; }
                .print-stats span { display: block; color: #666; font-size: 12px; margin-bottom: 6px; }
                .print-stats strong { font-size: 20px; }
                h2 { font-size: 18px; margin: 0 0 12px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 9px 10px; text-align: left; font-size: 12px; }
                th { background: #f5f5f5; }
            </style>
        </head>
        <body>${reportHtml}</body>
        </html>
    `);

    printWindow.document.close();
    printWindow.focus();

    setTimeout(function() {
        printWindow.print();
        printWindow.close();
    }, 300);
}
</script>

<script>
document.querySelectorAll('.online-payment-action').forEach(function(button) {
    button.addEventListener('click', async function() {
        const orderId = this.dataset.orderId;
        const method = this.dataset.paymentMethod || 'payment';
        const message = method.toLowerCase() === 'gcash'
            ? 'Verify this GCash payment and mark it as paid?'
            : 'Mark this cash payment as paid?';

        if (!confirm(message)) {
            return;
        }

        this.disabled = true;

        try {
            const response = await fetch('complete_online_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: Number(orderId) })
            });
            const result = await response.json();

            if (!result.ok) {
                alert(result.message || 'Payment could not be completed.');
                this.disabled = false;
                return;
            }

            alert(result.message || 'Payment updated successfully.');
            window.location.reload();
        } catch (error) {
            alert('Unable to update the payment right now.');
            this.disabled = false;
        }
    });
});
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
