<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier', 'server']);

header('Content-Type: application/json');

try {
    $orders = $conn->query("
        SELECT id, order_no, queue_no, customer_name, order_type, table_no, ready_at, created_at
        FROM orders
        WHERE status = 'Ready'
        ORDER BY ready_at ASC, created_at ASC
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
