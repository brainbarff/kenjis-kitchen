<?php
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 3) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__, 3) . '/config/db.php';

function favorites_json($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$userId = (int)($_SESSION['customer_user_id'] ?? 0);
if ($userId < 1) {
    unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
    favorites_json(['status' => 'error', 'message' => 'Sign in to save favorites.'], 401);
}

$authStmt = $conn->prepare("
    SELECT u.id, u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");
$authStmt->execute([$userId]);
$authUser = $authStmt->fetch();

if (
    !$authUser
    || ($authUser['role_name'] ?? '') !== 'Customer (Online)'
    || ($authUser['status'] ?? '') !== 'Active'
    || ($authUser['customer_status'] ?? 'Active') !== 'Active'
    || empty($authUser['email_verified_at'])
    || !empty($authUser['deleted_at'])
) {
    unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
    favorites_json(['status' => 'error', 'message' => 'Sign in to save favorites.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("SELECT m.id, m.item_name AS name, m.description, m.price, m.promo_price, m.image, m.stock, c.category_name FROM customer_favorites f JOIN menu_items m ON m.id = f.menu_item_id JOIN categories c ON c.id = m.category_id WHERE f.user_id = ? AND m.is_archived = 0 ORDER BY f.created_at DESC");
    $stmt->execute([$userId]);
    favorites_json(['status' => 'success', 'data' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') favorites_json(['status' => 'error', 'message' => 'Method not allowed.'], 405);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) favorites_json(['status' => 'error', 'message' => 'Invalid request data.'], 400);
$itemId = filter_var($data['item_id'] ?? null, FILTER_VALIDATE_INT);
$action = $data['action'] ?? '';
if (!$itemId || !in_array($action, ['add', 'remove'], true)) favorites_json(['status' => 'error', 'message' => 'Invalid favorite request.'], 400);

if ($action === 'add') {
    $check = $conn->prepare('SELECT id FROM menu_items WHERE id = ? AND is_archived = 0');
    $check->execute([$itemId]);
    if (!$check->fetchColumn()) favorites_json(['status' => 'error', 'message' => 'Menu item is no longer available.'], 404);
    $stmt = $conn->prepare('INSERT IGNORE INTO customer_favorites (user_id, menu_item_id) VALUES (?, ?)');
    $stmt->execute([$userId, $itemId]);
} else {
    $stmt = $conn->prepare('DELETE FROM customer_favorites WHERE user_id = ? AND menu_item_id = ?');
    $stmt->execute([$userId, $itemId]);
}

favorites_json(['status' => 'success', 'action' => $action]);
