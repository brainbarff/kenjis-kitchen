<?php
/**
 * Kenji's Kitchen - Admin Email Diagnostics & Test Route
 * 
 * Access:
 * - Admin-only web interface (requires active admin session)
 * - Safe test dispatch that tests the active mail bridge and displays friendly feedback
 * - Never prints or leaks passwords/credentials
 */

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/mail_helper.php';

require_role('admin');

$feedback = null;
$recipient = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $feedback = [
            'type' => 'danger',
            'title' => 'Security Token Invalid',
            'message' => 'Please reload the page and try again.'
        ];
    } else {
        $recipient = trim($_POST['test_email'] ?? '');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $feedback = [
                'type' => 'danger',
                'title' => 'Invalid Email Address',
                'message' => 'Please provide a valid destination email address.'
            ];
        } else {
            $subject = "Kenji's Kitchen - Admin SMTP Test";
            $text = "This is a test email sent from the Kenji's Kitchen Admin panel to confirm Gmail SMTP integration.\nTimestamp: " . date('Y-m-d H:i:s');
            $html = '<div style="font-family:Arial,sans-serif;max-width:500px;padding:20px;border-top:4px solid #F2C12E;">' .
                    '<h2>Kenji\'s Kitchen</h2><p>This is a test email sent from the Admin Diagnostics panel to verify SMTP email delivery.</p>' .
                    '<p style="color:#10B981;font-weight:bold;">✔ Gmail SMTP is active and operating.</p>' .
                    '<p style="font-size:12px;color:#888;">Timestamp: ' . date('Y-m-d H:i:s') . '</p></div>';

            $res = send_app_email($recipient, $subject, $text, $html);
            if ($res['ok']) {
                $feedback = [
                    'type' => 'success',
                    'title' => 'Email Sent Successfully!',
                    'message' => 'A test email was dispatched to ' . htmlspecialchars($recipient) . '. Please check the inbox.'
                ];
            } else {
                $feedback = [
                    'type' => 'danger',
                    'title' => 'Email Delivery Failed',
                    'message' => htmlspecialchars($res['message'])
                ];
            }
        }
    }
}

$page_title = "Email Diagnostics - Kenji's Kitchen";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $page_title ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f1117; color: #f3f4f6; margin: 0; padding: 30px; }
    .card { background: #1a1d27; border: 1px solid #2a2d3a; border-radius: 14px; max-width: 600px; margin: 0 auto; padding: 28px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    h1 { font-size: 1.4rem; color: #F2C12E; margin-top: 0; }
    .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; line-height: 1.5; }
    .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10B981; color: #34d399; }
    .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid #EF4444; color: #f87171; }
    label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.88rem; color: #d1d5db; }
    input[type="email"] { width: 100%; box-sizing: border-box; padding: 12px 14px; background: #11131a; border: 1.5px solid #2a2d3a; border-radius: 8px; color: #fff; font-size: 0.95rem; margin-bottom: 16px; }
    input[type="email"]:focus { outline: none; border-color: #F2C12E; }
    button { background: #F2C12E; color: #111; font-weight: 800; border: none; padding: 12px 24px; border-radius: 8px; font-size: 0.95rem; cursor: pointer; }
    button:hover { background: #e0b020; }
    .back-link { display: inline-block; margin-top: 18px; color: #9ca3af; text-decoration: underline; font-size: 0.85rem; }
    .back-link:hover { color: #F2C12E; }
  </style>
</head>
<body>
  <div class="card">
    <h1>✉ Outgoing Mail Diagnostics (Admin)</h1>
    <p style="color: #9ca3af; font-size: 0.88rem; line-height: 1.5; margin-bottom: 20px;">
      Use this tool to test the Gmail SMTP configuration loaded from <code>.env</code>. This tests the exact mail pipeline used by Order Receipts, Customer OTPs, and Password Reset links.
    </p>

    <?php if ($feedback): ?>
      <div class="alert alert-<?= $feedback['type'] ?>">
        <strong><?= $feedback['title'] ?>:</strong> <?= $feedback['message'] ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>
      <label for="test_email">Recipient Email Address:</label>
      <input type="email" id="test_email" name="test_email" value="<?= htmlspecialchars($recipient) ?>" placeholder="e.g. yourname@gmail.com" required>
      <button type="submit">Send Test Email</button>
    </form>

    <a href="<?= BASE_URL ?>/pages/dashboard.php" class="back-link">← Return to Admin Dashboard</a>
  </div>
</body>
</html>
