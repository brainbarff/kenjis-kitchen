<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/config/config.php';

function order_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function require_customer_id()
{
    $id = (int)($_SESSION['customer_user_id'] ?? 0);
    if ($id <= 0) {
        order_json(['status' => 'error', 'message' => 'Please sign in or register before placing an order.'], 401);
    }
    return $id;
}

$action = $_GET['action'] ?? '';
$customerId = (int)($_SESSION['customer_user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'latest' || $action === 'history') {
        if ($customerId <= 0) {
            order_json(['status' => 'success', 'data' => []]);
        }

        if ($action === 'latest') {
            $stmt = $conn->prepare("SELECT o.id, o.order_no, o.order_type, o.status, o.customer_name, o.created_at, o.total, p.payment_method, p.payment_reference, p.payment_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.user_id = ? AND o.order_channel = 'ONLINE' AND o.status IN ('Pending', 'Preparing', 'Ready') ORDER BY o.id DESC LIMIT 1");
            $stmt->execute([$customerId]);
            $order = $stmt->fetch();
            if (!$order) order_json(['status' => 'success', 'data' => null]);
            order_json(['status' => 'success', 'data' => $order]);
        }

        $stmt = $conn->prepare("SELECT o.id, o.order_no, o.order_type, o.status, o.created_at, o.total, p.payment_method, p.payment_reference, p.payment_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.user_id = ? AND o.order_channel = 'ONLINE' ORDER BY o.id DESC LIMIT 20");
        $stmt->execute([$customerId]);
        order_json(['status' => 'success', 'data' => $stmt->fetchAll()]);
    }

    order_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    order_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

$customerId = require_customer_id();
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !is_array($data['items'] ?? null) || count($data['items']) < 1 || count($data['items']) > 50) {
    order_json(['status' => 'error', 'message' => 'Your cart is empty or invalid.'], 400);
}

$fulfillment = strtoupper(trim((string)($data['fulfillment'] ?? 'PICKUP')));
if (!in_array($fulfillment, ['DELIVERY', 'PICKUP'], true)) {
    order_json(['status' => 'error', 'message' => 'Invalid order type.'], 400);
}

$paymentMethod = strtoupper(trim((string)($data['payment_method'] ?? 'CASH')));
if (!in_array($paymentMethod, ['CASH', 'GCASH'], true)) {
    order_json(['status' => 'error', 'message' => 'Invalid payment method.'], 400);
}

$name = trim(strip_tags((string)($data['customer_name'] ?? '')));
$phone = preg_replace('/[^0-9]/', '', (string)($data['customer_phone'] ?? ''));
$address = trim(strip_tags((string)($data['delivery_address'] ?? '')));
$notes = trim(strip_tags((string)($data['notes'] ?? '')));
$paymentReference = trim((string)($data['payment_reference'] ?? ''));

if ($name === '' || mb_strlen($name) > 100) {
    order_json(['status' => 'error', 'message' => 'Enter a valid customer name.'], 400);
}
if (!preg_match('/^09[0-9]{9}$/', $phone)) {
    order_json(['status' => 'error', 'message' => 'Enter a valid 11-digit Philippine mobile number.'], 400);
}
if ($fulfillment === 'DELIVERY' && ($address === '' || mb_strlen($address) > 500)) {
    order_json(['status' => 'error', 'message' => 'A delivery address is required.'], 400);
}
if (mb_strlen($notes) > 1000) {
    order_json(['status' => 'error', 'message' => 'Order notes are too long.'], 400);
}
if ($paymentMethod === 'GCASH' && !preg_match('/^[0-9]{13}$/', $paymentReference)) {
    order_json(['status' => 'error', 'message' => 'Enter a valid 13-digit GCash reference number.'], 400);
}
if ($paymentMethod === 'CASH') {
    $paymentReference = '';
}

try {
    $conn->beginTransaction();

    $profile = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, customer_address = CASE WHEN ? = '' THEN customer_address ELSE ? END WHERE id = ? AND status = 'Active'");
    $profile->execute([$name, $phone, $address, $address, $customerId]);
    if ($profile->rowCount() < 0) {
        throw new RuntimeException('Unable to update customer profile.');
    }

    $menuStmt = $conn->prepare("SELECT id, item_name, price, promo_price, promo_start, promo_end, availability, is_archived, stock FROM menu_items WHERE id = ? FOR UPDATE");
    $seen = [];
    $normalized = [];
    $subtotal = 0.0;

    foreach ($data['items'] as $entry) {
        $menuId = filter_var($entry['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$menuId || !$qty || $qty < 1 || $qty > 99) {
            throw new InvalidArgumentException('One or more cart quantities are invalid.');
        }
        if (isset($seen[$menuId])) {
            throw new InvalidArgumentException('Duplicate menu items were detected in your cart.');
        }
        $seen[$menuId] = true;

        $menuStmt->execute([$menuId]);
        $product = $menuStmt->fetch();
        if (!$product || (int)$product['is_archived'] === 1 || $product['availability'] !== 'Available') {
            throw new RuntimeException('One of the selected items is unavailable.');
        }
        if ((int)$product['stock'] < $qty) {
            throw new RuntimeException($product['item_name'] . ' does not have enough stock.');
        }

        $price = (float)$product['price'];
        $today = date('Y-m-d');
        if ($product['promo_price'] !== null && $product['promo_start'] !== null && $product['promo_end'] !== null && $today >= $product['promo_start'] && $today <= $product['promo_end']) {
            $price = (float)$product['promo_price'];
        }

        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;
        $normalized[] = [
            'id' => $menuId,
            'name' => $product['item_name'],
            'qty' => $qty,
            'price' => $price,
            'line_total' => $lineTotal
        ];
    }

    $total = round($subtotal, 2);
    $queueStmt = $conn->query("SELECT COALESCE(MAX(queue_no), 0) + 1 FROM orders WHERE DATE(created_at) = CURDATE()");
    $queueNo = (int)$queueStmt->fetchColumn();
    $orderNo = 'ONL-' . date('Ymd') . '-' . str_pad((string)$queueNo, 4, '0', STR_PAD_LEFT);

    $orderStmt = $conn->prepare("INSERT INTO orders (order_no, queue_no, user_id, customer_name, customer_phone, order_type, delivery_address, order_channel, subtotal, discount, tax, total, status, kitchen_queued_at, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'ONLINE', ?, 0, 0, ?, 'Pending', NOW(), ?, NOW())");
    $orderType = $fulfillment === 'DELIVERY' ? 'ONLINE' : 'TAKE-OUT';
    $orderStmt->execute([$orderNo, $queueNo, $customerId, $name, $phone, $orderType, $fulfillment === 'DELIVERY' ? $address : null, $subtotal, $total, $notes !== '' ? $notes : null]);
    $orderId = (int)$conn->lastInsertId();

    $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price, notes) VALUES (?, ?, ?, ?, ?, NULL)");
    $stockStmt = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ? AND stock >= ? AND is_archived = 0 AND availability = 'Available'");
    $logStmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");

    foreach ($normalized as $item) {
        $itemStmt->execute([$orderId, $item['id'], $item['name'], $item['qty'], $item['price']]);
        $stockStmt->execute([$item['qty'], $item['id'], $item['qty']]);
        if ($stockStmt->rowCount() !== 1) {
            throw new RuntimeException('Stock changed while placing your order. Please try again.');
        }
        $logStmt->execute([$item['id'], $customerId, $item['qty'], 'Online order ' . $orderNo]);
    }

    $paymentStatus = $paymentMethod === 'GCASH' ? 'Pending' : 'Pending';
    $paymentLabel = $paymentMethod === 'GCASH' ? 'GCash' : ($fulfillment === 'PICKUP' ? 'Pay on Pickup' : 'Cash on Delivery');
    $payStmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_reference, payment_status, amount_paid, change_amount) VALUES (?, ?, ?, ?, ?, 0)");
    $payStmt->execute([$orderId, $paymentLabel, $paymentReference !== '' ? $paymentReference : null, $paymentStatus, 0]);

    $receiptNo = 'RCPT-' . date('Ymd') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
    $receiptStmt = $conn->prepare("INSERT INTO receipts (order_id, receipt_no) VALUES (?, ?)");
    $receiptStmt->execute([$orderId, $receiptNo]);

    $conn->commit();

    $breakdown = array_map(static function ($item) {
        return [
            'name' => $item['name'],
            'qty' => $item['qty'],
            'unit_price' => number_format($item['price'], 2, '.', ''),
            'subtotal' => number_format($item['line_total'], 2, '.', '')
        ];
    }, $normalized);

    order_json([
        'status' => 'success',
        'message' => 'Order placed successfully.',
        'data' => [
            'order_id' => $orderId,
            'order_number' => $orderNo,
            'queue_no' => $queueNo,
            'receipt_no' => $receiptNo,
            'customer_name' => $name,
            'fulfillment' => $fulfillment,
            'order_type' => $orderType,
            'payment_method' => $paymentLabel,
            'payment_reference' => $paymentReference,
            'payment_status' => $paymentStatus,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'total_amount' => number_format($total, 2, '.', ''),
            'status' => 'Pending',
            'items' => $breakdown
        ]
    ], 201);
} catch (InvalidArgumentException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    order_json(['status' => 'error', 'message' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    order_json(['status' => 'error', 'message' => $e->getMessage()], 409);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('Online order error: ' . $e->getMessage());
    order_json(['status' => 'error', 'message' => 'Unable to place the order right now.'], 500);
}
