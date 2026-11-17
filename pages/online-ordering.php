<?php
/**
 * Customer Online Ordering with Recipe & Fulfillment Option Choices
 * URL: /kenjis-kitchen/pages/online-ordering.php
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Seamlessly forward to the primary responsive storefront while preserving parameters
$target = BASE_URL . '/online-menu/';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}

header('Location: ' . $target);
exit;
