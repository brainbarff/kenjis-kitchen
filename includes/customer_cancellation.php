<?php
// All mutation callers must first lock the order in their transaction.
function customer_cancel_eligible(array $order, array $dispatches): bool
{
    if (($order['order_channel'] ?? '') !== 'ONLINE' || ($order['status'] ?? '') !== 'Pending') return false;
    foreach (['preparing_at', 'kitchen_prepared_at', 'ready_at', 'served_at', 'cancelled_at'] as $field) {
        if (!empty($order[$field])) return false;
    }
    return !$dispatches;
}

function customer_cancel_review(array $payments): bool
{
    if (!$payments) return true;
    foreach ($payments as $payment) {
        if (($payment['payment_status'] ?? '') !== 'Pending'
            || (float)($payment['amount_paid'] ?? 0) > 0
            || !in_array($payment['payment_method'], ['Cash on Delivery', 'Pay on Pickup', 'Cash'], true)
            || !empty($payment['payment_reference']) || !empty($payment['payment_proof_path'])) return true;
    }
    return false;
}

function customer_cancel_context(PDO $conn, array $order, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $p = $conn->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id' . $suffix);
    $p->execute([$order['id']]);
    $d = $conn->prepare('SELECT * FROM delivery_dispatches WHERE order_id = ?' . $suffix);
    $d->execute([$order['id']]);
    $r = $conn->prepare('SELECT * FROM transaction_requests WHERE order_id = ? ORDER BY id DESC' . $suffix);
    $r->execute([$order['id']]);
    return ['payments' => $p->fetchAll(), 'dispatches' => $d->fetchAll(), 'requests' => $r->fetchAll()];
}

function customer_cancel_stock_plan(PDO $conn, array $order): array
{
    $expectedRemark = 'Online order ' . $order['order_no'] . ' auto-deduction';
    $logs = $conn->prepare("SELECT menu_item_id, quantity, action, remarks FROM inventory_logs WHERE action = 'Order Deduction' AND remarks = ? ORDER BY id FOR UPDATE");
    $logs->execute([$expectedRemark]);
    $plan = [];
    foreach ($logs->fetchAll() as $log) {
        if ((int)$log['menu_item_id'] < 1 || (float)$log['quantity'] <= 0 || (float)$log['quantity'] != (int)$log['quantity']) {
            throw new DomainException('Stock history needs staff investigation before cancellation can be approved.');
        }
        $id = (int)$log['menu_item_id'];
        // Checkout writes one deduction per stock item. Multiple entries are ambiguous.
        if (isset($plan[$id])) throw new DomainException('Duplicate stock deductions require staff investigation.');
        $plan[$id] = (int)$log['quantity'];
    }
    $items = $conn->prepare('SELECT quantity, voided_quantity FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE');
    $items->execute([$order['id']]);
    $quantity = 0;
    foreach ($items->fetchAll() as $item) {
        if ((int)$item['voided_quantity'] !== 0 || (int)$item['quantity'] <= 0) throw new DomainException('Adjusted order items require staff investigation.');
        $quantity += (int)$item['quantity'];
    }
    if ((int)$order['inventory_deducted'] === 0 && !$plan) return [];
    if ((int)$order['inventory_deducted'] !== 1 || !$plan || array_sum($plan) !== $quantity) {
        throw new DomainException('Stock deductions cannot be confirmed. Staff must investigate before cancellation.');
    }
    ksort($plan);
    return $plan;
}

function customer_cancel_apply(PDO $conn, array $order, array $plan, int $actor, string $reason): void
{
    $update = $conn->prepare("UPDATE orders SET status = 'Cancelled', cancelled_at = NOW(), cancel_reason = ?, inventory_deducted = 0 WHERE id = ? AND status = 'Pending' AND preparing_at IS NULL AND ready_at IS NULL AND served_at IS NULL");
    $update->execute([mb_substr($reason, 0, 255), $order['id']]);
    if ($update->rowCount() !== 1) throw new DomainException('Order status changed. Refresh your orders.');
    $stock = $conn->prepare("UPDATE menu_items SET availability = CASE WHEN (stock + ?) > 0 AND is_archived = 0 AND availability = 'Unavailable' THEN 'Available' ELSE availability END, stock = stock + ? WHERE id = ?");
    $log = $conn->prepare("INSERT INTO inventory_logs (menu_item_id, user_id, action, quantity, remarks) VALUES (?, ?, 'Stock In', ?, ?)");
    foreach ($plan as $id => $qty) {
        $stock->execute([$qty, $qty, $id]);
        if ($stock->rowCount() !== 1) throw new DomainException('A stock item is missing. Staff investigation is required.');
        $log->execute([$id, $actor, $qty, 'Customer cancellation ' . $order['order_no'] . ' stock return']);
    }
}

function customer_cancel_summary(PDO $conn, array $order): array
{
    $context = customer_cancel_context($conn, $order);
    $pending = false;
    $decision = null;
    $refundDecision = null;
    foreach ($context['requests'] as $request) {
        if ($request['status'] === 'Pending') $pending = true;
        if ($decision === null && $request['request_type'] === 'Cancel Order') {
            $decision = ['status' => $request['status'], 'reason' => $request['reason'], 'review_notes' => $request['review_notes']];
        }
        if ($refundDecision === null && $request['request_type'] === 'Refund' && $request['status'] === 'Approved') {
            $refundDecision = $request;
        }
    }
    if ($decision !== null && $decision['status'] === 'Approved' && $refundDecision !== null) {
        $method = !empty($refundDecision['refund_method']) ? ' via ' . $refundDecision['refund_method'] : '';
        $ref = !empty($refundDecision['refund_reference']) ? ' (Ref: ' . $refundDecision['refund_reference'] . ')' : '';
        $decision['review_notes'] = 'Cancellation approved and payment refunded' . $method . $ref . '.';
    }
    $eligible = customer_cancel_eligible($order, $context['dispatches']);
    return ['mode' => $eligible && !$pending ? (customer_cancel_review($context['payments']) || !empty($order['paid_at']) ? 'request' : 'cancel') : null,
        'pending' => $pending, 'decision' => $decision, 'reason' => $order['cancel_reason'] ?? null,
        'explanation' => $pending ? 'A staff review is pending.' : ($eligible ? '' : 'Cancellation is unavailable after preparation begins or the order is finalized.')];
}
