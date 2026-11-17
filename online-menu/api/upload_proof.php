<?php
/**
 * QR Screenshot Upload API for Online Orders
 * Part 4: Securely validates, cleans EXIF, hashes, and stores payment proof outside public root.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

$customerId = (int)($_SESSION['customer_user_id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Please sign in before uploading payment proof.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired security token. Reload checkout and try again.']);
    exit;
}

if (empty($_FILES['proof_image']['tmp_name']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please select a valid image file.']);
    exit;
}

$file = $_FILES['proof_image'];
if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Image exceeds maximum allowed size of 5 MB.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Only JPG, PNG, and WebP images are allowed.']);
    exit;
}

// Compute sha256 hash of original file content for duplicate image check
$rawHash = hash_file('sha256', $file['tmp_name']);

// Verify if image can be parsed and re-compressed to strip EXIF/metadata
$storageDir = ROOT_PATH . '/uploads/private_proofs';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0700, true);
    // Put an htaccess deny all just in case web server tries to serve it
    file_put_contents($storageDir . '/.htaccess', "Deny from all\n");
}

$imgInfo = @getimagesize($file['tmp_name']);
if (!$imgInfo || empty($imgInfo[0]) || empty($imgInfo[1])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Corrupt or unreadable image file.']);
    exit;
}

if (extension_loaded('gd')) {
    $sourceImage = null;
    switch ($mime) {
        case 'image/jpeg':
            $sourceImage = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $sourceImage = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $sourceImage = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : null;
            break;
    }

    if (!$sourceImage) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Corrupt or unreadable image file.']);
        exit;
    }

    $newFilename = 'proof_' . bin2hex(random_bytes(16)) . '.jpg';
    $destination = $storageDir . '/' . $newFilename;

    // Save stripped & compressed JPG (85% quality)
    imagejpeg($sourceImage, $destination, 85);
    imagedestroy($sourceImage);
} else {
    $extMap = [
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/webp' => '.webp',
    ];
    $ext = $extMap[$mime] ?? '.jpg';
    $newFilename = 'proof_' . bin2hex(random_bytes(16)) . $ext;
    $destination = $storageDir . '/' . $newFilename;
    if (!@move_uploaded_file($file['tmp_name'], $destination) && !@copy($file['tmp_name'], $destination)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to store uploaded payment proof.']);
        exit;
    }
}

// Store temporary upload in session token so checkout submission can tie it
$proofToken = bin2hex(random_bytes(16));
$_SESSION['pending_proof_' . $proofToken] = [
    'stored_path' => 'uploads/private_proofs/' . $newFilename,
    'image_hash' => $rawHash,
    'uploaded_at' => time()
];

echo json_encode([
    'status' => 'success',
    'proof_token' => $proofToken,
    'image_hash' => $rawHash,
    'message' => 'Payment screenshot uploaded successfully.'
]);
