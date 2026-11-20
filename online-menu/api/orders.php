<?php
header('Content-Type: application/json; charset=UTF-8');

// Store hours need no database, session lock, payment processing or mail service.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'pickup_slots') {
    require_once dirname(__DIR__, 2) . '/includes/checkout_requests.php';
    header('Cache-Control: no-store');
    $requiresAdvance = !empty($_GET['advance']);
    $days = $requiresAdvance ? kk_pickup_slots(null, 1440, 4) : kk_pickup_slots();
    echo json_encode(['status' => 'success', 'advance' => $requiresAdvance, 'days' => $days]);
    exit;
}

// Keep receipt failures JSON, including failures while loading dependencies.
$receiptRequest = in_array($_GET['action'] ?? '', ['receipt', 'cancel', 'cancellation_details'], true);
if ($receiptRequest) {
    header('Cache-Control: no-store');
    $receiptBufferLevel = ob_get_level();
    ob_start();
    $receiptResponseSent = false;
    $receiptFailure = static function () use (&$receiptResponseSent, $receiptBufferLevel) {
        if ($receiptResponseSent) return;
        $receiptResponseSent = true;
        while (ob_get_level() > $receiptBufferLevel) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => ($_GET['action'] ?? '') === 'receipt' ? 'Unable to load your receipt right now. Please try again.' : 'Unable to process this order request. Please refresh your orders.']);
    };
    set_error_handler(static function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    set_exception_handler(static function (Throwable $e) use ($receiptFailure) {
        error_log('Customer receipt error: ' . $e->getMessage());
        $receiptFailure();
    });
    register_shutdown_function(static function () use (&$receiptResponseSent, $receiptFailure) {
        if (!$receiptResponseSent) {
            error_log('Customer receipt request ended without a JSON response.');
            $receiptFailure();
        }
    });
}

require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/bilao-pricing.php';
require_once __DIR__ . '/mail_helper.php';
require_once dirname(__DIR__, 2) . '/includes/delivery_tracking.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/customer_cancellation.php';
require_once ROOT_PATH . '/includes/checkout_requests.php';
require_once ROOT_PATH . '/api/price_helpers.php';
require_once ROOT_PATH . '/includes/gcash_verification.php';

processPendingPriceUpdates($conn);
expire_overdue_gcash_orders($conn);

function order_json($data, $code = 200)
{
    global $receiptRequest, $receiptResponseSent, $receiptBufferLevel;
    if ($receiptRequest) {
        while (ob_get_level() > $receiptBufferLevel) ob_end_clean();
        $receiptResponseSent = true;
    }
    http_response_code($code);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function require_customer_id(PDO $conn, string $message = 'Please sign in or register before placing an order.'): int
{
    $id = (int)($_SESSION['customer_user_id'] ?? 0);
    if ($id <= 0) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        order_json(['status' => 'error', 'message' => $message], 401);
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (
        !$user
        || ($user['role_name'] ?? '') !== 'Customer (Online)'
        || ($user['status'] ?? '') !== 'Active'
        || ($user['customer_status'] ?? 'Active') !== 'Active'
        || empty($user['email_verified_at'])
        || !empty($user['deleted_at'])
    ) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        order_json(['status' => 'error', 'message' => $message], 401);
    }

    return (int)$user['id'];
}

function handle_verify_address_request(PDO $conn, array $input): void
{
    $rl = kk_check_delivery_verify_rate_limit();
    $reqSeq = isset($input['seq']) ? (int)$input['seq'] : null;
    if (!empty($rl['blocked'])) {
        order_json([
            'status' => 'error',
            'code' => 'RATE_LIMITED',
            'message' => $rl['message'] ?? 'Unable to check right now.',
            'data' => [
                'status' => 'OUTAGE',
                'headline' => 'Unable to check right now.',
                'reason_code' => 'RATE_LIMITED',
                'eligible_free' => false,
                'eligible_outside' => false,
                'message' => $rl['message'] ?? 'Unable to check right now.',
                'seq' => $reqSeq,
            ],
        ], 429);
    }
    $addr = trim((string)($input['address'] ?? ($input['delivery_address'] ?? '')));
    $brgy = trim((string)($input['barangay'] ?? ($input['delivery_barangay'] ?? '')));
    $lat = $input['lat'] ?? ($input['delivery_lat'] ?? null);
    $lng = $input['lng'] ?? ($input['delivery_lng'] ?? null);
    $verification = verify_lagro_delivery_address($addr, $brgy, $lat, $lng, $conn);
    if ($reqSeq !== null) {
        $verification['seq'] = $reqSeq;
    }
    if (($verification['status'] ?? '') === 'OUTAGE') {
        order_json(['status' => 'error', 'code' => 'OUTAGE', 'message' => $verification['message'], 'data' => $verification], 503);
    }
    order_json(['status' => 'success', 'data' => $verification]);
}

function handle_reverse_geocode_request(array $input): void
{
    $rl = kk_check_delivery_verify_rate_limit();
    $reqSeq = isset($input['seq']) ? (int)$input['seq'] : null;
    if (!empty($rl['blocked'])) {
        order_json(['status' => 'error', 'code' => 'RATE_LIMITED', 'message' => $rl['message'] ?? 'Too many requests.'], 429);
    }
    $lat = $input['lat'] ?? ($input['delivery_lat'] ?? null);
    $lng = $input['lng'] ?? ($input['delivery_lng'] ?? null);
    if (!is_numeric($lat) || !is_numeric($lng)) {
        order_json(['status' => 'error', 'message' => 'Valid latitude and longitude are required.'], 400);
    }
    $latFloat = (float)$lat;
    $lngFloat = (float)$lng;
    if (!is_finite($latFloat) || !is_finite($lngFloat) || $latFloat < 14.10 || $latFloat > 15.20 || $lngFloat < 120.70 || $lngFloat > 121.45) {
        order_json(['status' => 'error', 'message' => 'Selected coordinates are outside the serviceable Metro Manila region.'], 400);
    }
    try {
        $geo = kk_reverse_geocode_delivery_point($latFloat, $lngFloat);
        if ($reqSeq !== null) {
            $geo['seq'] = $reqSeq;
        }
        order_json(['status' => 'success', 'data' => $geo]);
    } catch (Throwable $e) {
        order_json(['status' => 'error', 'code' => 'OUTAGE', 'message' => 'Unable to resolve map location right now.'], 503);
    }
}

function handle_geocode_address_request(array $input): void
{
    $rl = kk_check_delivery_verify_rate_limit();
    $reqSeq = isset($input['seq']) ? (int)$input['seq'] : null;
    if (!empty($rl['blocked'])) {
        order_json(['status' => 'error', 'code' => 'RATE_LIMITED', 'message' => $rl['message'] ?? 'Too many requests.'], 429);
    }
    $addr = trim((string)($input['address'] ?? ($input['delivery_address'] ?? '')));
    try {
        $geo = kk_forward_geocode_delivery_address($addr);
        if ($reqSeq !== null) {
            $geo['seq'] = $reqSeq;
        }
        order_json(['status' => 'success', 'data' => $geo]);
    } catch (Throwable $e) {
        order_json(['status' => 'error', 'code' => 'OUTAGE', 'message' => 'Unable to search address on map right now.'], 503);
    }
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($action === 'cancellation_details') {
        $customerId = require_customer_id($conn);
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND order_channel = 'ONLINE'");
        $stmt->execute([(int)($_GET['order_id'] ?? 0), $customerId]);
        $order = $stmt->fetch();
        if (!$order) order_json(['status' => 'error', 'message' => 'Order not found.'], 404);
        $context = customer_cancel_context($conn, $order);
        order_json(['status' => 'success', 'data' => ['id' => $order['id'], 'order_no' => $order['order_no'], 'total' => $order['total'], 'status' => $order['status'],
            'payment_method' => implode(', ', array_unique(array_column($context['payments'], 'payment_method'))),
            'cancellation' => customer_cancel_summary($conn, $order)], 'csrf_token' => csrf_token()]);
    }
    if ($action === 'tracking_token') {
        require_customer_id($conn, 'Sign in to update your tracking link.');
        header('Cache-Control: no-store');
        order_json(['status' => 'success', 'csrf_token' => csrf_token()]);
    }
    if ($action === 'verify_address') {
        handle_verify_address_request($conn, $_GET);
    }
    if ($action === 'reverse_geocode') {
        handle_reverse_geocode_request($_GET);
    }
    if ($action === 'geocode_address') {
        handle_geocode_address_request($_GET);
    }
    if ($action === 'receipt') {
        $customerId = require_customer_id($conn, 'Sign in to view this receipt.');
        $orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$orderId || $orderId < 1) {
            order_json(['status' => 'error', 'message' => 'Invalid order ID.'], 400);
        }

        $stmt = $conn->prepare("
            SELECT o.id, o.order_no, o.queue_no, o.order_type, o.order_channel, o.status,
                   o.customer_name, o.customer_phone, o.delivery_address, o.delivery_lat, o.delivery_lng,
                   o.delivery_mode, o.coverage_status, o.coverage_boundary_version, o.courier_booking_by,
                   o.delivery_tracking_url, o.delivery_fee,
                   o.subtotal, o.discount, o.tax, o.total, o.notes, o.created_at, o.paid_at, o.served_at,
                   p.payment_method, p.payment_reference, p.payment_status, p.amount_paid, p.change_amount,
                   (p.payment_proof_path IS NOT NULL AND p.payment_proof_path <> '') AS has_payment_proof,
                   r.receipt_no
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.id
            LEFT JOIN receipts r ON r.order_id = o.id
            WHERE o.id = ? AND o.user_id = ? AND o.order_channel = 'ONLINE'
            ORDER BY p.id DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();
        if (!$order) {
            order_json(['status' => 'error', 'message' => 'Order receipt not found or unauthorized.'], 404);
        }
        $order['resolved_courier_booking_by'] = kk_resolve_courier_booking_by($order);

        $itemsStmt = $conn->prepare('SELECT item_name, quantity, price, notes FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();

        $isPaid = strcasecmp((string)($order['payment_status'] ?? ''), 'Paid') === 0;
        order_json([
            'status' => 'success',
            'data' => [
                'order' => $order,
                'items' => $items,
                'is_paid' => $isPaid,
                'document_title' => $isPaid ? 'Official Receipt' : 'Order Summary (Payment Pending)'
            ]
        ]);
    }
    if ($action === 'track' || $action === 'detail') {
        $customerId = require_customer_id($conn, 'Sign in to view this order.');
        $orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$orderId || $orderId < 1) order_json(['status' => 'error', 'message' => 'Invalid order.'], 400);

        $stmt = $conn->prepare("SELECT o.id, o.order_no, o.queue_no, o.order_type, o.status, o.customer_name, o.customer_phone, o.created_at, o.preparing_at, o.ready_at, o.served_at, o.subtotal, o.discount, o.tax, o.total, o.delivery_address, o.delivery_lat, o.delivery_lng, o.delivery_mode, o.coverage_status, o.coverage_boundary_version, o.courier_booking_by, o.delivery_tracking_url, o.courier_tracking_status, o.courier_tracking_verified_at, o.courier_tracking_rejection_reason, o.payment_verification_status, o.notes, p.payment_method, p.payment_reference, p.payment_status, (p.payment_proof_path IS NOT NULL AND p.payment_proof_path <> '') AS has_payment_proof, r.receipt_no, r.email_status AS receipt_email_status, d.status AS delivery_status, d.rider_name, d.out_at, d.delivered_at, tpd.booking_service AS tp_service, tpd.delivery_fee AS tp_fee, tpd.tracking_reference AS tp_tracking_ref, tpd.fee_paid AS tp_fee_paid, tpd.status AS tp_status, tpp.verification_status AS tp_fee_proof_status, tpp.reference_number AS tp_fee_ref, tpp.rejection_reason AS tp_fee_rejection_reason, EXISTS(SELECT 1 FROM transaction_requests tr WHERE tr.order_id = o.id AND tr.request_type = 'Cancel Delivery' AND tr.status = 'Pending') AS cancellation_requested FROM orders o LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN receipts r ON r.order_id = o.id LEFT JOIN delivery_dispatches d ON d.order_id = o.id LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id LEFT JOIN payment_proofs tpp ON tpp.id = COALESCE(tpd.fee_payment_proof_id, (SELECT MAX(pp2.id) FROM payment_proofs pp2 WHERE pp2.order_id = o.id AND pp2.payment_type = 'thirdparty_delivery')) WHERE o.id = ? AND o.user_id = ? AND o.order_channel = 'ONLINE' LIMIT 1");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();
        if (!$order) order_json(['status' => 'error', 'message' => 'Order not found.'], 404);
        $order['resolved_courier_booking_by'] = kk_resolve_courier_booking_by($order);

        $itemsStmt = $conn->prepare('SELECT item_name, quantity, price, notes FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $itemsStmt->execute([$orderId]);
        order_json(['status' => 'success', 'data' => ['order' => $order, 'items' => $itemsStmt->fetchAll()]]);
    }

    if ($action === 'latest' || $action === 'history') {
        $customerId = require_customer_id($conn, 'Sign in to view your orders.');

        if ($action === 'latest') {
            $stmt = $conn->prepare("SELECT o.id, o.order_no, o.queue_no, o.order_type, o.status, o.customer_name, o.created_at, o.preparing_at, o.ready_at, o.served_at, o.total, p.payment_method, p.payment_reference, p.payment_status, (p.payment_proof_path IS NOT NULL AND p.payment_proof_path <> '') AS has_payment_proof, r.receipt_no, r.email_status AS receipt_email_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN receipts r ON r.order_id = o.id WHERE o.user_id = ? AND o.order_channel = 'ONLINE' AND o.status IN ('Pending', 'Preparing', 'Ready') ORDER BY o.id DESC LIMIT 1");
            $stmt->execute([$customerId]);
            $order = $stmt->fetch();
            if (!$order) order_json(['status' => 'success', 'data' => null]);
            order_json(['status' => 'success', 'data' => $order]);
        }

        $stmt = $conn->prepare("SELECT o.id, o.order_no, o.queue_no, o.order_type, o.status, o.customer_name, o.created_at, o.preparing_at, o.ready_at, o.served_at, o.total, o.delivery_mode, o.coverage_status, o.courier_booking_by, o.delivery_tracking_url, o.courier_tracking_status, o.courier_tracking_verified_at, o.courier_tracking_rejection_reason, o.payment_verification_status, o.notes, p.payment_method, p.payment_reference, p.payment_status, (p.payment_proof_path IS NOT NULL AND p.payment_proof_path <> '') AS has_payment_proof, r.receipt_no, r.email_status AS receipt_email_status, d.status AS delivery_status, d.out_at, d.delivered_at, tpd.booking_service AS tp_service, tpd.delivery_fee AS tp_fee, tpd.tracking_reference AS tp_tracking_ref, tpd.fee_paid AS tp_fee_paid, tpd.status AS tp_status, tpp.verification_status AS tp_fee_proof_status, tpp.reference_number AS tp_fee_ref, tpp.rejection_reason AS tp_fee_rejection_reason, EXISTS(SELECT 1 FROM transaction_requests tr WHERE tr.order_id = o.id AND tr.request_type = 'Cancel Delivery' AND tr.status = 'Pending') AS cancellation_requested FROM orders o LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN receipts r ON r.order_id = o.id LEFT JOIN delivery_dispatches d ON d.order_id = o.id LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id LEFT JOIN payment_proofs tpp ON tpp.id = COALESCE(tpd.fee_payment_proof_id, (SELECT MAX(pp2.id) FROM payment_proofs pp2 WHERE pp2.order_id = o.id AND pp2.payment_type = 'thirdparty_delivery')) WHERE o.user_id = ? AND o.order_channel = 'ONLINE' ORDER BY o.id DESC LIMIT 50");
        $stmt->execute([$customerId]);
        $history = $stmt->fetchAll();
        $cancelOrder = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND order_channel = 'ONLINE'");
        foreach ($history as &$historyOrder) {
            $historyOrder['resolved_courier_booking_by'] = kk_resolve_courier_booking_by($historyOrder);
            $cancelOrder->execute([$historyOrder['id'], $customerId]);
            $cancelRecord = $cancelOrder->fetch();
            $historyOrder['cancellation'] = $cancelRecord ? customer_cancel_summary($conn, $cancelRecord) : ['mode' => null];
        }
        unset($historyOrder);
        order_json(['status' => 'success', 'data' => $history]);
    }

    order_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    order_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

if (isset($_POST['payload']) && is_string($_POST['payload'])) {
    $data = json_decode($_POST['payload'], true);
} else {
    $data = json_decode(file_get_contents('php://input'), true);
}

$postInput = is_array($data) ? array_merge($_GET, $data) : $_GET;
if ($action === 'verify_address') {
    handle_verify_address_request($conn, $postInput);
}
if ($action === 'reverse_geocode') {
    handle_reverse_geocode_request($postInput);
}
if ($action === 'geocode_address') {
    handle_geocode_address_request($postInput);
}

$customerId = require_customer_id($conn);
if ($action === 'cancel') {
    if (!is_array($data) || !verify_csrf_token($data['csrf_token'] ?? null)) order_json(['status' => 'error', 'message' => 'Reload your orders and try again.'], 403);
    $reason = trim((string)($data['reason'] ?? ''));
    if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500) order_json(['status' => 'error', 'message' => 'Provide a cancellation reason of 8 to 500 characters.'], 400);
    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND order_channel = 'ONLINE' FOR UPDATE");
        $stmt->execute([(int)($data['order_id'] ?? 0), $customerId]);
        $order = $stmt->fetch();
        if (!$order) { $conn->rollBack(); order_json(['status' => 'error', 'message' => 'Order not found.'], 404); }
        $context = customer_cancel_context($conn, $order, true);
        if (!customer_cancel_eligible($order, $context['dispatches'])) throw new DomainException('This order is no longer eligible for cancellation.');
        foreach ($context['requests'] as $request) if ($request['status'] === 'Pending') throw new DomainException('This order already has a pending staff review.');
        $review = customer_cancel_review($context['payments']) || !empty($order['paid_at']);
        $plan = [];
        $stockIssue = null;
        try { $plan = customer_cancel_stock_plan($conn, $order); }
        catch (DomainException $e) { $review = true; $stockIssue = $e->getMessage(); }
        if (!$review) customer_cancel_apply($conn, $order, $plan, $customerId, 'Customer: ' . $reason);
        $insert = $conn->prepare("INSERT INTO transaction_requests (order_id, request_type, requested_by, reason, status, review_notes, stock_returned, reviewed_at) VALUES (?, 'Cancel Order', ?, ?, ?, ?, ?, ?)");
        $insert->execute([$order['id'], $customerId, $reason, $review ? 'Pending' : 'Approved', $review ? $stockIssue : 'Automatic unpaid customer cancellation; no refund.', !$review && $plan ? 1 : 0, $review ? null : date('Y-m-d H:i:s')]);
        $conn->commit();
        if (!$review) {
            try {
                require_once dirname(__DIR__, 2) . '/includes/realtime.php';
                realtime_publish('kitchen:status_change', ['order_id' => (int)$order['id'], 'status' => 'Cancelled']);
            } catch (Throwable $notifyError) { error_log('Cancellation notification: ' . $notifyError->getMessage()); }
        }
        order_json(['status' => 'success', 'message' => $review ? 'Cancellation requested. Admin review is required; no refund has been processed.' : 'Your order has been cancelled.', 'review_required' => $review]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if (!($e instanceof DomainException)) error_log('Customer cancellation: ' . $e->getMessage());
        order_json(['status' => 'error', 'message' => $e instanceof DomainException ? $e->getMessage() : 'Unable to cancel right now. Please try again.'], $e instanceof DomainException ? 409 : 500);
    }
}
if ($action === 'tracking_link') {
    if (!is_array($data)) order_json(['status' => 'error', 'message' => 'Invalid request data.'], 400);
    if (!verify_csrf_token($data['csrf_token'] ?? null)) order_json(['status' => 'error', 'message' => 'Invalid security token. Reload and try again.'], 403);
    $orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId || $orderId < 1) order_json(['status' => 'error', 'message' => 'Invalid order.'], 400);
    try {
        $trackingUrl = normalize_delivery_tracking_url((string)($data['tracking_url'] ?? ''));
    } catch (InvalidArgumentException $e) {
        order_json(['status' => 'error', 'message' => $e->getMessage()], 400);
    }
    if ($trackingUrl === null) {
        order_json(['status' => 'error', 'message' => 'Enter a valid HTTPS courier tracking link (e.g. https://...).'], 400);
    }

    $orderCheck = $conn->prepare("SELECT id, status, delivery_mode, fulfillment_mode, courier_booking_by, notes FROM orders WHERE id = ? AND user_id = ? AND order_channel = 'ONLINE' AND order_type = 'ONLINE' LIMIT 1");
    $orderCheck->execute([$orderId, $customerId]);
    $ordRow = $orderCheck->fetch();
    if (!$ordRow || ($ordRow['delivery_mode'] ?? '') !== 'CUSTOMER_BOOKED' || !in_array((string)$ordRow['status'], ['Pending', 'Preparing', 'Ready'], true)) {
        order_json(['status' => 'error', 'message' => 'This order cannot update its tracking link.'], 409);
    }
    if (kk_resolve_courier_booking_by($ordRow) !== 'CUSTOMER') {
        order_json(['status' => 'error', 'message' => 'Staff is booking the courier for this order and will add the tracking link for you.'], 403);
    }
    $dispatchCheck = $conn->prepare("SELECT status FROM delivery_dispatches WHERE order_id = ? LIMIT 1");
    $dispatchCheck->execute([$orderId]);
    if ($dispatchCheck->fetchColumn() === 'Out for Delivery') {
        order_json(['status' => 'error', 'message' => 'The rider already has this order; tracking link is locked.'], 409);
    }

    $stmt = $conn->prepare("UPDATE orders SET delivery_tracking_url = ?, courier_tracking_status = 'Pending', courier_tracking_verified_by = NULL, courier_tracking_verified_at = NULL, courier_tracking_rejection_reason = NULL, kitchen_queued_at = CASE WHEN status = 'Pending' THEN NULL ELSE kitchen_queued_at END WHERE id = ? AND user_id = ? AND order_channel = 'ONLINE' AND order_type = 'ONLINE' AND delivery_mode = 'CUSTOMER_BOOKED' AND status IN ('Pending','Preparing','Ready')");
    $stmt->execute([$trackingUrl, $orderId, $customerId]);

    order_json([
        'status' => 'success',
        'message' => 'Tracking link submitted for staff verification.',
        'data' => [
            'tracking_url' => $trackingUrl,
            'courier_tracking_status' => 'Pending',
        ]
    ]);
}
if (!is_array($data) || !verify_csrf_token($data['csrf_token'] ?? null)) {
    order_json(['status' => 'error', 'message' => 'Invalid security token. Reload checkout and try again.'], 403);
}
try {
    $requestKey = checkout_request_key($data['request_key'] ?? null);
} catch (InvalidArgumentException $e) {
    order_json(['status' => 'error', 'message' => $e->getMessage()], 400);
}
if (!is_array($data['items'] ?? null) || count($data['items']) < 1 || count($data['items']) > 50) {
    order_json(['status' => 'error', 'message' => 'Your cart is empty or invalid.'], 400);
}

$fulfillment = strtoupper(trim((string)($data['fulfillment'] ?? '')));
if (!in_array($fulfillment, ['DELIVERY', 'PICKUP'], true)) {
    order_json(['status' => 'error', 'message' => 'Please select a fulfillment method (Delivery or Pickup).'], 400);
}

$paymentMethod = strtoupper(trim((string)($data['payment_method'] ?? '')));
if (!in_array($paymentMethod, ['CASH', 'GCASH'], true)) {
    order_json(['status' => 'error', 'message' => 'Please select a payment method (Cash or GCash).'], 400);
}

$name = trim(strip_tags((string)($data['customer_name'] ?? '')));
$rawPhone = trim((string)($data['customer_phone'] ?? ''));
if (!preg_match('/^09[0-9]{9}$/', $rawPhone)) {
    order_json(['status' => 'error', 'message' => 'Enter a valid 11-digit Philippine mobile number starting with 09 (e.g. 09171234567).'], 400);
}
$phone = $rawPhone;
$address = trim(strip_tags((string)($data['delivery_address'] ?? '')));
$deliveryBarangay = trim(strip_tags((string)($data['delivery_barangay'] ?? '')));
$rawDeliveryLat = $data['delivery_lat'] ?? ($data['lat'] ?? null);
$rawDeliveryLng = $data['delivery_lng'] ?? ($data['lng'] ?? null);
$verificationToken = isset($data['verification_token']) && is_string($data['verification_token']) ? trim($data['verification_token']) : null;
$notes = trim(strip_tags((string)($data['notes'] ?? '')));
$paymentReference = trim((string)($data['payment_reference'] ?? ''));
$rawDeliveryMode = strtoupper(trim((string)($data['delivery_mode'] ?? '')));

// Reject tampered browser fee or discount flags
if (array_key_exists('delivery_fee', $data) && $data['delivery_fee'] !== null && $data['delivery_fee'] !== '' && (!is_numeric($data['delivery_fee']) || abs((float)$data['delivery_fee']) > 0.0001)) {
    order_json(['status' => 'error', 'verification_status' => 'TAMPERED_FEE', 'message' => 'Delivery fee cannot be modified by the browser.'], 400);
}
if (array_key_exists('free_delivery_discount', $data) && $data['free_delivery_discount'] !== null && $data['free_delivery_discount'] !== '' && (!is_numeric($data['free_delivery_discount']) || abs((float)$data['free_delivery_discount']) > 0.0001)) {
    order_json(['status' => 'error', 'verification_status' => 'TAMPERED_DISCOUNT', 'message' => 'Delivery discount flags cannot be set by the browser.'], 400);
}

if ($fulfillment === 'DELIVERY') {
    if (!in_array($rawDeliveryMode, ['LAGRO_FREE', 'CUSTOMER_BOOKED'], true)) {
        order_json(['status' => 'error', 'message' => 'Please select and confirm your delivery destination on the map.'], 400);
    }
    $deliveryMode = $rawDeliveryMode;
} else {
    $deliveryMode = 'LAGRO_FREE';
}
if ($fulfillment === 'DELIVERY' && $deliveryMode === 'CUSTOMER_BOOKED' && $paymentMethod !== 'GCASH') {
    order_json(['status' => 'error', 'message' => 'Outside-Lagro third-party delivery requires GCash payment for the food. Courier charges are paid separately.'], 400);
}
if ($fulfillment === 'DELIVERY' && $paymentMethod === 'CASH') {
    $codBlockStmt = $conn->prepare("SELECT cod_blocked FROM users WHERE id = ? LIMIT 1");
    $codBlockStmt->execute([$customerId]);
    if ((int)$codBlockStmt->fetchColumn() === 1) {
        order_json(['status' => 'error', 'message' => 'Cash on Delivery is currently disabled on your account due to previous failed deliveries. Please select GCash or Pickup.'], 403);
    }
}
try {
    $trackingUrl = ($fulfillment === 'DELIVERY' && $deliveryMode === 'CUSTOMER_BOOKED')
        ? normalize_delivery_tracking_url((string)($data['delivery_tracking_url'] ?? ''))
        : null;
} catch (InvalidArgumentException $e) {
    order_json(['status' => 'error', 'message' => $e->getMessage()], 400);
}

if ($name === '' || mb_strlen($name) > 100) {
    order_json(['status' => 'error', 'message' => 'Enter a valid customer name.'], 400);
}
if ($fulfillment === 'DELIVERY' && ($address === '' || mb_strlen($address) > 500)) {
    order_json(['status' => 'error', 'message' => 'A delivery address is required.'], 400);
}

$deliveryLat = null;
$deliveryLng = null;
$coverageStatus = 'NONE';
$coverageBoundaryVersion = null;
$courierBookingBy = 'NONE';

if ($fulfillment === 'DELIVERY') {
    $lagroCheck = verify_lagro_delivery_address($address, $deliveryBarangay, $rawDeliveryLat, $rawDeliveryLng, $conn);
    $checkStatus = (string)($lagroCheck['status'] ?? 'UNVERIFIED');

    if ($checkStatus === 'OUTAGE') {
        order_json([
            'status' => 'error',
            'verification_status' => 'OUTAGE',
            'message' => $lagroCheck['message'] ?? 'Unable to check right now. Please try again shortly.'
        ], 503);
    }

    // Reject tampered client eligibility claims
    if (array_key_exists('eligible_free', $data) && (bool)$data['eligible_free'] !== (bool)($lagroCheck['eligible_free'] ?? false)) {
        order_json([
            'status' => 'error',
            'verification_status' => 'TAMPERED_CLAIM',
            'message' => 'Submitted delivery coverage eligibility does not match server verification.'
        ], 400);
    }
    if (array_key_exists('coverage_status', $data) && $data['coverage_status'] !== null && $data['coverage_status'] !== '' && strtoupper((string)$data['coverage_status']) !== $checkStatus) {
        order_json([
            'status' => 'error',
            'verification_status' => 'TAMPERED_CLAIM',
            'message' => 'Submitted delivery coverage status does not match server verification.'
        ], 400);
    }

    if (in_array($checkStatus, ['SELECT_LOCATION', 'NEEDS_CONFIRMATION', 'UNVERIFIED'], true)) {
        order_json([
            'status' => 'error',
            'verification_status' => $checkStatus,
            'reason_code' => $lagroCheck['reason_code'] ?? '',
            'message' => $lagroCheck['message'] ?? 'Address and location need confirmation before placing your order.'
        ], 400);
    }

    if ($deliveryMode === 'LAGRO_FREE') {
        if ($checkStatus !== 'WITHIN_LAGRO' || empty($lagroCheck['eligible_free'])) {
            order_json([
                'status' => 'error',
                'verification_status' => $checkStatus,
                'reason_code' => $lagroCheck['reason_code'] ?? '',
                'message' => $lagroCheck['message'] ?? 'Free Within-Lagro delivery is only available for verified destinations inside Barangay Greater Lagro.'
            ], 400);
        }
    } elseif ($deliveryMode === 'CUSTOMER_BOOKED') {
        if ($checkStatus !== 'OUTSIDE_LAGRO' || empty($lagroCheck['eligible_outside'])) {
            order_json([
                'status' => 'error',
                'verification_status' => $checkStatus,
                'reason_code' => $lagroCheck['reason_code'] ?? '',
                'message' => $checkStatus === 'WITHIN_LAGRO'
                    ? 'Your destination is verified inside Barangay Greater Lagro and qualifies for free store delivery.'
                    : ($lagroCheck['message'] ?? 'Confirm your outside-Lagro delivery destination on the map.')
            ], 400);
        }
        $booker = strtolower(trim((string)($data['courier_booking_by'] ?? '')));
        if (!in_array($booker, ['customer', 'store'], true)) {
            order_json(['status' => 'error', 'message' => 'Choose who will book the courier ("I’ll book the courier" or "Ask the store to book").'], 400);
        }
        $courierBookingBy = ($booker === 'store') ? 'STORE' : 'CUSTOMER';
        $notes = '[Courier booking: ' . ($booker === 'store' ? 'Kenjis staff' : 'Customer') . '; actual courier fee paid separately] ' . $notes;
    }

    // Enforce server-bound verification token (prevents stale verification after editing address/pin)
    $tokenValidation = kk_verify_delivery_verification_token(
        $verificationToken,
        $address,
        $rawDeliveryLat,
        $rawDeliveryLng,
        $deliveryMode,
        (string)($lagroCheck['boundary_version'] ?? ''),
        $customerId
    );
    if (empty($tokenValidation['ok'])) {
        order_json([
            'status' => 'error',
            'verification_status' => 'STALE_VERIFICATION',
            'message' => $tokenValidation['message'] ?? 'Your delivery address or map pin changed since coverage was checked. Please wait for coverage verification to finish.'
        ], 400);
    }

    $deliveryLat = round((float)$rawDeliveryLat, 6);
    $deliveryLng = round((float)$rawDeliveryLng, 6);
    $coverageStatus = $checkStatus;
    $coverageBoundaryVersion = (string)($lagroCheck['boundary_version'] ?? '');
}
if (mb_strlen($notes, 'UTF-8') > 1000) {
    order_json(['status' => 'error', 'message' => 'Overall order notes exceed the 1000-character limit. Please shorten your notes.'], 400);
}
if ($paymentMethod === 'GCASH' && !preg_match('/^[0-9]{13}$/', $paymentReference)) {
    order_json(['status' => 'error', 'message' => 'Enter a valid 13-digit GCash reference number.'], 400);
}

$proofTmpPath = null;
$proofTargetFilename = null;
$proofTargetFullPath = null;

if ($paymentMethod === 'GCASH') {
    if (empty($_FILES['payment_proof']) || !is_array($_FILES['payment_proof'])) {
        order_json(['status' => 'error', 'message' => 'Please upload a screenshot of your GCash payment receipt (JPG, PNG, or WebP).'], 400);
    }
    $proofFile = $_FILES['payment_proof'];
    $uploadErr = (int)($proofFile['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadErr !== UPLOAD_ERR_OK || empty($proofFile['tmp_name']) || !is_uploaded_file($proofFile['tmp_name'])) {
        order_json(['status' => 'error', 'message' => 'Please upload a valid GCash payment screenshot before placing your order.'], 400);
    }
    $fileSize = (int)($proofFile['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
        order_json(['status' => 'error', 'message' => 'GCash payment screenshot must be 5 MB or smaller.'], 400);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string)$finfo->file($proofFile['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];
    if (!isset($allowedMimes[$realMime])) {
        order_json(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, and WebP payment screenshots are accepted.'], 400);
    }
    $imgInfo = @getimagesize($proofFile['tmp_name']);
    if ($imgInfo === false || (int)($imgInfo[0] ?? 0) < 10 || (int)($imgInfo[1] ?? 0) < 10) {
        order_json(['status' => 'error', 'message' => 'The uploaded file is not a valid image screenshot.'], 400);
    }
    $proofTmpPath = $proofFile['tmp_name'];
    $proofTargetFilename = 'gcash_proof_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $allowedMimes[$realMime];
    $uploadDir = dirname(__DIR__, 2) . '/uploads/payment_proofs';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0750, true);
    }
    $proofTargetFullPath = $uploadDir . '/' . $proofTargetFilename;
}
if ($paymentMethod === 'CASH') {
    $paymentReference = '';
}

$checkoutCommitted = false;
$checkoutCommitAttempted = false;
$checkoutResponse = null;
$proofHash = $proofTmpPath ? hash_file('sha256', $proofTmpPath) : null;
$requestHash = checkout_request_hash($data, $proofHash ?: null);
try {
    $conn->beginTransaction();
    $replay = checkout_request_claim($conn, 'ONLINE', $customerId, $requestKey, $requestHash);
    if ($replay !== null) {
        $conn->rollBack();
        order_json($replay, 200);
    }

    $profile = $conn->prepare("UPDATE users SET full_name = CASE WHEN full_name IS NULL OR TRIM(full_name) = '' THEN ? ELSE full_name END, phone = ?, customer_address = CASE WHEN ? = '' THEN customer_address ELSE ? END WHERE id = ? AND status = 'Active'");
    $profile->execute([$name, $phone, $address, $address, $customerId]);
    if ($profile->rowCount() < 0) {
        throw new RuntimeException('Unable to update customer profile.');
    }

    $requestedByMenuId = [];
    foreach ($data['items'] as $entry) {
        $menuId = filter_var($entry['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$menuId || $menuId < 1 || !$qty || $qty < 1) {
            throw new InvalidArgumentException('One or more cart quantities are invalid.');
        }
        if ($qty > 99) {
            throw new InvalidArgumentException('Maximum quantity per item line is 99.');
        }

        $requestedByMenuId[$menuId] = ($requestedByMenuId[$menuId] ?? 0) + $qty;
    }

    $allMenuIdsToLock = array_keys($requestedByMenuId);
    sort($allMenuIdsToLock);
    ksort($requestedByMenuId);

    $menuStmt = $conn->prepare("SELECT m.id, m.item_name, m.price, m.promo_price, m.promo_start, m.promo_end, m.availability, m.is_archived, m.stock, c.category_name, c.status AS category_status FROM menu_items m JOIN categories c ON c.id = m.category_id WHERE m.id = ? FOR UPDATE");
    $lockedProducts = [];

    foreach ($allMenuIdsToLock as $menuId) {
        $menuStmt->execute([$menuId]);
        $product = $menuStmt->fetch();
        if (!$product) {
            throw new RuntimeException('One of the selected items is unavailable.');
        }
        if (is_one_liter_grouped_drink_name((string)$product['item_name'])) {
            throw new RuntimeException('Assorted 1L Bottled Drink has been replaced by individual 1L drink products (Coca-Cola, Sprite, Royal, Mountain Dew). Please remove it from your basket and select the drink you want.');
        }
        if ((int)$product['is_archived'] === 1 || ($product['category_status'] ?? 'Active') !== 'Active') {
            throw new RuntimeException($product['item_name'] . ' has been archived and is no longer available for ordering.');
        }
        if ($product['availability'] !== 'Available') {
            throw new RuntimeException($product['item_name'] . ' is currently unavailable.');
        }
        $totalQtyForId = (int)($requestedByMenuId[$menuId] ?? 0);
        $availStock = max(0, (int)$product['stock']);
        if ($totalQtyForId > 0 && $availStock < $totalQtyForId) {
            if ($availStock <= 0) {
                throw new RuntimeException($product['item_name'] . ' is currently out of stock — remaining stock was claimed by an earlier walk-in or online order.');
            }
            throw new RuntimeException('Only ' . $availStock . ' left in stock for ' . $product['item_name'] . ' (requested ' . $totalQtyForId . ') — remaining stock was claimed by an earlier walk-in or online order.');
        }
        $lockedProducts[$menuId] = $product;
    }

    $normalized = [];
    $subtotal = 0.0;
    $today = date('Y-m-d');
    $hasAdvanceBilao = false;
    $hasImmediateItem = false;

    foreach ($data['items'] as $entry) {
        $menuId = (int)filter_var($entry['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = (int)filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
        $product = $lockedProducts[$menuId];
        $recordMenuId = $menuId;

        $price = (float)$product['price'];
        if ($product['promo_price'] !== null && $product['promo_start'] !== null && $product['promo_end'] !== null && $today >= $product['promo_start'] && $today <= $product['promo_end']) {
            $price = (float)$product['promo_price'];
        }

        $requestedSize = strtoupper(trim((string)($entry['variant_size'] ?? '')));
        $isBilao = ($product['category_name'] ?? '') === 'Bilao Favorites';
        $dishName = $isBilao ? bilao_dish_from_menu_name((string)$product['item_name']) : null;
        if ($isBilao) {
            if (!$dishName || !in_array($requestedSize, ['S', 'M', 'L', 'XL'], true)) {
                throw new InvalidArgumentException('Please remove this bilao item from your cart, add it again, and select a size.');
            }
            $bilaoValidation = validate_bilao_variant_order($conn, $menuId, $dishName, $requestedSize);
            if (empty($bilaoValidation['ok'])) {
                throw new RuntimeException($bilaoValidation['msg'] ?? ($dishName . ' (' . $requestedSize . ') is no longer available.'));
            }
            $packaging = strtoupper(trim((string)($entry['packaging'] ?? '')));
            if (!in_array($packaging, ['TRAY', 'BILAO'], true)) {
                throw new InvalidArgumentException('Please choose either Tray or Bilao packaging for ' . $dishName . '.');
            }
            $packagingLabel = ($packaging === 'TRAY') ? 'Tray' : 'Bilao';
            $itemName = $dishName . ' ' . $packagingLabel . ' (' . $requestedSize . ')';
            if (in_array($requestedSize, ['M', 'L', 'XL'], true)) {
                $hasAdvanceBilao = true;
            } else {
                $hasImmediateItem = true;
            }
        } else {
            $packagingLabel = null;
            $itemName = (string)$product['item_name'];
            $hasImmediateItem = true;
        }

        $customerNote = trim(strip_tags((string)($entry['notes'] ?? '')));
        if ($isBilao) {
            $combinedNote = ($customerNote !== '')
                ? 'Packaging: ' . $packagingLabel . ' | Customer note: ' . $customerNote
                : 'Packaging: ' . $packagingLabel;
        } else {
            $combinedNote = $customerNote;
        }

        $noteLen = mb_strlen($combinedNote, 'UTF-8');
        if ($noteLen > 255) {
            throw new InvalidArgumentException('Special instructions for ' . $itemName . ' exceed the 255-character limit (current length: ' . $noteLen . ' characters). Please shorten your note.');
        }
        $itemNote = $combinedNote !== '' ? $combinedNote : null;

        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;
        $normalized[] = [
            'id' => $recordMenuId,
            'name' => $itemName,
            'qty' => $qty,
            'price' => $price,
            'line_total' => $lineTotal,
            'notes' => $itemNote
        ];
    }

    if ($hasAdvanceBilao && $hasImmediateItem) {
        throw new InvalidArgumentException('Orders cannot combine regular same-day items with Medium, Large, or Extra Large Bilao platters (which require 1 to 2 days advance preparation). Please place separate orders.');
    }
    if ($fulfillment === 'PICKUP') {
        $pickupAt = kk_validate_pickup_time($data['pickup_at'] ?? null, null, $hasAdvanceBilao);
        $pickupDate = new DateTimeImmutable($pickupAt, new DateTimeZone('Asia/Manila'));
        $notes = '[Pickup: ' . $pickupDate->format('M j, Y g:i A') . '] ' . $notes;
        if (mb_strlen($notes, 'UTF-8') > 1000) throw new InvalidArgumentException('Please shorten your order notes.');
    } elseif ($fulfillment === 'DELIVERY' && $hasAdvanceBilao) {
        $scheduledDeliveryAt = kk_validate_pickup_time($data['scheduled_delivery_at'] ?? null, null, true);
        $deliveryDate = new DateTimeImmutable($scheduledDeliveryAt, new DateTimeZone('Asia/Manila'));
        $notes = '[Delivery: ' . $deliveryDate->format('M j, Y g:i A') . '] ' . $notes;
        if (mb_strlen($notes, 'UTF-8') > 1000) throw new InvalidArgumentException('Please shorten your order notes.');
    }

    $total = round($subtotal, 2);
    $totalOrderQty = array_sum(array_map(static fn($item) => (int)($item['qty'] ?? 0), $normalized));

    $isGcash = ($paymentMethod === 'GCASH');
    $isCod = ($paymentMethod === 'CASH');

    $deliveryRules = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('lagro_delivery_min_items', 'lagro_delivery_min_amount')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $minOrderItems = isset($deliveryRules['lagro_delivery_min_items']) ? max(0, (int)$deliveryRules['lagro_delivery_min_items']) : 2;
    $minOrderAmount = isset($deliveryRules['lagro_delivery_min_amount']) ? max(0.0, (float)$deliveryRules['lagro_delivery_min_amount']) : 100.0;
    if ($minOrderAmount > 0 && $subtotal < $minOrderAmount) {
        $reqText = $minOrderItems > 1
            ? 'a minimum of ' . $minOrderItems . ' items and ₱' . number_format($minOrderAmount, 2) . ' (or 1 item priced at ₱' . number_format($minOrderAmount, 2) . ' or more)'
            : 'a ₱' . number_format($minOrderAmount, 2) . ' minimum food order';
        throw new InvalidArgumentException('Online orders require ' . $reqText . '. Current food subtotal: ₱' . number_format($subtotal, 2) . '.');
    }

    if ($isCod && $fulfillment === 'DELIVERY') {
        $codSettings = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('cod_min_order', 'cod_max_order')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $codMin = isset($codSettings['cod_min_order']) ? (float)$codSettings['cod_min_order'] : 0.0;
        $codMax = isset($codSettings['cod_max_order']) ? (float)$codSettings['cod_max_order'] : 0.0;
        if ($codMin > 0 && $total < $codMin) {
            throw new InvalidArgumentException('Cash on Delivery requires a minimum order of ₱' . number_format($codMin, 2) . '.');
        }
        if ($codMax > 0 && $total > $codMax) {
            throw new InvalidArgumentException('Cash on Delivery is limited to orders up to ₱' . number_format($codMax, 2) . '. Please use GCash for larger orders.');
        }
    }

    require_once ROOT_PATH . '/includes/order_queue.php';
    $queueNo = next_daily_queue_no($conn);
    $orderNo = 'ONL-' . date('Ymd') . '-' . str_pad((string)$queueNo, 4, '0', STR_PAD_LEFT);

    $paymentVerificationStatus = $isGcash ? 'Awaiting Verification' : 'None';
    $paymentAutoCancelAt = $isGcash ? date('Y-m-d H:i:s', time() + (qr_payment_timeout_minutes($conn) * 60)) : null;
    $codApprovalStatus = ($isCod && $fulfillment === 'DELIVERY') ? 'Pending' : 'None';
    $orderType = $fulfillment === 'DELIVERY' ? 'ONLINE' : 'TAKE-OUT';
    $fulfillmentMode = $fulfillment === 'DELIVERY' ? ($deliveryMode === 'CUSTOMER_BOOKED' ? 'OUTSIDE' : 'LAGRO') : 'PICKUP';
    $changeFor = null;
    if ($isCod && $fulfillment === 'DELIVERY' && isset($data['change_for']) && $data['change_for'] !== '' && $data['change_for'] !== null) {
        $rawChangeFor = filter_var($data['change_for'], FILTER_VALIDATE_FLOAT);
        if ($rawChangeFor === false || $rawChangeFor < $total) {
            throw new InvalidArgumentException('Cash amount for change must be at least ₱' . number_format($total, 2) . '.');
        }
        if ($rawChangeFor > 100000) {
            throw new InvalidArgumentException('Cash amount for change is too large.');
        }
        if ($rawChangeFor > ($total + 0.009)) {
            $changeFor = round((float)$rawChangeFor, 2);
        }
    }
    $kitchenQueuedAt = ($isGcash || $codApprovalStatus === 'Pending') ? null : date('Y-m-d H:i:s');

    $courierTrackingStatus = ($fulfillment === 'DELIVERY' && $deliveryMode === 'CUSTOMER_BOOKED' && $courierBookingBy === 'CUSTOMER' && $trackingUrl !== null) ? 'Pending' : 'None';

    $orderStmt = $conn->prepare("INSERT INTO orders (order_no, queue_no, user_id, customer_name, customer_phone, order_type, fulfillment_mode, delivery_address, delivery_lat, delivery_lng, delivery_mode, coverage_status, coverage_boundary_version, courier_booking_by, delivery_tracking_url, courier_tracking_status, order_channel, subtotal, discount, tax, delivery_fee, free_delivery_discount, total, change_for, status, kitchen_queued_at, notes, inventory_deducted, payment_verification_status, payment_auto_cancel_at, cod_approval_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ONLINE', ?, 0, 0, 0.00, 0.00, ?, ?, 'Pending', ?, ?, 1, ?, ?, ?, NOW())");
    $orderStmt->execute([
        $orderNo,
        $queueNo,
        $customerId,
        $name,
        $phone,
        $orderType,
        $fulfillmentMode,
        $fulfillment === 'DELIVERY' ? $address : null,
        $deliveryLat,
        $deliveryLng,
        $deliveryMode,
        $coverageStatus,
        $coverageBoundaryVersion,
        $courierBookingBy,
        $trackingUrl,
        $courierTrackingStatus,
        $subtotal,
        $total,
        $changeFor,
        $kitchenQueuedAt,
        $notes !== '' ? $notes : null,
        $paymentVerificationStatus,
        $paymentAutoCancelAt,
        $codApprovalStatus
    ]);
    $orderId = (int)$conn->lastInsertId();
    if ($orderId <= 0) {
        throw new RuntimeException('Unable to create order record.');
    }

    if ($fulfillment === 'DELIVERY' && $deliveryMode === 'CUSTOMER_BOOKED' && $courierBookingBy === 'STORE') {
        $tpStmt = $conn->prepare("INSERT INTO thirdparty_deliveries (order_id, booking_service, delivery_fee, tracking_reference, status) VALUES (?, 'Store-Booked Courier', 0.00, ?, 'Quoted')");
        $tpStmt->execute([$orderId, $trackingUrl]);
    }

    $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price, notes) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($normalized as $item) {
        $itemStmt->execute([$orderId, $item['id'], $item['name'], $item['qty'], $item['price'], $item['notes']]);
        if ($itemStmt->rowCount() !== 1) {
            throw new RuntimeException('Unable to create order item record.');
        }
    }

    $deductStmt = $conn->prepare("UPDATE menu_items SET stock = stock - ?, availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE availability END WHERE id = ? AND is_archived = 0 AND availability = 'Available' AND stock >= ?");
    $logStmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");
    foreach ($requestedByMenuId as $menuId => $totalQty) {
        $deductStmt->execute([$totalQty, $menuId, $totalQty]);
        if ($deductStmt->rowCount() !== 1) {
            throw new RuntimeException(($lockedProducts[$menuId]['item_name'] ?? 'Selected item') . ' does not have enough stock.');
        }
        $logStmt->execute([$menuId, $customerId, $totalQty, 'Online order ' . $orderNo . ' auto-deduction']);
        if ($logStmt->rowCount() !== 1) {
            throw new RuntimeException('Unable to record inventory deduction.');
        }
    }

    $paymentStatus = 'Pending';
    $paymentLabel = $paymentMethod === 'GCASH' ? 'GCash' : ($fulfillment === 'PICKUP' ? 'Pay on Pickup' : 'Cash on Delivery');
    if ($paymentMethod === 'GCASH' && $proofTmpPath && $proofTargetFullPath) {
        if (!move_uploaded_file($proofTmpPath, $proofTargetFullPath)) {
            throw new RuntimeException('Unable to save GCash payment screenshot. Please try again.');
        }
    }
    $payStmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_reference, payment_proof_path, payment_status, amount_paid, change_amount) VALUES (?, ?, ?, ?, ?, 0.00, 0)");
    $payStmt->execute([$orderId, $paymentLabel, $paymentReference !== '' ? $paymentReference : null, $proofTargetFilename, $paymentStatus]);
    if ($payStmt->rowCount() !== 1) {
        throw new RuntimeException('Unable to create payment record.');
    }
    $paymentId = (int)$conn->lastInsertId();
    if ($paymentId <= 0) {
        throw new RuntimeException('Unable to create payment record.');
    }

    if ($paymentMethod === 'GCASH') {
        try {
            $regStmt = $conn->prepare("INSERT INTO payment_reference_registry (reference_type, reference_no, order_id, payment_id, channel) VALUES ('GCASH', ?, ?, ?, 'ONLINE')");
            $regStmt->execute([$paymentReference, $orderId, $paymentId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)) {
                throw new RuntimeException('This GCash reference number has already been used for another order.');
            }
            throw $e;
        }

        // Insert into payment_proofs table so POS QR Verification displays it immediately
        $proofPath = 'uploads/payment_proofs/' . $proofTargetFilename;
        $imageHash = ($proofTargetFullPath && file_exists($proofTargetFullPath)) ? hash_file('sha256', $proofTargetFullPath) : hash('sha256', $orderNo . '_' . $paymentReference);
        $proofStmt = $conn->prepare("INSERT INTO payment_proofs (order_id, method_code, reference_number, image_path, image_hash, amount_declared, payment_type, verification_status, submitted_at) VALUES (?, 'GCASH', ?, ?, ?, ?, 'food', 'pending', NOW())");
        $proofStmt->execute([$orderId, $paymentReference, $proofPath, $imageHash, $total]);
    }

    $receiptNo = 'RCPT-' . date('Ymd') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
    $receiptStmt = $conn->prepare("INSERT INTO receipts (order_id, receipt_no) VALUES (?, ?)");
    $receiptStmt->execute([$orderId, $receiptNo]);
    if ($receiptStmt->rowCount() !== 1) {
        throw new RuntimeException('Unable to create receipt record.');
    }
    $breakdown = array_map(static function ($item) {
        return [
            'name' => $item['name'],
            'qty' => $item['qty'],
            'unit_price' => number_format($item['price'], 2, '.', ''),
            'subtotal' => number_format($item['line_total'], 2, '.', '')
        ];
    }, $normalized);

    $checkoutResponse = [
        'status' => 'success',
        'message' => 'Order saved. You can view it in My Orders.',
        'data' => [
            'order_id' => $orderId,
            'order_number' => $orderNo,
            'queue_no' => $queueNo,
            'receipt_no' => $receiptNo,
            'receipt_email_sent' => false,
            'receipt_email_status' => 'Pending',
            'customer_name' => $name,
            'fulfillment' => $fulfillment,
            'order_type' => $orderType,
            'payment_method' => $paymentLabel,
            'payment_reference' => $paymentReference,
            'payment_status' => $paymentStatus,
            'delivery_mode' => $deliveryMode,
            'coverage_status' => $coverageStatus,
            'coverage_boundary_version' => $coverageBoundaryVersion,
            'courier_booking_by' => $courierBookingBy,
            'delivery_lat' => $deliveryLat,
            'delivery_lng' => $deliveryLng,
            'delivery_tracking_url' => $trackingUrl,
            'courier_tracking_status' => $courierTrackingStatus,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'total_amount' => number_format($total, 2, '.', ''),
            'status' => 'Pending',
            'items' => $breakdown
        ]
    ];

    checkout_request_complete($conn, 'ONLINE', $customerId, $requestKey, $orderId, $checkoutResponse);
    $checkoutCommitAttempted = true;
    $conn->commit();
    $checkoutCommitted = true;

    checkout_after_commit(function () use ($orderId, $orderNo, $queueNo, $orderType, $kitchenQueuedAt, $total, &$checkoutResponse) {
    require_once dirname(__DIR__, 2) . '/includes/realtime.php';
    if ($kitchenQueuedAt !== null && function_exists('realtime_publish')) {
        realtime_publish('order:submitted', [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'queue_no' => $queueNo,
            'status' => 'Pending',
            'order_type' => $orderType,
            'order_channel' => 'ONLINE',
            'total' => $total
        ]);
    }

    $checkoutResponse['data']['receipt_email_sent'] = false;
    $checkoutResponse['data']['receipt_email_status'] = 'Disabled';
    });
    order_json($checkoutResponse, 201);
} catch (InvalidArgumentException $e) {
    if ($checkoutCommitted) order_json($checkoutResponse, 201);
    if ($conn->inTransaction()) $conn->rollBack();
    checkout_discard_proof($proofTargetFullPath, $checkoutCommitAttempted);
    order_json(['status' => 'error', 'message' => $e->getMessage()], 400);
} catch (DomainException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    order_json(['status' => 'error', 'message' => $e->getMessage()], 409);
} catch (RuntimeException $e) {
    if ($checkoutCommitted) order_json($checkoutResponse, 201);
    if ($conn->inTransaction()) $conn->rollBack();
    checkout_discard_proof($proofTargetFullPath, $checkoutCommitAttempted);
    order_json(['status' => 'error', 'message' => $e->getMessage()], 409);
} catch (Throwable $e) {
    if ($checkoutCommitted) order_json($checkoutResponse, 201);
    if ($conn->inTransaction()) $conn->rollBack();
    checkout_discard_proof($proofTargetFullPath, $checkoutCommitAttempted);
    error_log('Online order error: ' . $e->getMessage());
    order_json(['status' => 'error', 'message' => 'Unable to place the order right now.'], 500);
}
