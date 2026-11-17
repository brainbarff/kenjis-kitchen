<?php
/**
 * Outside-Lagro Third-Party Delivery Management
 * Part 5: Staff quotes delivery fee (Lalamove/Grab), tracks customer fee payment, and verifies before dispatch.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/delivery_tracking.php';

require_role(['admin', 'cashier']);

$page = 'outside_lagro';
$title = 'Outside Lagro Manual Courier';
$heading = 'Outside Lagro Delivery';
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_msg_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

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

    // Quote fee and booking service
    if ($action === 'quote_fee') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $service = trim((string)($_POST['booking_service'] ?? 'Lalamove'));
        $fee = max(0, (float)($_POST['delivery_fee'] ?? 0));
        $trackingRef = trim((string)($_POST['tracking_reference'] ?? ''));

        if ($orderId > 0) {
            try {
                if ($fee <= 0) {
                    throw new InvalidArgumentException('Enter a quoted third-party delivery fee greater than ₱0.00.');
                }
                $normalizedUrl = null;
                if ($trackingRef !== '' && (preg_match('#^https?://#i', $trackingRef) || str_contains($trackingRef, '://'))) {
                    $normalizedUrl = normalize_delivery_tracking_url($trackingRef);
                    $trackingRef = $normalizedUrl ?? $trackingRef;
                }

                // Insert or update thirdparty_deliveries
                $checkStmt = $conn->prepare("SELECT id FROM thirdparty_deliveries WHERE order_id = ? LIMIT 1");
                $checkStmt->execute([$orderId]);
                $check = $checkStmt->fetchColumn();
                if ($check) {
                    $stmt = $conn->prepare("UPDATE thirdparty_deliveries SET booking_service = ?, delivery_fee = ?, tracking_reference = ?, booked_by = ?, booked_at = NOW(), fee_notified_at = NOW(), status = CASE WHEN fee_paid = 1 THEN status ELSE 'Quoted' END WHERE order_id = ?");
                    $stmt->execute([$service, $fee, $trackingRef ?: null, $userId, $orderId]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO thirdparty_deliveries (order_id, booking_service, delivery_fee, tracking_reference, booked_by, booked_at, fee_notified_at, status) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'Quoted')");
                    $stmt->execute([$orderId, $service, $fee, $trackingRef ?: null, $userId]);
                }

                // Update delivery_fee (and validated HTTPS tracking link if supplied) on order record
                if ($normalizedUrl !== null) {
                    $conn->prepare("UPDATE orders SET delivery_fee = ?, delivery_tracking_url = ? WHERE id = ?")->execute([$fee, $normalizedUrl, $orderId]);
                } else {
                    $conn->prepare("UPDATE orders SET delivery_fee = ? WHERE id = ?")->execute([$fee, $orderId]);
                }

                log_audit($conn, 'outside_lagro_quoted', 'order', (string)$orderId, "Quoted $service fee: ₱$fee by {$currentUser['username']}");
                $_SESSION['flash_msg'] = "Manual third-party courier quote saved for customer payment via static GCash QR.";
                $_SESSION['flash_msg_type'] = 'success';
            } catch (Throwable $e) {
                $_SESSION['flash_msg'] = "Unable to save quote: " . $e->getMessage();
                $_SESSION['flash_msg_type'] = 'error';
            }
        }
        redirect('/modules/pos/outside_lagro.php');
    }

    // Verify delivery fee payment
    if ($action === 'verify_fee_payment') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $proofId = (int)($_POST['proof_id'] ?? 0);

        try {
            if ($orderId < 1 || $proofId < 1) {
                throw new RuntimeException('Invalid order or proof.');
            }
            $conn->beginTransaction();

            $updProof = $conn->prepare("UPDATE payment_proofs SET verification_status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ? AND order_id = ? AND payment_type = 'thirdparty_delivery' AND verification_status = 'pending'");
            $updProof->execute([$userId, $proofId, $orderId]);
            if ($updProof->rowCount() !== 1) {
                throw new RuntimeException('Fee payment proof is no longer pending.');
            }
            $conn->prepare("UPDATE thirdparty_deliveries SET fee_paid = 1, fee_payment_proof_id = ?, status = 'Fee Paid' WHERE order_id = ?")->execute([$proofId, $orderId]);

            log_audit($conn, 'outside_lagro_fee_verified', 'order', (string)$orderId, "Third-party delivery fee verified by {$currentUser['username']}");

            $conn->commit();
            $_SESSION['flash_msg'] = "Delivery fee verified! Order can be marked Out for Delivery once kitchen marks it Ready.";
            $_SESSION['flash_msg_type'] = 'success';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = "Error verifying fee: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/outside_lagro.php');
    }

    // Dispatch order (only allowed if fee is paid and a valid HTTPS tracking link is recorded!)
    if ($action === 'dispatch_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        try {
        $conn->beginTransaction();
        $tpdStmt = $conn->prepare("
            SELECT tpd.fee_paid, tpd.tracking_reference, o.delivery_tracking_url,
                   o.order_channel, o.order_type, o.status, o.delivery_mode,
                   o.fulfillment_mode, o.courier_booking_by, o.notes
            FROM orders o
            LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id
            WHERE o.id = ?
            LIMIT 1 FOR UPDATE
        ");
        $tpdStmt->execute([$orderId]);
        $tpd = $tpdStmt->fetch();
        if (!$tpd || $tpd['order_channel'] !== 'ONLINE' || $tpd['order_type'] !== 'ONLINE'
            || $tpd['delivery_mode'] !== 'CUSTOMER_BOOKED' || $tpd['status'] !== 'Ready') {
            throw new RuntimeException('Only a kitchen-ready online third-party delivery can be handed off.');
        }
        $foodPayment = $conn->prepare('SELECT payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $foodPayment->execute([$orderId]);
        $food = $foodPayment->fetch();
        if (!$food || strcasecmp((string)$food['payment_method'], 'GCash') !== 0 || $food['payment_status'] !== 'Paid') {
            throw new RuntimeException('Verify the GCash food payment before courier handoff.');
        }
        $cancelCheck = $conn->prepare("SELECT COUNT(*) FROM transaction_requests WHERE order_id = ? AND request_type = 'Cancel Delivery' AND status = 'Pending'");
        $cancelCheck->execute([$orderId]);
        if ((int)$cancelCheck->fetchColumn() > 0) throw new RuntimeException('Cancellation review is pending.');
        $dispatchCheck = $conn->prepare('SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE');
        $dispatchCheck->execute([$orderId]);
        $dispatch = $dispatchCheck->fetch();
        if ($dispatch && $dispatch['status'] !== 'Failed') throw new RuntimeException('Courier handoff has already been recorded.');

        $validTrackingUrl = null;
        $orderTracking = trim((string)($tpd['delivery_tracking_url'] ?? ''));
        $tpdTracking = trim((string)($tpd['tracking_reference'] ?? ''));
        try {
            if ($orderTracking !== '') {
                $validTrackingUrl = normalize_delivery_tracking_url($orderTracking);
            } elseif ($tpdTracking !== '' && preg_match('#^https?://#i', $tpdTracking)) {
                $validTrackingUrl = normalize_delivery_tracking_url($tpdTracking);
                if ($validTrackingUrl !== null) {
                    $conn->prepare("UPDATE orders SET delivery_tracking_url = ? WHERE id = ?")->execute([$validTrackingUrl, $orderId]);
                }
            }
        } catch (Throwable $e) {
            $validTrackingUrl = null;
        }

        $resolvedBooker = kk_resolve_courier_booking_by($tpd);
        $feeSatisfied = ($resolvedBooker === 'CUSTOMER') || (!empty($tpd) && (int)$tpd['fee_paid'] === 1);

        if (!$feeSatisfied) {
            $_SESSION['flash_msg'] = "CANNOT DISPATCH: Third-party delivery fee must be paid and verified first!";
            $_SESSION['flash_msg_type'] = 'error';
        } elseif ($validTrackingUrl === null) {
            $_SESSION['flash_msg'] = "CANNOT DISPATCH: A valid HTTPS third-party courier tracking link is required before handoff.";
            $_SESSION['flash_msg_type'] = 'error';
        } else {
        if ($dispatch) {
            $conn->prepare("UPDATE delivery_dispatches SET rider_name = 'Third-party courier', rider_phone = NULL, status = 'Out for Delivery', notes = 'Manual third-party courier handoff', assigned_by = ?, updated_by = ?, assigned_at = NOW(), out_at = NOW(), delivered_at = NULL, failed_at = NULL WHERE id = ?")
                ->execute([$userId, $userId, (int)$dispatch['id']]);
        } else {
            $conn->prepare("INSERT INTO delivery_dispatches (order_id, rider_name, rider_phone, status, notes, assigned_by, updated_by, assigned_at, out_at) VALUES (?, 'Third-party courier', NULL, 'Out for Delivery', 'Manual third-party courier handoff', ?, ?, NOW(), NOW())")
                ->execute([$orderId, $userId, $userId]);
        }
        if ($resolvedBooker === 'STORE') {
            $conn->prepare("UPDATE thirdparty_deliveries SET status = 'Dispatched' WHERE order_id = ?")->execute([$orderId]);
        }

            log_audit($conn, 'outside_lagro_dispatched', 'order', (string)$orderId, "Order dispatched by {$currentUser['username']}");
            $_SESSION['flash_msg'] = "Order marked Out for Delivery (manual third-party courier handoff logged).";
            $_SESSION['flash_msg_type'] = 'success';
        }
        $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['flash_msg'] = $e->getMessage();
            $_SESSION['flash_msg_type'] = 'error';
        }
        redirect('/modules/pos/outside_lagro.php');
    }
}

// Fetch Outside Lagro Orders
$outsideOrders = $conn->query("
    SELECT o.*, tpd.booking_service, tpd.delivery_fee AS tp_fee, tpd.tracking_reference, tpd.fee_paid, tpd.status AS tp_status,
           dd.status AS dispatch_status,
           pp.id AS fee_proof_id, pp.reference_number AS fee_ref_no, pp.verification_status AS fee_proof_status
    FROM orders o
    LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id
    LEFT JOIN delivery_dispatches dd ON dd.order_id = o.id
    LEFT JOIN payment_proofs pp ON pp.order_id = o.id AND pp.payment_type = 'thirdparty_delivery' AND pp.verification_status = 'pending'
    WHERE o.fulfillment_mode = 'OUTSIDE'
      AND o.order_channel = 'ONLINE'
      AND o.status NOT IN ('Served', 'Cancelled')
    ORDER BY o.created_at ASC
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>

<div class="outside-lagro-page">
<section class="card">
    <div class="page-head">
        <div>
            <h2 style="margin:0; display:flex; align-items:center; gap:12px;">
                <span class="outside-head-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                <span>Outside Lagro · Manual Third-Party Courier</span>
            </h2>
            <p class="muted" style="margin:6px 0 0;">Record manual third-party courier arrangements (GrabExpress, Lalamove). Physical booking is handled outside the system; log the quoted fee, verify the customer's GCash fee screenshot, and update handoff status.</p>
        </div>
        <a href="<?= e(BASE_URL . '/modules/delivery/index.php?filter=outside') ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Delivery Status
        </a>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:18px;">
        <?= e($msg) ?>
    </div>
    <?php endif; ?>

    <div class="outside-policy-banner" role="note">
        <span class="outside-policy-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
        <div class="outside-policy-body">
            <strong>Strict Dispatch Policy &amp; Manual Workflow</strong>
            <p>Third-party courier fees are paid separately via static GCash QR. Orders <strong>must NOT</strong> be handed over until both food payment and courier fee payment are verified.</p>
            <div class="outside-steps">
                <span><i class="bi bi-1-circle-fill" aria-hidden="true"></i> Check courier rate manually &amp; log quote</span>
                <span><i class="bi bi-2-circle-fill" aria-hidden="true"></i> Verify customer's static GCash fee screenshot</span>
                <span><i class="bi bi-3-circle-fill" aria-hidden="true"></i> Mark Out for Delivery when Kitchen is Ready</span>
            </div>
        </div>
    </div>

    <?php if (empty($outsideOrders)): ?>
    <div class="outside-empty">
        <span class="outside-empty-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
        <h3>No active Outside-Lagro orders right now</h3>
        <p>Active online delivery orders outside Barangay Greater Lagro will appear here for manual fee quoting, proof verification, and handoff status updates.</p>
    </div>
    <?php else: ?>
    <div class="outside-orders-grid">
        <?php foreach ($outsideOrders as $ord):
            $resolvedOrdBooker = kk_resolve_courier_booking_by($ord);
            $hasHttpsTracking = !empty($ord['delivery_tracking_url']) && str_starts_with(strtolower((string)$ord['delivery_tracking_url']), 'https://');
            $isCustomerBookedCourier = ($resolvedOrdBooker === 'CUSTOMER');
            $dispatchStatus = (string)($ord['dispatch_status'] ?? '');
            $alreadyDispatched = in_array($dispatchStatus, ['Assigned', 'Out for Delivery', 'Delivered'], true);
            $dispatchAllowed = !$alreadyDispatched && ($ord['status'] === 'Ready') && $hasHttpsTracking && ($isCustomerBookedCourier || !empty($ord['fee_paid']));
            $kitchenBadgeClass = $ord['status'] === 'Ready' ? 'badge-success' : ($ord['status'] === 'Preparing' ? 'badge-warning' : 'badge-muted');
        ?>
        <article class="outside-order-card">
            <div>
                <div class="outside-card-top">
                    <div>
                        <h3><?= e($ord['order_no']) ?></h3>
                        <span class="badge badge-info">Outside Lagro</span>
                    </div>
                    <span class="badge <?= $kitchenBadgeClass ?>"><?= e($ord['status']) ?></span>
                </div>

                <div class="outside-card-details">
                    <div><span class="muted">Customer:</span> <strong><?= e($ord['customer_name']) ?></strong> (<?= e($ord['customer_phone']) ?>)</div>
                    <div><span class="muted">Address:</span> <strong><?= e($ord['delivery_address']) ?></strong></div>
                    <div><span class="muted">Arrangement:</span> <strong><?= $isCustomerBookedCourier ? 'Customer-Booked Courier' : 'Store-Arranged Courier' ?></strong></div>
                    <div><span class="muted">Food Total (Paid):</span> <strong style="color:var(--success);">&#8369;<?= number_format((float)$ord['subtotal'], 2) ?></strong></div>
                    <div class="outside-card-sep">
                        <?php if ($isCustomerBookedCourier): ?>
                            <div><span class="muted">Courier Fee:</span> <strong style="color:#2767a5;">Paid directly by customer (No store quote needed)</strong></div>
                        <?php else: ?>
                            <div><span class="muted">Courier Status:</span> <strong><?= e($ord['tp_status'] ?? 'Not Quoted Yet') ?></strong></div>
                            <div><span class="muted">Quoted Fee:</span> <strong><?= !empty($ord['tp_fee']) ? '&#8369;' . number_format((float)$ord['tp_fee'], 2) : '—' ?></strong></div>
                            <?php if (!empty($ord['booking_service'])): ?>
                                <div><span class="muted">Service:</span> <?= e($ord['booking_service']) ?> (Ref: <code style="word-break:break-all;"><?= e($ord['tracking_reference'] ?: 'N/A') ?></code>)</div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div>
                            <span class="muted">External Link:</span>
                            <?php if ($hasHttpsTracking): ?>
                                <a href="<?= e($ord['delivery_tracking_url']) ?>" target="_blank" rel="noopener noreferrer">Open third-party tracking <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                            <?php else: ?>
                                <span style="color:var(--danger); font-weight:700;">Missing HTTPS link</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isCustomerBookedCourier): ?>
                        <div style="margin-top:4px; display:flex; align-items:center; gap:6px;">
                            <span class="muted">Courier Fee Paid:</span>
                            <?php if (!empty($ord['fee_paid'])): ?>
                                <span class="badge badge-success"><i class="bi bi-check-circle-fill" aria-hidden="true" style="margin-right:4px;"></i> Verified</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><i class="bi bi-x-circle-fill" aria-hidden="true" style="margin-right:4px;"></i> Unpaid</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($ord['fee_proof_id'])): ?>
                <div class="outside-proof-box">
                    <strong style="color:#8a6200;"><i class="bi bi-clock-history" aria-hidden="true"></i> Customer uploaded static GCash fee receipt</strong>
                    <div style="margin:6px 0;">Ref #: <code style="word-break:break-all;"><?= e($ord['fee_ref_no']) ?></code></div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                        <a href="<?= BASE_URL ?>/modules/online/api/proof_image.php?id=<?= $ord['fee_proof_id'] ?>" target="_blank" class="btn btn-secondary" style="min-height:36px; padding:6px 12px; font-size:13px;">
                            <i class="bi bi-image" aria-hidden="true"></i> View Fee Receipt
                        </a>
                        <form method="POST" style="flex:1; margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="verify_fee_payment">
                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                            <input type="hidden" name="proof_id" value="<?= $ord['fee_proof_id'] ?>">
                            <button type="submit" class="btn btn-primary" style="width:100%; min-height:36px; padding:6px 12px; font-size:13px;">
                                <i class="bi bi-check-circle" aria-hidden="true"></i> Confirm Delivery Fee Payment
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="outside-card-actions">
                <?php if (!$isCustomerBookedCourier): ?>
                <button type="button" class="btn btn-secondary" style="flex:1; min-height:40px; font-size:13px;" onclick="openQuoteModal(<?= $ord['id'] ?>, '<?= e($ord['order_no']) ?>', <?= (float)($ord['tp_fee'] ?? 0) ?>)">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Quote / Update Fee
                </button>
                <?php endif; ?>
                <?php if ($dispatchAllowed): ?>
                <form method="POST" style="flex:1; margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="dispatch_order">
                    <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                    <button type="submit" class="btn btn-primary" style="width:100%; min-height:40px; font-size:13px;">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Mark Out for Delivery
                    </button>
                </form>
                <?php else: ?>
                    <?php
                    if ($alreadyDispatched) {
                        $blockedLabel = 'Recorded (' . $dispatchStatus . ')';
                    } elseif ($isCustomerBookedCourier) {
                        $blockedLabel = !$hasHttpsTracking ? 'Awaiting Tracking Link' : 'Waiting Kitchen Ready';
                    } else {
                        $blockedLabel = 'Awaiting Fee';
                    }
                    ?>
                    <button type="button" class="btn btn-secondary" style="flex:1; min-height:40px; font-size:13px; opacity:0.65; cursor:not-allowed;" title="<?= e($blockedLabel) ?>" disabled>
                        <?= e($blockedLabel) ?>
                    </button>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
</div>

<!-- Quote Modal -->
<div id="quoteModal" class="modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="quoteModalTitle" onclick="if (event.target === this) closeModal('quoteModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h2 id="quoteModalTitle" style="font-size:20px;">Record Third-Party Delivery Quote</h2>
            <button type="button" class="btn btn-secondary" style="min-height:36px; padding:6px 10px;" aria-label="Close quote modal" onclick="closeModal('quoteModal')">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <p class="muted" style="font-size:13px; margin:0 0 16px;">Check your third-party courier app (e.g. GrabExpress, Lalamove) manually for the fare to the customer address, then enter the quote here:</p>
        <form method="POST" class="form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="quote_fee">
            <input type="hidden" name="order_id" id="quote_order_id">

            <div class="field">
                <label for="quote_booking_service">Courier Service</label>
                <select id="quote_booking_service" name="booking_service">
                    <option value="Lalamove">Lalamove</option>
                    <option value="GrabExpress">GrabExpress</option>
                    <option value="Borzo">Borzo / Mr. Speedy</option>
                    <option value="Customer Own Rider">Other Manual Courier</option>
                </select>
            </div>

            <div class="field">
                <label for="quote_fee_input">Quoted Delivery Fee (&#8369;)</label>
                <input type="number" step="1" min="1" name="delivery_fee" id="quote_fee_input" required placeholder="e.g. 85">
            </div>

            <div class="field">
                <label for="quote_tracking_ref">External Reference or Share Link (optional)</label>
                <input type="text" id="quote_tracking_ref" name="tracking_reference" placeholder="e.g. https://share.lalamove.com/... or booking ref">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:4px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quoteModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> Save Fee Quote</button>
            </div>
        </form>
    </div>
</div>

<style>
.outside-lagro-page .card { border:1px solid #e8e8e4; border-radius:16px; box-shadow:0 3px 14px rgba(0,0,0,.025); }
.outside-lagro-page .page-head { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:20px; }
.outside-lagro-page .page-head > div:first-child { flex:1 1 500px; min-width:0; }
.outside-lagro-page .page-head h2 { font-size:26px; line-height:1.25; color:#242424; }
.outside-lagro-page .page-head p { font-size:15px; line-height:1.55; max-width:78ch; }
.outside-head-icon { display:inline-grid; place-items:center; width:42px; height:42px; flex:0 0 42px; border-radius:12px; background:#fff3c4; color:#775600; font-size:20px; }
.outside-policy-banner { display:flex; align-items:flex-start; gap:14px; background:#fff9e7; border:1px solid #eee3be; border-left:4px solid #f2c12e; border-radius:12px; padding:16px 18px; margin-bottom:22px; }
.outside-policy-icon { display:inline-grid; place-items:center; width:38px; height:38px; flex:0 0 38px; border-radius:10px; background:#fff0b3; color:#775600; font-size:18px; margin-top:2px; }
.outside-policy-body { min-width:0; flex:1; }
.outside-policy-body > strong { display:block; color:#242424; font-size:14px; margin-bottom:4px; }
.outside-policy-body p { margin:0 0 10px; font-size:13.5px; line-height:1.5; color:#5e5a4c; }
.outside-steps { display:flex; flex-wrap:wrap; gap:10px 18px; font-size:12.5px; font-weight:600; color:#705300; }
.outside-steps span { display:inline-flex; align-items:center; gap:6px; }
.outside-empty { text-align:center; padding:40px 20px; border:1px dashed #e2dcc8; border-radius:14px; background:#fcfbf7; }
.outside-empty-icon { display:flex; align-items:center; justify-content:center; width:56px; height:56px; margin:0 auto 14px; border-radius:16px; background:#fff3c4; color:#242424; font-size:26px; }
.outside-empty h3 { font-size:20px; margin:0 0 8px; color:#242424; }
.outside-empty p { font-size:15px; color:#656565; margin:0 auto; max-width:56ch; line-height:1.55; }
.outside-orders-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(370px, 1fr)); gap:18px; }
.outside-order-card { display:flex; flex-direction:column; justify-content:space-between; background:var(--white); border:1px solid #e6e3d8; border-radius:14px; padding:18px; }
.outside-card-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px; }
.outside-card-top h3 { margin:0 0 6px; font-size:17px; color:#242424; }
.outside-card-details { background:var(--bg); border:1px solid #ece9df; border-radius:10px; padding:12px 14px; margin-bottom:14px; font-size:13px; line-height:1.6; }
.outside-card-sep { margin-top:8px; padding-top:8px; border-top:1px dashed #d6d0bf; }
.outside-proof-box { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px; margin-bottom:14px; font-size:13px; }
.outside-card-actions { display:flex; gap:8px; flex-wrap:wrap; }
.outside-lagro-page .btn-primary { background:#f7c62f; color:#242424; border-color:#e8b720; }
.outside-lagro-page .btn-primary:hover { background:#e8b720; }
.outside-lagro-page .btn-secondary { background:#fff; color:#242424; border:1px solid #dfdcd0; }
.outside-lagro-page .btn-secondary:hover { background:#fffbed; border-color:#d6ab24; }
@media (max-width: 700px) {
    .outside-lagro-page .page-head h2 { font-size:21px; }
    .outside-orders-grid { grid-template-columns:1fr; }
}
</style>

<script>
function openQuoteModal(id, no, fee) {
    document.getElementById('quote_order_id').value = id;
    document.getElementById('quoteModalTitle').textContent = 'Record Quote · ' + no;
    document.getElementById('quote_fee_input').value = fee > 0 ? fee : '';
    document.getElementById('quoteModal').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal('quoteModal');
});
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
