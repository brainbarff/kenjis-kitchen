<?php
/**
 * Kenji's Kitchen – Customer Forgot Password
 * POST /modules/online/api/forgot_password.php
 * Body JSON: { "email": "customer@example.com" }
 *
 * Rate limit: 5 requests per email+IP per hour
 * Always returns the same generic message (no account enumeration).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Bootstrap ────────────────────────────────────────────────────────────────
$root = dirname(__DIR__, 2); // kenjis-kitchen/
require_once $root . '/config/config.php';
require_once $root . '/config/db.php';          // $conn (PDO)
require_once __DIR__ . '/mail_helper.php';      // send_app_email()

// ── Constants ────────────────────────────────────────────────────────────────
const TOKEN_EXPIRY_MINUTES = 45;
const RATE_LIMIT_MAX       = 5;      // max requests
const RATE_LIMIT_WINDOW    = 3600;   // seconds (1 hour)

// ── Helpers ──────────────────────────────────────────────────────────────────
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function generic_ok(): void
{
    json_out(['status' => 'success',
              'message' => "If an account with that email exists, we've sent password reset instructions."]);
}

// ── Method guard ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

// ── Parse body ───────────────────────────────────────────────────────────────
$body  = json_decode(file_get_contents('php://input'), true);
$email = trim((string)($body['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['status' => 'error', 'message' => 'Please enter a valid email address.'], 422);
}

$email = strtolower($email);
$ip    = $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['HTTP_X_REAL_IP']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
// Take only the first IP if comma-separated
$ip = trim(explode(',', $ip)[0]);

// ── Rate limit ───────────────────────────────────────────────────────────────
$windowStart = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW);

$stmtCount = $conn->prepare(
    'SELECT COUNT(*) FROM `password_reset_attempts`
     WHERE (`email` = :email OR `ip_address` = :ip)
       AND `attempted_at` > :window'
);
$stmtCount->execute([':email' => $email, ':ip' => $ip, ':window' => $windowStart]);
$attempts = (int)$stmtCount->fetchColumn();

// Log this attempt
$conn->prepare(
    'INSERT INTO `password_reset_attempts` (`email`, `ip_address`) VALUES (:email, :ip)'
)->execute([':email' => $email, ':ip' => $ip]);

if ($attempts >= RATE_LIMIT_MAX) {
    // Still return generic OK – don't hint that they've been rate-limited
    generic_ok();
}

// ── Look up customer account ──────────────────────────────────────────────────
$stmtUser = $conn->prepare(
    "SELECT u.id, u.full_name, u.email 
     FROM users u 
     JOIN roles r ON r.id = u.role_id 
     WHERE u.email = :email AND r.role_name = 'Customer (Online)' AND u.status = 'Active' 
     LIMIT 1"
);
$stmtUser->execute([':email' => $email]);
$user = $stmtUser->fetch();

if (!$user) {
    // No account – still return generic OK (prevents enumeration)
    generic_ok();
}

// ── Invalidate any existing unused tokens for this email ─────────────────────
$conn->prepare(
    "UPDATE `customer_password_resets`
     SET `used_at` = NOW()
     WHERE `email` = :email AND `used_at` IS NULL"
)->execute([':email' => $email]);

// ── Generate token ────────────────────────────────────────────────────────────
$token     = bin2hex(random_bytes(32));        // 64-char hex string
$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY_MINUTES * 60);

$conn->prepare(
    'INSERT INTO `customer_password_resets` (`email`, `token_hash`, `expires_at`, `ip_address`)
     VALUES (:email, :hash, :expires, :ip)'
)->execute([
    ':email'   => $email,
    ':hash'    => $tokenHash,
    ':expires' => $expiresAt,
    ':ip'      => $ip,
]);

// ── Build reset URL ───────────────────────────────────────────────────────────
$proto    = kk_is_https() ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Determine base path relative to server root
$scriptDir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])); // /kenjis-kitchen/modules/online/api
$onlineDir  = dirname($scriptDir);                                        // /kenjis-kitchen/modules/online
$resetUrl   = $proto . '://' . $host . $onlineDir . '/reset-password.html?token=' . urlencode($token);

$name = htmlspecialchars($user['full_name'] ?? 'Customer', ENT_QUOTES);

// ── Send email ────────────────────────────────────────────────────────────────
$subject = "Reset your Kenji's Kitchen password";

$textBody = <<<TEXT
Hi {$name},

We received a request to reset the password for your Kenji's Kitchen account.

Click the link below to set a new password (valid for 45 minutes):

{$resetUrl}

If you did not request a password reset, you can safely ignore this email.
Your password will not change until you access the link above.

— Kenji's Kitchen
TEXT;

$htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#0f1117;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0f1117;padding:32px 0;">
    <tr><td align="center">
      <table width="460" cellpadding="0" cellspacing="0" style="background:#1a1d27;border-radius:16px;overflow:hidden;border:1px solid #2a2d3a;">
        <tr>
          <td style="background:#F2C12E;padding:20px 32px;text-align:center;">
            <span style="font-size:1.35rem;font-weight:900;color:#111;">🍳 Kenji's Kitchen</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px 32px 24px;color:#e5e7eb;">
            <p style="margin:0 0 12px;font-size:1rem;">Hi <strong>{$name}</strong>,</p>
            <p style="margin:0 0 20px;font-size:0.9rem;color:#9ca3af;line-height:1.6;">
              We received a request to reset the password for your Kenji's Kitchen account.<br>
              Click the button below to set a new password. This link is valid for <strong style="color:#F2C12E;">45 minutes</strong>.
            </p>
            <p style="text-align:center;margin:24px 0;">
              <a href="{$resetUrl}"
                 style="display:inline-block;background:#F2C12E;color:#111;font-weight:800;font-size:1rem;
                        padding:14px 32px;border-radius:10px;text-decoration:none;">
                Reset My Password
              </a>
            </p>
            <p style="margin:20px 0 0;font-size:0.8rem;color:#6b7280;line-height:1.5;">
              If you did not request this, you can safely ignore this email.<br>
              Your password will remain unchanged.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:16px 32px;border-top:1px solid #2a2d3a;text-align:center;">
            <span style="font-size:0.75rem;color:#4b5563;">© Kenji's Kitchen · Fairview</span>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

send_app_email($email, $subject, $textBody, $htmlBody);
// We ignore the mail result – always return generic OK
generic_ok();
