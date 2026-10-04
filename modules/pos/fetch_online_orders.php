<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';

require_role(['admin', 'cashier']);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $stmt = $conn->prepare("
        SELECT
            o.id,
            o.order_no,
            o.queue_no,
            o.customer_name,
            o.customer_phone,
            o.delivery_address,
            o.order_type,
            o.order_channel,
            o.total,
            o.status,
            o.created_at,
            p.payment_method,
            p.payment_reference,
            p.payment_status,
            r.receipt_no
        FROM orders o
        LEFT JOIN payments p ON p.order_id = o.id
        LEFT JOIN receipts r ON r.order_id = o.id
        WHERE o.order_channel = 'ONLINE'
          AND DATE(o.created_at) = CURDATE()
          AND o.status IN ('Pending', 'Preparing', 'Ready')
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 20
    ");
    $stmt->execute();
    $orders = $stmt->fetchAll();

    $itemStmt = $conn->prepare("
        SELECT item_name, quantity, price, notes
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");

    foreach ($orders as &$order) {
        $itemStmt->execute([(int)$order['id']]);
        $order['items'] = $itemStmt->fetchAll();
    }
    unset($order);

    $pendingStmt = $conn->query("SELECT COUNT(*) FROM orders WHERE order_channel = 'ONLINE' AND DATE(created_at) = CURDATE() AND status = 'Pending'");
    $pendingCount = (int)$pendingStmt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'orders' => $orders,
        'pending_count' => $pendingCount,
        'server_time' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Unable to load online orders.'
    ]);
}
