<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier', 'server']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = (int) ($data['order_id'] ?? 0);

if (!$id) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid order selected.']);
    exit;
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT order_type FROM orders WHERE id = ? AND status = 'Ready' FOR UPDATE");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) {
        $conn->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Order is no longer ready for serving.']);
        exit;
    }

    // Dine-in orders are served at the table, while take-out and online orders are picked up.
    $next = $order['order_type'] === 'DINE-IN' ? 'Served' : 'Picked Up';

    $stmt = $conn->prepare("
        UPDATE orders
        SET status = ?, served_at = NOW(), served_by = ?
        WHERE id = ? AND status = 'Ready'
    ");
    $stmt->execute([$next, $_SESSION['u_id'], $id]);

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'status' => $next,
        'msg' => 'Order has been completed.'
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo json_encode([
        'ok' => false,
        'msg' => 'Unable to update the order right now.'
    ]);
}
?>
