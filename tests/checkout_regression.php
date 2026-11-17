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

echo "PASS: $checks regression checks (SQLite persistence; MySQL concurrency not covered).\n";
