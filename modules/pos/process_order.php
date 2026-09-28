<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$cart = $data['cart'] ?? [];
$paymentMode = !empty($data['paymentMode']) ? ucfirst(trim($data['paymentMode'])) : 'Cash';
$cash = (float) ($data['cash'] ?? 0);
$total = (float) ($data['total'] ?? 0);
$change = (float) ($data['change'] ?? 0);

if (!$cart || empty($data['orderType']) || ($paymentMode === 'Cash' && $cash < $total)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid order details.']);
    exit;
}

try {
    $conn->beginTransaction();

    $cols = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('notes', $cols, true)) {
        $conn->query("ALTER TABLE orders ADD COLUMN notes TEXT NULL");
    }

    $stockCheck = $conn->prepare("SELECT id, item_name, stock, availability, is_archived FROM menu_items WHERE id = ? FOR UPDATE");

    foreach ($cart as $row) {
        $itemId = (int) ($row['id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);

        if ($itemId <= 0 || $qty <= 0) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Invalid item quantity.']);
            exit;
        }

        $stockCheck->execute([$itemId]);
        $item = $stockCheck->fetch();

        if (!$item || (int) $item['is_archived'] === 1) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'One of the selected menu items is no longer available.']);
            exit;
        }

        if ($item['availability'] !== 'Available') {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => $item['item_name'] . ' is currently unavailable.']);
            exit;
        }

        if ((int) $item['stock'] < $qty) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Only ' . (int) $item['stock'] . ' pcs of ' . $item['item_name'] . ' are available.']);
            exit;
        }
    }

    $queue = (int) $conn->query("SELECT COALESCE(MAX(queue_no), 0) + 1 q FROM orders WHERE DATE(created_at) = CURDATE()")->fetch()['q'];
    $order_no = 'ORD-' . date('Ymd') . '-' . str_pad($queue, 4, '0', STR_PAD_LEFT);
    $orderNote = !empty($data['orderNote']) ? trim($data['orderNote']) : null;
    $userId = $_SESSION['u_id'] ?? ($_SESSION['user_id'] ?? 1);

    $stmt = $conn->prepare("INSERT INTO orders (order_no, queue_no, user_id, customer_name, order_type, table_no, subtotal, discount, tax, total, notes, status, paid_at, kitchen_queued_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())");
    $stmt->execute([
        $order_no,
        $queue,
        $userId,
        'Walk-in Customer',
        $data['orderType'],
        $data['tableNo'] ?: null,
        $data['subtotal'],
        $data['discount'],
        $data['tax'],
        $data['total'],
        $orderNote
    ]);
    $order_id = $conn->lastInsertId();

    $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
    $deduct = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ? AND stock >= ?");
    $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");

    foreach ($cart as $row) {
        $itemId = (int) $row['id'];
        $qty = (int) $row['qty'];

        $item_stmt->execute([$order_id, $itemId, $row['name'], $qty, $row['price']]);

        $deduct->execute([$qty, $itemId, $qty]);

        if ($deduct->rowCount() !== 1) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Stock changed while the order was being processed. Please review the order and try again.']);
            exit;
        }

        $log->execute([$itemId, $userId, $qty, 'Order ' . $order_no . ' auto-deduction']);
    }


    $amountPaid = ($paymentMode === 'Gcash' || $paymentMode === 'GCash') ? $total : $cash;
    $changeAmount = ($paymentMode === 'Gcash' || $paymentMode === 'GCash') ? 0 : $change;

    $stmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, amount_paid, change_amount) VALUES (?, ?, ?, ?)");
    $stmt->execute([$order_id, $paymentMode, $amountPaid, $changeAmount]);

    $receipt_no = 'RCPT-' . date('Ymd') . '-' . str_pad($order_id, 5, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("INSERT INTO receipts (order_id, receipt_no) VALUES (?, ?)");
    $stmt->execute([$order_id, $receipt_no]);

    $conn->commit();
    echo json_encode(['ok' => true, 'order_no' => $order_no, 'receipt_no' => $receipt_no]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['ok' => false, 'msg' => 'Order was not saved. ' . $e->getMessage()]);
}
