<?php
/**
 * Pending COD Cash Report
 * Part 7.2: Unconfirmed COD cash collected by staff but not yet confirmed by cashier.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/functions.php';

require_role('admin');

$page = 'pending_cod_cash';
$title = 'Pending COD Cash Handovers';
$heading = 'Pending COD Cash';

// Unconfirmed handovers
$unconfirmedHandovers = $conn->query("
    SELECT cch.*, o.order_no, o.total, u.full_name AS staff_name, u.username AS staff_user
    FROM cod_cash_handovers cch
    JOIN orders o ON o.id = cch.order_id
    JOIN users u ON u.id = cch.collected_by_user_id
    WHERE cch.status = 'pending'
    ORDER BY cch.collected_at ASC
")->fetchAll();

$totalUnconfirmed = 0.0;
foreach ($unconfirmedHandovers as $uh) {
    $totalUnconfirmed += (float)$uh['amount_collected'];
}

// Delivered / Dispatched COD orders with no cash collection recorded
$missingCashOrders = $conn->query("
    SELECT o.id, o.order_no, o.customer_name, o.customer_phone, o.total, o.created_at, o.status,
           COALESCE(u.full_name, d.rider_name) AS delivered_by_name
    FROM orders o
    LEFT JOIN users u ON u.id = o.delivered_by_user_id
    LEFT JOIN delivery_dispatches d ON d.order_id = o.id
    JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
    WHERE o.order_channel = 'ONLINE'
      AND (p.payment_method = 'Cash on Delivery' OR o.cod_approval_status = 'Approved')
      AND o.status IN ('Served', 'Ready')
      AND (o.cash_collected IS NULL OR o.cash_collected = 0)
      AND p.payment_status = 'Pending'
    ORDER BY o.created_at DESC
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>

<div class="page-head" style="margin-bottom:20px;">
    <h1><i class="bi bi-exclamation-diamond" style="color:var(--primary,#F2C12E);"></i> Pending COD Cash Audit Report</h1>
    <p class="muted">Audit unconfirmed cash in transit: money collected by riders/staff not yet credited to an open cashier shift, and delivered orders lacking cash records.</p>
</div>

<!-- Centralized Reports Hub Navigation Sub-Tabs -->
<div class="tabs" style="margin-bottom: 24px; display: flex; gap: 8px; flex-wrap: wrap;">
    <a href="index.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-cash-stack"></i> Sales Report
    </a>
    <a href="delivery_report.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-truck"></i> Delivery Reports
    </a>
    <a href="pending_cod_cash.php" class="tab active" style="background: var(--primary, #F2C12E); color: var(--dark, #232323); font-weight: 800; border-color: var(--primary); text-decoration: none;">
        <i class="bi bi-exclamation-triangle"></i> Pending COD Cash
    </a>
    <a href="shifts.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-clock-history"></i> Shift Reports
    </a>
    <a href="sales_performance.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-graph-up"></i> Sales Performance
    </a>
</div>

<!-- Alert Banner if unconfirmed cash exists -->
<?php if ($totalUnconfirmed > 0): ?>
<div style="background:#fff3cd; border:1px solid #ffeeba; border-left:5px solid #f59e0b; padding:14px 18px; border-radius:10px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
    <div>
        <strong style="color:#b45309; font-size:15px;"><i class="bi bi-cash-coin"></i> Total Unconfirmed Cash in Hand: ₱<?= number_format($totalUnconfirmed, 2) ?></strong>
        <p style="margin:4px 0 0; font-size:13px; color:#78350f;">This cash was recorded as collected from customers by delivery staff, but has not yet been accepted by a cashier on shift.</p>
    </div>
</div>
<?php endif; ?>

<!-- Section 1: Cash Collected Awaiting Cashier Confirmation -->
<div class="card" style="margin-bottom:24px; padding:20px;">
    <h3 style="margin-top:0; font-size:16px;"><i class="bi bi-clock-history"></i> Cash Collected Awaiting Cashier Confirmation</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Collected By Staff</th>
                    <th>Amount Collected</th>
                    <th>Date / Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($unconfirmedHandovers)): ?>
                <tr><td colspan="5" style="text-align:center; padding:24px; color:var(--muted);">All collected COD cash has been confirmed by cashiers! ✅</td></tr>
                <?php else: foreach ($unconfirmedHandovers as $uh): ?>
                <tr>
                    <td><strong><?= e($uh['order_no']) ?></strong></td>
                    <td><?= e($uh['staff_name']) ?> (@<?= e($uh['staff_user']) ?>)</td>
                    <td><strong style="color:#10b981;">₱<?= number_format((float)$uh['amount_collected'], 2) ?></strong></td>
                    <td><?= date('M d, Y h:i A', strtotime($uh['collected_at'])) ?></td>
                    <td><span class="badge badge-warning">Awaiting Cashier Shift Confirmation</span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Section 2: Dispatched Orders Missing Cash Record -->
<div class="card" style="padding:20px;">
    <h3 style="margin-top:0; font-size:16px; color:#b91c1c;"><i class="bi bi-question-circle"></i> Served / Dispatched Orders Missing Cash Record</h3>
    <p class="muted" style="font-size:12px; margin-bottom:12px;">These orders are marked as Ready or Served, but no cash receipt has been recorded.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Delivering Staff</th>
                    <th>Order Total</th>
                    <th>Order Date</th>
                    <th>Current Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($missingCashOrders)): ?>
                <tr><td colspan="6" style="text-align:center; padding:24px; color:var(--muted);">No missing cash discrepancies found. ✅</td></tr>
                <?php else: foreach ($missingCashOrders as $mo): ?>
                <tr>
                    <td><strong><?= e($mo['order_no']) ?></strong></td>
                    <td><?= e($mo['customer_name']) ?> (<?= e($mo['customer_phone']) ?>)</td>
                    <td><?= e($mo['delivered_by_name'] ?: 'Unassigned') ?></td>
                    <td><strong>₱<?= number_format((float)$mo['total'], 2) ?></strong></td>
                    <td><?= date('M d, Y h:i A', strtotime($mo['created_at'])) ?></td>
                    <td><span class="badge badge-danger"><?= e($mo['status']) ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
