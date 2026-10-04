<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = (int) ($data['order_id'] ?? 0);

if (!$id) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid order selected.']);
    exit;
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT id, order_type, order_channel, total FROM orders WHERE id = ? AND status = 'Ready' FOR UPDATE");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) {
        $conn->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Order is no longer ready for serving.']);
        exit;
    }

    if ($order['order_channel'] === 'ONLINE') {
        $paymentStmt = $conn->prepare("SELECT id, payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $paymentStmt->execute([$id]);
        $payment = $paymentStmt->fetch();

        if (!$payment) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Payment record is missing for this online order.']);
            exit;
        }

        if ($payment['payment_status'] !== 'Paid') {
            if (strcasecmp($payment['payment_method'], 'GCash') === 0) {
                $conn->rollBack();
                echo json_encode(['ok' => false, 'msg' => 'Verify the GCash payment before serving this online order.']);
                exit;
            }

            $payStmt = $conn->prepare("UPDATE payments SET payment_status = 'Paid', amount_paid = ?, change_amount = 0, paid_at = NOW() WHERE id = ?");
            $payStmt->execute([(float)$order['total'], (int)$payment['id']]);
        }
    }

    $next = 'Served';

    $stmt = $conn->prepare("
        UPDATE orders
        SET status = ?, served_at = NOW(), served_by = ?, paid_at = CASE
            WHEN order_channel = 'ONLINE' AND paid_at IS NULL THEN NOW()
            ELSE paid_at
        END
        WHERE id = ? AND status = 'Ready'
    ");
    $stmt->execute([$next, $_SESSION['u_id'], $id]);

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'status' => $next,
        'msg' => 'Order has been completed.'
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo json_encode([
        'ok' => false,
        'msg' => 'Unable to update the order right now.'
    ]);
}
?>
