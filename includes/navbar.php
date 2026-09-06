<header class="topbar">
    <button class="icon-btn menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
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
        <span class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
    </div>
</header>
