<?php
require_once dirname(__DIR__, 1) . '/config/config.php';
require_once dirname(__DIR__, 1) . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password - Kenji's Kitchen</title>
  <link rel="icon" type="image/png" href="../assets/img/logo.png">
  <link rel="stylesheet" href="../assets/css/ol.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/ol.css') ?: '20261118' ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    .fp-shell {
      max-width: 520px;
      margin: 36px auto 64px;
      padding: 0 18px;
    }
    .fp-card {
      background: var(--white, #ffffff);
      border: 1px solid var(--border, #e5e7eb);
      border-radius: 18px;
      padding: 32px 28px;
      box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
    }
    body.dark-mode .fp-card {
      background: #181c24;
      border-color: rgba(255, 255, 255, 0.1);
      color: #f3f4f6;
    }
    .fp-header {
      text-align: center;
      margin-bottom: 24px;
    }
    .fp-icon {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: rgba(217, 83, 79, 0.12);
      color: #d9534f;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.55rem;
      margin-bottom: 12px;
    }
    .fp-title {
      font-size: 1.5rem;
      font-weight: 800;
      margin: 0 0 6px;
      color: var(--dark, #111827);
    }
    body.dark-mode .fp-title {
      color: #f9fafb;
    }
    .fp-sub {
      font-size: 0.9rem;
      color: var(--muted, #6b7280);
      margin: 0;
      line-height: 1.5;
    }
    .fp-field {
      margin-bottom: 16px;
    }
    .fp-label {
      display: block;
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted, #6b7280);
      margin-bottom: 6px;
    }
    .fp-input {
      width: 100%;
      padding: 12px 14px;
      border-radius: 10px;
      border: 1.5px solid var(--border, #d1d5db);
      background: var(--white, #ffffff);
      color: var(--dark, #111827);
      font-size: 0.95rem;
      transition: border-color 0.2s;
      box-sizing: border-box;
    }
    body.dark-mode .fp-input {
      background: #12151c;
      border-color: rgba(255, 255, 255, 0.15);
      color: #f9fafb;
    }
    .fp-input:focus {
      outline: none;
      border-color: var(--primary, #f2c12e);
    }
    .fp-btn {
      width: 100%;
      padding: 13px 18px;
      border-radius: 10px;
      border: none;
      background: #d9534f;
      color: #ffffff;
      font-weight: 800;
      font-size: 0.95rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: opacity 0.15s;
    }
    .fp-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    .fp-btn-secondary {
      background: transparent;
      border: 1px solid var(--border, #d1d5db);
      color: var(--dark, #111827);
      margin-top: 10px;
    }
    body.dark-mode .fp-btn-secondary {
      border-color: rgba(255, 255, 255, 0.16);
      color: #f3f4f6;
    }
    .fp-otp-input {
      font-size: 1.35rem;
      font-weight: 800;
      letter-spacing: 0.35em;
      text-align: center;
    }
    .fp-meta-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.84rem;
      color: var(--muted, #6b7280);
      margin: 10px 0 18px;
      flex-wrap: wrap;
      gap: 8px;
    }
    .fp-link-btn {
      background: none;
      border: none;
      color: #d9534f;
      font-weight: 700;
      cursor: pointer;
      padding: 0;
      font-size: 0.84rem;
    }
    .fp-link-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .fp-nav {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 18px;
    }
    .fp-nav-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 14px;
      border-radius: 10px;
      border: 1px solid var(--border, #e5e7eb);
      background: var(--white, #ffffff);
      color: var(--dark, #111827);
      font-weight: 700;
      font-size: 0.88rem;
      text-decoration: none;
    }
    body.dark-mode .fp-nav-link {
      background: #1f2430;
      border-color: rgba(255, 255, 255, 0.12);
      color: #f9fafb;
    }
    .fp-note {
      margin-top: 18px;
      padding: 12px 14px;
      border-radius: 10px;
      background: rgba(242, 193, 46, 0.1);
      border: 1px solid rgba(242, 193, 46, 0.35);
      font-size: 0.82rem;
      color: var(--muted, #6b7280);
      line-height: 1.5;
    }
    @media (max-width: 600px) {
      .fp-card { padding: 22px 18px; }
      .navbar-top { flex-wrap: wrap; gap: 10px; }
      .nav-actions { flex-wrap: wrap; gap: 8px; }
      .fp-nav-link { padding: 8px 10px; font-size: 0.82rem; white-space: nowrap; }
    }
  </style>
</head>
<body>
  <script>
    (function () {
      try {
        if (localStorage.getItem('kk_theme') === 'dark') {
          document.body.classList.add('dark-mode');
        }
      } catch (_) {}
    })();
  </script>

  <header class="navbar">
    <div class="navbar-top">
      <a href="index.php" class="brand-wrapper">
        <img src="../assets/img/logo.png" alt="Kenji's Kitchen Logo" class="brand-icon" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; flex-shrink: 0;">
        <div class="brand-info">
          <h1>Kenji's Kitchen</h1>
          <span>Online Ordering · Password Recovery</span>
        </div>
      </a>
      <div class="nav-actions">
        <a href="index.php" class="fp-nav-link"><i class="bi bi-arrow-left"></i> Storefront</a>
      </div>
    </div>
  </header>

  <main class="fp-shell">
    <div class="fp-nav">
      <a href="index.php" class="fp-nav-link"><i class="bi bi-arrow-left"></i> Back to Storefront</a>
      <a href="../login.php?mode=customer" class="fp-nav-link"><i class="bi bi-box-arrow-in-right"></i> Customer Sign In</a>
    </div>

    <article class="fp-card">
      <div class="fp-header">
        <div class="fp-icon"><i class="bi bi-shield-lock-fill"></i></div>
        <h1 class="fp-title">Customer Password Recovery</h1>
        <p class="fp-sub" id="fpSubtitle">Enter your registered customer email address to receive a 6-digit password reset verification code.</p>
      </div>

      <!-- STEP 1: Request OTP -->
      <form id="fpStep1Form" onsubmit="handleRequestResetCode(event)">
        <div class="fp-field">
          <label class="fp-label" for="fpEmailInput">Registered Email Address *</label>
          <input type="email" id="fpEmailInput" class="fp-input" placeholder="you@example.com" required autocomplete="email">
        </div>
        <button type="submit" id="btnSendResetCode" class="fp-btn">
          <i class="bi bi-send-fill"></i> Send 6-Digit Reset Code
        </button>
      </form>

      <!-- STEP 2: Verify OTP & Set New Password -->
      <form id="fpStep2Form" style="display:none;" onsubmit="handleResetPassword(event)">
        <div class="fp-field">
          <label class="fp-label" for="fpOtpInput">6-Digit Verification Code *</label>
          <input type="text" id="fpOtpInput" class="fp-input fp-otp-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" required autocomplete="one-time-code">
        </div>

        <div class="fp-meta-row">
          <span>Code expires in <strong id="fpTimerText">5:00</strong></span>
          <button type="button" id="btnResendResetCode" class="fp-link-btn" onclick="handleResendResetCode()" disabled>Resend Code (60s)</button>
        </div>

        <div class="fp-field">
          <label class="fp-label" for="fpNewPassword">New Password *</label>
          <input type="password" id="fpNewPassword" class="fp-input" minlength="6" maxlength="72" placeholder="At least 6 characters" required autocomplete="new-password">
        </div>

        <div class="fp-field">
          <label class="fp-label" for="fpConfirmPassword">Confirm New Password *</label>
          <input type="password" id="fpConfirmPassword" class="fp-input" minlength="6" maxlength="72" placeholder="Re-enter your new password" required autocomplete="new-password">
        </div>

        <button type="submit" id="btnCompleteReset" class="fp-btn">
          <i class="bi bi-check-circle-fill"></i> Reset Password
        </button>
        <button type="button" class="fp-btn fp-btn-secondary" onclick="showStep(1)">
          <i class="bi bi-arrow-left"></i> Use a Different Email
        </button>
      </form>

      <!-- STEP 3: Completed -->
      <div id="fpStep3Done" style="display:none;text-align:center;">
        <div style="font-size:2.6rem;color:#22c55e;margin-bottom:10px;"><i class="bi bi-check-circle-fill"></i></div>
        <h2 style="margin:0 0 8px;font-size:1.25rem;">Password Reset Complete</h2>
        <p style="font-size:0.92rem;color:var(--muted,#6b7280);margin:0 0 20px;">Your online customer password has been updated. You can now sign in with your new password.</p>
        <a href="index.php" class="fp-btn" style="text-decoration:none;box-sizing:border-box;">
          <i class="bi bi-shop"></i> Return to Storefront &amp; Sign In
        </a>
        <a href="../login.php?mode=customer" class="fp-btn fp-btn-secondary" style="text-decoration:none;box-sizing:border-box;">
          <i class="bi bi-box-arrow-in-right"></i> Go to Dedicated Login Page
        </a>
      </div>

      <div class="fp-note">
        <strong>Note:</strong> Password recovery on this page applies only to active <strong>Online Customer</strong> accounts. Staff accounts are managed by the system administrator under User Management.
      </div>
    </article>
  </main>

  <script>
    let fpCurrentEmail = '';
    let fpExpiryTimer = null;
    let fpCooldownTimer = null;

    function kkNotify(message, type = 'info') {
      let container = document.getElementById('kkToastContainer');
      if (!container) {
        container = document.createElement('div');
        container.id = 'kkToastContainer';
        container.className = 'kk-toast-container';
        document.body.appendChild(container);
      }
      const icons = {
        success: 'bi-check-circle-fill',
        error: 'bi-exclamation-octagon-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill'
      };
      const toast = document.createElement('div');
      toast.className = 'kk-toast kk-toast-' + (icons[type] ? type : 'info');
      toast.innerHTML =
        '<i class="bi ' + (icons[type] || icons.info) + ' kk-toast-icon"></i>' +
        '<div class="kk-toast-body"></div>' +
        '<button type="button" class="kk-toast-close" aria-label="Dismiss">&times;</button>';
      toast.querySelector('.kk-toast-body').textContent = String(message || '');
      const removeToast = () => {
        toast.classList.add('kk-toast-Closing');
        setTimeout(() => toast.remove(), 220);
      };
      toast.querySelector('.kk-toast-close').addEventListener('click', removeToast);
      container.appendChild(toast);
      setTimeout(removeToast, 4500);
    }

    function showStep(step) {
      document.getElementById('fpStep1Form').style.display = step === 1 ? 'block' : 'none';
      document.getElementById('fpStep2Form').style.display = step === 2 ? 'block' : 'none';
      document.getElementById('fpStep3Done').style.display = step === 3 ? 'block' : 'none';
      const sub = document.getElementById('fpSubtitle');
      if (step === 1) {
        sub.textContent = 'Enter your registered customer email address to receive a 6-digit password reset verification code.';
      } else if (step === 2) {
        sub.textContent = 'Enter the 6-digit verification code sent to ' + fpCurrentEmail + ' and choose your new password.';
      } else {
        sub.textContent = 'Your password has been securely updated.';
      }
    }

    function startResetTimers() {
      clearInterval(fpExpiryTimer);
      clearInterval(fpCooldownTimer);

      let secondsLeft = 300;
      const timerEl = document.getElementById('fpTimerText');
      fpExpiryTimer = setInterval(() => {
        secondsLeft--;
        if (secondsLeft <= 0) {
          clearInterval(fpExpiryTimer);
          timerEl.textContent = 'Expired';
          return;
        }
        const m = Math.floor(secondsLeft / 60);
        const s = String(secondsLeft % 60).padStart(2, '0');
        timerEl.textContent = m + ':' + s;
      }, 1000);

      let cooldown = 60;
      const resendBtn = document.getElementById('btnResendResetCode');
      resendBtn.disabled = true;
      resendBtn.textContent = 'Resend Code (' + cooldown + 's)';
      fpCooldownTimer = setInterval(() => {
        cooldown--;
        if (cooldown <= 0) {
          clearInterval(fpCooldownTimer);
          resendBtn.disabled = false;
          resendBtn.textContent = 'Resend Code';
          return;
        }
        resendBtn.textContent = 'Resend Code (' + cooldown + 's)';
      }, 1000);
    }

    async function handleRequestResetCode(event) {
      if (event) event.preventDefault();
      const emailInput = document.getElementById('fpEmailInput');
      const email = emailInput.value.trim().toLowerCase();
      if (!email) {
        kkNotify('Please enter your registered email address.', 'warning');
        emailInput.focus();
        return;
      }

      const btn = document.getElementById('btnSendResetCode');
      btn.disabled = true;
      const origHtml = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending Reset Code…';

      try {
        const res = await fetch('./api/auth.php?action=forgot_password_send', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email })
        });
        const data = await res.json();
        if (res.ok && data.ok) {
          fpCurrentEmail = email;
          showStep(2);
          startResetTimers();
          kkNotify(data.message || 'Verification code sent if the account is active.', 'success');
          document.getElementById('fpOtpInput').focus();
        } else {
          kkNotify(data.message || 'Could not send reset code. Please try again.', 'error');
        }
      } catch (_) {
        kkNotify('Network error. Please check your connection and try again.', 'error');
      } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
      }
    }

    async function handleResendResetCode() {
      if (!fpCurrentEmail) return;
      document.getElementById('fpEmailInput').value = fpCurrentEmail;
      await handleRequestResetCode(null);
    }

    async function handleResetPassword(event) {
      event.preventDefault();
      const code = document.getElementById('fpOtpInput').value.trim();
      const newPassword = document.getElementById('fpNewPassword').value;
      const confirmPassword = document.getElementById('fpConfirmPassword').value;

      if (!/^[0-9]{6}$/.test(code)) {
        kkNotify('Please enter the 6-digit verification code.', 'warning');
        document.getElementById('fpOtpInput').focus();
        return;
      }
      if (newPassword.length < 6 || newPassword.length > 72) {
        kkNotify('New password must be between 6 and 72 characters.', 'warning');
        document.getElementById('fpNewPassword').focus();
        return;
      }
      if (newPassword !== confirmPassword) {
        kkNotify('New password and confirm password do not match.', 'warning');
        document.getElementById('fpConfirmPassword').focus();
        return;
      }

      const btn = document.getElementById('btnCompleteReset');
      btn.disabled = true;
      const origHtml = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Resetting Password…';

      try {
        const res = await fetch('./api/auth.php?action=forgot_password_reset', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            email: fpCurrentEmail,
            code: code,
            new_password: newPassword,
            confirm_password: confirmPassword
          })
        });
        const data = await res.json();
        if (res.ok && data.ok) {
          clearInterval(fpExpiryTimer);
          clearInterval(fpCooldownTimer);
          localStorage.removeItem('kk_customer_session');
          showStep(3);
          kkNotify(data.message || 'Password reset successfully.', 'success');
        } else {
          kkNotify(data.message || 'Unable to reset password. Please check your code.', 'error');
        }
      } catch (_) {
        kkNotify('Network error. Please check your connection and try again.', 'error');
      } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
      }
    }
  </script>
</body>
</html>
