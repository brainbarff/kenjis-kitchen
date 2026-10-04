const state = {
    categories: [],
    items: [],
    activeCategory: 'all',
    search: '',
    maxPrice: 1600,
    cart: JSON.parse(localStorage.getItem('kenji_online_cart') || '[]'),
    selectedItem: null,
    modalQty: 1,
    latestOrder: null,
    user: null
};

const API_BASE = './api';
const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

// Core DOM Elements
const categoryTabs = document.getElementById('categoryTabs');
const foodGrid = document.getElementById('foodGrid');
const searchInput = document.getElementById('searchInput');
const priceRange = document.getElementById('priceRange');
const priceLabel = document.getElementById('priceLabel');

// Cart Drawer Elements
const cartPanel = document.getElementById('cartPanel');
const cartBackdrop = document.getElementById('cartBackdrop');
const cartItems = document.getElementById('cartItems');
const cartBadge = document.getElementById('cartBadge');
const cartCountText = document.getElementById('cartCountText');
const deliveryProgressText = document.getElementById('deliveryProgressText');
const deliveryProgressFill = document.getElementById('deliveryProgressFill');
const subtotalText = document.getElementById('subtotalText');
const deliveryText = document.getElementById('deliveryText');
const totalText = document.getElementById('totalText');

// Item Modal Elements
const itemModal = document.getElementById('itemModal');
const modalImage = document.getElementById('modalImage');
const modalCategory = document.getElementById('modalCategory');
const modalStockPill = document.getElementById('modalStockPill');
const modalTitle = document.getElementById('modalTitle');
const modalDescription = document.getElementById('modalDescription');
const modalSpecialNotes = document.getElementById('modalSpecialNotes');
const modalQty = document.getElementById('modalQty');
const modalPrice = document.getElementById('modalPrice');

// Auth & Checkout Elements
const authModal = document.getElementById('authModal');
const authBannerTitle = document.getElementById('authBannerTitle');
const authBannerSub = document.getElementById('authBannerSub');
const authSwitchBtn = document.getElementById('authSwitchBtn');
const authFormTitle = document.getElementById('authFormTitle');
const signInForm = document.getElementById('signInForm');
const registerForm = document.getElementById('registerForm');

const historyGrid = document.getElementById('historyGrid');
const guestNotice = document.getElementById('guestNotice');
const guestNoticeText = document.getElementById('guestNoticeText');
const guestAccountOpt = document.getElementById('guestAccountOpt');
const saveAccountCheckbox = document.getElementById('saveAccountCheckbox');
const guestPasswordWrap = document.getElementById('guestPasswordWrap');
const guestPassword = document.getElementById('guestPassword');

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function saveCart() {
    localStorage.setItem('kenji_online_cart', JSON.stringify(state.cart));
}

function getItem(id) {
    return state.items.find(item => Number(item.id) === Number(id));
}

function currentUnitPrice(item) {
    return Number(item?.effective_price ?? item?.price ?? 0);
}

// ==========================================================================
// Slide-Over Cart Drawer Management
// ==========================================================================
function openCart() {
    if (!cartPanel) return;
    cartPanel.classList.add('open');
    if (cartBackdrop) {
        cartBackdrop.hidden = false;
        requestAnimationFrame(() => cartBackdrop.classList.add('open'));
    }
}

function closeCart() {
    if (!cartPanel) return;
    cartPanel.classList.remove('open');
    if (cartBackdrop) {
        cartBackdrop.classList.remove('open');
        setTimeout(() => {
            if (!cartPanel.classList.contains('open')) cartBackdrop.hidden = true;
        }, 300);
    }
}

function handleCartToggle() {
    if (cartPanel && cartPanel.classList.contains('open')) {
        closeCart();
    } else {
        openCart();
    }
}

// ==========================================================================
// Cart Calculations & Threshold Tracker
// ==========================================================================
function cartTotals() {
    const subtotal = state.cart.reduce((sum, item) => sum + Number(item.unitPrice) * Number(item.qty), 0);
    const fulfillment = document.querySelector('[name="fulfillment"]:checked')?.value || 'Delivery';
    const isPickup = fulfillment === 'Pickup';
    const isFreeDelivery = isPickup || subtotal >= 500;
    const delivery = (!isPickup && subtotal > 0 && subtotal < 500) ? 45 : 0;
    return { subtotal, delivery, total: subtotal + delivery, fulfillment, isFreeDelivery };
}

function updateFreeDeliveryProgress(totals) {
    if (!deliveryProgressText || !deliveryProgressFill) return;

    if (totals.fulfillment === 'Pickup') {
        deliveryProgressText.innerHTML = '<i class="bi bi-shop text-yellow"></i> Store Pickup selected &mdash; <strong>Zero delivery fee!</strong>';
        deliveryProgressFill.style.width = '100%';
        deliveryProgressFill.style.background = '#39B54A';
        return;
    }

    const threshold = 500;
    if (totals.subtotal >= threshold) {
        deliveryProgressText.innerHTML = '🎉 <strong>FREE Delivery unlocked!</strong> (Orders ₱500+)';
        deliveryProgressFill.style.width = '100%';
        deliveryProgressFill.style.background = 'linear-gradient(90deg, #F2C12E, #39B54A)';
    } else if (totals.subtotal > 0) {
        const remaining = threshold - totals.subtotal;
        const pct = Math.min(100, Math.max(8, Math.round((totals.subtotal / threshold) * 100)));
        deliveryProgressText.innerHTML = `Add <strong>${money.format(remaining)}</strong> more to get <strong>FREE Delivery</strong>!`;
        deliveryProgressFill.style.width = `${pct}%`;
        deliveryProgressFill.style.background = 'linear-gradient(90deg, #F2C12E, #E67E22)';
    } else {
        deliveryProgressText.textContent = 'Add ₱500.00 more to unlock FREE Delivery!';
        deliveryProgressFill.style.width = '0%';
        deliveryProgressFill.style.background = '#F2C12E';
    }
}

function renderCart() {
    saveCart();
    const itemCount = state.cart.reduce((sum, item) => sum + Number(item.qty), 0);
    if (cartBadge) cartBadge.textContent = itemCount;
    if (cartCountText) cartCountText.textContent = itemCount ? `${itemCount} selected item(s)` : 'No items yet';

    if (!state.cart.length) {
        cartItems.innerHTML = `
            <div class="cart-empty">
                <i class="bi bi-bag"></i>
                <strong>Your cart is empty.</strong>
                <span>Add an eatery meal or snack to start your order.</span>
            </div>`;
    } else {
        cartItems.innerHTML = state.cart.map((item, index) => {
            return `
                <article class="cart-line">
                    <div>
                        <strong>${escapeHtml(item.name)}</strong>
                        <span>${money.format(item.unitPrice)} each</span>
                        ${item.notes ? `<small style="display:block;color:#6B7280;font-style:italic;margin-top:2px;"><i class="bi bi-chat-left-text"></i> ${escapeHtml(item.notes)}</small>` : ''}
                    </div>
                    <div class="cart-controls">
                        <button type="button" data-cart-minus="${index}" aria-label="Decrease quantity">-</button>
                        <strong>${item.qty}</strong>
                        <button type="button" data-cart-plus="${index}" aria-label="Increase quantity">+</button>
                        <button type="button" data-cart-remove="${index}" aria-label="Remove item" title="Remove"><i class="bi bi-trash"></i></button>
                    </div>
                </article>`;
        }).join('');
    }

    const totals = cartTotals();
    updateFreeDeliveryProgress(totals);

    if (subtotalText) subtotalText.textContent = money.format(totals.subtotal);
    if (deliveryText) {
        if (totals.subtotal === 0) {
            deliveryText.textContent = money.format(0);
        } else if (totals.fulfillment === 'Pickup') {
            deliveryText.textContent = 'Free (Pickup)';
        } else if (totals.subtotal >= 500) {
            deliveryText.textContent = 'FREE (Promo)';
        } else {
            deliveryText.textContent = money.format(45);
        }
    }
    if (totalText) totalText.textContent = money.format(totals.total);
}

// ==========================================================================
// API & Data Fetching
// ==========================================================================
async function apiFetch(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await response.json().catch(() => ({ status: 'error', message: 'Invalid server response.' }));
    if (!response.ok && !data.message) data.message = 'Request failed.';
    return data;
}

async function loadCategories() {
    const result = await apiFetch(`${API_BASE}/menu.php?action=categories`);
    if (result.status !== 'success') throw new Error(result.message || 'Unable to load categories.');
    state.categories = result.data || [];
    renderCategories();
}

async function loadMenu() {
    const params = new URLSearchParams();
    if (state.activeCategory !== 'all') params.set('category_id', state.activeCategory);
    if (state.search) params.set('q', state.search);
    const result = await apiFetch(`${API_BASE}/menu.php?action=items&${params.toString()}`);
    if (result.status !== 'success') throw new Error(result.message || 'Unable to load menu.');
    state.items = result.data || [];
    const max = Math.max(1600, ...state.items.map(item => currentUnitPrice(item)));
    if (priceRange) {
        priceRange.max = Math.ceil(max);
        if (Number(priceRange.value) > Number(priceRange.max)) priceRange.value = priceRange.max;
        state.maxPrice = Number(priceRange.value);
    }
    renderMenu();
}

function renderCategories() {
    if (!categoryTabs) return;
    categoryTabs.innerHTML = [
        `<button class="category-tab ${state.activeCategory === 'all' ? 'active' : ''}" data-category="all">All</button>`,
        ...state.categories.map(category => `<button class="category-tab ${String(state.activeCategory) === String(category.id) ? 'active' : ''}" data-category="${category.id}">${escapeHtml(category.name)}</button>`)
    ].join('');
}

function renderMenu() {
    if (!foodGrid) return;
    const items = state.items.filter(item => currentUnitPrice(item) <= state.maxPrice);
    if (!items.length) {
        foodGrid.innerHTML = `<article class="empty-menu"><i class="bi bi-search"></i><h3>No eatery items found.</h3><p>Try another category, search term, or price range.</p></article>`;
        return;
    }

    foodGrid.innerHTML = items.map(item => {
        const stock = Math.max(0, Number(item.stock));
        const isAvailable = Number(item.is_available) === 1 && (item.availability === 'Available' || !item.availability);
        const out = stock <= 0 || !isAvailable;
        const price = currentUnitPrice(item);
        const original = price !== Number(item.price);

        let stockBadgeClass = '';
        let stockBadgeText = '';
        if (out) {
            stockBadgeClass = 'out-of-stock';
            stockBadgeText = 'Out of Stock';
        } else if (stock <= 5) {
            stockBadgeClass = 'low-stock';
            stockBadgeText = `${stock} pcs left`;
        } else {
            stockBadgeText = `${stock} pcs left`;
        }

        return `
            <article class="food-card ${out ? 'disabled unavailable' : ''}" data-item="${item.id}">
                <div class="food-img-wrap">
                    <img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}" loading="lazy">
                    <span class="stock-badge ${stockBadgeClass}">${stockBadgeText}</span>
                </div>
                <div class="food-body">
                    <span class="badge">${escapeHtml(item.category_name)}</span>
                    <h3>${escapeHtml(item.name)}</h3>
                    <p>${escapeHtml(item.description || 'Freshly cooked Filipino eatery comfort meal.')}</p>
                    <div class="food-bottom">
                        <strong>${money.format(price)}${original ? `<small style="display:block;text-decoration:line-through;opacity:.55">${money.format(item.price)}</small>` : ''}</strong>
                        <button type="button" class="food-add-btn" ${out ? 'disabled' : ''} data-add="${item.id}">
                            <i class="bi bi-${out ? 'slash-circle' : 'plus-lg'}"></i> ${out ? 'Out of Stock' : 'Add to Order'}
                        </button>
                    </div>
                </div>
            </article>`;
    }).join('');
}

// ==========================================================================
// Simplified Eatery Item Modal (Pure Quantity & Optional Note)
// ==========================================================================
function openItemModal(itemId) {
    const item = getItem(itemId);
    if (!item || Number(item.stock) <= 0) return;
    state.selectedItem = item;
    state.modalQty = 1;

    if (modalImage) {
        modalImage.src = item.image_url;
        modalImage.alt = item.name;
    }
    if (modalTitle) modalTitle.textContent = item.name;
    if (modalDescription) modalDescription.textContent = item.description || '';
    if (modalCategory) modalCategory.textContent = item.category_name;
    if (modalStockPill) modalStockPill.textContent = `${item.stock} available`;
    if (modalSpecialNotes) modalSpecialNotes.value = '';

    updateModalPrice();
    itemModal.hidden = false;
}

function updateModalPrice() {
    if (modalQty) modalQty.textContent = state.modalQty;
    const base = currentUnitPrice(state.selectedItem);
    if (modalPrice) modalPrice.textContent = money.format(base * state.modalQty);
}

function addSelectedItemToCart() {
    if (!state.selectedItem) return;

    const notes = modalSpecialNotes ? modalSpecialNotes.value.trim() : '';
    const unitPrice = currentUnitPrice(state.selectedItem);

    const existing = state.cart.find(c => Number(c.id) === Number(state.selectedItem.id) && (c.notes || '') === notes);
    if (existing) {
        existing.qty = Math.min(Number(state.selectedItem.stock), Number(existing.qty) + state.modalQty);
    } else {
        state.cart.push({
            id: Number(state.selectedItem.id),
            name: state.selectedItem.name,
            image_url: state.selectedItem.image_url,
            unitPrice,
            notes,
            qty: state.modalQty
        });
    }

    itemModal.hidden = true;
    renderCart();
    openCart();
}

// ==========================================================================
// Authentication & Guest Checkout Handling (Coffee Realm Inspired)
// ==========================================================================
function openAuth(tab = 'signin') {
    authModal.hidden = false;
    setAuthTab(tab);
}

function closeAuth() {
    authModal.hidden = true;
}

function setAuthTab(tab) {
    const isSignIn = tab === 'signin';
    if (authBannerTitle) authBannerTitle.textContent = isSignIn ? 'Hello, Welcome!' : 'Welcome Back!';
    if (authBannerSub) authBannerSub.textContent = isSignIn ? "Don't have an account?" : 'Already have an account?';
    if (authSwitchBtn) authSwitchBtn.textContent = isSignIn ? 'Register Now!' : 'Sign In!';
    if (authFormTitle) authFormTitle.textContent = isSignIn ? 'Customer Login' : 'Create Account';

    if (signInForm) signInForm.hidden = !isSignIn;
    if (registerForm) registerForm.hidden = isSignIn;

    if (isSignIn) {
        document.getElementById('signInEmail')?.focus();
    } else {
        document.getElementById('registerName')?.focus();
    }
}

function setAccountUI() {
    const btn = document.getElementById('accountBtn');
    if (!state.user) {
        if (btn) {
            btn.textContent = 'Sign In';
            btn.onclick = () => openAuth('signin');
        }

        // Guest Notice in Checkout
        if (guestNoticeText) {
            guestNoticeText.innerHTML = `Ordering as <strong>Guest</strong>. <a href="#" id="checkoutSignInLink">Sign in</a> to load saved details.`;
            const link = document.getElementById('checkoutSignInLink');
            if (link) link.onclick = (e) => { e.preventDefault(); openAuth('signin'); };
        }
        if (guestAccountOpt) guestAccountOpt.hidden = false;
        return;
    }

    // Logged-in State
    if (btn) {
        btn.textContent = state.user.full_name;
        btn.onclick = async () => {
            if (!confirm(`Log out from customer account "${state.user.full_name}"?`)) return;
            await apiFetch(`${API_BASE}/auth.php?action=logout`);
            state.user = null;
            state.latestOrder = null;
            setAccountUI();
            renderTracking(null);
            renderHistory([]);
        };
    }

    if (guestNoticeText) {
        guestNoticeText.innerHTML = `<i class="bi bi-person-check-fill"></i> Logged in as <strong>${escapeHtml(state.user.full_name)}</strong> (${escapeHtml(state.user.email || state.user.phone)}).`;
    }
    if (guestAccountOpt) guestAccountOpt.hidden = true;
}

async function loadCurrentUser() {
    const result = await apiFetch(`${API_BASE}/auth.php?action=me`);
    state.user = result.authenticated ? result.user : null;
    setAccountUI();
}

function fillCustomerDetails() {
    if (!state.user) return;
    const nameEl = document.getElementById('customerName');
    const phoneEl = document.getElementById('phoneNumber');
    const addrEl = document.getElementById('deliveryAddress');
    const emailEl = document.getElementById('customerEmail');

    if (nameEl && !nameEl.value) nameEl.value = state.user.full_name || '';
    if (phoneEl && !phoneEl.value) phoneEl.value = state.user.phone || '';
    if (addrEl && !addrEl.value) addrEl.value = state.user.customer_address || '';
    if (emailEl && !emailEl.value) emailEl.value = state.user.email || '';
}

function validateDetails() {
    const name = document.getElementById('customerName').value.trim();
    const phone = document.getElementById('phoneNumber').value.trim();
    const address = document.getElementById('deliveryAddress').value.trim();
    const email = document.getElementById('customerEmail').value.trim();
    const fulfillment = document.querySelector('[name="fulfillment"]:checked')?.value || 'Delivery';
    let ok = true;

    const nameErr = document.getElementById('nameError');
    const phoneErr = document.getElementById('phoneError');
    const addrErr = document.getElementById('addressError');
    const emailErr = document.getElementById('emailError');

    if (nameErr) nameErr.textContent = '';
    if (phoneErr) phoneErr.textContent = '';
    if (addrErr) addrErr.textContent = '';
    if (emailErr) emailErr.textContent = '';

    if (!name) {
        if (nameErr) nameErr.textContent = 'Name is required.';
        ok = false;
    }
    if (!/^09\d{9}$/.test(phone)) {
        if (phoneErr) phoneErr.textContent = 'Use a valid 11-digit mobile number starting with 09.';
        ok = false;
    }
    if (fulfillment === 'Delivery' && !address) {
        if (addrErr) addrErr.textContent = 'Delivery address is required.';
        ok = false;
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (emailErr) emailErr.textContent = 'Enter a valid email address.';
        ok = false;
    }

    if (!state.user && saveAccountCheckbox?.checked) {
        const pwd = guestPassword?.value || '';
        if (pwd.length < 6) {
            alert('Please create a password with at least 6 characters.');
            ok = false;
        }
    }

    return ok;
}

async function placeOrder(event) {
    event.preventDefault();
    if (!state.cart.length) {
        openCart();
        return;
    }

    if (!validateDetails()) {
        setStep(1);
        return;
    }

    const payment = document.querySelector('[name="payment"]:checked')?.value || 'GCash';
    const gcashRef = document.getElementById('gcashReference').value.trim();
    const gcashErr = document.getElementById('gcashReferenceError');
    if (payment === 'GCash' && !/^\d{13}$/.test(gcashRef)) {
        if (gcashErr) gcashErr.textContent = 'Enter a valid 13-digit GCash reference number.';
        setStep(2);
        return;
    }
    if (gcashErr) gcashErr.textContent = '';

    const fulfillment = document.querySelector('[name="fulfillment"]:checked').value === 'Delivery' ? 'DELIVERY' : 'PICKUP';
    const isSaveAccount = !state.user && Boolean(saveAccountCheckbox?.checked);

    const payload = {
        customer_name: document.getElementById('customerName').value.trim(),
        customer_phone: document.getElementById('phoneNumber').value.trim(),
        delivery_address: document.getElementById('deliveryAddress').value.trim(),
        customer_email: document.getElementById('customerEmail').value.trim(),
        account_password: isSaveAccount ? (guestPassword?.value || '') : '',
        fulfillment,
        payment_method: payment.toUpperCase(),
        payment_reference: payment === 'GCash' ? gcashRef : '',
        items: state.cart.map(item => ({
            menu_item_id: Number(item.id),
            quantity: Number(item.qty),
            notes: item.notes || ''
        })),
        notes: ''
    };

    const btn = document.getElementById('placeOrderBtn');
    const btnText = btn?.querySelector('.btn-text');
    const btnLoader = btn?.querySelector('.btn-loader');
    if (btn) btn.disabled = true;
    if (btnText) btnText.textContent = 'Placing Order...';
    if (btnLoader) btnLoader.hidden = false;

    const result = await apiFetch(`${API_BASE}/orders.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).catch(() => ({ status: 'error', message: 'Unable to reach the ordering service.' }));

    if (btn) btn.disabled = false;
    if (btnText) btnText.textContent = 'Place Order';
    if (btnLoader) btnLoader.hidden = true;

    if (result.status !== 'success') {
        alert(result.message || 'Order could not be placed.');
        return;
    }

    // Success
    state.latestOrder = result.data;
    state.cart = [];
    renderCart();
    renderTracking(result.data);

    await loadCurrentUser();
    await loadMenu();
    await loadHistory();

    setStep(1);
    document.getElementById('checkoutForm')?.reset();
    if (saveAccountCheckbox) saveAccountCheckbox.checked = false;
    if (guestPasswordWrap) guestPasswordWrap.hidden = true;

    const gcashWrap = document.getElementById('gcashReferenceWrap');
    if (gcashWrap) gcashWrap.hidden = false;

    const trackSec = document.getElementById('trackingSection');
    if (trackSec) trackSec.scrollIntoView({ behavior: 'smooth' });

    alert(`Order ${result.data.order_number} placed successfully!`);
    pollLatestOrder();
}

// ==========================================================================
// Tracking & History
// ==========================================================================
function renderTracking(order) {
    const trackingStepper = document.getElementById('trackingStepper');
    const receiptView = document.getElementById('receiptView');
    const timerBadge = document.getElementById('timerBadge');
    if (!trackingStepper || !receiptView || !timerBadge) return;

    if (!order) {
        trackingStepper.innerHTML = `<div class="track-step done"><span>✓</span><strong>No active order</strong></div>`;
        receiptView.innerHTML = `<h3>No active online order</h3><p>Your completed orders are available under <strong>Order History</strong>.</p>`;
        timerBadge.textContent = 'No active order';
        return;
    }

    const steps = ['Pending', 'Preparing', 'Ready', 'Served'];
    const stageMap = { Pending: 0, Preparing: 1, Ready: 2, Served: 3, Cancelled: -1 };
    const current = stageMap[order.status] ?? 0;

    trackingStepper.innerHTML = steps.map((step, index) => `
        <div class="track-step ${index <= current ? 'done' : ''}">
            <span>${index + 1}</span>
            <strong>${step}</strong>
        </div>`).join('');

    receiptView.innerHTML = `
        <h3>${escapeHtml(order.order_number || order.order_no)}</h3>
        <p>Status: <strong>${escapeHtml(order.status)}</strong></p>
        <p>Fulfillment: <strong>${escapeHtml(order.fulfillment || order.order_type)}</strong></p>
        <p>Total: <strong>${money.format(Number(order.total_amount ?? order.total ?? 0))}</strong></p>`;
    timerBadge.textContent = order.status === 'Ready' ? 'Ready for Pickup / Dispatch' : 'ETA 20-30 min';
}

async function pollLatestOrder() {
    if (!state.user) return;
    const result = await apiFetch(`${API_BASE}/orders.php?action=latest`);
    if (result.status !== 'success') return;

    if (!result.data) {
        state.latestOrder = null;
        renderTracking(null);
        return;
    }

    state.latestOrder = result.data;
    renderTracking(result.data);
    setTimeout(pollLatestOrder, 8000);
}

function renderHistory(orders) {
    if (!historyGrid) return;

    if (!state.user) {
        historyGrid.innerHTML = `
            <div class="history-empty">
                <span class="badge">Customer Account</span>
                <h3>Sign in to view order history</h3>
                <p>Orders linked to your mobile phone or account appear here after signing in.</p>
            </div>`;
        return;
    }

    if (!orders.length) {
        historyGrid.innerHTML = `
            <div class="history-empty">
                <span class="badge">No Orders Yet</span>
                <h3>Your order history is empty.</h3>
                <p>Orders you place through Online Ordering will appear here.</p>
            </div>`;
        return;
    }

    const rows = orders.map(order => {
        const date = new Date(String(order.created_at || '').replace(' ', 'T'));
        const formattedDate = Number.isNaN(date.getTime())
            ? escapeHtml(order.created_at || '')
            : escapeHtml(date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }));
        const payment = escapeHtml(order.payment_method || 'Payment');
        const reference = order.payment_reference ? `<small class="history-reference">Ref: ${escapeHtml(order.payment_reference)}</small>` : '';
        const paymentStatus = escapeHtml(order.payment_status || 'Pending');
        const orderType = escapeHtml(order.order_type || 'ORDER');
        const orderStatus = escapeHtml(order.status || 'Pending');
        const total = money.format(Number(order.total || 0));

        return `
            <tr>
                <td class="history-order-number">${escapeHtml(order.order_no)}</td>
                <td>${orderType}</td>
                <td><div class="history-payment">${payment}${reference}</div></td>
                <td><span class="history-payment-status">${paymentStatus}</span></td>
                <td class="history-total">${total}</td>
                <td><span class="history-status">${orderStatus}</span></td>
                <td class="history-date">${formattedDate}</td>
            </tr>`;
    }).join('');

    historyGrid.innerHTML = `
        <div class="history-table-wrap">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Order Number</th>
                        <th>Type</th>
                        <th>Payment</th>
                        <th>Payment Status</th>
                        <th>Total</th>
                        <th>Order Status</th>
                        <th>Date / Time</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
}

async function loadHistory() {
    if (!state.user) {
        renderHistory([]);
        return;
    }

    const result = await apiFetch(`${API_BASE}/orders.php?action=history`);
    if (result.status !== 'success') {
        if (historyGrid) {
            historyGrid.innerHTML = `
                <article class="history-card">
                    <div>
                        <span class="badge">Order History</span>
                        <h3>Unable to load order history.</h3>
                        <p>${escapeHtml(result.message || 'Please refresh the page.')}</p>
                    </div>
                </article>`;
        }
        return;
    }

    renderHistory(Array.isArray(result.data) ? result.data : []);
}

// ==========================================================================
// Auth Form Submission Handlers
// ==========================================================================
async function handleSignIn(event) {
    event.preventDefault();
    const error = document.getElementById('signInError');
    if (error) error.textContent = '';

    const result = await apiFetch(`${API_BASE}/auth.php?action=login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: document.getElementById('signInEmail').value.trim(),
            password: document.getElementById('signInPassword').value
        })
    });

    if (result.status !== 'success') {
        if (error) error.textContent = result.message;
        return;
    }

    state.user = result.user;
    setAccountUI();
    closeAuth();
    fillCustomerDetails();
    await loadHistory();
    pollLatestOrder();
}

async function handleRegister(event) {
    event.preventDefault();
    const error = document.getElementById('registerError');
    if (error) error.textContent = '';

    const result = await apiFetch(`${API_BASE}/auth.php?action=register`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            full_name: document.getElementById('registerName').value.trim(),
            email: document.getElementById('registerEmail').value.trim(),
            phone: document.getElementById('registerPhone').value.trim(),
            address: document.getElementById('registerAddress').value.trim(),
            password: document.getElementById('registerPassword').value
        })
    });

    if (result.status !== 'success') {
        if (error) error.textContent = result.message;
        return;
    }

    state.user = result.user;
    setAccountUI();
    closeAuth();
    fillCustomerDetails();
    await loadHistory();
    pollLatestOrder();
}

function setStep(step) {
    const stepOne = document.getElementById('stepOne');
    const stepTwo = document.getElementById('stepTwo');
    const stepOneLabel = document.getElementById('stepOneLabel');
    const stepTwoLabel = document.getElementById('stepTwoLabel');

    if (stepOne) stepOne.hidden = step !== 1;
    if (stepTwo) stepTwo.hidden = step !== 2;
    if (stepOneLabel) stepOneLabel.classList.toggle('active', step === 1);
    if (stepTwoLabel) stepTwoLabel.classList.toggle('active', step === 2);
}

// ==========================================================================
// Event Listeners Initialization
// ==========================================================================
function setupEventListeners() {
    // 1. Password Visibility Toggle
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = btn.dataset.target;
            const input = document.getElementById(targetId);
            if (!input) return;
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                }
                btn.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                if (icon) {
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
                btn.setAttribute('aria-label', 'Show password');
            }
        });
    });

    // 2. Auth Switch & Guest Skip
    if (authSwitchBtn) {
        authSwitchBtn.addEventListener('click', () => {
            const isCurrentlySignIn = !signInForm.hidden;
            setAuthTab(isCurrentlySignIn ? 'register' : 'signin');
        });
    }

    const skipHandler = () => {
        closeAuth();
        const checkoutSec = document.getElementById('checkoutSection');
        if (checkoutSec) {
            checkoutSec.scrollIntoView({ behavior: 'smooth' });
            setTimeout(() => document.getElementById('customerName')?.focus(), 350);
        }
    };

    document.getElementById('guestAuthSkipBtn')?.addEventListener('click', skipHandler);
    document.getElementById('guestAuthSkipBtn2')?.addEventListener('click', skipHandler);

    if (saveAccountCheckbox && guestPasswordWrap) {
        saveAccountCheckbox.addEventListener('change', () => {
            guestPasswordWrap.hidden = !saveAccountCheckbox.checked;
            if (saveAccountCheckbox.checked && guestPassword) {
                guestPassword.focus();
            }
        });
    }

    document.getElementById('forgotPwdLink')?.addEventListener('click', (e) => {
        e.preventDefault();
        alert('Please approach our eatery staff or call hotline 0968 743 0373 to reset your customer password.');
    });

    // 3. Category & Food Selection
    if (categoryTabs) {
        categoryTabs.addEventListener('click', async event => {
            const btn = event.target.closest('[data-category]');
            if (!btn) return;
            state.activeCategory = btn.dataset.category;
            renderCategories();
            await loadMenu();
        });
    }

    if (foodGrid) {
        foodGrid.addEventListener('click', event => {
            const addButton = event.target.closest('[data-add]');
            if (addButton) {
                event.preventDefault();
                event.stopPropagation();
                const itemId = Number(addButton.dataset.add);
                const item = getItem(itemId);
                if (item && Number(item.stock) > 0 && Number(item.is_available) === 1) {
                    const existing = state.cart.find(c => Number(c.id) === Number(item.id) && !c.notes);
                    if (existing) {
                        existing.qty = Math.min(Number(item.stock), Number(existing.qty) + 1);
                    } else {
                        state.cart.push({
                            id: Number(item.id),
                            name: item.name,
                            image_url: item.image_url,
                            unitPrice: currentUnitPrice(item),
                            notes: '',
                            qty: 1
                        });
                    }
                    renderCart();
                    openCart();
                }
                return;
            }
            const card = event.target.closest('[data-item]');
            if (card) {
                openItemModal(Number(card.dataset.item));
            }
        });
    }

    // 4. Cart Controls
    if (cartItems) {
        cartItems.addEventListener('click', event => {
            const minus = event.target.closest('[data-cart-minus]');
            const plus = event.target.closest('[data-cart-plus]');
            const remove = event.target.closest('[data-cart-remove]');

            if (minus) {
                const index = Number(minus.dataset.cartMinus);
                state.cart[index].qty -= 1;
                if (state.cart[index].qty <= 0) state.cart.splice(index, 1);
            }
            if (plus) {
                const index = Number(plus.dataset.cartPlus);
                const current = state.cart[index];
                const menu = getItem(current.id);
                current.qty = Math.min(menu ? Number(menu.stock) : 99, current.qty + 1);
            }
            if (remove) {
                state.cart.splice(Number(remove.dataset.cartRemove), 1);
            }
            renderCart();
        });
    }

    // 5. Search & Filters
    if (searchInput) {
        searchInput.addEventListener('input', async () => {
            state.search = searchInput.value.trim();
            await loadMenu();
        });
    }

    if (priceRange) {
        priceRange.addEventListener('input', () => {
            state.maxPrice = Number(priceRange.value);
            if (priceLabel) priceLabel.textContent = `PHP ${state.maxPrice}`;
            renderMenu();
        });
    }

    // 6. Simple Item Modal Controls
    document.getElementById('modalMinus')?.addEventListener('click', () => {
        state.modalQty = Math.max(1, state.modalQty - 1);
        updateModalPrice();
    });

    document.getElementById('modalPlus')?.addEventListener('click', () => {
        const max = Math.max(1, Number(state.selectedItem?.stock || 1));
        state.modalQty = Math.min(max, state.modalQty + 1);
        updateModalPrice();
    });

    document.getElementById('addToCartBtn')?.addEventListener('click', addSelectedItemToCart);
    document.getElementById('closeModal')?.addEventListener('click', () => {
        if (itemModal) itemModal.hidden = true;
    });

    // 7. Cart Drawer Open / Close / Backdrop
    document.getElementById('cartToggle')?.addEventListener('click', handleCartToggle);
    document.getElementById('mobileCartBtn')?.addEventListener('click', openCart);
    document.getElementById('closeCart')?.addEventListener('click', closeCart);
    if (cartBackdrop) {
        cartBackdrop.addEventListener('click', closeCart);
    }

    // 8. Checkout Stepper & Submission
    document.getElementById('checkoutToggle')?.addEventListener('click', () => {
        closeCart();
        fillCustomerDetails();
        document.getElementById('checkoutSection')?.scrollIntoView({ behavior: 'smooth' });
    });

    document.getElementById('continuePayment')?.addEventListener('click', () => {
        if (validateDetails()) setStep(2);
    });

    document.getElementById('backDetails')?.addEventListener('click', () => setStep(1));
    document.getElementById('checkoutForm')?.addEventListener('submit', placeOrder);

    // 9. Auth Modal Listeners
    document.getElementById('closeAuth')?.addEventListener('click', closeAuth);
    document.getElementById('signInForm')?.addEventListener('submit', handleSignIn);
    document.getElementById('registerForm')?.addEventListener('submit', handleRegister);

    // 10. Fulfillment & Payment Method Switches
    document.querySelectorAll('[name="fulfillment"]').forEach(input => input.addEventListener('change', () => {
        const addressWrap = document.getElementById('addressWrap');
        if (addressWrap) addressWrap.hidden = input.value === 'Pickup' && input.checked;
        renderCart();
    }));

    document.querySelectorAll('[name="payment"]').forEach(input => input.addEventListener('change', () => {
        const gcash = input.value === 'GCash' && input.checked;
        const gcashWrap = document.getElementById('gcashReferenceWrap');
        const paymentNote = document.getElementById('paymentNote');
        if (gcashWrap) gcashWrap.hidden = !gcash;
        if (paymentNote) {
            paymentNote.textContent = gcash
                ? 'Enter the 13-digit reference number from the GCash receipt.'
                : 'Cash payment will be collected on pickup or delivery.';
        }
        renderCart();
    }));
}

// ==========================================================================
// Landing Page Utilities & Store Status
// ==========================================================================
function initLandingFeatures() {
    document.addEventListener('click', async (event) => {
        const jumpBtn = event.target.closest('[data-category-jump]');
        if (jumpBtn) {
            event.preventDefault();
            const cat = jumpBtn.dataset.categoryJump;
            state.activeCategory = cat;
            renderCategories();
            await loadMenu();
            const menuSection = document.getElementById('menuSection');
            if (menuSection) menuSection.scrollIntoView({ behavior: 'smooth' });
            return;
        }

        const heroAddBtn = event.target.closest('[data-hero-add]');
        if (heroAddBtn) {
            event.preventDefault();
            const itemId = Number(heroAddBtn.dataset.heroAdd);
            openItemModal(itemId);
            return;
        }

        const backToTop = event.target.closest('#backToTopBtn');
        if (backToTop) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
    });

    // Real-Time Store Status Check (9:00 AM - 9:00 PM)
    function updateStoreStatus() {
        const statusText = document.getElementById('storeStatusText');
        const statusDot = document.getElementById('storeStatusDot');
        if (!statusText || !statusDot) return;

        const now = new Date();
        const currentHour = now.getHours();
        const isOpen = currentHour >= 9 && currentHour < 21;

        if (isOpen) {
            statusText.textContent = 'Eatery Open Now';
            statusDot.style.background = 'var(--success)';
        } else {
            statusText.textContent = 'Closed (Opens at 9:00 AM)';
            statusDot.style.background = '#F2A900';
            statusDot.style.animation = 'none';
        }
    }
    updateStoreStatus();

    // Scroll Reveal Observer
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -40px 0px'
        });

        document.querySelectorAll('.reveal-on-scroll').forEach(el => observer.observe(el));
    } else {
        document.querySelectorAll('.reveal-on-scroll').forEach(el => el.classList.add('revealed'));
    }
}

// ==========================================================================
// Initialization Entry Point
// ==========================================================================
(async function init() {
    try {
        setupEventListeners();
        initLandingFeatures();
        await loadCurrentUser();
        await loadCategories();
        await loadMenu();
        renderCart();

        if (state.user) {
            fillCustomerDetails();
            await loadHistory();
            await pollLatestOrder();
        } else {
            renderHistory([]);
        }
        setAuthTab('signin');

        // Real-time stock sync with POS / Inventory
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                loadMenu().catch(() => {});
            }
        }, 20000);

        window.addEventListener('focus', () => {
            loadMenu().catch(() => {});
        });
    } catch (error) {
        if (foodGrid) {
            foodGrid.innerHTML = `
                <article class="empty-menu">
                    <i class="bi bi-exclamation-circle"></i>
                    <h3>Unable to load the menu.</h3>
                    <p>${escapeHtml(error.message)}</p>
                </article>`;
        }
    }
})();
