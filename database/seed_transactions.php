<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/config/db.php';

if (!isset($conn) || !($conn instanceof PDO)) {
    die('Database connection is not available.');
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$minSales = isset($_GET['min']) ? (float) $_GET['min'] : 22000.00;
$maxSales = isset($_GET['max']) ? (float) $_GET['max'] : 30000.00;
$action = $_GET['action'] ?? 'seed';

if ($minSales < 20000) {
    $minSales = 20000.00;
}

if ($maxSales < $minSales) {
    $maxSales = $minSales;
}

function validDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function getUserId(PDO $conn, string $roleName, int $fallback): int
{
    $stmt = $conn->prepare("
        SELECT u.id
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = ?
          AND u.status = 'Active'
        ORDER BY u.id
        LIMIT 1
    ");

    $stmt->execute([$roleName]);

    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : $fallback;
}

function getDatesFromRequest(): array
{
    $dates = [];

    if (!empty($_GET['date'])) {
        $dates[] = trim($_GET['date']);
    }

    if (!empty($_GET['dates'])) {
        foreach (explode(',', $_GET['dates']) as $date) {
            $date = trim($date);

            if ($date !== '') {
                $dates[] = $date;
            }
        }
    }

    if (!empty($_GET['from']) && !empty($_GET['to'])) {
        $from = trim($_GET['from']);
        $to = trim($_GET['to']);

        if (validDate($from) && validDate($to) && $from <= $to) {
            $cursor = new DateTime($from);
            $end = new DateTime($to);

            while ($cursor <= $end) {
                $dates[] = $cursor->format('Y-m-d');
                $cursor->modify('+1 day');
            }
        }
    }

    $dates = array_values(array_unique($dates));
    sort($dates);

    return $dates;
}

function currentSales(PDO $conn, string $date): array
{
    $from = $date . ' 00:00:00';
    $to = (new DateTime($date))
        ->modify('+1 day')
        ->format('Y-m-d 00:00:00');

    $stmt = $conn->prepare("
        SELECT
            COUNT(o.id) AS total_orders,
            COALESCE(SUM(o.total), 0) AS total_sales
        FROM orders o
        INNER JOIN payments p ON p.order_id = o.id
        WHERE o.paid_at IS NOT NULL
          AND o.paid_at >= ?
          AND o.paid_at < ?
          AND o.status = 'Served'
    ");

    $stmt->execute([$from, $to]);

    $row = $stmt->fetch();

    return [
        'orders' => (int) ($row['total_orders'] ?? 0),
        'sales' => (float) ($row['total_sales'] ?? 0)
    ];
}

function randomCustomer(array $names): string
{
    return $names[array_rand($names)];
}

function buildOrderItems(array $items): array
{
    if (empty($items)) {
        throw new RuntimeException('No active menu items are available.');
    }

    $selected = [];
    $attempts = 0;
    $targetBandMin = 90.00;
    $targetBandMax = 450.00;

    while ($attempts < 12) {
        $attempts++;
        $selected = [];
        $subtotal = 0.00;
        $itemCount = mt_rand(1, 3);

        $availableIndexes = array_keys($items);
        shuffle($availableIndexes);

        for ($i = 0; $i < $itemCount; $i++) {
            $index = $availableIndexes[$i] ?? array_rand($items);
            $item = $items[$index];

            $quantity = mt_rand(1, 100) <= 85 ? 1 : 2;

            $lineTotal =
                (float) $item['price'] * $quantity;

            if ($subtotal + $lineTotal > $targetBandMax) {
                continue;
            }

            $selected[] = [
                'item' => $item,
                'quantity' => $quantity
            ];

            $subtotal += $lineTotal;
        }

        if (
            $subtotal >= $targetBandMin &&
            $subtotal <= $targetBandMax
        ) {
            return $selected;
        }
    }

    $item = $items[array_rand($items)];

    $quantity = max(
        1,
        (int) ceil(
            110 / max(1, (float) $item['price'])
        )
    );

    return [[
        'item' => $item,
        'quantity' => $quantity
    ]];
}

function businessTimestamp(string $date, int $index): DateTime
{
    if (mt_rand(1, 100) <= 18) {
        $hour = mt_rand(0, 2);
        $minute = mt_rand(0, 54);
        $second = mt_rand(0, 59);

        return new DateTime(
            sprintf(
                '%s %02d:%02d:%02d',
                $date,
                $hour,
                $minute,
                $second
            )
        );
    }

    $start = 9 * 60;
    $end = 23 * 60 + 54;

    $minuteOfDay = mt_rand($start, $end);

    $hour = intdiv($minuteOfDay, 60);
    $minute = $minuteOfDay % 60;
    $second = mt_rand(0, 59);

    return new DateTime(
        sprintf(
            '%s %02d:%02d:%02d',
            $date,
            $hour,
            $minute,
            $second
        )
    );
}

$dates = getDatesFromRequest();

if ($action !== 'seed' && $action !== 'remove') {
    $action = 'seed';
}

if ($action === 'seed' && empty($dates)) {
    $previewDates = [
        '2026-09-07',
        '2026-09-24',
        '2026-09-25',
        '2026-09-26',
        '2026-09-27',
        '2026-09-28'
    ];

    $rows = [];

    foreach ($previewDates as $date) {
        $stats = currentSales($conn, $date);

        $rows[] = [
            'date' => $date,
            'orders' => $stats['orders'],
            'sales' => $stats['sales']
        ];
    }
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>Transaction Seeder</title>

        <style>
            body{
                font-family:Segoe UI,Tahoma,sans-serif;
                background:#f8fafc;
                color:#1f2937;
                padding:32px
            }

            .card{
                max-width:860px;
                margin:0 auto;
                background:#fff;
                border:1px solid #e5e7eb;
                border-radius:14px;
                padding:28px;
                box-shadow:0 8px 24px rgba(0,0,0,.06)
            }

            h1{
                margin:0 0 8px
            }

            p{
                line-height:1.6
            }

            .table{
                width:100%;
                border-collapse:collapse;
                margin-top:20px
            }

            .table th,
            .table td{
                padding:10px 12px;
                border-bottom:1px solid #e5e7eb;
                text-align:left
            }

            .btn{
                display:inline-block;
                padding:10px 14px;
                background:#f2c12e;
                color:#111827;
                text-decoration:none;
                border-radius:8px;
                font-weight:700;
                margin:6px 6px 0 0
            }

            .muted{
                color:#6b7280
            }

            .code{
                background:#111827;
                color:#f9fafb;
                padding:14px;
                border-radius:8px;
                overflow:auto
            }
        </style>
    </head>

    <body>

    <div class="card">

        <h1>Historical Transaction Seeder</h1>

        <p>
            This tool is ready but will not create data until a date is supplied.
            The default target is between
            <strong>₱22,000</strong> and
            <strong>₱30,000</strong>
            per selected date.
        </p>

        <p class="muted">
            Times are simulated from 9:00 AM through late evening,
            with some activity between 12:00 AM and 2:54 AM on the
            same report date so the current calendar-date reports
            keep each day's sales together.
        </p>

        <table class="table">

            <thead>
                <tr>
                    <th>Date</th>
                    <th>Current Orders</th>
                    <th>Current Sales</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($rows as $row): ?>

                <tr>
                    <td><?= h($row['date']) ?></td>

                    <td>
                        <?= number_format($row['orders']) ?>
                    </td>

                    <td>
                        ₱<?= number_format($row['sales'], 2) ?>
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

        <p>
            <strong>Example:</strong>
        </p>

        <div class="code">
            http://localhost/kenjis-kitchen/database/seed_transactions.php?dates=2026-09-07,2026-09-24,2026-09-25,2026-09-26,2026-09-27,2026-09-28
        </div>

        <p>
            <a
                class="btn"
                href="?dates=2026-09-07,2026-09-24,2026-09-25,2026-09-26,2026-09-27,2026-09-28"
            >
                Seed Selected Dates
            </a>
        </p>

    </div>

    </body>
    </html>

    <?php
    exit;
}

foreach ($dates as $date) {
    if (!validDate($date)) {
        die('Invalid date supplied: ' . h($date));
    }
}

$cashierId = getUserId(
    $conn,
    'Cashier',
    1
);

$serverId = getUserId(
    $conn,
    'Server',
    $cashierId
);

$menuStmt = $conn->query("
    SELECT
        id,
        item_name,
        price,
        promo_price,
        promo_start,
        promo_end
    FROM menu_items
    WHERE availability = 'Available'
      AND is_archived = 0
    ORDER BY id
");

$menuItems = $menuStmt->fetchAll();

if (empty($menuItems)) {
    die('No active menu items are available.');
}

$customers = [
    'Josh',
    'Erika',
    'Christian',
    'Kyle',
    'Bea',
    'Angelo',
    'Nicole',
    'Justin',
    'Daniel',
    'Chloe',
    'Mark',
    'John Paul',
    'Alyssa',
    'Vince',
    'Kaye',
    'Lance',
    'Miguel',
    'Patricia',
    'Dave',
    'Hannah',
    'Jeric',
    'Princess',
    'Nathan',
    'Andrea',
    'Kevin',
    'Jasmine',
    'Paolo',
    'Mika',
    'Aaron',
    'Sofia'
];

$tables = [
    'Table 1',
    'Table 2',
    'Table 3',
    'Table 4',
    'Table 5',
    'Table 6',
    'Table 7',
    'Table 8'
];

$results = [];

foreach ($dates as $targetDate) {

    $existing = currentSales(
        $conn,
        $targetDate
    );

    if ($action === 'remove') {

        $marker =
            '[SIMULATED:' . $targetDate . ']';

        $conn->beginTransaction();

        try {

            $deleteStmt = $conn->prepare("
                DELETE FROM orders
                WHERE notes = ?
            ");

            $deleteStmt->execute([$marker]);

            $deleted =
                $deleteStmt->rowCount();

            $conn->commit();

            $results[] = [
                'date' => $targetDate,
                'action' => 'removed',
                'generated_orders' => $deleted,
                'added_sales' => 0,
                'final_sales' =>
                    currentSales(
                        $conn,
                        $targetDate
                    )['sales']
            ];

        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            throw $e;
        }

        continue;
    }

    if ($existing['sales'] >= $minSales) {

        $results[] = [
            'date' => $targetDate,
            'action' => 'skipped',
            'generated_orders' => 0,
            'added_sales' => 0,
            'final_sales' => $existing['sales']
        ];

        continue;
    }

    $targetSales =
        mt_rand(
            (int) round($minSales * 100),
            (int) round($maxSales * 100)
        ) / 100;

    $needed =
        max(
            0,
            $targetSales - $existing['sales']
        );

    $queueStmt = $conn->prepare("
        SELECT COALESCE(MAX(queue_no), 0)
        FROM orders
        WHERE order_no LIKE ?
    ");

    $queueStmt->execute([
        'ORD-' .
        str_replace('-', '', $targetDate) .
        '-%'
    ]);

    $queueNo =
        (int) $queueStmt->fetchColumn();

    $generatedCount = 0;
    $addedSales = 0.00;

    $conn->beginTransaction();

    try {

        $orderInsert = $conn->prepare("
            INSERT INTO orders
            (
                order_no,
                queue_no,
                user_id,
                customer_name,
                order_type,
                table_no,
                subtotal,
                discount,
                tax,
                total,
                notes,
                status,
                created_at,
                paid_at,
                kitchen_queued_at,
                preparing_at,
                ready_at,
                served_at,
                served_by
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, 'Served', ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $itemInsert = $conn->prepare("
            INSERT INTO order_items
            (
                order_id,
                menu_item_id,
                item_name,
                quantity,
                price,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $paymentInsert = $conn->prepare("
            INSERT INTO payments
            (
                order_id,
                payment_method,
                amount_paid,
                change_amount,
                paid_at
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $receiptInsert = $conn->prepare("
            INSERT INTO receipts
            (
                order_id,
                receipt_no,
                printed_at
            )
            VALUES (?, ?, ?)
        ");

        while ($addedSales < $needed) {

            $queueNo++;

            $orderNo =
                'ORD-' .
                str_replace('-', '', $targetDate) .
                '-' .
                str_pad(
                    (string) $queueNo,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

            $marker =
                '[SIMULATED:' . $targetDate . ']';

            $orderItems =
                buildOrderItems($menuItems);

            $subtotal = 0.00;

            foreach ($orderItems as &$entry) {

                $item =
                    $entry['item'];

                $effectivePrice =
                    (float) $item['price'];

                if (
                    $item['promo_price'] !== null &&
                    $item['promo_start'] !== null &&
                    $item['promo_end'] !== null
                ) {

                    if (
                        $targetDate >= $item['promo_start'] &&
                        $targetDate <= $item['promo_end']
                    ) {
                        $effectivePrice =
                            (float) $item['promo_price'];
                    }
                }

                $entry['price'] =
                    $effectivePrice;

                $entry['line_total'] =
                    $effectivePrice *
                    (int) $entry['quantity'];

                $subtotal +=
                    $entry['line_total'];
            }

            unset($entry);

            $isDineIn =
                mt_rand(1, 100) <= 65;

            $orderType =
                $isDineIn
                    ? 'DINE-IN'
                    : 'TAKE-OUT';

            $tableNo =
                $isDineIn
                    ? $tables[array_rand($tables)]
                    : null;

            $customerName =
                randomCustomer($customers);

            $createdAt =
                businessTimestamp(
                    $targetDate,
                    $generatedCount
                );

            $paidAt =
                (clone $createdAt)
                ->modify(
                    '+' .
                    mt_rand(1, 3) .
                    ' minutes'
                );

            $queuedAt =
                clone $paidAt;

            $preparingAt =
                (clone $paidAt)
                ->modify(
                    '+' .
                    mt_rand(1, 2) .
                    ' minutes'
                );

            $readyAt =
                (clone $preparingAt)
                ->modify(
                    '+' .
                    mt_rand(3, 6) .
                    ' minutes'
                );

            $servedAt =
                (clone $readyAt)
                ->modify(
                    '+' .
                    mt_rand(1, 3) .
                    ' minutes'
                );

            $total =
                $subtotal;

            $paymentMethod =
                mt_rand(1, 100) <= 70
                    ? 'Cash'
                    : 'GCash';

            $amountPaid =
                $total;

            $changeAmount =
                0.00;

            if ($paymentMethod === 'Cash') {

                $cashBills = [
                    50,
                    100,
                    200,
                    500,
                    1000,
                    2000
                ];

                $amountPaid =
                    $total;

                foreach ($cashBills as $bill) {

                    if ($bill >= $total) {

                        $amountPaid =
                            mt_rand(1, 100) <= 72
                                ? $total
                                : $bill;

                        break;
                    }
                }

                $changeAmount =
                    max(
                        0,
                        $amountPaid - $total
                    );
            }

            $orderInsert->execute([
                $orderNo,
                $queueNo,
                $cashierId,
                $customerName,
                $orderType,
                $tableNo,
                $subtotal,
                0.00,
                0.00,
                $total,
                $marker,
                $createdAt->format('Y-m-d H:i:s'),
                $paidAt->format('Y-m-d H:i:s'),
                $queuedAt->format('Y-m-d H:i:s'),
                $preparingAt->format('Y-m-d H:i:s'),
                $readyAt->format('Y-m-d H:i:s'),
                $servedAt->format('Y-m-d H:i:s'),
                $serverId
            ]);

            $orderId =
                (int) $conn->lastInsertId();

            foreach ($orderItems as $entry) {

                $item =
                    $entry['item'];

                $itemInsert->execute([
                    $orderId,
                    (int) $item['id'],
                    $item['item_name'],
                    (int) $entry['quantity'],
                    $entry['price'],
                    null
                ]);
            }

            $paymentInsert->execute([
                $orderId,
                $paymentMethod,
                $amountPaid,
                $changeAmount,
                $paidAt->format('Y-m-d H:i:s')
            ]);

            $receiptNo =
                'RCPT-' .
                date(
                    'Ymd',
                    strtotime($targetDate)
                ) .
                '-' .
                str_pad(
                    (string) $orderId,
                    5,
                    '0',
                    STR_PAD_LEFT
                );

            $receiptInsert->execute([
                $orderId,
                $receiptNo,
                $paidAt->format('Y-m-d H:i:s')
            ]);

            $generatedCount++;

            $addedSales +=
                $total;
        }

        $conn->commit();

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        throw $e;
    }

    $final =
        currentSales(
            $conn,
            $targetDate
        );

    $results[] = [
        'date' => $targetDate,
        'action' => 'seeded',
        'generated_orders' => $generatedCount,
        'added_sales' => $addedSales,
        'final_sales' => $final['sales']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Historical Transaction Seeder Result
    </title>

    <style>
        body{
            font-family:Segoe UI,Tahoma,sans-serif;
            background:#f8fafc;
            color:#1f2937;
            padding:32px
        }

        .card{
            max-width:900px;
            margin:0 auto;
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:28px;
            box-shadow:0 8px 24px rgba(0,0,0,.06)
        }

        h1{
            margin:0 0 8px
        }

        .muted{
            color:#6b7280
        }

        .table{
            width:100%;
            border-collapse:collapse;
            margin-top:20px
        }

        .table th,
        .table td{
            padding:11px 12px;
            border-bottom:1px solid #e5e7eb;
            text-align:left
        }

        .ok{
            font-weight:700
        }

        .btn{
            display:inline-block;
            padding:10px 14px;
            background:#f2c12e;
            color:#111827;
            text-decoration:none;
            border-radius:8px;
            font-weight:700;
            margin:18px 8px 0 0
        }
    </style>
</head>

<body>

<div class="card">

    <h1>
        Historical Transaction Seeder Complete
    </h1>

    <p class="muted">
        Existing sales were preserved.
        Generated records use
        <strong>Served</strong> status and do not
        deduct menu stock or write inventory history.
    </p>

    <table class="table">

        <thead>
            <tr>
                <th>Date</th>
                <th>Action</th>
                <th>Orders Added</th>
                <th>Sales Added</th>
                <th>Final Sales</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($results as $row): ?>

            <tr>

                <td>
                    <?= h($row['date']) ?>
                </td>

                <td class="ok">
                    <?= h(ucfirst($row['action'])) ?>
                </td>

                <td>
                    <?= number_format(
                        $row['generated_orders']
                    ) ?>
                </td>

                <td>
                    ₱<?= number_format(
                        $row['added_sales'],
                        2
                    ) ?>
                </td>

                <td>
                    ₱<?= number_format(
                        $row['final_sales'],
                        2
                    ) ?>
                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    <a
        class="btn"
        href="../modules/reports/index.php"
    >
        Open Reports
    </a>

    <a
        class="btn"
        href="../pages/dashboard.php"
    >
        Open Dashboard
    </a>

</div>

</body>
</html>