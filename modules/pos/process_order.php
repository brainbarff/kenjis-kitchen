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
    if (!in_array('notes', $cols)) {
        $conn->query("ALTER TABLE orders ADD COLUMN notes TEXT NULL");
    }

    $stock_check = $conn->prepare("
        SELECT inventory.ingredient_name
        FROM menu_ingredients
        JOIN inventory ON inventory.id = menu_ingredients.inventory_id
        JOIN menu_items ON menu_items.id = menu_ingredients.menu_item_id
        WHERE menu_ingredients.menu_item_id = ?
          AND (inventory.current_stock < (menu_ingredients.qty_needed * ?) OR menu_items.availability <> 'Available')
        LIMIT 1
    ");

    foreach ($cart as $row) {
        $stock_check->execute([$row['id'], $row['qty']]);
        $short = $stock_check->fetch();

        if ($short) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => $short['ingredient_name'] . ' is not enough for this order.']);
            exit;
        }
    }

    $queue = (int) $conn->query("SELECT COALESCE(MAX(queue_no), 0) + 1 q FROM orders WHERE DATE(created_at) = CURDATE()")->fetch()['q'];
    $order_no = 'ORD-' . date('Ymd') . '-' . str_pad($queue, 4, '0', STR_PAD_LEFT);
    $orderNote = !empty($data['orderNote']) ? trim($data['orderNote']) : null;
    $userId = $_SESSION['u_id'] ?? ($_SESSION['user_id'] ?? 1);

    $stmt = $conn->prepare("
        INSERT INTO orders (order_no, queue_no, user_id, customer_name, order_type, table_no, subtotal, discount, tax, total, notes, status, paid_at, kitchen_queued_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())
    ");
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
    $deduct = $conn->prepare("
        UPDATE inventory
        JOIN menu_ingredients ON menu_ingredients.inventory_id = inventory.id
        SET inventory.current_stock = inventory.current_stock - (menu_ingredients.qty_needed * ?)
        WHERE menu_ingredients.menu_item_id = ?
    ");
    $log = $conn->prepare("
        INSERT INTO inventory_logs (inventory_id, user_id, action, quantity, remarks)
        SELECT inventory_id, ?, 'Order Deduction', qty_needed * ?, ?
        FROM menu_ingredients
        WHERE menu_item_id = ?
    ");

    foreach ($cart as $row) {
        $item_stmt->execute([$order_id, $row['id'], $row['name'], $row['qty'], $row['price']]);
        $deduct->execute([$row['qty'], $row['id']]);
        $log->execute([$userId, $row['qty'], 'Order ' . $order_no, $row['id']]);
    }

    $conn->exec("
        UPDATE menu_items
        SET availability = 'Unavailable'
        WHERE id IN (
            SELECT menu_item_id
            FROM menu_ingredients
            JOIN inventory ON inventory.id = menu_ingredients.inventory_id
            WHERE inventory.current_stock <= 0
        )
    ");

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
    $conn->rollBack();
    echo json_encode(['ok' => false, 'msg' => 'Order was not saved. ' . $e->getMessage()]);
}