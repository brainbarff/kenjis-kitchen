<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_tracking.php';

/** Confirm one pending online GCash payment and its latest proof in the caller's transaction. */
function verify_online_gcash_payment(PDO $conn, int $orderId, ?int $proofId, int $staffId, ?int $shiftId, string $source): array
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Payment verification requires a transaction.');
    }

    $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND order_channel = 'ONLINE' FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['status'] !== 'Pending' || $order['payment_verification_status'] !== 'Awaiting Verification') {
        throw new RuntimeException('This order is not awaiting GCash verification.');
    }

    $paymentStmt = $conn->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $paymentStmt->execute([$orderId]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || $payment['payment_status'] !== 'Pending') {
        throw new RuntimeException('A pending GCash payment was not found for this order.');
    }

    $proofStmt = $conn->prepare("SELECT * FROM payment_proofs WHERE order_id = ? AND method_code = 'GCASH' AND payment_type = 'food' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $proofStmt->execute([$orderId]);
    $proof = $proofStmt->fetch(PDO::FETCH_ASSOC);
    if (!$proof || $proof['verification_status'] !== 'pending' || ($proofId !== null && (int)$proof['id'] !== $proofId)) {
        throw new RuntimeException('The latest pending payment proof was not found for this order.');
    }
    if (!hash_equals((string)$payment['payment_reference'], (string)$proof['reference_number'])
        || abs((float)$proof['amount_declared'] - (float)$order['total']) > 0.009) {
        throw new RuntimeException('The payment proof does not match this order and amount.');
    }
    $proofFile = ROOT_PATH . '/uploads/payment_proofs/' . basename((string)$proof['image_path']);
    if (!is_file($proofFile)) {
        throw new RuntimeException('The payment screenshot is unavailable for review.');
    }

    $updateProof = $conn->prepare("UPDATE payment_proofs SET verification_status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ? AND order_id = ? AND verification_status = 'pending'");
    $updateProof->execute([$staffId, (int)$proof['id'], $orderId]);
    if ($updateProof->rowCount() !== 1) throw new RuntimeException('Payment proof changed during review.');

    $updatePayment = $conn->prepare("UPDATE payments SET payment_status = 'Paid', amount_paid = ?, change_amount = 0, paid_at = NOW(), shift_id = ? WHERE id = ? AND payment_status = 'Pending'");
    $updatePayment->execute([(float)$order['total'], $shiftId, (int)$payment['id']]);
    if ($updatePayment->rowCount() !== 1) throw new RuntimeException('Payment changed during review.');

    $shouldQueueKitchen = kk_should_queue_online_gcash_order_to_kitchen($order);
    $queueSqlExpr = $shouldQueueKitchen ? 'COALESCE(kitchen_queued_at, NOW())' : 'kitchen_queued_at';

    $updateOrder = $conn->prepare("UPDATE orders SET payment_verification_status = 'Verified', payment_verified_by = ?, payment_verified_at = NOW(), paid_at = NOW(), payment_auto_cancel_at = NULL, kitchen_queued_at = {$queueSqlExpr}, locked_by_user_id = NULL, locked_at = NULL WHERE id = ? AND status = 'Pending' AND payment_verification_status = 'Awaiting Verification'");
    $updateOrder->execute([$staffId, $orderId]);
    if ($updateOrder->rowCount() !== 1) throw new RuntimeException('Order changed during review.');

    $audit = $conn->prepare("INSERT INTO payment_verification_audits (payment_id, order_id, verified_by, previous_status, new_status, verification_source, notes) VALUES (?, ?, ?, 'Pending', 'Paid', ?, ?)");
    $audit->execute([(int)$payment['id'], $orderId, $staffId, $source, $shouldQueueKitchen ? 'Verified against the latest uploaded GCash proof' : 'Verified GCash proof; held from Kitchen pending Option 1 courier tracking link approval']);
    if ((float)$order['free_delivery_discount'] > 0 && !empty($order['customer_phone'])) {
        $benefit = $conn->prepare('INSERT INTO first_order_benefit_hashes (phone_hash, address_hash, used_order_id) VALUES (?, ?, ?)');
        $benefit->execute([
            hash('sha256', preg_replace('/\D+/', '', (string)$order['customer_phone'])),
            hash('sha256', strtolower(trim((string)$order['delivery_address']))),
            $orderId,
        ]);
    }
    log_audit($conn, 'qr_payment_verified', 'order', (string)$orderId, 'GCash proof verified by staff #' . $staffId . ($shouldQueueKitchen ? '' : ' (Option 1 held pending courier tracking link verification)'));

    return [
        'order_no' => $order['order_no'],
        'queue_no' => (int)$order['queue_no'],
        'was_queued' => $order['kitchen_queued_at'] !== null,
        'released_to_kitchen' => $shouldQueueKitchen && $order['kitchen_queued_at'] === null,
        'held_for_tracking' => !$shouldQueueKitchen,
    ];
}

/** Cancel one unpaid GCash order and restore stock exactly once. Caller owns the transaction. */
function cancel_pending_gcash_order(PDO $conn, int $orderId, string $reason, ?int $staffId = null): ?string
{
    if (!$conn->inTransaction()) throw new LogicException('GCash cancellation requires a transaction.');
    $stmt = $conn->prepare("SELECT id, order_no, status, payment_verification_status, inventory_deducted FROM orders WHERE id = ? AND order_channel = 'ONLINE' FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['status'] !== 'Pending' || !in_array($order['payment_verification_status'], ['Awaiting Verification', 'Rejected'], true)) {
        return null;
    }
    $paymentStmt = $conn->prepare('SELECT id, payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $paymentStmt->execute([$orderId]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || $payment['payment_status'] !== 'Pending') {
        return null;
    }

    if ((int)$order['inventory_deducted'] === 1) {
        $itemsStmt = $conn->prepare('SELECT menu_item_id, SUM(quantity - voided_quantity) AS qty FROM order_items WHERE order_id = ? GROUP BY menu_item_id ORDER BY menu_item_id');
        $itemsStmt->execute([$orderId]);
        $restock = $conn->prepare("UPDATE menu_items SET availability = CASE WHEN availability = 'Unavailable' AND stock = 0 THEN 'Available' ELSE availability END, stock = stock + ? WHERE id = ?");
        $stockLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Stock In', ?, ?)");
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $qty = (int)$item['qty'];
            if ($qty < 1) continue;
            $menuId = (int)$item['menu_item_id'];
            $restock->execute([$qty, $menuId]);
            if ($restock->rowCount() !== 1) throw new RuntimeException('Unable to restore stock for cancelled order.');
            $stockLog->execute([$menuId, $staffId, $qty, $reason . ' for order ' . $order['order_no']]);
        }
    }

    $update = $conn->prepare("UPDATE orders SET status = 'Cancelled', cancel_reason = ?, cancelled_at = NOW(), payment_verification_status = 'Rejected', payment_auto_cancel_at = NULL, inventory_deducted = 0, locked_by_user_id = NULL, locked_at = NULL WHERE id = ? AND status = 'Pending'");
    $update->execute([mb_substr($reason, 0, 255), $orderId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Order changed during cancellation.');
    $conn->prepare("UPDATE payments SET payment_status = 'Failed', paid_at = NULL WHERE id = ? AND payment_status = 'Pending'")->execute([(int)$payment['id']]);
    $conn->prepare("UPDATE payment_proofs SET verification_status = 'rejected', rejection_reason = ? WHERE order_id = ? AND verification_status = 'pending'")->execute([$reason, $orderId]);
    log_audit($conn, 'qr_payment_cancelled', 'order', (string)$orderId, $reason);
    return (string)$order['order_no'];
}

/** Return configured QR payment verification timeout in minutes (default 30, clamped 5..1440). */
function qr_payment_timeout_minutes(PDO $conn): int
{
    try {
        $val = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'qr_payment_timeout_minutes'")->fetchColumn();
        return max(5, min(1440, (int)($val ?: 30)));
    } catch (Throwable $e) {
        return 30;
    }
}

/**
 * Automatically cancel overdue unpaid online GCash orders (both 'Awaiting Verification' and 'Rejected' proofs)
 * and restore reserved stock without requiring staff to open administration pages.
 */
function expire_overdue_gcash_orders(PDO $conn, ?int $staffId = null): int
{
    if ($conn->inTransaction()) {
        return 0;
    }

    $cancelledCount = 0;
    try {
        $timeoutMinutes = qr_payment_timeout_minutes($conn);
        $stmt = $conn->prepare("
            SELECT o.id, o.order_no, o.payment_verification_status
            FROM orders o
            WHERE o.order_channel = 'ONLINE'
              AND o.status = 'Pending'
              AND o.payment_verification_status IN ('Awaiting Verification', 'Rejected')
              AND (
                  (o.payment_auto_cancel_at IS NOT NULL AND o.payment_auto_cancel_at <= NOW())
                  OR (
                      o.payment_auto_cancel_at IS NULL
                      AND COALESCE(
                          (SELECT MAX(COALESCE(pp.verified_at, pp.submitted_at))
                           FROM payment_proofs pp
                           WHERE pp.order_id = o.id AND pp.payment_type = 'food'),
                          o.created_at
                      ) <= DATE_SUB(NOW(), INTERVAL :timeout MINUTE)
                  )
              )
            ORDER BY o.id ASC
        ");
        $stmt->execute([':timeout' => $timeoutMinutes]);
        $expiredOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($expiredOrders as $eo) {
            $eId = (int)$eo['id'];
            $reason = ($eo['payment_verification_status'] === 'Rejected')
                ? 'Rejected payment proof expired without resubmission'
                : 'Payment verification timeout';
            try {
                $conn->beginTransaction();
                $cancelledOrderNo = cancel_pending_gcash_order($conn, $eId, $reason, $staffId);
                $conn->commit();
                if ($cancelledOrderNo !== null) {
                    $cancelledCount++;
                }
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('GCash timeout cancellation failed for order #' . $eId . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('expire_overdue_gcash_orders error: ' . $e->getMessage());
    }

    return $cancelledCount;
}
