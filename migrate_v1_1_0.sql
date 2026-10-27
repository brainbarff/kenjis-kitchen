-- =============================================================
-- Kenji's Kitchen — DB Migration: v1.0 → v1.1.0
-- Run this ONLY if your existing kenjis_kitchen DB was created
-- from the original v2 kenjis_kitchen.sql (not from schema.sql).
-- Safe to re-run — uses IF NOT EXISTS / IGNORE checks.
-- =============================================================

USE kenjis_kitchen;

-- ------------------------------------------------------------
-- 1. categories — add sort_order
-- ------------------------------------------------------------
ALTER TABLE categories
  ADD COLUMN IF NOT EXISTS `sort_order` INT UNSIGNED NOT NULL DEFAULT 99;

-- Set deterministic sort orders for the 8 standard categories
UPDATE categories SET sort_order = 1 WHERE category_name = 'Add-Ons';
UPDATE categories SET sort_order = 2 WHERE category_name = 'Bilao Favorites';
UPDATE categories SET sort_order = 3 WHERE category_name = 'Drinks';
UPDATE categories SET sort_order = 4 WHERE category_name = 'Pulutan & Pares';
UPDATE categories SET sort_order = 5 WHERE category_name = 'Silog Meals';
UPDATE categories SET sort_order = 6 WHERE category_name = 'Silog Set Meals';
UPDATE categories SET sort_order = 7 WHERE category_name = 'Student Meals';

-- ------------------------------------------------------------
-- 2. menu_items — add missing columns
-- ------------------------------------------------------------
ALTER TABLE menu_items
  ADD COLUMN IF NOT EXISTS `sort_order`   INT UNSIGNED    NOT NULL DEFAULT 99,
  ADD COLUMN IF NOT EXISTS `is_archived`  TINYINT(1)      NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `stock`        INT UNSIGNED    NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `promo_price`  DECIMAL(10,2)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `promo_start`  DATE            DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `promo_end`    DATE            DEFAULT NULL;

-- Index for fast archived-item filtering
ALTER TABLE menu_items
  ADD INDEX IF NOT EXISTS `idx_menu_archived` (`is_archived`),
  ADD INDEX IF NOT EXISTS `idx_menu_category` (`category_id`, `is_archived`, `sort_order`);

-- ------------------------------------------------------------
-- 3. order_items — add notes column for item-level instructions
-- ------------------------------------------------------------
ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS `notes` VARCHAR(255) DEFAULT NULL;

-- ------------------------------------------------------------
-- 4. orders — add order_channel column
-- ------------------------------------------------------------
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS `order_channel` ENUM('POS','ONLINE') NOT NULL DEFAULT 'POS',
  ADD COLUMN IF NOT EXISTS `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `notes`         TEXT DEFAULT NULL;

-- ------------------------------------------------------------
-- 5. users — add soft-delete column
-- ------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS `customer_status`    ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  ADD COLUMN IF NOT EXISTS `registration_source` VARCHAR(50) DEFAULT 'Direct',
  ADD COLUMN IF NOT EXISTS `deleted_at`         DATETIME DEFAULT NULL;

-- ------------------------------------------------------------
-- 5b. receipts — add email receipt tracking columns
-- ------------------------------------------------------------
ALTER TABLE receipts
  ADD COLUMN IF NOT EXISTS `email_status`  ENUM('Pending','Sent','Failed') NOT NULL DEFAULT 'Pending',
  ADD COLUMN IF NOT EXISTS `email_sent_at` DATETIME DEFAULT NULL;

-- ------------------------------------------------------------
-- 6. Create otp_verifications table (for OTP service)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_verifications` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(255)     NOT NULL,
  `code`        CHAR(6)          NOT NULL,
  `expires_at`  DATETIME         NOT NULL,
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_sent_at` DATETIME        DEFAULT NULL,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_otp_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Create approval_requests table (v2 inventory approval flow)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `approval_requests` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `request_type`  ENUM('Stock In','Stock Out','Adjustment','Archive','Unarchive') NOT NULL,
  `menu_item_id`  INT UNSIGNED  NOT NULL,
  `quantity`      INT           DEFAULT NULL,
  `remarks`       TEXT          DEFAULT NULL,
  `status`        ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `requested_by`  INT UNSIGNED  NOT NULL,
  `reviewed_by`   INT UNSIGNED  DEFAULT NULL,
  `review_notes`  TEXT          DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at`   DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ar_item`      (`menu_item_id`),
  KEY `fk_ar_requester` (`requested_by`),
  KEY `fk_ar_reviewer`  (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Ensure "Drinks" category exists and seed drinks
-- ------------------------------------------------------------
INSERT IGNORE INTO categories (category_name, status, sort_order)
  VALUES ('Drinks', 'Active', 3);

SET @drinks_id = (SELECT id FROM categories WHERE category_name = 'Drinks' LIMIT 1);

INSERT IGNORE INTO menu_items
  (category_id, item_name, description, price, stock, availability, is_archived, sort_order)
VALUES
  (@drinks_id, 'Coca-Cola',    'Classic cola drink (330ml can)', 20.00, 50, 'Available', 0, 1),
  (@drinks_id, 'Sprite',       'Lemon-lime soda (330ml can)',    20.00, 50, 'Available', 0, 2),
  (@drinks_id, 'Royal',        'Orange soda (330ml can)',        20.00, 50, 'Available', 0, 3),
  (@drinks_id, 'Mountain Dew', 'Citrus soda (330ml can)',        20.00, 50, 'Available', 0, 4),
  (@drinks_id, 'Bottled Soda', 'Assorted bottled soda (500ml)',  20.00, 50, 'Available', 0, 5);

-- Done
SELECT 'Migration v1.1.0 applied successfully.' AS result;
