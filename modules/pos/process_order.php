<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$cart = $data['cart'] ?? [];
$paymentMode = strtolower(trim((string)($data['paymentMode'] ?? 'cash')));
$cash = (float) ($data['cash'] ?? 0);
$gcashRef = trim((string)($data['gcashRef'] ?? ''));
$orderType = trim((string)($data['orderType'] ?? ''));
$tableNo = trim((string)($data['tableNo'] ?? ''));
$discountPercent = (float)($data['discountPercent'] ?? $data['discount_percent'] ?? 0);

if (!$cart || !in_array($paymentMode, ['cash', 'gcash'], true) || !in_array($orderType, ['DINE-IN', 'TAKE-OUT'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid order details.']);
    exit;
}

if ($discountPercent < 0 || $discountPercent > 100) {
    echo json_encode(['ok' => false, 'msg' => 'Discount must be between 0% and 100%.']);
    exit;
}

if ($paymentMode === 'gcash' && !preg_match('/^\d{13}$/', $gcashRef)) {
    echo json_encode(['ok' => false, 'msg' => 'Enter a valid 13-digit GCash reference number.']);
    exit;
}

if ($orderType === 'DINE-IN' && (!ctype_digit($tableNo) || (int)$tableNo < 1 || (int)$tableNo > 10)) {
    echo json_encode(['ok' => false, 'msg' => 'Dine-in table number must be from 1 to 10.']);
    exit;
}

if ($orderType !== 'DINE-IN') {
    $tableNo = '';
}

try {
    $conn->beginTransaction();

    $cols = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('notes', $cols, true)) {
        $conn->query("ALTER TABLE orders ADD COLUMN notes TEXT NULL");
    }

    $stockCheck = $conn->prepare("SELECT id, item_name, price, promo_price, promo_start, promo_end, stock, availability, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
    $normalized = [];
    $subtotal = 0.0;

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

        $price = (float)$item['price'];
        $today = date('Y-m-d');
        if (
            $item['promo_price'] !== null &&
            $item['promo_start'] !== null &&
            $item['promo_end'] !== null &&
            $today >= $item['promo_start'] &&
            $today <= $item['promo_end']
        ) {
            $price = (float)$item['promo_price'];
        }

        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;
        $normalized[] = [
            'id' => $itemId,
            'name' => $item['item_name'],
            'client_name' => trim((string)($row['name'] ?? '')),
            'qty' => $qty,
            'price' => $price
        ];
    }

    $discount = round($subtotal * ($discountPercent / 100), 2);
    $tax = 0.0;
    $total = round(max($subtotal - $discount + $tax, 0), 2);
    $change = $paymentMode === 'cash' ? round(max($cash - $total, 0), 2) : 0.0;

    if ($paymentMode === 'cash' && $cash < $total) {
        $conn->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Insufficient cash tendered.']);
        exit;
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
        $orderType,
        $tableNo !== '' ? $tableNo : null,
        $subtotal,
        $discount,
        $tax,
        $total,
        $orderNote
    ]);
    $order_id = $conn->lastInsertId();

    $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
    $deduct = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ? AND stock >= ?");
    $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");

    foreach ($normalized as $item) {
        $item_stmt->execute([$order_id, $item['id'], $item['client_name'] !== '' ? $item['client_name'] : $item['name'], $item['qty'], $item['price']]);

        $deduct->execute([$item['qty'], $item['id'], $item['qty']]);

        if ($deduct->rowCount() !== 1) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Stock changed while the order was being processed. Please review the order and try again.']);
            exit;
        }

        $log->execute([$item['id'], $userId, $item['qty'], 'Order ' . $order_no . ' auto-deduction']);
    }

    $amountPaid = $paymentMode === 'gcash' ? $total : $cash;
    $paymentMethod = $paymentMode === 'gcash' ? 'Gcash' : 'Cash';

    $stmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_reference, payment_status, amount_paid, change_amount) VALUES (?, ?, ?, 'Paid', ?, ?)");
    $stmt->execute([$order_id, $paymentMethod, $paymentMode === 'gcash' ? $gcashRef : null, $amountPaid, $change]);

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
