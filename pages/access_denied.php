<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();

$page = 'access-denied';
$title = 'Access Denied';
$heading = 'Access Denied';

include ROOT_PATH . '/includes/header.php';
?>
<section class="card access-card">
    <span class="access-icon"><i class="bi bi-shield-lock"></i></span>
    <h2>You do not have access to this page.</h2>
    <p class="muted">Your account can only open the modules assigned to your role.</p>
    <a class="btn btn-primary" href="<?= BASE_URL . role_home() ?>"><i class="bi bi-arrow-left"></i>Go to My Module</a>
</section>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
