<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier', 'server']);

$page = 'serving';
$title = 'Order Serving';
$heading = 'Order Serving';

include ROOT_PATH . '/includes/header.php';
?>

<style>
    .serving-page {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .serve-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
    }

    .serve-toolbar h2 {
        margin: 0;
        font-size: 1.65rem;
        color: #111111;
    }

    .serve-toolbar p {
        margin: 4px 0 0;
        font-size: 0.88rem;
    }

    .serve-tools {
        display: flex;
        gap: 8px;
    }

    .serve-tools .btn {
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        font-size: 0.82rem;
    }

    .serving-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .serving-stat-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 15px 18px;
        min-height: 80px;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: #ffffff;
    }

    .serving-stat-card span {
        display: block;
        margin-bottom: 4px;
        color: #737373;
        font-size: 0.84rem;
        font-weight: 600;
    }

    .serving-stat-card strong {
        display: block;
        color: #111111;
        font-size: 1.55rem;
        line-height: 1;
    }

    .serving-stat-icon {
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

    .serving-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
    }

    .serve-card {
        padding: 14px;
        border: 1px solid #e5e5e5;
        border-radius: 13px;
        background: #ffffff;
        box-shadow: 0 2px 7px rgba(0, 0, 0, 0.035);
    }

    .serve-card.serve-card-error {
        border-color: #dc2626;
        box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.08);
    }

    .serve-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 11px;
    }

    .serve-card-head-left {
        min-width: 0;
    }

    .serve-queue-number {
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

    .serve-order-number {
        margin: 7px 0 0;
        color: #737373;
        font-size: 0.82rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .serve-type-badge {
        flex-shrink: 0;
        padding: 6px 8px;
        border-radius: 6px;
        background: #f7f7f7;
        border: 1px solid #dddddd;
        color: #111111;
        font-size: 0.69rem;
        font-weight: 800;
    }

    .serve-destination {
        margin: 0 0 10px;
        padding: 11px;
        border: 1px solid #dedede;
        border-radius: 9px;
        background: #fafafa;
    }

    .serve-destination-label {
        display: block;
        margin-bottom: 3px;
        color: #777777;
        font-size: 0.64rem;
        font-weight: 800;
        letter-spacing: 0.35px;
        text-transform: uppercase;
    }

    .serve-destination-main {
        display: block;
        color: #111111;
        font-size: 1.32rem;
        line-height: 1.05;
        font-weight: 900;
    }

    .serve-destination-sub {
        display: block;
        margin-top: 3px;
        color: #6b6b6b;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .serve-meta {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 7px;
        margin-bottom: 10px;
    }

    .serve-meta-box {
        padding: 8px 9px;
        border: 1px solid #eeeeee;
        border-radius: 7px;
        background: #ffffff;
    }

    .serve-meta-box span {
        display: block;
        margin-bottom: 3px;
        color: #888888;
        font-size: 0.63rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .serve-meta-box strong {
        display: block;
        color: #111111;
        font-size: 0.82rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .serve-note {
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

    .serve-note i {
        flex-shrink: 0;
        margin-top: 2px;
        color: #111111;
    }

    .serve-items {
        margin: 0 0 11px;
        padding: 0;
        list-style: none;
    }

    .serve-items li {
        padding: 6px 0;
        border-bottom: 1px dashed #e5e5e5;
        color: #222222;
        font-size: 0.84rem;
        line-height: 1.35;
    }

    .serve-items li:last-child {
        border-bottom: none;
    }

    .serve-item-note {
        display: block;
        margin-top: 2px;
        color: #858585;
        font-size: 0.69rem;
        line-height: 1.3;
    }

    .serve-actions {
        display: grid;
        grid-template-columns: 1.35fr 1fr;
        gap: 7px;
    }

    .serve-actions .btn {
        min-height: 39px;
        padding: 6px 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border-radius: 7px;
        font-size: 0.74rem;
        font-weight: 800;
    }

    .serve-complete-btn {
        background: #F2C12E !important;
        border: 1px solid #D8AF18 !important;
        color: #111111 !important;
    }

    .serve-complete-btn:hover {
        background: #E8B717 !important;
    }

    .serve-empty-card {
        grid-column: 1 / -1;
        min-height: 270px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 30px;
        border: 1px solid #e5e5e5;
        border-radius: 13px;
        background: #ffffff;
    }

    .serve-empty-card i {
        margin-bottom: 10px;
        color: #b9b9b9;
        font-size: 2.4rem;
    }

    .serve-empty-card h2 {
        margin: 0 0 5px;
        color: #111111;
        font-size: 1.08rem;
    }

    .serve-empty-card p {
        margin: 0;
        font-size: 0.84rem;
    }

    .serve-notification-overlay {
        position: fixed;
        inset: 0;
        z-index: 100000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(0, 0, 0, 0.58);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }

    .serve-notification-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .serve-notification-modal {
        position: relative;
        width: min(420px, 100%);
        overflow: hidden;
        border: 1px solid #e5e5e5;
        border-radius: 15px;
        background: #ffffff;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.25);
        transform: translateY(12px) scale(0.98);
        transition: transform 0.2s ease;
    }

    .serve-notification-overlay.show .serve-notification-modal {
        transform: translateY(0) scale(1);
    }

    .serve-notification-accent {
        height: 6px;
        background: #F2C12E;
    }

    .serve-notification-body {
        padding: 25px;
        text-align: center;
    }

    .serve-notification-icon {
        width: 60px;
        height: 60px;
        margin: 0 auto 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #FFF1B8;
        color: #111111;
        font-size: 1.7rem;
    }

    .serve-notification-title {
        margin: 0;
        color: #111111;
        font-size: 1.2rem;
        font-weight: 900;
    }

    .serve-notification-message {
        margin: 7px auto 16px;
        max-width: 320px;
        color: #666666;
        font-size: 0.8rem;
        line-height: 1.5;
    }

    .serve-notification-btn {
        width: 100%;
        height: 40px;
        border: 1px solid #D8AF18;
        border-radius: 7px;
        background: #F2C12E;
        color: #111111;
        font-size: 0.8rem;
        font-weight: 800;
        cursor: pointer;
    }

    .serve-notification-btn:hover {
        background: #E8B717;
    }

    .slip-preview {
        width: min(440px, calc(100vw - 30px));
        max-height: calc(100vh - 40px);
        overflow-y: auto;
    }

    @media (max-width: 1200px) {
        .serving-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 950px) {
        .serving-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 700px) {
        .serve-toolbar {
            flex-direction: column;
            align-items: stretch;
        }

        .serving-stats {
            grid-template-columns: 1fr;
        }

        .serving-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="serving-page">
    <section class="serve-toolbar">
        <div>
            <h2>Ready Orders</h2>
            <p class="muted">Orders marked ready by the kitchen appear here automatically.</p>
        </div>

        <div class="serve-tools">
            <a class="btn btn-secondary" href="display.php" target="_blank">
                <i class="bi bi-tv"></i>
                Now Serving Display
            </a>

            <button class="btn btn-secondary" id="refreshServing">
                <i class="bi bi-arrow-clockwise"></i>
                Refresh
            </button>
        </div>
    </section>

    <section class="serving-stats">
        <article class="serving-stat-card">
            <div>
                <span>Ready Orders</span>
                <strong id="readyCount">0</strong>
            </div>

            <div class="serving-stat-icon">
                <i class="bi bi-bell"></i>
            </div>
        </article>

        <article class="serving-stat-card">
            <div>
                <span>Dine-In</span>
                <strong id="dineInCount">0</strong>
            </div>

            <div class="serving-stat-icon">
                <i class="bi bi-table"></i>
            </div>
        </article>

        <article class="serving-stat-card">
            <div>
                <span>Pickup</span>
                <strong id="pickupCount">0</strong>
            </div>

            <div class="serving-stat-icon">
                <i class="bi bi-bag-check"></i>
            </div>
        </article>
    </section>

    <div id="serveError" hidden></div>

    <section class="serving-grid" id="servingGrid">
        <article class="serve-empty-card">
            <span class="loader"></span>
            <h2>Loading ready orders...</h2>
            <p class="muted">Please wait while the system checks the kitchen queue.</p>
        </article>
    </section>
</div>

<div class="modal-backdrop" id="slipModal" style="display: none;" hidden>
    <section class="modal-card slip-preview">
        <div class="modal-head no-print">
            <h2>Order Slip</h2>

            <button class="icon-btn" id="closeSlip">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div id="printArea"></div>

        <div class="serve-actions no-print">
            <button class="btn serve-complete-btn" id="printSlip">
                <i class="bi bi-printer"></i>
                Print Slip
            </button>

            <button class="btn btn-secondary" id="cancelSlip">
                Close
            </button>
        </div>
    </section>
</div>

<?php $script = 'serving.js?v=' . time(); include ROOT_PATH . '/includes/footer.php'; ?>