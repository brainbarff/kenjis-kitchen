<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/customer_cancellation.php';
require_once ROOT_PATH . '/includes/transaction_rules.php';
require_role(['admin', 'cashier']);

$page = 'transactions';
$title = 'Order Requests';
$heading = 'Order Requests';
$isAdmin = role_key() === 'admin';
$flash = $_SESSION['transaction_flash'] ?? '';
$flashType = $_SESSION['transaction_flash_type'] ?? 'success';
unset($_SESSION['transaction_flash'], $_SESSION['transaction_flash_type']);

function transaction_flash(string $message, string $type = 'success'): void
{
    $_SESSION['transaction_flash'] = $message;
    $_SESSION['transaction_flash_type'] = $type;
    redirect('/modules/transactions/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        transaction_flash('Invalid security token. Reload the page and try again.', 'danger');
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'request' && !$isAdmin) {
            $orderNo = strtoupper(trim((string)($_POST['order_no'] ?? '')));
            $type = (string)($_POST['request_type'] ?? '');
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($orderNo === '' || !in_array($type, ['Void', 'Refund', 'Cancel Delivery'], true) || mb_strlen($reason) < 8 || mb_strlen($reason) > 500) {
                throw new RuntimeException('Enter an order number, request type, and reason of 8–500 characters.');
            }

            $conn->beginTransaction();
            $orderStmt = $conn->prepare('SELECT id, status, order_channel, order_type FROM orders WHERE order_no = ? FOR UPDATE');
            $orderStmt->execute([$orderNo]);
            $order = $orderStmt->fetch();
            if (!$order) throw new RuntimeException('Order number not found.');
            transaction_require_status($type, $order['status']);
            if ($type === 'Refund') {
                $paidStmt = $conn->prepare("SELECT payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $paidStmt->execute([(int)$order['id']]);
                if ($paidStmt->fetchColumn() !== 'Paid') throw new RuntimeException('A refund requires a currently paid order.');
            }
            if ($type === 'Cancel Delivery') {
                if ($order['order_channel'] !== 'ONLINE' || $order['order_type'] !== 'ONLINE') {
                    throw new RuntimeException('This request is for online deliveries only.');
                }
                $dispatchStmt = $conn->prepare('SELECT status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE');
                $dispatchStmt->execute([(int)$order['id']]);
                if ($dispatchStmt->fetchColumn() !== 'Failed') throw new RuntimeException('Record the failed delivery first.');
                $paymentStmt = $conn->prepare('SELECT payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $paymentStmt->execute([(int)$order['id']]);
                $payment = $paymentStmt->fetch();
                if (!$payment || $payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Pending') {
                    throw new RuntimeException('Only unpaid cash-on-delivery orders can use this request.');
                }
            }
            $pendingStmt = $conn->prepare("SELECT COUNT(*) FROM transaction_requests WHERE order_id = ? AND status = 'Pending'");
            $pendingStmt->execute([(int)$order['id']]);
            if ((int)$pendingStmt->fetchColumn() > 0) throw new RuntimeException('This order already has a pending request.');

            $requesterShiftId = transaction_find_open_shift_id($conn, (int)$_SESSION['u_id']);
            $conn->prepare("INSERT INTO transaction_requests (order_id, request_type, requested_by, reason, refund_shift_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([(int)$order['id'], $type, (int)$_SESSION['u_id'], $reason, $requesterShiftId]);
            $conn->commit();
            transaction_flash($type . ' request submitted for Admin review.');
        }

        if (in_array($action, ['approve', 'reject'], true) && $isAdmin) {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
            if ($requestId < 1 || mb_strlen($reviewNotes) > 500) throw new RuntimeException('Invalid request or review notes.');

            $conn->beginTransaction();
            $idStmt = $conn->prepare('SELECT order_id FROM transaction_requests WHERE id = ?');
            $idStmt->execute([$requestId]);
            $orderId = (int)$idStmt->fetchColumn();
            if ($orderId < 1) throw new RuntimeException('Request not found.');

            $orderStmt = $conn->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order) throw new RuntimeException('Order not found.');
            $requestStmt = $conn->prepare('SELECT * FROM transaction_requests WHERE id = ? FOR UPDATE');
            $requestStmt->execute([$requestId]);
            $request = $requestStmt->fetch();
            if (!$request || $request['status'] !== 'Pending') throw new RuntimeException('Request was already reviewed.');

            if ($action === 'reject') {
                $conn->prepare("UPDATE transaction_requests SET status = 'Rejected', reviewed_by = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?")
                    ->execute([(int)$_SESSION['u_id'], $reviewNotes !== '' ? $reviewNotes : 'Rejected by Admin.', $requestId]);
                $conn->commit();
                transaction_flash('Request rejected. The order was not changed.');
            }

            $type = $request['request_type'];
            if ($type === 'Cancel Order') {
                $context = customer_cancel_context($conn, $order, true);
                if (!customer_cancel_eligible($order, $context['dispatches'])) throw new RuntimeException('Preparation or fulfillment has started. Reject this cancellation request.');
                $plan = customer_cancel_stock_plan($conn, $order);
                customer_cancel_apply($conn, $order, $plan, (int)$_SESSION['u_id'], 'Customer request: ' . $request['reason']);
                $conn->prepare("UPDATE transaction_requests SET status = 'Approved', reviewed_by = ?, review_notes = ?, stock_returned = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'")
                    ->execute([(int)$_SESSION['u_id'], $reviewNotes !== '' ? $reviewNotes : 'Cancellation approved. Payment retained; refund is separate.', $plan ? 1 : 0, $requestId]);
                $conn->commit();
                try {
                    require_once ROOT_PATH . '/includes/realtime.php';
                    realtime_publish('kitchen:status_change', ['order_id' => $orderId, 'status' => 'Cancelled']);
                } catch (Throwable $notifyError) { error_log('Cancellation notification: ' . $notifyError->getMessage()); }
                transaction_flash('Cancellation approved. No payment was changed and no refund was processed.');
            }
            transaction_require_status($type, $order['status']);
            $requiredStatus = $order['status'];
            if ($type === 'Cancel Delivery') {
                if ($order['order_channel'] !== 'ONLINE' || $order['order_type'] !== 'ONLINE') throw new RuntimeException('This is not an online delivery.');
                $dispatchStmt = $conn->prepare('SELECT status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE');
                $dispatchStmt->execute([$orderId]);
                if ($dispatchStmt->fetchColumn() !== 'Failed') throw new RuntimeException('The delivery is no longer marked Failed.');
            }

            $paymentStmt = $conn->prepare('SELECT id, payment_method, payment_status, shift_id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([$orderId]);
            $payment = $paymentStmt->fetch();
            if (!$payment) throw new RuntimeException('Order payment record is missing.');
            if ($type === 'Cancel Delivery' && ($payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Pending')) {
                throw new RuntimeException('The delivery is no longer unpaid cash on delivery.');
            }

            $refundMethod = null;
            $refundReference = null;
            $refundConfirmed = false;
            $refundShiftId = null;
            if ($payment['payment_status'] === 'Paid') {
                $refundMethod = (string)($_POST['refund_method'] ?? '');
                $refundReference = trim((string)($_POST['refund_reference'] ?? ''));
                $refundConfirmed = (string)($_POST['refund_confirmed'] ?? '') === '1';
                if (!$refundConfirmed || !in_array($refundMethod, ['Cash', 'GCash'], true)) {
                    throw new RuntimeException('Confirm the manual refund and select Cash or GCash before approval.');
                }
                if ($refundMethod === 'GCash' && !preg_match('/^[0-9]{13}$/', $refundReference)) {
                    throw new RuntimeException('Enter the 13-digit outgoing GCash refund reference.');
                }
                if ($refundMethod === 'Cash') $refundReference = null;
                $explicitShiftId = isset($_POST['refund_shift_id']) && $_POST['refund_shift_id'] !== '' ? (int)$_POST['refund_shift_id'] : null;
                $refundShiftId = transaction_resolve_refund_shift_id($conn, $order, $payment, $request, (int)$_SESSION['u_id'], $explicitShiftId);
            } elseif ($type === 'Refund') {
                throw new RuntimeException('Only paid orders can be refunded.');
            }

            $returnStock = $type === 'Cancel Delivery' && (string)($_POST['return_stock'] ?? '') === '1';
            $newInventoryDeducted = (int)$order['inventory_deducted'];
            if ($type === 'Cancel Delivery') {
                $itemsStmt = $conn->prepare('SELECT menu_item_id, SUM(quantity - COALESCE(voided_quantity, 0)) AS quantity FROM order_items WHERE order_id = ? GROUP BY menu_item_id ORDER BY menu_item_id');
                $itemsStmt->execute([$orderId]);
                $deliveryItems = $itemsStmt->fetchAll();
                if ($returnStock) {
                    if ((int)$order['inventory_deducted'] !== 1) throw new RuntimeException('Stock deduction is not recorded; review this order before returning stock.');
                    $restock = $conn->prepare("UPDATE menu_items SET availability = CASE WHEN is_archived = 0 AND availability = 'Unavailable' AND (stock + ?) > 0 THEN 'Available' ELSE availability END, stock = stock + ? WHERE id = ?");
                    $stockLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Stock In', ?, ?)");
                    foreach ($deliveryItems as $item) {
                        $quantity = (int)$item['quantity'];
                        if ($quantity < 1) continue;
                        $menuId = (int)$item['menu_item_id'];
                        $restock->execute([$quantity, $quantity, $menuId]);
                        if ($restock->rowCount() !== 1) throw new RuntimeException('Unable to restore menu stock.');
                        $stockLog->execute([$menuId, (int)$_SESSION['u_id'], $quantity, 'Cancelled failed delivery ' . $order['order_no'] . '; request #' . $requestId]);
                    }
                    $newInventoryDeducted = 0;
                } elseif ((int)$order['inventory_deducted'] === 1) {
                    $wasteLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Waste/Spoilage', ?, ?)");
                    foreach ($deliveryItems as $item) {
                        $quantity = (int)$item['quantity'];
                        if ($quantity < 1) continue;
                        $wasteLog->execute([(int)$item['menu_item_id'], (int)$_SESSION['u_id'], $quantity, 'Waste from cancelled failed delivery ' . $order['order_no'] . '; request #' . $requestId]);
                    }
                }
                $conn->prepare("UPDATE payments SET payment_status = 'Failed', paid_at = NULL WHERE id = ?")->execute([(int)$payment['id']]);
                $custUserId = (int)($order['user_id'] ?? 0);
                if ($custUserId > 0) {
                    $conn->prepare("UPDATE users SET cod_fail_count = cod_fail_count + 1 WHERE id = ?")->execute([$custUserId]);
                    $blockThreshold = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'cod_block_after_failures'")->fetchColumn() ?: 2);
                    $failCheckStmt = $conn->prepare("SELECT cod_fail_count FROM users WHERE id = ?");
                    $failCheckStmt->execute([$custUserId]);
                    $newFails = (int)$failCheckStmt->fetchColumn();
                    if ($newFails >= $blockThreshold) {
                        $conn->prepare("UPDATE users SET cod_blocked = 1 WHERE id = ?")->execute([$custUserId]);
                    }
                }
            }

            if ($type === 'Void') {
                $deductionRecorded = (int)$order['inventory_deducted'] === 1 || $order['order_channel'] === 'POS';
                if (!$deductionRecorded) {
                    $logCheck = $conn->prepare("SELECT COUNT(*) FROM inventory_logs WHERE action = 'Order Deduction' AND remarks LIKE ?");
                    $logCheck->execute(['%' . $order['order_no'] . '%']);
                    $deductionRecorded = (int)$logCheck->fetchColumn() > 0;
                }
                $itemsStmt = $conn->prepare('SELECT id, menu_item_id, quantity, voided_quantity, price FROM order_items WHERE order_id = ? ORDER BY menu_item_id, id FOR UPDATE');
                $itemsStmt->execute([$orderId]);
                $items = $itemsStmt->fetchAll();
                $restock = $conn->prepare("UPDATE menu_items SET availability = CASE WHEN is_archived = 0 AND availability = 'Unavailable' AND (stock + ?) > 0 THEN 'Available' ELSE availability END, stock = stock + ? WHERE id = ?");
                $stockLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Stock In', ?, ?)");
                $voidShiftId = $refundShiftId ?? ((int)($order['shift_id'] ?: ($payment['shift_id'] ?? 0)) ?: null);
                $voidLog = $conn->prepare("INSERT INTO void_logs (shift_id, void_type, approval_required, order_id, item_id, menu_item_id, quantity, price, cashier_id, approving_admin_id, reason) VALUES (?, 'order', 1, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($items as $item) {
                    $quantity = max(0, (int)$item['quantity'] - (int)$item['voided_quantity']);
                    if ($quantity === 0) continue;
                    if ($deductionRecorded) {
                        $restock->execute([$quantity, $quantity, (int)$item['menu_item_id']]);
                        if ($restock->rowCount() !== 1) throw new RuntimeException('Unable to restore menu stock.');
                        $stockLog->execute([(int)$item['menu_item_id'], (int)$_SESSION['u_id'], $quantity, 'Voided order ' . $order['order_no'] . ' stock return']);
                    }
                    $voidLog->execute([$voidShiftId, $orderId, (int)$item['id'], (int)$item['menu_item_id'], $quantity, (float)$item['price'], (int)$request['requested_by'], (int)$_SESSION['u_id'], $request['reason']]);
                    $conn->prepare('UPDATE order_items SET voided_quantity = quantity WHERE id = ?')->execute([(int)$item['id']]);
                }
                $newInventoryDeducted = 0;
                $conn->prepare("UPDATE payments SET payment_status = CASE WHEN payment_status = 'Paid' THEN 'Refunded' ELSE 'Voided' END WHERE id = ?")->execute([(int)$payment['id']]);
            }

            if ($type === 'Refund') {
                transaction_refund_payment($conn, (int)$payment['id']);
            }

            if ($refundConfirmed && $refundShiftId !== null) {
                $conn->prepare("UPDATE cashier_shifts SET total_refunds = total_refunds + ? WHERE id = ?")
                    ->execute([(float)$order['total'], $refundShiftId]);
            }

            $nextStatus = $type === 'Void' ? 'Voided' : ($type === 'Refund' ? 'Refunded' : 'Cancelled');
            $updateOrder = $conn->prepare("UPDATE orders SET status = ?, inventory_deducted = ?, cod_failure_reason = CASE WHEN ? = 'Cancel Delivery' THEN ? ELSE cod_failure_reason END, cancelled_at = CASE WHEN ? IN ('Voided','Cancelled') THEN NOW() ELSE cancelled_at END, cancel_reason = CASE WHEN ? IN ('Voided','Cancelled') THEN ? ELSE cancel_reason END WHERE id = ? AND status = ?");
            $updateOrder->execute([$nextStatus, $newInventoryDeducted, $type, mb_substr($request['reason'], 0, 255), $nextStatus, $nextStatus, mb_substr($request['reason'], 0, 255), $orderId, $requiredStatus]);
            if ($updateOrder->rowCount() !== 1) throw new RuntimeException('Order changed during review.');
            $stockReturnedFlag = $type === 'Void'
                ? (!empty($deductionRecorded) ? 1 : 0)
                : ($type === 'Cancel Delivery' && $returnStock ? 1 : 0);
            $conn->prepare("UPDATE transaction_requests SET status = 'Approved', reviewed_by = ?, review_notes = ?, refund_method = ?, refund_reference = ?, refund_confirmed_at = ?, refund_shift_id = ?, stock_returned = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([(int)$_SESSION['u_id'], $reviewNotes !== '' ? $reviewNotes : null, $refundMethod, $refundReference, $refundConfirmed ? date('Y-m-d H:i:s') : null, $refundShiftId, $stockReturnedFlag, $requestId]);
            $conn->commit();

            try {
                require_once ROOT_PATH . '/includes/realtime.php';
                realtime_publish('kitchen:status_change', ['order_id' => $orderId, 'status' => $nextStatus]);
            } catch (Throwable $notifyError) { error_log('Transaction notification: ' . $notifyError->getMessage()); }
            transaction_flash($type . ' approved for ' . $order['order_no'] . '.');
        }

        throw new RuntimeException('Action is not allowed for this role.');
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if ($e instanceof PDOException) {
            error_log('Order request database error: ' . $e->getMessage());
            transaction_flash('Unable to process the request. Please try again.', 'danger');
        }
        transaction_flash($e->getMessage(), 'danger');
    }
}

$latestPayment = "SELECT p1.order_id, p1.payment_method, p1.payment_status FROM payments p1 INNER JOIN (SELECT order_id, MAX(id) AS id FROM payments GROUP BY order_id) lp ON lp.id = p1.id";

$perPage = 10;
$eligibleWhere = "o.status = 'Pending' OR (o.status IN ('Served','Picked Up','Cancelled') AND p.payment_status = 'Paid') OR (o.status = 'Ready' AND o.order_channel = 'ONLINE' AND o.order_type = 'ONLINE' AND d.status = 'Failed' AND p.payment_method = 'Cash on Delivery' AND p.payment_status = 'Pending')";
$totalEligibleOrders = (int)$conn->query("SELECT COUNT(*) FROM orders o LEFT JOIN ($latestPayment) p ON p.order_id = o.id LEFT JOIN delivery_dispatches d ON d.order_id = o.id WHERE $eligibleWhere")->fetchColumn();
$totalEligiblePages = max(1, (int)ceil($totalEligibleOrders / $perPage));
$eligiblePage = max(1, min((int)($_GET['eligible_page'] ?? 1), $totalEligiblePages));
$eligibleOffset = ($eligiblePage - 1) * $perPage;

$recentOrdersStmt = $conn->prepare("SELECT o.order_no, o.order_channel, o.status, o.total, o.created_at, p.payment_method, p.payment_status FROM orders o LEFT JOIN ($latestPayment) p ON p.order_id = o.id LEFT JOIN delivery_dispatches d ON d.order_id = o.id WHERE $eligibleWhere ORDER BY o.id DESC LIMIT :limit OFFSET :offset");
$recentOrdersStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$recentOrdersStmt->bindValue(':offset', $eligibleOffset, PDO::PARAM_INT);
$recentOrdersStmt->execute();
$recentOrders = $recentOrdersStmt->fetchAll();

$pendingRequests = $conn->query("SELECT tr.*, o.order_no, o.order_channel, o.status AS order_status, o.total, p.payment_method, p.payment_status, u.full_name AS requester FROM transaction_requests tr JOIN orders o ON o.id = tr.order_id LEFT JOIN ($latestPayment) p ON p.order_id = o.id JOIN users u ON u.id = tr.requested_by WHERE tr.status = 'Pending' ORDER BY tr.created_at ASC, tr.id ASC")->fetchAll();

$totalDecisions = (int)$conn->query("SELECT COUNT(*) FROM transaction_requests tr JOIN orders o ON o.id = tr.order_id JOIN users u ON u.id = tr.requested_by WHERE tr.status <> 'Pending'")->fetchColumn();
$totalDecisionsPages = max(1, (int)ceil($totalDecisions / $perPage));
$decisionsPage = max(1, min((int)($_GET['decisions_page'] ?? 1), $totalDecisionsPages));
$decisionsOffset = ($decisionsPage - 1) * $perPage;

$historyStmt = $conn->prepare("SELECT tr.*, o.order_no, u.full_name AS requester, reviewer.full_name AS reviewer FROM transaction_requests tr JOIN orders o ON o.id = tr.order_id JOIN users u ON u.id = tr.requested_by LEFT JOIN users reviewer ON reviewer.id = tr.reviewed_by WHERE tr.status <> 'Pending' ORDER BY tr.reviewed_at DESC, tr.id DESC LIMIT :limit OFFSET :offset");
$historyStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$historyStmt->bindValue(':offset', $decisionsOffset, PDO::PARAM_INT);
$historyStmt->execute();
$history = $historyStmt->fetchAll();

$buildTransactionsQuery = static function (array $overrides = []) use ($eligiblePage, $decisionsPage): string {
    $params = array_merge([
        'order_no' => trim((string)($_GET['order_no'] ?? '')),
        'request_type' => trim((string)($_GET['request_type'] ?? '')),
        'eligible_page' => $eligiblePage,
        'decisions_page' => $decisionsPage,
    ], $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
};

include ROOT_PATH . '/includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= e($flashType) ?>" style="margin-bottom:18px"><?= e($flash) ?></div><?php endif; ?>
<?php if ($isAdmin || $pendingRequests): ?>
<section class="card" id="pendingRequests" style="margin-bottom:24px">
    <h2>Pending requests (<?= count($pendingRequests) ?>)</h2>
    <?php if (!$pendingRequests): ?><p class="muted">No pending requests.</p><?php endif; ?>
    <?php foreach ($pendingRequests as $request): ?>
    <div style="border-top:1px solid var(--border);padding:16px 0">
        <strong>#<?= (int)$request['id'] ?> · <?= e($request['request_type']) ?> · <?= e($request['order_no']) ?></strong>
        <p class="muted">Requested by <?= e($request['requester']) ?> · Order <?= e($request['order_status']) ?> · <?= e($request['payment_method'] ?? '—') ?> / <?= e($request['payment_status'] ?? '—') ?> · <?= money($request['total']) ?></p>
        <p><?= e($request['reason']) ?></p>
        <?php if ($isAdmin): ?>
        <form class="form" method="post" style="max-width:680px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
            <div class="field"><label>Review notes</label><input name="review_notes" maxlength="500"></div>
            <?php if ($request['request_type'] === 'Cancel Order'): ?>
            <p class="muted">Customer cancellation before preparation. Approval restores confirmed deducted stock only. Payments remain unchanged; any refund must be handled separately. Ambiguous stock history blocks approval.</p>
            <?php if ($request['review_notes']): ?><p><?= e($request['review_notes']) ?></p><?php endif; ?>
            <?php endif; ?>
            <?php if ($request['payment_status'] === 'Paid' && $request['request_type'] !== 'Cancel Order'): ?>
            <div class="field"><label>Manual refund method</label><select name="refund_method"><option value="">Select method</option><option value="Cash">Cash</option><option value="GCash">GCash</option></select></div>
            <div class="field"><label>Outgoing GCash reference (required for GCash refund)</label><input name="refund_reference" maxlength="100" inputmode="numeric"></div>
            <label><input type="checkbox" name="refund_confirmed" value="1"> I confirm the customer has been refunded outside this system.</label>
            <?php endif; ?>
            <?php if ($request['request_type'] === 'Cancel Delivery'): ?>
            <label><input type="checkbox" name="return_stock" value="1"> Items are available for sale; return them to stock.</label>
            <p class="muted">Leave unchecked if the food was used, damaged, or cannot be sold.</p>
            <?php endif; ?>
            <div style="display:flex;gap:10px;margin-top:12px"><button class="btn btn-primary" name="action" value="approve" type="submit">Approve</button><button class="btn btn-secondary" name="action" value="reject" type="submit">Reject</button></div>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<div class="grid grid-2">
    <?php if (!$isAdmin): ?>
    <section class="card">
        <h2>Request a void, refund, or delivery cancellation</h2>
        <p class="muted">Void: Pending. Refund: Served, Picked Up, or Cancelled and still paid. Cancel delivery: Failed and unpaid. Admin approval is required. Refunding a cancelled order does not return stock again.</p>
        <form class="form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="request">
            <div class="field"><label>Order number</label><input name="order_no" placeholder="ORD-YYYYMMDD-0001" value="<?= e(trim((string)($_GET['order_no'] ?? ''))) ?>" required></div>
            <div class="field"><label>Request type</label><select name="request_type" required><option value="Void">Void Pending order</option><option value="Refund">Refund completed or cancelled paid order</option><option value="Cancel Delivery" <?= ($_GET['request_type'] ?? '') === 'Cancel Delivery' ? 'selected' : '' ?>>Cancel failed unpaid delivery</option></select></div>
            <div class="field"><label>Reason</label><textarea name="reason" minlength="8" maxlength="500" required></textarea></div>
            <button class="btn btn-primary" type="submit">Send to Admin</button>
        </form>
    </section>
    <?php else: ?>
    <section class="card"><h2>Admin review</h2><p class="muted">Void/refund requests for paid orders require manual refund confirmation. Customer cancellation preserves the payment. A cashier can then request a refund for the cancelled paid order here; approval records repayment without returning stock again.</p></section>
    <?php endif; ?>
    <section class="card">
        <div class="page-head" style="margin-bottom:12px;">
            <h2 style="margin:0;">Recent eligible orders</h2>
            <?= render_pagination_controls($eligiblePage, $totalEligiblePages, static fn(int $p) => $buildTransactionsQuery(['eligible_page' => $p])) ?>
        </div>
        <div class="table-wrap"><table><thead><tr><th>Order</th><th>Status</th><th>Payment</th><th>Total</th></tr></thead><tbody>
        <?php foreach ($recentOrders as $row): ?><tr><td><?= e($row['order_no']) ?></td><td><?= e($row['status']) ?></td><td><?= e($row['payment_method'] ?? '—') ?> / <?= e($row['payment_status'] ?? '—') ?></td><td><?= money($row['total']) ?></td></tr><?php endforeach; ?>
        <?php if (!$recentOrders): ?><tr><td colspan="4">No eligible orders.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<section class="card" style="margin-top:24px">
    <div class="page-head" style="margin-bottom:12px;">
        <h2 style="margin:0;">Recent decisions</h2>
        <?= render_pagination_controls($decisionsPage, $totalDecisionsPages, static fn(int $p) => $buildTransactionsQuery(['decisions_page' => $p])) ?>
    </div>
    <div class="table-wrap"><table><thead><tr><th>Order</th><th>Request</th><th>Status</th><th>Requester</th><th>Reviewer</th><th>Refund / stock</th></tr></thead><tbody>
<?php foreach ($history as $request): ?>
<?php
$decisionParts = [];
if (!empty($request['refund_method'])) {
    $decisionParts[] = $request['refund_method'] . (!empty($request['refund_reference']) ? ' · ' . $request['refund_reference'] : '');
}
if ($request['status'] === 'Approved') {
    if ($request['request_type'] === 'Cancel Delivery') {
        $decisionParts[] = (int)$request['stock_returned'] === 1 ? 'Stock returned' : 'Stock kept deducted';
    } elseif ($request['request_type'] === 'Cancel Order') {
        $decisionParts[] = (int)$request['stock_returned'] === 1 ? 'Stock returned' : 'No stock returned';
    } elseif ($request['request_type'] === 'Void' && (int)$request['stock_returned'] === 1) {
        $decisionParts[] = 'Stock returned';
    }
}
$decisionSummary = $decisionParts ? implode(' · ', $decisionParts) : '—';
?>
<tr><td><?= e($request['order_no']) ?></td><td><?= e($request['request_type']) ?></td><td><?= e($request['status']) ?></td><td><?= e($request['requester']) ?></td><td><?= e($request['reviewer'] ?? '—') ?></td><td><?= e($decisionSummary) ?></td></tr>
<?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="6">No decisions yet.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
