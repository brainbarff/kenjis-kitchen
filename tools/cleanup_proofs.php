<?php
/**
 * Automated Proof Cleanup Task (Cron / Scheduled Job)
 * Deletes payment proof screenshot files older than proof_retention_days (default 90 days).
 * Preserves the database audit record and reference numbers while freeing disk space.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI execution only.');
}

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/config.php';

$retentionDays = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'proof_retention_days'")->fetchColumn() ?: 90);

echo "Starting Payment Proof Cleanup (Retention: {$retentionDays} days)...\n";

$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

$stmt = $conn->prepare("
    SELECT id, image_path 
    FROM payment_proofs 
    WHERE submitted_at < ? 
      AND image_path IS NOT NULL 
      AND image_path <> 'CLEANED_UP'
");
$stmt->execute([$cutoffDate]);
$proofs = $stmt->fetchAll();

$deletedCount = 0;
foreach ($proofs as $p) {
    $filePath = ROOT_PATH . '/' . ltrim($p['image_path'], '/');
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    // Update record to indicate file was purged per retention policy
    $conn->prepare("UPDATE payment_proofs SET image_path = 'CLEANED_UP' WHERE id = ?")->execute([$p['id']]);
    $deletedCount++;
}

echo "Cleanup complete. Purged {$deletedCount} expired payment screenshots submitted before {$cutoffDate}.\n";
