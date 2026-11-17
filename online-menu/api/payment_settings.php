<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/config.php';

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
    $stmt = $conn->query('SELECT gcash_account_name, gcash_phone, gcash_qr_image FROM store_payment_settings WHERE id = 1 LIMIT 1');
    $settings = $stmt->fetch() ?: [];
    $phone = preg_replace('/\D+/', '', (string)($settings['gcash_phone'] ?? ''));
    if (!preg_match('/^09\d{9}$/', $phone)) {
        $phone = '09952071574';
    }

    $filename = basename((string)($settings['gcash_qr_image'] ?? ''));
    $basePath = parse_url(BASE_URL, PHP_URL_PATH);
    $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
    $qrFile = $filename !== '' ? dirname(__DIR__, 2) . '/uploads/payments/' . $filename : '';
    $qrUrl = ($qrFile !== '' && is_file($qrFile))
        ? $basePath . '/uploads/payments/' . rawurlencode($filename) . '?v=' . (int)@filemtime($qrFile)
        : null;

    $sysRows = $conn->query("
        SELECT setting_key, setting_value
        FROM system_settings
        WHERE setting_key IN ('lagro_delivery_min_items', 'lagro_delivery_min_amount', 'cod_min_order', 'cod_max_order')
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    payment_settings_json([
        'status' => 'success',
        'data' => [
            'gcash_account_name' => (string)($settings['gcash_account_name'] ?? ''),
            'gcash_phone' => $phone,
            'gcash_qr_url' => $qrUrl,
            'delivery_rules' => [
                'within_lagro_min_items' => isset($sysRows['lagro_delivery_min_items']) ? max(0, (int)$sysRows['lagro_delivery_min_items']) : 2,
                'within_lagro_min_amount' => isset($sysRows['lagro_delivery_min_amount']) ? max(0.0, (float)$sysRows['lagro_delivery_min_amount']) : 100.0,
                'cod_min_order' => isset($sysRows['cod_min_order']) ? max(0.0, (float)$sysRows['cod_min_order']) : 30.0,
                'cod_max_order' => isset($sysRows['cod_max_order']) ? max(0.0, (float)$sysRows['cod_max_order']) : 0.0,
            ],
        ]
    ]);
} catch (Throwable $error) {
    error_log('Online GCash settings could not be loaded: ' . $error->getMessage());
    payment_settings_json(['status' => 'error', 'message' => 'Payment details are temporarily unavailable.'], 500);
}
