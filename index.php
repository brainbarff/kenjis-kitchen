<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_GET['staff']) || !empty($_GET['login'])) {
    if (!empty($_SESSION['u_id'])) {
        redirect(role_home());
    }
    redirect('/login.php');
}

redirect('online-menu/');
?>
