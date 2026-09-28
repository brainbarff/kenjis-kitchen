<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/db.php';

require_login();

$page = 'dashboard';
$title = 'Dashboard';
$heading = 'Dashboard';

$user = current_user();
$role = $user['role_key'];
$userId = (int)($user['id'] ?? 0);
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$sevenDaysAgo = date('Y-m-d', strtotime('-6 days'));
$thirtyDaysAgo = date('Y-m-d', strtotime('-29 days'));
$currentYear = date('Y');
$nextYear = (string)((int)$currentYear + 1);

function dashboard_scalar(PDO $conn, string $sql, array $params = [])
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$todayStart = $today . ' 00:00:00';
$tomorrowStart = $tomorrow . ' 00:00:00';

if (in_array($role, ['admin', 'manager'], true)) {
    $todaySales = dashboard_scalar($conn, "
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status <> 'Cancelled'
    ", [':start' => $todayStart, ':end' => $tomorrowStart]);

    $todayOrders = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status <> 'Cancelled'
    ", [':start' => $todayStart, ':end' => $tomorrowStart]);

    $lowStock = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM menu_items
        WHERE is_archived = 0
          AND stock BETWEEN 1 AND 5
    ");

    $outOfStock = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM menu_items
        WHERE is_archived = 0
          AND stock = 0
    ");

    $pendingOrders = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE status = 'Pending'
    ");

    $trend7Stmt = $conn->prepare("
        SELECT DATE(paid_at) AS d, COALESCE(SUM(total), 0) AS total
        FROM orders
        WHERE paid_at IS NOT NULL
          AND status <> 'Cancelled'
          AND paid_at >= :start
          AND paid_at < :end
        GROUP BY DATE(paid_at)
    ");
    $trend7Stmt->execute([':start' => $sevenDaysAgo . ' 00:00:00', ':end' => $tomorrowStart]);
    $trend7 = $trend7Stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $trend30Stmt = $conn->prepare("
        SELECT DATE(paid_at) AS d, COALESCE(SUM(total), 0) AS total
        FROM orders
        WHERE paid_at IS NOT NULL
          AND status <> 'Cancelled'
          AND paid_at >= :start
          AND paid_at < :end
        GROUP BY DATE(paid_at)
    ");
    $trend30Stmt->execute([':start' => $thirtyDaysAgo . ' 00:00:00', ':end' => $tomorrowStart]);
    $trend30 = $trend30Stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $trendYearStmt = $conn->prepare("
        SELECT DATE_FORMAT(paid_at, '%Y-%m') AS m, COALESCE(SUM(total), 0) AS total
        FROM orders
        WHERE paid_at IS NOT NULL
          AND status <> 'Cancelled'
          AND paid_at >= :start
          AND paid_at < :end
        GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
    ");
    $trendYearStmt->execute([':start' => $currentYear . '-01-01 00:00:00', ':end' => $nextYear . '-01-01 00:00:00']);
    $trendYear = $trendYearStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $labels7 = [];
    $data7 = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels7[] = date('M d', strtotime($d));
        $data7[] = (float)($trend7[$d] ?? 0);
    }

    $labels30 = [];
    $data30 = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels30[] = date('M d', strtotime($d));
        $data30[] = (float)($trend30[$d] ?? 0);
    }

    $labelsYear = [];
    $dataYear = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthKey = sprintf('%s-%02d', $currentYear, $m);
        $labelsYear[] = date('M', mktime(0, 0, 0, $m, 1));
        $dataYear[] = (float)($trendYear[$monthKey] ?? 0);
    }

    $recent = $conn->query("
        SELECT order_no, order_type, total, status, created_at
        FROM orders
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll();

    $topItemsStmt = $conn->prepare("
        SELECT oi.item_name, SUM(oi.quantity) AS quantity_sold
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.paid_at IS NOT NULL
          AND o.paid_at >= :start
          AND o.paid_at < :end
          AND o.status <> 'Cancelled'
        GROUP BY oi.item_name
        ORDER BY quantity_sold DESC, oi.item_name ASC
        LIMIT 5
    ");
    $topItemsStmt->execute([':start' => $todayStart, ':end' => $tomorrowStart]);
    $topItems = $topItemsStmt->fetchAll();
} elseif ($role === 'cashier') {
    $mySales = dashboard_scalar($conn, "
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE user_id = :user_id
          AND paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status <> 'Cancelled'
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myOrders = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE user_id = :user_id
          AND paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status <> 'Cancelled'
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myCash = dashboard_scalar($conn, "
        SELECT COALESCE(SUM(o.total), 0)
        FROM orders o
        INNER JOIN payments p ON p.order_id = o.id
        WHERE o.user_id = :user_id
          AND o.paid_at IS NOT NULL
          AND o.paid_at >= :start
          AND o.paid_at < :end
          AND o.status <> 'Cancelled'
          AND LOWER(p.payment_method) = 'cash'
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myGcash = dashboard_scalar($conn, "
        SELECT COALESCE(SUM(o.total), 0)
        FROM orders o
        INNER JOIN payments p ON p.order_id = o.id
        WHERE o.user_id = :user_id
          AND o.paid_at IS NOT NULL
          AND o.paid_at >= :start
          AND o.paid_at < :end
          AND o.status <> 'Cancelled'
          AND LOWER(p.payment_method) = 'gcash'
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myAverage = (float)$myOrders > 0 ? ((float)$mySales / (int)$myOrders) : 0;
    $recent = $conn->prepare("
        SELECT order_no, order_type, total, status, created_at
        FROM orders
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $recent->execute([':user_id' => $userId]);
    $myRecent = $recent->fetchAll();

    $myTopItemsStmt = $conn->prepare("
        SELECT oi.item_name, SUM(oi.quantity) AS quantity_sold
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.user_id = :user_id
          AND o.paid_at IS NOT NULL
          AND o.paid_at >= :start
          AND o.paid_at < :end
          AND o.status <> 'Cancelled'
        GROUP BY oi.item_name
        ORDER BY quantity_sold DESC, oi.item_name ASC
        LIMIT 5
    ");
    $myTopItemsStmt->execute([':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);
    $myTopItems = $myTopItemsStmt->fetchAll();
} elseif ($role === 'inventory') {
    $lowStock = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM menu_items
        WHERE is_archived = 0
          AND stock BETWEEN 1 AND 5
    ");

    $outOfStock = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM menu_items
        WHERE is_archived = 0
          AND stock = 0
    ");

    $activeItems = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM menu_items
        WHERE is_archived = 0
    ");

    $myActions = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM inventory_logs
        WHERE user_id = :user_id
          AND created_at >= :start
          AND created_at < :end
          AND action IN ('Stock In','Stock Out','Adjustment','Order Deduction')
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myRecentLogsStmt = $conn->prepare("
        SELECT il.action, il.quantity, il.remarks, il.created_at,
               COALESCE(mi.item_name, i.ingredient_name, 'Inventory Item') AS item_name
        FROM inventory_logs il
        LEFT JOIN menu_items mi ON mi.id = il.menu_item_id
        LEFT JOIN inventory i ON i.id = il.inventory_id
        WHERE il.user_id = :user_id
        ORDER BY il.created_at DESC
        LIMIT 8
    ");
    $myRecentLogsStmt->execute([':user_id' => $userId]);
    $myRecentLogs = $myRecentLogsStmt->fetchAll();
} elseif ($role === 'kitchen') {
    $pending = dashboard_scalar($conn, "SELECT COUNT(*) FROM orders WHERE status = 'Pending'");
    $preparing = dashboard_scalar($conn, "SELECT COUNT(*) FROM orders WHERE status = 'Preparing'");
    $ready = dashboard_scalar($conn, "SELECT COUNT(*) FROM orders WHERE status = 'Ready'");
    $queuedToday = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE kitchen_queued_at >= :start
          AND kitchen_queued_at < :end
          AND status <> 'Cancelled'
    ", [':start' => $todayStart, ':end' => $tomorrowStart]);

    $kitchenRecentStmt = $conn->query("
        SELECT order_no, order_type, table_no, status, kitchen_queued_at
        FROM orders
        WHERE status IN ('Pending','Preparing','Ready')
        ORDER BY COALESCE(kitchen_queued_at, created_at) ASC
        LIMIT 8
    ");
    $kitchenRecent = $kitchenRecentStmt->fetchAll();
} elseif ($role === 'server') {
    $ready = dashboard_scalar($conn, "SELECT COUNT(*) FROM orders WHERE status = 'Ready'");
    $servedToday = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE served_by = :user_id
          AND served_at >= :start
          AND served_at < :end
          AND status = 'Served'
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myDineIn = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE served_by = :user_id
          AND served_at >= :start
          AND served_at < :end
          AND status = 'Served'
          AND order_type = 'DINE-IN'
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myTakeOut = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE served_by = :user_id
          AND served_at >= :start
          AND served_at < :end
          AND status = 'Served'
          AND order_type = 'TAKE-OUT'
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $serverRecentStmt = $conn->prepare("
        SELECT order_no, order_type, table_no, total, status, served_at
        FROM orders
        WHERE served_by = :user_id
        ORDER BY served_at DESC
        LIMIT 8
    ");
    $serverRecentStmt->execute([':user_id' => $userId]);
    $serverRecent = $serverRecentStmt->fetchAll();
}

include ROOT_PATH . '/includes/header.php';
?>
<style>
.dashboard-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 24px;
}

.dashboard-kpis.four {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

@media (max-width: 1200px) {
    .dashboard-kpis,
    .dashboard-kpis.four {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .dashboard-kpis,
    .dashboard-kpis.four {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .dashboard-kpis,
    .dashboard-kpis.four {
        grid-template-columns: 1fr;
    }
}

.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 24px;
}

@media (max-width: 900px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="page-head" style="margin-bottom:24px;">
    <div>
        <h1>Welcome, <?= e($user['username']) ?></h1>
        <p class="muted"><?= e($user['role']) ?> Dashboard · <?= e(date('F j, Y')) ?></p>
    </div>
</div>

<?php if (in_array($role, ['admin', 'manager'], true)): ?>
    <section class="dashboard-kpis">
        <article class="card stat">
            <div><span class="muted">Today's Sales</span><strong><?= money($todaySales) ?></strong></div>
            <i class="bi bi-cash-stack"></i>
        </article>
        <article class="card stat">
            <div><span class="muted">Today's Orders</span><strong><?= e($todayOrders) ?></strong></div>
            <i class="bi bi-bag-check"></i>
        </article>
        <article class="card stat">
            <div><span class="muted">Low Stock</span><strong><?= e($lowStock) ?></strong></div>
            <i class="bi bi-exclamation-triangle"></i>
        </article>
        <article class="card stat">
            <div><span class="muted">Out of Stock</span><strong><?= e($outOfStock) ?></strong></div>
            <i class="bi bi-x-circle"></i>
        </article>
        <article class="card stat">
            <div><span class="muted">Pending Orders</span><strong><?= e($pendingOrders) ?></strong></div>
            <i class="bi bi-hourglass-split"></i>
        </article>
    </section>

    <section class="card" style="margin-top:24px">
        <div class="page-head" style="margin-bottom:16px; flex-wrap:wrap; gap:12px;">
            <div>
                <h2>Sales Overview</h2>
                <p class="muted" id="chartSubtitle">Daily revenue performance over the last 7 days.</p>
            </div>
            <div style="display:flex; gap:6px; background:var(--soft); padding:4px; border-radius:12px;">
                <button type="button" class="btn chart-filter active" data-range="7d" style="min-height:36px; padding:6px 14px; font-size:13px; background:var(--primary); color:var(--dark);">7 Days</button>
                <button type="button" class="btn chart-filter" data-range="30d" style="min-height:36px; padding:6px 14px; font-size:13px; background:transparent; color:var(--text);">30 Days</button>
                <button type="button" class="btn chart-filter" data-range="year" style="min-height:36px; padding:6px 14px; font-size:13px; background:transparent; color:var(--text);">This Year</button>
            </div>
        </div>
        <div style="height:280px; position:relative;"><canvas id="salesChart"></canvas></div>
    </section>

    <div class="dashboard-grid">
        <section class="card">
            <div class="page-head" style="margin-bottom:16px;">
                <div><h2>Top Items Today</h2><p class="muted">Most ordered menu items today.</p></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Menu Item</th><th>Quantity Sold</th></tr></thead>
                    <tbody>
                    <?php foreach ($topItems as $item): ?>
                        <tr><td><?= e($item['item_name']) ?></td><td><?= e($item['quantity_sold']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$topItems): ?><tr><td colspan="2" class="muted" style="text-align:center; padding:28px;">No paid sales found today.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <div class="page-head" style="margin-bottom:16px;">
                <div><h2>Recent Orders</h2><p class="muted">Latest transactions from POS.</p></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Order No.</th><th>Type</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $row): ?>
                        <?php
                        $status = $row['status'] ?: 'Pending';
                        $badgeClass = match($status) {
                            'Served', 'Completed' => 'badge-success',
                            'Ready' => 'badge-primary',
                            'Preparing' => 'badge-info',
                            'Cancelled' => 'badge-danger',
                            default => 'badge-warning',
                        };
                        ?>
                        <tr>
                            <td><?= e($row['order_no']) ?></td>
                            <td><?= e($row['order_type']) ?></td>
                            <td><?= money($row['total']) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= e($status) ?></span></td>
                            <td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><tr><td colspan="5" class="muted" style="text-align:center; padding:28px;">No recent orders found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
    const salesChartData = {
        '7d': { labels: <?= json_encode($labels7) ?>, data: <?= json_encode($data7) ?>, subtitle: 'Daily revenue performance over the last 7 days.' },
        '30d': { labels: <?= json_encode($labels30) ?>, data: <?= json_encode($data30) ?>, subtitle: 'Daily revenue performance over the last 30 days.' },
        'year': { labels: <?= json_encode($labelsYear) ?>, data: <?= json_encode($dataYear) ?>, subtitle: 'Monthly revenue performance for <?= e($currentYear) ?>.' }
    };

    const salesCtx = document.getElementById('salesChart');
    let salesChart = new Chart(salesCtx, {
        type: 'line',
        data: { labels: salesChartData['7d'].labels, datasets: [{ label: 'Sales', data: salesChartData['7d'].data, fill: true, tension: 0.25, borderColor: '#F2C12E', backgroundColor: 'rgba(242, 193, 46, 0.18)', pointBackgroundColor: '#F2C12E', pointBorderColor: '#F2C12E', pointHoverBackgroundColor: '#F2C12E', pointHoverBorderColor: '#F2C12E' }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });

    const filterButtons = document.querySelectorAll('.chart-filter');
    filterButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            filterButtons.forEach(function(item) {
                item.classList.remove('active');
                item.style.background = 'transparent';
                item.style.color = 'var(--text)';
            });

            this.classList.add('active');
            this.style.background = 'var(--primary)';
            this.style.color = 'var(--dark)';

            const range = this.dataset.range;
            salesChart.data.labels = salesChartData[range].labels;
            salesChart.data.datasets[0].data = salesChartData[range].data;
            salesChart.update();
            document.getElementById('chartSubtitle').textContent = salesChartData[range].subtitle;
        });
    });
    </script>

<?php elseif ($role === 'cashier'): ?>
    <section class="dashboard-kpis four">
        <article class="card stat"><div><span class="muted">My Sales Today</span><strong><?= money($mySales) ?></strong></div><i class="bi bi-cash-stack"></i></article>
        <article class="card stat"><div><span class="muted">My Orders Today</span><strong><?= e($myOrders) ?></strong></div><i class="bi bi-receipt"></i></article>
        <article class="card stat"><div><span class="muted">Cash Sales</span><strong><?= money($myCash) ?></strong></div><i class="bi bi-wallet2"></i></article>
        <article class="card stat"><div><span class="muted">GCash Sales</span><strong><?= money($myGcash) ?></strong></div><i class="bi bi-phone"></i></article>
    </section>

    <div class="dashboard-grid">
        <section class="card">
            <div class="page-head" style="margin-bottom:16px;"><div><h2>My Performance Today</h2><p class="muted">Your POS activity for today.</p></div></div>
            <div class="dashboard-kpis four" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                <article class="card stat"><div><span class="muted">Average Order</span><strong><?= money($myAverage) ?></strong></div><i class="bi bi-calculator"></i></article>
                <article class="card stat"><div><span class="muted">Cash + GCash</span><strong><?= money((float)$myCash + (float)$myGcash) ?></strong></div><i class="bi bi-credit-card"></i></article>
            </div>
        </section>
        <section class="card">
            <div class="page-head" style="margin-bottom:16px;"><div><h2>My Top Items Today</h2><p class="muted">Menu items from your paid orders.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Menu Item</th><th>Quantity Sold</th></tr></thead><tbody>
                <?php foreach ($myTopItems as $item): ?><tr><td><?= e($item['item_name']) ?></td><td><?= e($item['quantity_sold']) ?></td></tr><?php endforeach; ?>
                <?php if (!$myTopItems): ?><tr><td colspan="2" class="muted" style="text-align:center;padding:28px;">No paid orders found today.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </div>

    <section class="card" style="margin-top:24px">
        <div class="page-head" style="margin-bottom:16px;"><div><h2>My Recent Orders</h2><p class="muted">Your latest POS transactions.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Order No.</th><th>Type</th><th>Total</th><th>Status</th><th>Date</th></tr></thead><tbody>
            <?php foreach ($myRecent as $row): ?>
                <?php $status = $row['status'] ?: 'Pending'; ?>
                <tr><td><?= e($row['order_no']) ?></td><td><?= e($row['order_type']) ?></td><td><?= money($row['total']) ?></td><td><?= e($status) ?></td><td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$myRecent): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:28px;">No orders found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

<?php elseif ($role === 'inventory'): ?>
    <section class="dashboard-kpis four">
        <article class="card stat"><div><span class="muted">Low Stock</span><strong><?= e($lowStock) ?></strong></div><i class="bi bi-exclamation-triangle"></i></article>
        <article class="card stat"><div><span class="muted">Out of Stock</span><strong><?= e($outOfStock) ?></strong></div><i class="bi bi-x-circle"></i></article>
        <article class="card stat"><div><span class="muted">Active Menu Items</span><strong><?= e($activeItems) ?></strong></div><i class="bi bi-card-list"></i></article>
        <article class="card stat"><div><span class="muted">My Actions Today</span><strong><?= e($myActions) ?></strong></div><i class="bi bi-arrow-repeat"></i></article>
    </section>

    <section class="card" style="margin-top:24px">
        <div class="page-head" style="margin-bottom:16px;"><div><h2>My Recent Stock Activity</h2><p class="muted">Latest inventory actions performed by you.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Item</th><th>Action</th><th>Quantity</th><th>Remarks</th><th>Date</th></tr></thead><tbody>
            <?php foreach ($myRecentLogs as $row): ?><tr><td><?= e($row['item_name']) ?></td><td><?= e($row['action']) ?></td><td><?= e(rtrim(rtrim(number_format((float)$row['quantity'], 2, '.', ''), '0'), '.')) ?></td><td><?= e($row['remarks'] ?? '') ?></td><td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td></tr><?php endforeach; ?>
            <?php if (!$myRecentLogs): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:28px;">No stock activity found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

<?php elseif ($role === 'kitchen'): ?>
    <section class="dashboard-kpis four">
        <article class="card stat"><div><span class="muted">Pending</span><strong><?= e($pending) ?></strong></div><i class="bi bi-hourglass-split"></i></article>
        <article class="card stat"><div><span class="muted">Preparing</span><strong><?= e($preparing) ?></strong></div><i class="bi bi-fire"></i></article>
        <article class="card stat"><div><span class="muted">Ready</span><strong><?= e($ready) ?></strong></div><i class="bi bi-check-circle"></i></article>
        <article class="card stat"><div><span class="muted">Queued Today</span><strong><?= e($queuedToday) ?></strong></div><i class="bi bi-list-check"></i></article>
    </section>

    <section class="card" style="margin-top:24px">
        <div class="page-head" style="margin-bottom:16px;"><div><h2>Kitchen Queue</h2><p class="muted">Current orders that still need kitchen action.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Order No.</th><th>Type</th><th>Table</th><th>Status</th><th>Queued</th></tr></thead><tbody>
            <?php foreach ($kitchenRecent as $row): ?><tr><td><?= e($row['order_no']) ?></td><td><?= e($row['order_type']) ?></td><td><?= e($row['table_no'] ?: '—') ?></td><td><?= e($row['status']) ?></td><td><?= $row['kitchen_queued_at'] ? e(date('M d, Y h:i A', strtotime($row['kitchen_queued_at']))) : '—' ?></td></tr><?php endforeach; ?>
            <?php if (!$kitchenRecent): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:28px;">No active kitchen orders.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <p class="muted" style="margin-top:12px;">Kitchen activity is based on the shared order queue. The current database does not record which kitchen staff member prepared each order.</p>

<?php elseif ($role === 'server'): ?>
    <section class="dashboard-kpis four">
        <article class="card stat"><div><span class="muted">Ready to Serve</span><strong><?= e($ready) ?></strong></div><i class="bi bi-bell"></i></article>
        <article class="card stat"><div><span class="muted">Served by Me Today</span><strong><?= e($servedToday) ?></strong></div><i class="bi bi-check2-circle"></i></article>
        <article class="card stat"><div><span class="muted">Dine-In Served</span><strong><?= e($myDineIn) ?></strong></div><i class="bi bi-shop"></i></article>
        <article class="card stat"><div><span class="muted">Take-Out Served</span><strong><?= e($myTakeOut) ?></strong></div><i class="bi bi-bag"></i></article>
    </section>

    <section class="card" style="margin-top:24px">
        <div class="page-head" style="margin-bottom:16px;"><div><h2>My Recent Served Orders</h2><p class="muted">Orders you recently marked as served.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Order No.</th><th>Type</th><th>Table</th><th>Total</th><th>Served</th></tr></thead><tbody>
            <?php foreach ($serverRecent as $row): ?><tr><td><?= e($row['order_no']) ?></td><td><?= e($row['order_type']) ?></td><td><?= e($row['table_no'] ?: '—') ?></td><td><?= money($row['total']) ?></td><td><?= $row['served_at'] ? e(date('M d, Y h:i A', strtotime($row['served_at']))) : '—' ?></td></tr><?php endforeach; ?>
            <?php if (!$serverRecent): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:28px;">No served orders found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

<?php else: ?>
    <section class="card">
        <h2>Dashboard</h2>
        <p class="muted">Your dashboard does not have any role-specific analytics yet.</p>
    </section>
<?php endif; ?>

<?php include ROOT_PATH . '/includes/footer.php'; ?>