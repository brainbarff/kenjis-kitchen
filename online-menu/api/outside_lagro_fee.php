<?php
/**
 * Customer Outside-Lagro Delivery Fee QR Payment API
 * Part 5: Customer submits reference number and screenshot for the quoted third-party delivery fee.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/delivery_tracking.php';

$customerId = (int)($_SESSION['customer_user_id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Please sign in to submit fee payment.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !verify_csrf_token($data['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired security token. Please reload My Orders and try again.']);
    exit;
}

$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
$proofToken = trim((string)($data['proof_token'] ?? ''));
$refNo = preg_replace('/[^0-9]/', '', (string)($data['payment_reference'] ?? ''));
$methodCode = strtoupper(trim((string)($data['payment_method'] ?? 'GCASH')));
if ($methodCode === '') {
    $methodCode = 'GCASH';
}

if (!$orderId || !preg_match('/^[0-9]{13}$/', $refNo)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Enter a valid 13-digit GCash reference number.']);
    exit;
}

$sessionKey = 'pending_proof_' . $proofToken;
if ($proofToken === '' || empty($_SESSION[$sessionKey]['stored_path'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please upload a screenshot of your fee payment receipt.']);
    exit;
}

$storedProof = $_SESSION[$sessionKey];

try {
    $conn->beginTransaction();

    // Verify order belongs to customer, is active Store-Booked outside-Lagro, and has a quoted unpaid fee
    $stmt = $conn->prepare("
        SELECT o.*, tpd.delivery_fee AS quoted_fee, tpd.fee_paid, tpd.fee_payment_proof_id
        FROM orders o
        JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id
        WHERE o.id = ? AND o.user_id = ? AND o.order_channel = 'ONLINE'
        FOR UPDATE
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order
        || $order['delivery_mode'] !== 'CUSTOMER_BOOKED'
        || kk_resolve_courier_booking_by($order) !== 'STORE'
        || !in_array((string)$order['status'], ['Pending', 'Preparing', 'Ready'], true)
    ) {
        throw new RuntimeException('Order not found or not eligible for store-booked courier fee submission.');
    }

    if ((float)($order['quoted_fee'] ?? 0) <= 0) {
        throw new RuntimeException('Delivery fee has not been quoted by staff yet.');
    }

    if ((int)($order['fee_paid'] ?? 0) === 1) {
        throw new RuntimeException('The delivery fee for this order has already been verified.');
    }

    $pendingProofStmt = $conn->prepare("
        SELECT id FROM payment_proofs
        WHERE order_id = ? AND payment_type = 'thirdparty_delivery' AND verification_status = 'pending'
        LIMIT 1 FOR UPDATE
    ");
    $pendingProofStmt->execute([$orderId]);
    if ($pendingProofStmt->fetch()) {
        throw new RuntimeException('A courier fee payment screenshot is already awaiting staff verification.');
    }

    $dupRefStmt = $conn->prepare("
        SELECT id FROM payment_proofs
        WHERE method_code = ? AND reference_number = ? AND verification_status <> 'rejected'
        LIMIT 1 FOR UPDATE
    ");
    $dupRefStmt->execute([$methodCode, $refNo]);
    if ($dupRefStmt->fetch()) {
        throw new RuntimeException('This GCash reference number has already been submitted for another payment.');
    }

    // Insert payment proof with payment_type = 'thirdparty_delivery'
    $insStmt = $conn->prepare("INSERT INTO payment_proofs (order_id, method_code, reference_number, image_path, image_hash, amount_declared, payment_type, verification_status) VALUES (?, ?, ?, ?, ?, ?, 'thirdparty_delivery', 'pending')");
    $insStmt->execute([$orderId, $methodCode, $refNo, $storedProof['stored_path'], $storedProof['image_hash'], (float)$order['quoted_fee']]);
    $proofId = $conn->lastInsertId();

    // Link latest fee proof on thirdparty_deliveries
    $conn->prepare("UPDATE thirdparty_deliveries SET fee_payment_proof_id = ? WHERE order_id = ?")->execute([$proofId, $orderId]);

    unset($_SESSION[$sessionKey]);

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Delivery fee payment proof submitted. Staff will verify and dispatch your order shortly!'
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
