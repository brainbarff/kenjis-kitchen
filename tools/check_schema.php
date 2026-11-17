<?php
// Read-only preflight: never imports a dump or applies a migration.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/config/config.php';
require_once ROOT_PATH . '/includes/schema_check.php';
$options = getopt('', ['database:']);
$database = $options['database'] ?? '';
if (!is_string($database) || !preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
    fwrite(STDERR, "Usage: php tools/check_schema.php --database=EXACT_DATABASE_NAME\nUses DB_HOST/DB_PORT/DB_USER/DB_PASS from the environment. Read-only.\n");
    exit(2);
}
try {
    $db = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database . ';charset=utf8mb4', getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $db->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
    $stmt->execute([$database]);
    $actual = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $actual[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    $issues = kk_schema_issues(kk_schema_definitions(file_get_contents(ROOT_PATH . '/schema.sql')), $actual);
    // Checkout replay depends on this exact uniqueness guarantee, not just columns.
    $index = $db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'checkout_requests' AND INDEX_NAME = 'PRIMARY' AND NON_UNIQUE = 0 ORDER BY SEQ_IN_INDEX");
    $index->execute([$database]);
    if ($index->fetchAll(PDO::FETCH_COLUMN) !== ['channel', 'actor_id', 'request_key']) $issues[] = 'checkout_requests requires PRIMARY KEY (channel, actor_id, request_key).';
    echo json_encode(['database' => $database, 'read_only' => true, 'compatible' => !$issues, 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($issues ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Schema check could not connect or complete. Verify the selected database and connection settings.\n");
    exit(2);
}
