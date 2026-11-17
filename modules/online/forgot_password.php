<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
$target = BASE_URL . '/online-menu/forgot_password.php';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . $target, true, 302);
exit;
