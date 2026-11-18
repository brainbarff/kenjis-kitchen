<?php
/**
 * Payment Verification Queue for Cashier, Manager, and Admin
 * Part 4: One shared queue, oldest first, with waiting time, order locking, and verify/reject modal.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/gcash_verification.php';
require_once ROOT_PATH . '/includes/delivery_tracking.php';

require_role(['admin', 'cashier']);

$page = 'payment_verification';
$title = 'QR Payment Verification Queue';
$heading = 'QR Payments Queue';
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_msg_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRole = $currentUser['role_key'] ?? 'cashier';

// Get active shift_id if cashier
$cashierShiftId = null;
if ($userRole === 'cashier') {
    $shiftStmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
    $shiftStmt->execute([$userId]);
    $cashierShiftId = $shiftStmt->fetchColumn() ?: null;
    if (!$cashierShiftId && !empty($_SESSION['pos_active_cashier']['id'])) {
        $shiftStmt->execute([(int)$_SESSION['pos_active_cashier']['id']]);
        $cashierShiftId = $shiftStmt->fetchColumn() ?: null;
    }
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

// Handle Verification / Rejection Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'lock_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        // Lock order if not locked or locked by current user, or if lock is older than 5 mins
        $conn->prepare("UPDATE orders SET locked_by_user_id = ?, locked_at = NOW() WHERE id = ? AND (locked_by_user_id IS NULL OR locked_by_user_id = ? OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))")->execute([$userId, $orderId, $userId]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'verify_payment') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $proofId = (int)($_POST['proof_id'] ?? 0);

        try {
            if ($orderId < 1 || $proofId < 1) throw new RuntimeException('Select a valid order and proof.');
            if ($userRole === 'cashier' && !$cashierShiftId) throw new RuntimeException('Open a cashier shift before verifying payment.');
            $conn->beginTransaction();
            $verified = verify_online_gcash_payment($conn, $orderId, $proofId, $userId, $cashierShiftId ? (int)$cashierShiftId : null, $userRole === 'cashier' ? 'POS_CASHIER' : 'ADMIN_ONLINE');
            $conn->commit();

            if (!empty($verified['released_to_kitchen']) && file_exists(ROOT_PATH . '/includes/realtime.php')) {
                require_once ROOT_PATH . '/includes/realtime.php';
                if (function_exists('realtime_publish')) {
                    realtime_publish('order:submitted', [
                        'order_id' => (int)$orderId,
                        'order_no' => $verified['order_no'],
                        'queue_no' => $verified['queue_no'],
                        'status' => 'Pending'
                    ]);
                }
            }
            if (!empty($verified['held_for_tracking'])) {
                $_SESSION['flash_msg'] = "Order #{$verified['order_no']} food payment verified! Order is held from Kitchen until the customer’s third-party courier tracking link is approved in Delivery Status (Outside Lagro).";
            } else {
                $_SESSION['flash_msg'] = "Order #{$verified['order_no']} payment verified! Order released to Kitchen.";
            }
            $_SESSION['flash_msg_type'] = 'success';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = "Error verifying payment: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/payment_verification.php');
    }

    if ($action === 'reject_payment') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $proofId = (int)($_POST['proof_id'] ?? 0);
        $reason = trim((string)($_POST['rejection_reason'] ?? 'Invalid screenshot or reference number.'));
        if ($reason === 'Other') {
            $customReason = trim((string)($_POST['custom_reason'] ?? ''));
            $reason = $customReason !== '' ? $customReason : 'Invalid screenshot or reference number.';
        }

        try {
            if ($orderId < 1 || $proofId < 1 || $reason === '') throw new RuntimeException('Select an order, proof, and reason.');
            $conn->beginTransaction();

            $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND order_channel = 'ONLINE' AND status = 'Pending' AND payment_verification_status IN ('Awaiting Verification', 'Rejected') FOR UPDATE");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order) throw new RuntimeException('Order is no longer awaiting payment review.');
            $proofStmt = $conn->prepare("SELECT id, verification_status FROM payment_proofs WHERE order_id = ? AND method_code = 'GCASH' AND payment_type = 'food' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $proofStmt->execute([$orderId]);
            $proof = $proofStmt->fetch();
            if (!$proof || (int)$proof['id'] !== $proofId || $proof['verification_status'] !== 'pending') {
                throw new RuntimeException('The selected proof is no longer the latest pending proof for this order.');
            }
            $paymentStmt = $conn->prepare('SELECT payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paymentStmt->execute([$orderId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || $payment['payment_status'] !== 'Pending') {
                throw new RuntimeException('Payment is no longer pending GCash verification.');
            }

            // Increment rejection count
            $newCount = (int)$order['qr_submission_count'] + 1;

            $rejectProof = $conn->prepare("UPDATE payment_proofs SET verification_status = 'rejected', verified_by = ?, verified_at = NOW(), rejection_reason = ? WHERE id = ? AND order_id = ? AND verification_status = 'pending'");
            $rejectProof->execute([$userId, $reason, $proofId, $orderId]);
            if ($rejectProof->rowCount() !== 1) throw new RuntimeException('Payment proof changed during review.');

            if ($newCount >= 3) {
                $conn->prepare('UPDATE orders SET qr_submission_count = ? WHERE id = ?')->execute([$newCount, $orderId]);
                if (cancel_pending_gcash_order($conn, $orderId, 'Payment proof rejected 3 times', $userId) === null) {
                    throw new RuntimeException('Order changed during cancellation.');
                }
                $statusMsg = "Order #{$order['order_no']} has reached 3 rejected proofs and was CANCELLED. Stock has been restored.";
            } else {
                $timeoutMinutes = qr_payment_timeout_minutes($conn);
                $conn->prepare("UPDATE orders SET payment_verification_status = 'Rejected', qr_submission_count = ?, payment_auto_cancel_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_by_user_id = NULL, locked_at = NULL WHERE id = ?")->execute([$newCount, $timeoutMinutes, $orderId]);
                $statusMsg = "Payment proof rejected for Order #{$order['order_no']}. Customer may re-submit up to 3 times (Current: $newCount/3).";
            }

            log_audit($conn, 'qr_payment_rejected', 'order', (string)$orderId, "Payment rejected by {$currentUser['username']} (Reason: $reason)");

            $conn->commit();
            $_SESSION['flash_msg'] = $statusMsg;
            $_SESSION['flash_msg_type'] = 'warning';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = "Error rejecting payment: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/payment_verification.php');
    }
}

// Auto-cancel expired QR orders (including rejected proofs that expired without resubmission)
expire_overdue_gcash_orders($conn, $userId);

// Fetch Pending Queue (Oldest First)
$queueSql = "
    SELECT o.id, o.order_no, o.queue_no, o.customer_name, o.customer_phone, o.total, o.created_at,
           o.fulfillment_mode, o.delivery_mode, o.courier_booking_by, o.delivery_tracking_url,
           o.courier_tracking_status, o.delivery_address, o.notes, o.qr_submission_count,
           o.locked_by_user_id, o.locked_at,
           pp.id AS proof_id, pp.method_code, pp.reference_number, pp.image_hash, pp.amount_declared, pp.submitted_at,
           u_lock.username AS locked_by_name
    FROM orders o
    JOIN payment_proofs pp ON pp.order_id = o.id AND pp.verification_status = 'pending' AND pp.payment_type = 'food'
    LEFT JOIN users u_lock ON u_lock.id = o.locked_by_user_id
    WHERE o.payment_verification_status = 'Awaiting Verification'
      AND o.status = 'Pending'
    ORDER BY o.created_at ASC
";
$pendingList = $conn->query($queueSql)->fetchAll();

$outsideHeldRow = $conn->query("
    SELECT
        SUM(CASE WHEN o.courier_booking_by = 'CUSTOMER' AND o.courier_tracking_status = 'Pending' THEN 1 ELSE 0 END) AS opt1_pending_links,
        SUM(CASE WHEN o.courier_booking_by = 'CUSTOMER' AND o.courier_tracking_status IN ('None','Rejected') AND o.payment_verification_status = 'Verified' THEN 1 ELSE 0 END) AS opt1_waiting_customer,
        SUM(CASE WHEN o.courier_booking_by = 'STORE' AND o.payment_verification_status = 'Verified' THEN 1 ELSE 0 END) AS opt2_active
    FROM orders o
    WHERE o.order_channel = 'ONLINE'
      AND o.fulfillment_mode = 'OUTSIDE'
      AND o.status IN ('Pending', 'Preparing', 'Ready')
")->fetch(PDO::FETCH_ASSOC);
$opt1PendingLinks = (int)($outsideHeldRow['opt1_pending_links'] ?? 0);
$opt1WaitingCust = (int)($outsideHeldRow['opt1_waiting_customer'] ?? 0);
$opt2ActiveCount = (int)($outsideHeldRow['opt2_active'] ?? 0);

// Check for duplicate reference numbers or duplicate image hashes
$dupeRefStmt = $conn->prepare("SELECT COUNT(*) FROM payment_proofs WHERE reference_number = ? AND id <> ? AND verification_status IN ('pending', 'verified')");
$dupeHashStmt = $conn->prepare("SELECT COUNT(*) FROM payment_proofs WHERE image_hash = ? AND id <> ? AND verification_status IN ('pending', 'verified')");

include ROOT_PATH . '/includes/header.php';
?>

<div class="qr-verification-page">
<div class="page-head card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="display:flex;align-items:center;gap:10px;">
            <i class="bi bi-qr-code-scan" style="color:var(--primary,#F2C12E);"></i> QR Payment Verification Queue
        </h1>
        <p class="muted">Review uploaded customer GCash/QR screenshots, verify reference numbers against bank/merchant app, and release orders to the kitchen.</p>
    </div>
    <div style="font-size:14px; background:var(--soft); padding:8px 14px; border-radius:10px;">
        Awaiting review: <strong><?= count($pendingList) ?></strong>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'error' ? 'danger' : ($msg_type === 'warning' ? 'warning' : 'success') ?>" style="margin-bottom:16px;">
    <?= e($msg) ?>
</div>
<?php endif; ?>

<!-- Important Operational Warning (Requirement 4.5) -->
<div style="background:#fff9e6; border:1px solid #eedda5; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; line-height:1.6; color:#38352c;">
    <i class="bi bi-shield-check" style="font-size:16px; margin-right:4px;"></i>
    <strong>Security Reminder:</strong> Always confirm the actual incoming money in your official GCash or Merchant Banking app before clicking Verify. A screenshot alone can be faked or reused.
</div>

<?php if ($opt1PendingLinks > 0 || $opt1WaitingCust > 0 || $opt2ActiveCount > 0): ?>
<div style="background:#fff9e7; border:1px solid #eee3be; border-left:4px solid #f2c12e; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; line-height:1.5; color:#242424; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
        <i class="bi bi-box-seam" style="margin-right:5px; color:#775600;"></i>
        <strong>Outside Lagro Courier Actions:</strong>
        <?php if ($opt1PendingLinks > 0): ?>
            <span><strong><?= $opt1PendingLinks ?></strong> customer tracking link<?= $opt1PendingLinks > 1 ? 's' : '' ?> awaiting approval before Kitchen prep.</span>
        <?php endif; ?>
        <?php if ($opt1WaitingCust > 0): ?>
            <span><strong><?= $opt1WaitingCust ?></strong> paid customer-booked order<?= $opt1WaitingCust > 1 ? 's' : '' ?> waiting for customer tracking link.</span>
        <?php endif; ?>
        <?php if ($opt2ActiveCount > 0): ?>
            <span><strong><?= $opt2ActiveCount ?></strong> staff-booked courier order<?= $opt2ActiveCount > 1 ? 's' : '' ?> in Outside Lagro.</span>
        <?php endif; ?>
    </div>
    <a href="<?= e(BASE_URL . '/modules/delivery/index.php?filter=outside') ?>" class="btn btn-primary" style="min-height:36px; padding:6px 14px; font-size:13px; white-space:nowrap;">
        Open Delivery Status (Outside Lagro) <i class="bi bi-arrow-right"></i>
    </a>
</div>
<?php endif; ?>

<?php if (empty($pendingList)): ?>
<div class="card qr-empty" style="text-align:center; padding:48px 20px;">
    <i class="bi bi-check2-circle" style="font-size:32px; color:#242424; display:flex; align-items:center; justify-content:center; width:72px; height:72px; background:#fff3c4; border-radius:20px; margin:0 auto 20px;"></i>
    <h3>No payments awaiting review</h3>
    <p class="muted">New GCash and QR payment submissions will appear here for review.</p>
</div>
<?php else: ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 360px), 1fr)); gap:20px;">
    <?php foreach ($pendingList as $item):
        $createdAt = new DateTime($item['created_at']);
        $now = new DateTime();
        $waitInterval = $createdAt->diff($now);
        $waitStr = ($waitInterval->h > 0 ? $waitInterval->h . 'h ' : '') . $waitInterval->i . 'm ' . $waitInterval->s . 's';

        // Check for duplicates
        $dupeRefStmt->execute([$item['reference_number'], $item['proof_id']]);
        $hasDupeRef = ($dupeRefStmt->fetchColumn() > 0);

        $dupeHashStmt->execute([$item['image_hash'], $item['proof_id']]);
        $hasDupeHash = ($dupeHashStmt->fetchColumn() > 0);

        $isLocked = !empty($item['locked_by_user_id']) && (int)$item['locked_by_user_id'] !== $userId && (strtotime($item['locked_at']) > time() - 300);
    ?>
    <section class="card qr-payment-card" style="display:flex; flex-direction:column; justify-content:space-between; border:2px solid <?= ($hasDupeRef || $hasDupeHash) ? '#ef4444' : '#e2e8f0' ?>;">
        <div>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <h3 style="margin:0 0 2px; font-size:17px;"><?= e($item['order_no']) ?></h3>
                    <small class="muted">Queue #<?= e($item['queue_no']) ?> · <?= e($item['fulfillment_mode']) ?></small>
                </div>
                <span class="badge badge-warning" style="background:#fef3c7; color:#b45309;">
                    <i class="bi bi-clock"></i> <?= $waitStr ?> ago
                </span>
            </div>

            <!-- Customer & Amount -->
            <div style="background:var(--soft); border-radius:8px; padding:10px 12px; margin-bottom:12px; font-size:14px; line-height:1.5;">
                <div>Customer: <strong><?= e($item['customer_name']) ?></strong> (<?= e($item['customer_phone']) ?>)</div>
                <div>Amount to Verify: <strong style="font-size:15px; color:var(--text);">₱<?= number_format((float)$item['total'], 2) ?></strong></div>
                <div>Method: <span class="badge" style="background:#fff3c4; color:#343434;"><?= e($item['method_code']) ?></span></div>
                <div>Reference #: <code style="font-size:14px; font-weight:800; background:#fcfbf7; border:1px solid #e6e3d8; padding:2px 6px; border-radius:4px;"><?= e($item['reference_number']) ?></code></div>
                <?php if (($item['fulfillment_mode'] ?? '') === 'OUTSIDE'):
                    $qrBooker = kk_resolve_courier_booking_by($item);
                    $qrTrackStatus = (string)($item['courier_tracking_status'] ?? 'None');
                ?>
                <div style="margin-top:6px; padding-top:6px; border-top:1px dashed #e6e3d8; font-size:13px;">
                    <?php if ($qrBooker === 'CUSTOMER'): ?>
                        <strong>Outside Lagro · Customer Books Rider:</strong>
                        <span>Tracking Link: <strong><?= e($qrTrackStatus === 'None' ? 'Not submitted yet' : $qrTrackStatus) ?></strong></span>
                        <?php if (!empty($item['delivery_tracking_url']) && str_starts_with(strtolower((string)$item['delivery_tracking_url']), 'https://')): ?>
                            · <a href="<?= e($item['delivery_tracking_url']) ?>" target="_blank" rel="noopener noreferrer">Open link ↗</a>
                        <?php endif; ?>
                        <div class="muted" style="font-size:12px;">After verifying payment, approve the tracking link in <a href="<?= e(BASE_URL . '/modules/delivery/index.php?filter=outside') ?>">Delivery Status (Outside Lagro)</a> before Kitchen prepares this order.</div>
                    <?php else: ?>
                        <strong>Outside Lagro · Staff Books Rider:</strong>
                        <span class="muted">Enter fee quote and manage tracking in <a href="<?= e(BASE_URL . '/modules/delivery/index.php?filter=outside') ?>">Delivery Status (Outside Lagro)</a>.</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Duplicate Warnings -->
            <?php if ($hasDupeRef): ?>
            <div style="background:#fee2e2; color:#991b1b; padding:8px 10px; border-radius:6px; font-size:12px; font-weight:700; margin-bottom:10px;">
                <i class="bi bi-exclamation-octagon-fill"></i> WARNING: This reference number was already used on another order!
            </div>
            <?php endif; ?>
            <?php if ($hasDupeHash): ?>
            <div style="background:#fee2e2; color:#991b1b; padding:8px 10px; border-radius:6px; font-size:12px; font-weight:700; margin-bottom:10px;">
                <i class="bi bi-images"></i> WARNING: Identical screenshot uploaded on another order!
            </div>
            <?php endif; ?>

            <!-- Screenshot Thumbnail Preview -->
            <div style="text-align:center; margin-bottom:12px;">
                <a href="<?= BASE_URL ?>/modules/online/api/proof_image.php?id=<?= $item['proof_id'] ?>" target="_blank" title="Click to view full image">
                    <img src="<?= BASE_URL ?>/modules/online/api/proof_image.php?id=<?= $item['proof_id'] ?>" alt="Payment Proof" style="max-height:160px; max-width:100%; object-fit:contain; border-radius:8px; border:1px solid #cbd5e1; box-shadow:0 2px 6px rgba(0,0,0,0.06);">
                </a>
                <div style="font-size:13px; color:var(--muted); margin-top:4px;">(Click image to open full resolution)</div>
            </div>
        </div>

        <div>
            <?php if ($isLocked): ?>
                <div style="background:#fef3c7; color:#92400e; font-size:12px; padding:8px; border-radius:6px; text-align:center; font-weight:700;">
                    <i class="bi bi-lock-fill"></i> Being reviewed by <?= e($item['locked_by_name'] ?? 'another staff') ?>
                </div>
            <?php else: ?>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-outline" style="flex:1; color:#dc2626; border-color:#fca5a5; font-size:14px;" onclick="openRejectModal(<?= $item['id'] ?>, <?= $item['proof_id'] ?>, '<?= e($item['order_no']) ?>')">
                        <i class="bi bi-x-circle"></i> Reject
                    </button>
                    <form method="POST" style="flex:1; margin:0;" onsubmit="return confirm('Confirm that you have checked your GCash/Bank app and the funds are received?');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="verify_payment">
                        <input type="hidden" name="order_id" value="<?= $item['id'] ?>">
                        <input type="hidden" name="proof_id" value="<?= $item['proof_id'] ?>">
                        <button type="submit" class="btn btn-primary" style="width:100%; font-size:14px;">
                            <i class="bi bi-check-circle-fill"></i> Verify Payment
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>
<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:440px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;" id="rejectModalTitle">Reject Payment Proof</h3>
        <p class="muted" style="font-size:14px;">Please specify why this payment proof could not be verified. Customers can submit another proof unless this is their third rejection, which cancels the order.</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="reject_payment">
            <input type="hidden" name="order_id" id="reject_order_id">
            <input type="hidden" name="proof_id" id="reject_proof_id">

            <div style="margin-bottom:16px;">
                <label style="font-size:14px; font-weight:700;">Rejection Reason <span style="color:red;">*</span></label>
                <select name="rejection_reason" class="search-input" style="width:100%; padding:8px; margin-bottom:8px;" onchange="if(this.value==='Other'){document.getElementById('custom_reason').style.display='block';}else{document.getElementById('custom_reason').style.display='none';}">
                    <option value="Reference number not found in merchant app">Reference number not found in merchant app</option>
                    <option value="Screenshot is blurry / unreadable">Screenshot is blurry / unreadable</option>
                    <option value="Incorrect amount paid">Incorrect amount paid</option>
                    <option value="Duplicate screenshot / Reference already used">Duplicate screenshot / Reference already used</option>
                    <option value="Other">Other reason...</option>
                </select>
                <input type="text" id="custom_reason" name="custom_reason" placeholder="Enter custom reason..." class="search-input" style="width:100%; padding:8px; display:none;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#dc2626; border-color:#dc2626; color:#fff;">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<style>
.qr-verification-page .page-head { padding:24px; gap:18px!important; }
.qr-verification-page .page-head h1 { font-size:28px; margin:0 0 10px; line-height:1.25; }
.qr-verification-page .page-head p { font-size:15px; line-height:1.6; margin:0; max-width:950px; }
.qr-verification-page .card { border:1px solid #e8e8e5; border-radius:16px; box-shadow:0 3px 14px rgba(0,0,0,.025); }
.qr-verification-page .qr-empty h3 { font-size:22px; margin:0 0 10px; color:#242424; }
.qr-verification-page .qr-empty p { font-size:15px; line-height:1.6; }
.qr-verification-page .qr-payment-card { padding:22px; overflow-wrap:anywhere; }
.qr-verification-page .qr-payment-card small { font-size:13px; }
.qr-verification-page .qr-payment-card .btn { min-height:42px; border-radius:9px; }
.qr-verification-page .btn-primary { background:#f7c62f; border-color:#e8b720; color:#242424; }
#rejectModal > div { border-radius:18px!important; padding:28px!important; max-height:90vh; overflow:auto; }
#rejectModal h3 { font-size:24px; line-height:1.3; }
#rejectModal p { line-height:1.6; }
#rejectModal select, #rejectModal input[type=text] { min-height:44px; border:1px solid #d7d7d2; border-radius:9px; font-size:14px; }
#rejectModal select:focus, #rejectModal input:focus { outline:2px solid #b48b12; outline-offset:2px; }
@media(max-width:600px) { .qr-verification-page .page-head h1 { font-size:24px; } .qr-verification-page .page-head { padding:18px; } }
</style>
<script>
function openRejectModal(orderId, proofId, orderNo) {
    document.getElementById('reject_order_id').value = orderId;
    document.getElementById('reject_proof_id').value = proofId;
    document.getElementById('rejectModalTitle').textContent = 'Reject Payment for ' + orderNo;
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
