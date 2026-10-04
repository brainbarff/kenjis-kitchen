<?php
require_once __DIR__ . '/config.php';

$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = explode(':', $httpHost)[0];
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1']) || PHP_SAPI === 'cli';

$host = 'localhost';
if ($isLocal) {
    $db_name = 'kenjis_kitchen';
    $db_user = 'root';
    $db_pass = '';
} else {
    $db_name = 'u201025533_kenjiskitchen';
    $db_user = 'u201025533_kenjiskitchen';
    $db_pass = 'Letsgocapstone2026';
}

try {
    $conn = new PDO(
        "mysql:host=$host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // If local fails or differs, try fallback credentials
    if ($isLocal && $db_name === 'kenjis_kitchen') {
        try {
            $conn = new PDO("mysql:host=$host;dbname=u201025533_kenjiskitchen;charset=utf8mb4", 'root', '');
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $fallbackErr) {
            die('Database error: ' . $e->getMessage());
        }
    } else {
        die('Database error: ' . $e->getMessage());
    }
}
?>