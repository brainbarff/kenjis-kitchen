<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("
        SELECT users.*, roles.role_name
        FROM users
        JOIN roles ON roles.id = users.role_id
        WHERE users.username = ? AND users.status = 'Active'
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['u_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['login_time'] = date('Y-m-d H:i:s');
        redirect(role_home($user['role_name']));
    } else {
        $msg = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Kenji's Kitchen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Serif:wght@400;500;600;700&family=Old+Standard+TT:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page">
    <form class="login-card" method="post">
        <div class="login-brand">
            <span class="brand-mark">K</span>
            <h1>Kenji's Kitchen</h1>
            <p class="muted">Restaurant POS and Online Ordering System</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-danger"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="form">
            <div class="field">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button class="btn btn-primary" type="submit">Login</button>
            <a class="muted" href="#">Forgot Password?</a>
        </div>
    </form>
</body>
</html>
