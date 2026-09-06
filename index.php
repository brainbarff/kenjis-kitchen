<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['u_id'])) {
    redirect(role_home());
}

redirect('/login.php');
?>
