<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once __DIR__ . '/mail_helper.php';

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
    $loginInput = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($loginInput === '' || $password === '') {
        online_json(['status' => 'error', 'message' => 'Enter your email or phone number and password.'], 400);
    }

    $cleanPhone = preg_replace('/[^0-9]/', '', $loginInput);
    $isPhone = preg_match('/^09[0-9]{9}$/', $cleanPhone);

    if ($isPhone) {
        $stmt = $conn->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.password, u.status, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE (u.phone = ? OR u.username = ?) LIMIT 1");
        $stmt->execute([$cleanPhone, $cleanPhone]);
    } else {
        $stmt = $conn->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.password, u.status, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?) LIMIT 1");
        $stmt->execute([strtolower($loginInput), strtolower($loginInput)]);
    }
    $user = $stmt->fetch();

    if (!$user || $user['role_name'] !== 'Customer (Online)') {
        online_json(['status' => 'error', 'message' => 'Online customer account not found.'], 404);
    }
    if ($user['status'] !== 'Active') {
        online_json(['status' => 'error', 'message' => 'This account is inactive.'], 403);
    }
    if (!password_verify($password, $user['password'])) {
        online_json(['status' => 'error', 'message' => 'Invalid email or password.'], 401);
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

if ($action === 'otp_send') {
    // Accept either 'email' or 'phone' as the contact identifier
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
    $mode  = in_array($data['mode'] ?? '', ['signin', 'register']) ? $data['mode'] : 'signin';

    // Determine contact key and validate format
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            online_json(['ok' => false, 'message' => 'Please enter a valid email address.'], 400);
        }
        $contactKey   = 'email';
        $contactValue = $email;
        $channel      = 'email';
    } elseif ($phone !== '') {
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            online_json(['ok' => false, 'message' => 'Enter a valid 11-digit PH mobile number starting with 09.'], 400);
        }
        $contactKey   = 'phone';
        $contactValue = $phone;
        $channel      = 'sms';
    } else {
        online_json(['ok' => false, 'message' => 'Please provide an email address or mobile number.'], 400);
    }

    if ($channel === 'sms') {
        online_json(['ok' => false, 'message' => 'SMS verification is not configured yet. Please use email verification.'], 501);
    }

    $recentStmt = $conn->prepare('SELECT last_sent_at FROM otp_verifications WHERE email = ? LIMIT 1');
    $recentStmt->execute([$contactValue]);
    $recentSent = $recentStmt->fetchColumn();
    if ($recentSent && strtotime($recentSent) > time() - 60) {
        $wait = max(1, 60 - (time() - strtotime($recentSent)));
        online_json(['ok' => false, 'message' => "Please wait {$wait} seconds before requesting another code."], 429);
    }

    // Generate 6-digit OTP and expiry (5 minutes)
    $code    = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 300);

    // Store the code server-side. Never include it in the API response.
    $stmt = $conn->prepare("
        INSERT INTO otp_verifications (email, code, expires_at, attempts, last_sent_at)
        VALUES (?, ?, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE code = VALUES(code), expires_at = VALUES(expires_at), attempts = 0, last_sent_at = NOW()
    ");
    $stmt->execute([$contactValue, $code, $expires]);

    $emailResult = send_app_email(
        $contactValue,
        "Your Kenji's Kitchen verification code",
        "Your Kenji's Kitchen verification code is {$code}. It expires in 5 minutes. Do not share this code.",
        '<div style="font-family:Arial,sans-serif;max-width:480px;margin:24px auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px;color:#252b33">' .
        '<h2 style="margin-top:0">Kenji\'s Kitchen</h2><p>Your verification code is:</p>' .
        '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#9a5b00">' . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' .
        '<p>This code expires in 5 minutes. Do not share it with anyone.</p></div>'
    );
    if (!$emailResult['ok']) {
        $conn->prepare('DELETE FROM otp_verifications WHERE email = ?')->execute([$contactValue]);
        error_log('Kenji Kitchen OTP email could not be delivered.');
        online_json(['ok' => false, 'message' => $emailResult['message'] ?? 'We could not send the verification email. Please try again in a moment.'], 503);
    }

    online_json([
        'ok'       => true,
        'message'  => 'Verification code sent to your email inbox.',
        'channel'  => $channel
    ]);
}

if ($action === 'otp_verify') {
    // Accept either email or phone as contact identifier
    $email    = strtolower(trim((string)($data['email'] ?? '')));
    $phone    = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
    $code     = trim((string)($data['code'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ''));
    $mode     = in_array($data['mode'] ?? '', ['signin', 'register']) ? $data['mode'] : 'signin';

    if ($email !== '') {
        $contactValue = $email;
    } elseif ($phone !== '') {
        $contactValue = $phone;
    } else {
        online_json(['ok' => false, 'message' => 'Contact identifier required.'], 400);
    }

    if (empty($code)) {
        online_json(['ok' => false, 'message' => 'Verification code is required.'], 400);
    }

    // Look up OTP record
    $stmt = $conn->prepare("SELECT * FROM otp_verifications WHERE email = ? LIMIT 1");
    $stmt->execute([$contactValue]);
    $otpRecord = $stmt->fetch();

    if (!$otpRecord || $otpRecord['code'] !== $code) {
        if ($otpRecord) {
            $tries = (int)$otpRecord['attempts'] + 1;
            if ($tries >= 5) {
                $conn->prepare('DELETE FROM otp_verifications WHERE email = ?')->execute([$contactValue]);
                online_json(['ok' => false, 'message' => 'Too many incorrect codes. Request a new verification code.'], 429);
            }
            $conn->prepare('UPDATE otp_verifications SET attempts = ? WHERE email = ?')->execute([$tries, $contactValue]);
        }
        online_json(['ok' => false, 'message' => 'Invalid verification code. Please try again.'], 400);
    }

    if (strtotime($otpRecord['expires_at']) < time()) {
        online_json(['ok' => false, 'message' => 'Verification code has expired. Please request a new one.'], 400);
    }

    // Clean up used OTP
    $del = $conn->prepare("DELETE FROM otp_verifications WHERE email = ?");
    $del->execute([$contactValue]);

    // Resolve customer role
    $roleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'Customer (Online)' LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    // Look up existing user (by email or phone)
    if ($email !== '') {
        $userStmt = $conn->prepare("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1");
        $userStmt->execute([$email]);
    } else {
        $userStmt = $conn->prepare("SELECT * FROM users WHERE phone = ? LIMIT 1");
        $userStmt->execute([$phone]);
    }
    $user = $userStmt->fetch();

    $password = (string)($data['password'] ?? '');

    if (!$user) {
        // Auto-create account with chosen password or random password
        $defaultName = $fullName !== '' ? $fullName : ($email !== '' ? explode('@', $email)[0] : $phone);
        $userPassHash = ($password !== '' && strlen($password) >= 6)
            ? password_hash($password, PASSWORD_DEFAULT)
            : password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

        $ins = $conn->prepare("
            INSERT INTO users (full_name, username, email, phone, role_id, password, status, registration_source, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Active', 'ONLINE', NOW())
        ");
        $ins->execute([
            $defaultName,
            $email !== '' ? $email : $phone,
            $email !== '' ? $email : null,
            $phone !== '' ? $phone : null,
            $roleId,
            $userPassHash
        ]);
        $userId = (int)$conn->lastInsertId();
    } else {
        $userId      = (int)$user['id'];
        $defaultName = $user['full_name'];
        // If user exists but full_name was supplied (re-register), update it
        if ($fullName !== '' && $mode === 'register') {
            $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$fullName, $userId]);
            $defaultName = $fullName;
        }
    }

    session_regenerate_id(true);
    $_SESSION['customer_user_id'] = $userId;
    $_SESSION['customer_user']    = [
        'id'               => $userId,
        'full_name'        => $defaultName,
        'email'            => $email !== '' ? $email : ($user['email'] ?? ''),
        'phone'            => $phone !== '' ? $phone : ($user['phone'] ?? ''),
        'customer_address' => $user['customer_address'] ?? ''
    ];

    online_json([
        'ok'      => true,
        'message' => 'Verified successfully.',
        'user'    => $_SESSION['customer_user']
    ]);
}

online_json(['status' => 'error', 'message' => 'Invalid action.'], 400);

