<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_role(['admin', 'cashier']);
header('Content-Type: application/json; charset=UTF-8');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'msg' => 'This unverified void endpoint is retired. Submit and review void/refund requests in Order Requests.',
]);
