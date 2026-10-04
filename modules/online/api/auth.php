<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 3) . '/config/db.php';

function online_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
    online_json(['status' => 'success', 'message' => 'Logged out.']);
}

if ($action === 'me') {
    $id = (int)($_SESSION['customer_user_id'] ?? 0);
    if (!$id) {
        online_json(['status' => 'success', 'authenticated' => false]);
    }

    $stmt = $conn->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.status, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'Active' LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user || $user['role_name'] !== 'Customer (Online)') {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        online_json(['status' => 'success', 'authenticated' => false]);
    }

    unset($user['status'], $user['role_name']);
    $_SESSION['customer_user'] = $user;
    online_json(['status' => 'success', 'authenticated' => true, 'user' => $user]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    online_json(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    online_json(['status' => 'error', 'message' => 'Invalid request data.'], 400);
}

if ($action === 'register') {
    $fullName = trim((string)($data['full_name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $address = trim(strip_tags((string)($data['address'] ?? '')));

    if (!preg_match('/^[\p{L}\s\.\'\-]{2,100}$/u', $fullName)) {
        online_json(['status' => 'error', 'message' => 'Enter a valid full name.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        online_json(['status' => 'error', 'message' => 'Please enter a valid email address.'], 400);
    }
    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        online_json(['status' => 'error', 'message' => 'Enter a valid 11-digit Philippine mobile number starting with 09.'], 400);
    }
    if (strlen($password) < 6 || strlen($password) > 72) {
        online_json(['status' => 'error', 'message' => 'Password must be 6 to 72 characters long.'], 400);
    }
    if (strlen($address) > 500) {
        online_json(['status' => 'error', 'message' => 'Address must be 500 characters or fewer.'], 400);
    }

    $check = $conn->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
    $check->execute([$email, $email]);
    if ($check->fetch()) {
        online_json(['status' => 'error', 'message' => 'An account with this email address already exists. Please sign in.'], 409);
    }

    $roleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'Customer (Online)' LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    if (!$roleId) {
        online_json(['status' => 'error', 'message' => 'Online customer role is not configured. Run the Phase 4 database migration first.'], 500);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, phone, customer_address, password, role_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
    $stmt->execute([
        $fullName,
        $email,
        $email,
        $phone,
        $address !== '' ? $address : null,
        $passwordHash,
        $roleId
    ]);

    $userId = (int)$conn->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['customer_user_id'] = $userId;
    $_SESSION['customer_user'] = [
        'id' => $userId,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'customer_address' => $address
    ];

    online_json(['status' => 'success', 'message' => 'Registration successful.', 'user' => $_SESSION['customer_user']]);
}

if ($action === 'login') {
    $identifier = trim((string)($data['email'] ?? $data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($identifier === '' || $password === '') {
        online_json(['status' => 'error', 'message' => 'Enter your email, username, or phone number and password.'], 400);
    }

    $cleanPhone = preg_replace('/[^0-9]/', '', $identifier);

    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.password, u.status, r.role_name 
        FROM users u 
        JOIN roles r ON r.id = u.role_id 
        WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ? OR (? != '' AND u.phone = ?)) 
        LIMIT 1
    ");
    $stmt->execute([strtolower($identifier), strtolower($identifier), $cleanPhone, $cleanPhone]);
    $user = $stmt->fetch();

    if (!$user || $user['role_name'] !== 'Customer (Online)') {
        online_json(['status' => 'error', 'message' => 'Customer account not found. Please check your credentials or register.'], 404);
    }
    if ($user['status'] !== 'Active') {
        online_json(['status' => 'error', 'message' => 'This account is inactive. Please contact store staff.'], 403);
    }
    if (!password_verify($password, $user['password'])) {
        online_json(['status' => 'error', 'message' => 'Incorrect password. Please try again.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['customer_user_id'] = (int)$user['id'];
    $_SESSION['customer_user'] = [
        'id' => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'customer_address' => $user['customer_address']
    ];

    online_json(['status' => 'success', 'message' => 'Login successful.', 'user' => $_SESSION['customer_user']]);
}

online_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
