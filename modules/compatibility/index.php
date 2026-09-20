<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_role('admin');

$page = 'compatibility';
$title = 'Device Compatibility';
$heading = 'Cross-Platform Compatibility';

include ROOT_PATH . '/includes/header.php';
?>
<section class="grid grid-4">
    <article class="card stat"><div><span class="muted">Android</span><strong>Ready</strong></div><i class="bi bi-phone"></i></article>
    <article class="card stat"><div><span class="muted">iOS</span><strong>Ready</strong></div><i class="bi bi-phone-flip"></i></article>
    <article class="card stat"><div><span class="muted">Windows</span><strong>Ready</strong></div><i class="bi bi-pc-display"></i></article>
    <article class="card stat"><div><span class="muted">macOS</span><strong>Ready</strong></div><i class="bi bi-laptop"></i></article>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Supported Gadgets</h2>
            <p class="muted">The system is designed as a responsive web app that can be opened on phones, tablets, POS terminals, PCs, and smart displays.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Device / Hardware</th><th>Connection</th><th>System Use</th><th>Status</th></tr></thead>
            <tbody>
                <tr><td>Thermal Receipt Printer</td><td>USB / Bluetooth / Wi-Fi</td><td>Receipt and order slip printing</td><td><span class="badge badge-warning">Planned Integration</span></td></tr>
                <tr><td>Wireless Barcode Reader</td><td>Bluetooth / USB</td><td>Inventory and product lookup</td><td><span class="badge badge-warning">Planned Integration</span></td></tr>
                <tr><td>KDS Smart Display</td><td>Wi-Fi Browser</td><td>Kitchen order queue</td><td><span class="badge badge-success">Web Ready</span></td></tr>
                <tr><td>Customer Mobile Phone</td><td>Browser</td><td>Online ordering and tracking</td><td><span class="badge badge-success">Responsive</span></td></tr>
            </tbody>
        </table>
    </div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
