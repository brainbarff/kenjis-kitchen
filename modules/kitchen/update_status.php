<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'kitchen']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = (int) ($data['id'] ?? 0);
$status = $data['status'] ?? '';
$allowed = ['Preparing', 'Ready'];

if (!$id || !in_array($status, $allowed, true)) {
    echo json_encode(['ok' => false]);
    exit;
}

require_once ROOT_PATH . '/includes/realtime.php';

if ($status === 'Preparing') {
    $stmt = $conn->prepare("UPDATE orders SET status = 'Preparing', preparing_at = NOW() WHERE id = ? AND status = 'Pending'");
    $stmt->execute([$id]);
}

if ($status === 'Ready') {
    $stmt = $conn->prepare("UPDATE orders SET status = 'Ready', ready_at = NOW() WHERE id = ? AND status = 'Preparing'");
    $stmt->execute([$id]);
}

$changed = $stmt->rowCount();
if ($changed !== 1) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'msg' => 'Order status changed or order was not found. Refresh the kitchen queue.']);
    exit;
}

if (function_exists('realtime_publish')) {
    realtime_publish('kitchen:status_change', ['order_id' => $id, 'status' => $status]);
}

echo json_encode(['ok' => true, 'status' => $status]);
?>
