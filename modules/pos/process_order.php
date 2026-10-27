<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/config/bilao-pricing.php';
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

$cashierAttested = !empty($data['cashierAttested']);

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

if ($paymentMode === 'gcash' && !$cashierAttested) {
    echo json_encode(['ok' => false, 'msg' => 'Cashier must attest that payment was verified on merchant phone / POS terminal.']);
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

    $requestedByMenuId = [];
    foreach ($cart as $row) {
        $itemId = (int) ($row['id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);

        if ($itemId <= 0 || $qty <= 0) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Invalid item quantity.']);
            exit;
        }

        $requestedByMenuId[$itemId] = ($requestedByMenuId[$itemId] ?? 0) + $qty;
    }

    ksort($requestedByMenuId);

    $stockCheck = $conn->prepare("SELECT m.id, m.item_name, m.price, m.promo_price, m.promo_start, m.promo_end, m.stock, m.availability, m.is_archived, c.category_name FROM menu_items m JOIN categories c ON c.id = m.category_id WHERE m.id = ? FOR UPDATE");
    $lockedItems = [];

    foreach ($requestedByMenuId as $itemId => $totalRequestedQty) {
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

        if ((int) $item['stock'] < $totalRequestedQty) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Only ' . (int) $item['stock'] . ' pcs of ' . $item['item_name'] . ' are available.']);
            exit;
        }

        $lockedItems[$itemId] = $item;
    }

    $normalized = [];
    $subtotal = 0.0;

    foreach ($cart as $row) {
        $itemId = (int) ($row['id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);
        $item = $lockedItems[$itemId];

        $clientName = trim((string)($row['name'] ?? ''));
        $displayName = $clientName !== '' ? $clientName : $item['item_name'];
        $price = (float)$item['price'];

        if ($item['category_name'] === 'Bilao Favorites') {
            $dish = bilao_dish_from_menu_name((string)$item['item_name']);
            $validDishes = implode('|', array_map(static fn($name) => preg_quote($name, '/'), array_keys(bilao_pricing_specs())));
            if (!$dish || !preg_match('/^(' . $validDishes . ')\s+(S|M|L|XL)$/i', $clientName, $match) || strcasecmp($match[1], $dish) !== 0) {
                throw new InvalidArgumentException('Choose a valid bilao item and size before adding it to the order.');
            }
            $size = strtoupper($match[2]);
            $price = bilao_price($dish, $size);
            if ($price === null) {
                throw new InvalidArgumentException('The selected bilao size does not have a valid price.');
            }
            $displayName = $dish . ' ' . $size;
        } else {
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
        }

        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;
        $normalized[] = [
            'id' => $itemId,
            'name' => $item['item_name'],
            'client_name' => $displayName,
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

    $stmt = $conn->prepare("INSERT INTO orders (order_no, queue_no, user_id, customer_name, order_type, table_no, order_channel, subtotal, discount, tax, total, notes, status, inventory_deducted, paid_at, kitchen_queued_at) VALUES (?, ?, ?, ?, ?, ?, 'POS', ?, ?, ?, ?, ?, 'Pending', 1, NOW(), NOW())");
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
    $order_id = (int) $conn->lastInsertId();
    if ($order_id <= 0) {
        throw new RuntimeException('Failed to create order record.');
    }

    $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
    foreach ($normalized as $item) {
        $item_stmt->execute([$order_id, $item['id'], $item['client_name'] !== '' ? $item['client_name'] : $item['name'], $item['qty'], $item['price']]);
        if ($item_stmt->rowCount() !== 1) {
            throw new RuntimeException('Failed to create order item record.');
        }
    }

    $deduct = $conn->prepare("UPDATE menu_items SET stock = stock - ?, availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE availability END WHERE id = ? AND is_archived = 0 AND availability = 'Available' AND stock >= ?");
    // This records menu stock changes, which are not tied to one ingredient row.
    // inventory_id is therefore intentionally NULL (schema permits it).
    $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");

    foreach ($requestedByMenuId as $itemId => $totalRequestedQty) {
        $deduct->execute([$totalRequestedQty, $itemId, $totalRequestedQty]);

        if ($deduct->rowCount() !== 1) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Stock changed while the order was being processed. Please review the order and try again.']);
            exit;
        }

        $log->execute([$itemId, $userId, $totalRequestedQty, 'Order ' . $order_no . ' auto-deduction']);
        if ($log->rowCount() !== 1) {
            throw new RuntimeException('Failed to create inventory log record.');
        }
    }

    $amountPaid = $paymentMode === 'gcash' ? $total : $cash;
    $paymentMethod = $paymentMode === 'gcash' ? 'GCash' : 'Cash';

    $stmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_reference, payment_status, amount_paid, change_amount) VALUES (?, ?, ?, 'Paid', ?, ?)");
    $stmt->execute([$order_id, $paymentMethod, $paymentMode === 'gcash' ? $gcashRef : null, $amountPaid, $change]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Failed to create payment record.');
    }
    $payment_id = (int) $conn->lastInsertId();
    if ($payment_id <= 0) {
        throw new RuntimeException('Failed to retrieve payment record ID.');
    }

    if ($paymentMode === 'gcash') {
        try {
            $regStmt = $conn->prepare("INSERT INTO payment_reference_registry (reference_type, reference_no, order_id, payment_id, channel) VALUES ('GCASH', ?, ?, ?, 'POS')");
            $regStmt->execute([$gcashRef, $order_id, $payment_id]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)) {
                echo json_encode(['ok' => false, 'msg' => 'This GCash reference number has already been used for another order.']);
                exit;
            }
            throw $e;
        }

        $auditStmt = $conn->prepare("INSERT INTO payment_verification_audits (payment_id, order_id, verified_by, previous_status, new_status, verification_source, notes) VALUES (?, ?, ?, 'Pending', 'Paid', 'POS_CASHIER', ?)");
        $auditStmt->execute([$payment_id, $order_id, $userId, 'Cashier verified on merchant device at POS checkout']);
        if ($auditStmt->rowCount() !== 1) {
            throw new RuntimeException('Failed to record payment verification audit.');
        }
    }

    $receipt_no = 'RCPT-' . date('Ymd') . '-' . str_pad((string)$order_id, 5, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("INSERT INTO receipts (order_id, receipt_no) VALUES (?, ?)");
    $stmt->execute([$order_id, $receipt_no]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Failed to create receipt record.');
    }

    $conn->commit();
    echo json_encode(['ok' => true, 'order_no' => $order_no, 'receipt_no' => $receipt_no]);
} catch (InvalidArgumentException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('POS order processing failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Order was not saved because of a system error. Please try again.']);
}
