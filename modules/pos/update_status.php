<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_role(['admin', 'cashier']);
header('Content-Type: application/json; charset=UTF-8');
http_response_code(410);
echo json_encode([
    'status' => 'error',
    'message' => 'This direct status endpoint is retired. Use the kitchen, serving, delivery, or order-request workflow.',
]);
