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
        $deliveredBy = !empty($_POST['delivered_by_user_id']) ? (int)$_POST['delivered_by_user_id'] : null;
        try {
            if ($orderId < 1) throw new RuntimeException('Select a COD order.');
            $conn->beginTransaction();
            $orderStmt = $conn->prepare("SELECT id, order_no, queue_no, order_type, order_channel, status, cod_approval_status FROM orders WHERE id = ? FOR UPDATE");
            $orderStmt->execute([$orderId]);
            $ordInfo = $orderStmt->fetch();
            if (!$ordInfo || $ordInfo['order_channel'] !== 'ONLINE' || $ordInfo['order_type'] !== 'ONLINE' || $ordInfo['status'] !== 'Pending' || $ordInfo['cod_approval_status'] !== 'Pending') {
                throw new RuntimeException('This COD delivery is no longer awaiting approval.');
            }
            $paymentStmt = $conn->prepare('SELECT payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([$orderId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['payment_method'] !== 'Cash on Delivery' || $payment['payment_status'] !== 'Pending') {
                throw new RuntimeException('This delivery is no longer an unpaid COD order.');
            }
            if ($deliveredBy !== null) {
                $riderStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE u.id = ?
                      AND u.status = 'Active'
                      AND u.is_archived = 0
                      AND u.deleted_at IS NULL
                      AND r.role_name IN ('Admin', 'Cashier', 'Kitchen Staff', 'Inventory Staff')
                ");
                $riderStmt->execute([$deliveredBy]);
                if ((int)$riderStmt->fetchColumn() !== 1) throw new RuntimeException('Choose an active delivery staff member.');
            }
            $update = $conn->prepare("UPDATE orders SET cod_approval_status = 'Approved', cod_approved_by = ?, delivered_by_user_id = ?, kitchen_queued_at = NOW() WHERE id = ? AND status = 'Pending' AND cod_approval_status = 'Pending'");
            $update->execute([$userId, $deliveredBy, $orderId]);
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
                $restock = $conn->prepare("UPDATE menu_items SET availability = CASE WHEN availability = 'Unavailable' AND stock = 0 THEN 'Available' ELSE availability END, stock = stock + ? WHERE id = ?");
                $stockLog = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Stock In', ?, ?)");
                foreach ($itemsStmt->fetchAll() as $item) {
                    $qty = (int)$item['qty'];
                    if ($qty < 1) continue;
                    $restock->execute([$qty, (int)$item['menu_item_id']]);
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

// Active staff for delivery assignment
$activeStaff = $conn->query("
    SELECT u.id, u.full_name, u.username
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.status = 'Active'
      AND u.is_archived = 0
      AND u.deleted_at IS NULL
      AND r.role_name IN ('Admin', 'Cashier', 'Kitchen Staff', 'Inventory Staff')
    ORDER BY u.full_name ASC
")->fetchAll();

// Pending COD Approvals
$pendingCod = $conn->query("
    SELECT o.*, u.cod_fail_count, u.cod_blocked
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

include ROOT_PATH . '/includes/header.php';
?>

<div class="cod-page">
<div class="page-head card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="display:flex;align-items:center;gap:10px;">
            <i class="bi bi-cash-coin" style="color:var(--primary,#F2C12E);"></i> COD Approvals &amp; Cash Handover
        </h1>
        <p class="muted">Manage cash-on-delivery approval requests, track change requirements, and confirm collected cash into cashier shifts.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <span class="badge" style="background:#fef3c7; color:#66500d; padding:8px 12px; font-size:14px;">
            Pending Approvals: <strong><?= count($pendingCod) ?></strong>
        </span>
        <span class="badge" style="background:#f2f2ef; color:#343434; padding:8px 12px; font-size:14px;">
            Uncollected Dispatch: <strong><?= count($dispatchedCod) ?></strong>
        </span>
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
                            <button type="submit" class="btn btn-primary" style="font-size:14px; padding:5px 12px;" onclick="return confirm('Confirm receipt of ₱<?= number_format((float)$ph['amount_collected'], 2) ?> into shift #<?= $cashierShiftId ?>?');">
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
<h2 class="cod-section-title"><i class="bi bi-hourglass-split"></i> Awaiting approval</h2><p class="cod-section-description">Review customer details and change requirements before sending orders to the kitchen.</p>
<?php if (empty($pendingCod)): ?>
<div class="card" style="text-align:center; padding:32px 20px; margin-bottom:30px;">
    <p class="muted" style="margin:0;"><span class="cod-empty-icon"><i class="bi bi-check2-circle"></i></span><strong class="cod-empty-title">No orders awaiting approval</strong>New cash-on-delivery requests will appear here.</p>
</div>
<?php else: ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 360px), 1fr)); gap:20px; margin-bottom:30px;">
    <?php foreach ($pendingCod as $ord):
        $changeNeeded = ((float)$ord['change_for'] > 0) ? ((float)$ord['change_for'] - (float)$ord['total']) : 0;
    ?>
    <section class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
        <div>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <h3 style="margin:0 0 2px; font-size:17px;"><?= e($ord['order_no']) ?></h3>
                    <span class="badge" style="background:#fef3c7; color:#66500d;"><?= e($ord['fulfillment_mode']) ?></span>
                </div>
                <div style="text-align:right;">
                    <strong style="font-size:18px; color:var(--text);">₱<?= number_format((float)$ord['total'], 2) ?></strong>
                    <div style="font-size:13px; color:var(--muted);">(Food ₱<?= number_format((float)$ord['subtotal'], 2) ?> + Fee ₱<?= number_format((float)$ord['delivery_fee'], 2) ?>)</div>
                </div>
            </div>

            <!-- Customer Details & Anti-Abuse Risk Signals -->
            <div style="background:var(--soft); border-radius:8px; padding:10px 12px; margin-bottom:12px; font-size:14px; line-height:1.5;">
                <div>Customer: <strong><?= e($ord['customer_name']) ?></strong></div>
                <div>Phone: <strong><?= e($ord['customer_phone']) ?></strong></div>
                <?php if ($ord['delivery_address']): ?>
                <div>Address: <?= e($ord['delivery_address']) ?></div>
                <?php endif; ?>
                <?php if ($ord['landmark']): ?>
                <div>Landmark: <em><?= e($ord['landmark']) ?></em></div>
                <?php endif; ?>

                <!-- Change Calculation (Requirement 6.2) -->
                <div style="margin-top:6px; padding-top:6px; border-top:1px dashed #cbd5e1;">
                    <?php if ((float)$ord['change_for'] > 0): ?>
                        <div>Bill handed: <strong>₱<?= number_format((float)$ord['change_for'], 2) ?></strong></div>
                        <div style="color:#66500d; font-weight:700;">Prepare Change: ₱<?= number_format($changeNeeded, 2) ?></div>
                    <?php else: ?>
                        <div style="color:var(--success,#10b981); font-weight:700;"><i class="bi bi-check-circle"></i> Exact Amount (No change needed)</div>
                    <?php endif; ?>
                </div>

                <!-- Anti-Abuse History Badge (Requirement 6.6) -->
                <?php if ((int)$ord['cod_fail_count'] > 0): ?>
                <div style="margin-top:6px; background:#fee2e2; color:#991b1b; padding:4px 8px; border-radius:4px; font-size:13px; font-weight:700;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Warning: Customer has <?= $ord['cod_fail_count'] ?> past failed COD deliveries!
                </div>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">

            <?php if ($ord['fulfillment_mode'] === 'LAGRO' || ($ord['delivery_mode'] ?? 'LAGRO_FREE') === 'LAGRO_FREE'): ?>
            <div style="margin-bottom:10px;">
                <label style="font-size:14px; font-weight:700;">Assign Delivery Staff:</label>
                <select name="delivered_by_user_id" class="search-input" style="width:100%; padding:6px; font-size:14px;">
                    <option value="">-- Choose Staff Rider --</option>
                    <?php foreach ($activeStaff as $st): ?>
                        <option value="<?= $st['id'] ?>"><?= e($st['full_name']) ?> (@<?= e($st['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div style="display:flex; gap:8px;">
                <button type="button" class="btn btn-outline" style="flex:1; color:#dc2626; border-color:#fca5a5; font-size:14px;" onclick="openDeclineModal(<?= $ord['id'] ?>, '<?= e($ord['order_no']) ?>')">
                    Decline
                </button>
                <button type="submit" name="action" value="approve_cod" class="btn btn-primary" style="flex:1; font-size:14px;">
                    <i class="bi bi-check2"></i> Approve &amp; Cook
                </button>
            </div>
        </form>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Section 3: Dispatched Orders Awaiting Cash Collection -->
<h2 class="cod-section-title"><i class="bi bi-bicycle"></i> Awaiting cash collection</h2><p class="cod-section-description">Track dispatched orders, record collected cash, or resolve a failed delivery.</p>
<?php if (empty($dispatchedCod)): ?>
<div class="card" style="text-align:center; padding:32px 20px;">
    <p class="muted" style="margin:0;"><span class="cod-empty-icon"><i class="bi bi-cash-stack"></i></span><strong class="cod-empty-title">No cash collections pending</strong>Dispatched COD orders will appear here when cash needs to be recorded.</p>
</div>
<?php else: ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 360px), 1fr)); gap:20px;">
    <?php foreach ($dispatchedCod as $ord):
        $changeNeeded = ((float)$ord['change_for'] > 0) ? ((float)$ord['change_for'] - (float)$ord['total']) : 0;
    ?>
    <section class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
        <div>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <h3 style="margin:0 0 2px; font-size:17px;"><?= e($ord['order_no']) ?></h3>
                    <span class="badge <?= ($ord['dispatch_status'] ?? '') === 'Failed' ? 'badge-danger' : 'badge-info' ?>"><?= e($ord['dispatch_status'] ?? $ord['status']) ?> · <?= e($ord['fulfillment_mode']) ?></span>
                </div>
                <div style="text-align:right;">
                    <strong style="font-size:18px;">₱<?= number_format((float)$ord['total'], 2) ?></strong>
                </div>
            </div>

            <div style="background:var(--soft); border-radius:8px; padding:10px 12px; margin-bottom:12px; font-size:14px; line-height:1.5;">
                <div>Customer: <strong><?= e($ord['customer_name']) ?></strong> (<?= e($ord['customer_phone']) ?>)</div>
                <div>Address: <?= e($ord['delivery_address'] ?: 'Pickup at counter') ?></div>
                <?php if ($ord['delivering_staff_name']): ?>
                <div>Rider/Staff: <strong><?= e($ord['delivering_staff_name']) ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($ord['dispatch_notes'])): ?>
                <div>Dispatch Note: <em><?= e($ord['dispatch_notes']) ?></em></div>
                <?php endif; ?>
                <div style="margin-top:6px; font-weight:700; color:#66500d;">
                    Expected Cash: ₱<?= number_format((float)$ord['total'], 2) ?>
                    <?php if ((float)$ord['change_for'] > 0): ?>
                        (Bill: ₱<?= number_format((float)$ord['change_for'], 2) ?>, Change: ₱<?= number_format($changeNeeded, 2) ?>)
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:8px;">
            <button type="button" class="btn btn-outline" style="flex:1; color:#dc2626; border-color:#fca5a5; font-size:14px;" onclick="openFailedModal(<?= $ord['id'] ?>, '<?= e($ord['order_no']) ?>')">
                <i class="bi bi-x-octagon"></i> Failed / No-Show
            </button>
            <?php if (($ord['dispatch_status'] ?? '') === 'Out for Delivery'): ?>
            <button type="button" class="btn btn-primary" style="flex:1; font-size:14px;" onclick="openCollectModal(<?= $ord['id'] ?>, '<?= e($ord['order_no']) ?>', <?= (float)$ord['total'] ?>)">
                <i class="bi bi-cash-stack"></i> Cash Collected
            </button>
            <?php else: ?>
            <a class="btn btn-secondary" style="flex:1; font-size:14px; text-align:center;" href="<?= BASE_URL ?>/modules/delivery/index.php">
                <i class="bi bi-truck"></i> Reassign in Dispatch
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

.cod-page .page-head { padding:24px; }
.cod-page .page-head h1 { font-size:28px; line-height:1.3; margin:0 0 10px; }
.cod-page .page-head p { font-size:15px; line-height:1.6; margin:0; }
.cod-page .page-head > div:last-child { flex-wrap:wrap; }
.cod-page .page-head .badge { display:inline-flex; gap:6px; border-radius:9px; }
.cod-page .card { border-radius:16px; box-shadow:0 3px 14px rgba(0,0,0,.025); }
.cod-page .cod-section-title { display:flex; align-items:center; gap:10px; font-size:24px; margin:28px 0 6px; color:#242424; }
.cod-page .cod-section-title i { font-size:21px; }
.cod-page .cod-section-description { font-size:15px; line-height:1.6; color:#646464; margin:0 0 16px; }
.cod-empty-icon { display:flex; align-items:center; justify-content:center; width:56px; height:56px; margin:0 auto 14px; background:#fff3c4; color:#242424; border-radius:16px; font-size:26px; }
.cod-empty-title { display:block; font-size:18px; color:#242424; margin-bottom:6px; }
.cod-page section.card { padding:22px; overflow-wrap:anywhere; }
.cod-page .btn { min-height:42px; border-radius:9px; }
.cod-page .btn-primary:not([style*="background:#dc2626"]) { background:#f7c62f; border-color:#e8b720; color:#242424; }
.cod-page .btn-primary[style*="background:#dc2626"] { color:#fff; }
.cod-page .search-input { min-height:44px; border:1px solid #d7d7d2; border-radius:9px; margin-top:6px; }
.cod-page .search-input:focus { outline:2px solid #b48b12; outline-offset:2px; }
.cod-page .modal-overlay > div { border-radius:18px!important; padding:28px!important; max-height:90vh; overflow:auto; }
.cod-page .modal-overlay h3 { font-size:24px; line-height:1.3; }
.cod-page .modal-overlay label { line-height:1.5; }
.cod-page input[type=checkbox] { accent-color:#d8a900; flex-shrink:0; }
.cod-page table th, .cod-page table td { padding:14px 16px; font-size:14px; line-height:1.5; }
@media(max-width:600px) { .cod-page .page-head { padding:18px; } .cod-page .page-head h1 { font-size:24px; } .cod-page .cod-section-title { font-size:22px; } }

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
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
