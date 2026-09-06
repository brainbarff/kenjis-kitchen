const pickupDisplay = document.getElementById('pickupDisplay');
const dineDisplay = document.getElementById('dineDisplay');
const displayClock = document.getElementById('displayClock');

function safeText(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function updateClock() {
    displayClock.textContent = new Date().toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit'
    });
}

function displayCard(order) {
    return `
        <div class="display-order">
            <span>#${safeText(order.queue_no)}</span>
            <strong>${safeText(order.order_no)}</strong>
            <small>${safeText(order.order_type)}</small>
        </div>
    `;
}

async function loadDisplayOrders() {
    try {
        const res = await fetch('api_ready_orders.php');
        const data = await res.json();
        const orders = data.ok ? data.orders : [];
        const pickups = orders.filter(order => order.order_type !== 'DINE-IN');
        const dineIns = orders.filter(order => order.order_type === 'DINE-IN');

        pickupDisplay.innerHTML = pickups.length
            ? pickups.map(displayCard).join('')
            : '<p class="display-empty">No pickup orders ready.</p>';

        dineDisplay.innerHTML = dineIns.length
            ? dineIns.map(displayCard).join('')
            : '<p class="display-empty">No dine-in orders ready.</p>';
    } catch (error) {
        pickupDisplay.innerHTML = '<p class="display-empty">Unable to load orders.</p>';
        dineDisplay.innerHTML = '<p class="display-empty">Please check the connection.</p>';
    }
}

updateClock();
loadDisplayOrders();
setInterval(updateClock, 30000);
setInterval(loadDisplayOrders, 5000);
