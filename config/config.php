<?php

date_default_timezone_set('Asia/Manila');

define('APP_NAME', "Kenji's Kitchen");

$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
$forwardedProto = strtolower(trim(explode(',', $forwardedProto)[0]));

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) ||
    $forwardedProto === 'https';

$protocol = $isHttps ? 'https://' : 'http://';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = explode(':', $httpHost)[0];

if (in_array($hostOnly, ['localhost', '127.0.0.1'], true)) {
    define('BASE_URL', $protocol . $httpHost . '/kenjis-kitchen');
} else {
    define('BASE_URL', $protocol . $httpHost);
}

define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/menu/');
