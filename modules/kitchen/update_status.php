<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'kitchen']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = (int) ($data['id'] ?? 0);
$status = $data['status'] ?? '';
$allowed = ['Preparing', 'Ready'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token() || !$id || !in_array($status, $allowed, true)) {
    http_response_code(($_SERVER['REQUEST_METHOD'] !== 'POST') ? 405 : 403);
    echo json_encode(['ok' => false]);
    exit;
}

require_once ROOT_PATH . '/includes/realtime.php';
require_once ROOT_PATH . '/includes/delivery_tracking.php';

$option1Gate = kk_kitchen_option1_gate_sql('orders');

if ($status === 'Preparing') {
    $stmt = $conn->prepare("UPDATE orders SET status = 'Preparing', preparing_at = NOW() WHERE id = ? AND status = 'Pending' AND kitchen_queued_at IS NOT NULL AND (order_channel <> 'ONLINE' OR EXISTS (SELECT 1 FROM payments p WHERE p.order_id = orders.id AND (p.payment_method <> 'GCash' OR p.payment_status = 'Paid'))) AND {$option1Gate}");
    $stmt->execute([$id]);
}

if ($status === 'Ready') {
    $stmt = $conn->prepare("UPDATE orders SET status = 'Ready', ready_at = NOW() WHERE id = ? AND status = 'Preparing' AND kitchen_queued_at IS NOT NULL AND (order_channel <> 'ONLINE' OR EXISTS (SELECT 1 FROM payments p WHERE p.order_id = orders.id AND (p.payment_method <> 'GCash' OR p.payment_status = 'Paid'))) AND {$option1Gate}");
    $stmt->execute([$id]);
}

$changed = $stmt->rowCount();
if ($changed !== 1) {
    $msg = 'Order status changed or order was not found. Refresh the kitchen queue.';
    $checkStmt = $conn->prepare("SELECT order_type, delivery_mode, fulfillment_mode, courier_booking_by, courier_tracking_status, delivery_tracking_url, notes FROM orders WHERE id = ? LIMIT 1");
    $checkStmt->execute([$id]);
    $ordCheck = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if ($ordCheck && kk_is_option1_customer_courier($ordCheck) && !kk_option1_tracking_is_verified($ordCheck)) {
        $msg = 'Cannot start kitchen prep: customer’s third-party courier tracking link must be verified in Delivery Status (Outside Lagro) first.';
    }
    http_response_code(409);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

if (function_exists('realtime_publish')) {
    realtime_publish('kitchen:status_change', ['order_id' => $id, 'status' => $status]);
}

echo json_encode(['ok' => true, 'status' => $status]);
?>
