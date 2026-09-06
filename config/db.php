<?php
require_once __DIR__ . '/config.php';

$host = 'localhost';
$db_name = 'kenjis_kitchen';
$db_user = 'root';
$db_pass = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database connection failed. Please check config/db.php.');
}
?>
