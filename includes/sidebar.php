<aside class="sidebar" id="sidebar" aria-label="Main Navigation">
    <div class="sidebar-header">
        <a class="brand" href="<?= BASE_URL . role_home() ?>" style="flex: 1; min-width: 0;">
            <img src="<?= BASE_URL ?>/assets/img/logo.jpg" alt="Kenji's Kitchen Logo" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; flex-shrink: 0;">
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
            <a class="<?= active_link('compatibility') ?>" href="<?= BASE_URL ?>/modules/compatibility/index.php"><i class="bi bi-phone"></i>Devices</a>
            <a class="<?= active_link('users') ?>" href="<?= BASE_URL ?>/modules/users/index.php"><i class="bi bi-people"></i>Users</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'inventory'])): ?>
            <a class="<?= active_link('menu') ?>" href="<?= BASE_URL ?>/modules/menu/index.php"><i class="bi bi-card-list"></i>Menu</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'inventory'])): ?>
            <a class="<?= active_link('inventory') ?>" href="<?= BASE_URL ?>/modules/inventory/index.php"><i class="bi bi-box-seam"></i>Inventory</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'cashier'])): ?>
            <a class="<?= active_link('pos') ?>" href="<?= BASE_URL ?>/modules/pos/index.php"><i class="bi bi-receipt"></i>POS</a>
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
            <a class="<?= active_link('reports') ?>" href="<?= BASE_URL ?>/modules/reports/index.php"><i class="bi bi-graph-up"></i>Reports</a>
            <a class="<?= active_link('payments') ?>" href="<?= BASE_URL ?>/modules/settings/payments.php"><i class="bi bi-qr-code"></i>Online Payments</a>
            <a class="<?= active_link('approvals') ?>" href="<?= BASE_URL ?>/modules/approvals/index.php">
                <i class="bi bi-shield-check"></i>Approvals
                <?php if ($sidebarPendingApprovals > 0): ?>
                    <span class="badge badge-danger" style="margin-left:auto; font-size:0.72rem; padding:2px 7px; border-radius:10px;"><?= $sidebarPendingApprovals ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'cashier'])): ?>
            <a href="<?= BASE_URL ?>/online/index.html" target="_blank" style="margin-top: 12px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 12px; color: #f59e0b;"><i class="bi bi-globe"></i>Online Ordering ↗</a>
        <?php endif; ?>
    </nav>

    <div class="side-user">
        <span class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
        <div>
            <strong><?= e($user['username']) ?></strong>
            <small><?= e($user['role']) ?></small>
            <a class="side-user-logout" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-left"></i> Log out</a>
        </div>
    </div>
</aside>
