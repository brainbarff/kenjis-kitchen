<?php
/**
 * Kenji's Kitchen – Price Helper Functions
 * Shared helper for processing scheduled price updates.
 * Safe to include from both menu/index.php and api/update_price.php.
 */

/**
 * Automatically applies any scheduled price updates that have reached their effective date.
 *
 * @param PDO $conn  Active database connection
 */
function processPendingPriceUpdates($conn) {
    try {
        $now = date('Y-m-d H:i:s');
        $dueStmt = $conn->prepare("
            SELECT id, item_name, price, pending_price, pending_price_effective
            FROM menu_items
            WHERE pending_price IS NOT NULL
              AND pending_price_effective IS NOT NULL
              AND pending_price_effective <= ?
        ");
        $dueStmt->execute([$now]);
        $dueItems = $dueStmt->fetchAll();

        foreach ($dueItems as $item) {
            $update = $conn->prepare("
                UPDATE menu_items
                SET price = pending_price, pending_price = NULL, pending_price_effective = NULL
                WHERE id = ?
            ");
            $update->execute([$item['id']]);

            // Mark audit log entry as applied
            $log = $conn->prepare("
                UPDATE price_change_logs
                SET status = 'Applied'
                WHERE menu_item_id = ? AND status = 'Scheduled' AND effective_at <= ?
            ");
            $log->execute([$item['id'], $now]);
        }
    } catch (Exception $e) {
        error_log('processPendingPriceUpdates error: ' . $e->getMessage());
    }
}
