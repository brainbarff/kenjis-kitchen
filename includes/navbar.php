<header class="topbar">
    <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar"><i class="bi bi-list"></i></button>
    <div>
        <h1><?= e($heading ?? $title ?? APP_NAME) ?></h1>
        <p><?= date('l, F j, Y') ?> <span id="clock"></span></p>
    </div>
    <div class="top-actions">
        <label class="search">
            <i class="bi bi-search"></i>
            <input type="search" id="globalSearch" placeholder="Search">
        </label>
        <button class="icon-btn"><i class="bi bi-bell"></i></button>
        <div class="account-menu" id="accountMenu">
            <button class="account-menu-toggle" id="accountMenuToggle" type="button" aria-label="Open account menu" aria-haspopup="true" aria-expanded="false" aria-controls="accountMenuPanel">
                <span class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
            </button>
            <div class="account-menu-panel" id="accountMenuPanel" hidden>
                <div class="account-menu-user">
                    <strong><?= e($user['username']) ?></strong>
                    <small><?= e(ucfirst($user['role'])) ?></small>
                </div>
                <a class="account-menu-logout" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-left"></i> Log out</a>
            </div>
        </div>
    </div>
</header>
