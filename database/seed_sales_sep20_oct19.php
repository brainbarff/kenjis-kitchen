<?php
/**
 * Historical Sales Generator: September 20, 2026 to October 19, 2026 (30 Days)
 * Generates realistic POS, Online (Lagro Delivery with ₱15 fee, Pickup, Outside Lagro),
 * multiple cashiers, shifts, payments (Cash, GCash, COD, Other QR), order items, and receipts.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/config.php';

set_time_limit(300);

echo "Starting Sales Seed Generator (Sept 20, 2026 - Oct 19, 2026)...\n";

// Disable foreign key checks for clean seed
$conn->exec("SET FOREIGN_KEY_CHECKS = 0;");
$conn->exec("TRUNCATE TABLE order_items;");
$conn->exec("TRUNCATE TABLE payments;");
$conn->exec("TRUNCATE TABLE receipts;");
$conn->exec("TRUNCATE TABLE payment_proofs;");
$conn->exec("TRUNCATE TABLE thirdparty_deliveries;");
$conn->exec("TRUNCATE TABLE cod_cash_handovers;");
$conn->exec("TRUNCATE TABLE cashier_shifts;");
$conn->exec("TRUNCATE TABLE orders;");
$conn->exec("SET FOREIGN_KEY_CHECKS = 1;");

// Menu items
$menuItems = $conn->query("SELECT id, item_name, price FROM menu_items WHERE is_archived = 0")->fetchAll();
if (empty($menuItems)) {
    die("Error: No menu items found.\n");
}

// Cashier users
$cashierUsers = [2, 8]; // cashier, cashier1
$deliveringStaff = [3, 4, 7]; // kitchen, inventory, server

// Verified delivery areas for Lagro orders
$verifiedAreas = [
    ['id' => 1, 'street' => 'Misa de Gallo St', 'sub' => 'Lagro Core'],
    ['id' => 2, 'street' => 'Tatlong Hari St', 'sub' => 'Lagro Core'],
    ['id' => 3, 'street' => 'Noche Buena St', 'sub' => 'Lagro Core'],
    ['id' => 4, 'street' => 'Flores de Mayo St', 'sub' => 'Lagro Core'],
    ['id' => 5, 'street' => 'La Naval St', 'sub' => 'Lagro Core'],
    ['id' => 6, 'street' => 'Kulintang St', 'sub' => 'Lagro Core'],
    ['id' => 7, 'street' => 'Kudyapi St', 'sub' => 'Lagro Core'],
    ['id' => 8, 'street' => 'Rondalla St', 'sub' => 'Lagro Core'],
    ['id' => 9, 'street' => 'Lakambini St', 'sub' => 'Lagro Core'],
    ['id' => 10, 'street' => 'Lam-ang St', 'sub' => 'Lagro Core'],
    ['id' => 11, 'street' => 'Luningning St', 'sub' => 'Lagro Core'],
];

$outsideAddresses = [
    'Unit 402, Fairview Terraces Residences, Novaliches, QC',
    'Block 15 Lot 8, North Fairview Subdivision, Quezon City',
    '77 Commonwealth Ave, Brgy. Holy Spirit, Quezon City',
    '12 Regalado Highway, Fairview, Quezon City',
    'Block 3 Lot 19, Geneva Garden Neopolitan, Novaliches, QC'
];

$customerNames = [
    'Erika Tambo', 'Joshua Reyes', 'Maria Santos', 'Angelo Cruz', 'Beatriz Gomez',
    'Christian Bautista', 'Diana Mendoza', 'Francis Ramos', 'Grace Villanueva', 'Ian Del Rosario',
    'Jasmine Morales', 'Kevin Alcantara', 'Liza Soberano', 'Mark Anthony', 'Nicole Ferrer',
    'Paolo Contis', 'Rochelle Pangilinan', 'Sam Milby', 'Trina Candaza', 'Victor Magtanggol'
];

$startDate = new DateTime('2026-09-20');
$endDate = new DateTime('2026-10-19');
$interval = new DateInterval('P1D');
$datePeriod = new DatePeriod($startDate, $interval, (clone $endDate)->modify('+1 day'));

$orderInsertStmt = $conn->prepare("
    INSERT INTO orders (
        order_no, queue_no, user_id, shift_id, customer_name, customer_phone,
        order_type, fulfillment_mode, delivery_area_id, delivery_address, landmark,
        order_channel, subtotal, delivery_fee, free_delivery_discount, total,
        change_for, cash_collected, collected_by_user_id, delivered_by_user_id,
        cod_approval_status, payment_verification_status, status,
        paid_at, kitchen_queued_at, preparing_at, ready_at, served_at, is_seed, created_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?, ?, 1, ?
    )
");

$itemInsertStmt = $conn->prepare("
    INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price, notes)
    VALUES (?, ?, ?, ?, ?, ?)
");

$paymentInsertStmt = $conn->prepare("
    INSERT INTO payments (order_id, shift_id, payment_method, payment_reference, payment_status, amount_paid, change_amount, paid_at)
    VALUES (?, ?, ?, ?, 'Paid', ?, ?, ?)
");

$receiptInsertStmt = $conn->prepare("
    INSERT INTO receipts (order_id, receipt_no, email_status, email_sent_at)
    VALUES (?, ?, 'Sent', ?)
");

$shiftInsertStmt = $conn->prepare("
    INSERT INTO cashier_shifts (user_id, terminal, opened_at, closed_at, opening_cash, closing_cash, total_sales, cash_sales, gcash_sales, total_refunds, variance, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'Closed')
");

$totalOrdersCount = 0;
$totalRevenue = 0.0;
$globalOrderNum = 1;

foreach ($datePeriod as $dt) {
    $curDate = $dt->format('Y-m-d');
    
    // Day of week weighting per requirements:
    // Weekdays (Mon-Thu, 1-4): 90 to 150 (or above) orders per day
    // Weekends (Fri-Sun, 5-7): 50 to 100 orders per day
    $dayOfWeek = (int)$dt->format('N');
    $isWeekend = ($dayOfWeek >= 5); // Friday (5), Saturday (6), Sunday (7)
    $ordersTodayCount = $isWeekend ? rand(50, 100) : rand(90, 155);

    // Create 2 shifts per day (Morning: 8:00 AM - 3:00 PM, Evening: 3:00 PM - 10:00 PM)
    $shiftsToday = [];
    foreach ([1, 2] as $shiftIdx) {
        $cUser = ($shiftIdx === 1) ? 2 : 8; // cashier vs cashier1
        $term = 'POS-' . $shiftIdx;
        $startH = ($shiftIdx === 1) ? '08:00:00' : '15:00:00';
        $endH   = ($shiftIdx === 1) ? '15:00:00' : '22:00:00';
        $shiftStart = "$curDate $startH";
        $shiftEnd   = "$curDate $endH";
        $opening = 1000.00;

        $shiftInsertStmt->execute([$cUser, $term, $shiftStart, $shiftEnd, $opening, 0, 0, 0, 0, 0]);
        $shiftsToday[$shiftIdx] = [
            'id' => (int)$conn->lastInsertId(),
            'user' => $cUser,
            'sales' => 0.0,
            'cash' => 0.0,
            'gcash' => 0.0,
        ];
    }

    $queueNoCounter = 1;

    for ($i = 0; $i < $ordersTodayCount; $i++) {
        // Distribute timestamps throughout operating hours (8:00 AM - 9:50 PM)
        $hour = rand(8, 21);
        $min = rand(0, 59);
        $sec = rand(0, 59);
        $orderTime = sprintf('%s %02d:%02d:%02d', $curDate, $hour, $min, $sec);
        
        $assignedShiftIdx = ($hour < 15) ? 1 : 2;
        $activeShift = &$shiftsToday[$assignedShiftIdx];

        // Order channel: 65% POS, 35% ONLINE
        $isOnline = (rand(1, 100) <= 35);
        $channel = $isOnline ? 'ONLINE' : 'POS';
        
        $orderNo = sprintf('ORD-%s-%04d', $dt->format('Ymd'), $globalOrderNum++);
        $custName = $customerNames[array_rand($customerNames)];
        $custPhone = '09' . rand(100000000, 999999999);

        // Fulfillment mode & payment method
        $fulfillmentMode = 'PICKUP';
        $orderType = 'DINE-IN';
        $deliveryFee = 0.0;
        $freeDeliveryDiscount = 0.0;
        $deliveryAreaId = null;
        $deliveryAddress = null;
        $landmark = null;
        $paymentMethod = 'Cash';
        $codApprovalStatus = 'None';
        $payVerificationStatus = 'None';
        $deliveredByUser = null;
        $collectedByUser = null;
        $changeFor = null;

        if ($isOnline) {
            $onlineRand = rand(1, 100);
            if ($onlineRand <= 50) {
                // Lagro Delivery (50% of online)
                $fulfillmentMode = 'LAGRO';
                $orderType = 'ONLINE';
                $deliveryFee = 15.00;

                // 20% first order free delivery promo
                if (rand(1, 100) <= 20) {
                    $freeDeliveryDiscount = 15.00;
                    $deliveryFee = 0.00;
                }

                $areaObj = $verifiedAreas[array_rand($verifiedAreas)];
                $deliveryAreaId = $areaObj['id'];
                $deliveryAddress = 'House #' . rand(10, 250) . ', ' . $areaObj['street'] . ', ' . $areaObj['sub'] . ', Brgy. Greater Lagro, QC';
                $landmark = 'Near Gate ' . rand(1, 4);

                // Payment for Lagro: 60% GCash, 35% COD, 5% Other QR
                $pRand = rand(1, 100);
                if ($pRand <= 60) {
                    $paymentMethod = 'GCash';
                    $payVerificationStatus = 'Verified';
                } elseif ($pRand <= 95) {
                    $paymentMethod = 'Cash on Delivery';
                    $codApprovalStatus = 'Approved';
                    $deliveredByUser = $deliveringStaff[array_rand($deliveringStaff)];
                    $collectedByUser = $deliveredByUser;
                } else {
                    $paymentMethod = 'Other QR';
                    $payVerificationStatus = 'Verified';
                }
            } elseif ($onlineRand <= 80) {
                // Pickup (30% of online)
                $fulfillmentMode = 'PICKUP';
                $orderType = 'TAKE-OUT';
                $paymentMethod = (rand(1, 100) <= 70) ? 'GCash' : 'Cash on Pickup';
                if ($paymentMethod === 'GCash') $payVerificationStatus = 'Verified';
                else $codApprovalStatus = 'Approved';
            } else {
                // Outside Lagro Third-Party (20% of online)
                $fulfillmentMode = 'OUTSIDE';
                $orderType = 'ONLINE';
                $deliveryAddress = $outsideAddresses[array_rand($outsideAddresses)];
                $paymentMethod = (rand(1, 100) <= 85) ? 'GCash' : 'Other QR';
                $payVerificationStatus = 'Verified';
                // Pass-through courier fee (₱60-₱120)
                $deliveryFee = rand(60, 120);
            }
        } else {
            // POS (Dine-in or Take-out)
            $orderType = (rand(1, 100) <= 65) ? 'DINE-IN' : 'TAKE-OUT';
            $fulfillmentMode = ($orderType === 'DINE-IN') ? 'PICKUP' : 'PICKUP';
            $paymentMethod = (rand(1, 100) <= 75) ? 'Cash' : 'GCash';
        }

        // Randomly pick 1 to 4 items
        $itemsInOrderCount = rand(1, 4);
        $subtotal = 0.0;
        $orderItemsList = [];

        for ($j = 0; $j < $itemsInOrderCount; $j++) {
            $m = $menuItems[array_rand($menuItems)];
            $qty = rand(1, 2);
            $itemTotal = (float)$m['price'] * $qty;
            $subtotal += $itemTotal;
            $orderItemsList[] = [
                'id' => $m['id'],
                'name' => $m['item_name'],
                'price' => (float)$m['price'],
                'qty' => $qty
            ];
        }

        $total = $subtotal + $deliveryFee;

        if (str_contains($paymentMethod, 'Cash') || $paymentMethod === 'Cash on Delivery') {
            $changeFor = ceil($total / 50) * 50;
            if ($changeFor < $total) $changeFor = $total + 50;
        }

        // Timestamps
        $paidAt = $orderTime;
        $kitchenAt = date('Y-m-d H:i:s', strtotime($orderTime . ' + 1 minute'));
        $prepAt    = date('Y-m-d H:i:s', strtotime($orderTime . ' + 3 minutes'));
        $readyAt   = date('Y-m-d H:i:s', strtotime($orderTime . ' + 15 minutes'));
        $servedAt  = date('Y-m-d H:i:s', strtotime($orderTime . ' + 20 minutes'));

        // Insert Order
        $orderInsertStmt->execute([
            $orderNo, $queueNoCounter++, $activeShift['user'], $activeShift['id'], $custName, $custPhone,
            $orderType, $fulfillmentMode, $deliveryAreaId, $deliveryAddress, $landmark,
            $channel, $subtotal, $deliveryFee, $freeDeliveryDiscount, $total,
            $changeFor, ($paymentMethod === 'Cash on Delivery' || str_contains($paymentMethod, 'Cash')) ? $total : null, $collectedByUser, $deliveredByUser,
            $codApprovalStatus, $payVerificationStatus, 'Served',
            $paidAt, $kitchenAt, $prepAt, $readyAt, $servedAt, $orderTime
        ]);
        $orderId = (int)$conn->lastInsertId();

        // Insert Order Items
        foreach ($orderItemsList as $oi) {
            $itemInsertStmt->execute([$orderId, $oi['id'], $oi['name'], $oi['qty'], $oi['price'], null]);
        }

        // Insert Payment
        $refNo = (str_contains($paymentMethod, 'GCash')) ? (string)rand(1000000000000, 9999999999999) : $paymentMethod;
        $changeAmount = $changeFor ? ($changeFor - $total) : 0;
        $paymentInsertStmt->execute([$orderId, $activeShift['id'], $paymentMethod, $refNo, $total, $changeAmount, $paidAt]);

        // Insert Receipt
        $receiptNo = sprintf('RCPT-%s-%05d', $dt->format('Ymd'), $orderId);
        $receiptInsertStmt->execute([$orderId, $receiptNo, $paidAt]);

        // Accumulate into Shift
        $activeShift['sales'] += $total;
        if (str_contains($paymentMethod, 'Cash')) {
            $activeShift['cash'] += $total;
        } else {
            $activeShift['gcash'] += $total;
        }

        $totalRevenue += $total;
        $totalOrdersCount++;
    }

    // Update closed shift financial totals
    foreach ($shiftsToday as $sData) {
        $cClose = 1000.00 + $sData['cash'];
        $conn->prepare("UPDATE cashier_shifts SET total_sales = ?, cash_sales = ?, gcash_sales = ?, closing_cash = ? WHERE id = ?")
             ->execute([$sData['sales'], $sData['cash'], $sData['gcash'], $cClose, $sData['id']]);
    }
}

echo "Seeding completed successfully!\n";
echo "Total Orders Created: {$totalOrdersCount}\n";
echo "Total Sales Volume: ₱" . number_format($totalRevenue, 2) . "\n";
echo "Date Span: September 20, 2026 to October 19, 2026 (30 full days)\n";
