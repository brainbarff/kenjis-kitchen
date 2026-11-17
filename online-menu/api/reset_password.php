<?php
/**
 * Kenji's Kitchen – Customer Reset Password
 * POST /modules/online/api/reset_password.php
 * Body JSON: { "token": "...", "password": "...", "confirm_password": "..." }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once $root . '/config/db.php'; // $conn (PDO)

// ── Helpers ───────────────────────────────────────────────────────────────────
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

// ── Parse body ────────────────────────────────────────────────────────────────
$body           = json_decode(file_get_contents('php://input'), true);
$token          = trim((string)($body['token']           ?? ''));
$password       = (string)($body['password']              ?? '');
$confirmPassword = (string)($body['confirm_password']    ?? '');

// ── Input validation ──────────────────────────────────────────────────────────
if ($token === '') {
    json_out(['status' => 'error',
              'message' => 'Reset link is invalid or expired. Please request a new one.',
              'expired'  => true], 422);
}

if (strlen($password) < 8) {
    json_out(['status' => 'error',
              'message' => 'Password must be at least 8 characters.'], 422);
}

if ($password !== $confirmPassword) {
    json_out(['status' => 'error',
              'message' => 'Passwords do not match.'], 422);
}

// ── Validate token ────────────────────────────────────────────────────────────
$tokenHash = hash('sha256', $token);

$stmt = $conn->prepare(
    'SELECT `id`, `email`
     FROM `customer_password_resets`
     WHERE `token_hash` = :hash
       AND `used_at` IS NULL
       AND `expires_at` > NOW()
     LIMIT 1'
);
$stmt->execute([':hash' => $tokenHash]);
$reset = $stmt->fetch();

if (!$reset) {
    json_out(['status' => 'error',
              'message' => 'This reset link is invalid or has expired. Please request a new one.',
              'expired'  => true], 422);
}

// ── Update password ───────────────────────────────────────────────────────────
$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmtUpdate = $conn->prepare(
    "UPDATE `users` SET `password` = :pw WHERE `email` = :email AND `role_id` = (SELECT id FROM roles WHERE role_name = 'Customer (Online)' LIMIT 1)"
);
$stmtUpdate->execute([':pw' => $hashed, ':email' => $reset['email']]);

if ($stmtUpdate->rowCount() === 0) {
    // Account may have been deleted between token issue and now
    json_out(['status' => 'error',
              'message' => 'Account not found. Please contact support.'], 422);
}

// ── Mark token as used ────────────────────────────────────────────────────────
$conn->prepare(
    'UPDATE `customer_password_resets` SET `used_at` = NOW() WHERE `id` = :id'
)->execute([':id' => $reset['id']]);

json_out(['status' => 'success',
          'message' => 'Your password has been reset successfully. You can now sign in.']);
