<?php
/**
 * 911-Order Historical Seed Generator (Sept 21-27, 2026)
 * database/seed_sales_sept2026.php
 */
require_once dirname(__DIR__) . '/config/db.php';

echo "Running September 2026 sales dataset seed...\n";

$sqlFile = __DIR__ . '/seed_sales_sept2026.sql';
if (!file_exists($sqlFile)) {
    die("Error: seed_sales_sept2026.sql not found.\n");
}

try {
    $sql = file_get_contents($sqlFile);
    $conn->exec($sql);
    echo "Successfully imported 911 historical orders into MySQL database 'kenjis_kitchen'!\n";
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
