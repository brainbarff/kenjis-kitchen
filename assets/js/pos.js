let cart = [];
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
            gap: 8px;
        }

        .pos-checkout-action {
            height: 42px;
            border-radius: 7px;
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
            background: #ffffff;
            border: 1px solid #cfcfcf;
            color: #111111;
        }

        .pos-checkout-print:hover {
            background: #f7f7f7;
            border-color: #999999;
        }

        .pos-checkout-new {
            background: #F2C12E;
            border: 1px solid #D8AF18;
            color: #111111;
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

    if (mode === 'cash') {
        if (tenderCashWrap) {
            tenderCashWrap.style.display = 'flex';
        }

        if (tenderGcashWrap) {
            tenderGcashWrap.style.display = 'none';
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

        const data = totals();

        if (cashInput) {
            cashInput.value = data.total;
        }

        if (gcashRefInput) {
            setTimeout(() => {
                gcashRefInput.focus();
            }, 50);
        }
    }

    renderCart();
};

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

window.clearAllOrder = function() {
    cart = [];

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

    renderCart();
};

function filterMenu() {
    const activeTab =
        document.querySelector('.tab.active');

    const cat =
        activeTab
            ? activeTab.dataset.cat
            : 'all';

    const searchInput =
        document.querySelector(
            'input[type="search"]'
        ) ||
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

            const itemName = (
                card.dataset.name ||
                card.textContent
            ).toLowerCase();

            const matchesCat =
                cat === 'all' ||
                itemCat === cat;

            const matchesSearch =
                !query ||
                itemName.includes(query);

            card.style.display =
                matchesCat &&
                matchesSearch
                    ? ''
                    : 'none';
        });
}

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
            filterMenu();
        });
    });

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

function addToCart(
    id,
    name,
    price,
    stock
) {
    const maxStock =
        Number(stock ?? 999);

    const existing =
        cart.find(
            item => item.id === id
        );

    if (existing) {
        if (existing.qty >= maxStock) {
            showPosAlert(
                'Stock Limit Reached',
                `Only ${maxStock} pieces are available in stock.`
            );
            return;
        }

        existing.qty += 1;
    } else {
        if (maxStock <= 0) {
            showPosAlert(
                'Out of Stock',
                'This item is currently out of stock.'
            );
            return;
        }

        cart.push({
            id: id,
            name: name,
            price: Number(price),
            stock: maxStock,
            qty: 1
        });
    }

    renderCart();
}

function decreaseFromCart(id) {
    const item =
        cart.find(
            row => row.id === id
        );

    if (!item) {
        return;
    }

    item.qty -= 1;

    if (item.qty <= 0) {
        cart =
            cart.filter(
                row => row.id !== id
            );
    }

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
                        '.bilao-type-row'
                    ) ||
                    event.target.closest(
                        '.bilao-sizes-row'
                    )
                ) {
                    return;
                }

                if (
                    card.classList.contains(
                        'unavailable'
                    )
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
                    card.dataset.stock
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

                addToCart(
                    card.dataset.id,
                    card.dataset.name,
                    card.dataset.price,
                    card.dataset.stock
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
                    btn.dataset.id
                );
            }
        );
    });

function totals() {
    const subtotal =
        cart.reduce(
            (sum, item) =>
                sum +
                (
                    item.price *
                    item.qty
                ),
            0
        );

    const discount =
        Number(
            discountInput?.value || 0
        );

    const tax = 0;

    const total =
        Math.max(
            subtotal -
                discount +
                tax,
            0
        );

    let cash =
        Number(
            cashInput?.value || 0
        );

    if (
        currentPaymentMode ===
        'gcash'
    ) {
        cash = total;
    }

    return {
        subtotal,
        discount,
        tax,
        total,
        cash,
        change:
            Math.max(
                cash -
                    total,
                0
            )
    };
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
            const id =
                card.dataset.id;

            const stock =
                Number(
                    card.dataset.stock ??
                    999
                );

            const cartItem =
                cart.find(
                    item =>
                        item.id ===
                        id
                );

            const currentQty =
                cartItem
                    ? cartItem.qty
                    : 0;

            const plusBtn =
                card.querySelector(
                    '.btn-card-plus'
                );

            const qtyDisplay =
                card.querySelector(
                    '.qty-display'
                );

            if (plusBtn) {
                plusBtn.disabled =
                    stock <= 0 ||
                    currentQty >= stock;
            }

            if (qtyDisplay) {
                qtyDisplay.textContent =
                    currentQty;
            }
        });
}

function renderCart() {
    if (!cartItems) {
        return;
    }

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
        cart.forEach(item => {
            const row =
                document.createElement(
                    'div'
                );

            row.className =
                'cart-row';

            row.innerHTML = `
                <div>
                    <strong>
                        ${escapeHtml(item.name)}
                    </strong>

                    <p class="muted">
                        ${peso.format(item.price)}
                    </p>

                    <div class="qty">
                        <button
                            type="button"
                            data-minus="${escapeHtml(item.id)}"
                            aria-label="Decrease quantity"
                        >−</button>

                        <input
                            type="number"
                            class="cart-qty-input"
                            data-id="${escapeHtml(item.id)}"
                            value="${item.qty}"
                            min="1"
                            max="${item.stock}"
                            step="1"
                            inputmode="numeric"
                            aria-label="Quantity for ${escapeHtml(item.name)}"
                        >

                        <button
                            type="button"
                            data-plus="${escapeHtml(item.id)}"
                            ${item.qty >= item.stock ? 'disabled' : ''}
                            aria-label="Increase quantity"
                        >+</button>

                        <button
                            type="button"
                            data-remove="${escapeHtml(item.id)}"
                            aria-label="Remove ${escapeHtml(item.name)}"
                            title="Remove item"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <strong>
                    ${peso.format(
                        item.price *
                        item.qty
                    )}
                </strong>
            `;

            cartItems.appendChild(
                row
            );
        });
    }

    updateTotalsDisplay();
    updateMenuCardControls();
}

if (cartItems) {
    cartItems.addEventListener(
        'click',
        event => {
            const plus =
                event.target.closest(
                    '[data-plus]'
                );

            const minus =
                event.target.closest(
                    '[data-minus]'
                );

            const remove =
                event.target.closest(
                    '[data-remove]'
                );

            if (plus) {
                const item =
                    cart.find(
                        i =>
                            i.id ===
                            plus.dataset.plus
                    );

                if (item) {
                    if (
                        item.qty >=
                        item.stock
                    ) {
                        showPosAlert(
                            'Stock Limit Reached',
                            `Only ${item.stock} pieces are available in stock.`
                        );
                        return;
                    }

                    item.qty += 1;
                    renderCart();
                }

                return;
            }

            if (minus) {
                decreaseFromCart(
                    minus.dataset.minus
                );
                return;
            }

            if (remove) {
                cart =
                    cart.filter(
                        item =>
                            item.id !==
                            remove.dataset.remove
                    );

                renderCart();
            }
        }
    );

    cartItems.addEventListener(
        'input',
        event => {
            if (
                !event.target.classList.contains(
                    'cart-qty-input'
                )
            ) {
                return;
            }

            const input =
                event.target;

            const id =
                input.dataset.id;

            const item =
                cart.find(
                    row =>
                        row.id ===
                        id
                );

            if (!item) {
                return;
            }

            let value =
                input.value.replace(
                    /\D/g,
                    ''
                );

            if (value === '') {
                updateTotalsDisplay();
                return;
            }

            let newQty =
                parseInt(
                    value,
                    10
                );

            if (
                isNaN(newQty) ||
                newQty < 1
            ) {
                newQty = 1;
            }

            if (
                newQty >
                item.stock
            ) {
                newQty =
                    item.stock;
            }

            item.qty =
                newQty;

            input.value =
                String(
                    newQty
                );

            const row =
                input.closest(
                    '.cart-row'
                );

            if (row) {
                const rowTotal =
                    row.querySelector(
                        ':scope > strong:last-child'
                    );

                if (rowTotal) {
                    rowTotal.textContent =
                        peso.format(
                            item.price *
                            item.qty
                        );
                }
            }

            updateTotalsDisplay();
            updateMenuCardControls();
        }
    );

    cartItems.addEventListener(
        'change',
        event => {
            if (
                !event.target.classList.contains(
                    'cart-qty-input'
                )
            ) {
                return;
            }

            const input =
                event.target;

            const id =
                input.dataset.id;

            const item =
                cart.find(
                    row =>
                        row.id ===
                        id
                );

            if (!item) {
                return;
            }

            let newQty =
                parseInt(
                    input.value,
                    10
                );

            if (
                isNaN(newQty) ||
                newQty < 1
            ) {
                newQty = 1;
            }

            if (
                newQty >
                item.stock
            ) {
                showPosAlert(
                    'Stock Limit Reached',
                    `Only ${item.stock} pieces are available in stock.`
                );

                newQty =
                    item.stock;
            }

            item.qty =
                newQty;

            input.value =
                String(
                    newQty
                );

            renderCart();
        }
    );
}

if (discountInput) {
    discountInput.addEventListener(
        'input',
        () => {
            renderCart();
        }
    );
}

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
                        class="pos-checkout-action pos-checkout-print"
                        id="checkoutSuccessPrintBtn"
                    >
                        <i class="bi bi-printer"></i>
                        Print Receipt
                    </button>

                    <button
                        type="button"
                        class="pos-checkout-action pos-checkout-new"
                        id="checkoutSuccessNewBtn"
                    >
                        <i class="bi bi-plus-circle"></i>
                        New Order
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
            'checkoutSuccessNewBtn'
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
                printReceipt(
                    orderData
                );
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
                                    Discount:
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
    orderData
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

const checkoutBtn =
    document.getElementById(
        'checkoutBtn'
    );

if (checkoutBtn) {
    checkoutBtn.addEventListener(
        'click',
        async () => {
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
                data.discount < 0 ||
                data.discount >
                    data.subtotal
            ) {
                showPosAlert(
                    'Invalid Discount',
                    'The discount cannot be negative or greater than the order subtotal.'
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
                ...data
            };

            checkoutBtn.disabled =
                true;

            try {
                const res =
                    await fetch(
                        'process_order.php',
                        {
                            method:
                                'POST',
                            headers: {
                                'Content-Type':
                                    'application/json'
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
                        discount:
                            data.discount,
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
                } else {
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

renderCart();