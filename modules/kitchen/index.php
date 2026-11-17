<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'kitchen']);

$page = 'kitchen';
$title = 'Kitchen Display System';
$heading = 'Kitchen Display System';
$orderSounds = true;

include ROOT_PATH . '/includes/header.php';
?>

<style>
    .kitchen-page {
        display: flex;
        flex-direction: column;
        gap: 20px;
        min-height: calc(100vh - 120px);
    }

    /* Clean Light Theme for KDS aligned with Admin Portal */
    .kds-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        background: #ffffff;
        color: #1e1e1e;
        padding: 18px 24px;
        border-radius: 14px;
        border: 1px solid #eef0f2;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
    }

    .kds-header-brand {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .kds-header-brand img {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        object-fit: cover;
        border: 1px solid #e2e8f0;
    }

    .kds-header-brand h2 {
        margin: 0;
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e1e1e;
        letter-spacing: -0.3px;
    }

    .kds-header-brand p {
        margin: 2px 0 0;
        font-size: 0.82rem;
        color: #64748b;
    }

    .kds-header-actions {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .kds-active-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.82rem;
        font-weight: 700;
        color: #475569;
    }

    .kds-active-pill strong {
        color: #1e1e1e;
        font-size: 1.05rem;
    }

    .kds-refresh-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 10px;
        background: #1e1e1e;
        border: 1px solid #1e1e1e;
        color: #ffffff;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s ease, transform 0.15s ease;
    }

    .kds-refresh-btn:hover {
        background: #334155;
        transform: translateY(-1px);
    }

    /* 3 Main KDS Columns Layout */
    .kds-board {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 20px;
        align-items: start;
        flex: 1;
    }

    .kds-column {
        background: #f8fafc;
        border-radius: 16px;
        padding: 18px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        min-height: 600px;
        border: 1px solid #e2e8f0;
    }

    .kds-col-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }

    .kds-col-title {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .kds-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
    }

    .dot-pending { background: #ef4444; }
    .dot-preparing { background: #f59e0b; }
    .dot-ready { background: #10b981; }

    .kds-col-title h3 {
        margin: 0;
        font-size: 1.08rem;
        font-weight: 800;
        color: #1e1e1e;
        letter-spacing: -0.2px;
    }

    .kds-col-count {
        background: #ffffff;
        color: #1e1e1e;
        font-size: 0.82rem;
        font-weight: 800;
        padding: 3px 10px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
    }

    .kds-col-tickets {
        display: flex;
        flex-direction: column;
        gap: 14px;
        min-height: 200px;
    }

    /* Clean Card Ticket Design */
    .kds-ticket {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        display: flex;
        flex-direction: column;
        gap: 12px;
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .kds-ticket:hover {
        border-color: #cbd5e1;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        transform: translateY(-2px);
    }

    .kds-ticket.ticket-pending {
        border-left: 4px solid #ef4444;
    }

    .kds-ticket.ticket-preparing {
        border-left: 4px solid #f59e0b;
    }

    .kds-ticket.ticket-ready {
        border-left: 4px solid #10b981;
    }

    .kds-ticket-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    .kds-table-badge {
        font-size: 1rem;
        font-weight: 800;
        color: #1e1e1e;
        letter-spacing: -0.2px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .kds-timer-badge {
        background: #fee2e2;
        color: #b91c1c;
        font-size: 0.74rem;
        font-weight: 800;
        padding: 3px 8px;
        border-radius: 6px;
        letter-spacing: 0.3px;
    }

    .kds-ticket-id {
        font-size: 1.15rem;
        font-weight: 900;
        color: #d97706;
        margin-top: 3px;
        letter-spacing: 0.3px;
    }

    .kds-ticket-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 3px;
    }

    .kds-customer-name {
        color: #334155;
        font-weight: 700;
    }

    .kds-items-list {
        margin: 0;
        padding: 0;
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .kds-item-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 0.92rem;
        color: #1e1e1e;
        line-height: 1.35;
    }

    .kds-item-qty {
        font-weight: 800;
        color: #d97706;
        min-width: 24px;
    }

    .kds-item-name {
        font-weight: 600;
        flex: 1;
    }

    .kds-item-note {
        display: block;
        font-size: 0.74rem;
        color: #475569;
        background: #f1f5f9;
        padding: 3px 8px;
        border-radius: 4px;
        margin-top: 3px;
        border-left: 2px solid #F2C12E;
    }

    .kds-order-note {
        background: #fffbeb;
        border: 1px solid #fef3c7;
        border-radius: 8px;
        padding: 9px 12px;
        font-size: 0.78rem;
        color: #92400e;
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }

    .kds-order-note i {
        color: #f59e0b;
        margin-top: 1px;
    }

    .kds-ticket-btn {
        width: 100%;
        min-height: 42px;
        border: none;
        border-radius: 8px;
        font-size: 0.88rem;
        font-weight: 800;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.15s ease;
    }

    .kds-ticket-btn:active {
        transform: scale(0.98);
    }

    .btn-start-prep {
        background: #F2C12E;
        color: #111111;
        border: 1px solid #D8AF18;
    }

    .btn-start-prep:hover {
        background: #E8B717;
    }

    .btn-mark-ready {
        background: #10b981;
        color: #ffffff;
    }

    .btn-mark-ready:hover {
        background: #059669;
    }

    .btn-mark-served {
        background: #2563eb;
        color: #ffffff;
    }

    .btn-mark-served:hover {
        background: #1d4ed8;
    }

    .kds-empty-col {
        min-height: 240px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        text-align: center;
        padding: 20px;
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
    }

    .kds-empty-col i {
        font-size: 2rem;
        margin-bottom: 8px;
        color: #cbd5e1;
    }

    .kds-empty-col p {
        margin: 0;
        font-size: 0.85rem;
        font-weight: 600;
    }

    @media (max-width: 1100px) {
        .kds-board {
            grid-template-columns: 1fr;
        }
        .kds-column {
            min-height: auto;
        }
    }

    .kitchen-page { min-height:0; gap:22px; }
    .kds-header { border-color:#e9e5da; padding:22px 24px; border-radius:16px; flex-wrap:wrap; }
    .kds-header-brand { min-width:0; }
    .kds-header-brand h2 { font-size:22px; color:#242424; }
    .kds-header-brand p { color:#746e60; line-height:1.6; margin-top:5px; }
    .kds-header-actions { flex-wrap:wrap; }
    .kds-active-pill { background:#fff3be; border-color:#f0dfa1; color:#6d5104; border-radius:10px; }
    .kds-refresh-btn { min-height:40px; background:#242424; border-color:#242424; }
    .kds-board { align-items:stretch; gap:18px; }
    .kds-column { min-height:460px; padding:16px; background:#faf9f5; border-color:#e7e3d9; gap:18px; }
    .kds-col-header { border-color:#e7e3d9; padding-bottom:16px; gap:8px; }
    .kds-col-title { min-width:0; gap:10px; }
    .kds-col-title h3 { font-size:17px; }
    .kds-col-title small { display:block; font-size:12px; color:#746e60; line-height:1.5; margin-top:3px; }
    .kds-stage-icon { flex:0 0 38px; height:38px; display:grid; place-items:center; border-radius:10px; background:#fff0ba; color:#775600; font-size:19px; }
    #colPreparing .kds-stage-icon { background:#f7c62f; color:#242424; }
    #colReady .kds-stage-icon { background:#e4f3e9; color:#267044; }
    .kds-col-count { min-width:30px; text-align:center; border:0; background:#fff0ba; color:#614800; padding:6px 9px; border-radius:8px; }
    #colReady .kds-col-count { background:#e4f3e9; color:#267044; }
    .kds-empty-col { min-height:270px; background:#fff; border:1px dashed #e2dccd; color:#5e584b; padding:24px 16px; }
    .kds-empty-col i { width:54px; height:54px; display:grid; place-items:center; background:#fff4cc; color:#93701b; border-radius:15px; font-size:25px; margin-bottom:16px; }
    .kds-empty-col p { font-size:15px; font-weight:650; }
    .kds-empty-col small { max-width:240px; color:#817969; font-size:13px; line-height:1.6; margin-top:8px; }
    .kds-ticket { border-color:#e7e2d6; border-radius:12px; padding:16px; gap:14px; }
    .kds-ticket:hover { transform:none; border-color:#cfc5ae; }
    .kds-ticket.ticket-pending { border-left-color:#d9b433; }
    .kds-ticket.ticket-preparing { border-left-color:#df940a; }
    .kds-ticket.ticket-ready { border-left-color:#439565; }
    .kds-ticket-id { color:#725400; font-size:18px; overflow-wrap:anywhere; }
    .kds-ticket-meta { flex-wrap:wrap; color:#746e60; }
    .kds-ticket-top>div { min-width:0; }
    .kds-timer-badge { flex-shrink:0; }
    .kds-items-list { border-top:1px solid #eee9de; padding-top:12px; }
    .kds-item-row { line-height:1.5; }
    .kds-item-name { min-width:0; overflow-wrap:anywhere; }
    .kds-item-qty { color:#6c5000; background:#fff2bf; border-radius:5px; padding:1px 5px; text-align:center; }
    .kds-item-note { background:#faf7ed; color:#665e4b; }
    .kds-ticket-btn { min-height:44px; border-radius:9px; }
    .btn-start-prep { background:#f7c62f; border-color:#e3b627; }
    .btn-mark-ready { background:#242424; }
    .btn-mark-ready:hover { background:#444038; }
    .kitchen-page button:focus-visible { outline:3px solid #b58a10; outline-offset:3px; }
    @media(max-width:1100px) { .kds-column { min-height:0; } .kds-empty-col { min-height:200px; } }
    @media(max-width:600px) { .kds-header { padding:18px; } .kds-header-brand img { display:none; } .kds-header-actions { width:100%; justify-content:space-between; } }
/* Kitchen order tickets */
.kds-ticket{overflow:hidden;padding:0 16px 16px;border-left-width:1px;border-top:4px solid #e5ba27;box-shadow:0 3px 12px #30260008}.kds-ticket.ticket-preparing{border-top-color:#df940a}.kds-ticket.ticket-ready{border-top-color:#439565}.kds-ticket-strip{margin:0 -16px;padding:10px 16px;background:#fff8df;display:flex;justify-content:space-between;gap:10px;font-size:10px;font-weight:800;letter-spacing:1px;color:#756022}.ticket-preparing .kds-ticket-strip{background:#fff3df}.ticket-ready .kds-ticket-strip{background:#edf7ef;color:#28603c}.kds-ticket-strip span:last-child{letter-spacing:0;text-transform:none}.kds-table-badge{font-size:13px;color:#5d5546}.kds-ticket-id{font-size:20px;color:#29251d;line-height:1.3;margin:8px 0}.kds-ticket-meta{line-height:1.6}.kds-timer-badge{background:#f3f0e8;color:#5c5445;padding:6px 8px;white-space:nowrap}.kds-items-heading{display:flex;justify-content:space-between;border-top:1px dashed #d9d1bf;padding-top:14px;font-size:11px;text-transform:uppercase;letter-spacing:1px;font-weight:700;color:#7a705d}.kds-items-heading span{letter-spacing:0;text-transform:none}.kds-items-list{border-top:0;padding-top:0;gap:0}.kds-item-row{padding:10px 0;border-bottom:1px solid #f0ece2}.kds-item-row:last-child{border-bottom:0}.kds-item-qty{min-width:32px;min-height:30px;display:grid;place-items:center;font-size:15px;color:#322a15}.kds-item-name{font-weight:700}.kds-item-note{font-weight:400;padding:6px 8px;margin-top:6px}.kds-order-note{line-height:1.5;overflow-wrap:anywhere}.kds-order-note strong{display:block;margin-bottom:3px}.kds-ready-label{background:#edf7ef;color:#28603c;padding:12px;border-radius:9px;text-align:center;font-size:12px;font-weight:700}.kds-ticket-btn{min-height:46px}
</style>

<div class="kitchen-page">
    <header class="kds-header">
        <div class="kds-header-brand">
            <img src="<?= e(BASE_URL . '/assets/img/logo.png') ?>" alt="Kenji's Kitchen Logo">
            <div>
                <h2>Order preparation</h2>
                <p>Manage incoming orders from preparation to serving. · <span id="kdsClock">Live</span></p>
            </div>
        </div>

        <div class="kds-header-actions">
            <div class="kds-active-pill">
                Active Orders: <strong id="kdsTotalActive">0</strong>
            </div>
            <button type="button" class="kds-refresh-btn" onclick="loadOrders()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
    </header>

    <!-- 3 Main Columns: New Orders, Preparing, To Be Served (Ready) -->
    <main class="kds-board">
        <!-- Column 1: New Orders (Pending) -->
        <section class="kds-column" id="colPending">
            <div class="kds-col-header">
                <div class="kds-col-title">
                    <span class="kds-stage-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                    <div><h3>New Orders</h3><small>Review and start preparing</small></div>
                </div>
                <span class="kds-col-count" id="countPending">0</span>
            </div>
            <div class="kds-col-tickets" id="ticketsPending"></div>
        </section>

        <!-- Column 2: Preparing (In progress) -->
        <section class="kds-column" id="colPreparing">
            <div class="kds-col-header">
                <div class="kds-col-title">
                    <span class="kds-stage-icon"><i class="bi bi-fire" aria-hidden="true"></i></span>
                    <div><h3>Preparing</h3><small>Orders currently in the kitchen</small></div>
                </div>
                <span class="kds-col-count" id="countPreparing">0</span>
            </div>
            <div class="kds-col-tickets" id="ticketsPreparing"></div>
        </section>

        <!-- Column 3: To Be Served (Ready) -->
        <section class="kds-column" id="colReady">
            <div class="kds-col-header">
                <div class="kds-col-title">
                    <span class="kds-stage-icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
                    <div><h3>To Be Served</h3><small>Ready for serving or handoff</small></div>
                </div>
                <span class="kds-col-count" id="countReady">0</span>
            </div>
            <div class="kds-col-tickets" id="ticketsReady"></div>
        </section>
    </main>
</div>

<script>window.KK_CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php $script = 'kitchen.js?v=' . (@filemtime(ROOT_PATH . '/assets/js/kitchen.js') ?: '20261118'); include ROOT_PATH . '/includes/footer.php'; ?>
