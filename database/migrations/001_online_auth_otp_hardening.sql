-- ============================================================================
-- Migration: 001_online_auth_otp_hardening.sql
-- Target DBMS: MariaDB 10.4 (`kenjis_kitchen`)
-- Purpose:
--   1. Delete any legacy 6-digit plaintext or expired rows in `otp_verifications`
--      without deleting active hashed OTP records if rerun.
--   2. Widen `otp_verifications.code` from CHAR(6) to VARCHAR(255) to store
--      password_hash() digests instead of plaintext codes.
--   3. Add `purpose` ('register' | 'signin') and pending registration columns
--      (`pending_full_name`, `pending_phone`, `pending_address`,
--       `pending_password_hash`) so registration data is validated and bound
--      server-side before an OTP email is sent.
--
-- Rollback Procedure (MariaDB 10.4):
--   USE `kenjis_kitchen`;
--   DELETE FROM `otp_verifications`;
--   ALTER TABLE `otp_verifications`
--       MODIFY COLUMN `code` CHAR(6) NOT NULL,
--       DROP COLUMN IF EXISTS `purpose`,
--       DROP COLUMN IF EXISTS `pending_full_name`,
--       DROP COLUMN IF EXISTS `pending_phone`,
--       DROP COLUMN IF EXISTS `pending_address`,
--       DROP COLUMN IF EXISTS `pending_password_hash`;
-- ============================================================================

USE `kenjis_kitchen`;

-- Clear any legacy plaintext 6-digit codes or expired codes.
-- Idempotent: preserves valid active hashed OTPs (length > 6, non-numeric) on rerun.
DELETE FROM `otp_verifications`
WHERE CHAR_LENGTH(`code`) <= 6
   OR `code` REGEXP '^[0-9]{6}$'
   OR `expires_at` < NOW();

ALTER TABLE `otp_verifications`
    MODIFY COLUMN `code` VARCHAR(255) NOT NULL,
    ADD COLUMN IF NOT EXISTS `purpose` ENUM('register', 'signin') NOT NULL DEFAULT 'signin' AFTER `code`,
    ADD COLUMN IF NOT EXISTS `pending_full_name` VARCHAR(100) DEFAULT NULL AFTER `purpose`,
    ADD COLUMN IF NOT EXISTS `pending_phone` VARCHAR(20) DEFAULT NULL AFTER `pending_full_name`,
    ADD COLUMN IF NOT EXISTS `pending_address` VARCHAR(500) DEFAULT NULL AFTER `pending_phone`,
    ADD COLUMN IF NOT EXISTS `pending_password_hash` VARCHAR(255) DEFAULT NULL AFTER `pending_address`;
