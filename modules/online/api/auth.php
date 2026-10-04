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

// 1. Logout
if ($action === 'logout') {
    unset($_SESSION['customer_user_id'], $_SESSION['customer_user'], $_SESSION['pending_registration']);
    online_json(['status' => 'success', 'message' => 'Logged out successfully.']);
}

// 2. Current Session Check
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
    online_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    online_json(['status' => 'error', 'message' => 'Invalid request data.'], 400);
}

// Helper: send verification email
function send_verification_email($email, $fullName, $code)
{
    $subject = "Kenji's Kitchen - Email Verification Code: " . $code;
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Kenji's Kitchen <no-reply@kenjis-kitchen.com>\r\n";
    $headers .= "Reply-To: no-reply@kenjis-kitchen.com\r\n";

    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin:0;padding:24px;background:#f9f9f9;font-family:Arial,Helvetica,sans-serif;'>
        <div style='max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-radius:16px;overflow:hidden;'>
            <div style='background:#181818;padding:24px;text-align:center;'>
                <h1 style='color:#F2C12E;margin:0;font-size:24px;letter-spacing:1px;'>Kenji's Kitchen</h1>
                <p style='color:#E2E2E2;margin:4px 0 0;font-size:13px;'>Online Ordering &bull; Greater Lagro, Quezon City</p>
            </div>
            <div style='padding:32px 28px;text-align:center;'>
                <h2 style='color:#232323;margin:0 0 10px;font-size:20px;'>Verify Your Email Address</h2>
                <p style='color:#555555;font-size:14px;line-height:1.5;margin:0 0 24px;'>
                    Hello <strong>" . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . "</strong>,<br>
                    Thank you for signing up for Kenji's Kitchen online ordering. Please use the verification code below to complete your registration:
                </p>
                <div style='background:#FFFDF5;border:2px dashed #F2C12E;border-radius:12px;padding:16px 24px;display:inline-block;margin:0 auto 24px;'>
                    <span style='font-size:36px;font-weight:800;letter-spacing:10px;color:#181818;font-family:monospace;'>" . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . "</span>
                </div>
                <p style='color:#777777;font-size:12.5px;line-height:1.4;margin:0;'>
                    This code is valid for <strong>10 minutes</strong>.<br>If you did not register for an account, please disregard this email.
                </p>
            </div>
            <div style='background:#f4f4f4;padding:16px;text-align:center;border-top:1px solid #eaeaea;'>
                <p style='color:#888888;font-size:12px;margin:0;'>
                    &copy; " . date('Y') . " Kenji's Kitchen. Blk 79 Lot 34 Florante at Laura St., Greater Lagro, QC.
                </p>
            </div>
        </div>
    </body>
    </html>";

    return @mail($email, $subject, $htmlBody, $headers);
}

// 3. Step 1: Send Email Verification Code
if ($action === 'send_verification_code') {
    $fullName = trim((string)($data['full_name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $address = trim(strip_tags((string)($data['address'] ?? '')));

    if (!preg_match('/^[\p{L}\s\.\'\-]{2,100}$/u', $fullName)) {
        online_json(['status' => 'error', 'message' => 'Please enter a valid full name (at least 2 letters).'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        online_json(['status' => 'error', 'message' => 'Please enter a valid email address.'], 400);
    }
    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        online_json(['status' => 'error', 'message' => 'Enter a valid 11-digit Philippine mobile number starting with 09.'], 400);
    }
    if (strlen($password) < 6 || strlen($password) > 72) {
        online_json(['status' => 'error', 'message' => 'Password must be between 6 and 72 characters long.'], 400);
    }
    if (strlen($address) > 500) {
        online_json(['status' => 'error', 'message' => 'Address must be 500 characters or fewer.'], 400);
    }

    // Check if email already exists
    $checkEmail = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1');
    $checkEmail->execute([$email, $email]);
    if ($checkEmail->fetch()) {
        online_json(['status' => 'error', 'message' => 'An account with this email address already exists. Please sign in.'], 409);
    }

    // Generate 6-digit code
    $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['pending_registration'] = [
        'full_name'     => $fullName,
        'email'         => $email,
        'phone'         => $phone,
        'address'       => $address !== '' ? $address : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'code'          => $code,
        'expires_at'    => time() + 600, // 10 minutes
        'sent_at'       => time()
    ];

    $mailSent = send_verification_email($email, $fullName, $code);

    online_json([
        'status'    => 'success',
        'message'   => 'A 6-digit verification code has been sent to ' . $email . '.',
        'email'     => $email,
        'demo_code' => $code // Displayed in local/dev toast fallback so testing on localhost without an active SMTP server is seamless
    ]);
}

// 4. Resend Verification Code
if ($action === 'resend_code') {
    $pending = $_SESSION['pending_registration'] ?? null;
    if (!$pending) {
        online_json(['status' => 'error', 'message' => 'No pending registration found. Please register again.'], 400);
    }

    $timeSinceLast = time() - ($pending['sent_at'] ?? 0);
    if ($timeSinceLast < 30) {
        $remaining = 30 - $timeSinceLast;
        online_json(['status' => 'error', 'message' => "Please wait {$remaining} seconds before requesting a new code."], 429);
    }

    $newCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['pending_registration']['code'] = $newCode;
    $_SESSION['pending_registration']['expires_at'] = time() + 600;
    $_SESSION['pending_registration']['sent_at'] = time();

    send_verification_email($pending['email'], $pending['full_name'], $newCode);

    online_json([
        'status'    => 'success',
        'message'   => 'A new verification code has been sent to ' . $pending['email'] . '.',
        'email'     => $pending['email'],
        'demo_code' => $newCode
    ]);
}

// 5. Step 2: Verify Code and Complete Registration
if ($action === 'verify_code') {
    $pending = $_SESSION['pending_registration'] ?? null;
    if (!$pending) {
        online_json(['status' => 'error', 'message' => 'Registration session expired. Please enter your details again.'], 400);
    }

    $inputCode = trim((string)($data['code'] ?? ''));
    if ($inputCode === '' || strlen($inputCode) !== 6) {
        online_json(['status' => 'error', 'message' => 'Please enter the 6-digit verification code.'], 400);
    }

    if (time() > $pending['expires_at']) {
        online_json(['status' => 'error', 'message' => 'Verification code has expired. Please click "Resend Code".'], 400);
    }

    if ($inputCode !== (string)$pending['code']) {
        online_json(['status' => 'error', 'message' => 'Incorrect verification code. Please check your email and try again.'], 400);
    }

    // Role check
    $roleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'Customer (Online)' LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    if (!$roleId) {
        online_json(['status' => 'error', 'message' => 'Online customer role is not configured. Run the database migration first.'], 500);
    }

    // Check one more time before inserting
    $check = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1');
    $check->execute([$pending['email'], $pending['email']]);
    if ($check->fetch()) {
        unset($_SESSION['pending_registration']);
        online_json(['status' => 'error', 'message' => 'An account with this email already exists. Please sign in.'], 409);
    }

    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, phone, customer_address, password, role_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
    $stmt->execute([
        $pending['full_name'],
        $pending['email'],
        $pending['email'],
        $pending['phone'],
        $pending['address'],
        $pending['password_hash'],
        $roleId
    ]);

    $userId = (int)$conn->lastInsertId();
    unset($_SESSION['pending_registration']);

    session_regenerate_id(true);
    $_SESSION['customer_user_id'] = $userId;
    $_SESSION['customer_user'] = [
        'id'               => $userId,
        'full_name'        => $pending['full_name'],
        'email'            => $pending['email'],
        'phone'            => $pending['phone'],
        'customer_address' => $pending['address']
    ];

    online_json([
        'status'  => 'success',
        'message' => 'Email verified successfully! Welcome to Kenji\'s Kitchen, ' . $pending['full_name'] . '.',
        'user'    => $_SESSION['customer_user']
    ]);
}

// 6. Direct Register Fallback (Legacy support)
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

    $check = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1');
    $check->execute([$email, $email]);
    if ($check->fetch()) {
        online_json(['status' => 'error', 'message' => 'An account with this email address already exists. Please sign in.'], 409);
    }

    $roleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'Customer (Online)' LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    if (!$roleId) {
        online_json(['status' => 'error', 'message' => 'Online customer role is not configured.'], 500);
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
        'id'               => $userId,
        'full_name'        => $fullName,
        'email'            => $email,
        'phone'            => $phone,
        'customer_address' => $address
    ];

    online_json(['status' => 'success', 'message' => 'Registration successful.', 'user' => $_SESSION['customer_user']]);
}

// 7. Login
if ($action === 'login') {
    $identifier = trim((string)($data['email'] ?? $data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($identifier === '' || $password === '') {
        online_json(['status' => 'error', 'message' => 'Please enter your email, username, or phone number and password.'], 400);
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
        online_json(['status' => 'error', 'message' => 'This account is inactive. Please approach eatery staff.'], 403);
    }
    if (!password_verify($password, $user['password'])) {
        online_json(['status' => 'error', 'message' => 'Incorrect password. Please try again.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['customer_user_id'] = (int)$user['id'];
    $_SESSION['customer_user'] = [
        'id'               => (int)$user['id'],
        'full_name'        => $user['full_name'],
        'email'            => $user['email'],
        'phone'            => $user['phone'],
        'customer_address' => $user['customer_address']
    ];

    online_json(['status' => 'success', 'message' => 'Login successful.', 'user' => $_SESSION['customer_user']]);
}

online_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
