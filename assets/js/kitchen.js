const grid =
    document.getElementById('kdsGrid');

const activeCount =
    document.getElementById('activeCount');

const preparingCount =
    document.getElementById('preparingCount');

const readyCount =
    document.getElementById('readyCount');

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function elapsed(createdAt) {
    if (!createdAt) {
        return '0 min';
    }

    const timestamp =
        new Date(
            String(createdAt).replace(
                ' ',
                'T'
            )
        ).getTime();

    const mins =
        Math.floor(
            (
                Date.now() -
                timestamp
            ) / 60000
        );

    return `${Math.max(mins, 0)} min`;
}

function getDestination(order) {
    if (
        order.order_type ===
        'DINE-IN'
    ) {
        return {
            main:
                order.table_no
                    ? `TABLE ${order.table_no}`
                    : 'TABLE NOT SET',
            sub:
                'DINE-IN'
        };
    }

    return {
        main: 'TAKE-OUT',
        sub:
            order.order_type ||
            'PICKUP'
    };
}

function nextButton(order) {
    if (
        order.status ===
        'Pending'
    ) {
        return `
            <button
                class="btn kds-start-btn"
                data-status="Preparing"
                data-id="${escapeHtml(
                    order.id
                )}"
            >
                <i class="bi bi-play-fill"></i>
                Start Preparing
            </button>
        `;
    }

    if (
        order.status ===
        'Preparing'
    ) {
        return `
            <button
                class="btn kds-ready-btn"
                data-status="Ready"
                data-id="${escapeHtml(
                    order.id
                )}"
            >
                <i class="bi bi-check-lg"></i>
                Mark Ready
            </button>
        `;
    }

    return `
        <span class="kds-waiting">
            <i class="bi bi-person-check"></i>
            Waiting for Server
        </span>
    `;
}

async function loadOrders() {
    try {
        const res =
            await fetch(
                'fetch_orders.php'
            );

        const orders =
            await res.json();

        const safeOrders =
            Array.isArray(orders)
                ? orders
                : [];

        activeCount.textContent =
            safeOrders.length;

        preparingCount.textContent =
            safeOrders.filter(
                order =>
                    order.status ===
                    'Preparing'
            ).length;

        readyCount.textContent =
            safeOrders.filter(
                order =>
                    order.status ===
                    'Ready'
            ).length;

        if (!safeOrders.length) {
            grid.innerHTML = `
                <article style="
                    grid-column: 1 / -1;
                    min-height: 250px;
                    display:flex;
                    flex-direction:column;
                    align-items:center;
                    justify-content:center;
                    text-align:center;
                    padding:25px;
                    border:1px solid #e5e5e5;
                    border-radius:13px;
                    background:#ffffff;
                ">
                    <i
                        class="bi bi-check2-circle"
                        style="
                            color:#b9b9b9;
                            font-size:2.3rem;
                            margin-bottom:9px;
                        "
                    ></i>

                    <h2 style="
                        margin:0 0 5px;
                        color:#111111;
                        font-size:1rem;
                    ">
                        No active orders
                    </h2>

                    <p class="muted" style="
                        margin:0;
                        font-size:0.78rem;
                    ">
                        New orders will appear here automatically.
                    </p>
                </article>
            `;

            return;
        }

        grid.innerHTML =
            safeOrders
                .map(
                    order => {
                        const destination =
                            getDestination(
                                order
                            );

                        const items =
                            Array.isArray(
                                order.items
                            )
                                ? order.items
                                    .map(
                                        item => `
                                            <li>
                                                <strong>
                                                    ${escapeHtml(
                                                        item.quantity
                                                    )}x
                                                </strong>
                                                ${escapeHtml(
                                                    item.item_name
                                                )}

                                                ${
                                                    item.notes
                                                        ? `
                                                            <span class="kds-item-note">
                                                                ${escapeHtml(
                                                                    item.notes
                                                                )}
                                                            </span>
                                                        `
                                                        : ''
                                                }
                                            </li>
                                        `
                                    )
                                    .join('')
                                : '';

                        const statusClass =
                            String(
                                order.status ||
                                ''
                            ).toLowerCase();

                        return `
                            <article class="
                                kds-ticket
                                ${escapeHtml(
                                    statusClass
                                )}
                            ">
                                <div class="kds-ticket-head">
                                    <div class="kds-ticket-left">
                                        <span class="kds-queue-number">
                                            #${escapeHtml(
                                                order.queue_no
                                            )}
                                        </span>

                                        <p class="kds-order-number">
                                            ${escapeHtml(
                                                order.order_no
                                            )}
                                        </p>
                                    </div>

                                    <span class="kds-status">
                                        ${escapeHtml(
                                            order.status
                                        )}
                                    </span>
                                </div>

                                <div class="kds-destination">
                                    <span class="kds-destination-label">
                                        Destination
                                    </span>

                                    <strong class="kds-destination-main">
                                        ${escapeHtml(
                                            destination.main
                                        )}
                                    </strong>

                                    <span class="kds-destination-sub">
                                        ${escapeHtml(
                                            destination.sub
                                        )}
                                    </span>
                                </div>

                                <div class="kds-info-row">
                                    <div class="kds-info-box">
                                        <span>Customer</span>
                                        <strong>
                                            ${escapeHtml(
                                                order.customer_name ||
                                                'Walk-in Customer'
                                            )}
                                        </strong>
                                    </div>

                                    <div class="kds-info-box">
                                        <span>Elapsed</span>
                                        <strong>
                                            ${elapsed(
                                                order.created_at
                                            )}
                                        </strong>
                                    </div>
                                </div>

                                ${
                                    order.notes
                                        ? `
                                            <div class="kds-note">
                                                <i class="bi bi-chat-left-text-fill"></i>
                                                <span>
                                                    <strong>Note:</strong>
                                                    ${escapeHtml(
                                                        order.notes
                                                    )}
                                                </span>
                                            </div>
                                        `
                                        : ''
                                }

                                <ul class="kds-items">
                                    ${items}
                                </ul>

                                <div class="kds-actions">
                                    ${nextButton(
                                        order
                                    )}
                                </div>
                            </article>
                        `;
                    }
                )
                .join('');
    } catch (error) {
        grid.innerHTML = `
            <article style="
                grid-column:1 / -1;
                min-height:220px;
                display:flex;
                flex-direction:column;
                align-items:center;
                justify-content:center;
                text-align:center;
                padding:25px;
                border:1px solid #e5e5e5;
                border-radius:13px;
                background:#ffffff;
            ">
                <i
                    class="bi bi-wifi-off"
                    style="
                        color:#b9b9b9;
                        font-size:2.1rem;
                        margin-bottom:9px;
                    "
                ></i>

                <h2 style="
                    margin:0 0 5px;
                    color:#111111;
                    font-size:1rem;
                ">
                    Unable to load orders
                </h2>

                <p class="muted" style="
                    margin:0;
                    font-size:0.78rem;
                ">
                    Please check the kitchen connection.
                </p>
            </article>
        `;
    }
}

grid.addEventListener(
    'click',
    async event => {
        const btn =
            event.target.closest(
                '[data-status]'
            );

        if (!btn) {
            return;
        }

        btn.disabled = true;

        try {
            const res =
                await fetch(
                    'update_status.php',
                    {
                        method:
                            'POST',
                        headers: {
                            'Content-Type':
                                'application/json'
                        },
                        body:
                            JSON.stringify({
                                id:
                                    btn.dataset.id,
                                status:
                                    btn.dataset.status
                            })
                    }
                );

            const data =
                await res.json();

            if (!data.ok) {
                throw new Error(
                    data.msg ||
                    'Unable to update order status.'
                );
            }

            loadOrders();
        } catch (error) {
            btn.disabled = false;

            alert(
                error.message ||
                'Unable to update order status.'
            );
        }
    }
);

loadOrders();

setInterval(
    loadOrders,
    5000
);