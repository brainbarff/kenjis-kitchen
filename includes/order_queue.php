<?php
declare(strict_types=1);

/** Allocate a daily queue number while the caller's order transaction is open. */
function next_daily_queue_no(PDO $conn): int
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Queue numbers must be allocated inside the order transaction.');
    }

    $conn->exec("
        INSERT INTO daily_queue_counters (queue_date, last_no)
        VALUES (
            CURDATE(),
            LAST_INSERT_ID((
                SELECT COALESCE(MAX(queue_no), 0) + 1
                FROM orders
                WHERE created_at >= CURDATE()
                  AND created_at < CURDATE() + INTERVAL 1 DAY
            ))
        )
        ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)
    ");

    $number = (int)$conn->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($number < 1) {
        throw new RuntimeException('Unable to allocate a queue number.');
    }
    return $number;
}
