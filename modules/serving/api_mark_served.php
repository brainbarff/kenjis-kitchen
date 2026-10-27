<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier', 'kitchen']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = (int) ($data['order_id'] ?? 0);

if (!$id) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid order selected.']);
    exit;
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT id, order_no, order_type, order_channel, total, status, COALESCE(inventory_deducted, 0) AS inventory_deducted FROM orders WHERE id = ? AND status IN ('Ready', 'Preparing', 'Pending') FOR UPDATE");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) {
        $conn->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Order is no longer active or already completed.']);
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
                echo json_encode(['ok' => false, 'msg' => 'Verify the GCash payment before completing this online order.']);
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
        WHERE id = ? AND status IN ('Ready', 'Preparing', 'Pending')
    ");
    $stmt->execute([$next, $_SESSION['u_id'], $id]);

    // Only deduct upon serving for legacy ONLINE orders whose stock was not deducted at checkout.
    // New POS and ONLINE orders have inventory_deducted = 1 at checkout, and legacy POS orders
    // already deducted stock at checkout even when inventory_deducted was left 0.
    if ((int)$order['inventory_deducted'] === 0) {
        $alreadyDeducted = (strtoupper((string)$order['order_channel']) === 'POS');

        if (!$alreadyDeducted) {
            $logCheckStmt = $conn->prepare("
                SELECT 1
                FROM inventory_logs
                WHERE action = 'Order Deduction' AND remarks LIKE ?
                LIMIT 1
            ");
            $logCheckStmt->execute(['%' . $order['order_no'] . '%']);
            $alreadyDeducted = (bool)$logCheckStmt->fetchColumn();
        }

        if (!$alreadyDeducted) {
            $itemStmt = $conn->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
            $itemStmt->execute([$id]);
            $orderItems = $itemStmt->fetchAll();

            $fallbackDeductions = [];
            foreach ($orderItems as $oi) {
                $menuId = (int)($oi['menu_item_id'] ?? 0);
                $qty = (int)($oi['quantity'] ?? 0);
                if ($menuId > 0 && $qty > 0) {
                    $fallbackDeductions[$menuId] = ($fallbackDeductions[$menuId] ?? 0) + $qty;
                }
            }

            if ($fallbackDeductions) {
                ksort($fallbackDeductions);
                $lockItemStmt = $conn->prepare("SELECT id FROM menu_items WHERE id = ? FOR UPDATE");
                $stockStmt = $conn->prepare("
                    UPDATE menu_items
                    SET stock = GREATEST(0, stock - ?),
                        availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE availability END
                    WHERE id = ?
                ");
                $logStmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");
                $channelPrefix = ($order['order_channel'] === 'ONLINE') ? 'Online order ' : 'Order ';
                $actingUserId = $_SESSION['u_id'] ?? ($_SESSION['user_id'] ?? 1);

                foreach ($fallbackDeductions as $menuId => $totalQty) {
                    $lockItemStmt->execute([$menuId]);
                    if ($lockItemStmt->fetch()) {
                        $stockStmt->execute([$totalQty, $menuId]);
                        $logStmt->execute([$menuId, $actingUserId, $totalQty, $channelPrefix . $order['order_no'] . ' completed deduction']);
                    }
                }
            }
        }

        $conn->prepare("UPDATE orders SET inventory_deducted = 1 WHERE id = ?")->execute([$id]);
    }

    require_once ROOT_PATH . '/includes/realtime.php';
    if (function_exists('realtime_publish')) {
        realtime_publish('kitchen:status_change', ['order_id' => $id, 'status' => $next]);
    }

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
