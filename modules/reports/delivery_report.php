<?php
/**
 * Delivery & Payment Policies Summary Report
 * Part 2 & Part 7: Reports delivery income, first-order discounts, third-party courier fees, and COD stats.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/functions.php';

require_role('admin');

$page = 'delivery_report';
$title = 'Delivery & Fulfillment Reports';
$heading = 'Fulfillment Reports';

$rawStart = (string)($_GET['start_date'] ?? date('Y-m-01'));
$rawEnd = (string)($_GET['end_date'] ?? date('Y-m-d'));
$startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart) ? $rawStart : date('Y-m-01');
$endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnd) ? $rawEnd : date('Y-m-d');
$startTs = $startDate . ' 00:00:00';
$endTs = $endDate . ' 23:59:59';

// 1. Lagro Delivery Income Collected
$lagroStmt = $conn->prepare("
    SELECT COALESCE(SUM(delivery_fee), 0)
    FROM orders
    WHERE fulfillment_mode = 'LAGRO'
      AND status NOT IN ('Cancelled', 'Voided', 'Refunded')
      AND created_at BETWEEN ? AND ?
");
$lagroStmt->execute([$startTs, $endTs]);
$lagroIncome = (float)$lagroStmt->fetchColumn();

// 2. Free Delivery Discounts Given
$discountsStmt = $conn->prepare("
    SELECT COUNT(*) AS count_given, COALESCE(SUM(free_delivery_discount), 0) AS total_discount_cost
    FROM orders
    WHERE free_delivery_discount > 0
      AND status NOT IN ('Cancelled', 'Voided', 'Refunded')
      AND created_at BETWEEN ? AND ?
");
$discountsStmt->execute([$startTs, $endTs]);
$discountsGiven = $discountsStmt->fetch();

// 3. Third-party Delivery Courier Fees (Not counted as restaurant food sales)
$tpStmt = $conn->prepare("
    SELECT COALESCE(SUM(delivery_fee), 0)
    FROM thirdparty_deliveries
    WHERE fee_paid = 1
      AND created_at BETWEEN ? AND ?
");
$tpStmt->execute([$startTs, $endTs]);
$thirdPartyFees = (float)$tpStmt->fetchColumn();

// 4. Breakdown by Fulfillment Mode
$modeStmt = $conn->prepare("
    SELECT fulfillment_mode, COUNT(*) AS order_count, COALESCE(SUM(subtotal), 0) AS food_sales, COALESCE(SUM(delivery_fee), 0) AS delivery_income
    FROM orders
    WHERE order_channel = 'ONLINE'
      AND status NOT IN ('Cancelled', 'Voided', 'Refunded')
      AND created_at BETWEEN ? AND ?
    GROUP BY fulfillment_mode
");
$modeStmt->execute([$startTs, $endTs]);
$modeBreakdown = $modeStmt->fetchAll();

// 5. COD Delivery Performance & Failures
$codStmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_cod,
        SUM(CASE WHEN o.status = 'Served' OR o.paid_at IS NOT NULL THEN 1 ELSE 0 END) AS completed_cod,
        SUM(CASE WHEN o.cod_failure_reason IS NOT NULL THEN 1 ELSE 0 END) AS failed_cod,
        COALESCE(SUM(CASE WHEN o.status = 'Served' THEN o.total ELSE 0 END), 0) AS total_cod_collected
    FROM orders o
    LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
    WHERE (p.payment_method = 'Cash on Delivery' OR o.cod_approval_status IN ('Approved', 'Pending', 'Declined'))
      AND o.created_at BETWEEN ? AND ?
");
$codStmt->execute([$startTs, $endTs]);
$codStats = $codStmt->fetch();

include ROOT_PATH . '/includes/header.php';
?>

<div class="page-head" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:16px;">
    <div>
        <h1><i class="bi bi-graph-up-arrow" style="color:var(--primary,#F2C12E);"></i> Delivery &amp; Fulfillment Report</h1>
        <p class="muted">Track restaurant delivery income, first-order promotions, third-party courier pass-through fees, and COD completion rates.</p>
    </div>
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
        <input type="date" name="start_date" value="<?= e($startDate) ?>" class="search-input" style="padding:6px 12px; font-size:13px;">
        <span>to</span>
        <input type="date" name="end_date" value="<?= e($endDate) ?>" class="search-input" style="padding:6px 12px; font-size:13px;">
        <button type="submit" class="btn btn-primary" style="padding:6px 14px; font-size:13px;"><i class="bi bi-funnel"></i> Filter</button>
    </form>
</div>

<!-- Centralized Reports Hub Navigation Sub-Tabs -->
<div class="tabs" style="margin-bottom: 24px; display: flex; gap: 8px; flex-wrap: wrap;">
    <a href="index.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-cash-stack"></i> Sales Report
    </a>
    <a href="delivery_report.php" class="tab active" style="background: var(--primary, #F2C12E); color: var(--dark, #232323); font-weight: 800; border-color: var(--primary); text-decoration: none;">
        <i class="bi bi-truck"></i> Delivery Reports
    </a>
    <a href="pending_cod_cash.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-exclamation-triangle"></i> Pending COD Cash
    </a>
    <a href="shifts.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-clock-history"></i> Shift Reports
    </a>
    <a href="sales_performance.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-graph-up"></i> Sales Performance
    </a>
</div>

<!-- KPI Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px; margin-bottom:24px;">
    <article class="card stat" style="border-left:4px solid #10b981;">
        <div>
            <span class="muted">Lagro Delivery Income</span>
            <strong style="color:#059669;">₱<?= number_format($lagroIncome, 2) ?></strong>
            <small class="muted" style="display:block; margin-top:4px;">100% Restaurant Revenue</small>
        </div>
        <i class="bi bi-bicycle" style="font-size:24px; color:#10b981;"></i>
    </article>

    <article class="card stat" style="border-left:4px solid #f59e0b;">
        <div>
            <span class="muted">Free First-Order Deliveries</span>
            <strong><?= (int)$discountsGiven['count_given'] ?> orders</strong>
            <small class="muted" style="display:block; margin-top:4px;">Cost: ₱<?= number_format((float)$discountsGiven['total_discount_cost'], 2) ?></small>
        </div>
        <i class="bi bi-gift" style="font-size:24px; color:#f59e0b;"></i>
    </article>

    <article class="card stat" style="border-left:4px solid #8b5cf6;">
        <div>
            <span class="muted">Third-Party Courier Fees</span>
            <strong style="color:#7c3aed;">₱<?= number_format($thirdPartyFees, 2) ?></strong>
            <small class="muted" style="display:block; margin-top:4px;">Pass-Through (Lalamove/Grab)</small>
        </div>
        <i class="bi bi-box-seam" style="font-size:24px; color:#8b5cf6;"></i>
    </article>

    <article class="card stat" style="border-left:4px solid #3b82f6;">
        <div>
            <span class="muted">COD Success Rate</span>
            <strong>
                <?= ((int)$codStats['total_cod'] > 0) ? round(((int)$codStats['completed_cod'] / (int)$codStats['total_cod']) * 100, 1) : 100 ?>%
            </strong>
            <small class="muted" style="display:block; margin-top:4px;">Failed/Refused: <?= (int)$codStats['failed_cod'] ?></small>
        </div>
        <i class="bi bi-cash-stack" style="font-size:24px; color:#3b82f6;"></i>
    </article>
</div>

<!-- Fulfillment Mode Breakdown Table -->
<div class="card" style="margin-bottom:24px; padding:20px;">
    <h3 style="margin-top:0; font-size:16px;">Online Orders by Fulfillment Mode</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Fulfillment Mode</th>
                    <th>Orders Handled</th>
                    <th>Food Sales (₱)</th>
                    <th>Delivery Fee Income (₱)</th>
                    <th>Total Billed (₱)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($modeBreakdown)): ?>
                <tr><td colspan="5" style="text-align:center; padding:24px; color:var(--muted);">No online orders in this date range.</td></tr>
                <?php else: foreach ($modeBreakdown as $mb):
                    $fMode = match($mb['fulfillment_mode']) {
                        'LAGRO' => 'Lagro In-House Delivery (₱15)',
                        'OUTSIDE' => 'Outside Lagro Third-Party Courier',
                        default => 'Store Pickup'
                    };
                ?>
                <tr>
                    <td><strong><?= e($fMode) ?></strong></td>
                    <td><?= e($mb['order_count']) ?></td>
                    <td>₱<?= number_format((float)$mb['food_sales'], 2) ?></td>
                    <td><strong style="color:#10b981;">₱<?= number_format((float)$mb['delivery_income'], 2) ?></strong></td>
                    <td><strong>₱<?= number_format((float)$mb['food_sales'] + (float)$mb['delivery_income'], 2) ?></strong></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
