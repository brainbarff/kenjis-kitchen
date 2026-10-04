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

$baseUrl = getenv('APP_URL') ?: '/kenjis-kitchen';
define('BASE_URL', rtrim($baseUrl, '/'));
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/menu/');


?>
