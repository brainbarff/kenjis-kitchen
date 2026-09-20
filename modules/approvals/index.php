<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_role(['admin', 'manager']);

$page = 'approvals';
$title = 'Approvals and Monitoring';
$heading = 'Transaction Approvals & Monitoring';

include ROOT_PATH . '/includes/header.php';
?>
<section class="grid grid-3">
    <article class="card stat"><div><span class="muted">Void Requests</span><strong>0</strong></div><i class="bi bi-x-octagon"></i></article>
    <article class="card stat"><div><span class="muted">Refund Requests</span><strong>0</strong></div><i class="bi bi-arrow-counterclockwise"></i></article>
    <article class="card stat"><div><span class="muted">System Alerts</span><strong>0</strong></div><i class="bi bi-exclamation-triangle"></i></article>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Monitoring Checklist</h2>
            <p class="muted">This page is prepared for future approval workflows and system alert logs.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Workflow</th><th>Responsible Role</th><th>Status</th></tr></thead>
            <tbody>
                <tr><td>Void transaction authorization</td><td>Admin / Manager</td><td><span class="badge badge-warning">Prepared</span></td></tr>
                <tr><td>Refund approval</td><td>Admin / Manager</td><td><span class="badge badge-warning">Prepared</span></td></tr>
                <tr><td>Low inventory alert</td><td>Admin / Inventory Staff</td><td><span class="badge badge-success">Available</span></td></tr>
                <tr><td>Order status updates</td><td>Kitchen / Server</td><td><span class="badge badge-success">Available</span></td></tr>
            </tbody>
        </table>
    </div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
