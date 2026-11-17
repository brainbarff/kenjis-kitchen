<?php
/**
 * Kenji's Kitchen - Shift Reports & Cashier Performance
 * Modules /reports/shifts.php (Admin & Manager)
 *
 * Features:
 * - Cashier name, terminal, start/end time, duration, orders, total sales, cash vs gcash,
 *   voids/refunds (with approver), opening/closing cash, over/short variance, notes.
 * - Date, cashier, and role filters.
 * - Per-cashier performance summary (orders handled, total sales, AOV, voids, discrepancies).
 * - Printable view and CSV export.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/transaction_rules.php';
require_role('admin');

$page = 'shifts';
$title = 'Shift Reports';
$heading = 'Shift Reports & Handover Audit';

// Filters
$today = date('Y-m-d');
$fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
$toDate = $_GET['to_date'] ?? $today;
$filterCashier = (int)($_GET['cashier_id'] ?? 0);
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

// Base Query for Shifts
$where = ["DATE(cs.opened_at) >= ? AND DATE(cs.opened_at) <= ?"];
$params = [$fromDate, $toDate];

if ($filterCashier > 0) {
    $where[] = "cs.user_id = ?";
    $params[] = $filterCashier;
}

$whereSql = implode(' AND ', $where);

// Detailed shifts list
$shiftsStmt = $conn->prepare("
    SELECT cs.*, u.full_name, u.username, r.role_name
    FROM cashier_shifts cs
    JOIN users u ON u.id = cs.user_id
    JOIN roles r ON r.id = u.role_id
    WHERE {$whereSql}
    ORDER BY cs.opened_at DESC
");
$shiftsStmt->execute($params);
$shifts = $shiftsStmt->fetchAll();

foreach ($shifts as &$sRow) {
    $m = kk_calculate_shift_metrics($conn, (int)$sRow['id']);
    $sRow['calculated_orders'] = $m['order_count'];
    $sRow['calculated_total']  = $m['total_sales'];
    $sRow['calculated_cash']   = $m['cash_sales'];
    $sRow['calculated_gcash']  = $m['gcash_sales'];
    $sRow['calculated_refunds']= $m['total_refunds'];
    $sRow['calculated_cash_refunds'] = $m['cash_refunds'];
    $sRow['void_count']        = $m['void_count'];
    $sRow['void_total']        = $m['void_total'];
    if ($sRow['expected_cash'] === null) {
        $sRow['expected_cash'] = $m['expected_cash'];
    }
}
unset($sRow);

// CSV Export
if ($exportCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=kenjis_shifts_' . $fromDate . '_to_' . $toDate . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Shift ID', 'Terminal', 'Cashier', 'Username', 'Role', 'Status', 'Opened At', 'Closed At', 'Opening Cash', 'Cash Sales', 'GCash Sales', 'Total Sales', 'Expected Cash', 'Closing Cash', 'Variance (Over/Short)', 'Voids Count', 'Voids Total', 'Remarks']);
    foreach ($shifts as $s) {
        fputcsv($out, [
            $s['id'],
            $s['terminal'],
            $s['full_name'],
            $s['username'],
            $s['role_name'],
            $s['status'],
            $s['opened_at'],
            $s['closed_at'] ?? 'Still Open',
            $s['opening_cash'],
            $s['calculated_cash'],
            $s['calculated_gcash'],
            $s['calculated_total'],
            $s['expected_cash'] ?? ($s['opening_cash'] + $s['calculated_cash']),
            $s['closing_cash'] ?? '-',
            $s['variance'] ?? '-',
            $s['void_count'],
            $s['void_total'],
            $s['remarks'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Cashier performance summary across selected period
$perfStmt = $conn->prepare("
    SELECT u.id, u.full_name, u.username,
           COUNT(DISTINCT cs.id) as total_shifts,
           COALESCE((
               SELECT SUM(o.total)
               FROM orders o
               JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
               JOIN cashier_shifts cs_s ON (o.shift_id = cs_s.id OR (o.shift_id IS NULL AND p.shift_id = cs_s.id))
               WHERE cs_s.user_id = u.id
                 AND DATE(cs_s.opened_at) >= ? AND DATE(cs_s.opened_at) <= ?
                 AND p.payment_status = 'Paid'
                 AND o.paid_at IS NOT NULL
                 AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded')
           ), 0) as total_sales,
           COALESCE((
               SELECT COUNT(DISTINCT o.id)
               FROM orders o
               JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
               JOIN cashier_shifts cs_o ON (o.shift_id = cs_o.id OR (o.shift_id IS NULL AND p.shift_id = cs_o.id))
               WHERE cs_o.user_id = u.id
                 AND DATE(cs_o.opened_at) >= ? AND DATE(cs_o.opened_at) <= ?
                 AND p.payment_status = 'Paid'
                 AND o.paid_at IS NOT NULL
                 AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded')
           ), 0) as total_orders,
           COALESCE(SUM(cs.variance), 0) as net_variance,
           COALESCE((SELECT COUNT(*) FROM void_logs vl JOIN cashier_shifts cs2 ON cs2.id = vl.shift_id WHERE cs2.user_id = u.id AND DATE(cs2.opened_at) >= ? AND DATE(cs2.opened_at) <= ?), 0) as total_voids,
           COALESCE((SELECT SUM(vl.price * vl.quantity) FROM void_logs vl JOIN cashier_shifts cs2 ON cs2.id = vl.shift_id WHERE cs2.user_id = u.id AND DATE(cs2.opened_at) >= ? AND DATE(cs2.opened_at) <= ?), 0) as total_void_amount
    FROM users u
    JOIN cashier_shifts cs ON cs.user_id = u.id
    WHERE DATE(cs.opened_at) >= ? AND DATE(cs.opened_at) <= ?
    GROUP BY u.id
    ORDER BY total_sales DESC
");
$perfStmt->execute([$fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate]);
$performances = $perfStmt->fetchAll();

// Cashiers list for dropdown
$cashierList = $conn->query("
    SELECT u.id, u.full_name, u.username 
    FROM users u 
    JOIN roles r ON r.id = u.role_id 
    WHERE r.role_name IN ('Cashier', 'Admin')
    ORDER BY u.full_name ASC
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>

<style>
@media print {
    .sidebar, .topbar, .no-print, .btn, form { display: none !important; }
    .content, .main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { background: #fff !important; color: #000 !important; }
}
</style>

<!-- Centralized Reports Hub Navigation Sub-Tabs -->
<div class="tabs no-print" style="margin-bottom: 24px; display: flex; gap: 8px; flex-wrap: wrap;">
    <a href="index.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-cash-stack"></i> Sales Report
    </a>
    <a href="delivery_report.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-truck"></i> Delivery Reports
    </a>
    <a href="pending_cod_cash.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-exclamation-triangle"></i> Pending COD Cash
    </a>
    <a href="shifts.php" class="tab active" style="background: var(--primary, #F2C12E); color: var(--dark, #232323); font-weight: 800; border-color: var(--primary); text-decoration: none;">
        <i class="bi bi-clock-history"></i> Shift Reports
    </a>
    <a href="sales_performance.php" class="tab" style="text-decoration: none;">
        <i class="bi bi-graph-up"></i> Sales Performance
    </a>
</div>

<div class="no-print" style="margin-bottom: 20px;">
    <div style="background: #FFFBE6; border-left: 4px solid #F2C12E; padding: 12px 18px; border-radius: 6px; color: #111; font-size: 0.9rem; line-height: 1.5;">
        <strong><i class="bi bi-clock-history"></i> Shift Accountability & Reconciliation:</strong>
        Tracks exact cash and GCash processed per cashier shift, handover variances (over/short), and supervisor-approved voids. Printable and exportable to CSV.
    </div>
</div>

<!-- Filter Controls -->
<div class="card no-print" style="margin-bottom: 20px;">
    <form method="get" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
        <div>
            <label style="display:block; font-size:0.82rem; font-weight:700; margin-bottom:6px; color:var(--dark);">From Date</label>
            <input type="date" name="from_date" value="<?= e($fromDate) ?>" class="search-input" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--text);">
        </div>
        <div>
            <label style="display:block; font-size:0.82rem; font-weight:700; margin-bottom:6px; color:var(--dark);">To Date</label>
            <input type="date" name="to_date" value="<?= e($toDate) ?>" class="search-input" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--text);">
        </div>
        <div>
            <label style="display:block; font-size:0.82rem; font-weight:700; margin-bottom:6px; color:var(--dark);">Cashier</label>
            <select name="cashier_id" class="search-input" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--text);">
                <option value="0">All Staff Cashiers</option>
                <?php foreach ($cashierList as $c): ?>
                    <option value="<?= e($c['id']) ?>" <?= ($filterCashier === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= e($c['full_name']) ?> (@<?= e($c['username']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="padding:9px 18px;"><i class="bi bi-funnel"></i> Apply Filter</button>
        <a href="shifts.php" class="btn btn-secondary" style="padding:9px 14px;">Reset</a>
        <div style="margin-left:auto; display:flex; gap:8px;">
            <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            <a href="shifts.php?from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&cashier_id=<?= (int)$filterCashier ?>&export=csv" class="btn btn-primary" style="background:#059669; border-color:#059669;">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
            </a>
        </div>
    </form>
</div>

<!-- Cashier Performance Summary -->
<div class="card" style="margin-bottom: 24px;">
    <h2>Cashier Performance Summary</h2>
    <p class="muted" style="font-size:0.85rem; margin-bottom:14px;">Performance, average order value, void frequency, and cash variance between <?= date('M j, Y', strtotime($fromDate)) ?> and <?= date('M j, Y', strtotime($toDate)) ?>.</p>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Cashier</th>
                    <th>Shifts</th>
                    <th>Orders Handled</th>
                    <th>Total Sales</th>
                    <th>Avg Order Value (AOV)</th>
                    <th>Voids & Refunds</th>
                    <th>Net Cash Variance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($performances)): ?>
                    <tr><td colspan="7" class="muted text-center" style="padding:16px;">No cashier activity recorded during this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($performances as $p): 
                        $ordersCount = (int)$p['total_orders'];
                        $salesTotal = (float)$p['total_sales'];
                        $aov = $ordersCount > 0 ? ($salesTotal / $ordersCount) : 0;
                        $var = (float)$p['net_variance'];
                    ?>
                        <tr>
                            <td>
                                <strong><?= e($p['full_name']) ?></strong><br>
                                <small class="muted">@<?= e($p['username']) ?></small>
                            </td>
                            <td><?= (int)$p['total_shifts'] ?> shifts</td>
                            <td><strong><?= $ordersCount ?></strong></td>
                            <td><strong style="color:var(--brand, #F2C12E);">₱<?= number_format($salesTotal, 2) ?></strong></td>
                            <td>₱<?= number_format($aov, 2) ?></td>
                            <td>
                                <?= (int)$p['total_voids'] ?> voids<br>
                                <small class="muted">₱<?= number_format((float)$p['total_void_amount'], 2) ?></small>
                            </td>
                            <td>
                                <?php if (abs($var) < 0.01): ?>
                                    <span class="badge" style="background:#d1fae5; color:#065f46;">₱0.00 Exact</span>
                                <?php elseif ($var > 0): ?>
                                    <span class="badge" style="background:#e0f2fe; color:#0369a1;">+₱<?= number_format($var, 2) ?> Over</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#fee2e2; color:#991b1b;">-₱<?= number_format(abs($var), 2) ?> Short</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Detailed Shift Handovers Log -->
<div class="card">
    <h2>Detailed Shift Log (<?= count($shifts) ?>)</h2>
    <p class="muted" style="font-size:0.85rem; margin-bottom:14px;">Complete timeline of terminal openings, closings, drawer counts, and handover remarks.</p>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Shift #</th>
                    <th>Cashier & Terminal</th>
                    <th>Timeline / Status</th>
                    <th>Float Cash</th>
                    <th>Sales (Cash / GCash)</th>
                    <th>Drawer Reconciliation</th>
                    <th>Voids</th>
                    <th>Handover Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($shifts)): ?>
                    <tr><td colspan="8" class="muted text-center" style="padding:24px;">No shifts found for this selection.</td></tr>
                <?php else: ?>
                    <?php foreach ($shifts as $s): 
                        $open = new DateTime($s['opened_at']);
                        $close = $s['closed_at'] ? new DateTime($s['closed_at']) : null;
                        $duration = $close ? $open->diff($close)->format('%hh %im') : 'Active';
                        $variance = $s['variance'] !== null ? (float)$s['variance'] : null;
                    ?>
                        <tr>
                            <td>
                                <strong>#<?= (int)$s['id'] ?></strong><br>
                                <?php if ($s['status'] === 'Open'): ?>
                                    <span class="badge" style="background:#d1fae5; color:#065f46;">Open</span>
                                <?php elseif (!empty($s['auto_closed'])): ?>
                                    <span class="badge" style="background:#fef3c7; color:#92400e;">Auto-Closed</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--soft); color:var(--text);">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e($s['full_name']) ?></strong><br>
                                <small class="muted"><i class="bi bi-pc-display"></i> <?= e($s['terminal']) ?></small>
                            </td>
                            <td style="font-size:0.82rem;">
                                <?= $open->format('M j, g:i A') ?><br>
                                <?= $close ? '➔ ' . $close->format('g:i A') . " ({$duration})" : '<span style="color:#10b981;">Currently On Duty</span>' ?>
                            </td>
                            <td>₱<?= number_format((float)$s['opening_cash'], 2) ?></td>
                            <td>
                                <strong>₱<?= number_format((float)$s['calculated_total'], 2) ?></strong> (<?= (int)$s['calculated_orders'] ?> ord)<br>
                                <small class="muted">Cash: ₱<?= number_format((float)$s['calculated_cash'], 2) ?> | GCash: ₱<?= number_format((float)$s['calculated_gcash'], 2) ?></small>
                            </td>
                            <td>
                                <?php if ($s['status'] === 'Open'): ?>
                                    <span class="muted" style="font-size:0.82rem;">In Progress...</span>
                                <?php else: ?>
                                    <span style="font-size:0.82rem;">Expected: ₱<?= number_format((float)$s['expected_cash'], 2) ?></span><br>
                                    <span style="font-size:0.82rem;">Counted: <strong>₱<?= number_format((float)$s['closing_cash'], 2) ?></strong></span><br>
                                    <?php if ($variance !== null): ?>
                                        <?php if (abs($variance) < 0.01): ?>
                                            <span style="color:#059669; font-weight:700; font-size:0.75rem;">Exact Match</span>
                                        <?php elseif ($variance > 0): ?>
                                            <span style="color:#0284c7; font-weight:700; font-size:0.75rem;">+₱<?= number_format($variance, 2) ?> Over</span>
                                        <?php else: ?>
                                            <span style="color:#dc2626; font-weight:700; font-size:0.75rem;">-₱<?= number_format(abs($variance), 2) ?> Short</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (int)$s['void_count'] ?> voids<br>
                                <small class="muted">₱<?= number_format((float)$s['void_total'], 2) ?></small>
                            </td>
                            <td style="font-size:0.82rem; max-width:200px; word-break:break-word;">
                                <?= e($s['remarks'] ?: '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
