const assert = require('node:assert/strict');
const StockQty = require('../assets/js/stock-qty.js');

// 1. Stock 95, empty basket: max total 95
{
  const a = StockQty.getAddAllowance({
    poolStock: 95,
    usedInBasketForPool: 0,
    perLineCap: StockQty.ONLINE_MAX_LINE_QTY,
    existingMatchingLineQty: 0
  });
  assert.equal(a.maxAdd, 95);
  assert.equal(a.remainingStock, 95);
}

// 2. Stock 95, qty 1 already present: max additional 94
{
  const a = StockQty.getAddAllowance({
    poolStock: 95,
    usedInBasketForPool: 1,
    perLineCap: StockQty.ONLINE_MAX_LINE_QTY,
    existingMatchingLineQty: 1
  });
  assert.equal(a.maxAdd, 94);
  assert.equal(StockQty.formatAddLimitMessage(a), 'You can add up to 94 more.');
}

// 3. Existing basket qty 1, replace with 100 -> capped at 95 with accurate message
{
  const r = StockQty.resolveRowReplace({
    rawInput: '100',
    currentQty: 1,
    poolStock: 95,
    usedInOtherRowsForPool: 0,
    perLineCap: StockQty.ONLINE_MAX_LINE_QTY
  });
  assert.equal(r.qty, 95);
  assert.equal(r.adjusted, true);
  assert.equal(r.atMax, true);
  assert.equal(r.reason, 'stock_limit');
  assert.equal(r.message, 'Adjusted to 95 (stock limit).');
  assert.ok(!r.message.includes('85 more'));
}

// 4. Repeat from existing qty 10, replace with 100 -> still 95 without "85 more"
{
  const r = StockQty.resolveRowReplace({
    rawInput: '100',
    currentQty: 10,
    poolStock: 95,
    usedInOtherRowsForPool: 0,
    perLineCap: StockQty.ONLINE_MAX_LINE_QTY
  });
  assert.equal(r.qty, 95);
  assert.equal(r.message, 'Adjusted to 95 (stock limit).');
  assert.ok(!r.message.includes('85 more'));
}

// 5. Multiple rows sharing stock (e.g., notes or Bilao Tray/Bilao or sizes sharing 1 DB row)
{
  const cart = [
    { id: 1, qty: 10, notes: '' },
    { id: 1, qty: 5, notes: 'extra gravy' }
  ];
  const getKey = l => 'menu:' + l.id;
  const otherForRow1 = StockQty.sumPoolQty(cart, getKey, 'menu:1', 1);
  assert.equal(otherForRow1, 10);
  const r = StockQty.resolveRowReplace({
    rawInput: '90',
    currentQty: 5,
    poolStock: 95,
    usedInOtherRowsForPool: otherForRow1,
    perLineCap: StockQty.ONLINE_MAX_LINE_QTY
  });
  assert.equal(r.qty, 85);
  assert.equal(r.message, 'Adjusted to 85. 10 in other rows.');
  assert.equal(10 + r.qty, 95);
}

// 6. Stock 0, stock 1, and stock above per-order cap (199 > 99)
{
  const s0 = StockQty.getAddAllowance({ poolStock: 0, usedInBasketForPool: 0, perLineCap: 99, existingMatchingLineQty: 0 });
  assert.equal(s0.maxAdd, 0);
  assert.equal(StockQty.formatAddLimitMessage(s0), 'Out of stock.');

  const s1 = StockQty.getAddAllowance({ poolStock: 1, usedInBasketForPool: 1, perLineCap: 99, existingMatchingLineQty: 1 });
  assert.equal(s1.maxAdd, 0);
  assert.equal(StockQty.formatAddLimitMessage(s1), 'All 1 available are in your basket.');

  const s199Add = StockQty.getAddAllowance({ poolStock: 199, usedInBasketForPool: 0, perLineCap: 99, existingMatchingLineQty: 0 });
  assert.equal(s199Add.maxAdd, 99);
  assert.equal(s199Add.limitedByLineCap, true);
  assert.equal(StockQty.formatAddLimitMessage(s199Add), 'Maximum 99 per item line (199 in stock).');

  const s199Replace = StockQty.resolveRowReplace({
    rawInput: '150',
    currentQty: 1,
    poolStock: 199,
    usedInOtherRowsForPool: 0,
    perLineCap: StockQty.ONLINE_MAX_LINE_QTY
  });
  assert.equal(s199Replace.qty, 99);
  assert.equal(s199Replace.reason, 'line_cap');
  assert.equal(s199Replace.message, 'Adjusted to 99 (limit per row). 199 in stock.');

  // POS has Infinity per-line cap so stock 199 allows 150
  const posReplace = StockQty.resolveRowReplace({
    rawInput: '150',
    currentQty: 1,
    poolStock: 199,
    usedInOtherRowsForPool: 0,
    perLineCap: Infinity
  });
  assert.equal(posReplace.qty, 150);
  assert.equal(posReplace.adjusted, false);
}

// 7. Empty, invalid, negative, decimal, pasted whitespace, and huge inputs
{
  const empty = StockQty.resolveRowReplace({ rawInput: '   ', currentQty: 4, poolStock: 95, usedInOtherRowsForPool: 0, perLineCap: 99 });
  assert.equal(empty.qty, 4);
  assert.equal(empty.reason, 'empty');

  const neg = StockQty.resolveRowReplace({ rawInput: '-5', currentQty: 4, poolStock: 95, usedInOtherRowsForPool: 0, perLineCap: 99 });
  assert.equal(neg.qty, 4);
  assert.equal(neg.reason, 'below_min');

  const zero = StockQty.resolveRowReplace({ rawInput: '0', currentQty: 4, poolStock: 95, usedInOtherRowsForPool: 0, perLineCap: 99 });
  assert.equal(zero.qty, 4);
  assert.equal(zero.reason, 'below_min');

  const dec = StockQty.resolveRowReplace({ rawInput: '3.5', currentQty: 4, poolStock: 95, usedInOtherRowsForPool: 0, perLineCap: 99 });
  assert.equal(dec.qty, 4);
  assert.equal(dec.reason, 'invalid');

  const alpha = StockQty.resolveRowReplace({ rawInput: 'abc', currentQty: 4, poolStock: 95, usedInOtherRowsForPool: 0, perLineCap: 99 });
  assert.equal(alpha.qty, 4);
  assert.equal(alpha.reason, 'invalid');

  const huge = StockQty.resolveRowReplace({ rawInput: '99999999999999999999', currentQty: 4, poolStock: 95, usedInOtherRowsForPool: 0, perLineCap: 99 });
  assert.equal(huge.qty, 95);
  assert.equal(huge.reason, 'stock_limit');

  const pastedSpace = StockQty.resolveRowReplace({ rawInput: ' 12 ', currentQty: 4, poolStock: 95, usedInOtherRowsForPool: 0, perLineCap: 99 });
  assert.equal(pastedSpace.qty, 12);
  assert.equal(pastedSpace.adjusted, false);
}

// 8. POS JS (pos.js + app.js kkNotify) cart/stock/pool/input/notification integration in sandboxed DOM
{
  const fs = require('node:fs');
  const path = require('node:path');
  const vm = require('node:vm');

  function makeEl(tag = 'div', id = '') {
    const attrs = new Map();
    const listeners = new Map();
    const el = {
      tagName: tag.toUpperCase(),
      id,
      className: '',
      value: '',
      textContent: '',
      innerHTML: '',
      disabled: false,
      hidden: false,
      dataset: {},
      children: [],
      style: {},
      classList: {
        _set: new Set(),
        add(...c) { c.forEach(x => { this._set.add(x); }); el.className = [...this._set].join(' '); },
        remove(...c) { c.forEach(x => { this._set.delete(x); }); el.className = [...this._set].join(' '); },
        toggle(c, force) {
          const on = force !== undefined ? Boolean(force) : !this._set.has(c);
          if (on) this._set.add(c); else this._set.delete(c);
          el.className = [...this._set].join(' ');
          return on;
        },
        contains(c) { return this._set.has(c); }
      },
      setAttribute(k, v) { attrs.set(k, String(v)); },
      getAttribute(k) { return attrs.has(k) ? attrs.get(k) : null; },
      appendChild(child) { this.children.push(child); child.parentNode = this; return child; },
      remove() {
        if (this.parentNode) {
          this.parentNode.children = this.parentNode.children.filter(c => c !== this);
        }
      },
      addEventListener(type, fn) {
        if (!listeners.has(type)) listeners.set(type, []);
        listeners.get(type).push(fn);
      },
      dispatchEvent(ev) {
        for (const fn of (listeners.get(ev.type) || [])) fn(ev);
      },
      querySelector() { return null; },
      querySelectorAll() { return []; },
      closest() { return null; },
      focus() {},
      blur() {}
    };
    return el;
  }

  const byId = new Map();
  const NO_AUTO_IDS = new Set(['kkToastContainer', 'posNotificationOverlay', 'customModal', 'discountModal']);
  const getEl = (id) => {
    if (!byId.has(id) && !NO_AUTO_IDS.has(id)) {
      byId.set(id, makeEl('div', id));
    }
    return byId.get(id) || null;
  };
  const cartItemsEl = getEl('cartItems');

  const doc = {
    head: makeEl('head'),
    body: makeEl('body'),
    createElement(tag) { return makeEl(tag); },
    getElementById: getEl,
    querySelector() { return null; },
    querySelectorAll() { return []; },
    addEventListener() {}
  };

  const ctx = {
    window: null,
    document: doc,
    console,
    setTimeout: () => 1,
    clearTimeout: () => {},
    setInterval: () => 1,
    clearInterval: () => {},
    requestAnimationFrame: cb => cb(),
    addEventListener: () => {},
    removeEventListener: () => {},
    fetch: async () => ({ ok: true, json: async () => ({ ok: true, orders: [] }) }),
    BASE_URL: '/kenjis-kitchen',
    IS_PRODUCTION: false,
    POS_CSRF_TOKEN: 'test-csrf',
    KKStockQty: StockQty,
    Number, String, Math, Array, Object, Set, Map, JSON, Date, RegExp, Intl, parseInt, parseFloat, isNaN, isFinite,
    Boolean, Error, Event: function(t) { this.type = t; }
  };
  ctx.window = ctx;
  ctx.globalThis = ctx;

  vm.createContext(ctx);
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8'), ctx);
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/pos.js'), 'utf8'), ctx);

  // Verify app.js kkNotify deduplication & max-2 stack
  ctx.kkNotify('Stock limit', 'warning', { title: 'Limit', dedupeKey: 'dup-pos' });
  ctx.kkNotify('Stock limit', 'warning', { title: 'Limit', dedupeKey: 'dup-pos' });
  ctx.kkNotify('Msg 2', 'info', { title: 'Info', dedupeKey: 'm2' });
  ctx.kkNotify('Msg 3', 'info', { title: 'Info', dedupeKey: 'm3' });
  const toastContainer = byId.get('kkToastContainer') || doc.body.children.find(c => c.id === 'kkToastContainer');
  assert.ok(toastContainer && toastContainer.children.length <= 2, 'POS toast stack capped at <= 2 and deduplicated');

  // Seed virtual DOM container tracking for POS row feedback tests
  const posInput = makeEl('input');
  posInput.dataset.idx = '0';
  cartItemsEl.querySelectorAll = (sel) => {
    if (sel.includes('dirty')) return posInput.dataset.dirty === '1' ? [posInput] : [];
    return [];
  };

  // POS shared packaging pool test: Spaghetti Tray (S) + Spaghetti Bilao (S) both share id 51 (stock 6)
  ctx.addToCart('51', 'Spaghetti Tray (S)', 500, 6, 'Available', '6', { packaging: 'Tray', variantSize: 'S' });
  ctx.addToCart('51', 'Spaghetti Tray (S)', 500, 6, 'Available', '6', { packaging: 'Tray', variantSize: 'S' });
  ctx.addToCart('51', 'Spaghetti Tray (S)', 500, 6, 'Available', '6', { packaging: 'Tray', variantSize: 'S' });
  ctx.addToCart('51', 'Spaghetti Tray (S)', 500, 6, 'Available', '6', { packaging: 'Tray', variantSize: 'S' });
  ctx.addToCart('51', 'Spaghetti Bilao (S)', 500, 6, 'Available', '6', { packaging: 'Bilao', variantSize: 'S' });
  ctx.addToCart('51', 'Spaghetti Bilao (S)', 500, 6, 'Available', '6', { packaging: 'Bilao', variantSize: 'S' });
  // 7th add must NOT exceed pool stock 6!
  ctx.addToCart('51', 'Spaghetti Bilao (S)', 500, 6, 'Available', '6', { packaging: 'Bilao', variantSize: 'S' });
  assert.equal(vm.runInContext('cart.length', ctx), 2);
  assert.equal(vm.runInContext('cart.reduce((s, r) => s + r.qty, 0)', ctx), 6);

  // Decreasing Spaghetti Bilao (S) to 0 must NOT delete Spaghetti Tray (S)!
  ctx.decreaseFromCart('51', 'Spaghetti Bilao (S)');
  ctx.decreaseFromCart('51', 'Spaghetti Bilao (S)');
  assert.equal(vm.runInContext('cart.length', ctx), 1);
  assert.equal(vm.runInContext('cart[0].name', ctx), 'Spaghetti Tray (S)');
  assert.equal(vm.runInContext('cart[0].qty', ctx), 4);

  // Independent Bilao size pools: Spaghetti Small (51, stock 6) vs Spaghetti Medium (136, stock 4)
  ctx.addToCart('136', 'Spaghetti Tray (M)', 850, 4, 'Available', '6', { packaging: 'Tray', variantSize: 'M' });
  assert.equal(ctx.usedInPosCartForPool('menu:51'), 4);
  assert.equal(ctx.usedInPosCartForPool('menu:136'), 1);

  // Independent Bilao pools: Carbonara L (78, stock 50) vs Carbonara XL (79, stock 5)
  ctx.addToCart('78', 'Carbonara Bilao (L)', 1200, 50, 'Available', '6', { packaging: 'Bilao', variantSize: 'L' });
  ctx.addToCart('79', 'Carbonara Bilao (XL)', 1550, 5, 'Available', '6', { packaging: 'Bilao', variantSize: 'XL' });
  assert.equal(ctx.usedInPosCartForPool('menu:78'), 1);
  assert.equal(ctx.usedInPosCartForPool('menu:79'), 1);

  // POS typing 100 on Burgersteak Rice (stock 95) -> capped at 95, accurate message, no blocking modal overlay
  ctx.clearAllOrder(true);
  ctx.addToCart('1', 'Burgersteak Rice', 55, 95, 'Available', '1');
  posInput.value = '100';
  posInput.dataset.dirty = '1';
  ctx.commitPosCartQtyInput(0, posInput, { force: true });
  // Simulate immediate blur right after Enter -> dirty is '0', no duplicate commit
  ctx.commitPosCartQtyInput(0, posInput, { force: false });
  assert.equal(vm.runInContext('cart[0].qty', ctx), 95);
  const posMsg0 = vm.runInContext('posCartRowFeedback[0]', ctx);
  assert.ok(posMsg0 && posMsg0.text.includes('95') && !posMsg0.text.includes('85 more'));
  assert.equal(byId.get('posNotificationOverlay'), undefined, 'POS stock cap must not create blocking overlay');

  // POS allows > 99 when stock permits (e.g. Plain Rice stock 199 -> 150)
  ctx.clearAllOrder(true);
  ctx.addToCart('9', 'Plain Rice', 20, 199, 'Available', '1');
  posInput.value = '150';
  posInput.dataset.dirty = '1';
  ctx.commitPosCartQtyInput(0, posInput, { force: true });
  assert.equal(vm.runInContext('cart[0].qty', ctx), 150);

  // POS stock refresh syncs cart down cleanly without wiping unrelated items
  ctx.clearAllOrder(true);
  ctx.addToCart('1', 'Burgersteak Rice', 55, 95, 'Available', '1');
  for (let i = 0; i < 9; i++) ctx.addToCart('1', 'Burgersteak Rice', 55, 95, 'Available', '1');
  ctx.addToCart('9', 'Plain Rice', 20, 199, 'Available', '1');
  ctx.syncPosStockUpdates([{ id: 1, name: 'Burgersteak Rice', stock: 3 }]);
  assert.equal(vm.runInContext('cart[0].qty', ctx), 3);
  assert.equal(vm.runInContext('cart[1].qty', ctx), 1);
  assert.ok(byId.get('posCartStockBanner').textContent.includes('Burgersteak Rice'));
}

console.log('PASS: all KKStockQty unit + POS cart/pool assertions passed.');
