<?php
require_once __DIR__ . '/config/config.php';
kk_start_session();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

// If already logged in as staff, verify session state and forward to their role's dashboard
if (!empty($_SESSION['u_id']) && !empty($_SESSION['role'])) {
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/includes/auth.php';
    require_login();
    redirect(role_home($_SESSION['role']));
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_limiter.php';

function get_safe_redirect(string $target, string $default): string
{
    $target = trim($target);
    if ($target === '') {
        return $default;
    }
    // Prevent open redirects: disallow protocols and protocol-relative URLs
    if (preg_match('#^(https?:)?//#i', $target)) {
        return $default;
    }
    if ($target[0] !== '/' && (BASE_URL === '' || !str_starts_with($target, BASE_URL . '/'))) {
        return $default;
    }
    return $target;
}

$defaultCustomerRedirect = '/online-menu/';
$redirectUrl = get_safe_redirect((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''), $defaultCustomerRedirect);


$initialMode = 'staff';

$staffMsg = '';
$custMsg = '';
$username = '';
$custEmail = '';
$unverifiedDetected = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authMode = $_POST['auth_mode'] ?? 'staff';

    if ($authMode === 'staff') {
        $initialMode = 'staff';
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $rateLimit = kk_check_login_rate_limit($username);
        if ($rateLimit['blocked']) {
            $staffMsg = $rateLimit['message'];
        } else {
            $stmt = $conn->prepare("
                SELECT users.*, roles.role_name
                FROM users
                JOIN roles ON roles.id = users.role_id
                WHERE users.username = ?
                  AND users.status = 'Active'
                  AND users.is_archived = 0
                  AND users.deleted_at IS NULL
                  AND roles.role_name IN ('Admin', 'Cashier', 'Kitchen Staff', 'Inventory Staff')
                LIMIT 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, (string)$user['password'])) {
                kk_clear_login_rate_limit($username);
                session_regenerate_id(true);
                $_SESSION['u_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role_name'];
                $_SESSION['login_time'] = date('Y-m-d H:i:s');
                $_SESSION['force_password_change'] = !empty($user['force_password_change']);
                kk_create_user_session($conn, (int)$user['id']);
                if (!empty($user['force_password_change'])) {
                    redirect('/pages/change_password.php');
                }
                redirect(role_home($user['role_name']));
            } else {
                $failResult = kk_record_login_failure($username);
                $staffMsg = $failResult['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Kenji's Kitchen</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/img/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Serif:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&family=Old+Standard+TT:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .login-card {
            width: min(460px, 100%);
            padding: 28px 32px;
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.12);
            border-radius: 20px;
        }
        .login-brand {
            margin-bottom: 20px;
        }
        .login-brand h1 {
            font-size: 1.6rem;
            margin-top: 6px;
        }
        .mode-nav {
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #f1f3f5;
            padding: 4px;
            border-radius: 12px;
            gap: 4px;
            margin-bottom: 22px;
        }
        .mode-btn {
            border: none;
            background: transparent;
            padding: 10px 14px;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 0.88rem;
            font-weight: 700;
            color: #4b5563;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .mode-btn.active {
            background: #ffffff;
            color: #111827;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .mode-btn i {
            font-size: 1rem;
            color: #F2C12E;
        }
        .cust-sub-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 10px;
        }
        .cust-tab-btn {
            background: transparent;
            border: none;
            font-size: 0.85rem;
            font-weight: 700;
            color: #6b7280;
            padding: 6px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .cust-tab-btn.active {
            background: #fff8df;
            color: #9a5b00;
        }
        .otp-boxes-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 8px;
            margin: 16px 0;
        }
        .otp-digit-box {
            width: 100%;
            height: 48px;
            text-align: center;
            font-size: 1.4rem;
            font-weight: 800;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            background: #ffffff;
            font-family: monospace;
        }
        .otp-digit-box:focus {
            outline: none;
            border-color: #F2C12E;
            box-shadow: 0 0 0 3px rgba(242, 193, 46, 0.25);
        }
        .return-link-row {
            text-align: center;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #f1f3f5;
        }
        .return-link-row a {
            color: #6b7280;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.15s ease;
        }
        .return-link-row a:hover {
            color: #111827;
        }
        .auth-helper-note {
            font-size: 0.82rem;
            color: #6b7280;
            margin-bottom: 14px;
            line-height: 1.4;
        }
    
        .login-notice { display:flex; gap:12px; align-items:flex-start; padding:16px; border:1px solid #eadfbd; border-radius:12px; background:#fffaf0; text-align:left; margin:0 0 18px; }
        .login-notice[hidden] { display:none; }
        .login-notice-icon { display:grid; place-items:center; flex:0 0 34px; height:34px; border-radius:10px; background:#ffedab; color:#795500; font-size:18px; }
        .login-notice>div { min-width:0; flex:1; }
        .login-notice strong { display:block; color:#292720; font-size:14px; line-height:1.5; }
        .login-notice p { margin:5px 0 0; color:#665d49; font-size:13px; line-height:1.65; overflow-wrap:anywhere; }
        .login-notice small { display:block; margin-top:9px; color:#81745c; font-size:12px; line-height:1.6; }
        .login-notice-error { background:#fff8f5; border-color:#efd8cf; }
        .login-notice-error .login-notice-icon { color:#a33225; background:#fbe6de; }
        .login-notice-help { margin:16px 0 0; }
        .login-help-toggle { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:40px; background:none; border:0; color:#745710; padding:10px 12px; border-radius:8px; cursor:pointer; font:600 13px Inter,sans-serif; }
        .login-help-toggle:hover { background:#fff4cd; }
        .login-notice-close { display:grid; place-items:center; flex:0 0 28px; height:28px; background:transparent; border:0; border-radius:6px; color:#756a52; cursor:pointer; }
        .login-notice-close:hover { background:#ffedb8; color:#242424; }
        .login-help-toggle:focus-visible,.login-notice-close:focus-visible { outline:2px solid #b78b16; outline-offset:3px; }
        @media(pointer:coarse) { .login-help-toggle { min-height:44px; } }
        @media(max-width:480px) { .login-card { padding:24px 20px; } .login-notice { padding:13px; gap:9px; } }
    </style>
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="Kenji's Kitchen Logo" class="brand-mark" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid #f2c12e; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
            <h1>Kenji's Kitchen</h1>
            <p class="muted" style="margin: 4px 0 0 0; font-size: 0.88rem;">Store Staff Login</p>
        </div>

        <!-- =============================================================== -->
        <!-- RESTAURANT STAFF MODE                                           -->
        <!-- =============================================================== -->
        <div id="panelStaff" style="<?= $initialMode === 'staff' ? 'display:block;' : 'display:none;' ?>">
            <?php if ($staffMsg): ?>
                <div class="login-notice login-notice-error" role="alert" id="loginError">
                    <span class="login-notice-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
                    <div><strong>Unable to sign in</strong><p><?= e($staffMsg) ?></p></div>
                </div>
            <?php endif; ?>

            <form class="form" method="post" id="staffLoginForm" autocomplete="on">
                <input type="hidden" name="auth_mode" value="staff">

                <div class="field">
                    <label for="staffUsername">Username</label>
                    <input type="text" name="username" id="staffUsername" value="<?= e($username) ?>" required autofocus autocomplete="username" placeholder="e.g. admin, cashier1">
                </div>
                <div class="field">
                    <label for="loginPassword">Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="loginPassword" required autocomplete="current-password" placeholder="Enter your staff password">
                        <button type="button" class="password-toggle-btn" id="togglePasswordBtn" aria-label="Show password" aria-pressed="false" onclick="togglePasswordVisibility('loginPassword', 'togglePasswordIcon', this)">
                            <i class="bi bi-eye" id="togglePasswordIcon" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit" style="width: 100%; margin-top: 8px;">
                    <i class="bi bi-box-arrow-in-right"></i> Login as Staff
                </button>
                <div style="text-align: center; margin-top: 14px;">
                    <button type="button" class="login-help-toggle" id="staffRecoveryToggle" aria-expanded="false" aria-controls="staffRecovery">Forgot staff password?</button>
                    <div class="login-notice login-notice-help" id="staffRecovery" hidden>
                        <span class="login-notice-icon"><i class="bi bi-person-lock" aria-hidden="true"></i></span>
                        <div><strong>Need help signing in?</strong><p>Ask Kenji's Kitchen Admin to reset your staff password.</p><small>Have your staff username ready when requesting help.</small></div>
                        <button type="button" class="login-notice-close" id="closeStaffRecovery" aria-label="Dismiss password recovery help"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- =============================================================== -->
        <!-- ONLINE CUSTOMER MODE                                            -->
        <!-- =============================================================== -->
    </div>

    <script>
        const BASE_API_URL = '<?= BASE_URL ?>/online-menu/api/auth.php';
        const REDIRECT_TARGET = <?= json_encode($redirectUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        let otpPendingEmail = <?= json_encode($custEmail ?: '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let otpTimerInterval = null;

        function togglePasswordVisibility(inputId, iconId, btn) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;
            const isPass = input.type === 'password';
            input.type = isPass ? 'text' : 'password';
            icon.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
            btn.setAttribute('aria-label', isPass ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', isPass ? 'true' : 'false');
        }

        const recoveryToggle = document.getElementById('staffRecoveryToggle');
        const recoveryPanel = document.getElementById('staffRecovery');
        const recoveryClose = document.getElementById('closeStaffRecovery');
        recoveryToggle.addEventListener('click', () => {
            const opening = recoveryPanel.hidden;
            recoveryPanel.hidden = !opening;
            recoveryToggle.setAttribute('aria-expanded', String(opening));
            if (opening) recoveryClose.focus();
        });
        recoveryClose.addEventListener('click', () => {
            recoveryPanel.hidden = true;
            recoveryToggle.setAttribute('aria-expanded', 'false');
            recoveryToggle.focus();
        });
        // Prevent navigating forward back into authenticated session from cached history
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
    <script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/app.js') ?: '20261118' ?>"></script>
</body>
</html>
