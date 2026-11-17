<header class="topbar">
    <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar"><i class="bi bi-list"></i></button>
    <div>
        <h1><?= e($heading ?? $title ?? APP_NAME) ?></h1>
        <p><?= date('l, F j, Y') ?> <span id="clock"></span></p>
    </div>
    <div class="top-actions">
        <?php
        $posCashier = $_SESSION['pos_active_cashier'] ?? null;
        $displayUsername = $posCashier ? $posCashier['username'] : $user['username'];
        $displayRole = $posCashier ? 'Active POS Cashier' : ucwords(str_replace('_', ' ', $user['role']));
        $showAccountRole = $posCashier || strcasecmp(trim($displayUsername), trim($displayRole)) !== 0;
        $accountLabel = $showAccountRole ? $displayUsername : $displayRole;
        $avatarLetter = strtoupper(substr($displayUsername, 0, 1));
        ?>
        <div class="header-account<?= $posCashier ? ' header-account-cashier' : '' ?>">
            <span class="avatar" aria-hidden="true"><?= e($avatarLetter) ?></span>
            <div class="header-account-details">
                <strong title="<?= e($displayUsername) ?>"><?= e($accountLabel) ?></strong>
                <?php if ($showAccountRole): ?><small><?= e($displayRole) ?></small><?php endif; ?>
            </div>
        </div>
    </div>
</header>
