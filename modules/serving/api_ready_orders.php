<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier', 'kitchen']);

header('Content-Type: application/json');

try {
    $orders = $conn->query("
        SELECT o.id, o.order_no, o.queue_no, o.customer_name, o.order_type, o.order_channel, o.total, o.table_no, o.notes, o.ready_at, o.created_at,
               (SELECT p.payment_method FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1) AS payment_method,
               (SELECT p.payment_status FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1) AS payment_status
        FROM orders o
        WHERE o.status = 'Ready'
          AND NOT (o.order_channel = 'ONLINE' AND o.order_type = 'ONLINE')
        ORDER BY o.ready_at ASC, o.created_at ASC
    ")->fetchAll();

    $stmt = $conn->prepare("
        SELECT item_name, quantity, notes
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");

    foreach ($orders as &$order) {
        $stmt->execute([$order['id']]);
        $order['items'] = $stmt->fetchAll();
    }

    echo json_encode([
        'ok' => true,
        'orders' => $orders,
        'server_time' => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Unable to load ready orders.'
    ]);
}
?>
