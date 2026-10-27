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
    { id: 'all', name: 'All' }
  ],
  menuItems: [],
  activeCategory: 'all',
  searchQuery: '',
  fulfillmentMode: 'delivery', // 'delivery' | 'pickup'
  cart: JSON.parse(localStorage.getItem('kk_cart_v110') || '[]'),
  currentModalItem: null,
  currentBilaoGroup: null,
  isBilaoModal: false,
  selectedBilaoSize: 'S',
  selectedPackaging: null, // 'Tray' | 'Bilao' | null
  selectedSizeIndex: null,
  modalQty: 1,
  currentUser: JSON.parse(localStorage.getItem('kk_customer_session') || 'null'),
  lastOrderId: null,
  favoriteIds: new Set(),
  favoriteItems: [],
  ordersPollInterval: null,
  trackedOrderStatuses: {},
  currentTrackedOrderId: null,
  isTrackingPollInFlight: false,
  lastTrackedData: null,
  gcashSettingsPromise: null,
  otpSentEmail: '',
  otpTimerInterval: null
};

// Formatting helper
const formatPeso = val => `₱${Number(val || 0).toFixed(2)}`;

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function getCategoryIcon(name) {
  const lower = String(name || '').toLowerCase();
  if (lower.includes('add-on') || lower.includes('addon')) return 'bi-plus-circle';
  if (lower.includes('bilao')) return 'bi-basket';
  if (lower.includes('drink') || lower.includes('beverage')) return 'bi-cup-straw';
  if (lower.includes('pulutan') || lower.includes('pares')) return 'bi-egg-fried';
  if (lower.includes('set meal')) return 'bi-box';
  if (lower.includes('silog')) return 'bi-egg-fried';
  if (lower.includes('student')) return 'bi-mortarboard';
  if (lower.includes('dessert') || lower.includes('sweet')) return 'bi-cake';
  if (lower.includes('snack') || lower.includes('appetizer')) return 'bi-pie-chart';
  return 'bi-tag';
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
  await Promise.all([loadCategories(), loadMenuItems()]);
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
 * Load Active Categories from API
 */
async function loadCategories() {
  try {
    const res = await fetch(`${API_ROOT}/menu.php?action=categories`);
    const data = await res.json();
    if (data.status === 'success' && Array.isArray(data.data)) {
      const activeCats = data.data.map(cat => ({
        id: Number(cat.id),
        name: cat.name
      }));
      appState.categories = [
        { id: 'all', name: 'All' },
        ...activeCats
      ];
      // Reset activeCategory if it was a specific category that is no longer present
      if (appState.activeCategory !== 'all' && !appState.categories.some(c => String(c.id) === String(appState.activeCategory))) {
        appState.activeCategory = 'all';
      }
    }
  } catch (err) {
    console.warn('Unable to load categories from API:', err);
  }
  renderCategoryPills();
}

/**
 * Render category pills
 */
function renderCategoryPills() {
  const container = document.getElementById('categoryStrip');
  if (!container) return;

  container.innerHTML = appState.categories.map(cat => {
    const isAll = cat.id === 'all';
    const icon = isAll ? 'bi-grid-3x3-gap' : getCategoryIcon(cat.name);
    const label = escapeHtml(cat.name);
    const isActive = String(appState.activeCategory) === String(cat.id);

    return `
      <button class="cat-pill ${isActive ? 'active' : ''}" data-cat-id="${cat.id}">
        <i class="bi ${icon}"></i> ${label}
      </button>
    `;
  }).join('');
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

  // Customer Orders Modal Track Order clicks (event delegation)
  const ordersModal = document.getElementById('customerOrdersModal');
  if (ordersModal) {
    ordersModal.addEventListener('click', (e) => {
      const trackBtn = e.target.closest('[data-track-order-id]');
      if (trackBtn) {
        e.preventDefault();
        const orderId = Number(trackBtn.dataset.trackOrderId);
        if (orderId) trackOrder(orderId, true, true);
      }
    });
  }

  // Page Visibility API: Pause tracking polling when tab is hidden, resume when visible
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopTrackingPolling();
    } else {
      const modal = document.getElementById('customerOrdersModal');
      if (modal && modal.classList.contains('active') && appState.currentTrackedOrderId) {
        const currentStatus = appState.trackedOrderStatuses[appState.currentTrackedOrderId];
        if (['Pending', 'Preparing', 'Ready'].includes(currentStatus)) {
          fetchOrderTracking(appState.currentTrackedOrderId, true);
          startTrackingPolling(appState.currentTrackedOrderId);
        }
      }
    }
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
 * Canonical Bilao Dish Pricing and Specifications
 * authoritatively matches config/bilao-pricing.php
 */
const BILAO_SPECS = {
  'Spaghetti': {
    dish: 'Spaghetti',
    name: 'Spaghetti',
    description: 'Special spaghetti in rich savory sauce, available in Tray or Bilao (Good for 5-25 pax).',
    rates: {
      'S': { label: 'Small (S)', price: 500, desc: 'Good for 5-7 pax' },
      'M': { label: 'Medium (M)', price: 850, desc: 'Good for 8-12 pax' },
      'L': { label: 'Large (L)', price: 1200, desc: 'Good for 12-18 pax' },
      'XL': { label: 'Extra Large (XL)', price: 1550, desc: 'Good for 18-25 pax' }
    }
  },
  'Carbonara': {
    dish: 'Carbonara',
    name: 'Carbonara',
    description: 'Creamy rich carbonara with bacon and mushrooms, available in Tray or Bilao (Good for 5-25 pax).',
    rates: {
      'S': { label: 'Small (S)', price: 500, desc: 'Good for 5-7 pax' },
      'M': { label: 'Medium (M)', price: 850, desc: 'Good for 8-12 pax' },
      'L': { label: 'Large (L)', price: 1200, desc: 'Good for 12-18 pax' },
      'XL': { label: 'Extra Large (XL)', price: 1550, desc: 'Good for 18-25 pax' }
    }
  },
  'Palabok': {
    dish: 'Palabok',
    name: 'Palabok',
    description: 'Classic Pancit Palabok with rich savory sauce, egg, and toppings, available in Tray or Bilao (Good for 6-35 pax).',
    rates: {
      'S': { label: 'Small (S)', price: 650, desc: 'Good for 6-9 pax' },
      'M': { label: 'Medium (M)', price: 850, desc: 'Good for 9-13 pax' },
      'L': { label: 'Large (L)', price: 1000, desc: 'Good for 20-25 pax' },
      'XL': { label: 'Extra Large (XL)', price: 1200, desc: 'Good for 30-35 pax' }
    }
  },
  'Sotanghon': {
    dish: 'Sotanghon',
    name: 'Sotanghon',
    description: 'Savory stir-fried Pancit Sotanghon loaded with meat and veggies, available in Tray or Bilao (Good for 6-35 pax).',
    rates: {
      'S': { label: 'Small (S)', price: 650, desc: 'Good for 6-9 pax' },
      'M': { label: 'Medium (M)', price: 850, desc: 'Good for 9-13 pax' },
      'L': { label: 'Large (L)', price: 1000, desc: 'Good for 20-25 pax' },
      'XL': { label: 'Extra Large (XL)', price: 1200, desc: 'Good for 30-35 pax' }
    }
  },
  'Bihon': {
    dish: 'Bihon',
    name: 'Bihon',
    description: 'Traditional Pancit Bihon Guisado seasoned to perfection, available in Tray or Bilao (Good for 6-35 pax).',
    rates: {
      'S': { label: 'Small (S)', price: 550, desc: 'Good for 6-9 pax' },
      'M': { label: 'Medium (M)', price: 750, desc: 'Good for 9-13 pax' },
      'L': { label: 'Large (L)', price: 950, desc: 'Good for 20-25 pax' },
      'XL': { label: 'Extra Large (XL)', price: 1100, desc: 'Good for 30-35 pax' }
    }
  },
  'Canton': {
    dish: 'Canton',
    name: 'Canton',
    description: 'Delicious Pancit Canton Guisado with savory noodles and toppings, available in Tray or Bilao (Good for 6-35 pax).',
    rates: {
      'S': { label: 'Small (S)', price: 550, desc: 'Good for 6-9 pax' },
      'M': { label: 'Medium (M)', price: 750, desc: 'Good for 9-13 pax' },
      'L': { label: 'Large (L)', price: 950, desc: 'Good for 20-25 pax' },
      'XL': { label: 'Extra Large (XL)', price: 1100, desc: 'Good for 30-35 pax' }
    }
  }
};

function getBilaoDishFromItem(item) {
  if (!item) return null;
  const isBilaoCat = Number(item.category_id) === 6 || (item.category_name || '').toLowerCase() === 'bilao favorites';
  if (!isBilaoCat) return null;
  const itemName = String(item.name || item.item_name || '');
  const dishes = ['Spaghetti', 'Carbonara', 'Palabok', 'Sotanghon', 'Bihon', 'Canton'];
  for (const dish of dishes) {
    if (new RegExp('\\b' + dish + '\\b', 'i').test(itemName) || itemName.toLowerCase().includes(dish.toLowerCase())) {
      return dish;
    }
  }
  return null;
}

function buildBilaoGroups() {
  const groups = {};
  const bilaoItems = appState.menuItems.filter(i => {
    const isCat6 = Number(i.category_id) === 6 || (i.category_name || '').toLowerCase() === 'bilao favorites';
    return isCat6 && !i.is_archived;
  });

  const dishes = ['Spaghetti', 'Carbonara', 'Palabok', 'Sotanghon', 'Bihon', 'Canton'];
  dishes.forEach(dish => {
    const spec = BILAO_SPECS[dish];
    groups[dish] = {
      dish,
      name: spec.name,
      description: spec.description,
      category_id: 6,
      category_name: 'Bilao Favorites',
      category_slug: 'bilao_favorites',
      is_bilao_group: true,
      image_url: null,
      variants: {}
    };

    // Filter items matching this dish
    bilaoItems.forEach(item => {
      const lower = (item.name || '').toLowerCase();
      if (lower.includes(dish.toLowerCase())) {
        let size = 'S';
        if (/\b(?:XL|X-LARGE|EXTRA\s+LARGE)\b/i.test(item.name)) {
          size = 'XL';
        } else if (/\b(?:LARGE|L)\b/i.test(item.name)) {
          size = 'L';
        } else if (/\b(?:MEDIUM|M)\b/i.test(item.name)) {
          size = 'M';
        }

        if (item.image_url && !groups[dish].image_url) {
          groups[dish].image_url = item.image_url;
        }

        const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
        const availability = item.availability || 'Available';
        const price = spec.rates[size] ? spec.rates[size].price : Number(item.price);
        const desc = spec.rates[size] ? spec.rates[size].desc : '';

        // Deterministic POS mapping: later items overwrite in sort order
        groups[dish].variants[size] = {
          id: Number(item.id),
          name: item.name,
          size,
          price,
          desc,
          stock,
          availability,
          is_archived: Boolean(item.is_archived),
          item_raw: item
        };
      }
    });

    // POS fallback for missing sizes (e.g. Spaghetti M, L, XL -> ID 51)
    const sizes = ['S', 'M', 'L', 'XL'];
    const existingSizes = Object.keys(groups[dish].variants);
    if (existingSizes.length > 0) {
      const firstAvailableKey = existingSizes[0];
      const fallbackVariant = groups[dish].variants[firstAvailableKey];
      sizes.forEach(sz => {
        if (!groups[dish].variants[sz]) {
          groups[dish].variants[sz] = {
            id: fallbackVariant.id,
            name: fallbackVariant.name,
            size: sz,
            price: spec.rates[sz] ? spec.rates[sz].price : fallbackVariant.price,
            desc: spec.rates[sz] ? spec.rates[sz].desc : '',
            stock: fallbackVariant.stock,
            availability: fallbackVariant.availability,
            is_archived: fallbackVariant.is_archived,
            item_raw: fallbackVariant.item_raw,
            is_fallback: true
          };
        }
      });
    }

    // Set group card stock / availability summary
    const allVariants = Object.values(groups[dish].variants);
    const hasAvailable = allVariants.some(v => !v.is_archived && v.availability === 'Available' && v.stock > 0);
    const anyAvailableAttr = allVariants.some(v => !v.is_archived && v.availability === 'Available');
    groups[dish].is_available = hasAvailable;
    groups[dish].is_out = !hasAvailable;
    groups[dish].availability_status = !anyAvailableAttr ? 'Unavailable' : (!hasAvailable ? 'Sold Out' : 'Available');
    groups[dish].starting_price = spec.rates['S'].price;
  });

  return groups;
}

function renderNoItemsFound() {
  return `
    <div style="grid-column: 1/-1; text-align: center; padding: 48px 16px;">
      <div style="font-size: 2.2rem; margin-bottom: 8px;"><i class="bi bi-search"></i></div>
      <h3 style="font-size: 1.1rem; color: var(--dark); margin-bottom: 4px;">No delicious items found</h3>
      <p style="color: var(--muted); font-size: 0.85rem;">Try another category search or clear your filter.</p>
    </div>
  `;
}

function renderBilaoCard(group) {
  const isOut = group.is_out;
  const isFav = group.variants['S'] ? appState.favoriteIds.has(Number(group.variants['S'].id)) : false;
  const favId = group.variants['S'] ? Number(group.variants['S'].id) : 0;

  let stockTagHtml = '';
  if (isOut) {
    stockTagHtml = `<span class="stock-tag sold-out">${escapeHtml(group.availability_status)}</span>`;
  } else {
    stockTagHtml = '<span class="stock-tag">Tray &amp; Bilao</span>';
  }

  const imgUrl = group.image_url || '../../assets/img/logo.jpg';

  return `
    <article class="food-card ${isOut ? 'out-of-stock' : ''}" onclick="openBilaoModal('${escapeHtml(group.dish)}')">
      <div class="card-img-wrapper">
        <img src="${imgUrl}" alt="${escapeHtml(group.name)}" onerror="this.src='../../assets/img/logo.jpg'">
        ${favId > 0 ? `
        <button type="button" class="favorite-toggle ${isFav ? 'active' : ''}" onclick="event.stopPropagation(); toggleFavorite(${favId})" aria-label="${isFav ? 'Remove from' : 'Add to'} favorites" title="${isFav ? 'Remove from' : 'Add to'} favorites">
          <i class="bi ${isFav ? 'bi-heart-fill' : 'bi-heart'}"></i>
        </button>` : ''}
        ${stockTagHtml}
      </div>

      <div class="food-card-body">
        <span class="food-category">${escapeHtml(group.category_name)}</span>
        <h3 class="food-title">${escapeHtml(group.name)}</h3>
        <p class="food-desc">${escapeHtml(group.description)}</p>

        <div class="food-card-footer">
          <div class="price-container">
            <span class="food-price">From ${formatPeso(group.starting_price)}</span>
          </div>

          <button class="btn-quick-add" ${isOut ? 'disabled' : ''} onclick="event.stopPropagation(); openBilaoModal('${escapeHtml(group.dish)}')">
            +
          </button>
        </div>
      </div>
    </article>
  `;
}

function renderRegularItemCard(item) {
  const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
  const isArchived = Boolean(item.is_archived);
  const isUnavailable = item.availability !== 'Available';
  const isOutOfStock = stock <= 0;
  const isOut = isArchived || isUnavailable || isOutOfStock;
  const isLow = !isOut && stock <= 5;
  const price = Number(item.effective_price || item.price);
  const hasPromo = Boolean(item.is_promo);

  let stockTagHtml = '';
  if (isUnavailable) {
    stockTagHtml = '<span class="stock-tag sold-out">Unavailable</span>';
  } else if (isOutOfStock) {
    stockTagHtml = '<span class="stock-tag sold-out">Sold Out</span>';
  } else if (isLow) {
    stockTagHtml = `<span class="stock-tag low-stock">Only ${stock} left</span>`;
  } else {
    stockTagHtml = `<span class="stock-tag">${stock} pcs left</span>`;
  }

  return `
    <article class="food-card ${isOut ? 'out-of-stock' : ''}" onclick="handleCardClick(${item.id})">
      <div class="card-img-wrapper">
        <img src="${item.image_url || item.image || '../../assets/img/logo.jpg'}" alt="${escapeHtml(item.name)}" onerror="this.src='../../assets/img/logo.jpg'">
        <button type="button" class="favorite-toggle ${appState.favoriteIds.has(Number(item.id)) ? 'active' : ''}" onclick="event.stopPropagation(); toggleFavorite(${Number(item.id)})" aria-label="${appState.favoriteIds.has(Number(item.id)) ? 'Remove from' : 'Add to'} favorites" title="${appState.favoriteIds.has(Number(item.id)) ? 'Remove from' : 'Add to'} favorites">
          <i class="bi ${appState.favoriteIds.has(Number(item.id)) ? 'bi-heart-fill' : 'bi-heart'}"></i>
        </button>
        ${stockTagHtml}
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
}

/**
 * Filter & Render Menu Grid
 */
function filterAndRenderMenu() {
  const grid = document.getElementById('foodGrid');
  if (!grid) return;

  const bilaoGroups = buildBilaoGroups();
  const bilaoDishes = Object.values(bilaoGroups);

  const isBilaoSelected = String(appState.activeCategory) === '6' ||
    String(appState.activeCategory).toLowerCase().includes('bilao');
  const isAllSelected = appState.activeCategory === 'all';

  let regularItems = appState.menuItems.filter(i => {
    const isCat6 = Number(i.category_id) === 6 || (i.category_name || '').toLowerCase() === 'bilao favorites';
    return !isCat6;
  });

  // Category filter
  if (!isAllSelected && !isBilaoSelected) {
    const activeCatId = Number(appState.activeCategory);
    regularItems = regularItems.filter(i => {
      if (!isNaN(activeCatId) && activeCatId > 0) {
        return Number(i.category_id) === activeCatId;
      }
      const slug = (i.category_slug || '').toLowerCase();
      const catName = (i.category_name || '').toLowerCase();
      return slug === appState.activeCategory || catName.includes(String(appState.activeCategory).replace('_', ' '));
    });
  }

  // Search filter
  const query = (appState.searchQuery || '').toLowerCase().trim();
  if (query) {
    regularItems = regularItems.filter(i =>
      (i.name || '').toLowerCase().includes(query) ||
      (i.description || '').toLowerCase().includes(query)
    );
  }

  let displayedBilao = [];
  if (isAllSelected || isBilaoSelected) {
    displayedBilao = bilaoDishes;
    if (query) {
      displayedBilao = displayedBilao.filter(g =>
        g.name.toLowerCase().includes(query) ||
        g.description.toLowerCase().includes(query) ||
        'bilao favorites'.includes(query) ||
        'bilao'.includes(query) ||
        'tray'.includes(query)
      );
    }
  }

  let cardsHtml = '';

  if (isBilaoSelected) {
    if (!displayedBilao.length) {
      grid.innerHTML = renderNoItemsFound();
      return;
    }
    cardsHtml = displayedBilao.map(g => renderBilaoCard(g)).join('');
  } else if (isAllSelected) {
    if (!regularItems.length && !displayedBilao.length) {
      grid.innerHTML = renderNoItemsFound();
      return;
    }
    cardsHtml = regularItems.map(item => renderRegularItemCard(item)).join('') +
                displayedBilao.map(g => renderBilaoCard(g)).join('');
  } else {
    if (!regularItems.length) {
      grid.innerHTML = renderNoItemsFound();
      return;
    }
    cardsHtml = regularItems.map(item => renderRegularItemCard(item)).join('');
  }

  grid.innerHTML = cardsHtml;
}

function getCartVariantSize(item) {
  if (item && ['S', 'M', 'L', 'XL'].includes(item.variantSize)) return item.variantSize;
  const name = String(item?.name || item || '');
  if (/\b(?:XL|X-LARGE|EXTRA\s+LARGE)\b/i.test(name)) return 'XL';
  if (/\b(?:LARGE|L)\b/i.test(name)) return 'L';
  if (/\b(?:MEDIUM|M)\b/i.test(name)) return 'M';
  if (/\b(?:SMALL|S)\b/i.test(name)) return 'S';
  return null;
}

/**
 * Open Grouped Bilao Modal with Portions and Packaging Picker
 */
function openBilaoModal(dishKey) {
  const groups = buildBilaoGroups();
  const group = groups[dishKey];
  if (!group) return;

  if (group.is_out) {
    showToast(group.availability_status === 'Unavailable' ? 'This dish is currently unavailable.' : 'This dish is currently sold out.');
    return;
  }

  appState.isBilaoModal = true;
  appState.currentBilaoGroup = group;
  appState.currentModalItem = null;
  appState.selectedPackaging = null; // Explicit selection required!
  appState.modalQty = 1;

  // Determine initial size selection: first available size, or 'S'
  const sizes = ['S', 'M', 'L', 'XL'];
  let defaultSize = sizes.find(sz => {
    const v = group.variants[sz];
    return v && !v.is_archived && v.availability === 'Available' && v.stock > 0;
  }) || 'S';

  appState.selectedBilaoSize = defaultSize;
  const initialVariant = group.variants[defaultSize];
  appState.modalUnitPrice = initialVariant ? initialVariant.price : group.starting_price;

  // Setup Packaging Picker UI
  const pkgContainer = document.getElementById('modalPackagingPickerContainer');
  if (pkgContainer) pkgContainer.style.display = 'block';
  const optTray = document.getElementById('pkgOptionTray');
  const optBilao = document.getElementById('pkgOptionBilao');
  if (optTray) optTray.classList.remove('selected');
  if (optBilao) optBilao.classList.remove('selected');
  const helpText = document.getElementById('packagingHelpText');
  if (helpText) helpText.style.display = 'none';

  // Setup Size Picker UI
  const sizeContainer = document.getElementById('modalSizePickerContainer');
  const sizeGrid = document.getElementById('modalSizeOptions');
  if (sizeContainer) sizeContainer.style.display = 'block';

  if (sizeGrid) {
    sizeGrid.innerHTML = sizes.map(sz => {
      const v = group.variants[sz];
      const isSelected = sz === defaultSize;
      const isAvailable = v && !v.is_archived && v.availability === 'Available' && v.stock > 0;
      let statusLabel = '';
      if (!v || v.is_archived || v.availability !== 'Available') {
        statusLabel = ' (Unavailable)';
      } else if (v.stock <= 0) {
        statusLabel = ' (Sold Out)';
      }

      const sizeLabel = sz === 'S' ? 'Small (S)' : (sz === 'M' ? 'Medium (M)' : (sz === 'L' ? 'Large (L)' : 'Extra Large (XL)'));
      const descLabel = v?.desc ? ` - ${v.desc}` : '';

      return `
        <div class="size-option-card ${isSelected ? 'selected' : ''} ${!isAvailable ? 'out-of-stock' : ''}" onclick="selectBilaoModalSize('${sz}')">
          <div class="size-info-col">
            <span class="size-name-tag">${escapeHtml(sizeLabel + descLabel)}${statusLabel}</span>
            <span class="size-price-tag">${formatPeso(v ? v.price : 0)}</span>
          </div>
          <div class="size-radio-indicator"></div>
        </div>
      `;
    }).join('');
  }

  // Update Modal Header / Details
  document.getElementById('modalItemTitle').textContent = group.name;
  document.getElementById('modalItemDesc').textContent = group.description;
  document.getElementById('modalItemPrice').textContent = formatPeso(appState.modalUnitPrice);
  document.getElementById('modalItemImg').src = group.image_url || '../../assets/img/logo.jpg';
  const bilaoNotesField = document.getElementById('modalItemNotes');
  if (bilaoNotesField) {
    bilaoNotesField.value = '';
    bilaoNotesField.maxLength = 221;
    bilaoNotesField.placeholder = 'e.g. No onions, extra calamansi (max 221 chars)...';
  }
  document.getElementById('modalQty').textContent = '1';

  // Check initial button state
  const modalBtn = document.querySelector('#itemDetailModal .btn-primary');
  const isInitAvailable = initialVariant && !initialVariant.is_archived && initialVariant.availability === 'Available' && initialVariant.stock > 0;
  if (modalBtn) {
    modalBtn.disabled = !isInitAvailable;
    modalBtn.textContent = !isInitAvailable ? (initialVariant?.availability !== 'Available' ? 'Unavailable' : 'Sold Out') : 'Add to Order';
  }

  document.getElementById('itemDetailModal').classList.add('active');
}

/**
 * Handle packaging selection (Tray vs Bilao)
 */
function selectPackaging(pkg) {
  if (pkg !== 'Tray' && pkg !== 'Bilao') return;
  appState.selectedPackaging = pkg;

  const optTray = document.getElementById('pkgOptionTray');
  const optBilao = document.getElementById('pkgOptionBilao');
  if (optTray) optTray.classList.toggle('selected', pkg === 'Tray');
  if (optBilao) optBilao.classList.toggle('selected', pkg === 'Bilao');

  const helpText = document.getElementById('packagingHelpText');
  if (helpText) helpText.style.display = 'none';
}

/**
 * Handle portion size selection in Bilao Modal
 */
function selectBilaoModalSize(sz) {
  if (!appState.isBilaoModal || !appState.currentBilaoGroup) return;
  const group = appState.currentBilaoGroup;
  const variant = group.variants[sz];
  if (!variant) return;

  appState.selectedBilaoSize = sz;
  appState.modalUnitPrice = variant.price;

  // Update UI selection on size cards
  const cards = document.querySelectorAll('#modalSizeOptions .size-option-card');
  const sizes = ['S', 'M', 'L', 'XL'];
  cards.forEach((c, idx) => {
    c.classList.toggle('selected', sizes[idx] === sz);
  });

  // Check availability
  const isAvailable = !variant.is_archived && variant.availability === 'Available' && variant.stock > 0;
  const modalBtn = document.querySelector('#itemDetailModal .btn-primary');
  if (modalBtn) {
    modalBtn.disabled = !isAvailable;
    modalBtn.textContent = !isAvailable ? (variant.availability !== 'Available' ? 'Unavailable' : 'Sold Out') : 'Add to Order';
  }

  // Recalculate price
  document.getElementById('modalItemPrice').textContent = formatPeso(appState.modalUnitPrice * appState.modalQty);
}

/**
 * Open Item Detail Modal with Interactive Size Selection & Special Instructions
 */
/**
 * Open Item Detail Modal for Regular Items
 */
function handleCardClick(itemId) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(itemId));
  if (!item) return;

  // If item belongs to a Bilao dish, route directly to openBilaoModal
  const bilaoDish = getBilaoDishFromItem(item);
  if (bilaoDish) {
    openBilaoModal(bilaoDish);
    return;
  }

  const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
  const isAvailable = item.availability === 'Available';
  if (item.is_archived || stock <= 0 || !isAvailable) {
    showToast(!isAvailable ? 'This item is currently unavailable.' : 'This item is currently sold out.');
    return;
  }

  appState.isBilaoModal = false;
  appState.currentBilaoGroup = null;
  appState.currentModalItem = item;
  appState.selectedPackaging = null;
  appState.modalQty = 1;

  // Hide packaging container for regular items
  const pkgContainer = document.getElementById('modalPackagingPickerContainer');
  if (pkgContainer) pkgContainer.style.display = 'none';

  // Regular items have no size options
  const sizeContainer = document.getElementById('modalSizePickerContainer');
  if (sizeContainer) sizeContainer.style.display = 'none';

  appState.modalUnitPrice = Number(item.effective_price || item.price);

  const modalBtn = document.querySelector('#itemDetailModal .btn-primary');
  if (modalBtn) {
    modalBtn.disabled = false;
    modalBtn.textContent = 'Add to Order';
  }

  document.getElementById('modalItemTitle').textContent = item.name;
  document.getElementById('modalItemDesc').textContent = item.description || '';
  document.getElementById('modalItemPrice').textContent = formatPeso(appState.modalUnitPrice);
  document.getElementById('modalItemImg').src = item.image_url || item.image || '../../assets/img/logo.jpg';
  const regNotesField = document.getElementById('modalItemNotes');
  if (regNotesField) {
    regNotesField.value = '';
    regNotesField.maxLength = 255;
    regNotesField.placeholder = 'e.g. No onions, extra gravy, less ice (max 255 chars)...';
  }
  document.getElementById('modalQty').textContent = '1';

  document.getElementById('itemDetailModal').classList.add('active');
}

function adjustModalQty(delta) {
  appState.modalQty = Math.max(1, Math.min(99, appState.modalQty + delta));
  document.getElementById('modalQty').textContent = appState.modalQty;
  const unitPrice = appState.modalUnitPrice || 0;
  document.getElementById('modalItemPrice').textContent = formatPeso(unitPrice * appState.modalQty);
}

function confirmAddItemFromModal() {
  if (appState.isBilaoModal && appState.currentBilaoGroup) {
    const group = appState.currentBilaoGroup;

    // Packaging selection is mandatory!
    if (!appState.selectedPackaging) {
      const helpText = document.getElementById('packagingHelpText');
      if (helpText) {
        helpText.style.display = 'block';
        helpText.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
      showToast('Please select a packaging option (Tray or Bilao).');
      return;
    }

    const sz = appState.selectedBilaoSize || 'S';
    const variant = group.variants[sz];
    if (!variant || variant.is_archived || variant.availability !== 'Available' || variant.stock <= 0) {
      showToast('The selected portion size is currently unavailable.');
      return;
    }

    const pkg = appState.selectedPackaging; // 'Tray' or 'Bilao'
    const finalName = `${group.dish} ${pkg} (${sz})`;
    const notes = document.getElementById('modalItemNotes').value.trim();
    if (notes.length > 221) {
      showToast(`Special instructions are too long (${notes.length} characters). Maximum allowed is 221 characters.`);
      return;
    }
    const unitPrice = variant.price;

    const added = addItemToCart(variant.id, finalName, unitPrice, appState.modalQty, notes, sz, pkg);
    if (added) {
      closeModal('itemDetailModal');
      showToast(`Added ${appState.modalQty}x ${finalName} to cart!`);
    }
    return;
  }

  // Regular item flow
  if (!appState.currentModalItem) return;
  const item = appState.currentModalItem;
  const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
  const isAvailable = item.availability === 'Available';
  if (item.is_archived || stock <= 0 || !isAvailable) {
    showToast(!isAvailable ? 'This item is currently unavailable.' : 'This item is currently sold out.');
    return;
  }

  const notes = document.getElementById('modalItemNotes').value.trim();
  if (notes.length > 255) {
    showToast(`Special instructions are too long (${notes.length} characters). Maximum allowed is 255 characters.`);
    return;
  }
  const unitPrice = Number(item.effective_price || item.price);
  const added = addItemToCart(item.id, item.name, unitPrice, appState.modalQty, notes, null, null);
  if (added) {
    closeModal('itemDetailModal');
    showToast(`Added ${appState.modalQty}x ${item.name} to cart!`);
  }
}

/**
 * Quick Add ("+" button)
 */
function quickAddToCart(itemId) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(itemId));
  if (!item) return;

  // For Bilao dishes, quick-add (+) must open the portion & packaging selector
  const bilaoDish = getBilaoDishFromItem(item);
  if (bilaoDish) {
    openBilaoModal(bilaoDish);
    return;
  }

  const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
  const isAvailable = item.availability === 'Available';
  if (item.is_archived || stock <= 0 || !isAvailable) {
    showToast(!isAvailable ? 'This item is currently unavailable.' : 'This item is currently sold out.');
    return;
  }

  const unitPrice = Number(item.effective_price || item.price);
  const added = addItemToCart(item.id, item.name, unitPrice, 1, '', null, null);
  if (added) {
    showToast(`Added ${item.name} to cart!`);
  }
}

/**
 * Core Cart Management
 */
function addItemToCart(id, name, price, qty, notes, variantSize = null, packaging = null) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(id));
  if (item) {
    const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
    const isAvailable = item.availability === 'Available';
    if (item.is_archived || stock <= 0 || !isAvailable) {
      showToast(!isAvailable ? 'This item is currently unavailable.' : 'This item is currently sold out.');
      return false;
    }
  }
  const existing = appState.cart.find(i =>
    Number(i.id) === Number(id) &&
    i.variantSize === variantSize &&
    i.packaging === packaging &&
    (i.notes || '') === (notes || '')
  );
  if (existing) {
    existing.qty += qty;
  } else {
    appState.cart.push({ id, name, price, qty, notes: notes || '', variantSize, packaging });
  }
  saveCart();
  return true;
}

window.openBilaoModal = openBilaoModal;
window.selectPackaging = selectPackaging;
window.selectBilaoModalSize = selectBilaoModalSize;
window.handleCardClick = handleCardClick;
window.quickAddToCart = quickAddToCart;
window.confirmAddItemFromModal = confirmAddItemFromModal;
window.adjustModalQty = adjustModalQty;

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
          <div style="margin-top: 2px;">
            ${item.notes ? `<div class="item-notes-text">Note: ${escapeHtml(item.notes)}</div>` : ''}
            <button type="button" style="border: none; background: none; color: var(--primary); font-size: 0.75rem; cursor: pointer; text-decoration: underline; padding: 0; font-weight: 600;" onclick="editCartItemNote(${idx})">${item.notes ? 'Edit note' : 'Add note'}</button>
          </div>
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

function editCartItemNote(index) {
  const item = appState.cart[index];
  if (!item) return;
  const maxLen = item.packaging ? 221 : 255;
  const promptMsg = item.packaging
    ? `Edit special instructions for ${item.name}\n(Max ${maxLen} characters for bilao/tray items):`
    : `Edit special instructions for ${item.name}\n(Max ${maxLen} characters):`;
  const newNote = prompt(promptMsg, item.notes || '');
  if (newNote === null) return;
  const trimmed = newNote.trim();
  if (trimmed.length > maxLen) {
    alert(`Special instructions are too long (${trimmed.length} characters). The maximum allowed is ${maxLen} characters.`);
    return;
  }
  item.notes = trimmed;
  saveCart();
  renderCartDrawerItems();
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
      variant_size: i.variantSize || getCartVariantSize(i),
      packaging: i.packaging || null,
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
  openOrdersModal(appState.lastOrderId);
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

const TRACKING_POLL_INTERVAL_MS = 3500; // 3.5 seconds for snappy local testing

function formatTimeShort(dateStr) {
  if (!dateStr) return '';
  try {
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  } catch (_) {
    return '';
  }
}

function openOrdersModal(initialOrderId = null) {
  closeModal('customerAccountModal');
  const modal = document.getElementById('customerOrdersModal');
  if (modal) modal.classList.add('active');
  const list = document.getElementById('customerOrdersList');
  if (list) list.textContent = 'Loading orders…';
  const panel = document.getElementById('orderTrackingPanel');
  if (panel && !initialOrderId && !appState.currentTrackedOrderId) {
    panel.innerHTML = '';
  }
  loadOrderHistory(initialOrderId);
}

function stopTrackingPolling() {
  if (appState.ordersPollInterval) {
    clearInterval(appState.ordersPollInterval);
    appState.ordersPollInterval = null;
  }
  appState.isTrackingPollInFlight = false;
}

function startTrackingPolling(orderId) {
  stopTrackingPolling();
  const modal = document.getElementById('customerOrdersModal');
  if (!modal || !modal.classList.contains('active')) return;
  if (document.hidden) return;

  appState.ordersPollInterval = setInterval(async () => {
    const activeModal = document.getElementById('customerOrdersModal');
    if (!activeModal || !activeModal.classList.contains('active')) {
      stopTrackingPolling();
      return;
    }
    if (document.hidden) return;
    if (appState.currentTrackedOrderId !== Number(orderId)) {
      stopTrackingPolling();
      return;
    }
    await fetchOrderTracking(orderId, true);
  }, TRACKING_POLL_INTERVAL_MS);
}

function updateOrderRowSelection(orderId) {
  document.querySelectorAll('.customer-order-row').forEach(row => {
    const rowId = Number(row.dataset.orderId || 0);
    const isSelected = orderId && rowId === Number(orderId);
    row.classList.toggle('is-selected-tracking', Boolean(isSelected));
    const btn = row.querySelector('.btn-track-order');
    if (btn) {
      btn.classList.toggle('active', Boolean(isSelected));
      btn.innerHTML = isSelected
        ? '<i class="bi bi-geo-alt-fill"></i> Tracking this order'
        : '<i class="bi bi-geo-alt"></i> Track order';
    }
  });
}

function closeTrackingPanel() {
  stopTrackingPolling();
  appState.currentTrackedOrderId = null;
  appState.lastTrackedData = null;
  const panel = document.getElementById('orderTrackingPanel');
  if (panel) panel.innerHTML = '';
  updateOrderRowSelection(null);
}

async function trackOrder(orderId, startPolling = true, scrollIntoView = false) {
  orderId = Number(orderId);
  if (!orderId || isNaN(orderId)) return;

  const panel = document.getElementById('orderTrackingPanel');
  if (!panel) return;

  // Clear existing polling when switching orders
  if (appState.currentTrackedOrderId !== orderId) {
    stopTrackingPolling();
    appState.currentTrackedOrderId = orderId;
    appState.lastTrackedData = null;
    panel.innerHTML = '<div class="tracking-card"><p class="muted"><i class="bi bi-hourglass-split"></i> Loading order tracking…</p></div>';
  }

  updateOrderRowSelection(orderId);

  if (scrollIntoView) {
    const modalBody = document.querySelector('.customer-orders-modal > .modal-body');
    if (modalBody) {
      modalBody.scrollTo({ top: 0, behavior: 'smooth' });
    }
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  await fetchOrderTracking(orderId, false);

  const currentStatus = appState.trackedOrderStatuses[orderId];
  const isActive = ['Pending', 'Preparing', 'Ready'].includes(currentStatus);

  if (startPolling && isActive) {
    startTrackingPolling(orderId);
  } else if (!isActive) {
    stopTrackingPolling();
  }
}

async function fetchOrderTracking(orderId, isBackgroundPoll = false) {
  orderId = Number(orderId);
  if (!orderId || isNaN(orderId)) return;

  // Prevent overlapping concurrent network requests
  if (appState.isTrackingPollInFlight) return;
  appState.isTrackingPollInFlight = true;

  const panel = document.getElementById('orderTrackingPanel');

  try {
    const res = await fetch(`./api/orders.php?action=track&order_id=${encodeURIComponent(orderId)}`, { credentials: 'same-origin' });
    const result = await res.json();

    // If user switched to another order while request was in flight, discard response
    if (appState.currentTrackedOrderId !== orderId) return;

    if (!res.ok || result.status !== 'success') {
      throw new Error(result.message || 'Unable to track this order.');
    }

    const { order, items } = result.data;
    appState.lastTrackedData = result.data;

    const previousStatus = appState.trackedOrderStatuses[order.id];
    if (previousStatus && previousStatus !== 'Ready' && order.status === 'Ready') {
      window.KenjiOrderSounds?.play('ready');
    }
    appState.trackedOrderStatuses[order.id] = order.status;

    // Update status chip in list if present
    const chip = document.getElementById(`order-status-${Number(order.id)}`);
    if (chip) {
      chip.textContent = order.status;
      chip.className = `order-status-chip ${order.status.toLowerCase()}`;
    }

    const completed = ['Served', 'Picked Up'].includes(order.status);
    const cancelled = ['Cancelled', 'Refunded', 'Voided'].includes(order.status);
    const isActive = !completed && !cancelled;

    // If status transitioned to a terminal state (Served or Cancelled), stop polling and refresh lists
    if (!isActive && previousStatus && ['Pending', 'Preparing', 'Ready'].includes(previousStatus)) {
      stopTrackingPolling();
      loadOrderHistory(orderId, false);
    }

    // Render Tracking Card
    const steps = ['Received', 'Preparing', 'Ready', order.order_type === 'ONLINE' ? 'Completed' : 'Picked up'];
    const statusIndex = ({ Pending: 0, Preparing: 1, Ready: 2, Served: 3, 'Picked Up': 3 })[order.status] ?? -1;

    const stepTimes = [
      order.created_at ? formatTimeShort(order.created_at) : '',
      order.preparing_at ? formatTimeShort(order.preparing_at) : '',
      order.ready_at ? formatTimeShort(order.ready_at) : '',
      order.served_at ? formatTimeShort(order.served_at) : ''
    ];

    const progress = cancelled
      ? `<div class="tracking-cancelled-banner"><i class="bi bi-x-circle"></i> This order is ${escapeHtml(order.status.toLowerCase())}.</div>`
      : `<div class="tracking-steps">
          ${steps.map((step, index) => `
            <div class="tracking-step ${index < statusIndex ? 'complete' : ''} ${index === statusIndex ? 'current' : ''}">
              <span class="tracking-step-label">${step}</span>
              ${stepTimes[index] ? `<span class="tracking-step-time">${stepTimes[index]}</span>` : ''}
            </div>
          `).join('')}
         </div>`;

    const itemsHtml = (items || []).map(item => `
      <li>
        <div class="tracking-item-row">
          <span>${Number(item.quantity)} × ${escapeHtml(item.item_name)}</span>
          <span>${formatPeso(item.price)}</span>
        </div>
        ${item.notes ? `<div class="tracking-item-notes">${escapeHtml(item.notes)}</div>` : ''}
      </li>
    `).join('');

    const statusNote = completed
      ? 'Order complete'
      : (cancelled ? 'Order cancelled' : '<span class="live-pulse">●</span> Status updates live (every 3–5s)');

    if (panel) {
      panel.innerHTML = `
        <div class="tracking-card">
          <div class="tracking-card-header">
            <div>
              <h3>Tracking ${escapeHtml(order.order_no)} <span class="order-status-chip ${order.status.toLowerCase()}">${escapeHtml(order.status)}</span></h3>
              <p class="muted">Receipt ${escapeHtml(order.receipt_no || '—')} · ${formatPeso(order.total)} · ${statusNote}</p>
            </div>
            <button type="button" class="tracking-close-btn" onclick="closeTrackingPanel()" aria-label="Close tracking view" title="Close tracking view">
              <i class="bi bi-x-lg"></i> Close
            </button>
          </div>
          ${progress}
          <ul class="tracking-items">${itemsHtml}</ul>
        </div>
      `;
    }

    updateOrderRowSelection(orderId);
  } catch (err) {
    if (appState.currentTrackedOrderId !== orderId) return;

    if (isBackgroundPoll && appState.lastTrackedData && panel) {
      // Preserve last known valid status during background poll error
      let warn = panel.querySelector('.tracking-poll-warning');
      if (!warn) {
        warn = document.createElement('div');
        warn.className = 'tracking-poll-warning';
        const card = panel.querySelector('.tracking-card');
        if (card) card.appendChild(warn);
      }
      if (warn) {
        warn.innerHTML = `<i class="bi bi-wifi-off"></i> Could not refresh status. Retrying…`;
      }
    } else if (panel) {
      panel.innerHTML = `
        <div class="tracking-card">
          <p class="muted" style="color:var(--danger, #EF4444);"><i class="bi bi-exclamation-triangle"></i> ${escapeHtml(err.message || 'Unable to track this order.')}</p>
          <button type="button" class="customer-order-actions button" style="margin-top:8px;" onclick="trackOrder(${orderId}, true, true)">
            <i class="bi bi-arrow-clockwise"></i> Try again
          </button>
        </div>
      `;
    }
  } finally {
    appState.isTrackingPollInFlight = false;
  }
}

async function loadOrderHistory(targetOrderId = null, showLoading = true) {
  const historyList = document.getElementById('customerOrdersList');
  const activeSection = document.getElementById('customerActiveOrdersSection');
  const activeList = document.getElementById('customerActiveOrdersList');
  const historySection = document.getElementById('customerHistorySection');
  const historyHeading = document.getElementById('orderHistoryHeading');

  if (showLoading && historyList) {
    historyList.textContent = 'Loading orders…';
  }

  try {
    const res = await fetch('./api/orders.php?action=history', { credentials: 'same-origin' });
    const result = await res.json();
    if (!res.ok || result.status !== 'success') throw new Error(result.message || 'Unable to load orders.');
    const orders = result.data || [];

    if (!orders.length) {
      if (activeSection) activeSection.style.display = 'none';
      if (historySection) historySection.style.display = 'block';
      if (historyList) historyList.innerHTML = '<p class="muted">No online orders yet. Your orders will appear here.</p>';
      const panel = document.getElementById('orderTrackingPanel');
      if (panel) panel.innerHTML = '';
      return;
    }

    const activeOrders = orders.filter(o => ['Pending', 'Preparing', 'Ready'].includes(o.status));
    const historyOrders = orders.filter(o => !['Pending', 'Preparing', 'Ready'].includes(o.status));

    const renderCard = (order) => {
      const isSelected = appState.currentTrackedOrderId === Number(order.id);
      const formattedDate = new Date(order.created_at.replace(' ', 'T')).toLocaleString();
      const fulfillment = order.order_type === 'ONLINE' ? 'Delivery' : 'Pickup';
      const emailStatusText = order.receipt_email_status === 'Sent'
        ? 'Emailed'
        : (order.receipt_email_status === 'Failed' ? 'Receipt email failed' : 'Receipt email pending');

      return `
        <article class="customer-order-row ${isSelected ? 'is-selected-tracking' : ''}" id="customer-order-${Number(order.id)}" data-order-id="${Number(order.id)}">
          <div class="customer-order-top">
            <strong>${escapeHtml(order.order_no)}</strong>
            <span class="order-status-chip ${order.status.toLowerCase()}" id="order-status-${Number(order.id)}">${escapeHtml(order.status)}</span>
          </div>
          <p>
            ${formattedDate} · ${fulfillment} · ${formatPeso(order.total)}<br>
            Receipt ${escapeHtml(order.receipt_no || '—')} · ${emailStatusText}
          </p>
          <div class="customer-order-actions">
            <button type="button" class="btn-track-order ${isSelected ? 'active' : ''}" data-track-order-id="${Number(order.id)}" onclick="trackOrder(${Number(order.id)}, true, true)">
              <i class="bi ${isSelected ? 'bi-geo-alt-fill' : 'bi-geo-alt'}"></i> ${isSelected ? 'Tracking this order' : 'Track order'}
            </button>
          </div>
        </article>
      `;
    };

    // Render Active Orders Section (at top, above history)
    if (activeOrders.length > 0) {
      if (activeSection) activeSection.style.display = 'block';
      if (activeList) activeList.innerHTML = activeOrders.map(renderCard).join('');
    } else {
      if (activeSection) activeSection.style.display = 'none';
      if (activeList) activeList.innerHTML = '';
    }

    // Render History Orders Section (below active orders)
    if (historyOrders.length > 0) {
      if (historySection) historySection.style.display = 'block';
      if (historyHeading) historyHeading.style.display = activeOrders.length > 0 ? 'flex' : 'none';
      if (historyList) historyList.innerHTML = historyOrders.map(renderCard).join('');
    } else {
      if (historySection) historySection.style.display = 'none';
      if (historyList) historyList.innerHTML = '';
    }

    // Determine target order to track
    let chosenOrderId = targetOrderId;
    if (!chosenOrderId) {
      if (appState.currentTrackedOrderId && orders.some(o => Number(o.id) === appState.currentTrackedOrderId)) {
        chosenOrderId = appState.currentTrackedOrderId;
      } else if (activeOrders.length > 0) {
        chosenOrderId = Number(activeOrders[0].id); // Newest active order first
      }
    }

    if (chosenOrderId) {
      const isTargetActive = activeOrders.some(o => Number(o.id) === chosenOrderId);
      trackOrder(chosenOrderId, isTargetActive, false);
    } else {
      const panel = document.getElementById('orderTrackingPanel');
      if (panel) panel.innerHTML = '';
      updateOrderRowSelection(null);
    }
  } catch (err) {
    if (historyList) {
      historyList.innerHTML = `<p class="muted">${escapeHtml(err.message || 'Unable to load orders.')}</p>`;
    }
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
  stopTrackingPolling();
  appState.currentTrackedOrderId = null;
  appState.lastTrackedData = null;
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
  if (modalId === 'customerOrdersModal') {
    stopTrackingPolling();
    appState.currentTrackedOrderId = null;
    appState.lastTrackedData = null;
  }
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
    const payload = {
      [itype === 'email' ? 'email' : 'phone']: contact,
      mode: form,
      ...(isReg ? { full_name: fullName, password: _auth.pendingPassword } : {})
    };
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
      mode: _auth.tab
    };

    const res = await fetch('./api/auth.php?action=otp_verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (result.ok) {
      clearInterval(_auth.timerInterval);
      _auth.pendingPassword = '';

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
    } else if (result.status === 'unverified') {
      alert(result.message || 'Please verify your email address to complete your registration.');
      _auth.pendingContact = loginInput;
      document.getElementById('authOtpChannel').textContent = 'email';
      document.getElementById('authOtpTarget').textContent  = loginInput;
      _showAuthStep(2);
      _clearOtpBoxes();
      _authInitOtpBoxes();
      _authStartCountdown(300);
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
