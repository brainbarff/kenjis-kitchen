<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/api/price_helpers.php';
require_once ROOT_PATH . '/includes/gcash_verification.php';
require_role(['admin', 'cashier']);

processPendingPriceUpdates($conn);
expire_overdue_gcash_orders($conn, (int)(current_user()['id'] ?? 0) ?: null);

$page = 'pos';
$title = 'Point of Sale';
$heading = 'Point of Sale';
$orderSounds = true;
$bodyClass = 'pos-page';

$categories = $conn->query("SELECT * FROM categories WHERE status = 'Active' ORDER BY sort_order ASC")->fetchAll();
// Keep POS navigation aligned with Menu Management without changing stored categories.
$posCategoryOrder = ['add-ons' => 0, 'student meals' => 1, 'pulutan & pares' => 2, 'drinks' => 3, 'silog' => 4, 'silog meals' => 4, 'silog set meals' => 5, 'bilao favorites' => 6];
usort($categories, static function ($a, $b) use ($posCategoryOrder) {
    return ($posCategoryOrder[strtolower($a['category_name'])] ?? 99) <=> ($posCategoryOrder[strtolower($b['category_name'])] ?? 99);
});
$raw_items = $conn->query("
    SELECT menu_items.*, categories.category_name
    FROM menu_items
    JOIN categories ON categories.id = menu_items.category_id
    WHERE menu_items.is_archived = 0
    ORDER BY categories.sort_order ASC, menu_items.sort_order ASC
")->fetchAll();

$bilaoCatId = null;
$silogCatId = null;
foreach ($categories as $cat) {
    if ($cat['category_name'] === 'Bilao Favorites') {
        $bilaoCatId = $cat['id'];
    } elseif ($cat['category_name'] === 'Silog Set Meals') {
        $silogCatId = $cat['id'];
    }
}

$today_count = 1;
try {
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
    $today_count = ((int)$stmt->fetchColumn()) + 1;
} catch (Exception $e) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM orders");
        $today_count = ((int)$stmt->fetchColumn()) + 1;
    } catch (Exception $e2) {
        $today_count = 1;
    }
}
$orderTicket = str_pad($today_count, 4, '0', STR_PAD_LEFT);

require_once ROOT_PATH . '/config/bilao-pricing.php';
$official_specs = bilao_pricing_specs();
$resolved_bilao_groups = resolve_bilao_variant_states($conn);

$bilao_groups = [];
foreach ($resolved_bilao_groups as $dish => $groupInfo) {
    if (!empty($groupInfo['all_variants_archived'])) {
        continue;
    }
    $bilao_groups[$dish] = $groupInfo;
}

$regular_items = [];

foreach ($raw_items as $item) {
    if ((int)($item['is_archived'] ?? 0) === 1) {
        continue;
    }
    if ($bilaoCatId && (int)$item['category_id'] === (int)$bilaoCatId) {
        continue;
    }

    $isDrinks = strcasecmp((string)($item['category_name'] ?? ''), 'Drinks') === 0;
    if ($isDrinks && is_one_liter_grouped_drink_name((string)$item['item_name'])) {
        continue;
    }

    $regular_items[] = $item;
}

include ROOT_PATH . '/includes/header.php';
?>
<style>
    body {
        overflow: hidden;
    }

    html {
        scrollbar-gutter: stable;
    }

    .pos-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 20px;
        height: calc(100vh - 110px);
        max-height: calc(100vh - 110px);
        overflow: hidden;
        align-items: stretch;
        padding-bottom: 8px;
    }

    .pos-products-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 12px;
        flex-shrink: 0;
        flex-wrap: nowrap;
        width: 100%;
    }

    .pos-category-scroller-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
        flex: 1;
        min-width: 0;
        position: relative;
    }

    .pos-category-nav-btn {
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #334155;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.15s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        user-select: none;
    }

    .pos-category-nav-btn:hover:not(:disabled) {
        background: #F2C12E;
        border-color: #F2C12E;
        color: #232323;
        transform: scale(1.05);
    }

    .pos-category-nav-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
        pointer-events: none;
    }

    .pos-products-header .tabs {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        overflow-y: hidden;
        padding: 4px 2px;
        scrollbar-width: none;
        -ms-overflow-style: none;
        flex: 1;
        min-width: 0;
        white-space: nowrap;
        flex-wrap: nowrap;
        scroll-behavior: smooth;
        cursor: grab;
    }

    .pos-products-header .tabs:active {
        cursor: grabbing;
    }

    .pos-products-header .tabs .tab {
        flex-shrink: 0;
        white-space: nowrap;
    }

    .pos-products-header .tabs::-webkit-scrollbar {
        display: none;
    }

    .pos-search-box {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 0 16px;
        height: 42px;
        width: 250px;
        max-width: 100%;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .pos-search-box:focus-within {
        border-color: #F2C12E;
        box-shadow: 0 0 0 3px rgba(242, 193, 46, 0.2);
    }

    .pos-search-box i {
        color: #94a3b8;
        font-size: 0.95rem;
    }

    .pos-search-box input {
        border: none;
        outline: none;
        background: transparent;
        font-size: 0.85rem;
        width: 100%;
        color: #1e293b;
    }

    .pos-layout > section {
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }

    .tabs {
        flex-shrink: 0;
    }

    .sub-tabs {
        flex-shrink: 0;
        display: none;
        gap: 8px;
        margin-top: 10px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .menu-grid {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 180px), 1fr)) !important;
        grid-auto-rows: max-content !important;
        gap: 14px !important;
        padding-right: 6px;
        padding-bottom: 24px;
        height: auto !important;
    }

    .menu-grid::-webkit-scrollbar {
        width: 6px;
    }

    .menu-grid::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 4px;
    }

    .menu-grid::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .menu-grid::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .food-card {
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: #ffffff;
        border-radius: 14px;
        padding: 10px;
        box-sizing: border-box;
        overflow: hidden;
        border: 1px solid #f1f5f9;
        min-height: 250px !important;
        height: auto !important;
        flex-shrink: 0 !important;
        box-shadow: 0 3px 12px rgba(0, 0, 0, 0.03);
        transition: transform 0.16s ease, box-shadow 0.16s ease;
    }

    .food-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
    }

    .food-card.unavailable {
        opacity: 0.55;
        filter: grayscale(0.5);
        pointer-events: none;
    }

    .food-card img {
        width: 100%;
        height: 135px !important;
        min-height: 135px !important;
        max-height: 135px !important;
        object-fit: cover;
        border-radius: 10px;
        background-color: #f8fafc;
        transition: transform 0.2s ease;
        flex-shrink: 0 !important;
    }

    .food-card:hover img {
        transform: scale(1.04);
    }

    .stock-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 9999px;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
        z-index: 2;
        pointer-events: none;
    }

    .stock-badge.low-stock {
        background: #fef3c7;
        color: #b45309;
        border-color: #fde68a;
    }

    .stock-badge.out-of-stock {
        background: #fee2e2;
        color: #dc2626;
        border-color: #fecaca;
    }

    .card-info {
        display: flex;
        flex-direction: column;
        flex-grow: 1;
        justify-content: space-between;
        padding: 4px;
    }

    .card-info h3 {
        font-size: 0.95rem;
        margin: 4px 0 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .card-desc {
        font-size: 0.72rem;
        color: #6b7280;
        line-height: 1.3;
        margin: 0 0 6px;
        min-height: 28px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .card-footer-row {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-top: auto;
    }

    .card-footer-price {
        font-size: 0.95rem;
        font-weight: 800;
        color: #0f172a;
    }

    .qty-stepper {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 28px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
        box-sizing: border-box;
    }

    .btn-qty {
        flex: 1;
        height: 100%;
        border: none;
        outline: none;
        box-shadow: none;
        background: transparent;
        font-size: 1rem;
        font-weight: 700;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: color 0.15s ease;
        user-select: none;
        -webkit-tap-highlight-color: transparent;
    }

    .btn-qty:hover,
    .btn-qty:active,
    .btn-qty:focus,
    .btn-qty:focus-visible {
        background: transparent;
        outline: none;
        box-shadow: none;
    }

    .btn-qty:hover:not(:disabled) {
        color: #0f172a;
    }

    .btn-qty:disabled {
        color: #cbd5e1;
        cursor: not-allowed;
        background: transparent;
    }

    .qty-display {
        flex: 1;
        text-align: center;
        font-size: 0.85rem;
        font-weight: 700;
        color: #0f172a;
    }

    .receipt-panel {
        height: 100%;
        max-height: 100%;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border-radius: 14px;
        padding: 14px 16px;
        box-sizing: border-box;
        overflow: hidden;
        border: 1px solid #f1f5f9;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .receipt-header-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .receipt-header-row2 {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-top: 6px;
    }

    .receipt-header-row2-left {
        display: flex;
        align-items: center;
        gap: 6px;
        flex: 1;
        min-width: 0;
    }

    .receipt-header-row2-right {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-shrink: 0;
    }

    .btn-online-orders {
        min-height: 34px;
        padding: 0 10px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        background: #ffffff;
        color: #111111;
        font-size: 0.72rem;
        font-weight: 800;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: border-color 0.15s ease, background 0.15s ease;
    }

    .btn-online-orders:hover {
        border-color: #D8AF18;
        background: #FFFBE6;
    }

    .online-orders-badge {
        min-width: 20px;
        height: 20px;
        padding: 0 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #F2C12E;
        color: #111111;
        font-size: 0.68rem;
        font-weight: 900;
    }

    .online-orders-badge.has-pending {
        background: #D97706;
        color: #ffffff;
    }

    .online-orders-overlay {
        position: fixed;
        inset: 0;
        z-index: 99998;
        background: rgba(0, 0, 0, 0.48);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .online-orders-overlay[hidden] {
        display: none;
    }

    .online-orders-modal {
        width: min(760px, 100%);
        max-height: min(720px, 90vh);
        overflow: hidden;
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 28px 80px rgba(0, 0, 0, 0.28);
        display: flex;
        flex-direction: column;
    }

    .online-orders-modal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid #e5e7eb;
    }

    .online-orders-modal-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 900;
        color: #111111;
    }

    .online-orders-modal-subtitle {
        margin: 3px 0 0;
        font-size: 0.74rem;
        color: #6b7280;
    }

    .online-orders-modal-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .online-orders-modal-btn {
        min-height: 34px;
        padding: 0 10px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        background: #ffffff;
        color: #111111;
        font-size: 0.72rem;
        font-weight: 800;
        cursor: pointer;
    }

    .online-orders-modal-btn:hover {
        background: #f8fafc;
    }

    .online-orders-list {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding: 12px;
    }

    .online-order-card {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px;
        margin-bottom: 10px;
        background: #ffffff;
    }

    .online-order-card:last-child {
        margin-bottom: 0;
    }

    .online-order-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 8px;
    }

    .online-order-number {
        font-size: 0.9rem;
        font-weight: 900;
        color: #111111;
    }

    .online-order-meta {
        margin-top: 2px;
        font-size: 0.72rem;
        color: #6b7280;
    }

    .online-order-status {
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 0.67rem;
        font-weight: 900;
        white-space: nowrap;
    }

    .online-order-status.pending {
        background: #FEF3C7;
        color: #B45309;
    }

    .online-order-status.preparing {
        background: #DBEAFE;
        color: #1D4ED8;
    }

    .online-order-status.ready {
        background: #DCFCE7;
        color: #15803D;
    }

    .online-order-lines {
        margin: 8px 0 10px;
        padding: 9px 10px;
        border-radius: 8px;
        background: #f8fafc;
    }

    .online-order-line {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        font-size: 0.76rem;
        color: #374151;
        padding: 3px 0;
    }

    .online-order-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-top: 8px;
        border-top: 1px solid #eef2f7;
        font-size: 0.76rem;
    }

    .online-order-total {
        font-size: 0.9rem;
        font-weight: 900;
        color: #111111;
    }

    .online-orders-empty {
        padding: 48px 20px;
        text-align: center;
        color: #6b7280;
        font-size: 0.82rem;
    }

    .online-orders-error {
        padding: 24px 20px;
        text-align: center;
        color: #b91c1c;
        font-size: 0.82rem;
    }

    .receipt-brand {
        flex-shrink: 0;
        padding-bottom: 8px;
        border-bottom: 1px solid #f1f5f9;
    }

    .receipt-header-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .receipt-brand-left {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .brand-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: #f59e0b;
        color: #ffffff;
        font-weight: 800;
        font-size: 0.95rem;
        flex-shrink: 0;
    }

    .brand-text-col {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .brand-text-col h2 {
        font-size: 1.05rem;
        font-weight: 800;
        margin: 0;
        color: #0f172a;
        line-height: 1.15;
    }

    .order-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
        padding: 1px 7px;
        border-radius: 4px;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.3px;
        width: fit-content;
    }

    .btn-clear-cart {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #ffffff;
        color: #0f172a;
        border: 1px solid #cbd5e1;
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .btn-clear-cart:hover:not(:disabled) {
        background: #0f172a;
        color: #ffffff;
        border-color: #0f172a;
    }

    .btn-clear-cart:disabled {
        opacity: 0.45;
        cursor: not-allowed;
        background: #f8fafc;
        color: #cbd5e1;
        border-color: #e2e8f0;
    }

    .receipt-form-top {
        flex-shrink: 0;
        display: grid;
        grid-template-columns: 1.1fr 0.9fr;
        gap: 8px;
        padding: 8px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .receipt-form-top .field {
        display: flex;
        flex-direction: column;
        gap: 3px;
        margin-bottom: 0;
    }

    .receipt-form-top label {
        font-size: 0.72rem;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .receipt-form-top select,
    .receipt-form-top input {
        height: 30px;
        min-height: 30px;
        max-height: 30px;
        font-size: 0.82rem;
        padding: 2px 8px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        background: #f8fafc;
        width: 100%;
        box-sizing: border-box;
    }

    .cart-items {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        margin: 6px 0;
        padding-right: 4px;
        display: flex !important;
        flex-direction: column !important;
        justify-content: flex-start !important;
        align-items: stretch !important;
    }

    .cart-items::-webkit-scrollbar {
        width: 4px;
    }

    .cart-items::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 4px;
    }

    .cart-items::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .cart-items::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .empty-cart-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        min-height: 130px;
        text-align: center;
        color: #94a3b8;
        gap: 5px;
        margin: auto 0;
    }

    .empty-cart-state i {
        font-size: 2.2rem;
        color: #cbd5e1;
    }

    .empty-cart-state span {
        font-size: 0.85rem;
        font-weight: 700;
        color: #64748b;
    }

    .empty-cart-state small {
        font-size: 0.72rem;
        color: #94a3b8;
    }

    .cart-row {
        display: flex !important;
        flex: 0 0 auto !important;
        align-items: center;
        justify-content: space-between;
        padding: 5px 0 !important;
        border-bottom: 1px dashed #e2e8f0;
        gap: 8px;
    }

    .cart-row:last-child {
        border-bottom: none;
    }

    .cart-row > div:first-child {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1px;
    }

    .cart-row strong {
        font-size: 0.85rem;
        color: #0f172a;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cart-row p.muted {
        font-size: 0.72rem;
        color: #64748b;
        margin: 0;
        line-height: 1.2;
    }

    .cart-row .qty {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 1px 4px;
        width: fit-content;
        margin-top: 2px;
    }

    .cart-row .qty button {
        width: 20px;
        height: 20px;
        border: none;
        outline: none;
        background: transparent;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border-radius: 4px;
        transition: background 0.15s ease;
    }

    .cart-row .qty button:hover:not(:disabled) {
        background: #cbd5e1;
        color: #0f172a;
    }

    .cart-row .qty span {
        min-width: 16px;
        text-align: center;
        font-size: 0.78rem;
        font-weight: 700;
        color: #0f172a;
    }

    .cart-row .qty .cart-qty-input {
        width: 38px;
        height: 22px;
        padding: 0 2px;
        border: none;
        outline: none;
        background: transparent;
        text-align: center;
        font-size: 0.78rem;
        font-weight: 700;
        color: #0f172a;
        box-sizing: border-box;
        appearance: textfield;
        -moz-appearance: textfield;
    }

    .cart-row .qty .cart-qty-input::-webkit-outer-spin-button,
    .cart-row .qty .cart-qty-input::-webkit-inner-spin-button {
        margin: 0;
        -webkit-appearance: none;
    }

    .cart-row .qty .cart-qty-input:focus {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
    }

    .cart-row > strong:last-child {
        font-size: 0.88rem;
        font-weight: 800;
        color: #0f172a;
        white-space: nowrap;
    }

    .receipt-bottom-section {
        flex-shrink: 0;
        border-top: 1px solid #e2e8f0;
        padding-top: 6px;
        padding-bottom: 2px;
        background: #ffffff;
    }

    .note-trigger-wrap {
        padding: 2px 0 6px 0;
        display: flex;
        align-items: center;
    }

    .btn-add-note-link {
        background: none;
        border: none;
        padding: 0;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.78rem;
        font-weight: 700;
        color: #64748b;
        cursor: pointer;
        transition: color 0.15s ease;
    }

    .btn-add-note-link:hover {
        color: #0f172a;
    }

    .btn-add-note-link i {
        font-size: 0.82rem;
    }

    .note-input-row {
        display: flex;
        align-items: center;
        gap: 6px;
        width: 100%;
        padding: 2px 0 6px 0;
    }

    .note-input-row input {
        flex: 1;
        height: 26px;
        padding: 2px 8px;
        font-size: 0.78rem;
        border: 1px solid #cbd5e1;
        border-radius: 5px;
        background: #f8fafc;
        box-sizing: border-box;
    }

    .note-input-row input:focus {
        outline: none;
        border-color: #0f172a;
        background: #ffffff;
    }

    .note-input-row button {
        height: 26px;
        padding: 0 8px;
        font-size: 0.75rem;
        font-weight: 700;
        border-radius: 5px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        cursor: pointer;
    }

    .note-input-row .btn-done-note {
        background: #0f172a;
        color: #ffffff;
        border-color: #0f172a;
    }

    .note-pill-display {
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        padding: 3px 8px;
        border-radius: 5px;
        margin-bottom: 6px;
        box-sizing: border-box;
        font-size: 0.75rem;
        color: #334155;
    }

    .note-pill-text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 90%;
    }

    .note-pill-remove {
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 0 2px;
        font-size: 0.85rem;
        line-height: 1;
    }

    .note-pill-remove:hover {
        color: #ef4444;
    }

    .totals {
        display: flex;
        flex-direction: column;
        gap: 3px;
        font-size: 0.8rem;
        margin-bottom: 6px;
    }

    .totals > div {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .totals .grand {
        font-size: 0.98rem;
        font-weight: 800;
        border-top: 1px dashed #cbd5e1;
        padding-top: 3px;
        margin-top: 2px;
        color: #0f172a;
    }

    .discount-line {
        display: grid !important;
        grid-template-columns: 1fr auto auto;
        align-items: center;
        gap: 8px;
    }

    .pos-discount-quick-btns {
        display: flex;
        align-items: center;
        gap: 5px;
        margin: 4px 0 6px;
        width: 100%;
    }

    .pos-disc-btn {
        flex: 1;
        padding: 5px 6px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #334155;
        border-radius: 6px;
        font-size: 0.74rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        transition: all 0.15s ease;
        user-select: none;
    }

    .pos-disc-btn:hover {
        border-color: #F2C12E;
        background: #fffbeb;
        color: #92400e;
    }

    .pos-disc-btn.active {
        background: #F2C12E;
        border-color: #d97706;
        color: #232323;
        box-shadow: 0 1px 3px rgba(242, 193, 46, 0.4);
    }

    .pos-disc-badge-row {
        display: none;
        align-items: center;
        justify-content: space-between;
        background: #fef3c7;
        border: 1px solid #fde68a;
        border-radius: 6px;
        padding: 3px 8px;
        font-size: 0.72rem;
        color: #92400e;
        margin-bottom: 4px;
    }

    .pos-disc-badge-row .badge-text {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-weight: 700;
    }

    .pos-disc-badge-row .remove-btn {
        background: none;
        border: none;
        color: #b45309;
        font-size: 0.95rem;
        cursor: pointer;
        line-height: 1;
        padding: 0 2px;
        display: inline-flex;
        align-items: center;
    }

    .pos-disc-badge-row .remove-btn:hover {
        color: #b91c1c;
    }

    .discount-control {
        display: inline-flex;
        align-items: center;
        gap: 3px;
    }

    .discount-control input {
        width: 58px;
        height: 22px;
        padding: 1px 6px;
        font-size: 0.8rem;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        text-align: right;
        box-sizing: border-box;
    }

    .discount-control span {
        font-size: 0.76rem;
        font-weight: 700;
        color: #475569;
    }

    .discount-amount {
        min-width: 72px;
        text-align: right;
        font-weight: 700;
        color: #475569;
    }

    .receipt-cash-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 4px 8px;
        margin-bottom: 6px;
        box-sizing: border-box;
    }

    .receipt-cash-row .field {
        display: flex;
        align-items: center;
        gap: 6px;
        margin: 0;
        flex: 1;
    }

    .receipt-cash-row label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
    }

    .receipt-cash-row input {
        width: 100px;
        height: 26px;
        min-height: 26px;
        max-height: 26px;
        padding: 2px 6px;
        font-size: 0.82rem;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        background: #ffffff;
        box-sizing: border-box;
    }

    .receipt-cash-row .change-display {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 4px;
        font-size: 0.78rem;
        white-space: nowrap;
    }

    .receipt-cash-row .change-display strong {
        font-size: 0.92rem;
        font-weight: 800;
        color: #0f172a;
    }

    .payment-toggle-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
        margin-bottom: 6px;
    }

    .btn-pay-toggle {
        height: 40px;
        font-size: 0.85rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 10px;
        cursor: pointer;
        background: #ffffff;
        color: #475569;
        border: 2px solid #e2e8f0;
        outline: none;
        transition: all 0.15s ease;
        box-sizing: border-box;
    }

    .btn-pay-toggle:hover:not(.active) {
        background: #f8fafc;
        color: #0f172a;
        border-color: #cbd5e1;
    }

    .btn-pay-toggle.active {
        background: rgba(242, 193, 46, 0.12) !important;
        color: #1e1e1e !important;
        border: 2px solid #F2C12E !important;
        font-weight: 800;
        box-shadow: 0 2px 8px rgba(242, 193, 46, 0.25);
    }

    .btn-pay-toggle::before,
    .btn-pay-toggle::after {
        content: none !important;
        display: none !important;
    }

    .receipt-btn-group {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
        margin-top: 6px;
    }

    .receipt-btn-group .btn {
        height: 44px;
        font-size: 0.92rem;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 10px;
        cursor: pointer;
        box-sizing: border-box;
        transition: all 0.16s ease;
    }

    .receipt-btn-group #checkoutBtn {
        background: #F2C12E;
        color: #1e1e1e;
        border: 1px solid #E0B028;
        box-shadow: 0 4px 14px rgba(242, 193, 46, 0.35);
    }

    .receipt-btn-group #checkoutBtn:hover:not(:disabled) {
        background: #e5b422;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(242, 193, 46, 0.45);
    }

    .receipt-btn-group #checkoutBtn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        box-shadow: none;
    }

    .sub-tab {
        padding: 6px 16px;
        border-radius: 20px;
        border: 1px solid #d1d5db;
        background: #ffffff;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        color: #4b5563;
        transition: all 0.2s ease;
    }

    .sub-tab:hover {
        background: #f3f4f6;
    }

    .sub-tab.active {
        background: #1f2937;
        color: #ffffff;
        border-color: #1f2937;
    }

    .bilao-centered-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 16px 12px;
        min-height: 280px !important;
    }

    .bilao-centered-card img {
        margin-bottom: 8px;
    }

    .bilao-center-content {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        width: 100%;
        gap: 8px;
    }

    .bilao-title {
        font-size: 1.15rem;
        font-weight: 700;
        color: #111827;
        margin: 0;
    }

    .bilao-desc {
        font-size: 0.8rem;
        color: #6b7280;
        margin: 0;
        min-height: 18px;
    }

    .bilao-sizes-row {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 6px;
        width: 100%;
    }

    .bilao-size-btn {
        min-width: 0;
        padding: 0;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        font-size: 0.85rem;
        font-weight: 700;
        color: #475569;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .bilao-size-btn:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
    }

    .bilao-size-btn.active {
        background: #f59e0b;
        color: #ffffff;
        border-color: #d97706;
        box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);
    }

    .bilao-pkg-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
        width: 100%;
    }

    .bilao-pkg-row.pkg-needs-selection {
        padding: 3px;
        border-radius: 9px;
        outline: 2px solid #dc2626;
        background: #fef2f2;
    }

    .bilao-pkg-btn {
        min-width: 0;
        padding: 0 8px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .bilao-pkg-btn:hover:not(:disabled) {
        border-color: #c39a19;
        background: #fff7d9;
        color: #242424;
    }

    .bilao-pkg-btn.active {
        background: #1f2937;
        color: #ffffff;
        border-color: #1f2937;
    }

    .bilao-pkg-hint {
        font-size: 0.72rem;
        color: #6b7280;
        margin: 0;
    }

    .bilao-pkg-hint.is-error {
        color: #dc2626;
        font-weight: 700;
    }

    .food-card.bilao-card.unavailable {
        pointer-events: auto;
        cursor: default;
        opacity: 1;
    }

    .food-card.bilao-card.unavailable img,
    .food-card.bilao-card.unavailable .bilao-title,
    .food-card.bilao-card.unavailable .bilao-desc,
    .food-card.bilao-card.unavailable .bilao-price,
    .food-card.bilao-card.unavailable .bilao-reservation-note {
        opacity: 0.5;
    }

    .food-card.bilao-card.unavailable .bilao-sizes-row,
    .food-card.bilao-card.unavailable .bilao-size-btn,
    .food-card.bilao-card.unavailable .bilao-pkg-row,
    .food-card.bilao-card.unavailable .bilao-pkg-btn {
        pointer-events: auto;
        cursor: pointer;
    }

    .bilao-price {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0f172a;
        margin-top: 2px;
    }


    .pos-keypad-overlay {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(15, 23, 42, 0.46);
    }

    .pos-keypad-overlay.show {
        display: flex;
    }

    .pos-keypad-modal {
        width: min(360px, 100%);
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.22);
        padding: 16px;
    }

    .pos-keypad-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 12px;
    }

    .pos-keypad-head strong {
        font-size: 1rem;
        color: #0f172a;
    }

    .pos-keypad-close {
        width: 34px;
        height: 34px;
        border: 0;
        background: #f1f5f9;
        color: #334155;
        border-radius: 8px;
        cursor: pointer;
    }

    .pos-keypad-display {
        width: 100%;
        height: 48px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 0 12px;
        text-align: right;
        font-size: 1.35rem;
        font-weight: 800;
        color: #0f172a;
        background: #f8fafc;
        margin-bottom: 12px;
        box-sizing: border-box;
    }

    .pos-keypad-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }

    .pos-keypad-key {
        min-height: 52px;
        border: 1px solid #dbe3ec;
        border-radius: 10px;
        background: #ffffff;
        color: #0f172a;
        font-size: 1.05rem;
        font-weight: 800;
        cursor: pointer;
        touch-action: manipulation;
    }

    .pos-keypad-key:hover {
        background: #f8fafc;
    }

    .pos-keypad-key.action {
        background: #f1f5f9;
        color: #334155;
    }

    .pos-keypad-key.primary {
        background: #F2C12E;
        border-color: #E0B028;
        color: #111827;
    }

    .pos-keypad-key.wide {
        grid-column: span 2;
    }

    .pos-keypad-error {
        min-height: 18px;
        margin: 6px 0 10px;
        color: #dc2626;
        font-size: 0.78rem;
        text-align: center;
    }

    @media (max-width: 1200px) {
        body.pos-page {
            overflow: auto;
        }

        .pos-layout {
            grid-template-columns: minmax(0, 1fr) 340px;
            height: auto;
            max-height: none;
            overflow: visible;
        }

        .pos-layout > section {
            height: auto;
            overflow: visible;
        }

        .menu-grid {
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 180px), 1fr)) !important;
            overflow-y: visible;
            max-height: none;
        }
    }

    .pos-mobile-order-bar,
    .pos-back-to-menu-btn {
        display: none;
    }

    @media (max-width: 900px) {
        .pos-layout {
            grid-template-columns: minmax(0, 1fr);
            padding-bottom: 84px;
        }

        .pos-layout > section,
        .pos-layout > aside {
            min-width: 0;
            max-width: 100%;
            width: 100%;
            box-sizing: border-box;
        }

        .receipt-panel {
            position: static;
            height: auto;
            max-height: none;
            overflow: visible;
            scroll-margin-top: 84px;
        }

        .menu-grid {
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 180px), 1fr)) !important;
        }

        .pos-mobile-order-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 900;
            padding: 12px 16px max(12px, env(safe-area-inset-bottom, 0px));
            background: #1e1e1e;
            color: #ffffff;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.22);
            transition: transform 0.2s ease, opacity 0.2s ease;
        }

        .pos-mobile-order-bar.is-hidden {
            transform: translateY(100%);
            opacity: 0;
            pointer-events: none;
        }

        .pos-mobile-order-meta {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .pos-mobile-order-meta small {
            color: #cbd5e1;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .pos-mobile-order-meta strong {
            color: #f2c12e;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .pos-mobile-order-cta {
            min-height: 44px;
            padding: 10px 16px;
            border: 0;
            border-radius: 10px;
            background: #f2c12e;
            color: #1e1e1e;
            font-weight: 800;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            white-space: nowrap;
        }

        .pos-back-to-menu-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-height: 36px;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid #d6d6d6;
            background: #ffffff;
            color: #242424;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
        }
    }

    @media (max-width: 620px) {
        .pos-layout {
            gap: 12px;
        }

        .pos-products-header {
            flex-wrap: wrap;
            gap: 8px;
        }

        .pos-category-scroller-wrap {
            flex: 1 1 100%;
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }

        .pos-search {
            width: 100%;
            flex: 1 1 100%;
        }

        .menu-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
            padding-right: 0;
        }

        .food-card,
        .bilao-card {
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
        }

        .bilao-centered-card {
            grid-column: 1 / -1;
        }

        .food-card {
            min-height: 215px !important;
        }

        .food-card img {
            height: 105px !important;
            min-height: 105px !important;
            max-height: 105px !important;
        }

        .receipt-form-top {
            grid-template-columns: 1fr !important;
        }

        .receipt-header-row {
            align-items: flex-start;
            flex-direction: column;
            gap: 10px;
        }

        .receipt-header-actions {
            width: 100%;
        }

        .btn-online-orders,
        .btn-clear-cart {
            flex: 1;
        }

        .receipt-cash-row {
            flex-direction: column;
            align-items: stretch;
        }

        .receipt-cash-row .field {
            width: 100%;
        }

        .change-display {
            justify-content: space-between !important;
        }

        .tabs {
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 4px;
        }

        .tabs .tab {
            flex-shrink: 0;
        }

        .pos-keypad-overlay {
            align-items: flex-end;
            padding: 0;
        }

        .pos-keypad-modal {
            width: 100%;
            border-radius: 16px 16px 0 0;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
        }
    }

    @media print {
        .pos-mobile-order-bar {
            display: none !important;
        }

        body, html {
            overflow: visible !important;
            height: auto !important;
            background: #ffffff !important;
        }

        body * {
            visibility: hidden;
        }

        #posPrintReceipt, #posPrintReceipt * {
            visibility: visible;
        }

        #posPrintReceipt {
            position: absolute;
            left: 0;
            top: 0;
            width: 280px;
            padding: 8px;
            font-family: 'Courier New', Courier, monospace, sans-serif;
            font-size: 12px;
            color: #000000;
            background: #ffffff;
            display: block !important;
        }

        .pos-layout, header, nav, aside, .sidebar {
            display: none !important;
        }
    }

    .pos-print-only {
        display: none;
    }

    /* Consistent POS controls and a readable, separated order workspace. */
    .pos-page .food-card { border-color:#e9e9e5; }
    .pos-page .food-card img { object-fit:contain; }
    .pos-page .card-desc { line-height:1.45; }
    .pos-page .bilao-centered-card { padding:10px; text-align:left; }
    .pos-page .bilao-center-content { align-items:stretch; text-align:left; gap:10px; }
    .pos-page .bilao-title { font-size:1rem; }
    .pos-page .bilao-price { font-size:1.05rem; }
    .pos-page .bilao-size-btn.active { background:#f7c62f; border-color:#e8b720; color:#202020; box-shadow:none; }
    .pos-page .bilao-size-btn:hover:not(:disabled) { border-color:#c39a19; background:#fff7d9; color:#242424; }
    .pos-page .bilao-reservation-note { background:#fff9e7; padding:8px; border-radius:8px; line-height:1.4; }
    .pos-page .receipt-header-row { flex-wrap:wrap; gap:12px; }
    .pos-page .receipt-brand-left { width:100%; }
    .pos-page .brand-text-col { flex:1; flex-direction:row; align-items:center; justify-content:space-between; gap:8px; }
    .pos-page .brand-text-col h2 { font-size:17px; }
    .pos-page .order-pill { font-size:12px; padding:4px 7px; white-space:nowrap; }
    .pos-page .receipt-header-actions { width:100%; display:grid; grid-template-columns:1fr 1fr; }
    .pos-page .receipt-header-actions button { justify-content:center; font-size:13px; }
    .pos-page .receipt-header-row2 { flex-direction:column; align-items:stretch; gap:10px; margin-top:10px; }
    .pos-page #activeCashierBadgeWrap { width:100%; justify-content:space-between; min-width:0; }
    .pos-page #activeCashierBadge { display:flex; align-items:center; gap:6px; min-width:0; white-space:normal; text-align:left; }
    .pos-page #activeCashierName { overflow-wrap:anywhere; }
    .pos-page #activeCashierBadgeWrap button { flex-shrink:0; font-size:13px!important; text-decoration:none!important; padding:6px!important; color:#785b08!important; }
    .pos-page .receipt-header-row2-right { justify-content:space-between; }
    .pos-page .receipt-header-row2-right button { min-height:36px; font-size:13px; }
    .pos-page .receipt-form-top { margin-top:12px; padding-bottom:12px; gap:10px; }
    .pos-page .receipt-form-top select, .pos-page .receipt-form-top input { min-height:38px; font-size:14px; border-radius:8px; }
    .pos-page .receipt-panel { overflow-y:auto; }
    .pos-page .cart-items { min-height:120px; }
    .pos-page #cashierAuthModalOverlay input:focus { outline:2px solid #c39a19; outline-offset:2px; border-color:#c39a19!important; }
    .pos-page #cashierAuthModalOverlay label { font-size:14px!important; }
    .pos-page #btnCashierLogin:hover { background:#eebc20!important; }
    .pos-page .tab:focus-visible, .pos-page .bilao-size-btn:focus-visible, .pos-page .receipt-panel button:focus-visible { outline:2px solid #95720a; outline-offset:3px; }

    #closeShiftModalOverlay input:focus, #closeShiftModalOverlay textarea:focus { outline:2px solid #b48b12; outline-offset:2px; border-color:#b48b12!important; }
    #btnEndShift:hover:not(:disabled) { background:#eebc20!important; }
    #closeShiftModalOverlay button:focus-visible { outline:2px solid #95720a; outline-offset:3px; }

    #cashDrawerModalOverlay input:focus, #cashDrawerModalOverlay select:focus { outline:2px solid #b48b12; outline-offset:2px; border-color:#b48b12!important; }
    #drawerReasonSelect { min-height:44px; }
    #btnUnlockDrawer:hover:not(:disabled), #refreshOnlineOrdersBtn:hover:not(:disabled) { background:#eebc20!important; }
    .online-orders-modal { border-color:#e6dfd0; border-top:4px solid #f2c12e; background:#fffdf8; }
    .online-orders-modal-head { padding:20px 24px; flex-wrap:wrap; background:#ffffff; border-bottom-color:#ece6d8; }
    .online-orders-modal-title { font-size:22px; font-weight:800; color:#242424; }
    .online-orders-modal-subtitle { font-size:13.5px; line-height:1.45; color:#655c4c; }
    .online-orders-modal-actions { gap:8px; }
    .online-orders-modal-btn { min-height:38px; padding:0 14px; font-size:13.5px; border-radius:9px; border-color:#dfd8c8; background:#fffaf0; color:#29251f; }
    #refreshOnlineOrdersBtn { background:#f2c12e; color:#1f2328; border-color:#dfaf1e; }
    .online-orders-list { padding:16px 24px; }
    .online-order-card { background:#ffffff; border-color:#e6dfd0; border-radius:12px; padding:14px 16px; box-shadow:0 2px 8px rgba(0,0,0,.04); }
    .online-order-number { color:#1f2328; font-size:0.95rem; }
    .online-order-meta { color:#655c4c; font-size:0.78rem; }
    .online-order-schedule { display:flex; align-items:center; gap:7px; flex-wrap:wrap; margin:0 0 8px; padding:7px 10px; border-radius:8px; background:#fff8e1; border:1px solid #efd379; color:#5c4300; font-size:0.78rem; font-weight:700; }
    .online-order-schedule i { color:#8a6200; }
    .online-order-note-box { margin:0 0 8px; padding:6px 10px; border-radius:8px; background:#fcfbf7; border:1px solid #e6dfd0; color:#4b4639; font-size:0.77rem; }
    .online-order-cod-change { display:inline-flex; align-items:center; gap:5px; margin-top:6px; padding:4px 10px; border-radius:999px; background:#fff4cc; border:1px solid #e5c352; color:#5c4300; font-size:0.74rem; font-weight:700; }
    .online-order-cod-exact { display:inline-flex; align-items:center; gap:5px; margin-top:6px; padding:4px 10px; border-radius:999px; background:#dcfce7; border:1px solid #bbf7d0; color:#15803d; font-size:0.74rem; font-weight:700; }
    .pos-online-proof-link { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; background:#fff8e1; border:1px solid #e2c567; color:#594113; font-size:0.74rem; font-weight:700; text-decoration:none; transition:background .15s, border-color .15s; }
    .pos-online-proof-link:hover { background:#f2c12e; border-color:#d6a81f; color:#1f2328; }
    .pos-online-verify-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:34px; padding:6px 14px; border-radius:8px; background:#f2c12e; border:1px solid #dfaf1e; color:#1f2328; font:inherit; font-size:0.8rem; font-weight:800; cursor:pointer; transition:background .15s, border-color .15s; }
    .pos-online-verify-btn:hover { background:#e5b420; border-color:#c69814; }
    .pos-online-manage-btn { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:6px 14px; border-radius:8px; background:#fff8e1; border:1px solid #e2c567; color:#594113; font-size:0.8rem; font-weight:700; text-decoration:none; transition:background .15s, border-color .15s; }
    .pos-online-manage-btn:hover { background:#f2c12e; border-color:#d6a81f; color:#1f2328; }
    .online-orders-empty { padding:40px 16px; font-size:15px; line-height:1.6; color:#626262; }
    .online-orders-empty .online-empty-icon { display:flex; align-items:center; justify-content:center; width:60px; height:60px; margin:0 auto 16px; border-radius:16px; background:#fff4c9; color:#242424; font-size:28px; }
    .online-orders-empty strong { display:block; font-size:18px; color:#242424; margin-bottom:6px; }
    .online-orders-empty p { margin:0; }
    .online-orders-modal button:focus-visible, #cashDrawerModalOverlay button:focus-visible { outline:2px solid #95720a; outline-offset:3px; }
</style>

<div class="pos-layout">
    <section class="pos-menu-panel">
        <!-- Horizontal Categories Bar with Left/Right Scroller -->
        <div class="pos-products-header">
            <div class="pos-category-scroller-wrap">
                <button type="button" class="pos-category-nav-btn" id="catScrollLeftBtn" aria-label="Scroll categories left" title="Scroll left">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <div class="tabs" id="posCategoryTabs">
                    <button class="tab active" data-cat="all">All</button>
                    <?php foreach ($categories as $cat): ?>
                        <button class="tab" data-cat="<?= e($cat['id']) ?>"><?= e(['add-ons' => 'Add-ons', 'silog meals' => 'Silog'][strtolower($cat['category_name'])] ?? $cat['category_name']) ?></button>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="pos-category-nav-btn" id="catScrollRightBtn" aria-label="Scroll categories right" title="Scroll right">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>

        <div class="sub-tabs" id="silogSubFilter">
            <button type="button" class="sub-tab active" data-sub="all">All</button>
            <button type="button" class="sub-tab" data-sub="classic">Classic</button>
            <button type="button" class="sub-tab" data-sub="loaded">Loaded</button>
        </div>

        <div class="sub-tabs" id="bilaoSubFilter">
            <button type="button" class="sub-tab active" data-sub="all">All</button>
            <button type="button" class="sub-tab" data-sub="pasta">Spaghetti & Carbonara</button>
            <button type="button" class="sub-tab" data-sub="palabok_sotanghon">Palabok & Sotanghon</button>
            <button type="button" class="sub-tab" data-sub="bihon_canton">Bihon & Canton</button>
        </div>

        <div class="menu-grid" id="menuGrid">
            <?php foreach ($regular_items as $item): ?>
                <?php
                $price = $item['price'];
                if ($item['promo_price'] && $item['promo_start'] <= date('Y-m-d') && $item['promo_end'] >= date('Y-m-d')) {
                    $price = $item['promo_price'];
                }

                $subcat = '';
                if (in_array($item['item_name'], ['Set Meal A', 'Set Meal B', 'Set Meal C', 'Set Meal D', 'Set Meal E'])) {
                    $subcat = 'classic';
                } elseif (in_array($item['item_name'], ['Set Meal F', 'Set Meal G', 'Set Meal H', 'Set Meal I'])) {
                    $subcat = 'loaded';
                }

                $stock = max(0, (int)($item['stock'] ?? 0));
                $availability = $item['availability'] ?? 'Available';
                $isUnavailable = ($stock <= 0 || $availability !== 'Available');
                ?>

                <article class="food-card <?= $isUnavailable ? 'unavailable' : '' ?>"
                         data-cat="<?= e($item['category_id']) ?>"
                         data-subcat="<?= $subcat ?>"
                         data-id="<?= e($item['id']) ?>"
                         data-name="<?= e($item['item_name']) ?>"
                         data-price="<?= e($price) ?>"
                         data-stock="<?= e($stock) ?>"
                         data-availability="<?= e($availability) ?>">

                    <span class="stock-badge <?= ($stock <= 0 || $availability !== 'Available') ? 'out-of-stock' : ($stock <= 5 ? 'low-stock' : '') ?>">
                        <?= $availability !== 'Available' ? 'Unavailable' : ($stock <= 0 ? 'Out of Stock' : ($stock <= 5 ? 'Only ' . e($stock) . ' left' : e($stock) . ' pcs left')) ?>
                    </span>

                    <?php if ($item['image']): ?>
                        <img src="<?= e(menu_image_url($item['image'])) ?>" alt="<?= e($item['item_name']) ?>">
                    <?php else: ?>
                        <img src="<?= BASE_URL ?>/assets/img/food-placeholder.svg" alt="">
                    <?php endif; ?>

                    <div class="card-info">
                        <h3><?= e($item['item_name']) ?></h3>
                        <?php if (!empty($item['description'])): ?>
                            <p class="card-desc"><?= e($item['description']) ?></p>
                        <?php else: ?>
                            <p class="muted card-desc"><?= e($item['category_name']) ?></p>
                        <?php endif; ?>

                        <div class="card-footer-row">
                            <strong class="card-footer-price"><?= money($price) ?></strong>

                            <div class="qty-stepper" onclick="event.stopPropagation();">
                                <button type="button" class="btn-qty btn-card-minus" data-id="<?= e($item['id']) ?>">−</button>
                                <span class="qty-display" id="card-qty-<?= e($item['id']) ?>">0</span>
                                <button type="button" class="btn-qty btn-card-plus" data-id="<?= e($item['id']) ?>" <?= ($stock <= 0 || $availability !== 'Available') ? 'disabled' : '' ?>>+</button>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if ($bilaoCatId): ?>
                <?php foreach ($bilao_groups as $dish => $info): ?>
                    <?php
                    $default_size = !empty($info['eligible_sizes'])
                        ? $info['eligible_sizes'][0]
                        : (!empty($info['active_sizes']) ? $info['active_sizes'][0] : 'S');
                    $default_variant = $info['variants'][$default_size];
                    $init_id = $default_variant['id'];
                    $init_price = $default_variant['price'];
                    $init_name = $dish . ' (' . $default_size . ')';
                    $init_desc = $default_variant['desc'];
                    $json_variants = htmlspecialchars(json_encode($info['variants']), ENT_QUOTES, 'UTF-8');
                    $init_stock = max(0, (int)($default_variant['stock'] ?? 0));
                    $init_availability = !empty($default_variant['is_archived']) ? 'Archived' : ($default_variant['availability'] ?? 'Available');
                    $init_unavailable = ($init_stock <= 0 || $init_availability !== 'Available');
                    $tray_img_url = $info['tray_image_url'] ?: menu_image_url($info['tray_image'] ?? $info['image'] ?? '');
                    $bilao_img_url = $info['bilao_image_url'] ?: menu_image_url($info['bilao_image'] ?? $info['image'] ?? '');
                    $default_img_url = $bilao_img_url ?: ($tray_img_url ?: (BASE_URL . '/assets/img/food-placeholder.svg'));
                    ?>

                    <article class="food-card bilao-card bilao-centered-card <?= $init_unavailable ? 'unavailable' : '' ?>"
                             data-cat="<?= e($bilaoCatId) ?>"
                             data-subcat="<?= e($info['subcat']) ?>"
                             data-id="<?= e($init_id) ?>"
                             data-name="<?= e($init_name) ?>"
                             data-price="<?= e($init_price) ?>"
                             data-stock="<?= e($init_stock) ?>"
                             data-availability="<?= e($init_availability) ?>"
                             data-base="<?= e($dish) ?>"
                             data-size="<?= e($default_size) ?>"
                             data-packaging=""
                             data-tray-img="<?= e($tray_img_url) ?>"
                             data-bilao-img="<?= e($bilao_img_url) ?>"
                             data-variants='<?= $json_variants ?>'>

                        <span class="stock-badge <?= ($init_stock <= 0 || $init_availability !== 'Available') ? 'out-of-stock' : ($init_stock <= 5 ? 'low-stock' : '') ?>">
                            <?= $init_availability !== 'Available' ? 'Unavailable' : ($init_stock <= 0 ? 'Out of Stock' : ($init_stock <= 5 ? 'Only ' . e($init_stock) . ' left' : e($init_stock) . ' pcs left')) ?>
                        </span>

                        <img src="<?= e($default_img_url) ?>" alt="<?= e($dish) ?>">

                        <div class="bilao-center-content">
                            <h3 class="bilao-title"><?= e($dish) ?></h3>
                            <p class="bilao-desc"><?= e($init_desc) ?></p>

                            <div class="bilao-sizes-row size-group" onclick="event.stopPropagation();">
                                <?php foreach (['S', 'M', 'L', 'XL'] as $szBtn): ?>
                                    <?php $szArchived = !empty($info['variants'][$szBtn]['is_archived']); ?>
                                    <button type="button"
                                            class="bilao-size-btn size-btn <?= $szBtn === $default_size ? 'active' : '' ?>"
                                            data-size="<?= e($szBtn) ?>"
                                            title="<?= in_array($szBtn, ['M','L','XL']) ? 'Advance reservation required' : '' ?>"
                                            <?= $szArchived ? 'disabled hidden style="display:none;"' : '' ?>><?= e($szBtn) ?></button>
                                <?php endforeach; ?>
                            </div>

                            <div class="bilao-pkg-row" onclick="event.stopPropagation();">
                                <button type="button" class="bilao-pkg-btn pkg-btn" data-pkg="Tray">Tray</button>
                                <button type="button" class="bilao-pkg-btn pkg-btn" data-pkg="Bilao">Bilao</button>
                            </div>
                            <p class="bilao-pkg-hint">Select Tray or Bilao (required)</p>

                            <?php if (in_array($default_size, ['M','L','XL']) || !empty($info['variants']['M']) || !empty($info['variants']['L']) || !empty($info['variants']['XL'])): ?>
                            <p class="bilao-reservation-note" style="font-size:0.8rem;color:#343434;margin:3px 0 0;font-weight:500;">
                                <i class="bi bi-clock"></i> M / L / XL require advance reservation
                            </p>
                            <?php endif; ?>

                            <strong class="bilao-price"><?= money($init_price) ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <aside class="card receipt-panel" id="receiptPanel">
        <div class="receipt-brand">
            <!-- Row 1: Logo + Brand name + main action buttons -->
            <div class="receipt-header-row">
                <div class="receipt-brand-left">
                    <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="Kenji's Kitchen" class="brand-mark" style="width: 32px; height: 32px; border-radius: 8px; object-fit: cover;">
                    <div class="brand-text-col">
                        <h2>Kenji's Kitchen</h2>
                        <span class="order-pill"><i class="bi bi-receipt"></i> Order #<?= e($orderTicket) ?></span>
                    </div>
                </div>

                <div class="receipt-header-actions">
                    <button type="button" class="btn-online-orders" id="openDrawerModalBtn" onclick="openCashDrawerModal()" title="Open Cash Drawer (Security PIN required)" style="background:#0f172a; border-color:#0f172a; color:#fff;">
                        <i class="bi bi-safe2"></i> Drawer
                    </button>

                    <button type="button" class="btn-online-orders" id="closeShiftBtn" onclick="openCloseShiftModal()" title="End Shift" style="background:#fff; border-color:#d6d6d6; color:#242424;">
                        <i class="bi bi-box-arrow-right"></i> End Shift
                    </button>
                </div>
            </div>

            <!-- Row 2: Cashier badge + secondary action buttons -->
            <div class="receipt-header-row2">
                <div class="receipt-header-row2-left">
                    <div id="activeCashierBadgeWrap" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="badge" id="activeCashierBadge" style="background:#f5f5f3; color:#242424; font-size:13px; padding:8px 10px; border-radius:8px; font-weight:600;">
                            <i class="bi bi-person-badge"></i> <span id="activeCashierName">Checking...</span>
                        </span>
                        <button type="button" onclick="promptSwitchCashier()" title="Switch Cashier" style="background:none; border:none; color:#64748b; cursor:pointer; font-size:12px; padding:0; text-decoration:underline;">Switch</button>
                    </div>
                </div>

                <div class="receipt-header-row2-right">
                    <button type="button" class="pos-back-to-menu-btn" onclick="document.querySelector('.pos-menu-panel')?.scrollIntoView({behavior:'smooth',block:'start'})">
                        <i class="bi bi-arrow-up"></i> Menu
                    </button>

                    <button type="button" class="btn-online-orders" id="onlineOrdersBtn" title="View active online orders">
                        <i class="bi bi-phone"></i> Online Orders
                        <span id="onlineOrdersBadge" class="online-orders-badge">0</span>
                    </button>

                    <button type="button" class="btn-clear-cart" id="clearCartBtn" onclick="window.clearAllOrder()" title="Clear all items in cart">
                        <i class="bi bi-trash3"></i> Clear
                    </button>
                </div>
            </div>
        </div>

        <div class="receipt-form-top">
            <div class="field">
                <label for="orderType">Order Type</label>
                <select id="orderType">
                    <option value="">Select type</option>
                    <option value="DINE-IN">Dine-In</option>
                    <option value="TAKE-OUT">Take-Out</option>
                </select>
            </div>

            <div class="field">
                <label for="tableNo">Table No.</label>
                <input id="tableNo" type="text" inputmode="none" readonly maxlength="2" placeholder="1–10" data-keypad-type="table" aria-label="Table number">
            </div>
        </div>

        <div id="posCartStockBanner" class="pos-cart-stock-banner" role="status" aria-live="polite" hidden></div>
        <div class="cart-items" id="cartItems"></div>

        <div class="receipt-bottom-section">
            <div class="note-trigger-wrap" id="noteTriggerWrap">
                <button type="button" class="btn-add-note-link" id="btnAddNoteLink">
                    <i class="bi bi-plus-lg"></i> Add Note
                </button>
            </div>

            <div class="note-input-row" id="noteInputRow" style="display: none;">
                <input id="orderNoteInput" type="text" placeholder="Add note here..." maxlength="120">
                <button type="button" class="btn-done-note" id="btnDoneNote">Done</button>
                <button type="button" id="btnCancelNote">Cancel</button>
            </div>

            <div class="note-pill-display" id="notePillDisplay" style="display: none;">
                <span class="note-pill-text">
                    <i class="bi bi-chat-left-text"></i>
                    <strong id="notePillText"></strong>
                </span>

                <button type="button" class="note-pill-remove" id="btnRemoveNote" title="Remove note">
                    &times;
                </button>
            </div>

            <div class="totals">
                <div>
                    <span>Subtotal</span>
                    <strong id="subtotal">₱0.00</strong>
                </div>

                <!-- Automatic Senior Citizen & PWD Discount Selector -->
                <div class="pos-discount-quick-btns">
                    <button type="button" class="pos-disc-btn" id="btnDiscountSenior" onclick="toggleQuickDiscount('Senior')">
                        <i class="bi bi-person-heart"></i> Senior (20%)
                    </button>
                    <button type="button" class="pos-disc-btn" id="btnDiscountPwd" onclick="toggleQuickDiscount('PWD')">
                        <i class="bi bi-universal-access"></i> PWD (20%)
                    </button>
                </div>

                <div class="pos-disc-badge-row" id="posDiscBadgeRow">
                    <span class="badge-text" id="posDiscBadgeText">
                        <i class="bi bi-check-circle-fill"></i> Senior Citizen Applied
                    </span>
                    <button type="button" class="remove-btn" onclick="removeQuickDiscount()" title="Remove Discount">&times;</button>
                </div>

                <div class="discount-line">
                    <span>Discount</span>
                    <div class="discount-control">
                        <input
                            id="discount"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            value="0"
                            inputmode="decimal"
                            aria-label="Discount percentage"
                        >
                        <span>%</span>
                    </div>
                    <strong class="discount-amount" id="discountAmount">-₱0.00</strong>
                </div>

                <div>
                    <span>Tax</span>
                    <strong id="tax">Tax-Free</strong>
                </div>

                <div class="grand">
                    <span>Total</span>
                    <strong id="grandTotal">₱0.00</strong>
                </div>
            </div>

            <div class="receipt-cash-row">
                <div class="field" id="tenderCashWrap">
                    <label id="tenderLabel">Cash</label>
                    <input id="cash" type="text" inputmode="none" readonly placeholder="0.00" data-keypad-type="cash" aria-label="Cash amount">
                </div>

                <div class="field" id="tenderGcashWrap" style="display: none;">
                    <label>Ref #</label>
                    <input
                        id="gcashRef"
                        type="text"
                        placeholder="13-digit reference number"
                        maxlength="13"
                        inputmode="none"
                        autocomplete="off"
                        readonly
                        data-keypad-type="gcash"
                    >
                </div>

                <div class="change-display">
                    <span id="changeLabel">Change:</span>
                    <strong id="change">₱0.00</strong>
                </div>
            </div>

            <div id="tenderGcashAttestWrap" style="display: none; margin-bottom: 6px; padding: 6px 10px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.78rem; font-weight: 600; color: #1e3a8a; cursor: pointer; user-select: none; margin: 0;">
                    <input type="checkbox" id="gcashCashierAttested" style="width: 16px; height: 16px; accent-color: #2563eb; cursor: pointer;">
                    <span>Payment verified on merchant phone / POS terminal</span>
                </label>
            </div>

            <div class="payment-toggle-row">
                <button
                    type="button"
                    class="btn-pay-toggle"
                    id="btnMethodCash"
                    onclick="window.setPaymentMode('cash')"
                >
                    <i class="bi bi-cash-stack"></i> Cash
                </button>

                <button
                    type="button"
                    class="btn-pay-toggle"
                    id="btnMethodGcash"
                    onclick="window.setPaymentMode('gcash')"
                >
                    <i class="bi bi-phone"></i> GCash
                </button>
            </div>

            <div class="receipt-btn-group">
                <button class="btn" id="checkoutBtn">
                    <i class="bi bi-check-circle"></i>Checkout
                </button>

            </div>
        </div>
    </aside>
</div>

<div class="pos-mobile-order-bar" id="posMobileOrderBar" aria-label="Quick POS Order Summary">
    <div class="pos-mobile-order-meta">
        <small id="posMobileCartCount">0 items in order</small>
        <strong id="posMobileCartTotal">₱0.00</strong>
    </div>
    <button type="button" class="pos-mobile-order-cta" onclick="document.getElementById('receiptPanel')?.scrollIntoView({behavior:'smooth',block:'start'})">
        View Cart &amp; Checkout <i class="bi bi-arrow-down-circle"></i>
    </button>
</div>

<div class="pos-keypad-overlay" id="posKeypadOverlay" hidden>
    <div class="pos-keypad-modal" role="dialog" aria-modal="true" aria-labelledby="posKeypadTitle">
        <div class="pos-keypad-head">
            <strong id="posKeypadTitle">Enter Number</strong>
            <button type="button" class="pos-keypad-close" id="posKeypadClose" aria-label="Close">&times;</button>
        </div>
        <input class="pos-keypad-display" id="posKeypadDisplay" type="text" readonly aria-label="Keypad value">
        <div class="pos-keypad-error" id="posKeypadError" aria-live="polite"></div>
        <div class="pos-keypad-grid" id="posKeypadGrid">
            <button type="button" class="pos-keypad-key" data-key="1">1</button>
            <button type="button" class="pos-keypad-key" data-key="2">2</button>
            <button type="button" class="pos-keypad-key" data-key="3">3</button>
            <button type="button" class="pos-keypad-key" data-key="4">4</button>
            <button type="button" class="pos-keypad-key" data-key="5">5</button>
            <button type="button" class="pos-keypad-key" data-key="6">6</button>
            <button type="button" class="pos-keypad-key" data-key="7">7</button>
            <button type="button" class="pos-keypad-key" data-key="8">8</button>
            <button type="button" class="pos-keypad-key" data-key="9">9</button>
            <button type="button" class="pos-keypad-key action" data-action="clear">Clear</button>
            <button type="button" class="pos-keypad-key" data-key="0">0</button>
            <button type="button" class="pos-keypad-key action" data-action="backspace">⌫</button>
            <button type="button" class="pos-keypad-key action" data-key="." data-decimal="true" hidden>.</button>
            <button type="button" class="pos-keypad-key action" data-action="cancel">Cancel</button>
            <button type="button" class="pos-keypad-key primary" data-action="done">Done</button>
        </div>
    </div>
</div>

<div class="online-orders-overlay" id="onlineOrdersOverlay" hidden>
    <div class="online-orders-modal" role="dialog" aria-modal="true" aria-labelledby="onlineOrdersTitle">
        <div class="online-orders-modal-head">
            <div>
                <h3 class="online-orders-modal-title" id="onlineOrdersTitle">Online Orders</h3>
                <p class="online-orders-modal-subtitle">Active online orders from today</p>
            </div>
            <div class="online-orders-modal-actions">
                <button type="button" class="online-orders-modal-btn" id="refreshOnlineOrdersBtn">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <button type="button" class="online-orders-modal-btn" id="closeOnlineOrdersBtn">Close</button>
            </div>
        </div>
        <div class="kk-queue-toolbar" id="posOnlineToolbar" style="margin:12px 12px 0;">
            <div class="kk-queue-pills" id="posOnlineFilterPills" role="group" aria-label="Filter online orders">
                <button type="button" class="kk-queue-pill active" data-pos-online-filter="all">All</button>
                <button type="button" class="kk-queue-pill" data-pos-online-filter="delivery">Delivery</button>
                <button type="button" class="kk-queue-pill" data-pos-online-filter="pickup">Pickup</button>
                <button type="button" class="kk-queue-pill" data-pos-online-filter="pending">Pending</button>
                <button type="button" class="kk-queue-pill" data-pos-online-filter="preparing">Preparing</button>
                <button type="button" class="kk-queue-pill" data-pos-online-filter="ready">Ready</button>
            </div>
            <label class="kk-queue-sort-wrap">
                <span>Prioritize</span>
                <select class="kk-queue-sort" id="posOnlineSortSelect" aria-label="Prioritize online orders">
                    <option value="newest">Newest first</option>
                    <option value="fifo">First in, first out</option>
                    <option value="pickup">Scheduled pickup time</option>
                    <option value="fewest">Fewest items first</option>
                </select>
            </label>
        </div>
        <div class="online-orders-list" id="onlineOrdersList">
            <div class="online-orders-empty">Loading online orders...</div>
        </div>
    </div>
</div>

<!-- Senior Citizen / PWD Verification Modal -->
<div class="online-orders-overlay" id="seniorPwdModalOverlay" hidden style="background:rgba(15,23,42,0.7); z-index:99999;">
    <div style="background:#ffffff; border-radius:18px; width:100%; max-width:420px; padding:28px 24px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); position:relative; margin:auto;">
        <button type="button" onclick="closeSeniorPwdModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.4rem; color:#64748b; cursor:pointer; line-height:1;">&times;</button>
        
        <div style="text-align:center; margin-bottom:18px;">
            <div id="spModalIconWrap" style="width:54px; height:54px; background:#fef3c7; color:#d97706; border-radius:16px; display:inline-flex; align-items:center; justify-content:center; font-size:1.6rem; margin-bottom:10px;">
                <i class="bi bi-person-badge-fill"></i>
            </div>
            <h3 id="spModalTitle" style="margin:0 0 4px; font-size:1.25rem; font-weight:800; color:#0f172a;">Senior Citizen Verification</h3>
            <p style="margin:0; font-size:0.83rem; color:#64748b;">Verify the customer's ID under R.A. 9994 / 10754 (20% Discount).</p>
        </div>

        <form id="seniorPwdForm" onsubmit="event.preventDefault(); confirmSeniorPwdDiscount();" style="text-align:left;">
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#334155; margin-bottom:5px;">
                    Customer Full Name <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" id="spCustomerName" placeholder="e.g. Juanita Dela Cruz" required
                    style="width:100%; font-size:0.95rem; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-weight:600; color:#0f172a;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#334155; margin-bottom:5px;">
                    <span id="spIdLabel">Senior Citizen ID / OSCA #</span> <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" id="spIdNumber" placeholder="e.g. OSCA-2024-12345" required
                    style="width:100%; font-size:0.95rem; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-weight:600; color:#0f172a;">
            </div>

            <div style="margin-bottom:18px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:0.78rem; color:#475569;">
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px; font-weight:700; color:#0f172a;">
                    <i class="bi bi-info-circle-fill" style="color:#0284c7;"></i> Discount Eligibility Note:
                </div>
                <div>Per BIR rules, 20% discount applies to individual personal consumption meals. Bilao catering favorites are bulk-sized and non-discountable.</div>
            </div>

            <div id="spModalErrorMsg" style="display:none; color:#dc2626; font-size:0.82rem; font-weight:700; margin-bottom:14px; background:#fee2e2; padding:8px 12px; border-radius:8px;"></div>

            <div style="display:flex; gap:10px;">
                <button type="button" onclick="closeSeniorPwdModal()" style="flex:1; padding:10px 14px; border:1px solid #cbd5e1; background:#f8fafc; color:#475569; font-weight:700; border-radius:10px; cursor:pointer;">Cancel</button>
                <button type="submit" id="btnConfirmSpDiscount" style="flex:1.5; padding:10px 14px; border:none; background:#F2C12E; color:#232323; font-weight:800; border-radius:10px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                    <i class="bi bi-check2-circle"></i> Apply Discount
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Close Shift Modal -->
<div class="online-orders-overlay" id="closeShiftModalOverlay" hidden style="background:rgba(20,20,20,0.65); z-index:99999;">
    <div style="background:#fff; border-radius:18px; width:100%; max-width:440px; max-height:90vh; overflow-y:auto; padding:28px 24px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); position:relative; margin:auto;">
        <button type="button" aria-label="Close End Shift" onclick="closeShiftModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.4rem; color:#64748b; cursor:pointer; line-height:1;">&times;</button>
        <div style="text-align:center; margin-bottom:18px;">
            <div style="width:52px; height:52px; background:#f7c62f; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 12px;">
                <i class="bi bi-box-arrow-right" style="font-size:1.5rem; color:#242424;"></i>
            </div>
            <h3 style="margin:0 0 6px; font-size:1.5rem; font-weight:800; color:#0f172a;">End Shift</h3>
            <p style="margin:0; font-size:0.9rem; line-height:1.5; color:#64748b;">Enter the actual cash counted in the drawer to close your shift.</p>
        </div>
        <div id="shiftSummaryBox" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:0.83rem; color:#334155; display:none;">
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Opening Cash</span><span id="ss_opening">—</span></div>
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Cash Sales</span><span id="ss_cash_sales">—</span></div>
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>GCash Sales</span><span id="ss_gcash_sales">—</span></div>
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Cash Refunds Out</span><span id="ss_cash_refunds" style="color:#dc2626;">—</span></div>
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Total Sales</span><span id="ss_total_sales" style="font-weight:700;">—</span></div>
            <div style="display:flex; justify-content:space-between; border-top:1px solid #e2e8f0; padding-top:6px; margin-top:6px;"><span>Expected Cash</span><span id="ss_expected" style="font-weight:700; color:#059669;">—</span></div>
        </div>
        <div style="margin-bottom:14px;">
            <label for="closingCashInput" style="font-size:0.9rem; font-weight:700; color:#374151; display:block; margin-bottom:6px;">Actual Cash Counted (₱)</label>
            <input type="number" id="closingCashInput" min="0" step="0.01" placeholder="0.00"
                style="width:100%; font-size:1.4rem; text-align:center; padding:10px 14px; border:2px solid #cbd5e1; border-radius:10px; font-weight:700;" autocomplete="off">
        </div>
        <div style="margin-bottom:14px;">
            <label for="shiftRemarksInput" style="font-size:0.9rem; font-weight:700; color:#374151; display:block; margin-bottom:6px;">Remarks / Notes <span id="remarksRequired" style="color:#dc2626; display:none;">* Required (variance too large)</span></label>
            <textarea id="shiftRemarksInput" rows="2" placeholder="Optional notes about this shift..."
                style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem; resize:vertical;"></textarea>
        </div>
        <div id="shiftCloseErrorMsg" style="display:none; color:#dc2626; font-size:0.9rem; font-weight:700; margin-bottom:12px; background:#fee2e2; padding:8px 12px; border-radius:8px;"></div>
        <div id="shiftCloseSuccessMsg" style="display:none; color:#059669; font-size:0.9rem; line-height:1.5; font-weight:700; margin-bottom:12px; background:#d1fae5; padding:8px 12px; border-radius:8px; text-align:center;"></div>
        <div style="display:flex; gap:10px;">
            <button type="button" onclick="closeShiftModal()" style="flex:1; padding:10px 14px; border:1px solid #cbd5e1; background:#f8fafc; color:#475569; font-weight:700; border-radius:10px; cursor:pointer;">Cancel</button>
            <button type="button" id="btnEndShift" onclick="submitEndShift()" style="flex:1.4; padding:10px 14px; border:none; background:#f7c62f; color:#242424; font-weight:700; border-radius:10px; cursor:pointer;">
                <i class="bi bi-box-arrow-right"></i> End Shift
            </button>
        </div>
    </div>
</div>

<!-- Open Shift Modal -->
<div class="online-orders-overlay" id="openShiftModalOverlay" hidden role="dialog" aria-modal="true" aria-labelledby="openShiftTitle" aria-describedby="openShiftHelp">
    <div class="pos-shift-card">
        <div class="pos-shift-heading">
            <span class="pos-shift-icon" aria-hidden="true"><i class="bi bi-cash-stack"></i></span>
            <div><span class="pos-shift-brand">Kenji’s Kitchen · POS</span><h3 id="openShiftTitle">Open shift</h3></div>
        </div>
        <p class="pos-shift-cashier"><i class="bi bi-person" aria-hidden="true"></i> <strong id="openShiftCashierName">Cashier</strong></p>
        <label class="pos-shift-label" for="openingCashInput">Opening cash</label>
        <p id="openShiftHelp">Enter the cash in your drawer before taking orders.</p>
        <div class="pos-shift-amount">
            <span aria-hidden="true">₱</span>
            <input type="number" id="openingCashInput" min="0" step="0.01" placeholder="0.00" value="" inputmode="decimal" autocomplete="off" aria-label="Opening cash in pesos" aria-describedby="openShiftHelp openShiftErrorMsg"
                onkeydown="if(event.key==='Enter' && !event.isComposing) { event.preventDefault(); submitOpenShift(); }">
        </div>
        <div id="openShiftErrorMsg" role="alert" style="display:none"></div>
        <div class="pos-shift-actions">
            <button type="button" id="btnOpenShift" onclick="submitOpenShift()" class="pos-shift-start" style="width:100%;"><i class="bi bi-play-circle-fill" aria-hidden="true"></i> Start shift</button>
        </div>
    </div>
</div>

<!-- Cash Drawer Access Modal -->
<div class="online-orders-overlay" id="cashDrawerModalOverlay" hidden style="background:rgba(20,20,20,0.65); z-index:99999;">
    <div style="background:#fff; border-radius:18px; width:100%; max-width:440px; max-height:90vh; overflow-y:auto; padding:26px 24px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); text-align:center; position:relative; margin:auto;">
        <button type="button" aria-label="Close Cash Drawer" onclick="closeCashDrawerModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.4rem; color:#64748b; cursor:pointer; line-height:1;">&times;</button>
        
        <div style="width:56px; height:56px; background:#f7c62f; color:#242424; border-radius:16px; display:inline-flex; align-items:center; justify-content:center; font-size:1.6rem; margin-bottom:12px;">
            <i class="bi bi-safe2-fill"></i>
        </div>
        
        <h3 style="margin:0 0 6px; font-size:1.5rem; font-weight:800; color:#0f172a;">Open Cash Drawer</h3>
        <p style="margin:0 0 18px; font-size:0.9rem; line-height:1.5; color:#64748b;"><?= role_key() === 'admin' ? 'Select a reason to authorize and record drawer access.' : 'Re-enter your cashier PIN to record drawer access. Physical drawer control requires a connected device.' ?></p>
        
        <div id="drawerPinWrap" style="margin-bottom:16px; text-align:left; <?= role_key() === 'admin' ? 'display:none;' : '' ?>">
            <label for="drawerPinInput" style="display:block; font-size:0.9rem; font-weight:700; color:#475569; margin-bottom:6px;">Security PIN</label>
            <input type="password" id="drawerPinInput" maxlength="4" placeholder="••••" style="width:100%; font-size:1.6rem; text-align:center; letter-spacing:8px; padding:10px 14px; border:2px solid #cbd5e1; border-radius:10px; font-weight:800;" autocomplete="off" onkeydown="if(event.key==='Enter') submitCashDrawerUnlock()">
        </div>

        <div style="margin-bottom:18px; text-align:left;">
            <label for="drawerReasonSelect" style="display:block; font-size:0.9rem; font-weight:700; color:#475569; margin-bottom:6px;">Reason for Opening</label>
            <select id="drawerReasonSelect" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.9rem; line-height:1.5; font-weight:600; color:#1e293b;">
                <option value="Customer Change / Panukli Refill">Customer Change / Panukli Refill</option>
                <option value="Shift Float Deposit (₱4,000)">Shift Float Deposit (₱4,000)</option>
                <option value="Auditing / Cash Count">Auditing / Cash Count</option>
                <option value="Manual Drawer Check">Manual Drawer Check</option>
            </select>
        </div>

        <div id="drawerErrorMsg" style="display:none; color:#dc2626; font-size:0.82rem; font-weight:700; margin-bottom:14px; background:#fee2e2; padding:8px 12px; border-radius:8px;"></div>
        <div id="drawerSuccessMsg" style="display:none; color:#059669; font-size:0.9rem; line-height:1.5; font-weight:700; margin-bottom:14px; background:#d1fae5; padding:8px 12px; border-radius:8px;"></div>

        <div style="display:flex; gap:10px;">
            <button type="button" onclick="closeCashDrawerModal()" style="flex:1; padding:10px 14px; border:1px solid #cbd5e1; background:#f8fafc; color:#475569; font-weight:700; border-radius:10px; cursor:pointer;">Cancel</button>
            <button type="button" id="btnUnlockDrawer" onclick="submitCashDrawerUnlock()" style="flex:1.4; padding:10px 14px; border:none; background:#f7c62f; color:#242424; font-weight:700; border-radius:10px; cursor:pointer;">
                <i class="bi bi-unlock-fill"></i> Unlock Drawer
            </button>
        </div>
    </div>
</div>
</div>

<!-- 1. Automatic Cashier Authentication Modal -->
<div id="cashierAuthModalOverlay" style="display:<?= (role_key() === 'admin' || !empty($_SESSION['pos_active_cashier'])) ? 'none' : 'flex' ?>; position:fixed; inset:0; background:rgba(20,20,20,0.65); z-index:999999; backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#ffffff; border-radius:20px; width:100%; max-width:420px; padding:32px 28px; box-shadow:0 25px 60px -15px rgba(0,0,0,0.5); text-align:center; position:relative; border:1px solid #e2e8f0;">
        <?php if (role_key() === 'admin'): ?>
        <button type="button" onclick="hideCashierAuthModal()" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.6rem; color:#94a3b8; cursor:pointer; line-height:1; width:32px; height:32px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; transition:background 0.2s, color 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.color='#0f172a';" onmouseout="this.style.background='none'; this.style.color='#94a3b8';" title="Close">&times;</button>
        <?php endif; ?>
        <div style="width:64px; height:64px; background:#f7c62f; color:#242424; border-radius:18px; display:inline-flex; align-items:center; justify-content:center; font-size:2rem; margin-bottom:16px; box-shadow:none;">
            <i class="bi bi-person-badge-fill"></i>
        </div>

        <h3 style="margin:0 0 6px; font-size:1.5rem; font-weight:800; color:#0f172a;">Cashier Sign In</h3>
        <p style="margin:0 0 22px; font-size:0.88rem; color:#64748b;">Sign in to start taking orders at Kenji's Kitchen.</p>

        <form id="cashierAuthForm" onsubmit="event.preventDefault(); submitCashierLogin();" style="text-align:left;">
            <div style="margin-bottom:16px;">
                <label for="cashierUsernameInput" style="display:block; font-size:0.82rem; font-weight:700; color:#334155; margin-bottom:6px;">Cashier Username</label>
                <div style="position:relative;">
                    <i class="bi bi-person" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:1.1rem;"></i>
                    <input type="text" id="cashierUsernameInput" placeholder="e.g. C1 or C2" required style="width:100%; font-size:1rem; padding:11px 14px 11px 40px; border:2px solid #cbd5e1; border-radius:10px; font-weight:600; color:#0f172a; " autocomplete="username" autofocus>
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <label for="cashierPinInput" style="display:block; font-size:0.82rem; font-weight:700; color:#334155; margin-bottom:6px;">4-Digit PIN</label>
                <div style="position:relative;">
                    <i class="bi bi-key" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:1.1rem;"></i>
                    <input type="password" id="cashierPinInput" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required style="width:100%; font-size:1.4rem; padding:9px 14px 9px 40px; border:2px solid #cbd5e1; border-radius:10px; font-weight:800; letter-spacing:6px; color:#0f172a;" autocomplete="current-password">
                </div>
            </div>

            <div id="cashierAuthErrorMsg" style="display:none; color:#dc2626; font-size:0.84rem; font-weight:700; margin-bottom:16px; background:#fee2e2; padding:10px 14px; border-radius:10px;"></div>
            <div id="cashierAuthSuccessMsg" style="display:none; color:#059669; font-size:0.86rem; font-weight:700; margin-bottom:16px; background:#d1fae5; padding:10px 14px; border-radius:10px;"></div>

            <button type="submit" id="btnCashierLogin" style="width:100%; padding:12px 18px; border:none; background:#f7c62f; color:#242424; font-size:1rem; font-weight:800; border-radius:12px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:none; transition:all 0.2s;">
                <i class="bi bi-shield-check"></i> Verify & Access POS
            </button>
        </form>
    </div>
</div>

<div id="posPrintReceipt" class="pos-print-only"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const silogSubFilter = document.getElementById('silogSubFilter');
    const bilaoSubFilter = document.getElementById('bilaoSubFilter');
    const catButtons = document.querySelectorAll('.tabs .tab');
    const foodCards = document.querySelectorAll('.food-card');

    const silogCatId = '<?= $silogCatId ?>';
    const bilaoCatId = '<?= $bilaoCatId ?>';

    function updateSubFilterVisibility() {
        const activeCatBtn = document.querySelector('.tabs .tab.active');
        const currentCat = activeCatBtn ? activeCatBtn.dataset.cat : 'all';

        if (currentCat === silogCatId) {
            silogSubFilter.style.display = 'flex';
            bilaoSubFilter.style.display = 'none';
        } else if (currentCat === bilaoCatId) {
            silogSubFilter.style.display = 'none';
            bilaoSubFilter.style.display = 'flex';
        } else {
            silogSubFilter.style.display = 'none';
            bilaoSubFilter.style.display = 'none';
        }

        applySubFilters(currentCat);
    }

    function applySubFilters(currentCat) {
        if (currentCat === silogCatId) {
            const activeSub = silogSubFilter.querySelector('.sub-tab.active')?.dataset.sub || 'all';

            foodCards.forEach(card => {
                if (card.dataset.cat === silogCatId) {
                    card.style.display =
                        (activeSub === 'all' || card.dataset.subcat === activeSub)
                            ? ''
                            : 'none';
                }
            });
        } else if (currentCat === bilaoCatId) {
            const activeSub = bilaoSubFilter.querySelector('.sub-tab.active')?.dataset.sub || 'all';

            foodCards.forEach(card => {
                if (card.dataset.cat === bilaoCatId) {
                    card.style.display =
                        (activeSub === 'all' || card.dataset.subcat === activeSub)
                            ? ''
                            : 'none';
                }
            });
        }
    }

    catButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            silogSubFilter
                .querySelectorAll('.sub-tab')
                .forEach(s => s.classList.remove('active'));

            silogSubFilter
                .querySelector('[data-sub="all"]')
                ?.classList.add('active');

            bilaoSubFilter
                .querySelectorAll('.sub-tab')
                .forEach(s => s.classList.remove('active'));

            bilaoSubFilter
                .querySelector('[data-sub="all"]')
                ?.classList.add('active');

            setTimeout(updateSubFilterVisibility, 20);
        });
    });

    silogSubFilter.querySelectorAll('.sub-tab').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();

            silogSubFilter
                .querySelectorAll('.sub-tab')
                .forEach(s => s.classList.remove('active'));

            this.classList.add('active');

            applySubFilters(silogCatId);
        });
    });

    bilaoSubFilter.querySelectorAll('.sub-tab').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();

            bilaoSubFilter
                .querySelectorAll('.sub-tab')
                .forEach(s => s.classList.remove('active'));

            this.classList.add('active');

            applySubFilters(bilaoCatId);
        });
    });

    document.querySelectorAll('.bilao-card').forEach(card => {
        const baseName = card.dataset.base;
        const priceTag = card.querySelector('.bilao-price');
        const descTag = card.querySelector('.bilao-desc');
        const imgTag = card.querySelector('img');
        const pkgRow = card.querySelector('.bilao-pkg-row');
        const pkgHint = card.querySelector('.bilao-pkg-hint');

        function updateCardState() {
            const activeSize =
                card.querySelector('.size-btn.active')?.dataset.size || 'S';
            const activePkg =
                card.querySelector('.pkg-btn.active')?.dataset.pkg || '';

            const variants = JSON.parse(card.dataset.variants || '{}');
            const v = variants[activeSize];

            if (v) {
                const isArchived = Boolean(v.is_archived);
                const stock = isArchived ? 0 : Math.max(0, Number(v.stock ?? 0));
                const availability = isArchived ? 'Archived' : (v.availability || 'Available');
                const unavailable = isArchived || availability !== 'Available' || stock <= 0;

                card.dataset.id = v.id;
                card.dataset.price = v.price;
                card.dataset.size = activeSize;
                card.dataset.packaging = activePkg;
                card.dataset.name = activePkg ? `${baseName} ${activePkg} (${activeSize})` : `${baseName} (${activeSize})`;
                card.dataset.stock = String(Math.floor(stock));
                card.dataset.availability = availability;
                card.classList.toggle('unavailable', unavailable);

                if (imgTag) {
                    const nextImg = activePkg === 'Tray'
                        ? (card.dataset.trayImg || card.dataset.bilaoImg)
                        : (card.dataset.bilaoImg || card.dataset.trayImg);
                    if (nextImg) imgTag.src = nextImg;
                }

                if (pkgHint) {
                    if (activePkg) {
                        pkgHint.textContent = `Packaging: ${activePkg}`;
                        pkgHint.classList.remove('is-error');
                        if (pkgRow) pkgRow.classList.remove('pkg-needs-selection');
                    } else {
                        pkgHint.textContent = 'Select Tray or Bilao (required)';
                    }
                }

                const stockBadge = card.querySelector('.stock-badge');
                if (stockBadge) {
                    stockBadge.classList.remove('low-stock', 'out-of-stock');

                    if (availability !== 'Available') {
                        stockBadge.classList.add('out-of-stock');
                        stockBadge.textContent = 'Unavailable';
                    } else if (stock <= 0) {
                        stockBadge.classList.add('out-of-stock');
                        stockBadge.textContent = 'Out of Stock';
                    } else {
                        if (stock <= 5) {
                            stockBadge.classList.add('low-stock');
                        }
                        stockBadge.textContent = stock <= 5 ? `Only ${Math.floor(stock)} left` : `${Math.floor(stock)} pcs left`;
                    }
                }

                if (descTag) {
                    descTag.textContent = v.desc;
                }

                if (priceTag) {
                    priceTag.textContent =
                        '₱' +
                        parseFloat(v.price).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                }
            }
        }

        card.querySelectorAll('.size-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();

                card
                    .querySelectorAll('.size-btn')
                    .forEach(b => b.classList.remove('active'));

                this.classList.add('active');
                updateCardState();
                if (typeof updateMenuCardControls === 'function') {
                    updateMenuCardControls();
                }
            });
        });

        card.querySelectorAll('.pkg-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();

                card
                    .querySelectorAll('.pkg-btn')
                    .forEach(b => b.classList.remove('active'));

                this.classList.add('active');
                updateCardState();
            });
        });

        updateCardState();
    });

    updateSubFilterVisibility();
});

function openCashDrawerModal() {
    var overlay = document.getElementById('cashDrawerModalOverlay');
    var pinInput = document.getElementById('drawerPinInput');
    var err = document.getElementById('drawerErrorMsg');
    var succ = document.getElementById('drawerSuccessMsg');
    if (err) err.style.display = 'none';
    if (succ) succ.style.display = 'none';
    if (pinInput) pinInput.value = '';
    if (overlay) {
        overlay.removeAttribute('hidden');
        overlay.style.display = 'flex';
    }
    setTimeout(function() {
        if (pinInput) pinInput.focus();
    }, 100);
}

function closeCashDrawerModal() {
    var overlay = document.getElementById('cashDrawerModalOverlay');
    if (overlay) {
        overlay.setAttribute('hidden', '');
        overlay.style.display = 'none';
    }
}

async function submitCashDrawerUnlock() {
    var pinInput = document.getElementById('drawerPinInput');
    var reasonSelect = document.getElementById('drawerReasonSelect');
    var err = document.getElementById('drawerErrorMsg');
    var succ = document.getElementById('drawerSuccessMsg');
    var btn = document.getElementById('btnUnlockDrawer');

    var pin = (pinInput ? pinInput.value.trim() : '');
    var reason = (reasonSelect ? reasonSelect.value : '');
    var isAdminActive = currentActiveCashier && String(currentActiveCashier.role || currentActiveCashier.role_name || '').toLowerCase() === 'admin';

    if (!pin && !isAdminActive) {
        if (err) { err.textContent = 'Please enter a 4-digit PIN.'; err.style.display = 'block'; }
        if (succ) succ.style.display = 'none';
        return;
    }

    if (btn) btn.disabled = true;

    try {
        var res = await fetch('<?= BASE_URL ?>/modules/pos/drawer_api.php?action=open_drawer', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': KK_CSRF_TOKEN
            },
            body: JSON.stringify({ pin: pin, reason: reason })
        });
        var data = await res.json();
        if (data.ok) {
            if (err) err.style.display = 'none';
            if (succ) {
                succ.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + data.msg + ' (' + data.pin_role + ')';
                succ.style.display = 'block';
            }
            if (pinInput) pinInput.value = '';
            setTimeout(function() {
                closeCashDrawerModal();
            }, 1200);
        } else {
            if (succ) succ.style.display = 'none';
            if (err) {
                err.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> ' + (data.msg || 'Invalid PIN.');
                err.style.display = 'block';
            }
            if (pinInput) { pinInput.value = ''; pinInput.focus(); }
        }
    } catch (e) {
        if (err) {
            err.textContent = 'Network or server error while contacting drawer.';
            err.style.display = 'block';
        }
    } finally {
        if (btn) btn.disabled = false;
    }
}

// -------------------------------------------------------------
// POS Cashier Automatic Authentication Flow
// -------------------------------------------------------------
let currentActiveCashier = null;

async function checkPosCashierAuth() {
    try {
        const res = await fetch('<?= BASE_URL ?>/modules/pos/cashier_auth_api.php?action=current');
        const data = await res.json();
        if (data.ok && data.authenticated && data.cashier) {
            setAuthenticatedCashier(data.cashier);
        } else {
            showCashierAuthModal();
        }
    } catch (e) {
        showCashierAuthModal();
    }
}

function showCashierAuthModal() {
    const overlay = document.getElementById('cashierAuthModalOverlay');
    const uInput = document.getElementById('cashierUsernameInput');
    const pInput = document.getElementById('cashierPinInput');
    const err = document.getElementById('cashierAuthErrorMsg');
    const succ = document.getElementById('cashierAuthSuccessMsg');
    if (err) err.style.display = 'none';
    if (succ) succ.style.display = 'none';
    if (uInput) uInput.value = '';
    if (pInput) pInput.value = '';

    if (overlay) {
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    setTimeout(() => {
        if (uInput) uInput.focus();
    }, 150);
}

function hideCashierAuthModal() {
    const overlay = document.getElementById('cashierAuthModalOverlay');
    if (overlay) {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function setAuthenticatedCashier(cashier) {
    currentActiveCashier = cashier;
    const nameEl = document.getElementById('activeCashierName');
    if (nameEl) {
        nameEl.textContent = cashier.full_name || cashier.username;
        nameEl.title = 'Signed in as ' + cashier.username;
    }
    const pinWrap = document.getElementById('drawerPinWrap');
    const isAdminActive = String(cashier.role || cashier.role_name || '').toLowerCase() === 'admin';
    if (pinWrap) {
        pinWrap.style.display = isAdminActive ? 'none' : 'block';
    }
    hideCashierAuthModal();
    loadShiftSummary();
    // After login, check if shift is already open — if not, prompt Cashiers to open one
    if (!isAdminActive) {
        checkAndPromptShift(cashier);
    }
}

async function checkAndPromptShift(cashier) {
    try {
        const res = await fetch('<?= BASE_URL ?>/modules/pos/shift_api.php?action=status&terminal=Main+POS');
        const data = await res.json();
        if (data.ok && !data.has_open_shift) {
            // No open shift — show the Open Shift modal
            const nameEl = document.getElementById('openShiftCashierName');
            if (nameEl) nameEl.textContent = cashier.full_name || cashier.username;
            document.getElementById('openShiftModalOverlay').hidden = false;
            setTimeout(() => { const inp = document.getElementById('openingCashInput'); if (inp) inp.focus(); }, 100);
        }
        // If shift already open, do nothing
    } catch (_) {}
}

async function submitOpenShift() {
    if (document.getElementById('btnOpenShift').disabled) return;
    const rawCash = document.getElementById('openingCashInput').value.trim();
    const errBox = document.getElementById('openShiftErrorMsg');
    if (rawCash === '' || !Number.isFinite(Number(rawCash)) || Number(rawCash) < 0) {
        errBox.textContent = 'Enter your counted opening cash amount (0.00 or higher).';
        errBox.style.display = 'block';
        document.getElementById('openingCashInput')?.focus();
        return;
    }
    const openingCash = Number(rawCash);
    const btn = document.getElementById('btnOpenShift');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Starting...';
    errBox.style.display = 'none';
    try {
        const res = await fetch('<?= BASE_URL ?>/modules/pos/shift_api.php?action=start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': KK_CSRF_TOKEN },
            body: JSON.stringify({ opening_cash: openingCash, terminal: 'Main POS' })
        });
        const data = await res.json();
        if (data.ok) {
            document.getElementById('openShiftModalOverlay').hidden = true;
            loadShiftSummary();
        } else {
            // If shift already exists for this user/terminal, just close
            if (data.msg && (data.msg.includes('already') || data.msg.includes('open shift'))) {
                document.getElementById('openShiftModalOverlay').hidden = true;
            } else {
                errBox.textContent = data.msg || 'Failed to start shift.';
                errBox.style.display = 'block';
            }
        }
    } catch (e) {
        errBox.textContent = 'Network error. Please try again.';
        errBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle-fill" aria-hidden="true"></i> Start shift';
    }
}

async function submitCashierLogin() {
    const uInput = document.getElementById('cashierUsernameInput');
    const pInput = document.getElementById('cashierPinInput');
    const err = document.getElementById('cashierAuthErrorMsg');
    const succ = document.getElementById('cashierAuthSuccessMsg');
    const btn = document.getElementById('btnCashierLogin');

    const username = uInput ? uInput.value.trim() : '';
    const pin = pInput ? pInput.value.trim() : '';

    if (!username || !pin) {
        if (err) {
            err.textContent = 'Please enter both Username and 4-digit PIN.';
            err.style.display = 'block';
        }
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Verifying...';
    }

    try {
        const res = await fetch('<?= BASE_URL ?>/modules/pos/cashier_auth_api.php?action=login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': KK_CSRF_TOKEN
            },
            body: JSON.stringify({ username, pin })
        });
        const data = await res.json();
        if (data.ok && data.cashier) {
            if (err) err.style.display = 'none';
            if (succ) {
                succ.innerHTML = '<i class="bi bi-check-circle-fill"></i> Welcome, ' + data.cashier.full_name + '! Access granted.';
                succ.style.display = 'block';
            }
            setTimeout(() => {
                setAuthenticatedCashier(data.cashier);
            }, 800);
        } else {
            if (succ) succ.style.display = 'none';
            if (err) {
                err.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> ' + (data.msg || 'Authentication failed.');
                err.style.display = 'block';
            }
            if (pInput) { pInput.value = ''; pInput.focus(); }
        }
    } catch (e) {
        if (err) {
            err.textContent = 'Network or server error verifying cashier credentials.';
            err.style.display = 'block';
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-shield-check"></i> Verify & Access POS';
        }
    }
}

async function promptSwitchCashier() {
    try {
        await fetch('<?= BASE_URL ?>/modules/pos/cashier_auth_api.php?action=logout', { method: 'POST', headers: { 'X-CSRF-Token': KK_CSRF_TOKEN } });
    } catch (_) {}
    showCashierAuthModal();
}

// Automatically initiate cashier check when entering POS
document.addEventListener('DOMContentLoaded', () => {
    checkPosCashierAuth();
});
var KK_CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* ===== CLOSE SHIFT MODAL ===== */
function openCloseShiftModal() {
    document.getElementById('closeShiftModalOverlay').hidden = false;
    document.getElementById('shiftCloseErrorMsg').style.display = 'none';
    document.getElementById('shiftCloseSuccessMsg').style.display = 'none';
    document.getElementById('closingCashInput').value = '';
    document.getElementById('shiftRemarksInput').value = '';
    loadShiftSummary();
}
function closeShiftModal() {
    document.getElementById('closeShiftModalOverlay').hidden = true;
}
async function loadShiftSummary() {
    if (!currentActiveCashier) return;
    try {
        const res = await fetch('<?= BASE_URL ?>/modules/pos/shift_api.php?action=status&terminal=Main+POS');
        const data = await res.json();
        const box = document.getElementById('shiftSummaryBox');
        if (data.ok && data.shift) {
            const s = data.shift;
            const fmt = v => '₱' + parseFloat(v||0).toLocaleString('en-PH', {minimumFractionDigits:2});
            document.getElementById('ss_opening').textContent    = fmt(s.opening_cash);
            document.getElementById('ss_cash_sales').textContent = fmt(s.cash_sales);
            document.getElementById('ss_gcash_sales').textContent= fmt(s.gcash_sales);
            const refEl = document.getElementById('ss_cash_refunds');
            if (refEl) refEl.textContent = (parseFloat(s.cash_refunds||0) > 0 ? '-' : '') + fmt(s.cash_refunds);
            document.getElementById('ss_total_sales').textContent= fmt(s.total_sales);
            document.getElementById('ss_expected').textContent   = fmt(s.expected_cash);
            if (box) box.style.display = 'block';
        } else {
            if (box) box.style.display = 'none';
        }
    } catch (_) {}
}
async function submitEndShift() {
    const closingCash = parseFloat(document.getElementById('closingCashInput').value);
    const remarks     = document.getElementById('shiftRemarksInput').value.trim();
    const errBox      = document.getElementById('shiftCloseErrorMsg');
    const succBox     = document.getElementById('shiftCloseSuccessMsg');
    const btn         = document.getElementById('btnEndShift');

    if (isNaN(closingCash) || closingCash < 0) {
        errBox.textContent = 'Please enter the actual cash amount in the drawer.';
        errBox.style.display = 'block';
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Closing...';
    errBox.style.display = 'none';

    try {
        const res = await fetch('<?= BASE_URL ?>/modules/pos/shift_api.php?action=end', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': KK_CSRF_TOKEN },
            body: JSON.stringify({ closing_cash: closingCash, remarks })
        });
        const data = await res.json();
        if (data.ok) {
            const d = data.data;
            const fmt = v => '₱' + parseFloat(v||0).toLocaleString('en-PH', {minimumFractionDigits:2});
            const variance = parseFloat(d.variance||0);
            const varColor = variance < 0 ? '#dc2626' : (variance > 0 ? '#d97706' : '#059669');
            succBox.innerHTML = `<i class="bi bi-check-circle-fill"></i> Shift closed!<br>
                Expected: <strong>${fmt(d.expected_cash)}</strong> &nbsp;|&nbsp;
                Counted: <strong>${fmt(d.closing_cash)}</strong> &nbsp;|&nbsp;
                Variance: <strong style="color:${varColor}">${variance >= 0 ? '+' : ''}${fmt(d.variance)}</strong>`;
            succBox.style.display = 'block';
            setTimeout(() => { closeShiftModal(); promptSwitchCashier(); }, 2500);
        } else {
            if (data.msg && data.msg.includes('variance')) {
                document.getElementById('remarksRequired').style.display = 'inline';
            }
            errBox.textContent = data.msg || 'Failed to close shift.';
            errBox.style.display = 'block';
        }
    } catch (e) {
        errBox.textContent = 'Network error. Please try again.';
        errBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-box-arrow-right"></i> End Shift';
    }
}

</script>

<script src="<?= BASE_URL ?>/assets/js/checkout-retry.js?v=<?= (int)@filemtime(ROOT_PATH . '/assets/js/checkout-retry.js') ?: '20261118' ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/stock-qty.js?v=<?= filemtime(ROOT_PATH . '/assets/js/stock-qty.js') ?>"></script>
<?php $script = 'pos.js?v=' . (int)@filemtime(ROOT_PATH . '/assets/js/pos.js'); include ROOT_PATH . '/includes/footer.php'; ?>
