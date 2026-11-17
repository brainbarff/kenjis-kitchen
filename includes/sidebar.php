<aside class="sidebar" id="sidebar" aria-label="Main Navigation">
    <div class="sidebar-header">
        <a class="brand" href="<?= BASE_URL . role_home() ?>" style="flex: 1; min-width: 0;">
            <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="Kenji's Kitchen Logo" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; flex-shrink: 0;">
            <span style="min-width: 0; overflow: hidden;">
                <strong>Kenji's Kitchen</strong>
                <small>Restaurant POS</small>
            </span>
        </a>
        <button type="button" id="sidebarCloseBtn" class="icon-btn sidebar-close-btn" aria-label="Close sidebar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <nav class="nav">
        <?php if (can_access('admin')): ?>
            <a class="<?= active_link('dashboard') ?>" href="<?= BASE_URL ?>/pages/dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
            <a class="<?= active_link('users') ?>" href="<?= BASE_URL ?>/modules/users/index.php"><i class="bi bi-people"></i>Users</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'inventory'])): ?>
            <a class="<?= active_link('menu') ?>" href="<?= BASE_URL ?>/modules/menu/index.php"><i class="bi bi-card-list"></i>Menu</a>
            <a class="<?= active_link('inventory') ?>" href="<?= BASE_URL ?>/modules/inventory/index.php"><i class="bi bi-box-seam"></i>Inventory</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'cashier'])): ?>
            <?php
            $sidebarPendingQr = 0;
            $sidebarPendingCod = 0;
            if (isset($conn)) {
                try {
                    require_once ROOT_PATH . '/includes/gcash_verification.php';
                    expire_overdue_gcash_orders($conn, (int)(current_user()['id'] ?? 0) ?: null);
                    $sidebarPendingQr = (int)$conn->query("
                        SELECT COUNT(*) 
                        FROM orders o 
                        JOIN payment_proofs pp ON pp.order_id = o.id AND pp.verification_status = 'pending' 
                        WHERE o.payment_verification_status = 'Awaiting Verification' AND o.status = 'Pending'
                    ")->fetchColumn();
                } catch (Throwable $t) {}
                try {
                    $sidebarPendingCod = (int)$conn->query("
                        SELECT COUNT(*) 
                        FROM orders 
                        WHERE order_channel = 'ONLINE' AND cod_approval_status = 'Pending' AND status = 'Pending'
                    ")->fetchColumn();
                } catch (Throwable $t) {}
            }
            ?>
            <a class="<?= active_link('pos') ?>" href="<?= BASE_URL ?>/modules/pos/index.php"><i class="bi bi-receipt"></i>POS</a>
            <a class="<?= active_link('payment_verification') ?>" href="<?= BASE_URL ?>/modules/pos/payment_verification.php">
                <i class="bi bi-qr-code-scan"></i>QR Verification
                <?php if ($sidebarPendingQr > 0): ?>
                    <span class="badge badge-warning" style="margin-left:auto; font-size:0.72rem; padding:2px 7px; border-radius:10px; background:#f59e0b; color:#111; font-weight:700;"><?= $sidebarPendingQr ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= active_link('cod_approvals') ?>" href="<?= BASE_URL ?>/modules/pos/cod_approvals.php">
                <i class="bi bi-cash-coin"></i>COD Approvals
                <?php if ($sidebarPendingCod > 0): ?>
                    <span class="badge badge-warning" style="margin-left:auto; font-size:0.72rem; padding:2px 7px; border-radius:10px; background:#f59e0b; color:#111; font-weight:700;"><?= $sidebarPendingCod ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= active_link('delivery') ?>" href="<?= BASE_URL ?>/modules/delivery/index.php"><i class="bi bi-truck"></i>Delivery Status</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'kitchen'])): ?>
            <a class="<?= active_link('kitchen') ?>" href="<?= BASE_URL ?>/modules/kitchen/index.php"><i class="bi bi-display"></i>Kitchen</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'cashier', 'kitchen'])): ?>
            <a class="<?= active_link('serving') ?>" href="<?= BASE_URL ?>/modules/serving/index.php"><i class="bi bi-bell"></i>Serving</a>
        <?php endif; ?>

        <?php if (can_access('admin')): ?>
            <?php
            $sidebarPendingApprovals = 0;
            if (isset($conn)) {
                try {
                    $sidebarPendingApprovals = (int)$conn->query("SELECT COUNT(*) FROM approval_requests WHERE status = 'Pending'")->fetchColumn();
                } catch (Throwable $t) {}
            }
            ?>
            <a class="<?= active_link('reports') ?>" href="<?= BASE_URL ?>/modules/reports/index.php"><i class="bi bi-file-earmark-bar-graph"></i>Reports Hub</a>
            <a class="<?= active_link('approvals') ?>" href="<?= BASE_URL ?>/modules/approvals/index.php">
                <i class="bi bi-shield-check"></i>Approvals
                <?php if ($sidebarPendingApprovals > 0): ?>
                    <span class="badge badge-danger" style="margin-left:auto; font-size:0.72rem; padding:2px 7px; border-radius:10px;"><?= $sidebarPendingApprovals ?></span>
                <?php endif; ?>
            </a>
            <a class="<?= active_link('payments') ?>" href="<?= BASE_URL ?>/modules/settings/payments.php"><i class="bi bi-gear"></i>Payment Settings</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'cashier'])): ?>
            <a href="<?= BASE_URL ?>/online-menu/" target="_blank" rel="noopener noreferrer" class="sidebar-storefront" aria-label="Online ordering, opens in a new tab"><i class="bi bi-shop" aria-hidden="true"></i><span>Online Ordering</span><i class="bi bi-box-arrow-up-right storefront-external" aria-hidden="true"></i></a>
        <?php endif; ?>
    </nav>

    <!-- Dedicated Bottom Log Out Button -->
    <div class="sidebar-bottom-action">
        <a href="<?= BASE_URL ?>/logout.php" class="sidebar-logout-btn">
            <i class="bi bi-box-arrow-left"></i>
            <span>Log Out</span>
        </a>
    </div>
</aside>

<style>
.sidebar-nav a.sidebar-storefront, nav a.sidebar-storefront { display:flex; align-items:center; gap:10px; margin-top:14px; color:#f7ce54; background:rgba(247,198,47,.07); border:1px solid rgba(247,198,47,.18); border-radius:10px; padding:12px; }
nav a.sidebar-storefront span { flex:1; min-width:0; }
nav a.sidebar-storefront .storefront-external { font-size:11px; width:auto; flex-shrink:0; margin:0; }
nav a.sidebar-storefront:hover { background:rgba(247,198,47,.15); color:#ffe49a; }
nav a.sidebar-storefront:focus-visible { outline:2px solid #f7c62f; outline-offset:2px; }
</style>
