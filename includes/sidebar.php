<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= BASE_URL . role_home() ?>">
        <img src="<?= BASE_URL ?>/assets/img/logo.jpg" alt="Kenji's Kitchen Logo" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover;">
        <span>
            <strong>Kenji's Kitchen</strong>
            <small>Restaurant POS</small>
        </span>
    </a>

    <nav class="nav">
        <?php if (can_access('admin')): ?>
            <a class="<?= active_link('dashboard') ?>" href="<?= BASE_URL ?>/pages/dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
            <a class="<?= active_link('compatibility') ?>" href="<?= BASE_URL ?>/modules/compatibility/index.php"><i class="bi bi-phone"></i>Devices</a>
            <a class="<?= active_link('users') ?>" href="<?= BASE_URL ?>/modules/users/index.php"><i class="bi bi-people"></i>Users</a>
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

        <?php if (can_access(['admin', 'cashier', 'server'])): ?>
            <a class="<?= active_link('serving') ?>" href="<?= BASE_URL ?>/modules/serving/index.php"><i class="bi bi-bell"></i>Serving</a>
        <?php endif; ?>

        <?php if (can_access(['admin', 'manager'])): ?>
            <a class="<?= active_link('bills') ?>" href="<?= BASE_URL ?>/modules/bills/index.php"><i class="bi bi-wallet2"></i>Bills & Reports</a>
            <a class="<?= active_link('approvals') ?>" href="<?= BASE_URL ?>/modules/approvals/index.php"><i class="bi bi-shield-check"></i>Approvals</a>
        <?php endif; ?>
    </nav>

    <div class="side-user">
        <span class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
        <div>
            <strong><?= e($user['username']) ?></strong>
            <small><?= e($user['role']) ?></small>
        </div>
    </div>
    <a class="logout" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
</aside>