<?php
// Keep existing bookmarks working while staff and customer sign-in stay separate.
require_once __DIR__ . '/includes/functions.php';
$mode = $_GET['mode'] ?? $_GET['tab'] ?? $_POST['auth_mode'] ?? '';
$mode = is_string($mode) ? strtolower(trim($mode)) : '';
if (in_array($mode, ['customer', 'customer_login', 'online', 'customer_register', 'register'], true)) {
    $tab = in_array($mode, ['customer_register', 'register'], true) ? 'register' : 'signin';
    redirect('/modules/online/?auth=' . $tab);
}
redirect('/staff/');
