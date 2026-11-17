<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/delivery_tracking.php';
require_role(['admin', 'cashier']);

$page = 'delivery';
$title = 'Delivery Status';
$heading = 'Delivery Status';
$flash = $_SESSION['delivery_flash'] ?? '';
$flashType = $_SESSION['delivery_flash_type'] ?? 'success';
unset($_SESSION['delivery_flash'], $_SESSION['delivery_flash_type']);

function delivery_flash(string $message, string $type = 'success'): void
{
    $_SESSION['delivery_flash'] = $message;
    $_SESSION['delivery_flash_type'] = $type;
    redirect('/modules/delivery/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) delivery_flash('Invalid security token. Reload the page and try again.', 'danger');
    $orderId = (int)($_POST['order_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($orderId < 1 || !in_array($action, ['out', 'delivered', 'failed', 'set_external', 'set_local', 'tracking_link', 'handoff'], true)) {
        delivery_flash('Invalid delivery action.', 'danger');
    }

    try {
        $conn->beginTransaction();
        $orderStmt = $conn->prepare("SELECT id, order_no, order_channel, order_type, status, total, free_delivery_discount, customer_phone, delivery_address, delivery_lat, delivery_lng, coverage_status, coverage_boundary_version, courier_booking_by, notes, inventory_deducted, delivery_mode, delivery_tracking_url, cod_approval_status, cash_collected, delivered_by_user_id FROM orders WHERE id = ? FOR UPDATE");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order || $order['order_channel'] !== 'ONLINE' || $order['order_type'] !== 'ONLINE') {
            throw new RuntimeException('Online delivery order not found.');
        }
        if ($order['status'] !== 'Ready') throw new RuntimeException('The kitchen must mark the delivery Ready before dispatch can change.');

        $paymentStmt = $conn->prepare('SELECT id, payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $paymentStmt->execute([$orderId]);
        $payment = $paymentStmt->fetch();
        if (!$payment) throw new RuntimeException('Payment record is missing.');
        if ($payment['payment_method'] === 'Cash on Delivery' && $order['cod_approval_status'] !== 'Approved') {
            throw new RuntimeException('Approve the COD order in COD Approvals before dispatch.');
        }
        if (strcasecmp((string)$payment['payment_method'], 'GCash') === 0 && $payment['payment_status'] !== 'Paid') {
            throw new RuntimeException('Verify the GCash payment before dispatch.');
        }

        $dispatchStmt = $conn->prepare('SELECT * FROM delivery_dispatches WHERE order_id = ? FOR UPDATE');
        $dispatchStmt->execute([$orderId]);
        $dispatch = $dispatchStmt->fetch();
        $actorId = (int)$_SESSION['u_id'];
        $cancellationCheck = $conn->prepare("SELECT COUNT(*) FROM transaction_requests WHERE order_id = ? AND request_type = 'Cancel Delivery' AND status = 'Pending'");
        $cancellationCheck->execute([$orderId]);
        if ((int)$cancellationCheck->fetchColumn() > 0) throw new RuntimeException('Cancellation review is pending.');

        if (in_array($action, ['set_external', 'set_local'], true)) {
            if ($dispatch && $dispatch['status'] !== 'Failed') throw new RuntimeException('The delivery is already in progress. Record a failure before changing its arrangement.');
            $overrideReason = trim((string)($_POST['override_reason'] ?? ''));
            if (mb_strlen($overrideReason) < 8 || mb_strlen($overrideReason) > 500) {
                throw new RuntimeException('Enter a staff override reason of 8–500 characters for the audit trail.');
            }
            if ($action === 'set_external') {
                if (strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || $payment['payment_status'] !== 'Paid') {
                    throw new RuntimeException('Outside-Lagro third-party delivery requires verified GCash payment for the food.');
                }
                $trackingUrl = normalize_delivery_tracking_url((string)($_POST['tracking_url'] ?? ''));
                $bookingBy = strtoupper(trim((string)($_POST['courier_booking_by'] ?? 'CUSTOMER')));
                if (!in_array($bookingBy, ['CUSTOMER', 'STORE'], true)) {
                    $bookingBy = 'CUSTOMER';
                }
                $conn->prepare("UPDATE orders SET delivery_mode = 'CUSTOMER_BOOKED', fulfillment_mode = 'OUTSIDE', coverage_status = 'STAFF_OVERRIDE', courier_booking_by = ?, delivery_tracking_url = ? WHERE id = ?")
                    ->execute([$bookingBy, $trackingUrl, $orderId]);
                log_audit(
                    $conn,
                    'delivery_coverage_override',
                    'order',
                    (string)$orderId,
                    sprintf(
                        'Staff overrode delivery arrangement for %s from %s (%s) to CUSTOMER_BOOKED (%s). Reason: %s',
                        (string)$order['order_no'],
                        (string)$order['delivery_mode'],
                        (string)($order['coverage_status'] ?? 'NONE'),
                        $bookingBy,
                        $overrideReason
                    )
                );
            } else {
                $conn->prepare("UPDATE orders SET delivery_mode = 'LAGRO_FREE', fulfillment_mode = 'LAGRO', coverage_status = 'STAFF_OVERRIDE', courier_booking_by = 'NONE', delivery_tracking_url = NULL WHERE id = ?")
                    ->execute([$orderId]);
                log_audit(
                    $conn,
                    'delivery_coverage_override',
                    'order',
                    (string)$orderId,
                    sprintf(
                        'Staff overrode delivery arrangement for %s from %s (%s) to LAGRO_FREE. Reason: %s',
                        (string)$order['order_no'],
                        (string)$order['delivery_mode'],
                        (string)($order['coverage_status'] ?? 'NONE'),
                        $overrideReason
                    )
                );
            }
            $conn->commit();
            delivery_flash('Delivery arrangement updated and logged for ' . $order['order_no'] . '.');
        }

        if ($action === 'tracking_link') {
            if ($order['delivery_mode'] !== 'CUSTOMER_BOOKED') throw new RuntimeException('Tracking links are for third-party deliveries.');
            $trackingUrl = normalize_delivery_tracking_url((string)($_POST['tracking_url'] ?? ''));
            if ($dispatch && $dispatch['status'] === 'Out for Delivery' && !$trackingUrl) {
                throw new RuntimeException('Keep a tracking link while the third-party rider has the order.');
            }
            $conn->prepare('UPDATE orders SET delivery_tracking_url = ? WHERE id = ?')->execute([$trackingUrl, $orderId]);
            $conn->commit();
            delivery_flash('Third-party tracking link saved.');
        }

        if ($action === 'handoff') {
            if ($order['delivery_mode'] !== 'CUSTOMER_BOOKED') throw new RuntimeException('This order uses Kenji\'s Kitchen delivery.');
            if (!normalize_delivery_tracking_url($order['delivery_tracking_url'])) throw new RuntimeException('Add the third-party courier tracking link before handoff.');
            if ($dispatch && $dispatch['status'] !== 'Failed') throw new RuntimeException('The handoff is already recorded.');
            if (strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || $payment['payment_status'] !== 'Paid') {
                throw new RuntimeException('Verify GCash payment before handing food to a third-party courier.');
            }
            if ($dispatch) {
                $conn->prepare("UPDATE delivery_dispatches SET rider_name = 'Third-party courier', rider_phone = NULL, status = 'Out for Delivery', notes = 'Manual third-party courier handoff', assigned_by = ?, updated_by = ?, assigned_at = NOW(), out_at = NOW(), delivered_at = NULL, failed_at = NULL WHERE id = ?")
                    ->execute([$actorId, $actorId, (int)$dispatch['id']]);
            } else {
                $conn->prepare("INSERT INTO delivery_dispatches (order_id, rider_name, rider_phone, status, notes, assigned_by, updated_by, assigned_at, out_at) VALUES (?, 'Third-party courier', NULL, 'Out for Delivery', 'Manual third-party courier handoff', ?, ?, NOW(), NOW())")
                    ->execute([$orderId, $actorId, $actorId]);
            }
            $conn->commit();
            delivery_flash('Third-party rider handoff recorded for ' . $order['order_no'] . '.');
        }

        if ($action === 'out') {
            if ($order['delivery_mode'] !== 'LAGRO_FREE') throw new RuntimeException('Use third-party handoff for outside-Lagro delivery.');
            if ($dispatch && !in_array($dispatch['status'], ['Assigned', 'Failed'], true)) {
                throw new RuntimeException('This delivery is already ' . $dispatch['status'] . '.');
            }
            $notes = trim((string)($_POST['notes'] ?? ''));
            if (mb_strlen($notes) > 500) {
                throw new RuntimeException('Delivery notes must not exceed 500 characters.');
            }
            if ($dispatch) {
                $conn->prepare("UPDATE delivery_dispatches SET rider_name = COALESCE(NULLIF(rider_name, ''), 'Store Delivery'), notes = COALESCE(?, notes), status = 'Out for Delivery', updated_by = ?, out_at = NOW(), delivered_at = NULL, failed_at = NULL WHERE id = ?")
                    ->execute([$notes !== '' ? $notes : null, $actorId, (int)$dispatch['id']]);
            } else {
                $conn->prepare("INSERT INTO delivery_dispatches (order_id, rider_name, rider_phone, status, notes, assigned_by, updated_by, assigned_at, out_at) VALUES (?, 'Store Delivery', NULL, 'Out for Delivery', ?, ?, ?, NOW(), NOW())")
                    ->execute([$orderId, $notes !== '' ? $notes : 'Manual store delivery arrangement', $actorId, $actorId]);
            }
            $conn->commit();
            delivery_flash($order['order_no'] . ' marked Out for Delivery.');
        }

        if (!$dispatch) throw new RuntimeException('Mark this order Out for Delivery before updating delivery status.');
        if ($action === 'failed') {
            if ($dispatch['status'] !== 'Out for Delivery') throw new RuntimeException('Only an order out for delivery can be marked Failed.');
            $notes = trim((string)($_POST['notes'] ?? ''));
            if (mb_strlen($notes) < 8 || mb_strlen($notes) > 500) throw new RuntimeException('Enter a failure reason of 8–500 characters.');
            $conn->prepare("UPDATE delivery_dispatches SET status = 'Failed', notes = ?, failed_at = NOW(), updated_by = ? WHERE id = ?")
                ->execute([$notes, $actorId, (int)$dispatch['id']]);
            $conn->commit();
            delivery_flash('Delivery failure recorded. You can retry manual handoff or resolve it in COD Approvals / Order Requests.');
        }

        if ($dispatch['status'] !== 'Out for Delivery') throw new RuntimeException('Only an order out for delivery can be marked Delivered.');
        if ($order['delivery_mode'] === 'CUSTOMER_BOOKED' && (strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || $payment['payment_status'] !== 'Paid')) {
            throw new RuntimeException('Customer-booked delivery requires verified GCash payment.');
        }
        if (strcasecmp((string)$payment['payment_method'], 'GCash') === 0 && $payment['payment_status'] !== 'Paid') {
            throw new RuntimeException('Verify the GCash payment before completing delivery.');
        }
        if ($payment['payment_status'] !== 'Paid') {
            if ($order['cash_collected'] !== null) {
                throw new RuntimeException('Cash has already been recorded for this order.');
            }
            if ((string)($_POST['cash_collected'] ?? '') !== '1') {
                throw new RuntimeException('Confirm cash was collected from the customer before marking Delivered.');
            }
            $isCod = $payment['payment_method'] === 'Cash on Delivery';
            if ($isCod && $order['cod_approval_status'] !== 'Approved') throw new RuntimeException('Approve the COD order before collecting payment.');
            $role = (string)(current_user()['role_key'] ?? '');
            $shiftId = null;
            if ($role === 'cashier') {
                $shiftStmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $shiftStmt->execute([$actorId]);
                $shiftId = $shiftStmt->fetchColumn();
                if (!$shiftId && !empty($_SESSION['pos_active_cashier']['id'])) {
                    $shiftStmt->execute([(int)$_SESSION['pos_active_cashier']['id']]);
                    $shiftId = $shiftStmt->fetchColumn();
                }
                if (!$shiftId) throw new RuntimeException('Open your cashier shift before collecting cash.');
            }
            $conn->prepare("UPDATE payments SET payment_status = 'Paid', amount_paid = ?, change_amount = 0, paid_at = NOW(), shift_id = ? WHERE id = ?")
                ->execute([(float)$order['total'], $shiftId ?: null, (int)$payment['id']]);
            if ($isCod) {
                $conn->prepare('UPDATE orders SET cash_collected = ?, collected_by_user_id = ? WHERE id = ?')
                    ->execute([(float)$order['total'], $actorId, $orderId]);
                if ($role !== 'cashier') {
                    $existingHandover = $conn->prepare("SELECT id FROM cod_cash_handovers WHERE order_id = ? LIMIT 1 FOR UPDATE");
                    $existingHandover->execute([$orderId]);
                    if (!$existingHandover->fetchColumn()) {
                        $conn->prepare("INSERT INTO cod_cash_handovers (order_id, collected_by_user_id, amount_collected, collected_at, status) VALUES (?, ?, ?, NOW(), 'pending')")
                            ->execute([$orderId, $actorId, (float)$order['total']]);
                    }
                }
                if ((float)$order['free_delivery_discount'] > 0 && !empty($order['customer_phone'])) {
                    $pHash = hash('sha256', preg_replace('/\D+/', '', (string)$order['customer_phone']));
                    $aHash = hash('sha256', strtolower(trim((string)$order['delivery_address'])));
                    $conn->prepare("INSERT IGNORE INTO first_order_benefit_hashes (phone_hash, address_hash, used_order_id) VALUES (?, ?, ?)")
                        ->execute([$pHash, $aHash, $orderId]);
                }
            }
        }

        // Preserve the legacy online-order stock fallback used by the serving workflow.
        if ((int)$order['inventory_deducted'] === 0) {
            $logCheck = $conn->prepare("SELECT COUNT(*) FROM inventory_logs WHERE action = 'Order Deduction' AND remarks LIKE ?");
            $logCheck->execute(['%' . $order['order_no'] . '%']);
            if ((int)$logCheck->fetchColumn() === 0) {
                $itemsStmt = $conn->prepare('SELECT menu_item_id, SUM(quantity) AS quantity FROM order_items WHERE order_id = ? GROUP BY menu_item_id ORDER BY menu_item_id');
                $itemsStmt->execute([$orderId]);
                $deduct = $conn->prepare("UPDATE menu_items SET stock = GREATEST(0, stock - ?), availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE availability END WHERE id = ?");
                $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");
                foreach ($itemsStmt->fetchAll() as $item) {
                    $quantity = (int)$item['quantity'];
                    $menuId = (int)$item['menu_item_id'];
                    if ($quantity < 1) continue;
                    $deduct->execute([$quantity, $menuId]);
                    if ($deduct->rowCount() !== 1) throw new RuntimeException('Unable to deduct legacy order stock.');
                    $log->execute([$menuId, $actorId, $quantity, 'Online order ' . $order['order_no'] . ' completed deduction']);
                }
            }
            $conn->prepare('UPDATE orders SET inventory_deducted = 1 WHERE id = ?')->execute([$orderId]);
        }

        $conn->prepare("UPDATE delivery_dispatches SET status = 'Delivered', delivered_at = NOW(), updated_by = ? WHERE id = ?")
            ->execute([$actorId, (int)$dispatch['id']]);
        $orderUpdate = $conn->prepare("UPDATE orders SET status = 'Served', served_at = NOW(), served_by = ?, paid_at = COALESCE(paid_at, NOW()) WHERE id = ? AND status = 'Ready'");
        $orderUpdate->execute([$actorId, $orderId]);
        if ($orderUpdate->rowCount() !== 1) throw new RuntimeException('Order changed before delivery completion.');
        $conn->commit();

        require_once ROOT_PATH . '/includes/realtime.php';
        realtime_publish('kitchen:status_change', ['order_id' => $orderId, 'status' => 'Served']);
        delivery_flash($order['order_no'] . ' marked Delivered.');
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        delivery_flash($e->getMessage(), 'danger');
    }
}

$latestPayment = "SELECT p1.order_id, p1.payment_method, p1.payment_status FROM payments p1 INNER JOIN (SELECT order_id, MAX(id) AS id FROM payments GROUP BY order_id) lp ON lp.id = p1.id";

$deliveryFilter = $_GET['filter'] ?? 'all';
$filterConditions = [
    'all' => '',
    'lagro' => " AND COALESCE(o.delivery_mode, '') <> 'CUSTOMER_BOOKED'",
    'outside' => " AND o.delivery_mode = 'CUSTOMER_BOOKED'",
    'cod' => " AND p.payment_method = 'Cash on Delivery'",
];
if (!is_string($deliveryFilter) || !isset($filterConditions[$deliveryFilter])) $deliveryFilter = 'all';
$filterCondition = $filterConditions[$deliveryFilter];
$perPage = 10;
$eligibleWhere = "o.order_channel = 'ONLINE' AND o.order_type = 'ONLINE' AND o.status IN ('Pending', 'Preparing', 'Ready')";
$eligibleWhere .= $filterCondition;
$totalEligibleOrders = (int)$conn->query("SELECT COUNT(*) FROM orders o LEFT JOIN ($latestPayment) p ON p.order_id = o.id WHERE $eligibleWhere")->fetchColumn();
$totalEligiblePages = max(1, (int)ceil($totalEligibleOrders / $perPage));
$eligiblePage = max(1, min((int)($_GET['eligible_page'] ?? 1), $totalEligiblePages));
$eligibleOffset = ($eligiblePage - 1) * $perPage;

$ordersStmt = $conn->prepare("SELECT o.id, o.order_no, o.customer_name, o.customer_phone, o.delivery_address, o.delivery_lat, o.delivery_lng, o.coverage_status, o.coverage_boundary_version, o.courier_booking_by, o.notes AS order_notes, o.delivery_mode, o.delivery_tracking_url, o.status AS order_status, o.cod_approval_status, o.payment_verification_status, o.delivered_by_user_id, ur.full_name AS preassigned_rider_name, ur.phone AS preassigned_rider_phone, o.total, o.ready_at, o.created_at, p.payment_method, p.payment_status, d.rider_name, d.rider_phone, d.status AS dispatch_status, d.notes, d.assigned_at, d.out_at, d.delivered_at, d.failed_at, EXISTS(SELECT 1 FROM transaction_requests tr WHERE tr.order_id = o.id AND tr.request_type = 'Cancel Delivery' AND tr.status = 'Pending') AS cancel_pending FROM orders o LEFT JOIN users ur ON ur.id = o.delivered_by_user_id LEFT JOIN delivery_dispatches d ON d.order_id = o.id LEFT JOIN ($latestPayment) p ON p.order_id = o.id WHERE $eligibleWhere ORDER BY CASE WHEN o.status = 'Ready' THEN 0 ELSE 1 END ASC, o.id DESC LIMIT :limit OFFSET :offset");
$ordersStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$ordersStmt->bindValue(':offset', $eligibleOffset, PDO::PARAM_INT);
$ordersStmt->execute();
$orders = $ordersStmt->fetchAll();

$historyWhere = "o.order_channel = 'ONLINE' AND o.order_type = 'ONLINE' AND o.status NOT IN ('Pending', 'Preparing', 'Ready') AND (d.id IS NOT NULL OR o.status IN ('Served', 'Cancelled', 'Refunded', 'Voided'))";
$historyWhere .= $filterCondition;
$totalHistoryOrders = (int)$conn->query("SELECT COUNT(*) FROM orders o LEFT JOIN delivery_dispatches d ON d.order_id = o.id LEFT JOIN ($latestPayment) p ON p.order_id = o.id WHERE $historyWhere")->fetchColumn();
$totalHistoryPages = max(1, (int)ceil($totalHistoryOrders / $perPage));
$historyPage = max(1, min((int)($_GET['history_page'] ?? 1), $totalHistoryPages));
$historyOffset = ($historyPage - 1) * $perPage;

$historyStmt = $conn->prepare("SELECT o.id, o.order_no, o.customer_name, o.customer_phone, o.delivery_address, o.delivery_lat, o.delivery_lng, o.coverage_status, o.coverage_boundary_version, o.courier_booking_by, o.notes AS order_notes, o.delivery_mode, o.delivery_tracking_url, o.status AS order_status, o.total, o.served_at, o.created_at, p.payment_method, p.payment_status, d.rider_name, d.rider_phone, d.status AS dispatch_status, d.notes, d.assigned_at, d.out_at, d.delivered_at, d.failed_at FROM orders o LEFT JOIN delivery_dispatches d ON d.order_id = o.id LEFT JOIN ($latestPayment) p ON p.order_id = o.id WHERE $historyWhere ORDER BY CASE WHEN d.id IS NOT NULL THEN 0 ELSE 1 END ASC, COALESCE(d.delivered_at, d.failed_at, d.updated_at, o.served_at, o.created_at) DESC, o.id DESC LIMIT :limit OFFSET :offset");
$historyStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$historyStmt->bindValue(':offset', $historyOffset, PDO::PARAM_INT);
$historyStmt->execute();
$historyOrders = $historyStmt->fetchAll();

$buildDeliveryQuery = static function (array $overrides = []) use ($eligiblePage, $historyPage, $deliveryFilter): string {
    $params = array_merge([
        'filter' => $deliveryFilter,
        'eligible_page' => $eligiblePage,
        'history_page' => $historyPage,
    ], $overrides);
    return '?' . http_build_query($params);
};

include ROOT_PATH . '/includes/header.php';
?>
<div class="delivery-page">
<section class="card" style="margin-bottom:24px;">
    <div class="page-head" style="margin-bottom:12px;">
        <div>
            <h2 style="margin:0;">Recent Eligible Orders</h2>
            <p class="muted" style="margin:6px 0 0;">Record basic delivery handoff and completion status for eligible online orders. Physical delivery arrangements are handled manually outside the system.</p>
        </div>
        <?= render_pagination_controls($eligiblePage, $totalEligiblePages, static fn(int $p) => $buildDeliveryQuery(['eligible_page' => $p])) ?>
    </div>
    <nav class="delivery-guide" aria-label="Filter delivery orders">
        <?php foreach (['lagro' => ['Within Lagro', 'geo-alt', 'Free manual delivery by Kenji’s Kitchen staff.'], 'outside' => ['Outside Lagro', 'motorcycle', 'Customer or store arranges a third-party courier manually; staff records handoff and basic status.'], 'cod' => ['Cash on delivery', 'cash-stack', 'Marked paid only after cash collection is confirmed.']] as $key => $info): ?>
        <a class="delivery-filter-card <?= $deliveryFilter === $key ? 'is-selected' : '' ?>" href="?filter=<?= $key ?>" <?= $deliveryFilter === $key ? 'aria-current="page"' : '' ?>>
            <?php if ($key === 'outside'): ?>
            <i class="delivery-motorcycle" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7" cy="24" r="3.5"/><circle cx="25" cy="24" r="3.5"/><rect x="2" y="9" width="9" height="7" rx="1.5"/><path d="M3 19h10l3 5h5M11 17h5M16 17v4h4l3-7-2-7h-4M21 10h4v4h-2M4 19l-1 2M7 24h9"/></svg></i>
            <?php else: ?><i class="bi bi-<?= $info[1] ?>" aria-hidden="true"></i><?php endif; ?>
            <div><strong><?= e($info[0]) ?></strong><span><?= e($info[2]) ?></span><small><?= $deliveryFilter === $key ? 'Selected filter' : 'View orders' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></small></div>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="delivery-filter-summary"><span><?= $deliveryFilter === 'all' ? 'Showing all delivery orders' : 'Filtering eligible orders and history by: ' . e(['lagro' => 'Within Lagro', 'outside' => 'Outside Lagro', 'cod' => 'Cash on delivery'][$deliveryFilter]) ?></span><?php if ($deliveryFilter !== 'all'): ?><a href="?filter=all">Show all deliveries</a><?php endif; ?></div>
    <?php if ($flash): ?><div class="alert alert-<?= e($flashType) ?>"><?= e($flash) ?></div><?php endif; ?>
    <?php if (!$orders): ?><div class="delivery-empty"><span class="delivery-empty-icon"><i class="bi bi-bag" aria-hidden="true"></i></span><h3><?= $deliveryFilter === 'all' ? 'No orders ready for delivery status update' : 'No eligible orders match this filter' ?></h3><p>Ready or active eligible delivery orders will appear here.</p></div><?php endif; ?>
    <?php foreach ($orders as $order):
        $resolvedBooker = kk_resolve_courier_booking_by([
            'delivery_mode' => $order['delivery_mode'],
            'courier_booking_by' => $order['courier_booking_by'] ?? 'NONE',
            'notes' => $order['order_notes'] ?? '',
        ]);
        $hasCoords = $order['delivery_lat'] !== null && $order['delivery_lng'] !== null;
    ?>
    <div style="border-top:1px solid var(--border);padding:18px 0">
        <h3><?= e($order['order_no']) ?> · <?= e(($order['dispatch_status'] === 'Assigned' ? 'Ready for handoff' : $order['dispatch_status']) ?? ($order['order_status'] === 'Ready' ? 'Ready for handoff' : 'In Kitchen (' . $order['order_status'] . ')')) ?></h3>
        <p class="muted"><?= e($order['customer_name'] ?: 'Online Customer') ?> · <?= e($order['customer_phone'] ?: 'No phone') ?> · <?= e($order['delivery_address'] ?: 'No address') ?><?= $hasCoords ? ' · Pin: ' . e(number_format((float)$order['delivery_lat'], 5) . ', ' . number_format((float)$order['delivery_lng'], 5)) : '' ?></p>
        <p class="muted">Kitchen: <?= e($order['order_status']) ?> · <?= e($order['payment_method'] ?? '—') ?> / <?= e($order['payment_status'] ?? '—') ?> · <?= money($order['total']) ?> · Coverage: <?= e($order['coverage_status'] ?? 'NONE') ?></p>
        <p><strong>Arrangement:</strong> <?= $order['delivery_mode'] === 'CUSTOMER_BOOKED'
            ? ($resolvedBooker === 'STORE' ? 'Outside Greater Lagro · Store-arranged third-party courier (actual courier fee separate)' : 'Outside Greater Lagro · Customer-booked third-party courier (actual courier fee separate)')
            : 'Within Greater Lagro · Free Kenji\'s Kitchen manual delivery (₱0.00)' ?></p>
        <?php if ($order['delivery_mode'] === 'CUSTOMER_BOOKED' && $order['delivery_tracking_url'] && str_starts_with(strtolower($order['delivery_tracking_url']), 'https://')): ?><p><a href="<?= e($order['delivery_tracking_url']) ?>" target="_blank" rel="noopener noreferrer">Open third-party tracking ↗</a></p><?php endif; ?>
        <?php if ($order['notes']): ?><p class="muted">Delivery Notes: <?= e($order['notes']) ?></p><?php endif; ?>
        <?php if (!empty($order['order_notes'])): ?><p class="muted">Order Notes: <?= e($order['order_notes']) ?></p><?php endif; ?>
        <?php if (($order['payment_method'] ?? '') === 'Cash on Delivery' && ($order['cod_approval_status'] ?? '') !== 'Approved'): ?>
        <p class="muted" style="color:var(--warning,#d97706);font-weight:600;">COD approval is still <?= e($order['cod_approval_status'] ?: 'Pending') ?>. <a href="<?= e(BASE_URL . '/modules/pos/cod_approvals.php') ?>">Approve in COD Approvals</a> before kitchen/delivery handoff.</p>
        <?php elseif (strcasecmp((string)($order['payment_method'] ?? ''), 'GCash') === 0 && ($order['payment_status'] ?? '') !== 'Paid'): ?>
        <p class="muted" style="color:var(--warning,#d97706);font-weight:600;">GCash payment verification is still pending. <a href="<?= e(BASE_URL . '/modules/pos/payment_verification.php') ?>">Verify in QR Verification</a> before kitchen/delivery handoff.</p>
        <?php elseif ($order['order_status'] !== 'Ready'): ?>
        <p class="muted">Kitchen must mark this order Ready before delivery status actions become available.</p>
        <?php elseif ($order['cancel_pending']): ?><p class="muted">Cancellation pending Admin review. Delivery status updates are paused.</p><?php else: ?>
        <?php if ($order['order_status'] === 'Ready' && $order['dispatch_status'] === 'Failed' && $order['payment_method'] === 'Cash on Delivery' && $order['payment_status'] === 'Pending'): ?><p style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn btn-secondary" href="<?= e(BASE_URL . '/modules/pos/cod_approvals.php') ?>">Resolve Failed COD</a><a class="btn btn-secondary" href="<?= e(BASE_URL . '/modules/transactions/index.php?order_no=' . rawurlencode($order['order_no']) . '&request_type=Cancel%20Delivery') ?>">Request Cancellation</a></p><?php endif; ?>
        <?php if ($order['order_status'] === 'Ready' && (!$order['dispatch_status'] || $order['dispatch_status'] === 'Failed')): ?>
            <?php if ($order['delivery_mode'] === 'LAGRO_FREE' && $order['payment_method'] === 'GCash' && $order['payment_status'] === 'Paid'): ?>
            <form class="form" method="post" style="max-width:700px;margin-bottom:12px">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="action" value="set_external">
                <div class="field">
                    <label>Who books the third-party courier?</label>
                    <select name="courier_booking_by">
                        <option value="CUSTOMER">Customer books the courier</option>
                        <option value="STORE">Store books the courier</option>
                    </select>
                </div>
                <div class="field"><label>Third-party courier tracking link (optional until handoff)</label><input name="tracking_url" type="url" placeholder="https://..."></div>
                <div class="field"><label>Staff override reason (required, 8–500 characters for audit log)</label><input name="override_reason" minlength="8" maxlength="500" required placeholder="Explain why this order is being changed to Outside Lagro..."></div>
                <button class="btn btn-secondary" type="submit">Override to Outside-Lagro Third-Party Delivery</button>
            </form>
            <?php elseif ($order['delivery_mode'] === 'CUSTOMER_BOOKED'): ?>
            <form class="form" method="post" style="max-width:700px;margin-bottom:12px">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="action" value="set_local">
                <div class="field"><label>Staff override reason (required, 8–500 characters for audit log)</label><input name="override_reason" minlength="8" maxlength="500" required placeholder="Explain why this order qualifies for Within-Lagro delivery..."></div>
                <button class="btn btn-secondary" type="submit">Override to Within-Lagro Free Delivery</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($order['order_status'] === 'Ready' && $order['delivery_mode'] === 'CUSTOMER_BOOKED'): ?>
        <form class="form" method="post" style="max-width:700px;margin-bottom:12px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="action" value="tracking_link"><div class="field"><label>Third-party tracking link (<?= $resolvedBooker === 'STORE' ? 'store staff' : 'customer or staff' ?> must enter before handoff)</label><input name="tracking_url" type="url" value="<?= e($order['delivery_tracking_url'] ?? '') ?>" placeholder="https://..."></div><button class="btn btn-secondary" type="submit">Save Tracking Link</button></form>
        <?php endif; ?>
        <?php if ($order['order_status'] === 'Ready' && (!$order['dispatch_status'] || in_array($order['dispatch_status'], ['Assigned', 'Failed'], true)) && $order['delivery_mode'] === 'LAGRO_FREE'): ?>
        <form class="form" method="post" style="max-width:700px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="action" value="out">
            <div class="field"><label>Delivery / handoff note (optional)</label><input name="notes" maxlength="500" placeholder="Optional manual delivery arrangement note"></div>
            <button class="btn btn-primary" type="submit"><?= $order['dispatch_status'] === 'Failed' ? 'Retry: Mark Out for Delivery' : 'Mark Out for Delivery' ?></button>
        </form>
        <?php elseif ($order['order_status'] === 'Ready' && (!$order['dispatch_status'] || $order['dispatch_status'] === 'Failed') && $order['delivery_mode'] === 'CUSTOMER_BOOKED'): ?>
            <?php if ($order['delivery_tracking_url']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-primary" name="action" value="handoff" type="submit">Confirm Handoff to <?= $resolvedBooker === 'STORE' ? 'Store-Arranged' : 'Customer-Booked' ?> Courier</button></form><?php else: ?><p class="muted">Waiting for <?= $resolvedBooker === 'STORE' ? 'staff' : 'the customer or staff' ?> to add the third-party courier's HTTPS share link before handoff.</p><?php endif; ?>
        <?php elseif ($order['order_status'] === 'Ready' && $order['dispatch_status'] === 'Out for Delivery'): ?>
        <div class="grid grid-2">
            <form class="form cod-deliver-form" method="post" data-order-no="<?= e($order['order_no']) ?>" data-order-total="<?= e(money($order['total'])) ?>" data-payment-method="<?= e($order['payment_method'] ?: 'Cash on Delivery') ?>" data-is-paid="<?= $order['payment_status'] === 'Paid' ? '1' : '0' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="action" value="delivered">
                <?php if ($order['payment_status'] !== 'Paid'): ?>
                <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;background:#fffbeb;border:1px solid #f59e0b;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="cash_collected" value="1" class="cod-collected-checkbox" style="width:18px;height:18px;accent-color:#d97706;">
                    <span>I confirm cash (<?= e(money($order['total'])) ?>) was collected on delivery.</span>
                </label>
                <?php endif; ?>
                <button class="btn btn-primary cod-deliver-btn" type="submit" <?= $order['payment_status'] !== 'Paid' ? 'disabled style="opacity:0.55;cursor:not-allowed;"' : '' ?>>
                    <i class="bi bi-check2-circle"></i> Mark Delivered
                </button>
            </form>
            <form class="form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><div class="field"><label>Failure reason</label><input name="notes" minlength="8" maxlength="500" required></div><button class="btn btn-secondary" name="action" value="failed" type="submit">Record Failure</button></form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>

<section class="card">
    <div class="page-head" style="margin-bottom:16px;">
        <div>
            <h2 style="margin:0;">Delivery Status History</h2>
            <p class="muted" style="margin:6px 0 0;">Completed and historical online delivery status records.</p>
        </div>
        <?= render_pagination_controls($historyPage, $totalHistoryPages, static fn(int $p) => $buildDeliveryQuery(['history_page' => $p])) ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer &amp; Address</th>
                    <th>Arrangement</th>
                    <th>Delivery Notes</th>
                    <th>Delivery Status</th>
                    <th>Order / Payment</th>
                    <th>Total</th>
                    <th>Completed / Recorded<br>Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$historyOrders): ?>
                    <tr><td colspan="8" class="muted">No delivery status history found.</td></tr>
                <?php else: ?>
                    <?php foreach ($historyOrders as $hist):
                        $histBooker = kk_resolve_courier_booking_by([
                            'delivery_mode' => $hist['delivery_mode'],
                            'courier_booking_by' => $hist['courier_booking_by'] ?? 'NONE',
                            'notes' => $hist['order_notes'] ?? '',
                        ]);
                    ?>
                    <tr>
                        <td><strong><?= e($hist['order_no']) ?></strong></td>
                        <td>
                            <div><?= e($hist['customer_name'] ?: 'Online Customer') ?><?= $hist['customer_phone'] ? ' · ' . e($hist['customer_phone']) : '' ?></div>
                            <div class="muted" style="font-size:14px;"><?= e($hist['delivery_address'] ?: 'No address recorded') ?></div>
                        </td>
                        <td>
                            <?= $hist['delivery_mode'] === 'CUSTOMER_BOOKED'
                                ? ($histBooker === 'STORE' ? 'Outside Lagro (Store-arranged courier)' : 'Outside Lagro (Customer-booked courier)')
                                : 'Within Lagro (Free)' ?>
                            <?php if ($hist['delivery_mode'] === 'CUSTOMER_BOOKED' && !empty($hist['delivery_tracking_url']) && str_starts_with(strtolower((string)$hist['delivery_tracking_url']), 'https://')): ?>
                                <div><a href="<?= e($hist['delivery_tracking_url']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:14px;color:#765b08;">External Link ↗</a></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($hist['notes'])): ?>
                                <div><?= e($hist['notes']) ?></div>
                            <?php elseif (!empty($hist['rider_name'])): ?>
                                <div class="muted"><?= e($hist['rider_name']) ?></div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $dStatus = (string)($hist['dispatch_status'] ?? ($hist['order_status'] === 'Served' ? 'Delivered' : $hist['order_status']));
                            $dBadge = $dStatus === 'Delivered' ? 'badge-success' : ($dStatus === 'Failed' || $dStatus === 'Cancelled' ? 'badge-danger' : 'badge-warning');
                            ?>
                            <span class="badge <?= $dBadge ?>"><?= e($dStatus) ?></span>
                        </td>
                        <td>
                            <div><?= e($hist['order_status']) ?></div>
                            <div class="muted" style="font-size:14px;"><?= e($hist['payment_method'] ?? '—') ?> / <?= e($hist['payment_status'] ?? '—') ?></div>
                        </td>
                        <td><?= money($hist['total']) ?></td>
                        <td><?= e($hist['delivered_at'] ?? $hist['failed_at'] ?? $hist['served_at'] ?? $hist['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
</div>
<style>
.delivery-page .card { border:1px solid #e8e8e4; border-radius:16px; box-shadow:0 3px 14px rgba(0,0,0,.025); }
.delivery-page .page-head { display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; }
.delivery-page .page-head > div:first-child { flex:1 1 420px; min-width:0; }
.delivery-page .page-head h2 { font-size:28px; line-height:1.3; color:#242424; }
.delivery-page .page-head p { font-size:15px; line-height:1.6; }
.delivery-page .page-head .kk-pagination { flex:0 0 auto; justify-content:flex-end; }
.delivery-guide { display:grid; grid-template-columns:1fr 1.4fr 1.2fr; gap:20px; background:#fff9e7; border:1px solid #eee3be; border-radius:12px; padding:18px; margin:20px 0; }
.delivery-guide strong { display:block; color:#343434; margin-bottom:6px; font-size:14px; }
.delivery-guide span { display:block; font-size:14px; line-height:1.6; color:#5e5a4c; }
.delivery-guide i { margin-right:5px; }
.delivery-empty { text-align:center; padding:26px 16px; }
.delivery-empty-icon { display:flex; align-items:center; justify-content:center; width:56px; height:56px; margin:0 auto 14px; border-radius:16px; background:#fff3c4; color:#242424; font-size:26px; }
.delivery-empty h3 { font-size:20px; margin:0 0 8px; }
.delivery-empty p { font-size:15px; color:#656565; margin:0; line-height:1.6; }
.delivery-page table { min-width:1100px; width:100%; }
.delivery-page th { background:#f7f7f4; font-size:13px; color:#5b5b56; }
.delivery-page td { font-size:15px; vertical-align:top; line-height:1.55; }
.delivery-page th, .delivery-page td { padding:16px; }
.delivery-page td:nth-child(1) { min-width:165px; }
.delivery-page td:nth-child(2) { min-width:260px; max-width:360px; overflow-wrap:anywhere; }
.delivery-page td:nth-child(7) { white-space:nowrap; font-weight:600; font-variant-numeric:tabular-nums; }
.delivery-page td:last-child { min-width:160px; font-variant-numeric:tabular-nums; }
.delivery-page .table-wrap { border:1px solid #ededE8; border-radius:12px; overflow-x:auto; margin-top:20px; }
.delivery-page .btn { min-height:40px; border-radius:9px; font-size:14px; }
.delivery-page .btn-primary { background:#f7c62f; color:#242424; border-color:#e8b720; }
.delivery-page input:focus, .delivery-page select:focus, .delivery-page textarea:focus { outline:2px solid #b48b12; outline-offset:2px; }
.delivery-page input[type=checkbox] { accent-color:#c49a19!important; }
@media(max-width:700px) { .delivery-guide { grid-template-columns:1fr; gap:14px; } .delivery-page .page-head h2 { font-size:24px; } .delivery-page .page-head .kk-pagination { flex:1 1 100%; } }
.delivery-guide { grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; background:none; border:0; padding:0; margin:20px 0 12px; }
.delivery-filter-card { display:flex; align-items:flex-start; gap:14px; padding:20px; border:1px solid #e5e1d7; border-radius:12px; background:#fff; text-decoration:none; transition:background .15s,border-color .15s; }
.delivery-filter-card:hover { background:#fffbed; border-color:#dfb637; }
.delivery-filter-card.is-selected { background:#fff3bd; border-color:#d6ab24; box-shadow:inset 0 0 0 1px #d6ab24; }
.delivery-filter-card:focus-visible { outline:3px solid #a77b06; outline-offset:3px; }
.delivery-filter-card>i { display:grid; place-items:center; flex:0 0 42px; height:42px; border-radius:11px; background:#fff2bf; color:#775600; font-size:22px; margin:0; }
.delivery-filter-card.is-selected>i { background:#f7c62f; color:#242424; }
.delivery-filter-card small { display:block; margin-top:12px; font-size:12px; font-weight:600; color:#705300; }
.delivery-filter-summary { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#737063; margin-bottom:16px; }
.delivery-filter-summary a { color:#755600; font-weight:600; text-underline-offset:3px; }
@media(max-width:900px) { .delivery-guide { grid-template-columns:1fr; } }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cod-deliver-form').forEach(function (form) {
        var chk = form.querySelector('.cod-collected-checkbox');
        var btn = form.querySelector('.cod-deliver-btn');
        if (chk && btn) {
            chk.addEventListener('change', function () {
                btn.disabled = !chk.checked;
                btn.style.opacity = chk.checked ? '1' : '0.55';
                btn.style.cursor = chk.checked ? 'pointer' : 'not-allowed';
            });
        }

        form.addEventListener('submit', async function (e) {
            if (form.dataset.kkConfirmed === '1') {
                delete form.dataset.kkConfirmed;
                return;
            }
            if (chk && !chk.checked) {
                e.preventDefault();
                if (window.kkNotify) {
                    window.kkNotify('Please check the confirmation box that Cash on Delivery was collected before marking this order delivered.', 'warning', { title: 'COD Collection Required' });
                }
                return;
            }
            if (window.kkConfirm) {
                e.preventDefault();
                var isPaid = form.dataset.isPaid === '1';
                var orderNo = form.dataset.orderNo || 'Order';
                var orderTotal = form.dataset.orderTotal || '';
                var payMethod = form.dataset.paymentMethod || 'Cash on Delivery';
                var confirmed = await window.kkConfirm({
                    title: isPaid ? 'Complete Delivery Order?' : 'Confirm COD Collection & Complete Delivery?',
                    details: [
                        { label: 'Order Number', value: orderNo },
                        { label: 'Amount to Collect', value: isPaid ? (orderTotal + ' (Paid)') : orderTotal },
                        { label: 'Payment Method', value: payMethod }
                    ],
                    message: isPaid
                        ? 'Confirm that this order has been delivered to the customer.'
                        : 'Confirm that you have collected the Cash on Delivery payment from the customer before marking this order delivered.',
                    confirmText: isPaid ? 'Confirm Delivery' : 'Confirm COD & Mark Delivered',
                    cancelText: 'Cancel',
                    variant: 'primary'
                });
                if (!confirmed) return;
                form.dataset.kkConfirmed = '1';
                if (btn) btn.disabled = true;
                form.submit();
            }
        });
    });
});
</script>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
