<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'users';
$title = 'User Management';
$heading = 'User Management';
$msg = $_SESSION['users_flash_msg'] ?? '';
$msgIsError = (bool)($_SESSION['users_flash_error'] ?? false);
unset($_SESSION['users_flash_msg'], $_SESSION['users_flash_error']);

$currentAdminId = (int)($_SESSION['u_id'] ?? 0);
$showArchivedStaff = isset($_GET['archived_staff']) && $_GET['archived_staff'] === '1';

function count_other_active_admins(PDO $conn, int $excludeUserId): int
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'Admin'
          AND u.status = 'Active'
          AND u.is_archived = 0
          AND u.deleted_at IS NULL
          AND u.id != ?
    ");
    $stmt->execute([$excludeUserId]);
    return (int)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $msg = 'Invalid security token. Please reload the page and try again.';
        $msgIsError = true;
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if (in_array($action, ['add', 'edit'], true)) {
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $username = trim((string)($_POST['username'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $roleId = (int)($_POST['role_id'] ?? 0);
                $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
                $password = (string)($_POST['password'] ?? '');
                $pinCode = trim((string)($_POST['pin_code'] ?? ''));
                $forcePasswordChange = !empty($_POST['force_password_change']) ? 1 : 0;

                $roleStmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ? AND role_name IN ('Admin','Cashier','Kitchen Staff','Inventory Staff') LIMIT 1");
                $roleStmt->execute([$roleId]);
                $targetRoleName = $roleStmt->fetchColumn();

                if (!$targetRoleName) {
                    throw new RuntimeException('Select one of the four supported staff roles (Admin, Cashier, Kitchen Staff, Inventory Staff).');
                }
                if ($fullName === '' || mb_strlen($fullName) > 100) {
                    throw new RuntimeException('Enter a valid full name (up to 100 characters).');
                }
                if ($username === '' || !preg_match('/^[A-Za-z0-9_.\-]{2,50}$/', $username)) {
                    throw new RuntimeException('Username must be 3–50 characters using letters, numbers, dots, underscores, or hyphens.');
                }
                if ($phone !== '' && !preg_match('/^09[0-9]{9}$/', $phone)) {
                    throw new RuntimeException('Staff mobile number must be 11 digits starting with 09, or left blank.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid email address, or leave it blank.');
                }
                if ($pinCode !== '' && !preg_match('/^[0-9]{4}$/', $pinCode)) {
                    throw new RuntimeException('Cashier POS PIN must be exactly 4 numeric digits (e.g. 0000, 1111).');
                }

                if ($action === 'add') {
                    if (strlen($password) < 6) {
                        throw new RuntimeException('Password must be at least 6 characters long.');
                    }
                    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?)");
                    $dupStmt->execute([$username]);
                    if ((int)$dupStmt->fetchColumn() > 0) {
                        throw new RuntimeException('That username is already in use.');
                    }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $conn->prepare("INSERT INTO users (full_name, username, email, phone, password, force_password_change, pin_code, role_id, status, is_archived, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)");
                    $ins->execute([$fullName, $username, $email ?: null, $phone ?: null, $hash, $forcePasswordChange, $pinCode !== '' ? password_hash($pinCode, PASSWORD_DEFAULT) : null, $roleId, $status]);
                    $newStaffId = (int)$conn->lastInsertId();
                    log_audit($conn, 'Staff Created', 'user', (string)$newStaffId, "Created staff account @{$username} ({$fullName}), Role: {$targetRoleName}");
                    $msg = 'Staff user created successfully.';
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $existingStmt = $conn->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1");
                    $existingStmt->execute([$id]);
                    $existingUser = $existingStmt->fetch();
                    if (!$existingUser || $existingUser['role_name'] === 'Customer (Online)') {
                        throw new RuntimeException('Staff user account not found.');
                    }
                    if (in_array($existingUser['role_name'], ['Manager', 'Server'], true) && !empty($existingUser['deleted_at'])) {
                        throw new RuntimeException('Retired legacy staff accounts cannot be modified or reactivated here.');
                    }
                    if ($id === $currentAdminId) {
                        if ($status !== 'Active') {
                            throw new RuntimeException('You cannot deactivate your own active administrator account.');
                        }
                        if ($targetRoleName !== 'Admin') {
                            throw new RuntimeException('You cannot remove your own Administrator role.');
                        }
                    }
                    if ($existingUser['role_name'] === 'Admin' && ($status !== 'Active' || $targetRoleName !== 'Admin')) {
                        if (count_other_active_admins($conn, $id) < 1) {
                            throw new RuntimeException('Cannot deactivate or demote the last active Administrator account.');
                        }
                    }
                    $dupStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?) AND id != ?");
                    $dupStmt->execute([$username, $id]);
                    if ((int)$dupStmt->fetchColumn() > 0) {
                        throw new RuntimeException('That username is already in use by another account.');
                    }

                    $storedPin = $pinCode !== '' ? password_hash($pinCode, PASSWORD_DEFAULT) : $existingUser['pin_code'];

                    if ($password !== '') {
                        if (strlen($password) < 6) {
                            throw new RuntimeException('New password must be at least 6 characters long.');
                        }
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, password = ?, force_password_change = ?, pin_code = ?, role_id = ?, status = ? WHERE id = ?");
                        $upd->execute([$fullName, $username, $email ?: null, $phone ?: null, $hash, $forcePasswordChange, $storedPin, $roleId, $status, $id]);
                        if ($id !== $currentAdminId) {
                            $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
                        }
                    } else {
                        $upd = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, force_password_change = ?, pin_code = ?, role_id = ?, status = ? WHERE id = ?");
                        $upd->execute([$fullName, $username, $email ?: null, $phone ?: null, $forcePasswordChange, $storedPin, $roleId, $status, $id]);
                    }
                    if ($status !== 'Active') {
                        $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
                    }
                    log_audit($conn, 'Staff Updated', 'user', (string)$id, "Updated staff account @{$username} (Role: {$targetRoleName}, Status: {$status})");
                    $msg = 'Staff user updated successfully.';
                }
            } elseif (in_array($action, ['activate_staff', 'deactivate_staff', 'archive_staff', 'restore_staff'], true)) {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target || $target['role_name'] === 'Customer (Online)') {
                    throw new RuntimeException('Staff account not found.');
                }

                $isLegacyRole = !in_array($target['role_name'], ['Admin', 'Cashier', 'Kitchen Staff', 'Inventory Staff'], true);
                if ($isLegacyRole && in_array($action, ['activate_staff', 'restore_staff'], true)) {
                    throw new RuntimeException('Archived legacy role accounts (' . $target['role_name'] . ') are retired and cannot be reactivated.');
                }

                if (in_array($action, ['deactivate_staff', 'archive_staff'], true)) {
                    if ($id === $currentAdminId) {
                        throw new RuntimeException('You cannot deactivate or archive your own active administrator account.');
                    }
                    if ($target['role_name'] === 'Admin' && count_other_active_admins($conn, $id) < 1) {
                        throw new RuntimeException('Cannot deactivate or archive the last usable Administrator account.');
                    }
                }

                if ($action === 'activate_staff') {
                    if (!empty($target['deleted_at']) || !empty($target['is_archived'])) {
                        throw new RuntimeException('Restore the archived account first before activating it.');
                    }
                    $conn->prepare("UPDATE users SET status = 'Active' WHERE id = ?")->execute([$id]);
                    log_audit($conn, 'Staff Activated', 'user', (string)$id, "Activated staff account @{$target['username']}");
                    $msg = 'Staff account activated.';
                } elseif ($action === 'deactivate_staff') {
                    $conn->prepare("UPDATE users SET status = 'Inactive' WHERE id = ?")->execute([$id]);
                    $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
                    log_audit($conn, 'Staff Deactivated', 'user', (string)$id, "Deactivated staff account @{$target['username']}");
                    $msg = 'Staff account deactivated.';
                } elseif ($action === 'archive_staff') {
                    $conn->prepare("UPDATE users SET status = 'Inactive', is_archived = 1, deleted_at = COALESCE(deleted_at, NOW()) WHERE id = ?")->execute([$id]);
                    $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
                    log_audit($conn, 'Staff Archived', 'user', (string)$id, "Archived staff account @{$target['username']}");
                    $msg = 'Staff account archived safely (transaction history preserved).';
                } elseif ($action === 'restore_staff') {
                    $conn->prepare("UPDATE users SET deleted_at = NULL, is_archived = 0, status = 'Active' WHERE id = ?")->execute([$id]);
                    log_audit($conn, 'Staff Restored', 'user', (string)$id, "Restored staff account @{$target['username']}");
                    $msg = 'Staff account restored and set to Active.';
                }
            } elseif (in_array($action, ['edit_customer', 'activate_customer', 'deactivate_customer', 'archive_customer', 'restore_customer', 'reset_cod'], true)) {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.role_name = 'Customer (Online)' LIMIT 1");
                $stmt->execute([$id]);
                $customer = $stmt->fetch();
                if (!$customer) {
                    throw new RuntimeException('Online customer account not found.');
                }

                if ($action === 'edit_customer') {
                    $fullName = trim((string)($_POST['full_name'] ?? ''));
                    $phone = trim((string)($_POST['phone'] ?? ''));
                    $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
                    if ($fullName === '' || mb_strlen($fullName) > 100) {
                        throw new RuntimeException('Enter a valid customer full name.');
                    }
                    if ($phone !== '' && !preg_match('/^09[0-9]{9}$/', $phone)) {
                        throw new RuntimeException('Customer mobile number must be 11 digits starting with 09, or left blank.');
                    }
                    $conn->prepare("UPDATE users SET full_name = ?, phone = ?, status = ?, customer_status = ? WHERE id = ?")
                        ->execute([$fullName, $phone ?: null, $status, $status, $id]);
                    if ($status !== 'Active') {
                        $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
                    }
                    log_audit($conn, 'Customer Updated', 'customer', (string)$id, "Updated customer account ID {$id}");
                    $msg = 'Customer account details updated.';
                } elseif ($action === 'activate_customer') {
                    $conn->prepare("UPDATE users SET status = 'Active', customer_status = 'Active', is_archived = 0, deleted_at = NULL WHERE id = ?")->execute([$id]);
                    log_audit($conn, 'Customer Activated', 'customer', (string)$id, "Activated customer ID {$id}");
                    $msg = 'Customer account activated.';
                } elseif ($action === 'deactivate_customer') {
                    $conn->prepare("UPDATE users SET status = 'Inactive', customer_status = 'Inactive' WHERE id = ?")->execute([$id]);
                    $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
                    log_audit($conn, 'Customer Deactivated', 'customer', (string)$id, "Deactivated customer ID {$id}");
                    $msg = 'Customer account deactivated.';
                } elseif ($action === 'archive_customer') {
                    $activeOrdersStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND order_channel = 'ONLINE' AND status IN ('Pending', 'Preparing', 'Ready')");
                    $activeOrdersStmt->execute([$id]);
                    $activeCount = (int)$activeOrdersStmt->fetchColumn();
                    if ($activeCount > 0) {
                        throw new RuntimeException("Cannot archive customer: Customer currently has {$activeCount} active order(s) (Pending, Preparing, or Ready). Please fulfill or cancel the orders first.");
                    }
                    $conn->prepare("UPDATE users SET status = 'Inactive', customer_status = 'Inactive', is_archived = 1, deleted_at = COALESCE(deleted_at, NOW()) WHERE id = ?")->execute([$id]);
                    $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
                    log_audit($conn, 'Customer Archived', 'customer', (string)$id, "Archived customer ID {$id}");
                    $msg = 'Customer account archived (historical orders preserved).';
                } elseif ($action === 'restore_customer') {
                    $conn->prepare("UPDATE users SET deleted_at = NULL, is_archived = 0, status = 'Active', customer_status = 'Active' WHERE id = ?")->execute([$id]);
                    log_audit($conn, 'Customer Restored', 'customer', (string)$id, "Restored customer ID {$id}");
                    $msg = 'Customer account restored and activated.';
                } elseif ($action === 'reset_cod') {
                    $conn->prepare("UPDATE users SET cod_blocked = 0, cod_fail_count = 0 WHERE id = ?")->execute([$id]);
                    if (function_exists('log_audit')) {
                        log_audit($conn, 'Customer COD Unblocked', 'customer', (string)$id, "Admin reset COD fail count and unblocked customer ID {$id}.");
                    }
                    $msg = 'Customer COD block lifted and fail count reset to 0.';
                }
            } else {
                throw new RuntimeException('Unsupported user management action.');
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $msgIsError = true;
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.role_name IN ('Admin','Cashier','Kitchen Staff','Inventory Staff') LIMIT 1");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$roles = $conn->query("SELECT * FROM roles WHERE role_name IN ('Admin','Cashier','Kitchen Staff','Inventory Staff') ORDER BY FIELD(role_name, 'Admin','Cashier','Kitchen Staff','Inventory Staff')")->fetchAll();

$staffPerPage = 10;
if ($showArchivedStaff) {
    $totalStaffUsers = (int)$conn->query("
        SELECT COUNT(*)
        FROM users
        JOIN roles ON roles.id = users.role_id
        WHERE roles.role_name != 'Customer (Online)'
          AND (users.deleted_at IS NOT NULL OR users.is_archived = 1 OR roles.role_name NOT IN ('Admin','Cashier','Kitchen Staff','Inventory Staff'))
    ")->fetchColumn();
    $totalStaffPages = max(1, (int)ceil($totalStaffUsers / $staffPerPage));
    $staffPage = max(1, min((int)($_GET['staff_page'] ?? 1), $totalStaffPages));
    $staffOffset = ($staffPage - 1) * $staffPerPage;

    $staffStmt = $conn->prepare("
        SELECT users.*, roles.role_name
        FROM users
        JOIN roles ON roles.id = users.role_id
        WHERE roles.role_name != 'Customer (Online)'
          AND (users.deleted_at IS NOT NULL OR users.is_archived = 1 OR roles.role_name NOT IN ('Admin','Cashier','Kitchen Staff','Inventory Staff'))
        ORDER BY COALESCE(users.deleted_at, users.created_at) DESC, users.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $staffStmt->bindValue(':limit', $staffPerPage, PDO::PARAM_INT);
    $staffStmt->bindValue(':offset', $staffOffset, PDO::PARAM_INT);
    $staffStmt->execute();
    $users = $staffStmt->fetchAll();
} else {
    $totalStaffUsers = (int)$conn->query("
        SELECT COUNT(*)
        FROM users
        JOIN roles ON roles.id = users.role_id
        WHERE roles.role_name IN ('Admin','Cashier','Kitchen Staff','Inventory Staff')
          AND users.deleted_at IS NULL
          AND users.is_archived = 0
    ")->fetchColumn();
    $totalStaffPages = max(1, (int)ceil($totalStaffUsers / $staffPerPage));
    $staffPage = max(1, min((int)($_GET['staff_page'] ?? 1), $totalStaffPages));
    $staffOffset = ($staffPage - 1) * $staffPerPage;

    $staffStmt = $conn->prepare("
        SELECT users.*, roles.role_name
        FROM users
        JOIN roles ON roles.id = users.role_id
        WHERE roles.role_name IN ('Admin','Cashier','Kitchen Staff','Inventory Staff')
          AND users.deleted_at IS NULL
          AND users.is_archived = 0
        ORDER BY FIELD(roles.role_name, 'Admin','Cashier','Kitchen Staff','Inventory Staff'), users.id ASC
        LIMIT :limit OFFSET :offset
    ");
    $staffStmt->bindValue(':limit', $staffPerPage, PDO::PARAM_INT);
    $staffStmt->bindValue(':offset', $staffOffset, PDO::PARAM_INT);
    $staffStmt->execute();
    $users = $staffStmt->fetchAll();
}

$archivedStaffCount = (int)$conn->query("
    SELECT COUNT(*)
    FROM users
    JOIN roles ON roles.id = users.role_id
    WHERE roles.role_name != 'Customer (Online)'
      AND (users.deleted_at IS NOT NULL OR users.is_archived = 1 OR roles.role_name NOT IN ('Admin','Cashier','Kitchen Staff','Inventory Staff'))
")->fetchColumn();

$customerPerPage = 10;
$totalOnlineCustomers = (int)$conn->query("
    SELECT COUNT(*)
    FROM users
    JOIN roles ON roles.id = users.role_id
    WHERE roles.role_name = 'Customer (Online)'
")->fetchColumn();
$totalCustomerPages = max(1, (int)ceil($totalOnlineCustomers / $customerPerPage));
$customerPage = max(1, min((int)($_GET['customer_page'] ?? 1), $totalCustomerPages));
$customerOffset = ($customerPage - 1) * $customerPerPage;

$onlineCustomersStmt = $conn->prepare("
    SELECT users.id, users.full_name, users.username, users.email, users.phone, users.customer_address,
           users.status, users.customer_status, users.email_verified_at, users.deleted_at, users.created_at,
           users.cod_blocked, users.cod_fail_count,
           roles.role_name,
           COUNT(o.id) AS total_orders
    FROM users
    JOIN roles ON roles.id = users.role_id
    LEFT JOIN orders o ON o.user_id = users.id AND o.order_channel = 'ONLINE'
    WHERE roles.role_name = 'Customer (Online)'
    GROUP BY users.id
    ORDER BY users.created_at DESC, users.id DESC
    LIMIT :limit OFFSET :offset
");
$onlineCustomersStmt->bindValue(':limit', $customerPerPage, PDO::PARAM_INT);
$onlineCustomersStmt->bindValue(':offset', $customerOffset, PDO::PARAM_INT);
$onlineCustomersStmt->execute();
$onlineCustomers = $onlineCustomersStmt->fetchAll();

$buildUsersQuery = static function (array $overrides = []) use ($staffPage, $customerPage, $showArchivedStaff): string {
    $params = array_merge([
        'edit' => isset($_GET['edit']) ? (int)$_GET['edit'] : '',
        'archived_staff' => $showArchivedStaff ? '1' : '',
        'staff_page' => $staffPage,
        'customer_page' => $customerPage,
    ], $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
};

include ROOT_PATH . '/includes/header.php';
?>
<style>
.staff-identity {
    display: grid;
    gap: 8px;
    min-width: 150px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}
.staff-identity-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 10px;
    font-size: 13px;
}
.staff-self-badge {
    padding: 3px 9px;
    font-size: 11px;
    line-height: 1.4;
    flex-shrink: 0;
}
.staff-credentials {
    align-items: start;
}
.staff-field-hint {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
}
.user-actions-group {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
    white-space: nowrap;
}
.user-actions-group form {
    margin: 0;
    display: inline-flex;
    flex-shrink: 0;
}
.btn-sm-action {
    min-height: 34px;
    padding: 5px 10px;
    font-size: 12.5px;
    border-radius: 9px;
    white-space: nowrap;
    flex-shrink: 0;
}
.user-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    z-index: 1200;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
}
.user-modal-backdrop.show {
    display: flex;
}
.user-modal-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 16px;
    max-width: 520px;
    width: 100%;
    padding: 24px;
    box-shadow: 0 20px 48px rgba(15, 23, 42, 0.22);
    max-height: 90vh;
    overflow-y: auto;
}
.user-detail-grid {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 10px 14px;
    margin-top: 14px;
    font-size: 14px;
}
.user-detail-grid dt {
    color: var(--muted);
    font-weight: 600;
}
.user-detail-grid dd {
    margin: 0;
    font-weight: 600;
    word-break: break-word;
}
@media (max-width: 540px) {
    .user-detail-grid {
        grid-template-columns: 1fr;
        gap: 4px;
    }
}

#userDetailModal { background:rgba(20,20,20,.6); }
#userDetailModal .user-modal-card { max-width:620px; padding:28px; }
#userDetailModalTitle { font-size:24px!important; line-height:1.3; color:#242424; }
#userDetailModal .btn { min-height:38px; font-size:14px; }
.account-profile { display:flex; align-items:center; gap:14px; padding:20px 0; margin-top:8px; border-bottom:1px solid #e8e8e3; }
.account-avatar { display:flex; align-items:center; justify-content:center; flex-shrink:0; width:52px; height:52px; border-radius:50%; background:#242424; color:white; font-size:22px; font-weight:700; }
.account-profile h4 { font-size:20px; line-height:1.4; margin:0 0 4px; overflow-wrap:anywhere; }
.account-profile p { margin:0; font-size:14px; color:#66500d; }
#userDetailList { display:block; margin:14px 0 0; }
#userDetailList .account-detail-row { display:grid; grid-template-columns:155px minmax(0,1fr); gap:16px; padding:11px 0; border-bottom:1px solid #efefeb; font-size:15px; line-height:1.6; }
#userDetailList .account-detail-row:last-child { border-bottom:0; }
#userDetailList dt { color:#646464; font-weight:500; }
#userDetailList dd { color:#292929; font-weight:600; overflow-wrap:anywhere; }
#userDetailModal button:focus-visible { outline:2px solid #b48b12; outline-offset:3px; }
@media(max-width:540px) { #userDetailModal .user-modal-card { padding:20px; } #userDetailList .account-detail-row { grid-template-columns:1fr; gap:3px; } #userDetailModalTitle { font-size:22px!important; } }
</style>

<?php if ($msg): ?>
    <div class="alert <?= $msgIsError ? 'alert-danger' : 'alert-success' ?>" style="margin-bottom: 18px;"><?= e($msg) ?></div>
<?php endif; ?>

<div class="grid grid-2">
    <section class="card">
        <div class="page-head" style="margin-bottom: 16px;">
            <h2><?= $edit ? 'Edit Staff User' : 'Create Staff User' ?></h2>
            <?php if ($edit): ?><a class="btn btn-secondary btn-sm-action" href="<?= e($buildUsersQuery(['edit' => ''])) ?>">Cancel Edit</a><?php endif; ?>
        </div>
        <form class="form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= e($edit['id']) ?>"><?php endif; ?>
            <div class="field"><label>Full Name</label><input name="full_name" maxlength="100" value="<?= e($edit['full_name'] ?? '') ?>" required></div>
            <div class="field"><label>Username</label><input name="username" maxlength="50" value="<?= e($edit['username'] ?? '') ?>" required></div>
            <div class="grid grid-2" style="gap: 12px;">
                <div class="field"><label>Email (Optional)</label><input type="email" name="email" maxlength="120" value="<?= e($edit['email'] ?? '') ?>"></div>
                <div class="field"><label for="staff-phone">Mobile (Optional)</label><input id="staff-phone" type="tel" name="phone" maxlength="11" pattern="09[0-9]{9}" placeholder="09171234567" value="<?= e($edit['phone'] ?? '') ?>"></div>
            </div>
            <div class="grid grid-2 staff-credentials" style="gap: 12px;">
                <div class="field">
                    <label for="staff-password">Password</label>
                    <input id="staff-password" type="password" name="password" minlength="6" <?= $edit ? 'aria-describedby="staff-password-hint"' : 'required' ?> autocomplete="new-password">
                    <?php if ($edit): ?><p class="staff-field-hint" id="staff-password-hint">Leave blank to keep the current password.</p><?php endif; ?>
                </div>
                <div class="field">
                    <label for="staff-pin">4-Digit POS PIN</label>
                    <input id="staff-pin" type="password" name="pin_code" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="4 digits" autocomplete="new-password" aria-describedby="staff-pin-hint">
                    <p class="staff-field-hint" id="staff-pin-hint">For cashier staff POS access only.<?= $edit ? ' Leave blank to keep the current PIN.' : '' ?></p>
                </div>
            </div>
            <div class="grid grid-2" style="gap: 12px;">
                <div class="field">
                    <label>Role</label>
                    <select name="role_id" required>
                        <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role['id']) ?>" <?= ($edit['role_id'] ?? '') == $role['id'] ? 'selected' : '' ?>><?= e($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <option value="Active" <?= ($edit['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= ($edit['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="field" style="margin-top: 8px; margin-bottom: 12px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.86rem; font-weight:500;">
                    <input type="checkbox" name="force_password_change" value="1" <?= !empty($edit['force_password_change']) ? 'checked' : '' ?> style="width:auto; margin:0;">
                    Require password change on next login (Temporary Password)
                </label>
            </div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i><?= $edit ? 'Update Staff User' : 'Save Staff User' ?></button>
        </form>
    </section>

    <section class="card">
        <div class="page-head" style="flex-wrap: wrap;">
            <div>
                <h2 style="margin:0;"><?= $showArchivedStaff ? 'Archived Staff Accounts' : 'Staff Accounts' ?></h2>
                <p class="muted" style="margin:4px 0 0;font-size:13px;">Manage active staff roles or view archived staff records.</p>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto;">
                <?php if ($showArchivedStaff): ?>
                    <a class="btn btn-secondary btn-sm-action" href="<?= e($buildUsersQuery(['archived_staff' => '', 'staff_page' => 1])) ?>"><i class="bi bi-people"></i> Active Staff</a>
                <?php else: ?>
                    <a class="btn btn-secondary btn-sm-action" href="<?= e($buildUsersQuery(['archived_staff' => '1', 'staff_page' => 1])) ?>"><i class="bi bi-archive"></i> Archived (<?= (int)$archivedStaffCount ?>)</a>
                <?php endif; ?>
                <?= render_pagination_controls($staffPage, $totalStaffPages, static fn(int $p) => $buildUsersQuery(['staff_page' => $p])) ?>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name / Username</th><th>Role</th><th>POS PIN</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="5" class="muted" style="text-align:center;padding:24px;">No staff accounts found in this view.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $row): ?>
                    <?php
                    $isSupportedRole = in_array($row['role_name'], ['Admin', 'Cashier', 'Kitchen Staff', 'Inventory Staff'], true);
                    $isArchived = !empty($row['deleted_at']) || !empty($row['is_archived']) || !$isSupportedRole;
                    $isSelf = (int)$row['id'] === $currentAdminId;
                    $detailPayload = [
                        'type' => 'Staff Account',
                        'id' => (int)$row['id'],
                        'full_name' => (string)$row['full_name'],
                        'username' => '@' . (string)$row['username'],
                        'role' => (string)$row['role_name'] . ($isSupportedRole ? '' : ' (Retired Legacy Role)'),
                        'status' => $isArchived ? 'Archived (' . (string)$row['status'] . ')' : (string)$row['status'],
                        'email' => (string)($row['email'] ?: '—'),
                        'phone' => (string)($row['phone'] ?: '—'),
                        'address' => (string)($row['customer_address'] ?: '—'),
                        'created_at' => !empty($row['created_at']) ? date('M d, Y h:i A', strtotime($row['created_at'])) : '—',
                        'archived_at' => !empty($row['deleted_at'])
                            ? date('M d, Y h:i A', strtotime($row['deleted_at']))
                            : ($isArchived ? 'Archived (Legacy Role)' : 'Not archived'),
                        'orders' => 'N/A (Staff)',
                    ];
                    ?>
                    <tr>
                        <td>
                            <div class="staff-identity">
                                <strong><?= e($row['full_name']) ?></strong>
                                <div class="staff-identity-meta">
                                    <span class="muted">@<?= e($row['username']) ?></span>
                                    <?php if ($isSelf): ?><span class="badge badge-warning staff-self-badge">You</span><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?= e($row['role_name']) ?>
                            <?php if (!$isSupportedRole): ?>
                                <br><small class="muted">Legacy (Retired)</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['pin_code'])): ?>
                                <span class="badge badge-info" style="font-family:monospace; font-size:12px; letter-spacing:2px;">••••</span>
                            <?php else: ?>
                                <span class="muted" style="font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isArchived): ?>
                                <span class="badge badge-danger">Archived</span>
                            <?php else: ?>
                                <span class="badge <?= $row['status'] === 'Active' ? 'badge-success' : 'badge-muted' ?>"><?= e($row['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="user-actions-group">
                                <button type="button" class="btn btn-secondary btn-sm-action" title="View User Details" aria-label="View User Details" data-user-detail="<?= e(json_encode($detailPayload)) ?>" onclick="openUserDetailModal(this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php if (!$isArchived && $isSupportedRole): ?>
                                    <a class="btn btn-secondary btn-sm-action" title="Edit Staff User" aria-label="Edit Staff User" href="<?= e($buildUsersQuery(['edit' => (int)$row['id']])) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($row['status'] === 'Active'): ?>
                                        <?php if (!$isSelf): ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="deactivate_staff">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm-action" title="Deactivate Staff User" data-confirm="Deactivate staff account @<?= e($row['username']) ?>?">
                                                <i class="bi bi-pause-circle"></i> Deactivate
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="activate_staff">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm-action" title="Activate Staff User" data-confirm="Activate staff account @<?= e($row['username']) ?>?">
                                                <i class="bi bi-check-circle"></i> Activate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!$isSelf): ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="archive_staff">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm-action" title="Archive Staff User" data-confirm="Archive staff account @<?= e($row['username']) ?>? Historical records will be preserved.">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($isArchived && $isSupportedRole): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore_staff">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm-action" title="Restore Staff User" data-confirm="Restore archived staff account @<?= e($row['username']) ?> to Active status?">
                                            <i class="bi bi-arrow-counterclockwise"></i> Restore
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted" style="font-size:12px;">Retired Role</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- Online Registered Customers -->
<section class="card" style="margin-top: 24px;">
    <div class="page-head" style="margin-bottom: 16px; flex-wrap: wrap;">
        <div>
            <h2 style="margin:0;"><i class="bi bi-globe" style="color: var(--primary);"></i> Online Registered Customers</h2>
            <p class="muted" style="margin:4px 0 0;font-size:13px;">Customer accounts registered via the online ordering storefront.</p>
        </div>
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-left:auto;">
            <a class="btn btn-secondary btn-sm-action" href="<?= BASE_URL ?>/modules/admin/customers.php"><i class="bi bi-search"></i> Search &amp; Customer Archive</a>
            <span style="font-size: 0.85rem; color: var(--muted); font-weight: 600;"><?= (int)$totalOnlineCustomers ?> registered</span>
            <?= render_pagination_controls($customerPage, $totalCustomerPages, static fn(int $p) => $buildUsersQuery(['customer_page' => $p])) ?>
        </div>
    </div>
    <?php if (empty($onlineCustomers)): ?>
        <div style="text-align:center; padding: 40px; color: var(--muted);">
            <i class="bi bi-people" style="font-size: 2rem; display:block; margin-bottom: 8px;"></i>
            No online customers have registered yet.
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Orders</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($onlineCustomers as $c): ?>
                <?php
                $isCustArchived = !empty($c['deleted_at']);
                $isCustActive = !$isCustArchived && ($c['status'] === 'Active') && (($c['customer_status'] ?? 'Active') === 'Active');
                $custStatusLabel = $isCustActive ? 'Active' : 'Inactive';
                $custDetailPayload = [
                    'type' => 'Online Customer',
                    'id' => (int)$c['id'],
                    'full_name' => (string)$c['full_name'],
                    'username' => (string)($c['username'] ? '@' . $c['username'] : '—'),
                    'role' => (string)($c['role_name'] ?? 'Customer (Online)'),
                    'status' => $isCustArchived ? 'Inactive (Archived)' : $custStatusLabel,
                    'email' => (string)($c['email'] ?: '—'),
                    'phone' => (string)($c['phone'] ?: '—'),
                    'address' => (string)($c['customer_address'] ?: '—'),
                    'created_at' => !empty($c['created_at']) ? date('M d, Y h:i A', strtotime($c['created_at'])) : '—',
                    'archived_at' => $isCustArchived ? date('M d, Y h:i A', strtotime($c['deleted_at'])) : 'Not archived',
                    'orders' => (int)$c['total_orders'] . ' online order(s)',
                ];
                $custEditPayload = [
                    'id' => (int)$c['id'],
                    'full_name' => (string)$c['full_name'],
                    'email' => (string)($c['email'] ?? ''),
                    'phone' => (string)($c['phone'] ?? ''),
                    'customer_address' => (string)($c['customer_address'] ?? ''),
                    'status' => $isCustActive ? 'Active' : 'Inactive',
                ];
                ?>
                <tr>
                    <td>
                        <strong><?= e($c['full_name']) ?></strong>
                        <?php if ($isCustArchived): ?><br><small class="muted">Archived</small><?php endif; ?>
                    </td>
                    <td><?= e($c['email'] ?? '—') ?></td>
                    <td><?= e($c['phone'] ?? '—') ?></td>
                    <td><?= e($c['role_name'] ?? 'Customer (Online)') ?></td>
                    <td><span class="badge badge-success"><?= (int)$c['total_orders'] ?> orders</span></td>
                    <td>
                        <span class="badge <?= $isCustActive ? 'badge-success' : 'badge-muted' ?>"><?= e($custStatusLabel) ?></span>
                        <?php if (!empty($c['cod_blocked'])): ?>
                            <br><span class="badge badge-danger" style="margin-top:4px;font-size:11px;">COD Blocked (<?= (int)$c['cod_fail_count'] ?> fails)</span>
                        <?php elseif ((int)($c['cod_fail_count'] ?? 0) > 0): ?>
                            <br><span class="badge badge-warning" style="margin-top:4px;font-size:11px;"><?= (int)$c['cod_fail_count'] ?> COD fail(s)</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.82rem; color:var(--muted);"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                    <td>
                        <div class="user-actions-group">
                            <button type="button" class="btn btn-secondary btn-sm-action" title="View Customer Details" aria-label="View Customer Details" data-user-detail="<?= e(json_encode($custDetailPayload)) ?>" onclick="openUserDetailModal(this)">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm-action" title="Edit Customer Details" aria-label="Edit Customer Details" data-cust-edit="<?= e(json_encode($custEditPayload)) ?>" onclick="openCustomerEditModal(this)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if (!empty($c['cod_blocked']) || (int)($c['cod_fail_count'] ?? 0) > 0): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset_cod">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm-action" title="Reset COD Block & Fail Count" data-confirm="Reset COD fail count and lift COD block for <?= e($c['full_name']) ?>?">
                                        <i class="bi bi-shield-check"></i> Unblock COD
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($isCustArchived): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="restore_customer">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm-action" title="Restore Customer" data-confirm="Restore and activate customer account for <?= e($c['full_name']) ?>?">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                    </button>
                                </form>
                            <?php else: ?>
                                <?php if ($isCustActive): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate_customer">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm-action" title="Deactivate Customer" data-confirm="Deactivate customer account for <?= e($c['full_name']) ?>?">
                                            <i class="bi bi-pause-circle"></i> Deactivate
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate_customer">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm-action" title="Activate Customer" data-confirm="Activate customer account for <?= e($c['full_name']) ?>?">
                                            <i class="bi bi-check-circle"></i> Activate
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="archive_customer">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm-action" title="Archive Customer" data-confirm="Archive customer account for <?= e($c['full_name']) ?>? Historical orders and receipts will be preserved.">
                                        <i class="bi bi-archive"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<!-- View User Details Modal -->
<div class="user-modal-backdrop" id="userDetailModal" role="dialog" aria-modal="true" aria-labelledby="userDetailModalTitle">
    <div class="user-modal-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <h3 id="userDetailModalTitle" style="margin:0;font-size:20px;">Account Details</h3>
            <button type="button" class="btn btn-secondary btn-sm-action" onclick="closeUserModal('userDetailModal')">Close</button>
        </div>
        <div class="account-profile"><span id="accountProfileAvatar" class="account-avatar" aria-hidden="true"></span><div><h4 id="accountProfileName"></h4><p id="accountProfileType"></p></div></div>
        <dl class="user-detail-grid" id="userDetailList"></dl>
    </div>
</div>

<!-- Edit Online Customer Modal -->
<div class="user-modal-backdrop" id="customerEditModal" role="dialog" aria-modal="true" aria-labelledby="customerEditModalTitle">
    <div class="user-modal-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;">
            <h3 id="customerEditModalTitle" style="margin:0;font-size:20px;">Edit Customer Account</h3>
            <button type="button" class="btn btn-secondary btn-sm-action" onclick="closeUserModal('customerEditModal')">Cancel</button>
        </div>
        <form class="form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_customer">
            <input type="hidden" name="id" id="editCustId">
            <div class="field">
                <label>Full Name</label>
                <input type="text" name="full_name" id="editCustName" maxlength="100" required>
            </div>
            <div class="field">
                <label>Email Address (Verified Identity)</label>
                <input type="email" id="editCustEmail" disabled>
            </div>
            <div class="field">
                <label>Mobile Number (09...)</label>
                <input type="tel" name="phone" id="editCustPhone" maxlength="11" pattern="09[0-9]{9}" placeholder="09171234567">
            </div>
            <div class="field">
                <label>Default Delivery Address (Customer-managed, read-only)</label>
                <input type="text" id="editCustAddress" maxlength="500" readonly disabled title="Customers update their own delivery address during checkout.">
            </div>
            <div class="field">
                <label>Account Status</label>
                <select name="status" id="editCustStatus">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
                <button type="button" class="btn btn-secondary" onclick="closeUserModal('customerEditModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUserDetailModal(btn) {
    var raw = btn ? btn.getAttribute('data-user-detail') : '';
    if (!raw) return;
    try {
        var data = JSON.parse(raw);
        var dl = document.getElementById('userDetailList');
        if (!dl) return;
        document.getElementById('accountProfileName').textContent = data.full_name || 'Account';
        document.getElementById('accountProfileAvatar').textContent = (data.full_name || 'A').trim().charAt(0).toUpperCase();
        document.getElementById('accountProfileType').textContent = (data.type || 'Account') + ' · #' + (data.id || '—');
        document.getElementById('userDetailModalTitle').textContent = data.type === 'Staff Account' ? 'Staff Account Details' : 'Customer Account Details';
        var rows = [
            ['Username', data.username || '—'],
            ['Role', data.role || '—'],
            ['Status', data.status || '—'],
            ['Email', data.email || '—'],
            ['Mobile Phone', data.phone || '—'],
            ['Address', data.address || '—'],
            ['Order History', data.orders || '—'],
            ['Created / Registered', data.created_at || '—'],
            ['Archive State', data.archived_at || '—']
        ];
        dl.innerHTML = rows.map(function (r) {
            var d = document.createElement('div');
            d.textContent = r[1];
            return '<div class="account-detail-row"><dt>' + r[0] + '</dt><dd>' + d.innerHTML + '</dd></div>';
        }).join('');
        document.getElementById('userDetailModal').classList.add('show');
    } catch (_) {}
}

function openCustomerEditModal(btn) {
    var raw = btn ? btn.getAttribute('data-cust-edit') : '';
    if (!raw) return;
    try {
        var data = JSON.parse(raw);
        document.getElementById('editCustId').value = data.id || '';
        document.getElementById('editCustName').value = data.full_name || '';
        document.getElementById('editCustEmail').value = data.email || '';
        document.getElementById('editCustPhone').value = data.phone || '';
        document.getElementById('editCustAddress').value = data.customer_address || '';
        document.getElementById('editCustStatus').value = data.status === 'Inactive' ? 'Inactive' : 'Active';
        document.getElementById('customerEditModal').classList.add('show');
    } catch (_) {}
}

function closeUserModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
</script>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
