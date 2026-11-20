let cart = [];
let posCartRowFeedback = {};
let posCartStockBanner = '';
let currentPaymentMode = null;
let currentOrderNote = '';
let lastCompletedOrder = null;

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
});

const cartItems = document.getElementById('cartItems');
const discountInput = document.getElementById('discount');
const cashInput = document.getElementById('cash');
const gcashRefInput = document.getElementById('gcashRef');
const clearCartBtn = document.getElementById('clearCartBtn');

const noteTriggerWrap = document.getElementById('noteTriggerWrap');
const btnAddNoteLink = document.getElementById('btnAddNoteLink');
const noteInputRow = document.getElementById('noteInputRow');
const orderNoteInput = document.getElementById('orderNoteInput');
const btnDoneNote = document.getElementById('btnDoneNote');
const btnCancelNote = document.getElementById('btnCancelNote');
const notePillDisplay = document.getElementById('notePillDisplay');
const notePillText = document.getElementById('notePillText');
const btnRemoveNote = document.getElementById('btnRemoveNote');

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

function injectPosModalStyles() {
    if (document.getElementById('posModalStyles')) {
        return;
    }

    const style = document.createElement('style');
    style.id = 'posModalStyles';

    style.textContent = `
        .pos-notification-overlay,
        .pos-checkout-overlay {
            position: fixed;
            inset: 0;
            z-index: 99999;
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

        .pos-notification-overlay.show,
        .pos-checkout-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .pos-notification-modal,
        .pos-checkout-modal {
            position: relative;
            width: min(440px, 100%);
            background: #ffffff;
            border: 1px solid #e5e5e5;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.28);
            transform: translateY(16px) scale(0.97);
            transition: transform 0.2s ease;
        }

        .pos-notification-overlay.show .pos-notification-modal,
        .pos-checkout-overlay.show .pos-checkout-modal {
            transform: translateY(0) scale(1);
        }

        .pos-notification-accent,
        .pos-checkout-accent {
            height: 7px;
            background: #F2C12E;
        }

        .pos-notification-close,
        .pos-checkout-close {
            position: absolute;
            top: 13px;
            right: 13px;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 50%;
            background: #f5f5f5;
            color: #111111;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.15s ease;
            z-index: 2;
        }

        .pos-notification-close:hover,
        .pos-checkout-close:hover {
            background: #F2C12E;
            transform: scale(1.04);
        }

        .pos-notification-body,
        .pos-checkout-body {
            padding: 30px 28px 26px;
            text-align: center;
        }

        .pos-notification-icon,
        .pos-checkout-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #FFF1B8;
            color: #111111;
            font-size: 2.15rem;
        }

        .pos-notification-title,
        .pos-checkout-title {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 800;
            color: #111111;
        }

        .pos-notification-message,
        .pos-checkout-subtitle {
            margin: 8px auto 20px;
            max-width: 340px;
            font-size: 0.84rem;
            line-height: 1.5;
            color: #666666;
        }

        .pos-notification-action {
            width: 100%;
            height: 42px;
            border: 1px solid #D8AF18;
            border-radius: 7px;
            background: #F2C12E;
            color: #111111;
            font-size: 0.82rem;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .pos-notification-action:hover {
            background: #E8B717;
        }

        .pos-checkout-receipt {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            padding: 5px 12px;
            margin-bottom: 18px;
            background: #ffffff;
            border: 1px solid #dddddd;
            border-radius: 8px;
            color: #111111;
            font-size: 0.8rem;
            font-weight: 800;
        }

        .pos-checkout-details {
            margin-bottom: 20px;
            background: #ffffff;
            border: 1px solid #dddddd;
            border-radius: 10px;
            overflow: hidden;
            text-align: left;
        }

        .pos-checkout-detail-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            min-height: 38px;
            padding: 8px 12px;
            border-bottom: 1px solid #eeeeee;
            font-size: 0.8rem;
        }

        .pos-checkout-detail-label {
            color: #666666;
            font-weight: 600;
        }

        .pos-checkout-detail-value {
            color: #111111;
            font-weight: 800;
            text-align: right;
            word-break: break-word;
        }

        .pos-checkout-note {
            align-items: flex-start;
        }

        .pos-checkout-note .pos-checkout-detail-value {
            max-width: 64%;
            line-height: 1.35;
        }

        .pos-checkout-total-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #FFFBE6;
            border-top: 1px solid #dddddd;
        }

        .pos-checkout-total-label {
            color: #111111;
            font-size: 0.82rem;
            font-weight: 800;
        }

        .pos-checkout-total-value {
            color: #111111;
            font-size: 1.08rem;
            font-weight: 900;
        }

        .pos-checkout-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .pos-checkout-action {
            height: 42px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-sizing: border-box;
            transition: all 0.15s ease;
        }

        .pos-checkout-print {
            background: #fffdf7;
            border: 1px solid #e2d8be;
            color: #3b3323;
            font-size: 0.82rem;
        }

        .pos-checkout-print:hover {
            background: #fff4cc;
            border-color: #d6a81f;
        }

        .pos-checkout-new {
            background: #F2C12E;
            border: 1px solid #D8AF18;
            color: #1f2328;
        }

        .pos-checkout-new:hover {
            background: #E8B717;
        }

        .pos-print-active {
            display: block !important;
            visibility: visible !important;
        }

        @media (max-width: 480px) {
            .pos-notification-body,
            .pos-checkout-body {
                padding: 24px 18px 20px;
            }

            .pos-checkout-actions {
                grid-template-columns: 1fr;
            }

            .pos-notification-close,
            .pos-checkout-close {
                top: 10px;
                right: 10px;
            }
        }

        @media print {
            html,
            body {
                background: #ffffff !important;
                overflow: visible !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            body * {
                visibility: hidden !important;
            }

            #posPrintReceipt,
            #posPrintReceipt * {
                visibility: visible !important;
            }

            #posPrintReceipt {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 280px !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 8px !important;
                display: block !important;
                background: #ffffff !important;
                color: #000000 !important;
                font-family: 'Courier New', Courier, monospace, sans-serif !important;
                font-size: 12px !important;
                box-sizing: border-box !important;
            }

            .pos-layout,
            header,
            nav,
            aside,
            .sidebar,
            .pos-notification-overlay,
            .pos-checkout-overlay {
                display: none !important;
            }
        }
    `;

    document.head.appendChild(style);
}

injectPosModalStyles();

function showPosAlert(title, message) {
    const existing =
        document.getElementById('posNotificationOverlay');

    if (existing) {
        existing.remove();
    }

    const overlay = document.createElement('div');

    overlay.id = 'posNotificationOverlay';
    overlay.className = 'pos-notification-overlay';

    overlay.innerHTML = `
        <div class="pos-notification-modal">
            <div class="pos-notification-accent"></div>

            <button
                type="button"
                class="pos-notification-close"
                id="posNotificationClose"
                aria-label="Close"
                title="Close"
            >
                <i class="bi bi-x-lg"></i>
            </button>

            <div class="pos-notification-body">
                <div class="pos-notification-icon">
                    <i class="bi bi-exclamation-lg"></i>
                </div>

                <h2 class="pos-notification-title">
                    ${escapeHtml(title)}
                </h2>

                <p class="pos-notification-message">
                    ${escapeHtml(message)}
                </p>

                <button
                    type="button"
                    class="pos-notification-action"
                    id="posNotificationOk"
                >
                    OK
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const closeAlert = () => {
        overlay.classList.remove('show');

        setTimeout(() => {
            overlay.remove();
        }, 200);
    };

    document
        .getElementById('posNotificationClose')
        ?.addEventListener('click', closeAlert);

    document
        .getElementById('posNotificationOk')
        ?.addEventListener('click', closeAlert);

    requestAnimationFrame(() => {
        overlay.classList.add('show');
    });
}

if (btnAddNoteLink) {
    btnAddNoteLink.addEventListener('click', () => {
        noteTriggerWrap.style.display = 'none';
        noteInputRow.style.display = 'flex';
        orderNoteInput.value = currentOrderNote;
        orderNoteInput.focus();
    });
}

if (btnDoneNote) {
    btnDoneNote.addEventListener('click', () => {
        const val = orderNoteInput.value.trim();
        currentOrderNote = val;
        updateNoteDisplay();
    });
}

if (btnCancelNote) {
    btnCancelNote.addEventListener('click', () => {
        orderNoteInput.value = currentOrderNote;
        updateNoteDisplay();
    });
}

if (btnRemoveNote) {
    btnRemoveNote.addEventListener('click', () => {
        currentOrderNote = '';
        orderNoteInput.value = '';
        updateNoteDisplay();
    });
}

function updateNoteDisplay() {
    if (currentOrderNote) {
        noteTriggerWrap.style.display = 'none';
        noteInputRow.style.display = 'none';
        notePillDisplay.style.display = 'inline-flex';
        notePillText.textContent = currentOrderNote;
    } else {
        notePillDisplay.style.display = 'none';
        noteInputRow.style.display = 'none';
        noteTriggerWrap.style.display = 'flex';
    }
}

window.setPaymentMode = function(mode) {
    currentPaymentMode = mode;

    const btnCash =
        document.getElementById('btnMethodCash');

    const btnGcash =
        document.getElementById('btnMethodGcash');

    const tenderCashWrap =
        document.getElementById('tenderCashWrap');

    const tenderGcashWrap =
        document.getElementById('tenderGcashWrap');

    if (btnCash) {
        btnCash.classList.toggle(
            'active',
            mode === 'cash'
        );
    }

    if (btnGcash) {
        btnGcash.classList.toggle(
            'active',
            mode === 'gcash'
        );
    }

    const tenderGcashAttestWrap =
        document.getElementById('tenderGcashAttestWrap');

    const gcashCashierAttested =
        document.getElementById('gcashCashierAttested');

    if (mode === 'cash') {
        if (tenderCashWrap) {
            tenderCashWrap.style.display = 'flex';
        }

        if (tenderGcashWrap) {
            tenderGcashWrap.style.display = 'none';
        }

        if (tenderGcashAttestWrap) {
            tenderGcashAttestWrap.style.display = 'none';
        }

        if (gcashCashierAttested) {
            gcashCashierAttested.checked = false;
        }

        if (cashInput) {
            cashInput.value = '';
        }
    }

    if (mode === 'gcash') {
        if (tenderCashWrap) {
            tenderCashWrap.style.display = 'none';
        }

        if (tenderGcashWrap) {
            tenderGcashWrap.style.display = 'flex';
        }

        if (tenderGcashAttestWrap) {
            tenderGcashAttestWrap.style.display = 'block';
        }

        const data = totals();

        if (cashInput) {
            cashInput.value = data.total;
        }

        if (gcashRefInput) {
            setTimeout(() => {
                openKeypad(gcashRefInput);
            }, 50);
        }
    }

    renderCart();
};

// POS on-screen numeric keypad
const keypadOverlay = document.getElementById('posKeypadOverlay');
const keypadTitle = document.getElementById('posKeypadTitle');
const keypadDisplay = document.getElementById('posKeypadDisplay');
const keypadError = document.getElementById('posKeypadError');
const keypadGrid = document.getElementById('posKeypadGrid');
const keypadClose = document.getElementById('posKeypadClose');
let keypadTarget = null;
let keypadOriginalValue = '';
let keypadType = '';
let keypadValue = '';

function setKeypadError(message = '') {
    if (keypadError) keypadError.textContent = message;
}

function renderKeypad() {
    if (keypadDisplay) keypadDisplay.value = keypadValue;
    const decimalButton = keypadGrid?.querySelector('[data-decimal="true"]');
    if (decimalButton) decimalButton.hidden = keypadType !== 'cash';
}

function openKeypad(input) {
    if (!keypadOverlay || !input) return;
    keypadTarget = input;
    keypadOriginalValue = input.value || '';
    keypadType = input.dataset.keypadType || 'number';
    keypadValue = keypadOriginalValue;
    setKeypadError('');

    const titles = {
        table: 'Enter Table Number',
        cash: 'Enter Cash Amount',
        gcash: 'Enter GCash Reference'
    };

    if (keypadTitle) keypadTitle.textContent = titles[keypadType] || 'Enter Number';
    renderKeypad();
    keypadOverlay.hidden = false;
    keypadOverlay.classList.add('show');
}

function closeKeypad(save = false) {
    if (!keypadOverlay) return;

    if (save && keypadTarget) {
        keypadTarget.value = keypadValue;
        keypadTarget.dispatchEvent(new Event('input', { bubbles: true }));
        keypadTarget.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (keypadTarget) {
        keypadTarget.value = keypadOriginalValue;
    }

    keypadOverlay.classList.remove('show');
    keypadOverlay.hidden = true;
    keypadTarget = null;
    keypadType = '';
    keypadValue = '';
    keypadOriginalValue = '';
    setKeypadError('');
}

function appendKeypadValue(key) {
    setKeypadError('');

    if (keypadType === 'table') {
        if (!/^\d$/.test(key) || keypadValue.length >= 2) return;
        const next = keypadValue + key;
        const numeric = Number(next);
        if (numeric >= 1 && numeric <= 10) {
            keypadValue = next;
        }
        renderKeypad();
        return;
    }

    if (keypadType === 'gcash') {
        if (!/^\d$/.test(key) || keypadValue.length >= 13) return;
        keypadValue += key;
        renderKeypad();
        return;
    }

    if (keypadType === 'cash') {
        if (key === '.' && keypadValue.includes('.')) return;
        if (!/^\d$/.test(key) && key !== '.') return;
        const parts = keypadValue.split('.');
        if (!keypadValue.includes('.') && keypadValue.length >= 8 && key !== '.') return;
        if (keypadValue.includes('.') && parts[1].length >= 2) return;
        if (key === '.' && keypadValue === '') keypadValue = '0';
        keypadValue += key;
        renderKeypad();
    }
}

function validateKeypadValue() {
    if (keypadType === 'table') {
        const value = Number(keypadValue);
        if (!Number.isInteger(value) || value < 1 || value > 10) {
            setKeypadError('Table number must be from 1 to 10.');
            return false;
        }
    }

    if (keypadType === 'cash') {
        if (keypadValue === '' || Number.isNaN(Number(keypadValue)) || Number(keypadValue) < 0) {
            setKeypadError('Enter a valid cash amount.');
            return false;
        }
    }

    if (keypadType === 'gcash') {
        if (!/^\d{13}$/.test(keypadValue)) {
            setKeypadError('GCash reference must contain exactly 13 digits.');
            return false;
        }
    }

    return true;
}

if (keypadGrid) {
    keypadGrid.addEventListener('click', event => {
        const button = event.target.closest('.pos-keypad-key');
        if (!button) return;

        const key = button.dataset.key;
        const action = button.dataset.action;

        if (key !== undefined) {
            appendKeypadValue(key);
            return;
        }

        if (action === 'clear') {
            keypadValue = '';
            setKeypadError('');
            renderKeypad();
            return;
        }

        if (action === 'backspace') {
            keypadValue = keypadValue.slice(0, -1);
            setKeypadError('');
            renderKeypad();
            return;
        }

        if (action === 'cancel') {
            closeKeypad(false);
            return;
        }

        if (action === 'done' && validateKeypadValue()) {
            closeKeypad(true);
        }
    });
}

if (keypadClose) {
    keypadClose.addEventListener('click', () => closeKeypad(false));
}

if (keypadOverlay) {
    keypadOverlay.addEventListener('click', event => {
        if (event.target === keypadOverlay) closeKeypad(false);
    });
}

[
    document.getElementById('tableNo'),
    document.getElementById('cash'),
    document.getElementById('gcashRef')
].filter(Boolean).forEach(input => {
    input.addEventListener('click', () => openKeypad(input));
});

if (gcashRefInput) {
    gcashRefInput.addEventListener('input', () => {
        gcashRefInput.value =
            gcashRefInput.value
                .replace(/\D/g, '')
                .slice(0, 13);
    });

    gcashRefInput.addEventListener('paste', event => {
        event.preventDefault();

        const clipboard =
            event.clipboardData ||
            window.clipboardData;

        const value =
            clipboard
                ? clipboard.getData('text')
                : '';

        gcashRefInput.value =
            value
                .replace(/\D/g, '')
                .slice(0, 13);
    });
}

let clearCartConfirmationPending = false;
window.clearAllOrder = async function(skipConfirm = false) {
    if (!skipConfirm && cart.length > 0) {
        if (clearCartConfirmationPending) return;
        clearCartConfirmationPending = true;
        try {
            const confirmed = await window.kkConfirm({
                title: 'Clear cart?',
                message: 'Remove all items and reset the details for this order?',
                confirmText: 'Clear cart',
                cancelText: 'Keep items',
                variant: 'primary'
            });
            if (!confirmed) return;
        } finally {
            clearCartConfirmationPending = false;
        }
    }
    cart = [];
    posCartRowFeedback = {};
    posCartStockBanner = '';

    const orderType =
        document.getElementById('orderType');

    const tableNo =
        document.getElementById('tableNo');

    if (orderType) {
        orderType.value = '';
    }

    if (tableNo) {
        tableNo.value = '';
    }

    if (cashInput) {
        cashInput.value = '';
    }

    if (gcashRefInput) {
        gcashRefInput.value = '';
    }

    if (discountInput) {
        discountInput.value = 0;
    }

    currentDiscountType = null;
    currentDiscountIdNumber = '';
    currentDiscountCustomerName = '';

    currentOrderNote = '';

    if (orderNoteInput) {
        orderNoteInput.value = '';
    }

    updateNoteDisplay();

    currentPaymentMode = null;

    const btnCash =
        document.getElementById('btnMethodCash');

    const btnGcash =
        document.getElementById('btnMethodGcash');

    const tenderCashWrap =
        document.getElementById('tenderCashWrap');

    const tenderGcashWrap =
        document.getElementById('tenderGcashWrap');

    if (btnCash) {
        btnCash.classList.remove('active');
    }

    if (btnGcash) {
        btnGcash.classList.remove('active');
    }

    if (tenderCashWrap) {
        tenderCashWrap.style.display = 'flex';
    }

    if (tenderGcashWrap) {
        tenderGcashWrap.style.display = 'none';
    }

    const tenderGcashAttestWrap =
        document.getElementById('tenderGcashAttestWrap');

    const gcashCashierAttested =
        document.getElementById('gcashCashierAttested');

    if (tenderGcashAttestWrap) {
        tenderGcashAttestWrap.style.display = 'none';
    }

    if (gcashCashierAttested) {
        gcashCashierAttested.checked = false;
    }

    renderCart();
};

function filterMenu() {
    const activeTab =
        document.querySelector('.tabs .tab.active') ||
        document.querySelector('.tab.active');

    const cat =
        activeTab
            ? activeTab.dataset.cat
            : 'all';

    const silogSubFilter = document.getElementById('silogSubFilter');
    const bilaoSubFilter = document.getElementById('bilaoSubFilter');
    let activeSub = 'all';
    if (silogSubFilter && silogSubFilter.style.display !== 'none') {
        activeSub = silogSubFilter.querySelector('.sub-tab.active')?.dataset.sub || 'all';
    } else if (bilaoSubFilter && bilaoSubFilter.style.display !== 'none') {
        activeSub = bilaoSubFilter.querySelector('.sub-tab.active')?.dataset.sub || 'all';
    }

    const searchInput =
        document.getElementById('posProductSearch') ||
        document.querySelector('input[type="search"]') ||
        document.querySelector('.navbar input') ||
        document.querySelector('header input');

    const query =
        searchInput
            ? searchInput.value.trim().toLowerCase()
            : '';

    document
        .querySelectorAll('.food-card')
        .forEach(card => {
            const itemCat =
                card.dataset.cat || '';
            const itemSubcat =
                card.dataset.subcat || '';

            const itemName = (
                card.dataset.name ||
                card.dataset.base ||
                card.textContent
            ).toLowerCase();

            const itemDesc = (
                card.querySelector('.card-desc, .bilao-desc')?.textContent || ''
            ).toLowerCase();

            const matchesCat =
                cat === 'all' ||
                (cat === 'bestsellers' ? card.dataset.bestseller === '1' : itemCat === cat);

            const matchesSubcat =
                cat === 'all' ||
                cat === 'bestsellers' ||
                activeSub === 'all' ||
                itemSubcat === activeSub;

            const matchesSearch =
                !query ||
                itemName.includes(query) ||
                itemDesc.includes(query);

            card.style.display =
                matchesCat &&
                matchesSubcat &&
                matchesSearch
                    ? ''
                    : 'none';

            card.style.order =
                cat === 'bestsellers'
                    ? (card.dataset.bestsellerRank || '999')
                    : '';
        });
}
window.filterPosMenu = filterMenu;

document
    .querySelectorAll('.tab')
    .forEach(tab => {
        tab.addEventListener('click', () => {
            document
                .querySelectorAll('.tab')
                .forEach(btn => {
                    btn.classList.remove(
                        'active'
                    );
                });

            tab.classList.add('active');
            const searchInput = document.getElementById('posProductSearch');
            if (searchInput && searchInput.value) {
                searchInput.value = '';
            }
            if (typeof window.syncPosSubFilterVisibility === 'function') {
                window.syncPosSubFilterVisibility();
            } else {
                filterMenu();
            }
        });
    });

(function initCategoryScroller() {
    const btnLeft = document.getElementById('catScrollLeftBtn');
    const btnRight = document.getElementById('catScrollRightBtn');
    if (btnLeft) btnLeft.style.display = 'none';
    if (btnRight) btnRight.style.display = 'none';
})();

const dedicatedPosSearch = document.getElementById('posProductSearch');
if (dedicatedPosSearch) {
    dedicatedPosSearch.addEventListener('input', filterMenu);
}

const posSearchInput =
    document.querySelector(
        'input[type="search"]'
    ) ||
    document.querySelector('.navbar input') ||
    document.querySelector('header input');

if (posSearchInput) {
    posSearchInput.addEventListener(
        'input',
        filterMenu
    );
}

function getPosPoolKey(item) {
    return 'menu:' + Number(item?.id || 0);
}

function getPosPoolStock(id, fallbackStock = 0) {
    const cards = document.querySelectorAll('.food-card');
    for (const card of cards) {
        if (card.dataset.variants) {
            try {
                const variants = JSON.parse(card.dataset.variants);
                for (const sz of Object.keys(variants)) {
                    const v = variants[sz];
                    if (v && Number(v.id) === Number(id)) {
                        return Math.max(0, Math.floor(Number(v.stock ?? 0)));
                    }
                }
            } catch (_) {}
        }
        if (Number(card.dataset.id) === Number(id)) {
            return Math.max(0, Math.floor(Number(card.dataset.stock ?? fallbackStock)));
        }
    }
    return Math.max(0, Math.floor(Number(fallbackStock) || 0));
}

function usedInPosCartForPool(poolKey, excludeIndex = -1) {
    if (window.KKStockQty) {
        return window.KKStockQty.sumPoolQty(cart, getPosPoolKey, poolKey, excludeIndex);
    }
    return cart.reduce((sum, row, idx) => (
        idx !== excludeIndex && getPosPoolKey(row) === poolKey ? sum + (Number(row.qty) || 0) : sum
    ), 0);
}

function shiftPosRowFeedbackAfterRemoval(removedIndex) {
    const next = {};
    Object.keys(posCartRowFeedback).forEach(k => {
        const oldIdx = Number(k);
        if (oldIdx < removedIndex) next[oldIdx] = posCartRowFeedback[k];
        else if (oldIdx > removedIndex) next[oldIdx - 1] = posCartRowFeedback[k];
    });
    posCartRowFeedback = next;
}

function notifyPosStockMessage(message, title = 'Stock Limit', dedupeKey = 'pos-stock') {
    if (typeof window.kkNotify === 'function') {
        window.kkNotify(message, 'warning', { title, dedupeKey, duration: 3200 });
    } else {
        showPosAlert(title, message);
    }
}

function addToCart(
    id,
    name,
    price,
    stock,
    availability,
    catId = null,
    opts = null
) {
    flushPosPendingQtyEdits();
    if (availability !== undefined && availability !== null && availability !== 'Available') {
        notifyPosStockMessage('This item is currently unavailable.', 'Item Unavailable', 'pos-unavail-' + id);
        return;
    }

    const packaging = opts && opts.packaging ? String(opts.packaging) : null;
    const variantSize = opts && opts.variantSize ? String(opts.variantSize) : null;
    const itemNotes = opts && opts.notes ? String(opts.notes) : (packaging ? ('Packaging: ' + packaging) : null);

    const maxStock = Math.max(0, Math.floor(Number(stock ?? 0)));
    const poolKey = 'menu:' + Number(id);
    const usedForPool = usedInPosCartForPool(poolKey, -1);
    const existingIndex = cart.findIndex(
        item => String(item.id) === String(id) &&
            item.name === name &&
            (item.packaging || null) === (packaging || null)
    );
    const existing = existingIndex >= 0 ? cart[existingIndex] : null;
    const allowance = window.KKStockQty
        ? window.KKStockQty.getAddAllowance({
            poolStock: maxStock,
            usedInBasketForPool: usedForPool,
            perLineCap: Infinity,
            existingMatchingLineQty: existing ? existing.qty : 0
        })
        : { poolStock: maxStock, usedInBasketForPool: usedForPool, remainingStock: Math.max(0, maxStock - usedForPool), maxAdd: Math.max(0, maxStock - usedForPool) };

    if (maxStock <= 0) {
        notifyPosStockMessage('This item is currently out of stock.', 'Out of Stock', 'pos-oos-' + id);
        return;
    }

    if (allowance.maxAdd <= 0) {
        const limitMsg = `All ${maxStock} available are in the cart.`;
        let flaggedRow = false;
        cart.forEach((row, idx) => {
            if (getPosPoolKey(row) === poolKey) {
                posCartRowFeedback[idx] = { text: limitMsg, tone: 'warning' };
                flaggedRow = true;
            }
        });
        if (flaggedRow) {
            renderCart();
        } else {
            notifyPosStockMessage(limitMsg, 'Stock Limit Reached', 'pos-limit-' + id);
        }
        return;
    }

    posCartStockBanner = '';
    if (existing) {
        existing.qty += 1;
        existing.stock = maxStock;
        if (catId !== null && catId !== undefined) {
            existing.catId = catId;
        }
        if (usedForPool + 1 >= maxStock) {
            posCartRowFeedback[existingIndex] = {
                text: `Maximum available: ${maxStock}.`,
                tone: 'info'
            };
        } else {
            delete posCartRowFeedback[existingIndex];
        }
    } else {
        const newIdx = cart.length;
        cart.push({
            id: id,
            name: name,
            price: Number(price),
            stock: maxStock,
            qty: 1,
            catId: catId,
            packaging: packaging,
            variantSize: variantSize,
            notes: itemNotes
        });
        if (usedForPool + 1 >= maxStock) {
            posCartRowFeedback[newIdx] = {
                text: `Maximum available: ${maxStock}.`,
                tone: 'info'
            };
        }
    }

    renderCart();
}

function decreaseFromCart(id, name) {
    flushPosPendingQtyEdits();
    const index = cart.findIndex(
        row => String(row.id) === String(id) && (name === undefined || row.name === name)
    );

    if (index < 0) {
        return;
    }

    cart[index].qty -= 1;

    if (cart[index].qty <= 0) {
        cart.splice(index, 1);
        shiftPosRowFeedbackAfterRemoval(index);
    } else {
        delete posCartRowFeedback[index];
    }

    posCartStockBanner = '';
    renderCart();
}

document
    .querySelectorAll('.food-card')
    .forEach(card => {
        card.addEventListener(
            'click',
            event => {
                if (
                    event.target.closest(
                        '.qty-stepper'
                    ) ||
                    event.target.closest(
                        '.bilao-sizes-row'
                    ) ||
                    event.target.closest(
                        '.bilao-pkg-row'
                    )
                ) {
                    return;
                }

                if (
                    card.classList.contains(
                        'unavailable'
                    ) ||
                    (card.dataset.availability && card.dataset.availability !== 'Available')
                ) {
                    showPosAlert(
                        'Item Unavailable',
                        'This item is currently unavailable.'
                    );
                    return;
                }

                if (card.classList.contains('bilao-card')) {
                    const pkg = card.dataset.packaging || '';
                    if (pkg !== 'Tray' && pkg !== 'Bilao') {
                        const pkgRow = card.querySelector('.bilao-pkg-row');
                        const pkgHint = card.querySelector('.bilao-pkg-hint');
                        if (pkgRow) pkgRow.classList.add('pkg-needs-selection');
                        if (pkgHint) {
                            pkgHint.textContent = 'Choose Tray or Bilao first';
                            pkgHint.classList.add('is-error');
                        }
                        notifyPosStockMessage(
                            'Please select Tray or Bilao packaging before adding this item.',
                            'Packaging Required',
                            'pos-pkg-' + (card.dataset.base || card.dataset.id)
                        );
                        return;
                    }
                    addToCart(
                        card.dataset.id,
                        card.dataset.name,
                        card.dataset.price,
                        card.dataset.stock,
                        card.dataset.availability,
                        card.dataset.cat,
                        {
                            packaging: pkg,
                            variantSize: card.dataset.size || null,
                            notes: 'Packaging: ' + pkg
                        }
                    );
                    return;
                }

                addToCart(
                    card.dataset.id,
                    card.dataset.name,
                    card.dataset.price,
                    card.dataset.stock,
                    card.dataset.availability,
                    card.dataset.cat
                );
            }
        );
    });

document
    .querySelectorAll('.btn-card-plus')
    .forEach(btn => {
        btn.addEventListener(
            'click',
            event => {
                event.stopPropagation();

                const card =
                    btn.closest('.food-card');

                if (!card) {
                    return;
                }

                if (
                    card.classList.contains('unavailable') ||
                    (card.dataset.availability && card.dataset.availability !== 'Available')
                ) {
                    showPosAlert(
                        'Item Unavailable',
                        'This item is currently unavailable.'
                    );
                    return;
                }

                addToCart(
                    card.dataset.id,
                    card.dataset.name,
                    card.dataset.price,
                    card.dataset.stock,
                    card.dataset.availability,
                    card.dataset.cat
                );
            }
        );
    });

document
    .querySelectorAll('.btn-card-minus')
    .forEach(btn => {
        btn.addEventListener(
            'click',
            event => {
                event.stopPropagation();

                decreaseFromCart(
                    btn.dataset.id,
                    btn.closest('.food-card')?.dataset.name
                );
            }
        );
    });

let currentDiscountType = null; // 'Senior Citizen' | 'PWD' | null
let currentDiscountIdNumber = '';
let currentDiscountCustomerName = '';

function totals() {
    const subtotal =
        cart.reduce(
            (sum, item) =>
                sum +
                (item.price * item.qty),
            0
        );

    let discountPercent = 0;
    let discount = 0;

    if (currentDiscountType === 'Senior Citizen' || currentDiscountType === 'PWD') {
        discountPercent = 20;
        // Philippine RA 9994 / RA 10754: 20% discount applies to individual personal consumption meals.
        // Bilao Favorites (category 6) is bulk/catering party size, excluded from Senior/PWD discount.
        const eligibleSubtotal = cart.reduce((sum, item) => {
            const isBilao = String(item.catId) === '6' ||
                            Boolean(item.packaging) ||
                            /bilao/i.test(item.name || '') ||
                            /\b(Spaghetti|Carbonara|Palabok|Sotanghon|Bihon|Canton)\b/i.test(item.name || '');
            return isBilao ? sum : sum + (item.price * item.qty);
        }, 0);

        discount = Math.round(
            (eligibleSubtotal * 0.20 + Number.EPSILON) * 100
        ) / 100;
    } else {
        discountPercent = Math.min(
            Math.max(
                Number(discountInput?.value || 0),
                0
            ),
            100
        );

        discount = Math.round(
            (subtotal * discountPercent / 100 + Number.EPSILON) * 100
        ) / 100;
    }

    const tax = 0;
    const total = Math.max(subtotal - discount + tax, 0);

    let cash = Number(cashInput?.value || 0);
    if (currentPaymentMode === 'gcash') {
        cash = total;
    }

    return {
        subtotal,
        discountPercent,
        discount,
        tax,
        total,
        cash,
        change: Math.max(cash - total, 0),
        discountType: currentDiscountType,
        discountIdNumber: currentDiscountIdNumber,
        discountCustomerName: currentDiscountCustomerName
    };
}

function initPosMobileBarObserver() {
    if (window.__kkPosMobileBarObserved) return;
    const bar = document.getElementById('posMobileOrderBar');
    const receipt = document.getElementById('receiptPanel');
    if (!bar || !receipt || typeof IntersectionObserver !== 'function') return;
    window.__kkPosMobileBarObserved = true;
    const observer = new IntersectionObserver((entries) => {
        const visible = entries.some((entry) => entry.isIntersecting);
        bar.classList.toggle('is-hidden', visible);
    }, { threshold: 0.15 });
    observer.observe(receipt);
}

function updateTotalsDisplay() {
    const data = totals();

    const subtotalElement =
        document.getElementById(
            'subtotal'
        );

    const taxElement =
        document.getElementById(
            'tax'
        );

    const grandTotalElement =
        document.getElementById(
            'grandTotal'
        );

    const discountAmountElement =
        document.getElementById(
            'discountAmount'
        );

    const changeElement =
        document.getElementById(
            'change'
        );

    if (subtotalElement) {
        subtotalElement.textContent =
            peso.format(
                data.subtotal
            );
    }

    if (taxElement) {
        taxElement.textContent =
            peso.format(
                data.tax
            );
    }

    if (grandTotalElement) {
        grandTotalElement.textContent =
            peso.format(
                data.total
            );
    }

    const mobileCountEl = document.getElementById('posMobileCartCount');
    const mobileTotalEl = document.getElementById('posMobileCartTotal');
    if (mobileCountEl) {
        const totalQty = cart.reduce((sum, item) => sum + Number(item.qty || 0), 0);
        mobileCountEl.textContent = totalQty === 1 ? '1 item in order' : `${totalQty} items in order`;
    }
    if (mobileTotalEl) {
        mobileTotalEl.textContent = peso.format(data.total);
    }

    initPosMobileBarObserver();

    if (discountAmountElement) {
        if (data.discount > 0) {
            const label = data.discountType ? ` (${data.discountType})` : '';
            discountAmountElement.textContent = '-' + peso.format(data.discount);
            discountAmountElement.title = (data.discountType ? data.discountType + ' Discount: ' : 'Discount: ') + peso.format(data.discount);
        } else {
            discountAmountElement.textContent = '-₱0.00';
            discountAmountElement.title = '';
        }
    }

    // Synchronize Senior Citizen & PWD quick discount UI
    const btnSenior = document.getElementById('btnDiscountSenior');
    const btnPwd = document.getElementById('btnDiscountPwd');
    const badgeRow = document.getElementById('posDiscBadgeRow');
    const badgeText = document.getElementById('posDiscBadgeText');

    if (btnSenior) btnSenior.classList.toggle('active', data.discountType === 'Senior Citizen');
    if (btnPwd) btnPwd.classList.toggle('active', data.discountType === 'PWD');

    if (badgeRow && badgeText) {
        if (data.discountType) {
            badgeRow.style.display = 'flex';
            badgeText.innerHTML = `<i class="bi bi-patch-check-fill"></i> ${data.discountType} (-20%): ${data.discountIdNumber ? escapeHtml(data.discountIdNumber) : 'Verified'}`;
        } else {
            badgeRow.style.display = 'none';
        }
    }

    if (changeElement) {
        changeElement.textContent =
            peso.format(
                data.change
            );
    }

    return data;
}

function updateMenuCardControls() {
    document
        .querySelectorAll('.food-card')
        .forEach(card => {
            const id = card.dataset.id;
            const stock = Math.max(0, Math.floor(Number(card.dataset.stock ?? 0)));
            const cardName = card.dataset.name;
            const cartItem = cart.find(item => String(item.id) === String(id) && item.name === cardName);
            const currentQty = cartItem ? cartItem.qty : 0;
            const usedForPool = usedInPosCartForPool('menu:' + Number(id), -1);

            const plusBtn = card.querySelector('.btn-card-plus');
            const qtyDisplay = card.querySelector('.qty-display');

            const availability = card.dataset.availability || 'Available';
            const isUnavailable = availability !== 'Available' || stock <= 0;

            if (plusBtn) {
                plusBtn.disabled = isUnavailable || usedForPool >= stock;
            }

            if (qtyDisplay) {
                qtyDisplay.textContent = currentQty;
            }
            card.classList.toggle('in-cart', currentQty > 0);
        });
}

function renderPosCartBanner() {
    const banner = document.getElementById('posCartStockBanner');
    if (!banner) return;
    if (!posCartStockBanner) {
        banner.textContent = '';
        banner.hidden = true;
        return;
    }
    banner.textContent = posCartStockBanner;
    banner.hidden = false;
}

function renderCart() {
    if (!cartItems) {
        return;
    }

    renderPosCartBanner();
    cartItems.innerHTML = '';

    if (clearCartBtn) {
        clearCartBtn.disabled =
            cart.length === 0;
    }

    if (cart.length === 0) {
        cartItems.innerHTML = `
            <div class="empty-cart-state">
                <i class="bi bi-basket3"></i>
                <span>No items added</span>
                <small>Tap dishes from the menu to start order</small>
            </div>
        `;
    } else {
        cart.forEach((item, index) => {
            const poolKey = getPosPoolKey(item);
            const poolStock = getPosPoolStock(item.id, item.stock);
            item.stock = poolStock;
            const usedInOtherRows = usedInPosCartForPool(poolKey, index);
            const availableForRow = Math.max(0, poolStock - usedInOtherRows);
            const atMax = availableForRow > 0 && item.qty >= availableForRow;
            const fb = posCartRowFeedback[index] || null;

            const row = document.createElement('div');
            row.className = 'cart-row';

            row.innerHTML = `
                <div>
                    <strong>
                        ${escapeHtml(item.name)}
                    </strong>
                    ${item.packaging ? `<div style="font-size:0.75rem; color:#64748b; font-weight:600;">Packaging: ${escapeHtml(item.packaging)}</div>` : ''}

                    <p class="muted">
                        ${peso.format(item.price)}
                    </p>

                    <div class="qty">
                        <button
                            type="button"
                            data-cart-index="${index}"
                            data-minus="${escapeHtml(item.id)}"
                            aria-label="Decrease quantity"
                        >−</button>

                        <input
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            class="cart-qty-input"
                            data-cart-index="${index}"
                            data-id="${escapeHtml(item.id)}"
                            value="${item.qty}"
                            aria-label="Quantity for ${escapeHtml(item.name)}"
                            aria-describedby="posCartMsg-${index}"
                        >

                        <button
                            type="button"
                            data-cart-index="${index}"
                            data-plus="${escapeHtml(item.id)}"
                            ${atMax ? 'disabled aria-disabled="true" title="Maximum available reached"' : ''}
                            aria-label="Increase quantity"
                        >+</button>

                        <button
                            type="button"
                            data-cart-index="${index}"
                            data-remove="${escapeHtml(item.id)}"
                            aria-label="Remove ${escapeHtml(item.name)}"
                            title="Remove item"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div id="posCartMsg-${index}" class="pos-cart-qty-msg ${fb ? 'is-' + fb.tone : ''}" role="status" aria-live="polite" ${fb ? '' : 'hidden'}>${fb ? escapeHtml(fb.text) : ''}</div>
                </div>

                <strong>
                    ${peso.format(
                        item.price *
                        item.qty
                    )}
                </strong>
            `;

            cartItems.appendChild(row);
        });
    }

    updateTotalsDisplay();
    updateMenuCardControls();
}

function commitPosCartQtyInput(index, input, opts = {}) {
    const item = cart[index];
    if (!item || !input) return;
    if (!opts.force && input.dataset.dirty !== '1') {
        return;
    }
    input.dataset.dirty = '0';

    const poolKey = getPosPoolKey(item);
    const poolStock = getPosPoolStock(item.id, item.stock);
    const usedInOtherRows = usedInPosCartForPool(poolKey, index);

    const res = window.KKStockQty
        ? window.KKStockQty.resolveRowReplace({
            rawInput: input.value,
            currentQty: item.qty,
            poolStock,
            usedInOtherRowsForPool: usedInOtherRows,
            perLineCap: Infinity
        })
        : (() => {
            const avail = Math.max(0, poolStock - usedInOtherRows);
            let parsed = parseInt(String(input.value || ''), 10);
            if (isNaN(parsed) || parsed < 1) parsed = 1;
            const adjusted = parsed > avail;
            const qty = adjusted ? avail : parsed;
            return { qty, availableForRow: avail, effectiveMax: avail, adjusted, atMax: qty >= avail, reason: adjusted ? 'stock_limit' : 'ok', message: adjusted ? `Adjusted to ${qty} (stock limit).` : '' };
        })();

    if (res.qty <= 0) {
        cart.splice(index, 1);
        shiftPosRowFeedbackAfterRemoval(index);
        posCartStockBanner = `${item.name} has no remaining stock and was removed.`;
        renderCart();
        return;
    }

    item.qty = res.qty;
    item.stock = poolStock;
    input.value = String(res.qty);

    if (res.message) {
        posCartRowFeedback[index] = {
            text: res.message,
            tone: (res.reason === 'invalid' || res.reason === 'below_min') ? 'error' : 'warning'
        };
    } else if (res.atMax) {
        posCartRowFeedback[index] = {
            text: `Maximum available: ${res.effectiveMax}.`,
            tone: 'info'
        };
    } else {
        delete posCartRowFeedback[index];
    }

    posCartStockBanner = '';
    renderCart();
}

function flushPosPendingQtyEdits() {
    if (!cartItems) return;
    cartItems.querySelectorAll('.cart-qty-input[data-dirty="1"]').forEach(input => {
        const idx = Number(input.dataset.cartIndex);
        if (Number.isInteger(idx) && cart[idx]) {
            commitPosCartQtyInput(idx, input, { force: true });
        }
    });
}

if (cartItems) {
    cartItems.addEventListener('click', event => {
        const plus = event.target.closest('[data-plus]');
        const minus = event.target.closest('[data-minus]');
        const remove = event.target.closest('[data-remove]');

        if (!plus && !minus && !remove) return;
        flushPosPendingQtyEdits();

        if (plus) {
            const index = Number(plus.dataset.cartIndex);
            const item = cart[index];
            if (item) {
                const poolKey = getPosPoolKey(item);
                const poolStock = getPosPoolStock(item.id, item.stock);
                const usedInOtherRows = usedInPosCartForPool(poolKey, index);
                const availableForRow = Math.max(0, poolStock - usedInOtherRows);
                if (item.qty + 1 > availableForRow) {
                    posCartRowFeedback[index] = {
                        text: usedInOtherRows > 0
                            ? `Maximum for this row: ${availableForRow}. ${usedInOtherRows} in other rows.`
                            : `Maximum available: ${availableForRow}.`,
                        tone: 'warning'
                    };
                    renderCart();
                    return;
                }
                item.qty += 1;
                if (item.qty >= availableForRow) {
                    posCartRowFeedback[index] = {
                        text: `Maximum available: ${availableForRow}.`,
                        tone: 'info'
                    };
                } else {
                    delete posCartRowFeedback[index];
                }
                posCartStockBanner = '';
                renderCart();
            }
            return;
        }

        if (minus) {
            const index = Number(minus.dataset.cartIndex);
            if (cart[index]) {
                cart[index].qty -= 1;
                if (cart[index].qty <= 0) {
                    cart.splice(index, 1);
                    shiftPosRowFeedbackAfterRemoval(index);
                } else {
                    delete posCartRowFeedback[index];
                }
                posCartStockBanner = '';
                renderCart();
            }
            return;
        }

        if (remove) {
            const index = Number(remove.dataset.cartIndex);
            if (index >= 0 && index < cart.length) {
                cart.splice(index, 1);
                shiftPosRowFeedbackAfterRemoval(index);
                posCartStockBanner = '';
                renderCart();
            }
        }
    });

    cartItems.addEventListener('input', event => {
        if (!event.target.classList.contains('cart-qty-input')) {
            return;
        }
        const input = event.target;
        input.dataset.dirty = '1';
        const index = Number(input.dataset.cartIndex);
        if (posCartRowFeedback[index] && posCartRowFeedback[index].tone === 'error') {
            delete posCartRowFeedback[index];
            const msgEl = document.getElementById(`posCartMsg-${index}`);
            if (msgEl) {
                msgEl.textContent = '';
                msgEl.hidden = true;
                msgEl.className = 'pos-cart-qty-msg';
            }
        }
    });

    cartItems.addEventListener('keydown', event => {
        if (!event.target.classList.contains('cart-qty-input') || event.isComposing) {
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            const input = event.target;
            const index = Number(input.dataset.cartIndex);
            commitPosCartQtyInput(index, input, { force: true });
            input.blur();
        }
    });

    cartItems.addEventListener('focusout', event => {
        if (!event.target.classList.contains('cart-qty-input')) {
            return;
        }
        const input = event.target;
        const index = Number(input.dataset.cartIndex);
        commitPosCartQtyInput(index, input);
    });
}

if (discountInput) {
    discountInput.addEventListener(
        'input',
        () => {
            // When cashier manually enters a custom discount, clear active Senior/PWD preset
            if (currentDiscountType) {
                currentDiscountType = null;
                currentDiscountIdNumber = '';
                currentDiscountCustomerName = '';
            }
            const value = Number(discountInput.value || 0);
            if (value > 100) discountInput.value = '100';
            if (value < 0) discountInput.value = '0';
            renderCart();
        }
    );
}

// Senior Citizen and PWD Discount Helper Functions
let pendingQuickDiscountType = null;

window.toggleQuickDiscount = function(type) {
    const fullType = type === 'Senior' ? 'Senior Citizen' : 'PWD';

    if (currentDiscountType === fullType) {
        // Cashier clicked the active one again: remove discount
        window.removeQuickDiscount();
        return;
    }

    if (cart.length === 0) {
        showPosAlert('Empty Cart', 'Please add items to the cart first before applying a discount.');
        return;
    }

    pendingQuickDiscountType = fullType;
    openSeniorPwdModal(fullType);
};

window.removeQuickDiscount = function() {
    currentDiscountType = null;
    currentDiscountIdNumber = '';
    currentDiscountCustomerName = '';
    if (discountInput) discountInput.value = 0;
    renderCart();
};

function openSeniorPwdModal(type) {
    const modal = document.getElementById('seniorPwdModalOverlay');
    const title = document.getElementById('spModalTitle');
    const subtitle = document.getElementById('spModalSubtitle');
    const label = document.getElementById('spIdLabel');
    const iconWrap = document.getElementById('spModalIconWrap');
    const errBox = document.getElementById('spModalErrorMsg');
    const nameInp = document.getElementById('spCustomerName');
    const idInp = document.getElementById('spIdNumber');

    if (!modal) return;

    if (errBox) errBox.style.display = 'none';

    if (type === 'Senior Citizen') {
        if (title) title.textContent = 'Senior Citizen Verification';
        if (subtitle) subtitle.textContent = 'Verify the customer\'s ID under R.A. 9994 (20% Discount).';
        if (label) label.textContent = 'Senior Citizen ID / OSCA #';
        if (idInp) idInp.placeholder = 'e.g. OSCA-2024-12345';
        if (iconWrap) {
            iconWrap.style.background = '#fff4c9';
            iconWrap.style.color = '#9a6b00';
            iconWrap.innerHTML = '<i class="bi bi-person-badge-fill"></i>';
        }
    } else {
        if (title) title.textContent = 'PWD Verification';
        if (subtitle) subtitle.textContent = 'Verify the customer\'s ID under R.A. 10754 (20% Discount).';
        if (label) label.textContent = 'PWD ID / Card #';
        if (idInp) idInp.placeholder = 'e.g. PWD-QC-2024-001';
        if (iconWrap) {
            iconWrap.style.background = '#fff4c9';
            iconWrap.style.color = '#9a6b00';
            iconWrap.innerHTML = '<i class="bi bi-universal-access"></i>';
        }
    }

    if (nameInp) nameInp.value = currentDiscountCustomerName || '';
    if (idInp) idInp.value = currentDiscountIdNumber || '';

    modal.hidden = false;
    setTimeout(() => {
        if (nameInp && !nameInp.value) nameInp.focus();
        else if (idInp) idInp.focus();
    }, 50);
}

window.closeSeniorPwdModal = function() {
    const modal = document.getElementById('seniorPwdModalOverlay');
    if (modal) modal.hidden = true;
    pendingQuickDiscountType = null;
};

window.confirmSeniorPwdDiscount = function() {
    const nameInp = document.getElementById('spCustomerName');
    const idInp = document.getElementById('spIdNumber');
    const errBox = document.getElementById('spModalErrorMsg');

    const nameVal = (nameInp ? nameInp.value : '').trim();
    const idVal = (idInp ? idInp.value : '').trim();

    if (!nameVal || !idVal) {
        if (errBox) {
            errBox.textContent = 'Please enter both the customer full name and ID card number.';
            errBox.style.display = 'block';
        }
        return;
    }

    currentDiscountType = pendingQuickDiscountType;
    currentDiscountCustomerName = nameVal;
    currentDiscountIdNumber = idVal;

    if (discountInput) {
        discountInput.value = 20;
    }

    window.closeSeniorPwdModal();
    renderCart();
};

if (cashInput) {
    cashInput.addEventListener(
        'input',
        () => {
            renderCart();
        }
    );
}

function showCheckoutSuccessScreen(
    orderData
) {
    const existing =
        document.getElementById(
            'posCheckoutOverlay'
        );

    if (existing) {
        existing.remove();
    }

    const overlay =
        document.createElement(
            'div'
        );

    overlay.id =
        'posCheckoutOverlay';

    overlay.className =
        'pos-checkout-overlay';

    const tableText =
        orderData.tableNo
            ? orderData.tableNo
            : 'Take-Out';

    const paymentText =
        orderData.paymentMode ===
        'gcash'
            ? 'GCash'
            : 'Cash';

    const orderNumber =
        String(
            orderData.receiptNo ||
            ''
        ).replace(
            /^Order\s*#/i,
            ''
        );

    overlay.innerHTML = `
        <div class="pos-checkout-modal">
            <div class="pos-checkout-accent"></div>

            <button
                type="button"
                class="pos-checkout-close"
                id="checkoutSuccessCloseBtn"
                aria-label="Close"
                title="Close"
            >
                <i class="bi bi-x-lg"></i>
            </button>

            <div class="pos-checkout-body">
                <div class="pos-checkout-icon">
                    <i class="bi bi-check-lg"></i>
                </div>

                <h2 class="pos-checkout-title">
                    Order Completed
                </h2>

                <p class="pos-checkout-subtitle">
                    Your order has been saved successfully.
                </p>

                <div class="pos-checkout-receipt">
                    Receipt #${escapeHtml(
                        orderNumber
                    )}
                </div>

                <div class="pos-checkout-details">
                    <div class="pos-checkout-detail-row">
                        <span class="pos-checkout-detail-label">
                            Order Type
                        </span>

                        <span class="pos-checkout-detail-value">
                            ${escapeHtml(
                                orderData.orderType
                            )}
                        </span>
                    </div>

                    <div class="pos-checkout-detail-row">
                        <span class="pos-checkout-detail-label">
                            Table
                        </span>

                        <span class="pos-checkout-detail-value">
                            ${escapeHtml(
                                tableText
                            )}
                        </span>
                    </div>

                    <div class="pos-checkout-detail-row">
                        <span class="pos-checkout-detail-label">
                            Payment
                        </span>

                        <span class="pos-checkout-detail-value">
                            ${escapeHtml(
                                paymentText
                            )}
                        </span>
                    </div>

                    <div class="pos-checkout-detail-row">
                        <span class="pos-checkout-detail-label">
                            Kitchen
                        </span>

                        <span class="pos-checkout-detail-value" style="display:inline-flex;align-items:center;gap:4px;color:#15803d;font-weight:700;">
                            <i class="bi bi-check2-circle"></i> Sent to Kitchen
                        </span>
                    </div>

                    ${
                        orderData.note
                            ? `
                                <div class="pos-checkout-detail-row pos-checkout-note">
                                    <span class="pos-checkout-detail-label">
                                        Order Note
                                    </span>

                                    <span class="pos-checkout-detail-value">
                                        ${escapeHtml(
                                            orderData.note
                                        )}
                                    </span>
                                </div>
                            `
                            : ''
                    }

                    ${
                        paymentText ===
                        'Cash'
                            ? `
                                <div class="pos-checkout-detail-row">
                                    <span class="pos-checkout-detail-label">
                                        Change
                                    </span>

                                    <span class="pos-checkout-detail-value">
                                        ${peso.format(
                                            orderData.change
                                        )}
                                    </span>
                                </div>
                            `
                            : ''
                    }

                    ${
                        orderData.discount > 0
                            ? `
                                <div class="pos-checkout-detail-row">
                                    <span class="pos-checkout-detail-label">
                                        Discount ${orderData.discountType ? '(' + escapeHtml(orderData.discountType) + ')' : ''}
                                    </span>

                                    <span class="pos-checkout-detail-value" style="color:#b91c1c; font-weight:700;">
                                        -${peso.format(orderData.discount)}
                                    </span>
                                </div>
                            `
                            : ''
                    }

                    <div class="pos-checkout-total-row">
                        <span class="pos-checkout-total-label">
                            Total
                        </span>

                        <span class="pos-checkout-total-value">
                            ${peso.format(
                                orderData.total
                            )}
                        </span>
                    </div>
                </div>

                <div class="pos-checkout-actions">
                    <button
                        type="button"
                        class="pos-checkout-action pos-checkout-new"
                        id="checkoutSuccessPrintBtn"
                        title="Choose a printer and print the customer receipt."
                    >
                        <i class="bi bi-printer-fill"></i>
                        Print Receipt
                    </button>

                    <button
                        type="button"
                        class="pos-checkout-action pos-checkout-print"
                        id="checkoutSuccessDoneBtn"
                        title="Close and start the next order."
                    >
                        <i class="bi bi-check2"></i>
                        Done
                    </button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(
        overlay
    );

    const closeSuccessScreen =
        () => {
            overlay.classList.remove(
                'show'
            );

            setTimeout(() => {
                overlay.remove();
                window.clearAllOrder();
            }, 200);
        };

    document
        .getElementById(
            'checkoutSuccessCloseBtn'
        )
        ?.addEventListener(
            'click',
            closeSuccessScreen
        );

    document
        .getElementById(
            'checkoutSuccessDoneBtn'
        )
        ?.addEventListener(
            'click',
            closeSuccessScreen
        );

    document
        .getElementById(
            'checkoutSuccessPrintBtn'
        )
        ?.addEventListener(
            'click',
            () => {
                printReceipt(orderData, closeSuccessScreen);
            }
        );

    requestAnimationFrame(() => {
        overlay.classList.add(
            'show'
        );
    });
}

function generatePrintReceiptHtml(
    orderData
) {
    const itemsHtml =
        orderData.items
            .map(item => `
                <tr>
                    <td style="
                        padding: 2px 0;
                        vertical-align: top;
                        width: 30px;
                    ">
                        ${escapeHtml(item.qty)}x
                    </td>

                    <td style="
                        padding: 2px 4px;
                        vertical-align: top;
                    ">
                        ${escapeHtml(item.name)}
                        ${item.notes ? `<div style="font-size: 9px; color: #555;">${escapeHtml(item.notes)}</div>` : ''}
                    </td>

                    <td style="
                        padding: 2px 0;
                        text-align: right;
                        vertical-align: top;
                        white-space: nowrap;
                    ">
                        ${peso.format(
                            item.price *
                            item.qty
                        )}
                    </td>
                </tr>
            `)
            .join('');

    const paymentLabel =
        orderData.paymentMode ===
        'gcash'
            ? 'GCash'
            : 'Cash';

    return `
        <div style="
            width: 100%;
            box-sizing: border-box;
        ">
            <div style="
                text-align: center;
                margin-bottom: 8px;
            ">
                <h2 style="
                    margin: 0;
                    font-size: 16px;
                    font-weight: 800;
                    letter-spacing: 1px;
                ">
                    KENJI'S KITCHEN
                </h2>

                <p style="
                    margin: 2px 0;
                    font-size: 10px;
                ">
                    Restaurant Point of Sale
                </p>

                <p style="
                    margin: 2px 0;
                    font-size: 10px;
                ">
                    ${new Date().toLocaleString()}
                </p>

                <div style="
                    border-bottom: 1px dashed #000;
                    margin: 6px 0;
                "></div>

                <div style="
                    display: flex;
                    justify-content: space-between;
                    gap: 10px;
                    font-weight: 800;
                    font-size: 12px;
                ">
                    <span>
                        ${escapeHtml(
                            orderData.receiptNo
                        )}
                    </span>

                    <span>
                        ${escapeHtml(
                            orderData.orderType
                        )}
                    </span>
                </div>

                ${
                    orderData.tableNo
                        ? `
                            <div style="
                                text-align: left;
                                font-size: 11px;
                                margin-top: 2px;
                            ">
                                Table:
                                ${escapeHtml(
                                    orderData.tableNo
                                )}
                            </div>
                        `
                        : `
                            <div style="
                                text-align: left;
                                font-size: 11px;
                                margin-top: 2px;
                            ">
                                Take-Out
                            </div>
                        `
                }
            </div>

            ${
                orderData.note
                    ? `
                        <div style="
                            border: 1px dashed #000;
                            padding: 5px;
                            margin: 6px 0;
                            font-size: 11px;
                        ">
                            <strong>NOTE:</strong>
                            ${escapeHtml(
                                orderData.note
                            )}
                        </div>
                    `
                    : ''
            }

            <div style="
                border-bottom: 1px dashed #000;
                margin: 6px 0;
            "></div>

            <table style="
                width: 100%;
                font-size: 11px;
                border-collapse: collapse;
            ">
                ${itemsHtml}
            </table>

            <div style="
                border-bottom: 1px dashed #000;
                margin: 6px 0;
            "></div>

            <table style="
                width: 100%;
                font-size: 11px;
                border-collapse: collapse;
            ">
                <tr>
                    <td style="
                        padding: 1px 0;
                    ">
                        Subtotal:
                    </td>

                    <td style="
                        text-align: right;
                        padding: 1px 0;
                    ">
                        ${peso.format(
                            orderData.subtotal
                        )}
                    </td>
                </tr>

                ${
                    orderData.discount >
                    0
                        ? `
                            <tr>
                                <td style="
                                    padding: 1px 0;
                                ">
                                    Discount ${orderData.discountType ? '(' + escapeHtml(orderData.discountType) + ')' : '(' + Number(orderData.discountPercent || 0).toFixed(2).replace(/\.00$/, '') + '%)'}:
                                </td>

                                <td style="
                                    text-align: right;
                                    padding: 1px 0;
                                ">
                                    -${peso.format(
                                        orderData.discount
                                    )}
                                </td>
                            </tr>
                            ${
                                orderData.discountIdNumber
                                    ? `
                                        <tr>
                                            <td colspan="2" style="padding: 1px 0; font-size: 9px; color: #555;">
                                                ID: ${escapeHtml(orderData.discountIdNumber)} ${orderData.discountCustomerName ? '- ' + escapeHtml(orderData.discountCustomerName) : ''}
                                            </td>
                                        </tr>
                                    `
                                    : ''
                            }
                        `
                        : ''
                }

                <tr style="
                    font-weight: 800;
                    font-size: 13px;
                ">
                    <td style="
                        padding: 4px 0 2px;
                    ">
                        TOTAL:
                    </td>

                    <td style="
                        text-align: right;
                        padding: 4px 0 2px;
                    ">
                        ${peso.format(
                            orderData.total
                        )}
                    </td>
                </tr>

                <tr>
                    <td style="
                        padding: 1px 0;
                    ">
                        Payment:
                        ${escapeHtml(
                            paymentLabel
                        )}
                    </td>

                    <td style="
                        text-align: right;
                        padding: 1px 0;
                    ">
                        ${peso.format(
                            orderData.cash
                        )}
                    </td>
                </tr>

                ${
                    orderData.paymentMode ===
                    'cash'
                        ? `
                            <tr>
                                <td style="
                                    padding: 1px 0;
                                ">
                                    Change:
                                </td>

                                <td style="
                                    text-align: right;
                                    padding: 1px 0;
                                ">
                                    ${peso.format(
                                        orderData.change
                                    )}
                                </td>
                            </tr>
                        `
                        : ''
                }

                ${
                    orderData.gcashRef
                        ? `
                            <tr>
                                <td style="
                                    padding: 1px 0;
                                ">
                                    GCash Ref:
                                </td>

                                <td style="
                                    text-align: right;
                                    padding: 1px 0;
                                    word-break: break-all;
                                ">
                                    ${escapeHtml(
                                        orderData.gcashRef
                                    )}
                                </td>
                            </tr>
                        `
                        : ''
                }
            </table>

            <div style="
                border-bottom: 1px dashed #000;
                margin: 8px 0;
            "></div>

            <div style="
                text-align: center;
                font-size: 10px;
                margin-top: 6px;
            ">
                <p style="
                    margin: 2px 0;
                ">
                    Thank you for dining with us!
                </p>

                <p style="
                    margin: 2px 0;
                ">
                    Please come again.
                </p>
            </div>
        </div>
    `;
}

function printReceipt(
    orderData,
    afterPrint = null
) {
    const printContainer =
        document.getElementById(
            'posPrintReceipt'
        );

    if (!printContainer) {
        showPosAlert(
            'Print Error',
            'The receipt area could not be found.'
        );
        return;
    }

    printContainer.innerHTML =
        generatePrintReceiptHtml(
            orderData
        );

    printContainer.classList.add(
        'pos-print-active'
    );

    const existingStyle =
        document.getElementById(
            'posDynamicPrintStyles'
        );

    if (existingStyle) {
        existingStyle.remove();
    }

    const style =
        document.createElement(
            'style'
        );

    style.id =
        'posDynamicPrintStyles';

    style.textContent = `
        @media print {
            html,
            body {
                background: #ffffff !important;
                overflow: visible !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            body * {
                visibility: hidden !important;
            }

            #posPrintReceipt,
            #posPrintReceipt * {
                visibility: visible !important;
            }

            #posPrintReceipt {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 280px !important;
                display: block !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 8px !important;
                background: #ffffff !important;
                color: #000000 !important;
                box-sizing: border-box !important;
            }
        }
    `;

    document.head.appendChild(
        style
    );

    const cleanup = () => {
        printContainer.classList.remove(
            'pos-print-active'
        );

        const dynamicStyle =
            document.getElementById(
                'posDynamicPrintStyles'
            );

        if (dynamicStyle) {
            dynamicStyle.remove();
        }

        window.removeEventListener(
            'afterprint',
            cleanup
        );

        if (typeof afterPrint === 'function') {
            afterPrint();
        }
    };

    window.addEventListener(
        'afterprint',
        cleanup
    );

    requestAnimationFrame(() => {
        setTimeout(() => {
            window.print();
        }, 80);
    });
}

function updateMenuStockAfterCheckout(completedCart) {
    const cards = Array.from(document.querySelectorAll('.food-card'));
    const soldById = new Map();

    completedCart.forEach(orderItem => {
        const itemId = String(orderItem.id);
        const soldQty = Math.max(0, Math.floor(Number(orderItem.qty ?? 0)));
        soldById.set(itemId, (soldById.get(itemId) || 0) + soldQty);
    });

    soldById.forEach((soldQty, itemId) => {
        cards.forEach(card => {
            const variantsRaw = card.dataset.variants;

            if (variantsRaw) {
                let variants;

                try {
                    variants = JSON.parse(variantsRaw);
                } catch (error) {
                    return;
                }

                let changed = false;

                Object.keys(variants).forEach(size => {
                    const variant = variants[size];

                    if (variant && String(variant.id) === itemId) {
                        variant.stock = Math.max(0, Math.floor(Number(variant.stock ?? 0)) - soldQty);
                        if (variant.stock <= 0) {
                            variant.availability = 'Unavailable';
                        }
                        changed = true;
                    }
                });

                if (changed) {
                    card.dataset.variants = JSON.stringify(variants);

                    if (String(card.dataset.id) === itemId) {
                        const currentVariant = variants[
                            card.querySelector('.size-btn.active')?.dataset.size || 'S'
                        ];

                        if (currentVariant) {
                            card.dataset.stock = String(currentVariant.stock);
                            card.dataset.availability = currentVariant.availability || (currentVariant.stock <= 0 ? 'Unavailable' : 'Available');

                            if (currentVariant.stock <= 0) {
                                card.classList.add('unavailable');
                            }

                            const badge = card.querySelector('.stock-badge');

                            if (badge) {
                                badge.classList.remove('low-stock', 'out-of-stock');

                                if (currentVariant.stock <= 0) {
                                    badge.classList.add('out-of-stock');
                                    badge.textContent = 'Out of Stock';
                                } else {
                                    if (currentVariant.stock <= 5) {
                                        badge.classList.add('low-stock');
                                    }
                                    badge.textContent = currentVariant.stock <= 5 ? `Only ${currentVariant.stock} left` : `${currentVariant.stock} pcs left`;
                                }
                            }
                        }
                    }
                }

                return;
            }

            if (String(card.dataset.id) === itemId) {
                const currentStock = Math.max(0, Math.floor(Number(card.dataset.stock ?? 0)));
                const newStock = Math.max(0, currentStock - soldQty);
                card.dataset.stock = String(newStock);

                if (newStock <= 0) {
                    card.classList.add('unavailable');
                }

                const badge = card.querySelector('.stock-badge');

                if (badge) {
                    badge.classList.remove('low-stock', 'out-of-stock');

                    if (newStock <= 0) {
                        badge.classList.add('out-of-stock');
                        badge.textContent = 'Out of Stock';
                    } else {
                        if (newStock <= 5) {
                            badge.classList.add('low-stock');
                        }
                        badge.textContent = newStock <= 5 ? `Only ${newStock} left` : `${newStock} pcs left`;
                    }
                }
            }
        });
    });

    updateMenuCardControls();
}

function syncPosStockUpdates(updates) {
    if (!Array.isArray(updates) || !updates.length) return;
    const byId = new Map();
    updates.forEach(u => {
        if (u && u.id != null) {
            byId.set(String(u.id), {
                stock: Math.max(0, Math.floor(Number(u.stock ?? 0))),
                availability: u.availability || 'Available'
            });
        }
    });
    if (!byId.size) return;

    document.querySelectorAll('.food-card').forEach(card => {
        if (card.dataset.variants) {
            try {
                const variants = JSON.parse(card.dataset.variants);
                let changed = false;
                Object.keys(variants).forEach(sz => {
                    const v = variants[sz];
                    if (v && byId.has(String(v.id))) {
                        const fresh = byId.get(String(v.id));
                        v.stock = fresh.stock;
                        v.availability = fresh.availability;
                        changed = true;
                    }
                });
                if (changed) {
                    card.dataset.variants = JSON.stringify(variants);
                    const activeSize = card.querySelector('.size-btn.active')?.dataset.size || 'S';
                    const activeVar = variants[activeSize];
                    if (activeVar) {
                        card.dataset.stock = String(activeVar.stock);
                        card.dataset.availability = activeVar.availability;
                        card.classList.toggle('unavailable', activeVar.availability !== 'Available' || activeVar.stock <= 0);
                        const badge = card.querySelector('.stock-badge');
                        if (badge) {
                            badge.classList.remove('low-stock', 'out-of-stock');
                            if (activeVar.availability !== 'Available') {
                                badge.classList.add('out-of-stock');
                                badge.textContent = 'Unavailable';
                            } else if (activeVar.stock <= 0) {
                                badge.classList.add('out-of-stock');
                                badge.textContent = 'Out of Stock';
                            } else {
                                if (activeVar.stock <= 5) badge.classList.add('low-stock');
                                badge.textContent = activeVar.stock <= 5 ? `Only ${activeVar.stock} left` : `${activeVar.stock} pcs left`;
                            }
                        }
                    }
                }
            } catch (_) {}
            return;
        }
        if (byId.has(String(card.dataset.id))) {
            const fresh = byId.get(String(card.dataset.id));
            card.dataset.stock = String(fresh.stock);
            card.dataset.availability = fresh.availability;
            card.classList.toggle('unavailable', fresh.availability !== 'Available' || fresh.stock <= 0);
            const badge = card.querySelector('.stock-badge');
            if (badge) {
                badge.classList.remove('low-stock', 'out-of-stock');
                if (fresh.availability !== 'Available') {
                    badge.classList.add('out-of-stock');
                    badge.textContent = 'Unavailable';
                } else if (fresh.stock <= 0) {
                    badge.classList.add('out-of-stock');
                    badge.textContent = 'Out of Stock';
                } else {
                    if (fresh.stock <= 5) badge.classList.add('low-stock');
                    badge.textContent = fresh.stock <= 5 ? `Only ${fresh.stock} left` : `${fresh.stock} pcs left`;
                }
            }
        }
    });

    const used = new Map();
    const removed = [];
    const adjusted = [];
    const nextCart = [];
    const nextFeedback = {};
    for (let i = 0; i < cart.length; i++) {
        const item = cart[i];
        const fresh = byId.get(String(item.id));
        const maxStock = fresh ? (fresh.availability === 'Available' ? fresh.stock : 0) : getPosPoolStock(item.id, item.stock);
        item.stock = maxStock;
        const poolKey = getPosPoolKey(item);
        const rem = Math.max(0, maxStock - (used.get(poolKey) || 0));
        const kept = Math.min(item.qty, rem);
        if (kept <= 0) {
            removed.push(item.name);
            continue;
        }
        const newIdx = nextCart.length;
        if (kept !== item.qty) {
            adjusted.push(`${item.name} (${item.qty} → ${kept})`);
            nextFeedback[newIdx] = {
                text: `Adjusted to ${kept} (current stock).`,
                tone: 'warning'
            };
        } else if (posCartRowFeedback[i]) {
            nextFeedback[newIdx] = posCartRowFeedback[i];
        }
        item.qty = kept;
        nextCart.push(item);
        used.set(poolKey, (used.get(poolKey) || 0) + kept);
    }
    cart = nextCart;
    posCartRowFeedback = nextFeedback;
    const notes = [];
    if (removed.length) notes.push(`Removed unavailable: ${removed.join(', ')}.`);
    if (adjusted.length) notes.push(`Adjusted to match stock: ${adjusted.join(', ')}.`);
    if (notes.length) posCartStockBanner = notes.join(' ');
    renderCart();
}

const checkoutBtn =
    document.getElementById(
        'checkoutBtn'
    );

if (checkoutBtn) {
    checkoutBtn.addEventListener(
        'click',
        async () => {
            flushPosPendingQtyEdits();
            const orderType =
                document.getElementById(
                    'orderType'
                )?.value || '';

            const tableNo =
                document.getElementById(
                    'tableNo'
                )?.value.trim() || '';

            const gcashRef =
                document.getElementById(
                    'gcashRef'
                )?.value.trim() || '';

            const activeNote =
                currentOrderNote ||
                document.getElementById(
                    'orderNoteInput'
                )?.value.trim() ||
                '';

            const data = totals();

            if (!cart.length) {
                showPosAlert(
                    'No Items Added',
                    'Please add at least one item to the order before checking out.'
                );
                return;
            }

            if (!orderType) {
                showPosAlert(
                    'Order Type Required',
                    'Please select an order type before continuing.'
                );
                return;
            }

            if (
                orderType ===
                    'DINE-IN' &&
                !tableNo
            ) {
                showPosAlert(
                    'Table Number Required',
                    'Please enter the table number for this dine-in order.'
                );
                return;
            }

            if (!currentPaymentMode) {
                showPosAlert(
                    'Payment Method Required',
                    'Please select Cash or GCash before continuing.'
                );
                return;
            }

            if (
                data.discountPercent < 0 ||
                data.discountPercent > 100
            ) {
                showPosAlert(
                    'Invalid Discount',
                    'The discount must be between 0% and 100%.'
                );
                return;
            }

            if (
                currentPaymentMode ===
                    'cash' &&
                data.cash <
                    data.total
            ) {
                showPosAlert(
                    'Insufficient Cash',
                    'The cash tendered is not enough to complete this order.'
                );
                return;
            }

            if (
                currentPaymentMode ===
                    'gcash' &&
                !/^\d{13}$/.test(
                    gcashRef
                )
            ) {
                showPosAlert(
                    'Invalid GCash Reference',
                    'The GCash reference number must contain exactly 13 digits.'
                );
                return;
            }

            const cashierAttested = currentPaymentMode === 'gcash'
                ? Boolean(document.getElementById('gcashCashierAttested')?.checked)
                : false;

            if (currentPaymentMode === 'gcash' && !cashierAttested) {
                showPosAlert(
                    'Verification Required',
                    'Cashier must attest that payment was verified on merchant phone / POS terminal before completing checkout.'
                );
                return;
            }

            // Checkout Confirmation Modal
            if (window.kkConfirm) {
                const confirmed = await window.kkConfirm({
                    title: 'Complete Order Checkout?',
                    details: [
                        { label: 'Order Type', value: orderType + (tableNo ? ' · Table ' + tableNo : '') },
                        { label: 'Payment Method', value: currentPaymentMode === 'gcash' ? 'GCash' : 'Cash' },
                        { label: 'Total Amount', value: peso.format(data.total) }
                    ],
                    message: `Confirm completing this ${currentPaymentMode === 'gcash' ? 'GCash' : 'Cash'} order checkout?`,
                    confirmText: 'Complete Checkout',
                    cancelText: 'Cancel',
                    variant: 'primary'
                });
                if (!confirmed) {
                    return;
                }
            } else {
                const confirmMsg = `Complete order checkout for ${peso.format(data.total)} via ${currentPaymentMode === 'gcash' ? 'GCash' : 'Cash'}?`;
                if (!confirm(confirmMsg)) {
                    return;
                }
            }

            const payload = {
                cart,
                orderType,
                tableNo,
                orderNote:
                    activeNote,
                paymentMode:
                    currentPaymentMode,
                gcashRef:
                    currentPaymentMode ===
                    'gcash'
                        ? gcashRef
                        : null,
                cashierAttested: cashierAttested,
                discountPercent: data.discountPercent,
                discount: data.discount,
                discountType: data.discountType,
                discountIdNumber: data.discountIdNumber,
                discountCustomerName: data.discountCustomerName,
                subtotal: data.subtotal,
                tax: data.tax,
                total: data.total,
                cash: data.cash,
                change: data.change
            };

            const originalBtnHtml = checkoutBtn.innerHTML;
            checkoutBtn.disabled = true;
            checkoutBtn.innerHTML = '<i class="bi bi-arrow-repeat spin-icon"></i> Processing...';

            const retryScope = 'pos:' + (typeof currentActiveCashier !== 'undefined' && currentActiveCashier ? currentActiveCashier.id : 'staff');
            try {
                payload.request_key = KKCheckoutRetry.key(retryScope, payload);
                const res =
                    await fetch(
                        'process_order.php',
                        {
                            method:
                                'POST',
                            headers: {
                                'Content-Type':
                                    'application/json',
                                'X-CSRF-Token':
                                    typeof KK_CSRF_TOKEN !== 'undefined' ? KK_CSRF_TOKEN : ''
                            },
                            body:
                                JSON.stringify(
                                    payload
                                )
                        }
                    );

                let json;

                try {
                    json =
                        await res.json();
                } catch (
                    error
                ) {
                    throw new Error(
                        'Invalid server response.'
                    );
                }

                if (!res.ok) {
                    throw new Error(
                        json.msg ||
                            'Unable to process order.'
                    );
                }

                if (json.ok) {
                    updateMenuStockAfterCheckout([...cart]);

                    const receiptNumber =
                        json.receipt_no ||
                        json.order_no;

                    lastCompletedOrder = {
                        receiptNo:
                            receiptNumber,
                        orderType:
                            orderType,
                        tableNo:
                            tableNo,
                        note:
                            activeNote,
                        items:
                            [...cart],
                        subtotal:
                            data.subtotal,
                        discountPercent:
                            data.discountPercent,
                        discount:
                            data.discount,
                        discountType:
                            data.discountType,
                        discountIdNumber:
                            data.discountIdNumber,
                        discountCustomerName:
                            data.discountCustomerName,
                        total:
                            data.total,
                        cash:
                            data.cash,
                        change:
                            data.change,
                        paymentMode:
                            currentPaymentMode,
                        gcashRef:
                            currentPaymentMode ===
                            'gcash'
                                ? gcashRef
                                : null
                    };

                    showCheckoutSuccessScreen(
                        lastCompletedOrder
                    );
                    KKCheckoutRetry.complete(retryScope);
                } else {
                    if (Array.isArray(json.stock_updates)) {
                        syncPosStockUpdates(json.stock_updates);
                    }
                    refreshPosMenuStock();
                    loadOnlineOrders(false);
                    showPosAlert(
                        'Order Not Completed',
                        json.msg ||
                            'Unable to process the order.'
                    );
                }

            } catch (
                error
            ) {
                console.error(
                    'Checkout error:',
                    error
                );

                showPosAlert(
                    'Checkout Error',
                    error.message ||
                        'Unable to process the order. Please try again.'
                );
            } finally {
                checkoutBtn.disabled =
                    false;
                checkoutBtn.innerHTML = originalBtnHtml;
            }
        }
    );
}

const printBtn =
    document.getElementById(
        'printBtn'
    );

if (printBtn) {
    printBtn.addEventListener(
        'click',
        () => {
            let targetOrder =
                null;

            if (cart.length > 0) {
                const orderType =
                    document.getElementById(
                        'orderType'
                    )?.value ||
                    'DINE-IN';

                const tableNo =
                    document.getElementById(
                        'tableNo'
                    )?.value.trim() ||
                    '';

                const gcashRef =
                    document.getElementById(
                        'gcashRef'
                    )?.value.trim() ||
                    '';

                const activeNote =
                    currentOrderNote ||
                    document.getElementById(
                        'orderNoteInput'
                    )?.value.trim() ||
                    '';

                const data =
                    totals();

                targetOrder = {
                    receiptNo:
                        document
                            .querySelector(
                                '.order-pill'
                            )
                            ?.textContent
                            .trim() ||
                        'RECEIPT',

                    orderType,
                    tableNo,
                    note:
                        activeNote,
                    items:
                        [...cart],
                    subtotal:
                        data.subtotal,
                    discount:
                        data.discount,
                    discountType:
                        data.discountType,
                    discountIdNumber:
                        data.discountIdNumber,
                    discountCustomerName:
                        data.discountCustomerName,
                    total:
                        data.total,
                    cash:
                        data.cash,
                    change:
                        data.change,
                    paymentMode:
                        currentPaymentMode ||
                        'cash',
                    gcashRef:
                        currentPaymentMode ===
                        'gcash'
                            ? gcashRef
                            : null
                };
            } else if (
                lastCompletedOrder
            ) {
                targetOrder =
                    lastCompletedOrder;
            } else {
                showPosAlert(
                    'Nothing to Print',
                    'There is no current or completed order available to print.'
                );
                return;
            }

            printReceipt(
                targetOrder
            );
        }
    );
}


const onlineOrdersBtn = document.getElementById('onlineOrdersBtn');
const onlineOrdersBadge = document.getElementById('onlineOrdersBadge');
const onlineOrdersOverlay = document.getElementById('onlineOrdersOverlay');
const onlineOrdersList = document.getElementById('onlineOrdersList');
const closeOnlineOrdersBtn = document.getElementById('closeOnlineOrdersBtn');
const refreshOnlineOrdersBtn = document.getElementById('refreshOnlineOrdersBtn');

let onlineOrdersTimer = null;
let knownOnlineOrderIds = null;

function formatOnlineOrderDate(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-PH', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function onlineOrderStatusClass(status) {
    if (status === 'Pending') return 'pending';
    if (status === 'Preparing') return 'preparing';
    if (status === 'Ready') return 'ready';
    return '';
}

function formatOnlineOrderTypeLabel(order) {
    if (!order || order.order_type !== 'ONLINE') {
        return 'Online Pickup';
    }
    const isOutside = String(order.fulfillment_mode || '').toUpperCase() === 'OUTSIDE'
        || String(order.delivery_mode || '').toUpperCase() === 'CUSTOMER_BOOKED';
    return isOutside
        ? 'Online Delivery (Outside Lagro)'
        : 'Online Delivery (Within Lagro)';
}

let onlineOrdersData = [];
let posOnlineFilter = localStorage.getItem('kk_pos_online_filter') || 'all';
let posOnlineSort = localStorage.getItem('kk_pos_online_sort') || 'newest';

function getVisibleOnlineOrders(orders) {
    const filtered = (Array.isArray(orders) ? orders : []).filter(order => {
        if (posOnlineFilter === 'delivery') return order.order_type === 'ONLINE';
        if (posOnlineFilter === 'pickup') return order.order_type !== 'ONLINE';
        if (posOnlineFilter === 'pending') return order.status === 'Pending';
        if (posOnlineFilter === 'preparing') return order.status === 'Preparing';
        if (posOnlineFilter === 'ready') return order.status === 'Ready';
        return true;
    });
    return window.kkSortOrders ? window.kkSortOrders(filtered, posOnlineSort, { timeField: 'created_at' }) : filtered;
}

function renderOnlineOrders(orders) {
    onlineOrdersData = Array.isArray(orders) ? orders : [];
    if (!onlineOrdersList) return;

    const visibleOrders = getVisibleOnlineOrders(onlineOrdersData);
    if (!visibleOrders.length) {
        onlineOrdersList.innerHTML = `
            <div class="online-orders-empty">
                <span class="online-empty-icon"><i class="bi bi-bag-check"></i></span>
                <strong>${onlineOrdersData.length ? 'No matching online orders' : 'No active online orders'}</strong>
                <p>${onlineOrdersData.length ? 'Try another filter pill or sort option.' : 'Today’s active Kenji’s Kitchen online orders will appear here.<br>Use Refresh to check for new orders.'}</p>
            </div>
        `;
        return;
    }

    onlineOrdersList.innerHTML = visibleOrders.map(order => {
        const statusClass = onlineOrderStatusClass(order.status);
        const items = Array.isArray(order.items) ? order.items : [];
        const itemHtml = items.map(item => `
            <div class="online-order-line">
                <div>
                    <span>${escapeHtml(item.quantity)}x ${escapeHtml(item.item_name)}</span>
                    ${item.notes ? `<div style="font-size:0.75rem; color:#B45309; background:#FEF3C7; padding:2px 6px; border-radius:4px; margin-top:2px; font-weight:600;">Note: ${escapeHtml(item.notes)}</div>` : ''}
                </div>
                <strong>${peso.format(Number(item.price || 0) * Number(item.quantity || 0))}</strong>
            </div>
        `).join('');

        const fulfillment = formatOnlineOrderTypeLabel(order);
        const rawNotes = String(order.notes || '')
            .replace(/^\[Courier booking:[^\]]+\]\s*/i, '')
            .replace(/\s+Philippine time(?=\])/gi, '')
            .trim();
        const pickupSchedMatch = rawNotes.match(/\[Pickup:\s*([^\]]+)\]/i);
        const deliverySchedMatch = rawNotes.match(/\[Delivery:\s*([^\]]+)\]/i);
        const scheduleLabel = pickupSchedMatch
            ? `Scheduled Pickup: ${pickupSchedMatch[1].trim()}`
            : (deliverySchedMatch ? `Scheduled Delivery: ${deliverySchedMatch[1].trim()}` : '');
        const extraOrderNote = rawNotes
            .replace(/\[(?:Pickup|Delivery):\s*[^\]]+\]\s*/gi, '')
            .trim();
        const payMethodLower = String(order.payment_method || '').trim().toLowerCase();
        const isCodOrder = order.order_type === 'ONLINE' && (payMethodLower === 'cash on delivery' || payMethodLower === 'cash');
        const orderTotalNum = Number(order.total || 0);
        const changeForNum = Number(order.change_for || 0);
        let codChangeBadgeHtml = '';
        if (isCodOrder) {
            if (changeForNum > orderTotalNum + 0.009) {
                const changeDueNum = Math.max(0, changeForNum - orderTotalNum);
                codChangeBadgeHtml = `<div class="online-order-cod-change"><i class="bi bi-cash-coin" aria-hidden="true"></i> Bill ${peso.format(changeForNum)} · Prepare ${peso.format(changeDueNum)} change</div>`;
            } else {
                codChangeBadgeHtml = `<div class="online-order-cod-exact"><i class="bi bi-check2-circle" aria-hidden="true"></i> Exact Amount (No change needed)</div>`;
            }
        }
        return `
            <div class="online-order-card">
                <div class="online-order-card-head">
                    <div>
                        <div class="online-order-number">#${escapeHtml(order.queue_no)} · ${escapeHtml(order.order_no)}</div>
                        <div class="online-order-meta">
                            ${escapeHtml(order.customer_name || 'Online Customer')} · ${escapeHtml(fulfillment)} · ${escapeHtml(formatOnlineOrderDate(order.created_at))}
                        </div>
                    </div>
                    <span class="online-order-status ${statusClass}">${escapeHtml(order.status)}</span>
                </div>

                ${scheduleLabel ? `
                    <div class="online-order-schedule">
                        <i class="bi bi-calendar2-check" aria-hidden="true"></i>
                        <span>${escapeHtml(scheduleLabel)}</span>
                    </div>
                ` : ''}

                ${order.delivery_address ? `
                    <div class="online-order-meta" style="margin-bottom:8px;">
                        <strong>Delivery:</strong> ${escapeHtml(order.delivery_address)}
                        ${order.customer_phone ? ` · ${escapeHtml(order.customer_phone)}` : ''}
                    </div>
                ` : ''}

                ${extraOrderNote ? `
                    <div class="online-order-note-box">
                        <strong>Order Note:</strong> ${escapeHtml(extraOrderNote)}
                    </div>
                ` : ''}

                <div class="online-order-lines">
                    ${itemHtml || '<div class="online-order-meta">No item details available.</div>'}
                </div>

                <div class="online-order-footer" style="display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; padding-top:10px; border-top:1px dashed #e6e3d8;">
                    <div>
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                            <span style="font-weight:700; color:#29251f;">${escapeHtml(order.payment_method || 'Payment not set')}</span>
                            ${order.payment_method === 'GCash' ? (order.payment_status === 'Paid' ? '<span class="badge" style="font-size:0.72rem; background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; padding:2px 8px; border-radius:999px; font-weight:700;">Verified</span>' : '<span class="badge" style="font-size:0.72rem; background:#fff3c4; color:#775600; border:1px solid #eed98a; padding:2px 8px; border-radius:999px; font-weight:700;">Pending</span>') : ''}
                            <strong class="online-order-total" style="margin-left:2px; font-size:0.98rem; color:#24231e;">${peso.format(Number(order.total || 0))}</strong>
                        </div>
                        ${codChangeBadgeHtml}
                        ${order.payment_reference ? `<div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:0.76rem; color:#655c4c;">
                            <span>Ref: <strong style="color:#29251f;">${escapeHtml(order.payment_reference)}</strong></span>
                            ${Number(order.has_payment_proof) === 1 ? `<a href="../online/api/payment_proof.php?order_id=${Number(order.id)}" target="_blank" rel="noopener noreferrer" class="pos-online-proof-link"><i class="bi bi-image" aria-hidden="true"></i> View Screenshot</a>` : ''}
                        </div>` : ''}
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        ${(order.payment_method === 'GCash' && order.payment_status === 'Pending') ? `
                            <button type="button" class="pos-online-verify-btn" onclick="verifyOnlineGCashPayment(${order.id}, '${escapeHtml(order.payment_reference || '')}')">Verify GCash</button>
                        ` : ''}
                        ${order.order_type === 'ONLINE' ? `
                            <a class="pos-online-manage-btn" href="../delivery/index.php">Manage Delivery</a>
                        ` : order.status === 'Ready' ? `
                            <button type="button" class="pos-online-verify-btn" onclick="completeOnlineOrder(${order.id})">
                                <i class="bi bi-check2-circle"></i> Complete Order
                            </button>
                        ` : '<span class="online-order-meta">Awaiting kitchen readiness</span>'}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

window.verifyOnlineGCashPayment = async function(orderId, ref) {
    const order = onlineOrdersData.find(o => Number(o.id) === Number(orderId));
    if (window.kkConfirm) {
        const confirmed = await window.kkConfirm({
            title: 'Verify GCash Payment?',
            details: [
                { label: 'Order Number', value: order?.order_no || ('#' + orderId) },
                { label: 'Order Total', value: peso.format(Number(order?.total || 0)) },
                { label: 'GCash Reference', value: ref || order?.payment_reference || 'N/A' }
            ],
            message: 'Confirm that you have verified this GCash payment in your account before marking this order as Paid.',
            confirmText: 'Verify GCash Payment',
            cancelText: 'Cancel',
            variant: 'primary'
        });
        if (!confirmed) return;
    }
    try {
        const csrf = typeof KK_CSRF_TOKEN !== 'undefined' ? KK_CSRF_TOKEN : '';
        const res = await fetch('../reports/complete_online_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ order_id: Number(orderId), csrf_token: csrf })
        });
        const data = await res.json();
        if (data.ok) {
            if (window.kkNotify) window.kkNotify(data.message || 'GCash payment verified successfully.', 'success', { title: 'Payment Verified' });
            loadOnlineOrders(true);
        } else {
            if (window.kkNotify) window.kkNotify(data.message || 'Unable to verify payment.', 'error', { title: 'Verification Error' });
        }
    } catch (e) {
        if (window.kkNotify) window.kkNotify('Network error while verifying payment.', 'error', { title: 'Network Error' });
    }
};

window.completeOnlineOrder = async function(orderId) {
    const order = onlineOrdersData.find(o => Number(o.id) === Number(orderId));
    const isAlreadyPaid = String(order?.payment_status || '') === 'Paid';
    const totalFormatted = peso.format(Number(order?.total || 0));
    const paymentMethod = order?.payment_method || 'Cash';

    if (window.kkConfirm) {
        const confirmed = await window.kkConfirm({
            title: 'Complete Pickup Order?',
            details: [
                { label: 'Order Number', value: order?.order_no || ('#' + orderId) },
                { label: 'Amount to Collect', value: isAlreadyPaid ? `${totalFormatted} (Paid)` : totalFormatted },
                { label: 'Payment Method', value: paymentMethod }
            ],
            message: isAlreadyPaid
                ? 'Confirm that this paid pickup order is being handed over to the customer.'
                : "Confirm that you have received the customer's payment before completing this order.",
            confirmText: 'Confirm Payment & Complete Order',
            cancelText: 'Cancel',
            variant: 'primary'
        });
        if (!confirmed) return;
    }
    try {
        const res = await fetch('../serving/api_mark_served.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': typeof KK_CSRF_TOKEN !== 'undefined' ? KK_CSRF_TOKEN : '' },
            body: JSON.stringify({ order_id: orderId, confirm_payment: true })
        });
        const data = await res.json();
        if (data.ok) {
            if (window.kkNotify) window.kkNotify(data.msg || 'Pickup order completed and marked as served.', 'success', { title: 'Order Completed' });
            loadOnlineOrders(true);
        } else {
            if (window.kkNotify) window.kkNotify(data.msg || 'Unable to complete order.', 'error', { title: 'Completion Error' });
        }
    } catch (e) {
        if (window.kkNotify) window.kkNotify('Network error while completing order.', 'error', { title: 'Network Error' });
    }
};

async function loadOnlineOrders(showLoading = false) {
    if (!onlineOrdersList) return;

    if (showLoading) {
        onlineOrdersList.innerHTML = '<div class="online-orders-empty">Loading online orders...</div>';
    }

    try {
        const res = await fetch('fetch_online_orders.php', {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });

        const json = await res.json();
        if (!res.ok || !json.ok) {
            throw new Error(json.msg || 'Unable to load online orders.');
        }

        const orders = Array.isArray(json.orders) ? json.orders : [];
        const pendingCount = Number(json.pending_count || 0);
        const currentIds = new Set(orders.map(order => String(order.id)));

        if (onlineOrdersBadge) {
            onlineOrdersBadge.textContent = String(pendingCount);
            onlineOrdersBadge.classList.toggle('has-pending', pendingCount > 0);
        }

        const newOrders = knownOnlineOrderIds !== null
            ? orders.filter(order => !knownOnlineOrderIds.has(String(order.id)))
            : [];
        if (newOrders.length > 0) {
            window.KenjiOrderSounds?.play('online');
            onlineOrdersBtn?.classList.add('has-new-order');
            setTimeout(() => onlineOrdersBtn?.classList.remove('has-new-order'), 2500);
            refreshPosMenuStock();
            if (window.kkNotify) {
                const newest = newOrders[0];
                const typeLabel = formatOnlineOrderTypeLabel(newest);
                const payLabel = newest.payment_method ? ` · ${newest.payment_method}` : '';
                const extra = newOrders.length > 1 ? ` (+${newOrders.length - 1} more)` : '';
                window.kkNotify(
                    `${typeLabel}${payLabel} · ${peso.format(Number(newest.total || 0))}${extra}`,
                    'info',
                    {
                        title: `New ${typeLabel}: ${newest.order_no || ''}`.trim(),
                        dedupeKey: `pos-online-${newest.id}`,
                        duration: 7000
                    }
                );
            }
        }

        knownOnlineOrderIds = currentIds;
        renderOnlineOrders(orders);
    } catch (error) {
        console.error('Online order queue error:', error);
        if (onlineOrdersList) {
            onlineOrdersList.innerHTML = `<div class="online-orders-error">${escapeHtml(error.message || 'Unable to load online orders.')}</div>`;
        }
    }
}

function openOnlineOrdersModal() {
    if (!onlineOrdersOverlay) return;
    onlineOrdersOverlay.hidden = false;
    loadOnlineOrders(true);
}

function closeOnlineOrdersModal() {
    if (!onlineOrdersOverlay) return;
    onlineOrdersOverlay.hidden = true;
}

if (onlineOrdersBtn) {
    onlineOrdersBtn.addEventListener('click', openOnlineOrdersModal);
}

if (closeOnlineOrdersBtn) {
    closeOnlineOrdersBtn.addEventListener('click', closeOnlineOrdersModal);
}

if (refreshOnlineOrdersBtn) {
    refreshOnlineOrdersBtn.addEventListener('click', () => loadOnlineOrders(true));
}

(function initPosOnlineQueueControls() {
    const pillsEl = document.getElementById('posOnlineFilterPills');
    const sortEl = document.getElementById('posOnlineSortSelect');
    if (pillsEl) {
        pillsEl.querySelectorAll('[data-pos-online-filter]').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-pos-online-filter') === posOnlineFilter);
        });
        pillsEl.addEventListener('click', event => {
            const btn = event.target.closest('[data-pos-online-filter]');
            if (!btn) return;
            posOnlineFilter = btn.getAttribute('data-pos-online-filter') || 'all';
            localStorage.setItem('kk_pos_online_filter', posOnlineFilter);
            pillsEl.querySelectorAll('[data-pos-online-filter]').forEach(b => b.classList.toggle('active', b === btn));
            renderOnlineOrders(onlineOrdersData);
        });
    }
    if (sortEl) {
        sortEl.value = posOnlineSort;
        sortEl.addEventListener('change', () => {
            posOnlineSort = sortEl.value || 'newest';
            localStorage.setItem('kk_pos_online_sort', posOnlineSort);
            renderOnlineOrders(onlineOrdersData);
        });
    }
})();

if (onlineOrdersOverlay) {
    onlineOrdersOverlay.addEventListener('click', event => {
        if (event.target === onlineOrdersOverlay) {
            closeOnlineOrdersModal();
        }
    });
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && onlineOrdersOverlay && !onlineOrdersOverlay.hidden) {
        closeOnlineOrdersModal();
    }
});

let posStockSyncInflight = false;
let posStockLastSignature = null;
let posStockTimer = null;

async function refreshPosMenuStock() {
    if (posStockSyncInflight) return;
    posStockSyncInflight = true;
    try {
        const res = await fetch('fetch_stock.php', {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });
        if (!res.ok) return;
        const json = await res.json();
        if (!json || !json.ok || !Array.isArray(json.items)) return;
        const signature = json.items
            .map(it => `${it.id}:${Math.max(0, Math.floor(Number(it.stock ?? 0)))}:${it.availability || 'Available'}`)
            .join('|');
        if (signature === posStockLastSignature) return;
        posStockLastSignature = signature;
        syncPosStockUpdates(json.items);
        updateMenuCardControls();
    } catch (_) {
        // Ignore transient network errors during background stock sync.
    } finally {
        posStockSyncInflight = false;
    }
}

loadOnlineOrders(false);
onlineOrdersTimer = setInterval(() => {
    if (!document.hidden) loadOnlineOrders(false);
}, 5000);

refreshPosMenuStock();
posStockTimer = setInterval(() => {
    if (!document.hidden) refreshPosMenuStock();
}, 4000);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        loadOnlineOrders(false);
        refreshPosMenuStock();
    }
});

renderCart();
