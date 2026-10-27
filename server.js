'use strict';

/**
 * Kenji's Kitchen - Consolidated Realtime & REST API Server (v1.1.0)
 *
 * Features:
 * - Express REST API: Menu management, soft-archiving, atomic order placement, stock sync, and OTP
 * - Native WebSocket Server: Live cashier carts, immediate KDS/POS synchronization
 * - HMAC-SHA256 JWT authorization for WebSocket clients
 * - Realtime inventory checks & deductions
 * - Zero hard deletes: strict is_archived = 1 and status = 'Inactive'
 */

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { URL } = require('url');
const mysql = require('mysql2/promise');

// Node does not load .env automatically. Load it before initializing services
// so SMTP credentials configured for this project are available to Nodemailer.
try {
  const envText = fs.readFileSync(path.join(__dirname, '.env'), 'utf8');
  for (const line of envText.split(/\r?\n/)) {
    const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
    if (!match || match[1].startsWith('#') || process.env[match[1]] !== undefined) continue;
    let value = match[2];
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    process.env[match[1]] = value;
  }
} catch (_) {
  // Environment variables may be supplied directly by the service manager.
}

const otpService = require('./otpService');

// Environment & Configuration
const port = Number(process.env.PORT || process.env.REALTIME_PORT || 8787);
const host = process.env.REALTIME_HOST || '0.0.0.0';
const secret = process.env.REALTIME_SHARED_SECRET || 'kenjis_kitchen_super_secret_key_32_characters_minimum_len_2026';

if (secret.length < 32) {
  console.error('FATAL: REALTIME_SHARED_SECRET must be at least 32 characters long.');
  process.exit(1);
}

// Database Connection Pool
const dbConfig = {
  host: process.env.DB_HOST || '127.0.0.1',
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'kenjis_kitchen',
  waitForConnections: true,
  connectionLimit: 15,
  queueLimit: 0
};

let pool = null;
function getPool() {
  if (!pool) {
    pool = mysql.createPool(dbConfig);
  }
  return pool;
}

// Active WebSockets & POS Monitoring Store
const sockets = new Set();
const activeCarts = new Map();

const decodeBase64 = val => Buffer.from(val.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
const encodeBase64 = val => Buffer.from(val).toString('base64');

/**
 * Verify JWT Token for WebSocket connections
 */
function verifyToken(token) {
  const parts = String(token || '').split('.');
  if (parts.length !== 3) return null;
  const expected = crypto.createHmac('sha256', secret).update(`${parts[0]}.${parts[1]}`).digest();
  const actual = Buffer.from(parts[2].replace(/-/g, '+').replace(/_/g, '/'), 'base64');
  if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) return null;
  try {
    const header = JSON.parse(decodeBase64(parts[0]));
    if (header.alg !== 'HS256' || header.typ !== 'JWT') return null;
    const claims = JSON.parse(decodeBase64(parts[1]));
    if (!claims.uid || claims.exp < Math.floor(Date.now() / 1000) || !['admin', 'manager', 'cashier', 'kitchen'].includes(claims.role)) return null;
    return claims;
  } catch (_) { return null; }
}

/**
 * Construct WebSocket frame
 */
function frame(text) {
  const data = Buffer.from(text);
  let header;
  if (data.length < 126) {
    header = Buffer.from([0x81, data.length]);
  } else if (data.length < 65536) {
    header = Buffer.alloc(4); header[0] = 0x81; header[1] = 126; header.writeUInt16BE(data.length, 2);
  } else {
    header = Buffer.alloc(10); header[0] = 0x81; header[1] = 127; header.writeBigUInt64BE(BigInt(data.length), 2);
  }
  return Buffer.concat([header, data]);
}

function sendToClient(client, event, payload) {
  if (!client.socket.destroyed) {
    client.socket.write(frame(JSON.stringify({ event, payload })));
  }
}

function broadcast(event, payload) {
  for (const client of sockets) {
    sendToClient(client, event, payload);
  }
}

/**
 * Handle incoming WebSocket frames
 */
function readFrames(client, chunk) {
  client.buffer = Buffer.concat([client.buffer, chunk]);
  while (client.buffer.length >= 2) {
    const first = client.buffer[0], second = client.buffer[1];
    const opcode = first & 0x0f, masked = Boolean(second & 0x80);
    let length = second & 0x7f, offset = 2;
    if (length === 126) {
      if (client.buffer.length < 4) return;
      length = client.buffer.readUInt16BE(2); offset = 4;
    } else if (length === 127) {
      if (client.buffer.length < 10) return;
      const big = client.buffer.readBigUInt64BE(2);
      if (big > 65536n) { client.socket.destroy(); return; }
      length = Number(big); offset = 10;
    }
    const maskBytes = masked ? 4 : 0;
    if (client.buffer.length < offset + maskBytes + length) return;
    let data = client.buffer.subarray(offset + maskBytes, offset + maskBytes + length);
    if (masked) {
      const mask = client.buffer.subarray(offset, offset + 4);
      data = Buffer.from(data.map((byte, idx) => byte ^ mask[idx % 4]));
    }
    client.buffer = client.buffer.subarray(offset + maskBytes + length);

    if (opcode === 8) { client.socket.end(); return; }
    if (opcode === 9) { client.socket.write(Buffer.from([0x8a, 0])); continue; }
    if (opcode !== 1 || client.claims.role !== 'cashier') continue;

    let message;
    try { message = JSON.parse(data.toString('utf8')); } catch (_) { continue; }
    const device = String(message.device_id || 'default').slice(0, 80);
    const key = `${client.claims.uid}:${device}`;

    if (message.event === 'pos:cart_updated' && Array.isArray(message.payload?.items)) {
      const items = message.payload.items.slice(0, 100).map(item => ({
        name: String(item.name || '').slice(0, 100),
        quantity: Math.max(0, Math.min(999, Number(item.quantity) || 0)),
        price: Math.max(0, Number(item.price) || 0)
      }));
      const cart = {
        cashier_id: client.claims.uid,
        cashier: String(client.claims.username),
        device_id: device,
        items,
        updated_at: new Date().toISOString()
      };
      activeCarts.set(key, { cart, expires: Date.now() + 60000 });
      broadcast('pos:cart_updated', cart);
    } else if (message.event === 'pos:cart_cleared') {
      activeCarts.delete(key);
      broadcast('pos:cart_cleared', { cashier_id: client.claims.uid, device_id: device });
    }
  }
}

/**
 * Helper to parse JSON body
 */
function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > 1024 * 1024) { req.destroy(); reject(new Error('Payload too large')); }
    });
    req.on('end', () => {
      try {
        resolve(body ? JSON.parse(body) : {});
      } catch (err) {
        reject(err);
      }
    });
  });
}

function sendJson(res, statusCode, data) {
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=UTF-8',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PATCH, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Realtime-Secret'
  });
  res.end(JSON.stringify(data));
}

/**
 * Main HTTP Server & REST API Router
 */
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const method = req.method;

  // Handle CORS preflight
  if (method === 'OPTIONS') {
    res.writeHead(200, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PATCH, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Realtime-Secret'
    });
    res.end();
    return;
  }

  // 1. Health Check
  if (url.pathname === '/health') {
    sendJson(res, 200, { ok: true, timestamp: new Date().toISOString() });
    return;
  }

  // Private mail bridge for the PHP ordering/auth endpoints. Loopback and the
  // shared secret are both required; the browser never receives that secret.
  if (url.pathname === '/internal/email/send' && method === 'POST') {
    const remote = req.socket.remoteAddress || '';
    if (!['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(remote)) {
      sendJson(res, 403, { ok: false });
      return;
    }
    const supplied = Buffer.from(String(req.headers['x-realtime-secret'] || ''));
    const expected = Buffer.from(secret);
    if (supplied.length !== expected.length || !crypto.timingSafeEqual(supplied, expected)) {
      sendJson(res, 403, { ok: false });
      return;
    }
    try {
      const body = await readJsonBody(req);
      const to = String(body.to || '').trim().slice(0, 254);
      const subject = String(body.subject || '').trim().replace(/[\r\n]+/g, ' ').slice(0, 180);
      const text = String(body.text || '').slice(0, 20000);
      const html = String(body.html || '').slice(0, 50000);
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to) || !subject || (!text && !html)) {
        sendJson(res, 400, { ok: false });
        return;
      }
      await otpService.sendEmailMessage({ to, subject, text, html });
      sendJson(res, 200, { ok: true });
    } catch (err) {
      const errorCode = String(err.code || '');
      const failure = errorCode === 'EAUTH'
        ? 'smtp_auth'
        : ['ECONNECTION', 'ETIMEDOUT', 'ESOCKET', 'ENOTFOUND'].includes(errorCode)
          ? 'smtp_connection'
          : /credentials are missing or invalid/i.test(String(err.message || ''))
            ? 'smtp_unconfigured'
            : 'smtp_rejected';
      console.error(`[MAIL SERVICE] ${failure} (${errorCode || 'unknown'}):`, err.message);
      sendJson(res, 503, { ok: false, code: failure });
    }
    return;
  }

  // 2. Internal Realtime Event Webhook (Used by PHP backend)
  if (url.pathname === '/internal/publish' && method === 'POST') {
    const remote = req.socket.remoteAddress || '';
    if (!['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(remote)) {
      sendJson(res, 403, { ok: false, error: 'Forbidden' });
      return;
    }
    const supplied = Buffer.from(String(req.headers['x-realtime-secret'] || ''));
    const expected = Buffer.from(secret);
    if (supplied.length !== expected.length || !crypto.timingSafeEqual(supplied, expected)) {
      sendJson(res, 403, { ok: false, error: 'Invalid secret' });
      return;
    }
    try {
      const body = await readJsonBody(req);
      const eventName = String(body.event || '').slice(0, 80);
      broadcast(eventName, body.payload || {});
      sendJson(res, 202, { ok: true });
    } catch (_) {
      sendJson(res, 400, { ok: false });
    }
    return;
  }

  // 3. API: Menu Categories
  if (url.pathname === '/api/menu/categories' && method === 'GET') {
    try {
      const db = getPool();
      const [rows] = await db.query("SELECT id, category_name AS name, sort_order FROM categories WHERE status = 'Active' ORDER BY sort_order ASC, category_name ASC");
      sendJson(res, 200, { status: 'success', data: rows });
    } catch (err) {
      sendJson(res, 500, { status: 'error', message: err.message });
    }
    return;
  }

  // 4. API: Active Menu Items (Excludes archived, returns stock and promo)
  if (url.pathname === '/api/menu/items' && method === 'GET') {
    try {
      const db = getPool();
      const categoryId = url.searchParams.get('category_id');
      const search = (url.searchParams.get('q') || '').trim();

      let query = `
        SELECT m.id, m.category_id, c.category_name, m.item_name AS name, m.description,
               m.price, m.promo_price, m.promo_start, m.promo_end, m.image, m.availability, m.stock,
               m.is_archived
        FROM menu_items m
        JOIN categories c ON c.id = m.category_id
        WHERE m.is_archived = 0
      `;
      const params = [];

      if (categoryId && categoryId !== 'all') {
        query += " AND m.category_id = ?";
        params.push(Number(categoryId));
      }
      if (search) {
        query += " AND (m.item_name LIKE ? OR m.description LIKE ?)";
        params.push(`%${search}%`, `%${search}%`);
      }

      query += " ORDER BY c.sort_order ASC, m.sort_order ASC, m.item_name ASC";
      const [rows] = await db.query(query, params);

      const today = new Date().toISOString().slice(0, 10);
      const items = rows.map(item => {
        const price = Number(item.price);
        const hasPromo = item.promo_price !== null && item.promo_start && item.promo_end && today >= item.promo_start && today <= item.promo_end;
        const effectivePrice = hasPromo ? Number(item.promo_price) : price;
        return {
          id: item.id,
          category_id: item.category_id,
          category_name: item.category_name,
          name: item.name,
          description: item.description,
          price,
          effective_price: effectivePrice,
          is_promo: hasPromo,
          stock: Number(item.stock),
          availability: item.availability,
          is_available: Number(item.stock) > 0 && item.availability === 'Available' ? 1 : 0,
          image: item.image
        };
      });

      sendJson(res, 200, { status: 'success', data: items });
    } catch (err) {
      sendJson(res, 500, { status: 'error', message: err.message });
    }
    return;
  }

  // 5. API: Soft-Archiving (DECOMMISSIONED - Use PHP modules/menu/index.php & approvals workflow)
  const archiveMatch = url.pathname.match(/^\/api\/menu\/items\/(\d+)\/archive$/);
  if (archiveMatch && method === 'PATCH') {
    sendJson(res, 410, {
      status: 'error',
      code: 'ENDPOINT_DECOMMISSIONED',
      message: 'This endpoint has been decommissioned. Menu archiving requires authentication and Admin dual-control approval via the PHP management interface.'
    });
    return;
  }

  // 6. API: Restore Archived Item (DECOMMISSIONED - Use PHP modules/menu/index.php & approvals workflow)
  const restoreMatch = url.pathname.match(/^\/api\/menu\/items\/(\d+)\/restore$/);
  if (restoreMatch && method === 'PATCH') {
    sendJson(res, 410, {
      status: 'error',
      code: 'ENDPOINT_DECOMMISSIONED',
      message: 'This endpoint has been decommissioned. Menu restoration requires authentication and Admin dual-control approval via the PHP management interface.'
    });
    return;
  }

  // 7. API: Real-Time Stock Status
  if (url.pathname === '/api/inventory/stock' && method === 'GET') {
    try {
      const db = getPool();
      const [rows] = await db.query("SELECT id, item_name, stock, availability, is_archived FROM menu_items WHERE is_archived = 0");
      sendJson(res, 200, { status: 'success', data: rows });
    } catch (err) {
      sendJson(res, 500, { status: 'error', message: err.message });
    }
    return;
  }

  // 8. API: Real-Time Order Placement (DECOMMISSIONED - Use authenticated PHP storefront API)
  if (url.pathname === '/api/orders' && method === 'POST') {
    sendJson(res, 410, {
      status: 'error',
      code: 'ENDPOINT_DECOMMISSIONED',
      message: 'This endpoint has been decommissioned. Online orders must be submitted through the authenticated PHP storefront API (modules/online/api/orders.php) which enforces GCash reference verification, stock deduction, and CSRF protection.'
    });
    return;
  }

  // 9. API: Order Status Update (DECOMMISSIONED - Use authenticated PHP KDS/Serving workflow)
  const statusMatch = url.pathname.match(/^\/api\/orders\/(\d+)\/status$/);
  if (statusMatch && method === 'PATCH') {
    sendJson(res, 410, {
      status: 'error',
      code: 'ENDPOINT_DECOMMISSIONED',
      message: 'This endpoint has been decommissioned. Order status updates require authenticated staff access via the PHP KDS and Serving modules.'
    });
    return;
  }

  // 10. API: OTP Dispatch Endpoint
  if (url.pathname === '/api/auth/otp/send' && method === 'POST') {
    try {
      const body = await readJsonBody(req);
      const result = await otpService.sendOtp(body.email);
      sendJson(res, result.ok ? 200 : 400, result);
    } catch (err) {
      sendJson(res, 500, { ok: false, message: err.message });
    }
    return;
  }

  // 11. API: OTP Verification Endpoint
  if (url.pathname === '/api/auth/otp/verify' && method === 'POST') {
    try {
      const body = await readJsonBody(req);
      const result = otpService.verifyOtp(body.email, body.code);
      sendJson(res, result.ok ? 200 : 400, result);
    } catch (err) {
      sendJson(res, 500, { ok: false, message: err.message });
    }
    return;
  }

  // 404 Not Found
  sendJson(res, 404, { status: 'error', message: 'Endpoint not found' });
});

/**
 * Handle WebSocket Upgrades
 */
server.on('upgrade', (req, socket) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const claims = url.pathname === '/ws/pos-monitor' ? verifyToken(url.searchParams.get('token')) : null;
  const key = req.headers['sec-websocket-key'];

  if (!claims || !key) {
    socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
    socket.destroy();
    return;
  }

  const accept = crypto.createHash('sha1').update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64');
  socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`);

  const client = { socket, claims, buffer: Buffer.alloc(0) };
  sockets.add(client);

  socket.on('data', chunk => readFrames(client, chunk));
  socket.on('close', () => sockets.delete(client));
  socket.on('error', () => sockets.delete(client));

  if (claims.role === 'admin' || claims.role === 'manager') {
    for (const [k, value] of activeCarts) {
      if (value.expires < Date.now()) {
        activeCarts.delete(k);
      } else {
        sendToClient(client, 'pos:cart_updated', value.cart);
      }
    }
  }
});

// Clean up expired cashier carts every 15s
setInterval(() => {
  const now = Date.now();
  for (const [key, value] of activeCarts) {
    if (value.expires < now) {
      activeCarts.delete(key);
      const [cashier_id, device_id] = key.split(':');
      broadcast('pos:cart_cleared', { cashier_id: Number(cashier_id), device_id, expired: true });
    }
  }
}, 15000).unref();

server.listen(port, host, () => {
  console.log(`=======================================================`);
  console.log(` Kenji's Kitchen Consolidated Server (v1.1.0)`);
  console.log(` REST API & WebSocket service active on http://${host}:${port}`);
  console.log(`=======================================================`);
});
