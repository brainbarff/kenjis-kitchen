<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/config/bilao-pricing.php';
require_once ROOT_PATH . '/includes/order_queue.php';
require_once ROOT_PATH . '/includes/pos_cashier.php';
require_once ROOT_PATH . '/includes/checkout_requests.php';
require_once ROOT_PATH . '/api/price_helpers.php';
require_once ROOT_PATH . '/includes/gcash_verification.php';
require_role(['admin', 'cashier']);

processPendingPriceUpdates($conn);
expire_overdue_gcash_orders($conn, (int)(current_user()['id'] ?? 0) ?: null);

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}
if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Invalid security token. Reload POS and try again.']);
    exit;
}
try {
    $activeCashier = pos_active_cashier($conn);
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
try {
    if (!is_array($data)) throw new InvalidArgumentException('Invalid checkout request.');
    $requestKey = checkout_request_key($data['request_key'] ?? null);
    $requestHash = checkout_request_hash($data);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}
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
    $replay = checkout_request_claim($conn, 'POS', (int)$activeCashier['id'], $requestKey, $requestHash);
    if ($replay !== null) {
        $conn->rollBack();
        echo json_encode($replay);
        exit;
    }

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

    $stockCheck = $conn->prepare("SELECT m.id, m.item_name, m.price, m.promo_price, m.promo_start, m.promo_end, m.stock, m.availability, m.is_archived, m.category_id, c.category_name, c.status AS category_status FROM menu_items m JOIN categories c ON c.id = m.category_id WHERE m.id = ? FOR UPDATE");
    $lockedItems = [];

    foreach ($requestedByMenuId as $itemId => $totalRequestedQty) {
        $stockCheck->execute([$itemId]);
        $item = $stockCheck->fetch();

        if (!$item || (int) $item['is_archived'] === 1 || ($item['category_status'] ?? 'Active') !== 'Active') {
            $conn->rollBack();
            echo json_encode([
                'ok' => false,
                'msg' => ($item['item_name'] ?? 'One of the selected menu items') . ' is archived or no longer available.',
                'stock_updates' => [['id' => $itemId, 'stock' => 0, 'availability' => 'Unavailable']]
            ]);
            exit;
        }

        if ($item['availability'] !== 'Available') {
            $conn->rollBack();
            echo json_encode([
                'ok' => false,
                'msg' => $item['item_name'] . ' is currently unavailable.',
                'stock_updates' => [['id' => $itemId, 'stock' => max(0, (int)$item['stock']), 'availability' => (string)$item['availability']]]
            ]);
            exit;
        }

        $availStock = max(0, (int)$item['stock']);
        if ($availStock < $totalRequestedQty) {
            $conn->rollBack();
            $freshStmt = $conn->prepare("SELECT id, stock, availability FROM menu_items WHERE id IN (" . implode(',', array_fill(0, count($requestedByMenuId), '?')) . ")");
            $freshStmt->execute(array_keys($requestedByMenuId));
            $freshUpdates = array_map(static function (array $r): array {
                return [
                    'id' => (int)$r['id'],
                    'stock' => max(0, (int)$r['stock']),
                    'availability' => (string)$r['availability'],
                ];
            }, $freshStmt->fetchAll(PDO::FETCH_ASSOC));
            $msg = $availStock <= 0
                ? ($item['item_name'] . ' is currently out of stock — remaining stock was claimed by an earlier walk-in or online order.')
                : ('Only ' . $availStock . ' pcs of ' . $item['item_name'] . ' remain (remaining stock was claimed by an earlier walk-in or online order).');
            echo json_encode([
                'ok' => false,
                'msg' => $msg,
                'stock_updates' => $freshUpdates ?: [['id' => $itemId, 'stock' => $availStock, 'availability' => (string)$item['availability']]]
            ]);
            exit;
        }

        $lockedItems[$itemId] = $item;
    }

    $normalized = [];
    $subtotal = 0.0;
    $today = date('Y-m-d');

    foreach ($cart as $row) {
        $itemId = (int) ($row['id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);
        $item = $lockedItems[$itemId];

        if ($item['category_name'] === 'Drinks' && is_one_liter_grouped_drink_name((string)$item['item_name'])) {
            throw new InvalidArgumentException('Assorted 1L Bottled Drink has been retired. Select an individual 1L drink product instead.');
        }

        $clientName = trim((string)($row['name'] ?? ''));
        $displayName = $clientName !== '' ? $clientName : $item['item_name'];
        $itemNotes = null;

        // Always derive price from the database record (including active promo_price)
        $price = (float)$item['price'];
        if (
            $item['promo_price'] !== null &&
            $item['promo_start'] !== null &&
            $item['promo_end'] !== null &&
            $today >= $item['promo_start'] &&
            $today <= $item['promo_end']
        ) {
            $price = (float)$item['promo_price'];
        }

        if ($item['category_name'] === 'Bilao Favorites') {
            $dish = bilao_dish_from_menu_name((string)$item['item_name']);
            $validDishes = implode('|', array_map(static fn($name) => preg_quote($name, '/'), array_keys(bilao_pricing_specs())));

            $size = strtoupper(trim((string)($row['variantSize'] ?? $row['variant_size'] ?? '')));
            $packagingRaw = strtoupper(trim((string)($row['packaging'] ?? '')));

            if (preg_match('/^(' . $validDishes . ')\s+(Tray|Bilao)\s+\((S|M|L|XL)\)$/i', $clientName, $match)) {
                if (!$dish || strcasecmp($match[1], $dish) !== 0) {
                    throw new InvalidArgumentException('Choose a valid bilao item and size before adding it to the order.');
                }
                if ($packagingRaw === '') {
                    $packagingRaw = strtoupper($match[2]);
                }
                if ($size === '') {
                    $size = strtoupper($match[3]);
                }
            } elseif (preg_match('/^(' . $validDishes . ')\s+(S|M|L|XL)$/i', $clientName, $match)) {
                if (!$dish || strcasecmp($match[1], $dish) !== 0) {
                    throw new InvalidArgumentException('Choose a valid bilao item and size before adding it to the order.');
                }
                if ($size === '') {
                    $size = strtoupper($match[2]);
                }
            }

            if (!$dish || !in_array($size, ['S', 'M', 'L', 'XL'], true)) {
                throw new InvalidArgumentException('Choose a valid bilao item and size before adding it to the order.');
            }
            if (!in_array($packagingRaw, ['TRAY', 'BILAO'], true)) {
                throw new InvalidArgumentException('Please select Tray or Bilao packaging for ' . $dish . '.');
            }

            $bilaoValidation = validate_bilao_variant_order($conn, $itemId, $dish, $size);
            if (empty($bilaoValidation['ok'])) {
                throw new InvalidArgumentException($bilaoValidation['msg'] ?? ($dish . ' (' . $size . ') is no longer available.'));
            }

            $packagingLabel = ($packagingRaw === 'TRAY') ? 'Tray' : 'Bilao';
            $displayName = $dish . ' ' . $packagingLabel . ' (' . $size . ')';
            $itemNotes = 'Packaging: ' . $packagingLabel;
        }

        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;
        $normalized[] = [
            'id' => $itemId,
            'name' => $item['item_name'],
            'client_name' => $displayName,
            'qty' => $qty,
            'price' => $price,
            'notes' => $itemNotes
        ];
    }

    $discountType = !empty($data['discountType']) ? trim((string)$data['discountType']) : null;
    $discountIdNumber = !empty($data['discountIdNumber']) ? trim((string)$data['discountIdNumber']) : null;
    $discountCustomerName = !empty($data['discountCustomerName']) ? trim((string)$data['discountCustomerName']) : null;

    if ($discountType !== null && !in_array($discountType, ['Senior Citizen', 'PWD'], true)) {
        throw new InvalidArgumentException('Unsupported discount type.');
    }
    if ($discountType !== null && ($discountIdNumber === null || $discountCustomerName === null)) {
        throw new InvalidArgumentException('Senior/PWD discounts require the customer name and ID number.');
    }

    if ($discountType && in_array($discountType, ['Senior Citizen', 'PWD'], true)) {
        // BIR 20% discount applies to individual personal eligible items
        // In Kenji's Kitchen, Bilao Favorites (category 6) is catering/bulk party size, thus excluded
        $eligibleSubtotal = 0.0;
        foreach ($normalized as $item) {
            $catId = (int)($lockedItems[$item['id']]['category_id'] ?? 0);
            if ($catId !== 6) { // Not bilao catering
                $eligibleSubtotal += ($item['price'] * $item['qty']);
            }
        }
        $discount = round($eligibleSubtotal * 0.20, 2);
    } else {
        $discount = round($subtotal * ($discountPercent / 100), 2);
    }

    $tax = 0.0;
    $total = round(max($subtotal - $discount + $tax, 0), 2);
    $change = $paymentMode === 'cash' ? round(max($cash - $total, 0), 2) : 0.0;

    if ($paymentMode === 'cash' && $cash < $total) {
        $conn->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Insufficient cash tendered.']);
        exit;
    }

    $queue = next_daily_queue_no($conn);
    $order_no = 'ORD-' . date('Ymd') . '-' . str_pad($queue, 4, '0', STR_PAD_LEFT);
    $orderNote = !empty($data['orderNote']) ? trim($data['orderNote']) : null;

    // Append discount ID verification details into notes for audit trail and reports compatibility
    if ($discountType && $discountIdNumber) {
        $discNote = "[$discountType ID: $discountIdNumber" . ($discountCustomerName ? " - $discountCustomerName" : "") . "]";
        $orderNote = $orderNote ? ($orderNote . " " . $discNote) : $discNote;
    }
    
    // Customer name: use verified Senior/PWD name if provided, else 'Walk-in Customer'
    $finalCustomerName = ($discountType && $discountCustomerName) ? $discountCustomerName : 'Walk-in Customer';

    $userId = (int)$activeCashier['id'];
    $cashierName = $activeCashier['full_name'] ?: $activeCashier['username'];
    $isAdminOperator = strcasecmp((string)($activeCashier['role_name'] ?? ''), 'Admin') === 0;
    $shiftStmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $shiftStmt->execute([$userId]);
    $shiftId = (int)$shiftStmt->fetchColumn();
    if ($shiftId < 1 && $isAdminOperator) {
        $anyShiftStmt = $conn->query("SELECT id FROM cashier_shifts WHERE status = 'Open' ORDER BY id DESC LIMIT 1");
        $shiftId = (int)$anyShiftStmt->fetchColumn();
    }
    if ($shiftId < 1 && !$isAdminOperator) {
        throw new InvalidArgumentException('Open a cashier shift before checkout.');
    }
    $shiftIdOrNull = $shiftId > 0 ? $shiftId : null;

    $stmt = $conn->prepare("INSERT INTO orders (order_no, queue_no, user_id, shift_id, cashier_name, customer_name, order_type, table_no, order_channel, subtotal, discount, discount_type, discount_id_number, tax, total, notes, status, inventory_deducted, paid_at, kitchen_queued_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'POS', ?, ?, ?, ?, ?, ?, ?, 'Pending', 1, NOW(), NOW())");
    $stmt->execute([
        $order_no,
        $queue,
        $userId,
        $shiftIdOrNull,
        $cashierName,
        $finalCustomerName,
        $orderType,
        $tableNo !== '' ? $tableNo : null,
        $subtotal,
        $discount,
        $discountType,
        $discountIdNumber,
        $tax,
        $total,
        $orderNote
    ]);
    $order_id = (int) $conn->lastInsertId();
    if ($order_id <= 0) {
        throw new RuntimeException('Failed to create order record.');
    }

    $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price, notes) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($normalized as $item) {
        $item_stmt->execute([$order_id, $item['id'], $item['client_name'] !== '' ? $item['client_name'] : $item['name'], $item['qty'], $item['price'], $item['notes'] ?? null]);
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
            $freshStmt = $conn->prepare("SELECT id, stock, availability FROM menu_items WHERE id IN (" . implode(',', array_fill(0, count($requestedByMenuId), '?')) . ")");
            $freshStmt->execute(array_keys($requestedByMenuId));
            $freshUpdates = array_map(static function (array $r): array {
                return [
                    'id' => (int)$r['id'],
                    'stock' => max(0, (int)$r['stock']),
                    'availability' => (string)$r['availability'],
                ];
            }, $freshStmt->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode([
                'ok' => false,
                'msg' => 'Stock changed while the order was being processed (an earlier order claimed the remaining stock). Your cart has been synced.',
                'stock_updates' => $freshUpdates
            ]);
            exit;
        }

        $log->execute([$itemId, $userId, $totalRequestedQty, 'Order ' . $order_no . ' auto-deduction']);
        if ($log->rowCount() !== 1) {
            throw new RuntimeException('Failed to create inventory log record.');
        }
    }

    $amountPaid = $paymentMode === 'gcash' ? $total : $cash;
    $paymentMethod = $paymentMode === 'gcash' ? 'GCash' : 'Cash';

    $stmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_reference, payment_status, amount_paid, change_amount, paid_at, shift_id) VALUES (?, ?, ?, 'Paid', ?, ?, NOW(), ?)");
    $stmt->execute([$order_id, $paymentMethod, $paymentMode === 'gcash' ? $gcashRef : null, $amountPaid, $change, $shiftIdOrNull]);
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

    $response = ['ok' => true, 'order_no' => $order_no, 'receipt_no' => $receipt_no];
    checkout_request_complete($conn, 'POS', (int)$activeCashier['id'], $requestKey, $order_id, $response);
    $conn->commit();
    echo json_encode($response);
} catch (DomainException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    http_response_code(409);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
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
