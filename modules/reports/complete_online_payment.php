<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/gcash_verification.php';
require_role(['admin', 'cashier']);

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
$submittedToken = (is_array($data) && isset($data['csrf_token'])) ? $data['csrf_token'] : null;
if (!verify_csrf_token($submittedToken)) {
    payment_json(['ok' => false, 'message' => 'Invalid or expired security token. Please reload the page and try again.'], 403);
}

$orderId = (int)($data['order_id'] ?? 0);

if ($orderId <= 0) {
    payment_json(['ok' => false, 'message' => 'Invalid order selected.'], 400);
}

try {
    $conn->beginTransaction();

    $orderStmt = $conn->prepare("SELECT id, order_no, status, total, kitchen_queued_at FROM orders WHERE id = ? AND order_channel = 'ONLINE' FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        throw new RuntimeException('Online order not found.');
    }

    if ($order['status'] === 'Cancelled') {
        throw new RuntimeException('Cancelled orders cannot be marked as paid.');
    }

    $cancelReqStmt = $conn->prepare("SELECT id FROM transaction_requests WHERE order_id = ? AND request_type = 'Cancel Order' AND status = 'Pending' LIMIT 1 FOR UPDATE");
    $cancelReqStmt->execute([$orderId]);
    if ($cancelReqStmt->fetchColumn()) {
        throw new RuntimeException('Customer has requested to cancel this order. Review the cancellation request in Order Requests before confirming payment.');
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
        $staffId = (int)$_SESSION['u_id'];
        $shiftId = null;
        if (role_key() === 'cashier') {
            $shiftStmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
            $shiftStmt->execute([$staffId]);
            $shiftId = $shiftStmt->fetchColumn() ?: null;
            if (!$shiftId) throw new RuntimeException('Open a cashier shift before verifying payment.');
        }
        $verified = verify_online_gcash_payment($conn, $orderId, null, $staffId, $shiftId ? (int)$shiftId : null, role_key() === 'cashier' ? 'POS_CASHIER' : 'ADMIN_ONLINE');
        $conn->commit();
        if (!$verified['was_queued']) {
            require_once ROOT_PATH . '/includes/realtime.php';
            realtime_publish('order:submitted', [
                'order_id' => $orderId,
                'order_no' => $verified['order_no'],
                'status' => 'Pending',
                'order_channel' => 'ONLINE',
            ]);
        }
        payment_json(['ok' => true, 'message' => 'GCash proof verified and order released to kitchen.', 'order_no' => $verified['order_no'], 'payment_status' => 'Paid']);
    }
    if ($order['status'] !== 'Served') {
        throw new RuntimeException('Cash payment can be marked as paid after the order is served or collected.');
    }

    $staffUserId = (int)($_SESSION['u_id'] ?? ($_SESSION['user_id'] ?? 0));
    $staffRole = role_key();
    $shiftId = null;
    if ($staffRole === 'cashier') {
        $shiftStmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $shiftStmt->execute([$staffUserId]);
        $shiftId = $shiftStmt->fetchColumn() ?: null;
        if (!$shiftId && !empty($_SESSION['pos_active_cashier']['id'])) {
            $shiftStmt->execute([(int)$_SESSION['pos_active_cashier']['id']]);
            $shiftId = $shiftStmt->fetchColumn() ?: null;
        }
        if (!$shiftId) {
            throw new RuntimeException('Open a cashier shift before marking cash payment as paid.');
        }
    }

    $updatePayment = $conn->prepare("UPDATE payments SET payment_status = 'Paid', amount_paid = ?, change_amount = 0, paid_at = NOW(), shift_id = ? WHERE id = ?");
    $updatePayment->execute([(float)$order['total'], $shiftId ? (int)$shiftId : null, (int)$payment['id']]);

    $updateOrder = $conn->prepare("UPDATE orders SET paid_at = NOW(), cash_collected = COALESCE(cash_collected, ?), collected_by_user_id = COALESCE(collected_by_user_id, ?) WHERE id = ?");
    $updateOrder->execute([(float)$order['total'], $staffUserId ?: null, $orderId]);

    if ($payment['payment_method'] === 'Cash on Delivery' && $staffRole !== 'cashier') {
        $existingHandover = $conn->prepare("SELECT id FROM cod_cash_handovers WHERE order_id = ? LIMIT 1 FOR UPDATE");
        $existingHandover->execute([$orderId]);
        if (!$existingHandover->fetchColumn()) {
            $conn->prepare("INSERT INTO cod_cash_handovers (order_id, collected_by_user_id, amount_collected, collected_at, status) VALUES (?, ?, ?, NOW(), 'pending')")
                ->execute([$orderId, $staffUserId, (float)$order['total']]);
        }
    }

    $queueForKitchen = strcasecmp($payment['payment_method'], 'GCash') === 0
        && $order['kitchen_queued_at'] === null
        && $order['status'] === 'Pending';
    if ($queueForKitchen) {
        $conn->prepare("UPDATE orders SET kitchen_queued_at = NOW() WHERE id = ?")->execute([$orderId]);
    }

    $staffUserId = $_SESSION['u_id'] ?? ($_SESSION['user_id'] ?? null);
    $staffRole = role_key();
    $verificationSource = ($staffRole === 'cashier') ? 'POS_CASHIER' : 'ADMIN_ONLINE';
    $notes = strcasecmp($payment['payment_method'], 'GCash') === 0
        ? (($staffRole === 'cashier') ? 'Cashier verified GCash payment' : 'Admin verified GCash payment via Reports dashboard')
        : (($staffRole === 'cashier') ? 'Cashier marked cash payment as paid' : 'Admin marked cash payment as paid via Reports dashboard');

    $auditStmt = $conn->prepare("INSERT INTO payment_verification_audits (payment_id, order_id, verified_by, previous_status, new_status, verification_source, notes) VALUES (?, ?, ?, ?, 'Paid', ?, ?)");
    $auditStmt->execute([
        (int)$payment['id'],
        $orderId,
        $staffUserId ? (int)$staffUserId : null,
        (string)$payment['payment_status'],
        $verificationSource,
        $notes
    ]);

    $conn->commit();

    if ($queueForKitchen) {
        require_once ROOT_PATH . '/includes/realtime.php';
        realtime_publish('order:submitted', [
            'order_id' => $orderId,
            'order_no' => $order['order_no'],
            'status' => 'Pending',
            'order_channel' => 'ONLINE'
        ]);
    }

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
