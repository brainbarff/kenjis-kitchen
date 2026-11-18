// Kitchen Display System (KDS) - Modern 3-Column Ticket Board
const ticketsPending = document.getElementById('ticketsPending');
const ticketsPreparing = document.getElementById('ticketsPreparing');
const ticketsReady = document.getElementById('ticketsReady');

const countPending = document.getElementById('countPending');
const countPreparing = document.getElementById('countPreparing');
const countReady = document.getElementById('countReady');
const kdsTotalActive = document.getElementById('kdsTotalActive');
const kdsClock = document.getElementById('kdsClock');

let knownOnlineOrderIds = null;

function updateClock() {
    if (kdsClock) {
        const now = new Date();
        kdsClock.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
}
setInterval(updateClock, 1000);
updateClock();

function alertForNewOnlineOrders(orders) {
    const ids = orders
        .filter(order => order.order_channel === 'ONLINE')
        .map(order => String(order.id));
    if (knownOnlineOrderIds !== null && ids.some(id => !knownOnlineOrderIds.has(id))) {
        window.KenjiOrderSounds?.play('online');
    }
    knownOnlineOrderIds = new Set(ids);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatElapsed(createdAt) {
    if (!createdAt) return '0m';
    const timestamp = new Date(String(createdAt).replace(' ', 'T')).getTime();
    const mins = Math.max(0, Math.floor((Date.now() - timestamp) / 60000));
    return `${mins}m`;
}

function formatOrderTime(createdAt) {
    if (!createdAt) return '';
    try {
        const d = new Date(String(createdAt).replace(' ', 'T'));
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
        return '';
    }
}

function getTableOrChannelBadge(order) {
    if (order.order_type === 'ONLINE') {
        let sub = 'Kenji Rider';
        if (order.delivery_mode === 'CUSTOMER_BOOKED') {
            sub = String(order.courier_booking_by || '').toLowerCase() === 'store'
                ? 'Store-Booked Courier'
                : 'Customer Rider';
        }
        return `<span class="kds-table-badge"><i class="bi bi-truck"></i> Delivery (${sub})</span>`;
    }
    if (order.order_type === 'DINE-IN') {
        return `<span class="kds-table-badge"><i class="bi bi-geo-alt-fill"></i> Table ${escapeHtml(order.table_no || 'TBD')}</span>`;
    }
    if (order.order_channel === 'ONLINE' || order.fulfillment_mode === 'PICKUP') {
        return `<span class="kds-table-badge"><i class="bi bi-bag-check-fill"></i> Online Pickup</span>`;
    }
    return `<span class="kds-table-badge"><i class="bi bi-bag-check-fill"></i> Take-Out</span>`;
}

function renderTicket(order) {
    const statusClass = String(order.status || '').toLowerCase();
    const itemsList = Array.isArray(order.items)
        ? order.items.map(item => `
            <li class="kds-item-row">
                <span class="kds-item-qty">${escapeHtml(item.quantity)}x</span>
                <div class="kds-item-name">
                    ${escapeHtml(item.item_name)}
                    ${item.notes ? `<span class="kds-item-note"><i class="bi bi-info-circle"></i> ${escapeHtml(item.notes)}</span>` : ''}
                </div>
            </li>
        `).join('')
        : '';

    let actionBtn = '';
    if (order.status === 'Pending') {
        actionBtn = `
            <button class="kds-ticket-btn btn-start-prep" data-status="Preparing" data-id="${escapeHtml(order.id)}">
                <i class="bi bi-play-fill"></i> Start Preparing
            </button>
        `;
    } else if (order.status === 'Preparing') {
        actionBtn = `
            <button class="kds-ticket-btn btn-mark-ready" data-status="Ready" data-id="${escapeHtml(order.id)}">
                <i class="bi bi-check-circle-fill"></i> Mark Ready
            </button>
        `;
    }

    const cleanNotes = String(order.notes || '')
        .replace(/^\[Courier booking:[^\]]+\]\s*/i, '')
        .trim();

    return `
        <article class="kds-ticket ticket-${escapeHtml(statusClass)}" data-order-id="${escapeHtml(order.id)}">
            <div class="kds-ticket-strip"><span>KENJI’S KITCHEN</span><span>${order.status === 'Pending' ? 'New order' : order.status === 'Preparing' ? 'Preparing' : 'Ready to serve'}</span></div>
            <div>
                <div class="kds-ticket-top">
                    ${getTableOrChannelBadge(order)}
                    <span class="kds-timer-badge">
                        <i class="bi bi-clock" aria-hidden="true"></i> ${formatElapsed(order.created_at)}
                    </span>
                </div>
                <div class="kds-ticket-id kds-order-no">${order.order_no ? escapeHtml(order.order_no) : ('ORD-' + escapeHtml(order.id))}</div>
                <div class="kds-ticket-meta">
                    <span class="kds-customer-name"><i class="bi bi-person"></i> ${escapeHtml(order.customer_name || 'Walk-in Customer')}</span>
                    <span>•</span>
                    <span><i class="bi bi-clock"></i> ${formatOrderTime(order.created_at)}</span>
                </div>
            </div>

            ${cleanNotes ? `
                <div class="kds-order-note">
                    <i class="bi bi-chat-left-dots-fill"></i>
                    <div>
                        <strong>Kitchen notes</strong>
                        <div>${escapeHtml(cleanNotes)}</div>
                    </div>
                </div>
            ` : ''}

            <div class="kds-items-heading">Order items <span>${Array.isArray(order.items) ? order.items.reduce((total, item) => total + (Number(item.quantity) || 0), 0) : 0} total</span></div>
            <ul class="kds-items-list">
                ${itemsList}
            </ul>

            <div style="margin-top:auto;padding-top:8px;">
                ${actionBtn || '<div class="kds-ready-label"><i class="bi bi-check-circle-fill"></i> Ready for serving or handoff</div>'}
            </div>
        </article>
    `;
}

function renderEmptyState(label) {
    const states = {
        'new pending': ['receipt', 'No new orders', 'Incoming kitchen orders will appear here.'],
        'in-progress preparing': ['fire', 'Nothing cooking yet', 'Orders move here when preparation starts.'],
        'ready to serve': ['bell', 'No orders ready yet', 'Completed preparations will appear here for serving or handoff.']
    };
    const [icon, title, description] = states[label] || states['new pending'];
    return '<div class="kds-empty-col"><i class="bi bi-' + icon + '" aria-hidden="true"></i><p>' + title + '</p><small>' + description + '</small></div>';
}

async function loadOrders() {
    try {
        const res = await fetch('fetch_orders.php');
        const orders = await res.json();
        const safeOrders = Array.isArray(orders) ? orders : [];

        alertForNewOnlineOrders(safeOrders);

        // Segregate into the 3 columns:
        const pendingOrders = safeOrders.filter(o => o.status === 'Pending');
        const preparingOrders = safeOrders.filter(o => o.status === 'Preparing');
        const readyOrders = safeOrders.filter(o => o.status === 'Ready');

        if (kdsTotalActive) kdsTotalActive.textContent = safeOrders.length;
        if (countPending) countPending.textContent = pendingOrders.length;
        if (countPreparing) countPreparing.textContent = preparingOrders.length;
        if (countReady) countReady.textContent = readyOrders.length;

        // Populate Column 1: New Orders
        if (ticketsPending) {
            ticketsPending.innerHTML = pendingOrders.length
                ? pendingOrders.map(renderTicket).join('')
                : renderEmptyState('new pending');
        }

        // Populate Column 2: Preparing
        if (ticketsPreparing) {
            ticketsPreparing.innerHTML = preparingOrders.length
                ? preparingOrders.map(renderTicket).join('')
                : renderEmptyState('in-progress preparing');
        }

        // Populate Column 3: To Be Served (Ready)
        if (ticketsReady) {
            ticketsReady.innerHTML = readyOrders.length
                ? readyOrders.map(renderTicket).join('')
                : renderEmptyState('ready to serve');
        }

    } catch (error) {
        console.error('KDS loadOrders error:', error);
    }
}

// Global button handler for transitioning tickets across columns
document.addEventListener('click', async event => {
    const btn = event.target.closest('.kds-ticket-btn[data-status]');
    if (!btn) return;

    const orderId = btn.dataset.id;
    const nextStatus = btn.dataset.status;

    btn.disabled = true;
    btn.innerHTML = `<i class="bi bi-arrow-repeat spin-icon"></i> Updating...`;

    try {
        const res = await fetch('update_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.KK_CSRF_TOKEN || '' },
            body: JSON.stringify({ id: orderId, status: nextStatus })
        });
        const data = await res.json();

        if (!data.ok) {
            throw new Error(data.msg || 'Unable to update order status.');
        }

        await loadOrders();
    } catch (err) {
        btn.disabled = false;
        if (window.kkNotify) {
            window.kkNotify(err.message || 'Status update failed.', 'error', { title: 'Kitchen Error' });
        } else {
            alert(err.message || 'Status update failed.');
        }
        await loadOrders();
    }
});

// Auto-refresh every 4 seconds
loadOrders();
setInterval(loadOrders, 4000);
