<?php
declare(strict_types=1);

/** Return the currently PIN-authenticated cashier (or the logged-in Admin automatically) after checking the account is still active. */
function pos_active_cashier(PDO $conn): array
{
    $sessionCashier = $_SESSION['pos_active_cashier'] ?? null;
    $id = (int)($sessionCashier['id'] ?? 0);
    $authenticatedAt = strtotime((string)($sessionCashier['authenticated_at'] ?? '')) ?: 0;

    if ($id > 0 && $authenticatedAt >= time() - 8 * 3600) {
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.full_name, r.role_name
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.status = 'Active' AND u.is_archived = 0
              AND u.deleted_at IS NULL AND r.role_name IN ('Admin', 'Cashier')
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && hash_equals((string)$user['username'], (string)($sessionCashier['username'] ?? ''))) {
            return $user;
        }
        unset($_SESSION['pos_active_cashier']);
    } else {
        unset($_SESSION['pos_active_cashier']);
    }

    // Admin users have full access to POS without needing to input a cashier username and PIN
    $loggedUserId = (int)($_SESSION['u_id'] ?? 0);
    if ($loggedUserId > 0) {
        $admStmt = $conn->prepare("
            SELECT u.id, u.username, u.full_name, r.role_name
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.status = 'Active' AND u.is_archived = 0
              AND u.deleted_at IS NULL AND r.role_name = 'Admin'
            LIMIT 1
        ");
        $admStmt->execute([$loggedUserId]);
        $adminUser = $admStmt->fetch(PDO::FETCH_ASSOC);
        if ($adminUser) {
            $_SESSION['pos_active_cashier'] = [
                'id' => (int)$adminUser['id'],
                'username' => $adminUser['username'],
                'full_name' => $adminUser['full_name'],
                'role' => $adminUser['role_name'],
                'authenticated_at' => date('Y-m-d H:i:s'),
            ];
            return $adminUser;
        }
    }

    throw new RuntimeException('Authenticate the cashier on this POS terminal before continuing.');
}
