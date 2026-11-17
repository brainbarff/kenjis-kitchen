<?php
/**
 * Authenticated Image Server for Payment Proofs
 * Part 4.2: Serves private payment screenshots only to order owner customer, Cashier, Manager, and Admin.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$proofId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$proofId) {
    http_response_code(400);
    exit('Invalid image ID.');
}

$stmt = $conn->prepare("SELECT pp.*, o.user_id AS order_customer_id FROM payment_proofs pp JOIN orders o ON o.id = pp.order_id WHERE pp.id = ? LIMIT 1");
$stmt->execute([$proofId]);
$proof = $stmt->fetch();

if (!$proof) {
    http_response_code(404);
    exit('Image not found.');
}

// Authorization check:
// Allowed if logged in as staff (Admin, Cashier) OR if customer is the owner of the order
$isStaff = false;
if (!empty($_SESSION['u_id'])) {
    $roleKey = strtolower((string)(current_user()['role_key'] ?? ''));
    if (in_array($roleKey, ['admin', 'cashier'], true)) {
        $isStaff = true;
    }
}

$isOwner = false;
$customerUserId = (int)($_SESSION['customer_user_id'] ?? 0);
if ($customerUserId > 0 && $customerUserId === (int)$proof['order_customer_id']) {
    $isOwner = true;
}

if (!$isStaff && !$isOwner) {
    http_response_code(403);
    exit('Access denied to payment screenshot.');
}

$cleanPath = ltrim((string)$proof['image_path'], '/\\');
$filePath = ROOT_PATH . '/' . $cleanPath;

// Fallback search in uploads/payment_proofs if saved as filename only or in alt folder
if (!file_exists($filePath)) {
    $altPath = ROOT_PATH . '/uploads/payment_proofs/' . basename($cleanPath);
    if (file_exists($altPath)) {
        $filePath = $altPath;
    }
}

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('Image file missing from storage.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($filePath) ?: 'image/jpeg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=86400');
readfile($filePath);
exit;
