<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier']);

$page = 'pos';
$title = 'Point of Sale';
$heading = 'Point of Sale';

$categories = $conn->query("SELECT * FROM categories WHERE status = 'Active' ORDER BY sort_order ASC")->fetchAll();
$raw_items = $conn->query("
    SELECT menu_items.*, categories.category_name
    FROM menu_items
    JOIN categories ON categories.id = menu_items.category_id
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

$official_specs = [
    'Spaghetti' => [
        'subcat' => 'pasta',
        'rates' => [
            'S'  => ['price' => 500,  'desc' => 'Good for 5-7 pax'],
            'M'  => ['price' => 850,  'desc' => 'Good for 8-12 pax'],
            'L'  => ['price' => 1200, 'desc' => 'Good for 12-18 pax'],
            'XL' => ['price' => 1550, 'desc' => 'Good for 18-25 pax']
        ]
    ],
    'Carbonara' => [
        'subcat' => 'pasta',
        'rates' => [
            'S'  => ['price' => 500,  'desc' => 'Good for 5-7 pax'],
            'M'  => ['price' => 850,  'desc' => 'Good for 8-12 pax'],
            'L'  => ['price' => 1200, 'desc' => 'Good for 12-18 pax'],
            'XL' => ['price' => 1550, 'desc' => 'Good for 18-25 pax']
        ]
    ],
    'Palabok' => [
        'subcat' => 'palabok_sotanghon',
        'rates' => [
            'S'  => ['price' => 650,  'desc' => 'Good for 6-9 pax'],
            'M'  => ['price' => 850,  'desc' => 'Good for 9-13 pax'],
            'L'  => ['price' => 1000, 'desc' => 'Good for 20-25 pax'],
            'XL' => ['price' => 1200, 'desc' => 'Good for 30-35 pax']
        ]
    ],
    'Sotanghon' => [
        'subcat' => 'palabok_sotanghon',
        'rates' => [
            'S'  => ['price' => 650,  'desc' => 'Good for 6-9 pax'],
            'M'  => ['price' => 850,  'desc' => 'Good for 9-13 pax'],
            'L'  => ['price' => 1000, 'desc' => 'Good for 20-25 pax'],
            'XL' => ['price' => 1200, 'desc' => 'Good for 30-35 pax']
        ]
    ],
    'Bihon' => [
        'subcat' => 'bihon_canton',
        'rates' => [
            'S'  => ['price' => 550,  'desc' => 'Good for 6-9 pax'],
            'M'  => ['price' => 750,  'desc' => 'Good for 9-13 pax'],
            'L'  => ['price' => 950,  'desc' => 'Good for 20-25 pax'],
            'XL' => ['price' => 1100, 'desc' => 'Good for 30-35 pax']
        ]
    ],
    'Canton' => [
        'subcat' => 'bihon_canton',
        'rates' => [
            'S'  => ['price' => 550,  'desc' => 'Good for 6-9 pax'],
            'M'  => ['price' => 750,  'desc' => 'Good for 9-13 pax'],
            'L'  => ['price' => 950,  'desc' => 'Good for 20-25 pax'],
            'XL' => ['price' => 1100, 'desc' => 'Good for 30-35 pax']
        ]
    ]
];

$bilao_groups = [];
foreach ($official_specs as $dish => $spec) {
    $bilao_groups[$dish] = [
        'subcat'   => $spec['subcat'],
        'image'    => null,
        'variants' => []
    ];
}

$regular_items = [];

foreach ($raw_items as $item) {
    if ($bilaoCatId && (int)$item['category_id'] === (int)$bilaoCatId) {
        foreach (array_keys($official_specs) as $dish) {
            if (stripos($item['item_name'], $dish) !== false) {
                $size = 'S';
                if (stripos($item['item_name'], 'XL') !== false) {
                    $size = 'XL';
                } elseif (stripos($item['item_name'], 'Large') !== false) {
                    $size = 'L';
                } elseif (stripos($item['item_name'], 'Medium') !== false) {
                    $size = 'M';
                }

                if (!empty($item['image']) && empty($bilao_groups[$dish]['image'])) {
                    $bilao_groups[$dish]['image'] = $item['image'];
                }

                $bilao_groups[$dish]['variants'][$size] = [
                    'id'    => (int)$item['id'],
                    'price' => (float)$item['price'],
                    'desc'  => $official_specs[$dish]['rates'][$size]['desc']
                ];
                break;
            }
        }
    } else {
        $regular_items[] = $item;
    }
}

foreach ($bilao_groups as $dish => &$group) {
    $first_available_id = !empty($group['variants']) ? reset($group['variants'])['id'] : 1;
    foreach (['S', 'M', 'L', 'XL'] as $sz) {
        if (!isset($group['variants'][$sz])) {
            $group['variants'][$sz] = [
                'id'    => $first_available_id,
                'price' => (float)$official_specs[$dish]['rates'][$sz]['price'],
                'desc'  => $official_specs[$dish]['rates'][$sz]['desc']
            ];
        }
    }
}
unset($group);

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
        grid-template-columns: 1fr 380px;
        gap: 20px;
        height: calc(100vh - 120px);
        max-height: calc(100vh - 120px);
        overflow: hidden;
        align-items: stretch;
        padding-bottom: 8px;
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
        grid-template-columns: repeat(6, 1fr) !important;
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
        min-height: 245px !important;
        height: auto !important;
        flex-shrink: 0 !important;
    }
    .food-card img {
        width: 100%;
        height: 125px !important;
        min-height: 125px !important;
        max-height: 125px !important;
        object-fit: contain;
        padding: 6px 6px 0 6px;
        background-color: transparent;
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
        height: 30px;
        font-size: 0.8rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border-radius: 6px;
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
        background: #ffffff !important;
        color: #0f172a !important;
        border: 2px solid #0f172a !important;
        font-weight: 800;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
    }

    .receipt-btn-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }
    .receipt-btn-group .btn {
        height: 36px;
        font-size: 0.82rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border-radius: 6px;
        cursor: pointer;
        box-sizing: border-box;
    }
    .receipt-btn-group #checkoutBtn {
        background: #f59e0b;
        color: #0f172a;
        border: 1px solid #d97706;
    }
    .receipt-btn-group #checkoutBtn:hover {
        background: #eab308;
    }
    .receipt-btn-group #printBtn {
        background: #ffffff;
        color: #0f172a;
        border: 1px solid #cbd5e1;
    }
    .receipt-btn-group #printBtn:hover {
        background: #f8fafc;
        border-color: #94a3b8;
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
    .bilao-type-row {
        display: inline-flex;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        padding: 3px;
        border-radius: 8px;
        gap: 4px;
    }
    .bilao-type-btn {
        border: none;
        background: transparent;
        padding: 4px 16px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #64748b;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .bilao-type-btn:hover {
        color: #0f172a;
    }
    .bilao-type-btn.active {
        background: #ffffff;
        color: #1e293b;
        font-weight: 700;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    .bilao-sizes-row {
        display: inline-flex;
        gap: 8px;
    }
    .bilao-size-btn {
        width: 40px;
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
    .bilao-price {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0f172a;
        margin-top: 2px;
    }

    @media print {
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
</style>

<div class="pos-layout">
    <section>
        <div class="tabs">
            <button class="tab active" data-cat="all">All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="tab" data-cat="<?= e($cat['id']) ?>"><?= e($cat['category_name']) ?></button>
            <?php endforeach; ?>
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

                $stock = isset($item['stock']) ? (int)$item['stock'] : (isset($item['quantity']) ? (int)$item['quantity'] : 50);
                $isUnavailable = ($item['availability'] === 'Unavailable') || ($stock <= 0);
            ?>
            <article class="food-card <?= $isUnavailable ? 'unavailable' : '' ?>" 
                     data-cat="<?= e($item['category_id']) ?>" 
                     data-subcat="<?= $subcat ?>"
                     data-id="<?= e($item['id']) ?>" 
                     data-name="<?= e($item['item_name']) ?>" 
                     data-price="<?= e($price) ?>"
                     data-stock="<?= e($stock) ?>">

                <span class="stock-badge <?= $stock <= 0 ? 'out-of-stock' : ($stock <= 5 ? 'low-stock' : '') ?>">
                    <?= $stock > 0 ? e($stock) . ' pcs left' : 'Out of Stock' ?>
                </span>

                <?php if ($item['image']): ?>
                    <img src="<?= BASE_URL ?>/uploads/menu/<?= e($item['image']) ?>" alt="<?= e($item['item_name']) ?>">
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
                            <button type="button" class="btn-qty btn-card-plus" data-id="<?= e($item['id']) ?>" <?= $stock <= 0 ? 'disabled' : '' ?>>+</button>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>

            <?php if ($bilaoCatId): ?>
            <?php foreach ($bilao_groups as $dish => $info): ?>
            <?php
                $default_size = 'S';
                $default_variant = $info['variants'][$default_size];
                $init_id = $default_variant['id'];
                $init_price = $default_variant['price'];
                $init_name = $dish . ' Tray S';
                $init_desc = $default_variant['desc'];
                $json_variants = htmlspecialchars(json_encode($info['variants']), ENT_QUOTES, 'UTF-8');
            ?>
            <article class="food-card bilao-card bilao-centered-card" 
                     data-cat="<?= e($bilaoCatId) ?>" 
                     data-subcat="<?= e($info['subcat']) ?>"
                     data-id="<?= e($init_id) ?>" 
                     data-name="<?= e($init_name) ?>" 
                     data-price="<?= e($init_price) ?>"
                     data-base="<?= e($dish) ?>"
                     data-variants='<?= $json_variants ?>'>
                <?php if ($info['image']): ?>
                    <img src="<?= BASE_URL ?>/uploads/menu/<?= e($info['image']) ?>" alt="<?= e($dish) ?>">
                <?php else: ?>
                    <img src="<?= BASE_URL ?>/assets/img/food-placeholder.svg" alt="">
                <?php endif; ?>
                
                <div class="bilao-center-content">
                    <h3 class="bilao-title"><?= e($dish) ?></h3>
                    <p class="bilao-desc"><?= e($init_desc) ?></p>

                    <div class="bilao-type-row pkg-group" onclick="event.stopPropagation();">
                        <button type="button" class="bilao-type-btn pkg-btn active" data-pkg="Tray">Tray</button>
                        <button type="button" class="bilao-type-btn pkg-btn" data-pkg="Bilao">Bilao</button>
                    </div>

                    <div class="bilao-sizes-row size-group" onclick="event.stopPropagation();">
                        <button type="button" class="bilao-size-btn size-btn active" data-size="S">S</button>
                        <button type="button" class="bilao-size-btn size-btn" data-size="M">M</button>
                        <button type="button" class="bilao-size-btn size-btn" data-size="L">L</button>
                        <button type="button" class="bilao-size-btn size-btn" data-size="XL">XL</button>
                    </div>

                    <strong class="bilao-price"><?= money($init_price) ?></strong>
                </div>
            </article>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <aside class="card receipt-panel">
        <div class="receipt-brand">
            <div class="receipt-header-row">
                <div class="receipt-brand-left">
                    <span class="brand-mark">K</span>
                    <div class="brand-text-col">
                        <h2>KENJI'S kitchen</h2>
                        <span class="order-pill"><i class="bi bi-receipt"></i> Order #<?= e($orderTicket) ?></span>
                    </div>
                </div>
                <button type="button" class="btn-clear-cart" id="clearCartBtn" onclick="window.clearAllOrder()" title="Clear all items in cart">
                    <i class="bi bi-trash3"></i> Clear
                </button>
            </div>
        </div>
        
        <div class="receipt-form-top">
            <div class="field">
                <label>Order Type</label>
                <select id="orderType">
                    <option value="">Select type</option>
                    <option value="DINE-IN">Dine-In</option>
                    <option value="TAKE-OUT">Take-Out</option>
                </select>
            </div>
            <div class="field">
                <label>Table No.</label>
                <input id="tableNo" placeholder="For dine-in">
            </div>
        </div>

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
                <span class="note-pill-text"><i class="bi bi-chat-left-text"></i> <strong id="notePillText"></strong></span>
                <button type="button" class="note-pill-remove" id="btnRemoveNote" title="Remove note">&times;</button>
            </div>

            <div class="totals">
                <div><span>Subtotal</span><strong id="subtotal">₱0.00</strong></div>
                <div><span>Discount</span><input id="discount" type="number" min="0" value="0" style="width:75px; height:22px; padding:1px 6px; font-size:0.8rem; border:1px solid #cbd5e1; border-radius:4px;"></div>
                <div><span>Tax</span><strong id="tax">Tax-Free</strong></div>
                <div class="grand"><span>Total</span><strong id="grandTotal">₱0.00</strong></div>
            </div>

            <div class="receipt-cash-row">
                <div class="field" id="tenderCashWrap">
                    <label id="tenderLabel">Cash</label>
                    <input id="cash" type="number" min="0" placeholder="0.00">
                </div>
                <div class="field" id="tenderGcashWrap" style="display: none;">
                    <label>Ref #</label>
                    <input id="gcashRef" type="text" placeholder="Last 4 digits" maxlength="12">
                </div>
                <div class="change-display">
                    <span id="changeLabel">Change:</span><strong id="change">₱0.00</strong>
                </div>
            </div>

            <div class="payment-toggle-row">
                <button type="button" class="btn-pay-toggle" id="btnMethodCash" onclick="window.setPaymentMode('cash')">
                    <i class="bi bi-cash-stack"></i> Cash
                </button>
                <button type="button" class="btn-pay-toggle" id="btnMethodGcash" onclick="window.setPaymentMode('gcash')">
                    <i class="bi bi-phone"></i> GCash
                </button>
            </div>

            <div class="receipt-btn-group">
                <button class="btn" id="checkoutBtn"><i class="bi bi-check-circle"></i>Checkout</button>
                <button class="btn" id="printBtn"><i class="bi bi-printer"></i>Print</button>
            </div>
        </div>
    </aside>
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
                    card.style.display = (activeSub === 'all' || card.dataset.subcat === activeSub) ? '' : 'none';
                }
            });
        } else if (currentCat === bilaoCatId) {
            const activeSub = bilaoSubFilter.querySelector('.sub-tab.active')?.dataset.sub || 'all';
            foodCards.forEach(card => {
                if (card.dataset.cat === bilaoCatId) {
                    card.style.display = (activeSub === 'all' || card.dataset.subcat === activeSub) ? '' : 'none';
                }
            });
        }
    }

    catButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            silogSubFilter.querySelectorAll('.sub-tab').forEach(s => s.classList.remove('active'));
            silogSubFilter.querySelector('[data-sub="all"]')?.classList.add('active');

            bilaoSubFilter.querySelectorAll('.sub-tab').forEach(s => s.classList.remove('active'));
            bilaoSubFilter.querySelector('[data-sub="all"]')?.classList.add('active');

            setTimeout(updateSubFilterVisibility, 20);
        });
    });

    silogSubFilter.querySelectorAll('.sub-tab').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            silogSubFilter.querySelectorAll('.sub-tab').forEach(s => s.classList.remove('active'));
            this.classList.add('active');
            applySubFilters(silogCatId);
        });
    });

    bilaoSubFilter.querySelectorAll('.sub-tab').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            bilaoSubFilter.querySelectorAll('.sub-tab').forEach(s => s.classList.remove('active'));
            this.classList.add('active');
            applySubFilters(bilaoCatId);
        });
    });

    document.querySelectorAll('.bilao-card').forEach(card => {
        const variants = JSON.parse(card.dataset.variants || '{}');
        const baseName = card.dataset.base;
        const priceTag = card.querySelector('.bilao-price');
        const descTag = card.querySelector('.bilao-desc');

        function updateCardState() {
            const activeSize = card.querySelector('.size-btn.active')?.dataset.size || 'S';
            const activePkg = card.querySelector('.pkg-btn.active')?.dataset.pkg || 'Tray';
            const v = variants[activeSize];

            if (v) {
                card.dataset.id = v.id;
                card.dataset.price = v.price;
                card.dataset.name = baseName + ' ' + activePkg + ' ' + activeSize;
                if (descTag) descTag.textContent = v.desc;
                if (priceTag) priceTag.textContent = '₱' + parseFloat(v.price).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }

        card.querySelectorAll('.size-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                card.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                updateCardState();
            });
        });

        card.querySelectorAll('.pkg-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                card.querySelectorAll('.pkg-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                updateCardState();
            });
        });
    });

    updateSubFilterVisibility();
});
</script>

<?php $script = 'pos.js?v=' . time(); include ROOT_PATH . '/includes/footer.php'; ?>