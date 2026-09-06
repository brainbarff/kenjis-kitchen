const state = {
    activeCategory: 'all',
    search: '',
    maxPrice: 250,
    cart: JSON.parse(localStorage.getItem('kenji_online_cart') || '[]'),
    selectedItem: null,
    modalQty: 1,
    selectedPortion: 'regular',
    selectedExtras: [],
    latestOrder: null,
    trackingStage: 0
};

const money = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
});

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

// This helper keeps all localStorage writing in one place.
function saveCart() {
    localStorage.setItem('kenji_online_cart', JSON.stringify(state.cart));
}

function safeText(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function getItem(id) {
    return onlineData.items.find(item => Number(item.id) === Number(id));
}

function getPortion(id) {
    return onlineData.portions.find(portion => portion.id === id);
}

function currentModalUnitPrice() {
    const extrasTotal = state.selectedExtras.reduce((sum, id) => {
        const extra = onlineData.extras.find(row => row.id === id);
        return sum + (extra ? extra.price : 0);
    }, 0);

    return state.selectedItem.price + getPortion(state.selectedPortion).add + extrasTotal;
}

function cartTotals() {
    const subtotal = state.cart.reduce((sum, item) => sum + (item.unitPrice * item.qty), 0);
    const tax = subtotal * 0.12;
    const fulfillment = document.querySelector('[name="fulfillment"]:checked')?.value || 'Delivery';
    const delivery = fulfillment === 'Delivery' && subtotal > 0 ? 45 : 0;

    return {
        subtotal,
        tax,
        delivery,
        total: subtotal + tax + delivery
    };
}

function renderCategories() {
    categoryTabs.innerHTML = onlineData.categories.map(cat => `
        <button class="category-tab ${state.activeCategory === cat.id ? 'active' : ''}" data-category="${cat.id}">
            ${safeText(cat.name)}
        </button>
    `).join('');
}

function filteredItems() {
    return onlineData.items.filter(item => {
        const matchesCategory = state.activeCategory === 'all' || item.category === state.activeCategory;
        const matchesSearch = item.name.toLowerCase().includes(state.search) ||
            item.description.toLowerCase().includes(state.search);
        const matchesPrice = item.price <= state.maxPrice;

        return matchesCategory && matchesSearch && matchesPrice;
    });
}

function renderMenu() {
    const items = filteredItems();

    if (!items.length) {
        foodGrid.innerHTML = `
            <article class="empty-menu">
                <i class="bi bi-search"></i>
                <h3>No menu items found.</h3>
                <p>Try another category, search term, or price range.</p>
            </article>
        `;
        return;
    }

    foodGrid.innerHTML = items.map(item => `
        <article class="food-card ${item.stock === 'out' ? 'disabled' : ''}" data-item="${item.id}">
            <div class="food-img-wrap">
                <img src="${item.image}" alt="${safeText(item.name)}">
                ${item.popular ? '<span class="popular-badge">Popular</span>' : ''}
                ${item.stock === 'out' ? '<span class="stock-badge">Out of Stock</span>' : ''}
            </div>
            <div class="food-body">
                <h3>${safeText(item.name)}</h3>
                <p>${safeText(item.description)}</p>
                <div class="food-bottom">
                    <strong>${money.format(item.price)}</strong>
                    <button ${item.stock === 'out' ? 'disabled' : ''} data-add="${item.id}">
                        <i class="bi bi-plus-lg"></i>Add to Order
                    </button>
                </div>
            </div>
        </article>
    `).join('');
}

function renderCart() {
    saveCart();

    const itemCount = state.cart.reduce((sum, item) => sum + item.qty, 0);
    cartBadge.textContent = itemCount;
    cartCountText.textContent = itemCount ? `${itemCount} selected item(s)` : 'No items yet';

    if (!state.cart.length) {
        cartItems.innerHTML = `
            <div class="cart-empty">
                <i class="bi bi-bag"></i>
                <strong>Your cart is empty.</strong>
                <span>Add a meal to start your order.</span>
            </div>
        `;
    } else {
        cartItems.innerHTML = state.cart.map((item, index) => `
            <article class="cart-line">
                <div>
                    <strong>${safeText(item.name)}</strong>
                    <p>${safeText(item.portionName)}${item.extras.length ? ' · ' + item.extras.map(extra => safeText(extra.name)).join(', ') : ''}</p>
                    <span>${money.format(item.unitPrice)} each</span>
                </div>
                <div class="cart-controls">
                    <button data-cart-minus="${index}">-</button>
                    <strong>${item.qty}</strong>
                    <button data-cart-plus="${index}">+</button>
                    <button data-cart-remove="${index}"><i class="bi bi-trash"></i></button>
                </div>
            </article>
        `).join('');
    }

    const totals = cartTotals();
    document.getElementById('subtotalText').textContent = money.format(totals.subtotal);
    document.getElementById('taxText').textContent = money.format(totals.tax);
    document.getElementById('deliveryText').textContent = money.format(totals.delivery);
    document.getElementById('totalText').textContent = money.format(totals.total);
}

function openItemModal(itemId) {
    const item = getItem(itemId);
    if (!item || item.stock === 'out') return;

    state.selectedItem = item;
    state.modalQty = 1;
    state.selectedPortion = 'regular';
    state.selectedExtras = [];

    document.getElementById('modalImage').src = item.image;
    document.getElementById('modalImage').alt = item.name;
    document.getElementById('modalTitle').textContent = item.name;
    document.getElementById('modalDescription').textContent = item.description;
    document.getElementById('modalCategory').textContent = onlineData.categories.find(cat => cat.id === item.category).name;

    renderModalOptions();
    updateModalPrice();
    itemModal.hidden = false;
}

function renderModalOptions() {
    document.getElementById('portionOptions').innerHTML = onlineData.portions.map(portion => `
        <label>
            <input type="radio" name="portion" value="${portion.id}" ${portion.id === state.selectedPortion ? 'checked' : ''}>
            <span>${safeText(portion.name)} ${portion.add ? `+ ${money.format(portion.add)}` : ''}</span>
        </label>
    `).join('');

    document.getElementById('extraOptions').innerHTML = onlineData.extras.map(extra => `
        <label>
            <input type="checkbox" value="${extra.id}">
            <span>${safeText(extra.name)} + ${money.format(extra.price)}</span>
        </label>
    `).join('');
}

function updateModalPrice() {
    document.getElementById('modalQty').textContent = state.modalQty;
    document.getElementById('modalPrice').textContent = money.format(currentModalUnitPrice() * state.modalQty);
}

function addSelectedItemToCart() {
    const portion = getPortion(state.selectedPortion);
    const extras = state.selectedExtras.map(id => onlineData.extras.find(extra => extra.id === id));
    const unitPrice = currentModalUnitPrice();

    state.cart.push({
        id: state.selectedItem.id,
        name: state.selectedItem.name,
        portion: portion.id,
        portionName: portion.name,
        extras,
        unitPrice,
        qty: state.modalQty
    });

    itemModal.hidden = true;
    cartPanel.classList.add('open');
    renderCart();
}

function setStep(step) {
    document.getElementById('stepOne').hidden = step !== 1;
    document.getElementById('stepTwo').hidden = step !== 2;
    document.getElementById('stepOneLabel').classList.toggle('active', step === 1);
    document.getElementById('stepTwoLabel').classList.toggle('active', step === 2);
}

function validateDetails() {
    const name = document.getElementById('customerName').value.trim();
    const phone = document.getElementById('phoneNumber').value.trim();
    const address = document.getElementById('deliveryAddress').value.trim();
    const fulfillment = document.querySelector('[name="fulfillment"]:checked').value;
    let ok = true;

    document.getElementById('nameError').textContent = '';
    document.getElementById('phoneError').textContent = '';
    document.getElementById('addressError').textContent = '';

    if (!name) {
        document.getElementById('nameError').textContent = 'Name is required.';
        ok = false;
    }

    if (!/^09\d{9}$/.test(phone)) {
        document.getElementById('phoneError').textContent = 'Use a valid 11-digit PH mobile number.';
        ok = false;
    }

    if (fulfillment === 'Delivery' && !address) {
        document.getElementById('addressError').textContent = 'Delivery address is required.';
        ok = false;
    }

    return ok;
}

function placeOrder(event) {
    event.preventDefault();

    if (!state.cart.length) {
        cartPanel.classList.add('open');
        return;
    }

    const btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.querySelector('.btn-text').textContent = 'Placing Order';
    btn.querySelector('.btn-loader').hidden = false;

    setTimeout(() => {
        const totals = cartTotals();
        state.latestOrder = {
            orderNo: `ONL-${Date.now().toString().slice(-6)}`,
            total: totals.total,
            items: [...state.cart],
            status: 'Order Placed'
        };

        state.cart = [];
        state.trackingStage = 0;
        renderCart();
        renderTracking();
        btn.disabled = false;
        btn.querySelector('.btn-text').textContent = 'Place Order';
        btn.querySelector('.btn-loader').hidden = true;
        document.getElementById('trackingSection').scrollIntoView({ behavior: 'smooth' });
    }, 900);
}

function renderTracking() {
    const steps = ['Order Placed', 'Preparing', 'Ready', 'Out for Delivery', 'Delivered'];

    document.getElementById('trackingStepper').innerHTML = steps.map((step, index) => `
        <div class="track-step ${index <= state.trackingStage ? 'done' : ''}">
            <span>${index + 1}</span>
            <strong>${step}</strong>
        </div>
    `).join('');

    const order = state.latestOrder || {
        orderNo: 'No active order',
        total: 0,
        items: [],
        status: 'Waiting for order'
    };

    document.getElementById('receiptView').innerHTML = `
        <h3>${safeText(order.orderNo)}</h3>
        <p>Status: <strong>${safeText(steps[state.trackingStage] || order.status)}</strong></p>
        <p>Total: <strong>${money.format(order.total)}</strong></p>
        <small>This is a frontend mock tracking view for presentation.</small>
    `;
}

function renderHistory() {
    document.getElementById('historyGrid').innerHTML = onlineData.history.map(order => `
        <article class="history-card">
            <div>
                <span class="status-badge">${safeText(order.status)}</span>
                <h3>${safeText(order.orderNo)}</h3>
                <p>${safeText(order.date)}</p>
                <p>${safeText(order.items.join(', '))}</p>
                <strong>${money.format(order.total)}</strong>
            </div>
            <button class="secondary-btn" data-reorder="${order.orderNo}"><i class="bi bi-arrow-repeat"></i>Reorder</button>
        </article>
    `).join('');
}

categoryTabs.addEventListener('click', event => {
    const btn = event.target.closest('[data-category]');
    if (!btn) return;
    state.activeCategory = btn.dataset.category;
    renderCategories();
    renderMenu();
});

foodGrid.addEventListener('click', event => {
    const card = event.target.closest('[data-item]');
    if (!card) return;
    openItemModal(card.dataset.item);
});

cartItems.addEventListener('click', event => {
    const minus = event.target.closest('[data-cart-minus]');
    const plus = event.target.closest('[data-cart-plus]');
    const remove = event.target.closest('[data-cart-remove]');

    if (minus) {
        const item = state.cart[Number(minus.dataset.cartMinus)];
        item.qty -= 1;
        if (item.qty <= 0) state.cart.splice(Number(minus.dataset.cartMinus), 1);
    }

    if (plus) {
        state.cart[Number(plus.dataset.cartPlus)].qty += 1;
    }

    if (remove) {
        state.cart.splice(Number(remove.dataset.cartRemove), 1);
    }

    renderCart();
});

document.getElementById('portionOptions').addEventListener('change', event => {
    state.selectedPortion = event.target.value;
    updateModalPrice();
});

document.getElementById('extraOptions').addEventListener('change', () => {
    state.selectedExtras = Array.from(document.querySelectorAll('#extraOptions input:checked')).map(input => input.value);
    updateModalPrice();
});

document.querySelectorAll('[name="fulfillment"]').forEach(input => {
    input.addEventListener('change', () => {
        document.getElementById('addressWrap').hidden = input.value === 'Pickup' && input.checked;
        renderCart();
    });
});

searchInput.addEventListener('input', () => {
    state.search = searchInput.value.toLowerCase().trim();
    renderMenu();
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
    state.modalQty += 1;
    updateModalPrice();
});

document.getElementById('addToCartBtn').addEventListener('click', addSelectedItemToCart);
document.getElementById('closeModal').addEventListener('click', () => itemModal.hidden = true);
document.getElementById('cartToggle').addEventListener('click', () => cartPanel.classList.toggle('open'));
document.getElementById('mobileCartBtn').addEventListener('click', () => cartPanel.classList.add('open'));
document.getElementById('closeCart').addEventListener('click', () => cartPanel.classList.remove('open'));
document.getElementById('checkoutToggle').addEventListener('click', () => document.getElementById('checkoutSection').scrollIntoView({ behavior: 'smooth' }));
document.getElementById('continuePayment').addEventListener('click', () => validateDetails() && setStep(2));
document.getElementById('backDetails').addEventListener('click', () => setStep(1));
document.getElementById('checkoutForm').addEventListener('submit', placeOrder);

document.getElementById('historyGrid').addEventListener('click', event => {
    const btn = event.target.closest('[data-reorder]');
    if (!btn) return;
    const order = onlineData.history.find(row => row.orderNo === btn.dataset.reorder);

    order.itemIds.forEach(id => {
        const item = getItem(id);
        if (item && item.stock !== 'out') {
            state.cart.push({
                id: item.id,
                name: item.name,
                portion: 'regular',
                portionName: 'Regular',
                extras: [],
                unitPrice: item.price,
                qty: 1
            });
        }
    });

    cartPanel.classList.add('open');
    renderCart();
});

setInterval(() => {
    if (!state.latestOrder || state.trackingStage >= 4) return;
    state.trackingStage += 1;
    renderTracking();
}, 7000);

renderCategories();
renderMenu();
renderCart();
renderTracking();
renderHistory();
