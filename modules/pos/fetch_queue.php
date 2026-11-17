<?php
/**
 * Unified Queue API with Multi-Channel & Status Filtering
 * POS /modules/pos/fetch_queue.php
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';

require_role(['admin', 'cashier']);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$channel = trim($_GET['channel'] ?? ''); // 'ALL', 'POS', 'ONLINE'
$status  = trim($_GET['status'] ?? '');  // 'ALL', 'Pending', 'Preparing', 'Ready', 'Served'

try {
    $where = ["DATE(o.created_at) = CURDATE()"];
    $params = [];

    if ($channel !== '' && strtoupper($channel) !== 'ALL') {
        $where[] = "o.order_channel = ?";
        $params[] = strtoupper($channel);
    }

    if ($status !== '' && strtoupper($status) !== 'ALL') {
        $where[] = "o.status = ?";
        $params[] = $status;
    } else {
        $where[] = "o.status IN ('Pending', 'Preparing', 'Ready')";
    }

    $sql = "
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
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $itemStmt = $conn->prepare("
        SELECT item_name, quantity, price, notes
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");

    foreach ($orders as &$order) {
        $itemStmt->execute([$order['id']]);
        $order['items'] = $itemStmt->fetchAll();
    }

    echo json_encode([
        'status' => 'success',
        'count'  => count($orders),
        'orders' => $orders
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
