const { onCall, HttpsError } = require('firebase-functions/v2/https');
const { initializeApp } = require('firebase-admin/app');
const { getFirestore, FieldValue } = require('firebase-admin/firestore');
initializeApp();
const db = getFirestore();

exports.placeOrder = onCall({ enforceAppCheck: true }, async (request) => {
  if (!request.auth) throw new HttpsError('unauthenticated', 'Sign in before placing an order.');
  const lines = request.data?.items;
  if (!Array.isArray(lines) || lines.length < 1 || lines.length > 50) throw new HttpsError('invalid-argument', 'Add between 1 and 50 menu items.');
  const normalized = new Map(); let totalQuantity = 0;
  for (const line of lines) {
    const id = String(line.id || ''); const qty = Number(line.quantity);
    if (!/^[A-Za-z0-9_-]{1,150}$/.test(id) || !Number.isInteger(qty) || qty < 1 || qty > 50) throw new HttpsError('invalid-argument', 'Invalid order item or quantity.');
    totalQuantity += qty;
    if (totalQuantity > 100) throw new HttpsError('invalid-argument', 'An order cannot exceed 100 items.');
    const variantName = line.variantName == null || line.variantName === '' ? null : String(line.variantName).slice(0, 80);
    const key = `${id}::${variantName || ''}`;
    const combined = (normalized.get(key)?.quantity || 0) + qty;
    if (combined > 50) throw new HttpsError('invalid-argument', 'A menu item quantity cannot exceed 50.');
    normalized.set(key, { id, variantName, quantity: combined });
  }
  const orderRef = db.collection('orders').doc();
  await db.runTransaction(async (tx) => {
    const settings = await tx.get(db.collection('settings').doc('public'));
    if (settings.exists && settings.data().orderingOpen === false) throw new HttpsError('failed-precondition', 'Online ordering is currently closed.');
    const ids = [...new Set([...normalized.values()].map(line => line.id))];
    const docs = await Promise.all(ids.map(id => tx.get(db.collection('menuItems').doc(id))));
    const byId = new Map(docs.map(snap => [snap.id, snap]));
    let total = 0; const items = []; const stockTotals = new Map();
    for (const line of normalized.values()) {
      const snap = byId.get(line.id); const quantity = line.quantity;
      if (!snap.exists || snap.data().active !== true) throw new HttpsError('failed-precondition', 'A selected menu item is unavailable.');
      const item = snap.data(); const price = Number(item.price);
      let unitPrice = price;
      if (line.variantName) {
        const variant = Array.isArray(item.variants) && item.variants.find(option => option.name === line.variantName);
        if (!variant) throw new HttpsError('failed-precondition', 'A selected size or option is no longer available.');
        unitPrice = Number(variant.price);
      }
      if (!Number.isFinite(unitPrice) || unitPrice < 0) throw new HttpsError('failed-precondition', 'A menu item has an invalid price.');
      stockTotals.set(snap.id, (stockTotals.get(snap.id) || 0) + quantity);
      total += unitPrice * quantity;
      items.push({ itemId: snap.id, name: String(item.name || 'Item'), ...(line.variantName ? { variantName: line.variantName } : {}), quantity, unitPrice, lineTotal: unitPrice * quantity });
    }
    for (const [id, quantity] of stockTotals) {
      const snap = byId.get(id); const item = snap.data();
      if (item.stock !== null && item.stock !== undefined && Number.isFinite(Number(item.stock))) {
        const stock = Number(item.stock);
        if (stock < quantity) throw new HttpsError('failed-precondition', `${item.name || 'Item'} is out of stock.`);
        tx.update(snap.ref, { stock: stock - quantity, updatedAt: FieldValue.serverTimestamp() });
      }
    }
    tx.create(orderRef, { customerId: request.auth.uid, customerName: request.auth.token.name || '', items, total: Number(total.toFixed(2)), status: 'Received', createdAt: FieldValue.serverTimestamp(), updatedAt: FieldValue.serverTimestamp() });
  });
  return { orderId: orderRef.id };
});
