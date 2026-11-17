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

if ($role === 'admin') {
    $todaySales = dashboard_scalar($conn, "
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status NOT IN ('Cancelled','Voided','Refunded')
    ", [':start' => $todayStart, ':end' => $tomorrowStart]);

    $todayOrders = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status NOT IN ('Cancelled','Voided','Refunded')
    ", [':start' => $todayStart, ':end' => $tomorrowStart]);

    $onlineOrders = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE order_channel = 'ONLINE'
          AND created_at >= :start
          AND created_at < :end
          AND status NOT IN ('Cancelled','Voided','Refunded')
    ", [':start' => $todayStart, ':end' => $tomorrowStart]);

    $lowStock = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM menu_items
        WHERE is_archived = 0
          AND stock BETWEEN 1 AND 5
          AND category_id != 6
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
          AND status NOT IN ('Cancelled','Voided','Refunded')
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
          AND status NOT IN ('Cancelled','Voided','Refunded')
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
          AND status NOT IN ('Cancelled','Voided','Refunded')
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

    $recentPerPage = 10;
    $totalRecentOrders = (int)$conn->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $totalRecentPages = max(1, (int)ceil($totalRecentOrders / $recentPerPage));
    $recentPage = max(1, min((int)($_GET['recent_page'] ?? 1), $totalRecentPages));
    $recentOffset = ($recentPage - 1) * $recentPerPage;

    $recentStmt = $conn->prepare("
        SELECT order_no, order_type, total, status, created_at
        FROM orders
        ORDER BY created_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");
    $recentStmt->bindValue(':limit', $recentPerPage, PDO::PARAM_INT);
    $recentStmt->bindValue(':offset', $recentOffset, PDO::PARAM_INT);
    $recentStmt->execute();
    $recent = $recentStmt->fetchAll();

    $topItemsStmt = $conn->prepare("
        SELECT oi.item_name, SUM(oi.quantity) AS quantity_sold,
               COALESCE(mi.image, '') AS image,
               COALESCE(c.category_name, 'Main Dish') AS category_name
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        LEFT JOIN categories c ON c.id = mi.category_id
        WHERE o.paid_at IS NOT NULL
          AND o.paid_at >= :start
          AND o.paid_at < :end
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
        GROUP BY oi.item_name
        ORDER BY quantity_sold DESC, oi.item_name ASC
        LIMIT 5
    ");
    $topItemsStmt->execute([':start' => $todayStart, ':end' => $tomorrowStart]);
    $topItems = $topItemsStmt->fetchAll();

    // Fallback to all-time or past 30 days if today has few orders
    if (empty($topItems)) {
        $topItemsStmt2 = $conn->prepare("
            SELECT oi.item_name, SUM(oi.quantity) AS quantity_sold,
                   COALESCE(mi.image, '') AS image,
                   COALESCE(c.category_name, 'Main Dish') AS category_name
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
            LEFT JOIN categories c ON c.id = mi.category_id
            WHERE o.paid_at IS NOT NULL
              AND o.status NOT IN ('Cancelled','Voided','Refunded')
            GROUP BY oi.item_name
            ORDER BY quantity_sold DESC, oi.item_name ASC
            LIMIT 5
        ");
        $topItemsStmt2->execute();
        $topItems = $topItemsStmt2->fetchAll();
    }

    // Additional admin analytics:
    // Total 7-day revenue, 30-day revenue, and yearly revenue
    $rev7Total = array_sum($data7);
    $avgDaily7 = count($data7) > 0 ? ($rev7Total / count($data7)) : 0;

    $rev30Total = array_sum($data30);
    $avgDaily30 = count($data30) > 0 ? ($rev30Total / count($data30)) : 0;

    $revYearTotal = array_sum($dataYear);
    $avgMonthlyYear = count($dataYear) > 0 ? ($revYearTotal / 12) : 0;

    // Completed orders counts for periods
    $completed7 = (int)dashboard_scalar($conn, "
        SELECT COUNT(*) FROM orders 
        WHERE paid_at IS NOT NULL 
          AND paid_at >= :start AND paid_at < :end 
          AND status IN ('Served','Completed')
    ", [':start' => $sevenDaysAgo . ' 00:00:00', ':end' => $tomorrowStart]);

    $completed30 = (int)dashboard_scalar($conn, "
        SELECT COUNT(*) FROM orders 
        WHERE paid_at IS NOT NULL 
          AND paid_at >= :start AND paid_at < :end 
          AND status IN ('Served','Completed')
    ", [':start' => $thirtyDaysAgo . ' 00:00:00', ':end' => $tomorrowStart]);

    $completedYear = (int)dashboard_scalar($conn, "
        SELECT COUNT(*) FROM orders 
        WHERE paid_at IS NOT NULL 
          AND paid_at >= :start AND paid_at < :end 
          AND status IN ('Served','Completed')
    ", [':start' => $currentYear . '-01-01 00:00:00', ':end' => $nextYear . '-01-01 00:00:00']);

    // Order channel breakdown for donut chart (Dine-in, Takeout, Online)
    $channelDineIn = (int)dashboard_scalar($conn, "SELECT COUNT(*) FROM orders WHERE order_type = 'DINE-IN' AND status NOT IN ('Cancelled','Voided','Refunded')");
    $channelTakeOut = (int)dashboard_scalar($conn, "SELECT COUNT(*) FROM orders WHERE order_type = 'TAKE-OUT' AND status NOT IN ('Cancelled','Voided','Refunded')");
    $channelOnline = (int)dashboard_scalar($conn, "SELECT COUNT(*) FROM orders WHERE order_channel = 'ONLINE' AND status NOT IN ('Cancelled','Voided','Refunded')");
    $totalAllOrders = max(1, $channelDineIn + $channelTakeOut + $channelOnline);

    // Critical low stock & out of stock items preview (top 4)
    $alertStockItemsStmt = $conn->query("
        SELECT item_name, stock, price
        FROM menu_items
        WHERE is_archived = 0 AND stock <= 5 AND category_id != 6
        ORDER BY stock ASC, item_name ASC
        LIMIT 5
    ");
    $alertStockItems = $alertStockItemsStmt->fetchAll();
} elseif ($role === 'cashier') {
    $mySales = dashboard_scalar($conn, "
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE user_id = :user_id
          AND paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status NOT IN ('Cancelled','Voided','Refunded')
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myOrders = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE user_id = :user_id
          AND paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at < :end
          AND status NOT IN ('Cancelled','Voided','Refunded')
    ", [':user_id' => $userId, ':start' => $todayStart, ':end' => $tomorrowStart]);

    $myCash = dashboard_scalar($conn, "
        SELECT COALESCE(SUM(o.total), 0)
        FROM orders o
        INNER JOIN payments p ON p.order_id = o.id
        WHERE o.user_id = :user_id
          AND o.paid_at IS NOT NULL
          AND o.paid_at >= :start
          AND o.paid_at < :end
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
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
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
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
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
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
          AND category_id != 6
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
               CASE WHEN il.menu_item_id IS NOT NULL THEN 'pcs' ELSE NULLIF(TRIM(i.unit), '') END AS unit,
               COALESCE(mi.item_name, i.ingredient_name, 'Inventory Item') AS item_name
        FROM inventory_logs il
        LEFT JOIN menu_items mi ON mi.id = il.menu_item_id
        LEFT JOIN inventory i ON i.id = il.inventory_id
        WHERE il.user_id = :user_id
        ORDER BY il.created_at DESC, il.id DESC
        LIMIT 8
    ");
    $myRecentLogsStmt->execute([':user_id' => $userId]);
    $myRecentLogs = $myRecentLogsStmt->fetchAll();
} elseif ($role === 'kitchen') {
    $kitchenVisible = "o.kitchen_queued_at IS NOT NULL AND (o.order_channel <> 'ONLINE' OR EXISTS (
        SELECT 1 FROM payments p WHERE p.order_id = o.id
          AND (p.payment_method <> 'GCash' OR p.payment_status = 'Paid')
    ))";
    $pending = dashboard_scalar($conn, "SELECT COUNT(*) FROM orders o WHERE o.status = 'Pending' AND $kitchenVisible");
    $preparing = dashboard_scalar($conn, "SELECT COUNT(*) FROM orders o WHERE o.status = 'Preparing' AND $kitchenVisible");
    $ready = dashboard_scalar($conn, "SELECT COUNT(*) FROM orders o WHERE o.status = 'Ready' AND $kitchenVisible");
    $queuedToday = dashboard_scalar($conn, "
        SELECT COUNT(*)
        FROM orders
        WHERE kitchen_queued_at >= :start
          AND kitchen_queued_at < :end
          AND status NOT IN ('Cancelled','Voided','Refunded')
    ", [':start' => $todayStart, ':end' => $tomorrowStart]);

    $kitchenRecentStmt = $conn->query("
        SELECT o.order_no, o.order_type, o.table_no, o.status, o.kitchen_queued_at
        FROM orders o
        WHERE o.status IN ('Pending','Preparing','Ready') AND $kitchenVisible
        ORDER BY o.kitchen_queued_at ASC
        LIMIT 8
    ");
    $kitchenRecent = $kitchenRecentStmt->fetchAll();
}

include ROOT_PATH . '/includes/header.php';
?>
<style>
/* ==========================================================================
   KENJI'S KITCHEN MODERN CLEAN FLAT ADMIN DASHBOARD (DRIBBLE EDITORIAL SPEC)
   ========================================================================== */
.admin-dashboard-wrap {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

/* Clean Flat KPI Cards Grid */
.kpi-grid-6 {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 16px;
}

@media (max-width: 1400px) {
    .kpi-grid-6 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 860px) {
    .kpi-grid-6 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .kpi-grid-6 {
        grid-template-columns: 1fr;
    }
}

.kpi-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
    border: 1px solid rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 110px;
    position: relative;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
}

.kpi-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.kpi-card-title {
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    letter-spacing: 0.01em;
}

.kpi-card-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    flex-shrink: 0;
}

.kpi-icon-gold {
    background: rgba(242, 193, 46, 0.15);
    color: #b48508;
}

.kpi-icon-green {
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
}

.kpi-icon-blue {
    background: rgba(59, 130, 246, 0.12);
    color: #2563eb;
}

.kpi-icon-orange {
    background: rgba(245, 158, 11, 0.14);
    color: #d97706;
}

.kpi-icon-red {
    background: rgba(239, 68, 68, 0.12);
    color: #dc2626;
}

.kpi-icon-purple {
    background: rgba(147, 51, 234, 0.12);
    color: #7c3aed;
}

.kpi-card-value {
    font-size: 1.65rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.2;
    margin-top: 10px;
    display: flex;
    align-items: baseline;
    gap: 6px;
}

.kpi-card-sub {
    font-size: 0.74rem;
    color: #94a3b8;
    margin-top: 4px;
    font-weight: 500;
}

/* 2-Column Analytics Main Split */
.admin-analytics-grid {
    display: grid;
    grid-template-columns: 2.2fr 1fr;
    gap: 20px;
}

@media (max-width: 1100px) {
    .admin-analytics-grid {
        grid-template-columns: 1fr;
    }
}

.admin-analytics-grid > *,
.admin-operations-grid > *,
.analytics-panel {
    min-width: 0;
}

.analytics-panel {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
    border: 1px solid rgba(0, 0, 0, 0.05);
}

@media (max-width: 600px) {
    .analytics-panel { padding: 16px; }
    .chart-metrics-bar { grid-template-columns: 1fr; gap: 12px; }
}

.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.panel-title-group h2 {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.panel-title-group p {
    font-size: 0.82rem;
    color: #64748b;
    margin: 3px 0 0;
}

/* Chart Quick Filter Pills */
.filter-pills {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 10px;
    gap: 3px;
}

.filter-pill-btn {
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.16s ease;
    font-family: inherit;
}

.filter-pill-btn:hover {
    color: #1e293b;
}

.filter-pill-btn.active {
    background: var(--primary, #F2C12E);
    color: #1e1e1e;
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(242, 193, 46, 0.35);
}

/* Chart Summary Metric Header */
.chart-metrics-bar {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding: 14px 18px;
    background: #f8fafc;
    border-radius: 12px;
    margin-bottom: 20px;
    border: 1px solid #edf2f7;
}

.chart-metric-item {
    display: flex;
    flex-direction: column;
}

.chart-metric-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.chart-metric-val {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin-top: 3px;
}

.chart-container-rel {
    position: relative;
    height: 290px;
    width: 100%;
}

/* Donut & Channel Distribution Card */
.donut-card-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding-top: 6px;
}

.donut-chart-container {
    position: relative;
    width: 200px;
    height: 200px;
    margin: 0 auto;
}

.donut-center-metric {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
}

.donut-center-metric strong {
    display: block;
    font-size: 1.35rem;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.1;
}

.donut-center-metric span {
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
}

.donut-legend-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    margin-top: 22px;
}

.donut-legend-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.84rem;
    padding: 6px 10px;
    background: #f8fafc;
    border-radius: 8px;
}

.donut-legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 8px;
}

/* Lower Operational Split Grid */
.admin-operations-grid {
    display: grid;
    grid-template-columns: 1.45fr 1fr;
    gap: 20px;
}

@media (max-width: 1100px) {
    .admin-operations-grid {
        grid-template-columns: 1fr;
    }
}

/* Modern Clean Tables for Admin Dashboard */
.clean-dash-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.86rem;
}

.clean-dash-table th {
    background: #f8fafc;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.03em;
    padding: 10px 14px;
    border-bottom: 1px solid #edf2f7;
    text-align: left;
}

.clean-dash-table td {
    padding: 12px 14px;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.clean-dash-table tr:last-child td {
    border-bottom: none;
}

.clean-dash-table tr:hover td {
    background: #fafbfc;
}

/* Status Pill Badges */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-pill-success {
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
}

.status-pill-warning {
    background: rgba(245, 158, 11, 0.14);
    color: #d97706;
}

.status-pill-info {
    background: rgba(59, 130, 246, 0.12);
    color: #2563eb;
}

.status-pill-danger {
    background: rgba(239, 68, 68, 0.12);
    color: #dc2626;
}

.status-pill-primary {
    background: rgba(242, 193, 46, 0.22);
    color: #92400e;
}

/* Top Selling Products List Item */
.top-product-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.top-product-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    transition: background 0.15s ease;
}

.top-product-item:hover {
    background: #f1f5f9;
}

.top-product-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.top-product-rank {
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: rgba(242, 193, 46, 0.2);
    color: #b48508;
    font-size: 0.75rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.top-product-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.88rem;
    line-height: 1.2;
}

.top-product-cat {
    font-size: 0.74rem;
    color: #94a3b8;
}

.top-product-sold {
    text-align: right;
}

.top-product-sold strong {
    display: block;
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
}

.top-product-sold span {
    font-size: 0.72rem;
    color: #64748b;
}

/* Low Stock Alert List */
.stock-alert-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-radius: 10px;
    margin-bottom: 8px;
    font-size: 0.85rem;
}

.stock-alert-low {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    color: #b45309;
}

.stock-alert-out {
    background: #fef2f2;
    border: 1px solid #fee2e2;
    color: #b91c1c;
}

.dashboard-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 24px;
}

.dashboard-kpis.four {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.dashboard-kpis.six {
    grid-template-columns: repeat(6, minmax(0, 1fr));
}

@media (max-width: 1200px) {
    .dashboard-kpis,
    .dashboard-kpis.four,
    .dashboard-kpis.six {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .dashboard-kpis,
    .dashboard-kpis.four,
    .dashboard-kpis.six {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .dashboard-kpis,
    .dashboard-kpis.four,
    .dashboard-kpis.six {
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

.dashboard-storefront { display:inline-flex; align-items:center; gap:10px; padding:12px 18px; background:#f7c62f; color:#242424; border:1px solid #e8ba24; border-radius:11px; font-size:14px; font-weight:650; text-decoration:none; }
.dashboard-storefront:hover { background:#ebbc24; }
.dashboard-storefront i:last-child { font-size:12px; margin-left:6px; }
.admin-dashboard-wrap .kpi-card,.admin-dashboard-wrap .analytics-panel { border:1px solid #e9e5dc; box-shadow:0 4px 16px #00000003; border-radius:15px; }
.admin-dashboard-wrap .kpi-card-title { color:#625d52; font-weight:600; }
.admin-dashboard-wrap .kpi-card-sub { color:#807969; line-height:1.5; }
.admin-dashboard-wrap .kpi-card-value { color:#242424; font-variant-numeric:tabular-nums; }
.admin-dashboard-wrap .panel-header { gap:16px; flex-wrap:wrap; }
.admin-dashboard-wrap .panel-title-group p { color:#797264; line-height:1.5; }
.admin-dashboard-wrap .filter-pills { background:#f5f2e9; padding:4px; border-radius:10px; gap:4px; }
.admin-dashboard-wrap .filter-pill-btn { padding:8px 14px; color:#635b49; border-radius:7px; }
.admin-dashboard-wrap .filter-pill-btn.active { background:#f7c62f; color:#242424; font-weight:700; box-shadow:0 1px 3px #9a741022; }
.admin-dashboard-wrap .chart-metrics-bar { background:#fcfaf3; border-color:#eee6cf; border-radius:11px; }
.admin-dashboard-wrap .chart-metric-label { color:#766a50; }
.admin-dashboard-wrap .top-product-item { background:#faf9f5; border-color:#eee9df; }
.admin-dashboard-wrap .top-product-rank { background:#fff0b8; color:#785700; }
.admin-dashboard-wrap .top-product-cat { color:#807969; }
.admin-dashboard-wrap button:focus-visible,.dashboard-storefront:focus-visible { outline:3px solid #ad8111; outline-offset:3px; }
</style>

<div class="page-head" style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h1>Welcome, <?= e($user['username']) ?></h1>
        <p class="muted"><?= e($user['role']) ?> Dashboard · <?= e(date('F j, Y')) ?></p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/online-menu/" target="_blank" rel="noopener noreferrer" class="btn btn-primary dashboard-storefront" aria-label="Open online store in a new tab">
            <i class="bi bi-shop" aria-hidden="true"></i><span>Open Online Store</span><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
        </a>
    </div>
</div>

<?php if ($role === 'admin'): ?>
    <div class="admin-dashboard-wrap">
        <!-- 6 Main KPI Stat Cards -->
        <section class="kpi-grid-6">
            <!-- 1. Today's Sales -->
            <article class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Today's Sales</span>
                    <div class="kpi-card-icon kpi-icon-gold"><i class="bi bi-cash-stack"></i></div>
                </div>
                <div>
                    <div class="kpi-card-value"><?= money($todaySales) ?></div>
                    <div class="kpi-card-sub">Paid POS & online revenue</div>
                </div>
            </article>

            <!-- 2. Today's Orders -->
            <article class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Today's Orders</span>
                    <div class="kpi-card-icon kpi-icon-green"><i class="bi bi-bag-check"></i></div>
                </div>
                <div>
                    <div class="kpi-card-value"><?= number_format((int)$todayOrders) ?></div>
                    <div class="kpi-card-sub">Total active & completed</div>
                </div>
            </article>

            <!-- 3. Online Orders -->
            <article class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Online Orders</span>
                    <div class="kpi-card-icon kpi-icon-blue"><i class="bi bi-globe2"></i></div>
                </div>
                <div>
                    <div class="kpi-card-value"><?= number_format((int)$onlineOrders) ?></div>
                    <div class="kpi-card-sub">Web storefront orders</div>
                </div>
            </article>

            <!-- 4. Low Stock -->
            <article class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Low Stock</span>
                    <div class="kpi-card-icon kpi-icon-orange"><i class="bi bi-exclamation-triangle"></i></div>
                </div>
                <div>
                    <div class="kpi-card-value"><?= number_format((int)$lowStock) ?></div>
                    <div class="kpi-card-sub">Items between 1 to 5 stock</div>
                </div>
            </article>

            <!-- 5. Out of Stock -->
            <article class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Out of Stock</span>
                    <div class="kpi-card-icon kpi-icon-red"><i class="bi bi-x-circle"></i></div>
                </div>
                <div>
                    <div class="kpi-card-value"><?= number_format((int)$outOfStock) ?></div>
                    <div class="kpi-card-sub">Immediate restock needed</div>
                </div>
            </article>

            <!-- 6. Pending Orders -->
            <article class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Pending Orders</span>
                    <div class="kpi-card-icon kpi-icon-purple"><i class="bi bi-hourglass-split"></i></div>
                </div>
                <div>
                    <div class="kpi-card-value"><?= number_format((int)$pendingOrders) ?></div>
                    <div class="kpi-card-sub">Awaiting confirmation/prep</div>
                </div>
            </article>
        </section>

        <!-- Main Analytics & Order Breakdown Split -->
        <div class="admin-analytics-grid">
            <!-- Sales Line Chart Section -->
            <section class="analytics-panel">
                <div class="panel-header">
                    <div class="panel-title-group">
                        <h2>Sales Performance</h2>
                        <p id="chartSubtitle">Daily revenue performance over the last 7 days.</p>
                    </div>
                    <div class="filter-pills">
                        <button type="button" class="filter-pill-btn active" data-range="7d" title="Last 7 days">Weekly</button>
                        <button type="button" class="filter-pill-btn" data-range="30d" title="Last 30 days">Monthly</button>
                        <button type="button" class="filter-pill-btn" data-range="year" title="Current calendar year">Yearly</button>
                    </div>
                </div>

                <!-- 3 Summary Metrics in Chart Header -->
                <div class="chart-metrics-bar">
                    <div class="chart-metric-item">
                        <span class="chart-metric-label">Total Revenue</span>
                        <strong class="chart-metric-val" id="metricTotalRev"><?= money($rev7Total) ?></strong>
                    </div>
                    <div class="chart-metric-item">
                        <span class="chart-metric-label" id="metricAvgLabel">Average Daily Sales</span>
                        <strong class="chart-metric-val" id="metricAvgVal"><?= money($avgDaily7) ?></strong>
                    </div>
                    <div class="chart-metric-item">
                        <span class="chart-metric-label">Completed Orders</span>
                        <strong class="chart-metric-val" id="metricCompleted"><?= number_format($completed7) ?></strong>
                    </div>
                </div>

                <div class="chart-container-rel">
                    <canvas id="salesChart"></canvas>
                </div>
            </section>

            <!-- Order Channels & Status Overview (Donut Chart) -->
            <section class="analytics-panel">
                <div class="panel-header">
                    <div class="panel-title-group">
                        <h2>Order Distribution</h2>
                        <p>Orders breakdown by channel</p>
                    </div>
                </div>

                <div class="donut-card-body">
                    <div class="donut-chart-container">
                        <canvas id="channelDonutChart"></canvas>
                        <div class="donut-center-metric">
                            <strong><?= number_format($channelDineIn + $channelTakeOut + $channelOnline) ?></strong>
                            <span>Total Orders</span>
                        </div>
                    </div>

                    <div class="donut-legend-list">
                        <div class="donut-legend-item">
                            <span style="display:flex; align-items:center;">
                                <span class="donut-legend-dot" style="background:#F2C12E;"></span> Dine-In
                            </span>
                            <strong><?= number_format($channelDineIn) ?> <small class="muted">(<?= round(($channelDineIn / $totalAllOrders) * 100) ?>%)</small></strong>
                        </div>
                        <div class="donut-legend-item">
                            <span style="display:flex; align-items:center;">
                                <span class="donut-legend-dot" style="background:#39B54A;"></span> Take-Out
                            </span>
                            <strong><?= number_format($channelTakeOut) ?> <small class="muted">(<?= round(($channelTakeOut / $totalAllOrders) * 100) ?>%)</small></strong>
                        </div>
                        <div class="donut-legend-item">
                            <span style="display:flex; align-items:center;">
                                <span class="donut-legend-dot" style="background:#3B82F6;"></span> Online Orders
                            </span>
                            <strong><?= number_format($channelOnline) ?> <small class="muted">(<?= round(($channelOnline / $totalAllOrders) * 100) ?>%)</small></strong>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Operational Feeds Grid: Recent Orders (Left) & Top-Selling Products + Low Stock (Right) -->
        <div class="admin-operations-grid">
            <!-- Recent Orders / Transactions -->
            <section class="analytics-panel">
                <div class="panel-header">
                    <div class="panel-title-group">
                        <h2>Recent Transactions</h2>
                        <p>Latest POS and online customer orders</p>
                    </div>
                    <div>
                        <?= render_pagination_controls($recentPage, $totalRecentPages, static fn(int $p) => '?recent_page=' . $p) ?>
                    </div>
                </div>

                <div class="table-wrap" style="border:none; margin:0; padding:0;">
                    <table class="clean-dash-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Channel / Type</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $row): ?>
                                <?php
                                $status = $row['status'] ?: 'Pending';
                                $pillClass = match($status) {
                                    'Served', 'Completed' => 'status-pill-success',
                                    'Ready' => 'status-pill-primary',
                                    'Preparing' => 'status-pill-info',
                                    'Cancelled', 'Voided' => 'status-pill-danger',
                                    default => 'status-pill-warning',
                                };
                                ?>
                                <tr>
                                    <td><strong style="color:#0f172a;"><?= e($row['order_no']) ?></strong></td>
                                    <td>
                                        <span style="font-weight:500;"><?= e(ucfirst(strtolower($row['order_type']))) ?></span>
                                    </td>
                                    <td><strong style="color:#1e293b;"><?= money($row['total']) ?></strong></td>
                                    <td>
                                        <span class="status-pill <?= $pillClass ?>">
                                            <i class="bi bi-circle-fill" style="font-size:6px;"></i>
                                            <?= e($status) ?>
                                        </span>
                                    </td>
                                    <td style="color:#64748b; font-size:0.8rem;">
                                        <?= e(date('M d, h:i A', strtotime($row['created_at']))) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recent): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:32px; color:#94a3b8;">No recent orders recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Top-Selling Products & Stock Alerts -->
            <section class="analytics-panel" style="display:flex; flex-direction:column; gap:24px;">
                <!-- Top-Selling Products -->
                <div>
                    <div class="panel-header" style="margin-bottom:14px;">
                        <div class="panel-title-group">
                            <h2>Top-Selling Products</h2>
                            <p>Highest performing dishes</p>
                        </div>
                        <a href="<?= BASE_URL ?>/modules/reports/sales_performance.php" class="muted" style="font-size:0.8rem; font-weight:600; color:var(--primary-hover);">View Report →</a>
                    </div>

                    <div class="top-product-list">
                        <?php $rank = 1; ?>
                        <?php foreach ($topItems as $item): ?>
                            <div class="top-product-item">
                                <div class="top-product-info">
                                    <div class="top-product-rank"><?= $rank++ ?></div>
                                    <div>
                                        <div class="top-product-name"><?= e($item['item_name']) ?></div>
                                        <div class="top-product-cat"><?= e($item['category_name']) ?></div>
                                    </div>
                                </div>
                                <div class="top-product-sold">
                                    <strong><?= number_format($item['quantity_sold']) ?> sold</strong>
                                    <span>paid orders</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($topItems)): ?>
                            <div style="text-align:center; padding:24px; color:#94a3b8; font-size:0.88rem;">No paid sales data available.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Low Stock & Out of Stock Watchlist -->
                <div style="border-top:1px solid #f1f5f9; padding-top:18px;">
                    <div class="panel-header" style="margin-bottom:12px;">
                        <div class="panel-title-group">
                            <h2 style="font-size:1.02rem;">Low Stock Alerts</h2>
                            <p>Items requiring inventory replenishment</p>
                        </div>
                        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="muted" style="font-size:0.8rem; font-weight:600; color:var(--primary-hover);">Inventory →</a>
                    </div>

                    <div>
                        <?php if (!empty($alertStockItems)): ?>
                            <?php foreach ($alertStockItems as $stk): ?>
                                <div class="stock-alert-item <?= (int)$stk['stock'] === 0 ? 'stock-alert-out' : 'stock-alert-low' ?>">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <i class="bi <?= (int)$stk['stock'] === 0 ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                                        <strong><?= e($stk['item_name']) ?></strong>
                                    </div>
                                    <span style="font-weight:700; font-size:0.82rem;">
                                        <?= (int)$stk['stock'] === 0 ? 'OUT OF STOCK' : e($stk['stock']) . ' left' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="background:#f0fdf4; border:1px solid #dcfce7; color:#166534; padding:12px 14px; border-radius:10px; font-size:0.84rem; display:flex; align-items:center; gap:8px;">
                                <i class="bi bi-check-circle-fill"></i> All menu inventory items have healthy stock levels!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
    // Sales Trends Data
    const salesChartData = {
        '7d': {
            labels: <?= json_encode($labels7) ?>,
            data: <?= json_encode($data7) ?>,
            subtitle: 'Daily revenue performance over the last 7 days.',
            totalRev: '<?= money($rev7Total) ?>',
            avgLabel: 'Average Daily Sales',
            avgVal: '<?= money($avgDaily7) ?>',
            completed: '<?= number_format($completed7) ?>'
        },
        '30d': {
            labels: <?= json_encode($labels30) ?>,
            data: <?= json_encode($data30) ?>,
            subtitle: 'Daily revenue performance over the last 30 days.',
            totalRev: '<?= money($rev30Total) ?>',
            avgLabel: 'Average Daily Sales',
            avgVal: '<?= money($avgDaily30) ?>',
            completed: '<?= number_format($completed30) ?>'
        },
        'year': {
            labels: <?= json_encode($labelsYear) ?>,
            data: <?= json_encode($dataYear) ?>,
            subtitle: 'Monthly revenue performance for <?= e($currentYear) ?>.',
            totalRev: '<?= money($revYearTotal) ?>',
            avgLabel: 'Average Monthly Sales',
            avgVal: '<?= money($avgMonthlyYear) ?>',
            completed: '<?= number_format($completedYear) ?>'
        }
    };

    const salesCtx = document.getElementById('salesChart');
    let salesChart = new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: salesChartData['7d'].labels,
            datasets: [{
                label: 'Revenue',
                data: salesChartData['7d'].data,
                fill: true,
                tension: 0.38,
                borderWidth: 2.5,
                borderColor: '#F2C12E',
                backgroundColor: 'rgba(242, 193, 46, 0.12)',
                pointBackgroundColor: '#F2C12E',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#1e1e1e',
                pointHoverBorderColor: '#F2C12E'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e1e1e',
                    titleColor: '#ffffff',
                    bodyColor: '#F2C12E',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return ' ₱' + Number(context.parsed.y).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 11, family: 'Inter' } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 11, family: 'Inter' },
                        callback: function(val) {
                            if (val >= 1000) return '₱' + (val / 1000) + 'k';
                            return '₱' + val;
                        }
                    }
                }
            }
        }
    });

    // Donut Chart: Order Distribution
    const donutCtx = document.getElementById('channelDonutChart');
    new Chart(donutCtx, {
        type: 'doughnut',
        data: {
            labels: ['Dine-In', 'Take-Out', 'Online Orders'],
            datasets: [{
                data: [<?= (int)$channelDineIn ?>, <?= (int)$channelTakeOut ?>, <?= (int)$channelOnline ?>],
                backgroundColor: ['#F2C12E', '#39B54A', '#3B82F6'],
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e1e1e',
                    padding: 8,
                    cornerRadius: 6
                }
            }
        }
    });

    // Filter Buttons logic
    const filterButtons = document.querySelectorAll('.filter-pill-btn');
    filterButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            filterButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');

            const range = this.dataset.range;
            const currentObj = salesChartData[range];

            salesChart.data.labels = currentObj.labels;
            salesChart.data.datasets[0].data = currentObj.data;
            salesChart.update();

            document.getElementById('chartSubtitle').textContent = currentObj.subtitle;
            document.getElementById('metricTotalRev').textContent = currentObj.totalRev;
            document.getElementById('metricAvgLabel').textContent = currentObj.avgLabel;
            document.getElementById('metricAvgVal').textContent = currentObj.avgVal;
            document.getElementById('metricCompleted').textContent = currentObj.completed;
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
        <div class="table-wrap"><table><thead><tr><th>Item</th><th>Action</th><th>Movement / Level</th><th>Remarks</th><th>Date</th></tr></thead><tbody>
            <?php foreach ($myRecentLogs as $row): ?>
                <?php $mv = kk_stock_movement_display((string)($row['action'] ?? ''), $row['quantity'] ?? 0, $row['unit'] ?? null); ?>
                <tr><td><?= e($row['item_name']) ?></td><td><?= e($mv['action_label']) ?></td><td style="font-weight:700;color:<?= e($mv['color']) ?>;white-space:nowrap;"><?= e($mv['qty_text']) ?></td><td><?= e($row['remarks'] ?? '') ?></td><td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td></tr>
            <?php endforeach; ?>
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

<?php else: ?>
    <section class="card">
        <h2>Dashboard</h2>
        <p class="muted">Your dashboard does not have any role-specific analytics yet.</p>
    </section>
<?php endif; ?>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
