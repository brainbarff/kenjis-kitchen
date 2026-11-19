<header class="topbar">
    <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar"><i class="bi bi-list"></i></button>
    <div>
        <h1><?= e($heading ?? $title ?? APP_NAME) ?></h1>
        <p><?= date('l, F j, Y') ?> <span id="clock"></span></p>
    </div>
    <div class="top-actions">
        <?php
        $currentModuleKey = (string)($GLOBALS['page'] ?? 'dashboard');
        $initialModNotifs = isset($conn) && function_exists('kk_module_notifications')
            ? kk_module_notifications($conn, $currentModuleKey, (int)($user['id'] ?? 0))
            : ['module' => $currentModuleKey, 'label' => 'Notifications', 'count' => 0, 'items' => []];
        $notifCount = (int)($initialModNotifs['count'] ?? 0);

        $posCashier = $_SESSION['pos_active_cashier'] ?? null;
        $displayUsername = $posCashier ? $posCashier['username'] : $user['username'];
        $displayRole = $posCashier ? 'Active POS Cashier' : ucwords(str_replace('_', ' ', $user['role']));
        $showAccountRole = $posCashier || strcasecmp(trim($displayUsername), trim($displayRole)) !== 0;
        $accountLabel = $showAccountRole ? $displayUsername : $displayRole;
        $avatarLetter = strtoupper(substr($displayUsername, 0, 1));
        ?>
        <div class="kk-notif-bell-wrap" id="kkHeaderNotifWrap" data-module="<?= e($currentModuleKey) ?>" data-counts-url="<?= e(BASE_URL . '/api/sidebar_counts.php') ?>">
            <button type="button" class="kk-notif-bell-btn" id="kkHeaderNotifBtn" aria-label="Module notifications" aria-expanded="false" aria-controls="kkHeaderNotifMenu">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="kk-notif-bell-count" id="kkHeaderNotifCount"<?= $notifCount > 0 ? '' : ' hidden' ?>><?= $notifCount > 99 ? '99+' : $notifCount ?></span>
            </button>
            <div class="kk-notif-dropdown" id="kkHeaderNotifMenu" role="menu" hidden>
                <div class="kk-notif-dropdown-head">
                    <strong id="kkHeaderNotifTitle"><?= e($initialModNotifs['label']) ?></strong>
                    <span class="kk-notif-dropdown-pill" id="kkHeaderNotifPill"><?= $notifCount ?> pending</span>
                </div>
                <div class="kk-notif-dropdown-list" id="kkHeaderNotifList">
                    <?php if (empty($initialModNotifs['items'])): ?>
                        <div class="kk-notif-empty">You’re all caught up. No pending notifications here.</div>
                    <?php else: ?>
                        <?php foreach ($initialModNotifs['items'] as $nItem): ?>
                            <a class="kk-notif-item" href="<?= e($nItem['url']) ?>" role="menuitem">
                                <div class="kk-notif-item-top">
                                    <strong><?= e($nItem['title']) ?></strong>
                                    <span class="kk-notif-tag"><?= e($nItem['tag']) ?></span>
                                </div>
                                <div class="kk-notif-item-detail"><?= e($nItem['detail']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="header-account<?= $posCashier ? ' header-account-cashier' : '' ?>">
            <span class="avatar" aria-hidden="true"><?= e($avatarLetter) ?></span>
            <div class="header-account-details">
                <strong title="<?= e($displayUsername) ?>"><?= e($accountLabel) ?></strong>
                <?php if ($showAccountRole): ?><small><?= e($displayRole) ?></small><?php endif; ?>
            </div>
        </div>
    </div>
</header>
<style>
.top-actions { display:flex; align-items:center; gap:12px; }
.kk-notif-bell-wrap { position:relative; }
.kk-notif-bell-btn { position:relative; width:40px; height:40px; border-radius:999px; border:1px solid #e6dfd0; background:#fffaf0; color:#29251f; display:inline-flex; align-items:center; justify-content:center; font-size:17px; cursor:pointer; transition:background .15s, border-color .15s; }
.kk-notif-bell-btn:hover { background:#fff3c4; border-color:#d6a81f; }
.kk-notif-bell-btn:focus-visible { outline:2px solid #b48b12; outline-offset:2px; }
.kk-notif-bell-count[hidden], .kk-notif-dropdown[hidden] { display:none!important; }
.kk-notif-bell-count { position:absolute; top:-4px; right:-4px; min-width:19px; height:19px; padding:0 5px; border-radius:999px; background:#f2c12e; color:#1f2328; border:2px solid #ffffff; font-size:11px; font-weight:800; line-height:15px; text-align:center; }
.kk-notif-dropdown { position:absolute; top:calc(100% + 8px); right:0; width:min(340px, calc(100vw - 24px)); background:#fffdf8; border:1px solid #e6dfd0; border-top:3px solid #f2c12e; border-radius:14px; box-shadow:0 16px 38px rgba(0,0,0,.16); z-index:10050; overflow:hidden; }
.kk-notif-dropdown-head { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:12px 14px; background:#ffffff; border-bottom:1px solid #eeeae0; font-size:13.5px; color:#1f2328; }
.kk-notif-dropdown-pill { padding:2px 9px; border-radius:999px; background:#fff3c4; color:#775600; font-size:11.5px; font-weight:800; }
.kk-notif-dropdown-list { max-height:340px; overflow-y:auto; padding:6px; }
.kk-notif-item { display:block; padding:9px 11px; border-radius:10px; text-decoration:none; color:#29251f; transition:background .12s; }
.kk-notif-item:hover { background:#fff8e1; }
.kk-notif-item-top { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:13px; }
.kk-notif-tag { flex-shrink:0; padding:2px 8px; border-radius:999px; background:#fff3c4; color:#775600; font-size:11px; font-weight:700; }
.kk-notif-item-detail { margin-top:3px; font-size:12px; color:#5e5a4c; line-height:1.4; }
.kk-notif-empty { padding:20px 14px; text-align:center; font-size:12.5px; color:#655c4c; }
</style>
