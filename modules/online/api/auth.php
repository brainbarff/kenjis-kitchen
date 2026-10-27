<?php
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 3) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once __DIR__ . '/mail_helper.php';

function online_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function is_active_online_customer(?array $user): bool
{
    if (!$user) {
        return false;
    }
    if (($user['role_name'] ?? '') !== 'Customer (Online)') {
        return false;
    }
    if (($user['status'] ?? '') !== 'Active') {
        return false;
    }
    if (($user['customer_status'] ?? 'Active') !== 'Active') {
        return false;
    }
    if (!empty($user['deleted_at'])) {
        return false;
    }
    if (empty($user['email_verified_at'])) {
        return false;
    }
    return true;
}

$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
    online_json(['status' => 'success', 'message' => 'Logged out.']);
}

if ($action === 'me') {
    $id = (int)($_SESSION['customer_user_id'] ?? 0);
    if ($id <= 0) {
        online_json(['status' => 'success', 'authenticated' => false]);
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.customer_address,
               u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!is_active_online_customer($user)) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        online_json(['status' => 'success', 'authenticated' => false]);
    }

    unset($user['status'], $user['customer_status'], $user['email_verified_at'], $user['deleted_at'], $user['role_name']);
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
    online_json([
        'status'  => 'error',
        'ok'      => false,
        'message' => 'Email OTP verification is required to register an account. Please request a verification code first.'
    ], 403);
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
        $stmt = $conn->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.password,
                   u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE (u.phone = ? OR u.username = ?)
            ORDER BY (r.role_name = 'Customer (Online)') DESC
            LIMIT 1
        ");
        $stmt->execute([$cleanPhone, $cleanPhone]);
    } else {
        $stmt = $conn->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.password,
                   u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?)
            ORDER BY (r.role_name = 'Customer (Online)') DESC
            LIMIT 1
        ");
        $stmt->execute([strtolower($loginInput), strtolower($loginInput)]);
    }
    $user = $stmt->fetch();

    if (!$user || $user['role_name'] !== 'Customer (Online)') {
        online_json(['status' => 'error', 'message' => 'Online customer account not found.'], 404);
    }
    if (!empty($user['deleted_at']) || ($user['status'] ?? '') !== 'Active' || ($user['customer_status'] ?? '') !== 'Active') {
        online_json(['status' => 'error', 'message' => 'This account is inactive.'], 403);
    }
    if (!password_verify($password, (string)$user['password'])) {
        online_json(['status' => 'error', 'message' => 'Invalid email or password.'], 401);
    }
    if (empty($user['email_verified_at'])) {
        online_json([
            'status'  => 'unverified',
            'ok'      => false,
            'message' => 'Your account is not verified yet. Please enter the verification code sent to your email.'
        ], 403);
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
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
    $rawMode = $data['mode'] ?? 'signin';
    if (!is_string($rawMode) || !in_array($rawMode, ['signin', 'register'], true)) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Invalid verification mode.'], 400);
    }
    $mode = $rawMode;

    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Please enter a valid email address.'], 400);
        }
        $contactValue = $email;
        $channel      = 'email';
    } elseif ($phone !== '') {
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Enter a valid 11-digit PH mobile number starting with 09.'], 400);
        }
        $contactValue = $phone;
        $channel      = 'sms';
    } else {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Please provide an email address or mobile number.'], 400);
    }

    if ($channel === 'sms') {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'SMS verification is not configured yet. Please use email verification.'], 501);
    }

    $pendingFullName = null;
    $pendingPhone    = null;
    $pendingAddress  = null;
    $pendingPassHash = null;

    if ($mode === 'register') {
        $fullName = trim((string)($data['full_name'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $regAddress = trim(strip_tags((string)($data['address'] ?? '')));

        if (!preg_match('/^[\p{L}\s\.\'\-]{2,100}$/u', $fullName)) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Please enter a valid full name (2 to 100 characters).'], 400);
        }
        if (strlen($password) < 6 || strlen($password) > 72) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Password must be 6 to 72 characters long.'], 400);
        }
        if ($phone !== '' && !preg_match('/^09[0-9]{9}$/', $phone)) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Enter a valid 11-digit PH mobile number starting with 09.'], 400);
        }
        if (mb_strlen($regAddress) > 500) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Address must be 500 characters or fewer.'], 400);
        }

        // Check across all users regardless of status or role so registration cannot collide with or overwrite any existing account
        $check = $conn->prepare("
            SELECT u.id, u.email_verified_at, r.role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE LOWER(u.email) = ? OR LOWER(u.username) = ?
            LIMIT 1
        ");
        $check->execute([$contactValue, $contactValue]);
        $existing = $check->fetch();
        if ($existing) {
            if ($existing['role_name'] !== 'Customer (Online)' || !empty($existing['email_verified_at'])) {
                online_json(['ok' => false, 'status' => 'error', 'message' => 'An account with this email address already exists. Please switch to Sign In.'], 409);
            }
        }

        $roleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'Customer (Online)' LIMIT 1");
        $roleStmt->execute();
        if (!$roleStmt->fetchColumn()) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Online customer role is not configured.'], 500);
        }

        $pendingFullName = $fullName;
        $pendingPhone    = $phone !== '' ? $phone : null;
        $pendingAddress  = $regAddress !== '' ? $regAddress : null;
        $pendingPassHash = password_hash($password, PASSWORD_DEFAULT);
    } else {
        // mode === 'signin': require an existing active Customer (Online) account
        $userStmt = $conn->prepare("
            SELECT u.id, u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?)
            ORDER BY (r.role_name = 'Customer (Online)') DESC
            LIMIT 1
        ");
        $userStmt->execute([$contactValue, $contactValue]);
        $existingUser = $userStmt->fetch();

        if (!$existingUser || $existingUser['role_name'] !== 'Customer (Online)') {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Online customer account not found.'], 404);
        }
        if (!is_active_online_customer($existingUser)) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'This account is inactive.'], 403);
        }
    }

    $recentStmt = $conn->prepare('SELECT last_sent_at FROM otp_verifications WHERE email = ? LIMIT 1');
    $recentStmt->execute([$contactValue]);
    $recentSent = $recentStmt->fetchColumn();
    if ($recentSent && strtotime((string)$recentSent) > time() - 60) {
        $wait = max(1, 60 - (time() - strtotime((string)$recentSent)));
        online_json(['ok' => false, 'status' => 'error', 'message' => "Please wait {$wait} seconds before requesting another code."], 429);
    }

    // Generate 6-digit OTP, hash it before storage, and set 5-minute expiry
    $code     = (string)random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expires  = date('Y-m-d H:i:s', time() + 300);

    $stmt = $conn->prepare("
        INSERT INTO otp_verifications (
            email, code, purpose, pending_full_name, pending_phone, pending_address, pending_password_hash, expires_at, attempts, last_sent_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE
            code = VALUES(code),
            purpose = VALUES(purpose),
            pending_full_name = VALUES(pending_full_name),
            pending_phone = VALUES(pending_phone),
            pending_address = VALUES(pending_address),
            pending_password_hash = VALUES(pending_password_hash),
            expires_at = VALUES(expires_at),
            attempts = 0,
            last_sent_at = NOW()
    ");
    $stmt->execute([
        $contactValue,
        $codeHash,
        $mode,
        $pendingFullName,
        $pendingPhone,
        $pendingAddress,
        $pendingPassHash,
        $expires
    ]);

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
        error_log('Kenji Kitchen OTP email could not be delivered: ' . ($emailResult['message'] ?? 'Unknown error'));
        online_json(['ok' => false, 'status' => 'error', 'message' => $emailResult['message'] ?? 'We could not send the verification email. Please try again in a moment.'], 503);
    }

    online_json([
        'ok'      => true,
        'status'  => 'success',
        'message' => 'Verification code sent to your email inbox.',
        'channel' => $channel
    ]);
}

if ($action === 'otp_verify') {
    $email   = strtolower(trim((string)($data['email'] ?? '')));
    $phone   = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
    $code    = trim((string)($data['code'] ?? ''));
    $rawMode = $data['mode'] ?? 'signin';
    if (!is_string($rawMode) || !in_array($rawMode, ['signin', 'register'], true)) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Invalid verification mode.'], 400);
    }
    $mode = $rawMode;

    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Please enter a valid email address.'], 400);
        }
        $contactValue = $email;
    } elseif ($phone !== '') {
        $contactValue = $phone;
    } else {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Contact identifier required.'], 400);
    }

    if (!preg_match('/^[0-9]{6}$/', $code)) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Enter a valid 6-digit verification code.'], 400);
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare('SELECT * FROM otp_verifications WHERE email = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$contactValue]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord) {
            $conn->rollBack();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Invalid verification code. Please request a new one.'], 400);
        }

        $otpId = (int)$otpRecord['id'];

        // 1. Check expiration before verifying
        if (strtotime((string)$otpRecord['expires_at']) < time()) {
            $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Verification code has expired. Please request a new one.'], 400);
        }

        // 2. Check attempt limit before verifying
        if ((int)$otpRecord['attempts'] >= 5) {
            $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Too many incorrect codes. Request a new verification code.'], 429);
        }

        // 3. Enforce OTP purpose match
        if (($otpRecord['purpose'] ?? '') !== $mode) {
            $tries = (int)$otpRecord['attempts'] + 1;
            if ($tries >= 5) {
                $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                $conn->commit();
                online_json(['ok' => false, 'status' => 'error', 'message' => 'Too many incorrect codes. Request a new verification code.'], 429);
            }
            $conn->prepare('UPDATE otp_verifications SET attempts = ? WHERE id = ?')->execute([$tries, $otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Verification code does not match this action. Please request a new code.'], 400);
        }

        // 4. Verify hashed OTP code (reject any non-hashed value as defense-in-depth)
        $storedHash = (string)($otpRecord['code'] ?? '');
        $hashInfo   = password_get_info($storedHash);
        $isHashed   = !empty($hashInfo['algo']);
        $codeValid  = $isHashed && password_verify($code, $storedHash);

        if (!$codeValid) {
            $tries = (int)$otpRecord['attempts'] + 1;
            if ($tries >= 5) {
                $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                $conn->commit();
                online_json(['ok' => false, 'status' => 'error', 'message' => 'Too many incorrect codes. Request a new verification code.'], 429);
            }
            $conn->prepare('UPDATE otp_verifications SET attempts = ? WHERE id = ?')->execute([$tries, $otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Invalid verification code. Please try again.'], 400);
        }

        if ($mode === 'register') {
            // Use ONLY the validated pending registration fields stored with the OTP record
            $regFullName = trim((string)($otpRecord['pending_full_name'] ?? ''));
            $regPassHash = (string)($otpRecord['pending_password_hash'] ?? '');
            $regPhone    = $otpRecord['pending_phone'] ?? null;
            $regAddress  = $otpRecord['pending_address'] ?? null;
            $passInfo    = password_get_info($regPassHash);

            if ($regFullName === '' || $regPassHash === '' || empty($passInfo['algo'])) {
                $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                $conn->commit();
                online_json(['ok' => false, 'status' => 'error', 'message' => 'Registration session is incomplete. Please request a new verification code.'], 400);
            }

            // Lock and verify no account already owns this email or username
            $existingStmt = $conn->prepare("
                SELECT u.id, u.email_verified_at, r.role_name
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE LOWER(u.email) = ? OR LOWER(u.username) = ?
                LIMIT 1
                FOR UPDATE
            ");
            $existingStmt->execute([$contactValue, $contactValue]);
            $existingUser = $existingStmt->fetch();

            if ($existingUser) {
                // Guard: Never touch a staff account!
                if ($existingUser['role_name'] !== 'Customer (Online)') {
                    $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                    $conn->commit();
                    online_json(['ok' => false, 'status' => 'error', 'message' => 'An account with this email address already exists. Please sign in.'], 409);
                }
                // Guard: If already verified, do not overwrite password!
                if (!empty($existingUser['email_verified_at'])) {
                    $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                    $conn->commit();
                    online_json(['ok' => false, 'status' => 'error', 'message' => 'An account with this email address is already verified. Please sign in.'], 409);
                }

                // Unverified customer: activate and mark verified
                $upd = $conn->prepare("
                    UPDATE users SET
                        full_name = ?,
                        phone = COALESCE(?, phone),
                        customer_address = COALESCE(?, customer_address),
                        password = ?,
                        status = 'Active',
                        customer_status = 'Active',
                        email_verified_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([
                    $regFullName,
                    $regPhone,
                    $regAddress,
                    $regPassHash,
                    $existingUser['id']
                ]);
                $userId = (int)$existingUser['id'];
            } else {
                $roleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'Customer (Online)' LIMIT 1");
                $roleStmt->execute();
                $roleId = $roleStmt->fetchColumn();
                if (!$roleId) {
                    $conn->rollBack();
                    online_json(['ok' => false, 'status' => 'error', 'message' => 'Online customer role is not configured.'], 500);
                }

                $ins = $conn->prepare("
                    INSERT INTO users (
                        full_name, username, email, phone, customer_address, role_id, password, status, customer_status, email_verified_at, registration_source, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', 'Active', NOW(), 'ONLINE', NOW())
                ");
                $ins->execute([
                    $regFullName,
                    $contactValue,
                    $email !== '' ? $email : null,
                    $regPhone,
                    $regAddress,
                    $roleId,
                    $regPassHash
                ]);
                $userId = (int)$conn->lastInsertId();
            }

            $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
            $conn->commit();

            $sessionUser = [
                'id'               => $userId,
                'full_name'        => $regFullName,
                'email'            => $email !== '' ? $email : '',
                'phone'            => $regPhone ?? '',
                'customer_address' => $regAddress ?? ''
            ];
        } else {
            // mode === 'signin': require an existing active Customer (Online) account; never create or overwrite
            $userStmt = $conn->prepare("
                SELECT u.id, u.full_name, u.email, u.phone, u.customer_address,
                       u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?)
                ORDER BY (r.role_name = 'Customer (Online)') DESC
                LIMIT 1
                FOR UPDATE
            ");
            $userStmt->execute([$contactValue, $contactValue]);
            $user = $userStmt->fetch();

            if (!$user || $user['role_name'] !== 'Customer (Online)') {
                $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                $conn->commit();
                online_json(['ok' => false, 'status' => 'error', 'message' => 'Online customer account not found.'], 404);
            }
            if (!is_active_online_customer($user)) {
                $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                $conn->commit();
                online_json(['ok' => false, 'status' => 'error', 'message' => 'This account is inactive.'], 403);
            }

            $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
            $conn->commit();

            $userId = (int)$user['id'];
            $sessionUser = [
                'id'               => $userId,
                'full_name'        => (string)$user['full_name'],
                'email'            => (string)($user['email'] ?? ''),
                'phone'            => (string)($user['phone'] ?? ''),
                'customer_address' => (string)($user['customer_address'] ?? '')
            ];
        }

        session_regenerate_id(true);
        $_SESSION['customer_user_id'] = $userId;
        $_SESSION['customer_user']    = $sessionUser;

        online_json([
            'ok'      => true,
            'status'  => 'success',
            'message' => 'Verified successfully.',
            'user'    => $_SESSION['customer_user']
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Online auth otp_verify error: ' . $e->getMessage());
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Unable to complete verification right now. Please try again.'], 500);
    }
}

online_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
