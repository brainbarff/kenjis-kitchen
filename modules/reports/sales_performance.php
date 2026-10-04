<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'reports';
$title = 'Sales Performance';
$heading = 'Sales Performance';

$today = date('Y-m-d');
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? $today;

$fromObject = DateTime::createFromFormat('Y-m-d', $fromDate);
$toObject = DateTime::createFromFormat('Y-m-d', $toDate);

if (!$fromObject || $fromObject->format('Y-m-d') !== $fromDate) {
    $fromDate = date('Y-m-01');
    $fromObject = new DateTime($fromDate);
}

if (!$toObject || $toObject->format('Y-m-d') !== $toDate) {
    $toDate = $today;
    $toObject = new DateTime($toDate);
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$reportFrom = $fromDate . ' 00:00:00';
$reportTo = (new DateTime($toDate))->modify('+1 day')->format('Y-m-d 00:00:00');

$rowsPerPage = 10;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}

$latestPayment = "
    SELECT p1.order_id, p1.payment_method
    FROM payments p1
    INNER JOIN (
        SELECT order_id, MAX(id) AS max_id
        FROM payments
        GROUP BY order_id
    ) latest ON latest.max_id = p1.id
";

$totalsStmt = $conn->prepare("
    SELECT
        COUNT(o.id) AS total_orders,
        COALESCE(SUM(o.total), 0) AS total_sales
    FROM orders o
    INNER JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :totals_from
      AND o.paid_at < :totals_to
      AND o.status <> 'Cancelled'
");
$totalsStmt->bindValue(':totals_from', $reportFrom, PDO::PARAM_STR);
$totalsStmt->bindValue(':totals_to', $reportTo, PDO::PARAM_STR);
$totalsStmt->execute();
$totals = $totalsStmt->fetch();

$dailyStmt = $conn->prepare("
    SELECT
        DATE(o.paid_at) AS sales_date,
        COUNT(o.id) AS total_orders,
        COALESCE(SUM(o.total), 0) AS total_sales,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(p.payment_method)) = 'cash' THEN o.total ELSE 0 END), 0) AS cash_sales,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(p.payment_method)) = 'gcash' THEN o.total ELSE 0 END), 0) AS gcash_sales
    FROM orders o
    INNER JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.paid_at IS NOT NULL
      AND o.paid_at >= :daily_from
      AND o.paid_at < :daily_to
      AND o.status <> 'Cancelled'
    GROUP BY DATE(o.paid_at)
    ORDER BY sales_date DESC
");
$dailyStmt->bindValue(':daily_from', $reportFrom, PDO::PARAM_STR);
$dailyStmt->bindValue(':daily_to', $reportTo, PDO::PARAM_STR);
$dailyStmt->execute();
$dailyResult = $dailyStmt->fetchAll();

$dailyByDate = [];
foreach ($dailyResult as $row) {
    $dailyByDate[$row['sales_date']] = $row;
}

$calendarRows = [];
$calendarDate = new DateTime($fromDate);
$calendarEnd = new DateTime($toDate);

while ($calendarDate <= $calendarEnd) {
    $salesDate = $calendarDate->format('Y-m-d');
    $calendarRows[] = $dailyByDate[$salesDate] ?? [
        'sales_date' => $salesDate,
        'total_orders' => 0,
        'total_sales' => 0,
        'cash_sales' => 0,
        'gcash_sales' => 0,
    ];
    $calendarDate->modify('+1 day');
}

$calendarRows = array_reverse($calendarRows);
$totalDays = count($calendarRows);
$totalPages = max(1, (int) ceil($totalDays / $rowsPerPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $rowsPerPage;
$dailyRows = array_slice($calendarRows, $offset, $rowsPerPage);
$printRows = $calendarRows;

include ROOT_PATH . '/includes/header.php';
?>

<section class="page-head">
    <div>
        <h2>Sales Performance</h2>
        <p class="muted">Daily sales performance for the selected date range.</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary" href="index.php?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>">
            <i class="bi bi-receipt"></i>Daily Sales Report
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

<section class="grid grid-2" style="margin-bottom:24px;">
    <article class="card stat">
        <div>
            <span class="muted">Total Sales</span>
            <strong><?= money($totals['total_sales']) ?></strong>
        </div>
        <i class="bi bi-cash-stack"></i>
    </article>
    <article class="card stat">
        <div>
            <span class="muted">Total Orders</span>
            <strong><?= e($totals['total_orders']) ?></strong>
        </div>
        <i class="bi bi-receipt-cutoff"></i>
    </article>
</section>

<section class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:24px; margin-bottom:4px;">Sales Performance by Date</h2>
            <p class="muted">Sales and order count grouped by paid transaction date.</p>
        </div>

        <?php if ($totalDays > 0): ?>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $currentPage - 1 ?>" <?= $currentPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
                <strong style="white-space:nowrap;">Page <?= $currentPage ?> of <?= $totalPages ?></strong>
                <a class="btn btn-secondary" href="?from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>&page=<?= $currentPage + 1 ?>" <?= $currentPage >= $totalPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Orders</th>
                    <th>Total Sales</th>
                    <th>Cash Sales</th>
                    <th>GCash Sales</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyRows as $row): ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($row['sales_date'])) ?></td>
                        <td><?= e($row['total_orders']) ?></td>
                        <td><?= money($row['total_sales']) ?></td>
                        <td><?= money($row['cash_sales']) ?></td>
                        <td><?= money($row['gcash_sales']) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$dailyRows): ?>
                    <tr>
                        <td colspan="5" class="muted" style="text-align:center; padding:28px;">No sales found for the selected date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="printArea">
    <div class="print-header">
        <h1>Kenji's Kitchen</h1>
        <h2>Sales Performance</h2>
        <p>Period: <?= e(date('M d, Y', strtotime($fromDate))) ?> to <?= e(date('M d, Y', strtotime($toDate))) ?></p>
    </div>

    <table class="print-table">
        <colgroup>
            <col style="width:22%;">
            <col style="width:14%;">
            <col style="width:22%;">
            <col style="width:21%;">
            <col style="width:21%;">
        </colgroup>
        <thead>
            <tr>
                <th>Date</th>
                <th>Orders</th>
                <th>Total Sales</th>
                <th>Cash Sales</th>
                <th>GCash Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printRows as $row): ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($row['sales_date'])) ?></td>
                    <td><?= e($row['total_orders']) ?></td>
                    <td><?= money($row['total_sales']) ?></td>
                    <td><?= money($row['cash_sales']) ?></td>
                    <td><?= money($row['gcash_sales']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$printRows): ?>
                <tr>
                    <td colspan="5">No sales found for the selected date range.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
#printArea {
    display: none;
}

@media print {
    @page {
        size: A4 landscape;
        margin: 12mm;
    }

    body * {
        visibility: hidden !important;
    }

    #printArea,
    #printArea * {
        visibility: visible !important;
    }

    #printArea {
        display: block !important;
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0;
        overflow: visible !important;
        color: #000;
        background: #fff;
        box-sizing: border-box;
        font-family: Arial, sans-serif;
        font-size: 10pt;
    }

    .print-header {
        text-align: center;
        margin-bottom: 18px;
    }

    .print-header h1 {
        margin: 0 0 4px;
        font-size: 20pt;
    }

    .print-header h2 {
        margin: 0 0 4px;
        font-size: 14pt;
    }

    .print-header p {
        margin: 0;
        font-size: 10pt;
    }

    .print-table {
        width: 100%;
        max-width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .print-table thead {
        display: table-header-group;
    }

    .print-table th,
    .print-table td {
        padding: 7px 8px;
        border-bottom: 1px solid #ccc;
        font-size: 9.5pt;
        line-height: 1.25;
        white-space: nowrap;
        overflow: visible;
        text-overflow: clip;
    }

    .print-table th {
        border-bottom: 1.5px solid #000;
        text-align: left;
    }

    .print-table th:not(:first-child),
    .print-table td:not(:first-child) {
        text-align: right;
    }

    .print-table tr {
        page-break-inside: avoid;
    }
}
</style>

<script>
function printReport() {
    window.print();
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
