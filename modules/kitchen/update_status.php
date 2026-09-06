<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'kitchen']);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = (int) ($data['id'] ?? 0);
$status = $data['status'] ?? '';
$allowed = ['Preparing', 'Ready'];

if (!$id || !in_array($status, $allowed, true)) {
    echo json_encode(['ok' => false]);
    exit;
}

if ($status === 'Preparing') {
    $stmt = $conn->prepare("UPDATE orders SET status = 'Preparing', preparing_at = NOW() WHERE id = ? AND status = 'Pending'");
    $stmt->execute([$id]);
}

if ($status === 'Ready') {
    $stmt = $conn->prepare("UPDATE orders SET status = 'Ready', ready_at = NOW() WHERE id = ? AND status = 'Preparing'");
    $stmt->execute([$id]);
}

echo json_encode(['ok' => true]);
?>
