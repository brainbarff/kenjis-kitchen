<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_role('admin');

$params = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $params['edit'] = (int)$_GET['edit'];
}
if (($filter = ($_GET['filter'] ?? '')) === 'archived') {
    $params['archived_staff'] = '1';
}
$target = '/modules/users/index.php' . ($params ? ('?' . http_build_query($params)) : '');
redirect($target);
