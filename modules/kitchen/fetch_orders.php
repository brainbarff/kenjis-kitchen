<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'kitchen']);

header('Content-Type: application/json');

$orders = $conn->query("
    SELECT *
    FROM orders o
    WHERE o.status IN ('Pending', 'Preparing', 'Ready')
      AND o.kitchen_queued_at IS NOT NULL
      AND (o.order_channel <> 'ONLINE' OR EXISTS (
          SELECT 1 FROM payments p
          WHERE p.order_id = o.id
            AND (p.payment_method <> 'GCash' OR p.payment_status = 'Paid')
      ))
    ORDER BY kitchen_queued_at ASC, created_at ASC
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

echo json_encode($orders);
?>
