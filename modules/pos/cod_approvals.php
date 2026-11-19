<?php
/**
 * Cash on Delivery (COD) Approvals & Cash Handover Queue
 * Part 6: Approve/decline pending COD orders, assign delivery staff, record cash collected, and confirm handovers into cashier shift.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/functions.php';

require_role(['admin', 'cashier']);

$page = 'cod_approvals';
$title = 'COD Approvals & Handover';
$heading = 'COD Management';
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_msg_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRole = $currentUser['role_key'] ?? 'cashier';

// Active cashier shift (direct user shift or active POS PIN cashier shift)
$cashierShiftId = null;
$shiftOwnerId = $userId;
$shiftStmt = $conn->prepare("SELECT id, user_id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
$shiftStmt->execute([$userId]);
$openShiftRow = $shiftStmt->fetch(PDO::FETCH_ASSOC);
if (!$openShiftRow && !empty($_SESSION['pos_active_cashier']['id'])) {
    $shiftStmt->execute([(int)$_SESSION['pos_active_cashier']['id']]);
    $openShiftRow = $shiftStmt->fetch(PDO::FETCH_ASSOC);
}
if ($openShiftRow) {
    $cashierShiftId = (int)$openShiftRow['id'];
    $shiftOwnerId = (int)$openShiftRow['user_id'];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function verify_csrf(): void {
    if (
        empty($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Approve COD Order
    if ($action === 'approve_cod') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        try {
            if ($orderId < 1) throw new RuntimeException('Select a COD order.');
            $conn->beginTransaction();
            $orderStmt = $conn->prepare("SELECT id, order_no, queue_no, order_type, order_channel, status, cod_approval_status FROM orders WHERE id = ? FOR UPDATE");
            $orderStmt->execute([$orderId]);
            $ordInfo = $orderStmt->fetch();
            if (!$ordInfo || $ordInfo['order_channel'] !== 'ONLINE' || $ordInfo['order_type'] !== 'ONLINE' || $ordInfo['status'] !== 'Pending' || $ordInfo['cod_approval_status'] !== 'Pending') {
                throw new RuntimeException('This COD delivery is no longer awaiting approval.');
            }
            $cancelReqStmt = $conn->prepare("SELECT id FROM transaction_requests WHERE order_id = ? AND request_type IN ('Cancel Order', 'Cancel Delivery') AND status = 'Pending' LIMIT 1 FOR UPDATE");
            $cancelReqStmt->execute([$orderId]);
            if ($cancelReqStmt->fetchColumn()) {
                throw new RuntimeException('Customer has requested to cancel this order. Review the cancellation request in Order Requests before approving COD.');
            }
            $paymentStmt = $conn->prepare('SELECT payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([$orderId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Pending') {
                throw new RuntimeException('This delivery is no longer an unpaid COD order.');
            }
            $update = $conn->prepare("UPDATE orders SET cod_approval_status = 'Approved', cod_approved_by = ?, delivered_by_user_id = NULL, kitchen_queued_at = NOW() WHERE id = ? AND status = 'Pending' AND cod_approval_status = 'Pending'");
            $update->execute([$userId, $orderId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('COD order changed during approval.');
            log_audit($conn, 'cod_order_approved', 'order', (string)$orderId, "COD order approved by {$currentUser['username']}");
            $conn->commit();
            require_once ROOT_PATH . '/includes/realtime.php';
            realtime_publish('order:submitted', ['order_id' => $orderId, 'order_no' => $ordInfo['order_no'], 'queue_no' => (int)$ordInfo['queue_no'], 'status' => 'Pending']);
            $_SESSION['flash_msg'] = 'COD order approved and released to Kitchen.';
            $_SESSION['flash_msg_type'] = 'success';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = 'Unable to approve COD: ' . $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/cod_approvals.php');
    }

    // 2. Decline COD Order
    if ($action === 'decline_cod') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['decline_reason'] ?? 'Declined by staff.'));

        try {
            if ($orderId < 1 || $reason === '') throw new RuntimeException('Select a COD order and reason.');
            $conn->beginTransaction();

            $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND order_channel = 'ONLINE' AND order_type = 'ONLINE' FOR UPDATE");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order || $order['status'] !== 'Pending' || $order['cod_approval_status'] !== 'Pending') {
                throw new RuntimeException('This COD order is no longer awaiting approval.');
            }
            $paymentStmt = $conn->prepare('SELECT id, payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([$orderId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Pending') {
                throw new RuntimeException('This is no longer an unpaid COD order.');
            }
            if ((int)$order['inventory_deducted'] === 1) {
                $itemsStmt = $conn->prepare('SELECT menu_item_id, SUM(quantity - voided_quantity) AS qty FROM order_items WHERE order_id = ? GROUP BY menu_item_id');
                $itemsStmt->execute([$orderId]);
                $restock = $conn->prepare("UPDATE menu_items SET availability = CASE WHEN availability = 'Unavailable' AND is_archived = 0 AND (stock + ?) > 0 THEN 'Available' ELSE availability END, stock = stock + ? WHERE id = ?");
                $stockLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Stock In', ?, ?)");
                foreach ($itemsStmt->fetchAll() as $item) {
                    $qty = (int)$item['qty'];
                    if ($qty < 1) continue;
                    $restock->execute([$qty, $qty, (int)$item['menu_item_id']]);
                    if ($restock->rowCount() !== 1) throw new RuntimeException('Unable to restore COD stock.');
                    $stockLog->execute([(int)$item['menu_item_id'], $userId, $qty, 'Declined COD order ' . $order['order_no']]);
                }
            }
            $update = $conn->prepare("UPDATE orders SET cod_approval_status = 'Declined', cod_declined_reason = ?, status = 'Cancelled', cancel_reason = ?, cancelled_at = NOW(), inventory_deducted = 0 WHERE id = ? AND status = 'Pending' AND cod_approval_status = 'Pending'");
            $update->execute([mb_substr($reason, 0, 500), mb_substr('COD Declined: ' . $reason, 0, 255), $orderId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('COD order changed during decline.');
            $conn->prepare("UPDATE payments SET payment_status = 'Failed', paid_at = NULL WHERE id = ?")->execute([(int)$payment['id']]);
            $conn->prepare('DELETE FROM first_order_benefit_hashes WHERE used_order_id = ?')->execute([$orderId]);
            log_audit($conn, 'cod_order_declined', 'order', (string)$orderId, "COD declined by {$currentUser['username']} (Reason: $reason)");

            $conn->commit();
            $_SESSION['flash_msg'] = "COD Order declined and stock restored.";
            $_SESSION['flash_msg_type'] = 'warning';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = "Error declining COD: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/cod_approvals.php');
    }

    // 3. Cash Collected (by delivering staff or cashier)
    if ($action === 'cash_collected') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $amount = (float)($_POST['amount_collected'] ?? 0);

        try {
            if ($orderId < 1) throw new RuntimeException('Select a COD order.');
            $conn->beginTransaction();
            $orderStmt = $conn->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order || $order['order_channel'] !== 'ONLINE' || $order['status'] !== 'Ready' || $order['cod_approval_status'] !== 'Approved' || $order['cash_collected'] !== null) {
                throw new RuntimeException('This COD order is not ready for collection.');
            }
            if (abs($amount - (float)$order['total']) > 0.009 || $amount <= 0) throw new RuntimeException('Collected amount must equal the order total.');
            $dispatchStmt = $conn->prepare('SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE');
            $dispatchStmt->execute([$orderId]);
            $dispatch = $dispatchStmt->fetch();
            if (!$dispatch || $dispatch['status'] !== 'Out for Delivery') throw new RuntimeException('Mark the order Out for Delivery in Delivery Status first.');
            $paymentStmt = $conn->prepare('SELECT id, payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([$orderId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Pending') throw new RuntimeException('This is no longer an unpaid COD order.');
            if ($userRole === 'cashier' && !$cashierShiftId) throw new RuntimeException('Open your cashier shift before recording cash.');
            $appliedShiftId = ($userRole === 'cashier') ? $cashierShiftId : null;
            $updated = $conn->prepare("UPDATE orders SET cash_collected = ?, collected_by_user_id = ?, status = 'Served', served_at = NOW(), served_by = ?, paid_at = NOW() WHERE id = ? AND status = 'Ready' AND cash_collected IS NULL");
            $updated->execute([$amount, $userId, $userId, $orderId]);
            if ($updated->rowCount() !== 1) throw new RuntimeException('COD order changed during collection.');
            $conn->prepare("UPDATE delivery_dispatches SET status = 'Delivered', delivered_at = NOW(), updated_by = ? WHERE id = ?")->execute([$userId, (int)$dispatch['id']]);
            $conn->prepare("UPDATE payments SET payment_status = 'Paid', amount_paid = ?, change_amount = 0, paid_at = NOW(), shift_id = ? WHERE id = ?")->execute([$amount, $appliedShiftId, (int)$payment['id']]);

            // If collected by non-cashier (e.g. delivery driver / admin), create cod_cash_handovers record
            if ($userRole !== 'cashier') {
                $existingHandover = $conn->prepare("SELECT id FROM cod_cash_handovers WHERE order_id = ? LIMIT 1 FOR UPDATE");
                $existingHandover->execute([$orderId]);
                if (!$existingHandover->fetchColumn()) {
                    $conn->prepare("INSERT INTO cod_cash_handovers (order_id, collected_by_user_id, amount_collected, collected_at, status) VALUES (?, ?, ?, NOW(), 'pending')")->execute([$orderId, $userId, $amount]);
                }
                $handoverMsg = "Cash collected (₱" . number_format($amount, 2) . ") logged. Please hand over to active cashier.";
            } else {
                $handoverMsg = "Cash collected (₱" . number_format($amount, 2) . ") and linked directly to your active Cashier shift.";
            }

            // Mark first order free delivery benefit as permanently used
            if ((float)$order['free_delivery_discount'] > 0 && !empty($order['customer_phone'])) {
                $pHash = hash('sha256', preg_replace('/\D+/', '', $order['customer_phone']));
                $aHash = hash('sha256', strtolower(trim((string)$order['delivery_address'])));
                $conn->prepare("INSERT IGNORE INTO first_order_benefit_hashes (phone_hash, address_hash, used_order_id) VALUES (?, ?, ?)")->execute([$pHash, $aHash, $orderId]);
            }

            log_audit($conn, 'cod_cash_collected', 'order', (string)$orderId, "Cash collected: ₱$amount by {$currentUser['username']}");

            $conn->commit();
            $_SESSION['flash_msg'] = $handoverMsg;
            $_SESSION['flash_msg_type'] = 'success';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = "Error recording cash: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/cod_approvals.php');
    }

    // 4. Mark Failed Delivery / Customer Refused / No-show (Requirement 6.5)
    if ($action === 'cod_failed') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['failure_reason'] ?? 'Customer unreachable / no-show.'));
        $treatAsWaste = !empty($_POST['treat_as_waste']);
        $giveBackBenefit = !empty($_POST['give_back_benefit']);

        try {
            if ($orderId < 1 || $reason === '') throw new RuntimeException('Select a COD order and reason.');
            $conn->beginTransaction();
            $orderStmt = $conn->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order || $order['order_channel'] !== 'ONLINE' || $order['status'] !== 'Ready' || $order['cod_approval_status'] !== 'Approved' || $order['cash_collected'] !== null) {
                throw new RuntimeException('This COD order cannot be marked failed.');
            }
            $paymentStmt = $conn->prepare('SELECT id, payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([$orderId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Pending') throw new RuntimeException('This is no longer an unpaid COD order.');
            $dispatchStmt = $conn->prepare('SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE');
            $dispatchStmt->execute([$orderId]);
            $dispatch = $dispatchStmt->fetch();
            if (!$dispatch || !in_array($dispatch['status'], ['Out for Delivery', 'Failed'], true)) {
                throw new RuntimeException('Only an order out for delivery or with a failed delivery attempt can be finalized as Failed COD.');
            }
            {
                $newInventoryDeducted = $treatAsWaste ? (int)$order['inventory_deducted'] : 0;
                $cancelUpd = $conn->prepare("UPDATE orders SET status = 'Cancelled', cod_failure_reason = ?, cancel_reason = ?, cancelled_at = NOW(), inventory_deducted = ? WHERE id = ? AND status = 'Ready'");
                $cancelUpd->execute([mb_substr($reason, 0, 255), mb_substr("Failed COD: $reason", 0, 255), $newInventoryDeducted, $orderId]);
                if ($cancelUpd->rowCount() !== 1) {
                    throw new RuntimeException('Order status changed before failed COD could be recorded.');
                }
                $conn->prepare("UPDATE payments SET payment_status = 'Failed', paid_at = NULL WHERE id = ?")->execute([(int)$payment['id']]);
                $conn->prepare("UPDATE delivery_dispatches SET status = 'Failed', failed_at = COALESCE(failed_at, NOW()), updated_by = ?, notes = ? WHERE id = ?")->execute([$userId, mb_substr($reason, 0, 500), (int)$dispatch['id']]);
                $conn->prepare("UPDATE transaction_requests SET status = 'Approved', reviewed_by = ?, review_notes = ?, stock_returned = ?, reviewed_at = NOW() WHERE order_id = ? AND request_type = 'Cancel Delivery' AND status = 'Pending'")
                    ->execute([$userId, 'Resolved via COD Approvals Failed Delivery', (!$treatAsWaste && (int)$order['inventory_deducted'] === 1) ? 1 : 0, $orderId]);

                $custUserId = (int)$order['user_id'];
                if ($custUserId > 0) {
                    // Increment customer fail count
                    $conn->prepare("UPDATE users SET cod_fail_count = cod_fail_count + 1 WHERE id = ?")->execute([$custUserId]);

                    // Check if should be auto-blocked (default 2)
                    $blockThreshold = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'cod_block_after_failures'")->fetchColumn() ?: 2);
                    $failCheckStmt = $conn->prepare("SELECT cod_fail_count FROM users WHERE id = ?");
                    $failCheckStmt->execute([$custUserId]);
                    $newFails = (int)$failCheckStmt->fetchColumn();
                    if ($newFails >= $blockThreshold) {
                        $conn->prepare("UPDATE users SET cod_blocked = 1 WHERE id = ?")->execute([$custUserId]);
                        log_audit($conn, 'customer_cod_blocked', 'user', (string)$custUserId, "Customer auto-blocked from COD after $newFails failed orders.");
                    }
                }

                if ((int)$order['inventory_deducted'] === 1) {
                    $itemsStmt = $conn->prepare("SELECT menu_item_id, SUM(quantity - COALESCE(voided_quantity, 0)) AS qty FROM order_items WHERE order_id = ? GROUP BY menu_item_id");
                    $itemsStmt->execute([$orderId]);
                    $items = $itemsStmt->fetchAll();
                    if ($treatAsWaste) {
                        $wasteLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Waste/Spoilage', ?, ?)");
                        foreach ($items as $it) {
                            $qty = (int)$it['qty'];
                            if ($qty < 1) continue;
                            $wasteLog->execute([(int)$it['menu_item_id'], $userId, $qty, "Waste from failed COD order #{$order['order_no']}"]);
                        }
                    } else {
                        $restock = $conn->prepare("UPDATE menu_items SET availability = CASE WHEN is_archived = 0 AND availability = 'Unavailable' AND (stock + ?) > 0 THEN 'Available' ELSE availability END, stock = stock + ? WHERE id = ?");
                        $stockLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Stock In', ?, ?)");
                        foreach ($items as $it) {
                            $qty = (int)$it['qty'];
                            if ($qty < 1) continue;
                            $restock->execute([$qty, $qty, (int)$it['menu_item_id']]);
                            $stockLog->execute([(int)$it['menu_item_id'], $userId, $qty, "Returned stock from failed COD order #{$order['order_no']}"]);
                        }
                    }
                }

                if ($giveBackBenefit) {
                    $conn->prepare("DELETE FROM first_order_benefit_hashes WHERE used_order_id = ?")->execute([$orderId]);
                }

                log_audit($conn, 'cod_delivery_failed', 'order', (string)$orderId, "Marked as failed delivery: $reason");
            }

            $conn->commit();
            $_SESSION['flash_msg'] = "Order marked as Failed COD. Customer fail counter updated.";
            $_SESSION['flash_msg_type'] = 'warning';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = "Error: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/cod_approvals.php');
    }

    // 5. Cashier Confirms Handover Cash (Requirement 6.4)
    if ($action === 'confirm_handover') {
        $handoverId = (int)($_POST['handover_id'] ?? 0);
        try {
            if (!$cashierShiftId || $handoverId < 1) throw new RuntimeException('An active open cashier shift is required to confirm a pending handover.');
            $conn->beginTransaction();
            $handoverStmt = $conn->prepare('SELECT * FROM cod_cash_handovers WHERE id = ? FOR UPDATE');
            $handoverStmt->execute([$handoverId]);
            $h = $handoverStmt->fetch();
            if (!$h || $h['status'] !== 'pending') throw new RuntimeException('This handover was already confirmed or does not exist.');
            $paymentStmt = $conn->prepare('SELECT id, payment_method, payment_status, shift_id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([(int)$h['order_id']]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Paid' || $payment['shift_id'] !== null) throw new RuntimeException('COD payment is already reconciled or invalid.');
            $conn->prepare("UPDATE cod_cash_handovers SET status = 'confirmed', confirmed_by_cashier_id = ?, cashier_shift_id = ?, confirmed_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$shiftOwnerId, $cashierShiftId, $handoverId]);
            $conn->prepare('UPDATE payments SET shift_id = ? WHERE id = ?')->execute([$cashierShiftId, (int)$payment['id']]);
            log_audit($conn, 'cod_handover_confirmed', 'shift', (string)$cashierShiftId, "Confirmed ₱{$h['amount_collected']} handover for order #{$h['order_id']} into shift #{$cashierShiftId}");
            $conn->commit();
            $_SESSION['flash_msg'] = 'Cash handover confirmed and credited to active shift #' . $cashierShiftId . '.';
            $_SESSION['flash_msg_type'] = 'success';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/cod_approvals.php');
    }
}

// Pending COD Approvals
$pendingCod = $conn->query("
    SELECT o.*, u.cod_fail_count, u.cod_blocked,
           EXISTS(SELECT 1 FROM transaction_requests tr WHERE tr.order_id = o.id AND tr.request_type IN ('Cancel Order', 'Cancel Delivery') AND tr.status = 'Pending') AS cancel_requested,
           (SELECT tr.reason FROM transaction_requests tr WHERE tr.order_id = o.id AND tr.request_type IN ('Cancel Order', 'Cancel Delivery') AND tr.status = 'Pending' ORDER BY tr.id DESC LIMIT 1) AS cancel_reason_text
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    WHERE o.order_channel = 'ONLINE'
      AND o.cod_approval_status = 'Pending'
      AND o.status = 'Pending'
    ORDER BY o.created_at ASC
")->fetchAll();

// Active Dispatched / Ready COD Orders (Awaiting Cash Collection or Failed Resolution)
$dispatchedCod = $conn->query("
    SELECT o.*, COALESCE(u.full_name, d.rider_name) AS delivering_staff_name, d.status AS dispatch_status, d.notes AS dispatch_notes
    FROM orders o
    LEFT JOIN users u ON u.id = o.delivered_by_user_id
    JOIN delivery_dispatches d ON d.order_id = o.id
    WHERE o.order_channel = 'ONLINE'
      AND o.cod_approval_status = 'Approved'
      AND o.status = 'Ready'
      AND o.cash_collected IS NULL
      AND d.status IN ('Out for Delivery', 'Failed')
    ORDER BY o.created_at ASC
")->fetchAll();

// Pending Cash Handovers to Cashier
$pendingHandovers = $conn->query("
    SELECT cch.*, o.order_no, u.full_name AS staff_name
    FROM cod_cash_handovers cch
    JOIN orders o ON o.id = cch.order_id
    JOIN users u ON u.id = cch.collected_by_user_id
    WHERE cch.status = 'pending'
    ORDER BY cch.collected_at ASC
")->fetchAll();

// Fetch ordered items for Pending & Dispatched COD cards
$codOrderItemsMap = [];
$allCodIds = array_values(array_unique(array_filter(array_map(
    static fn($r) => (int)($r['id'] ?? 0),
    array_merge($pendingCod, $dispatchedCod)
))));
if (!empty($allCodIds)) {
    $placeholders = implode(',', array_fill(0, count($allCodIds), '?'));
    $oiStmt = $conn->prepare("SELECT order_id, item_name, quantity, notes FROM order_items WHERE order_id IN ($placeholders) ORDER BY id ASC");
    $oiStmt->execute($allCodIds);
    foreach ($oiStmt->fetchAll() as $oiRow) {
        $oidKey = (int)$oiRow['order_id'];
        $codOrderItemsMap[$oidKey][] = $oiRow;
    }
}

include ROOT_PATH . '/includes/header.php';
?>

<div class="cod-page">
<div class="page-head card cod-hero-card">
    <div class="cod-hero-main">
        <span class="cod-hero-icon" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
        <div>
            <h1>COD Approvals &amp; Cash Handover</h1>
            <p class="muted">Review cash-on-delivery orders, check change readiness, and confirm collected cash into shifts.</p>
        </div>
    </div>
    <div class="cod-hero-metrics">
        <span class="cod-metric-pill is-gold">
            Pending Approvals <strong><?= count($pendingCod) ?></strong>
        </span>
        <span class="cod-metric-pill">
            Awaiting Cash <strong><?= count($dispatchedCod) ?></strong>
        </span>
        <?php if (!empty($pendingHandovers)): ?>
        <span class="cod-metric-pill is-amber">
            Cash Handovers <strong><?= count($pendingHandovers) ?></strong>
        </span>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'error' ? 'danger' : ($msg_type === 'warning' ? 'warning' : 'success') ?>" style="margin-bottom:16px;">
    <?= e($msg) ?>
</div>
<?php endif; ?>

<!-- Section 1: Pending Cash Handovers (For Cashiers) -->
<?php if (!empty($pendingHandovers)): ?>
<section class="card" style="margin-bottom:24px; border:2px solid #f59e0b; padding:18px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:16px; color:#66500d;"><i class="bi bi-arrow-left-right"></i> Pending Cash Handovers (Awaiting Cashier Confirmation)</h3>
        <span class="badge badge-warning"><?= count($pendingHandovers) ?> Unconfirmed</span>
    </div>
    <p class="muted" style="font-size:14px; margin-bottom:12px;">Delivery staff who collected COD cash must hand over money to an active cashier. Once confirmed, cash enters your current shift balance.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Collected By</th>
                    <th>Amount Collected</th>
                    <th>Collected Time</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingHandovers as $ph): ?>
                <tr>
                    <td><strong><?= e($ph['order_no']) ?></strong></td>
                    <td><?= e($ph['staff_name']) ?></td>
                    <td><strong style="color:var(--success,#10b981);">₱<?= number_format((float)$ph['amount_collected'], 2) ?></strong></td>
                    <td><?= date('M d, h:i A', strtotime($ph['collected_at'])) ?></td>
                    <td style="text-align:right;">
                        <?php if ($cashierShiftId): ?>
                        <form method="POST" style="margin:0; display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="confirm_handover">
                            <input type="hidden" name="handover_id" value="<?= $ph['id'] ?>">
                            <button type="submit" class="btn btn-primary" style="font-size:14px; padding:5px 12px;" data-confirm="Confirm receipt of ₱<?= number_format((float)$ph['amount_collected'], 2) ?> into shift #<?= $cashierShiftId ?>?">
                                <i class="bi bi-check2"></i> Confirm into Shift #<?= $cashierShiftId ?>
                            </button>
                        </form>
                        <?php else: ?>
                            <small class="muted">Open cashier shift required</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- Section 2: Pending COD Approvals Queue -->
<div class="cod-section-head">
    <div>
        <h2 class="cod-section-title"><i class="bi bi-hourglass-split"></i> Awaiting approval</h2>
        <p class="cod-section-description">Review customer details, items, and change requirements before sending orders to the kitchen.</p>
    </div>
</div>
<?php if (empty($pendingCod)): ?>
<div class="card cod-empty-card">
    <p class="muted" style="margin:0;"><span class="cod-empty-icon"><i class="bi bi-check2-circle"></i></span><strong class="cod-empty-title">No orders awaiting approval</strong>New cash-on-delivery requests will appear here.</p>
</div>
<?php else: ?>
<div class="kk-queue-toolbar" id="codQueueToolbar" style="margin-bottom:16px;">
    <div class="kk-queue-pills" id="codFilterPills" role="group" aria-label="Filter COD approvals">
        <button type="button" class="kk-queue-pill active" data-cod-filter="all">All</button>
        <button type="button" class="kk-queue-pill" data-cod-filter="change">Needs Change</button>
        <button type="button" class="kk-queue-pill" data-cod-filter="exact">Exact Amount</button>
        <button type="button" class="kk-queue-pill" data-cod-filter="risk">Risk Warning</button>
    </div>
    <label class="kk-queue-sort-wrap">
        <span>Prioritize</span>
        <select class="kk-queue-sort" id="codSortSelect" aria-label="Prioritize COD approvals">
            <option value="fifo">First in, first out</option>
            <option value="newest">Newest first</option>
            <option value="highest">Highest amount (₱)</option>
            <option value="fewest">Fewest items first</option>
        </select>
    </label>
</div>
<div id="codFilterEmptyState" class="card cod-empty-card" style="display:none; margin-bottom:20px;">
    <p class="muted" style="margin:0;"><strong class="cod-empty-title" style="margin-bottom:4px;">No matching COD requests</strong>Try selecting another filter pill above.</p>
</div>
<div id="codPendingGrid" class="cod-cards-grid" style="margin-bottom:30px;">
    <?php foreach ($pendingCod as $ord):
        $changeNeeded = ((float)$ord['change_for'] > 0) ? max(0.0, (float)$ord['change_for'] - (float)$ord['total']) : 0.0;
        $needsChange = ((float)$ord['change_for'] > 0 && $changeNeeded > 0);
        $isOutsideCod = strtoupper((string)($ord['fulfillment_mode'] ?? '')) === 'OUTSIDE';
        $codCoverage = $isOutsideCod ? 'outside' : 'within';
        $codCreatedTs = strtotime((string)($ord['created_at'] ?? '')) ?: 0;
        $waitMins = $codCreatedTs > 0 ? max(0, (int)floor((time() - $codCreatedTs) / 60)) : 0;
        $waitLabel = $waitMins >= 60 ? ((int)floor($waitMins / 60) . 'h ' . ($waitMins % 60) . 'm ago') : ($waitMins . 'm ago');
        $ordItems = $codOrderItemsMap[(int)$ord['id']] ?? [];
        $ordItemCount = 0;
        foreach ($ordItems as $oi) {
            $ordItemCount += max(1, (int)($oi['quantity'] ?? 1));
        }
        $ordItemCount = max(1, $ordItemCount);
        $hasRisk = ((int)($ord['cod_fail_count'] ?? 0) > 0);
        $hasCancelRequest = !empty($ord['cancel_requested']);
        $coverageText = $isOutsideCod ? 'Outside Lagro · Third-Party' : 'Within Lagro · Store Rider';
    ?>
    <section class="card cod-ticket-card cod-pending-card<?= $hasCancelRequest ? ' cod-ticket-cancel-pending' : '' ?>"
        data-coverage="<?= e($codCoverage) ?>"
        data-needs-change="<?= $needsChange ? '1' : '0' ?>"
        data-risk="<?= $hasRisk ? '1' : '0' ?>"
        data-created-ts="<?= (int)$codCreatedTs ?>"
        data-items="<?= (int)$ordItemCount ?>"
        data-total="<?= e((string)((float)$ord['total'])) ?>"
        <?= $hasCancelRequest ? 'style="border:1.5px solid #ef4444;"' : '' ?>>
        <div>
            <div class="cod-ticket-strip">
                <span><i class="bi bi-cash-coin" aria-hidden="true"></i> <?= e($coverageText) ?></span>
                <span class="cod-wait-chip"><i class="bi bi-clock" aria-hidden="true"></i> <?= e($waitLabel) ?></span>
            </div>

            <div class="cod-ticket-head">
                <div>
                    <h3 class="cod-ticket-no"><?= e($ord['order_no']) ?></h3>
                    <?php if (!empty($ord['queue_no'])): ?>
                    <span class="cod-ticket-queue">Queue #<?= e($ord['queue_no']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="cod-ticket-amount">
                    <strong>₱<?= number_format((float)$ord['total'], 2) ?></strong>
                    <small>Food ₱<?= number_format((float)$ord['subtotal'], 2) ?> + Fee ₱<?= number_format((float)$ord['delivery_fee'], 2) ?></small>
                </div>
            </div>

            <div class="cod-info-box">
                <div class="cod-info-line"><i class="bi bi-person-fill" aria-hidden="true"></i> <span>Customer: <strong><?= e($ord['customer_name']) ?></strong></span></div>
                <div class="cod-info-line"><i class="bi bi-telephone-fill" aria-hidden="true"></i> <span>Phone: <strong><?= e($ord['customer_phone']) ?></strong></span></div>
                <?php if ($ord['delivery_address']): ?>
                <div class="cod-info-line"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> <span><?= e($ord['delivery_address']) ?></span></div>
                <?php endif; ?>
                <?php if ($ord['landmark']): ?>
                <div class="cod-info-line"><i class="bi bi-signpost-2-fill" aria-hidden="true"></i> <span>Landmark: <em><?= e($ord['landmark']) ?></em></span></div>
                <?php endif; ?>

                <div class="cod-change-row">
                    <?php if ((float)$ord['change_for'] > 0): ?>
                        <span class="cod-change-pill is-prepare"><i class="bi bi-cash-stack" aria-hidden="true"></i> Bill ₱<?= number_format((float)$ord['change_for'], 2) ?> · Prepare Change: ₱<?= number_format($changeNeeded, 2) ?></span>
                    <?php else: ?>
                        <span class="cod-change-pill is-exact"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Exact Amount (No change needed)</span>
                    <?php endif; ?>
                </div>

                <?php if ($hasCancelRequest): ?>
                <div style="background:#fee2e2; color:#991b1b; padding:10px 12px; border-radius:8px; font-size:12.5px; font-weight:700; margin-top:10px; line-height:1.45;">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i> CUSTOMER REQUESTED CANCELLATION: Approval is blocked until this request is reviewed.
                    <?php if (!empty($ord['cancel_reason_text'])): ?>
                        <div style="font-weight:500; margin-top:4px;">Reason: <?= e((string)$ord['cancel_reason_text']) ?></div>
                    <?php endif; ?>
                    <div style="margin-top:6px;"><a href="<?= e(BASE_URL . '/modules/transactions/index.php') ?>" style="color:#7f1d1d; text-decoration:underline;">Review in Order Requests →</a></div>
                </div>
                <?php endif; ?>

                <?php if ((int)$ord['cod_fail_count'] > 0): ?>
                <div class="cod-risk-alert">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Customer has <?= (int)$ord['cod_fail_count'] ?> past failed COD deliver<?= (int)$ord['cod_fail_count'] === 1 ? 'y' : 'ies' ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($ordItems)): ?>
            <div class="cod-items-box">
                <div class="cod-items-head">
                    <span>Order Items</span>
                    <strong><?= $ordItemCount ?> item<?= $ordItemCount === 1 ? '' : 's' ?></strong>
                </div>
                <ul class="cod-items-list">
                    <?php foreach (array_slice($ordItems, 0, 4) as $oi): ?>
                    <li><span class="cod-item-qty"><?= (int)$oi['quantity'] ?>x</span> <span><?= e($oi['item_name']) ?></span></li>
                    <?php endforeach; ?>
                    <?php if (count($ordItems) > 4): ?>
                    <li class="muted" style="font-size:12px;">+<?= count($ordItems) - 4 ?> more item(s)…</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST" class="cod-ticket-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">

            <?php if (!$isOutsideCod): ?>
            <div class="cod-outside-note">
                <i class="bi bi-bicycle" aria-hidden="true"></i>
                <span>Store rider handoff is managed in <strong>Delivery Status</strong> after Kitchen prep.</span>
            </div>
            <?php else: ?>
            <div class="cod-outside-note">
                <i class="bi bi-box-seam" aria-hidden="true"></i>
                <span>Third-party courier booking is managed in <strong>Delivery Status</strong> after approval.</span>
            </div>
            <?php endif; ?>

            <div class="cod-ticket-actions">
                <button type="button" class="btn cod-btn-decline" onclick="openDeclineModal(<?= $ord['id'] ?>, '<?= e($ord['order_no']) ?>')">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> Decline
                </button>
                <?php if ($hasCancelRequest): ?>
                <a href="<?= e(BASE_URL . '/modules/transactions/index.php') ?>" class="btn cod-btn-approve" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; text-decoration:none;">
                    <i class="bi bi-slash-circle-fill" aria-hidden="true"></i> Review Cancellation
                </a>
                <?php else: ?>
                <button type="submit" name="action" value="approve_cod" class="btn cod-btn-approve">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Approve &amp; Cook
                </button>
                <?php endif; ?>
            </div>
        </form>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Section 3: Dispatched Orders Awaiting Cash Collection -->
<div class="cod-section-head">
    <div>
        <h2 class="cod-section-title"><i class="bi bi-bicycle"></i> Awaiting cash collection</h2>
        <p class="cod-section-description">Track dispatched orders, record collected cash, or resolve a failed delivery.</p>
    </div>
</div>
<?php if (empty($dispatchedCod)): ?>
<div class="card cod-empty-card">
    <p class="muted" style="margin:0;"><span class="cod-empty-icon"><i class="bi bi-cash-stack"></i></span><strong class="cod-empty-title">No cash collections pending</strong>Dispatched COD orders will appear here when cash needs to be recorded.</p>
</div>
<?php else: ?>
<div class="cod-cards-grid">
    <?php foreach ($dispatchedCod as $ord):
        $changeNeeded = ((float)$ord['change_for'] > 0) ? max(0.0, (float)$ord['change_for'] - (float)$ord['total']) : 0.0;
        $isFailedDispatch = ($ord['dispatch_status'] ?? '') === 'Failed';
        $isOutsideDisp = strtoupper((string)($ord['fulfillment_mode'] ?? '')) === 'OUTSIDE';
    ?>
    <section class="card cod-ticket-card <?= $isFailedDispatch ? 'is-danger-strip' : 'is-success-strip' ?>">
        <div>
            <div class="cod-ticket-strip">
                <span><i class="bi bi-<?= $isFailedDispatch ? 'exclamation-triangle' : 'truck' ?>" aria-hidden="true"></i> <?= e($ord['dispatch_status'] ?? $ord['status']) ?> · <?= $isOutsideDisp ? 'Outside Lagro' : 'Within Lagro' ?></span>
                <?php if (!empty($ord['queue_no'])): ?><span class="cod-ticket-queue">Queue #<?= e($ord['queue_no']) ?></span><?php endif; ?>
            </div>
            <div class="cod-ticket-head">
                <div>
                    <h3 class="cod-ticket-no"><?= e($ord['order_no']) ?></h3>
                </div>
                <div class="cod-ticket-amount">
                    <strong>₱<?= number_format((float)$ord['total'], 2) ?></strong>
                    <small>Expected Cash</small>
                </div>
            </div>

            <div class="cod-info-box">
                <div class="cod-info-line"><i class="bi bi-person-fill" aria-hidden="true"></i> <span><strong><?= e($ord['customer_name']) ?></strong> (<?= e($ord['customer_phone']) ?>)</span></div>
                <div class="cod-info-line"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> <span><?= e($ord['delivery_address'] ?: 'Pickup at counter') ?></span></div>
                <?php if ($ord['delivering_staff_name']): ?>
                <div class="cod-info-line"><i class="bi bi-bicycle" aria-hidden="true"></i> <span>Rider / Staff: <strong><?= e($ord['delivering_staff_name']) ?></strong></span></div>
                <?php endif; ?>
                <?php if (!empty($ord['dispatch_notes'])): ?>
                <div class="cod-info-line"><i class="bi bi-chat-left-text" aria-hidden="true"></i> <span><em><?= e($ord['dispatch_notes']) ?></em></span></div>
                <?php endif; ?>
                <div class="cod-change-row">
                    <?php if ((float)$ord['change_for'] > 0): ?>
                        <span class="cod-change-pill is-prepare"><i class="bi bi-cash-stack" aria-hidden="true"></i> Collect ₱<?= number_format((float)$ord['total'], 2) ?> (Bill ₱<?= number_format((float)$ord['change_for'], 2) ?>, Change ₱<?= number_format($changeNeeded, 2) ?>)</span>
                    <?php else: ?>
                        <span class="cod-change-pill is-exact"><i class="bi bi-cash-coin" aria-hidden="true"></i> Expected Cash: ₱<?= number_format((float)$ord['total'], 2) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="cod-ticket-actions">
            <button type="button" class="btn cod-btn-decline" onclick="openFailedModal(<?= $ord['id'] ?>, '<?= e($ord['order_no']) ?>')">
                <i class="bi bi-x-octagon" aria-hidden="true"></i> Failed / No-Show
            </button>
            <?php if (($ord['dispatch_status'] ?? '') === 'Out for Delivery'): ?>
            <button type="button" class="btn cod-btn-approve" onclick="openCollectModal(<?= $ord['id'] ?>, '<?= e($ord['order_no']) ?>', <?= (float)$ord['total'] ?>)">
                <i class="bi bi-cash-stack" aria-hidden="true"></i> Cash Collected
            </button>
            <?php else: ?>
            <a class="btn btn-secondary" style="flex:1; font-size:13.5px; text-align:center;" href="<?= BASE_URL ?>/modules/delivery/index.php">
                <i class="bi bi-truck" aria-hidden="true"></i> Reassign in Dispatch
            </a>
            <?php endif; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal: Decline COD -->
<div id="declineModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:440px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;" id="declineModalTitle">Decline COD Order</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="decline_cod">
            <input type="hidden" name="order_id" id="decline_order_id">
            <div style="margin-bottom:16px;">
                <label style="font-size:14px; font-weight:700;">Reason for declining:</label>
                <input type="text" name="decline_reason" placeholder="e.g. Unreachable contact number, fake order" class="search-input" style="width:100%; padding:8px;" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('declineModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#dc2626; border-color:#dc2626;">Decline &amp; Restore Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Collect Cash -->
<div id="collectModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:440px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;" id="collectModalTitle">Record Cash Collected</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="cash_collected">
            <input type="hidden" name="order_id" id="collect_order_id">
            <div style="margin-bottom:16px;">
                <label style="font-size:14px; font-weight:700;">Actual Amount Received (₱):</label>
                <input type="number" step="0.5" name="amount_collected" id="collect_amount" class="search-input" style="width:100%; padding:8px;" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('collectModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save &amp; Complete Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Failed COD Delivery -->
<div id="failedModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:440px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;" id="failedModalTitle">Report Failed / Refused Delivery</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="cod_failed">
            <input type="hidden" name="order_id" id="failed_order_id">
            <div style="margin-bottom:12px;">
                <label style="font-size:14px; font-weight:700;">Reason:</label>
                <select name="failure_reason" class="search-input" style="width:100%; padding:8px;">
                    <option value="Customer unreachable / phone ringing only">Customer unreachable / phone ringing only</option>
                    <option value="Customer refused order upon arrival">Customer refused order upon arrival</option>
                    <option value="Incorrect / non-existent address provided">Incorrect / non-existent address provided</option>
                    <option value="Customer lacked money to pay">Customer lacked money to pay</option>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:14px; cursor:pointer;">
                    <input type="checkbox" name="treat_as_waste" value="1" checked>
                    <span>Record prepared food as Kitchen Waste (do not restore to stock)</span>
                </label>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:14px; cursor:pointer;">
                    <input type="checkbox" name="give_back_benefit" value="1">
                    <span>Give back first-order free delivery (only if not customer fault)</span>
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('failedModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#dc2626; border-color:#dc2626;">Confirm Failed Delivery</button>
            </div>
        </form>
    </div>
</div>

</div>
<style>

.cod-page .cod-hero-card { padding:20px 24px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; border:1px solid #e6dfd0; border-top:4px solid #f2c12e; background:#fffdf8; }
.cod-page .cod-hero-main { display:flex; align-items:center; gap:14px; flex:1 1 320px; min-width:0; }
.cod-page .cod-hero-icon { width:48px; height:48px; border-radius:14px; background:#fff3c4; border:1px solid #eed98a; color:#775600; display:grid; place-items:center; font-size:22px; flex-shrink:0; }
.cod-page .cod-hero-card h1 { font-size:23px; line-height:1.25; margin:0 0 4px; color:#1f2328; }
.cod-page .cod-hero-card p { font-size:13.5px; line-height:1.5; margin:0; color:#655c4c; }
.cod-page .cod-hero-metrics { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.cod-page .cod-metric-pill { display:inline-flex; align-items:center; gap:6px; padding:7px 12px; border-radius:999px; background:#ffffff; border:1px solid #e6dfd0; color:#4b4539; font-size:13px; font-weight:600; }
.cod-page .cod-metric-pill strong { color:#1f2328; font-size:14px; }
.cod-page .cod-metric-pill.is-gold { background:#fff5d6; border-color:#d9c582; color:#594113; }
.cod-page .cod-metric-pill.is-amber { background:#fef3c7; border-color:#f59e0b; color:#92400e; }

.cod-page .cod-section-head { margin:24px 0 12px; }
.cod-page .cod-section-title { display:flex; align-items:center; gap:9px; font-size:20px; margin:0 0 4px; color:#1f2328; }
.cod-page .cod-section-title i { font-size:18px; color:#93630c; }
.cod-page .cod-section-description { font-size:13.5px; line-height:1.5; color:#655c4c; margin:0; }
.cod-page .cod-empty-card { text-align:center; padding:32px 20px; margin-bottom:26px; background:#fffdf8; border:1px solid #e6dfd0; }
.cod-empty-icon { display:flex; align-items:center; justify-content:center; width:52px; height:52px; margin:0 auto 12px; background:#fff3c4; color:#775600; border-radius:14px; font-size:23px; }
.cod-empty-title { display:block; font-size:16.5px; color:#1f2328; margin-bottom:4px; }

.cod-cards-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 380px), 1fr)); gap:18px; }
.cod-page .card { border-radius:16px; box-shadow:0 3px 14px rgba(48,38,0,.04); }
.cod-page section.cod-ticket-card { display:flex; flex-direction:column; justify-content:space-between; overflow:hidden; padding:0 18px 18px; background:#fffdf8; border:1px solid #e6dfd0; border-top:4px solid #f2c12e; overflow-wrap:anywhere; }
.cod-page section.cod-ticket-card.is-success-strip { border-top-color:#3c8d5d; }
.cod-page section.cod-ticket-card.is-danger-strip { border-top-color:#dc2626; }
.cod-ticket-strip { margin:0 -18px 14px; padding:9px 18px; background:#fff8df; border-bottom:1px solid #f0e6c5; display:flex; justify-content:space-between; align-items:center; gap:10px; font-size:11.5px; font-weight:800; color:#756022; text-transform:uppercase; letter-spacing:.5px; }
.is-success-strip .cod-ticket-strip { background:#edf7ef; border-bottom-color:#cde8d4; color:#28603c; }
.is-danger-strip .cod-ticket-strip { background:#fef2f2; border-bottom-color:#fecaca; color:#991b1b; }
.cod-wait-chip { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; background:#fff3c4; border:1px solid #eed98a; color:#775600; font-size:11px; font-weight:800; text-transform:none; letter-spacing:0; white-space:nowrap; }
.cod-ticket-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px; }
.cod-ticket-no { margin:0 0 2px; font-size:17px; font-weight:800; color:#1f2328; }
.cod-ticket-queue { font-size:12px; font-weight:700; color:#655c4c; }
.cod-ticket-amount { text-align:right; }
.cod-ticket-amount strong { display:block; font-size:19px; font-weight:800; color:#1f2328; line-height:1.15; }
.cod-ticket-amount small { display:block; margin-top:2px; font-size:12px; color:#655c4c; }

.cod-info-box { background:#fcfbf7; border:1px solid #ece6d8; border-radius:12px; padding:11px 13px; margin-bottom:12px; font-size:13.5px; line-height:1.5; color:#29251f; display:flex; flex-direction:column; gap:4px; }
.cod-info-line { display:flex; align-items:flex-start; gap:8px; }
.cod-info-line i { color:#866018; font-size:13px; margin-top:3px; flex-shrink:0; }
.cod-change-row { margin-top:6px; padding-top:8px; border-top:1px dashed #e2d9c2; }
.cod-change-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:12.5px; font-weight:700; }
.cod-change-pill.is-exact { background:#edf7ef; border:1px solid #cde8d4; color:#1e6a3d; }
.cod-change-pill.is-prepare { background:#fff3c4; border:1px solid #eed98a; color:#694c00; }
.cod-risk-alert { margin-top:6px; background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:6px 10px; border-radius:8px; font-size:12.5px; font-weight:700; display:flex; align-items:center; gap:6px; }

.cod-items-box { background:#ffffff; border:1px solid #ece6d8; border-radius:12px; padding:10px 12px; margin-bottom:12px; }
.cod-items-head { display:flex; justify-content:space-between; align-items:center; font-size:11px; text-transform:uppercase; letter-spacing:.8px; font-weight:700; color:#7a705d; padding-bottom:6px; border-bottom:1px dashed #e6dfd0; margin-bottom:6px; }
.cod-items-head strong { text-transform:none; letter-spacing:0; color:#594113; }
.cod-items-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:5px; font-size:13px; font-weight:600; color:#1f2328; }
.cod-items-list li { display:flex; align-items:center; gap:7px; }
.cod-item-qty { min-width:26px; padding:1px 6px; border-radius:6px; background:#fff2bf; color:#594113; font-size:12px; font-weight:800; text-align:center; }

.cod-ticket-form { margin-top:auto; }
.cod-rider-field { margin-bottom:12px; }
.cod-rider-field label { display:block; font-size:12.5px; font-weight:700; color:#594113; margin-bottom:5px; }
.cod-rider-select { width:100%; min-height:40px; height:auto; padding:8px 12px; line-height:1.3; font-size:13.5px; font-weight:600; color:#1f2328; background:#ffffff; border:1px solid #dfd8c8; border-radius:10px; cursor:pointer; }
.cod-rider-select:focus { outline:none; border-color:#d6a81f; box-shadow:0 0 0 3px rgba(242,193,46,.2); }
.cod-outside-note { margin-bottom:12px; padding:9px 11px; border-radius:10px; background:#fffaf0; border:1px dashed #e5d5a7; color:#5e5440; font-size:12.5px; line-height:1.45; display:flex; align-items:flex-start; gap:8px; }
.cod-outside-note i { color:#866018; margin-top:2px; flex-shrink:0; }

.cod-ticket-actions { display:flex; gap:8px; margin-top:auto; }
.cod-page .btn { min-height:40px; border-radius:10px; font-weight:700; }
.cod-btn-decline { flex:1; background:#fff5f4; color:#b42318; border:1px solid #efc8c4; font-size:13.5px; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
.cod-btn-decline:hover { background:#fee4e2; border-color:#db8d86; }
.cod-btn-approve { flex:1.25; background:#f2c12e; color:#1f2328; border:1px solid #dfaf1e; font-size:13.5px; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
.cod-btn-approve:hover { background:#e5b420; border-color:#c69814; }
.cod-page .btn-primary:not([style*="background:#dc2626"]) { background:#f2c12e; border-color:#dfaf1e; color:#1f2328; }
.cod-page .btn-primary[style*="background:#dc2626"] { color:#fff; }
.cod-page .search-input { min-height:42px; height:auto; border:1px solid #dfd8c8; border-radius:10px; padding:8px 12px; line-height:1.3; margin-top:6px; }
.cod-page .search-input:focus { outline:2px solid #b48b12; outline-offset:2px; }
.cod-page .modal-overlay > div { border-radius:18px!important; border-top:4px solid #f2c12e; background:#fffdf8!important; padding:26px!important; max-height:90vh; overflow:auto; }
.cod-page .modal-overlay h3 { font-size:21px; line-height:1.3; color:#1f2328; }
.cod-page .modal-overlay label { line-height:1.5; }
.cod-page input[type=checkbox] { accent-color:#d8a900; flex-shrink:0; }
.cod-page table th, .cod-page table td { padding:14px 16px; font-size:14px; line-height:1.5; }
@media(max-width:600px) { .cod-page .cod-hero-card { padding:18px; } .cod-page .cod-hero-card h1 { font-size:20px; } .cod-page .cod-section-title { font-size:19px; } }

</style>
<script>
function openDeclineModal(id, no) {
    document.getElementById('decline_order_id').value = id;
    document.getElementById('declineModalTitle').textContent = 'Decline ' + no;
    document.getElementById('declineModal').style.display = 'flex';
}
function openCollectModal(id, no, total) {
    document.getElementById('collect_order_id').value = id;
    document.getElementById('collectModalTitle').textContent = 'Collect Cash for ' + no;
    document.getElementById('collect_amount').value = total;
    document.getElementById('collectModal').style.display = 'flex';
}
function openFailedModal(id, no) {
    document.getElementById('failed_order_id').value = id;
    document.getElementById('failedModalTitle').textContent = 'Failed Delivery: ' + no;
    document.getElementById('failedModal').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
(function initCodQueueControls() {
    const grid = document.getElementById('codPendingGrid');
    const pillsEl = document.getElementById('codFilterPills');
    const sortEl = document.getElementById('codSortSelect');
    const emptyEl = document.getElementById('codFilterEmptyState');
    if (!grid) return;

    let codFilter = localStorage.getItem('kk_cod_filter') || 'all';
    if (!['all', 'change', 'exact', 'risk'].includes(codFilter)) {
        codFilter = 'all';
        localStorage.setItem('kk_cod_filter', 'all');
    }
    let codSort = localStorage.getItem('kk_cod_sort') || 'fifo';

    function applyCodFilterAndSort() {
        const cards = Array.from(grid.querySelectorAll('.cod-pending-card'));
        cards.sort((a, b) => {
            const ca = Number(a.dataset.createdTs || 0);
            const cb = Number(b.dataset.createdTs || 0);
            if (codSort === 'newest') return cb - ca;
            if (codSort === 'highest') {
                return (Number(b.dataset.total || 0) - Number(a.dataset.total || 0)) || (ca - cb);
            }
            if (codSort === 'fewest') {
                return (Number(a.dataset.items || 1) - Number(b.dataset.items || 1)) || (ca - cb);
            }
            return ca - cb;
        });
        let visibleCount = 0;
        cards.forEach(card => {
            let show = true;
            if (codFilter === 'change') {
                show = card.dataset.needsChange === '1';
            } else if (codFilter === 'exact') {
                show = card.dataset.needsChange === '0';
            } else if (codFilter === 'risk') {
                show = card.dataset.risk === '1';
            }
            card.style.display = show ? 'flex' : 'none';
            if (show) visibleCount++;
            grid.appendChild(card);
        });
        if (emptyEl) emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    if (pillsEl) {
        pillsEl.querySelectorAll('[data-cod-filter]').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-cod-filter') === codFilter);
        });
        pillsEl.addEventListener('click', e => {
            const btn = e.target.closest('[data-cod-filter]');
            if (!btn) return;
            codFilter = btn.getAttribute('data-cod-filter') || 'all';
            localStorage.setItem('kk_cod_filter', codFilter);
            pillsEl.querySelectorAll('[data-cod-filter]').forEach(b => b.classList.toggle('active', b === btn));
            applyCodFilterAndSort();
        });
    }
    if (sortEl) {
        sortEl.value = codSort;
        sortEl.addEventListener('change', () => {
            codSort = sortEl.value || 'fifo';
            localStorage.setItem('kk_cod_sort', codSort);
            applyCodFilterAndSort();
        });
    }
    applyCodFilterAndSort();
})();
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
