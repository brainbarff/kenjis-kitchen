<?php
require_once dirname(__DIR__) . '/config/config.php';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($amount)
{
    return '₱' . number_format((float) $amount, 2);
}

function active_link($key)
{
    return ($GLOBALS['page'] ?? '') === $key ? 'active' : '';
}

function redirect($path)
{
    $path = trim((string)$path);
    if ($path === '') {
        $path = '/';
    }

    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        $target = $path;
    } elseif (defined('BASE_URL') && BASE_URL !== '' && (strpos($path, BASE_URL . '/') === 0 || $path === BASE_URL)) {
        // Path already includes BASE_URL, do not duplicate it
        $target = $path;
    } else {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $target = rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    header('Location: ' . $target);
    exit;
}


function upload_menu_image($file)
{
    if (empty($file) || !is_array($file) || empty($file['name'])) {
        return null;
    }

    if (!isset($file['tmp_name'], $file['error'], $file['size'])) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!is_file($file['tmp_name'])) {
        return null;
    }

    $isUploaded = is_uploaded_file($file['tmp_name']);
    if (!$isUploaded && php_sapi_name() !== 'cli') {
        return null;
    }

    if ($file['size'] <= 0 || $file['size'] > 3000000) {
        return null;
    }

    $originalName = (string)$file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        return null;
    }

    if (!extension_loaded('fileinfo')) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return null;
    }
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!$detectedMime) {
        return null;
    }

    $mimeToExt = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp']
    ];

    if (!isset($mimeToExt[$detectedMime])) {
        return null;
    }

    if (!in_array($extension, $mimeToExt[$detectedMime], true)) {
        return null;
    }

    $canonicalExt = ($detectedMime === 'image/jpeg') ? 'jpg' : (($detectedMime === 'image/png') ? 'png' : 'webp');
    $name = 'menu_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $canonicalExt;

    if (!defined('UPLOAD_PATH')) {
        define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/menu/');
    }

    if (!is_dir(UPLOAD_PATH)) {
        @mkdir(UPLOAD_PATH, 0755, true);
    }

    $dest = UPLOAD_PATH . $name;
    $saved = $isUploaded ? move_uploaded_file($file['tmp_name'], $dest) : copy($file['tmp_name'], $dest);

    if (!$saved) {
        return null;
    }

    return $name;
}

/**
 * Returns the session-bound CSRF token, creating it when missing.
 * Shares the $_SESSION['csrf_token'] key used by modules/settings/payments.php.
 */
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent()) {
                throw new RuntimeException('CSRF token requires an active session.');
            }
            kk_start_session();
        }

        $current = $_SESSION['csrf_token'] ?? '';
        if (!is_string($current) || !preg_match('/^[a-f0-9]{64}$/', $current)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

/**
 * Returns an escaped hidden input carrying the CSRF token for HTML forms.
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

/**
 * Validates a submitted CSRF token against the session token.
 * When $token is null, the token is read from $_POST['csrf_token'], then from
 * the X-CSRF-Token request header (for JSON/fetch requests).
 * Missing, empty, malformed, or mismatched tokens are always rejected.
 */
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return false;
        }

        if ($token === null) {
            $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }

        if (!is_string($token) || $token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
?>
