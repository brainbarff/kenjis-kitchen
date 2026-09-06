const servingGrid = document.getElementById('servingGrid');
const serveError = document.getElementById('serveError');
const readyCount = document.getElementById('readyCount');
const dineInCount = document.getElementById('dineInCount');
const pickupCount = document.getElementById('pickupCount');
const refreshServing = document.getElementById('refreshServing');
const slipModal = document.getElementById('slipModal');
const printArea = document.getElementById('printArea');

let readyOrders = [];
let isLoading = false;

const servingApi = {
    async getReadyOrders() {
        const res = await fetch('api_ready_orders.php');
        return res.json();
    },

    async markServed(orderId) {
        const res = await fetch('api_mark_served.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
        return res.json();
    }
};

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function minutesSince(dateValue) {
    if (!dateValue) return 0;
    const date = new Date(dateValue.replace(' ', 'T'));
    return Math.max(Math.floor((Date.now() - date.getTime()) / 60000), 0);
}

function showError(message) {
    if (!serveError) return;
    serveError.textContent = message;
    serveError.hidden = false;
    setTimeout(() => serveError.hidden = true, 4000);
}

function orderTypeClass(type) {
    return type === 'DINE-IN' ? 'badge-warning' : 'badge-info';
}

function updateSummary() {
    if (readyCount) readyCount.textContent = readyOrders.length;
    if (dineInCount) dineInCount.textContent = readyOrders.filter(order => order.order_type === 'DINE-IN').length;
    if (pickupCount) pickupCount.textContent = readyOrders.filter(order => order.order_type !== 'DINE-IN').length;
}

function renderEmptyState() {
    if (!servingGrid) return;
    servingGrid.innerHTML = `
        <article class="card empty-state">
            <i class="bi bi-check2-circle"></i>
            <h2>No orders ready for serving.</h2>
            <p class="muted">Ready orders will show up here automatically.</p>
        </article>
    `;
}

function renderOrders() {
    updateSummary();

    if (!readyOrders.length) {
        renderEmptyState();
        return;
    }

    if (!servingGrid) return;

    servingGrid.innerHTML = readyOrders.map(order => {
        const elapsed = minutesSince(order.ready_at || order.created_at);
        const items = (order.items || []).map(item => `
            <li>
                <strong>${escapeHtml(item.quantity)}x</strong> ${escapeHtml(item.item_name)}
                ${item.notes ? `<br><span class="muted">${escapeHtml(item.notes)}</span>` : ''}
            </li>
        `).join('');

        return `
            <article class="card serve-card" data-order-id="${order.id}">
                <header>
                    <div>
                        <span class="order-number">#${escapeHtml(order.queue_no)}</span>
                        <p class="muted">${escapeHtml(order.order_no)}</p>
                    </div>
                    <span class="badge ${orderTypeClass(order.order_type)}">${escapeHtml(order.order_type)}</span>
                </header>

                <div class="serve-meta">
                    <p><strong>Table / Type:</strong> ${order.table_no ? `Table ${escapeHtml(order.table_no)}` : escapeHtml(order.order_type)}</p>
                    <p><strong>Elapsed:</strong> ${elapsed} min</p>
                    <p><strong>Customer:</strong> ${escapeHtml(order.customer_name || 'Walk-in Customer')}</p>
                </div>

                <ul class="order-items">${items}</ul>

                <div class="serve-actions">
                    <button class="btn btn-primary" data-serve="${order.id}"><i class="bi bi-check-circle"></i>Bump / Served</button>
                    <button class="btn btn-secondary" data-slip="${order.id}"><i class="bi bi-printer"></i>Print Slip</button>
                </div>
            </article>
        `;
    }).join('');
}

async function loadReadyOrders() {
    if (isLoading) return;
    isLoading = true;

    try {
        const data = await servingApi.getReadyOrders();
        if (!data.ok) throw new Error(data.msg);
        readyOrders = data.orders || [];
        renderOrders();
    } catch (error) {
        showError(error.message || 'Unable to refresh ready orders.');
    } finally {
        isLoading = false;
    }
}

async function bumpOrder(orderId) {
    const card = servingGrid?.querySelector(`[data-order-id="${orderId}"]`);
    const oldOrders = [...readyOrders];

    readyOrders = readyOrders.filter(order => String(order.id) !== String(orderId));
    renderOrders();

    try {
        const data = await servingApi.markServed(orderId);
        if (!data.ok) throw new Error(data.msg);
    } catch (error) {
        readyOrders = oldOrders;
        renderOrders();
        if (card) card.classList.add('serve-error');
        showError(error.message || 'Order was not updated.');
    }
}

function buildSlip(order) {
    if (!printArea) return;
    const items = (order.items || []).map(item => `
        <tr>
            <td>${escapeHtml(item.quantity)}x ${escapeHtml(item.item_name)}</td>
        </tr>
        ${item.notes ? `<tr><td class="slip-note">${escapeHtml(item.notes)}</td></tr>` : ''}
    `).join('');

    printArea.innerHTML = `
        <section class="thermal-slip">
            <h1>Kenji's Kitchen</h1>
            <p>${new Date().toLocaleString()}</p>
            <hr>
            <h2>Order ${escapeHtml(order.order_no)}</h2>
            <p>Queue No: #${escapeHtml(order.queue_no)}</p>
            <p>Type: ${escapeHtml(order.order_type)}</p>
            ${order.table_no ? `<p>Table: ${escapeHtml(order.table_no)}</p>` : ''}
            <hr>
            <table>${items}</table>
            <hr>
            <p class="slip-footer">Please serve while hot. Thank you.</p>
        </section>
    `;
}

function hideSlipModal() {
    if (!slipModal) return;
    slipModal.setAttribute('hidden', '');
    slipModal.classList.remove('active', 'show', 'open');
    slipModal.style.setProperty('display', 'none', 'important');
}

function showSlipModal() {
    if (!slipModal) return;
    slipModal.removeAttribute('hidden');
    slipModal.classList.add('active', 'show', 'open');
    slipModal.style.setProperty('display', 'flex', 'important');
}

if (servingGrid) {
    servingGrid.addEventListener('click', event => {
        const serveBtn = event.target.closest('[data-serve]');
        const slipBtn = event.target.closest('[data-slip]');

        if (serveBtn) {
            bumpOrder(serveBtn.dataset.serve);
        }

        if (slipBtn) {
            const order = readyOrders.find(row => String(row.id) === String(slipBtn.dataset.slip));
            if (!order) return;
            buildSlip(order);
            showSlipModal();
        }
    });
}

document.addEventListener('click', event => {
    if (
        event.target.closest('#closeSlip') ||
        event.target.closest('#cancelSlip') ||
        event.target === slipModal
    ) {
        hideSlipModal();
    }
});

document.getElementById('printSlip')?.addEventListener('click', () => window.print());
if (refreshServing) refreshServing.addEventListener('click', loadReadyOrders);

hideSlipModal();
loadReadyOrders();
setInterval(loadReadyOrders, 5000);