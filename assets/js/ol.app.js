/**
 * Kenji's Kitchen - Foodpanda-Style Frontend Client Application (v1.1.0)
 * 
 * Features:
 * - Real-time stock status sync & active catalog rendering
 * - Sticky header with search & delivery/pickup toggle
 * - Horizontal smooth category navigation with 8 categories
 * - 2-column food item cards with stock badge and "+" add button
 * - Special Instructions / Item Notes capture (saved to order_items.notes)
 * - Floating Cart Bar and Slide-Up Checkout Drawer
 * - 6-Digit OTP verification flow with 5-minute TTL & rate limit warnings
 * - Live Order Placement with immediate KDS & POS broadcast
 */

'use strict';

const API_ROOT = window.location.port === '8787'
  ? 'http://127.0.0.1:8787/api'
  : (window.location.pathname.includes('/modules/online/') ? './api' : '../modules/online/api');

// Application State
const appState = {
  categories: [
    { id: 'all', name: '<i class="bi bi-grid-3x3-gap"></i> All' },
    { id: 'add_ons', name: '<i class="bi bi-plus-circle"></i> Add-Ons' },
    { id: 'bilao', name: '<i class="bi bi-basket"></i> Bilao Favorites' },
    { id: 'drinks', name: '<i class="bi bi-cup-straw"></i> Drinks' },
    { id: 'pulutan_pares', name: '<i class="bi bi-egg-fried"></i> Pulutan & Pares' },
    { id: 'silog_meals', name: '<i class="bi bi-egg-fried"></i> Silog Meals' },
    { id: 'set_meals', name: '<i class="bi bi-box"></i> Silog Set Meals' },
    { id: 'student_meals', name: '<i class="bi bi-mortarboard"></i> Student Meals' }
  ],
  menuItems: [],
  activeCategory: 'all',
  searchQuery: '',
  fulfillmentMode: 'delivery', // 'delivery' | 'pickup'
  cart: JSON.parse(localStorage.getItem('kk_cart_v110') || '[]'),
  currentModalItem: null,
  selectedSizeIndex: null,
  modalQty: 1,
  currentUser: JSON.parse(localStorage.getItem('kk_customer_session') || 'null'),
  lastOrderId: null,
  favoriteIds: new Set(),
  favoriteItems: [],
  ordersPollInterval: null,
  trackedOrderStatuses: {},
  gcashSettingsPromise: null,
  otpSentEmail: '',
  otpTimerInterval: null
};

// Formatting helper
const formatPeso = val => `₱${Number(val || 0).toFixed(2)}`;

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function saveCart() {
  localStorage.setItem('kk_cart_v110', JSON.stringify(appState.cart));
  updateCartUI();
}

/**
 * Initialize Frontend on DOM Load
 */
document.addEventListener('DOMContentLoaded', async () => {
  renderCategoryPills();
  setupUIEventListeners();
  updateCartUI();
  updateUserUI();
  await loadOnlineGcashSettings();
  await syncCustomerSession();
  await loadMenuItems();
});

async function loadOnlineGcashSettings() {
  if (appState.gcashSettingsPromise) return appState.gcashSettingsPromise;
  appState.gcashSettingsPromise = (async () => {
    try {
      const response = await fetch('./api/payment_settings.php', { credentials: 'same-origin', cache: 'no-store' });
      const result = await response.json();
      if (!response.ok || result.status !== 'success') return;

      const phone = String(result.data?.gcash_phone || '').replace(/\D/g, '');
      const phoneNode = document.getElementById('onlineGcashPhone');
      if (phoneNode && /^09\d{9}$/.test(phone)) phoneNode.textContent = phone;

      const frame = document.querySelector('.gcash-qr-frame');
      const image = document.getElementById('onlineGcashQr');
      const imageUrl = result.data?.gcash_qr_url;
      if (!frame || !image || typeof imageUrl !== 'string' || !imageUrl.startsWith('/') || imageUrl.startsWith('//')) return;

      frame.classList.remove('loaded', 'unavailable');
      image.onload = () => frame.classList.add('loaded');
      image.onerror = () => frame.classList.add('unavailable');
      image.src = imageUrl;
    } catch (_) {
      // Keep the configured default number visible if settings cannot be fetched.
      appState.gcashSettingsPromise = null;
    }
  })();
  return appState.gcashSettingsPromise;
}

/**
 * Render the 8 category pills
 */
function renderCategoryPills() {
  const container = document.getElementById('categoryStrip');
  if (!container) return;

  container.innerHTML = appState.categories.map(cat => `
    <button class="cat-pill ${appState.activeCategory === cat.id ? 'active' : ''}" data-cat-id="${cat.id}">
      ${cat.name}
    </button>
  `).join('');
}

/**
 * Setup Event Listeners
 */
function setupUIEventListeners() {
  // Category Pill Selection
  const strip = document.getElementById('categoryStrip');
  if (strip) {
    strip.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-cat-id]');
      if (!btn) return;
      document.querySelectorAll('.cat-pill').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      appState.activeCategory = btn.dataset.catId;
      filterAndRenderMenu();
    });
  }

  // Search Input
  const searchInput = document.getElementById('searchInput');
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      appState.searchQuery = e.target.value.toLowerCase().trim();
      filterAndRenderMenu();
    });
  }

  // Delivery / Pickup / Dine-In Toggle
  document.querySelectorAll('.fulfillment-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      document.querySelectorAll('.fulfillment-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      appState.fulfillmentMode = btn.dataset.mode;
      updateFulfillmentDetails();
    });
  });
}

/**
 * Load Menu Items from API
 */
async function loadMenuItems() {
  const grid = document.getElementById('foodGrid');
  if (grid) {
    grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding: 40px; color: var(--muted);">Loading menu items...</div>';
  }

  try {
    const res = await fetch(`${API_ROOT}/menu.php?action=items`);
    const data = await res.json();
    if (data.status === 'success' && Array.isArray(data.data)) {
      appState.menuItems = data.data;
    }
  } catch (_) {
    console.warn('Backend API unavailable. Relying on cached or embedded catalog.');
  }

  filterAndRenderMenu();
}

/**
 * Filter & Render Menu Grid
 */
function filterAndRenderMenu() {
  const grid = document.getElementById('foodGrid');
  if (!grid) return;

  let items = appState.menuItems;

  // Filter category
  if (appState.activeCategory !== 'all') {
    items = items.filter(i => {
      const slug = (i.category_slug || '').toLowerCase();
      const catName = (i.category_name || '').toLowerCase();
      return slug === appState.activeCategory || catName.includes(appState.activeCategory.replace('_', ' '));
    });
  }

  // Filter search
  if (appState.searchQuery) {
    items = items.filter(i => (i.name || '').toLowerCase().includes(appState.searchQuery) || (i.description || '').toLowerCase().includes(appState.searchQuery));
  }

  if (!items.length) {
    grid.innerHTML = `
      <div style="grid-column: 1/-1; text-align: center; padding: 48px 16px;">
        <div style="font-size: 2.2rem; margin-bottom: 8px;"><i class="bi bi-search"></i></div>
        <h3 style="font-size: 1.1rem; color: var(--dark); margin-bottom: 4px;">No delicious items found</h3>
        <p style="color: var(--muted); font-size: 0.85rem;">Try another category search or clear your filter.</p>
      </div>
    `;
    return;
  }

  grid.innerHTML = items.map(item => {
    const stock = Number(item.stock ?? item.pcs_available ?? 0);
    const isOut = stock <= 0 || item.availability === 'Unavailable';
    const isLow = stock > 0 && stock <= 5;
    const price = Number(item.effective_price || item.price);
    const hasPromo = Boolean(item.is_promo);

    return `
      <article class="food-card ${isOut ? 'out-of-stock' : ''}" onclick="handleCardClick(${item.id})">
        <div class="card-img-wrapper">
          <img src="${item.image_url || item.image || '../../assets/img/logo.jpg'}" alt="${escapeHtml(item.name)}" onerror="this.src='../../assets/img/logo.jpg'">
          <button type="button" class="favorite-toggle ${appState.favoriteIds.has(Number(item.id)) ? 'active' : ''}" onclick="event.stopPropagation(); toggleFavorite(${Number(item.id)})" aria-label="${appState.favoriteIds.has(Number(item.id)) ? 'Remove from' : 'Add to'} favorites" title="${appState.favoriteIds.has(Number(item.id)) ? 'Remove from' : 'Add to'} favorites">
            <i class="bi ${appState.favoriteIds.has(Number(item.id)) ? 'bi-heart-fill' : 'bi-heart'}"></i>
          </button>
          ${isOut 
            ? '<span class="stock-tag sold-out">Sold Out</span>'
            : (isLow 
                ? `<span class="stock-tag low-stock">Only ${stock} left</span>`
                : `<span class="stock-tag">${stock} pcs left</span>`)}
          ${hasPromo ? '<span class="promo-tag">SALE</span>' : ''}
        </div>

        <div class="food-card-body">
          <span class="food-category">${escapeHtml(item.category_name || 'Kenji Special')}</span>
          <h3 class="food-title">${escapeHtml(item.name)}</h3>
          <p class="food-desc">${escapeHtml(item.description || '')}</p>

          <div class="food-card-footer">
            <div class="price-container">
              <span class="food-price">${formatPeso(price)}</span>
              ${hasPromo ? `<span class="old-price">${formatPeso(item.price)}</span>` : ''}
            </div>

            <button class="btn-quick-add" ${isOut ? 'disabled' : ''} onclick="event.stopPropagation(); quickAddToCart(${item.id})">
              +
            </button>
          </div>
        </div>
      </article>
    `;
  }).join('');
}

/**
 * Noodle & Pasta Interactive Size and Price Configurations
 */
const NOODLE_PASTA_SIZES = {
  // Spaghetti / Carbonara
  'spaghetti': [
    { label: 'Small (S)', sizeKey: 'Small (S)', price: 500 },
    { label: 'Medium (M)', sizeKey: 'Medium (M)', price: 850 },
    { label: 'Large (L)', sizeKey: 'Large (L)', price: 1200 },
    { label: 'Extra Large (XL)', sizeKey: 'Extra Large (XL)', price: 1550 }
  ],
  'carbonara': [
    { label: 'Small (S)', sizeKey: 'Small (S)', price: 500 },
    { label: 'Medium (M)', sizeKey: 'Medium (M)', price: 850 },
    { label: 'Large (L)', sizeKey: 'Large (L)', price: 1200 },
    { label: 'Extra Large (XL)', sizeKey: 'Extra Large (XL)', price: 1550 }
  ],
  // Palabok / Sotanghon
  'palabok': [
    { label: 'Small (S)', sizeKey: 'Small (S)', price: 650 },
    { label: 'Medium (M)', sizeKey: 'Medium (M)', price: 850 },
    { label: 'Large (L)', sizeKey: 'Large (L)', price: 1000 },
    { label: 'Extra Large (XL)', sizeKey: 'Extra Large (XL)', price: 1200 }
  ],
  'sotanghon': [
    { label: 'Small (S)', sizeKey: 'Small (S)', price: 650 },
    { label: 'Medium (M)', sizeKey: 'Medium (M)', price: 850 },
    { label: 'Large (L)', sizeKey: 'Large (L)', price: 1000 },
    { label: 'Extra Large (XL)', sizeKey: 'Extra Large (XL)', price: 1200 }
  ],
  // Bihon / Canton
  'bihon': [
    { label: 'Small (S)', sizeKey: 'Small (S)', price: 550 },
    { label: 'Medium (M)', sizeKey: 'Medium (M)', price: 750 },
    { label: 'Large (L)', sizeKey: 'Large (L)', price: 950 },
    { label: 'Extra Large (XL)', sizeKey: 'Extra Large (XL)', price: 1100 }
  ],
  'canton': [
    { label: 'Small (S)', sizeKey: 'Small (S)', price: 550 },
    { label: 'Medium (M)', sizeKey: 'Medium (M)', price: 750 },
    { label: 'Large (L)', sizeKey: 'Large (L)', price: 950 },
    { label: 'Extra Large (XL)', sizeKey: 'Extra Large (XL)', price: 1100 }
  ]
};

function getItemSizeOptions(itemName) {
  if (!itemName) return null;
  const lower = itemName.toLowerCase();
  for (const [key, options] of Object.entries(NOODLE_PASTA_SIZES)) {
    if (lower.includes(key)) {
      return options;
    }
  }
  return null;
}

function getBilaoBaseName(itemName) {
  return String(itemName || '')
    .replace(/\b(?:extra\s+large|x-large|xl|large|medium|small|s|m|l)\b/ig, ' ')
    .replace(/\bbilao\b/ig, ' ')
    .replace(/[()\-/]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function getCartVariantSize(item) {
  if (['S', 'M', 'L', 'XL'].includes(item.variantSize)) return item.variantSize;
  const name = String(item.name || '');
  if (/\b(?:XL|X-LARGE|EXTRA\s+LARGE)\b/i.test(name)) return 'XL';
  if (/\b(?:LARGE|L)\b/i.test(name)) return 'L';
  if (/\b(?:MEDIUM|M)\b/i.test(name)) return 'M';
  if (/\b(?:SMALL|S)\b/i.test(name)) return 'S';
  return null;
}

/**
 * Open Item Detail Modal with Interactive Size Selection & Special Instructions
 */
function handleCardClick(itemId) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(itemId));
  if (!item) return;

  const stock = Number(item.stock ?? item.pcs_available ?? 0);
  if (stock <= 0 || item.availability === 'Unavailable') {
    showToast('This item is currently sold out.');
    return;
  }

  appState.currentModalItem = item;
  appState.modalQty = 1;

  const sizeOptions = getItemSizeOptions(item.name);
  const sizeContainer = document.getElementById('modalSizePickerContainer');
  const sizeGrid = document.getElementById('modalSizeOptions');

  if (sizeOptions && sizeOptions.length > 0) {
    // Default to the first size or find one matching current item price
    appState.selectedSizeOption = sizeOptions[0];
    appState.selectedSizeIndex = 0;
    appState.modalUnitPrice = sizeOptions[0].price;

    sizeGrid.innerHTML = sizeOptions.map((opt, idx) => `
      <div class="size-option-card ${idx === 0 ? 'selected' : ''}" onclick="selectModalSize(${idx})">
        <div class="size-info-col">
          <span class="size-name-tag">${escapeHtml(opt.label)}</span>
          <span class="size-price-tag">${formatPeso(opt.price)}</span>
        </div>
        <div class="size-radio-indicator"></div>
      </div>
    `).join('');
    sizeContainer.style.display = 'block';
  } else {
    appState.selectedSizeOption = null;
    appState.selectedSizeIndex = null;
    appState.modalUnitPrice = Number(item.effective_price || item.price);
    if (sizeContainer) sizeContainer.style.display = 'none';
  }

  document.getElementById('modalItemTitle').textContent = item.name;
  document.getElementById('modalItemDesc').textContent = item.description || '';
  document.getElementById('modalItemPrice').textContent = formatPeso(appState.modalUnitPrice);
  document.getElementById('modalItemImg').src = item.image_url || item.image || '../../assets/img/logo.jpg';
  document.getElementById('modalItemNotes').value = '';
  document.getElementById('modalQty').textContent = '1';

  document.getElementById('itemDetailModal').classList.add('active');
}

function selectModalSize(index) {
  if (!appState.currentModalItem) return;
  const sizeOptions = getItemSizeOptions(appState.currentModalItem.name);
  if (!sizeOptions || !sizeOptions[index]) return;

  appState.selectedSizeOption = sizeOptions[index];
  appState.selectedSizeIndex = index;
  appState.modalUnitPrice = sizeOptions[index].price;

  // Update UI selection
  const cards = document.querySelectorAll('#modalSizeOptions .size-option-card');
  cards.forEach((c, idx) => {
    c.classList.toggle('selected', idx === index);
  });

  // Recalculate price
  document.getElementById('modalItemPrice').textContent = formatPeso(appState.modalUnitPrice * appState.modalQty);
}

function adjustModalQty(delta) {
  appState.modalQty = Math.max(1, Math.min(99, appState.modalQty + delta));
  document.getElementById('modalQty').textContent = appState.modalQty;
  const unitPrice = appState.modalUnitPrice || Number(appState.currentModalItem.effective_price || appState.currentModalItem.price);
  document.getElementById('modalItemPrice').textContent = formatPeso(unitPrice * appState.modalQty);
}

function confirmAddItemFromModal() {
  if (!appState.currentModalItem) return;

  const item = appState.currentModalItem;
  const notes = document.getElementById('modalItemNotes').value.trim();
  const unitPrice = appState.modalUnitPrice || Number(item.effective_price || item.price);
  const selectedIndex = appState.selectedSizeOption ? appState.selectedSizeIndex : -1;
  const variantSize = selectedIndex >= 0 ? ['S', 'M', 'L', 'XL'][selectedIndex] : null;
  const finalName = variantSize
    ? `${getBilaoBaseName(item.name)} (${variantSize})`
    : item.name;

  addItemToCart(item.id, finalName, unitPrice, appState.modalQty, notes, variantSize);
  closeModal('itemDetailModal');
  showToast(`Added ${appState.modalQty}x ${finalName} to cart!`);
}

/**
 * Quick Add ("+" button)
 */
function quickAddToCart(itemId) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(itemId));
  if (!item) return;

  // If item has multiple size/portion options, open the interactive size picker modal
  const sizeOptions = getItemSizeOptions(item.name);
  if (sizeOptions && sizeOptions.length > 0) {
    handleCardClick(itemId);
    return;
  }

  const unitPrice = Number(item.effective_price || item.price);
  addItemToCart(item.id, item.name, unitPrice, 1, '');
  showToast(`Added ${item.name} to cart!`);
}

/**
 * Core Cart Management
 */
function addItemToCart(id, name, price, qty, notes, variantSize = null) {
  const existing = appState.cart.find(i => i.id === id && i.variantSize === variantSize && (i.notes || '') === (notes || ''));
  if (existing) {
    existing.qty += qty;
  } else {
    appState.cart.push({ id, name, price, qty, notes: notes || '', variantSize });
  }
  saveCart();
}

function updateCartUI() {
  const totalCount = appState.cart.reduce((sum, i) => sum + i.qty, 0);
  const totalAmount = appState.cart.reduce((sum, i) => sum + (i.price * i.qty), 0);

  // Update Nav Badge
  const navBadge = document.getElementById('navCartBadge');
  if (navBadge) navBadge.textContent = totalCount;

  // Update Floating Cart Bar
  const floatingBar = document.getElementById('floatingCartBar');
  if (floatingBar) {
    if (totalCount > 0) {
      floatingBar.style.display = 'flex';
      document.getElementById('floatingCartCount').textContent = totalCount;
      document.getElementById('floatingCartTotal').textContent = formatPeso(totalAmount);
    } else {
      floatingBar.style.display = 'none';
    }
  }

  // Update Slide-Up Drawer Items
  renderCartDrawerItems(totalAmount);
}

function renderCartDrawerItems(totalAmount) {
  const container = document.getElementById('cartDrawerItems');
  if (!container) return;

  if (!appState.cart.length) {
    container.innerHTML = `
      <div style="text-align: center; padding: 40px 16px; color: var(--muted);">
        <div style="font-size: 2.2rem; margin-bottom: 8px;"><i class="bi bi-cart3"></i></div>
        <strong>Your shopping cart is empty.</strong>
        <p style="font-size: 0.85rem; margin-top: 4px;">Choose from our tasty meals above to get started!</p>
      </div>
    `;
    const checkBtn = document.getElementById('btnProceedCheckout');
    if (checkBtn) checkBtn.disabled = true;
    return;
  }

  const checkBtn = document.getElementById('btnProceedCheckout');
  if (checkBtn) checkBtn.disabled = false;

  container.innerHTML = appState.cart.map((item, idx) => `
    <div class="cart-item-row">
      <div class="cart-item-main">
        <div class="cart-item-info">
          <strong>${escapeHtml(item.name)}</strong>
          <span>${formatPeso(item.price)} each</span>
          ${item.notes ? `<div class="item-notes-text">Note: ${escapeHtml(item.notes)}</div>` : ''}
        </div>
        <strong style="color: var(--dark);">${formatPeso(item.price * item.qty)}</strong>
      </div>

      <div class="cart-item-controls">
        <div class="qty-pill">
          <button class="qty-btn" onclick="changeCartItemQty(${idx}, -1)">-</button>
          <span class="qty-value">${item.qty}</span>
          <button class="qty-btn" onclick="changeCartItemQty(${idx}, 1)">+</button>
        </div>
        <button style="border: none; background: none; color: var(--danger); font-size: 0.8rem; cursor: pointer; font-weight: 700;" onclick="removeCartItem(${idx})">
          Remove
        </button>
      </div>
    </div>
  `).join('');

  document.getElementById('drawerSubtotal').textContent = formatPeso(totalAmount);
  document.getElementById('drawerTotal').textContent = formatPeso(totalAmount);
}

function changeCartItemQty(index, delta) {
  if (!appState.cart[index]) return;
  appState.cart[index].qty += delta;
  if (appState.cart[index].qty <= 0) {
    appState.cart.splice(index, 1);
  }
  saveCart();
}

function removeCartItem(index) {
  appState.cart.splice(index, 1);
  saveCart();
}

/**
 * Open & Close Slide-Up Drawer
 */
function openCartDrawer() {
  document.getElementById('drawerBackdrop').classList.add('active');
  document.getElementById('cartDrawerSheet').classList.add('active');
}

function closeCartDrawer() {
  document.getElementById('drawerBackdrop').classList.remove('active');
  document.getElementById('cartDrawerSheet').classList.remove('active');
}

/**
 * Checkout Wizard & OTP Flow
 */
function proceedToCheckout() {
  closeCartDrawer();

  // If user is not authenticated, trigger OTP sign-in modal
  if (!appState.currentUser) {
    openOtpModal();
    return;
  }

  openCheckoutModal();
}

function openOtpModal() {
  // Opens the new unified auth modal in sign-in mode
  openAuthModal('signin');
}

async function handleRequestOtp() {
  const email = document.getElementById('otpEmailInput').value.trim();
  if (!email || !email.includes('@')) {
    alert('Please enter a valid email address.');
    return;
  }

  const btn = document.getElementById('btnRequestOtp');
  btn.disabled = true;
  btn.textContent = 'Sending code...';

  try {
    let res;
    // Try Node backend first if running, otherwise use PHP endpoint
    try {
      res = await fetch(`${API_ROOT}/auth/otp/send`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email })
      });
      if (!res.ok && res.status === 404) throw new Error('fallback');
    } catch (_) {
      res = await fetch('./api/auth.php?action=otp_send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email })
      });
    }
    const result = await res.json();

    if (result.ok) {
      appState.otpSentEmail = email;
      document.getElementById('otpTargetEmail').textContent = email;
      document.getElementById('otpStepEmail').style.display = 'none';
      document.getElementById('otpStepCode').style.display = 'block';
      startOtpCountdown(300); // 5 minutes TTL
    } else {
      alert(result.message || 'Unable to send OTP.');
    }
  } catch (err) {
    alert('Unable to send verification code. Please check your network or try again.');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Send Verification Code';
  }
}

function startOtpCountdown(seconds) {
  let left = seconds;
  const display = document.getElementById('otpTimerDisplay');
  clearInterval(appState.otpTimerInterval);

  appState.otpTimerInterval = setInterval(() => {
    left--;
    const mins = Math.floor(left / 60);
    const secs = String(left % 60).padStart(2, '0');
    if (display) display.textContent = `${mins}:${secs}`;

    if (left <= 0) {
      clearInterval(appState.otpTimerInterval);
      if (display) display.textContent = 'Expired';
    }
  }, 1000);
}

async function handleVerifyOtp() {
  const code = document.getElementById('otpCodeInput').value.trim();
  if (code.length !== 6) {
    alert('Enter the 6-digit code received in your email.');
    return;
  }

  try {
    let res;
    try {
      res = await fetch(`${API_ROOT}/auth/otp/verify`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: appState.otpSentEmail, code })
      });
      if (!res.ok && res.status === 404) throw new Error('fallback');
    } catch (_) {
      res = await fetch('./api/auth.php?action=otp_verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: appState.otpSentEmail, code })
      });
    }
    const result = await res.json();

    if (result.ok) {
      clearInterval(appState.otpTimerInterval);
      appState.currentUser = result.user || { email: appState.otpSentEmail, full_name: appState.otpSentEmail.split('@')[0] };
      localStorage.setItem('kk_customer_session', JSON.stringify(appState.currentUser));
      updateUserUI();
      await loadFavoriteData();
      closeModal('otpModal');
      showToast('Verified successfully!');
      openCheckoutModal();
    } else {
      alert(result.message || 'Invalid code.');
    }
  } catch (_) {
    alert('Unable to verify OTP.');
  }
}

function toggleOnlinePaymentMethod() {
  const method = document.querySelector('input[name="paymentMethod"]:checked')?.value || 'Cash';
  const gcashWrap = document.getElementById('onlineGcashWrap');
  if (gcashWrap) {
    gcashWrap.style.display = method.toLowerCase() === 'gcash' ? 'block' : 'none';
    if (method.toLowerCase() === 'gcash') loadOnlineGcashSettings();
  }
}

function openCheckoutModal() {
  if (appState.currentUser) {
    const nameInput = document.getElementById('custNameInput');
    if (nameInput) nameInput.value = appState.currentUser.full_name || '';
  }
  toggleOnlinePaymentMethod();
  document.getElementById('checkoutModal').classList.add('active');
}

/**
 * Place Order & Route to POS / KDS
 */
async function submitOrder() {
  const name = document.getElementById('custNameInput').value.trim();
  const phone = document.getElementById('custPhoneInput').value.trim();
  const address = document.getElementById('custAddressInput').value.trim();
  const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked').value;
  const gcashRefInput = document.getElementById('custGcashRef');
  const gcashRef = gcashRefInput ? gcashRefInput.value.trim() : '';
  const orderNotes = document.getElementById('checkoutOverallNotes').value.trim();

  if (!name || phone.length < 11) {
    alert('Please enter your full name and 11-digit mobile number.');
    return;
  }

  if (appState.fulfillmentMode === 'delivery' && !address) {
    alert('Delivery address is required.');
    return;
  }

  if (paymentMethod.toLowerCase() === 'gcash') {
    if (!/^\d{13}$/.test(gcashRef)) {
      alert('Please enter a valid 13-digit GCash reference number.');
      if (gcashRefInput) gcashRefInput.focus();
      return;
    }
  }

  const payload = {
    customer_name: name,
    customer_phone: phone,
    delivery_address: address,
    fulfillment: appState.fulfillmentMode,
    order_type: appState.fulfillmentMode,
    payment_method: paymentMethod,
    payment_reference: paymentMethod.toLowerCase() === 'gcash' ? gcashRef : null,
    notes: orderNotes,
    items: appState.cart.map(i => ({
      menu_item_id: i.id,
      quantity: i.qty,
      variant_size: getCartVariantSize(i),
      notes: i.notes // Item-level special instructions saved to order_items.notes
    }))
  };

  const btn = document.getElementById('btnConfirmOrder');
  btn.disabled = true;
  btn.textContent = 'Processing Order...';

  try {
    const res = await fetch(`${API_ROOT}/orders.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (result.status === 'success' || result.ok) {
      appState.cart = [];
      saveCart();
      closeModal('checkoutModal');
      showConfirmationModal(result.data);
    } else {
      alert(result.message || 'Order could not be placed. Please check stock.');
    }
  } catch (_) {
    alert('Connection error. Please try again.');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Place Order Now';
  }
}

function showConfirmationModal(data) {
  appState.lastOrderId = Number(data.order_id) || null;
  document.getElementById('confOrderNo').textContent = data.order_number || data.order_no;
  document.getElementById('confReceiptNo').textContent = data.receipt_no || '—';
  document.getElementById('confTotal').textContent = formatPeso(data.total_amount || 0);
  document.getElementById('confReceiptStatus').textContent = data.receipt_email_sent
    ? 'Your receipt was sent to your email inbox.'
    : 'The order is saved, but the receipt email could not be delivered.';
  document.getElementById('orderConfirmationModal').classList.add('active');
}

function openOrderTrackingFromConfirmation() {
  closeModal('orderConfirmationModal');
  openOrdersModal();
  if (appState.lastOrderId) setTimeout(() => trackOrder(appState.lastOrderId), 250);
}

function updateFulfillmentDetails() {
  const addrWrap = document.getElementById('addressFieldWrap');
  if (!addrWrap) return;
  if (appState.fulfillmentMode === 'delivery') {
    addrWrap.style.display = 'block';
  } else {
    addrWrap.style.display = 'none';
  }
}

function updateUserUI() {
  const container = document.getElementById('navUserContainer');
  if (!container) return;
  if (appState.currentUser) {
    container.innerHTML = `
      <button class="nav-btn" onclick="openCustomerAccountModal()" title="My account" aria-label="My account">
        <i class="bi bi-person-check"></i>
      </button>
    `;
  } else {
    container.innerHTML = `
      <button class="nav-btn" style="width: auto; padding: 0 14px; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;" onclick="openOtpModal()">
        <i class="bi bi-person"></i> Sign In
      </button>
    `;
  }
}

async function syncCustomerSession() {
  if (!appState.currentUser) return;
  try {
    const res = await fetch('./api/auth.php?action=me', { credentials: 'same-origin' });
    const result = await res.json();
    if (result.authenticated && result.user) {
      appState.currentUser = result.user;
      localStorage.setItem('kk_customer_session', JSON.stringify(result.user));
      await loadFavoriteData();
    } else {
      appState.currentUser = null;
      appState.favoriteIds.clear();
      localStorage.removeItem('kk_customer_session');
    }
  } catch (_) {
    // Keep the account label if the API is temporarily unavailable.
  }
  updateUserUI();
}

async function loadFavoriteData() {
  if (!appState.currentUser) return;
  try {
    const res = await fetch('./api/favorites.php', { credentials: 'same-origin' });
    const result = await res.json();
    if (!res.ok || result.status !== 'success') return;
    appState.favoriteItems = result.data || [];
    appState.favoriteIds = new Set(appState.favoriteItems.map(item => Number(item.id)));
    filterAndRenderMenu();
  } catch (_) {}
}

async function toggleFavorite(itemId) {
  if (!appState.currentUser) {
    openAuthModal('signin');
    showToast('Sign in to save your favorites.');
    return;
  }
  const id = Number(itemId);
  const action = appState.favoriteIds.has(id) ? 'remove' : 'add';
  try {
    const res = await fetch('./api/favorites.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, item_id: id })
    });
    const result = await res.json();
    if (!res.ok || result.status !== 'success') throw new Error(result.message || 'Could not update favorites.');
    if (action === 'add') appState.favoriteIds.add(id);
    else appState.favoriteIds.delete(id);
    filterAndRenderMenu();
    if (document.getElementById('customerFavoritesModal').classList.contains('active')) {
      await loadFavoriteData();
      renderFavoriteList();
    }
    showToast(action === 'add' ? 'Saved to favorites.' : 'Removed from favorites.');
  } catch (err) {
    alert(err.message || 'Could not update favorites.');
  }
}

function openCustomerAccountModal() {
  document.getElementById('customerAccountName').textContent = appState.currentUser?.full_name || appState.currentUser?.email || 'Customer';
  document.getElementById('customerAccountModal').classList.add('active');
}

function openOrdersModal() {
  closeModal('customerAccountModal');
  document.getElementById('customerOrdersModal').classList.add('active');
  document.getElementById('customerOrdersList').textContent = 'Loading orders…';
  document.getElementById('orderTrackingPanel').innerHTML = '';
  loadOrderHistory();
}

async function loadOrderHistory() {
  const list = document.getElementById('customerOrdersList');
  try {
    const res = await fetch('./api/orders.php?action=history', { credentials: 'same-origin' });
    const result = await res.json();
    if (!res.ok || result.status !== 'success') throw new Error(result.message || 'Unable to load orders.');
    const orders = result.data || [];
    if (!orders.length) {
      list.innerHTML = '<p class="muted">No online orders yet. Your orders will appear here.</p>';
      return;
    }
    list.innerHTML = orders.map(order => `
      <article class="customer-order-row">
        <div class="customer-order-top">
          <strong>${escapeHtml(order.order_no)}</strong>
          <span class="order-status-chip" id="order-status-${Number(order.id)}">${escapeHtml(order.status)}</span>
        </div>
        <p>${new Date(order.created_at.replace(' ', 'T')).toLocaleString()} · ${order.order_type === 'ONLINE' ? 'Delivery' : 'Pickup'} · ${formatPeso(order.total)}<br>Receipt ${escapeHtml(order.receipt_no || '—')} · ${order.receipt_email_status === 'Sent' ? 'Emailed' : (order.receipt_email_status === 'Failed' ? 'Receipt email failed' : 'Receipt email pending')}</p>
        <div class="customer-order-actions"><button type="button" onclick="trackOrder(${Number(order.id)})"><i class="bi bi-geo-alt"></i> Track order</button></div>
      </article>`).join('');
    const active = orders.find(order => ['Pending', 'Preparing', 'Ready'].includes(order.status));
    if (active) trackOrder(active.id, false);
  } catch (err) {
    list.innerHTML = `<p class="muted">${escapeHtml(err.message || 'Unable to load orders.')}</p>`;
  }
}

async function trackOrder(orderId, startPolling = true) {
  const panel = document.getElementById('orderTrackingPanel');
  if (startPolling) panel.innerHTML = '<div class="tracking-card">Loading order tracking…</div>';
  try {
    const res = await fetch(`./api/orders.php?action=track&order_id=${encodeURIComponent(orderId)}`, { credentials: 'same-origin' });
    const result = await res.json();
    if (!res.ok || result.status !== 'success') throw new Error(result.message || 'Unable to track this order.');
    const { order, items } = result.data;
    const previousStatus = appState.trackedOrderStatuses[order.id];
    if (previousStatus && previousStatus !== 'Ready' && order.status === 'Ready') {
      window.KenjiOrderSounds?.play('ready');
    }
    appState.trackedOrderStatuses[order.id] = order.status;
    const chip = document.getElementById(`order-status-${Number(order.id)}`);
    if (chip) chip.textContent = order.status;
    const completed = ['Served', 'Picked Up'].includes(order.status);
    const cancelled = ['Cancelled', 'Refunded', 'Voided'].includes(order.status);
    const steps = ['Received', 'Preparing', 'Ready', order.order_type === 'ONLINE' ? 'Completed' : 'Picked up'];
    const statusIndex = ({ Pending: 0, Preparing: 1, Ready: 2, Served: 3, 'Picked Up': 3 })[order.status] ?? -1;
    const progress = cancelled
      ? `<p class="muted">This order is ${escapeHtml(order.status.toLowerCase())}.</p>`
      : `<div class="tracking-steps">${steps.map((step, index) => `<div class="tracking-step ${index < statusIndex ? 'complete' : ''} ${index === statusIndex ? 'current' : ''}">${step}</div>`).join('')}</div>`;
    panel.innerHTML = `<div class="tracking-card">
      <h3>Tracking ${escapeHtml(order.order_no)} <span class="order-status-chip">${escapeHtml(order.status)}</span></h3>
      <p class="muted">Receipt ${escapeHtml(order.receipt_no || '—')} · ${completed ? 'Order complete' : 'Status updates automatically while this window is open.'}</p>
      ${progress}
      <ul class="tracking-items">${items.map(item => `<li>${Number(item.quantity)} × ${escapeHtml(item.item_name)} — ${formatPeso(item.price)}</li>`).join('')}</ul>
    </div>`;
    clearInterval(appState.ordersPollInterval);
    if (!completed && !cancelled && document.getElementById('customerOrdersModal').classList.contains('active')) {
      appState.ordersPollInterval = setInterval(() => trackOrder(orderId, false), 15000);
    }
  } catch (err) {
    panel.innerHTML = `<div class="tracking-card">${escapeHtml(err.message || 'Unable to track this order.')}</div>`;
  }
}

async function openFavoritesModal() {
  closeModal('customerAccountModal');
  document.getElementById('customerFavoritesModal').classList.add('active');
  await loadFavoriteData();
  renderFavoriteList();
}

function renderFavoriteList() {
  const list = document.getElementById('customerFavoritesList');
  if (!appState.favoriteItems.length) {
    list.innerHTML = '<p class="muted">No favorites saved yet. Tap the heart on a menu item to save it.</p>';
    return;
  }
  list.innerHTML = appState.favoriteItems.map(item => {
    const menuItem = appState.menuItems.find(candidate => Number(candidate.id) === Number(item.id));
    const available = menuItem && Number(menuItem.stock) > 0 && menuItem.availability === 'Available';
    return `<article class="customer-favorite-row">
      <div><strong>${escapeHtml(item.name)}</strong><p>${escapeHtml(item.category_name || '')} · ${formatPeso(item.price)}${available ? '' : ' · Currently unavailable'}</p></div>
      <div class="customer-favorite-actions">
        ${available ? `<button type="button" onclick="closeModal('customerFavoritesModal'); quickAddToCart(${Number(item.id)})"><i class="bi bi-plus-lg"></i> Add</button>` : ''}
        <button type="button" aria-label="Remove ${escapeHtml(item.name)} from favorites" onclick="toggleFavorite(${Number(item.id)})"><i class="bi bi-heartbreak"></i></button>
      </div>
    </article>`;
  }).join('');
}

async function handleSignOut() {
  try { await fetch('./api/auth.php?action=logout', { credentials: 'same-origin' }); } catch (_) {}
  appState.currentUser = null;
  appState.favoriteIds.clear();
  appState.favoriteItems = [];
  localStorage.removeItem('kk_customer_session');
  filterAndRenderMenu();
  closeModal('customerAccountModal');
  closeModal('customerOrdersModal');
  closeModal('customerFavoritesModal');
  updateUserUI();
  showToast('Logged out.');
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) modal.classList.remove('active');
  if (modalId === 'customerOrdersModal') clearInterval(appState.ordersPollInterval);
}

function showToast(msg) {
  const toast = document.getElementById('toastBox');
  if (!toast) return;
  toast.textContent = msg;
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 2800);
}

/* ══════════════════════════════════════════════════════════════════════════════
   UNIFIED AUTH MODAL — Sign In & Register with OTP
   Functions: openAuthModal, switchTab, toggleInputType,
              sendCode, verifyCode, resendCode, goBackToForm,
              _authStartCountdown, _authInitOtpBoxes
   ══════════════════════════════════════════════════════════════════════════════ */

/** Internal auth state shared across all functions in this block */
const _auth = {
  tab: 'signin',           // 'signin' | 'register'
  inputType: {
    signin:   'email',     // 'email' | 'sms'
    register: 'email',
  },
  pendingContact:  '',      // email or phone being verified
  pendingName:     '',      // full name for register flow
  pendingPassword: '',      // password for register flow
  timerInterval:   null,
  resendTimeout:   null,
};

/**
 * openAuthModal(tab)
 * Opens the unified auth modal, optionally pre-selecting a tab.
 * @param {'signin'|'register'} tab
 */
function openAuthModal(tab = 'signin') {
  switchTab(tab);
  _showAuthStep(1);
  _clearOtpBoxes();
  document.getElementById('authModal').classList.add('active');
}

/**
 * switchTab(tab)
 * Switches between Sign In and Register tabs.
 * @param {'signin'|'register'} tab
 */
function switchTab(tab) {
  _auth.tab = tab;

  // Tab button styles
  document.getElementById('tabSignIn').classList.toggle('active', tab === 'signin');
  document.getElementById('tabRegister').classList.toggle('active', tab === 'register');

  // Form visibility
  document.getElementById('formSignIn').style.display   = tab === 'signin'   ? 'block' : 'none';
  document.getElementById('formRegister').style.display = tab === 'register' ? 'block' : 'none';

  _showAuthStep(1);
}

/**
 * toggleInputType(form, type)
 * Toggles Email / SMS input visibility in the Sign In or Register form.
 * @param {'signin'|'register'} form
 * @param {'email'|'sms'} type
 */
function toggleInputType(form, type) {
  _auth.inputType[form] = type;

  if (form === 'signin') {
    document.getElementById('pillEmailSI').classList.toggle('active', type === 'email');
    document.getElementById('pillSmsSI').classList.toggle('active',   type === 'sms');
    document.getElementById('fieldEmailSI').style.display = type === 'email' ? 'block' : 'none';
    document.getElementById('fieldSmsSI').style.display   = type === 'sms'   ? 'block' : 'none';
    // Focus the revealed input
    setTimeout(() => {
      const el = type === 'email'
        ? document.getElementById('siEmailInput')
        : document.getElementById('siSmsInput');
      if (el) el.focus();
    }, 50);
  } else {
    document.getElementById('pillEmailReg').classList.toggle('active', type === 'email');
    document.getElementById('pillSmsReg').classList.toggle('active',   type === 'sms');
    document.getElementById('fieldEmailReg').style.display = type === 'email' ? 'block' : 'none';
    document.getElementById('fieldSmsReg').style.display   = type === 'sms'   ? 'block' : 'none';
    setTimeout(() => {
      const el = type === 'email'
        ? document.getElementById('regEmailInput')
        : document.getElementById('regSmsInput');
      if (el) el.focus();
    }, 50);
  }
}

/**
 * sendCode(form)
 * Validates form inputs and requests an OTP from the PHP backend.
 * @param {'signin'|'register'} form
 */
async function sendCode(form) {
  const itype  = _auth.inputType[form];
  const isReg  = form === 'register';
  const btnId  = isReg ? 'btnSendReg' : 'btnSendSI';
  const btn    = document.getElementById(btnId);

  // ── Gather + validate inputs ────────────────────────────────────────────
  let contact = '';
  let fullName = '';

  if (itype === 'email') {
    contact = (isReg
      ? document.getElementById('regEmailInput')
      : document.getElementById('siEmailInput')
    ).value.trim();

    if (!contact || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact)) {
      _authAlert('Please enter a valid email address.', isReg ? 'regEmailInput' : 'siEmailInput');
      return;
    }
  } else {
    contact = (isReg
      ? document.getElementById('regSmsInput')
      : document.getElementById('siSmsInput')
    ).value.replace(/\D/g, '');

    if (!/^09\d{9}$/.test(contact)) {
      _authAlert('Please enter a valid 11-digit PH mobile number starting with 09.',
        isReg ? 'regSmsInput' : 'siSmsInput');
      return;
    }
  }

  if (isReg) {
    fullName = document.getElementById('regNameInput').value.trim();
    if (fullName.length < 2) {
      _authAlert('Please enter your full name.', 'regNameInput');
      return;
    }
    const regPass = document.getElementById('regPasswordInput')?.value || '';
    if (regPass.length < 6) {
      _authAlert('Please enter a password with at least 6 characters.', 'regPasswordInput');
      return;
    }
    _auth.pendingPassword = regPass;
  }

  _auth.pendingContact = contact;
  _auth.pendingName    = fullName;

  // ── Send OTP request ────────────────────────────────────────────────────
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending…';

  try {
    const payload = { [itype === 'email' ? 'email' : 'phone']: contact, mode: form };
    const res = await fetch('./api/auth.php?action=otp_send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (result.ok) {
      // Update OTP step display
      const channelLabel = itype === 'email' ? 'email' : 'phone';
      document.getElementById('authOtpChannel').textContent = channelLabel;
      document.getElementById('authOtpTarget').textContent  = contact;

      _showAuthStep(2);
      _clearOtpBoxes();
      _authInitOtpBoxes();
      _authStartCountdown(300);  // 5-minute TTL

    } else {
      alert(result.message || 'Unable to send verification code. Please try again.');
    }
  } catch (err) {
    console.error('[Auth] sendCode error:', err);
    alert('Network error. Please check your connection and try again.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send-fill"></i> Send Verification Code';
  }
}

/**
 * verifyCode()
 * Reads the 6 OTP digit boxes and verifies against the PHP backend.
 */
async function verifyCode() {
  const boxes = document.querySelectorAll('#authOtpBoxes .auth-otp-box');
  const code  = Array.from(boxes).map(b => b.value.trim()).join('');

  if (code.length !== 6 || !/^\d{6}$/.test(code)) {
    alert('Please enter all 6 digits of your verification code.');
    boxes[0].focus();
    return;
  }

  const btn = document.getElementById('btnVerify');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Verifying…';

  const itype      = _auth.inputType[_auth.tab];
  const isRegister = _auth.tab === 'register';

  try {
    const payload = {
      [itype === 'email' ? 'email' : 'phone']: _auth.pendingContact,
      code,
      mode: _auth.tab,
      ...(isRegister ? { full_name: _auth.pendingName, password: _auth.pendingPassword } : {})
    };

    const res = await fetch('./api/auth.php?action=otp_verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (result.ok) {
      clearInterval(_auth.timerInterval);

      // Save session
      appState.currentUser = result.user || {
        email: _auth.pendingContact,
        full_name: _auth.pendingName || _auth.pendingContact.split('@')[0]
      };
      localStorage.setItem('kk_customer_session', JSON.stringify(appState.currentUser));
      updateUserUI();
      await loadFavoriteData();

      closeModal('authModal');
      showToast(isRegister ? '🎉 Account created! Welcome to Kenji\'s Kitchen.' : '✅ Verified! Welcome back.');

      // Proceed to checkout if cart has items
      if (appState.cart.length > 0) {
        setTimeout(() => openCheckoutModal(), 400);
      }

    } else {
      alert(result.message || 'Invalid code. Please check and try again.');
      // Shake boxes on error
      const boxWrap = document.getElementById('authOtpBoxes');
      boxWrap.style.animation = 'none';
      boxWrap.offsetHeight; // reflow
      boxWrap.style.animation = 'authShake 0.4s ease';
      _clearOtpBoxes();
    }
  } catch (err) {
    console.error('[Auth] verifyCode error:', err);
    alert('Network error. Please try again.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Verify Code';
  }
}

/**
 * resendCode()
 * Re-triggers sendCode() for the current tab.
 */
function resendCode() {
  sendCode(_auth.tab);
}

/**
 * goBackToForm()
 * Returns from OTP step back to the input form.
 */
function goBackToForm() {
  clearInterval(_auth.timerInterval);
  clearTimeout(_auth.resendTimeout);
  _showAuthStep(1);
  _clearOtpBoxes();
}

/* ── Internal helpers ──────────────────────────────────────────────────────── */

function _showAuthStep(step) {
  document.getElementById('authStep1').style.display = step === 1 ? 'block' : 'none';
  document.getElementById('authStep2').style.display = step === 2 ? 'block' : 'none';
}

function _clearOtpBoxes() {
  document.querySelectorAll('#authOtpBoxes .auth-otp-box').forEach(b => {
    b.value = '';
    b.classList.remove('filled');
  });
}

/** Wire up auto-advance keyboard behaviour on the 6 OTP boxes */
function _authInitOtpBoxes() {
  const boxes = Array.from(document.querySelectorAll('#authOtpBoxes .auth-otp-box'));
  boxes.forEach((box, i) => {
    box.value = '';
    box.focus = box.focus; // ensure focusable

    box.oninput = (e) => {
      // Allow only digits
      box.value = box.value.replace(/\D/g, '').slice(0, 1);
      box.classList.toggle('filled', box.value !== '');
      // Auto-advance
      if (box.value && i < boxes.length - 1) {
        boxes[i + 1].focus();
      }
      // Auto-verify when all filled
      if (boxes.every(b => b.value !== '')) {
        setTimeout(() => verifyCode(), 150);
      }
    };

    box.onkeydown = (e) => {
      if (e.key === 'Backspace' && !box.value && i > 0) {
        boxes[i - 1].focus();
        boxes[i - 1].value = '';
        boxes[i - 1].classList.remove('filled');
      }
    };

    // Support paste of full 6-digit code
    box.onpaste = (e) => {
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
      if (pasted.length === 6) {
        e.preventDefault();
        boxes.forEach((b, idx) => {
          b.value = pasted[idx] || '';
          b.classList.toggle('filled', b.value !== '');
        });
        boxes[5].focus();
        setTimeout(() => verifyCode(), 150);
      }
    };
  });

  // Focus first box
  boxes[0].focus();
}

/** Countdown timer for OTP expiry */
function _authStartCountdown(seconds) {
  let left = seconds;
  const display    = document.getElementById('authTimerDisplay');
  const resendBtn  = document.getElementById('btnResend');

  clearInterval(_auth.timerInterval);
  clearTimeout(_auth.resendTimeout);
  if (resendBtn) resendBtn.disabled = true;

  _auth.timerInterval = setInterval(() => {
    left--;
    const m = Math.floor(left / 60);
    const s = String(left % 60).padStart(2, '0');
    if (display) display.textContent = `${m}:${s}`;

    if (left <= 0) {
      clearInterval(_auth.timerInterval);
      if (display) display.textContent = 'Expired';
    }
  }, 1000);

  // Enable resend after 30 seconds
  _auth.resendTimeout = setTimeout(() => {
    if (resendBtn) resendBtn.disabled = false;
  }, 30000);
}

/** Small inline alert helper that focuses the offending input */
function _authAlert(msg, inputId) {
  alert(msg);
  const el = document.getElementById(inputId);
  if (el) { el.focus(); el.select && el.select(); }
}

/* ── CSS shake animation for wrong OTP ────────────────────────────────────── */
(function injectAuthAnimations() {
  if (document.getElementById('_authAnim')) return;
  const style = document.createElement('style');
  style.id = '_authAnim';
  style.textContent = `
    @keyframes authShake {
      0%,100% { transform: translateX(0); }
      20%      { transform: translateX(-6px); }
      40%      { transform: translateX(6px); }
      60%      { transform: translateX(-4px); }
      80%      { transform: translateX(4px); }
    }
  `;
  document.head.appendChild(style);
})();


/* ══════════════════════════════════════════════════════════════════════════════
   DAY & NIGHT MODE TOGGLE LOGIC
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * toggleTheme()
 * Toggles dark mode class on body, updates icon, and stores preference in localStorage.
 */
function toggleTheme() {
  const isDark = document.body.classList.toggle('dark-mode');
  localStorage.setItem('kk_theme', isDark ? 'dark' : 'light');
  _updateThemeIcon(isDark);
}

function _updateThemeIcon(isDark) {
  const icon = document.getElementById('themeToggleIcon');
  if (!icon) return;
  if (isDark) {
    icon.className = 'bi bi-sun-fill';
    icon.parentElement.title = 'Switch to Day Mode';
    icon.style.color = '#f59e0b';
  } else {
    icon.className = 'bi bi-moon-stars';
    icon.parentElement.title = 'Switch to Night Mode';
    icon.style.color = '';
  }
}

// Initialize theme immediately on script load
(function initTheme() {
  const savedTheme = localStorage.getItem('kk_theme');
  // The storefront launches in its low-glare slate theme unless a visitor has
  // explicitly chosen Day Mode before.
  const isDark = savedTheme !== 'light';
  if (isDark) {
    document.body.classList.add('dark-mode');
  }
  // Update icon once DOM is ready
  document.addEventListener('DOMContentLoaded', () => {
    _updateThemeIcon(document.body.classList.contains('dark-mode'));
  });
})();

/* ══════════════════════════════════════════════════════════════════════════════
   DIRECT SIGN IN (EMAIL/PHONE + PASSWORD)
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * handleDirectSignIn()
 * Authenticates registered users directly using email/phone and password without OTP.
 */
async function handleDirectSignIn() {
  const loginInput = document.getElementById('siEmailInput').value.trim();
  const password = document.getElementById('siPasswordInput').value;

  if (!loginInput) {
    _authAlert('Please enter your email address or phone number.', 'siEmailInput');
    return;
  }
  if (!password) {
    _authAlert('Please enter your password.', 'siPasswordInput');
    return;
  }

  const btn = document.getElementById('btnSignInDirect');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Signing in…';

  try {
    const res = await fetch('./api/auth.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: loginInput, password: password })
    });
    const result = await res.json();

    if (result.status === 'success' || result.ok) {
      appState.currentUser = result.user;
      localStorage.setItem('kk_customer_session', JSON.stringify(appState.currentUser));
      updateUserUI();
      await loadFavoriteData();

      closeModal('authModal');
      showToast('✅ Signed in successfully! Welcome back, ' + (appState.currentUser.full_name || 'Customer') + '.');

      // Auto-open checkout if customer had items in cart
      if (appState.cart.length > 0) {
        setTimeout(() => openCheckoutModal(), 400);
      }
    } else {
      alert(result.message || 'Invalid email/phone or password. Please try again.');
      document.getElementById('siPasswordInput').focus();
    }
  } catch (err) {
    console.error('[Auth] Direct sign in error:', err);
    alert('Network error. Please check your connection and try again.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
  }
}
