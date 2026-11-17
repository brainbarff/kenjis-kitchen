<?php
/**
 * Drawer & Cash Float Action API
 */
header('Content-Type: application/json; charset=UTF-8');
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/pos_cashier.php';
require_once ROOT_PATH . '/includes/rate_limiter.php';
require_role(['admin', 'cashier']);

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token()) {
    http_response_code($_SERVER['REQUEST_METHOD'] !== 'POST' ? 405 : 403);
    echo json_encode(['ok' => false, 'msg' => 'Invalid request method or security token.']);
    exit;
}
try {
    $cashier = pos_active_cashier($conn);
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}
$userId = (int)$cashier['id'];

if ($action === 'open_drawer') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin = trim((string)($data['pin'] ?? ''));
    $reason = trim((string)($data['reason'] ?? 'Manual Drawer Access'));

    $rateKey = 'drawer:' . $userId;
    $rateLimit = kk_check_login_rate_limit($rateKey);
    if ($rateLimit['blocked']) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'msg' => $rateLimit['message']]);
        exit;
    }
    $stmt = $conn->prepare('SELECT pin_code FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $storedPin = (string)$stmt->fetchColumn();
    $isAdminOperator = strcasecmp((string)($cashier['role_name'] ?? ''), 'Admin') === 0;

    if ($isAdminOperator && ($pin === '' || $storedPin === '')) {
        $pinValid = true;
    } else {
        $pinValid = false;
        if (preg_match('/^[0-9]{4}$/', $pin) && $storedPin !== '') {
            $pinValid = preg_match('/^[0-9]{4}$/', $storedPin)
                ? hash_equals($storedPin, $pin)
                : password_verify($pin, $storedPin);
        }
    }

    if (!$pinValid) {
        kk_record_login_failure($rateKey);
        echo json_encode(['ok' => false, 'msg' => 'Incorrect cashier PIN. Access denied.']);
        exit;
    }
    kk_clear_login_rate_limit($rateKey);

    try {
        $stmt = $conn->prepare("INSERT INTO drawer_logs (user_id, pin_used, reason) VALUES (?, NULL, ?)");
        $stmt->execute([$userId, mb_substr($reason ?: 'Manual Drawer Access', 0, 100)]);
    } catch (Throwable $t) {
        error_log('Drawer access log failed: ' . $t->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Could not record drawer access.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'msg' => 'Drawer access authorized and logged. Physical drawer control is not connected.',
        'pin_role' => $cashier['role_name'],
        'hardware_opened' => false
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Invalid action.']);
