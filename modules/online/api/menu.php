<?php
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/config/config.php';

function menu_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? 'items';
$placeholder = BASE_URL . '/assets/img/food-placeholder.svg';

try {
    if ($action === 'categories') {
        $stmt = $conn->query("SELECT id, category_name AS name FROM categories WHERE status = 'Active' ORDER BY sort_order ASC, category_name ASC");
        menu_json(['status' => 'success', 'data' => $stmt->fetchAll()]);
    }

    if ($action === 'items') {
        $categoryId = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);
        $search = trim((string)($_GET['q'] ?? ''));

        $sql = "SELECT m.id, m.category_id, c.category_name, m.item_name AS name, m.description, m.price, m.promo_price, m.promo_start, m.promo_end, m.image, m.availability, m.stock
                FROM menu_items m
                JOIN categories c ON c.id = m.category_id
                WHERE m.is_archived = 0";
        $params = [];

        if ($categoryId) {
            $sql .= " AND m.category_id = ?";
            $params[] = $categoryId;
        }
        if ($search !== '') {
            $sql .= " AND (m.item_name LIKE ? OR m.description LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY c.sort_order ASC, m.sort_order ASC, m.item_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['category_id'] = (int)$item['category_id'];
            $item['stock'] = (int)$item['stock'];
            $item['price'] = (float)$item['price'];
            $item['promo_price'] = $item['promo_price'] !== null ? (float)$item['promo_price'] : null;
            $item['effective_price'] = $item['price'];
            $today = date('Y-m-d');
            if ($item['promo_price'] !== null && $item['promo_start'] !== null && $item['promo_end'] !== null && $today >= $item['promo_start'] && $today <= $item['promo_end']) {
                $item['effective_price'] = $item['promo_price'];
            }
            $item['is_available'] = ($item['stock'] > 0 && $item['availability'] === 'Available') ? 1 : 0;
            $imageName = basename((string)($item['image'] ?? ''));
            $item['image_url'] = $imageName !== '' ? BASE_URL . '/uploads/menu/' . rawurlencode($imageName) : $placeholder;
        }
        unset($item);

        menu_json(['status' => 'success', 'count' => count($items), 'data' => $items]);
    }

    menu_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
} catch (Throwable $e) {
    menu_json(['status' => 'error', 'message' => 'Unable to load menu data.'], 500);
}
