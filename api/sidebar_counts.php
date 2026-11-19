<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/functions.php';

require_role(['admin', 'cashier', 'kitchen', 'inventory']);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$actorId = (int)(current_user()['id'] ?? 0);
$module = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['module'] ?? 'dashboard'));
if ($module === '') {
    $module = 'dashboard';
}

echo json_encode([
    'ok' => true,
    'counts' => kk_sidebar_badge_counts($conn, $actorId),
    'notifications' => kk_module_notifications($conn, $module, $actorId),
]);
