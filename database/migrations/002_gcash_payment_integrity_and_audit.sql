-- ============================================================================
-- Migration: 002_gcash_payment_integrity_and_audit.sql
-- Target DBMS: MariaDB 10.4 / MySQL 8.0+
-- Purpose:
--   1. Create `payment_reference_registry` to enforce single-use GCash payment
--      references across POS and Online checkouts via a unique key constraint.
--   2. Create `payment_verification_audits` to record an immutable, auditable
--      history of cashier attestations (POS) and manual payment verifications (Admin).
--   3. Idempotently backfill existing non-empty payment references into the registry:
--      - Unique historical references are registered with their respective
--        order_id, payment_id, and channel ('POS' or 'ONLINE').
--      - Duplicate historical references (e.g., '1234567890123') are registered
--        once with channel = 'LEGACY' and order_id/payment_id = NULL so the
--        reference is claimed without mutating or deleting historical records.
--
-- Rollback Procedure:
--   DROP TABLE IF EXISTS `payment_verification_audits`;
--   DROP TABLE IF EXISTS `payment_reference_registry`;
-- ============================================================================

-- 1. Create payment_reference_registry
CREATE TABLE IF NOT EXISTS `payment_reference_registry` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reference_type` VARCHAR(20) NOT NULL DEFAULT 'GCASH',
    `reference_no` VARCHAR(50) NOT NULL,
    `order_id` INT DEFAULT NULL,
    `payment_id` INT DEFAULT NULL,
    `channel` ENUM('POS', 'ONLINE', 'LEGACY') NOT NULL DEFAULT 'LEGACY',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_reference_type_no` (`reference_type`, `reference_no`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_payment_id` (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Create payment_verification_audits
CREATE TABLE IF NOT EXISTS `payment_verification_audits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `verified_by` INT DEFAULT NULL,
    `previous_status` VARCHAR(20) NOT NULL,
    `new_status` VARCHAR(20) NOT NULL,
    `verification_source` ENUM('POS_CASHIER', 'ADMIN_ONLINE') NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_payment_id` (`payment_id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_verified_by` (`verified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Idempotently backfill duplicate references as LEGACY (claimed without mutating payments)
INSERT IGNORE INTO `payment_reference_registry` (`reference_type`, `reference_no`, `order_id`, `payment_id`, `channel`, `created_at`)
SELECT
    'GCASH' AS `reference_type`,
    TRIM(p.`payment_reference`) AS `reference_no`,
    NULL AS `order_id`,
    NULL AS `payment_id`,
    'LEGACY' AS `channel`,
    MIN(p.`paid_at`) AS `created_at`
FROM `payments` p
WHERE p.`payment_reference` IS NOT NULL
  AND TRIM(p.`payment_reference`) != ''
GROUP BY TRIM(p.`payment_reference`)
HAVING COUNT(*) > 1;

-- 4. Idempotently backfill unique historical references with their matching order and payment references
INSERT IGNORE INTO `payment_reference_registry` (`reference_type`, `reference_no`, `order_id`, `payment_id`, `channel`, `created_at`)
SELECT
    'GCASH' AS `reference_type`,
    TRIM(p.`payment_reference`) AS `reference_no`,
    p.`order_id`,
    p.`id` AS `payment_id`,
    CASE
        WHEN o.`order_channel` = 'ONLINE' OR o.`order_type` = 'ONLINE' THEN 'ONLINE'
        ELSE 'POS'
    END AS `channel`,
    p.`paid_at` AS `created_at`
FROM `payments` p
JOIN `orders` o ON p.`order_id` = o.`id`
WHERE p.`payment_reference` IS NOT NULL
  AND TRIM(p.`payment_reference`) != ''
  AND TRIM(p.`payment_reference`) IN (
      SELECT `ref` FROM (
          SELECT TRIM(`payment_reference`) AS `ref`
          FROM `payments`
          WHERE `payment_reference` IS NOT NULL AND TRIM(`payment_reference`) != ''
          GROUP BY TRIM(`payment_reference`)
          HAVING COUNT(*) = 1
      ) AS `single_refs`
  );
