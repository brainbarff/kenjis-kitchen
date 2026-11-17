<?php
require_once dirname(__DIR__) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__) . '/includes/functions.php';

function prevent_browser_cache(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }
}

function kk_create_user_session(PDO $conn, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['session_token'] = $token;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    $device = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Web'), 0, 255);

    try {
        $stmt = $conn->prepare("
            INSERT INTO user_sessions (id, user_id, device_info, ip_address, created_at, last_seen_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$token, $userId, $device, $ip]);

        $upd = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_ip = ? WHERE id = ?");
        $upd->execute([$ip, $userId]);
    } catch (Throwable $e) {
        error_log('Session tracking write failed: ' . $e->getMessage());
    }

    return $token;
}

function kk_revoke_current_user_session(?PDO $conn = null): void
{
    $token = $_SESSION['session_token'] ?? '';
    if (!is_string($token) || $token === '' || !($conn instanceof PDO)) {
        return;
    }
    try {
        $stmt = $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL");
        $stmt->execute([$token]);
    } catch (Throwable $e) {
        error_log('Session revocation write failed: ' . $e->getMessage());
    }
}

function kk_clear_staff_session(): void
{
    unset(
        $_SESSION['u_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['login_time'],
        $_SESSION['session_token'],
        $_SESSION['force_password_change']
    );
}

function require_login()
{
    prevent_browser_cache();

    if (empty($_SESSION['u_id'])) {
        redirect('/login.php');
    }

    global $conn;
    if (!isset($conn) || !($conn instanceof PDO)) {
        $dbFile = dirname(__DIR__) . '/config/db.php';
        if (is_file($dbFile)) {
            require_once $dbFile;
        }
    }

    if (isset($conn) && $conn instanceof PDO) {
        static $verifiedSessionUser = false;
        if (!$verifiedSessionUser) {
            $verifiedSessionUser = true;
            try {
                $stmt = $conn->prepare("
                    SELECT u.id, u.username, u.status, u.is_archived, u.deleted_at, u.force_password_change, r.role_name
                    FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $stmt->execute([(int)$_SESSION['u_id']]);
                $liveUser = $stmt->fetch(PDO::FETCH_ASSOC);
                if (
                    !$liveUser
                    || ($liveUser['status'] ?? '') !== 'Active'
                    || !empty($liveUser['is_archived'])
                    || !empty($liveUser['deleted_at'])
                    || !in_array($liveUser['role_name'] ?? '', ['Admin', 'Cashier', 'Kitchen Staff', 'Inventory Staff'], true)
                ) {
                    kk_clear_staff_session();
                    redirect('/login.php');
                }

                $sessionToken = $_SESSION['session_token'] ?? '';
                if (is_string($sessionToken) && $sessionToken !== '') {
                    $sessStmt = $conn->prepare("SELECT revoked_at FROM user_sessions WHERE id = ? AND user_id = ? LIMIT 1");
                    $sessStmt->execute([$sessionToken, (int)$liveUser['id']]);
                    $sessRow = $sessStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$sessRow || !empty($sessRow['revoked_at'])) {
                        kk_clear_staff_session();
                        redirect('/login.php');
                    }
                    $conn->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?")->execute([$sessionToken]);
                } else {
                    $loginTime = $_SESSION['login_time'] ?? '1970-01-01 00:00:00';
                    $revStmt = $conn->prepare("
                        SELECT COUNT(*) FROM user_sessions
                        WHERE user_id = ? AND revoked_at IS NOT NULL AND revoked_at >= ?
                    ");
                    $revStmt->execute([(int)$liveUser['id'], $loginTime]);
                    if ((int)$revStmt->fetchColumn() > 0) {
                        kk_clear_staff_session();
                        redirect('/login.php');
                    }
                    kk_create_user_session($conn, (int)$liveUser['id']);
                }

                $_SESSION['username'] = $liveUser['username'];
                $_SESSION['role'] = $liveUser['role_name'];
                $_SESSION['force_password_change'] = !empty($liveUser['force_password_change']);
            } catch (Throwable $e) {
                // Keep existing session check fallback if query fails unexpectedly
            }
        }
    }

    if (!empty($_SESSION['force_password_change']) && !defined('ALLOW_PASSWORD_CHANGE_ONLY')) {
        redirect('/pages/change_password.php');
    }
}

function role_key($role = null)
{
    $role = strtolower(trim($role ?? ($_SESSION['role'] ?? '')));

    $map = [
        'admin' => 'admin',
        'cashier' => 'cashier',
        'inventory' => 'inventory',
        'inventory staff' => 'inventory',
        'kitchen' => 'kitchen',
        'kitchen staff' => 'kitchen'
    ];

    return $map[$role] ?? $role;
}

function role_home($role = null)
{
    $homes = [
        'admin' => '/pages/dashboard.php',
        'cashier' => '/modules/pos/index.php',
        'inventory' => '/modules/inventory/index.php',
        'kitchen' => '/modules/kitchen/index.php'
    ];

    return $homes[role_key($role)] ?? '/pages/access_denied.php';
}

function require_role($roles)
{
    require_login();
    $allowed = array_map('role_key', is_array($roles) ? $roles : [$roles]);

    if (!in_array(role_key(), $allowed, true)) {
        redirect('/pages/access_denied.php');
    }
}

function can_access($roles)
{
    $allowed = array_map('role_key', is_array($roles) ? $roles : [$roles]);

    return in_array(role_key(), $allowed, true);
}

function current_user()
{
    return [
        'id' => $_SESSION['u_id'] ?? null,
        'username' => $_SESSION['username'] ?? 'Guest',
        'role' => $_SESSION['role'] ?? 'Guest',
        'role_key' => role_key()
    ];
}
?>
