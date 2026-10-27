-- ============================================================================
-- Migration: 003_customer_email_verification.sql
-- Target DBMS: MariaDB 10.4 (`kenjis_kitchen`)
-- Purpose:
--   1. Add `email_verified_at` column to `users` table to track verified status.
--   2. Backfill existing active online customers so historical customer accounts
--      remain active and verified.
--
-- Rollback Procedure (MariaDB 10.4):
--   ALTER TABLE `users` DROP COLUMN IF EXISTS `email_verified_at`;
-- ============================================================================

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `email_verified_at` DATETIME NULL DEFAULT NULL AFTER `customer_status`;

-- Backfill existing active Customer (Online) accounts using their created_at timestamp
UPDATE `users`
SET `email_verified_at` = `created_at`
WHERE `role_id` = (SELECT `id` FROM `roles` WHERE `role_name` = 'Customer (Online)' LIMIT 1)
  AND `status` = 'Active'
  AND `customer_status` = 'Active'
  AND `deleted_at` IS NULL
  AND `email_verified_at` IS NULL;
