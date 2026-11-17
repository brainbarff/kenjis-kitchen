<?php
/**
 * Customer Order Status & Payment Status API
 * Returns real-time QR verification state and courier quotes for My Orders modal.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/gcash_verification.php';

expire_overdue_gcash_orders($conn);

$customerId = (int)($_SESSION['customer_user_id'] ?? 0);
$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);

if ($customerId <= 0 || !$orderId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT o.id, o.order_no, o.status, o.total, o.fulfillment_mode, o.delivery_fee, o.free_delivery_discount,
           o.payment_verification_status, o.cod_approval_status, o.change_for,
           tpd.delivery_fee AS tp_fee, tpd.booking_service, tpd.tracking_reference, tpd.fee_paid, tpd.status AS tp_status,
           pp.rejection_reason, pp.verification_status AS proof_status
    FROM orders o
    LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id
    LEFT JOIN payment_proofs pp ON pp.order_id = o.id AND pp.payment_type = 'food'
    WHERE o.id = ? AND o.user_id = ?
    ORDER BY pp.id DESC
    LIMIT 1
");
$stmt->execute([$orderId, $customerId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'data' => $order
]);
