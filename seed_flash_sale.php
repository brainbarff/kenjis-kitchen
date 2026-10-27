<?php
/**
 * =============================================================================
 * Kenji's Kitchen – Flash Seed Sale Data Seeder
 * =============================================================================
 * Campaign : Flash Seed Sale
 * Date Range: September 28, 2026 → October 4, 2026
 * Volume   : 50–100 customer order records (randomised per run)
 * URL Source: http://localhost/kenjis-kitchen/online/index.html
 *
 * Usage (run once from CLI or visit in browser while logged in as admin):
 *   php seed_flash_sale.php
 *   — or —
 *   http://localhost/kenjis-kitchen/seed_flash_sale.php
 *
 * Safety:
 *   • All records are tagged  campaign = 'Flash Seed Sale'
 *   • To wipe only seed data run:
 *       DELETE o,oi,p,r FROM orders o
 *         LEFT JOIN order_items   oi ON oi.order_id = o.id
 *         LEFT JOIN payments       p ON p.order_id  = o.id
 *         LEFT JOIN receipts       r ON r.order_id  = o.id
 *       WHERE o.notes LIKE '%Flash Seed Sale%';
 * =============================================================================
 */

/* ── 0. Bootstrap ─────────────────────────────────────────────────────────── */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI execution only.');
}

define('ROOT_PATH', __DIR__);
require_once __DIR__ . '/config/db.php';   // PDO $conn
require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=UTF-8');
set_time_limit(120);

/* ── 1. Campaign Constants ───────────────────────────────────────────────── */
const CAMPAIGN_NAME   = 'Flash Seed Sale';
const CAMPAIGN_START  = '2026-09-28';
const CAMPAIGN_END    = '2026-10-04';
const URL_SOURCE      = 'http://localhost/kenjis-kitchen/online/index.html';
const MIN_ORDERS      = 50;
const MAX_ORDERS      = 100;
const ORDER_CHANNEL   = 'ONLINE';

/* ── 2. Pull live menu items from DB (active, in-stock) ──────────────────── */
$menuItems = $conn
    ->query("
        SELECT id, item_name, price, promo_price, promo_start, promo_end
        FROM   menu_items
        WHERE  is_archived = 0
          AND  availability = 'Available'
          AND  stock > 0
        ORDER  BY id
    ")
    ->fetchAll(PDO::FETCH_ASSOC);

if (empty($menuItems)) {
    die("❌  No available menu items found. Add stock first.\n");
}

/* ── 3. Sample Filipino customer names ───────────────────────────────────── */
$firstNames = [
    'Maria', 'Jose', 'Ana', 'Juan', 'Rosa', 'Pedro', 'Elena', 'Miguel',
    'Carmen', 'Rodrigo', 'Liza', 'Danilo', 'Maribel', 'Eduardo', 'Cristina',
    'Ronaldo', 'Sheila', 'Noel', 'Patricia', 'Arnel', 'Jenny', 'Dennis',
    'Glaiza', 'Mark', 'Rosario', 'Bernardo', 'Camille', 'Felix', 'Lovely',
    'Jerome', 'Cynthia', 'Raul', 'Grace', 'Alfredo', 'Donna', 'Ricky',
    'Maricel', 'Cesar', 'Lourdes', 'Edwin', 'Mylene', 'Vic', 'Pia', 'Rex',
    'Glenda', 'Bobby', 'Hazel', 'Nonoy', 'Mika', 'Jojo',
];
$lastNames = [
    'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Torres',
    'Ramos', 'Aquino', 'Diaz', 'Lopez', 'Castillo', 'Morales', 'Villanueva',
    'Hernandez', 'Flores', 'Gonzales', 'Rivera', 'Mendoza', 'Soriano',
    'Dela Cruz', 'San Pedro', 'Dela Rosa', 'De Leon', 'De Guzman',
    'Manalo', 'Tolentino', 'Espiritu', 'Mercado', 'Santiago',
];
$barangays = [
    'Blk 12 Lot 3, Tandang Sora', '45 Quirino Highway, Novaliches',
    'Unit 5 Camarin, Caloocan', '89 Luzon Ave, Fairview',
    'Blk 7 Lot 14, Commonwealth', '23 Batangas St, Quezon City',
    '2nd Floor, Mindanao Ave', 'Villa Maria, Novaliches',
    '10 Regalado Ave, Fairview', 'Phase 2 Blk 4 Lot 7, San Bartolome',
    'No. 55 Baesa Road', 'Purok 3, Batasan Hills',
    'Rm 102, BLISS Payatas', '17 Molave St, Culiat',
    'Lot 20 Blk 5, Greater Lagro',
];

/* ── 4. Helper: random date-time within campaign range ───────────────────── */
function randomCampaignDateTime(): string {
    $start = strtotime(CAMPAIGN_START . ' 08:00:00');
    $end   = strtotime(CAMPAIGN_END   . ' 22:00:00');
    $ts    = mt_rand($start, $end);
    return date('Y-m-d H:i:s', $ts);
}

/* ── 5. Helper: effective price for a menu item ─────────────────────────── */
function effectivePrice(array $item, string $dateStr): float {
    if (
        $item['promo_price'] !== null &&
        $item['promo_start'] !== null &&
        $item['promo_end']   !== null &&
        $dateStr >= $item['promo_start'] &&
        $dateStr <= $item['promo_end']
    ) {
        return (float) $item['promo_price'];
    }
    return (float) $item['price'];
}

/* ── 6. Pre-build queue counter base per day ─────────────────────────────── */
// We keep a per-day counter so order_no doesn't collide with real orders.
// We query the current MAX queue_no for each campaign day.
$queueCounters = [];
for ($d = 0; $d <= 6; $d++) {
    $day = date('Y-m-d', strtotime(CAMPAIGN_START . " +$d days"));
    $row = $conn
        ->query("SELECT COALESCE(MAX(queue_no), 0) FROM orders WHERE DATE(created_at) = '$day'")
        ->fetchColumn();
    $queueCounters[$day] = (int)$row;
}

/* ── 7. Seed records ─────────────────────────────────────────────────────── */
$totalOrders  = mt_rand(MIN_ORDERS, MAX_ORDERS);
$seededOrders = 0;
$results      = [];  // human-readable log rows

echo "════════════════════════════════════════════════════════════════\n";
echo "  Kenji's Kitchen – Flash Seed Sale Seeder\n";
echo "  Campaign  : " . CAMPAIGN_NAME . "\n";
echo "  Date Range: " . CAMPAIGN_START . " → " . CAMPAIGN_END . "\n";
echo "  Target    : $totalOrders orders\n";
echo "════════════════════════════════════════════════════════════════\n\n";

// Prepare reusable statements
$orderStmt = $conn->prepare("
    INSERT INTO orders (
        order_no, queue_no, user_id, customer_name, customer_phone,
        order_type, delivery_address, order_channel,
        subtotal, discount, tax, total,
        status, paid_at, kitchen_queued_at, created_at, notes
    ) VALUES (
        ?, ?, 1, ?, ?,
        'ONLINE', ?, 'ONLINE',
        ?, 0, 0, ?,
        'Served', ?, ?, ?, ?
    )
");

$itemStmt = $conn->prepare("
    INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price)
    VALUES (?, ?, ?, ?, ?)
");

$payStmt = $conn->prepare("
    INSERT INTO payments (order_id, payment_method, payment_reference, payment_status, amount_paid, change_amount)
    VALUES (?, ?, ?, 'Paid', ?, 0)
");

$rcptStmt = $conn->prepare("
    INSERT INTO receipts (order_id, receipt_no) VALUES (?, ?)
");

$conn->beginTransaction();

try {
    for ($i = 0; $i < $totalOrders; $i++) {

        /* 7a. Pick a random date-time within the campaign ──────────────────── */
        $createdAt = randomCampaignDateTime();
        $dateOnly  = substr($createdAt, 0, 10);

        /* 7b. Queue number per day ─────────────────────────────────────────── */
        $queueCounters[$dateOnly]++;
        $queueNo  = $queueCounters[$dateOnly];
        $orderNo  = 'ONL-' . str_replace('-', '', $dateOnly) . '-' . str_pad($queueNo, 4, '0', STR_PAD_LEFT);
        $receiptNo = 'RCPT-SEED-' . str_replace('-', '', $dateOnly) . '-' . str_pad($queueNo, 4, '0', STR_PAD_LEFT);

        /* 7c. Random customer ──────────────────────────────────────────────── */
        $customerName  = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $customerPhone = '09' . mt_rand(100000000, 999999999);  // PH format
        $address       = $barangays[array_rand($barangays)];
        $paymentMethod = mt_rand(0, 2) === 0 ? 'Gcash' : 'Cash';  // ~33 % GCash
        $gcashRef      = $paymentMethod === 'Gcash' ? (string)mt_rand(1000000000000, 9999999999999) : null;

        /* 7d. Pick 1–3 distinct menu items ────────────────────────────────── */
        $pickedCount = mt_rand(1, min(3, count($menuItems)));
        $shuffled    = $menuItems;
        shuffle($shuffled);
        $pickedItems = array_slice($shuffled, 0, $pickedCount);

        /* 7e. Compute order totals ─────────────────────────────────────────── */
        $subtotal  = 0.0;
        $lineItems = [];
        foreach ($pickedItems as $mi) {
            $qty   = mt_rand(1, 3);
            $price = effectivePrice($mi, $dateOnly);
            $line  = round($price * $qty, 2);
            $subtotal += $line;
            $lineItems[] = [
                'id'    => (int)$mi['id'],
                'name'  => $mi['item_name'],
                'qty'   => $qty,
                'price' => $price,
                'line'  => $line,
            ];
        }
        $subtotal = round($subtotal, 2);

        /* 7f. Notes field carries campaign meta so seeds are identifiable ──── */
        $notesJson = json_encode([
            'campaign'  => CAMPAIGN_NAME,
            'urlSource' => URL_SOURCE,
            'seeded'    => true,
        ]);

        /* 7g. Insert order ─────────────────────────────────────────────────── */
        $orderStmt->execute([
            $orderNo,
            $queueNo,
            $customerName,
            $customerPhone,
            $address,
            $subtotal,
            $subtotal,
            $createdAt,   // paid_at
            $createdAt,   // kitchen_queued_at
            $createdAt,   // created_at
            $notesJson,
        ]);
        $orderId = (int)$conn->lastInsertId();

        /* 7h. Insert order items ─────────────────────────────────────────────*/
        foreach ($lineItems as $li) {
            $itemStmt->execute([$orderId, $li['id'], $li['name'], $li['qty'], $li['price']]);
        }

        /* 7i. Insert payment ────────────────────────────────────────────────── */
        $payStmt->execute([$orderId, $paymentMethod, $gcashRef, $subtotal]);

        /* 7j. Insert receipt ─────────────────────────────────────────────────*/
        $rcptStmt->execute([$orderId, $receiptNo]);

        /* 7k. Log row ─────────────────────────────────────────────────────── */
        $itemSummary = implode(', ', array_map(fn($l) => "{$l['qty']}× {$l['name']}", $lineItems));
        $results[]   = [
            'orderId'      => $orderNo,
            'customerName' => $customerName,
            'items'        => $itemSummary,
            'totalAmount'  => '₱' . number_format($subtotal, 2),
            'payment'      => $paymentMethod . ($gcashRef ? " ($gcashRef)" : ''),
            'orderDate'    => $createdAt,
            'campaign'     => CAMPAIGN_NAME,
            'urlSource'    => URL_SOURCE,
        ];

        $seededOrders++;
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollBack();
    die("❌  Seeder failed: " . $e->getMessage() . "\n");
}

/* ── 8. Print Results Table ──────────────────────────────────────────────── */
printf("%-22s %-28s %-40s %-12s %-8s %-22s\n",
    'Order #', 'Customer', 'Items', 'Total', 'Payment', 'Order Date');
echo str_repeat('─', 138) . "\n";

foreach ($results as $r) {
    printf("%-22s %-28s %-40s %-12s %-8s %-22s\n",
        $r['orderId'],
        mb_substr($r['customerName'], 0, 27),
        mb_substr($r['items'], 0, 39),
        $r['totalAmount'],
        $r['payment'],
        $r['orderDate'],
    );
}

echo "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "✅  Seeded $seededOrders orders for campaign: " . CAMPAIGN_NAME . "\n";
echo "   Date range : " . CAMPAIGN_START . " → " . CAMPAIGN_END . "\n";
echo "   URL Source : " . URL_SOURCE . "\n";
echo "\n";
echo "   To remove ONLY seed data, run:\n";
echo "   DELETE o,oi,p,r FROM orders o\n";
echo "     LEFT JOIN order_items oi ON oi.order_id = o.id\n";
echo "     LEFT JOIN payments     p ON p.order_id  = o.id\n";
echo "     LEFT JOIN receipts     r ON r.order_id  = o.id\n";
echo "   WHERE o.notes LIKE '%Flash Seed Sale%';\n";
echo "════════════════════════════════════════════════════════════════\n";
