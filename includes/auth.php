<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

function require_login()
{
    if (empty($_SESSION['u_id'])) {
        redirect('/login.php');
    }
}

function role_key($role = null)
{
    $role = strtolower(trim($role ?? ($_SESSION['role'] ?? '')));

    $map = [
        'admin' => 'admin',
        'cashier' => 'cashier',
        'inventory' => 'inventory',
        'inventory staff' => 'inventory',
        'kitchen' => 'kitchen',
        'kitchen staff' => 'kitchen',
        'manager' => 'manager',
        'server' => 'server'
    ];

    return $map[$role] ?? $role;
}

function role_home($role = null)
{
    $homes = [
        'admin' => '/pages/dashboard.php',
        'cashier' => '/modules/pos/index.php',
        'inventory' => '/modules/inventory/index.php',
        'kitchen' => '/modules/kitchen/index.php',
        'server' => '/modules/serving/index.php'
    ];

    return $homes[role_key($role)] ?? '/pages/access_denied.php';
}

function require_role($roles)
{
    require_login();
    $allowed = array_map('role_key', is_array($roles) ? $roles : [$roles]);

    if (!in_array(role_key(), $allowed, true)) {
        redirect('/pages/access_denied.php');
    }
}

function can_access($roles)
{
    $allowed = array_map('role_key', is_array($roles) ? $roles : [$roles]);

    return in_array(role_key(), $allowed, true);
}

function current_user()
{
    return [
        'id' => $_SESSION['u_id'] ?? null,
        'username' => $_SESSION['username'] ?? 'Guest',
        'role' => $_SESSION['role'] ?? 'Guest',
        'role_key' => role_key()
    ];
}
?>
