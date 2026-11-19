let servingFilter = localStorage.getItem('kk_serving_filter') || "all";
let servingSort = localStorage.getItem('kk_serving_sort') || "fifo";
const servingGrid =
    document.getElementById('servingGrid');

const serveError =
    document.getElementById('serveError');

const readyCount =
    document.getElementById('readyCount');

const dineInCount =
    document.getElementById('dineInCount');

const pickupCount =
    document.getElementById('pickupCount');

const refreshServing =
    document.getElementById('refreshServing');

const slipModal =
    document.getElementById('slipModal');

const printArea =
    document.getElementById('printArea');

let readyOrders = [];
let isLoading = false;
let knownReadyOrderIds = null;

const servingApi = {
    async getReadyOrders() {
        const res =
            await fetch(
                'api_ready_orders.php'
            );

        return res.json();
    },

    async markServed(orderId) {
        const res =
            await fetch(
                'api_mark_served.php',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json',
                        'X-CSRF-Token':
                            window.KK_CSRF_TOKEN || ''
                    },
                    body:
                        JSON.stringify({
                            order_id:
                                orderId
                        })
                }
            );

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

function minutesSince(
    dateValue
) {
    if (!dateValue) {
        return 0;
    }

    const date =
        new Date(
            String(
                dateValue
            ).replace(
                ' ',
                'T'
            )
        );

    return Math.max(
        Math.floor(
            (
                Date.now() -
                date.getTime()
            ) / 60000
        ),
        0
    );
}

function showServeNotice(
    title,
    message
) {
    const existing =
        document.getElementById(
            'serveNotificationOverlay'
        );

    if (existing) {
        existing.remove();
    }

    const overlay =
        document.createElement(
            'div'
        );

    overlay.id =
        'serveNotificationOverlay';

    overlay.className =
        'serve-notification-overlay';

    overlay.innerHTML = `
        <div class="serve-notification-modal">
            <div class="serve-notification-accent"></div>

            <div class="serve-notification-body">
                <div class="serve-notification-icon">
                    <i class="bi bi-exclamation-lg"></i>
                </div>

                <h2 class="serve-notification-title">
                    ${escapeHtml(title)}
                </h2>

                <p class="serve-notification-message">
                    ${escapeHtml(message)}
                </p>

                <button
                    type="button"
                    class="serve-notification-btn"
                    id="serveNotificationOk"
                >
                    OK
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(
        overlay
    );

    const closeNotice =
        () => {
            overlay.classList.remove(
                'show'
            );

            setTimeout(() => {
                overlay.remove();
            }, 200);
        };

    document
        .getElementById(
            'serveNotificationOk'
        )
        ?.addEventListener(
            'click',
            closeNotice
        );

    requestAnimationFrame(() => {
        overlay.classList.add(
            'show'
        );
    });
}

function updateSummary() {
    readyCount.textContent =
        readyOrders.length;

    dineInCount.textContent =
        readyOrders.filter(
            order =>
                order.order_type ===
                'DINE-IN'
        ).length;

    pickupCount.textContent =
        readyOrders.filter(
            order =>
                order.order_type !==
                'DINE-IN'
        ).length;
}

function getDestination(
    order
) {
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

    if (order.order_channel === 'ONLINE') {
        return {
            main: 'PICKUP',
            sub: 'ONLINE PICKUP'
        };
    }

    return {
        main: 'TAKE-OUT',
        sub:
            order.order_type ||
            'TAKE-OUT'
    };
}

function renderEmptyState() {
    servingGrid.innerHTML = `
        <article class="serve-empty-card">
            <i class="bi bi-check2-circle"></i>
            <h2>${servingFilter === "dine" ? "No dine-in orders ready." : servingFilter === "pickup" ? "No pickup orders ready." : "No orders ready for serving."}</h2>
            <p class="muted">
                Ready orders will show up here automatically.
            </p>
        </article>
    `;
}

function renderOrders() {
    updateSummary();

    const filteredOrders = readyOrders.filter(order => {
        if (servingFilter === 'dine') return order.order_type === 'DINE-IN';
        if (servingFilter === 'takeout') return order.order_channel !== 'ONLINE' && order.order_type === 'TAKE-OUT';
        if (servingFilter === 'online-pickup') return order.order_channel === 'ONLINE' && order.order_type !== 'DINE-IN';
        if (servingFilter === 'pickup') return order.order_type !== 'DINE-IN';
        return true;
    });
    const visibleOrders = window.kkSortOrders
        ? window.kkSortOrders(filteredOrders, servingSort, { timeField: 'ready_at' })
        : filteredOrders;
    if (!visibleOrders.length) {
        renderEmptyState();
        return;
    }

    servingGrid.innerHTML =
        visibleOrders
            .map(order => {
                const elapsed =
                    minutesSince(
                        order.ready_at ||
                        order.created_at
                    );

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
                                                    <span class="serve-item-note">
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

                return `
                    <article
                        class="serve-card"
                        data-order-id="${escapeHtml(
                            order.id
                        )}"
                    >
                        <div class="serve-card-head">
                            <div class="serve-card-head-left">
                                <span class="serve-queue-number">
                                    #${escapeHtml(
                                        order.queue_no
                                    )}
                                </span>

                                <p class="serve-order-number">
                                    ${escapeHtml(
                                        order.order_no
                                    )}
                                </p>
                            </div>

                            <span class="serve-type-badge">
                                ${escapeHtml(
                                    order.order_channel === 'ONLINE' && order.order_type !== 'DINE-IN'
                                        ? 'ONLINE PICKUP'
                                        : order.order_type
                                )}
                            </span>
                        </div>

                        <div class="serve-destination">
                            <span class="serve-destination-label">
                                Destination
                            </span>

                            <strong class="serve-destination-main">
                                ${escapeHtml(
                                    destination.main
                                )}
                            </strong>

                            <span class="serve-destination-sub">
                                ${escapeHtml(
                                    destination.sub
                                )}
                            </span>
                        </div>

                        <div class="serve-meta">
                            <div class="serve-meta-box">
                                <span>Elapsed</span>
                                <strong>
                                    ${elapsed} min
                                </strong>
                            </div>

                            <div class="serve-meta-box">
                                <span>Customer</span>
                                <strong>
                                    ${escapeHtml(
                                        order.customer_name ||
                                        'Walk-in Customer'
                                    )}
                                </strong>
                            </div>
                        </div>

                        ${
                            order.notes
                                ? `
                                    <div class="serve-note">
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

                        <ul class="serve-items">
                            ${items}
                        </ul>

                        <div class="serve-actions">
                            <button
                                class="btn serve-complete-btn"
                                data-serve="${escapeHtml(
                                    order.id
                                )}"
                            >
                                <i class="bi bi-check-circle"></i>
                                Order Served
                            </button>

                            <button
                                class="btn btn-secondary"
                                data-slip="${escapeHtml(
                                    order.id
                                )}"
                            >
                                <i class="bi bi-printer"></i>
                                Print Slip
                            </button>
                        </div>
                    </article>
                `;
            })
            .join('');
}

async function loadReadyOrders() {
    if (isLoading) {
        return;
    }

    isLoading = true;

    try {
        const data =
            await servingApi.getReadyOrders();

        if (!data.ok) {
            throw new Error(
                data.msg ||
                'Unable to load ready orders.'
            );
        }

        readyOrders =
            Array.isArray(data.orders)
                ? data.orders
                : [];
        const currentIds = new Set(readyOrders.map(order => String(order.id)));
        if (knownReadyOrderIds !== null && [...currentIds].some(id => !knownReadyOrderIds.has(id))) {
            window.KenjiOrderSounds?.play('serving');
        }
        knownReadyOrderIds = currentIds;

        renderOrders();
    } catch (error) {
        showServeNotice(
            'Unable to Load Orders',
            error.message ||
                'The serving queue could not be refreshed.'
        );
    } finally {
        isLoading = false;
    }
}

async function serveOrder(
    orderId
) {
    const order = readyOrders.find(row => String(row.id) === String(orderId));
    if (order && (order.order_channel === 'ONLINE' || order.order_type !== 'DINE-IN') && window.kkConfirm) {
        const isPaid = String(order.payment_status || '') === 'Paid';
        const totalFormatted = '₱' + Number(order.total || 0).toFixed(2);
        const paymentMethod = order.payment_method || 'Cash';
        const confirmed = await window.kkConfirm({
            title: 'Complete Pickup Order?',
            details: [
                { label: 'Order Number', value: order.order_no || ('#' + orderId) },
                { label: 'Amount to Collect', value: isPaid ? `${totalFormatted} (Paid)` : totalFormatted },
                { label: 'Payment Method', value: paymentMethod }
            ],
            message: isPaid
                ? 'Confirm that this paid pickup order has been claimed by the customer.'
                : "Confirm that you have received the customer's payment before completing this order.",
            confirmText: 'Confirm Payment & Complete Order',
            cancelText: 'Cancel',
            variant: 'primary'
        });
        if (!confirmed) return;
    }

    const card =
        servingGrid.querySelector(
            `[data-order-id="${CSS.escape(
                String(orderId)
            )}"]`
        );

    const oldOrders =
        [...readyOrders];

    readyOrders =
        readyOrders.filter(
            order =>
                String(order.id) !==
                String(orderId)
        );

    renderOrders();

    try {
        const data =
            await servingApi.markServed(
                orderId
            );

        if (!data.ok) {
            throw new Error(
                data.msg ||
                'The order could not be marked as served.'
            );
        }
    } catch (error) {
        readyOrders =
            oldOrders;

        renderOrders();

        if (card) {
            card.classList.add(
                'serve-card-error'
            );
        }

        showServeNotice(
            'Order Not Updated',
            error.message ||
                'The order could not be marked as served.'
        );
    }
}

function buildSlip(
    order
) {
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
                        <tr>
                            <td>
                                ${escapeHtml(
                                    item.quantity
                                )}x
                                ${escapeHtml(
                                    item.item_name
                                )}
                            </td>
                        </tr>

                        ${
                            item.notes
                                ? `
                                    <tr>
                                        <td class="slip-note">
                                            ${escapeHtml(
                                                item.notes
                                            )}
                                        </td>
                                    </tr>
                                `
                                : ''
                        }
                    `
                )
                .join('')
            : '';

    printArea.innerHTML = `
        <section class="thermal-slip">
            <h1>Kenji's Kitchen</h1>

            <p>
                ${new Date().toLocaleString()}
            </p>

            <hr>

            <h2>
                Order ${escapeHtml(
                    order.order_no
                )}
            </h2>

            <p>
                Queue No:
                #${escapeHtml(
                    order.queue_no
                )}
            </p>

            <p>
                ${escapeHtml(
                    destination.main
                )}
            </p>

            <p>
                ${escapeHtml(
                    destination.sub
                )}
            </p>

            <p>
                Customer:
                ${escapeHtml(
                    order.customer_name ||
                    'Walk-in Customer'
                )}
            </p>

            ${
                order.notes
                    ? `
                        <p style="
                            text-align:left;
                            font-weight:bold;
                            margin:7px 0;
                        ">
                            Note:
                            ${escapeHtml(
                                order.notes
                            )}
                        </p>
                    `
                    : ''
            }

            <hr>

            <table>
                ${items}
            </table>

            <hr>

            <p class="slip-footer">
                Please serve while hot. Thank you.
            </p>
        </section>
    `;
}

function hideSlipModal() {
    slipModal.hidden =
        true;

    slipModal.style.display =
        'none';
}

function showSlipModal() {
    slipModal.hidden =
        false;

    slipModal.style.display =
        'flex';
}

servingGrid.addEventListener(
    'click',
    event => {
        const serveBtn =
            event.target.closest(
                '[data-serve]'
            );

        const slipBtn =
            event.target.closest(
                '[data-slip]'
            );

        if (serveBtn) {
            serveOrder(
                serveBtn.dataset.serve
            );

            return;
        }

        if (slipBtn) {
            const order =
                readyOrders.find(
                    row =>
                        String(row.id) ===
                        String(
                            slipBtn.dataset.slip
                        )
                );

            if (!order) {
                return;
            }

            buildSlip(order);
            showSlipModal();
        }
    }
);

document
    .getElementById(
        'closeSlip'
    )
    ?.addEventListener(
        'click',
        hideSlipModal
    );

document
    .getElementById(
        'cancelSlip'
    )
    ?.addEventListener(
        'click',
        hideSlipModal
    );

document
    .getElementById(
        'printSlip'
    )
    ?.addEventListener(
        'click',
        () => {
            window.print();
        }
    );

refreshServing?.addEventListener(
    'click',
    loadReadyOrders
);

loadReadyOrders();

setInterval(
    loadReadyOrders,
    5000
);

function syncServingFilterControls() {
    document.querySelectorAll('[data-serving-filter]').forEach(other => {
        other.setAttribute('aria-pressed', String(other.dataset.servingFilter === servingFilter));
    });
    document.querySelectorAll('[data-serving-pill]').forEach(pill => {
        pill.classList.toggle('active', pill.getAttribute('data-serving-pill') === servingFilter);
    });
}

document.querySelectorAll('[data-serving-filter]').forEach(button => {
    button.addEventListener('click', () => {
        servingFilter = button.dataset.servingFilter || 'all';
        localStorage.setItem('kk_serving_filter', servingFilter);
        syncServingFilterControls();
        renderOrders();
    });
});

document.getElementById('servingFilterPills')?.addEventListener('click', event => {
    const pill = event.target.closest('[data-serving-pill]');
    if (!pill) return;
    servingFilter = pill.getAttribute('data-serving-pill') || 'all';
    localStorage.setItem('kk_serving_filter', servingFilter);
    syncServingFilterControls();
    renderOrders();
});

const servingSortEl = document.getElementById('servingSortSelect');
if (servingSortEl) {
    servingSortEl.value = servingSort;
    servingSortEl.addEventListener('change', () => {
        servingSort = servingSortEl.value || 'fifo';
        localStorage.setItem('kk_serving_sort', servingSort);
        renderOrders();
    });
}

syncServingFilterControls();
