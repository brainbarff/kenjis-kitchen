/* Shared stock-pool and quantity validation helpers for storefront and POS. */
(function (global) {
  'use strict';

  const ONLINE_MAX_LINE_QTY = 99;

  function toNonNegInt(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0) return 0;
    return Math.floor(n);
  }

  function normalizeCap(perLineCap) {
    if (perLineCap === undefined || perLineCap === null || perLineCap === Infinity) {
      return Infinity;
    }
    const n = Number(perLineCap);
    return Number.isFinite(n) && n > 0 ? Math.floor(n) : Infinity;
  }

  /**
   * Parses raw text from a quantity field without turning "-5" into "5" or "3.5" into "35".
   */
  function parseQuantityInput(rawValue) {
    const trimmed = String(rawValue ?? '').trim();
    if (trimmed === '') {
      return { kind: 'empty', value: null };
    }
    if (/^-\d+(?:\.\d+)?$/.test(trimmed)) {
      return { kind: 'below_min', value: 0 };
    }
    if (/^\d+\.\d+$/.test(trimmed)) {
      return { kind: 'decimal', value: null };
    }
    if (!/^\d+$/.test(trimmed)) {
      return { kind: 'invalid', value: null };
    }
    // Cap absurdly long digit strings safely before Number() loses precision
    if (trimmed.length > 9) {
      return { kind: 'ok', value: Number.MAX_SAFE_INTEGER };
    }
    const parsed = parseInt(trimmed, 10);
    if (!Number.isFinite(parsed) || parsed < 1) {
      return { kind: 'below_min', value: parsed };
    }
    return { kind: 'ok', value: parsed };
  }

  /**
   * Computes remaining allowance when ADDING items (menu card, quick-add, or item-detail modal).
   */
  function getAddAllowance(params) {
    const poolStock = toNonNegInt(params?.poolStock);
    const usedInBasketForPool = toNonNegInt(params?.usedInBasketForPool);
    const existingMatchingLineQty = toNonNegInt(params?.existingMatchingLineQty);
    const perLineCap = normalizeCap(params?.perLineCap);

    const remainingStock = Math.max(0, poolStock - usedInBasketForPool);
    const remainingLineRoom = Number.isFinite(perLineCap)
      ? Math.max(0, perLineCap - existingMatchingLineQty)
      : Infinity;
    const maxAdd = Math.max(0, Math.min(remainingStock, remainingLineRoom));
    const limitedByLineCap = Number.isFinite(perLineCap) && remainingLineRoom < remainingStock;

    return {
      poolStock,
      usedInBasketForPool,
      existingMatchingLineQty,
      perLineCap,
      remainingStock,
      remainingLineRoom,
      maxAdd,
      limitedByLineCap
    };
  }

  function formatAddLimitMessage(allowance) {
    if (!allowance || allowance.poolStock <= 0) {
      return 'Out of stock.';
    }
    if (allowance.remainingStock <= 0) {
      return allowance.usedInBasketForPool > 0
        ? `All ${allowance.poolStock} available are in your basket.`
        : 'Out of stock.';
    }
    if (allowance.limitedByLineCap) {
      if (allowance.existingMatchingLineQty > 0) {
        return allowance.maxAdd > 0
          ? `Maximum ${allowance.perLineCap} per item line (${allowance.existingMatchingLineQty} in this row; you can add up to ${allowance.maxAdd} more).`
          : `Maximum ${allowance.perLineCap} per item line reached (add a note to start a separate row).`;
      }
      return `Maximum ${allowance.perLineCap} per item line (${allowance.poolStock} in stock).`;
    }
    if (allowance.usedInBasketForPool > 0) {
      return `You can add up to ${allowance.remainingStock} more.`;
    }
    return `Only ${allowance.remainingStock} available.`;
  }

  /**
   * Resolves REPLACING an existing basket/cart row's quantity.
   * Uses poolStock minus OTHER rows sharing the same stock pool (never subtracting this row's old qty).
   */
  function resolveRowReplace(params) {
    const poolStock = toNonNegInt(params?.poolStock);
    const usedInOtherRowsForPool = toNonNegInt(params?.usedInOtherRowsForPool);
    const perLineCap = normalizeCap(params?.perLineCap);
    const availableForRow = Math.max(0, poolStock - usedInOtherRowsForPool);
    const effectiveMax = Number.isFinite(perLineCap)
      ? Math.min(availableForRow, perLineCap)
      : availableForRow;

    const fallbackQty = Math.max(
      effectiveMax > 0 ? 1 : 0,
      Math.min(toNonNegInt(params?.currentQty) || 1, Math.max(1, effectiveMax))
    );

    const parsed = parseQuantityInput(params?.rawInput);

    if (effectiveMax <= 0) {
      return {
        qty: 0,
        availableForRow,
        effectiveMax: 0,
        adjusted: true,
        atMax: false,
        reason: 'out_of_stock',
        message: 'No stock remaining for this item.'
      };
    }

    if (parsed.kind === 'empty') {
      return {
        qty: fallbackQty,
        availableForRow,
        effectiveMax,
        adjusted: false,
        atMax: fallbackQty >= effectiveMax,
        reason: 'empty',
        message: ''
      };
    }

    if (parsed.kind === 'decimal' || parsed.kind === 'invalid') {
      return {
        qty: fallbackQty,
        availableForRow,
        effectiveMax,
        adjusted: true,
        atMax: fallbackQty >= effectiveMax,
        reason: 'invalid',
        message: 'Enter a whole number.'
      };
    }

    if (parsed.kind === 'below_min') {
      return {
        qty: fallbackQty,
        availableForRow,
        effectiveMax,
        adjusted: true,
        atMax: fallbackQty >= effectiveMax,
        reason: 'below_min',
        message: 'Quantity must be at least 1. Use Remove to delete this item.'
      };
    }

    if (parsed.value > effectiveMax) {
      const stockLimited = !Number.isFinite(perLineCap) || availableForRow <= perLineCap;
      let message;
      if (stockLimited) {
        message = usedInOtherRowsForPool > 0
          ? `Adjusted to ${effectiveMax}. ${usedInOtherRowsForPool} in other rows.`
          : `Adjusted to ${effectiveMax} (stock limit).`;
      } else {
        message = `Adjusted to ${effectiveMax} (limit per row). ${availableForRow} in stock.`;
      }
      return {
        qty: effectiveMax,
        availableForRow,
        effectiveMax,
        adjusted: true,
        atMax: true,
        reason: stockLimited ? 'stock_limit' : 'line_cap',
        message
      };
    }

    return {
      qty: parsed.value,
      availableForRow,
      effectiveMax,
      adjusted: false,
      atMax: parsed.value >= effectiveMax,
      reason: 'ok',
      message: ''
    };
  }

  /**
   * Aggregates total quantity in a cart array for a given poolKey, optionally excluding one row index.
   */
  function sumPoolQty(cartLines, getPoolKey, targetPoolKey, excludeIndex = -1) {
    if (!Array.isArray(cartLines) || !targetPoolKey) return 0;
    let total = 0;
    for (let i = 0; i < cartLines.length; i++) {
      if (i === excludeIndex) continue;
      const line = cartLines[i];
      if (!line) continue;
      if (getPoolKey(line) === targetPoolKey) {
        total += toNonNegInt(line.qty);
      }
    }
    return total;
  }

  const api = {
    ONLINE_MAX_LINE_QTY,
    parseQuantityInput,
    getAddAllowance,
    formatAddLimitMessage,
    resolveRowReplace,
    sumPoolQty
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.KKStockQty = api;
})(typeof window !== 'undefined' ? window : globalThis);
