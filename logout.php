<?php
require_once __DIR__ . '/config/config.php';
kk_start_session();

try {
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/includes/auth.php';
    if (function_exists('kk_revoke_current_user_session')) {
        kk_revoke_current_user_session($conn ?? null);
    }
} catch (Throwable $e) {
    // Proceed with logout even if DB connection fails
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

header('Location: login.php');
exit;
?>
