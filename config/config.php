<?php
// Load the project-local environment file for Apache/PHP without overwriting
// environment variables supplied by the host process.
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || $envLine[0] === '#' || strpos($envLine, '=') === false) {
            continue;
        }
        [$envKey, $envValue] = explode('=', $envLine, 2);
        $envKey = trim($envKey);
        if ($envKey === '' || getenv($envKey) !== false) {
            continue;
        }
        $envValue = trim($envValue);
        if (strlen($envValue) >= 2 && (($envValue[0] === '"' && substr($envValue, -1) === '"') || ($envValue[0] === "'" && substr($envValue, -1) === "'"))) {
            $envValue = substr($envValue, 1, -1);
        }
        putenv($envKey . '=' . $envValue);
        $_ENV[$envKey] = $envValue;
    }
}

date_default_timezone_set('Asia/Manila');

define('APP_NAME', "Kenji's Kitchen");

$rawAppUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
if ($rawAppUrl !== false && $rawAppUrl !== null) {
    $baseUrl = trim((string)$rawAppUrl);
} else {
    $baseUrl = '/kenjis-kitchen';
}
define('BASE_URL', rtrim($baseUrl, '/'));
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/menu/');

if (!function_exists('kk_is_https')) {
    function kk_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }
}

if (!function_exists('kk_start_session')) {
    function kk_start_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => kk_is_https(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        session_start();
    }
}
?>
