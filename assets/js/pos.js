let cart = [];

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const cartItems = document.getElementById('cartItems');
const discountInput = document.getElementById('discount');
const cashInput = document.getElementById('cash');

document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(btn => btn.classList.remove('active'));
        tab.classList.add('active');
        const cat = tab.dataset.cat;

        document.querySelectorAll('.food-card').forEach(card => {
            card.style.display = cat === 'all' || card.dataset.cat === cat ? '' : 'none';
        });
    });
});

document.querySelectorAll('.food-card').forEach(card => {
    card.addEventListener('click', () => {
        const found = cart.find(item => item.id === card.dataset.id);
        if (found) {
            found.qty += 1;
        } else {
            cart.push({
                id: card.dataset.id,
                name: card.dataset.name,
                price: Number(card.dataset.price),
                qty: 1
            });
        }
        renderCart();
    });
});

function totals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const discount = Number(discountInput.value || 0);
    const tax = Math.max(subtotal - discount, 0) * 0.12;
    const total = Math.max(subtotal - discount + tax, 0);
    const cash = Number(cashInput.value || 0);

    return { subtotal, discount, tax, total, cash, change: Math.max(cash - total, 0) };
}

function renderCart() {
    cartItems.innerHTML = '';

    cart.forEach(item => {
        const row = document.createElement('div');
        row.className = 'cart-row';
        row.innerHTML = `
            <div>
                <strong>${item.name}</strong>
                <p class="muted">${peso.format(item.price)}</p>
                <div class="qty">
                    <button data-minus="${item.id}">-</button>
                    <span>${item.qty}</span>
                    <button data-plus="${item.id}">+</button>
                    <button data-remove="${item.id}"><i class="bi bi-trash"></i></button>
                </div>
            </div>
            <strong>${peso.format(item.price * item.qty)}</strong>
        `;
        cartItems.appendChild(row);
    });

    const data = totals();
    document.getElementById('subtotal').textContent = peso.format(data.subtotal);
    document.getElementById('tax').textContent = peso.format(data.tax);
    document.getElementById('grandTotal').textContent = peso.format(data.total);
    document.getElementById('change').textContent = peso.format(data.change);
}

cartItems.addEventListener('click', event => {
    const plus = event.target.closest('[data-plus]');
    const minus = event.target.closest('[data-minus]');
    const remove = event.target.closest('[data-remove]');

    if (plus) {
        cart.find(item => item.id === plus.dataset.plus).qty += 1;
    }

    if (minus) {
        const item = cart.find(row => row.id === minus.dataset.minus);
        item.qty -= 1;
        if (item.qty <= 0) cart = cart.filter(row => row.id !== item.id);
    }

    if (remove) {
        cart = cart.filter(item => item.id !== remove.dataset.remove);
    }

    renderCart();
});

discountInput.addEventListener('input', renderCart);
cashInput.addEventListener('input', renderCart);

document.getElementById('checkoutBtn').addEventListener('click', async () => {
    const orderType = document.getElementById('orderType').value;
    const tableNo = document.getElementById('tableNo').value.trim();
    const data = totals();

    if (!cart.length) return alert('Cart is empty.');
    if (!orderType) return alert('Please select order type.');
    if (orderType === 'DINE-IN' && !tableNo) return alert('Table number is required.');
    if (data.discount < 0 || data.discount > data.subtotal) return alert('Invalid discount.');
    if (data.cash < data.total) return alert('Payment is insufficient.');

    const res = await fetch('process_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cart, orderType, tableNo, ...data })
    });
    const json = await res.json();

    if (json.ok) {
        alert(`Order saved. Receipt: ${json.receipt_no}`);
        cart = [];
        document.getElementById('tableNo').value = '';
        cashInput.value = '';
        discountInput.value = 0;
        renderCart();
    } else {
        alert(json.msg || 'Unable to process order.');
    }
});

document.getElementById('printBtn').addEventListener('click', () => window.print());
renderCart();
