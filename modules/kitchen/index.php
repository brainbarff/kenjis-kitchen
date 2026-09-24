<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'kitchen']);

$page = 'kitchen';
$title = 'Kitchen Display System';
$heading = 'Kitchen Display System';

include ROOT_PATH . '/includes/header.php';
?>
<section class="kds-top">
    <article class="card stat"><div><span class="muted">Active</span><strong id="activeCount">0</strong></div><i class="bi bi-fire"></i></article>
    <article class="card stat"><div><span class="muted">Preparing</span><strong id="preparingCount">0</strong></div><i class="bi bi-hourglass-split"></i></article>
    <article class="card stat"><div><span class="muted">Ready</span><strong id="readyCount">0</strong></div><i class="bi bi-check2-circle"></i></article>
</section>

<section class="kds-grid" id="kdsGrid"></section>
<?php $script = 'kitchen.js?v=' . time(); include ROOT_PATH . '/includes/footer.php'; ?>