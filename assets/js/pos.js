let cart = [];
let currentPaymentMode = null;
let currentOrderNote = '';
let lastCompletedOrder = null;

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
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
    const btnCash = document.getElementById('btnMethodCash');
    const btnGcash = document.getElementById('btnMethodGcash');
    const tenderCashWrap = document.getElementById('tenderCashWrap');
    const tenderGcashWrap = document.getElementById('tenderGcashWrap');

    if (btnCash) btnCash.classList.toggle('active', mode === 'cash');
    if (btnGcash) btnGcash.classList.toggle('active', mode === 'gcash');

    if (mode === 'cash') {
        if (tenderCashWrap) tenderCashWrap.style.display = 'flex';
        if (tenderGcashWrap) tenderGcashWrap.style.display = 'none';
        cashInput.value = '';
    } else if (mode === 'gcash') {
        if (tenderCashWrap) tenderCashWrap.style.display = 'none';
        if (tenderGcashWrap) tenderGcashWrap.style.display = 'flex';
        
        const data = totals();
        cashInput.value = data.total;
    }
    renderCart();
};

window.clearAllOrder = function() {
    if (!cart || cart.length === 0) return;
    cart = [];
    
    const tableNo = document.getElementById('tableNo');
    if (tableNo) tableNo.value = '';
    if (cashInput) cashInput.value = '';
    if (gcashRefInput) gcashRefInput.value = '';
    if (discountInput) discountInput.value = 0;
    
    currentOrderNote = '';
    if (orderNoteInput) orderNoteInput.value = '';
    updateNoteDisplay();

    currentPaymentMode = null;
    const btnCash = document.getElementById('btnMethodCash');
    const btnGcash = document.getElementById('btnMethodGcash');
    const tenderCashWrap = document.getElementById('tenderCashWrap');
    const tenderGcashWrap = document.getElementById('tenderGcashWrap');

    if (btnCash) btnCash.classList.remove('active');
    if (btnGcash) btnGcash.classList.remove('active');
    if (tenderCashWrap) tenderCashWrap.style.display = 'flex';
    if (tenderGcashWrap) tenderGcashWrap.style.display = 'none';

    renderCart();
};

function filterMenu() {
    const activeTab = document.querySelector('.tab.active');
    const cat = activeTab ? activeTab.dataset.cat : 'all';
    
    const searchInput = document.querySelector('input[type="search"]') || document.querySelector('.navbar input') || document.querySelector('header input');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

    document.querySelectorAll('.food-card').forEach(card => {
        const itemCat = card.dataset.cat || '';
        const itemName = (card.dataset.name || card.textContent).toLowerCase();

        const matchesCat = cat === 'all' || itemCat === cat;
        const matchesSearch = !query || itemName.includes(query);

        card.style.display = matchesCat && matchesSearch ? '' : 'none';
    });
}

document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(btn => btn.classList.remove('active'));
        tab.classList.add('active');
        filterMenu();
    });
});

const posSearchInput = document.querySelector('input[type="search"]') || document.querySelector('.navbar input') || document.querySelector('header input');
if (posSearchInput) {
    posSearchInput.addEventListener('input', filterMenu);
}

function addToCart(id, name, price, stock) {
    const maxStock = Number(stock ?? 999);
    const existing = cart.find(item => item.id === id);

    if (existing) {
        if (existing.qty >= maxStock) {
            alert(`Cannot add more. Only ${maxStock} pieces available in stock.`);
            return;
        }
        existing.qty += 1;
    } else {
        if (maxStock <= 0) {
            alert('This item is currently out of stock.');
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
    const item = cart.find(row => row.id === id);
    if (!item) return;

    item.qty -= 1;
    if (item.qty <= 0) {
        cart = cart.filter(row => row.id !== id);
    }
    renderCart();
}

document.querySelectorAll('.food-card').forEach(card => {
    card.addEventListener('click', (e) => {
        if (e.target.closest('.qty-stepper') || e.target.closest('.bilao-type-row') || e.target.closest('.bilao-sizes-row')) {
            return;
        }
        if (card.classList.contains('unavailable')) {
            alert('This item is currently unavailable.');
            return;
        }
        addToCart(card.dataset.id, card.dataset.name, card.dataset.price, card.dataset.stock);
    });
});

document.querySelectorAll('.btn-card-plus').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const card = btn.closest('.food-card');
        addToCart(card.dataset.id, card.dataset.name, card.dataset.price, card.dataset.stock);
    });
});

document.querySelectorAll('.btn-card-minus').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        decreaseFromCart(btn.dataset.id);
    });
});

function totals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const discount = Number(discountInput.value || 0);
    const tax = 0;
    const total = Math.max(subtotal - discount + tax, 0);
    
    let cash = Number(cashInput.value || 0);
    if (currentPaymentMode === 'gcash') {
        cash = total;
    }

    return { subtotal, discount, tax, total, cash, change: Math.max(cash - total, 0) };
}

function renderCart() {
    cartItems.innerHTML = '';

    if (clearCartBtn) {
        clearCartBtn.disabled = (cart.length === 0);
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
            const row = document.createElement('div');
            row.className = 'cart-row';
            row.innerHTML = `
                <div>
                    <strong>${item.name}</strong>
                    <p class="muted">${peso.format(item.price)}</p>
                    <div class="qty">
                        <button data-minus="${item.id}">-</button>
                        <span>${item.qty}</span>
                        <button data-plus="${item.id}" ${item.qty >= item.stock ? 'disabled' : ''}>+</button>
                        <button data-remove="${item.id}"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
                <strong>${peso.format(item.price * item.qty)}</strong>
            `;
            cartItems.appendChild(row);
        });
    }

    const data = totals();
    document.getElementById('subtotal').textContent = peso.format(data.subtotal);
    document.getElementById('tax').textContent = peso.format(data.tax);
    document.getElementById('grandTotal').textContent = peso.format(data.total);
    document.getElementById('change').textContent = peso.format(data.change);

    document.querySelectorAll('.qty-display').forEach(span => {
        span.textContent = '0';
    });

    cart.forEach(item => {
        const cardQty = document.getElementById(`card-qty-${item.id}`);
        if (cardQty) {
            cardQty.textContent = item.qty;
        }
    });

    document.querySelectorAll('.food-card').forEach(card => {
        const id = card.dataset.id;
        const stock = Number(card.dataset.stock ?? 999);
        const cartItem = cart.find(i => i.id === id);
        const currentQty = cartItem ? cartItem.qty : 0;
        const plusBtn = card.querySelector('.btn-card-plus');
        
        if (plusBtn) {
            plusBtn.disabled = stock <= 0 || currentQty >= stock;
        }
    });
}

cartItems.addEventListener('click', event => {
    const plus = event.target.closest('[data-plus]');
    const minus = event.target.closest('[data-minus]');
    const remove = event.target.closest('[data-remove]');

    if (plus) {
        const item = cart.find(i => i.id === plus.dataset.plus);
        if (item) {
            if (item.qty >= item.stock) {
                alert(`Cannot add more. Only ${item.stock} pieces available in stock.`);
                return;
            }
            item.qty += 1;
            renderCart();
        }
    }

    if (minus) {
        decreaseFromCart(minus.dataset.minus);
    }

    if (remove) {
        cart = cart.filter(item => item.id !== remove.dataset.remove);
        renderCart();
    }
});

discountInput.addEventListener('input', renderCart);
cashInput.addEventListener('input', renderCart);

function generatePrintReceiptHtml(orderData) {
    const itemsHtml = orderData.items.map(item => `
        <tr>
            <td style="padding: 2px 0; vertical-align: top;">${item.qty}x</td>
            <td style="padding: 2px 4px; vertical-align: top;">${item.name}</td>
            <td style="padding: 2px 0; text-align: right; vertical-align: top; white-space: nowrap;">${peso.format(item.price * item.qty)}</td>
        </tr>
    `).join('');

    return `
        <div style="text-align: center; margin-bottom: 8px;">
            <h2 style="margin: 0; font-size: 16px; font-weight: 800; letter-spacing: 1px;">KENJI'S KITCHEN</h2>
            <p style="margin: 2px 0; font-size: 10px;">Restaurant Point of Sale</p>
            <p style="margin: 2px 0; font-size: 10px;">${new Date().toLocaleString()}</p>
            <div style="border-bottom: 1px dashed #000; margin: 6px 0;"></div>
            <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 12px;">
                <span>${orderData.receiptNo}</span>
                <span>${orderData.orderType}</span>
            </div>
            ${orderData.tableNo ? `<div style="text-align: left; font-size: 11px; margin-top: 2px;">Table: ${orderData.tableNo}</div>` : ''}
        </div>

        ${orderData.note ? `
            <div style="border: 1px dashed #000; padding: 5px; margin: 6px 0; font-size: 11px; background: #fafafa;">
                <strong>NOTE:</strong> ${orderData.note}
            </div>
        ` : ''}

        <div style="border-bottom: 1px dashed #000; margin: 6px 0;"></div>
        <table style="width: 100%; font-size: 11px; border-collapse: collapse;">
            ${itemsHtml}
        </table>
        <div style="border-bottom: 1px dashed #000; margin: 6px 0;"></div>

        <table style="width: 100%; font-size: 11px; border-collapse: collapse;">
            <tr>
                <td style="padding: 1px 0;">Subtotal:</td>
                <td style="text-align: right; padding: 1px 0;">${peso.format(orderData.subtotal)}</td>
            </tr>
            ${orderData.discount > 0 ? `
            <tr>
                <td style="padding: 1px 0;">Discount:</td>
                <td style="text-align: right; padding: 1px 0;">-${peso.format(orderData.discount)}</td>
            </tr>` : ''}
            <tr style="font-weight: 800; font-size: 13px;">
                <td style="padding: 4px 0 2px;">TOTAL:</td>
                <td style="text-align: right; padding: 4px 0 2px;">${peso.format(orderData.total)}</td>
            </tr>
            <tr>
                <td style="padding: 1px 0;">Payment (${orderData.paymentMode || 'N/A'}):</td>
                <td style="text-align: right; padding: 1px 0;">${peso.format(orderData.cash)}</td>
            </tr>
            ${orderData.paymentMode === 'cash' ? `
            <tr>
                <td style="padding: 1px 0;">Change:</td>
                <td style="text-align: right; padding: 1px 0;">${peso.format(orderData.change)}</td>
            </tr>` : ''}
            ${orderData.gcashRef ? `
            <tr>
                <td style="padding: 1px 0;">GCash Ref:</td>
                <td style="text-align: right; padding: 1px 0;">${orderData.gcashRef}</td>
            </tr>` : ''}
        </table>

        <div style="border-bottom: 1px dashed #000; margin: 8px 0;"></div>
        <div style="text-align: center; font-size: 10px; margin-top: 6px;">
            <p style="margin: 2px 0;">Thank you for dining with us!</p>
            <p style="margin: 2px 0;">Please come again.</p>
        </div>
    `;
}

document.getElementById('checkoutBtn').addEventListener('click', async () => {
    const orderType = document.getElementById('orderType').value;
    const tableNo = document.getElementById('tableNo').value.trim();
    const gcashRef = document.getElementById('gcashRef')?.value.trim() || '';
    const activeNote = currentOrderNote 
        || document.getElementById('orderNoteInput')?.value.trim() 
        || '';
    const data = totals();

    if (!cart.length) return alert('Cart is empty.');
    if (!orderType) return alert('Please select order type.');
    if (orderType === 'DINE-IN' && !tableNo) return alert('Table number is required for dine-in.');
    if (!currentPaymentMode) return alert('Please select a payment method (Cash or GCash).');
    if (data.discount < 0 || data.discount > data.subtotal) return alert('Invalid discount value.');
    
    if (currentPaymentMode === 'cash' && data.cash < data.total) {
        return alert('Cash tendered is insufficient.');
    }

    const payload = {
        cart,
        orderType,
        tableNo,
        orderNote: activeNote,
        paymentMode: currentPaymentMode,
        gcashRef: currentPaymentMode === 'gcash' ? gcashRef : null,
        ...data
    };

    const res = await fetch('process_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const json = await res.json();

    if (json.ok) {
        lastCompletedOrder = {
            receiptNo: json.receipt_no || json.order_no,
            orderType,
            tableNo,
            note: activeNote,
            items: [...cart],
            subtotal: data.subtotal,
            discount: data.discount,
            total: data.total,
            cash: data.cash,
            change: data.change,
            paymentMode: currentPaymentMode,
            gcashRef: currentPaymentMode === 'gcash' ? gcashRef : null
        };

        alert(`Order saved successfully! Receipt: ${json.receipt_no || json.order_no}`);
        window.clearAllOrder();
    } else {
        alert(json.msg || 'Unable to process order.');
    }
});

document.getElementById('printBtn').addEventListener('click', () => {
    const printContainer = document.getElementById('posPrintReceipt');
    if (!printContainer) return;

    let targetOrder = null;

    if (cart.length > 0) {
        const orderType = document.getElementById('orderType').value || 'DINE-IN';
        const tableNo = document.getElementById('tableNo').value.trim();
        const gcashRef = document.getElementById('gcashRef')?.value.trim() || '';
        const activeNote = currentOrderNote 
            || document.getElementById('orderNoteInput')?.value.trim() 
            || '';
        const data = totals();

        targetOrder = {
            receiptNo: document.querySelector('.order-pill')?.textContent.trim() || 'RECEIPT',
            orderType,
            tableNo,
            note: activeNote,
            items: [...cart],
            subtotal: data.subtotal,
            discount: data.discount,
            total: data.total,
            cash: data.cash,
            change: data.change,
            paymentMode: currentPaymentMode || 'cash',
            gcashRef: currentPaymentMode === 'gcash' ? gcashRef : null
        };
    } else if (lastCompletedOrder) {
        targetOrder = lastCompletedOrder;
    } else {
        return alert('No current or recent order to print.');
    }

    printContainer.innerHTML = generatePrintReceiptHtml(targetOrder);
    window.print();
});

renderCart();