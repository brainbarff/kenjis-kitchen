<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';

require_role(['admin', 'cashier']);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $stmt = $conn->query("
        SELECT id, stock, availability
        FROM menu_items
        WHERE is_archived = 0
        ORDER BY id ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'stock' => max(0, (int)$row['stock']),
                'availability' => (string)$row['availability'],
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Unable to fetch menu stock snapshot.',
    ], JSON_UNESCAPED_UNICODE);
}
