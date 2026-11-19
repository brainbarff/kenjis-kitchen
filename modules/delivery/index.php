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

function delivery_sanitize_filter($value): string
{
    return is_string($value) && in_array($value, ['all', 'lagro', 'outside', 'cod'], true) ? $value : 'all';
}

function delivery_flash(string $message, string $type = 'success', string $filter = 'all'): void
{
    $_SESSION['delivery_flash'] = $message;
    $_SESSION['delivery_flash_type'] = $type;
    $targetFilter = delivery_sanitize_filter($filter);
    $suffix = $targetFilter !== 'all' ? ('?filter=' . rawurlencode($targetFilter)) : '';
    redirect('/modules/delivery/index.php' . $suffix);
}

$activeReturnFilter = delivery_sanitize_filter($_POST['filter'] ?? ($_GET['filter'] ?? 'all'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) delivery_flash('Invalid security token. Reload the page and try again.', 'danger', $activeReturnFilter);
    $orderId = (int)($_POST['order_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $allowedActions = ['out', 'delivered', 'failed', 'set_external', 'set_local', 'tracking_link', 'handoff', 'quote_fee', 'verify_fee_payment', 'approve_tracking', 'reject_tracking'];
    if ($orderId < 1 || !in_array($action, $allowedActions, true)) {
        delivery_flash('Invalid delivery action.', 'danger', $activeReturnFilter);
    }

    $currentUser = current_user();
    $actorId = (int)($currentUser['id'] ?? $_SESSION['u_id']);
    $actorName = (string)($currentUser['username'] ?? 'staff');
    $roleKey = (string)($currentUser['role_key'] ?? '');

    try {
        // Early-stage & pre-Ready actions allowed while order is Pending / Preparing / Ready
        if ($action === 'approve_tracking') {
            $conn->beginTransaction();
            $review = kk_review_option1_tracking_link($conn, $orderId, 'verify', $actorId);
            $conn->commit();
            if (!empty($review['released_to_kitchen']) && file_exists(ROOT_PATH . '/includes/realtime.php')) {
                require_once ROOT_PATH . '/includes/realtime.php';
                if (function_exists('realtime_publish')) {
                    realtime_publish('order:submitted', [
                        'order_id' => $orderId,
                        'order_no' => $review['order_no'],
                        'queue_no' => $review['queue_no'],
                        'status' => 'Pending'
                    ]);
                }
            }
            $msg = !empty($review['released_to_kitchen'])
                ? "Tracking link approved for #{$review['order_no']} and released to Kitchen."
                : "Tracking link approved for #{$review['order_no']}.";
            delivery_flash($msg, 'success', $activeReturnFilter);
        }

        if ($action === 'reject_tracking') {
            $reason = trim((string)($_POST['rejection_reason'] ?? ''));
            $conn->beginTransaction();
            $review = kk_review_option1_tracking_link($conn, $orderId, 'reject', $actorId, $reason);
            $conn->commit();
            delivery_flash("Tracking link rejected for #{$review['order_no']}. Customer has been prompted in My Orders.", 'success', $activeReturnFilter);
        }

        if ($action === 'tracking_link') {
            $conn->beginTransaction();
            kk_save_staff_tracking_link($conn, $orderId, (string)($_POST['tracking_url'] ?? ''), $actorId);
            $conn->commit();
            delivery_flash('Third-party courier tracking link saved and visible in customer My Orders.', 'success', $activeReturnFilter);
        }

        if ($action === 'quote_fee') {
            $service = trim((string)($_POST['booking_service'] ?? 'Lalamove'));
            $fee = max(0, (float)($_POST['delivery_fee'] ?? 0));
            $trackingRef = trim((string)($_POST['tracking_reference'] ?? ''));
            if ($fee <= 0) {
                throw new InvalidArgumentException('Enter a quoted third-party delivery fee greater than ₱0.00.');
            }
            $conn->beginTransaction();
            $ordStmt = $conn->prepare("SELECT id, order_no, order_channel, order_type, status, delivery_mode, fulfillment_mode, courier_booking_by, notes FROM orders WHERE id = ? FOR UPDATE");
            $ordStmt->execute([$orderId]);
            $ord = $ordStmt->fetch();
            if (!$ord || $ord['order_channel'] !== 'ONLINE' || $ord['order_type'] !== 'ONLINE'
                || $ord['delivery_mode'] !== 'CUSTOMER_BOOKED' || kk_resolve_courier_booking_by($ord) !== 'STORE'
                || !in_array((string)$ord['status'], ['Pending', 'Preparing', 'Ready'], true)) {
                throw new RuntimeException('Store-booked outside-Lagro order not eligible for fee quoting.');
            }

            $normalizedUrl = null;
            if ($trackingRef !== '' && (preg_match('#^https?://#i', $trackingRef) || str_contains($trackingRef, '://'))) {
                $normalizedUrl = normalize_delivery_tracking_url($trackingRef);
                $trackingRef = $normalizedUrl ?? $trackingRef;
            }

            $checkStmt = $conn->prepare("SELECT id FROM thirdparty_deliveries WHERE order_id = ? LIMIT 1 FOR UPDATE");
            $checkStmt->execute([$orderId]);
            if ($checkStmt->fetchColumn()) {
                $conn->prepare("UPDATE thirdparty_deliveries SET booking_service = ?, delivery_fee = ?, tracking_reference = ?, booked_by = ?, booked_at = NOW(), fee_notified_at = NOW(), status = CASE WHEN fee_paid = 1 THEN status ELSE 'Quoted' END WHERE order_id = ?")
                    ->execute([$service, $fee, $trackingRef ?: null, $actorId, $orderId]);
            } else {
                $conn->prepare("INSERT INTO thirdparty_deliveries (order_id, booking_service, delivery_fee, tracking_reference, booked_by, booked_at, fee_notified_at, status) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'Quoted')")
                    ->execute([$orderId, $service, $fee, $trackingRef ?: null, $actorId]);
            }

            $conn->prepare("UPDATE orders SET delivery_fee = ? WHERE id = ?")->execute([$fee, $orderId]);
            if ($normalizedUrl !== null) {
                kk_save_staff_tracking_link($conn, $orderId, $normalizedUrl, $actorId);
            }
            log_audit($conn, 'outside_lagro_quoted', 'order', (string)$orderId, "Quoted $service fee: ₱$fee by $actorName");
            $conn->commit();
            delivery_flash("Third-party courier quote saved for #{$ord['order_no']} (GCash fee payment awaited).", 'success', $activeReturnFilter);
        }

        if ($action === 'verify_fee_payment') {
            $proofId = (int)($_POST['proof_id'] ?? 0);
            if ($proofId < 1) {
                throw new RuntimeException('Invalid courier fee proof selected.');
            }
            $conn->beginTransaction();
            $updProof = $conn->prepare("UPDATE payment_proofs SET verification_status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ? AND order_id = ? AND payment_type = 'thirdparty_delivery' AND verification_status = 'pending'");
            $updProof->execute([$actorId, $proofId, $orderId]);
            if ($updProof->rowCount() !== 1) {
                throw new RuntimeException('Fee payment proof is no longer pending.');
            }
            $conn->prepare("UPDATE thirdparty_deliveries SET fee_paid = 1, fee_payment_proof_id = ?, status = 'Fee Paid' WHERE order_id = ?")
                ->execute([$proofId, $orderId]);
            log_audit($conn, 'outside_lagro_fee_verified', 'order', (string)$orderId, "Third-party delivery fee verified by $actorName");
            $conn->commit();
            delivery_flash('Delivery fee verified! Order can be handed off once Kitchen marks it Ready and a tracking link is saved.', 'success', $activeReturnFilter);
        }

        // Dispatch / completion actions require Kitchen status = Ready
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
        $cancellationCheck = $conn->prepare("SELECT COUNT(*) FROM transaction_requests WHERE order_id = ? AND request_type = 'Cancel Delivery' AND status = 'Pending'");
        $cancellationCheck->execute([$orderId]);
        if ((int)$cancellationCheck->fetchColumn() > 0) throw new RuntimeException('Cancellation review is pending.');

        if ($action === 'handoff') {
            $res = kk_dispatch_thirdparty_order($conn, $orderId, $actorId);
            $conn->commit();
            delivery_flash('Third-party rider handoff recorded for ' . $res['order_no'] . '.', 'success', $activeReturnFilter);
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
            delivery_flash($order['order_no'] . ' marked Out for Delivery.', 'success', $activeReturnFilter);
        }

        if ($action === 'failed') {
            $res = kk_fail_delivery_order($conn, $orderId, $actorId, (string)($_POST['notes'] ?? ''));
            $conn->commit();
            delivery_flash('Delivery failure recorded for ' . $res['order_no'] . '. You can retry handoff once resolved.', 'success', $activeReturnFilter);
        }

        $res = kk_complete_delivery_order($conn, $orderId, $actorId, $roleKey, (string)($_POST['cash_collected'] ?? '') === '1');
        $conn->commit();

        require_once ROOT_PATH . '/includes/realtime.php';
        if (function_exists('realtime_publish')) {
            realtime_publish('kitchen:status_change', ['order_id' => $orderId, 'status' => 'Served']);
        }
        delivery_flash($res['order_no'] . ' marked Delivered.', 'success', $activeReturnFilter);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        delivery_flash($e->getMessage(), 'danger', $activeReturnFilter);
    }
}

$latestPayment = "SELECT p1.order_id, p1.payment_method, p1.payment_status FROM payments p1 INNER JOIN (SELECT order_id, MAX(id) AS id FROM payments GROUP BY order_id) lp ON lp.id = p1.id";

$deliveryFilter = delivery_sanitize_filter($_GET['filter'] ?? 'all');
$filterConditions = [
    'all' => '',
    'lagro' => " AND COALESCE(o.delivery_mode, '') <> 'CUSTOMER_BOOKED'",
    'outside' => " AND o.delivery_mode = 'CUSTOMER_BOOKED'",
    'cod' => " AND p.payment_method = 'Cash on Delivery'",
];
$filterCondition = $filterConditions[$deliveryFilter];
$perPage = 10;
$eligibleWhere = "o.order_channel = 'ONLINE' AND o.order_type = 'ONLINE' AND o.status IN ('Pending', 'Preparing', 'Ready')";
$eligibleWhereWithFilter = $eligibleWhere . $filterCondition;

$filterCountRow = $conn->query("
    SELECT
        SUM(CASE WHEN COALESCE(o.delivery_mode, '') <> 'CUSTOMER_BOOKED' THEN 1 ELSE 0 END) AS lagro_count,
        SUM(CASE WHEN o.delivery_mode = 'CUSTOMER_BOOKED' THEN 1 ELSE 0 END) AS outside_count,
        SUM(CASE WHEN p.payment_method = 'Cash on Delivery' THEN 1 ELSE 0 END) AS cod_count
    FROM orders o
    LEFT JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE $eligibleWhere
")->fetch(PDO::FETCH_ASSOC);
$filterCounts = [
    'lagro' => (int)($filterCountRow['lagro_count'] ?? 0),
    'outside' => (int)($filterCountRow['outside_count'] ?? 0),
    'cod' => (int)($filterCountRow['cod_count'] ?? 0),
];

$totalEligibleOrders = (int)$conn->query("SELECT COUNT(*) FROM orders o LEFT JOIN ($latestPayment) p ON p.order_id = o.id WHERE $eligibleWhereWithFilter")->fetchColumn();
$totalEligiblePages = max(1, (int)ceil($totalEligibleOrders / $perPage));
$eligiblePage = max(1, min((int)($_GET['eligible_page'] ?? 1), $totalEligiblePages));
$eligibleOffset = ($eligiblePage - 1) * $perPage;

$ordersStmt = $conn->prepare("
    SELECT o.id, o.order_no, o.customer_name, o.customer_phone, o.delivery_address,
           o.delivery_lat, o.delivery_lng, o.coverage_status, o.coverage_boundary_version,
           o.courier_booking_by, o.notes AS order_notes, o.delivery_mode,
           o.delivery_tracking_url, o.courier_tracking_status, o.courier_tracking_rejection_reason,
           o.status AS order_status, o.kitchen_queued_at, o.cod_approval_status,
           o.payment_verification_status, o.delivered_by_user_id,
           ur.full_name AS preassigned_rider_name, ur.phone AS preassigned_rider_phone,
           o.total, o.subtotal, o.ready_at, o.created_at,
           p.payment_method, p.payment_status,
           d.rider_name, d.rider_phone, d.status AS dispatch_status, d.notes, d.assigned_at, d.out_at, d.delivered_at, d.failed_at,
           tpd.booking_service, tpd.delivery_fee AS tp_fee, tpd.tracking_reference, tpd.fee_paid, tpd.status AS tp_status,
           pp.id AS fee_proof_id, pp.reference_number AS fee_ref_no, pp.verification_status AS fee_proof_status,
           EXISTS(SELECT 1 FROM transaction_requests tr WHERE tr.order_id = o.id AND tr.request_type = 'Cancel Delivery' AND tr.status = 'Pending') AS cancel_pending
    FROM orders o
    LEFT JOIN users ur ON ur.id = o.delivered_by_user_id
    LEFT JOIN delivery_dispatches d ON d.order_id = o.id
    LEFT JOIN ($latestPayment) p ON p.order_id = o.id
    LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id
    LEFT JOIN payment_proofs pp ON pp.id = (
        SELECT MAX(pp2.id) FROM payment_proofs pp2
        WHERE pp2.order_id = o.id AND pp2.payment_type = 'thirdparty_delivery' AND pp2.verification_status = 'pending'
    )
    WHERE $eligibleWhereWithFilter
    ORDER BY
        CASE WHEN o.status = 'Ready' THEN 0 ELSE 1 END ASC,
        CASE WHEN o.delivery_mode = 'CUSTOMER_BOOKED' AND o.courier_tracking_status = 'Pending' THEN 0 ELSE 1 END ASC,
        o.id DESC
    LIMIT :limit OFFSET :offset
");
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
            <p class="muted" style="margin:6px 0 0;">Record delivery handoff, courier verification, and completion status for online delivery orders. Physical delivery arrangements are handled manually outside the system.</p>
        </div>
        <?= render_pagination_controls($eligiblePage, $totalEligiblePages, static fn(int $p) => $buildDeliveryQuery(['eligible_page' => $p])) ?>
    </div>
    <nav class="delivery-guide" aria-label="Filter delivery orders">
        <?php foreach (['lagro' => ['Within Lagro', 'geo-alt', 'Free store delivery by Kenji’s Kitchen staff.'], 'outside' => ['Outside Lagro', 'motorcycle', 'Third-party courier (customer link approval or staff fee quote).'], 'cod' => ['Cash on delivery', 'cash-stack', 'Marked paid only after cash collection is confirmed.']] as $key => $info): ?>
        <a class="delivery-filter-card <?= $deliveryFilter === $key ? 'is-selected' : '' ?>" href="?filter=<?= $key ?>" <?= $deliveryFilter === $key ? 'aria-current="page"' : '' ?>>
            <?php if ($key === 'outside'): ?>
            <i class="delivery-motorcycle" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7" cy="24" r="3.5"/><circle cx="25" cy="24" r="3.5"/><rect x="2" y="9" width="9" height="7" rx="1.5"/><path d="M3 19h10l3 5h5M11 17h5M16 17v4h4l3-7-2-7h-4M21 10h4v4h-2M4 19l-1 2M7 24h9"/></svg></i>
            <?php else: ?><i class="bi bi-<?= $info[1] ?>" aria-hidden="true"></i><?php endif; ?>
            <div style="flex:1;min-width:0;">
                <strong><span><?= e($info[0]) ?></span><?php if ($filterCounts[$key] > 0): ?><span class="delivery-filter-count"><?= $filterCounts[$key] ?></span><?php endif; ?></strong>
                <span><?= e($info[2]) ?></span>
                <small><?= $deliveryFilter === $key ? 'Selected filter' : 'View orders' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></small>
            </div>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="delivery-filter-summary"><span><?= $deliveryFilter === 'all' ? 'Showing all delivery orders' : 'Filtering eligible orders and history by: ' . e(['lagro' => 'Within Lagro', 'outside' => 'Outside Lagro', 'cod' => 'Cash on delivery'][$deliveryFilter]) ?></span><?php if ($deliveryFilter !== 'all'): ?><a href="?filter=all">Show all deliveries</a><?php endif; ?></div>
    <?php if ($deliveryFilter === 'outside'): ?>
    <div class="outside-policy-banner" role="note">
        <span class="outside-policy-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
        <div class="outside-policy-body">
            <strong>Outside Lagro Delivery Guide</strong>
            <p><strong>Customer Books Rider:</strong> Check and approve the customer's courier tracking link to send the order to Kitchen.</p>
            <p><strong>Staff Books Rider:</strong> Enter the courier fee quote, confirm the customer's GCash fee receipt, and save the tracking link before handoff.</p>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flashType) ?>"><?= e($flash) ?></div><?php endif; ?>
    <?php if (!$orders): ?><div class="delivery-empty"><span class="delivery-empty-icon"><i class="bi bi-bag" aria-hidden="true"></i></span><h3><?= $deliveryFilter === 'all' ? 'No orders ready for delivery status update' : 'No eligible orders match this filter' ?></h3><p>Ready or active eligible delivery orders will appear here.</p></div><?php endif; ?>
    <?php foreach ($orders as $order):
        $resolvedBooker = kk_resolve_courier_booking_by([
            'delivery_mode' => $order['delivery_mode'],
            'courier_booking_by' => $order['courier_booking_by'] ?? 'NONE',
            'notes' => $order['order_notes'] ?? '',
        ]);
        $isOutside = ($order['delivery_mode'] === 'CUSTOMER_BOOKED');
        $isCustomerBooked = $isOutside && ($resolvedBooker === 'CUSTOMER');
        $isStoreBooked = $isOutside && ($resolvedBooker === 'STORE');
        $hasHttpsTracking = !empty($order['delivery_tracking_url']) && str_starts_with(strtolower((string)$order['delivery_tracking_url']), 'https://');
        $trackVerifyStatus = (string)($order['courier_tracking_status'] ?? 'None');
        $foodPaid = strcasecmp((string)($order['payment_method'] ?? ''), 'GCash') === 0 && ($order['payment_status'] ?? '') === 'Paid';
        $isOutForDelivery = ($order['dispatch_status'] === 'Out for Delivery');
        $tpFee = (float)($order['tp_fee'] ?? 0);
        $cleanOrderNotes = trim((string)preg_replace('/^\[Courier booking:[^\]]+\]\s*/i', '', (string)($order['order_notes'] ?? '')));
        $trackBadgeClass = $trackVerifyStatus === 'Verified' ? 'badge-success' : ($trackVerifyStatus === 'Rejected' ? 'badge-danger' : ($trackVerifyStatus === 'Pending' ? 'badge-warning' : 'badge-muted'));

        if ($isOutForDelivery) {
            $stageLabel = 'Out for Delivery';
            $stageClass = 'is-out';
        } elseif ($order['dispatch_status'] === 'Failed') {
            $stageLabel = 'Delivery Attempt Failed';
            $stageClass = 'is-failed';
        } elseif ($order['order_status'] === 'Ready' || $order['dispatch_status'] === 'Assigned') {
            $stageLabel = 'Ready for Handoff';
            $stageClass = 'is-ready';
        } elseif ($order['kitchen_queued_at'] === null && $order['order_status'] === 'Pending') {
            if ($isCustomerBooked && $trackVerifyStatus !== 'Verified') {
                $stageLabel = 'Awaiting Link Approval';
            } elseif (($order['payment_method'] ?? '') === 'Cash on Delivery' && ($order['cod_approval_status'] ?? '') !== 'Approved') {
                $stageLabel = 'Awaiting COD Approval';
            } else {
                $stageLabel = 'Awaiting Payment Verification';
            }
            $stageClass = 'is-hold';
        } else {
            $stageLabel = 'In Kitchen (' . $order['order_status'] . ')';
            $stageClass = 'is-kitchen';
        }
    ?>
    <article class="delivery-order-card">
        <div class="delivery-order-head">
            <div class="delivery-order-title-group">
                <h3 class="delivery-order-no"><?= e($order['order_no']) ?></h3>
                <span class="delivery-stage-badge <?= $stageClass ?>"><?= e($stageLabel) ?></span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <?php if ($isOutside): ?>
                    <span class="delivery-arrangement-badge <?= $isCustomerBooked ? 'is-outside-opt1' : 'is-outside-opt2' ?>"><?= $isCustomerBooked ? 'Outside Lagro · Customer Books Rider' : 'Outside Lagro · Staff Books Rider' ?></span>
                <?php else: ?>
                    <span class="delivery-arrangement-badge is-lagro">Within Lagro · Free Delivery</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="delivery-meta-grid">
            <div class="delivery-meta-item">
                <span class="delivery-meta-label">Customer</span>
                <strong><?= e($order['customer_name'] ?: 'Online Customer') ?></strong>
                <span class="delivery-meta-sub"><?= e($order['customer_phone'] ?: 'No phone') ?></span>
            </div>
            <div class="delivery-meta-item">
                <span class="delivery-meta-label">Destination</span>
                <strong><?= e($order['delivery_address'] ?: 'No address recorded') ?></strong>
            </div>
            <div class="delivery-meta-item">
                <span class="delivery-meta-label">Order &amp; Payment</span>
                <strong><?= money($order['total']) ?></strong>
                <span class="delivery-meta-sub"><?= e($order['payment_method'] ?: 'Not set') ?> (<?= e($order['payment_status'] ?: 'Pending') ?>)</span>
            </div>
        </div>

        <?php if (!empty($order['preassigned_rider_name']) || $cleanOrderNotes !== '' || !empty($order['notes']) || ($isOutside && $hasHttpsTracking)): ?>
        <div class="delivery-extra-notes">
            <?php if (!empty($order['preassigned_rider_name'])): ?>
                <div><strong>Assigned Staff:</strong> <?= e($order['preassigned_rider_name']) ?><?= !empty($order['preassigned_rider_phone']) ? ' (' . e($order['preassigned_rider_phone']) . ')' : '' ?></div>
            <?php endif; ?>
            <?php if ($cleanOrderNotes !== ''): ?>
                <div><strong>Customer Note:</strong> <?= e($cleanOrderNotes) ?></div>
            <?php endif; ?>
            <?php if (!empty($order['notes'])): ?>
                <div><strong>Delivery Note:</strong> <?= e($order['notes']) ?></div>
            <?php endif; ?>
            <?php if ($isOutside && $hasHttpsTracking): ?>
                <div><a href="<?= e($order['delivery_tracking_url']) ?>" target="_blank" rel="noopener noreferrer" class="delivery-track-link"><?= $isOutForDelivery ? 'Open Tracking Link to Verify Drop-Off ↗' : 'Open third-party tracking ↗' ?></a></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isCustomerBooked): ?>
        <div class="delivery-outside-box is-opt1">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                <strong>Option 1 · Tracking Link Verification:</strong>
                <span class="badge <?= $trackBadgeClass ?>"><?= e($trackVerifyStatus === 'None' ? 'Not Submitted' : $trackVerifyStatus) ?></span>
                <?php if ($trackVerifyStatus === 'Rejected' && !empty($order['courier_tracking_rejection_reason'])): ?>
                    <span style="font-size:13px;color:#b91c1c;">Reason: <?= e($order['courier_tracking_rejection_reason']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($hasHttpsTracking && !$isOutForDelivery): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                    <a href="<?= e($order['delivery_tracking_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="min-height:36px;padding:6px 12px;font-size:13px;">
                        Open &amp; Verify Link <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                    </a>
                    <?php if ($trackVerifyStatus !== 'Verified'): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="action" value="approve_tracking">
                        <button type="submit" class="btn btn-primary" style="min-height:36px;padding:6px 14px;font-size:13px;">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> <?= $foodPaid ? 'Approve Tracking Link &amp; Send to Kitchen' : 'Approve Tracking Link' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <details style="margin-top:8px;">
                    <summary style="font-size:13px;font-weight:700;color:#b91c1c;cursor:pointer;">Reject Tracking Link</summary>
                    <form method="post" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;max-width:650px;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="action" value="reject_tracking">
                        <input type="text" name="rejection_reason" minlength="5" maxlength="255" required placeholder="Reason (e.g. broken or invalid courier link)" style="flex:1 1 220px;min-height:38px;padding:6px 10px;border:1px solid #d6d0bf;border-radius:8px;font-size:13px;">
                        <button type="submit" class="btn btn-secondary" style="min-height:38px;padding:6px 14px;font-size:13px;color:#b91c1c;border-color:#fca5a5;">Confirm Reject</button>
                    </form>
                </details>
            <?php elseif (!$hasHttpsTracking): ?>
                <p class="muted" style="margin:4px 0 0;">Waiting for customer to paste their courier HTTPS tracking link in <strong>My Orders</strong>.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isStoreBooked): ?>
        <div class="delivery-outside-box is-opt2">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:14px;">
                    <?php if ($tpFee <= 0): ?>
                        <span><strong>Fee Quote:</strong> Not quoted yet</span>
                        <span class="badge badge-warning">Awaiting Staff Quote</span>
                    <?php else: ?>
                        <?php if (!empty($order['booking_service'])): ?>
                            <span><strong>Service:</strong> <?= e($order['booking_service']) ?></span>
                        <?php endif; ?>
                        <span><strong>Quoted Fee:</strong> <?= money($tpFee) ?></span>
                        <span>
                            <strong>Courier Fee:</strong>
                            <?php if (!empty($order['fee_paid'])): ?>
                                <span class="badge badge-success">Paid &amp; Verified</span>
                            <?php elseif (!empty($order['fee_proof_id'])): ?>
                                <span class="badge badge-warning">Receipt Uploaded</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Awaiting Customer Payment</span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if (!$isOutForDelivery): ?>
                <button type="button" class="btn btn-secondary" style="min-height:36px;padding:6px 12px;font-size:13px;" onclick="openQuoteModal(<?= (int)$order['id'] ?>, '<?= e($order['order_no']) ?>', <?= $tpFee ?>)">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Quote / Update Fee
                </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($order['fee_proof_id'])): ?>
            <div class="outside-proof-box">
                <strong style="color:#8a6200;"><i class="bi bi-clock-history" aria-hidden="true"></i> Customer uploaded GCash fee receipt</strong>
                <span style="margin-left:8px;">Ref #: <code style="word-break:break-all;"><?= e($order['fee_ref_no']) ?></code></span>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                    <a href="<?= BASE_URL ?>/modules/online/api/proof_image.php?id=<?= (int)$order['fee_proof_id'] ?>" target="_blank" class="btn btn-secondary" style="min-height:36px;padding:6px 12px;font-size:13px;">
                        <i class="bi bi-image" aria-hidden="true"></i> View Fee Receipt
                    </a>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
                        <input type="hidden" name="action" value="verify_fee_payment">
                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="proof_id" value="<?= (int)$order['fee_proof_id'] ?>">
                        <button type="submit" class="btn btn-primary" style="min-height:36px;padding:6px 14px;font-size:13px;">
                            <i class="bi bi-check-circle" aria-hidden="true"></i> Confirm Delivery Fee Payment
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <form method="post" style="max-width:700px;margin-top:8px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="action" value="tracking_link">
                <label for="staff_tracking_<?= (int)$order['id'] ?>" style="display:block;font-size:13px;font-weight:700;margin-bottom:4px;">Third-party tracking link (customer sees in My Orders)</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input id="staff_tracking_<?= (int)$order['id'] ?>" name="tracking_url" type="url" value="<?= e($order['delivery_tracking_url'] ?? '') ?>" placeholder="https://share.lalamove.com/..." required style="flex:1 1 220px;min-height:38px;padding:6px 10px;border:1px solid #d6d0bf;border-radius:8px;font-size:13px;">
                    <button class="btn btn-secondary" type="submit" style="min-height:38px;padding:6px 14px;font-size:13px;white-space:nowrap;">
                        <i class="bi bi-link-45deg" aria-hidden="true"></i> <?= $hasHttpsTracking ? 'Update Tracking Link' : 'Save Tracking Link' ?>
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (($order['payment_method'] ?? '') === 'Cash on Delivery' && ($order['cod_approval_status'] ?? '') !== 'Approved'): ?>
        <p class="muted" style="color:var(--warning,#d97706);font-weight:600;margin:8px 0 0;">COD approval is still <?= e($order['cod_approval_status'] ?: 'Pending') ?>. <a href="<?= e(BASE_URL . '/modules/pos/cod_approvals.php') ?>">Approve in COD Approvals</a> before kitchen or delivery handoff.</p>
        <?php elseif (strcasecmp((string)($order['payment_method'] ?? ''), 'GCash') === 0 && ($order['payment_status'] ?? '') !== 'Paid'): ?>
        <p class="muted" style="color:var(--warning,#d97706);font-weight:600;margin:8px 0 0;">GCash payment verification is still pending. <a href="<?= e(BASE_URL . '/modules/pos/payment_verification.php') ?>">Verify in QR Verification</a> before kitchen or delivery handoff.</p>
        <?php elseif ($order['order_status'] !== 'Ready'): ?>
        <p class="muted" style="margin:8px 0 0;">Kitchen must mark this order Ready before rider handoff actions become available.</p>
        <?php elseif ($order['cancel_pending']): ?><p class="muted" style="margin:8px 0 0;">Cancellation pending Admin review. Delivery status updates are paused.</p><?php else: ?>
        <?php if ($order['order_status'] === 'Ready' && $order['dispatch_status'] === 'Failed' && $order['payment_method'] === 'Cash on Delivery' && $order['payment_status'] === 'Pending'): ?><p style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn btn-secondary" href="<?= e(BASE_URL . '/modules/pos/cod_approvals.php') ?>">Resolve Failed COD</a><a class="btn btn-secondary" href="<?= e(BASE_URL . '/modules/transactions/index.php?order_no=' . rawurlencode($order['order_no']) . '&request_type=Cancel%20Delivery') ?>">Request Cancellation</a></p><?php endif; ?>
        <?php if ($order['order_status'] === 'Ready' && (!$order['dispatch_status'] || in_array($order['dispatch_status'], ['Assigned', 'Failed'], true)) && $order['delivery_mode'] === 'LAGRO_FREE'): ?>
        <form class="form" method="post" style="max-width:700px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <input type="hidden" name="action" value="out">
            <div class="field"><label>Delivery / handoff note (optional)</label><input name="notes" maxlength="500" placeholder="Optional manual delivery arrangement note"></div>
            <button class="btn btn-primary" type="submit"><?= $order['dispatch_status'] === 'Failed' ? 'Retry: Mark Out for Delivery' : 'Mark Out for Delivery' ?></button>
        </form>
        <?php elseif ($order['order_status'] === 'Ready' && (!$order['dispatch_status'] || $order['dispatch_status'] === 'Failed') && $isOutside):
            $handoffReady = $foodPaid && $hasHttpsTracking && ($isCustomerBooked ? ($trackVerifyStatus === 'Verified') : !empty($order['fee_paid']));
            if ($handoffReady):
        ?>
            <form method="post" style="margin:6px 0 0;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <button class="btn btn-primary delivery-handoff-btn" name="action" value="handoff" type="submit">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Confirm Handed Over to Third-Party Rider
                </button>
            </form>
            <?php else:
                if ($isCustomerBooked) {
                    $blockedMsg = !$hasHttpsTracking
                        ? 'Waiting for customer to add the courier HTTPS tracking link before handoff.'
                        : 'Approve the customer’s tracking link above before rider handoff.';
                } else {
                    $blockedMsg = empty($order['fee_paid'])
                        ? 'Waiting for courier fee quote and verified GCash fee payment before rider handoff.'
                        : 'Save a valid HTTPS tracking link above before rider handoff.';
                }
            ?>
            <p class="muted" style="font-weight:600;color:#b45309;margin:8px 0 0;"><?= e($blockedMsg) ?></p>
            <?php endif; ?>
        <?php elseif ($order['order_status'] === 'Ready' && $isOutForDelivery): ?>
        <div class="delivery-complete-bar">
            <form class="cod-deliver-form" method="post" data-order-no="<?= e($order['order_no']) ?>" data-order-total="<?= e(money($order['total'])) ?>" data-payment-method="<?= e($order['payment_method'] ?: 'Cash on Delivery') ?>" data-is-paid="<?= $order['payment_status'] === 'Paid' ? '1' : '0' ?>" style="margin:0;display:flex;flex-direction:column;gap:8px;align-items:flex-start;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="action" value="delivered">
                <?php if ($order['payment_status'] !== 'Paid'): ?>
                <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;background:#fffbeb;border:1px solid #f59e0b;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="cash_collected" value="1" class="cod-collected-checkbox" style="width:18px;height:18px;accent-color:#d97706;">
                    <span>I confirm cash (<?= e(money($order['total'])) ?>) was collected on delivery.</span>
                </label>
                <?php endif; ?>
                <button class="btn btn-primary cod-deliver-btn" type="submit" style="min-height:42px;padding:8px 18px;" <?= $order['payment_status'] !== 'Paid' ? 'disabled style="min-height:42px;padding:8px 18px;opacity:0.55;cursor:not-allowed;"' : '' ?>>
                    <i class="bi bi-check2-circle"></i> Confirm Delivered to Customer
                </button>
            </form>
            <details class="delivery-fail-details">
                <summary>Report Delivery Issue / Failure</summary>
                <form method="post" class="delivery-fail-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="text" name="notes" minlength="8" maxlength="500" required placeholder="Reason (8 to 500 chars)" aria-label="Failure reason">
                    <button class="btn btn-secondary" name="action" value="failed" type="submit">Record Delivery Failure</button>
                </form>
            </details>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</section>

<section class="card">
    <div class="page-head" style="margin-bottom:16px;">
        <div>
            <h2 id="deliveryHistoryHeading" style="margin:0;">Delivery Status History</h2>
            <p class="muted" style="margin:6px 0 0;">Completed and historical online delivery status records.</p>
        </div>
        <?= render_pagination_controls($historyPage, $totalHistoryPages, static fn(int $p) => $buildDeliveryQuery(['history_page' => $p])) ?>
    </div>
    <div class="table-wrap" tabindex="0" role="region" aria-labelledby="deliveryHistoryHeading">
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
                    <th>Date &amp; Time</th>
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
                        $rawHistTime = $hist['delivered_at'] ?? $hist['failed_at'] ?? $hist['served_at'] ?? $hist['created_at'];
                        $histTs = $rawHistTime ? strtotime((string)$rawHistTime) : false;
                        $fmtHistDate = $histTs ? date('M j, Y', $histTs) : 'N/A';
                        $fmtHistClock = $histTs ? date('g:i A', $histTs) : '';
                    ?>
                    <tr>
                        <td style="white-space:nowrap;"><strong><?= e($hist['order_no']) ?></strong></td>
                        <td>
                            <div><strong><?= e($hist['customer_name'] ?: 'Online Customer') ?></strong><?= $hist['customer_phone'] ? ' · ' . e($hist['customer_phone']) : '' ?></div>
                            <div class="muted" style="font-size:12px;"><?= e($hist['delivery_address'] ?: 'No address recorded') ?></div>
                        </td>
                        <td>
                            <div><?= $hist['delivery_mode'] === 'CUSTOMER_BOOKED'
                                ? ($histBooker === 'STORE' ? 'Outside Lagro · Staff Courier' : 'Outside Lagro · Customer Courier')
                                : 'Within Lagro · Free' ?></div>
                            <?php if ($hist['delivery_mode'] === 'CUSTOMER_BOOKED' && !empty($hist['delivery_tracking_url']) && str_starts_with(strtolower((string)$hist['delivery_tracking_url']), 'https://')): ?>
                                <div><a href="<?= e($hist['delivery_tracking_url']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:12.5px;color:#765b08;font-weight:700;white-space:nowrap;">View Tracking ↗</a></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($hist['notes'])): ?>
                                <div><?= e($hist['notes']) ?></div>
                            <?php elseif (!empty($hist['rider_name'])): ?>
                                <div class="muted"><?= e($hist['rider_name']) ?></div>
                            <?php else: ?>
                                <span class="muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php
                            $dStatus = (string)($hist['dispatch_status'] ?? ($hist['order_status'] === 'Served' ? 'Delivered' : $hist['order_status']));
                            $dBadge = $dStatus === 'Delivered' ? 'badge-success' : ($dStatus === 'Failed' || $dStatus === 'Cancelled' ? 'badge-danger' : 'badge-warning');
                            ?>
                            <span class="badge <?= $dBadge ?>"><?= e($dStatus) ?></span>
                        </td>
                        <td>
                            <div><?= e($hist['order_status']) ?></div>
                            <div class="muted" style="font-size:12px;white-space:nowrap;"><?= e($hist['payment_method'] ?: 'N/A') ?> (<?= e($hist['payment_status'] ?: 'Pending') ?>)</div>
                        </td>
                        <td style="white-space:nowrap;"><?php echo money($hist['total']); ?></td>
                        <td style="white-space:nowrap;font-size:12.5px;"><?= e($fmtHistDate . ($fmtHistClock !== '' ? ', ' . $fmtHistClock : '')) ?></td>
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
.delivery-guide { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; margin:20px 0 12px; }
.delivery-guide strong { display:flex; align-items:center; justify-content:space-between; gap:8px; color:#242424; margin-bottom:6px; font-size:14.5px; }
.delivery-guide strong > span:first-child { display:inline; color:#242424; font-weight:700; }
.delivery-guide > a > div > span { display:block; font-size:13.5px; line-height:1.55; color:#5e5a4c; }
.delivery-guide i { margin-right:5px; }
.delivery-guide .delivery-filter-count { display:inline-flex; width:auto; flex:0 0 auto; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 7px; border-radius:999px; background:#f2c12e; color:#242424; font-size:12px; font-weight:800; line-height:1; }
.delivery-empty { text-align:center; padding:26px 16px; }
.delivery-empty-icon { display:flex; align-items:center; justify-content:center; width:56px; height:56px; margin:0 auto 14px; border-radius:16px; background:#fff3c4; color:#242424; font-size:26px; }
.delivery-empty h3 { font-size:20px; margin:0 0 8px; }
.delivery-empty p { font-size:15px; color:#656565; margin:0; line-height:1.6; }
.delivery-order-card { background:#ffffff; border:1px solid #e6e3d8; border-radius:14px; padding:16px 18px; margin-bottom:14px; box-shadow:0 2px 8px rgba(36,36,36,0.02); }
.delivery-order-head { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; padding-bottom:12px; margin-bottom:12px; border-bottom:1px solid #f0ede4; }
.delivery-order-title-group { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.delivery-order-no { margin:0; font-size:18px; font-weight:800; color:#242424; }
.delivery-stage-badge { display:inline-flex; align-items:center; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
.delivery-stage-badge.is-hold { background:#fff3c4; color:#775600; border:1px solid #eed98a; }
.delivery-stage-badge.is-kitchen { background:#fcfbf7; color:#5e5a4c; border:1px solid #e6e3d8; }
.delivery-stage-badge.is-ready { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; }
.delivery-stage-badge.is-out { background:#fff3bd; color:#705300; border:1px solid #e2c567; }
.delivery-stage-badge.is-failed { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
.delivery-arrangement-badge { display:inline-flex; align-items:center; padding:4px 11px; border-radius:999px; font-size:12px; font-weight:700; }
.delivery-arrangement-badge.is-lagro { background:#dcfce7; color:#15803d; }
.delivery-arrangement-badge.is-outside-opt1 { background:#fff9e7; color:#705300; border:1px solid #eee3be; }
.delivery-arrangement-badge.is-outside-opt2 { background:#fcfbf7; color:#4b4639; border:1px solid #e6e3d8; }
.delivery-meta-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px 16px; background:#fcfbf7; border:1px solid #eeeae0; border-radius:11px; padding:11px 14px; margin-bottom:10px; }
.delivery-meta-item { display:flex; flex-direction:column; gap:2px; min-width:0; }
.delivery-meta-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#7a7464; }
.delivery-meta-item strong { font-size:14px; color:#242424; overflow-wrap:anywhere; }
.delivery-meta-sub { font-size:13px; color:#5e5a4c; }
.delivery-extra-notes { display:flex; flex-direction:column; gap:4px; font-size:13.5px; color:#4b4639; margin:8px 0 10px; }
.delivery-track-link { display:inline-flex; align-items:center; gap:4px; font-weight:700; color:#775600; text-decoration:underline; text-underline-offset:2px; }
.delivery-complete-bar { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-top:10px; padding-top:10px; border-top:1px dashed #e6e3d8; }
.delivery-fail-details { font-size:13px; }
.delivery-fail-details summary { font-weight:700; color:#8a6200; cursor:pointer; padding:9px 12px; border-radius:9px; background:#fcfbf7; border:1px solid #e6e3d8; list-style:none; }
.delivery-fail-details summary:hover { background:#fff9e7; border-color:#dfb637; }
.delivery-fail-form { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; align-items:center; }
.delivery-fail-form input { min-width:210px; min-height:38px; padding:6px 10px; border:1px solid #d6d0bf; border-radius:8px; font-size:13px; }
.delivery-page table { min-width:0; width:100%; border-collapse:collapse; }
.delivery-page th { background:#fcfbf7; font-size:12px; font-weight:700; color:#5e5a4c; line-height:1.3; text-align:left; white-space:nowrap; }
.delivery-page td { font-size:12.5px; vertical-align:middle; line-height:1.45; }
.delivery-page th, .delivery-page td { padding:10px 9px; }
.delivery-page td:nth-child(2) { max-width:210px; overflow-wrap:anywhere; }
.delivery-page td:nth-child(3) { max-width:165px; }
.delivery-page td:nth-child(4) { max-width:130px; overflow-wrap:anywhere; }
.delivery-page td:nth-child(7), .delivery-page th:nth-child(7) { text-align:left; white-space:nowrap; font-weight:700; font-variant-numeric:tabular-nums; }
.delivery-page td:last-child, .delivery-page th:last-child { text-align:left; white-space:nowrap; font-variant-numeric:tabular-nums; color:#5e5a4c; }
.delivery-page .table-wrap { border:1px solid #e6e3d8; border-radius:12px; overflow-x:auto; margin-top:16px; }
.delivery-page .btn { min-height:40px; border-radius:9px; font-size:14px; }
.delivery-page .btn-primary { background:#f2c12e; color:#1f2328; border-color:#dfaf1e; font-weight:800; }
.delivery-page .btn-primary:hover { background:#e5b420; border-color:#c69814; }
.delivery-page .delivery-handoff-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:9px 20px; border-radius:10px; box-shadow:0 2px 8px rgba(242,193,46,0.28); }
.delivery-page input:focus, .delivery-page select:focus, .delivery-page textarea:focus { outline:2px solid #b48b12; outline-offset:2px; }
.delivery-page input[type=checkbox] { accent-color:#c49a19!important; }
.delivery-filter-card { display:flex; align-items:flex-start; gap:14px; padding:18px; border:1px solid #e5e1d7; border-radius:12px; background:#fff; text-decoration:none; transition:background .15s,border-color .15s; }
.delivery-filter-card:hover { background:#fffbed; border-color:#dfb637; }
.delivery-filter-card.is-selected { background:#fff3bd; border-color:#d6ab24; box-shadow:inset 0 0 0 1px #d6ab24; }
.delivery-filter-card:focus-visible { outline:3px solid #a77b06; outline-offset:3px; }
.delivery-filter-card>i { display:grid; place-items:center; flex:0 0 42px; height:42px; border-radius:11px; background:#fff2bf; color:#775600; font-size:22px; margin:0; }
.delivery-filter-card.is-selected>i { background:#f7c62f; color:#242424; }
.delivery-filter-card small { display:block; margin-top:10px; font-size:12px; font-weight:600; color:#705300; }
.delivery-filter-summary { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#737063; margin-bottom:16px; }
.delivery-filter-summary a { color:#755600; font-weight:600; text-underline-offset:3px; }
.outside-policy-banner { display:flex; align-items:flex-start; gap:14px; background:#fff9e7; border:1px solid #eee3be; border-left:4px solid #f2c12e; border-radius:12px; padding:14px 16px; margin-bottom:16px; }
.outside-policy-icon { display:inline-grid; place-items:center; width:36px; height:36px; flex:0 0 36px; border-radius:10px; background:#fff0b3; color:#775600; font-size:17px; }
.outside-policy-body strong { color:#242424; font-size:14px; }
.outside-policy-body p { margin:4px 0 0; font-size:13.5px; line-height:1.5; color:#5e5a4c; }
.delivery-outside-box { border-radius:12px; padding:12px 14px; margin:10px 0 12px; border:1px solid #e6e3d8; background:#fcfbf7; }
.delivery-outside-box.is-opt1 { background:#fffbeb; border-color:#eee3be; }
.delivery-outside-box.is-opt2 { background:#fcfbf7; border-color:#e6e3d8; }
.outside-proof-box { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px; margin:10px 0; font-size:13.5px; }
.delivery-modal-backdrop { position:fixed; inset:0; background:rgba(15, 18, 22, 0.56); backdrop-filter:blur(2px); z-index:9999; display:flex; justify-content:center; align-items:center; padding:16px; }
.delivery-modal-card { background:#fffdf8; border:1px solid #e6dfd0; border-top:4px solid #f2c12e; border-radius:16px; width:min(470px, 100%); max-height:90vh; overflow:auto; padding:22px 24px; box-shadow:0 18px 44px rgba(0,0,0,0.22); }
.delivery-modal-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:8px; }
.delivery-modal-head h2 { font-size:19px; font-weight:800; color:#1f2328; margin:0; }
.delivery-modal-close { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:999px; border:1px solid #dfd8c8; background:#fffaf0; color:#29251f; cursor:pointer; font-size:14px; flex-shrink:0; transition:background .15s, border-color .15s; }
.delivery-modal-close:hover { background:#fff3c4; border-color:#d6a81f; }
.delivery-modal-desc { font-size:13.5px; line-height:1.5; color:#5e5a4c; margin:0 0 16px; }
.delivery-modal-card .field { margin-bottom:13px; }
.delivery-modal-card .field label { display:block; font-size:13px; font-weight:700; color:#29251f; margin-bottom:5px; }
.delivery-modal-card .field input,
.delivery-modal-card .field select { width:100%; min-height:42px; padding:8px 12px; border:1px solid #dfd8c8; border-radius:10px; background:#ffffff; color:#1f2328; font-size:14px; box-sizing:border-box; }
.delivery-modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; padding-top:14px; border-top:1px dashed #e6dfd0; }
.delivery-modal-actions .btn { min-height:40px; padding:8px 18px; font-size:13.5px; border-radius:10px; }
.delivery-modal-actions .btn-secondary { background:#fffaf0; color:#29251f; border:1px solid #dfd8c8; font-weight:700; }
.delivery-modal-actions .btn-secondary:hover { background:#f3eee2; border-color:#c9bfa9; }
.delivery-modal-actions .btn-primary { background:#f2c12e; color:#1f2328; border:1px solid #dfaf1e; font-weight:800; }
.delivery-modal-actions .btn-primary:hover { background:#e5b420; border-color:#c69814; }
@media(max-width:900px) { .delivery-guide { grid-template-columns:1fr; } .delivery-meta-grid { grid-template-columns:1fr; } }
@media(max-width:700px) { .delivery-guide { gap:14px; } .delivery-page .page-head h2 { font-size:24px; } .delivery-page .page-head .kk-pagination { flex:1 1 100%; } }
</style>

<!-- Quote Modal -->
<div id="quoteModal" class="delivery-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="quoteModalTitle" onclick="if (event.target === this) closeQuoteModal()">
    <div class="delivery-modal-card">
        <div class="delivery-modal-head">
            <h2 id="quoteModalTitle">Record Quote</h2>
            <button type="button" class="delivery-modal-close" aria-label="Close quote modal" onclick="closeQuoteModal()">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <p class="delivery-modal-desc">Check your third-party courier app (Lalamove or GrabExpress) for the fare to the customer address, then enter the quote below.</p>
        <form method="post" class="form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="filter" value="<?= e($deliveryFilter) ?>">
            <input type="hidden" name="action" value="quote_fee">
            <input type="hidden" name="order_id" id="quote_order_id">

            <div class="field">
                <label for="quote_booking_service">Courier Service</label>
                <select id="quote_booking_service" name="booking_service">
                    <option value="Lalamove">Lalamove</option>
                    <option value="GrabExpress">GrabExpress</option>
                    <option value="Other third-party Courier">Other third-party Courier</option>
                </select>
            </div>

            <div class="field">
                <label for="quote_fee_input">Quoted Delivery Fee (&#8369;)</label>
                <input type="number" step="1" min="1" name="delivery_fee" id="quote_fee_input" required placeholder="e.g. 85">
            </div>

            <div class="field">
                <label for="quote_tracking_ref">External Reference or Share Link (optional)</label>
                <input type="text" id="quote_tracking_ref" name="tracking_reference" placeholder="e.g. https://share.lalamove.com/... or booking ref">
            </div>

            <div class="delivery-modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeQuoteModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> Save Fee Quote</button>
            </div>
        </form>
    </div>
</div>

<script>
function openQuoteModal(id, no, fee) {
    document.getElementById('quote_order_id').value = id;
    document.getElementById('quoteModalTitle').textContent = 'Record Quote · ' + no;
    document.getElementById('quote_fee_input').value = fee > 0 ? fee : '';
    document.getElementById('quoteModal').style.display = 'flex';
}
function closeQuoteModal() {
    var m = document.getElementById('quoteModal');
    if (m) m.style.display = 'none';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeQuoteModal();
});
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
