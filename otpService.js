'use strict';

/**
 * Kenji's Kitchen - OTP Generation, Email Delivery, & Verification Module
 *
 * Features:
 * - Cryptographically random 6-digit OTP generation
 * - 5-minute Time-To-Live (TTL) expiration window
 * - Anti-brute-force rate limiting: maximum 5 attempts per OTP code
 * - Resend cooldown protection (60 seconds)
 * - Nodemailer integration with branded Kenji's Kitchen HTML template
 */

const crypto = require('crypto');

// In-memory OTP token store (Key: lowercase email)
// Value structure: { code: '123456', expiresAt: timestamp, attempts: 0, lastSentAt: timestamp, isVerified: false }
const otpStore = new Map();

const OTP_TTL_MS = Number(process.env.OTP_TTL_MINUTES || 5) * 60 * 1000;
const MAX_ATTEMPTS = Number(process.env.OTP_MAX_ATTEMPTS || 5);
const COOLDOWN_MS = Number(process.env.OTP_RESEND_COOLDOWN_SECONDS || 60) * 1000;

let nodemailer = null;
try {
  nodemailer = require('nodemailer');
} catch (_) {
  // If nodemailer npm package is not installed yet in the environment, fallback gracefully
}

/**
 * Configure Nodemailer transport
 */
function getTransporter() {
  const user = process.env.EMAIL_USER || '';
  const pass = (process.env.EMAIL_PASS || '').replace(/\s/g, '');
  const host = (process.env.SMTP_HOST || '').trim();

  if (!nodemailer || !user || !pass || /your_.*password|placeholder|change.?me/i.test(pass)) {
    return null;
  }

  const transport = host
    ? {
        host,
        port: Number(process.env.SMTP_PORT || 587),
        secure: /^(1|true|yes)$/i.test(process.env.SMTP_SECURE || 'false')
      }
    : { service: process.env.EMAIL_SERVICE || 'gmail' };

  return nodemailer.createTransport({
    ...transport,
    auth: { user, pass },
    connectionTimeout: 10000,
    greetingTimeout: 10000,
    socketTimeout: 20000
  });
}

/**
 * Generate a cryptographically secure 6-digit OTP code
 */
function generateOtpCode() {
  const num = crypto.randomInt(100000, 1000000);
  return String(num);
}

/**
 * Construct branded HTML email template
 */
function renderEmailTemplate(code) {
  return `
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>Your Kenji's Kitchen Verification Code</title>
      <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f7f7f7; margin: 0; padding: 24px; color: #232323; }
        .card { max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 14px; padding: 32px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 6px solid #F2C12E; }
        .logo { font-size: 24px; font-weight: 800; color: #232323; margin-bottom: 20px; text-align: center; }
        .logo span { color: #E0B028; }
        .otp-box { background: #FFF9E6; border: 2px dashed #F2C12E; border-radius: 10px; padding: 18px; text-align: center; margin: 24px 0; }
        .otp-code { font-size: 32px; font-weight: 900; letter-spacing: 8px; color: #232323; font-family: monospace; }
        .badge { display: inline-block; background: #232323; color: #ffffff; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; margin-top: 6px; text-transform: uppercase; }
        .note { font-size: 13px; color: #777777; line-height: 1.5; margin-top: 16px; }
        .footer { font-size: 11px; color: #999999; text-align: center; margin-top: 24px; border-top: 1px solid #eeeeee; padding-top: 16px; }
      </style>
    </head>
    <body>
      <div class="card">
        <div class="logo">Kenji's <span>Kitchen</span></div>
        <p style="font-size: 15px; margin: 0 0 10px 0;">Hello,</p>
        <p style="font-size: 14px; line-height: 1.5; margin: 0 0 16px 0;">Use the 6-digit One-Time Password (OTP) below to authenticate your online order account.</p>

        <div class="otp-box">
          <div class="otp-code">${code}</div>
          <span class="badge">Valid for 5 minutes</span>
        </div>

        <p class="note">
          <strong>Security notice:</strong> For your protection, never share this code with anyone. Kenji's Kitchen staff will never ask for your verification code. This code will automatically expire in 5 minutes.
        </p>

        <div class="footer">
          &copy; ${new Date().getFullYear()} Kenji's Kitchen. All rights reserved.<br>
          Fairview, Quezon City, Philippines
        </div>
      </div>
    </body>
    </html>
  `;
}

/**
 * Send an OTP code to a customer email address
 *
 * @param {string} email - Destination email address
 * @returns {Promise<{ ok: boolean, message: string, cooldownRemaining?: number }>}
 */
async function sendOtp(email) {
  const normalized = String(email || '').trim().toLowerCase();
  if (!normalized || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized)) {
    return { ok: false, message: 'Please provide a valid email address.' };
  }

  const now = Date.now();
  const existing = otpStore.get(normalized);

  // Enforce resend cooldown
  if (existing && existing.lastSentAt && (now - existing.lastSentAt < COOLDOWN_MS)) {
    const remaining = Math.ceil((COOLDOWN_MS - (now - existing.lastSentAt)) / 1000);
    return {
      ok: false,
      message: `Please wait ${remaining} seconds before requesting a new code.`,
      cooldownRemaining: remaining
    };
  }

  const code = generateOtpCode();
  const expiresAt = now + OTP_TTL_MS;
  const transporter = getTransporter();
  if (!transporter) {
    return { ok: false, message: 'Email delivery is not configured. Please contact the store.' };
  }

  try {
    await sendEmailMessage({
      to: normalized,
      subject: `Your Kenji's Kitchen Verification Code: ${code}`,
      text: `Your Kenji's Kitchen verification code is: ${code}. It expires in 5 minutes.`,
      html: renderEmailTemplate(code)
    });

    otpStore.set(normalized, {
      code,
      expiresAt,
      attempts: 0,
      lastSentAt: now,
      isVerified: false
    });

    return { ok: true, message: 'Verification code sent to your email.' };
  } catch (err) {
    console.error('[OTP SERVICE ERROR] Failed to send email via Nodemailer:', err.message);
    return {
      ok: false,
      message: 'Unable to deliver verification email at this moment. Please verify your email or try again later.'
    };
  }
}

async function sendEmailMessage({ to, subject, text, html }) {
  const transporter = getTransporter();
  if (!transporter) throw new Error('SMTP email credentials are missing or invalid.');
  const sender = process.env.EMAIL_FROM || process.env.EMAIL_USER;
  return transporter.sendMail({ from: sender, to, subject, text, html: html || undefined });
}

/**
 * Verify a 6-digit OTP code against the store
 *
 * @param {string} email - Target email address
 * @param {string} code - 6-digit input string
 * @returns {{ ok: boolean, message: string, expired?: boolean, locked?: boolean }}
 */
function verifyOtp(email, code) {
  const normalized = String(email || '').trim().toLowerCase();
  const cleanCode = String(code || '').trim();

  if (!normalized || !cleanCode) {
    return { ok: false, message: 'Email and verification code are required.' };
  }

  const entry = otpStore.get(normalized);
  if (!entry) {
    return { ok: false, message: 'No verification request found for this email. Please request a new code.' };
  }

  const now = Date.now();

  // 1. Check expiration (5-minute TTL)
  if (now > entry.expiresAt) {
    otpStore.delete(normalized);
    return { ok: false, expired: true, message: 'The verification code has expired. Please request a new code.' };
  }

  // 2. Check brute-force attempts
  if (entry.attempts >= MAX_ATTEMPTS) {
    otpStore.delete(normalized);
    return {
      ok: false,
      locked: true,
      message: 'Too many incorrect attempts. This code has been invalidated for security. Please request a new code.'
    };
  }

  entry.attempts += 1;

  // 3. Constant-time code comparison to prevent timing attacks
  const bufferActual = Buffer.from(cleanCode);
  const bufferExpected = Buffer.from(entry.code);

  const isMatch = bufferActual.length === bufferExpected.length &&
                  crypto.timingSafeEqual(bufferActual, bufferExpected);

  if (!isMatch) {
    const attemptsLeft = MAX_ATTEMPTS - entry.attempts;
    return {
      ok: false,
      message: attemptsLeft > 0
        ? `Incorrect code. ${attemptsLeft} attempt(s) remaining.`
        : 'Incorrect code. Maximum attempts exceeded. Code has been invalidated.'
    };
  }

  // Success: mark verified and consume token
  entry.isVerified = true;
  otpStore.delete(normalized);

  return { ok: true, message: 'Verification successful.' };
}

// Garbage collection cleanup every 2 minutes
setInterval(() => {
  const now = Date.now();
  for (const [key, value] of otpStore.entries()) {
    if (now > value.expiresAt) {
      otpStore.delete(key);
    }
  }
}, 120000).unref();

module.exports = {
  sendOtp,
  sendEmailMessage,
  verifyOtp,
  renderEmailTemplate
};
