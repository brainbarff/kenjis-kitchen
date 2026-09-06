const grid = document.getElementById('kdsGrid');
const activeCount = document.getElementById('activeCount');
const preparingCount = document.getElementById('preparingCount');
const readyCount = document.getElementById('readyCount');

function elapsed(createdAt) {
    const mins = Math.floor((Date.now() - new Date(createdAt.replace(' ', 'T')).getTime()) / 60000);
    return `${Math.max(mins, 0)} min`;
}

function badgeClass(type) {
    if (type === 'DINE-IN') return 'badge-warning';
    if (type === 'ONLINE') return 'badge-info';
    return 'badge-success';
}

function nextButton(order) {
    if (order.status === 'Pending') {
        return `<button class="btn btn-info" data-status="Preparing" data-id="${order.id}">Start Preparing</button>`;
    }
    if (order.status === 'Preparing') {
        return `<button class="btn btn-success" data-status="Ready" data-id="${order.id}">Mark Ready</button>`;
    }
    return `<span class="badge badge-success">Waiting for Server</span>`;
}

async function loadOrders() {
    const res = await fetch('fetch_orders.php');
    const orders = await res.json();

    activeCount.textContent = orders.length;
    preparingCount.textContent = orders.filter(order => order.status === 'Preparing').length;
    readyCount.textContent = orders.filter(order => order.status === 'Ready').length;

    grid.innerHTML = orders.map(order => `
        <article class="order-card ${order.status.toLowerCase()}">
            <header>
                <div>
                    <h3>#${order.queue_no}</h3>
                    <p class="muted">${order.order_no}</p>
                </div>
                <span class="badge ${badgeClass(order.order_type)}">${order.order_type}</span>
            </header>
            <p><strong>Customer:</strong> ${order.customer_name || 'Walk-in Customer'}</p>
            ${order.table_no ? `<p><strong>Table:</strong> ${order.table_no}</p>` : ''}
            <p><strong>Elapsed:</strong> ${elapsed(order.created_at)}</p>
            <ul class="order-items">
                ${order.items.map(item => `<li><strong>${item.quantity}x</strong> ${item.item_name}${item.notes ? `<br><span class="muted">${item.notes}</span>` : ''}</li>`).join('')}
            </ul>
            <div class="page-head" style="margin:16px 0 0">
                <span class="badge badge-muted">${order.status}</span>
                ${nextButton(order)}
            </div>
        </article>
    `).join('');
}

grid.addEventListener('click', async event => {
    const btn = event.target.closest('[data-status]');
    if (!btn) return;

    await fetch('update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: btn.dataset.id, status: btn.dataset.status })
    });
    loadOrders();
});

loadOrders();
setInterval(loadOrders, 5000);
