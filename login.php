<?php
require_once __DIR__ . '/config/config.php';
kk_start_session();

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
    if ($target[0] !== '/' && !str_starts_with($target, BASE_URL)) {
        return $default;
    }
    return $target;
}

$defaultCustomerRedirect = '/modules/online/index.html';
$redirectUrl = get_safe_redirect((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''), $defaultCustomerRedirect);


$initialMode = 'staff';
$rawMode = strtolower(trim((string)($_GET['mode'] ?? $_GET['tab'] ?? $_POST['auth_mode'] ?? 'staff')));
if (in_array($rawMode, ['customer', 'customer_login', 'customer_register', 'register', 'online'], true)) {
    $initialMode = 'customer';
}

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
                  AND users.deleted_at IS NULL
                  AND roles.role_name != 'Customer (Online)'
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
                redirect(role_home($user['role_name']));
            } else {
                $failResult = kk_record_login_failure($username);
                $staffMsg = $failResult['message'];
            }
        }
    } elseif ($authMode === 'customer_login') {
        $initialMode = 'customer';
        $custEmail = trim((string)($_POST['customer_email'] ?? ''));
        $password = (string)($_POST['customer_password'] ?? '');

        if ($custEmail === '' || $password === '') {
            $custMsg = 'Please enter your email address and password.';
        } else {
            $cleanPhone = preg_replace('/[^0-9]/', '', $custEmail);
            $isPhone = preg_match('/^09[0-9]{9}$/', $cleanPhone);

            if ($isPhone) {
                $stmt = $conn->prepare("
                    SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.password,
                           u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
                    FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE (u.phone = ? OR u.username = ?)
                      AND r.role_name = 'Customer (Online)'
                    LIMIT 1
                ");
                $stmt->execute([$cleanPhone, $cleanPhone]);
            } else {
                $stmt = $conn->prepare("
                    SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.password,
                           u.status, u.customer_status, u.email_verified_at, u.deleted_at, r.role_name
                    FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?)
                      AND r.role_name = 'Customer (Online)'
                    LIMIT 1
                ");
                $stmt->execute([strtolower($custEmail), strtolower($custEmail)]);
            }
            $custUser = $stmt->fetch();

            if (!$custUser || $custUser['role_name'] !== 'Customer (Online)') {
                $custMsg = 'Online customer account not found.';
            } elseif (!empty($custUser['deleted_at']) || ($custUser['status'] ?? '') !== 'Active' || ($custUser['customer_status'] ?? '') !== 'Active') {
                $custMsg = 'This account is inactive.';
            } elseif (!password_verify($password, (string)$custUser['password'])) {
                $custMsg = 'Invalid email or password.';
            } elseif (empty($custUser['email_verified_at'])) {
                $custMsg = 'Your account is not verified yet. Please enter the verification code sent to your email.';
                $unverifiedDetected = true;
            } else {
                session_regenerate_id(true);
                $_SESSION['customer_user_id'] = (int)$custUser['id'];
                $_SESSION['customer_user'] = [
                    'id'               => (int)$custUser['id'],
                    'full_name'        => $custUser['full_name'],
                    'email'            => $custUser['email'],
                    'phone'            => $custUser['phone'],
                    'customer_address' => $custUser['customer_address']
                ];
                redirect($redirectUrl);
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
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/img/logo.jpg">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/img/logo.jpg">
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
    </style>
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <img src="<?= BASE_URL ?>/assets/img/logo.jpg" alt="Kenji's Kitchen Logo" class="brand-mark" style="width: 56px; height: 56px; border-radius: 12px; object-fit: cover;">
            <h1>Kenji's Kitchen</h1>
            <p class="muted" style="margin: 4px 0 0 0; font-size: 0.88rem;">Restaurant Management &amp; Online Store</p>
        </div>

        <!-- Mode Switcher Tabs -->
        <div class="mode-nav" role="tablist" aria-label="Login Mode">
            <button type="button" class="mode-btn <?= $initialMode === 'staff' ? 'active' : '' ?>" id="btnModeStaff" onclick="switchLoginMode('staff')">
                <i class="bi bi-shield-lock-fill"></i> Restaurant Staff
            </button>
            <button type="button" class="mode-btn <?= $initialMode === 'customer' ? 'active' : '' ?>" id="btnModeCustomer" onclick="switchLoginMode('customer')">
                <i class="bi bi-person-heart"></i> Online Customer
            </button>
        </div>

        <!-- =============================================================== -->
        <!-- RESTAURANT STAFF MODE                                           -->
        <!-- =============================================================== -->
        <div id="panelStaff" style="<?= $initialMode === 'staff' ? 'display:block;' : 'display:none;' ?>">
            <?php if ($staffMsg): ?>
                <div class="alert alert-danger" role="alert"><?= e($staffMsg) ?></div>
            <?php endif; ?>

            <form class="form" method="post" id="staffLoginForm" autocomplete="on">
                <input type="hidden" name="auth_mode" value="staff">

                <div class="field">
                    <label for="staffUsername">Username</label>
                    <input type="text" name="username" id="staffUsername" value="<?= e($username) ?>" required autofocus autocomplete="username" placeholder="e.g. admin, cashier">
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
                    <a class="muted" style="font-size: 0.85rem;" href="#" onclick="alert('Please contact the System Administrator to reset your staff credentials.'); return false;">Forgot Staff Password?</a>
                </div>
            </form>
        </div>

        <!-- =============================================================== -->
        <!-- ONLINE CUSTOMER MODE                                            -->
        <!-- =============================================================== -->
        <div id="panelCustomer" style="<?= $initialMode === 'customer' ? 'display:block;' : 'display:none;' ?>">
            <!-- Sub-tab Bar -->
            <div class="cust-sub-tabs">
                <button type="button" class="cust-tab-btn active" id="subTabCustLogin" onclick="switchCustomerSubView('login')">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                </button>
                <button type="button" class="cust-tab-btn" id="subTabCustRegister" onclick="switchCustomerSubView('register')">
                    <i class="bi bi-person-plus-fill"></i> Create Account
                </button>
            </div>

            <!-- Customer Sign In View -->
            <div id="custViewLogin">
                <?php if ($custMsg): ?>
                    <div class="alert alert-danger" role="alert"><?= e($custMsg) ?></div>
                <?php endif; ?>

                <p class="auth-helper-note">Sign in with your verified customer email and password.</p>

                <form class="form" method="post" id="customerLoginForm" autocomplete="on">
                    <input type="hidden" name="auth_mode" value="customer_login">
                    <input type="hidden" name="redirect" value="<?= e($redirectUrl) ?>">

                    <div class="field">
                        <label for="custEmailInput">Email Address</label>
                        <input type="text" name="customer_email" id="custEmailInput" value="<?= e($custEmail) ?>" required autocomplete="email" placeholder="name@example.com">
                    </div>
                    <div class="field">
                        <label for="custPasswordInput">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="customer_password" id="custPasswordInput" required autocomplete="current-password" placeholder="Enter your password">
                            <button type="button" class="password-toggle-btn" id="toggleCustPasswordBtn" aria-label="Show password" aria-pressed="false" onclick="togglePasswordVisibility('custPasswordInput', 'toggleCustPasswordIcon', this)">
                                <i class="bi bi-eye" id="toggleCustPasswordIcon" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit" style="width: 100%; margin-top: 8px;">
                        <i class="bi bi-box-arrow-in-right"></i> Sign In as Customer
                    </button>
                </form>
            </div>

            <!-- Customer Register View -->
            <div id="custViewRegister" style="display:none;">
                <div id="regAlertBox" class="alert alert-danger" style="display:none;" role="alert"></div>
                <p class="auth-helper-note">Create your customer account. A 6-digit OTP verification code will be sent to your email.</p>

                <div class="form">
                    <div class="field">
                        <label for="regFullName">Full Name</label>
                        <input type="text" id="regFullName" required placeholder="e.g. Juan dela Cruz" autocomplete="name">
                    </div>
                    <div class="field">
                        <label for="regEmail">Email Address</label>
                        <input type="email" id="regEmail" required placeholder="name@example.com" autocomplete="email">
                    </div>
                    <div class="field">
                        <label for="regPassword">Password (min. 6 characters)</label>
                        <div class="password-wrapper">
                            <input type="password" id="regPassword" required placeholder="Create a secure password" autocomplete="new-password">
                            <button type="button" class="password-toggle-btn" aria-label="Show password" aria-pressed="false" onclick="togglePasswordVisibility('regPassword', 'toggleRegPasswordIcon', this)">
                                <i class="bi bi-eye" id="toggleRegPasswordIcon" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary" id="btnSendCustomerOtp" style="width: 100%; margin-top: 8px;" onclick="handleSendRegistrationOtp()">
                        <i class="bi bi-envelope-check"></i> Send Verification Code
                    </button>
                </div>
            </div>

            <!-- Customer OTP Verification View -->
            <div id="custViewOtp" style="display:none;">
                <div id="otpAlertBox" class="alert alert-danger" style="display:none;" role="alert"></div>
                <p class="auth-helper-note" style="text-align: center;">
                    We sent a 6-digit verification code to<br>
                    <strong id="otpTargetDisplay" style="color: #111827;"></strong>
                </p>

                <div class="otp-boxes-grid" id="otpBoxesContainer">
                    <input class="otp-digit-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="0">
                    <input class="otp-digit-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="1">
                    <input class="otp-digit-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="2">
                    <input class="otp-digit-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="3">
                    <input class="otp-digit-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="4">
                    <input class="otp-digit-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="5">
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; font-size: 0.85rem;">
                    <span>Expires in: <strong id="otpTimerDisplay" style="color:#d9534f;">5:00</strong></span>
                    <button type="button" id="btnResendOtp" style="background:none; border:none; color:#9a5b00; font-weight:700; cursor:pointer;" onclick="handleResendCustomerOtp()" disabled>Resend code</button>
                </div>

                <button type="button" class="btn btn-primary" id="btnVerifyCustomerOtp" style="width: 100%;" onclick="handleVerifyRegistrationOtp()">
                    <i class="bi bi-shield-check"></i> Verify &amp; Sign In
                </button>
                <div style="text-align: center; margin-top: 10px;">
                    <button type="button" style="background:none; border:none; color:#6b7280; font-size:0.85rem; cursor:pointer;" onclick="switchCustomerSubView('register')">← Back to Form</button>
                </div>
            </div>

            <!-- Return to Storefront Link -->
            <div class="return-link-row">
                <a href="<?= BASE_URL ?>/modules/online/index.html">
                    <i class="bi bi-shop"></i> Return to Online Store
                </a>
            </div>
        </div>
    </div>

    <script>
        const BASE_API_URL = '<?= BASE_URL ?>/modules/online/api/auth.php';
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

        function switchLoginMode(mode) {
            const isStaff = mode === 'staff';
            document.getElementById('btnModeStaff').classList.toggle('active', isStaff);
            document.getElementById('btnModeCustomer').classList.toggle('active', !isStaff);
            document.getElementById('panelStaff').style.display = isStaff ? 'block' : 'none';
            document.getElementById('panelCustomer').style.display = !isStaff ? 'block' : 'none';

            // Update URL query state smoothly without reloading
            const url = new URL(window.location.href);
            url.searchParams.set('mode', mode);
            window.history.replaceState({}, '', url.toString());
        }

        function switchCustomerSubView(view) {
            document.getElementById('subTabCustLogin').classList.toggle('active', view === 'login');
            document.getElementById('subTabCustRegister').classList.toggle('active', view === 'register');
            document.getElementById('custViewLogin').style.display = view === 'login' ? 'block' : 'none';
            document.getElementById('custViewRegister').style.display = view === 'register' ? 'block' : 'none';
            document.getElementById('custViewOtp').style.display = view === 'otp' ? 'block' : 'none';
        }

        function showRegAlert(msg) {
            const box = document.getElementById('regAlertBox');
            box.textContent = msg;
            box.style.display = 'block';
        }

        function showOtpAlert(msg) {
            const box = document.getElementById('otpAlertBox');
            box.textContent = msg;
            box.style.display = 'block';
        }

        async function handleSendRegistrationOtp() {
            const fullName = document.getElementById('regFullName').value.trim();
            const email = document.getElementById('regEmail').value.trim().toLowerCase();
            const password = document.getElementById('regPassword').value;

            document.getElementById('regAlertBox').style.display = 'none';

            if (!fullName || fullName.length < 2) {
                showRegAlert('Please enter your full name (at least 2 characters).');
                return;
            }
            if (!email || !email.includes('@')) {
                showRegAlert('Please enter a valid email address.');
                return;
            }
            if (!password || password.length < 6) {
                showRegAlert('Password must be at least 6 characters long.');
                return;
            }

            const btn = document.getElementById('btnSendCustomerOtp');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending code...';

            try {
                const res = await fetch(BASE_API_URL + '?action=otp_send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: email,
                        mode: 'register',
                        full_name: fullName,
                        password: password
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    otpPendingEmail = email;
                    document.getElementById('otpTargetDisplay').textContent = email;
                    switchCustomerSubView('otp');
                    initOtpBoxes();
                    startOtpTimer(300);
                } else {
                    showRegAlert(data.message || 'Unable to send verification code.');
                }
            } catch (err) {
                showRegAlert('Network error. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-envelope-check"></i> Send Verification Code';
            }
        }

        async function handleResendCustomerOtp() {
            if (!otpPendingEmail) return;
            const resendBtn = document.getElementById('btnResendOtp');
            resendBtn.disabled = true;
            resendBtn.textContent = 'Resending...';

            const fullName = document.getElementById('regFullName').value.trim() || 'Kenji Customer';
            const password = document.getElementById('regPassword').value || 'SecurePass123!';

            try {
                const res = await fetch(BASE_API_URL + '?action=otp_send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: otpPendingEmail,
                        mode: 'register',
                        full_name: fullName,
                        password: password
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    showOtpAlert('A new verification code has been sent.');
                    document.getElementById('otpAlertBox').className = 'alert alert-success';
                    startOtpTimer(300);
                } else {
                    document.getElementById('otpAlertBox').className = 'alert alert-danger';
                    showOtpAlert(data.message || 'Unable to resend code.');
                }
            } catch (err) {
                document.getElementById('otpAlertBox').className = 'alert alert-danger';
                showOtpAlert('Network error. Please try again.');
            }
        }

        async function handleVerifyRegistrationOtp() {
            const boxes = document.querySelectorAll('.otp-digit-box');
            let code = '';
            boxes.forEach(b => code += b.value.trim());

            if (code.length !== 6 || !/^\d{6}$/.test(code)) {
                document.getElementById('otpAlertBox').className = 'alert alert-danger';
                showOtpAlert('Please enter all 6 digits of the verification code.');
                return;
            }

            const btn = document.getElementById('btnVerifyCustomerOtp');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Verifying...';

            try {
                const res = await fetch(BASE_API_URL + '?action=otp_verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: otpPendingEmail,
                        code: code,
                        mode: 'register'
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    if (data.user) {
                        try {
                            localStorage.setItem('kk_customer_session', JSON.stringify(data.user));
                        } catch (_) {}
                    }
                    window.location.href = REDIRECT_TARGET;
                } else {
                    document.getElementById('otpAlertBox').className = 'alert alert-danger';
                    showOtpAlert(data.message || 'Invalid verification code.');
                    boxes.forEach(b => b.value = '');
                    boxes[0].focus();
                }
            } catch (err) {
                document.getElementById('otpAlertBox').className = 'alert alert-danger';
                showOtpAlert('Network error during verification.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-shield-check"></i> Verify &amp; Sign In';
            }
        }

        function initOtpBoxes() {
            const boxes = document.querySelectorAll('.otp-digit-box');
            boxes.forEach((box, i) => {
                box.value = '';
                box.oninput = (e) => {
                    const val = e.target.value.replace(/\D/g, '');
                    box.value = val ? val[0] : '';
                    if (box.value && i < 5) boxes[i + 1].focus();
                    const allFilled = Array.from(boxes).every(b => b.value !== '');
                    if (allFilled) setTimeout(() => handleVerifyRegistrationOtp(), 150);
                };
                box.onkeydown = (e) => {
                    if (e.key === 'Backspace' && !box.value && i > 0) {
                        boxes[i - 1].focus();
                    }
                };
            });
            if (boxes[0]) boxes[0].focus();
        }

        function startOtpTimer(seconds) {
            clearInterval(otpTimerInterval);
            let remaining = seconds;
            const display = document.getElementById('otpTimerDisplay');
            const resendBtn = document.getElementById('btnResendOtp');
            resendBtn.disabled = true;

            function tick() {
                const m = Math.floor(remaining / 60);
                const s = remaining % 60;
                display.textContent = `${m}:${s < 10 ? '0' : ''}${s}`;
                if (remaining <= 0) {
                    clearInterval(otpTimerInterval);
                    display.textContent = 'Expired';
                    resendBtn.disabled = false;
                }
                remaining--;
            }
            tick();
            otpTimerInterval = setInterval(tick, 1000);
        }

        // If unverified customer attempted login, automatically switch to customer view & guide them
        <?php if ($unverifiedDetected && $custEmail): ?>
        switchLoginMode('customer');
        otpPendingEmail = <?= json_encode($custEmail) ?>;
        document.getElementById('otpTargetDisplay').textContent = otpPendingEmail;
        switchCustomerSubView('otp');
        initOtpBoxes();
        startOtpTimer(300);
        <?php endif; ?>
    </script>
</body>
</html>
