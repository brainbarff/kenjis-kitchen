<?php
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 3) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/config/bilao-pricing.php';
require_once __DIR__ . '/mail_helper.php';

function order_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function require_customer_id(PDO $conn, string $message = 'Please sign in or register before placing an order.'): int
{
    $id = (int)($_SESSION['customer_user_id'] ?? 0);
    if ($id <= 0) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        order_json(['status' => 'error', 'message' => $message], 401);
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (
        !$user
        || ($user['role_name'] ?? '') !== 'Customer (Online)'
        || ($user['status'] ?? '') !== 'Active'
        || ($user['customer_status'] ?? 'Active') !== 'Active'
        || empty($user['email_verified_at'])
        || !empty($user['deleted_at'])
    ) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        order_json(['status' => 'error', 'message' => $message], 401);
    }

    return (int)$user['id'];
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'track' || $action === 'detail') {
        $customerId = require_customer_id($conn, 'Sign in to view this order.');
        $orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$orderId || $orderId < 1) order_json(['status' => 'error', 'message' => 'Invalid order.'], 400);

        $stmt = $conn->prepare("SELECT o.id, o.order_no, o.queue_no, o.order_type, o.status, o.customer_name, o.created_at, o.preparing_at, o.ready_at, o.served_at, o.total, o.delivery_address, p.payment_method, p.payment_reference, p.payment_status, r.receipt_no, r.email_status AS receipt_email_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN receipts r ON r.order_id = o.id WHERE o.id = ? AND o.user_id = ? AND o.order_channel = 'ONLINE' LIMIT 1");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();
        if (!$order) order_json(['status' => 'error', 'message' => 'Order not found.'], 404);

        $itemsStmt = $conn->prepare('SELECT item_name, quantity, price, notes FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $itemsStmt->execute([$orderId]);
        order_json(['status' => 'success', 'data' => ['order' => $order, 'items' => $itemsStmt->fetchAll()]]);
    }

    if ($action === 'latest' || $action === 'history') {
        $customerId = require_customer_id($conn, 'Sign in to view your orders.');

        if ($action === 'latest') {
            $stmt = $conn->prepare("SELECT o.id, o.order_no, o.queue_no, o.order_type, o.status, o.customer_name, o.created_at, o.preparing_at, o.ready_at, o.served_at, o.total, p.payment_method, p.payment_reference, p.payment_status, r.receipt_no, r.email_status AS receipt_email_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN receipts r ON r.order_id = o.id WHERE o.user_id = ? AND o.order_channel = 'ONLINE' AND o.status IN ('Pending', 'Preparing', 'Ready') ORDER BY o.id DESC LIMIT 1");
            $stmt->execute([$customerId]);
            $order = $stmt->fetch();
            if (!$order) order_json(['status' => 'success', 'data' => null]);
            order_json(['status' => 'success', 'data' => $order]);
        }

        $stmt = $conn->prepare("SELECT o.id, o.order_no, o.queue_no, o.order_type, o.status, o.created_at, o.preparing_at, o.ready_at, o.served_at, o.total, p.payment_method, p.payment_status, r.receipt_no, r.email_status AS receipt_email_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN receipts r ON r.order_id = o.id WHERE o.user_id = ? AND o.order_channel = 'ONLINE' ORDER BY o.id DESC LIMIT 50");
        $stmt->execute([$customerId]);
        order_json(['status' => 'success', 'data' => $stmt->fetchAll()]);
    }

    order_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    order_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

$customerId = require_customer_id($conn);
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
if (mb_strlen($notes, 'UTF-8') > 1000) {
    order_json(['status' => 'error', 'message' => 'Overall order notes exceed the 1000-character limit. Please shorten your notes.'], 400);
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

    $requestedByMenuId = [];
    foreach ($data['items'] as $entry) {
        $menuId = filter_var($entry['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$menuId || $menuId < 1 || !$qty || $qty < 1 || $qty > 99) {
            throw new InvalidArgumentException('One or more cart quantities are invalid.');
        }
        $requestedByMenuId[$menuId] = ($requestedByMenuId[$menuId] ?? 0) + $qty;
    }

    ksort($requestedByMenuId);

    $menuStmt = $conn->prepare("SELECT m.id, m.item_name, m.price, m.promo_price, m.promo_start, m.promo_end, m.availability, m.is_archived, m.stock, c.category_name FROM menu_items m JOIN categories c ON c.id = m.category_id WHERE m.id = ? FOR UPDATE");
    $lockedProducts = [];

    foreach ($requestedByMenuId as $menuId => $totalQty) {
        $menuStmt->execute([$menuId]);
        $product = $menuStmt->fetch();
        if (!$product || (int)$product['is_archived'] === 1 || $product['availability'] !== 'Available') {
            throw new RuntimeException('One of the selected items is unavailable.');
        }
        if ((int)$product['stock'] < $totalQty) {
            throw new RuntimeException($product['item_name'] . ' does not have enough stock.');
        }
        $lockedProducts[$menuId] = $product;
    }

    $normalized = [];
    $subtotal = 0.0;

    foreach ($data['items'] as $entry) {
        $menuId = (int)filter_var($entry['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = (int)filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
        $product = $lockedProducts[$menuId];

        $requestedSize = strtoupper(trim((string)($entry['variant_size'] ?? '')));
        $isBilao = ($product['category_name'] ?? '') === 'Bilao Favorites';
        $dishName = $isBilao ? bilao_dish_from_menu_name((string)$product['item_name']) : null;
        if ($isBilao) {
            if (!$dishName || !in_array($requestedSize, ['S', 'M', 'L', 'XL'], true)) {
                throw new InvalidArgumentException('Please remove this bilao item from your cart, add it again, and select a size.');
            }
            $packaging = strtoupper(trim((string)($entry['packaging'] ?? '')));
            if (!in_array($packaging, ['TRAY', 'BILAO'], true)) {
                throw new InvalidArgumentException('Please choose either Tray or Bilao packaging for ' . $dishName . '.');
            }
            $packagingLabel = ($packaging === 'TRAY') ? 'Tray' : 'Bilao';
            $price = bilao_price($dishName, $requestedSize);
            if ($price === null) {
                throw new InvalidArgumentException('The selected bilao size does not have a valid price.');
            }
            $itemName = $dishName . ' ' . $packagingLabel . ' (' . $requestedSize . ')';
        } else {
            $packagingLabel = null;
            $price = (float)$product['price'];
            $today = date('Y-m-d');
            if ($product['promo_price'] !== null && $product['promo_start'] !== null && $product['promo_end'] !== null && $today >= $product['promo_start'] && $today <= $product['promo_end']) {
                $price = (float)$product['promo_price'];
            }
            $itemName = (string)$product['item_name'];
        }

        $customerNote = trim(strip_tags((string)($entry['notes'] ?? '')));
        if ($isBilao) {
            $combinedNote = ($customerNote !== '')
                ? 'Packaging: ' . $packagingLabel . ' | Customer note: ' . $customerNote
                : 'Packaging: ' . $packagingLabel;
        } else {
            $combinedNote = $customerNote;
        }

        $noteLen = mb_strlen($combinedNote, 'UTF-8');
        if ($noteLen > 255) {
            throw new InvalidArgumentException('Special instructions for ' . $itemName . ' exceed the 255-character limit (current length: ' . $noteLen . ' characters). Please shorten your note.');
        }
        $itemNote = $combinedNote !== '' ? $combinedNote : null;

        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;
        $normalized[] = [
            'id' => $menuId,
            'name' => $itemName,
            'qty' => $qty,
            'price' => $price,
            'line_total' => $lineTotal,
            'notes' => $itemNote
        ];
    }

    $total = round($subtotal, 2);
    $queueStmt = $conn->query("SELECT COALESCE(MAX(queue_no), 0) + 1 FROM orders WHERE DATE(created_at) = CURDATE()");
    $queueNo = (int)$queueStmt->fetchColumn();
    $orderNo = 'ONL-' . date('Ymd') . '-' . str_pad((string)$queueNo, 4, '0', STR_PAD_LEFT);

    $orderStmt = $conn->prepare("INSERT INTO orders (order_no, queue_no, user_id, customer_name, customer_phone, order_type, delivery_address, order_channel, subtotal, discount, tax, total, status, kitchen_queued_at, notes, inventory_deducted, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'ONLINE', ?, 0, 0, ?, 'Pending', NOW(), ?, 1, NOW())");
    $orderType = $fulfillment === 'DELIVERY' ? 'ONLINE' : 'TAKE-OUT';
    $orderStmt->execute([$orderNo, $queueNo, $customerId, $name, $phone, $orderType, $fulfillment === 'DELIVERY' ? $address : null, $subtotal, $total, $notes !== '' ? $notes : null]);
    $orderId = (int)$conn->lastInsertId();
    if ($orderId <= 0) {
        throw new RuntimeException('Unable to create order record.');
    }

    $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price, notes) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($normalized as $item) {
        $itemStmt->execute([$orderId, $item['id'], $item['name'], $item['qty'], $item['price'], $item['notes']]);
        if ($itemStmt->rowCount() !== 1) {
            throw new RuntimeException('Unable to create order item record.');
        }
    }

    $deductStmt = $conn->prepare("UPDATE menu_items SET stock = stock - ?, availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE availability END WHERE id = ? AND is_archived = 0 AND availability = 'Available' AND stock >= ?");
    $logStmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");
    foreach ($requestedByMenuId as $menuId => $totalQty) {
        $deductStmt->execute([$totalQty, $menuId, $totalQty]);
        if ($deductStmt->rowCount() !== 1) {
            throw new RuntimeException(($lockedProducts[$menuId]['item_name'] ?? 'Selected item') . ' does not have enough stock.');
        }
        $logStmt->execute([$menuId, $customerId, $totalQty, 'Online order ' . $orderNo . ' auto-deduction']);
        if ($logStmt->rowCount() !== 1) {
            throw new RuntimeException('Unable to record inventory deduction.');
        }
    }

    $paymentStatus = 'Pending';
    $paymentLabel = $paymentMethod === 'GCASH' ? 'GCash' : ($fulfillment === 'PICKUP' ? 'Pay on Pickup' : 'Cash on Delivery');
    $payStmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_reference, payment_status, amount_paid, change_amount) VALUES (?, ?, ?, ?, 0.00, 0)");
    $payStmt->execute([$orderId, $paymentLabel, $paymentReference !== '' ? $paymentReference : null, $paymentStatus]);
    if ($payStmt->rowCount() !== 1) {
        throw new RuntimeException('Unable to create payment record.');
    }
    $paymentId = (int)$conn->lastInsertId();
    if ($paymentId <= 0) {
        throw new RuntimeException('Unable to create payment record.');
    }

    if ($paymentMethod === 'GCASH') {
        try {
            $regStmt = $conn->prepare("INSERT INTO payment_reference_registry (reference_type, reference_no, order_id, payment_id, channel) VALUES ('GCASH', ?, ?, ?, 'ONLINE')");
            $regStmt->execute([$paymentReference, $orderId, $paymentId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)) {
                throw new RuntimeException('This GCash reference number has already been used for another order.');
            }
            throw $e;
        }
    }

    $receiptNo = 'RCPT-' . date('Ymd') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
    $receiptStmt = $conn->prepare("INSERT INTO receipts (order_id, receipt_no) VALUES (?, ?)");
    $receiptStmt->execute([$orderId, $receiptNo]);
    if ($receiptStmt->rowCount() !== 1) {
        throw new RuntimeException('Unable to create receipt record.');
    }
    $conn->commit();

    require_once dirname(__DIR__, 3) . '/includes/realtime.php';
    if (function_exists('realtime_publish')) {
        realtime_publish('order:submitted', [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'queue_no' => $queueNo,
            'status' => 'Pending',
            'order_type' => $orderType,
            'order_channel' => 'ONLINE',
            'total' => $total
        ]);
    }

    $breakdown = array_map(static function ($item) {
        return [
            'name' => $item['name'],
            'qty' => $item['qty'],
            'unit_price' => number_format($item['price'], 2, '.', ''),
            'subtotal' => number_format($item['line_total'], 2, '.', '')
        ];
    }, $normalized);

    $emailStmt = $conn->prepare("SELECT email FROM users WHERE id = ? AND status = 'Active' LIMIT 1");
    $emailStmt->execute([$customerId]);
    $customerEmail = strtolower(trim((string)$emailStmt->fetchColumn()));
    $receiptEmailSent = false;
    if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $emailRows = '';
        $textRows = [];
        foreach ($breakdown as $item) {
            $emailRows .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">' . $escape($item['name']) . '</td>' .
                '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center">' . (int)$item['qty'] . '</td>' .
                '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right">₱' . $escape($item['subtotal']) . '</td></tr>';
            $textRows[] = $item['qty'] . ' x ' . $item['name'] . ' - ₱' . $item['subtotal'];
        }
        $receiptHtml = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:20px auto;padding:24px;color:#252b33">' .
            '<h1 style="margin:0 0 4px">Kenji\'s Kitchen</h1><p style="color:#667085">Order receipt</p>' .
            '<p>Hi ' . $escape($name) . ', thank you for your order.</p>' .
            '<p><strong>Order:</strong> ' . $escape($orderNo) . '<br><strong>Receipt:</strong> ' . $escape($receiptNo) .
            '<br><strong>Placed:</strong> ' . $escape(date('M j, Y g:i A')) . '<br><strong>Status:</strong> Pending</p>' .
            '<table style="width:100%;border-collapse:collapse"><thead><tr><th align="left">Item</th><th>Qty</th><th align="right">Amount</th></tr></thead><tbody>' . $emailRows . '</tbody></table>' .
            '<p style="text-align:right;font-size:18px"><strong>Total: ₱' . number_format($total, 2) . '</strong></p>' .
            '<p>Track this order from the My Orders section on the Kenji\'s Kitchen online store.</p></div>';
        $receiptText = "Kenji's Kitchen receipt\nOrder: {$orderNo}\nReceipt: {$receiptNo}\nStatus: Pending\n\n" .
            implode("\n", $textRows) . "\n\nTotal: ₱" . number_format($total, 2);
        $mailResult = send_app_email($customerEmail, "Order receipt {$orderNo} - Kenji's Kitchen", $receiptText, $receiptHtml);
        $receiptEmailSent = $mailResult['ok'];
    }
    $emailStatus = $receiptEmailSent ? 'Sent' : 'Failed';
    $conn->prepare('UPDATE receipts SET email_status = ?, email_sent_at = ? WHERE order_id = ?')
        ->execute([$emailStatus, $receiptEmailSent ? date('Y-m-d H:i:s') : null, $orderId]);

    order_json([
        'status' => 'success',
        'message' => $receiptEmailSent ? 'Order placed. Your receipt was sent to your email inbox.' : 'Order placed, but the receipt email could not be delivered.',
        'data' => [
            'order_id' => $orderId,
            'order_number' => $orderNo,
            'queue_no' => $queueNo,
            'receipt_no' => $receiptNo,
            'receipt_email_sent' => $receiptEmailSent,
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
