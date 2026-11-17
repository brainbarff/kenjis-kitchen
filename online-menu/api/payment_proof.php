<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) {
    http_response_code(400);
    exit('Invalid order ID.');
}

$stmt = $conn->prepare("
    SELECT o.id, o.user_id, p.payment_proof_path
    FROM orders o
    JOIN payments p ON p.order_id = o.id
    WHERE o.id = ?
    ORDER BY p.id DESC
    LIMIT 1
");
$stmt->execute([$orderId]);
$row = $stmt->fetch();

if (!$row || empty($row['payment_proof_path'])) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$customerUserId = (int)($_SESSION['customer_user_id'] ?? 0);
$staffUser = current_user();
$staffRole = strtolower((string)($staffUser['role_key'] ?? ''));

$isAuthorizedCustomer = $customerUserId > 0 && $customerUserId === (int)($row['user_id'] ?? 0);
$isAuthorizedStaff = in_array($staffRole, ['admin', 'cashier', 'kitchen'], true);

if (!$isAuthorizedCustomer && !$isAuthorizedStaff) {
    http_response_code(403);
    exit('Unauthorized to view this payment proof.');
}

$safeFilename = basename((string)$row['payment_proof_path']);
$fullPath = dirname(__DIR__, 2) . '/uploads/payment_proofs/' . $safeFilename;

if (!is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    exit('Payment proof file is unavailable.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($fullPath);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Invalid payment proof format.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Content-Disposition: inline; filename="' . rawurlencode($safeFilename) . '"');
readfile($fullPath);
exit;
