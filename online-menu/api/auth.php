<?php
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/rate_limiter.php';
require_once __DIR__ . '/mail_helper.php';
require_once dirname(__DIR__, 2) . '/includes/customer_code_email.php';

function online_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function is_active_online_customer($user): bool
{
    if (!is_array($user) || !$user) {
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
    if (!empty($user['is_archived'])) {
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
        SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.profile_photo,
               u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
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

    $user['profile_photo_url'] = kk_customer_avatar_url($user['profile_photo'] ?? null);
    unset($user['profile_photo'], $user['status'], $user['is_archived'], $user['customer_status'], $user['email_verified_at'], $user['deleted_at'], $user['role_name']);
    $_SESSION['customer_user'] = $user;
    online_json(['status' => 'success', 'authenticated' => true, 'user' => $user, 'csrf_token' => csrf_token()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    online_json(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

function kk_require_active_customer_record(PDO $conn): array
{
    $customerId = (int)($_SESSION['customer_user_id'] ?? 0);
    if ($customerId <= 0) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please sign in to manage your account.'], 401);
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.profile_photo,
               u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $user = $stmt->fetch();

    if (!is_active_online_customer($user)) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Your customer account is no longer active.'], 403);
    }

    return $user;
}

function kk_ensure_avatar_storage_dir(): string
{
    $avatarDir = ROOT_PATH . '/uploads/avatars';
    if (!is_dir($avatarDir) && !@mkdir($avatarDir, 0755, true) && !is_dir($avatarDir)) {
        throw new RuntimeException('Unable to create avatar storage directory.');
    }

    $htaccessPath = $avatarDir . '/.htaccess';
    if (!is_file($htaccessPath)) {
        $htaccessRules = implode("\n", [
            'Options -Indexes -ExecCGI',
            '',
            '<IfModule php_module>',
            '    php_flag engine off',
            '</IfModule>',
            '<IfModule php8_module>',
            '    php_flag engine off',
            '</IfModule>',
            '',
            '<FilesMatch "(?i)\\.(php[0-9]?|phtml|phps|phar|pl|py|cgi|asp|aspx|sh|bat|cmd|exe|dll|jsp|htaccess|htpasswd|ini|html?|xhtml|shtml|js|mjs|svg|xml|txt|json|css)$">',
            '    SetHandler none',
            '    <IfModule mod_authz_core.c>',
            '        Require all denied',
            '    </IfModule>',
            '    <IfModule !mod_authz_core.c>',
            '        Order allow,deny',
            '        Deny from all',
            '    </IfModule>',
            '</FilesMatch>',
            '',
        ]);
        @file_put_contents($htaccessPath, $htaccessRules);
    }

    return $avatarDir;
}

function kk_save_customer_avatar_upload(array $file): string
{
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Profile picture must be 5 MB or smaller.'], 400);
    }
    if ($uploadError !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please choose a valid image file.'], 400);
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Profile picture must be 5 MB or smaller.'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string)$finfo->file($file['tmp_name']);
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($mimeToExt[$realMime])) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Only JPG, PNG, and WebP images are allowed.'], 400);
    }

    $imgInfo = @getimagesize($file['tmp_name']);
    if (
        !$imgInfo
        || empty($imgInfo[0])
        || empty($imgInfo[1])
        || (int)$imgInfo[0] > 8000
        || (int)$imgInfo[1] > 8000
        || image_type_to_mime_type((int)$imgInfo[2]) !== $realMime
    ) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please upload a valid JPG, PNG, or WebP image.'], 400);
    }

    $avatarDir = kk_ensure_avatar_storage_dir();
    $ext = $mimeToExt[$realMime];
    $newFilename = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $avatarDir . '/' . $newFilename;

    if (extension_loaded('gd')) {
        $srcWidth = (int)$imgInfo[0];
        $srcHeight = (int)$imgInfo[1];
        $sourceImage = null;
        if ($realMime === 'image/jpeg') {
            $sourceImage = @imagecreatefromjpeg($file['tmp_name']);
        } elseif ($realMime === 'image/png') {
            $sourceImage = @imagecreatefrompng($file['tmp_name']);
        } elseif ($realMime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $sourceImage = @imagecreatefromwebp($file['tmp_name']);
        }

        if ($sourceImage) {
            $maxDim = 512;
            $targetImage = $sourceImage;
            if ($srcWidth > $maxDim || $srcHeight > $maxDim) {
                $scale = min($maxDim / $srcWidth, $maxDim / $srcHeight);
                $dstWidth = max(1, (int)round($srcWidth * $scale));
                $dstHeight = max(1, (int)round($srcHeight * $scale));
                $resized = imagecreatetruecolor($dstWidth, $dstHeight);
                if ($realMime === 'image/png' || $realMime === 'image/webp') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                    imagefilledrectangle($resized, 0, 0, $dstWidth, $dstHeight, $transparent);
                }
                imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);
                imagedestroy($sourceImage);
                $targetImage = $resized;
            }

            $saved = false;
            if ($realMime === 'image/jpeg') {
                $saved = @imagejpeg($targetImage, $destination, 86);
            } elseif ($realMime === 'image/png') {
                $saved = @imagepng($targetImage, $destination, 6);
            } elseif ($realMime === 'image/webp' && function_exists('imagewebp')) {
                $saved = @imagewebp($targetImage, $destination, 86);
            }
            imagedestroy($targetImage);

            if ($saved && is_file($destination)) {
                return $newFilename;
            }
        }
    }

    if (!@move_uploaded_file($file['tmp_name'], $destination)) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Could not save your profile picture. Please try again.'], 500);
    }

    return $newFilename;
}

if ($action === 'upload_avatar') {
    if (!verify_csrf_token()) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Invalid security token. Please reload the page and try again.'], 403);
    }

    $user = kk_require_active_customer_record($conn);
    $customerId = (int)$user['id'];
    $oldPhoto = $user['profile_photo'] ?? null;

    if (empty($_FILES['avatar'])) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please choose an image file to upload.'], 400);
    }

    try {
        $newFilename = kk_save_customer_avatar_upload($_FILES['avatar']);
        $upd = $conn->prepare('UPDATE users SET profile_photo = ? WHERE id = ?');
        $upd->execute([$newFilename, $customerId]);
    } catch (Throwable $e) {
        if (!empty($newFilename)) {
            kk_delete_customer_avatar_file($newFilename);
        }
        error_log('Avatar upload failed: ' . $e->getMessage());
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Unable to save profile picture right now.'], 500);
    }

    if ($oldPhoto && $oldPhoto !== $newFilename) {
        kk_delete_customer_avatar_file($oldPhoto);
    }

    $avatarUrl = kk_customer_avatar_url($newFilename);
    $sessionUser = [
        'id' => $customerId,
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'customer_address' => $user['customer_address'],
        'profile_photo_url' => $avatarUrl,
    ];
    $_SESSION['customer_user'] = $sessionUser;

    online_json([
        'status' => 'success',
        'ok' => true,
        'message' => 'Profile picture updated.',
        'profile_photo_url' => $avatarUrl,
        'user' => $sessionUser,
    ]);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    online_json(['status' => 'error', 'message' => 'Invalid request data.'], 400);
}

if ($action === 'remove_avatar') {
    if (!verify_csrf_token($data['csrf_token'] ?? null)) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Invalid security token. Please reload the page and try again.'], 403);
    }

    $user = kk_require_active_customer_record($conn);
    $customerId = (int)$user['id'];
    $oldPhoto = $user['profile_photo'] ?? null;

    $conn->prepare('UPDATE users SET profile_photo = NULL WHERE id = ?')->execute([$customerId]);
    kk_delete_customer_avatar_file($oldPhoto);

    $sessionUser = [
        'id' => $customerId,
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'customer_address' => $user['customer_address'],
        'profile_photo_url' => null,
    ];
    $_SESSION['customer_user'] = $sessionUser;

    online_json([
        'status' => 'success',
        'ok' => true,
        'message' => 'Profile picture removed.',
        'profile_photo_url' => null,
        'user' => $sessionUser,
    ]);
}

if ($action === 'deactivate_account') {
    $customerId = (int)($_SESSION['customer_user_id'] ?? 0);
    if ($customerId <= 0) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please sign in to manage your account.'], 401);
    }

    if (!verify_csrf_token($data['csrf_token'] ?? null)) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Invalid security token. Please reload the page and try again.'], 403);
    }

    if (empty($data['confirm']) || $data['confirm'] !== true) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please confirm that you understand and want to deactivate your account.'], 400);
    }

    $password = (string)($data['password'] ?? '');
    if ($password === '') {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please enter your current password to confirm account deactivation.'], 400);
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.password, u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $user = $stmt->fetch();

    if (!is_active_online_customer($user)) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Your customer account is no longer active.'], 403);
    }

    if (!password_verify($password, (string)$user['password'])) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Incorrect current password. Account deactivation was not performed.'], 401);
    }

    $updateStmt = $conn->prepare("UPDATE users SET status = 'Inactive', customer_status = 'Inactive' WHERE id = ?");
    $updateStmt->execute([$customerId]);

    unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
    session_regenerate_id(true);

    online_json([
        'status' => 'success',
        'ok' => true,
        'message' => 'Your account has been deactivated and your session has been signed out.'
    ]);
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

    if ($loginInput === '' || !filter_var($loginInput, FILTER_VALIDATE_EMAIL)) {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please enter a valid email address.'], 400);
    }
    if ($password === '') {
        online_json(['status' => 'error', 'ok' => false, 'message' => 'Please enter your password.'], 400);
    }

    $rateKey = 'customer:' . $loginInput;
    $rateLimit = kk_check_login_rate_limit($rateKey);
    if ($rateLimit['blocked']) {
        online_json([
            'status'      => 'error',
            'ok'          => false,
            'message'     => $rateLimit['message'],
            'retry_after' => $rateLimit['retry_after']
        ], 429);
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.profile_photo, u.password,
               u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?)
        ORDER BY (r.role_name = 'Customer (Online)') DESC
        LIMIT 1
    ");
    $stmt->execute([strtolower($loginInput), strtolower($loginInput)]);
    $user = $stmt->fetch();

    if (!$user || $user['role_name'] !== 'Customer (Online)') {
        $fail = kk_record_login_failure($rateKey);
        if ($fail['blocked']) {
            online_json(['status' => 'error', 'ok' => false, 'message' => $fail['message'], 'retry_after' => $fail['retry_after']], 429);
        }
        online_json(['status' => 'error', 'message' => 'Online customer account not found.'], 404);
    }
    if (!empty($user['deleted_at']) || !empty($user['is_archived']) || ($user['status'] ?? '') !== 'Active' || ($user['customer_status'] ?? 'Active') !== 'Active') {
        online_json(['status' => 'error', 'message' => 'This account is inactive.'], 403);
    }
    if (!password_verify($password, (string)$user['password'])) {
        $fail = kk_record_login_failure($rateKey);
        if ($fail['blocked']) {
            online_json(['status' => 'error', 'ok' => false, 'message' => $fail['message'], 'retry_after' => $fail['retry_after']], 429);
        }
        online_json(['status' => 'error', 'message' => 'Invalid email or password.'], 401);
    }
    if (empty($user['email_verified_at'])) {
        online_json([
            'status'  => 'unverified',
            'ok'      => false,
            'message' => 'Your account is not verified yet. Please enter the verification code sent to your email.'
        ], 403);
    }

    kk_clear_login_rate_limit($rateKey);
    session_regenerate_id(true);
    $_SESSION['customer_user_id'] = (int)$user['id'];
    $_SESSION['customer_user'] = [
        'id' => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'customer_address' => $user['customer_address'],
        'profile_photo_url' => kk_customer_avatar_url($user['profile_photo'] ?? null),
    ];
    try {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
        $conn->prepare("UPDATE users SET last_login_at = NOW(), last_ip = ? WHERE id = ?")->execute([$ip, (int)$user['id']]);
    } catch (Throwable $e) {}

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
            SELECT u.id, u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
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
        customer_code_email($code, 'registration')
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
                'id'                => $userId,
                'full_name'         => $regFullName,
                'email'             => $email !== '' ? $email : '',
                'phone'             => $regPhone ?? '',
                'customer_address'  => $regAddress ?? '',
                'profile_photo_url' => null,
            ];
        } else {
            // mode === 'signin': require an existing active Customer (Online) account; never create or overwrite
            $userStmt = $conn->prepare("
                SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.profile_photo,
                       u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
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
                'id'                => $userId,
                'full_name'         => (string)$user['full_name'],
                'email'             => (string)($user['email'] ?? ''),
                'phone'             => (string)($user['phone'] ?? ''),
                'customer_address'  => (string)($user['customer_address'] ?? ''),
                'profile_photo_url' => kk_customer_avatar_url($user['profile_photo'] ?? null),
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

if ($action === 'forgot_password_send') {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Please enter a valid email address.'], 400);
    }

    // Check rate limit on otp_verifications for this email
    $recentStmt = $conn->prepare('SELECT last_sent_at FROM otp_verifications WHERE email = ? LIMIT 1');
    $recentStmt->execute([$email]);
    $recentSent = $recentStmt->fetchColumn();
    if ($recentSent && strtotime((string)$recentSent) > time() - 60) {
        $wait = max(1, 60 - (time() - strtotime((string)$recentSent)));
        online_json(['ok' => false, 'status' => 'error', 'message' => "Please wait {$wait} seconds before requesting another reset code."], 429);
    }

    $userStmt = $conn->prepare("
        SELECT u.id, u.full_name, u.email, u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?)
        ORDER BY (r.role_name = 'Customer (Online)') DESC
        LIMIT 1
    ");
    $userStmt->execute([$email, $email]);
    $user = $userStmt->fetch();

    if (!$user) {
        online_json([
            'ok'      => false,
            'status'  => 'error',
            'message' => 'No customer account is registered with that email address. Please register an account first.'
        ], 404);
    }

    if (($user['role_name'] ?? '') !== 'Customer (Online)') {
        online_json([
            'ok'      => false,
            'status'  => 'error',
            'message' => 'Staff accounts cannot reset passwords here. Please contact an administrator.'
        ], 403);
    }

    if (!is_active_online_customer($user)) {
        online_json([
            'ok'      => false,
            'status'  => 'error',
            'message' => 'This customer account is inactive or not yet email-verified. Please complete registration or contact support.'
        ], 403);
    }

    $code     = (string)random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expires  = date('Y-m-d H:i:s', time() + 300);

    $stmt = $conn->prepare("
        INSERT INTO otp_verifications (
            email, code, purpose, pending_full_name, pending_phone, pending_address, pending_password_hash, expires_at, attempts, last_sent_at
        ) VALUES (?, ?, 'password_reset', NULL, NULL, NULL, NULL, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE
            code = VALUES(code),
            purpose = 'password_reset',
            pending_full_name = NULL,
            pending_phone = NULL,
            pending_address = NULL,
            pending_password_hash = NULL,
            expires_at = VALUES(expires_at),
            attempts = 0,
            last_sent_at = NOW()
    ");
    $stmt->execute([$email, $codeHash, $expires]);

    $emailResult = send_app_email(
        $email,
        "Kenji's Kitchen — Password Reset Code",
        "Your Kenji's Kitchen password reset verification code is {$code}. It expires in 5 minutes. If you did not request a password reset, please ignore this email.",
        customer_code_email($code, 'password_reset')
    );

    if (!$emailResult['ok']) {
        $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'password_reset'")->execute([$email]);
        error_log('Kenji Kitchen password reset email could not be delivered: ' . ($emailResult['message'] ?? 'Unknown error'));
        online_json(['ok' => false, 'status' => 'error', 'message' => 'We could not send the verification email right now. Please try again in a moment.'], 503);
    }

    online_json([
        'ok'      => true,
        'status'  => 'success',
        'message' => 'A 6-digit password reset code has been sent to your registered email address.'
    ]);
}

if ($action === 'forgot_password_reset') {
    $email           = strtolower(trim((string)($data['email'] ?? '')));
    $code            = trim((string)($data['code'] ?? ''));
    $newPassword     = (string)($data['new_password'] ?? '');
    $confirmPassword = (string)($data['confirm_password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Please enter a valid email address.'], 400);
    }
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Please enter the 6-digit verification code sent to your email.'], 400);
    }
    if (strlen($newPassword) < 6 || strlen($newPassword) > 72) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'New password must be between 6 and 72 characters long.'], 400);
    }
    if ($newPassword !== $confirmPassword) {
        online_json(['ok' => false, 'status' => 'error', 'message' => 'New password and confirm password do not match.'], 400);
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare('SELECT * FROM otp_verifications WHERE email = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$email]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord || ($otpRecord['purpose'] ?? '') !== 'password_reset') {
            $conn->rollBack();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Invalid or expired reset code. Please request a new code.'], 400);
        }

        $otpId = (int)$otpRecord['id'];

        if (strtotime((string)$otpRecord['expires_at']) < time()) {
            $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Your reset code has expired. Please request a new code.'], 400);
        }

        if ((int)$otpRecord['attempts'] >= 5) {
            $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Too many incorrect attempts. Please request a new reset code.'], 429);
        }

        $storedHash = (string)($otpRecord['code'] ?? '');
        $hashInfo   = password_get_info($storedHash);
        $isHashed   = !empty($hashInfo['algo']);
        $codeValid  = $isHashed && password_verify($code, $storedHash);

        if (!$codeValid) {
            $tries = (int)$otpRecord['attempts'] + 1;
            if ($tries >= 5) {
                $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
                $conn->commit();
                online_json(['ok' => false, 'status' => 'error', 'message' => 'Too many incorrect attempts. Please request a new reset code.'], 429);
            }
            $conn->prepare('UPDATE otp_verifications SET attempts = ? WHERE id = ?')->execute([$tries, $otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Incorrect verification code. Please check your inbox and try again.'], 400);
        }

        $userStmt = $conn->prepare("
            SELECT u.id, u.status, u.is_archived, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?)
            ORDER BY (r.role_name = 'Customer (Online)') DESC
            LIMIT 1
            FOR UPDATE
        ");
        $userStmt->execute([$email, $email]);
        $user = $userStmt->fetch();

        // Strictly restrict to active, non-archived, verified Customer (Online) accounts
        if (!$user || !is_active_online_customer($user)) {
            $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
            $conn->commit();
            online_json(['ok' => false, 'status' => 'error', 'message' => 'Password reset cannot be completed for this account.'], 403);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $upd->execute([$newHash, (int)$user['id']]);

        // Invalidate the OTP immediately after use
        $conn->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$otpId]);
        $conn->commit();

        // Invalidate any current customer session so the user signs in cleanly with the new password
        unset($_SESSION['customer_user_id'], $_SESSION['customer_user']);
        session_regenerate_id(true);

        online_json([
            'ok'      => true,
            'status'  => 'success',
            'message' => 'Your password has been reset successfully. You may now sign in with your new password.'
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Online auth forgot_password_reset error: ' . $e->getMessage());
        online_json(['ok' => false, 'status' => 'error', 'message' => 'Unable to reset your password right now. Please try again.'], 500);
    }
}

online_json(['status' => 'error', 'message' => 'Invalid action.'], 400);
