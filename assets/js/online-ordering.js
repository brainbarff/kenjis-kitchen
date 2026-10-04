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

const categoryTabs = document.getElementById('categoryTabs');
const foodGrid = document.getElementById('foodGrid');
const searchInput = document.getElementById('searchInput');
const priceRange = document.getElementById('priceRange');
const priceLabel = document.getElementById('priceLabel');
const cartItems = document.getElementById('cartItems');
const cartBadge = document.getElementById('cartBadge');
const cartCountText = document.getElementById('cartCountText');
const cartPanel = document.getElementById('cartPanel');
const itemModal = document.getElementById('itemModal');
const authModal = document.getElementById('authModal');
const historyGrid = document.getElementById('historyGrid');

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

function cartTotals() {
    const subtotal = state.cart.reduce((sum, item) => sum + Number(item.unitPrice) * Number(item.qty), 0);
    const fulfillment = document.querySelector('[name="fulfillment"]:checked')?.value || 'Delivery';
    const delivery = fulfillment === 'Delivery' && subtotal > 0 ? 45 : 0;
    return { subtotal, delivery, total: subtotal + delivery };
}

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
    priceRange.max = Math.ceil(max);
    if (Number(priceRange.value) > Number(priceRange.max)) priceRange.value = priceRange.max;
    state.maxPrice = Number(priceRange.value);
    renderMenu();
}

function renderCategories() {
    categoryTabs.innerHTML = [
        `<button class="category-tab ${state.activeCategory === 'all' ? 'active' : ''}" data-category="all">All</button>`,
        ...state.categories.map(category => `<button class="category-tab ${String(state.activeCategory) === String(category.id) ? 'active' : ''}" data-category="${category.id}">${escapeHtml(category.name)}</button>`)
    ].join('');
}

function renderMenu() {
    const items = state.items.filter(item => currentUnitPrice(item) <= state.maxPrice);
    if (!items.length) {
        foodGrid.innerHTML = `<article class="empty-menu"><i class="bi bi-search"></i><h3>No menu items found.</h3><p>Try another category, search term, or price range.</p></article>`;
        return;
    }

    foodGrid.innerHTML = items.map(item => {
        const stock = Number(item.stock);
        const out = stock <= 0 || Number(item.is_available) !== 1;
        const price = currentUnitPrice(item);
        const original = price !== Number(item.price);
        return `
            <article class="food-card ${out ? 'disabled' : ''}" data-item="${item.id}">
                <div class="food-img-wrap">
                    <img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}">
                    ${out ? '<span class="stock-badge">Out of Stock</span>' : `<span class="stock-badge">${stock} pcs left</span>`}
                </div>
                <div class="food-body">
                    <span class="badge">${escapeHtml(item.category_name)}</span>
                    <h3>${escapeHtml(item.name)}</h3>
                    <p>${escapeHtml(item.description || 'No description available.')}</p>
                    <div class="food-bottom">
                        <strong>${money.format(price)}${original ? `<small style="display:block;text-decoration:line-through;opacity:.55">${money.format(item.price)}</small>` : ''}</strong>
                        <button ${out ? 'disabled' : ''} data-add="${item.id}"><i class="bi bi-plus-lg"></i>Add to Order</button>
                    </div>
                </div>
            </article>`;
    }).join('');
}

function renderCart() {
    saveCart();
    const itemCount = state.cart.reduce((sum, item) => sum + Number(item.qty), 0);
    cartBadge.textContent = itemCount;
    cartCountText.textContent = itemCount ? `${itemCount} selected item(s)` : 'No items yet';

    if (!state.cart.length) {
        cartItems.innerHTML = `<div class="cart-empty"><i class="bi bi-bag"></i><strong>Your cart is empty.</strong><span>Add a meal to start your order.</span></div>`;
    } else {
        cartItems.innerHTML = state.cart.map((item, index) => `
            <article class="cart-line">
                <div><strong>${escapeHtml(item.name)}</strong><span>${money.format(item.unitPrice)} each</span></div>
                <div class="cart-controls">
                    <button type="button" data-cart-minus="${index}">-</button>
                    <strong>${item.qty}</strong>
                    <button type="button" data-cart-plus="${index}">+</button>
                    <button type="button" data-cart-remove="${index}"><i class="bi bi-trash"></i></button>
                </div>
            </article>`).join('');
    }

    const totals = cartTotals();
    document.getElementById('subtotalText').textContent = money.format(totals.subtotal);
    document.getElementById('deliveryText').textContent = money.format(totals.delivery);
    document.getElementById('totalText').textContent = money.format(totals.total);
}

function openItemModal(itemId) {
    const item = getItem(itemId);
    if (!item || Number(item.stock) <= 0) return;
    state.selectedItem = item;
    state.modalQty = 1;
    document.getElementById('modalImage').src = item.image_url;
    document.getElementById('modalImage').alt = item.name;
    document.getElementById('modalTitle').textContent = item.name;
    document.getElementById('modalDescription').textContent = item.description || '';
    document.getElementById('modalCategory').textContent = item.category_name;
    document.getElementById('portionOptions').innerHTML = `<label><input type="radio" checked disabled><span>Regular</span></label>`;
    document.getElementById('extraOptions').innerHTML = '<p style="margin:0;color:#777">No extras available.</p>';
    updateModalPrice();
    itemModal.hidden = false;
}

function updateModalPrice() {
    document.getElementById('modalQty').textContent = state.modalQty;
    document.getElementById('modalPrice').textContent = money.format(currentUnitPrice(state.selectedItem) * state.modalQty);
}

function addSelectedItemToCart() {
    if (!state.selectedItem) return;
    const existing = state.cart.find(item => Number(item.id) === Number(state.selectedItem.id));
    if (existing) {
        existing.qty = Math.min(Number(state.selectedItem.stock), Number(existing.qty) + state.modalQty);
    } else {
        state.cart.push({
            id: Number(state.selectedItem.id),
            name: state.selectedItem.name,
            unitPrice: currentUnitPrice(state.selectedItem),
            qty: state.modalQty
        });
    }
    itemModal.hidden = true;
    cartPanel.classList.add('open');
    renderCart();
}

function openAuth(tab = 'signin') {
    authModal.hidden = false;
    setAuthTab(tab);
}

function closeAuth() {
    authModal.hidden = true;
}

function setAuthTab(tab) {
    const signIn = tab === 'signin';
    document.getElementById('signInTab').classList.toggle('active', signIn);
    document.getElementById('registerTab').classList.toggle('active', !signIn);
    document.getElementById('signInForm').hidden = !signIn;
    document.getElementById('registerForm').hidden = signIn;
    document.getElementById('authTitle').textContent = signIn ? 'Sign In' : 'Create Account';
}

function setAccountUI() {
    const btn = document.getElementById('accountBtn');
    if (!state.user) {
        btn.textContent = 'Sign In';
        btn.onclick = () => openAuth('signin');
        return;
    }
    btn.textContent = state.user.full_name;
    btn.onclick = async () => {
        if (!confirm('Log out of your customer account?')) return;
        await apiFetch(`${API_BASE}/auth.php?action=logout`);
        state.user = null;
        state.latestOrder = null;
        setAccountUI();
        renderTracking(null);
        renderHistory([]);
    };
}

async function loadCurrentUser() {
    const result = await apiFetch(`${API_BASE}/auth.php?action=me`);
    state.user = result.authenticated ? result.user : null;
    setAccountUI();
}

function validateDetails() {
    const name = document.getElementById('customerName').value.trim();
    const phone = document.getElementById('phoneNumber').value.trim();
    const address = document.getElementById('deliveryAddress').value.trim();
    const fulfillment = document.querySelector('[name="fulfillment"]:checked')?.value || 'Delivery';
    let ok = true;

    document.getElementById('nameError').textContent = '';
    document.getElementById('phoneError').textContent = '';
    document.getElementById('addressError').textContent = '';

    if (!name) { document.getElementById('nameError').textContent = 'Name is required.'; ok = false; }
    if (!/^09\d{9}$/.test(phone)) { document.getElementById('phoneError').textContent = 'Use a valid 11-digit PH mobile number.'; ok = false; }
    if (fulfillment === 'Delivery' && !address) { document.getElementById('addressError').textContent = 'Delivery address is required.'; ok = false; }
    return ok;
}

async function placeOrder(event) {
    event.preventDefault();
    if (!state.cart.length) { cartPanel.classList.add('open'); return; }
    if (!state.user) { openAuth('signin'); return; }
    if (!validateDetails()) { setStep(1); return; }

    const payment = document.querySelector('[name="payment"]:checked')?.value || 'GCash';
    const gcashRef = document.getElementById('gcashReference').value.trim();
    if (payment === 'GCash' && !/^\d{13}$/.test(gcashRef)) {
        document.getElementById('gcashReferenceError').textContent = 'Enter a valid 13-digit GCash reference number.';
        setStep(2);
        return;
    }
    document.getElementById('gcashReferenceError').textContent = '';

    const totals = cartTotals();
    const fulfillment = document.querySelector('[name="fulfillment"]:checked').value === 'Delivery' ? 'DELIVERY' : 'PICKUP';
    const payload = {
        customer_name: document.getElementById('customerName').value.trim(),
        customer_phone: document.getElementById('phoneNumber').value.trim(),
        delivery_address: document.getElementById('deliveryAddress').value.trim(),
        fulfillment,
        payment_method: payment.toUpperCase(),
        payment_reference: payment === 'GCash' ? gcashRef : '',
        items: state.cart.map(item => ({ menu_item_id: Number(item.id), quantity: Number(item.qty) })),
        notes: ''
    };

    const btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.querySelector('.btn-text').textContent = 'Placing Order...';

    const result = await apiFetch(`${API_BASE}/orders.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).catch(() => ({ status: 'error', message: 'Unable to reach the ordering service.' }));

    btn.disabled = false;
    btn.querySelector('.btn-text').textContent = 'Place Order';

    if (result.status !== 'success') {
        alert(result.message || 'Order could not be placed.');
        return;
    }

    state.latestOrder = result.data;
    state.cart = [];
    renderCart();
    renderTracking(result.data);
    await loadMenu();
    await loadHistory();
    setStep(1);
    document.getElementById('checkoutForm').reset();
    document.getElementById('gcashReferenceWrap').hidden = false;
    document.getElementById('checkoutSection').scrollIntoView({ behavior: 'smooth' });
    alert(`Order ${result.data.order_number} was placed successfully.`);
    pollLatestOrder();
}

function renderTracking(order) {
    const trackingStepper = document.getElementById('trackingStepper');
    const receiptView = document.getElementById('receiptView');
    const timerBadge = document.getElementById('timerBadge');

    if (!order) {
        trackingStepper.innerHTML = `
            <div class="track-step done"><span>✓</span><strong>No active order</strong></div>`;
        receiptView.innerHTML = `
            <h3>No active online order</h3>
            <p>Your completed orders are available under <strong>Order History</strong>.</p>`;
        timerBadge.textContent = 'No active order';
        return;
    }

    const steps = ['Pending', 'Preparing', 'Ready', 'Served'];
    const stageMap = { Pending: 0, Preparing: 1, Ready: 2, Served: 3, Cancelled: -1 };
    const current = stageMap[order.status] ?? 0;
    trackingStepper.innerHTML = steps.map((step, index) => `
        <div class="track-step ${index <= current ? 'done' : ''}"><span>${index + 1}</span><strong>${step}</strong></div>`).join('');
    receiptView.innerHTML = `
        <h3>${escapeHtml(order.order_number || order.order_no)}</h3>
        <p>Status: <strong>${escapeHtml(order.status)}</strong></p>
        <p>Total: <strong>${money.format(Number(order.total_amount ?? order.total ?? 0))}</strong></p>`;
    timerBadge.textContent = 'ETA 25 min';
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
    if (!state.user) {
        historyGrid.innerHTML = `
            <div class="history-empty">
                <span class="badge">Customer Account</span>
                <h3>Sign in to view order history</h3>
                <p>Your online orders will appear here after you sign in.</p>
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
        const reference = order.payment_reference
            ? `<small class="history-reference">Ref: ${escapeHtml(order.payment_reference)}</small>`
            : '';
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
        historyGrid.innerHTML = `
            <article class="history-card">
                <div>
                    <span class="badge">Order History</span>
                    <h3>Unable to load order history.</h3>
                    <p>${escapeHtml(result.message || 'Please refresh the page and try again.')}</p>
                </div>
            </article>`;
        return;
    }

    renderHistory(Array.isArray(result.data) ? result.data : []);
}

async function handleSignIn(event) {
    event.preventDefault();
    const error = document.getElementById('signInError');
    error.textContent = '';
    const result = await apiFetch(`${API_BASE}/auth.php?action=login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: document.getElementById('signInEmail').value.trim(),
            password: document.getElementById('signInPassword').value
        })
    });
    if (result.status !== 'success') { error.textContent = result.message; return; }
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
    error.textContent = '';
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
    if (result.status !== 'success') { error.textContent = result.message; return; }
    state.user = result.user;
    setAccountUI();
    closeAuth();
    fillCustomerDetails();
    await loadHistory();
    pollLatestOrder();
}

function fillCustomerDetails() {
    if (!state.user) return;
    document.getElementById('customerName').value = state.user.full_name || '';
    document.getElementById('phoneNumber').value = state.user.phone || '';
    document.getElementById('deliveryAddress').value = state.user.customer_address || '';
}

function setStep(step) {
    document.getElementById('stepOne').hidden = step !== 1;
    document.getElementById('stepTwo').hidden = step !== 2;
    document.getElementById('stepOneLabel').classList.toggle('active', step === 1);
    document.getElementById('stepTwoLabel').classList.toggle('active', step === 2);
}

function handleCartToggle() {
    if (window.matchMedia('(max-width: 1100px)').matches) {
        cartPanel.classList.toggle('open');
        return;
    }

    cartPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

categoryTabs.addEventListener('click', async event => {
    const btn = event.target.closest('[data-category]');
    if (!btn) return;
    state.activeCategory = btn.dataset.category;
    renderCategories();
    await loadMenu();
});

foodGrid.addEventListener('click', event => {
    const addButton = event.target.closest('[data-add]');
    if (addButton) { openItemModal(Number(addButton.dataset.add)); return; }
    const card = event.target.closest('[data-item]');
    if (card) openItemModal(Number(card.dataset.item));
});

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
    if (remove) state.cart.splice(Number(remove.dataset.cartRemove), 1);
    renderCart();
});

searchInput.addEventListener('input', async () => {
    state.search = searchInput.value.trim();
    await loadMenu();
});

priceRange.addEventListener('input', () => {
    state.maxPrice = Number(priceRange.value);
    priceLabel.textContent = `PHP ${state.maxPrice}`;
    renderMenu();
});

document.getElementById('modalMinus').addEventListener('click', () => {
    state.modalQty = Math.max(1, state.modalQty - 1);
    updateModalPrice();
});

document.getElementById('modalPlus').addEventListener('click', () => {
    const max = Math.max(1, Number(state.selectedItem?.stock || 1));
    state.modalQty = Math.min(max, state.modalQty + 1);
    updateModalPrice();
});

document.getElementById('addToCartBtn').addEventListener('click', addSelectedItemToCart);
document.getElementById('closeModal').addEventListener('click', () => itemModal.hidden = true);
document.getElementById('cartToggle').addEventListener('click', handleCartToggle);
document.getElementById('mobileCartBtn').addEventListener('click', () => cartPanel.classList.add('open'));
document.getElementById('closeCart').addEventListener('click', () => cartPanel.classList.remove('open'));
document.getElementById('checkoutToggle').addEventListener('click', () => {
    if (!state.user) { openAuth('signin'); return; }
    fillCustomerDetails();
    document.getElementById('checkoutSection').scrollIntoView({ behavior: 'smooth' });
});
document.getElementById('continuePayment').addEventListener('click', () => validateDetails() && setStep(2));
document.getElementById('backDetails').addEventListener('click', () => setStep(1));
document.getElementById('checkoutForm').addEventListener('submit', placeOrder);
document.getElementById('signInTab').addEventListener('click', () => setAuthTab('signin'));
document.getElementById('registerTab').addEventListener('click', () => setAuthTab('register'));
document.getElementById('closeAuth').addEventListener('click', closeAuth);
document.getElementById('signInForm').addEventListener('submit', handleSignIn);
document.getElementById('registerForm').addEventListener('submit', handleRegister);

document.querySelectorAll('[name="fulfillment"]').forEach(input => input.addEventListener('change', () => {
    document.getElementById('addressWrap').hidden = input.value === 'Pickup' && input.checked;
    renderCart();
}));

document.querySelectorAll('[name="payment"]').forEach(input => input.addEventListener('change', () => {
    const gcash = input.value === 'GCash' && input.checked;
    document.getElementById('gcashReferenceWrap').hidden = !gcash;
    document.getElementById('paymentNote').textContent = gcash ? 'Enter the 13-digit reference number from the GCash receipt.' : 'Cash payment will be collected on pickup or delivery.';
    renderCart();
}));

function initLandingFeatures() {
    // 1. Category Quick-Jump, Hero Quick-Add & Back to top
    document.addEventListener('click', async (event) => {
        const jumpBtn = event.target.closest('[data-category-jump]');
        if (jumpBtn) {
            event.preventDefault();
            const cat = jumpBtn.dataset.categoryJump;
            state.activeCategory = cat;
            renderCategories();
            await loadMenu();
            const menuSection = document.getElementById('menuSection');
            if (menuSection) {
                menuSection.scrollIntoView({ behavior: 'smooth' });
            }
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

    // 2. Real-Time Store Status Check (9:00 AM - 9:00 PM)
    function updateStoreStatus() {
        const statusText = document.getElementById('storeStatusText');
        const statusDot = document.getElementById('storeStatusDot');
        if (!statusText || !statusDot) return;

        const now = new Date();
        const currentHour = now.getHours();
        const isOpen = currentHour >= 9 && currentHour < 21;

        if (isOpen) {
            statusText.textContent = 'Kitchen Open Now';
            statusDot.style.background = 'var(--success)';
        } else {
            statusText.textContent = 'Closed (Opens at 9:00 AM)';
            statusDot.style.background = '#F2A900';
            statusDot.style.animation = 'none';
        }
    }
    updateStoreStatus();

    // 3. Scroll Reveal Observer
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

(async function init() {
    try {
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
    } catch (error) {
        foodGrid.innerHTML = `<article class="empty-menu"><i class="bi bi-exclamation-circle"></i><h3>Unable to load the menu.</h3><p>${escapeHtml(error.message)}</p></article>`;
    }
})();
