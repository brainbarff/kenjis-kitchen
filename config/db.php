<?php
require_once __DIR__ . '/config.php';

$envValue = static function (string $key, $default = null) {
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== false) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    return $val !== false ? $val : $default;
};

$host = $envValue('DB_HOST') ?: '127.0.0.1';
$port = (int) ($envValue('DB_PORT') ?: 3306);
$db_name = $envValue('DB_NAME') ?: 'u201025533_kenjiskitchen';
$db_user = $envValue('DB_USER') ?: 'u201025533_kenjiskitchen';
$db_pass = $envValue('DB_PASS', '');


try {
    $conn = new PDO(
        "mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->exec("SET time_zone = '+08:00'");

} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection error. A service error occurred. Please try again later.');
}
?>
