<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

header('Content-Type: application/json; charset=UTF-8');

function payment_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    payment_json(['ok' => false, 'message' => 'Invalid request method.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$orderId = (int)($data['order_id'] ?? 0);

if ($orderId <= 0) {
    payment_json(['ok' => false, 'message' => 'Invalid order selected.'], 400);
}

try {
    $conn->beginTransaction();

    $orderStmt = $conn->prepare("SELECT id, order_no, status, total FROM orders WHERE id = ? AND order_channel = 'ONLINE' FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        throw new RuntimeException('Online order not found.');
    }

    if ($order['status'] === 'Cancelled') {
        throw new RuntimeException('Cancelled orders cannot be marked as paid.');
    }

    $paymentStmt = $conn->prepare("SELECT id, payment_method, payment_reference, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $paymentStmt->execute([$orderId]);
    $payment = $paymentStmt->fetch();

    if (!$payment) {
        throw new RuntimeException('Payment record not found for this order.');
    }

    if ($payment['payment_status'] === 'Paid') {
        $conn->commit();
        payment_json([
            'ok' => true,
            'message' => 'Payment is already marked as paid.'
        ]);
    }

    if (strcasecmp($payment['payment_method'], 'GCash') === 0) {
        if (!preg_match('/^[0-9]{13}$/', (string)$payment['payment_reference'])) {
            throw new RuntimeException('GCash payment cannot be verified because its reference number is invalid.');
        }
    } else {
        if ($order['status'] !== 'Served') {
            throw new RuntimeException('Cash payment can be marked as paid after the order is served or collected.');
        }
    }

    $updatePayment = $conn->prepare("UPDATE payments SET payment_status = 'Paid', amount_paid = ?, change_amount = 0, paid_at = NOW() WHERE id = ?");
    $updatePayment->execute([(float)$order['total'], (int)$payment['id']]);

    $updateOrder = $conn->prepare("UPDATE orders SET paid_at = NOW() WHERE id = ? AND paid_at IS NULL");
    $updateOrder->execute([$orderId]);

    $conn->commit();

    $message = strcasecmp($payment['payment_method'], 'GCash') === 0
        ? 'GCash payment verified and marked as paid.'
        : 'Cash payment marked as paid.';

    payment_json([
        'ok' => true,
        'message' => $message,
        'order_no' => $order['order_no'],
        'payment_status' => 'Paid'
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    payment_json([
        'ok' => false,
        'message' => $e->getMessage()
    ], 400);
}
?>
