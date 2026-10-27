<?php

require_once __DIR__ . '/config.php';

$cloudHost = getenv('DB_HOST');
$useCloudDatabase = $cloudHost !== false && $cloudHost !== '';

if ($useCloudDatabase) {
    // Production: Render + Aiven MySQL
    $dbHost = $cloudHost;
    $dbPort = getenv('DB_PORT') ?: '3306';
    $dbName = getenv('DB_NAME') ?: 'defaultdb';
    $dbUser = getenv('DB_USER');
    $dbPass = getenv('DB_PASS');
    $sslCa = getenv('DB_SSL_CA') ?: '/etc/secrets/ca.pem';

    if (!$dbUser || !$dbPass) {
        error_log('Required database environment variables are missing.');
        die('Database configuration error.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort,
        $dbName
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_SSL_CA => $sslCa,
    ];
} else {
    // Development: local XAMPP
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=kenjis_kitchen;charset=utf8mb4';
    $dbUser = 'root';
    $dbPass = '';

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
}

try {
    $conn = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    die('Database connection is temporarily unavailable.');
}
