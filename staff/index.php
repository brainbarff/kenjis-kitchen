<?php
require_once dirname(__DIR__) . '/includes/auth.php';
if (!empty($_SESSION['u_id'])) {
    redirect(role_home());
}
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/rate_limiter.php';

$roles = ['admin' => 'Admin', 'cashier' => 'Cashier', 'inventory' => 'Inventory Staff', 'kitchen' => 'Kitchen Staff'];
$activeStaff = " FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE u.status = 'Active' AND u.deleted_at IS NULL";
$countsQuery = $conn->prepare("SELECT r.role_name, COUNT(*)" . $activeStaff .
    " AND r.role_name IN (?, ?, ?, ?) GROUP BY r.role_name");
$countsQuery->execute(array_values($roles));
$counts = $countsQuery->fetchAll(PDO::FETCH_KEY_PAIR);
$postedRole = $_POST['role'] ?? null;
$role = is_string($postedRole) && isset($roles[$postedRole]) ? $postedRole : 'admin';
$username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!verify_csrf_token()) {
        $error = 'Your sign-in form expired. Please try again.';
    } elseif ($postedRole !== $role || $password === '') {
        $error = 'Choose your role and enter your password.';
    } elseif (($counts[$roles[$role]] ?? 0) > 1 && $username === '') {
        $error = 'This role has several accounts. Enter your staff username.';
    } else {
        $shared = ($counts[$roles[$role]] ?? 0) > 1;
        $query = $conn->prepare("SELECT u.id, u.username, u.password, r.role_name" . $activeStaff .
            " AND r.role_name = ?" . ($shared ? " AND u.username = ?" : "") . " LIMIT 2");
        $query->execute($shared ? [$roles[$role], $username] : [$roles[$role]]);
        $matches = $query->fetchAll(PDO::FETCH_ASSOC);
        $user = count($matches) === 1 ? $matches[0] : null;
        $key = $user['username'] ?? ($shared ? $username : 'staff-role:' . $role);
        $limit = kk_check_login_rate_limit($key);
        if ($limit['blocked']) {
            $error = $limit['message'];
        } elseif ($user && password_verify($password, $user['password'])) {
            kk_clear_login_rate_limit($key);
            session_regenerate_id(true);
            $_SESSION['u_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role_name'];
            $_SESSION['login_time'] = date('Y-m-d H:i:s');
            redirect(role_home($user['role_name']));
        } else {
            $failure = kk_record_login_failure($key);
            $error = $failure['blocked'] ? $failure['message'] : 'We couldn’t sign you in. Check your role and credentials.';
        }
    }
}

// Small, self-contained line icons also work at an offline register.
$paths = [
    'person' => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
    'chevron' => '<path d="m6 9 6 6 6-6"/>',
    'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="2.7"/>',
    'eye-off' => '<path d="m3 3 18 18M9.5 5.4A11 11 0 0 1 12 5c6.5 0 10 7 10 7a16 16 0 0 1-3 3.9M15 18.5a11 11 0 0 1-3 .5c-6.5 0-10-7-10-7a16 16 0 0 1 3.7-4.5"/>',
    'arrow' => '<path d="M4 12h15m-6-6 6 6-6 6"/>',
    'lock' => '<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
];
function staff_icon(string $name): string {
    global $paths;
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff sign-in · Kenji’s Kitchen</title>
    <link rel="icon" href="<?= e(BASE_URL) ?>/assets/img/logo.jpg">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/staff-login.css?v=2">
    <script src="<?= e(BASE_URL) ?>/assets/js/staff-login.js?v=2" defer></script>
</head>
<body>
    <header><img src="<?= e(BASE_URL) ?>/assets/img/logo.jpg" alt=""><strong>Kenji’s Kitchen</strong><span class="internal"><?= staff_icon('lock') ?> Staff access</span></header>
    <main>
        <section class="card" aria-labelledby="heading">
            <div class="kitchen-panel">
                <span class="eyebrow">From the kitchen</span>
                <p class="greeting">Welcome to<br> your shift.</p>
                <svg class="cloche" viewBox="0 0 280 145" fill="none" aria-hidden="true">
                    <g stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M44 112h192l-10 10H55zM57 111a83 75 0 0 1 166 0M133 36v-4a7 7 0 0 1 14 0v4M79 83c7-17 19-28 34-33"/>
                        <path d="M117 22c4-6-5-8-1-15m44 15c6-7-4-10 1-18M38 77l-11-4m18-11-8-8m201 5 10-8m-4 22 12-3"/>
                        <path d="M73 131h137" opacity=".4"/>
                    </g>
                </svg>
                <span class="location">Greater Lagro · Quezon City</span>
            </div>
            <div class="sign-in-panel">
            <div class="eyebrow"><span></span> Team workspace</div>
            <h1 id="heading">Staff sign-in</h1>
            <p class="intro">Choose your role to start your shift.</p>
            <form method="post" action="<?= e(BASE_URL) ?>/staff/">
                <?= csrf_field() ?>
                <div><label for="role">Your role</label>
                <div class="role-select">
                    <?= staff_icon('person') ?>
                    <select id="role" name="role" required>
                    <option value="" disabled <?= $postedRole !== $role ? 'selected' : '' ?>>Select your role</option>
                <?php foreach ($roles as $value => $name): ?>
                    <option value="<?= e($value) ?>" data-shared="<?= ($counts[$name] ?? 0) > 1 ? 'yes' : 'no' ?>" <?= $postedRole === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option>
                <?php endforeach; ?>
                    </select>
                    <?= staff_icon('chevron') ?>
                </div></div>
                <?php if ($counts && max($counts) > 1): ?>
                <div id="account-field"><label for="username">Staff username</label><input id="username" name="username" autocomplete="username" value="<?= e($username) ?>"><small>Needed when more than one person uses this role.</small></div>
                <?php endif; ?>
                <div><label for="password">Password</label>
                <div class="password"><input id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required><button id="reveal" type="button" hidden aria-controls="password" aria-label="Show password"><?= staff_icon('eye') ?><?= staff_icon('eye-off') ?></button></div></div>
                <?php if ($error !== ''): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
                <button class="submit" type="submit">Sign in <?= staff_icon('arrow') ?></button>
            </form>
            <details><summary>Forgot your password?</summary><p>Ask the system administrator for the staff password.</p></details>
            </div>
        </section>
    </main>
    <footer><div><strong>Kenji’s Kitchen</strong><small>© 2026</small></div><span>Eatery operations</span></footer>
</body>
</html>
