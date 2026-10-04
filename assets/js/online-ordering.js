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
const upsellItems = document.getElementById('upsellItems');
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
const portionOptions = document.getElementById('portionOptions');
const riceOptions = document.getElementById('riceOptions');
const extraOptions = document.getElementById('extraOptions');
const modalSpecialNotes = document.getElementById('modalSpecialNotes');
const modalQty = document.getElementById('modalQty');
const modalPrice = document.getElementById('modalPrice');

// Auth & Checkout Elements
const authModal = document.getElementById('authModal');
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
    // Free delivery threshold: PHP 500. Under 500, delivery is PHP 45. Pickup is free.
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

function renderUpsellRecommendations() {
    if (!upsellItems) return;

    // Filter available add-ons and side dishes
    const currentCartIds = new Set(state.cart.map(i => Number(i.id)));
    const upsellCandidates = state.items.filter(item => {
        const isAvail = Number(item.stock) > 0 && Number(item.is_available) === 1;
        // Prefer extras (category 2), beverages, or budget sides
        const isTarget = Number(item.category_id) === 2 || currentUnitPrice(item) <= 45;
        return isAvail && isTarget && !currentCartIds.has(Number(item.id));
    }).slice(0, 4);

    if (!upsellCandidates.length) {
        const fallback = state.items.filter(item => Number(item.stock) > 0 && Number(item.is_available) === 1).slice(0, 3);
        upsellItems.innerHTML = fallback.map(renderUpsellChip).join('');
        return;
    }

    upsellItems.innerHTML = upsellCandidates.map(renderUpsellChip).join('');
}

function renderUpsellChip(item) {
    const price = currentUnitPrice(item);
    return `
        <div class="upsell-chip">
            <img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}">
            <strong>${escapeHtml(item.name)}</strong>
            <span>${money.format(price)}</span>
            <button type="button" class="upsell-add-btn" data-upsell-id="${item.id}">+ Add</button>
        </div>`;
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
                <span>Add a meal or snack to start your order.</span>
            </div>`;
    } else {
        cartItems.innerHTML = state.cart.map((item, index) => {
            const hasCustomization = Boolean(item.customization);
            return `
                <article class="cart-line" data-cart-key="${escapeHtml(item.cartKey || index)}">
                    <div>
                        <strong>${escapeHtml(item.name)}</strong>
                        <span>${money.format(item.unitPrice)} each</span>
                    </div>
                    ${hasCustomization ? `
                        <div class="cart-line-customs">
                            <span class="cart-custom-pill"><i class="bi bi-sliders"></i> ${escapeHtml(item.customization)}</span>
                        </div>` : ''}
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
    renderUpsellRecommendations();

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

// Quick-add an upsell item into the cart
function quickAddUpsell(itemId) {
    const item = getItem(itemId);
    if (!item || Number(item.stock) <= 0) return;

    const cartKey = `${item.id}_regular_default`;
    const existing = state.cart.find(c => c.cartKey === cartKey || (!c.customization && Number(c.id) === Number(item.id)));

    if (existing) {
        existing.qty = Math.min(Number(item.stock), Number(existing.qty) + 1);
    } else {
        state.cart.push({
            cartKey,
            id: Number(item.id),
            name: item.name,
            image_url: item.image_url,
            unitPrice: currentUnitPrice(item),
            addon_price: 0,
            customization: '',
            portion: 'Regular',
            rice: '',
            extras: [],
            notes: '',
            qty: 1
        });
    }

    renderCart();
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

// ==========================================================================
// Interactive Item Customization Modal
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
    if (modalStockPill) modalStockPill.textContent = `${item.stock} in stock`;

    // 1. Portion Choices
    const isBilao = Number(item.category_id) === 6 || /bilao|platter/i.test(item.name);
    if (portionOptions) {
        if (isBilao) {
            portionOptions.innerHTML = `
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalPortion" value="Regular / Standard Tray" data-price="0" checked>
                        <span>Standard Tray (4-6 pax)</span>
                    </div>
                    <span class="addon-price">Included</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalPortion" value="Large Tray (8-10 pax)" data-price="250">
                        <span>Large Tray (8-10 pax)</span>
                    </div>
                    <span class="addon-price">+₱250.00</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalPortion" value="Party XL Tray (12-15 pax)" data-price="450">
                        <span>Party XL Tray (12-15 pax)</span>
                    </div>
                    <span class="addon-price">+₱450.00</span>
                </label>`;
        } else {
            portionOptions.innerHTML = `
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalPortion" value="Regular" data-price="0" checked>
                        <span>Regular Serving</span>
                    </div>
                    <span class="addon-price">Included</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalPortion" value="Upsize / Extra Meat" data-price="35">
                        <span>Upsize / Extra Meat</span>
                    </div>
                    <span class="addon-price">+₱35.00</span>
                </label>`;
        }
    }

    // 2. Rice Choices (Customize based on dish type)
    const isBeverageOrSide = Number(item.category_id) === 2 || /drink|coke|shake|juice|dessert/i.test(item.name);
    if (riceOptions) {
        if (isBeverageOrSide || isBilao) {
            riceOptions.innerHTML = `
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalRice" value="No Rice" data-price="0" checked>
                        <span>No Rice Needed</span>
                    </div>
                    <span class="addon-price">Included</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalRice" value="Add Steamed Rice" data-price="15">
                        <span>Add Plain Steamed Rice</span>
                    </div>
                    <span class="addon-price">+₱15.00</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalRice" value="Add Garlic Rice" data-price="20">
                        <span>Add Garlic Fried Rice</span>
                    </div>
                    <span class="addon-price">+₱20.00</span>
                </label>`;
        } else {
            riceOptions.innerHTML = `
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalRice" value="Plain Steamed Rice" data-price="0" checked>
                        <span>Plain Steamed Rice</span>
                    </div>
                    <span class="addon-price">Included</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalRice" value="Garlic Fried Rice" data-price="15">
                        <span>Upgrade to Garlic Rice</span>
                    </div>
                    <span class="addon-price">+₱15.00</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalRice" value="Java Rice" data-price="20">
                        <span>Upgrade to Java Rice</span>
                    </div>
                    <span class="addon-price">+₱20.00</span>
                </label>
                <label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="radio" name="modalRice" value="Less Rice" data-price="0">
                        <span>Less Rice / Keto</span>
                    </div>
                    <span class="addon-price">+₱0.00</span>
                </label>`;
        }
    }

    // 3. Extras & Add-ons (Checkboxes)
    if (extraOptions) {
        extraOptions.innerHTML = `
            <label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="modalExtra" value="Crispy Fried Egg" data-price="15">
                    <span>Crispy Fried Egg</span>
                </div>
                <span class="addon-price">+₱15.00</span>
            </label>
            <label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="modalExtra" value="Extra Savory Gravy" data-price="10">
                    <span>Extra Savory Gravy</span>
                </div>
                <span class="addon-price">+₱10.00</span>
            </label>
            <label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="modalExtra" value="House Atchara Pickles" data-price="15">
                    <span>House Atchara Pickles</span>
                </div>
                <span class="addon-price">+₱15.00</span>
            </label>
            <label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="modalExtra" value="Extra Chili Crunch" data-price="10">
                    <span>Extra Chili Crunch & Garlic</span>
                </div>
                <span class="addon-price">+₱10.00</span>
            </label>`;
    }

    if (modalSpecialNotes) modalSpecialNotes.value = '';

    updateModalPrice();
    itemModal.hidden = false;
}

function calculateModalPrice() {
    if (!state.selectedItem) return { base: 0, addonPrice: 0, unitPrice: 0, total: 0 };
    const base = currentUnitPrice(state.selectedItem);

    const portionEl = document.querySelector('input[name="modalPortion"]:checked');
    const portionPrice = portionEl ? Number(portionEl.dataset.price || 0) : 0;

    const riceEl = document.querySelector('input[name="modalRice"]:checked');
    const ricePrice = riceEl ? Number(riceEl.dataset.price || 0) : 0;

    let extrasPrice = 0;
    document.querySelectorAll('input[name="modalExtra"]:checked').forEach(cb => {
        extrasPrice += Number(cb.dataset.price || 0);
    });

    const addonPrice = portionPrice + ricePrice + extrasPrice;
    const unitPrice = base + addonPrice;
    const total = unitPrice * state.modalQty;
    return { base, addonPrice, unitPrice, total };
}

function updateModalPrice() {
    if (modalQty) modalQty.textContent = state.modalQty;
    const calculated = calculateModalPrice();
    if (modalPrice) modalPrice.textContent = money.format(calculated.total);
}

function addSelectedItemToCart() {
    if (!state.selectedItem) return;

    const portionEl = document.querySelector('input[name="modalPortion"]:checked');
    const portionName = portionEl ? portionEl.value : 'Regular';

    const riceEl = document.querySelector('input[name="modalRice"]:checked');
    const riceName = riceEl ? riceEl.value : '';

    const selectedExtras = Array.from(document.querySelectorAll('input[name="modalExtra"]:checked')).map(cb => cb.value);
    const notes = modalSpecialNotes ? modalSpecialNotes.value.trim() : '';

    const calculated = calculateModalPrice();

    // Build human-readable customization description
    const customParts = [];
    if (portionName && portionName !== 'Regular' && portionName !== 'Regular / Standard Tray') {
        customParts.push(portionName);
    }
    if (riceName && riceName !== 'Plain Steamed Rice' && riceName !== 'No Rice') {
        customParts.push(riceName);
    }
    if (selectedExtras.length) {
        customParts.push(...selectedExtras);
    }
    if (notes) {
        customParts.push(`Note: ${notes}`);
    }
    const customization = customParts.join(', ');

    // Unique cart key to isolate distinct customization combinations
    const cartKey = `${state.selectedItem.id}|${portionName}|${riceName}|${selectedExtras.sort().join(';')}|${notes}`;

    const existing = state.cart.find(c => c.cartKey === cartKey);
    if (existing) {
        existing.qty = Math.min(Number(state.selectedItem.stock), Number(existing.qty) + state.modalQty);
    } else {
        state.cart.push({
            cartKey,
            id: Number(state.selectedItem.id),
            name: state.selectedItem.name,
            image_url: state.selectedItem.image_url,
            unitPrice: calculated.unitPrice,
            addon_price: calculated.addonPrice,
            customization,
            portion: portionName,
            rice: riceName,
            extras: selectedExtras,
            notes,
            qty: state.modalQty
        });
    }

    itemModal.hidden = true;
    renderCart();
    openCart();
}

// ==========================================================================
// Authentication & Guest Checkout Handling
// ==========================================================================
function openAuth(tab = 'signin') {
    authModal.hidden = false;
    setAuthTab(tab);
}

function closeAuth() {
    authModal.hidden = true;
}

function setAuthTab(tab) {
    const signIn = tab === 'signin';
    document.getElementById('signInTab')?.classList.toggle('active', signIn);
    document.getElementById('registerTab')?.classList.toggle('active', !signIn);
    document.getElementById('signInForm').hidden = !signIn;
    document.getElementById('registerForm').hidden = signIn;
    document.getElementById('authTitle').textContent = signIn ? 'Sign In' : 'Create Account';
    const subtitle = document.getElementById('authSubtitle');
    if (subtitle) {
        subtitle.textContent = signIn
            ? 'Sign in to track orders, save delivery addresses, and enjoy faster checkout.'
            : 'Register a new customer account to save your favorite dishes and details.';
    }
}

function setAccountUI() {
    const btn = document.getElementById('accountBtn');
    if (!state.user) {
        if (btn) {
            btn.textContent = 'Sign In';
            btn.onclick = () => openAuth('signin');
        }

        // Guest State in Checkout
        if (guestNoticeText) {
            guestNoticeText.innerHTML = `Ordering as <strong>Guest</strong>. <a href="#" id="checkoutSignInLink">Sign in</a> to load your saved profile.`;
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
            if (!confirm(`Log out from account "${state.user.full_name}"?`)) return;
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
        if (phoneErr) phoneErr.textContent = 'Use a valid 11-digit PH mobile number starting with 09.';
        ok = false;
    }
    if (fulfillment === 'Delivery' && !address) {
        if (addrErr) addrErr.textContent = 'Delivery address is required for dispatch.';
        ok = false;
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (emailErr) emailErr.textContent = 'Enter a valid email address.';
        ok = false;
    }

    // If guest opting to create account, validate password
    if (!state.user && saveAccountCheckbox?.checked) {
        const pwd = guestPassword?.value || '';
        if (pwd.length < 6) {
            alert('Please provide a password of at least 6 characters to create your account.');
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
            addon_price: Number(item.addon_price || 0),
            customization: item.customization || ''
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

    // Order Success
    state.latestOrder = result.data;
    state.cart = [];
    renderCart();
    renderTracking(result.data);

    // If account was created or guest linked, refresh user session
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

    alert(`Order ${result.data.order_number} was placed successfully! You can track your kitchen queue status below.`);
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
                <p>Orders linked to your phone or account appear here after signing in.</p>
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
                        <p>${escapeHtml(result.message || 'Please refresh the page and try again.')}</p>
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

    // 2. Guest Skip / Save Account
    const guestAuthSkipBtn = document.getElementById('guestAuthSkipBtn');
    if (guestAuthSkipBtn) {
        guestAuthSkipBtn.addEventListener('click', () => {
            closeAuth();
            const checkoutSec = document.getElementById('checkoutSection');
            if (checkoutSec) {
                checkoutSec.scrollIntoView({ behavior: 'smooth' });
                setTimeout(() => document.getElementById('customerName')?.focus(), 350);
            }
        });
    }

    if (saveAccountCheckbox && guestPasswordWrap) {
        saveAccountCheckbox.addEventListener('change', () => {
            guestPasswordWrap.hidden = !saveAccountCheckbox.checked;
            if (saveAccountCheckbox.checked && guestPassword) {
                guestPassword.focus();
            }
        });
    }

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
                openItemModal(Number(addButton.dataset.add));
                return;
            }
            const card = event.target.closest('[data-item]');
            if (card) openItemModal(Number(card.dataset.item));
        });
    }

    // 4. Cart Controls & Upsell Quick Add
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

    if (upsellItems) {
        upsellItems.addEventListener('click', event => {
            const upsellBtn = event.target.closest('[data-upsell-id]');
            if (upsellBtn) {
                quickAddUpsell(Number(upsellBtn.dataset.upsellId));
            }
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

    // 6. Item Modal Controls & Dynamic Price Recalculation
    document.getElementById('modalMinus')?.addEventListener('click', () => {
        state.modalQty = Math.max(1, state.modalQty - 1);
        updateModalPrice();
    });

    document.getElementById('modalPlus')?.addEventListener('click', () => {
        const max = Math.max(1, Number(state.selectedItem?.stock || 1));
        state.modalQty = Math.min(max, state.modalQty + 1);
        updateModalPrice();
    });

    document.getElementById('portionOptions')?.addEventListener('change', updateModalPrice);
    document.getElementById('riceOptions')?.addEventListener('change', updateModalPrice);
    document.getElementById('extraOptions')?.addEventListener('change', updateModalPrice);

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

    // 9. Auth Modal Toggling
    document.getElementById('signInTab')?.addEventListener('click', () => setAuthTab('signin'));
    document.getElementById('registerTab')?.addEventListener('click', () => setAuthTab('register'));
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
// Landing Page Animations & Interactive Utilities
// ==========================================================================
function initLandingFeatures() {
    // Category Quick-Jump, Hero Quick-Add & Back to top
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
            statusText.textContent = 'Kitchen Open Now';
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
