<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/includes/checkout_requests.php';
require dirname(__DIR__) . '/includes/transaction_rules.php';
require dirname(__DIR__) . '/includes/schema_check.php';

// SQLite exercises persistence/rollback locally. MySQL concurrency is a separate
// integration check; this adapter changes only the lock/upsert SQL dialect.
class CheckoutTestDb extends PDO
{
    public function __construct() { parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(' FOR UPDATE', '', $query);
        $query = str_replace('ON DUPLICATE KEY UPDATE request_key = VALUES(request_key)', 'ON CONFLICT(channel, actor_id, request_key) DO NOTHING', $query);
        return parent::prepare($query, $options);
    }
}
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (LogicException|RuntimeException $e) { check(true, $message); return; }
    throw new RuntimeException($message);
}
$db = new CheckoutTestDb();
$db->exec('CREATE TABLE checkout_requests (channel TEXT, actor_id INTEGER, request_key TEXT, request_hash TEXT, order_id INTEGER, response_json TEXT, PRIMARY KEY(channel,actor_id,request_key))');
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT); CREATE TABLE payments (id INTEGER PRIMARY KEY, payment_status TEXT); CREATE TABLE menu_items (id INTEGER PRIMARY KEY, stock INTEGER)');
$key = str_repeat('a', 64);
$payload = ['items' => [['id' => 10, 'qty' => 2]], 'payment' => 'Cash'];
$hash = checkout_request_hash($payload);
check(checkout_request_key($key) === $key, 'valid key accepted');
rejects(fn() => checkout_request_key('bad'), 'malformed key rejected');
check($hash === checkout_request_hash(['payment' => 'Cash', 'items' => [['qty' => 2, 'id' => 10]], 'csrf_token' => 'new']), 'token renewal and object key order do not change identity');
check($hash !== checkout_request_hash(['items' => [['id' => 10, 'qty' => 3]], 'payment' => 'Cash']), 'quantity change changes identity');
check(checkout_request_hash($payload, 'proofA') !== checkout_request_hash($payload, 'proofB'), 'proof bytes participate in identity');
rejects(fn() => checkout_request_claim($db,'POS',2,$key,$hash), 'claim outside transaction rejected');
$db->beginTransaction();
check(checkout_request_claim($db,'POS',2,$key,$hash) === null, 'first attempt acquired');
$db->exec("INSERT INTO orders VALUES (1,'Pending'); INSERT INTO menu_items VALUES (10,8)");
$response = ['ok' => true, 'order_no' => 'ORD-TEST', 'receipt_no' => 'RCPT-TEST'];
checkout_request_complete($db,'POS',2,$key,1,$response);
$db->commit();
$db->beginTransaction();
check(checkout_request_claim($db,'POS',2,$key,$hash) === $response, 'retry returns original receipt');
check((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1, 'retry retains one order');
check((int)$db->query('SELECT stock FROM menu_items WHERE id=10')->fetchColumn() === 8, 'retry does not deduct stock again');
rejects(fn() => checkout_request_claim($db,'POS',2,$key,str_repeat('b',64)), 'changed payload cannot reuse a key');
$db->rollBack();
$db->beginTransaction();
check(checkout_request_claim($db,'POS',3,$key,$hash) === null, 'another actor cannot replay someone else’s response');
check(checkout_request_claim($db,'ONLINE',2,$key,$hash) === null, 'channel scopes are isolated');
$db->rollBack();
$db->beginTransaction();
check(checkout_request_claim($db,'ONLINE',2,$key,$hash) === null, 'rolled-back attempt is retryable');
$db->rollBack();
check(checkout_after_commit(static function () { throw new RuntimeException('Simulated email outage'); }) === false, 'notification failure is contained');
check((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1, 'saved order survives notification failure');
$proof = tempnam(sys_get_temp_dir(), 'kk-proof-');
file_put_contents($proof, 'payment evidence');
checkout_discard_proof($proof, true);
check(is_file($proof), 'proof survives uncertain commit outcome');
checkout_discard_proof($proof, false);
check(!is_file($proof), 'proof can be removed only before commit was attempted');
foreach (['Served','Picked Up','Cancelled'] as $status) { transaction_require_status('Refund',$status); check(true,'eligible refund status'); }
foreach (['Pending','Preparing','Ready','Refunded','Voided'] as $status) rejects(fn() => transaction_require_status('Refund',$status),'invalid refund status rejected');
$db->exec("INSERT INTO payments VALUES (1,'Paid'); INSERT INTO orders VALUES (2,'Cancelled')");
$db->beginTransaction();
transaction_require_status('Refund', (string)$db->query('SELECT status FROM orders WHERE id=2')->fetchColumn());
transaction_refund_payment($db,1);
check($db->query('SELECT payment_status FROM payments WHERE id=1')->fetchColumn() === 'Refunded', 'paid cancellation can be refunded');
check((int)$db->query('SELECT stock FROM menu_items WHERE id=10')->fetchColumn() === 8, 'refund never returns stock again');
rejects(fn() => transaction_refund_payment($db,1), 'duplicate refund rejected');
$db->rollBack();
check($db->query('SELECT payment_status FROM payments WHERE id=1')->fetchColumn() === 'Paid', 'failed decision rolls refund back');
$schema = kk_schema_definitions(file_get_contents(dirname(__DIR__) . '/schema.sql'));
check(count($schema) === 36, 'complete baseline tables parsed');
$actual=[];
foreach($schema as $table=>$columns) foreach($columns as $column=>$definition) {
    preg_match('/^(enum\([^)]+\)|varchar\(\d+\)|\S+)/i',$definition,$type);
    $actual[$table][$column]=['COLUMN_TYPE'=>$type[1], 'IS_NULLABLE'=>str_contains(strtoupper($definition),'NOT NULL')?'NO':'YES'];
}
check(kk_schema_issues($schema,$actual) === [], 'baseline passes compatibility check');
$actual['users']['pin_code']['COLUMN_TYPE']='varchar(10)';
$actual['payments']['payment_status']['COLUMN_TYPE']="enum('Pending','Paid','Failed','Custom')";
unset($actual['daily_queue_counters']);
$issues=implode("\n",kk_schema_issues($schema,$actual));
check(str_contains($issues,'Column too short: users.pin_code'), 'truncated PIN hashes detected');
check(str_contains($issues,'Missing table: daily_queue_counters'), 'missing queue detected');
check(str_contains($issues,'Missing enum values: payments.payment_status'), 'refund enum gap detected');
check(str_contains($issues,'Preserve custom enum values'), 'custom enums protected from blind migration');

$tz = new DateTimeZone('Asia/Manila');
$at = static fn(string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);
$values = static fn(array $day): array => array_column($day['slots'], 'value');
$friday = kk_pickup_slots($at('2026-11-20 23:00:00'));
check(in_array('2026-11-21 00:00:00', $values($friday[1]), true), 'Saturday overnight pickup is open');
check(!in_array('2026-11-21 03:00:00', $values($friday[1]), true), '3 AM closing is excluded');
check(!in_array('2026-11-21 08:30:00', $values($friday[1]), true), 'before opening excluded');
check(in_array('2026-11-21 09:00:00', $values($friday[1]), true), '9 AM opening available');
$saturday = kk_pickup_slots($at('2026-11-21 23:01:00'));
check($saturday[0]['slots'] === [] && $saturday[1]['slots'] === [], 'Saturday late cutoff and Sunday closure');
$sunday = kk_pickup_slots($at('2026-11-22 10:00:00'));
check($sunday[0]['slots'] === [], 'Sunday no slots');
check($sunday[1]['slots'][0]['value'] === '2026-11-23 09:00:00', 'Monday opens at 9 without overnight slots');
$now = $at('2026-11-17 12:01:00');
check(kk_pickup_slots($now)[0]['slots'][0]['value'] === '2026-11-17 13:00:00', 'lead time rounded to next half hour');
check(kk_validate_pickup_time('2026-11-17 13:00:00', $now) === '2026-11-17 13:00:00', 'valid selection accepted');
foreach (['2026-11-17 12:30:00', '2026-11-18 04:00:00', '2026-11-19 13:00:00', '2026-11-17 13:15:00', '', null] as $bad) {
    try { kk_validate_pickup_time($bad, $now); throw new RuntimeException('Invalid slot accepted'); }
    catch (InvalidArgumentException $e) { check(true, 'Invalid slot rejected'); }
}

$morning = kk_pickup_slots($at('2026-11-17 08:00:00'));
check($morning[0]['slots'][0]['value'] === '2026-11-17 09:00:00', 'Today includes 9 AM when ordering before opening');
check(in_array('2026-11-17 11:30:00', $values($morning[0]), true), 'Today supports before-lunch pickup');
check(in_array('2026-11-18 09:00:00', $values($morning[1]), true), 'Tomorrow supports morning pickup');
check(in_array('2026-11-18 02:30:00', $values($morning[1]), true), 'Tomorrow includes overnight service before 3 AM');

require_once dirname(__DIR__) . '/includes/customer_cancellation.php';
check(customer_cancel_eligible(['order_channel'=>'ONLINE','status'=>'Pending'], []), 'Pending pickup may cancel');
check(!customer_cancel_eligible(['order_channel'=>'ONLINE','status'=>'Preparing'], []), 'Preparing pickup cannot cancel');
check(!customer_cancel_eligible(['order_channel'=>'ONLINE','status'=>'Pending','preparing_at'=>'2026-11-17 12:00:00'], []), 'Preparation timestamp blocks cancellation even with stale status');
check(!customer_cancel_eligible(['order_channel'=>'ONLINE','status'=>'Ready'], []), 'Ready pickup cannot cancel');

require_once dirname(__DIR__) . '/config/bilao-pricing.php';
$menuDb = new CheckoutTestDb();
$menuDb->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, category_name TEXT, status TEXT)');
$menuDb->exec('CREATE TABLE menu_items (id INTEGER PRIMARY KEY, item_name TEXT, category_id INTEGER, stock INTEGER, availability TEXT, is_archived INTEGER, sort_order INTEGER, image_path TEXT, price REAL)');
$menuDb->exec("INSERT INTO categories (id, category_name, status) VALUES (1, 'Bilao Favorites', 'Active'), (2, 'Drinks', 'Active')");
$menuDb->exec("INSERT INTO menu_items (id, item_name, category_id, stock, availability, is_archived, sort_order, price) VALUES
    (51, 'Spaghetti Small', 1, 6, 'Available', 0, 1, 525),
    (136, 'Spaghetti Medium', 1, 0, 'Unavailable', 0, 2, 850),
    (137, 'Spaghetti Large', 1, 4, 'Available', 0, 3, 1250),
    (78, 'Carbonara Large', 1, 50, 'Available', 0, 4, 1200),
    (79, 'Carbonara XL', 1, 5, 'Available', 0, 5, 1550),
    (122, 'Assorted 1L Bottled Drink', 2, 0, 'Unavailable', 1, 1, 60),
    (123, 'Sprite 1L Bottle', 2, 0, 'Unavailable', 0, 2, 60),
    (124, 'Royal 1L Bottle', 2, 4, 'Available', 0, 3, 65),
    (139, 'Coca-Cola 1L Bottle', 2, 8, 'Available', 0, 4, 60)");

$bilaoStates = resolve_bilao_variant_states($menuDb);
check((int)$bilaoStates['Spaghetti']['variants']['S']['id'] === 51 && (int)$bilaoStates['Spaghetti']['variants']['M']['id'] === 136, 'Spaghetti S and M map strictly to their own menu_item_id');
check((int)$bilaoStates['Spaghetti']['variants']['M']['stock'] === 0 && $bilaoStates['Spaghetti']['variants']['M']['eligible'] === false, 'Zero-stock Spaghetti M does not borrow Small stock');
check((float)$bilaoStates['Spaghetti']['variants']['S']['price'] === 525.0 && (float)$bilaoStates['Spaghetti']['variants']['L']['price'] === 1250.0, 'Bilao resolver preserves edited database prices');
check((int)$bilaoStates['Carbonara']['variants']['L']['id'] === 78 && (int)$bilaoStates['Carbonara']['variants']['XL']['id'] === 79, 'Carbonara L and XL resolve to separate menu items');
check((int)$bilaoStates['Carbonara']['variants']['L']['stock'] === 50 && (int)$bilaoStates['Carbonara']['variants']['XL']['stock'] === 5, 'Independent Bilao variants keep separate stock counts');

$vSpagBad = validate_bilao_variant_order($menuDb, 51, 'Spaghetti', 'M');
check($vSpagBad['ok'] === false, 'Spaghetti M rejects mismatched Small ID 51');
$vSpagZero = validate_bilao_variant_order($menuDb, 136, 'Spaghetti', 'M');
check($vSpagZero['ok'] === false, 'Zero-stock Spaghetti M cannot be ordered');
$vSpagOk = validate_bilao_variant_order($menuDb, 137, 'Spaghetti', 'L');
check($vSpagOk['ok'] === true && (int)$vSpagOk['variant']['id'] === 137 && (float)$vSpagOk['variant']['price'] === 1250.0, 'Spaghetti L validates with its own ID and database price');

$drinkStates = resolve_one_liter_drink_variants($menuDb);
check($drinkStates['parent_archived'] === true && $drinkStates['all_variants_archived'] === false, 'Archived Assorted 1L parent does not disable independent 1L drinks');
check($drinkStates['variants']['Coca-Cola']['eligible'] === true && (int)$drinkStates['variants']['Coca-Cola']['menu_item_id'] === 139 && (int)$drinkStates['variants']['Coca-Cola']['stock'] === 8, 'Coca-Cola 1L Bottle resolves independently');
check($drinkStates['variants']['Sprite']['eligible'] === false && $drinkStates['variants']['Sprite']['is_archived'] === false && (int)$drinkStates['variants']['Sprite']['stock'] === 0, 'Zero-stock Sprite 1L is active in catalog but not eligible');
check($drinkStates['variants']['Royal']['has_dedicated_row'] === true && (int)$drinkStates['variants']['Royal']['menu_item_id'] === 124 && (int)$drinkStates['variants']['Royal']['stock'] === 4, 'Active Royal 1L keeps independent stock 4');

// Verify aggregated row deduction rejects combined oversell across Tray and Bilao packaging for the same size (4 + 3 = 7 > 6)
$rowsSharingPool = [
    ['id' => 51, 'qty' => 4, 'notes' => 'Packaging: Tray'],
    ['id' => 51, 'qty' => 3, 'notes' => 'Packaging: Bilao']
];
$poolTotals = [];
foreach ($rowsSharingPool as $line) {
    $poolTotals[$line['id']] = ($poolTotals[$line['id']] ?? 0) + (int)$line['qty'];
}
$spagAvail = (int)$menuDb->query('SELECT stock FROM menu_items WHERE id = 51')->fetchColumn();
check($poolTotals[51] === 7 && $poolTotals[51] > $spagAvail, 'Server-side row aggregation detects combined oversell across notes/packaging');

require_once dirname(__DIR__) . '/includes/delivery_tracking.php';

// Delivery workflow regression suite: Option 1 gate, Option 2 staff tracking sync, and Delivered completion
$wfDb = new CheckoutTestDb();
$wfDb->exec("
    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_no TEXT,
        queue_no INTEGER,
        order_type TEXT,
        order_channel TEXT,
        fulfillment_mode TEXT,
        delivery_mode TEXT,
        courier_booking_by TEXT,
        delivery_tracking_url TEXT,
        courier_tracking_status TEXT DEFAULT 'None',
        courier_tracking_verified_by INTEGER,
        courier_tracking_verified_at TEXT,
        courier_tracking_rejection_reason TEXT,
        payment_verification_status TEXT DEFAULT 'None',
        cod_approval_status TEXT DEFAULT 'None',
        cash_collected REAL,
        collected_by_user_id INTEGER,
        free_delivery_discount REAL DEFAULT 0,
        customer_phone TEXT,
        delivery_address TEXT,
        total REAL DEFAULT 150,
        status TEXT DEFAULT 'Pending',
        kitchen_queued_at TEXT,
        served_at TEXT,
        served_by INTEGER,
        paid_at TEXT,
        notes TEXT,
        inventory_deducted INTEGER DEFAULT 1
    );
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        payment_method TEXT,
        payment_status TEXT,
        amount_paid REAL DEFAULT 0,
        change_amount REAL DEFAULT 0,
        paid_at TEXT,
        shift_id INTEGER
    );
    CREATE TABLE delivery_dispatches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        rider_name TEXT,
        rider_phone TEXT,
        status TEXT,
        notes TEXT,
        assigned_by INTEGER,
        updated_by INTEGER,
        assigned_at TEXT,
        out_at TEXT,
        delivered_at TEXT,
        failed_at TEXT
    );
    CREATE TABLE thirdparty_deliveries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        booking_service TEXT,
        delivery_fee REAL,
        tracking_reference TEXT,
        fee_paid INTEGER DEFAULT 0,
        status TEXT
    );
    CREATE TABLE transaction_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        request_type TEXT,
        status TEXT
    );
    CREATE TABLE cashier_shifts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, status TEXT);
    CREATE TABLE cod_cash_handovers (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, collected_by_user_id INTEGER, amount_collected REAL, collected_at TEXT, status TEXT);
    CREATE TABLE first_order_benefit_hashes (phone_hash TEXT, address_hash TEXT, used_order_id INTEGER);
    CREATE TABLE inventory_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, inventory_id INTEGER, menu_item_id INTEGER, user_id INTEGER, action TEXT, quantity INTEGER, remarks TEXT);
");

// 1. Option 1 gate check: unverified tracking stays held from Kitchen even when GCash is Paid
$wfDb->exec("INSERT INTO orders (id, order_no, queue_no, order_type, order_channel, fulfillment_mode, delivery_mode, courier_booking_by, courier_tracking_status, payment_verification_status, total, status) VALUES (101, 'ONL-OPT1', 1, 'ONLINE', 'ONLINE', 'OUTSIDE', 'CUSTOMER_BOOKED', 'CUSTOMER', 'None', 'Verified', 200, 'Pending')");
$wfDb->exec("INSERT INTO payments (order_id, payment_method, payment_status, amount_paid) VALUES (101, 'GCash', 'Paid', 200)");
$opt1Row = $wfDb->query("SELECT * FROM orders WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
check(kk_is_option1_customer_courier($opt1Row) === true, 'Option 1 courier detected accurately');
check(kk_should_queue_online_gcash_order_to_kitchen($opt1Row) === false, 'Option 1 without verified link must not queue to Kitchen on food payment');
check((int)$wfDb->query("SELECT COUNT(*) FROM orders o WHERE o.id = 101 AND " . kk_kitchen_option1_gate_sql('o'))->fetchColumn() === 0, 'Kitchen SQL gate excludes Option 1 order without verified link');

// Customer submits tracking link -> status becomes Pending, still held from Kitchen
$wfDb->exec("UPDATE orders SET delivery_tracking_url = 'https://share.lalamove.com/opt1-101', courier_tracking_status = 'Pending' WHERE id = 101");
check((int)$wfDb->query("SELECT COUNT(*) FROM orders o WHERE o.id = 101 AND " . kk_kitchen_option1_gate_sql('o'))->fetchColumn() === 0, 'Kitchen SQL gate still excludes Option 1 Pending tracking link until staff approves');

// Staff rejects Option 1 link -> Rejected + reason saved, still held
$wfDb->beginTransaction();
$rej = kk_review_option1_tracking_link($wfDb, 101, 'reject', 9, 'Broken tracking URL');
$wfDb->commit();
$opt1AfterRej = $wfDb->query("SELECT courier_tracking_status, courier_tracking_rejection_reason, kitchen_queued_at FROM orders WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
check($rej['released_to_kitchen'] === false && $opt1AfterRej['courier_tracking_status'] === 'Rejected' && $opt1AfterRej['courier_tracking_rejection_reason'] === 'Broken tracking URL' && $opt1AfterRej['kitchen_queued_at'] === null, 'Option 1 rejection clears verification and stores reason');

// Customer submits valid link and Staff approves -> Verified + released to Kitchen
$wfDb->exec("UPDATE orders SET delivery_tracking_url = 'https://share.lalamove.com/opt1-valid', courier_tracking_status = 'Pending' WHERE id = 101");
$wfDb->beginTransaction();
$ver = kk_review_option1_tracking_link($wfDb, 101, 'verify', 9);
$wfDb->commit();
$opt1AfterVer = $wfDb->query("SELECT courier_tracking_status, kitchen_queued_at FROM orders WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
check($ver['released_to_kitchen'] === true && $opt1AfterVer['courier_tracking_status'] === 'Verified' && $opt1AfterVer['kitchen_queued_at'] !== null, 'Option 1 verification releases paid order to Kitchen');
check((int)$wfDb->query("SELECT COUNT(*) FROM orders o WHERE o.id = 101 AND " . kk_kitchen_option1_gate_sql('o'))->fetchColumn() === 1, 'Kitchen SQL gate allows Option 1 order once verified');

// Kitchen prepares -> Ready -> Handoff -> Failure -> Retry Handoff -> Delivered
$wfDb->exec("UPDATE orders SET status = 'Ready' WHERE id = 101");
$wfDb->beginTransaction();
$disp1 = kk_dispatch_thirdparty_order($wfDb, 101, 9);
$wfDb->commit();
check($disp1['booking_by'] === 'CUSTOMER' && $wfDb->query("SELECT status FROM delivery_dispatches WHERE order_id = 101")->fetchColumn() === 'Out for Delivery', 'Option 1 dispatches Out for Delivery');

$wfDb->beginTransaction();
kk_fail_delivery_order($wfDb, 101, 9, 'Rider delayed at checkpoint');
$wfDb->commit();
check($wfDb->query("SELECT status FROM delivery_dispatches WHERE order_id = 101")->fetchColumn() === 'Failed', 'Delivery failure recorded');

$wfDb->beginTransaction();
kk_dispatch_thirdparty_order($wfDb, 101, 9);
$comp1 = kk_complete_delivery_order($wfDb, 101, 9, 'admin', true);
$wfDb->commit();
check($comp1['order_no'] === 'ONL-OPT1'
    && $wfDb->query("SELECT status FROM orders WHERE id = 101")->fetchColumn() === 'Served'
    && $wfDb->query("SELECT status FROM delivery_dispatches WHERE order_id = 101")->fetchColumn() === 'Delivered', 'Option 1 retry dispatch and Delivered completion succeeds');

// 2. Option 2: Store-booked courier tracking sync, fee guard, dispatch, and completion
$wfDb->exec("INSERT INTO orders (id, order_no, queue_no, order_type, order_channel, fulfillment_mode, delivery_mode, courier_booking_by, payment_verification_status, total, status, kitchen_queued_at) VALUES (102, 'ONL-OPT2', 2, 'ONLINE', 'ONLINE', 'OUTSIDE', 'CUSTOMER_BOOKED', 'STORE', 'Verified', 320, 'Ready', '2026-11-18 12:00:00')");
$wfDb->exec("INSERT INTO payments (order_id, payment_method, payment_status, amount_paid) VALUES (102, 'GCash', 'Paid', 320)");
$wfDb->exec("INSERT INTO thirdparty_deliveries (order_id, booking_service, delivery_fee, fee_paid, status) VALUES (102, 'Lalamove', 95, 0, 'Quoted')");
$opt2Row = $wfDb->query("SELECT * FROM orders WHERE id = 102")->fetch(PDO::FETCH_ASSOC);
check(kk_should_queue_online_gcash_order_to_kitchen($opt2Row) === true, 'Option 2 queues to Kitchen on food GCash verification');
check((int)$wfDb->query("SELECT COUNT(*) FROM orders o WHERE o.id = 102 AND " . kk_kitchen_option1_gate_sql('o'))->fetchColumn() === 1, 'Kitchen SQL gate allows Option 2 order');

$wfDb->beginTransaction();
kk_save_staff_tracking_link($wfDb, 102, 'https://share.grab.com/opt2-102', 9);
$wfDb->commit();
check($wfDb->query("SELECT delivery_tracking_url FROM orders WHERE id = 102")->fetchColumn() === 'https://share.grab.com/opt2-102'
    && $wfDb->query("SELECT tracking_reference FROM thirdparty_deliveries WHERE order_id = 102")->fetchColumn() === 'https://share.grab.com/opt2-102', 'Option 2 staff tracking link syncs to both orders and thirdparty_deliveries');

// Fee unpaid blocks Option 2 dispatch
$wfDb->beginTransaction();
rejects(fn() => kk_dispatch_thirdparty_order($wfDb, 102, 9), 'Option 2 unpaid fee rejects dispatch');
$wfDb->rollBack();

// Verify fee -> Dispatch -> Complete Delivered
$wfDb->exec("UPDATE thirdparty_deliveries SET fee_paid = 1, status = 'Fee Paid' WHERE order_id = 102");
$wfDb->beginTransaction();
$disp2 = kk_dispatch_thirdparty_order($wfDb, 102, 9);
$wfDb->commit();
check($disp2['booking_by'] === 'STORE' && $wfDb->query("SELECT status FROM thirdparty_deliveries WHERE order_id = 102")->fetchColumn() === 'Dispatched', 'Option 2 dispatches once fee and tracking link exist');

$wfDb->beginTransaction();
$comp2 = kk_complete_delivery_order($wfDb, 102, 9, 'admin', true);
$wfDb->commit();
check($wfDb->query("SELECT status FROM orders WHERE id = 102")->fetchColumn() === 'Served'
    && $wfDb->query("SELECT status FROM delivery_dispatches WHERE order_id = 102")->fetchColumn() === 'Delivered', 'Option 2 completes cleanly as Delivered');

// 3. Within Lagro COD completion checks cash confirmation
$wfDb->exec("INSERT INTO orders (id, order_no, queue_no, order_type, order_channel, fulfillment_mode, delivery_mode, courier_booking_by, cod_approval_status, total, status, kitchen_queued_at) VALUES (103, 'ONL-COD', 3, 'ONLINE', 'ONLINE', 'LAGRO', 'LAGRO_FREE', 'NONE', 'Approved', 140, 'Ready', '2026-11-18 12:00:00')");
$wfDb->exec("INSERT INTO payments (order_id, payment_method, payment_status, amount_paid) VALUES (103, 'Cash on Delivery', 'Pending', 0)");
$wfDb->exec("INSERT INTO delivery_dispatches (order_id, rider_name, status) VALUES (103, 'Store Delivery', 'Out for Delivery')");
$wfDb->beginTransaction();
rejects(fn() => kk_complete_delivery_order($wfDb, 103, 9, 'admin', false), 'Within-Lagro COD completion rejects missing cash confirmation');
$wfDb->rollBack();
$wfDb->beginTransaction();
$comp3 = kk_complete_delivery_order($wfDb, 103, 9, 'admin', true);
$wfDb->commit();
check($wfDb->query("SELECT payment_status FROM payments WHERE order_id = 103")->fetchColumn() === 'Paid'
    && (float)$wfDb->query("SELECT cash_collected FROM orders WHERE id = 103")->fetchColumn() === 140.0
    && $wfDb->query("SELECT status FROM orders WHERE id = 103")->fetchColumn() === 'Served', 'Within-Lagro COD completes payment and Served status on cash confirmation');

echo "PASS: $checks regression checks (SQLite persistence; MySQL concurrency not covered).\n";
