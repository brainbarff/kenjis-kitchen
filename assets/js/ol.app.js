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
 * - Live order placement; GCash reaches KDS after staff verification
 */

'use strict';

const API_ROOT = window.location.port === '8787'
  ? 'http://127.0.0.1:8787/api'
  : './api';
const ONE_LITER_DRINK_NAME = 'Assorted 1L Bottled Drink';

function isLegacyAssortedOneLiterLine(line, item = null) {
  const name = String(line?.name || item?.name || '').trim();
  return Boolean(line?.flavor) || /^Assorted 1L Bottled Drink\b/i.test(name);
}

// Application State
const appState = {
  categories: [
    { id: 'all', name: 'All' }
  ],
  menuItems: [],
  bilaoServerGroups: null,
  drinkVariantInfo: null,
  menuLoadError: '',
  activeCategory: 'all',
  searchQuery: '',
  fulfillmentMode: '', // '' | 'delivery' | 'pickup' (unselected by default)
  cart: JSON.parse(localStorage.getItem('kk_cart_v110') || '[]'),
  cartRowFeedback: {},
  cartStockBanner: '',
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
  myReview: null,
  reviewCsrf: '',
  reviewOrderId: null,
  gcashSettingsPromise: null,
  deliveryRules: {
    within_lagro_min_items: 2,
    within_lagro_min_amount: 100,
    cod_min_order: 30,
    cod_max_order: 0
  },
  otpSentEmail: '',
  otpTimerInterval: null,
  deliveryCoverage: {
    boundaryData: null,
    boundaryLoadPromise: null,
    boundaryLoadError: false,
    map: null,
    tileLayer: null,
    tileErrorCount: 0,
    boundaryLayer: null,
    storeMarker: null,
    destMarker: null,
    lat: null,
    lng: null,
    status: 'SELECT_LOCATION',
    headline: 'Select your delivery location.',
    message: 'Select your delivery location on the map and enter your complete House/Block/Lot and Street address.',
    reasonCode: 'PIN_REQUIRED',
    eligibleFree: false,
    eligibleOutside: false,
    deliveryMode: '',
    verificationToken: null,
    verifiedUserId: 0,
    verifiedAddressSnapshot: '',
    verifiedLatSnapshot: '',
    verifiedLngSnapshot: '',
    pinAutoPlacedForStore: false,
    addressOrigin: 'empty',
    pendingPinAddress: '',
    boundaryVersion: '',
    matchedStreet: null,
    pinInsideBoundary: null,
    pinNearBoundaryEdge: false,
    edgeDistanceMeters: null,
    coverageChecked: false,
    addressConfirmed: false,
    suggestedCenter: null,
    seq: 0,
    inFlightController: null,
    reverseGeocodeController: null,
    geocodeController: null,
    debounceTimer: null
  }
};

// Formatting helper
const formatPeso = val => `₱${Number(val || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

/* ══════════════════════════════════════════════════════════════════════════════
   REUSABLE STOREFRONT NOTIFICATIONS, CONFIRMATIONS & PROMPT DIALOGS
   ══════════════════════════════════════════════════════════════════════════════ */
(function initStorefrontDialogs() {
  function ensureToastContainer() {
    let container = document.getElementById('kkToastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'kkToastContainer';
      container.className = 'kk-toast-container';
      container.setAttribute('role', 'region');
      container.setAttribute('aria-label', 'Notifications');
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-atomic', 'false');
      document.body.appendChild(container);
    }
    return container;
  }

  function syncOverlayToastContext(container) {
    const hasOverlay = Boolean(document.querySelector('.modal-overlay.active, .drawer-sheet.active, .kk-dialog-backdrop.show'));
    const wasOverlay = document.body.classList.contains('kk-overlay-open');
    document.body.classList.toggle('kk-overlay-open', hasOverlay);
    if (container) {
      // Dismiss stale background toasts when entering a modal/drawer context
      if (hasOverlay && !wasOverlay) {
        Array.from(container.children).forEach((el) => {
          const tid = Number(el.dataset.timeoutId || 0);
          if (tid) clearTimeout(tid);
          el.remove();
        });
      }
      container.classList.toggle('is-overlay-context', hasOverlay);
    }
  }
  window.kkSyncOverlayContext = () => syncOverlayToastContext(document.getElementById('kkToastContainer'));

  const typeMeta = {
    success: { icon: 'bi-check-circle-fill', defaultTitle: 'Success' },
    error:   { icon: 'bi-exclamation-octagon-fill', defaultTitle: 'Error' },
    warning: { icon: 'bi-exclamation-triangle-fill', defaultTitle: 'Notice' },
    info:    { icon: 'bi-info-circle-fill', defaultTitle: 'Information' }
  };

  window.kkNotify = function kkNotify(message, type = 'info', options = {}) {
    const cleanType = typeMeta[type] ? type : 'info';
    const meta = typeMeta[cleanType];
    const opts = typeof options === 'string' ? { title: options } : (options || {});
    const title = opts.title || meta.defaultTitle;
    const msgText = String(message ?? '');
    const duration = typeof opts.duration === 'number' ? opts.duration : 3600;
    const dedupeKey = String(opts.dedupeKey || `${cleanType}::${title}::${msgText}`);
    const container = ensureToastContainer();
    syncOverlayToastContext(container);

    // Prevent repeated stacked toasts from rapid clicks or duplicate handlers
    const existing = Array.from(container.children).find(el => el.dataset.dedupeKey === dedupeKey);
    if (existing) {
      if (existing._dismissTimer) {
        clearTimeout(existing._dismissTimer);
        existing._dismissTimer = null;
      }
      const titleNode = existing.querySelector('.kk-toast-title');
      const msgNode = existing.querySelector('.kk-toast-msg');
      if (titleNode) titleNode.textContent = String(title);
      if (msgNode) msgNode.textContent = msgText;
      existing.classList.add('show');
      if (duration > 0) {
        existing._dismissTimer = setTimeout(() => existing._dismissFn && existing._dismissFn(), duration);
      }
      return { dismiss: () => existing._dismissFn && existing._dismissFn() };
    }

    while (container.children.length >= 2) {
      const oldest = container.firstElementChild;
      if (oldest && oldest._dismissTimer) clearTimeout(oldest._dismissTimer);
      if (oldest) oldest.remove();
    }

    const toast = document.createElement('div');
    toast.className = `kk-toast kk-toast-${cleanType}`;
    toast.dataset.dedupeKey = dedupeKey;
    toast.setAttribute('role', cleanType === 'error' ? 'alert' : 'status');

    const iconWrap = document.createElement('div');
    iconWrap.className = 'kk-toast-icon';
    const iconEl = document.createElement('i');
    iconEl.className = `bi ${meta.icon}`;
    iconEl.setAttribute('aria-hidden', 'true');
    iconWrap.appendChild(iconEl);

    const bodyWrap = document.createElement('div');
    bodyWrap.className = 'kk-toast-body';
    const titleEl = document.createElement('strong');
    titleEl.className = 'kk-toast-title';
    titleEl.textContent = String(title);
    const msgEl = document.createElement('span');
    msgEl.className = 'kk-toast-msg';
    msgEl.textContent = msgText;
    const brand = document.createElement('span');
    brand.className = 'kk-toast-brand';
    brand.textContent = "KENJI'S KITCHEN";
    bodyWrap.appendChild(brand);
    bodyWrap.appendChild(titleEl);
    bodyWrap.appendChild(msgEl);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'kk-toast-close';
    closeBtn.setAttribute('aria-label', 'Dismiss notification');
    closeBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

    const dismiss = () => {
      if (toast._dismissTimer) {
        clearTimeout(toast._dismissTimer);
        toast._dismissTimer = null;
      }
      toast.classList.remove('show');
      setTimeout(() => {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 200);
    };

    toast._dismissFn = dismiss;
    closeBtn.addEventListener('click', dismiss);

    toast.appendChild(iconWrap);
    toast.appendChild(bodyWrap);
    toast.appendChild(closeBtn);
    container.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.add('show');
    });

    if (duration > 0) {
      toast._dismissTimer = setTimeout(dismiss, duration);
    }

    return { dismiss };
  };

  window.kkConfirm = function kkConfirm(options = {}) {
    const opts = typeof options === 'string' ? { message: options } : (options || {});
    const title = opts.title || 'Please Confirm';
    const message = opts.message || 'Are you sure you want to continue?';
    const confirmText = opts.confirmText || 'Confirm';
    const cancelText = opts.cancelText || 'Cancel';
    const variant = opts.variant === 'danger' ? 'danger' : 'primary';
    const previousFocus = document.activeElement;

    const existing = document.getElementById('kkStoreDialogBackdrop');
    if (existing) existing.remove();

    return new Promise(resolve => {
      const backdrop = document.createElement('div');
      backdrop.id = 'kkStoreDialogBackdrop';
      backdrop.className = 'kk-dialog-backdrop';
      backdrop.setAttribute('role', 'dialog');
      backdrop.setAttribute('aria-modal', 'true');

      const card = document.createElement('div');
      card.className = 'kk-dialog-card';

      const accent = document.createElement('div');
      accent.className = `kk-dialog-accent ${variant === 'danger' ? 'danger' : ''}`;

      const body = document.createElement('div');
      body.className = 'kk-dialog-body';

      const head = document.createElement('div');
      head.className = 'kk-dialog-head';

      const iconWrap = document.createElement('div');
      iconWrap.className = `kk-dialog-icon ${variant === 'danger' ? 'danger' : ''}`;
      const iconEl = document.createElement('i');
      iconEl.className = variant === 'danger' ? 'bi bi-exclamation-triangle-fill' : 'bi bi-shield-check';
      iconWrap.appendChild(iconEl);

      const titleEl = document.createElement('h3');
      titleEl.className = 'kk-dialog-title';
      titleEl.textContent = String(title);

      head.appendChild(iconWrap);
      head.appendChild(titleEl);
      body.appendChild(head);

      const msgEl = document.createElement('p');
      msgEl.className = 'kk-dialog-message';
      msgEl.textContent = String(message);
      body.appendChild(msgEl);

      const actions = document.createElement('div');
      actions.className = 'kk-dialog-actions';

      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'btn-primary';
      cancelBtn.style.cssText = 'width:auto;background:var(--white);color:var(--dark);border:1px solid var(--border);padding:8px 16px;min-height:40px;';
      cancelBtn.textContent = String(cancelText);

      const confirmBtn = document.createElement('button');
      confirmBtn.type = 'button';
      confirmBtn.className = 'btn-primary';
      confirmBtn.style.cssText = variant === 'danger'
        ? 'width:auto;background:var(--danger);color:#fff;border:none;padding:8px 16px;min-height:40px;'
        : 'width:auto;padding:8px 16px;min-height:40px;';
      confirmBtn.textContent = String(confirmText);

      actions.appendChild(cancelBtn);
      actions.appendChild(confirmBtn);

      card.appendChild(accent);
      card.appendChild(body);
      card.appendChild(actions);
      backdrop.appendChild(card);
      document.body.appendChild(backdrop);

      let finished = false;
      const finish = (res) => {
        if (finished) return;
        finished = true;
        document.removeEventListener('keydown', onKey, true);
        backdrop.classList.remove('show');
        setTimeout(() => {
          if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
          if (previousFocus && typeof previousFocus.focus === 'function') {
            try { previousFocus.focus(); } catch (_) {}
          }
        }, 180);
        resolve(res);
      };

      const onKey = (e) => {
        if (e.key === 'Escape') {
          e.preventDefault();
          e.stopPropagation();
          finish(false);
        }
      };

      document.addEventListener('keydown', onKey, true);
      cancelBtn.addEventListener('click', () => finish(false));
      confirmBtn.addEventListener('click', () => finish(true));
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) finish(false);
      });

      requestAnimationFrame(() => {
        backdrop.classList.add('show');
        confirmBtn.focus();
      });
    });
  };

  window.kkPrompt = function kkPrompt(options = {}) {
    const opts = typeof options === 'string' ? { message: options } : (options || {});
    const title = opts.title || 'Enter Details';
    const message = opts.message || '';
    const defaultValue = String(opts.defaultValue ?? '');
    const placeholder = String(opts.placeholder ?? '');
    const maxLength = Number(opts.maxLength || 0);
    const confirmText = opts.confirmText || 'Save';
    const cancelText = opts.cancelText || 'Cancel';
    const previousFocus = document.activeElement;

    const existing = document.getElementById('kkStoreDialogBackdrop');
    if (existing) existing.remove();

    return new Promise(resolve => {
      const backdrop = document.createElement('div');
      backdrop.id = 'kkStoreDialogBackdrop';
      backdrop.className = 'kk-dialog-backdrop';
      backdrop.setAttribute('role', 'dialog');
      backdrop.setAttribute('aria-modal', 'true');

      const card = document.createElement('div');
      card.className = 'kk-dialog-card';

      const accent = document.createElement('div');
      accent.className = 'kk-dialog-accent';

      const body = document.createElement('div');
      body.className = 'kk-dialog-body';

      const head = document.createElement('div');
      head.className = 'kk-dialog-head';

      const iconWrap = document.createElement('div');
      iconWrap.className = 'kk-dialog-icon';
      const iconEl = document.createElement('i');
      iconEl.className = 'bi bi-pencil-square';
      iconWrap.appendChild(iconEl);

      const titleEl = document.createElement('h3');
      titleEl.className = 'kk-dialog-title';
      titleEl.textContent = String(title);

      head.appendChild(iconWrap);
      head.appendChild(titleEl);
      body.appendChild(head);

      if (message) {
        const msgEl = document.createElement('p');
        msgEl.className = 'kk-dialog-message';
        msgEl.textContent = String(message);
        body.appendChild(msgEl);
      }

      const inputEl = document.createElement('input');
      inputEl.type = 'text';
      inputEl.className = 'checkout-input';
      inputEl.value = defaultValue;
      inputEl.placeholder = placeholder;
      if (maxLength > 0) inputEl.maxLength = maxLength;
      body.appendChild(inputEl);

      const actions = document.createElement('div');
      actions.className = 'kk-dialog-actions';

      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'btn-primary';
      cancelBtn.style.cssText = 'width:auto;background:var(--white);color:var(--dark);border:1px solid var(--border);padding:8px 16px;min-height:40px;';
      cancelBtn.textContent = String(cancelText);

      const confirmBtn = document.createElement('button');
      confirmBtn.type = 'button';
      confirmBtn.className = 'btn-primary';
      confirmBtn.style.cssText = 'width:auto;padding:8px 16px;min-height:40px;';
      confirmBtn.textContent = String(confirmText);

      actions.appendChild(cancelBtn);
      actions.appendChild(confirmBtn);

      card.appendChild(accent);
      card.appendChild(body);
      card.appendChild(actions);
      backdrop.appendChild(card);
      document.body.appendChild(backdrop);

      let finished = false;
      const finish = (val) => {
        if (finished) return;
        finished = true;
        document.removeEventListener('keydown', onKey, true);
        backdrop.classList.remove('show');
        setTimeout(() => {
          if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
          if (previousFocus && typeof previousFocus.focus === 'function') {
            try { previousFocus.focus(); } catch (_) {}
          }
        }, 180);
        resolve(val);
      };

      const onKey = (e) => {
        if (e.key === 'Escape') {
          e.preventDefault();
          e.stopPropagation();
          finish(null);
        } else if (e.key === 'Enter' && document.activeElement === inputEl) {
          e.preventDefault();
          finish(inputEl.value);
        }
      };

      document.addEventListener('keydown', onKey, true);
      cancelBtn.addEventListener('click', () => finish(null));
      confirmBtn.addEventListener('click', () => finish(inputEl.value));
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) finish(null);
      });

      requestAnimationFrame(() => {
        backdrop.classList.add('show');
        inputEl.focus();
        inputEl.select();
      });
    });
  };
})();

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

function getStockPoolInfo(item) {
  if (!item || Boolean(item.is_archived)) {
    return {
      poolKey: 'menu:' + Number(item?.id || 0),
      poolStock: 0,
      isAvailable: false,
      isArchived: true
    };
  }
  const stock = Math.max(0, Math.floor(Number(item.stock ?? item.pcs_available ?? 0) || 0));
  return {
    poolKey: 'menu:' + Number(item.id),
    poolStock: stock,
    isAvailable: item.availability === 'Available',
    isArchived: false
  };
}

function getCartLinePoolKey(line) {
  if (!line) return '';
  return 'menu:' + Number(line.id);
}

function usedInCartForPool(poolKey, excludeIndex = -1) {
  return window.KKStockQty.sumPoolQty(appState.cart, getCartLinePoolKey, poolKey, excludeIndex);
}

function cartQuantityForMenuItem(id) {
  const item = appState.menuItems.find(entry => Number(entry.id) === Number(id));
  if (item) {
    const pool = getStockPoolInfo(item);
    return usedInCartForPool(pool.poolKey, -1);
  }
  return appState.cart.reduce((total, line) => total + (Number(line.id) === Number(id) ? Number(line.qty) || 0 : 0), 0);
}

function remainingMenuStock(id) {
  const item = appState.menuItems.find(entry => Number(entry.id) === Number(id));
  if (!item || item.availability !== 'Available' || item.is_archived) return 0;
  const pool = getStockPoolInfo(item);
  if (!pool.isAvailable || pool.isArchived) return 0;
  return Math.max(0, pool.poolStock - usedInCartForPool(pool.poolKey, -1));
}

function getCartLineAllowance(index) {
  const line = appState.cart[index];
  if (!line) return { pool: { poolKey: '', poolStock: 0, isAvailable: false }, usedInOtherRows: 0, availableForRow: 0, effectiveMax: 0 };
  const item = appState.menuItems.find(entry => Number(entry.id) === Number(line.id));
  const pool = item
    ? getStockPoolInfo(item)
    : { poolKey: getCartLinePoolKey(line), poolStock: window.KKStockQty.ONLINE_MAX_LINE_QTY, isAvailable: true, isArchived: false };
  const usedInOtherRows = usedInCartForPool(pool.poolKey, index);
  const availableForRow = Math.max(0, pool.poolStock - usedInOtherRows);
  const effectiveMax = Math.min(availableForRow, window.KKStockQty.ONLINE_MAX_LINE_QTY);
  return { pool, usedInOtherRows, availableForRow, effectiveMax };
}

function showStockLimit(item, remaining, allowanceOverride = null) {
  const allowance = allowanceOverride || (() => {
    const pool = getStockPoolInfo(item);
    const used = usedInCartForPool(pool.poolKey, -1);
    return window.KKStockQty.getAddAllowance({
      poolStock: pool.poolStock,
      usedInBasketForPool: used,
      perLineCap: window.KKStockQty.ONLINE_MAX_LINE_QTY,
      existingMatchingLineQty: 0
    });
  })();
  const msg = window.KKStockQty.formatAddLimitMessage(allowance);
  window.kkNotify(msg, 'warning', { title: 'Stock Limit', dedupeKey: 'stock-limit-' + Number(item?.id || 0), duration: 3600 });
}

function reconcileCartWithMenuStock(notifyUser = true) {
  let changed = false;
  const removedNames = [];
  const adjustedDetails = [];
  const priceUpdatedDetails = [];
  const used = new Map();
  const bilaoGroups = buildBilaoGroups();
  const nextRowFeedback = {};

  const nextCart = [];
  for (let i = 0; i < appState.cart.length; i++) {
    const line = appState.cart[i];
    const item = appState.menuItems.find(entry => Number(entry.id) === Number(line.id));
    const displayName = line.name || item?.name || 'Item';

    if (isLegacyAssortedOneLiterLine(line, item) || !item || Boolean(item.is_archived) || item.availability !== 'Available') {
      changed = true;
      removedNames.push(displayName);
      continue;
    }

    let expectedUnitPrice = Number(item.effective_price ?? item.price);
    const bilaoDish = getBilaoDishFromItem(item);
    const sz = line.variantSize || getCartVariantSize(line);
    if (bilaoDish || line.packaging || sz) {
      const group = bilaoDish ? bilaoGroups[bilaoDish] : null;
      const variant = (group && sz) ? group.variants[sz] : null;
      const validPkg = line.packaging === 'Tray' || line.packaging === 'Bilao';
      if (!group || !variant || Boolean(variant.is_archived) || Number(variant.id) !== Number(line.id) || !validPkg || variant.availability !== 'Available' || Number(variant.stock) <= 0) {
        changed = true;
        removedNames.push(displayName);
        continue;
      }
      expectedUnitPrice = Number(variant.price);
    }

    const pool = getStockPoolInfo(item);
    if (!pool.isAvailable || pool.poolStock <= 0) {
      changed = true;
      removedNames.push(displayName);
      continue;
    }

    if (Number.isFinite(expectedUnitPrice) && Math.abs(Number(line.price) - expectedUnitPrice) > 0.009) {
      priceUpdatedDetails.push(`${displayName} (${formatPeso(line.price)} → ${formatPeso(expectedUnitPrice)})`);
      line.price = expectedUnitPrice;
      changed = true;
    }

    const remaining = Math.max(0, pool.poolStock - (used.get(pool.poolKey) || 0));
    const maxForLine = Math.min(remaining, window.KKStockQty.ONLINE_MAX_LINE_QTY);
    const oldQty = Math.max(0, Math.floor(Number(line.qty) || 0));
    const kept = Math.min(oldQty, maxForLine);
    if (kept !== oldQty) {
      changed = true;
      if (kept > 0) {
        adjustedDetails.push(`${displayName} (${oldQty} → ${kept})`);
      }
    }
    if (kept <= 0) {
      changed = true;
      removedNames.push(displayName);
      continue;
    }
    const newIdx = nextCart.length;
    line.qty = kept;
    nextCart.push(line);
    used.set(pool.poolKey, (used.get(pool.poolKey) || 0) + kept);
    if (kept !== oldQty) {
      nextRowFeedback[newIdx] = {
        text: kept < remaining
          ? `Adjusted to ${kept} (limit per row).`
          : `Adjusted to ${kept} (current stock).`,
        tone: 'warning'
      };
    } else if (appState.cartRowFeedback && appState.cartRowFeedback[i]) {
      nextRowFeedback[newIdx] = appState.cartRowFeedback[i];
    }
  }

  appState.cart = nextCart;
  appState.cartRowFeedback = nextRowFeedback;

  if (changed) {
    const parts = [];
    if (removedNames.length > 0) {
      parts.push(`${removedNames.join(', ')} is no longer available and was removed.`);
    }
    if (adjustedDetails.length > 0) {
      parts.push(`Quantities updated to match available stock: ${adjustedDetails.join(', ')}.`);
    }
    if (priceUpdatedDetails.length > 0) {
      parts.push(`Prices updated to match current menu: ${priceUpdatedDetails.join(', ')}.`);
    }
    appState.cartStockBanner = parts.join(' ');
    saveCart();
    if (notifyUser) {
      const drawerOpen = Boolean(document.getElementById('cartDrawerSheet')?.classList.contains('active'));
      if (!drawerOpen && parts.length > 0) {
        window.kkNotify(appState.cartStockBanner, 'warning', {
          title: removedNames.length > 0 ? 'Unavailable Item Removed' : 'Basket Updated',
          dedupeKey: 'cart-reconcile'
        });
      }
    }
  }

  return {
    changed,
    removedCount: removedNames.length,
    adjustedCount: adjustedDetails.length + priceUpdatedDetails.length,
    removedNames,
    adjustedDetails,
    priceUpdatedDetails
  };
}

async function refreshMenuAndValidateCart(notifyUser = true) {
  try {
    const res = await fetch(`${API_ROOT}/menu.php?action=items`, { cache: 'no-store' });
    const data = await res.json();
    if (res.ok && data.status === 'success' && Array.isArray(data.data)) {
      appState.menuItems = data.data;
      appState.bilaoServerGroups = data.bilao_groups || null;
      appState.drinkVariantInfo = data.drink_variant_info || null;
      appState.menuLoadError = '';
      const check = reconcileCartWithMenuStock(notifyUser);
      filterAndRenderMenu();
      return check;
    }
  } catch (_) {}
  return reconcileCartWithMenuStock(notifyUser);
}

/**
 * Initialize Frontend on DOM Load
 */
document.addEventListener('DOMContentLoaded', async () => {
  renderCategoryPills();
  setupUIEventListeners();
  updateCartUI();
  updateUserUI();
  if (window.location.protocol === 'file:') {
    const grid = document.getElementById('foodGrid');
    if (grid) grid.innerHTML = '<div style="grid-column:1/-1; padding:32px; text-align:center; border:1px solid var(--border); border-radius:12px;"><h3>Open the online store through XAMPP</h3><p>This page was opened as a local file, so it cannot load the live menu from PHP and MySQL. Start Apache and MySQL in XAMPP, then open the link below.</p><a href="http://localhost/kenjis-kitchen/online-menu/">Open Kenji\'s Kitchen online store</a></div>';
    return;
  }
  await Promise.all([
    loadCategories(),
    loadMenuItems(),
    syncCustomerSession(),
    loadOnlineGcashSettings()
  ]);
});

async function loadOnlineGcashSettings() {
  if (appState.gcashSettingsPromise) return appState.gcashSettingsPromise;
  appState.gcashSettingsPromise = (async () => {
    try {
      const response = await fetch('./api/payment_settings.php', { credentials: 'same-origin', cache: 'no-store' });
      const result = await response.json();
      if (!response.ok || result.status !== 'success') return;

      if (result.data?.delivery_rules && typeof result.data.delivery_rules === 'object') {
        appState.deliveryRules = {
          within_lagro_min_items: Math.max(0, Number(result.data.delivery_rules.within_lagro_min_items ?? 2)),
          within_lagro_min_amount: Math.max(0, Number(result.data.delivery_rules.within_lagro_min_amount ?? 100)),
          cod_min_order: Math.max(0, Number(result.data.delivery_rules.cod_min_order ?? 30)),
          cod_max_order: Math.max(0, Number(result.data.delivery_rules.cod_max_order ?? 0)),
        };
      }

      const phone = String(result.data?.gcash_phone || '').replace(/\D/g, '');
      const imageUrl = result.data?.gcash_qr_url;
      const targets = [
        { phoneId: 'onlineGcashPhone', frameEl: document.querySelector('#onlineGcashWrap .gcash-qr-frame'), imgId: 'onlineGcashQr' },
        { phoneId: 'courierFeeGcashPhone', frameEl: document.getElementById('courierFeeQrFrame'), imgId: 'courierFeeGcashQr' }
      ];
      targets.forEach(({ phoneId, frameEl, imgId }) => {
        const prefix = phoneId.replace('GcashPhone', '');
        const nameNode = document.getElementById(prefix + 'GcashName');
        if (nameNode) nameNode.textContent = String(result.data?.gcash_account_name || 'Account name not configured');
        const download = document.getElementById(prefix + 'GcashDownload');
        if (download && typeof imageUrl === 'string' && imageUrl.startsWith('/') && !imageUrl.startsWith('//')) { download.href = imageUrl; download.hidden = false; }
        const phoneNode = document.getElementById(phoneId);
        if (phoneNode && /^09\d{9}$/.test(phone)) phoneNode.textContent = phone;
        const image = document.getElementById(imgId);
        if (!frameEl || !image || typeof imageUrl !== 'string' || !imageUrl.startsWith('/') || imageUrl.startsWith('//')) return;
        frameEl.classList.remove('loaded', 'unavailable');
        image.onload = () => frameEl.classList.add('loaded');
        image.onerror = () => frameEl.classList.add('unavailable');
        image.src = imageUrl;
      });
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
      if (!appState.hasInitializedDefaultCategory) {
        appState.hasInitializedDefaultCategory = true;
        const studentMealsCat = activeCats.find(c => String(c.name || '').trim().toLowerCase() === 'student meals');
        if (studentMealsCat && appState.activeCategory === 'all') {
          appState.activeCategory = String(studentMealsCat.id);
        }
      }
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

  const categoryOrder = ['all', 'add-ons', 'student meals', 'pulutan & pares', 'drinks', 'silog', 'silog set meals', 'bilao favorites'];
  const categoryLabel = name => name.toLowerCase() === 'silog meals' ? 'Silog' : name;
  const rank = name => { const index = categoryOrder.indexOf(categoryLabel(name).toLowerCase()); return index < 0 ? 99 : index; };
  container.innerHTML = [...appState.categories].sort((a, b) => rank(a.name) - rank(b.name)).map(cat => {
    const label = escapeHtml(categoryLabel(cat.name));
    const isActive = String(appState.activeCategory) === String(cat.id);

    return `
      <button class="cat-pill ${isActive ? 'active' : ''}" data-cat-id="${cat.id}">
        ${label}
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

      // Keep the category strip visible when filtering changes the grid height.
      const categoryBar = document.querySelector('.category-strip-wrapper');
      const headerHeight = document.querySelector('.navbar')?.offsetHeight || 80;
      if (categoryBar && categoryBar.getBoundingClientRect().top < headerHeight) {
        window.scrollTo({top: categoryBar.getBoundingClientRect().top + window.scrollY - headerHeight, behavior: 'instant'});
      }
    });
  }

  // Search Input (debounced for smooth typing)
  const searchInput = document.getElementById('searchInput');
  if (searchInput) {
    let searchDebounceTimer = null;
    searchInput.addEventListener('input', (e) => {
      appState.searchQuery = e.target.value.toLowerCase().trim();
      if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
      searchDebounceTimer = setTimeout(() => {
        filterAndRenderMenu();
      }, 120);
    });
  }

  const avatarInput = document.getElementById('customerAvatarInput');
  if (avatarInput) {
    avatarInput.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      if (file) handleCustomerAvatarUpload(file);
    });
  }

  // Fulfillment selection inside Checkout Modal
  document.querySelectorAll('#checkoutFulfillmentGroup .fulfillment-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.dataset.mode) {
        selectCheckoutFulfillment(btn.dataset.mode);
      }
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

  // Item detail modal quantity input & notes synchronization
  const modalQtyEl = document.getElementById('modalQty');
  if (modalQtyEl) {
    modalQtyEl.addEventListener('input', () => {
      modalQtyEl.dataset.dirty = '1';
    });
    modalQtyEl.addEventListener('keydown', (e) => {
      if (e.isComposing) return;
      if (e.key === 'Enter') {
        e.preventDefault();
        commitModalQtyInput({ force: true });
        modalQtyEl.blur();
      }
    });
    modalQtyEl.addEventListener('blur', () => {
      commitModalQtyInput();
    });
  }
  const modalNotesEl = document.getElementById('modalItemNotes');
  if (modalNotesEl) {
    modalNotesEl.addEventListener('input', () => {
      if (document.getElementById('itemDetailModal')?.classList.contains('active')) {
        syncModalQtyUI();
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
    if (!res.ok || data.status !== 'success' || !Array.isArray(data.data)) throw new Error('Menu API returned an error');
    appState.menuItems = data.data;
    appState.bilaoServerGroups = data.bilao_groups || null;
    appState.drinkVariantInfo = data.drink_variant_info || null;
    appState.menuLoadError = '';
    reconcileCartWithMenuStock();
  } catch (_) {
    appState.menuItems = [];
    appState.menuLoadError = 'The live menu could not be loaded. Check that MySQL is running in XAMPP, then try again.';
    console.warn('Menu API unavailable.');
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
    const serverGroup = (appState.bilaoServerGroups && appState.bilaoServerGroups[dish])
      ? appState.bilaoServerGroups[dish]
      : null;

    if (serverGroup && serverGroup.all_variants_archived) {
      return;
    }

    groups[dish] = {
      dish,
      name: spec.name,
      description: spec.description,
      category_id: 6,
      category_name: 'Bilao Favorites',
      category_slug: 'bilao_favorites',
      is_bilao_group: true,
      image_url: null,
      tray_image_url: serverGroup?.tray_image_url || null,
      bilao_image_url: serverGroup?.bilao_image_url || null,
      variants: {}
    };

    const archivedSizesSet = new Set(Array.isArray(serverGroup?.archived_sizes) ? serverGroup.archived_sizes : []);

    // Map each Bilao dish-size strictly to its own canonical DB record (never fall back across sizes)
    bilaoItems.forEach(item => {
      if (getBilaoDishFromItem(item) !== dish) return;
      if (Array.isArray(item.archived_bilao_sizes)) {
        item.archived_bilao_sizes.forEach(s => archivedSizesSet.add(s));
      }

      const size = item.bilao_size || getCartVariantSize({ name: item.name });
      if (!size || !['S', 'M', 'L', 'XL'].includes(size)) return;

      if (item.image_url && !groups[dish].image_url) {
        groups[dish].image_url = item.image_url;
      }
      if (item.tray_image_url && !groups[dish].tray_image_url) {
        groups[dish].tray_image_url = item.tray_image_url;
      }
      if (item.bilao_image_url && !groups[dish].bilao_image_url) {
        groups[dish].bilao_image_url = item.bilao_image_url;
      }

      const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
      const availability = item.availability || 'Available';
      const serverVariant = serverGroup?.variants?.[size] || null;
      const price = Number(item.effective_price ?? item.price ?? serverVariant?.price ?? spec.rates[size]?.price ?? 0);
      const basePrice = Number(item.price ?? serverVariant?.base_price ?? price);
      const dbDesc = String(item.description || serverVariant?.desc || '').trim();
      const desc = dbDesc !== '' ? dbDesc : (spec.rates[size] ? spec.rates[size].desc : '');

      if (!groups[dish].variants[size]) {
        groups[dish].variants[size] = {
          id: Number(item.id),
          name: item.name,
          size,
          price,
          base_price: basePrice,
          is_promo: Boolean(item.is_promo),
          desc,
          stock,
          availability,
          is_archived: Boolean(item.is_archived) || archivedSizesSet.has(size),
          item_raw: item
        };
      }
    });

    // Missing or archived sizes remain explicitly unavailable/archived without cross-size fallback
    const sizes = ['S', 'M', 'L', 'XL'];
    sizes.forEach(sz => {
      const serverVariant = serverGroup?.variants?.[sz] || null;
      const existing = groups[dish].variants[sz];
      const isSizeArchived = archivedSizesSet.has(sz) || Boolean(serverVariant?.is_archived) || !existing;
      if (isSizeArchived) {
        groups[dish].variants[sz] = {
          id: existing ? existing.id : 0,
          name: `${dish} (${sz})`,
          size: sz,
          price: existing ? existing.price : (serverVariant?.price ?? spec.rates[sz]?.price ?? 0),
          base_price: existing ? existing.base_price : (serverVariant?.base_price ?? spec.rates[sz]?.price ?? 0),
          is_promo: false,
          desc: existing ? existing.desc : (spec.rates[sz]?.desc ?? ''),
          stock: 0,
          availability: 'Archived',
          is_archived: true,
          item_raw: null
        };
      }
    });

    // Set group card availability summary (individual size stock is shown inside the modal)
    const allVariants = Object.values(groups[dish].variants);
    const nonArchivedVariants = allVariants.filter(v => !v.is_archived);
    if (nonArchivedVariants.length === 0) {
      delete groups[dish];
      return;
    }
    const hasAvailable = nonArchivedVariants.some(v => v.availability === 'Available' && v.stock > 0);
    const anyAvailableAttr = nonArchivedVariants.some(v => v.availability === 'Available');
    groups[dish].is_available = hasAvailable;
    groups[dish].is_out = !hasAvailable;
    groups[dish].availability_status = !anyAvailableAttr ? 'Unavailable' : (!hasAvailable ? 'Sold Out' : 'Available');
    groups[dish].starting_price = Math.min(...nonArchivedVariants.map(v => Number(v.price || 0)));
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
  const favVariant = (group.variants['S'] && !group.variants['S'].is_archived)
    ? group.variants['S']
    : Object.values(group.variants).find(v => !v.is_archived && v.id > 0);
  const favId = favVariant ? Number(favVariant.id) : 0;
  const isFav = favId > 0 ? appState.favoriteIds.has(favId) : false;
  const stockTagHtml = '<span class="stock-tag">Tray &amp; Bilao</span>';

  const imgUrl = group.bilao_image_url || group.image_url || '../assets/img/logo.png';

  return `
    <article class="food-card ${isOut ? 'out-of-stock' : ''}" onclick="openBilaoModal('${escapeHtml(group.dish)}')">
      <div class="card-img-wrapper">
        <img src="${imgUrl}" alt="${escapeHtml(group.name)}" loading="lazy" decoding="async" onerror="this.src='../assets/img/logo.png'">
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
  const isDrink = item.category_name === 'Drinks';
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
    <article class="food-card ${isOut ? 'out-of-stock' : ''} ${isDrink ? 'drink-card' : ''}" onclick="handleCardClick(${item.id})">
      <div class="card-img-wrapper ${isDrink ? 'drink-photo' : ''}">
        <img src="${item.image_url || item.image || '../assets/img/logo.png'}" alt="${escapeHtml(item.name)}" loading="lazy" decoding="async" onerror="this.src='../assets/img/logo.png'">
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

  if (appState.menuLoadError) {
    grid.innerHTML = `<div style="grid-column:1/-1; padding:32px; text-align:center; border:1px solid var(--border); border-radius:12px;"><h3>Menu unavailable</h3><p>${escapeHtml(appState.menuLoadError)}</p><button type="button" class="btn-primary" onclick="loadMenuItems()">Try Again</button></div>`;
    return;
  }

  const bilaoGroups = buildBilaoGroups();
  const bilaoDishes = Object.values(bilaoGroups);

  const isBilaoSelected = String(appState.activeCategory) === '6' ||
    String(appState.activeCategory).toLowerCase().includes('bilao');
  const isAllSelected = appState.activeCategory === 'all';

  let regularItems = appState.menuItems.filter(i => {
    if (Boolean(i.is_archived)) return false;
    if (isLegacyAssortedOneLiterLine(i, i)) return false;
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
  if (!group) {
    showToast('This dish is no longer available.');
    return;
  }

  // Only non-archived variants may appear as selectable options
  const activeSizes = ['S', 'M', 'L', 'XL'].filter(sz => {
    const v = group.variants[sz];
    return v && !v.is_archived;
  });

  if (activeSizes.length === 0 || group.is_out) {
    showToast(group.availability_status === 'Unavailable' || activeSizes.length === 0 ? 'This dish is currently unavailable.' : 'This dish is currently sold out.');
    return;
  }

  appState.isBilaoModal = true;
  appState.currentBilaoGroup = group;
  appState.currentModalItem = null;
  appState.selectedPackaging = null; // Explicit selection required!
  appState.modalQty = 1;

  // Determine initial size selection: first available active size
  let defaultSize = activeSizes.find(sz => {
    const v = group.variants[sz];
    return v && !v.is_archived && v.availability === 'Available' && remainingMenuStock(v.id) > 0;
  }) || activeSizes[0];

  appState.selectedBilaoSize = defaultSize;
  const initialVariant = group.variants[defaultSize];
  appState.modalUnitPrice = initialVariant ? initialVariant.price : group.starting_price;

  // Setup Packaging Picker UI
  const pkgContainer = document.getElementById('modalPackagingPickerContainer');
  if (pkgContainer) pkgContainer.style.display = 'block';
  document.getElementById('modalDrinkFlavorContainer').style.display = 'none';
  const optTray = document.getElementById('pkgOptionTray');
  const optBilao = document.getElementById('pkgOptionBilao');
  if (optTray) optTray.classList.remove('selected');
  if (optBilao) optBilao.classList.remove('selected');
  const helpText = document.getElementById('packagingHelpText');
  if (helpText) helpText.style.display = 'none';

  // Setup Size Picker UI (archived variants are excluded from selectable options)
  const sizeContainer = document.getElementById('modalSizePickerContainer');
  const sizeGrid = document.getElementById('modalSizeOptions');
  if (sizeContainer) sizeContainer.style.display = 'block';
  const resNotice = document.getElementById('bilaoReservationNotice');
  if (resNotice) {
    resNotice.style.display = activeSizes.some(sz => ['M', 'L', 'XL'].includes(sz)) ? 'block' : 'none';
  }

  if (sizeGrid) {
    sizeGrid.innerHTML = activeSizes.map(sz => {
      const v = group.variants[sz];
      const isSelected = sz === defaultSize;
      const stock = v ? Math.max(0, Math.floor(Number(v.stock ?? 0))) : 0;
      const isVariantAvailable = Boolean(v && !v.is_archived && v.availability === 'Available' && stock > 0);
      const remaining = v ? remainingMenuStock(v.id) : 0;
      const isAvailable = isVariantAvailable && remaining > 0;

      let stockText = '';
      let stockClass = '';
      if (!v || v.is_archived || v.availability !== 'Available') {
        stockText = 'Unavailable';
        stockClass = 'out-of-stock';
      } else if (stock <= 0) {
        stockText = 'Out of Stock';
        stockClass = 'out-of-stock';
      } else if (stock <= 5) {
        stockText = `Only ${stock} left`;
        stockClass = 'low-stock';
      } else {
        stockText = `${stock} pcs left`;
      }
      if (isVariantAvailable && remaining <= 0) {
        stockText += ' (Already in Basket)';
      }

      const sizeLabel = sz === 'S' ? 'Small (S)' : (sz === 'M' ? 'Medium (M)' : (sz === 'L' ? 'Large (L)' : 'Extra Large (XL)'));
      const descLabel = v?.desc || '';

      return `
        <div class="size-option-card ${isSelected ? 'selected' : ''} ${!isAvailable ? 'out-of-stock' : ''}" data-size="${sz}" aria-disabled="${!isVariantAvailable ? 'true' : 'false'}" onclick="selectBilaoModalSize('${sz}')">
          <div class="size-info-col">
            <span class="size-name-tag">${escapeHtml(sizeLabel)}</span>
            ${descLabel ? `<span class="size-serving-tag">${escapeHtml(descLabel)}</span>` : ''}
            <span class="size-price-tag">${formatPeso(v ? v.price : 0)}</span>
            <span class="size-stock-tag ${stockClass}">${escapeHtml(stockText)}</span>
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
  document.getElementById('modalItemImg').src = group.bilao_image_url || group.image_url || '../assets/img/logo.png';
  document.getElementById('modalItemImg').classList.remove('drink-photo');
  const bilaoNotesField = document.getElementById('modalItemNotes');
  if (bilaoNotesField) {
    bilaoNotesField.value = '';
    bilaoNotesField.maxLength = 221;
    bilaoNotesField.placeholder = 'e.g. No onions, extra calamansi (max 221 chars)...';
  }
  const modalQtyInput = document.getElementById('modalQty');
  if (modalQtyInput) {
    modalQtyInput.value = '1';
    modalQtyInput.dataset.dirty = '0';
  }

  document.getElementById('itemDetailModal').classList.add('active');
  syncModalQtyUI();
  window.kkSyncOverlayContext?.();
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

  if (appState.isBilaoModal && appState.currentBilaoGroup) {
    const imgEl = document.getElementById('modalItemImg');
    if (imgEl) {
      imgEl.src = pkg === 'Tray'
        ? (appState.currentBilaoGroup.tray_image_url || appState.currentBilaoGroup.image_url || '../assets/img/logo.png')
        : (appState.currentBilaoGroup.bilao_image_url || appState.currentBilaoGroup.image_url || '../assets/img/logo.png');
    }
  }

  const helpText = document.getElementById('packagingHelpText');
  if (helpText) helpText.style.display = 'none';
  syncModalQtyUI();
}

/**
 * Handle portion size selection in Bilao Modal
 */
function selectBilaoModalSize(sz) {
  if (!appState.isBilaoModal || !appState.currentBilaoGroup) return;
  const group = appState.currentBilaoGroup;
  const variant = group.variants[sz];
  if (!variant || variant.is_archived) {
    showToast('This size is no longer available.', 'warning');
    return;
  }

  const stock = Math.max(0, Math.floor(Number(variant.stock ?? 0)));
  if (variant.availability !== 'Available' || stock <= 0) {
    showToast(variant.availability !== 'Available' ? 'This size is currently unavailable.' : 'This size is currently out of stock.', 'warning');
    return;
  }

  appState.selectedBilaoSize = sz;
  appState.modalUnitPrice = variant.price;
  const allowance = getModalAddAllowance();
  appState.modalQty = Math.max(1, Math.min(appState.modalQty, Math.max(1, allowance.maxAdd)));
  const modalQtyInput = document.getElementById('modalQty');
  if (modalQtyInput) {
    modalQtyInput.value = String(appState.modalQty);
    modalQtyInput.dataset.dirty = '0';
  }

  // Update UI selection on size cards
  const cards = document.querySelectorAll('#modalSizeOptions .size-option-card');
  cards.forEach(c => {
    c.classList.toggle('selected', c.dataset.size === sz);
  });

  syncModalQtyUI();
}

/**
 * Open Item Detail Modal for Regular Items
 */
function handleCardClick(itemId) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(itemId));
  if (!item || Boolean(item.is_archived)) {
    showToast('This item is no longer available.', 'warning');
    return;
  }

  // If item belongs to a Bilao dish, route directly to openBilaoModal
  const bilaoDish = getBilaoDishFromItem(item);
  if (bilaoDish) {
    openBilaoModal(bilaoDish);
    return;
  }

  const isAvailable = item.availability === 'Available';
  const isDrink = item.category_name === 'Drinks';
  const basePool = getStockPoolInfo(item);
  const stock = basePool.poolStock;

  if (stock <= 0 || !isAvailable) {
    showToast(!isAvailable ? 'This item is currently unavailable.' : 'This item is currently sold out.', 'warning');
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
  const resNotice = document.getElementById('bilaoReservationNotice');
  if (resNotice) resNotice.style.display = 'none';
  const flavorContainer = document.getElementById('modalDrinkFlavorContainer');
  if (flavorContainer) flavorContainer.style.display = 'none';

  appState.modalUnitPrice = Number(item.effective_price || item.price);

  document.getElementById('modalItemTitle').textContent = item.name;
  document.getElementById('modalItemDesc').textContent = item.description || '';
  document.getElementById('modalItemPrice').textContent = formatPeso(appState.modalUnitPrice);
  document.getElementById('modalItemImg').src = item.image_url || item.image || '../assets/img/logo.png';
  document.getElementById('modalItemImg').classList.toggle('drink-photo', isDrink);
  const regNotesField = document.getElementById('modalItemNotes');
  if (regNotesField) {
    regNotesField.value = '';
    regNotesField.maxLength = 255;
    regNotesField.placeholder = 'e.g. No onions, extra gravy, less ice (max 255 chars)...';
  }
  const modalQtyInput = document.getElementById('modalQty');
  if (modalQtyInput) {
    modalQtyInput.value = '1';
    modalQtyInput.dataset.dirty = '0';
  }

  document.getElementById('itemDetailModal').classList.add('active');
  syncModalQtyUI();
  window.kkSyncOverlayContext?.();
}

function getModalContext() {
  if (appState.isBilaoModal && appState.currentBilaoGroup) {
    const sz = appState.selectedBilaoSize || 'S';
    const variant = appState.currentBilaoGroup.variants[sz] || null;
    const item = variant ? appState.menuItems.find(entry => Number(entry.id) === Number(variant.id)) : null;
    return {
      item,
      id: variant?.id ?? null,
      variant,
      variantSize: sz,
      packaging: appState.selectedPackaging || null,
      notes: (document.getElementById('modalItemNotes')?.value || '').trim()
    };
  }
  const item = appState.currentModalItem;
  return {
    item,
    id: item?.id ?? null,
    variant: null,
    variantSize: null,
    packaging: null,
    notes: (document.getElementById('modalItemNotes')?.value || '').trim()
  };
}

function getModalAddAllowance() {
  const ctx = getModalContext();
  if (!ctx.item || ctx.id == null) {
    return window.KKStockQty.getAddAllowance({ poolStock: 0, usedInBasketForPool: 0, perLineCap: window.KKStockQty.ONLINE_MAX_LINE_QTY, existingMatchingLineQty: 0 });
  }
  const pool = getStockPoolInfo(ctx.item);
  const used = usedInCartForPool(pool.poolKey, -1);
  // Only count matching existing line when Bilao packaging is chosen
  const canMatchExactLine = !appState.isBilaoModal || Boolean(ctx.packaging);
  const existing = canMatchExactLine
    ? appState.cart.find(i =>
        Number(i.id) === Number(ctx.id) &&
        (i.variantSize || null) === (ctx.variantSize || null) &&
        (i.packaging || null) === (ctx.packaging || null) &&
        (i.notes || '') === (ctx.notes || '')
      )
    : null;

  return window.KKStockQty.getAddAllowance({
    poolStock: pool.isAvailable ? pool.poolStock : 0,
    usedInBasketForPool: used,
    perLineCap: window.KKStockQty.ONLINE_MAX_LINE_QTY,
    existingMatchingLineQty: existing ? Number(existing.qty || 0) : 0
  });
}

function setModalInlineFeedback(message = '', tone = 'info') {
  const el = document.getElementById('modalStockFeedback');
  if (!el) return;
  if (!message) {
    el.textContent = '';
    el.hidden = true;
    el.className = 'kk-inline-feedback';
    return;
  }
  el.textContent = message;
  el.hidden = false;
  el.className = `kk-inline-feedback is-${tone}`;
}

function syncModalQtyUI(explicitMessage = '', explicitTone = 'warning') {
  const ctx = getModalContext();
  const allowance = getModalAddAllowance();
  const modalQtyEl = document.getElementById('modalQty');
  const minusBtn = document.getElementById('modalQtyMinus');
  const plusBtn = document.getElementById('modalQtyPlus');
  const addBtn = document.getElementById('modalAddOrderBtn') || document.querySelector('#itemDetailModal .btn-primary');

  if (allowance.maxAdd > 0 && appState.modalQty > allowance.maxAdd) {
    appState.modalQty = allowance.maxAdd;
  }
  if (appState.modalQty < 1) appState.modalQty = 1;

  if (modalQtyEl && modalQtyEl.dataset.dirty !== '1') {
    modalQtyEl.value = String(appState.modalQty);
  }
  const priceEl = document.getElementById('modalItemPrice');
  if (priceEl) {
    priceEl.textContent = formatPeso((appState.modalUnitPrice || 0) * appState.modalQty);
  }
  if (minusBtn) minusBtn.disabled = appState.modalQty <= 1;
  if (plusBtn) plusBtn.disabled = allowance.maxAdd <= 0 || appState.modalQty >= allowance.maxAdd;

  if (addBtn) {
    const isVariantUnavailable = appState.isBilaoModal
      ? (!ctx.variant || ctx.variant.is_archived || ctx.variant.availability !== 'Available' || ctx.variant.stock <= 0)
      : (!ctx.item || ctx.item.is_archived || ctx.item.availability !== 'Available' || allowance.poolStock <= 0);
    const canOrder = !isVariantUnavailable && allowance.maxAdd > 0;
    addBtn.disabled = !canOrder;
    if (!canOrder) {
      if (isVariantUnavailable) {
        addBtn.textContent = (ctx.variant?.availability || ctx.item?.availability) !== 'Available' ? 'Unavailable' : 'Sold Out';
      } else if (allowance.limitedByLineCap && allowance.existingMatchingLineQty >= allowance.perLineCap) {
        addBtn.textContent = 'Line Limit Reached';
      } else {
        addBtn.textContent = 'Already in Basket';
      }
    } else {
      addBtn.textContent = 'Add to Order';
    }
  }

  if (explicitMessage) {
    setModalInlineFeedback(explicitMessage, explicitTone);
  } else if (allowance.poolStock > 0 && allowance.maxAdd <= 0) {
    setModalInlineFeedback(window.KKStockQty.formatAddLimitMessage(allowance), 'warning');
  } else if (allowance.maxAdd > 0 && appState.modalQty >= allowance.maxAdd && (allowance.usedInBasketForPool > 0 || allowance.limitedByLineCap)) {
    setModalInlineFeedback(window.KKStockQty.formatAddLimitMessage(allowance), 'warning');
  } else if (allowance.usedInBasketForPool > 0 && allowance.maxAdd > 0) {
    setModalInlineFeedback(`${allowance.usedInBasketForPool} in basket · You can add up to ${allowance.maxAdd} more.`, 'info');
  } else {
    setModalInlineFeedback('');
  }
}

function commitModalQtyInput(opts = {}) {
  const modalQtyEl = document.getElementById('modalQty');
  if (!modalQtyEl) return;
  if (!opts.force && modalQtyEl.dataset.dirty !== '1') return;
  modalQtyEl.dataset.dirty = '0';

  const allowance = getModalAddAllowance();
  if (allowance.maxAdd <= 0) {
    appState.modalQty = 1;
    modalQtyEl.value = '1';
    syncModalQtyUI(window.KKStockQty.formatAddLimitMessage(allowance), 'warning');
    return;
  }

  const parsed = window.KKStockQty.parseQuantityInput(modalQtyEl.value);
  if (parsed.kind === 'empty') {
    modalQtyEl.value = String(appState.modalQty);
    syncModalQtyUI();
    return;
  }
  if (parsed.kind === 'decimal' || parsed.kind === 'invalid') {
    modalQtyEl.value = String(appState.modalQty);
    syncModalQtyUI('Enter a whole number.', 'error');
    return;
  }
  if (parsed.kind === 'below_min') {
    appState.modalQty = 1;
    modalQtyEl.value = '1';
    syncModalQtyUI('Quantity must be at least 1.', 'error');
    return;
  }
  if (parsed.value > allowance.maxAdd) {
    appState.modalQty = allowance.maxAdd;
    modalQtyEl.value = String(allowance.maxAdd);
    syncModalQtyUI(window.KKStockQty.formatAddLimitMessage(allowance), 'warning');
    return;
  }
  appState.modalQty = parsed.value;
  modalQtyEl.value = String(parsed.value);
  syncModalQtyUI();
}

function adjustModalQty(delta) {
  commitModalQtyInput();
  const allowance = getModalAddAllowance();
  if (delta > 0 && appState.modalQty + delta > allowance.maxAdd) {
    appState.modalQty = Math.max(1, allowance.maxAdd);
    syncModalQtyUI(window.KKStockQty.formatAddLimitMessage(allowance), 'warning');
    return;
  }
  const upperBound = Math.max(1, allowance.maxAdd || 1);
  appState.modalQty = Math.max(1, Math.min(upperBound, appState.modalQty + delta));
  const modalQtyInput = document.getElementById('modalQty');
  if (modalQtyInput) {
    modalQtyInput.value = String(appState.modalQty);
    modalQtyInput.dataset.dirty = '0';
  }
  syncModalQtyUI();
}

function confirmAddItemFromModal() {
  commitModalQtyInput();
  if (appState.isBilaoModal && appState.currentBilaoGroup) {
    const group = appState.currentBilaoGroup;

    // Packaging selection is mandatory!
    if (!appState.selectedPackaging) {
      const helpText = document.getElementById('packagingHelpText');
      if (helpText) {
        helpText.style.display = 'block';
        helpText.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
      setModalInlineFeedback('Please select Tray or Bilao packaging first.', 'error');
      return;
    }

    const sz = appState.selectedBilaoSize || 'S';
    const variant = group.variants[sz];
    if (!variant || variant.is_archived || variant.availability !== 'Available' || variant.stock <= 0) {
      setModalInlineFeedback('This size is unavailable.', 'error');
      return;
    }

    const pkg = appState.selectedPackaging; // 'Tray' or 'Bilao'
    const finalName = `${group.dish} ${pkg} (${sz})`;
    const notes = document.getElementById('modalItemNotes').value.trim();
    if (notes.length > 221) {
      setModalInlineFeedback('Notes must be 221 characters or fewer.', 'error');
      return;
    }
    const unitPrice = variant.price;

    const added = addItemToCart(variant.id, finalName, unitPrice, appState.modalQty, notes, sz, pkg);
    if (added) {
      closeModal('itemDetailModal');
      showToast('Added to basket.', 'success', { title: 'Basket Updated', dedupeKey: 'basket-add', duration: 2400 });
    }
    return;
  }

  // Regular item flow
  if (!appState.currentModalItem) return;
  const item = appState.currentModalItem;
  const isAvailable = item.availability === 'Available';
  if (item.is_archived || !isAvailable) {
    setModalInlineFeedback('This item is currently unavailable.', 'error');
    return;
  }

  const pool = getStockPoolInfo(item);
  if (!pool.isAvailable || pool.poolStock <= 0) {
    setModalInlineFeedback('This item is currently sold out.', 'error');
    return;
  }

  const notes = document.getElementById('modalItemNotes').value.trim();
  if (notes.length > 255) {
    setModalInlineFeedback('Notes must be 255 characters or fewer.', 'error');
    return;
  }
  const unitPrice = Number(item.effective_price || item.price);
  const added = addItemToCart(item.id, item.name, unitPrice, appState.modalQty, notes, null, null);
  if (added) {
    closeModal('itemDetailModal');
    showToast('Added to basket.', 'success', { title: 'Basket Updated', dedupeKey: 'basket-add', duration: 2400 });
  }
}

/**
 * Quick Add ("+" button)
 */
function quickAddToCart(itemId) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(itemId));
  if (!item || Boolean(item.is_archived)) {
    showToast('This item is no longer available.', 'warning');
    return;
  }

  // For Bilao dishes, quick-add (+) must open the portion & packaging selector
  const bilaoDish = getBilaoDishFromItem(item);
  if (bilaoDish) {
    openBilaoModal(bilaoDish);
    return;
  }

  const stock = Math.max(0, Number(item.stock ?? item.pcs_available ?? 0));
  const isAvailable = item.availability === 'Available';
  if (item.is_archived || stock <= 0 || !isAvailable) {
    showToast(!isAvailable ? 'This item is currently unavailable.' : 'This item is currently sold out.', 'warning');
    return;
  }

  const unitPrice = Number(item.effective_price || item.price);
  const added = addItemToCart(item.id, item.name, unitPrice, 1, '', null, null);
  if (added) {
    showToast('Added to basket.', 'success', { title: 'Basket Updated', dedupeKey: 'basket-add', duration: 2400 });
  }
}

/**
 * Core Cart Management
 */
function addItemToCart(id, name, price, qty, notes, variantSize = null, packaging = null) {
  const item = appState.menuItems.find(i => Number(i.id) === Number(id));
  if (!item || item.is_archived || item.availability !== 'Available') {
    showToast('Item unavailable. Refresh the menu.', 'warning');
    return false;
  }
  const bilaoDish = getBilaoDishFromItem(item);
  if (bilaoDish || variantSize || packaging) {
    const groups = buildBilaoGroups();
    const group = bilaoDish ? groups[bilaoDish] : null;
    const sz = variantSize || getCartVariantSize({ name, variantSize });
    const variant = (group && sz) ? group.variants[sz] : null;
    if (!group || !variant || variant.is_archived || Number(variant.id) !== Number(id) || (packaging !== 'Tray' && packaging !== 'Bilao') || variant.availability !== 'Available' || Number(variant.stock) <= 0) {
      showToast('Selected size or packaging is no longer available.', 'warning');
      return false;
    }
  }
  const pool = getStockPoolInfo(item);
  if (!pool.isAvailable || pool.poolStock <= 0) {
    showToast('This item is currently sold out.', 'warning');
    return false;
  }

  const existing = appState.cart.find(i =>
    Number(i.id) === Number(id) &&
    (i.variantSize || null) === (variantSize || null) &&
    (i.packaging || null) === (packaging || null) &&
    (i.notes || '') === (notes || '')
  );

  const usedInBasket = usedInCartForPool(pool.poolKey, -1);
  const allowance = window.KKStockQty.getAddAllowance({
    poolStock: pool.poolStock,
    usedInBasketForPool: usedInBasket,
    perLineCap: window.KKStockQty.ONLINE_MAX_LINE_QTY,
    existingMatchingLineQty: existing ? Number(existing.qty || 0) : 0
  });

  if (!Number.isInteger(qty) || qty < 1 || qty > allowance.maxAdd) {
    if (document.getElementById('itemDetailModal')?.classList.contains('active')) {
      syncModalQtyUI(window.KKStockQty.formatAddLimitMessage(allowance), 'warning');
    } else {
      showStockLimit(item, allowance.remainingStock, allowance);
    }
    return false;
  }

  appState.cartStockBanner = '';
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

function clearFoodBasket() {
  if (!appState.cart.length) return;
  appState.cart = [];
  appState.cartRowFeedback = {};
  appState.cartStockBanner = '';
  saveCart();
  const drawerOpen = Boolean(document.getElementById('cartDrawerSheet')?.classList.contains('active'));
  if (!drawerOpen) {
    window.kkNotify('All items have been removed from your basket.', 'info', { title: 'Basket cleared', dedupeKey: 'basket-cleared' });
  }
}

function updateCartUI() {
  const clearButton = document.getElementById('clearBasketBtn');
  if (clearButton) clearButton.disabled = appState.cart.length === 0;
  const totalCount = appState.cart.reduce((sum, i) => sum + Number(i.qty || 0), 0);
  const totalAmount = appState.cart.reduce((sum, i) => sum + (Number(i.price || 0) * Number(i.qty || 0)), 0);

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

function renderCartBanner() {
  const banner = document.getElementById('cartStockBanner');
  if (!banner) return;
  if (!appState.cartStockBanner) {
    banner.textContent = '';
    banner.hidden = true;
    return;
  }
  banner.textContent = appState.cartStockBanner;
  banner.hidden = false;
}

function renderCartDrawerItems(totalAmount) {
  const container = document.getElementById('cartDrawerItems');
  if (!container) return;

  renderCartBanner();

  const activeEl = document.activeElement;
  const focusedCartIdx = (activeEl && container.contains(activeEl) && activeEl.classList.contains('cart-qty-input'))
    ? Number(activeEl.dataset.cartIndex)
    : -1;
  const focusedDraft = focusedCartIdx >= 0 ? activeEl.value : null;
  const focusedDirty = focusedCartIdx >= 0 ? activeEl.dataset.dirty : null;

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
    const minBanner = document.getElementById('cartMinOrderBanner');
    if (minBanner) {
      minBanner.textContent = '';
      minBanner.hidden = true;
    }
    const subEl = document.getElementById('drawerSubtotal');
    const totEl = document.getElementById('drawerTotal');
    if (subEl) subEl.textContent = formatPeso(0);
    if (totEl) totEl.textContent = formatPeso(0);
    return;
  }

  const checkBtn = document.getElementById('btnProceedCheckout');
  if (checkBtn) checkBtn.disabled = false;

  container.innerHTML = appState.cart.map((item, idx) => {
    const allowance = getCartLineAllowance(idx);
    const maxAllowed = Math.max(1, allowance.effectiveMax);
    const atMax = allowance.effectiveMax > 0 && Number(item.qty) >= allowance.effectiveMax;
    const feedback = appState.cartRowFeedback?.[idx] || null;
    const feedbackId = `cartQtyFeedback-${idx}`;
    return `
    <div class="cart-item-row" data-cart-index="${idx}">
      <div class="cart-item-main">
        <div class="cart-item-info">
          <strong>${escapeHtml(item.name)}</strong>
          <span>${formatPeso(item.price)} each</span>
          <div style="margin-top: 2px;">
            ${item.notes ? `<div class="item-notes-text">Note: ${escapeHtml(item.notes)}</div>` : ''}
            <button type="button" style="border: none; background: none; color: var(--primary); font-size: 0.75rem; cursor: pointer; text-decoration: underline; padding: 0; font-weight: 600;" onclick="editCartItemNote(${idx})">${item.notes ? 'Edit note' : 'Add note'}</button>
          </div>
        </div>
        <strong id="cartLineTotal-${idx}" style="color: var(--dark);">${formatPeso(item.price * item.qty)}</strong>
      </div>

      <div class="cart-item-controls">
        <div class="qty-pill">
          <button type="button" class="qty-btn" aria-label="Decrease quantity for ${escapeHtml(item.name)}" onclick="changeCartItemQty(${idx}, -1)">-</button>
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="cart-qty-input" data-cart-index="${idx}" value="${item.qty}" aria-label="Quantity for ${escapeHtml(item.name)}" aria-describedby="${feedbackId}" oninput="handleCartQtyInput(${idx}, this)" onblur="commitCartQtyInput(${idx}, this)" onkeydown="handleCartQtyKeydown(event, ${idx}, this)">
          <button type="button" class="qty-btn" aria-label="Increase quantity for ${escapeHtml(item.name)}" ${atMax ? 'disabled aria-disabled="true" title="Maximum available reached"' : ''} onclick="changeCartItemQty(${idx}, 1)">+</button>
        </div>
        <button type="button" style="border: none; background: none; color: var(--danger); font-size: 0.8rem; cursor: pointer; font-weight: 700;" onclick="removeCartItem(${idx})">
          Remove
        </button>
      </div>
      <div id="${feedbackId}" class="cart-qty-feedback ${feedback ? `is-${feedback.tone}` : ''}" role="status" aria-live="polite" ${feedback ? '' : 'hidden'}>${feedback ? escapeHtml(feedback.text) : ''}</div>
    </div>
  `;
  }).join('');

  if (focusedCartIdx >= 0 && focusedCartIdx < appState.cart.length && focusedDirty === '1') {
    const restored = container.querySelector(`.cart-qty-input[data-cart-index="${focusedCartIdx}"]`);
    if (restored) {
      restored.value = focusedDraft;
      restored.dataset.dirty = '1';
      restored.focus();
    }
  }

  const computedTotal = typeof totalAmount === 'number'
    ? totalAmount
    : appState.cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
  document.getElementById('drawerSubtotal').textContent = formatPeso(computedTotal);
  document.getElementById('drawerTotal').textContent = formatPeso(computedTotal);

  const minBanner = document.getElementById('cartMinOrderBanner');
  if (minBanner) {
    const minCheck = validateOnlineOrderMinimum();
    if (!minCheck.ok) {
      minBanner.textContent = `${minCheck.message} Add ${formatPeso(minCheck.remaining)} more to check out.`;
      minBanner.hidden = false;
    } else {
      minBanner.textContent = '';
      minBanner.hidden = true;
    }
  }
}

function handleCartQtyInput(index, inputEl) {
  const line = appState.cart[index];
  if (!line || !inputEl) return;
  inputEl.dataset.dirty = '1';
  // Clear transient invalid/below-min messages while user is actively typing
  const prevFeedback = appState.cartRowFeedback?.[index];
  if (prevFeedback && prevFeedback.tone === 'error') {
    delete appState.cartRowFeedback[index];
    const fbEl = document.getElementById(`cartQtyFeedback-${index}`);
    if (fbEl) {
      fbEl.textContent = '';
      fbEl.hidden = true;
      fbEl.className = 'cart-qty-feedback';
    }
  }
}

function handleCartQtyKeydown(event, index, inputEl) {
  if (event.isComposing) return;
  if (event.key === 'Enter') {
    event.preventDefault();
    commitCartQtyInput(index, inputEl, { force: true });
    if (inputEl && typeof inputEl.blur === 'function') {
      inputEl.blur();
    }
  }
}

function commitCartQtyInput(index, inputEl, opts = {}) {
  const line = appState.cart[index];
  if (!line || !inputEl) return;
  if (!opts.force && inputEl.dataset.dirty !== '1') {
    return;
  }
  inputEl.dataset.dirty = '0';

  const { pool, usedInOtherRows, availableForRow, effectiveMax } = getCartLineAllowance(index);
  const res = window.KKStockQty.resolveRowReplace({
    rawInput: inputEl.value,
    currentQty: line.qty,
    poolStock: pool.isAvailable ? pool.poolStock : 0,
    usedInOtherRowsForPool: usedInOtherRows,
    perLineCap: window.KKStockQty.ONLINE_MAX_LINE_QTY
  });

  if (res.qty <= 0) {
    reconcileCartWithMenuStock(true);
    return;
  }

  line.qty = res.qty;
  inputEl.value = String(res.qty);

  if (res.message) {
    appState.cartRowFeedback[index] = {
      text: res.message,
      tone: (res.reason === 'invalid' || res.reason === 'below_min') ? 'error' : 'warning'
    };
  } else if (res.atMax) {
    appState.cartRowFeedback[index] = {
      text: res.effectiveMax < availableForRow
        ? `Maximum ${res.effectiveMax} per item line reached.`
        : `Maximum available: ${res.effectiveMax}.`,
      tone: 'info'
    };
  } else {
    delete appState.cartRowFeedback[index];
  }

  appState.cartStockBanner = '';
  saveCart();
}

function flushPendingCartQtyEdits() {
  const container = document.getElementById('cartDrawerItems');
  if (!container) return;
  container.querySelectorAll('.cart-qty-input[data-dirty="1"]').forEach(inp => {
    const idx = Number(inp.dataset.cartIndex);
    if (Number.isInteger(idx) && appState.cart[idx]) {
      commitCartQtyInput(idx, inp, { force: true });
    }
  });
}

function changeCartItemQty(index, delta) {
  flushPendingCartQtyEdits();
  const line = appState.cart[index];
  if (!line) return;
  appState.cartStockBanner = '';

  const { availableForRow, effectiveMax } = getCartLineAllowance(index);

  if (delta > 0) {
    if (Number(line.qty) + delta > effectiveMax) {
      appState.cartRowFeedback[index] = {
        text: effectiveMax < availableForRow
          ? `Maximum ${effectiveMax} per item line reached (${availableForRow} in stock).`
          : `Maximum available: ${effectiveMax}.`,
        tone: 'warning'
      };
      saveCart();
      return;
    }
    line.qty = Number(line.qty) + delta;
    if (line.qty >= effectiveMax) {
      appState.cartRowFeedback[index] = {
        text: effectiveMax < availableForRow
          ? `Maximum ${effectiveMax} per item line reached.`
          : `Maximum available: ${effectiveMax}.`,
        tone: 'info'
      };
    } else {
      delete appState.cartRowFeedback[index];
    }
    saveCart();
    return;
  }

  // Decreasing quantity
  line.qty = Number(line.qty) + delta;
  if (line.qty <= 0) {
    removeCartItem(index);
    return;
  }
  delete appState.cartRowFeedback[index];
  saveCart();
}

function removeCartItem(index) {
  if (index < 0 || index >= appState.cart.length) return;
  appState.cart.splice(index, 1);
  const shiftedFeedback = {};
  Object.keys(appState.cartRowFeedback || {}).forEach(k => {
    const oldIdx = Number(k);
    if (oldIdx < index) shiftedFeedback[oldIdx] = appState.cartRowFeedback[k];
    else if (oldIdx > index) shiftedFeedback[oldIdx - 1] = appState.cartRowFeedback[k];
  });
  appState.cartRowFeedback = shiftedFeedback;
  appState.cartStockBanner = '';
  saveCart();
}

async function editCartItemNote(index) {
  flushPendingCartQtyEdits();
  const item = appState.cart[index];
  if (!item) return;
  const maxLen = item.packaging ? 221 : 255;
  const promptMsg = item.packaging
    ? `Edit special instructions for ${item.name} (Max ${maxLen} characters for bilao/tray items):`
    : `Edit special instructions for ${item.name} (Max ${maxLen} characters):`;
  const newNote = await window.kkPrompt({
    title: 'Special Instructions',
    message: promptMsg,
    defaultValue: item.notes || '',
    placeholder: 'e.g. Extra gravy, less ice...',
    maxLength: maxLen,
    confirmText: 'Save Note',
    cancelText: 'Cancel'
  });
  if (newNote === null) return;
  const trimmed = newNote.trim();
  if (trimmed.length > maxLen) {
    window.kkNotify(`Special instructions are too long (${trimmed.length} characters). The maximum allowed is ${maxLen} characters.`, 'warning', 'Note Too Long');
    return;
  }
  item.notes = trimmed;
  saveCart();
}

/**
 * Open & Close Slide-Up Drawer
 */
async function openCartDrawer() {
  document.getElementById('drawerBackdrop').classList.add('active');
  document.getElementById('cartDrawerSheet').classList.add('active');
  window.kkSyncOverlayContext?.();
  if (appState.cart.length > 0) {
    await refreshMenuAndValidateCart(true);
  }
}

function closeCartDrawer() {
  flushPendingCartQtyEdits();
  document.getElementById('drawerBackdrop').classList.remove('active');
  document.getElementById('cartDrawerSheet').classList.remove('active');
  window.kkSyncOverlayContext?.();
}

/**
 * Checkout Wizard & OTP Flow
 */
async function proceedToCheckout() {
  flushPendingCartQtyEdits();
  const cartCheck = await refreshMenuAndValidateCart(true);
  if (!appState.cart.length) {
    return;
  }
  if (cartCheck && (cartCheck.removedCount > 0 || cartCheck.adjustedCount > 0)) {
    // Keep the basket drawer visible so the customer can review updated items/quantities
    return;
  }

  const minCheck = validateOnlineOrderMinimum();
  if (!minCheck.ok) {
    renderCartDrawerItems();
    window.kkNotify(`${minCheck.message} Add ${formatPeso(minCheck.remaining)} more to check out.`, 'warning');
    return;
  }

  closeCartDrawer();

  // Verify live session so stale localStorage doesn't open checkout without an active PHP session
  if (appState.currentUser) {
    const session = await syncCustomerSession();
    if (!session.authenticated && !session.networkError) {
      window.kkNotify('Your session has expired. Please sign in again to continue.', 'warning', { title: 'Sign In Required' });
      openOtpModal();
      return;
    }
  } else {
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
    window.kkNotify('Please enter a valid email address.', 'warning');
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
      window.kkNotify(result.message || 'Unable to send OTP.', 'error');
    }
  } catch (err) {
    window.kkNotify('Unable to send verification code. Please check your network or try again.', 'error');
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
    window.kkNotify('Enter the 6-digit code received in your email.', 'warning');
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
      showToast('Verified.');
      openCheckoutModal();
    } else {
      window.kkNotify(result.message || 'Invalid code.', 'error');
    }
  } catch (_) {
    window.kkNotify('Unable to verify OTP.', 'error');
  }
}

function setCheckoutValidationError(message) {
  const alertEl = document.getElementById('checkoutValidationAlert');
  if (!alertEl) return;
  if (!message) {
    alertEl.style.display = 'none';
    alertEl.textContent = '';
  } else {
    alertEl.style.display = 'block';
    alertEl.textContent = message;
  }
}

function selectCheckoutFulfillment(mode) {
  const normalized = (mode === 'delivery' || mode === 'pickup') ? mode : '';
  appState.fulfillmentMode = normalized;
  document.querySelectorAll('#checkoutFulfillmentGroup .fulfillment-btn').forEach(btn => {
    const isMatch = btn.dataset.mode === normalized;
    btn.classList.toggle('active', isMatch);
    btn.setAttribute('aria-checked', isMatch ? 'true' : 'false');
  });
  const helpText = document.getElementById('fulfillmentHelpText');
  if (helpText) {
    if (normalized === 'delivery') {
      helpText.textContent = 'Delivery selected. Enter your delivery address and select your exact destination on the map.';
    } else if (normalized === 'pickup') {
      helpText.textContent = 'Pickup selected. You will pick up your order at Kenji\'s Kitchen.';
    } else {
      helpText.textContent = 'Select Delivery or Pickup to continue.';
    }
  }
  setCheckoutValidationError('');
  updateFulfillmentDetails();
}

function clientPointInPolygon(lat, lng, ring) {
  if (!Array.isArray(ring) || ring.length < 3) return false;
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const xi = Number(ring[i][0]);
    const yi = Number(ring[i][1]);
    const xj = Number(ring[j][0]);
    const yj = Number(ring[j][1]);
    if (Math.abs(xi - lng) < 1e-9 && Math.abs(yi - lat) < 1e-9) return true;
    const intersect = ((yi > lat) !== (yj > lat)) &&
      (lng < (xj - xi) * (lat - yi) / ((yj - yi) || 1e-15) + xi);
    if (intersect) inside = !inside;
  }
  return inside;
}

function clientDistanceToPolygonEdgeMeters(lat, lng, ring) {
  if (!Array.isArray(ring) || ring.length < 2) return Infinity;
  const mLat = 110574.27;
  const mLng = 111319.49 * Math.cos(lat * Math.PI / 180);
  let minSq = Infinity;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const x1 = (Number(ring[j][0]) - lng) * mLng;
    const y1 = (Number(ring[j][1]) - lat) * mLat;
    const x2 = (Number(ring[i][0]) - lng) * mLng;
    const y2 = (Number(ring[i][1]) - lat) * mLat;
    const dx = x2 - x1;
    const dy = y2 - y1;
    const lenSq = dx * dx + dy * dy;
    let distSq;
    if (lenSq <= 1e-12) {
      distSq = x1 * x1 + y1 * y1;
    } else {
      const t = Math.max(0, Math.min(1, -(x1 * dx + y1 * dy) / lenSq));
      const px = x1 + t * dx;
      const py = y1 + t * dy;
      distSq = px * px + py * py;
    }
    if (distSq < minSq) minSq = distSq;
  }
  return Math.sqrt(minSq);
}

/**
 * Local client-side feedback helper.
 * Browser checks are for immediate UI feedback only and NEVER grant final server verification without a server token.
 */
function checkClientLagroAddress(rawAddress, rawBarangay = '', rawLat = null, rawLng = null) {
  const clean = String(rawAddress || '').replace(/\s+/g, ' ').trim();
  const areaCode = String(rawBarangay || '').trim().toUpperCase();
  const hasPin = rawLat !== null && rawLat !== '' && rawLng !== null && rawLng !== '' &&
    Number.isFinite(Number(rawLat)) && Number.isFinite(Number(rawLng));

  const genericFairviewClean = clean.replace(/\b(sm\s+(?:city\s+)?fairview|ayala\s+malls?\s+fairview\s+terraces|fairview\s+terraces|national\s+university\s+fairview|nu\s+fairview|neu\s+fairview|toyota\s+fairview)\b/gi, 'LagroLandmark');
  const outsidePatterns = [
    /\bsti\s*fairview\b/i,
    /\bfeu[\s\-]*(nrmf|fairview|diliman)\b/i,
    /\b(north|west|east|south)\s+fairview\b/i,
    /\b(barangay|brgy\.?|bgy\.?)\s+fairview\b|\bfairview\s+(subd\.?|subdivision|park|proper)\b/i,
    /\bfcm\b|\bfairview\s+center\s+mall\b/i,
    /\bdahlia\s+(ave|avenue|st|street|fairview)\b|\bfev\b|\bfairview\s+general\b/i,
    /\b(barangay|brgy\.?|bgy\.?)\s+commonwealth\b|\bcommonwealth\s+(heights|market)\b/i,
    /\bbatasan\b|\bpayatas\b|\bbagong\s+silang\b|\bholy\s+spirit\b/i,
    /\bcamarin\b|\bdeparo\b|\bllano\b|\btala\b|\bmalaria\b|\bamparo\b|\bzabarte\b|\bcaloocan\b/i,
    /\bsan\s+jose\s+del\s+monte\b|\bsjdm\b|\btungko\b|\bmuzon\b|\bbulacan\b|\bsapang\s+palay\b/i,
    /\bnovaliches\s+proper\b|\bbayan\s+novaliches\b|\b(robinsons|sm(\s+city)?)\s+novaliches\b|\bsusano\s+(rd|road)\b|\bsanta\s+monica\b|\bsta\.?\s*monica\b|\bsanta\s+lucia\b|\bsta\.?\s*lucia\b/i,
    /\bkaligayahan\b|\bpasong\s+putik\b|\bsan\s+bartolome\b|\bgulod\b|\bsauyo\b|\bbagbag\b|\btalipapa\b/i,
    /\btandang\s+sora\b|\bculiat\b|\bdiliman\b|\bcubao\b|\bmanila\b|\bmakati\b|\bpasig\b|\btaguig\b|\bmarikina\b|\bvalenzuela\b/i,
    /\boutside\s+(greater\s+)?lagro\b/i
  ];

  const hasOutsideText = outsidePatterns.some(p => p.test(clean)) || /\bfairview\b/i.test(genericFairviewClean);
  const hasExplicitLagroText = /\b(barangay\s+greater\s+lagro|brgy\.?\s+greater\s+lagro|greater\s+lagro|lagro\s+subdivision|lagro\s+subd\.?|brgy\.?\s+lagro|barangay\s+lagro)\b/i.test(clean);
  const landmarkSpoof = /\btapat\s+(ni|ng)\b/i.test(clean) ||
    /\b(near|malapit\s+sa|across|before|after|past|lampas\s+ng|boundary\s+of|going\s+to|papuntang|from|galing|beside|katabi\s+ng)\s+(brgy\.?\s*|barangay\s*)?(greater\s+)?lagro\b/i.test(clean);

  if (hasOutsideText && (hasExplicitLagroText || areaCode === 'BRGY_GREATER_LAGRO')) {
    return {
      status: 'NEEDS_CONFIRMATION',
      headline: 'Address and location need confirmation.',
      reason_code: 'CONFLICTING_BARANGAY',
      eligible_free: false,
      eligible_outside: false,
      message: 'Address and location need confirmation: Conflicting barangay or locality details detected (outside area vs. Greater Lagro). Remove the conflicting locality and confirm your pin on the map.'
    };
  }

  if (landmarkSpoof) {
    return {
      status: 'NEEDS_CONFIRMATION',
      headline: 'Address and location need confirmation.',
      reason_code: 'LANDMARK_SPOOF',
      eligible_free: false,
      eligible_outside: false,
      message: 'Address and location need confirmation: Mentioning a landmark or directional reference near Lagro cannot establish coverage. Enter your exact House/Block/Lot and Street and select your location on the map.'
    };
  }

  if (!hasPin) {
    return {
      status: 'SELECT_LOCATION',
      headline: 'Select your delivery location.',
      reason_code: 'PIN_REQUIRED',
      eligible_free: false,
      eligible_outside: false,
      message: clean
        ? 'Select or confirm your delivery pin on the map to check coverage. Typed text alone cannot verify free delivery.'
        : 'Select your delivery location on the map and enter your complete House/Block/Lot and Street address.'
    };
  }

  return {
    status: 'CHECKING',
    headline: 'Checking coverage…',
    reason_code: 'CHECKING',
    eligible_free: false,
    eligible_outside: false,
    message: 'Checking delivery coverage for your selected destination…'
  };
}

async function ensureDeliveryBoundaryLoaded() {
  const cov = appState.deliveryCoverage;
  if (cov.boundaryData) return cov.boundaryData;
  if (cov.boundaryLoadPromise) return cov.boundaryLoadPromise;

  cov.boundaryLoadPromise = (async () => {
    try {
      const res = await fetch(`${API_ROOT}/delivery_areas.php`, { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || data.status !== 'success' || !data.boundary?.geometry) {
        throw new Error(data.message || 'Unable to load delivery coverage boundary.');
      }
      cov.boundaryData = data;
      cov.boundaryVersion = data.boundary.boundary_version || '';
      cov.boundaryLoadError = false;
      return data;
    } catch (err) {
      cov.boundaryLoadError = true;
      cov.boundaryLoadPromise = null;
      throw err;
    }
  })();

  return cov.boundaryLoadPromise;
}

function createPinDivIcon(kind, statusClass = '') {
  if (!window.L) return null;
  if (kind === 'store') {
    return window.L.divIcon({
      className: '',
      html: '<div class="kk-map-pin-store" title="Kenji\'s Kitchen (Greater Lagro)"><i class="bi bi-shop"></i></div>',
      iconSize: [30, 30],
      iconAnchor: [15, 15]
    });
  }
  return window.L.divIcon({
    className: '',
    html: `<div class="kk-map-pin-dest ${statusClass}" title="Selected delivery destination"><i class="bi bi-geo-alt-fill"></i></div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 30]
  });
}

function isStoreDeliveryAddress(address, store) {
  const normalize = value => String(value || '').toLowerCase()
    .replace(/\bblock\b/g, 'blk').replace(/\bbarangay\b/g, 'brgy')
    .replace(/\bstreet\b/g, 'st').replace(/\bphilippines\b/g, '')
    .replace(/[^a-z0-9]+/g, ' ').trim();
  return Boolean(store?.address) && normalize(address) === normalize(store.address);
}
function deliveryPinIsStore() {
  const cov = appState.deliveryCoverage;
  const store = cov.boundaryData?.store_location;
  return isStoreDeliveryAddress(document.getElementById('custAddressInput')?.value, store)
    && cov.lat !== null && cov.lng !== null
    && Math.abs(cov.lat - Number(store.lat)) < 0.00001 && Math.abs(cov.lng - Number(store.lng)) < 0.00001;
}
function updateDestinationMarkerStyle() {
  const cov = appState.deliveryCoverage;
  if (!cov.destMarker || !window.L) return;
  if (deliveryPinIsStore()) {
    cov.destMarker.setIcon(window.L.divIcon({className:'', html:'<div class="kk-store-destination"><i class="bi bi-shop"></i></div>',iconSize:[36,36],iconAnchor:[18,18]}));
    cov.destMarker.bindTooltip('Kenji’s Kitchen · Store address', {permanent:true,direction:'top',offset:[0,-20]});
    return;
  }
  cov.destMarker.unbindTooltip();
  let cls = '';
  if (cov.status === 'WITHIN_LAGRO') cls = 'is-inside';
  else if (cov.status === 'OUTSIDE_LAGRO') cls = 'is-outside';
  else if (cov.status === 'NEEDS_CONFIRMATION') cls = 'is-confirm';
  const icon = createPinDivIcon('dest', cls);
  if (icon) cov.destMarker.setIcon(icon);
}

async function initDeliveryCoverageMap() {
  const cov = appState.deliveryCoverage;
  const mapEl = document.getElementById('deliveryCoverageMap');
  const tileNoticeEl = document.getElementById('deliveryMapTileNotice');
  if (!mapEl) return;

  try {
    await ensureDeliveryBoundaryLoaded();
  } catch (_) {
    cov.status = 'OUTAGE';
    cov.headline = 'Unable to check right now.';
    cov.message = 'Unable to check right now: Delivery coverage boundary data could not be loaded.';
    renderDeliveryCoverageStatus();
    updateDeliveryModeDetails();
    return;
  }

  if (!window.L) {
    cov.status = 'OUTAGE';
    cov.headline = 'Unable to check right now.';
    cov.message = 'Unable to check right now: The interactive map component could not be initialized.';
    renderDeliveryCoverageStatus();
    updateDeliveryModeDetails();
    return;
  }

  const boundary = cov.boundaryData.boundary;
  const storeLoc = cov.boundaryData.store_location || { lat: 14.729954, lng: 121.066199, name: "Kenji's Kitchen" };
  const mapCfg = cov.boundaryData.map_config || {};

  if (!cov.map) {
    cov.map = window.L.map(mapEl, {
      center: [Number(storeLoc.lat), Number(storeLoc.lng)],
      zoom: 15,
      minZoom: 11,
      maxZoom: Number(mapCfg.max_zoom || 19),
      zoomControl: true,
      attributionControl: true
    });

    const tileUrl = mapCfg.tile_url || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    cov.map.attributionControl.setPrefix(false);
    const mapCredit = document.getElementById('deliveryMapCredit');
    if (mapCredit) mapCredit.appendChild(cov.map.attributionControl.getContainer());
    const tileAttr = mapCfg.tile_attribution || '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors';

    cov.tileErrorCount = 0;
    cov.tileLayer = window.L.tileLayer(tileUrl, {
      maxZoom: Number(mapCfg.max_zoom || 19),
      attribution: tileAttr,
      keepBuffer: 2
    });

    cov.tileLayer.on('tileerror', () => {
      cov.tileErrorCount++;
      if (cov.tileErrorCount >= 2 && tileNoticeEl) {
        tileNoticeEl.style.display = 'block';
        tileNoticeEl.textContent = 'Map background tiles are temporarily unavailable from the tile provider. The Barangay Greater Lagro boundary polygon and destination pin remain active.';
      }
    });

    cov.tileLayer.on('load', () => {
      if (cov.tileErrorCount === 0 && tileNoticeEl) {
        tileNoticeEl.style.display = 'none';
      }
    });

    cov.tileLayer.addTo(cov.map);

    cov.boundaryLayer = window.L.geoJSON(boundary.geometry, {
      style: {
        color: '#b4830a',
        weight: 2.5,
        opacity: 0.95,
        fillColor: '#f2c12e',
        fillOpacity: 0.18
      }
    }).addTo(cov.map);

    cov.storeMarker = window.L.marker([Number(storeLoc.lat), Number(storeLoc.lng)], {
      icon: createPinDivIcon('store'),
      keyboard: false,
      interactive: true
    }).addTo(cov.map);
    cov.storeMarker.bindTooltip("Kenji's Kitchen · Florante at Laura St., Greater Lagro", { direction: 'top', offset: [0, -12] });

    cov.map.on('click', (e) => {
      if (e?.latlng) {
        setDeliveryDestinationPin(e.latlng.lat, e.latlng.lng, false);
      }
    });

    // Keyboard accessibility on the map container: Enter/Space places pin at center; Shift+Arrows nudges pin
    mapEl.addEventListener('keydown', (e) => {
      if (!cov.map) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        placeDeliveryPinAtMapCenter();
      } else if (e.shiftKey && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key) && cov.lat !== null && cov.lng !== null) {
        e.preventDefault();
        const step = 0.00025;
        let nextLat = Number(cov.lat);
        let nextLng = Number(cov.lng);
        if (e.key === 'ArrowUp') nextLat += step;
        if (e.key === 'ArrowDown') nextLat -= step;
        if (e.key === 'ArrowRight') nextLng += step;
        if (e.key === 'ArrowLeft') nextLng -= step;
        setDeliveryDestinationPin(nextLat, nextLng, true);
      }
    });

    if (cov.boundaryLayer.getBounds().isValid()) {
      cov.map.fitBounds(cov.boundaryLayer.getBounds(), { padding: [14, 14] });
    }
  }

  setTimeout(() => {
    if (cov.map) cov.map.invalidateSize();
  }, 120);
}

function resetDeliveryMapView() {
  const cov = appState.deliveryCoverage;
  if (!cov.map) return;
  cov.map.invalidateSize();
  if (cov.boundaryLayer && cov.boundaryLayer.getBounds().isValid()) {
    cov.map.fitBounds(cov.boundaryLayer.getBounds(), { padding: [14, 14] });
  } else {
    cov.map.setView([14.7269, 121.0662], 15);
  }
}

function placeDeliveryPinAtMapCenter() {
  const cov = appState.deliveryCoverage;
  if (!cov.map) return;
  const center = cov.map.getCenter();
  setDeliveryDestinationPin(center.lat, center.lng, false);
}

function jumpMapToSuggestedStreet() {
  const cov = appState.deliveryCoverage;
  if (!cov.suggestedCenter || !cov.map) return;
  const { lat, lng } = cov.suggestedCenter;
  cov.map.setView([Number(lat), Number(lng)], 17);
  const hasManualText = Boolean(document.getElementById('custAddressInput')?.value.trim());
  setDeliveryDestinationPin(Number(lat), Number(lng), false, { skipReverseGeocode: hasManualText });
}

function showMapActionNotice(message) {
  const noticeEl = document.getElementById('deliveryMapTileNotice');
  if (noticeEl) {
    noticeEl.style.display = message ? 'block' : 'none';
    noticeEl.textContent = message || '';
  }
}

function useOneTimeDeviceLocation() {
  const btn = document.getElementById('btnUseMyLocation');
  const restoreLabel = '<i class="bi bi-crosshair" aria-hidden="true"></i> Use My Location';
  if (!navigator.geolocation) {
    const msg = 'Device GPS is unavailable in this browser. Click directly on the map or use "Find address on map".';
    showMapActionNotice(msg);
    window.kkNotify(msg, 'warning');
    return;
  }
  showMapActionNotice('');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i> Locating…';
  }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = restoreLabel;
      }
      const lat = Number(pos.coords.latitude);
      const lng = Number(pos.coords.longitude);
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        setDeliveryDestinationPin(lat, lng, true);
      }
    },
    (err) => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = restoreLabel;
      }
      const denied = err && err.code === 1;
      const msg = denied
        ? 'Location permission was denied. Click directly on the map at your delivery spot or type your address and click "Find address on map".'
        : 'Could not determine your device GPS location. Tap your delivery spot on the map or use "Find address on map".';
      showMapActionNotice(msg);
      window.kkNotify(msg, 'info');
    },
    { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
  );
}

function invalidateDeliveryVerification(nextStatus = 'CHECKING') {
  const cov = appState.deliveryCoverage;
  cov.seq++;
  if (cov.inFlightController) {
    try { cov.inFlightController.abort(); } catch (_) {}
    cov.inFlightController = null;
  }
  if (cov.reverseGeocodeController) {
    try { cov.reverseGeocodeController.abort(); } catch (_) {}
    cov.reverseGeocodeController = null;
  }
  if (cov.debounceTimer) {
    clearTimeout(cov.debounceTimer);
    cov.debounceTimer = null;
  }
  cov.verificationToken = null;
  cov.verifiedUserId = 0;
  cov.verifiedAddressSnapshot = '';
  cov.verifiedLatSnapshot = '';
  cov.verifiedLngSnapshot = '';
  cov.pendingPinAddress = '';
  cov.eligibleFree = false;
  cov.eligibleOutside = false;
  cov.deliveryMode = '';
  cov.addressConfirmed = false;

  cov.coverageChecked = false;
  cov.pinInsideBoundary = null;
  cov.pinNearBoundaryEdge = false;
  cov.matchedStreet = null;

  const modeSelect = document.getElementById('deliveryModeSelect');
  if (modeSelect) modeSelect.value = '';
  const brgySelect = document.getElementById('deliveryBarangaySelect');
  if (brgySelect) brgySelect.value = '';
  const usePinBtn = document.getElementById('btnUsePinnedAddress');
  if (usePinBtn) usePinBtn.style.display = 'none';

  if (nextStatus === 'CHECKING') {
    cov.status = 'CHECKING';
    cov.headline = 'Checking coverage…';
    cov.message = 'Checking delivery coverage for your destination…';
  } else if (nextStatus === 'SELECT_LOCATION') {
    cov.status = 'SELECT_LOCATION';
    cov.headline = 'Select your delivery location.';
    cov.message = 'Select your delivery location on the map and enter your complete delivery address.';
  }

  updateDestinationMarkerStyle();
  renderDeliveryCoverageStatus();
  updateDeliveryModeDetails();
}

async function resolveAndSyncPinAddress(latNum, lngNum, expectedSeq, shouldAutoFill) {
  const cov = appState.deliveryCoverage;
  if (expectedSeq !== cov.seq) return null;

  const controller = new AbortController();
  cov.reverseGeocodeController = controller;
  const timeout = setTimeout(() => controller.abort(), 5000);

  try {
    const res = await fetch(`${API_ROOT}/orders.php?action=reverse_geocode`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      signal: controller.signal,
      body: JSON.stringify({ lat: latNum, lng: lngNum, seq: expectedSeq })
    });
    const result = await res.json();
    if (expectedSeq !== cov.seq) return null;
    if (result?.data?.seq !== undefined && result?.data?.seq !== null && Number(result.data.seq) !== cov.seq) {
      return null;
    }
    const resolvedAddr = String(result?.data?.address || '').trim();
    if (!resolvedAddr) return null;

    if (shouldAutoFill) {
      const addrInput = document.getElementById('custAddressInput');
      if (addrInput) {
        addrInput.value = resolvedAddr;
        cov.addressOrigin = 'pin';
      }
      cov.pendingPinAddress = '';
      const usePinBtn = document.getElementById('btnUsePinnedAddress');
      if (usePinBtn) usePinBtn.style.display = 'none';
    } else {
      const currentVal = document.getElementById('custAddressInput')?.value.trim() || '';
      if (currentVal.toLowerCase() !== resolvedAddr.toLowerCase()) {
        cov.pendingPinAddress = resolvedAddr;
        const usePinBtn = document.getElementById('btnUsePinnedAddress');
        if (usePinBtn) {
          usePinBtn.style.display = 'inline-flex';
          usePinBtn.innerHTML = `<i class="bi bi-geo-alt" aria-hidden="true"></i> Use pinned address`;
          usePinBtn.title = resolvedAddr;
        }
      }
    }
    return result.data;
  } catch (_) {
    return null;
  } finally {
    clearTimeout(timeout);
    if (cov.reverseGeocodeController === controller) {
      cov.reverseGeocodeController = null;
    }
  }
}

function applyPinnedAddressToInput() {
  const cov = appState.deliveryCoverage;
  const addrInput = document.getElementById('custAddressInput');
  if (!cov.pendingPinAddress || !addrInput) return;
  addrInput.value = cov.pendingPinAddress;
  cov.addressOrigin = 'pin';
  cov.pendingPinAddress = '';
  const usePinBtn = document.getElementById('btnUsePinnedAddress');
  if (usePinBtn) usePinBtn.style.display = 'none';
  handleDeliveryAddressInput(false);
}

async function findTypedAddressOnMap() {
  const cov = appState.deliveryCoverage;
  const addrInput = document.getElementById('custAddressInput');
  const btn = document.getElementById('btnFindAddressOnMap');
  const address = addrInput ? addrInput.value.trim() : '';

  if (!address) {
    window.kkNotify('Enter a street, subdivision, or landmark first, or click directly on the map.', 'warning');
    addrInput?.focus();
    return;
  }

  if (cov.geocodeController) {
    try { cov.geocodeController.abort(); } catch (_) {}
  }
  const controller = new AbortController();
  cov.geocodeController = controller;
  const restoreLabel = '<i class="bi bi-search" aria-hidden="true"></i> Find address on map';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i> Finding…';
  }

  try {
    const res = await fetch(`${API_ROOT}/orders.php?action=geocode_address`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      signal: controller.signal,
      body: JSON.stringify({ address })
    });
    const result = await res.json();
    const geo = result?.data;
    if (!res.ok || !geo || !geo.found || !Number.isFinite(Number(geo.lat)) || !Number.isFinite(Number(geo.lng))) {
      window.kkNotify(geo?.message || result?.message || 'Could not locate that address on the map. Please tap or drag the pin manually.', 'info');
      return;
    }
    cov.addressOrigin = 'manual';
    setDeliveryDestinationPin(Number(geo.lat), Number(geo.lng), true, { skipReverseGeocode: true });
    showMapActionNotice('');
  } catch (err) {
    if (err?.name !== 'AbortError') {
      window.kkNotify('Could not search the address right now. Please tap your destination directly on the map.', 'warning');
    }
  } finally {
    if (cov.geocodeController === controller) {
      cov.geocodeController = null;
    }
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = restoreLabel;
    }
  }
}

function setDeliveryDestinationPin(lat, lng, panMap = false, options = false) {
  const cov = appState.deliveryCoverage;
  const opts = (typeof options === 'boolean')
    ? { autoPlacedForStore: options, skipReverseGeocode: options }
    : (options || {});
  const latNum = Number(Number(lat).toFixed(6));
  const lngNum = Number(Number(lng).toFixed(6));
  if (!Number.isFinite(latNum) || !Number.isFinite(lngNum)) return;

  cov.lat = latNum;
  cov.lng = lngNum;
  cov.pinAutoPlacedForStore = Boolean(opts.autoPlacedForStore);
  const latInput = document.getElementById('deliveryLatInput');
  const lngInput = document.getElementById('deliveryLngInput');
  if (latInput) latInput.value = latNum.toFixed(6);
  if (lngInput) lngInput.value = lngNum.toFixed(6);

  if (cov.map && window.L) {
    if (!cov.destMarker) {
      cov.destMarker = window.L.marker([latNum, lngNum], {
        draggable: true,
        icon: createPinDivIcon('dest', '')
      }).addTo(cov.map);
      cov.destMarker.on('dragstart', () => {
        invalidateDeliveryVerification('CHECKING');
      });
      cov.destMarker.on('dragend', () => {
        const pos = cov.destMarker.getLatLng();
        setDeliveryDestinationPin(pos.lat, pos.lng, false, { autoPlacedForStore: false, skipReverseGeocode: false });
      });
    } else {
      cov.destMarker.setLatLng([latNum, lngNum]);
    }
    if (panMap) {
      cov.map.setView([latNum, lngNum], Math.max(cov.map.getZoom(), 16));
    }
  }

  setCheckoutValidationError('');
  invalidateDeliveryVerification('CHECKING');
  const expectedSeq = cov.seq;
  const currentAddress = document.getElementById('custAddressInput')?.value.trim() || '';
  const shouldAutoFill = !opts.skipReverseGeocode && (!currentAddress || cov.addressOrigin === 'pin');

  if (opts.skipReverseGeocode) {
    requestServerCoverageVerification(expectedSeq);
    return;
  }

  if (shouldAutoFill) {
    resolveAndSyncPinAddress(latNum, lngNum, expectedSeq, true).then(() => {
      if (expectedSeq === cov.seq) {
        requestServerCoverageVerification(expectedSeq);
      }
    });
  } else {
    requestServerCoverageVerification(expectedSeq);
    resolveAndSyncPinAddress(latNum, lngNum, expectedSeq, false);
  }
}

function handleDeliveryAddressInput(addressEdited = false) {
  setCheckoutValidationError('');
  const addrInput = document.getElementById('custAddressInput');
  const clean = addrInput ? addrInput.value.trim() : '';
  const cov = appState.deliveryCoverage;

  if (addressEdited) {
    cov.addressOrigin = clean ? 'manual' : 'empty';
  }

  // Clear an auto-placed store pin if the customer edits away from the store address,
  // while preserving manually placed pins and re-verifying against the updated text.
  if (addressEdited && cov.pinAutoPlacedForStore) {
    cov.lat = null;
    cov.lng = null;
    cov.pinAutoPlacedForStore = false;
    cov.suggestedCenter = null;
    if (cov.destMarker && cov.map) cov.map.removeLayer(cov.destMarker);
    cov.destMarker = null;
    for (const id of ['deliveryLatInput', 'deliveryLngInput']) {
      const input = document.getElementById(id);
      if (input) input.value = '';
    }
  }

  if (!clean && cov.lat === null) {
    invalidateDeliveryVerification('SELECT_LOCATION');
    return;
  }

  invalidateDeliveryVerification(cov.lat === null ? 'SELECT_LOCATION' : 'CHECKING');
  const requestSeq = cov.seq;
  cov.debounceTimer = setTimeout(async () => {
    cov.debounceTimer = null;
    if (requestSeq !== cov.seq) return;
    if (addressEdited && clean) {
      try {
        const data = await ensureDeliveryBoundaryLoaded();
        if (requestSeq !== cov.seq) return;
        if (isStoreDeliveryAddress(clean, data.store_location)) {
          setDeliveryDestinationPin(data.store_location.lat, data.store_location.lng, true, { autoPlacedForStore: true, skipReverseGeocode: true });
          return;
        }
      } catch (_) { /* Coverage request reports unavailable data. */ }
    }
    requestServerCoverageVerification(requestSeq);
  }, 260);
}

async function requestServerCoverageVerification(expectedSeq) {
  const cov = appState.deliveryCoverage;
  if (expectedSeq !== cov.seq) return null;

  const addrInput = document.getElementById('custAddressInput');
  const address = addrInput ? addrInput.value.trim() : '';

  // If no pin and no address, stay in SELECT_LOCATION without hitting network
  if (!address && (cov.lat === null || cov.lng === null)) {
    cov.status = 'SELECT_LOCATION';
    cov.headline = 'Select your delivery location.';
    cov.message = 'Select your delivery location on the map and enter your complete House/Block/Lot and Street address.';
    cov.coverageChecked = false;
    cov.addressConfirmed = false;
    cov.suggestedCenter = null;
    renderDeliveryCoverageStatus();
    updateDeliveryModeDetails();
    return cov;
  }

  const controller = new AbortController();
  cov.inFlightController = controller;
  let timedOut = false;
  const timeout = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, 12000);

  try {
    const res = await fetch(`${API_ROOT}/orders.php?action=verify_address`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      signal: controller.signal,
      body: JSON.stringify({
        address,
        lat: cov.lat,
        lng: cov.lng,
        seq: expectedSeq
      })
    });

    const result = await res.json();
    // Ignore stale / out-of-order responses
    if (expectedSeq !== cov.seq) return null;
    if (result?.data?.seq !== undefined && result?.data?.seq !== null && Number(result.data.seq) !== cov.seq) {
      return null;
    }

    const v = result?.data;
    if (!v || (!res.ok && v.status !== 'OUTAGE')) {
      throw new Error(result?.message || 'Unable to check coverage right now.');
    }

    applyServerVerificationResult(v, address);
    return v;
  } catch (err) {
    if ((!timedOut && err?.name === 'AbortError') || expectedSeq !== cov.seq) return null;
    cov.status = 'OUTAGE';
    cov.headline = 'Unable to check right now.';
    cov.reasonCode = 'NETWORK_ERROR';
    cov.message = 'Unable to check right now. Please check your connection and try again.';
    cov.eligibleFree = false;
    cov.eligibleOutside = false;
    cov.deliveryMode = '';
    cov.verificationToken = null;
    updateDestinationMarkerStyle();
    renderDeliveryCoverageStatus();
    updateDeliveryModeDetails();
    return null;
  } finally {
    clearTimeout(timeout);
    if (cov.inFlightController === controller) {
      cov.inFlightController = null;
    }
  }
}

function applyServerVerificationResult(v, currentAddress) {
  const cov = appState.deliveryCoverage;
  const liveAddress = document.getElementById('custAddressInput')?.value.trim() || '';
  if (liveAddress !== currentAddress) {
    return;
  }
  cov.status = v.status || 'NEEDS_CONFIRMATION';
  cov.headline = v.headline || 'Address and location need confirmation.';
  cov.reasonCode = v.reason_code || '';
  cov.message = v.message || '';
  cov.eligibleFree = Boolean(v.eligible_free);
  cov.eligibleOutside = Boolean(v.eligible_outside);
  cov.coverageChecked = Boolean(v.coverage_checked);
  cov.addressConfirmed = Boolean(v.address_confirmed);
  cov.pinInsideBoundary = v.pin_inside_boundary ?? null;
  cov.pinNearBoundaryEdge = Boolean(v.pin_near_boundary_edge);
  cov.edgeDistanceMeters = v.edge_distance_meters ?? null;
  cov.matchedStreet = v.matched_street || null;
  cov.boundaryVersion = v.boundary_version || cov.boundaryVersion;
  cov.suggestedCenter = v.suggested_center || null;

  const modeSelect = document.getElementById('deliveryModeSelect');
  const brgySelect = document.getElementById('deliveryBarangaySelect');

  if (cov.status === 'WITHIN_LAGRO' && cov.eligibleFree && v.verification_token) {
    cov.deliveryMode = 'LAGRO_FREE';
    cov.verificationToken = v.verification_token;
    cov.verifiedUserId = Number(appState.currentUser?.id || 0);
    cov.verifiedAddressSnapshot = currentAddress;
    cov.verifiedLatSnapshot = cov.lat !== null ? Number(cov.lat).toFixed(6) : '';
    cov.verifiedLngSnapshot = cov.lng !== null ? Number(cov.lng).toFixed(6) : '';
    if (modeSelect) modeSelect.value = 'LAGRO_FREE';
    if (brgySelect) brgySelect.value = 'BRGY_GREATER_LAGRO';
  } else if (cov.status === 'OUTSIDE_LAGRO' && cov.eligibleOutside && v.verification_token) {
    cov.deliveryMode = 'CUSTOMER_BOOKED';
    cov.verificationToken = v.verification_token;
    cov.verifiedUserId = Number(appState.currentUser?.id || 0);
    cov.verifiedAddressSnapshot = currentAddress;
    cov.verifiedLatSnapshot = cov.lat !== null ? Number(cov.lat).toFixed(6) : '';
    cov.verifiedLngSnapshot = cov.lng !== null ? Number(cov.lng).toFixed(6) : '';
    if (modeSelect) modeSelect.value = 'CUSTOMER_BOOKED';
    if (brgySelect) brgySelect.value = 'OUTSIDE_LAGRO';
  } else {
    cov.deliveryMode = '';
    cov.verificationToken = null;
    cov.verifiedUserId = 0;
    cov.verifiedAddressSnapshot = '';
    cov.verifiedLatSnapshot = '';
    cov.verifiedLngSnapshot = '';
    if (modeSelect) modeSelect.value = '';
    if (brgySelect) brgySelect.value = cov.status === 'NEEDS_CONFIRMATION' ? 'OTHER_UNVERIFIED' : '';
  }

  updateDestinationMarkerStyle();
  renderDeliveryCoverageStatus();
  updateDeliveryModeDetails();
}

function renderDeliveryCoverageStatus() {
  const statusEl = document.getElementById('deliveryAreaVerificationStatus');
  const jumpBtn = document.getElementById('btnJumpSuggestedStreet');
  if (!statusEl) return;

  const cov = appState.deliveryCoverage;
  if (appState.fulfillmentMode !== 'delivery') {
    statusEl.style.display = 'none';
    if (jumpBtn) jumpBtn.style.display = 'none';
    return;
  }

  // Show local street quick-jump chip if a Greater Lagro street is mentioned in the address and pin is not yet placed (or far from it)
  if (jumpBtn) {
    if (cov.suggestedCenter && (cov.lat === null || cov.reasonCode === 'STREET_PIN_MISMATCH')) {
      jumpBtn.style.display = 'inline-flex';
      jumpBtn.innerHTML = `<i class="bi bi-compass" aria-hidden="true"></i> Center map on ${escapeHtml(cov.suggestedCenter.name)}`;
    } else {
      jumpBtn.style.display = 'none';
    }
  }

  statusEl.style.display = 'block';
  const stateClassMap = {
    SELECT_LOCATION: 'state-select',
    CHECKING: 'state-checking',
    WITHIN_LAGRO: 'state-within',
    OUTSIDE_LAGRO: 'state-outside',
    NEEDS_CONFIRMATION: 'state-confirm',
    OUTAGE: 'state-outage'
  };
  const iconMap = {
    SELECT_LOCATION: 'bi-geo-alt',
    CHECKING: 'bi-hourglass-split',
    WITHIN_LAGRO: 'bi-check-circle-fill',
    OUTSIDE_LAGRO: 'bi-truck',
    NEEDS_CONFIRMATION: 'bi-exclamation-triangle-fill',
    OUTAGE: 'bi-wifi-off'
  };

  statusEl.className = `delivery-coverage-status ${stateClassMap[cov.status] || 'state-select'}`;
  const iconCls = iconMap[cov.status] || 'bi-geo-alt';

  const copy = {
    SELECT_LOCATION: ['Choose your delivery location', 'Click on the map to pin your spot, or enter your address and click Find address on map.'],
    CHECKING: ['Checking your delivery area…', 'Updates automatically when you change your address or pin.'],
    WITHIN_LAGRO: ['Free delivery · ₱0', 'Within Greater Lagro. Kenji’s Kitchen staff will deliver your order.'],
    OUTSIDE_LAGRO: ['Outside Greater Lagro', 'Third-party courier only (delivery fee is separate from your food order).'],
    OUTAGE: ['Unable to check delivery area', 'Please try again shortly. Delivery coverage must be confirmed before continuing.']
  };
  const reasons = {
    INSIDE_PIN_OUTSIDE_ADDRESS: 'Your address is outside Greater Lagro. Move the pin to that address, outside the gold zone, to continue with courier delivery.',
    OUTSIDE_PIN_LAGRO_ADDRESS: 'Your address says Greater Lagro, but your pin is outside the gold zone. Move the pin inside Greater Lagro or correct your address.',
    CONFLICTING_BARANGAY: 'Your address names different barangays. Enter the correct barangay for your destination.',
    LANDMARK_SPOOF: 'Enter your exact Street, Subdivision, or House/Block/Lot instead of directional or landmark phrases.',
    STREET_PIN_MISMATCH: 'Your pin is too far from the street in your address. Move it to your destination.',
    BOUNDARY_EDGE: 'Your pin is directly on the boundary line. Nudge it slightly inside or outside the gold zone so we can confirm coverage.',
    MISSING_ADDRESS: 'Enter your complete delivery address above.',
    INCOMPLETE_ADDRESS: 'Include your Street, Subdivision, or Landmark (and House/Block/Lot if applicable).',
    INCOMPLETE_OUTSIDE_ADDRESS: 'Include your Street, Subdivision, or Landmark and Barangay/City.',
    UNVERIFIED_STREET: 'We couldn’t match your street. Include Barangay Greater Lagro or check your spelling and map pin.',
    UNVERIFIED_DELIVERY_AREA: cov.message || 'This delivery area requires confirmation. Check your street or contact the store.'
  };
  if (cov.status === 'WITHIN_LAGRO' && deliveryPinIsStore()) {
    copy.WITHIN_LAGRO = ['This is Kenji’s Kitchen', 'You’ve entered our store address. Choose Pickup to collect your order here.'];
  }
  let [headline, message] = copy[cov.status] || ['Check your delivery location', reasons[cov.reasonCode] || cov.message];
  if (cov.status === 'WITHIN_LAGRO' && !deliveryPinIsStore()) {
    const minCheck = validateOnlineOrderMinimum();
    if (!minCheck.ok) {
      message = minCheck.message;
    }
  }
  statusEl.innerHTML = '<div class="coverage-status-headline"><i class="bi ' + iconCls + '" aria-hidden="true"></i><span>' + escapeHtml(headline) + '</span></div><p class="coverage-status-message">' + escapeHtml(message) + '</p>';

}

function validateOnlineOrderMinimum() {
  const rules = appState.deliveryRules || {};
  const minItems = Math.max(0, Number(rules.within_lagro_min_items ?? 2));
  const minAmount = Math.max(0, Number(rules.within_lagro_min_amount ?? 100));
  const subtotal = appState.cart.reduce((sum, i) => sum + (Number(i.price || 0) * Number(i.qty || 0)), 0);
  if (minAmount > 0 && subtotal < minAmount) {
    const reqText = minItems > 1
      ? `a minimum of ${minItems} items and ${formatPeso(minAmount)} (or 1 item priced at ${formatPeso(minAmount)} or more)`
      : `a ${formatPeso(minAmount)} minimum food order`;
    return {
      ok: false,
      subtotal,
      minItems,
      minAmount,
      remaining: Math.max(0, minAmount - subtotal),
      message: `Online orders require ${reqText}.`
    };
  }
  return { ok: true, subtotal, minItems, minAmount, remaining: 0, message: '' };
}

function evaluateLagroDeliveryArea() {
  handleDeliveryAddressInput();
  return appState.deliveryCoverage;
}

function selectCourierBookingChoice(choice) {
  const normalized = choice === 'store' ? 'store' : 'customer';
  const hiddenInput = document.getElementById('courierBookingBy');
  if (hiddenInput) hiddenInput.value = normalized;
  updateCourierBookingChoiceUI();
}

function updateCourierBookingChoiceUI() {
  const hiddenInput = document.getElementById('courierBookingBy');
  const booker = (hiddenInput?.value || 'customer') === 'store' ? 'store' : 'customer';
  const noteEl = document.getElementById('courierBookingNote');
  const trackingWrap = document.getElementById('thirdPartyTrackingWrap');
  const trackingInput = document.getElementById('custTrackingUrl');
  const trackingHelp = document.getElementById('thirdPartyTrackingHelp');

  document.querySelectorAll('#courierBookingGroup .courier-choice-card').forEach(card => {
    const isSelected = card.dataset.choice === booker;
    card.classList.toggle('active', isSelected);
    const radio = card.querySelector('input[type="radio"]');
    if (radio) radio.checked = isSelected;
  });

  if (trackingWrap) {
    trackingWrap.style.display = booker === 'customer' ? 'block' : 'none';
  }
  if (booker === 'store' && trackingInput) {
    trackingInput.value = '';
  }

  if (noteEl) {
    if (booker === 'store') {
      noteEl.style.display = 'block';
      noteEl.textContent = 'Pay for your food via GCash now. Once confirmed, settle the courier fee separately in My Orders.';
    } else {
      noteEl.style.display = 'none';
      noteEl.textContent = '';
    }
  }
  if (trackingHelp) {
    trackingHelp.textContent = 'Paste your third-party carrier (Lalamove, GrabExpress) tracking link now, or add it later in My Orders.';
  }
}

function computeEstimatedPrepTime(cartItems = appState.cart) {
  const items = Array.isArray(cartItems) ? cartItems : [];
  const totalQty = items.reduce((sum, i) => sum + Number(i.qty || i.quantity || 1), 0);
  const hasBilao = items.some(i => Boolean(i.packaging) || /bilao|tray/i.test(String(i.name || i.item_name || '')));
  if (hasBilao || totalQty >= 8) {
    return '~25 to 35 minutes';
  }
  if (totalQty >= 4) {
    return '~20 to 30 minutes';
  }
  return '~15 to 25 minutes';
}

function toggleOnlinePaymentMethod() {
  if (appState.fulfillmentMode === 'delivery' && document.getElementById('deliveryModeSelect')?.value === 'CUSTOMER_BOOKED') {
    const gcash = document.querySelector('input[name="paymentMethod"][value="GCash"]');
    if (gcash) gcash.checked = true;
  }
  const selectedEl = document.querySelector('input[name="paymentMethod"]:checked');
  const method = selectedEl ? selectedEl.value : '';
  const gcashWrap = document.getElementById('onlineGcashWrap');
  if (gcashWrap) {
    gcashWrap.style.display = method.toLowerCase() === 'gcash' ? 'block' : 'none';
    if (method.toLowerCase() === 'gcash') loadOnlineGcashSettings();
  }
}

function handleGcashProofSelection(event) {
  const input = event?.target || document.getElementById('custGcashProofInput');
  const wrap = document.getElementById('gcashProofPreviewWrap');
  const img = document.getElementById('gcashProofPreviewImg');
  const nameEl = document.getElementById('gcashProofFileName');
  if (!input || !input.files || !input.files.length) {
    if (wrap) wrap.style.display = 'none';
    return;
  }
  const file = input.files[0];
  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!allowedTypes.includes(file.type)) {
    input.value = '';
    if (wrap) wrap.style.display = 'none';
    window.kkNotify('Invalid file format. Please select a JPG, PNG, or WebP screenshot.', 'error');
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    input.value = '';
    if (wrap) wrap.style.display = 'none';
    window.kkNotify('Screenshot file is too large. Maximum allowed size is 5 MB.', 'error');
    return;
  }
  const sizeKb = Math.max(1, Math.round(file.size / 1024));
  if (nameEl) nameEl.textContent = `${file.name} (${sizeKb} KB)`;
  if (img) {
    const objectUrl = URL.createObjectURL(file);
    img.onload = () => URL.revokeObjectURL(objectUrl);
    img.src = objectUrl;
  }
  if (wrap) wrap.style.display = 'block';
}

function clearGcashProofSelection() {
  const input = document.getElementById('custGcashProofInput');
  const wrap = document.getElementById('gcashProofPreviewWrap');
  const img = document.getElementById('gcashProofPreviewImg');
  const nameEl = document.getElementById('gcashProofFileName');
  if (input) input.value = '';
  if (img) img.src = '';
  if (nameEl) nameEl.textContent = '';
  if (wrap) wrap.style.display = 'none';
}

function getCartSubtotalAmount() {
  return (appState.cart || []).reduce((sum, item) => sum + (Number(item.price || 0) * Number(item.qty || 1)), 0);
}

function getUnavailableCashReason() {
  const cov = appState.deliveryCoverage;
  const external = appState.fulfillmentMode === 'delivery' && (cov.deliveryMode === 'CUSTOMER_BOOKED' || document.getElementById('deliveryModeSelect')?.value === 'CUSTOMER_BOOKED');
  if (external) {
    return 'Cash is unavailable outside Lagro. Use GCash for the food.';
  }
  const codMax = Math.max(0, Number(appState.deliveryRules?.cod_max_order || 0));
  if (appState.fulfillmentMode === 'delivery' && codMax > 0 && getCartSubtotalAmount() > codMax) {
    return `Cash on Delivery applies up to ${formatPeso(codMax)}. Use GCash for larger orders.`;
  }
  return '';
}

function explainUnavailableCash() {
  const reason = getUnavailableCashReason();
  if (reason) {
    window.kkNotify(reason, 'info');
  }
}

function openCheckoutModal() {
  if (appState.currentUser) {
    const nameInput = document.getElementById('custNameInput');
    if (nameInput && !nameInput.value.trim()) nameInput.value = appState.currentUser.full_name || '';
    const phoneInput = document.getElementById('custPhoneInput');
    if (phoneInput && !phoneInput.value.trim() && appState.currentUser.phone) {
      phoneInput.value = appState.currentUser.phone;
    }
    const addrInput = document.getElementById('custAddressInput');
    if (addrInput && !addrInput.value.trim() && appState.currentUser.customer_address) {
      addrInput.value = appState.currentUser.customer_address;
      appState.deliveryCoverage.addressOrigin = 'manual';
    }
    const cov = appState.deliveryCoverage;
    const activeUid = Number(appState.currentUser.id || 0);
    if (cov.verificationToken && cov.verifiedUserId !== activeUid) {
      invalidateDeliveryVerification(cov.lat === null ? 'SELECT_LOCATION' : 'CHECKING');
    }
  }
  // Ensure neither Cash nor GCash is pre-selected by default when opening checkout
  document.querySelectorAll('input[name="paymentMethod"]').forEach(radio => {
    radio.checked = false;
  });
  const prepEl = document.getElementById('checkoutPrepEstimateText');
  if (prepEl) {
    prepEl.textContent = computeEstimatedPrepTime(appState.cart);
  }
  setCheckoutValidationError('');
  updateFulfillmentDetails();
  document.getElementById('checkoutModal').classList.add('active');
  if (appState.fulfillmentMode === 'delivery') {
    setTimeout(() => {
      if (appState.deliveryCoverage.map) {
        appState.deliveryCoverage.map.invalidateSize();
      }
    }, 180);
  }
}

/**
 * Place Order & Route to POS / KDS
 */
async function submitOrder() {
  setCheckoutValidationError('');
  const cartCheck = await refreshMenuAndValidateCart(true);
  if (!appState.cart.length) {
    closeModal('checkoutModal');
    return;
  }
  if (cartCheck && cartCheck.removedCount > 0) {
    const removedMsg = `${cartCheck.removedNames.join(', ')} is no longer available and was removed from your basket. Please review your updated order before submitting.`;
    setCheckoutValidationError(removedMsg);
    const prepEl = document.getElementById('checkoutPrepEstimateText');
    if (prepEl) {
      prepEl.textContent = computeEstimatedPrepTime(appState.cart);
    }
    return;
  }

  const name = document.getElementById('custNameInput').value.trim();
  const phone = document.getElementById('custPhoneInput').value.trim();
  const address = document.getElementById('custAddressInput').value.trim();
  const cov = appState.deliveryCoverage;
  let deliveryMode = 'LAGRO_FREE';
  let deliveryBarangay = '';
  let courierBookingBy = 'none';

  if (!name) {
    const msg = 'Please enter your full name.';
    setCheckoutValidationError(msg);
    window.kkNotify(msg, 'warning');
    document.getElementById('custNameInput')?.focus();
    return;
  }

  if (!/^09[0-9]{9}$/.test(phone)) {
    const msg = 'Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g. 09171234567).';
    setCheckoutValidationError(msg);
    window.kkNotify(msg, 'warning');
    document.getElementById('custPhoneInput')?.focus();
    return;
  }

  const minCheck = validateOnlineOrderMinimum();
  if (!minCheck.ok) {
    setCheckoutValidationError(minCheck.message);
    window.kkNotify(minCheck.message, 'warning');
    return;
  }

  if (appState.fulfillmentMode !== 'delivery' && appState.fulfillmentMode !== 'pickup') {
    const msg = 'Please select a fulfillment method (Delivery or Pickup) before placing your order.';
    setCheckoutValidationError(msg);
    window.kkNotify(msg, 'warning');
    return;
  }

  if (appState.fulfillmentMode === 'delivery') {
    if (!address) {
      const msg = 'Delivery address is required when Delivery is selected.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'warning');
      document.getElementById('custAddressInput')?.focus();
      return;
    }

    if (cov.status === 'CHECKING' || cov.inFlightController || cov.debounceTimer) {
      const msg = 'Please wait while delivery coverage is still being checked.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'info');
      return;
    }

    if (cov.lat === null || cov.lng === null || cov.status === 'SELECT_LOCATION') {
      const msg = 'Please select your delivery destination on the map so we can verify coverage.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'warning');
      document.getElementById('deliveryCoverageMap')?.focus();
      return;
    }

    if (cov.status === 'OUTAGE') {
      const msg = cov.message || 'Unable to check delivery coverage right now. Please try again.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'error');
      return;
    }

    if (cov.status === 'NEEDS_CONFIRMATION') {
      const msg = cov.message || 'Address and map location need confirmation before placing your order.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'warning');
      return;
    }

    const currentLatFixed = Number(cov.lat).toFixed(6);
    const currentLngFixed = Number(cov.lng).toFixed(6);
    if (
      !cov.verificationToken ||
      cov.verifiedAddressSnapshot !== address ||
      cov.verifiedLatSnapshot !== currentLatFixed ||
      cov.verifiedLngSnapshot !== currentLngFixed ||
      (cov.status !== 'WITHIN_LAGRO' && cov.status !== 'OUTSIDE_LAGRO')
    ) {
      handleDeliveryAddressInput();
      const msg = 'Delivery address or map location changed. Please wait for coverage verification to finish.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'info');
      return;
    }

    if (cov.status === 'WITHIN_LAGRO' && cov.eligibleFree) {
      deliveryMode = 'LAGRO_FREE';
      deliveryBarangay = 'BRGY_GREATER_LAGRO';
      courierBookingBy = 'none';
    } else if (cov.status === 'OUTSIDE_LAGRO' && cov.eligibleOutside) {
      deliveryMode = 'CUSTOMER_BOOKED';
      deliveryBarangay = 'OUTSIDE_LAGRO';
      const chosenBooker = (document.getElementById('courierBookingBy')?.value || 'customer').toLowerCase();
      if (chosenBooker !== 'customer' && chosenBooker !== 'store') {
        const msg = 'Please choose who will book the third-party courier.';
        setCheckoutValidationError(msg);
        window.kkNotify(msg, 'warning');
        return;
      }
      courierBookingBy = chosenBooker;
    } else {
      const msg = cov.message || 'Please confirm your delivery address and map location.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'warning');
      return;
    }
  }

  const deliveryTrackingUrl = (appState.fulfillmentMode === 'delivery' && deliveryMode === 'CUSTOMER_BOOKED' && courierBookingBy === 'customer')
    ? (document.getElementById('custTrackingUrl')?.value.trim() || '')
    : '';
  const paymentMethodEl = document.querySelector('input[name="paymentMethod"]:checked');
  const paymentMethod = paymentMethodEl ? paymentMethodEl.value : '';
  const gcashRefInput = document.getElementById('custGcashRef');
  const gcashRef = gcashRefInput ? gcashRefInput.value.trim() : '';
  const gcashProofInput = document.getElementById('custGcashProofInput');
  const gcashProofFile = gcashProofInput?.files?.[0] || null;
  const orderNotes = document.getElementById('checkoutOverallNotes').value.trim();

  if (!paymentMethod) {
    const msg = 'Please select a payment method (Cash or GCash) before placing your order.';
    setCheckoutValidationError(msg);
    window.kkNotify(msg, 'warning');
    return;
  }

  if (appState.fulfillmentMode === 'delivery' && deliveryMode === 'CUSTOMER_BOOKED' && paymentMethod !== 'GCash') {
    const msg = 'Orders outside Greater Lagro require GCash payment for the food. The third-party courier booking charge is separate.';
    setCheckoutValidationError(msg);
    window.kkNotify(msg, 'warning');
    return;
  }

  if (deliveryTrackingUrl && !deliveryTrackingUrl.startsWith('https://')) {
    const msg = 'Use an HTTPS tracking link from the third-party delivery provider.';
    setCheckoutValidationError(msg);
    window.kkNotify(msg, 'warning');
    return;
  }

  if (paymentMethod.toLowerCase() === 'gcash') {
    if (!/^\d{13}$/.test(gcashRef)) {
      const msg = 'Please enter a valid 13-digit GCash reference number.';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'warning');
      if (gcashRefInput) gcashRefInput.focus();
      return;
    }
    if (!gcashProofFile) {
      const msg = 'Please upload a screenshot of your GCash payment receipt (JPG, PNG, or WebP).';
      setCheckoutValidationError(msg);
      window.kkNotify(msg, 'warning');
      if (gcashProofInput) gcashProofInput.focus();
      return;
    }
  }

  const pickupAt = appState.fulfillmentMode === 'pickup' ? document.getElementById('pickupTime')?.value : null;
  if (appState.fulfillmentMode === 'pickup' && !pickupAt) {
    setCheckoutValidationError('Choose your pickup day and time.');
    document.getElementById('pickupTime')?.focus();
    return;
  }
  const payload = {
    pickup_at: pickupAt,
    customer_name: name,
    customer_phone: phone,
    delivery_address: appState.fulfillmentMode === 'delivery' ? address : '',
    delivery_lat: appState.fulfillmentMode === 'delivery' ? cov.lat : null,
    delivery_lng: appState.fulfillmentMode === 'delivery' ? cov.lng : null,
    verification_token: appState.fulfillmentMode === 'delivery' ? cov.verificationToken : null,
    delivery_barangay: appState.fulfillmentMode === 'delivery' ? deliveryBarangay : '',
    delivery_mode: deliveryMode,
    courier_booking_by: courierBookingBy,
    delivery_tracking_url: deliveryTrackingUrl,
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
      notes: i.notes
    }))
  };

  const prepEstimateSnapshot = computeEstimatedPrepTime(appState.cart);
  const btn = document.getElementById('btnConfirmOrder');
  btn.dataset.submitting = '1';
  btn.disabled = true;
  btn.textContent = 'Processing Order...';

  try {
    const authRes = await fetch(`${API_ROOT}/auth.php?action=me`, { credentials: 'same-origin', cache: 'no-store' });
    const authData = await authRes.json();
    if (!authRes.ok || !authData.authenticated || !authData.csrf_token) {
      clearStaleCustomerSession();
      closeModal('checkoutModal');
      const signInMsg = 'Your session has expired. Please sign in again before placing your order.';
      setCheckoutValidationError(signInMsg);
      window.kkNotify(signInMsg, 'warning', { title: 'Sign In Required' });
      openOtpModal();
      return;
    }

    // Ensure verification_token is bound to the active authenticated customer/session
    if (appState.fulfillmentMode === 'delivery' && cov.verifiedUserId !== Number(authData.user.id || 0)) {
      appState.currentUser = authData.user;
      invalidateDeliveryVerification('CHECKING');
      const refreshed = await requestServerCoverageVerification(cov.seq);
      const expectedMode = refreshed?.delivery_mode || '';
      if (!refreshed || !cov.verificationToken || expectedMode !== deliveryMode) {
        const msg = cov.message || 'Delivery coverage was refreshed for your signed-in account. Please review and try again.';
        setCheckoutValidationError(msg);
        window.kkNotify(msg, 'warning');
        return;
      }
      payload.verification_token = cov.verificationToken;
      payload.delivery_mode = expectedMode;
    }

    const retryScope = 'online:' + authData.user.id;
    payload.request_key = KKCheckoutRetry.key(retryScope, payload, gcashProofFile);
    payload.csrf_token = authData.csrf_token;
    const formData = new FormData();
    formData.append('payload', JSON.stringify(payload));
    if (paymentMethod.toLowerCase() === 'gcash' && gcashProofFile) {
      formData.append('payment_proof', gcashProofFile);
    }

    const res = await fetch(`${API_ROOT}/orders.php`, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
    const result = await res.json();

    if (result.status === 'success' || result.ok) {
      appState.cart = [];
      saveCart();
      KKCheckoutRetry.complete(retryScope);
      if (appState.fulfillmentMode === 'delivery') {
        cov.verificationToken = null;
        cov.verifiedUserId = 0;
        cov.verifiedAddressSnapshot = '';
      }
      if (gcashRefInput) gcashRefInput.value = '';
      clearGcashProofSelection();
      closeModal('checkoutModal');
      if (result.data) result.data.prep_estimate = prepEstimateSnapshot;
      showConfirmationModal(result.data);
    } else {
      if (appState.fulfillmentMode === 'delivery' && ['STALE_VERIFICATION', 'TAMPERED_CLAIM', 'NEEDS_CONFIRMATION', 'SELECT_LOCATION'].includes(result.verification_status)) {
        invalidateDeliveryVerification(cov.lat === null ? 'SELECT_LOCATION' : 'CHECKING');
        requestServerCoverageVerification(cov.seq);
      }
      const check = await refreshMenuAndValidateCart(false);
      if (!appState.cart.length) {
        closeModal('checkoutModal');
      }
      const prepEl = document.getElementById('checkoutPrepEstimateText');
      if (prepEl) {
        prepEl.textContent = computeEstimatedPrepTime(appState.cart);
      }
      let errMsg = result.message || 'Order could not be placed. Please check your details.';
      if (check && (check.removedCount > 0 || check.adjustedCount > 0) && appState.cartStockBanner) {
        errMsg = `${errMsg} ${appState.cartStockBanner}`;
      }
      setCheckoutValidationError(errMsg);
      window.kkNotify(errMsg, 'error', { title: 'Order Review Needed', dedupeKey: 'checkout-submit-err' });
    }
  } catch (_) {
    const errMsg = 'Connection error. Please try again.';
    setCheckoutValidationError(errMsg);
    window.kkNotify(errMsg, 'error');
  } finally {
    delete btn.dataset.submitting;
    btn.disabled = false;
    btn.textContent = 'Place Order Now';
    updateDeliveryModeDetails();
  }
}

function showConfirmationModal(data) {
  appState.lastOrderId = Number(data.order_id) || null;
  const isStoreBooked = String(data.courier_booking_by || '').toUpperCase() === 'STORE';
  document.getElementById('confRoutingStatus').textContent = data.delivery_mode === 'CUSTOMER_BOOKED'
    ? (isStoreBooked
      ? 'Your food payment awaits GCash verification. You asked Kenji’s Kitchen to book the third-party courier; staff will confirm the actual booking charge and add the tracking link in My Orders.'
      : 'Your food payment awaits GCash verification. Book and pay your third-party courier separately, then add the tracking link in My Orders before handoff.')
    : data.payment_method === 'GCash' && data.payment_status !== 'Paid'
      ? 'Your GCash payment is awaiting staff verification. The order will reach the kitchen after verification.'
      : 'Your order has been sent to the kitchen and POS.';
  document.getElementById('confOrderNo').textContent = data.order_number || data.order_no;
  document.getElementById('confReceiptNo').textContent = data.receipt_no || 'N/A';
  const prepEl = document.getElementById('confPrepEstimate');
  if (prepEl) {
    prepEl.textContent = data.prep_estimate || computeEstimatedPrepTime(data.items || []);
  }
  document.getElementById('confTotal').textContent = formatPeso(data.total_amount || 0);
  document.getElementById('confReceiptStatus').textContent = 'View, print, or save your receipt anytime in My Orders.';
  document.getElementById('orderConfirmationModal').classList.add('active');
}

function openOrderTrackingFromConfirmation() {
  closeModal('orderConfirmationModal');
  openOrdersModal(appState.lastOrderId);
}

async function postCustomerTrackingLink(orderId, rawUrl) {
  const url = String(rawUrl || '').trim();
  if (!/^https:\/\/\S+/i.test(url)) {
    window.kkNotify('Enter a valid HTTPS tracking link from your courier (e.g. https://...).', 'warning');
    return false;
  }
  try {
    const tokenResponse = await fetch('./api/orders.php?action=tracking_token', { credentials: 'same-origin', cache: 'no-store' });
    const tokenData = await tokenResponse.json();
    if (!tokenResponse.ok || tokenData.status !== 'success') throw new Error(tokenData.message || 'Please sign in again.');
    const response = await fetch('./api/orders.php?action=tracking_link', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: Number(orderId), tracking_url: url, csrf_token: tokenData.csrf_token })
    });
    const result = await response.json();
    if (!response.ok || result.status !== 'success') throw new Error(result.message || 'Unable to save the link.');
    if (appState.trackingUrlDrafts) delete appState.trackingUrlDrafts[Number(orderId)];
    window.kkNotify(result.message || 'Tracking link submitted for staff verification.', 'success');
    await fetchOrderTracking(orderId, false);
    await loadOrderHistory(orderId, false);
    return true;
  } catch (error) {
    window.kkNotify(error.message || 'Unable to save the tracking link.', 'error');
    return false;
  }
}

function updateInlineTrackingDraft(orderId, val) {
  appState.trackingUrlDrafts = appState.trackingUrlDrafts || {};
  appState.trackingUrlDrafts[Number(orderId)] = String(val || '');
}

async function submitInlineTrackingLink(orderId, context = 'panel') {
  const id = Number(orderId);
  const input = document.getElementById(`kkTrackingInput-${context}-${id}`);
  const btn = document.getElementById(`kkTrackingBtn-${context}-${id}`);
  const val = input ? input.value : (appState.trackingUrlDrafts?.[id] || '');
  if (btn) {
    btn.disabled = true;
    btn.dataset.origLabel = btn.textContent;
    btn.textContent = 'Submitting…';
  }
  try {
    await postCustomerTrackingLink(id, val);
  } finally {
    if (btn && btn.isConnected) {
      btn.disabled = false;
      btn.textContent = btn.dataset.origLabel || 'Submit Tracking Link';
    }
  }
}

async function saveThirdPartyTrackingLink(orderId) {
  const current = appState.lastTrackedData?.order?.delivery_tracking_url || '';
  const link = await window.kkPrompt({
    title: 'Rider Tracking Link',
    message: 'Paste the HTTPS tracking link from your third-party rider. You can also update it later.',
    defaultValue: current,
    placeholder: 'https://...',
    confirmText: 'Save Link'
  });
  if (link === null) return;
  await postCustomerTrackingLink(orderId, link);
}

function buildOption1TrackingWidget(order, isClosed, context = 'panel') {
  const id = Number(order.id);
  const safeTrackingUrl = typeof order.delivery_tracking_url === 'string' && /^https:\/\//i.test(order.delivery_tracking_url)
    ? order.delivery_tracking_url : '';
  const trackStatus = String(order.courier_tracking_status || (safeTrackingUrl ? 'Pending' : 'None'));
  const rejectionReason = String(order.courier_tracking_rejection_reason || '').trim();
  const isOutForDelivery = String(order.delivery_status || '') === 'Out for Delivery';

  let badgeClass = 'is-missing';
  let statusText = 'Send your courier tracking link so we can start preparing your order.';
  if (trackStatus === 'Verified' && safeTrackingUrl) {
    badgeClass = 'is-verified';
    statusText = 'Tracking link approved. Your order is queued for the kitchen.';
  } else if (trackStatus === 'Rejected') {
    badgeClass = 'is-rejected';
    statusText = `Please update your tracking link${rejectionReason ? ' (' + rejectionReason + ')' : ''}.`;
  } else if (safeTrackingUrl && trackStatus === 'Pending') {
    badgeClass = 'is-pending';
    statusText = 'Tracking link submitted. Waiting for staff approval.';
  }

  const draftVal = (appState.trackingUrlDrafts && Object.prototype.hasOwnProperty.call(appState.trackingUrlDrafts, id))
    ? appState.trackingUrlDrafts[id]
    : safeTrackingUrl;
  const inputId = `kkTrackingInput-${context}-${id}`;
  const btnId = `kkTrackingBtn-${context}-${id}`;
  const helpId = `kkTrackingHelp-${context}-${id}`;
  const canEditLink = !isClosed && !isOutForDelivery;

  return `
    <div class="kk-opt1-tracking-box">
      <div class="kk-opt1-tracking-status ${badgeClass}">
        <i class="bi ${trackStatus === 'Verified' ? 'bi-check-circle-fill' : (trackStatus === 'Rejected' ? 'bi-exclamation-triangle-fill' : 'bi-hourglass-split')}" aria-hidden="true"></i>
        <span>${escapeHtml(statusText)}</span>
      </div>
      ${(!isClosed && trackStatus !== 'Verified') ? '<p class="kk-opt1-gate-note">Food preparation begins once Kenji’s Kitchen verifies both GCash payment and your courier tracking link.</p>' : ''}
      ${safeTrackingUrl ? `<div style="margin:6px 0;"><a href="${escapeHtml(safeTrackingUrl)}" target="_blank" rel="noopener noreferrer" style="font-weight:700;">Open third-party tracking ↗</a></div>` : ''}
      ${canEditLink ? `
        <form class="kk-inline-tracking-form" onsubmit="event.preventDefault(); submitInlineTrackingLink(${id}, '${context}');">
          <label for="${inputId}" class="kk-inline-tracking-label">Courier tracking link (HTTPS)</label>
          <div class="kk-inline-tracking-row">
            <input type="url" id="${inputId}" class="checkout-input kk-inline-tracking-input" value="${escapeHtml(draftVal)}" placeholder="https://..." inputmode="url" autocomplete="url" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="${helpId}" required oninput="updateInlineTrackingDraft(${id}, this.value)">
            <button type="submit" id="${btnId}" class="kk-inline-tracking-btn">${safeTrackingUrl ? 'Update Tracking Link' : 'Submit Tracking Link'}</button>
          </div>
          <small id="${helpId}" class="kk-inline-tracking-hint">Paste your Lalamove or GrabExpress share link so staff can approve it and start kitchen prep.</small>
        </form>
      ` : ''}
    </div>
  `;
}

let pickupDays = [];
let pickupDayIndex = -1;
let pickupLoading = false;
function pickupControlFocused() {
  const active = document.activeElement;
  return active?.id === 'pickupTime' || Boolean(active?.closest?.('#pickupDayChoices'));
}
async function loadPickupSlots() {
  if (pickupLoading || pickupControlFocused()) return;
  pickupLoading = true;
  try {
    const response = await fetch(API_ROOT + '/orders.php?action=pickup_slots', {credentials:'same-origin', cache:'no-store'});
    const result = await response.json();
    if (!response.ok || result.status !== 'success' || !Array.isArray(result.days) || result.days.length !== 2) throw new Error();
    // A request may finish after the customer opens the native picker.
    if (pickupControlFocused()) return;
    if (JSON.stringify(pickupDays) !== JSON.stringify(result.days)) {
      pickupDays = result.days;
      renderPickupSlots();
    }
  } catch (_) {
    // Keep the current choices and selection through temporary refresh failures.
    if (!pickupDays.length) {
      document.getElementById('pickupScheduleHelp').textContent = 'Unable to load pickup times. Retrying automatically…';
    }
  } finally { pickupLoading = false; }
}
function selectPickupDay(index) {
  pickupDayIndex = index;
  document.getElementById('pickupTime').value = '';
  renderPickupSlots();
}
function renderPickupSlots() {
  const select = document.getElementById('pickupTime');
  if (!select) return;
  const old = select.value;
  const day = pickupDays[pickupDayIndex];
  document.getElementById('pickupDayChoices').innerHTML = pickupDays.map((d,i) => '<button type="button" aria-pressed="' + (i === pickupDayIndex) + '" onclick="selectPickupDay(' + i + ')">' + d.label + (!d.slots.length ? ' · Unavailable' : '') + '</button>').join('');
  document.getElementById('pickupTimeLabel').textContent = 'Pickup time' + (day ? ' (' + day.label + ')' : '');
  select.innerHTML = '<option value="">Choose a time</option>' + (day?.slots || []).map(slot => '<option value="' + slot.value + '">' + slot.label + '</option>').join('');
  if ((day?.slots || []).some(slot => slot.value === old)) select.value = old;
  select.disabled = !day?.slots.length;
  document.getElementById('pickupScheduleHelp').textContent = !day ? 'Choose Today or Tomorrow first.' : day.slots.length ? 'Available times only · at least 30 minutes needed for preparation' : new Date(day.date + 'T12:00:00+08:00').getUTCDay() === 0 ? 'Closed on Sunday. Please choose another day.' : 'No pickup times left for this day. Please choose another day.';
}
setInterval(() => {
  if (appState.fulfillmentMode === 'pickup' && document.getElementById('checkoutModal')?.classList.contains('active')) loadPickupSlots();
}, 30000);

function updateFulfillmentDetails() {
  const prepHelp = document.getElementById('checkoutPrepHelp');
  if (prepHelp) prepHelp.textContent = appState.fulfillmentMode === 'pickup'
    ? 'Preparation may vary with your order and kitchen volume. Check My Orders for readiness before collecting.'
    : 'This covers food preparation only. Delivery time depends on courier availability, travel distance, and traffic.';
  const schedule = document.getElementById('pickupSchedule');
  if (schedule) schedule.hidden = appState.fulfillmentMode !== 'pickup';
  if (appState.fulfillmentMode === 'pickup') loadPickupSlots();
  const addrWrap = document.getElementById('addressFieldWrap');
  if (!addrWrap) return;
  if (appState.fulfillmentMode === 'delivery') {
    addrWrap.style.display = 'block';
    initDeliveryCoverageMap();
    renderDeliveryCoverageStatus();
    const cov = appState.deliveryCoverage;
    const currentAddr = document.getElementById('custAddressInput')?.value.trim() || '';
    if ((currentAddr || cov.lat !== null) && !cov.verificationToken && cov.status !== 'CHECKING') {
      handleDeliveryAddressInput();
    }
  } else {
    addrWrap.style.display = 'none';
  }
  updateDeliveryModeDetails();
}

function updateDeliveryModeDetails() {
  const mode = appState.fulfillmentMode;
  const pickup = mode === 'pickup';
  const delivery = mode === 'delivery';
  const cov = appState.deliveryCoverage;
  const external = delivery && cov.status === 'OUTSIDE_LAGRO' && cov.eligibleOutside && cov.deliveryMode === 'CUSTOMER_BOOKED';
  const localFree = delivery && cov.status === 'WITHIN_LAGRO' && cov.eligibleFree && cov.deliveryMode === 'LAGRO_FREE';
  const isChecking = delivery && cov.status === 'CHECKING';

  const trackingWrap = document.getElementById('thirdPartyTrackingWrap');
  const bookingWrap = document.getElementById('courierBookingWrap');
  const fee = document.getElementById('drawerDeliveryFeeText');
  const cash = document.querySelector('input[name="paymentMethod"][value="Cash"]');
  const cashOption = document.getElementById('cashPaymentOption');
  const paymentHelp = document.getElementById('paymentMethodHelp');
  const confirmBtn = document.getElementById('btnConfirmOrder');

  if (bookingWrap) bookingWrap.style.display = external ? 'block' : 'none';
  if (external) {
    updateCourierBookingChoiceUI();
  } else if (trackingWrap) {
    trackingWrap.style.display = 'none';
  }

  if (confirmBtn && confirmBtn.dataset.submitting !== '1') {
    if (isChecking) {
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Checking coverage…';
    } else {
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Place Order Now';
    }
  }

  if (fee) {
    if (pickup) {
      fee.textContent = 'Pickup at store';
      fee.style.color = 'var(--dark)';
    } else if (external) {
      fee.textContent = 'Third-party courier (paid separately)';
      fee.style.color = 'var(--muted)';
    } else if (localFree) {
      fee.textContent = '₱0.00 (Free Within Greater Lagro)';
      fee.style.color = 'var(--success)';
    } else if (isChecking) {
      fee.textContent = 'Checking coverage…';
      fee.style.color = 'var(--muted)';
    } else if (delivery && cov.status === 'NEEDS_CONFIRMATION') {
      fee.textContent = 'Needs confirmation';
      fee.style.color = 'var(--warning, #d97706)';
    } else if (delivery && cov.status === 'OUTAGE') {
      fee.textContent = 'Unable to check right now';
      fee.style.color = 'var(--danger, #dc2626)';
    } else {
      fee.textContent = 'Select location at checkout';
      fee.style.color = 'var(--muted)';
    }
  }
  const cashReason = getUnavailableCashReason();
  const cashDisabled = Boolean(cashReason);
  if (cash) {
    cash.disabled = cashDisabled;
    if (cashDisabled && cash.checked) cash.checked = false;
  }
  if (cashOption) cashOption.classList.toggle('payment-option-unavailable', cashDisabled);
  if (paymentHelp) {
    paymentHelp.textContent = cashReason;
    paymentHelp.style.display = cashDisabled ? 'block' : 'none';
  }
  toggleOnlinePaymentMethod();
}

function getCustomerInitial(nameOrEmail) {
  const raw = String(nameOrEmail || '').trim();
  const match = raw.match(/[\p{L}\p{N}]/u);
  return match ? match[0].toUpperCase() : 'C';
}

function getSafeAvatarUrl(url) {
  if (typeof url !== 'string') return '';
  const trimmed = url.trim();
  if (trimmed === '') return '';
  if (trimmed.startsWith('/') || /^https?:\/\//i.test(trimmed)) return trimmed;
  return '';
}

function createAvatarVisual(photoUrl, displayName, imgClass, fallbackClass) {
  const fallback = document.createElement('span');
  fallback.className = fallbackClass;
  fallback.setAttribute('aria-hidden', 'true');
  fallback.textContent = getCustomerInitial(displayName);

  const safeUrl = getSafeAvatarUrl(photoUrl);
  if (!safeUrl) return fallback;

  const img = document.createElement('img');
  img.className = imgClass;
  img.src = safeUrl;
  img.alt = '';
  img.addEventListener('error', () => img.replaceWith(fallback), { once: true });
  return img;
}

function updateUserUI() {
  const container = document.getElementById('navUserContainer');
  if (!container) return;
  container.replaceChildren();

  if (appState.currentUser) {
    const btn = document.createElement('button');
    btn.className = 'nav-btn nav-user-btn';
    btn.type = 'button';
    btn.title = 'My account';
    btn.setAttribute('aria-label', 'My account');
    btn.addEventListener('click', openCustomerAccountModal);
    btn.appendChild(createAvatarVisual(
      appState.currentUser.profile_photo_url,
      appState.currentUser.full_name || appState.currentUser.email,
      'nav-user-avatar',
      'nav-user-initial'
    ));
    container.appendChild(btn);
  } else {
    const btn = document.createElement('button');
    btn.className = 'nav-btn';
    btn.type = 'button';
    btn.style.cssText = 'width: auto; padding: 0 14px; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;';
    btn.addEventListener('click', openOtpModal);
    const icon = document.createElement('i');
    icon.className = 'bi bi-person';
    btn.append(icon, document.createTextNode(' Sign In'));
    container.appendChild(btn);
  }
}

function clearStaleCustomerSession() {
  appState.currentUser = null;
  appState.favoriteIds.clear();
  localStorage.removeItem('kk_customer_session');
  if (appState.deliveryCoverage?.verificationToken) {
    invalidateDeliveryVerification(appState.deliveryCoverage.lat === null ? 'SELECT_LOCATION' : 'CHECKING');
  }
  updateUserUI();
}

async function syncCustomerSession() {
  try {
    const res = await fetch('./api/auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
    const result = await res.json();
    if (res.ok && result.authenticated && result.user) {
      appState.currentUser = result.user;
      localStorage.setItem('kk_customer_session', JSON.stringify(result.user));
      await loadFavoriteData();
      updateUserUI();
      return { authenticated: true, user: result.user, csrfToken: result.csrf_token || '' };
    }
    clearStaleCustomerSession();
    return { authenticated: false };
  } catch (_) {
    // Keep the account label if the API is temporarily unavailable.
    updateUserUI();
    return { authenticated: Boolean(appState.currentUser), networkError: true };
  }
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
    window.kkNotify(err.message || 'Could not update favorites.', 'error');
  }
}

function renderCustomerAccountAvatar() {
  const preview = document.getElementById('customerAccountAvatarPreview');
  const uploadBtn = document.getElementById('btnUploadAvatar');
  const removeBtn = document.getElementById('btnRemoveAvatar');
  const user = appState.currentUser || {};
  const displayName = user.full_name || user.email || 'Customer';
  const hasPhoto = Boolean(getSafeAvatarUrl(user.profile_photo_url));

  if (preview) {
    preview.replaceChildren(createAvatarVisual(
      user.profile_photo_url,
      displayName,
      'account-avatar-img',
      'account-avatar-initial'
    ));
  }
  if (uploadBtn) uploadBtn.textContent = hasPhoto ? 'Change photo' : 'Add photo';
  if (removeBtn) removeBtn.style.display = hasPhoto ? 'inline-flex' : 'none';
}

function setCustomerAvatarStatus(message, isError = false) {
  const statusEl = document.getElementById('customerAvatarStatus');
  if (!statusEl) return;
  statusEl.textContent = message;
  statusEl.classList.toggle('is-error', Boolean(isError));
}

function triggerCustomerAvatarPicker() {
  const input = document.getElementById('customerAvatarInput');
  if (input) input.click();
}

async function handleCustomerAvatarUpload(file) {
  const input = document.getElementById('customerAvatarInput');
  const uploadBtn = document.getElementById('btnUploadAvatar');
  const removeBtn = document.getElementById('btnRemoveAvatar');
  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

  if (!allowedTypes.includes(file.type)) {
    setCustomerAvatarStatus('Please select a JPG, PNG, or WebP image.', true);
    if (input) input.value = '';
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    setCustomerAvatarStatus('Profile picture must be 5 MB or smaller.', true);
    if (input) input.value = '';
    return;
  }

  if (uploadBtn) uploadBtn.disabled = true;
  if (removeBtn) removeBtn.disabled = true;
  setCustomerAvatarStatus('Uploading photo…');

  try {
    const meRes = await fetch('./api/auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
    const meData = await meRes.json();
    if (!meRes.ok || !meData.authenticated || !meData.csrf_token) {
      throw new Error('Your session has expired. Please sign in again.');
    }

    const formData = new FormData();
    formData.append('avatar', file);
    formData.append('csrf_token', meData.csrf_token);

    const res = await fetch('./api/auth.php?action=upload_avatar', {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
    const result = await res.json();
    if (!res.ok || (result.status !== 'success' && !result.ok)) {
      throw new Error(result.message || 'Could not update profile picture.');
    }

    appState.currentUser = result.user || {
      ...(appState.currentUser || {}),
      profile_photo_url: result.profile_photo_url || null
    };
    localStorage.setItem('kk_customer_session', JSON.stringify(appState.currentUser));
    renderCustomerAccountAvatar();
    updateUserUI();
    setCustomerAvatarStatus('Profile picture updated.');
    showToast('Profile picture updated.');
    window.dispatchEvent(new CustomEvent('kk:profile-updated'));
  } catch (err) {
    setCustomerAvatarStatus(err.message || 'Could not upload photo. Please try again.', true);
    window.kkNotify(err.message || 'Could not upload photo. Please try again.', 'error');
  } finally {
    if (uploadBtn) uploadBtn.disabled = false;
    if (removeBtn) removeBtn.disabled = false;
    if (input) input.value = '';
  }
}

async function handleRemoveCustomerAvatar() {
  if (!appState.currentUser?.profile_photo_url) return;
  const uploadBtn = document.getElementById('btnUploadAvatar');
  const removeBtn = document.getElementById('btnRemoveAvatar');
  if (uploadBtn) uploadBtn.disabled = true;
  if (removeBtn) removeBtn.disabled = true;
  setCustomerAvatarStatus('Removing photo…');

  try {
    const meRes = await fetch('./api/auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
    const meData = await meRes.json();
    if (!meRes.ok || !meData.authenticated || !meData.csrf_token) {
      throw new Error('Your session has expired. Please sign in again.');
    }

    const res = await fetch('./api/auth.php?action=remove_avatar', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: meData.csrf_token })
    });
    const result = await res.json();
    if (!res.ok || (result.status !== 'success' && !result.ok)) {
      throw new Error(result.message || 'Could not remove profile picture.');
    }

    appState.currentUser = result.user || {
      ...(appState.currentUser || {}),
      profile_photo_url: null
    };
    localStorage.setItem('kk_customer_session', JSON.stringify(appState.currentUser));
    renderCustomerAccountAvatar();
    updateUserUI();
    setCustomerAvatarStatus('JPG, PNG, or WebP, up to 5 MB.');
    showToast('Profile picture removed.');
    window.dispatchEvent(new CustomEvent('kk:profile-updated'));
  } catch (err) {
    setCustomerAvatarStatus(err.message || 'Could not remove photo. Please try again.', true);
    window.kkNotify(err.message || 'Could not remove photo. Please try again.', 'error');
  } finally {
    if (uploadBtn) uploadBtn.disabled = false;
    if (removeBtn) removeBtn.disabled = false;
  }
}

function openCustomerAccountModal() {
  document.getElementById('customerAccountName').textContent = appState.currentUser?.full_name || appState.currentUser?.email || 'Customer';
  renderCustomerAccountAvatar();
  setCustomerAvatarStatus('JPG, PNG, or WebP, up to 5 MB.');
  toggleDeactivateAccountPanel(false);
  document.getElementById('customerAccountModal').classList.add('active');
}

function toggleDeactivateAccountPanel(forceState) {
  const panel = document.getElementById('deactivateAccountPanel');
  const errEl = document.getElementById('deactivateAccountError');
  const pwInput = document.getElementById('deactivateAccountPassword');
  const chkInput = document.getElementById('deactivateAccountConfirmCheck');
  if (!panel) return;
  const shouldOpen = typeof forceState === 'boolean' ? forceState : panel.style.display === 'none';
  panel.style.display = shouldOpen ? 'block' : 'none';
  if (errEl) {
    errEl.style.display = 'none';
    errEl.textContent = '';
  }
  if (pwInput) pwInput.value = '';
  if (chkInput) chkInput.checked = false;
  if (shouldOpen && pwInput) {
    setTimeout(() => pwInput.focus(), 50);
  }
}

async function handleDeactivateCustomerAccount() {
  const errEl = document.getElementById('deactivateAccountError');
  const pwInput = document.getElementById('deactivateAccountPassword');
  const chkInput = document.getElementById('deactivateAccountConfirmCheck');
  const btn = document.getElementById('btnConfirmDeactivateAccount');
  if (!pwInput || !chkInput || !btn) return;
  if (btn.disabled) return;

  const showErr = (msg) => {
    if (errEl) {
      errEl.textContent = msg;
      errEl.style.display = 'block';
    }
    window.kkNotify(msg, 'error');
  };

  const password = pwInput.value;
  if (!password) {
    showErr('Please enter your current password to confirm account deactivation.');
    pwInput.focus();
    return;
  }
  if (!chkInput.checked) {
    showErr('Please check the confirmation box to proceed with deactivating your account.');
    chkInput.focus();
    return;
  }

  btn.disabled = true;
  const origLabel = btn.textContent;
  btn.textContent = 'Deactivating…';

  try {
    const meRes = await fetch('./api/auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
    const meData = await meRes.json();
    if (!meRes.ok || !meData.authenticated || !meData.csrf_token) {
      showErr('Your session has expired. Please sign in again.');
      return;
    }

    const res = await fetch('./api/auth.php?action=deactivate_account', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        password: password,
        confirm: true,
        csrf_token: meData.csrf_token
      })
    });
    const result = await res.json();

    if (res.ok && (result.status === 'success' || result.ok)) {
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
      showToast('Your account has been deactivated.');
    } else {
      showErr(result.message || 'Unable to deactivate your account. Please verify your password.');
    }
  } catch (_) {
    showErr('Connection error. Please try again.');
  } finally {
    btn.disabled = false;
    btn.textContent = origLabel;
  }
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

function getDismissedReviewOrderIds() {
  try {
    const raw = JSON.parse(sessionStorage.getItem('kk_dismissed_review_orders') || '[]');
    return new Set(Array.isArray(raw) ? raw.map(Number) : []);
  } catch (_) {
    return new Set();
  }
}

function isOrderReviewDismissed(orderId) {
  return getDismissedReviewOrderIds().has(Number(orderId));
}

function dismissOrderReviewInvite(orderId) {
  const dismissed = getDismissedReviewOrderIds();
  dismissed.add(Number(orderId));
  try {
    sessionStorage.setItem('kk_dismissed_review_orders', JSON.stringify([...dismissed]));
  } catch (_) {}
  const invite = document.querySelector(`.order-review-invite[data-order-id="${Number(orderId)}"]`);
  if (invite) invite.remove();
}

async function syncMyReviewState() {
  try {
    const res = await fetch('./api/reviews.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await res.json();
    if (!res.ok) return;
    appState.reviewCsrf = data.csrf || '';
    appState.myReview = data.my_review || null;
    document.querySelectorAll('.btn-review-order').forEach(btn => {
      const icon = '<i class="bi bi-star"></i> ';
      btn.innerHTML = icon + (appState.myReview ? 'Edit review' : 'Write a review');
    });
  } catch (_) {}
}

function closeOrderReviewComposer() {
  appState.reviewOrderId = null;
  const box = document.getElementById('orderReviewComposer');
  if (box) {
    box.style.display = 'none';
    box.replaceChildren();
  }
}

function openOrderReviewComposer(orderId) {
  const box = document.getElementById('orderReviewComposer');
  if (!box) return;
  const order = appState.ordersById?.[Number(orderId)] || null;
  const orderLabel = order?.order_no ? `Order ${order.order_no}` : 'Completed order';
  const existing = appState.myReview;
  appState.reviewOrderId = Number(orderId);

  const starSvg = '<svg class="star-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.4l2.92 5.92 6.53.95-4.72 4.6 1.11 6.5L12 17.3l-5.84 3.07 1.11-6.5-4.72-4.6 6.53-.95L12 2.4z"/></svg>';
  box.innerHTML = `
    <div class="order-review-composer-head">
      <div>
        <h3>${existing ? 'Update your review' : 'Write a review'}</h3>
        <span class="order-review-order-tag">${escapeHtml(orderLabel)}</span>
      </div>
      <button type="button" class="order-review-skip-btn" onclick="closeOrderReviewComposer()">Close</button>
    </div>
    <form id="orderReviewForm" class="order-review-form">
      <fieldset class="star-rating">
        <legend>Your rating</legend>
        <div class="star-rating-row" id="orderReviewStarRow">
          ${[1, 2, 3, 4, 5].map(v => `
            <label>
              <input type="radio" name="rating" value="${v}" required aria-label="${v} ${v === 1 ? 'star' : 'stars'}">
              ${starSvg}
            </label>
          `).join('')}
          <span class="star-rating-value" id="orderReviewRatingLabel" aria-live="polite">Tap a star to rate</span>
        </div>
      </fieldset>
      <label for="orderReviewComment">Your review (optional to share)</label>
      <textarea id="orderReviewComment" name="comment" minlength="10" maxlength="1000" rows="3" required placeholder="How was your food and service?"></textarea>
      <p class="order-review-form-note">Your first name and profile picture (if set) appear publicly on Customer reviews.</p>
      <div class="order-review-invite-actions" style="margin-top:2px;">
        <button type="submit" class="order-review-primary-btn" id="btnSubmitOrderReview">${existing ? 'Update review' : 'Post review'}</button>
        <button type="button" class="order-review-skip-btn" onclick="closeOrderReviewComposer()">Cancel</button>
      </div>
      <p id="orderReviewStatus" class="order-review-status" role="status"></p>
    </form>
  `;
  box.style.display = 'block';

  const form = document.getElementById('orderReviewForm');
  const commentEl = document.getElementById('orderReviewComment');
  const statusEl = document.getElementById('orderReviewStatus');
  const starRow = document.getElementById('orderReviewStarRow');
  const ratingLabel = document.getElementById('orderReviewRatingLabel');
  const inputs = [...form.querySelectorAll('input[name="rating"]')];
  const labels = ['Tap a star to rate', '1 out of 5', '2 out of 5', '3 out of 5', '4 out of 5', '5 out of 5'];

  const paint = (hoverVal = 0) => {
    const selected = inputs.find(i => i.checked);
    const selectedVal = selected ? Number(selected.value) : 0;
    const activeVal = hoverVal || selectedVal;
    inputs.forEach(i => {
      const val = Number(i.value);
      i.parentElement.classList.toggle('selected', selectedVal > 0 && val <= selectedVal);
      i.parentElement.classList.toggle('hovered', hoverVal > 0 && val <= hoverVal);
    });
    if (ratingLabel) ratingLabel.textContent = labels[activeVal] || labels[0];
  };

  inputs.forEach(i => {
    i.addEventListener('change', () => paint(0));
    i.parentElement.addEventListener('mouseenter', () => paint(Number(i.value)));
    i.addEventListener('focus', () => paint(Number(i.value)));
    i.addEventListener('blur', () => paint(0));
  });
  if (starRow) starRow.addEventListener('mouseleave', () => paint(0));

  if (existing) {
    const match = inputs.find(i => Number(i.value) === Number(existing.rating));
    if (match) match.checked = true;
    if (commentEl) commentEl.value = existing.comment || '';
  }
  paint(0);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitOrderReview');
    if (!btn || btn.disabled) return;
    const chosen = inputs.find(i => i.checked);
    const ratingVal = chosen ? Number(chosen.value) : 0;
    const commentVal = (commentEl?.value || '').trim();
    if (!ratingVal || commentVal.length < 10) {
      if (statusEl) {
        statusEl.textContent = 'Choose a star rating and write at least 10 characters.';
        statusEl.className = 'order-review-status is-error';
      }
      return;
    }

    btn.disabled = true;
    if (statusEl) {
      statusEl.textContent = 'Saving review…';
      statusEl.className = 'order-review-status';
    }

    try {
      if (!appState.reviewCsrf) await syncMyReviewState();
      const res = await fetch('./api/reviews.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: appState.reviewCsrf,
          rating: ratingVal,
          comment: commentVal
        })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Could not post review.');

      appState.myReview = { rating: ratingVal, comment: commentVal };
      window.dispatchEvent(new CustomEvent('kk:reviews-updated'));
      showToast('Thank you! Your review is published on Customer reviews.');
      document.querySelectorAll('.btn-review-order').forEach(b => {
        b.innerHTML = '<i class="bi bi-star"></i> Edit review';
      });
      if (appState.currentTrackedOrderId) {
        fetchOrderTracking(appState.currentTrackedOrderId, true);
      }
      closeOrderReviewComposer();
    } catch (err) {
      if (statusEl) {
        statusEl.textContent = err.message || 'Could not post review. Please try again.';
        statusEl.className = 'order-review-status is-error';
      }
    } finally {
      btn.disabled = false;
    }
  });

  const modalBody = document.querySelector('#customerOrdersModal > .modal-body');
  if (modalBody) modalBody.scrollTo({ top: 0, behavior: 'smooth' });
  setTimeout(() => commentEl?.focus(), 120);
}

function openOrdersModal(initialOrderId = null) {
  closeModal('customerAccountModal');
  closeOrderReviewComposer();
  const modal = document.getElementById('customerOrdersModal');
  if (modal) modal.classList.add('active');
  const list = document.getElementById('customerOrdersList');
  if (list) list.textContent = 'Loading orders…';
  const panel = document.getElementById('orderTrackingPanel');
  if (panel && !initialOrderId && !appState.currentTrackedOrderId) {
    panel.innerHTML = '';
  }
  syncMyReviewState();
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

function customerOrderDisplayStatus(order) {
  const status = String(order?.status || 'Pending');
  if (order?.order_type !== 'ONLINE') return status;
  if (status === 'Served') return 'Delivered';
  if (status !== 'Ready') return status;
  if (Number(order.cancellation_requested) === 1) return 'Cancellation Requested';
  if (order.delivery_status === 'Out for Delivery') return 'Out for Delivery';
  if (order.delivery_status === 'Assigned') return 'Ready';
  if (order.delivery_status === 'Failed') return 'Delivery Attempt Failed';
  return status;
}

function customerOrderDisplayStatusClass(order) {
  return customerOrderDisplayStatus(order).toLowerCase().replace(/[^a-z0-9]+/g, '-');
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
    const displayStatus = customerOrderDisplayStatus(order);
    const displayStatusClass = customerOrderDisplayStatusClass(order);

    const previousStatus = appState.trackedOrderStatuses[order.id];
    if (previousStatus && previousStatus !== 'Ready' && order.status === 'Ready') {
      window.KenjiOrderSounds?.play('ready');
    }
    appState.trackedOrderStatuses[order.id] = order.status;

    appState.trackedFeeStates = appState.trackedFeeStates || {};
    const feeSig = [
      order.tp_fee || 0,
      order.tp_fee_paid || 0,
      order.tp_fee_proof_status || '',
      order.courier_tracking_status || '',
      order.delivery_tracking_url || '',
      order.delivery_status || '',
      order.status || ''
    ].join('|');
    const prevFeeSig = appState.trackedFeeStates[order.id];
    appState.trackedFeeStates[order.id] = feeSig;
    if (prevFeeSig !== undefined && prevFeeSig !== feeSig) {
      loadOrderHistory(orderId, false);
    }

    // Update status chip in list if present
    const chip = document.getElementById(`order-status-${Number(order.id)}`);
    if (chip) {
      chip.textContent = displayStatus;
      chip.className = `order-status-chip ${displayStatusClass}`;
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
    const isDelivery = order.order_type === 'ONLINE';
    const steps = isDelivery
      ? ['Received', 'Preparing', 'Ready', 'Out for Delivery', 'Delivered']
      : ['Received', 'Preparing', 'Ready', 'Picked up'];
    const statusIndex = isDelivery
      ? (order.status === 'Served' ? 4 : ['Out for Delivery', 'Failed'].includes(order.delivery_status) ? 3 : ({ Pending: 0, Preparing: 1, Ready: 2 })[order.status] ?? -1)
      : (({ Pending: 0, Preparing: 1, Ready: 2, Served: 3, 'Picked Up': 3 })[order.status] ?? -1);

    const stepTimes = [
      order.created_at ? formatTimeShort(order.created_at) : '',
      order.preparing_at ? formatTimeShort(order.preparing_at) : '',
      order.ready_at ? formatTimeShort(order.ready_at) : '',
      ...(isDelivery ? [order.out_at ? formatTimeShort(order.out_at) : '', (order.delivered_at || (order.status === 'Served' ? order.served_at : '')) ? formatTimeShort(order.delivered_at || order.served_at) : ''] : [order.served_at ? formatTimeShort(order.served_at) : ''])
    ];

    const pickupSummary = !isDelivery ? String(order.notes || '').match(/^\[Pickup: ([^\]]+)\]/)?.[1] : '';
    const progress = cancelled
      ? `<div class="tracking-cancelled-banner"><i class="bi bi-x-circle"></i> This order is ${escapeHtml(order.status.toLowerCase())}.</div>`
      : `<div class="tracking-steps ${isDelivery ? 'delivery' : 'pickup'}">
          ${steps.map((step, index) => `
            <div class="tracking-step ${index < statusIndex ? 'complete' : ''} ${index === statusIndex ? 'current' : ''}">
              <span class="tracking-step-label">${step}</span>
              ${stepTimes[index] ? `<span class="tracking-step-time">${stepTimes[index]}</span>` : ''}
            </div>
          `).join('')}
         </div>`;

    const itemsHtml = (items || []).map(item => {
      const qty = Number(item.quantity || 1);
      const unitPrice = Number(item.price || 0);
      const lineTotal = unitPrice * qty;
      return `
      <li>
        <div class="tracking-item-row">
          <span>${qty} × ${escapeHtml(item.item_name)}${qty > 1 ? ` <small class="muted">(${formatPeso(unitPrice)} each)</small>` : ''}</span>
          <span>${formatPeso(lineTotal)}</span>
        </div>
        ${item.notes ? `<div class="tracking-item-notes">${escapeHtml(item.notes)}</div>` : ''}
      </li>
    `;
    }).join('');

    const statusNote = completed
      ? (isDelivery ? 'Delivered' : 'Order complete')
      : (cancelled ? 'Order closed' : Number(order.cancellation_requested) === 1 ? 'Cancellation awaits Admin review' : order.delivery_status === 'Failed' ? 'Delivery attempt failed; staff can retry delivery handoff' : order.delivery_status === 'Assigned' ? 'Ready for manual handoff' : '<span class="live-pulse">●</span> Status updates live (every 3 to 5s)');
    const isCustomerBooked = isDelivery && order.delivery_mode === 'CUSTOMER_BOOKED';
    const resolvedBooker = String(order.resolved_courier_booking_by || order.courier_booking_by || 'CUSTOMER').toUpperCase();
    const isStoreBookedCourier = isCustomerBooked && resolvedBooker === 'STORE';
    const safeTrackingUrl = isCustomerBooked && typeof order.delivery_tracking_url === 'string' && /^https:\/\//i.test(order.delivery_tracking_url)
      ? order.delivery_tracking_url : '';
    const courierFeeInfo = isStoreBookedCourier ? buildCourierFeeInfo(order, completed || cancelled) : null;
    const externalTrackingHtml = isCustomerBooked ? `
      <div style="border:1px solid var(--border);border-radius:10px;padding:12px;margin:12px 0;">
        <strong>${isStoreBookedCourier ? 'Outside Greater Lagro · Staff Books Rider' : 'Outside Greater Lagro · Customer Books Rider'}</strong>
        ${isStoreBookedCourier ? `
          <p class="muted" style="margin:5px 0 9px">Our staff is booking your rider. Pay the quoted delivery fee here via GCash once posted.</p>
          ${safeTrackingUrl ? `<a href="${escapeHtml(safeTrackingUrl)}" target="_blank" rel="noopener noreferrer" style="font-weight:700;">Open third-party tracking ↗</a>` : '<span class="muted">No courier tracking link saved yet.</span>'}
          ${courierFeeInfo ? courierFeeInfo.trackingHtml : ''}
        ` : buildOption1TrackingWidget(order, completed || cancelled, 'panel')}
      </div>` : (isDelivery ? `
      <div style="border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin:12px 0;font-size:0.83rem;">
        <strong><i class="bi bi-check-circle-fill" style="color:var(--success,#16a34a);"></i> Within Greater Lagro (Free Store Delivery)</strong>
        <span class="muted" style="display:block;margin-top:3px;">Kenji’s Kitchen staff will deliver your order directly.</span>
      </div>` : '');

    const activeEl = document.activeElement;
    const editingInlineInPanel = panel && activeEl && panel.contains(activeEl) && activeEl.classList?.contains('kk-inline-tracking-input');
    if (panel && !editingInlineInPanel) {
      const isOrderPaid = String(order.payment_status || '').toLowerCase() === 'paid';
      const prepEstimateBanner = (order.status === 'Pending' || order.status === 'Preparing')
        ? `<div style="margin: 10px 0; padding: 8px 12px; border-radius: 8px; background: rgba(242, 193, 46, 0.12); border: 1px solid rgba(242, 193, 46, 0.35); font-size: 0.8rem;">
             <i class="bi bi-clock-history"></i> <strong>Estimated kitchen preparation:</strong> ${escapeHtml(computeEstimatedPrepTime(items || []))}
             <div style="font-size:0.75rem;color:var(--muted);margin-top:2px;">${isDelivery ? 'This covers food preparation only. Delivery time depends on courier availability, travel distance, and traffic.' : 'Check your order status before collecting. Preparation may vary with kitchen volume.'}</div>
           </div>`
        : '';
      const orderReviewInviteHtml = (completed && !isOrderReviewDismissed(order.id))
        ? `<div class="order-review-invite" data-order-id="${Number(order.id)}">
             <div class="order-review-invite-head">
               <div>
                 <p class="order-review-invite-title">${appState.myReview ? 'Want to update your review?' : 'Enjoyed your order?'}</p>
                 <p class="order-review-invite-sub">${appState.myReview ? 'You can edit your public review anytime.' : 'Share a review on our storefront if you’d like. Completely optional.'}</p>
               </div>
             </div>
             <div class="order-review-invite-actions">
               <button type="button" class="order-review-primary-btn" onclick="openOrderReviewComposer(${Number(order.id)})">
                 <i class="bi bi-star"></i> ${appState.myReview ? 'Edit review' : 'Write a review'}
               </button>
               <button type="button" class="order-review-skip-btn" onclick="dismissOrderReviewInvite(${Number(order.id)})">
                 ${appState.myReview ? 'Hide' : 'Not now'}
               </button>
             </div>
           </div>`
        : '';
      panel.innerHTML = `
        <div class="tracking-card">
          <div class="tracking-card-header">
            <div>
              <h3>Tracking ${escapeHtml(order.order_no)} <span class="order-status-chip ${displayStatusClass}">${escapeHtml(displayStatus)}</span></h3>
              <p class="muted">Receipt ${escapeHtml(order.receipt_no || 'N/A')} · ${formatPeso(order.total)} · ${statusNote}</p>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <button type="button" class="btn-track-order" onclick="openCustomerReceiptModal(${Number(order.id)})">
                <i class="bi bi-receipt"></i> ${isOrderPaid ? 'View Receipt' : 'Order Summary'}
              </button>
              <button type="button" class="tracking-close-btn" onclick="closeTrackingPanel()" aria-label="Close tracking view" title="Close tracking view">
                <i class="bi bi-x-lg"></i> Close
              </button>
            </div>
          </div>
          ${prepEstimateBanner}
          ${pickupSummary ? `<p class="pickup-order-time"><strong>Pickup:</strong> ${escapeHtml(pickupSummary)}</p>` : ''}${progress}
          ${externalTrackingHtml}
          <ul class="tracking-items">${itemsHtml}</ul>
          ${orderReviewInviteHtml}
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

let courierFeeState = { orderId: 0, busy: false };

function buildCourierFeeInfo(order, isClosed = false) {
  const fee = Number(order?.tp_fee || 0);
  const service = String(order?.tp_service || 'Third-Party Courier');
  const feePaid = Number(order?.tp_fee_paid || 0) === 1;
  const proofStatus = String(order?.tp_fee_proof_status || '').toLowerCase();
  const proofRef = String(order?.tp_fee_ref || '');
  const rejectionReason = String(order?.tp_fee_rejection_reason || '');

  if (feePaid) {
    return {
      fee,
      needsPayment: false,
      pillHtml: `<span class="courier-fee-pill paid"><i class="bi bi-check-circle-fill"></i> Courier Fee Paid · ${formatPeso(fee)}</span>`,
      trackingHtml: `
        <div class="courier-fee-card is-verified">
          <div class="courier-fee-card-top">
            <strong>${escapeHtml(service)} · Delivery Fee: ${formatPeso(fee)}</strong>
            <span class="courier-fee-pill paid"><i class="bi bi-check-circle-fill"></i> Verified</span>
          </div>
          <p class="muted" style="margin:6px 0 0;font-size:0.8rem;">Your courier fee payment has been verified by staff.</p>
        </div>`
    };
  }

  if (fee <= 0) {
    return {
      fee: 0,
      needsPayment: false,
      pillHtml: `<span class="courier-fee-pill waiting"><i class="bi bi-clock"></i> Awaiting Courier Fee Quote</span>`,
      trackingHtml: `
        <div class="courier-fee-card">
          <div class="courier-fee-card-top">
            <strong>Courier Fare Quote</strong>
            <span class="courier-fee-pill waiting"><i class="bi bi-clock"></i> Checking Rates</span>
          </div>
          <p class="muted" style="margin:6px 0 0;font-size:0.8rem;">Our staff is checking courier rates for your address. You can pay the quoted fee here via GCash once posted.</p>
        </div>`
    };
  }

  if (proofStatus === 'pending') {
    return {
      fee,
      needsPayment: false,
      pillHtml: `<span class="courier-fee-pill pending"><i class="bi bi-hourglass-split"></i> Courier Fee Submitted · Pending Review</span>`,
      trackingHtml: `
        <div class="courier-fee-card">
          <div class="courier-fee-card-top">
            <strong>${escapeHtml(service)} · Quoted Fee: ${formatPeso(fee)}</strong>
            <span class="courier-fee-pill pending"><i class="bi bi-hourglass-split"></i> Verifying Proof</span>
          </div>
          <p class="muted" style="margin:6px 0 0;font-size:0.8rem;">Receipt uploaded (Ref #${escapeHtml(proofRef)}). Waiting for staff verification.</p>
        </div>`
    };
  }

  return {
    fee,
    needsPayment: !isClosed,
    pillHtml: `<span class="courier-fee-pill quoted"><i class="bi bi-exclamation-circle-fill"></i> Courier Fee Quoted: ${formatPeso(fee)}</span>`,
    trackingHtml: `
      <div class="courier-fee-card is-action-needed">
        <div class="courier-fee-card-top">
          <strong>${escapeHtml(service)} · Quoted Fee: ${formatPeso(fee)}</strong>
          <span class="courier-fee-pill quoted"><i class="bi bi-wallet2"></i> Action Needed</span>
        </div>
        ${proofStatus === 'rejected' ? `<p style="margin:6px 0 0;font-size:0.8rem;color:var(--danger);">Previous receipt rejected${rejectionReason ? ': ' + escapeHtml(rejectionReason) : ''}. Please submit a clearer screenshot.</p>` : '<p class="muted" style="margin:6px 0 0;font-size:0.8rem;">Pay the quoted courier fee via GCash QR so staff can dispatch your rider.</p>'}
        ${!isClosed ? `<button type="button" class="courier-fee-pay-btn" onclick="openCourierFeeModal(${Number(order.id)})"><i class="bi bi-qr-code-scan"></i> Pay Courier Fee (${formatPeso(fee)})</button>` : ''}
      </div>`
  };
}

function setCourierFeeError(message) {
  const el = document.getElementById('courierFeeError');
  if (!el) return;
  if (!message) {
    el.style.display = 'none';
    el.textContent = '';
  } else {
    el.style.display = 'block';
    el.textContent = message;
  }
}

function openCourierFeeModal(orderId) {
  const id = Number(orderId);
  const order = (appState.lastTrackedData?.order && Number(appState.lastTrackedData.order.id) === id)
    ? appState.lastTrackedData.order
    : appState.ordersById?.[id];
  if (!order) {
    window.kkNotify('Order details are still loading. Please try again.', 'info');
    return;
  }
  courierFeeState = { orderId: id, busy: false };
  document.getElementById('courierFeeOrderNo').textContent = order.order_no || 'N/A';
  document.getElementById('courierFeeService').textContent = order.tp_service || 'Third-Party Courier';
  document.getElementById('courierFeeAmount').textContent = formatPeso(order.tp_fee || 0);
  const refInput = document.getElementById('courierFeeGcashRef');
  if (refInput) refInput.value = '';
  clearCourierFeeProofSelection();
  setCourierFeeError('');
  const btn = document.getElementById('btnSubmitCourierFee');
  if (btn) {
    btn.disabled = false;
    btn.textContent = 'Submit Fee Payment';
  }
  loadOnlineGcashSettings();
  document.getElementById('courierFeeModal')?.classList.add('active');
}

function closeCourierFeeModal() {
  clearCourierFeeProofSelection();
  setCourierFeeError('');
  document.getElementById('courierFeeModal')?.classList.remove('active');
}

function handleCourierFeeProofSelection(event) {
  const input = event?.target || document.getElementById('courierFeeProofInput');
  const wrap = document.getElementById('courierFeeProofPreviewWrap');
  const img = document.getElementById('courierFeeProofPreviewImg');
  const nameEl = document.getElementById('courierFeeProofFileName');
  if (!input || !input.files || !input.files.length) {
    if (wrap) wrap.style.display = 'none';
    return;
  }
  const file = input.files[0];
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    input.value = '';
    if (wrap) wrap.style.display = 'none';
    setCourierFeeError('Invalid file format. Please select a JPG, PNG, or WebP screenshot.');
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    input.value = '';
    if (wrap) wrap.style.display = 'none';
    setCourierFeeError('Screenshot file is too large. Maximum allowed size is 5 MB.');
    return;
  }
  setCourierFeeError('');
  const sizeKb = Math.max(1, Math.round(file.size / 1024));
  if (nameEl) nameEl.textContent = `${file.name} (${sizeKb} KB)`;
  if (img) {
    const objectUrl = URL.createObjectURL(file);
    img.onload = () => URL.revokeObjectURL(objectUrl);
    img.src = objectUrl;
  }
  if (wrap) wrap.style.display = 'block';
}

function clearCourierFeeProofSelection() {
  const input = document.getElementById('courierFeeProofInput');
  const wrap = document.getElementById('courierFeeProofPreviewWrap');
  const img = document.getElementById('courierFeeProofPreviewImg');
  const nameEl = document.getElementById('courierFeeProofFileName');
  if (input) input.value = '';
  if (img) img.src = '';
  if (nameEl) nameEl.textContent = '';
  if (wrap) wrap.style.display = 'none';
}

async function submitCourierFeePayment() {
  if (courierFeeState.busy || !courierFeeState.orderId) return;
  setCourierFeeError('');
  const refInput = document.getElementById('courierFeeGcashRef');
  const proofInput = document.getElementById('courierFeeProofInput');
  const btn = document.getElementById('btnSubmitCourierFee');
  const ref = String(refInput?.value || '').trim();
  const file = proofInput?.files?.[0] || null;

  if (!/^\d{13}$/.test(ref)) {
    setCourierFeeError('Please enter a valid 13-digit GCash reference number.');
    refInput?.focus();
    return;
  }
  if (!file) {
    setCourierFeeError('Please upload a screenshot of your GCash courier fee receipt.');
    proofInput?.focus();
    return;
  }

  courierFeeState.busy = true;
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Submitting...';
  }
  try {
    const authRes = await fetch('./api/auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
    const authData = await authRes.json();
    if (!authRes.ok || !authData.authenticated || !authData.csrf_token) {
      throw new Error('Please sign in again before submitting courier fee proof.');
    }

    const formData = new FormData();
    formData.append('csrf_token', authData.csrf_token);
    formData.append('proof_image', file);
    const upRes = await fetch('./api/upload_proof.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
    const upData = await upRes.json();
    if (!upRes.ok || upData.status !== 'success' || !upData.proof_token) {
      throw new Error(upData.message || 'Unable to upload payment proof.');
    }

    const submitRes = await fetch('./api/outside_lagro_fee.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: authData.csrf_token,
        order_id: courierFeeState.orderId,
        proof_token: upData.proof_token,
        payment_reference: ref,
        payment_method: 'GCASH'
      })
    });
    const submitData = await submitRes.json();
    if (!submitRes.ok || submitData.status !== 'success') {
      throw new Error(submitData.message || 'Unable to submit courier fee payment.');
    }

    const targetId = courierFeeState.orderId;
    closeCourierFeeModal();
    window.kkNotify(submitData.message || 'Courier fee proof submitted!', 'success');
    await fetchOrderTracking(targetId, false);
    await loadOrderHistory(targetId, false);
  } catch (err) {
    setCourierFeeError(err.message || 'Unable to submit courier fee payment.');
  } finally {
    courierFeeState.busy = false;
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Submit Fee Payment';
    }
  }
}

let customerCancellation = { order: null, csrf: '', busy: false, version: 0 };
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && document.getElementById('customerCancelModal')?.classList.contains('active')) closeCustomerCancellation();
});

function closeCustomerCancellation() {
  if (customerCancellation.busy) return;
  customerCancellation.version++;
  customerCancellation.order = null;
  closeModal('customerCancelModal');
}

async function openCustomerCancellation(id) {
  if (customerCancellation.busy) return;
  const version = ++customerCancellation.version;
  customerCancellation.order = null;
  document.getElementById('customerCancelForm').reset();
  document.getElementById('customerCancelOther').hidden = true;
  document.getElementById('customerCancelOther').required = false;
  document.getElementById('customerCancelError').textContent = '';
  document.getElementById('customerCancelDetails').textContent = 'Checking current order status…';
  document.getElementById('customerCancelWarning').textContent = '';
  document.getElementById('customerCancelSubmit').disabled = true;
  document.getElementById('customerCancelModal').classList.add('active');
  try {
    const response = await fetch(`${API_ROOT}/orders.php?action=cancellation_details&order_id=${Number(id)}`, { credentials: 'same-origin' });
    const result = await response.json();
    if (version !== customerCancellation.version) return;
    if (!response.ok || result.status !== 'success') throw new Error('Unable to load this order. Refresh your orders and try again.');
    const order = result.data;
    document.getElementById('customerCancelDetails').textContent = `${order.order_no}\nTotal: ${formatPeso(order.total)}\nStatus: ${order.status}\nPayment: ${order.payment_method || 'Not recorded'}`;
    if (!order.cancellation.mode) throw new Error(order.cancellation.explanation);
    customerCancellation.order = order;
    customerCancellation.csrf = result.csrf_token;
    const review = order.cancellation.mode === 'request';
    document.getElementById('customerCancelTitle').textContent = review ? 'Request Order Cancellation?' : 'Cancel This Order?';
    document.getElementById('customerCancelWarning').textContent = review
      ? 'Admin approval is required. Your order remains active until approved. This request does not process a refund.'
      : 'Are you sure you want to cancel this order? This action cannot be undone. If stock history requires investigation, your request will be sent to Admin instead.';
    document.getElementById('customerCancelSubmit').textContent = review ? 'Submit Cancellation Request' : 'Confirm Cancellation';
    document.getElementById('customerCancelSubmit').disabled = false;
    document.getElementById('customerCancelReason').focus();
  } catch (error) {
    if (version === customerCancellation.version) document.getElementById('customerCancelError').textContent = error instanceof SyntaxError ? 'Unable to load this order. Please try again.' : error.message;
  }
}

async function submitCustomerCancellation() {
  if (customerCancellation.busy || !customerCancellation.order || !document.getElementById('customerCancelForm').reportValidity()) return;
  const orderId = Number(customerCancellation.order.id);
  let reason = document.getElementById('customerCancelReason').value;
  if (reason === 'Other reason') reason = 'Other: ' + document.getElementById('customerCancelOther').value.trim();
  customerCancellation.busy = true;
  document.getElementById('customerCancelSubmit').disabled = true;
  document.getElementById('customerCancelError').textContent = '';
  try {
    const response = await fetch(`${API_ROOT}/orders.php?action=cancel`, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, reason, csrf_token: customerCancellation.csrf })
    });
    const result = await response.json();
    if (!response.ok || result.status !== 'success') {
      document.getElementById('customerCancelError').textContent = result.message || 'Cancellation was not completed. Refresh your orders.';
      return;
    }
    customerCancellation.busy = false;
    closeCustomerCancellation();
    window.kkNotify(result.message, 'success');
    await loadOrderHistory(orderId);
    await loadMenuItems();
  } catch (_) {
    document.getElementById('customerCancelError').textContent = 'Could not confirm the result. Close this dialog and refresh your orders before trying again.';
  } finally {
    customerCancellation.busy = false;
    // Reopen to obtain fresh eligibility instead of resubmitting stale state.
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
    appState.ordersById = Object.fromEntries(orders.map(o => [Number(o.id), o]));

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
      const displayedStatus = customerOrderDisplayStatus(order);
      const paymentStatusNorm = String(order.payment_status || '').toLowerCase();
      const isOrderPaid = paymentStatusNorm === 'paid';
      const paymentStatusLabel = isOrderPaid
        ? 'Paid'
        : (paymentStatusNorm === 'refunded' ? 'Refunded' : (paymentStatusNorm === 'voided' ? 'Voided' : (paymentStatusNorm === 'failed' ? 'Payment Cancelled' : 'Payment Pending')));
      const isOutsideCourierRow = order.order_type === 'ONLINE' && order.delivery_mode === 'CUSTOMER_BOOKED';
      const resolvedRowBooker = String(order.resolved_courier_booking_by || order.courier_booking_by || 'CUSTOMER').toUpperCase();
      const isStoreBookedRow = isOutsideCourierRow && resolvedRowBooker === 'STORE';
      const isOption1Row = isOutsideCourierRow && resolvedRowBooker === 'CUSTOMER';
      const rowSafeUrl = isOutsideCourierRow && typeof order.delivery_tracking_url === 'string' && /^https:\/\//i.test(order.delivery_tracking_url)
        ? order.delivery_tracking_url : '';
      const rowClosed = !['Pending', 'Preparing', 'Ready'].includes(order.status);
      const rowFeeInfo = isStoreBookedRow ? buildCourierFeeInfo(order, rowClosed) : null;
      const deliveredStamp = (order.status === 'Served' && order.order_type === 'ONLINE' && (order.delivered_at || order.served_at))
        ? ` · Delivered ${formatTimeShort(order.delivered_at || order.served_at)}`
        : '';

      const isCompletedOrder = ['Served', 'Picked Up'].includes(order.status);
      return `
        <article class="customer-order-row ${isSelected ? 'is-selected-tracking' : ''}" id="customer-order-${Number(order.id)}" data-order-id="${Number(order.id)}">
          <div class="customer-order-top">
            <strong>${escapeHtml(order.order_no)}</strong>
            <span class="order-status-chip ${customerOrderDisplayStatusClass(order)}" id="order-status-${Number(order.id)}">${escapeHtml(displayedStatus)}</span>
          </div>
          <p>
            ${formattedDate} · ${fulfillment} · ${formatPeso(order.total)}${escapeHtml(deliveredStamp)}<br>
            Receipt ${escapeHtml(order.receipt_no || 'N/A')} · ${paymentStatusLabel}
          </p>
          ${rowFeeInfo ? `<div style="margin:6px 0 8px;">${rowFeeInfo.pillHtml}</div>` : ''}
          ${(isStoreBookedRow && rowSafeUrl) ? `<div style="margin:4px 0 8px;font-size:0.84rem;"><a href="${escapeHtml(rowSafeUrl)}" target="_blank" rel="noopener noreferrer" style="font-weight:700;">Open third-party tracking ↗</a></div>` : ''}
          ${(isOption1Row && !rowClosed) ? buildOption1TrackingWidget(order, false, 'card') : ''}
          <div class="customer-order-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
            ${rowFeeInfo?.needsPayment && !rowClosed ? `<button type="button" style="background:var(--primary);color:#1f2328;border-color:var(--primary);font-weight:800;" onclick="openCourierFeeModal(${Number(order.id)})"><i class="bi bi-wallet2"></i> Pay Courier Fee (${formatPeso(rowFeeInfo.fee)})</button>` : ''}
            ${order.cancellation?.mode ? `<button type="button" style="color:#b42318;border-color:#b42318;" onclick="openCustomerCancellation(${Number(order.id)})">${order.cancellation.mode === 'request' ? 'Request Cancellation' : 'Cancel Order'}</button>` : ''}
            <button type="button" class="btn-track-order ${isSelected ? 'active' : ''}" data-track-order-id="${Number(order.id)}" onclick="trackOrder(${Number(order.id)}, true, true)">
              <i class="bi ${isSelected ? 'bi-geo-alt-fill' : 'bi-geo-alt'}"></i> ${isSelected ? 'Tracking this order' : 'Track order'}
            </button>
            <button type="button" class="btn-track-order" onclick="openCustomerReceiptModal(${Number(order.id)})">
              <i class="bi bi-receipt"></i> ${isOrderPaid ? 'View Receipt' : 'View Order Summary'}
            </button>
            ${isCompletedOrder ? `<button type="button" class="btn-track-order btn-review-order" onclick="openOrderReviewComposer(${Number(order.id)})"><i class="bi bi-star"></i> ${appState.myReview ? 'Edit review' : 'Write a review'}</button>` : ''}
          </div>
          ${order.cancellation ? `<p class="muted">${escapeHtml(order.cancellation.pending ? 'Cancellation / staff review pending.' : (order.cancellation.decision ? 'Cancellation ' + order.cancellation.decision.status.toLowerCase() + '. ' + (order.cancellation.decision.review_notes || '') : order.cancellation.explanation || ''))}${order.cancellation.reason ? '<br>' + escapeHtml(order.cancellation.reason) : ''}</p>` : ''}
        </article>
      `;
    };

    const editingInlineInList = activeList && document.activeElement && activeList.contains(document.activeElement) && document.activeElement.classList?.contains('kk-inline-tracking-input');
    // Render Active Orders Section (at top, above history)
    if (activeOrders.length > 0) {
      if (activeSection) activeSection.style.display = 'block';
      if (activeList && !editingInlineInList) activeList.innerHTML = activeOrders.map(renderCard).join('');
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

async function openCustomerReceiptModal(orderId) {
  const id = Number(orderId);
  if (!id || isNaN(id)) return;
  const modal = document.getElementById('customerReceiptModal');
  const container = document.getElementById('customerReceiptPrintableArea');
  if (!modal || !container) return;

  if (appState.receiptLoadingId === id) return;
  const request = (appState.receiptRequest || 0) + 1;
  appState.receiptRequest = request;
  appState.receiptLoadingId = id;
  appState.currentReceiptData = null;
  const buttons = modal.querySelectorAll('[data-receipt-print]');
  buttons.forEach(button => button.disabled = true);
  container.innerHTML = '<div style="text-align:center;padding:24px 0;color:var(--muted);"><i class="bi bi-hourglass-split"></i> Loading receipt details…</div>';
  modal.classList.add('active');

  try {
    const res = await fetch(`./api/orders.php?action=receipt&order_id=${encodeURIComponent(id)}`, { credentials: 'same-origin' });
    let result;
    try { result = await res.json(); } catch (_) { throw new Error('Invalid response'); }
    if (request !== appState.receiptRequest) return;
    if (!res.ok || result.status !== 'success' || !result.data?.order || !Array.isArray(result.data?.items)) {
      throw new Error(result.message || 'Unable to load receipt.');
    }

    const { order, items, is_paid, document_title } = result.data;


    const placedDate = order.created_at
      ? new Date(String(order.created_at).replace(' ', 'T')).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
      : 'N/A';
    const receiptBooker = String(order.resolved_courier_booking_by || order.courier_booking_by || 'CUSTOMER').toUpperCase();
    const orderTypeLabel = order.order_type === 'ONLINE'
      ? (order.delivery_mode === 'CUSTOMER_BOOKED'
        ? (receiptBooker === 'STORE' ? 'Delivery (Outside Lagro, Staff Courier)' : 'Delivery (Outside Lagro, Customer Courier)')
        : 'Delivery (Within Greater Lagro, Free Store Delivery)')
      : 'Pickup at Store';
    const deliveryFeeReceiptLabel = order.order_type === 'ONLINE'
      ? (order.delivery_mode === 'CUSTOMER_BOOKED'
        ? (receiptBooker === 'STORE' ? 'Actual courier fee (Store-booked, paid separately)' : 'Actual courier fee (Customer-booked, paid separately)')
        : '₱0.00 (Free Within Greater Lagro)')
      : '₱0.00 (Pickup)';
    const badgeBg = is_paid ? '#fff5d6' : 'rgba(245, 158, 11, 0.16)';
    const badgeColor = is_paid ? '#594113' : '#92400e';
    const badgeBorder = is_paid ? '1px solid #d9c582' : '1px solid rgba(245, 158, 11, 0.35)';
    const badgeText = is_paid ? 'PAID RECEIPT' : 'ORDER SUMMARY · PAYMENT PENDING';

    const itemsRowsHtml = (items || []).map(item => {
      const qty = Number(item.quantity || 1);
      const unitPrice = Number(item.price || 0);
      const lineTotal = unitPrice * qty;
      return `
        <tr>
          <td style="padding:7px 0;border-bottom:1px dashed var(--border);">
            <div style="font-weight:700;color:var(--dark);">${qty} × ${escapeHtml(item.item_name)}</div>
            <div style="font-size:0.76rem;color:var(--muted);">${formatPeso(unitPrice)} each</div>
            ${item.notes ? `<div style="font-size:0.75rem;color:var(--muted);font-style:italic;">Note: ${escapeHtml(item.notes)}</div>` : ''}
          </td>
          <td style="padding:7px 0;border-bottom:1px dashed var(--border);text-align:right;font-weight:700;vertical-align:top;color:var(--dark);">
            ${formatPeso(lineTotal)}
          </td>
        </tr>
      `;
    }).join('');

    const subtotal = Number(order.subtotal || order.total || 0);
    const discount = Number(order.discount || 0);
    const total = Number(order.total || 0);

    container.innerHTML = `
      <div class="kk-receipt-doc" style="font-size:0.84rem;line-height:1.5;color:var(--dark);">
        <div style="text-align:center;border-bottom:1.5px dashed var(--border);padding-bottom:12px;margin-bottom:12px;">
          <img src="../assets/img/logo.png" alt="Kenji's Kitchen" style="width:42px;height:42px;border-radius:10px;object-fit:cover;margin:0 auto 6px;display:block;border:1px solid #e6dbb8;">
          <h3 style="margin:0;font-size:1.12rem;font-weight:900;letter-spacing:-0.01em;">Kenji's Kitchen</h3>
          <div style="font-size:0.75rem;color:var(--muted);">Blk 79 Lot 34 Florante at Laura St., Brgy. Greater Lagro, Quezon City</div>
          <div style="margin-top:8px;">
            <span style="display:inline-block;padding:4px 12px;border-radius:999px;font-size:0.7rem;font-weight:800;letter-spacing:0.04em;background:${badgeBg};color:${badgeColor};border:${badgeBorder};">
              ${escapeHtml(badgeText)}
            </span>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:0.81rem;margin-bottom:12px;border-bottom:1px dashed var(--border);padding-bottom:12px;">
          <div><span style="color:var(--muted);">Document:</span><br><strong>${escapeHtml(document_title)}</strong></div>
          <div><span style="color:var(--muted);">Receipt No.:</span><br><strong>${escapeHtml(order.receipt_no || 'N/A')}</strong></div>
          <div><span style="color:var(--muted);">Order No.:</span><br><strong>${escapeHtml(order.order_no)}</strong></div>
          <div><span style="color:var(--muted);">Date &amp; Time:</span><br><strong>${escapeHtml(placedDate)}</strong></div>
          <div><span style="color:var(--muted);">Customer Name:</span><br><strong>${escapeHtml(order.customer_name || 'Online Customer')}</strong></div>
          <div><span style="color:var(--muted);">Order Type:</span><br><strong>${escapeHtml(orderTypeLabel)}</strong></div>
          <div><span style="color:var(--muted);">Payment Method:</span><br><strong>${escapeHtml(order.payment_method || 'N/A')}</strong></div>
          <div><span style="color:var(--muted);">Payment Status:</span><br><strong>${escapeHtml(order.payment_status || 'Pending')}</strong></div>
          ${order.payment_reference ? `<div style="grid-column:1/-1;"><span style="color:var(--muted);">GCash Ref No.:</span> <strong>${escapeHtml(order.payment_reference)}</strong> ${Number(order.has_payment_proof) === 1 ? `· <a href="./api/payment_proof.php?order_id=${Number(order.id)}" target="_blank" rel="noopener noreferrer" style="color:var(--primary);font-weight:700;text-decoration:underline;">View Uploaded Screenshot</a>` : ''}</div>` : ''}
          ${order.delivery_address ? `<div style="grid-column:1/-1;"><span style="color:var(--muted);">Delivery Address:</span> <strong>${escapeHtml(order.delivery_address)}</strong></div>` : ''}
        </div>

        <table style="width:100%;border-collapse:collapse;margin-bottom:12px;">
          <thead>
            <tr>
              <th style="text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--muted);padding-bottom:6px;border-bottom:1px solid var(--border);">Item Description</th>
              <th style="text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--muted);padding-bottom:6px;border-bottom:1px solid var(--border);">Amount</th>
            </tr>
          </thead>
          <tbody>
            ${itemsRowsHtml}
          </tbody>
        </table>

        <div style="border-top:1.5px dashed var(--border);padding-top:10px;font-size:0.85rem;">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span style="color:var(--muted);">Subtotal:</span>
            <strong>${formatPeso(subtotal)}</strong>
          </div>
          ${discount > 0 ? `
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span style="color:var(--muted);">Discount:</span>
            <strong>-${formatPeso(discount)}</strong>
          </div>` : ''}
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span style="color:var(--muted);">Delivery / Fulfillment Fee:</span>
            <strong>${escapeHtml(deliveryFeeReceiptLabel)}</strong>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:1.05rem;font-weight:900;margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
            <span>Total Amount:</span>
            <span>${formatPeso(total)}</span>
          </div>
        </div>

        <div style="text-align:center;margin-top:14px;font-size:0.76rem;color:var(--muted);">
          ${is_paid ? 'Thank you for dining with Kenji\'s Kitchen! This serves as your customer receipt copy.' : 'Payment is currently pending verification/collection. An official paid receipt is available once payment is completed.'}
        </div>
      </div>
    `;
    appState.currentReceiptData = result.data;
    buttons.forEach(button => button.disabled = false);
  } catch (err) {
    if (request !== appState.receiptRequest) return;
    appState.currentReceiptData = null;
    container.innerHTML = '<div style="text-align:center;padding:20px;">Unable to load your receipt. Please check your connection and sign-in, then try again.</div>';
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn-primary';
    retry.textContent = 'Retry';
    retry.onclick = () => openCustomerReceiptModal(id);
    container.appendChild(retry);
  } finally {
    if (request === appState.receiptRequest) appState.receiptLoadingId = null;
  }
}

function printCustomerReceipt(saveAsPdf = false) {
  const printable = document.getElementById('customerReceiptPrintableArea');
  if (!printable || !appState.currentReceiptData) {
    return;
  }
  const { order } = appState.currentReceiptData;
  const docTitle = `${order.receipt_no || order.order_no} - Kenji's Kitchen`;
  if (saveAsPdf) {
    window.kkNotify('In the print dialog, choose "Save as PDF" as the printer/destination.', 'info');
  }
  const printWin = window.open('', '_blank', 'width=520,height=720');
  if (!printWin) {
    window.kkNotify('Pop-up blocked. Please allow pop-ups for this site to print or save your receipt.', 'warning');
    return;
  }
  printWin.document.write(`<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>${escapeHtml(docTitle)}</title>
  <style>
    body {
      font-family: 'Segoe UI', Arial, sans-serif;
      color: #111827;
      background: #ffffff;
      margin: 0;
      padding: 24px;
    }
    .print-wrap {
      max-width: 420px;
      margin: 0 auto;
      border: 1px solid #d1d5db;
      border-radius: 12px;
      padding: 20px;
    }
    a { display: none; }
    @media print {
      body { padding: 0; }
      .print-wrap { border: none; padding: 0; max-width: 100%; }
    }
  </style>
</head>
<body>
  <div class="print-wrap">${printable.innerHTML}</div>
  <script>
    window.onload = function() {
      setTimeout(function() {
        window.focus();
        window.print();
      }, 150);
    };
  <\/script>
</body>
</html>`);
  printWin.document.close();
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
  const bilaoGroups = buildBilaoGroups();
  list.innerHTML = appState.favoriteItems.map(item => {
    const menuItem = appState.menuItems.find(candidate => Number(candidate.id) === Number(item.id));
    const bilaoDish = menuItem ? getBilaoDishFromItem(menuItem) : null;
    let available = bilaoDish
      ? Boolean(bilaoGroups[bilaoDish]?.is_available)
      : Boolean(menuItem && !menuItem.is_archived && Number(menuItem.stock) > 0 && menuItem.availability === 'Available');
    return `<article class="customer-favorite-row">
      <div><strong>${escapeHtml(item.name)}</strong><p>${escapeHtml(item.category_name || '')} · ${formatPeso(item.price)}${available ? '' : ' · Currently unavailable'}</p></div>
      <div class="customer-favorite-actions">
        ${available ? `<button type="button" onclick="closeModal('customerFavoritesModal'); quickAddToCart(${Number(item.id)})"><i class="bi bi-plus-lg"></i> Add</button>` : ''}
        <button type="button" aria-label="Remove ${escapeHtml(item.name)} from favorites" onclick="toggleFavorite(${Number(item.id)})"><i class="bi bi-heartbreak"></i></button>
      </div>
    </article>`;
  }).join('');
}

let signOutInProgress = false;
async function handleSignOut() {
  if (signOutInProgress) return;
  signOutInProgress = true;
  try {
    const confirmed = await window.kkConfirm({
      title: 'Sign out of Kenji’s Kitchen?',
      message: 'You can sign back in anytime to check your orders and favorites.',
      confirmText: 'Sign out',
      cancelText: 'Stay signed in'
    });
    if (!confirmed) return;
  try { await fetch('./api/auth.php?action=logout', { credentials: 'same-origin' }); } catch (_) {}
  stopTrackingPolling();
  appState.currentTrackedOrderId = null;
  appState.lastTrackedData = null;
  appState.myReview = null;
  appState.reviewCsrf = '';
  appState.reviewOrderId = null;
  appState.currentUser = null;
  appState.favoriteIds.clear();
  appState.favoriteItems = [];
  appState.cart = [];
  appState.cartRowFeedback = {};
  appState.cartStockBanner = '';
  saveCart();
  localStorage.removeItem('kk_customer_session');
  closeCartDrawer();
  filterAndRenderMenu();
  closeModal('customerAccountModal');
  closeModal('customerOrdersModal');
  closeModal('customerFavoritesModal');
  updateUserUI();
  showToast('You’re signed out. See you again at Kenji’s!');
  } finally { signOutInProgress = false; }
}

function closeModal(modalId) {
  if (modalId === 'authModal') leavePasswordRecovery();
  const modal = document.getElementById(modalId);
  if (modal) modal.classList.remove('active');
  if (modalId === 'customerOrdersModal') {
    stopTrackingPolling();
    closeOrderReviewComposer();
    appState.currentTrackedOrderId = null;
    appState.lastTrackedData = null;
  }
  window.kkSyncOverlayContext?.();
}

function showToast(msg, type = null, options = {}) {
  const text = String(msg ?? '');
  let resolvedType = type;
  if (!resolvedType) {
    const lower = text.toLowerCase();
    if (lower.includes('added to basket') || lower === 'verified.') resolvedType = 'success';
    else if (lower.includes('unavailable') || lower.includes('sold out') || lower.includes('out of stock') || lower.includes('limit') || lower.includes('select ') || lower.includes('choose ') || lower.includes('fewer')) resolvedType = 'warning';
    else resolvedType = 'info';
  }
  const opts = typeof options === 'string' ? { title: options } : { ...(options || {}) };
  if (resolvedType === 'success' && !opts.title) opts.title = 'Basket Updated';
  window.kkNotify(text, resolvedType, opts);
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
const _recovery = {
  active: false, version: 0, busy: false, email: '',
  expiresAt: 0, resendAt: 0, timer: null
};

function leavePasswordRecovery() {
  _recovery.active = false;
  _recovery.version++;
  clearInterval(_recovery.timer);
  _recovery.timer = null;
  document.getElementById('authRecovery').style.display = 'none';
  document.getElementById('recoveryResetForm').reset();
}

function openPasswordRecovery() {
  openAuthModal('signin');
  _recovery.active = true;
  _showAuthStep(0);
  document.getElementById('authRecovery').style.display = 'block';
  document.getElementById('siPasswordInput').value = '';
  document.getElementById('recoveryEmail').value = document.getElementById('siEmailInput').value.trim();
  showRecoveryEmail();
  _recovery.timer = setInterval(updateRecoveryControls, 1000);
}

function showRecoveryEmail() {
  // Ignore replies from a previous email step after the customer navigates back.
  _recovery.version++;
  document.getElementById('recoveryEmailForm').style.display = 'block';
  document.getElementById('recoveryResetForm').style.display = 'none';
  document.getElementById('recoveryResetForm').reset();
  document.getElementById('recoveryHelp').textContent = 'Enter your registered email to receive a 6-digit reset code.';
  updateRecoveryControls();
  document.getElementById('recoveryEmail').focus();
}

function showRecoveryReset() {
  document.getElementById('recoveryEmailForm').style.display = 'none';
  document.getElementById('recoveryResetForm').style.display = 'block';
  document.getElementById('recoveryHelp').textContent = 'If this email belongs to an active customer account, a reset code has been sent. Enter it below with your new password.';
  updateRecoveryControls();
  document.getElementById('recoveryCode').focus();
}

function updateRecoveryControls() {
  const remaining = Math.max(0, Math.ceil((_recovery.expiresAt - Date.now()) / 1000));
  const cooldown = Math.max(0, Math.ceil((_recovery.resendAt - Date.now()) / 1000));
  document.getElementById('recoveryExpiry').textContent = remaining
    ? `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}` : 'Expired. Request a new code';
  const resend = document.getElementById('recoveryResend');
  resend.disabled = _recovery.busy || cooldown > 0;
  resend.textContent = cooldown ? `Resend Code (${cooldown}s)` : 'Resend Code';
  document.getElementById('recoverySend').disabled = _recovery.busy;
  document.getElementById('recoverySend').textContent = _recovery.busy ? 'Please wait…' : 'Send Reset Code';
  document.getElementById('recoverySubmit').disabled = _recovery.busy || remaining === 0;
  document.getElementById('recoverySubmit').textContent = _recovery.busy ? 'Please wait…' : 'Reset Password';
}

function backToRecoverySignIn() {
  openAuthModal('signin');
  document.getElementById('siEmailInput').focus();
}

async function requestRecoveryCode(resend = false) {
  if (!_recovery.active || _recovery.busy) return;
  const form = document.getElementById('recoveryEmailForm');
  if (!resend && !form.reportValidity()) return;
  const email = resend ? _recovery.email : document.getElementById('recoveryEmail').value.trim().toLowerCase();
  if (email === _recovery.email && Date.now() < _recovery.resendAt) {
    if (!resend && Date.now() < _recovery.expiresAt) showRecoveryReset();
    return;
  }
  const version = _recovery.version;
  _recovery.busy = true;
  updateRecoveryControls();
  try {
    const res = await fetch(`${API_ROOT}/auth.php?action=forgot_password_send`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    });
    const data = await res.json();
    if (res.ok && data.ok) {
      _recovery.email = email;
      _recovery.expiresAt = Date.now() + 300000;
      _recovery.resendAt = Date.now() + 60000;
      if (!_recovery.active || version !== _recovery.version) return;
      document.getElementById('recoveryCode').value = '';
      showRecoveryReset();
      window.kkNotify('If this email belongs to an active customer account, a 6-digit reset code has been sent. Check your inbox and spam folder.', 'success', {title: 'Check your email', duration: 9000});
    } else if (_recovery.active && version === _recovery.version) {
      window.kkNotify(data.message || 'Could not send the reset code. Please try again.', 'error');
    }
  } catch (_) {
    if (_recovery.active && version === _recovery.version) {
      window.kkNotify('Network error. Please try again.', 'error');
    }
  } finally {
    _recovery.busy = false;
    if (_recovery.active) updateRecoveryControls();
  }
}

async function submitRecoveryPassword() {
  if (!_recovery.active || _recovery.busy) return;
  if (!document.getElementById('recoveryResetForm').reportValidity()) return;
  if (Date.now() >= _recovery.expiresAt) {
    window.kkNotify('Your reset code has expired. Please request a new code.', 'warning');
    updateRecoveryControls();
    return;
  }
  const password = document.getElementById('recoveryPassword').value;
  const confirm = document.getElementById('recoveryConfirm').value;
  if (password !== confirm) {
    window.kkNotify('New password and confirm password do not match.', 'warning');
    document.getElementById('recoveryConfirm').focus();
    return;
  }
  const email = _recovery.email;
  const version = _recovery.version;
  _recovery.busy = true;
  updateRecoveryControls();
  try {
    const res = await fetch(`${API_ROOT}/auth.php?action=forgot_password_reset`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, code: document.getElementById('recoveryCode').value.trim(), new_password: password, confirm_password: confirm })
    });
    const data = await res.json();
    if (res.ok && data.ok) {
      _recovery.expiresAt = 0;
      appState.currentUser = null;
      appState.favoriteIds.clear();
      appState.favoriteItems = [];
      try { localStorage.removeItem('kk_customer_session'); } catch (_) {}
      updateUserUI();
      document.getElementById('recoveryResetForm').reset();
      document.getElementById('siPasswordInput').value = '';
      if (_recovery.active && version === _recovery.version) {
        document.getElementById('siEmailInput').value = email;
        backToRecoverySignIn();
        document.getElementById('siPasswordInput').focus();
      }
      window.kkNotify('Your password has been updated. Sign in with your new password.', 'success', {title: 'Password updated', duration: 6000});
    } else if (_recovery.active && version === _recovery.version) {
      window.kkNotify(data.message || 'Unable to reset password. Please check your code.', 'error');
    }
  } catch (_) {
    if (_recovery.active && version === _recovery.version) {
      window.kkNotify('Network error. Please try again.', 'error');
    }
  } finally {
    _recovery.busy = false;
    if (_recovery.active) updateRecoveryControls();
  }
}

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
  leavePasswordRecovery();
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
  if (btn && btn.disabled) return;

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
      window.kkNotify('Verification code sent! Please check your inbox.', 'success');
    } else {
      window.kkNotify(result.message || 'Unable to send verification code. Please try again.', 'error');
    }
  } catch (err) {
    console.error('[Auth] sendCode error:', err);
    window.kkNotify('Network error. Please check your connection and try again.', 'error');
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
  const btn = document.getElementById('btnVerify');
  if (btn && btn.disabled) return;
  const boxes = document.querySelectorAll('#authOtpBoxes .auth-otp-box');
  const code  = Array.from(boxes).map(b => b.value.trim()).join('');

  if (code.length !== 6 || !/^\d{6}$/.test(code)) {
    window.kkNotify('Please enter all 6 digits of your verification code.', 'warning');
    boxes[0].focus();
    return;
  }

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
      window.kkNotify(isRegister ? 'Your account is verified. You’re ready to order.' : 'You’re signed in and ready to order.', 'success', {title: isRegister ? 'Your account is ready' : 'Welcome to Kenji’s Kitchen', duration: 5000});

      // Proceed to checkout if cart has items
      if (appState.cart.length > 0) {
        setTimeout(() => openCheckoutModal(), 400);
      }

    } else {
      window.kkNotify(result.message || 'Invalid code. Please check and try again.', 'error');
      // Shake boxes on error
      const boxWrap = document.getElementById('authOtpBoxes');
      boxWrap.style.animation = 'none';
      boxWrap.offsetHeight; // reflow
      boxWrap.style.animation = 'authShake 0.4s ease';
      _clearOtpBoxes();
    }
  } catch (err) {
    console.error('[Auth] verifyCode error:', err);
    window.kkNotify('Network error. Please try again.', 'error');
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
      } else if (e.key === 'Enter') {
        e.preventDefault();
        verifyCode();
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
  window.kkNotify(msg, 'warning');
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
  try {
    localStorage.setItem('kk_theme', isDark ? 'dark' : 'light');
  } catch (e) {}
  _updateThemeIcon(isDark);
}

function _updateThemeIcon(isDark) {
  const icon = document.getElementById('themeToggleIcon');
  if (!icon) return;
  if (isDark) {
    icon.className = 'bi bi-sun';
    icon.parentElement.title = 'Switch to Day Mode';
    icon.style.color = '#f59e0b';
  } else {
    icon.className = 'bi bi-moon';
    icon.parentElement.title = 'Switch to Night Mode';
    icon.style.color = '';
  }
}

// Initialize theme immediately on script load
(function initTheme() {
  let savedTheme = null;
  try {
    savedTheme = localStorage.getItem('kk_theme');
  } catch (e) {}
  // Default to Light Mode for first-time visitors without a saved preference
  const isDark = savedTheme === 'dark';
  document.body.classList.toggle('dark-mode', isDark);
  _updateThemeIcon(isDark);
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
  const btn = document.getElementById('btnSignInDirect');
  if (btn && btn.disabled) return;
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
      window.kkNotify('You’re signed in and ready to order.', 'success', {title: 'Welcome to Kenji’s Kitchen', duration: 5000});

      // Auto-open checkout if customer had items in cart
      if (appState.cart.length > 0) {
        setTimeout(() => openCheckoutModal(), 400);
      }
    } else if (result.status === 'unverified') {
      window.kkNotify(result.message || 'Please verify your email address to complete your registration.', 'info');
      _auth.pendingContact = loginInput;
      document.getElementById('authOtpChannel').textContent = 'email';
      document.getElementById('authOtpTarget').textContent  = loginInput;
      _showAuthStep(2);
      _clearOtpBoxes();
      _authInitOtpBoxes();
      _authStartCountdown(300);
    } else {
      window.kkNotify(result.message || 'Check your email and password, then try again.', 'error', {title: 'Unable to sign in', duration: 7000});
      document.getElementById('siPasswordInput').focus();
    }
  } catch (err) {
    console.error('[Auth] Direct sign in error:', err);
    window.kkNotify('Network error. Please check your connection and try again.', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign In';
  }
}

/**
 * Global Category & Search Navigator (Used by Hero Badges & Quick Action Links)
 * Smoothly selects matching category, sets optional search query, and scrolls to menu.
 */
window.jumpToCategory = function(categoryName, query) {
  const normCat = String(categoryName || '').toLowerCase().trim();
  const searchInput = document.getElementById('searchInput');

  // 1. Select matching category pill if available
  let matchedCatId = 'all';
  if (normCat !== 'all' && normCat !== '') {
    const found = appState.categories.find(c => {
      const name = String(c.name || '').toLowerCase();
      return name === normCat || name.includes(normCat) || normCat.includes(name);
    });
    if (found) {
      matchedCatId = found.id;
    }
  }

  appState.activeCategory = matchedCatId;
  document.querySelectorAll('.cat-pill').forEach(btn => {
    btn.classList.toggle('active', String(btn.dataset.catId) === String(matchedCatId));
  });

  // 2. Set search query if specified, or clear if 'all'
  if (typeof query === 'string' && query.length > 0) {
    appState.searchQuery = query.toLowerCase().trim();
    if (searchInput) searchInput.value = query;
  } else if (normCat === 'all') {
    appState.searchQuery = '';
    if (searchInput) searchInput.value = '';
  }

  // 3. Render filtered items
  filterAndRenderMenu();

  // 4. Smoothly scroll into food grid view
  const foodSection = document.getElementById('foodGrid') || document.querySelector('.main-content') || document.querySelector('.category-nav-wrapper');
  if (foodSection) {
    const yOffset = -90; // offset for sticky navbar
    const y = foodSection.getBoundingClientRect().top + window.pageYOffset + yOffset;
    window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
  }
};

async function copyGcashNumber(id, button) {
  const number = document.getElementById(id)?.textContent.trim();
  if (!/^09\d{9}$/.test(number || '')) return;
  try { await navigator.clipboard.writeText(number); button.textContent = 'Number copied'; }
  catch (_) { button.textContent = 'Select the number to copy'; }
  setTimeout(() => { button.textContent = 'Copy number'; }, 2500);
}
