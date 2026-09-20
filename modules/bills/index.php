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
        <button type="button" class="btn btn-secondary" onclick="printReport()"><i class="bi bi-printer"></i>Print Report</button>
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

<div id="printTemplate" style="display:none;">
    <div class="header">
        <h1>Kenji's Kitchen</h1>
        <p>Bills, Financials &amp; Best Sellers Report &bull; <?= date('F d, Y h:i A') ?></p>
    </div>
    <table class="stats-table">
        <tr>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Total Sales</div>
                    <div class="stat-value"><?= money($sales['total']) ?></div>
                </div>
            </td>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Paid Bills</div>
                    <div class="stat-value"><?= e($paid['total']) ?></div>
                </div>
            </td>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Active Bills</div>
                    <div class="stat-value"><?= e($pending['total']) ?></div>
                </div>
            </td>
        </tr>
    </table>
    <div class="section-title">Best Sellers</div>
    <div class="section-subtitle">Top items by quantity sold.</div>
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Menu Item</th>
                    <th>Sold Qty</th>
                    <th>Movement</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($best as $row): ?>
                    <tr>
                        <td><?= e($row['item_name']) ?></td>
                        <td><?= e($row['qty']) ?></td>
                        <td><span class="badge">Best-Seller</span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$best): ?>
                    <tr>
                        <td colspan="3" style="text-align:center; color:#94a3b8; padding:20px;">No sales yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function printReport() {
    const reportHtml = document.getElementById('printTemplate').innerHTML;
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    
    printWindow.document.write('<!DOCTYPE html>' +
        '<html>' +
        '<head>' +
        '<meta charset="utf-8">' +
        '<title>Bills and Financials Report - Kenji\'s Kitchen</title>' +
        '<style>' +
        '@page { size: portrait; margin: 12mm 15mm; }' +
        '* { box-sizing: border-box; margin: 0; padding: 0; }' +
        'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #1e293b; background: #ffffff; padding: 12px; width: 100%; }' +
        '.header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 20px; }' +
        '.header h1 { font-size: 24px; color: #0f172a; font-weight: 700; }' +
        '.header p { font-size: 13px; color: #64748b; margin-top: 4px; }' +
        '.stats-table { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 0 -6px 24px -6px; }' +
        '.stats-table td { width: 33.33%; vertical-align: top; padding: 0; border: none; }' +
        '.stat-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 18px; background: #f8fafc; }' +
        '.stat-label { font-size: 13px; color: #64748b; font-weight: 500; margin-bottom: 6px; }' +
        '.stat-value { font-size: 22px; font-weight: 700; color: #0f172a; }' +
        '.section-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }' +
        '.section-subtitle { font-size: 13px; color: #64748b; margin-bottom: 14px; }' +
        '.table-card { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }' +
        'table { width: 100%; border-collapse: collapse; text-align: left; }' +
        'th { background: #f8fafc; padding: 12px 16px; font-size: 13px; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; }' +
        'td { padding: 12px 16px; font-size: 14px; color: #1e293b; border-bottom: 1px solid #f1f5f9; }' +
        '.table-card tr:last-child td { border-bottom: none; }' +
        '.badge { display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #15803d; background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 9999px; }' +
        '</style>' +
        '</head>' +
        '<body>' + reportHtml + '</body>' +
        '</html>'
    );
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() {
        printWindow.print();
        printWindow.close();
    }, 300);
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>