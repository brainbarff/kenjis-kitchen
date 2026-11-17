<?php
/**
 * Kenji's Kitchen - Force Password Change on First Login
 */
define('ALLOW_PASSWORD_CHANGE_ONLY', true);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/db.php';

require_login();

$uId = (int)$_SESSION['u_id'];
$msg = '';
$msgType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $msg = 'Invalid security token. Please reload the page and try again.';
    } else {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 12) {
            $msg = 'Password must be at least 12 characters long.';
        } elseif ($newPass !== $confirmPass) {
            $msg = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?");
            $stmt->execute([$hash, $uId]);

            if (!empty($_SESSION['session_token'])) {
                $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND id != ? AND revoked_at IS NULL")
                    ->execute([$uId, (string)$_SESSION['session_token']]);
            }

            log_audit($conn, 'Password Change (Forced)', 'user', (string)$uId, 'User changed temporary password on first login.');

            $_SESSION['force_password_change'] = false;
            redirect(role_home());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Kenji's Kitchen</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .force-pw-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #11131a;
        }
        .force-pw-card {
            background: #1a1d27;
            border: 1px solid #2a2d3a;
            border-radius: 14px;
            max-width: 440px;
            width: 100%;
            padding: 32px 28px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            color: #f3f4f6;
        }
        .force-pw-card h2 {
            color: #F2C12E;
            margin-top: 0;
            font-size: 1.35rem;
        }
        .force-pw-card p {
            color: #9ca3af;
            font-size: 0.88rem;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .field {
            margin-bottom: 16px;
        }
        .field label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: #d1d5db;
        }
        .field input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 14px;
            background: #11131a;
            border: 1.5px solid #2a2d3a;
            border-radius: 8px;
            color: #fff;
            font-size: 0.95rem;
        }
        .field input:focus {
            outline: none;
            border-color: #F2C12E;
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #F2C12E;
            color: #111;
            font-weight: 800;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            margin-top: 8px;
        }
        .btn-submit:hover {
            background: #e0b020;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #EF4444;
            color: #f87171;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }
    </style>
</head>
<body class="force-pw-wrap">
    <div class="force-pw-card">
        <h2>🔒 Update Your Password</h2>
        <p>Your account was set with a temporary password or requires an update before continuing. Password must be <strong>at least 12 characters</strong>.</p>

        <?php if ($msg): ?>
            <div class="alert-error"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label>New Password (min 12 characters)</label>
                <input type="password" name="new_password" required minlength="12" autofocus placeholder="Enter new password">
            </div>
            <div class="field">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="12" placeholder="Repeat new password">
            </div>
            <button type="submit" class="btn-submit">Save Password & Continue →</button>
        </form>
    </div>
</body>
</html>
