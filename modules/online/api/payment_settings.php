<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/config/config.php';

function payment_settings_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    payment_settings_json(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

try {
    $stmt = $conn->query('SELECT gcash_phone, gcash_qr_image FROM store_payment_settings WHERE id = 1 LIMIT 1');
    $settings = $stmt->fetch() ?: [];
    $phone = preg_replace('/\D+/', '', (string)($settings['gcash_phone'] ?? ''));
    if (!preg_match('/^09\d{9}$/', $phone)) {
        $phone = '09952071575';
    }

    $filename = basename((string)($settings['gcash_qr_image'] ?? ''));
    $basePath = parse_url(BASE_URL, PHP_URL_PATH);
    $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
    $qrUrl = $filename !== '' ? $basePath . '/uploads/payments/' . rawurlencode($filename) : null;

    payment_settings_json([
        'status' => 'success',
        'data' => [
            'gcash_phone' => $phone,
            'gcash_qr_url' => $qrUrl
        ]
    ]);
} catch (Throwable $error) {
    error_log('Online GCash settings could not be loaded: ' . $error->getMessage());
    payment_settings_json(['status' => 'error', 'message' => 'Payment details are temporarily unavailable.'], 500);
}
