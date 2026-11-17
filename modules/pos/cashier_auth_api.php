<?php
/**
 * Cashier POS Authentication API
 * POST /modules/pos/cashier_auth_api.php?action=login|current|logout
 */
header('Content-Type: application/json; charset=UTF-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/pos_cashier.php';
require_once ROOT_PATH . '/includes/rate_limiter.php';
require_role(['admin', 'cashier']);

$action = $_GET['action'] ?? '';
if ($action === 'current' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}
if (in_array($action, ['login', 'logout'], true) && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token())) {
    http_response_code($_SERVER['REQUEST_METHOD'] !== 'POST' ? 405 : 403);
    echo json_encode(['ok' => false, 'msg' => 'Invalid request method or security token.']);
    exit;
}

// 1. GET CURRENT CASHIER IDENTITY
if ($action === 'current') {
    $activeCashier = null;
    try {
        $resolved = pos_active_cashier($conn);
        $activeCashier = $_SESSION['pos_active_cashier'] ?? [
            'id' => (int)$resolved['id'],
            'username' => $resolved['username'],
            'full_name' => $resolved['full_name'],
            'role' => $resolved['role_name'],
            'authenticated_at' => date('Y-m-d H:i:s')
        ];
    } catch (RuntimeException $e) {
        $activeCashier = null;
    }
    echo json_encode(['ok' => true, 'authenticated' => (bool)$activeCashier, 'cashier' => $activeCashier]);
    exit;
}

// 2. AUTHENTICATE CASHIER WITH USERNAME + 4-DIGIT PIN
if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim((string)($data['username'] ?? ''));
    $pin = trim((string)($data['pin'] ?? ''));

    if ($username === '' || $pin === '') {
        echo json_encode(['ok' => false, 'msg' => 'Please enter both your Username and 4-digit PIN.']);
        exit;
    }

    if (!preg_match('/^[0-9]{4}$/', $pin)) {
        echo json_encode(['ok' => false, 'msg' => 'PIN must be exactly 4 numeric digits.']);
        exit;
    }

    $rateKey = 'pos:' . strtolower($username);
    $rateLimit = kk_check_login_rate_limit($rateKey);
    if ($rateLimit['blocked']) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'msg' => $rateLimit['message']]);
        exit;
    }

    // Support shorthand POS cashier aliases (C1 -> cashier1, C2 -> cashier2)
    $lookupUsername = $username;
    if (strcasecmp($lookupUsername, 'C1') === 0) {
        $lookupUsername = 'cashier1';
    } elseif (strcasecmp($lookupUsername, 'C2') === 0) {
        $lookupUsername = 'cashier2';
    }

    // Query active cashier from users table
    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.username, u.pin_code, u.status, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE LOWER(u.username) IN (LOWER(?), LOWER(?))
          AND r.role_name IN ('Cashier', 'Admin')
          AND u.status = 'Active'
          AND u.is_archived = 0
          AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$lookupUsername, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        kk_record_login_failure($rateKey);
        echo json_encode(['ok' => false, 'msg' => 'Invalid cashier credentials.']);
        exit;
    }

    // Verify 4-digit PIN (supports bcrypt hash or 4-digit PIN string)
    $storedPin = (string)($user['pin_code'] ?? '');
    $pinValid = false;
    if ($storedPin !== '') {
        if (preg_match('/^[0-9]{4}$/', $storedPin)) {
            $pinValid = hash_equals($storedPin, $pin);
        } else {
            $pinValid = password_verify($pin, $storedPin);
        }
    }
    if (!$pinValid) {
        kk_record_login_failure($rateKey);
        echo json_encode(['ok' => false, 'msg' => 'Invalid cashier credentials.']);
        exit;
    }
    kk_clear_login_rate_limit($rateKey);
    session_regenerate_id(true);

    // Save active cashier session identity
    $_SESSION['pos_active_cashier'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role_name'],
        'authenticated_at' => date('Y-m-d H:i:s')
    ];

    echo json_encode([
        'ok' => true,
        'msg' => 'Cashier authenticated successfully.',
        'cashier' => $_SESSION['pos_active_cashier']
    ]);
    exit;
}

// 3. SWITCH / LOGOUT ACTIVE CASHIER
if ($action === 'logout') {
    unset($_SESSION['pos_active_cashier']);
    echo json_encode(['ok' => true, 'msg' => 'Cashier signed out from terminal.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Invalid action.']);
