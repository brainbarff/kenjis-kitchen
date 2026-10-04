const pickupDisplay =
    document.getElementById(
        'pickupDisplay'
    );

const dineDisplay =
    document.getElementById(
        'dineDisplay'
    );

const pickupCount =
    document.getElementById(
        'pickupCount'
    );

const dineCount =
    document.getElementById(
        'dineCount'
    );

const displayClock =
    document.getElementById(
        'displayClock'
    );

function safeText(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function updateClock() {
    displayClock.textContent =
        new Date().toLocaleTimeString(
            [],
            {
                hour: '2-digit',
                minute: '2-digit'
            }
        );
}

function getDestination(
    order
) {
    if (
        order.order_type ===
        'DINE-IN'
    ) {
        return order.table_no
            ? `TABLE ${safeText(
                order.table_no
            )}`
            : 'TABLE NOT SET';
    }

    return 'TAKE-OUT';
}

function displayCard(
    order
) {
    const isOnline =
        order.order_type ===
        'ONLINE';

    const destination =
        getDestination(
            order
        );

    const typeText =
        order.order_type ===
        'DINE-IN'
            ? 'DINE-IN'
            : 'READY FOR PICKUP';

    return `
        <div class="display-order">
            <div>
                <span class="display-order-queue">
                    #${safeText(
                        order.queue_no
                    )}
                </span>

                <div class="display-order-destination">
                    ${destination}
                </div>

                <div class="display-order-type">
                    ${safeText(
                        typeText
                    )}
                </div>

                ${
                    isOnline
                        ? `
                            <span class="display-online">
                                ONLINE
                            </span>
                        `
                        : ''
                }
            </div>

        </div>
    `;
}

function renderList(
    container,
    orders,
    emptyMessage
) {
    container.innerHTML =
        orders.length
            ? orders
                .map(
                    displayCard
                )
                .join('')
            : `
                <div class="display-empty">
                    ${safeText(
                        emptyMessage
                    )}
                </div>
            `;
}

async function loadDisplayOrders() {
    try {
        const res =
            await fetch(
                'api_ready_orders.php'
            );

        const data =
            await res.json();

        const orders =
            data.ok &&
            Array.isArray(
                data.orders
            )
                ? data.orders
                : [];

        const pickups =
            orders.filter(
                order =>
                    order.order_type !==
                    'DINE-IN'
            );

        const dineIns =
            orders.filter(
                order =>
                    order.order_type ===
                    'DINE-IN'
            );

        if (pickupCount) {
            pickupCount.textContent =
                pickups.length;
        }

        if (dineCount) {
            dineCount.textContent =
                dineIns.length;
        }

        renderList(
            pickupDisplay,
            pickups,
            'No pickup orders ready.'
        );

        renderList(
            dineDisplay,
            dineIns,
            'No dine-in orders ready.'
        );
    } catch (error) {
        if (pickupCount) {
            pickupCount.textContent =
                '0';
        }

        if (dineCount) {
            dineCount.textContent =
                '0';
        }

        pickupDisplay.innerHTML = `
            <div class="display-empty">
                Unable to load orders.
            </div>
        `;

        dineDisplay.innerHTML = `
            <div class="display-empty">
                Please check the connection.
            </div>
        `;
    }
}

updateClock();
loadDisplayOrders();

setInterval(
    updateClock,
    30000
);

setInterval(
    loadDisplayOrders,
    5000
);