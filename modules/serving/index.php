<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier', 'server']);

$page = 'serving';
$title = 'Order Serving';
$heading = 'Order Serving';

include ROOT_PATH . '/includes/header.php';
?>
<section class="serve-toolbar">
    <div>
        <h2>Ready Orders</h2>
        <p class="muted">Orders marked ready by the kitchen appear here automatically.</p>
    </div>
    <div class="serve-tools">
        <a class="btn btn-secondary" href="display.php" target="_blank"><i class="bi bi-tv"></i>Now Serving Display</a>
        <button class="btn btn-secondary" id="refreshServing"><i class="bi bi-arrow-clockwise"></i>Refresh</button>
    </div>
</section>

<section class="grid grid-3">
    <article class="card stat"><div><span class="muted">Ready Orders</span><strong id="readyCount">0</strong></div><i class="bi bi-bell"></i></article>
    <article class="card stat"><div><span class="muted">Dine-In</span><strong id="dineInCount">0</strong></div><i class="bi bi-table"></i></article>
    <article class="card stat"><div><span class="muted">Pickup</span><strong id="pickupCount">0</strong></div><i class="bi bi-bag-check"></i></article>
</section>

<div class="alert alert-danger" id="serveError" hidden></div>

<section class="serving-grid" id="servingGrid">
    <article class="card empty-state">
        <span class="loader"></span>
        <h2>Loading ready orders...</h2>
        <p class="muted">Please wait while the system checks the kitchen queue.</p>
    </article>
</section>

<div class="modal-backdrop" id="slipModal" style="display: none;" hidden>
    <section class="modal-card slip-preview">
        <div class="modal-head no-print">
            <h2>Order Slip</h2>
            <button class="icon-btn" id="closeSlip"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="printArea"></div>
        <div class="serve-actions no-print">
            <button class="btn btn-primary" id="printSlip"><i class="bi bi-printer"></i>Print Slip</button>
            <button class="btn btn-secondary" id="cancelSlip">Close</button>
        </div>
    </section>
</div>

<?php $script = 'serving.js'; include ROOT_PATH . '/includes/footer.php'; ?>