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
                $conn->prepare("UPDATE payment_proofs SET verification_status = 'rejected', rejection_reason = COALESCE(NULLIF(rejection_reason, ''), 'Cancelled by customer request') WHERE order_id = ? AND verification_status = 'pending'")->execute([$orderId]);
                $conn->prepare("UPDATE orders SET payment_verification_status = CASE WHEN payment_verification_status = 'Awaiting Verification' THEN 'Rejected' ELSE payment_verification_status END, payment_auto_cancel_at = NULL WHERE id = ?")->execute([$orderId]);
                $conn->prepare("UPDATE payments SET payment_status = 'Failed', paid_at = NULL WHERE order_id = ? AND payment_status = 'Pending'")->execute([$orderId]);
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

$eligibleFilter = (string)($_GET['eligible_filter'] ?? 'all');
if (!in_array($eligibleFilter, ['all', 'pending_void', 'paid_refund', 'cancelled_paid'], true)) {
    $eligibleFilter = 'all';
}
$eligibleSearch = trim((string)($_GET['eligible_search'] ?? ''));

$perPage = 10;
$baseEligibleWhere = "(o.status = 'Pending' OR (o.status IN ('Served','Picked Up','Cancelled') AND p.payment_status = 'Paid') OR (o.status = 'Ready' AND o.order_channel = 'ONLINE' AND o.order_type = 'ONLINE' AND d.status = 'Failed' AND p.payment_method = 'Cash on Delivery' AND p.payment_status = 'Pending'))";
$whereParts = [$baseEligibleWhere];
$whereParams = [];

if ($eligibleFilter === 'pending_void') {
    $whereParts[] = "o.status = 'Pending'";
} elseif ($eligibleFilter === 'paid_refund') {
    $whereParts[] = "(o.status IN ('Served','Picked Up') AND p.payment_status = 'Paid')";
} elseif ($eligibleFilter === 'cancelled_paid') {
    $whereParts[] = "(o.status = 'Cancelled' AND p.payment_status = 'Paid')";
}
if ($eligibleSearch !== '') {
    $whereParts[] = "(o.order_no LIKE ? OR COALESCE(o.customer_name, '') LIKE ?)";
    $likeParam = '%' . $eligibleSearch . '%';
    $whereParams[] = $likeParam;
    $whereParams[] = $likeParam;
}
$eligibleWhereSql = implode(' AND ', $whereParts);

$countEligibleStmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN ($latestPayment) p ON p.order_id = o.id LEFT JOIN delivery_dispatches d ON d.order_id = o.id WHERE $eligibleWhereSql");
$countEligibleStmt->execute($whereParams);
$totalEligibleOrders = (int)$countEligibleStmt->fetchColumn();
$totalEligiblePages = max(1, (int)ceil($totalEligibleOrders / $perPage));
$eligiblePage = max(1, min((int)($_GET['eligible_page'] ?? 1), $totalEligiblePages));
$eligibleOffset = ($eligiblePage - 1) * $perPage;

$recentOrdersStmt = $conn->prepare("SELECT o.order_no, o.order_channel, o.order_type, o.customer_name, o.status, o.total, o.created_at, p.payment_method, p.payment_status, d.status AS dispatch_status FROM orders o LEFT JOIN ($latestPayment) p ON p.order_id = o.id LEFT JOIN delivery_dispatches d ON d.order_id = o.id WHERE $eligibleWhereSql ORDER BY o.id DESC LIMIT $perPage OFFSET $eligibleOffset");
$recentOrdersStmt->execute($whereParams);
$recentOrders = $recentOrdersStmt->fetchAll();

$pendingRequests = $conn->query("SELECT tr.*, o.order_no, o.order_channel, o.customer_name, o.status AS order_status, o.total, p.payment_method, p.payment_status, u.full_name AS requester FROM transaction_requests tr JOIN orders o ON o.id = tr.order_id LEFT JOIN ($latestPayment) p ON p.order_id = o.id JOIN users u ON u.id = tr.requested_by WHERE tr.status = 'Pending' ORDER BY tr.created_at ASC, tr.id ASC")->fetchAll();

$totalDecisions = (int)$conn->query("SELECT COUNT(*) FROM transaction_requests tr JOIN orders o ON o.id = tr.order_id JOIN users u ON u.id = tr.requested_by WHERE tr.status <> 'Pending'")->fetchColumn();
$totalDecisionsPages = max(1, (int)ceil($totalDecisions / $perPage));
$decisionsPage = max(1, min((int)($_GET['decisions_page'] ?? 1), $totalDecisionsPages));
$decisionsOffset = ($decisionsPage - 1) * $perPage;

$historyStmt = $conn->prepare("SELECT tr.*, o.order_no, o.order_channel, o.customer_name, u.full_name AS requester, reviewer.full_name AS reviewer FROM transaction_requests tr JOIN orders o ON o.id = tr.order_id JOIN users u ON u.id = tr.requested_by LEFT JOIN users reviewer ON reviewer.id = tr.reviewed_by WHERE tr.status <> 'Pending' ORDER BY tr.reviewed_at DESC, tr.id DESC LIMIT :limit OFFSET :offset");
$historyStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$historyStmt->bindValue(':offset', $decisionsOffset, PDO::PARAM_INT);
$historyStmt->execute();
$history = $historyStmt->fetchAll();

$buildTransactionsQuery = static function (array $overrides = []) use ($eligiblePage, $decisionsPage, $eligibleFilter, $eligibleSearch): string {
    $params = array_merge([
        'order_no' => trim((string)($_GET['order_no'] ?? '')),
        'request_type' => trim((string)($_GET['request_type'] ?? '')),
        'eligible_filter' => $eligibleFilter !== 'all' ? $eligibleFilter : null,
        'eligible_search' => $eligibleSearch !== '' ? $eligibleSearch : null,
        'eligible_page' => $eligiblePage,
        'decisions_page' => $decisionsPage,
    ], $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
};

include ROOT_PATH . '/includes/header.php';
?>
<style>
.kk-tx-workspace { display: flex; flex-direction: column; gap: 22px; }
.kk-tx-hero {
    background: #fffefb;
    border: 1px solid #e7dfd0;
    border-top: 3px solid #f5c430;
    border-radius: 16px;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
    box-shadow: 0 3px 14px rgba(24, 20, 12, 0.04);
}
.kk-tx-hero-main { display: flex; align-items: flex-start; gap: 15px; }
.kk-tx-hero-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: #fff4c7; color: #7a5800; border: 1px solid #efd67a;
    display: grid; place-items: center; font-size: 22px; flex-shrink: 0;
}
.kk-tx-hero h2 { margin: 0 0 4px; font-size: 21px; font-weight: 800; color: #1f2328; }
.kk-tx-hero p { margin: 0; font-size: 13.5px; color: #615a4d; max-width: 680px; line-height: 1.45; }
.kk-tx-hero-stats { display: flex; gap: 10px; flex-wrap: wrap; }
.kk-tx-stat-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700;
    background: #fcfaf3; border: 1px solid #e7dfd0; color: #3d372c;
}
.kk-tx-stat-pill.is-alert { background: #fff1f2; border-color: #fecdd3; color: #9f1239; }
.kk-tx-stat-pill.is-gold { background: #fff6d6; border-color: #ebd37f; color: #694c00; }

.kk-tx-card {
    background: #ffffff; border: 1px solid #e7e2d8; border-radius: 16px; padding: 22px 24px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.025);
}
.kk-tx-card-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.kk-tx-card-head h2 { margin: 0 0 4px; font-size: 18px; font-weight: 800; color: #1f2328; display: flex; align-items: center; gap: 8px; }
.kk-tx-card-head h2 i { color: #9c7100; }
.kk-tx-card-head p { margin: 0; font-size: 13px; color: #666053; }

.kk-tx-empty {
    text-align: center; padding: 30px 18px; border-radius: 12px;
    background: #fcfaf4; border: 1px dashed #e2d8c3; color: #676053;
}
.kk-tx-empty-icon {
    width: 44px; height: 44px; border-radius: 12px; margin: 0 auto 10px;
    background: #fff4c7; color: #8c6400; display: grid; place-items: center; font-size: 20px;
}
.kk-tx-empty strong { display: block; color: #24211b; margin-bottom: 4px; font-size: 14.5px; }

.kk-tx-pending-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 460px), 1fr)); gap: 16px; }
.kk-tx-req-item {
    border: 1px solid #e7decb; border-top: 3px solid #f2c12e; border-radius: 14px;
    background: #fffefc; padding: 16px 18px; display: flex; flex-direction: column; gap: 12px;
}
.kk-tx-req-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.kk-tx-req-order { font-size: 16px; font-weight: 800; color: #1f2328; margin: 0; }
.kk-tx-req-meta { font-size: 12.5px; color: #665f52; margin-top: 3px; }
.kk-tx-reason-box {
    background: #faf6eb; border: 1px solid #ebe2cc; border-radius: 10px;
    padding: 9px 12px; font-size: 13px; color: #2c271d; line-height: 1.45;
}
.kk-tx-review-panel {
    background: #ffffff; border: 1px solid #ece5d5; border-radius: 10px; padding: 12px;
    display: flex; flex-direction: column; gap: 10px;
}
.kk-tx-review-panel label.kk-check { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #2b271e; cursor: pointer; }
.kk-tx-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.kk-tx-actions .btn { min-height: 40px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 16px; border-radius: 9px; }

.kk-tx-split { display: grid; grid-template-columns: minmax(290px, 0.85fr) minmax(420px, 1.55fr); gap: 20px; align-items: start; }
@media (max-width: 1120px) { .kk-tx-split { grid-template-columns: 1fr; } }
.kk-tx-split table th, .kk-tx-split table td { padding: 10px 9px; font-size: 13px; }
.kk-tx-order-no { white-space: nowrap; color: #1f2328; font-size: 13.2px; }

.kk-tx-guide-list { display: flex; flex-direction: column; gap: 10px; margin-top: 6px; }
.kk-tx-guide-item {
    display: flex; align-items: flex-start; gap: 10px; padding: 11px 12px;
    background: #fcfaf4; border: 1px solid #ebe3d0; border-radius: 11px;
}
.kk-tx-guide-item i { color: #8d6500; font-size: 16px; margin-top: 2px; flex-shrink: 0; }
.kk-tx-guide-item strong { display: block; font-size: 13px; color: #232018; margin-bottom: 2px; }
.kk-tx-guide-item span { display: block; font-size: 12.2px; color: #635c4e; line-height: 1.42; }

.kk-tx-toolbar {
    display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center;
    margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid #f0ebe0;
}
.kk-tx-pills { display: flex; flex-wrap: wrap; gap: 6px; }
.kk-tx-pill {
    display: inline-flex; align-items: center; padding: 6px 12px; border-radius: 999px;
    font-size: 12.2px; font-weight: 700; border: 1px solid #e2dac8; background: #fffdf8; color: #4f483a;
    text-decoration: none; transition: background .15s, border-color .15s;
}
.kk-tx-pill:hover { background: #fff6d6; border-color: #e6bf38; color: #362800; }
.kk-tx-pill.active { background: #f2c12e; border-color: #dfb020; color: #1f1a0d; }
.kk-tx-search-form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.kk-tx-search-input {
    border: 1px solid #dcd4c3; border-radius: 8px; padding: 6px 10px; font-size: 12.5px;
    min-width: 205px; background: #ffffff; color: #1f2328;
}

.kk-tx-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px;
    border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap;
}
.kk-tx-badge.is-cancel { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.kk-tx-badge.is-refund { background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; }
.kk-tx-badge.is-void { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.kk-tx-badge.is-delivery { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
.kk-tx-badge.is-approved { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.kk-tx-badge.is-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.kk-tx-badge.is-neutral { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
.kk-tx-use-btn {
    padding: 4px 10px; font-size: 11.5px; font-weight: 700; border-radius: 7px;
    border: 1px solid #e5c354; background: #fff7d6; color: #5f4300; cursor: pointer;
}
.kk-tx-use-btn:hover { background: #f2c12e; color: #1f1a0d; }
</style>

<?php if ($flash): ?><div class="alert alert-<?= e($flashType) ?>" style="margin-bottom:18px"><?= e($flash) ?></div><?php endif; ?>

<div class="kk-tx-workspace">
    <section class="kk-tx-hero">
        <div class="kk-tx-hero-main">
            <div class="kk-tx-hero-icon"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></div>
            <div>
                <h2>Order Cancellations, Voids &amp; Refunds</h2>
                <p>Review customer order cancellations, cashier void/refund requests, and failed COD delivery resolutions for Kenji’s Kitchen POS and Online Ordering.</p>
            </div>
        </div>
        <div class="kk-tx-hero-stats">
            <span class="kk-tx-stat-pill <?= count($pendingRequests) > 0 ? 'is-alert' : '' ?>">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i> Pending: <?= count($pendingRequests) ?>
            </span>
            <span class="kk-tx-stat-pill is-gold">
                <i class="bi bi-receipt" aria-hidden="true"></i> Eligible Orders: <?= number_format($totalEligibleOrders) ?>
            </span>
            <span class="kk-tx-stat-pill">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Reviewed: <?= number_format($totalDecisions) ?>
            </span>
        </div>
    </section>

    <?php if ($isAdmin || $pendingRequests): ?>
    <section class="kk-tx-card" id="pendingRequests">
        <div class="kk-tx-card-head">
            <div>
                <h2><i class="bi bi-inbox-fill" aria-hidden="true"></i> Pending Order Requests (<?= count($pendingRequests) ?>)</h2>
                <p>Approve or reject pending customer cancellations and cashier refund/void requests before any payment confirmation proceeds.</p>
            </div>
        </div>
        <?php if (!$pendingRequests): ?>
        <div class="kk-tx-empty">
            <div class="kk-tx-empty-icon"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
            <strong>No pending order requests</strong>
            <span>New customer cancellation requests (e.g. GCash orders awaiting review) and cashier void/refund submissions will appear here.</span>
        </div>
        <?php else: ?>
        <div class="kk-tx-pending-list">
            <?php foreach ($pendingRequests as $request):
                $reqType = (string)$request['request_type'];
                $reqBadgeCls = $reqType === 'Cancel Order'
                    ? 'is-cancel'
                    : ($reqType === 'Refund' ? 'is-refund' : ($reqType === 'Cancel Delivery' ? 'is-delivery' : 'is-void'));
            ?>
            <article class="kk-tx-req-item">
                <div class="kk-tx-req-top">
                    <div>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px;">
                            <span class="kk-tx-badge <?= $reqBadgeCls ?>"><?= e($reqType) ?></span>
                            <span class="kk-tx-badge is-neutral"><?= e($request['order_channel'] ?? 'POS') ?></span>
                            <span class="muted" style="font-size:12px;">Request #<?= (int)$request['id'] ?></span>
                        </div>
                        <h3 class="kk-tx-req-order"><?= e($request['order_no']) ?></h3>
                        <div class="kk-tx-req-meta">
                            Requested by <strong><?= e($request['requester']) ?></strong>
                            <?php if (!empty($request['customer_name']) && $request['customer_name'] !== $request['requester']): ?>
                                · Customer: <?= e($request['customer_name']) ?>
                            <?php endif; ?>
                            · Order <?= e($request['order_status']) ?>
                            · <?= e($request['payment_method'] ?? '—') ?> / <?= e($request['payment_status'] ?? '—') ?>
                        </div>
                    </div>
                    <strong style="font-size:17px; color:#1f2328; white-space:nowrap;"><?= money($request['total']) ?></strong>
                </div>

                <div class="kk-tx-reason-box">
                    <strong>Reason:</strong> <?= e($request['reason']) ?>
                </div>

                <?php if ($isAdmin): ?>
                <form class="form" method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                    <div class="kk-tx-review-panel">
                        <div class="field" style="margin:0;">
                            <label>Review notes</label>
                            <input name="review_notes" maxlength="500" placeholder="Optional admin note for audit log...">
                        </div>
                        <?php if ($reqType === 'Cancel Order'): ?>
                        <p class="muted" style="margin:0; font-size:12.5px; line-height:1.45;">
                            <i class="bi bi-info-circle" aria-hidden="true"></i> Approving restores confirmed deducted stock, clears pending QR proof verification, and sets order status to Cancelled. Any manual GCash refund is logged separately.
                        </p>
                        <?php endif; ?>
                        <?php if ($request['payment_status'] === 'Paid' && $reqType !== 'Cancel Order'): ?>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div class="field" style="margin:0;">
                                <label>Manual refund method</label>
                                <select name="refund_method"><option value="">Select method</option><option value="Cash">Cash</option><option value="GCash">GCash</option></select>
                            </div>
                            <div class="field" style="margin:0;">
                                <label>Outgoing 13-digit GCash ref</label>
                                <input name="refund_reference" maxlength="100" inputmode="numeric" placeholder="If GCash refund">
                            </div>
                        </div>
                        <label class="kk-check"><input type="checkbox" name="refund_confirmed" value="1"> I confirm the customer has been refunded outside this system.</label>
                        <?php endif; ?>
                        <?php if ($reqType === 'Cancel Delivery'): ?>
                        <label class="kk-check"><input type="checkbox" name="return_stock" value="1"> Items are still salable; return them to inventory stock.</label>
                        <?php endif; ?>
                        <div class="kk-tx-actions">
                            <button class="btn btn-primary" name="action" value="approve" type="submit"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Approve</button>
                            <button class="btn btn-secondary" name="action" value="reject" type="submit"><i class="bi bi-x-circle" aria-hidden="true"></i> Reject</button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="kk-tx-split">
        <?php if (!$isAdmin): ?>
        <section class="kk-tx-card">
            <div class="kk-tx-card-head">
                <div>
                    <h2><i class="bi bi-send-check" aria-hidden="true"></i> Submit Order Request</h2>
                    <p>Request Admin approval to void a Pending order, log a Refund on a Paid order, or cancel a Failed COD delivery. Tip: click <strong>Select</strong> on any order on the right to autofill.</p>
                </div>
            </div>
            <form class="form" method="post" id="cashierTxRequestForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="request">
                <div class="field">
                    <label>Order number</label>
                    <input id="txOrderNoInput" name="order_no" placeholder="e.g. ORD-20261120-0001" value="<?= e(trim((string)($_GET['order_no'] ?? ''))) ?>" required>
                </div>
                <div class="field">
                    <label>Request type</label>
                    <select id="txRequestTypeSelect" name="request_type" required>
                        <option value="Void">Void (Pending order — restores stock)</option>
                        <option value="Refund">Refund (Paid Served / Picked Up / Cancelled order)</option>
                        <option value="Cancel Delivery" <?= ($_GET['request_type'] ?? '') === 'Cancel Delivery' ? 'selected' : '' ?>>Cancel Delivery (Failed unpaid COD)</option>
                    </select>
                </div>
                <div class="field">
                    <label>Reason <span class="muted">(8–500 chars)</span></label>
                    <textarea name="reason" minlength="8" maxlength="500" placeholder="Explain why this void, refund, or delivery cancellation is requested..." required></textarea>
                </div>
                <button class="btn btn-primary" type="submit" style="width:100%;"><i class="bi bi-send-fill" aria-hidden="true"></i> Send Request to Admin</button>
            </form>
        </section>
        <?php else: ?>
        <section class="kk-tx-card">
            <div class="kk-tx-card-head">
                <div>
                    <h2><i class="bi bi-shield-check" aria-hidden="true"></i> Kenji’s Kitchen Request Rules</h2>
                    <p>How customer cancellations, cashier voids, and manual refunds affect inventory &amp; payments.</p>
                </div>
            </div>
            <div class="kk-tx-guide-list">
                <div class="kk-tx-guide-item">
                    <i class="bi bi-bag-x-fill" aria-hidden="true"></i>
                    <div>
                        <strong>Customer Cancel Order (GCash / Reviewed)</strong>
                        <span>Blocks QR Verification &amp; COD confirmation while Pending. Approving returns confirmed stock to inventory and marks unpaid proofs rejected.</span>
                    </div>
                </div>
                <div class="kk-tx-guide-item">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <div>
                        <strong>Refund (Paid Completed / Cancelled Orders)</strong>
                        <span>Cashiers submit refund requests against Paid orders shown on the right. Admin confirms manual Cash or 13-digit GCash refund without returning stock twice.</span>
                    </div>
                </div>
                <div class="kk-tx-guide-item">
                    <i class="bi bi-slash-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Void (Pending POS or Online Orders)</strong>
                        <span>Cancels an unstarted Pending order and returns its deducted stock back to POS and Online Storefront.</span>
                    </div>
                </div>
                <div class="kk-tx-guide-item">
                    <i class="bi bi-truck" aria-hidden="true"></i>
                    <div>
                        <strong>Cancel Delivery (Failed Unpaid COD)</strong>
                        <span>Resolves a Ready online COD order whose delivery dispatch failed, with optional stock return if items remain salable.</span>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="kk-tx-card">
            <div class="kk-tx-card-head">
                <div>
                    <h2><i class="bi bi-list-check" aria-hidden="true"></i> Void &amp; Refund Eligible Orders</h2>
                    <p>POS &amp; Online orders eligible for Void (Pending), Refund (Paid), or Failed COD cancellation (separate from Delivery Status).</p>
                </div>
                <?= render_pagination_controls($eligiblePage, $totalEligiblePages, static fn(int $p) => $buildTransactionsQuery(['eligible_page' => $p])) ?>
            </div>

            <div class="kk-tx-toolbar">
                <div class="kk-tx-pills">
                    <?php
                    $txPills = [
                        'all' => 'All Eligible',
                        'pending_void' => 'Pending (Void)',
                        'paid_refund' => 'Served / Paid (Refund)',
                        'cancelled_paid' => 'Cancelled Paid',
                    ];
                    foreach ($txPills as $pKey => $pLabel):
                        $pillHref = $buildTransactionsQuery(['eligible_filter' => $pKey === 'all' ? null : $pKey, 'eligible_page' => 1]);
                    ?>
                    <a class="kk-tx-pill <?= $eligibleFilter === $pKey ? 'active' : '' ?>" href="<?= e($pillHref) ?>"><?= e($pLabel) ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="get" class="kk-tx-search-form">
                    <?php if ($eligibleFilter !== 'all'): ?><input type="hidden" name="eligible_filter" value="<?= e($eligibleFilter) ?>"><?php endif; ?>
                    <input type="text" name="eligible_search" class="kk-tx-search-input" value="<?= e($eligibleSearch) ?>" placeholder="Search Order # or name">
                    <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:12px;">Search</button>
                    <?php if ($eligibleSearch !== ''): ?>
                    <a href="<?= e($buildTransactionsQuery(['eligible_search' => null, 'eligible_page' => 1])) ?>" class="kk-tx-pill">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Total</th>
                            <?php if (!$isAdmin): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentOrders as $row):
                        $rowStatus = (string)$row['status'];
                        $rowChannel = (string)($row['order_channel'] ?? 'POS');
                        $statusBadgeCls = $rowStatus === 'Served' || $rowStatus === 'Picked Up'
                            ? 'is-approved'
                            : ($rowStatus === 'Cancelled' ? 'is-rejected' : 'is-void');
                        $suggestedType = ($rowStatus === 'Ready' && ($row['dispatch_status'] ?? '') === 'Failed')
                            ? 'Cancel Delivery'
                            : ($rowStatus === 'Pending' ? 'Void' : 'Refund');
                    ?>
                        <tr>
                            <td>
                                <strong class="kk-tx-order-no"><?= e($row['order_no']) ?></strong>
                                <div class="muted" style="font-size:11.5px; margin-top:2px;">
                                    <?= e($rowChannel) ?><?= !empty($row['customer_name']) ? ' · ' . e($row['customer_name']) : '' ?>
                                </div>
                            </td>
                            <td><span class="kk-tx-badge <?= $statusBadgeCls ?>"><?= e($rowStatus) ?></span></td>
                            <td><?= e($row['payment_method'] ?? '—') ?> / <strong><?= e($row['payment_status'] ?? '—') ?></strong></td>
                            <td><strong><?= money($row['total']) ?></strong></td>
                            <?php if (!$isAdmin): ?>
                            <td>
                                <button type="button" class="kk-tx-use-btn" onclick="kkFillOrderRequestForm('<?= e($row['order_no']) ?>', '<?= e($suggestedType) ?>')">Select</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentOrders): ?>
                        <tr><td colspan="<?= $isAdmin ? 4 : 5 ?>" class="muted" style="text-align:center; padding:22px;">No matching eligible orders found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="kk-tx-card">
        <div class="kk-tx-card-head">
            <div>
                <h2><i class="bi bi-clock-history" aria-hidden="true"></i> Recent Decisions</h2>
                <p>Audit log of approved and rejected cancellations, voids, refunds, and stock/repayment outcomes.</p>
            </div>
            <?= render_pagination_controls($decisionsPage, $totalDecisionsPages, static fn(int $p) => $buildTransactionsQuery(['decisions_page' => $p])) ?>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Request</th>
                        <th>Status</th>
                        <th>Requester</th>
                        <th>Reviewer</th>
                        <th>Refund / Stock Outcome</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $request):
                    $decisionParts = [];
                    if (!empty($request['refund_method'])) {
                        $decisionParts[] = $request['refund_method'] . (!empty($request['refund_reference']) ? ' · Ref ' . $request['refund_reference'] : '');
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
                    $reqType = (string)$request['request_type'];
                    $reqBadgeCls = $reqType === 'Cancel Order'
                        ? 'is-cancel'
                        : ($reqType === 'Refund' ? 'is-refund' : ($reqType === 'Cancel Delivery' ? 'is-delivery' : 'is-void'));
                    $decBadgeCls = ($request['status'] === 'Approved') ? 'is-approved' : 'is-rejected';
                ?>
                <tr>
                    <td>
                        <strong><?= e($request['order_no']) ?></strong>
                        <?php if (!empty($request['customer_name'])): ?>
                        <div class="muted" style="font-size:11.5px;"><?= e($request['customer_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="kk-tx-badge <?= $reqBadgeCls ?>"><?= e($reqType) ?></span></td>
                    <td><span class="kk-tx-badge <?= $decBadgeCls ?>"><?= e($request['status']) ?></span></td>
                    <td><?= e($request['requester']) ?></td>
                    <td><?= e($request['reviewer'] ?? '—') ?></td>
                    <td><?= e($decisionSummary) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="6" class="muted" style="text-align:center; padding:22px;">No decisions recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php if (!$isAdmin): ?>
<script>
function kkFillOrderRequestForm(orderNo, requestType) {
    const input = document.getElementById('txOrderNoInput');
    const select = document.getElementById('txRequestTypeSelect');
    if (input) input.value = orderNo || '';
    if (select && requestType) select.value = requestType;
    const form = document.getElementById('cashierTxRequestForm');
    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    input?.focus();
}
</script>
<?php endif; ?>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
