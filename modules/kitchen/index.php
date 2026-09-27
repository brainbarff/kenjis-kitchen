<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'kitchen']);

$page = 'kitchen';
$title = 'Kitchen Display System';
$heading = 'Kitchen Display System';

include ROOT_PATH . '/includes/header.php';
?>

<style>
    .kitchen-page {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .kds-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .kds-heading h2 {
        margin: 0;
        color: #111111;
        font-size: 1.65rem;
    }

    .kds-heading p {
        margin: 4px 0 0;
        font-size: 0.88rem;
    }

    .kds-refresh-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 11px;
        border: 1px solid #dddddd;
        border-radius: 7px;
        background: #ffffff;
        color: #666666;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .kds-top {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .kds-stat {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 80px;
        padding: 15px 18px;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: #ffffff;
    }

    .kds-stat-label {
        display: block;
        margin-bottom: 4px;
        color: #737373;
        font-size: 0.84rem;
        font-weight: 600;
    }

    .kds-stat strong {
        display: block;
        color: #111111;
        font-size: 1.55rem;
        line-height: 1;
    }

    .kds-stat-icon {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #FFF1B8;
        color: #111111;
        font-size: 1.05rem;
    }

    .kds-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
    }

    .kds-ticket {
        padding: 14px;
        border: 1px solid #e5e5e5;
        border-radius: 13px;
        background: #ffffff;
        box-shadow: 0 2px 7px rgba(0, 0, 0, 0.035);
    }

    .kds-ticket.pending {
        border-top: 4px solid #F2C12E;
    }

    .kds-ticket.preparing {
        border-top: 4px solid #111111;
    }

    .kds-ticket.ready {
        border-top: 4px solid #767676;
    }

    .kds-ticket-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 11px;
    }

    .kds-ticket-left {
        min-width: 0;
    }

    .kds-queue-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 54px;
        height: 40px;
        padding: 0 10px;
        border-radius: 9px;
        background: #F2C12E;
        color: #111111;
        font-size: 1.15rem;
        font-weight: 900;
    }

    .kds-order-number {
        margin: 7px 0 0;
        color: #737373;
        font-size: 0.82rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .kds-status {
        flex-shrink: 0;
        padding: 6px 8px;
        border: 1px solid #dddddd;
        border-radius: 6px;
        background: #f7f7f7;
        color: #111111;
        font-size: 0.69rem;
        font-weight: 800;
    }

    .kds-destination {
        margin-bottom: 10px;
        padding: 11px;
        border: 1px solid #dedede;
        border-radius: 9px;
        background: #fafafa;
    }

    .kds-destination-label {
        display: block;
        margin-bottom: 3px;
        color: #777777;
        font-size: 0.64rem;
        font-weight: 800;
        letter-spacing: 0.35px;
        text-transform: uppercase;
    }

    .kds-destination-main {
        display: block;
        color: #111111;
        font-size: 1.32rem;
        line-height: 1.05;
        font-weight: 900;
    }

    .kds-destination-sub {
        display: block;
        margin-top: 3px;
        color: #666666;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .kds-info-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 7px;
        margin-bottom: 10px;
    }

    .kds-info-box {
        padding: 8px 9px;
        border: 1px solid #eeeeee;
        border-radius: 7px;
        background: #ffffff;
    }

    .kds-info-box span {
        display: block;
        margin-bottom: 3px;
        color: #888888;
        font-size: 0.63rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .kds-info-box strong {
        display: block;
        color: #111111;
        font-size: 0.82rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .kds-note {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        margin-bottom: 10px;
        padding: 8px 9px;
        border-left: 3px solid #F2C12E;
        border-radius: 6px;
        background: #FFFBE6;
        color: #454545;
        font-size: 0.76rem;
        line-height: 1.4;
    }

    .kds-note i {
        margin-top: 2px;
        color: #111111;
    }

    .kds-items {
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .kds-items li {
        padding: 6px 0;
        border-bottom: 1px dashed #e5e5e5;
        color: #222222;
        font-size: 0.84rem;
        line-height: 1.35;
    }

    .kds-items li:last-child {
        border-bottom: none;
    }

    .kds-item-note {
        display: block;
        margin-top: 2px;
        color: #858585;
        font-size: 0.69rem;
        line-height: 1.3;
    }

    .kds-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 11px;
    }

    .kds-actions .btn {
        min-height: 39px;
        padding: 6px 11px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border-radius: 7px;
        font-size: 0.74rem;
        font-weight: 800;
    }

    .kds-start-btn {
        background: #F2C12E !important;
        border: 1px solid #D8AF18 !important;
        color: #111111 !important;
    }

    .kds-start-btn:hover {
        background: #E8B717 !important;
    }

    .kds-ready-btn {
        background: #111111 !important;
        border: 1px solid #111111 !important;
        color: #ffffff !important;
    }

    .kds-waiting {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 39px;
        padding: 0 11px;
        border: 1px solid #dddddd;
        border-radius: 7px;
        background: #f7f7f7;
        color: #666666;
        font-size: 0.74rem;
        font-weight: 800;
    }

    @media (max-width: 1200px) {
        .kds-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 950px) {
        .kds-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 700px) {
        .kds-heading {
            flex-direction: column;
            align-items: stretch;
        }

        .kds-top {
            grid-template-columns: 1fr;
        }

        .kds-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="kitchen-page">
    <section class="kds-heading">
        <div>
            <h2>Kitchen Orders</h2>
            <p class="muted">Prepare incoming orders and send completed orders to the serving station.</p>
        </div>

        <div class="kds-refresh-label">
            <i class="bi bi-arrow-repeat"></i>
            Auto-refresh every 5 seconds
        </div>
    </section>

    <section class="kds-top">
        <article class="kds-stat">
            <div>
                <span class="kds-stat-label">Active</span>
                <strong id="activeCount">0</strong>
            </div>

            <div class="kds-stat-icon">
                <i class="bi bi-fire"></i>
            </div>
        </article>

        <article class="kds-stat">
            <div>
                <span class="kds-stat-label">Preparing</span>
                <strong id="preparingCount">0</strong>
            </div>

            <div class="kds-stat-icon">
                <i class="bi bi-hourglass-split"></i>
            </div>
        </article>

        <article class="kds-stat">
            <div>
                <span class="kds-stat-label">Ready</span>
                <strong id="readyCount">0</strong>
            </div>

            <div class="kds-stat-icon">
                <i class="bi bi-check2-circle"></i>
            </div>
        </article>
    </section>

    <section class="kds-grid" id="kdsGrid"></section>
</div>

<?php $script = 'kitchen.js?v=' . time(); include ROOT_PATH . '/includes/footer.php'; ?>