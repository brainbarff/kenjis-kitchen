-- =====================================================================
-- KENJI'S KITCHEN - FRESH INSTALL DATABASE SCHEMA
-- Import on an empty database server. Existing installations require reviewed migrations.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `kenjis_kitchen` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kenjis_kitchen`;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Roles Table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_name` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Users Table (Supports Staff & Online Customers with Soft-Archiving)
CREATE TABLE IF NOT EXISTS `users` (
    `pin_code` VARCHAR(255) DEFAULT NULL,
    `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
    `is_archived` tinyint(1) NOT NULL DEFAULT 0,
    `cod_fail_count` int(11) NOT NULL DEFAULT 0,
    `cod_blocked` tinyint(1) NOT NULL DEFAULT 0,
    `last_login_at` datetime DEFAULT NULL,
    `last_ip` varchar(45) DEFAULT NULL,
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role_id` INT NOT NULL,
    `email` VARCHAR(150) NULL UNIQUE,
    `phone` VARCHAR(20) NULL,
    `customer_address` VARCHAR(500) NULL,
    `profile_photo` VARCHAR(255) NULL DEFAULT NULL,
    `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `customer_status` ENUM('Active','Inactive','Suspended','Pending Approval') NULL DEFAULT 'Active',
    `email_verified_at` DATETIME NULL DEFAULT NULL,
    `registration_source` VARCHAR(50) DEFAULT 'Direct',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store payment details shown during online checkout.
CREATE TABLE IF NOT EXISTS `store_payment_settings` (
    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `gcash_phone` VARCHAR(20) NOT NULL DEFAULT '09952071575',
    `gcash_qr_image` VARCHAR(255) NULL,
    `updated_by` INT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_store_payment_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `store_payment_settings` (`id`, `gcash_phone`)
VALUES (1, '09952071575')
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- 3. Categories Table
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(80) NOT NULL UNIQUE,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Menu Items Table (Operational Source of Truth: menu_items.stock)
CREATE TABLE IF NOT EXISTS `menu_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `item_name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `price` DECIMAL(10,2) NOT NULL,
    `pending_price` DECIMAL(10,2) NULL DEFAULT NULL,
    `pending_price_effective` DATETIME NULL DEFAULT NULL,
    `image` VARCHAR(255) NULL,
    `availability` ENUM('Available','Unavailable') NOT NULL DEFAULT 'Available',
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `stock` INT NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `promo_price` DECIMAL(10,2) NULL,
    `promo_start` DATE NULL,
    `promo_end` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail for immediate and scheduled base price changes.
CREATE TABLE IF NOT EXISTS `price_change_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `menu_item_id` INT NULL,
    `item_name` VARCHAR(100) NOT NULL,
    `old_price` DECIMAL(10,2) NOT NULL,
    `new_price` DECIMAL(10,2) NOT NULL,
    `adjustment_type` VARCHAR(40) NOT NULL,
    `status` ENUM('Scheduled','Immediate','Applied','Cancelled') NOT NULL,
    `effective_at` DATETIME NOT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_price_change_due` (`status`, `effective_at`),
    INDEX `idx_price_change_item` (`menu_item_id`),
    CONSTRAINT `fk_price_change_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_price_change_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Inventory Table (Ingredient Storage)
CREATE TABLE IF NOT EXISTS `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ingredient_name` VARCHAR(100) NOT NULL,
    `unit` VARCHAR(30) NOT NULL,
    `current_stock` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `low_stock` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `supplier` VARCHAR(100),
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Menu Ingredients Link Table
CREATE TABLE IF NOT EXISTS `menu_ingredients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `menu_item_id` INT NOT NULL,
    `inventory_id` INT NOT NULL,
    `qty_needed` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`inventory_id`) REFERENCES `inventory`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Inventory Logs (All Movement History)
CREATE TABLE IF NOT EXISTS `inventory_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `inventory_id` INT NULL,
    `menu_item_id` INT NULL,
    `user_id` INT NULL,
    `action` ENUM('Stock In','Stock Out','Adjustment','Order Deduction','Delivery','Waste/Spoilage') NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL,
    `remarks` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`inventory_id`) REFERENCES `inventory`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Approval Requests Table (Dual-Control Workflow: Inventory Staff -> Admin)
CREATE TABLE IF NOT EXISTS `approval_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_type` ENUM('Stock In','Stock Out','Adjustment','Archive','Unarchive','Add Category','Delete Category','Add Item','Delete Item') NOT NULL,
    `menu_item_id` INT NULL,
    `category_id` INT NULL,
    `quantity` INT NULL,
    `remarks` VARCHAR(255) NULL,
    `payload` LONGTEXT NULL,
    `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `requested_by` INT NOT NULL,
    `reviewed_by` INT NULL,
    `review_notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Orders Table
CREATE TABLE IF NOT EXISTS `orders` (
    `cashier_name` varchar(100) DEFAULT NULL,
    `shift_id` int(11) DEFAULT NULL,
    `landmark` varchar(255) DEFAULT NULL,
    `fulfillment_mode` enum('PICKUP','LAGRO','OUTSIDE') NOT NULL DEFAULT 'PICKUP',
    `delivery_area_id` int(11) DEFAULT NULL,
    `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
    `free_delivery_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
    `discount_type` varchar(50) DEFAULT NULL,
    `discount_id_number` varchar(100) DEFAULT NULL,
    `change_for` decimal(10,2) DEFAULT NULL,
    `cash_collected` decimal(10,2) DEFAULT NULL,
    `collected_by_user_id` int(11) DEFAULT NULL,
    `delivered_by_user_id` int(11) DEFAULT NULL,
    `cod_approval_status` enum('None','Pending','Approved','Declined') NOT NULL DEFAULT 'None',
    `cod_approved_by` int(11) DEFAULT NULL,
    `cod_declined_reason` text DEFAULT NULL,
    `cod_failure_reason` varchar(255) DEFAULT NULL,
    `payment_verification_status` enum('None','Awaiting Verification','Verified','Rejected') NOT NULL DEFAULT 'None',
    `payment_verified_by` int(11) DEFAULT NULL,
    `payment_verified_at` datetime DEFAULT NULL,
    `payment_auto_cancel_at` datetime DEFAULT NULL,
    `qr_submission_count` int(11) NOT NULL DEFAULT 0,
    `locked_by_user_id` int(11) DEFAULT NULL,
    `locked_at` datetime DEFAULT NULL,
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(30) NOT NULL UNIQUE,
    `queue_no` INT NOT NULL,
    `user_id` INT NULL,
    `customer_name` VARCHAR(100),
    `customer_phone` VARCHAR(20) NULL,
    `delivery_address` VARCHAR(500) NULL,
    `delivery_lat` DECIMAL(10,7) NULL DEFAULT NULL,
    `delivery_lng` DECIMAL(10,7) NULL DEFAULT NULL,
    `delivery_mode` ENUM('LAGRO_FREE','CUSTOMER_BOOKED') NOT NULL DEFAULT 'LAGRO_FREE',
    `coverage_status` ENUM('NONE','WITHIN_LAGRO','OUTSIDE_LAGRO','STAFF_OVERRIDE') NOT NULL DEFAULT 'NONE',
    `coverage_boundary_version` VARCHAR(64) NULL DEFAULT NULL,
    `courier_booking_by` ENUM('NONE','CUSTOMER','STORE') NOT NULL DEFAULT 'NONE',
    `delivery_tracking_url` VARCHAR(1000) NULL,
    `payment_reference` VARCHAR(100) NULL,
    `payment_proof` VARCHAR(255) NULL,
    `order_type` ENUM('DINE-IN','TAKE-OUT','ONLINE') NOT NULL,
    `order_channel` ENUM('POS','ONLINE') NOT NULL DEFAULT 'POS',
    `table_no` VARCHAR(20) NULL,
    `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('Pending','Preparing','Ready','Served','Picked Up','Cancelled','Refunded','Voided') NOT NULL DEFAULT 'Pending',
    `paid_at` TIMESTAMP NULL DEFAULT NULL,
    `kitchen_queued_at` TIMESTAMP NULL DEFAULT NULL,
    `kitchen_prepared_at` DATETIME NULL DEFAULT NULL,
    `preparing_at` TIMESTAMP NULL DEFAULT NULL,
    `ready_at` TIMESTAMP NULL DEFAULT NULL,
    `served_at` TIMESTAMP NULL DEFAULT NULL,
    `served_by` INT NULL,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `cancel_reason` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `is_seed` TINYINT(1) NOT NULL DEFAULT 0,
    `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`served_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Order Items Table (With Item-Level Notes column)
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `menu_item_id` INT NOT NULL,
    `item_name` VARCHAR(100) NOT NULL,
    `quantity` INT NOT NULL,
    `voided_quantity` INT NOT NULL DEFAULT 0,
    `price` DECIMAL(10,2) NOT NULL,
    `notes` VARCHAR(255) NULL,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Payments Table
CREATE TABLE IF NOT EXISTS `payments` (
    `shift_id` int(11) DEFAULT NULL,
    `payment_proof_path` varchar(255) DEFAULT NULL,
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
    `payment_reference` VARCHAR(100) NULL,
    `payment_proof` VARCHAR(255) NULL,
    `payment_status` ENUM('Pending','Paid','Failed','Refunded','Voided') NOT NULL DEFAULT 'Paid',
    `amount_paid` DECIMAL(10,2) NOT NULL,
    `change_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `paid_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unique GCash references across POS and online orders; verified payments retain an audit trail.
CREATE TABLE IF NOT EXISTS `payment_reference_registry` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reference_type` VARCHAR(20) NOT NULL DEFAULT 'GCASH',
    `reference_no` VARCHAR(50) NOT NULL,
    `order_id` INT DEFAULT NULL,
    `payment_id` INT DEFAULT NULL,
    `channel` ENUM('POS','ONLINE','LEGACY') NOT NULL DEFAULT 'LEGACY',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_reference_type_no` (`reference_type`, `reference_no`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_payment_id` (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_verification_audits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `verified_by` INT DEFAULT NULL,
    `previous_status` VARCHAR(20) NOT NULL,
    `new_status` VARCHAR(20) NOT NULL,
    `verification_source` ENUM('POS_CASHIER','ADMIN_ONLINE') NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_payment_id` (`payment_id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_verified_by` (`verified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Receipts Table
CREATE TABLE IF NOT EXISTS `receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `receipt_no` VARCHAR(30) NOT NULL UNIQUE,
    `email_status` ENUM('Pending','Sent','Failed') NOT NULL DEFAULT 'Pending',
    `email_sent_at` DATETIME NULL,
    `printed_at` TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saved menu items for online customer accounts.
CREATE TABLE IF NOT EXISTS `customer_favorites` (
    `user_id` INT NOT NULL,
    `menu_item_id` INT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `menu_item_id`),
    INDEX `idx_customer_favorites_item` (`menu_item_id`),
    CONSTRAINT `fk_customer_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_customer_favorites_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Cashier Shifts Table
CREATE TABLE IF NOT EXISTS `cashier_shifts` (
    `terminal` varchar(50) NOT NULL DEFAULT 'Main POS',
    `total_sales` decimal(10,2) NOT NULL DEFAULT 0.00,
    `cash_sales` decimal(10,2) NOT NULL DEFAULT 0.00,
    `gcash_sales` decimal(10,2) NOT NULL DEFAULT 0.00,
    `total_refunds` decimal(10,2) NOT NULL DEFAULT 0.00,
    `auto_closed` tinyint(1) NOT NULL DEFAULT 0,
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `opening_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `closing_cash` DECIMAL(10,2) NULL,
    `expected_cash` DECIMAL(10,2) NULL,
    `variance` DECIMAL(10,2) NULL,
    `status` ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
    `opened_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `closed_at` TIMESTAMP NULL DEFAULT NULL,
    `remarks` VARCHAR(255),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Void Logs Table
CREATE TABLE IF NOT EXISTS `void_logs` (
    `shift_id` int(11) DEFAULT NULL,
    `void_type` enum('item','order','refund') NOT NULL DEFAULT 'item',
    `approval_required` tinyint(1) NOT NULL DEFAULT 0,
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `menu_item_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `cashier_id` INT NOT NULL,
    `approving_admin_id` INT NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`item_id`) REFERENCES `order_items`(`id`),
    FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`),
    FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`approving_admin_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Full-order void/refund and failed-delivery cancellation requests: Cashier requests, Admin reviews.
CREATE TABLE IF NOT EXISTS `transaction_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `request_type` ENUM('Void','Refund','Cancel Delivery','Cancel Order') NOT NULL,
    `requested_by` INT NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `reviewed_by` INT NULL,
    `review_notes` VARCHAR(500) NULL,
    `refund_method` VARCHAR(50) NULL,
    `refund_reference` VARCHAR(100) NULL,
    `refund_confirmed_at` DATETIME NULL,
    `refund_shift_id` INT NULL,
    `stock_returned` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME NULL,
    KEY `idx_transaction_requests_order` (`order_id`, `status`),
    KEY `idx_transaction_requests_status` (`status`, `created_at`),
    KEY `idx_tr_refund_shift` (`refund_shift_id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manual delivery assignment/tracking by Admin or Cashier; no separate rider login.
CREATE TABLE IF NOT EXISTS `delivery_dispatches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL UNIQUE,
    `rider_name` VARCHAR(100) NOT NULL,
    `rider_phone` VARCHAR(20) NULL,
    `status` ENUM('Assigned','Out for Delivery','Delivered','Failed') NOT NULL DEFAULT 'Assigned',
    `assigned_by` INT NOT NULL,
    `updated_by` INT NOT NULL,
    `assigned_at` DATETIME NOT NULL,
    `out_at` DATETIME NULL,
    `delivered_at` DATETIME NULL,
    `failed_at` DATETIME NULL,
    `notes` VARCHAR(500) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_delivery_dispatch_status` (`status`, `assigned_at`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. OTP Verifications Table (For OTP Expiration and Rate Limiting)
CREATE TABLE IF NOT EXISTS `otp_verifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL,
    `code` VARCHAR(255) NOT NULL,
    `purpose` ENUM('register','signin','password_reset') NOT NULL DEFAULT 'signin',
    `pending_full_name` VARCHAR(100) DEFAULT NULL,
    `pending_phone` VARCHAR(20) DEFAULT NULL,
    `pending_address` VARCHAR(500) DEFAULT NULL,
    `pending_password_hash` VARCHAR(255) DEFAULT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `last_sent_at` DATETIME NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_otp_email` (`email`),
    INDEX `idx_email_expires` (`email`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Operational tables required by the current application.
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_role` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_al_action` (`action`),
  KEY `idx_al_target` (`target_type`,`target_id`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cod_cash_handovers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `collected_by_user_id` int(11) NOT NULL,
  `amount_collected` decimal(10,2) NOT NULL,
  `collected_at` datetime NOT NULL,
  `confirmed_by_cashier_id` int(11) DEFAULT NULL,
  `cashier_shift_id` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `status` enum('pending','confirmed') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_cch_status` (`status`),
  KEY `idx_cch_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cpr_email` (`email`),
  KEY `idx_cpr_token_hash` (`token_hash`),
  KEY `idx_cpr_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barangay` varchar(100) NOT NULL DEFAULT 'Greater Lagro',
  `subdivision` varchar(150) DEFAULT NULL,
  `street_name` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_da_lookup` (`barangay`,`street_name`),
  KEY `idx_da_verified` (`is_verified`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drawer_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pin_used` VARCHAR(10) NULL,
  `reason` varchar(100) NOT NULL,
  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `first_order_benefit_hashes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_hash` varchar(64) NOT NULL,
  `address_hash` varchar(64) NOT NULL,
  `used_order_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fob_phone` (`phone_hash`),
  KEY `idx_fob_address` (`address_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pra_email_ip` (`email`,`ip_address`),
  KEY `idx_pra_attempted` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `account_name` varchar(150) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `qr_image` varchar(255) DEFAULT NULL,
  `ref_label` varchar(100) NOT NULL DEFAULT 'Reference Number',
  `ref_regex` varchar(255) NOT NULL DEFAULT '^[A-Za-z0-9]{8,32}$',
  `ref_min_length` int(11) NOT NULL DEFAULT 8,
  `ref_max_length` int(11) NOT NULL DEFAULT 32,
  `ref_error_message` varchar(255) NOT NULL DEFAULT 'Please enter a valid reference number.',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_proofs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `method_code` varchar(50) NOT NULL,
  `reference_number` varchar(100) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `image_hash` varchar(64) NOT NULL,
  `amount_declared` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_type` enum('food','thirdparty_delivery') NOT NULL DEFAULT 'food',
  `verification_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pp_order` (`order_id`),
  KEY `idx_pp_ref` (`method_code`,`reference_number`),
  KEY `idx_pp_hash` (`image_hash`),
  KEY `idx_pp_status` (`verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `thirdparty_deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `booking_service` varchar(100) DEFAULT NULL,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tracking_reference` varchar(255) DEFAULT NULL,
  `fee_paid` tinyint(1) NOT NULL DEFAULT 0,
  `fee_payment_proof_id` int(11) DEFAULT NULL,
  `booked_by` int(11) DEFAULT NULL,
  `booked_at` datetime DEFAULT NULL,
  `fee_notified_at` datetime DEFAULT NULL,
  `status` enum('Quoted','Fee Paid','Dispatched','Cancelled') NOT NULL DEFAULT 'Quoted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tpd_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` char(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_queue_counters` (
    `queue_date` DATE NOT NULL PRIMARY KEY,
    `last_no` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `checkout_requests` (
    `channel` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `actor_id` INT NOT NULL,
    `request_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `order_id` INT NULL,
    `response_json` LONGTEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`channel`, `actor_id`, `request_key`),
    KEY `idx_checkout_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- INITIAL SEED DATA
-- =====================================================================

-- Roles
INSERT INTO `roles` (`id`, `role_name`) VALUES
(1, 'Admin'),
(2, 'Cashier'),
(3, 'Kitchen Staff'),
(4, 'Inventory Staff'),
(5, 'Server'),
(6, 'Manager'),
(7, 'Customer (Online)')
ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`);

-- Default Staff Users (Password: password123 -> $2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K)
INSERT INTO `users` (`id`, `full_name`, `username`, `password`, `role_id`, `email`, `status`) VALUES
(1, 'System Administrator', 'admin', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 1, 'admin@kenjis.local', 'Active'),
(2, 'Kenji Cashier', 'cashier', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 2, 'cashier@kenjis.local', 'Active'),
(3, 'Kitchen Staff', 'kitchen', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 3, 'kitchen@kenjis.local', 'Active'),
(4, 'Inventory Staff', 'inventory', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 4, 'inventory@kenjis.local', 'Active'),
(5, 'Kenji Server', 'server', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 5, 'server@kenjis.local', 'Inactive'),
(6, 'Kenji Manager', 'manager', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 6, 'manager@kenjis.local', 'Inactive')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`), `password` = VALUES(`password`);


-- Categories
INSERT INTO `categories` (`id`, `category_name`, `sort_order`, `status`) VALUES
(1, 'Student Meals', 1, 'Active'),
(2, 'Add-Ons', 2, 'Active'),
(3, 'Pulutan & Pares', 3, 'Active'),
(4, 'Silog Meals', 4, 'Active'),
(5, 'Silog Set Meals', 5, 'Active'),
(6, 'Bilao Favorites', 6, 'Active'),
(7, 'Drinks', 7, 'Active')
ON DUPLICATE KEY UPDATE `category_name` = VALUES(`category_name`), `sort_order` = VALUES(`sort_order`);

-- Menu Items (Including required Drinks seeded at ₱20 with initial stock of 50)
INSERT INTO `menu_items` (`id`, `category_id`, `item_name`, `description`, `price`, `image`, `availability`, `is_archived`, `stock`, `sort_order`) VALUES
-- 1. Student Meals
(1, 1, 'Burgersteak Rice', 'Student meal with savory burgersteak patty, gravy, and rice.', 35.00, NULL, 'Available', 0, 100, 1),
(2, 1, 'Pancit Canton', 'Budget-friendly stir-fried pancit canton noodles.', 35.00, NULL, 'Available', 0, 80, 2),
(3, 1, 'Pastil Rice', 'Sulit shredded seasoned chicken pastil rice meal.', 30.00, NULL, 'Available', 0, 100, 3),
(4, 1, 'Pastilsilog', 'Chicken pastil rice served with fried egg and garlic rice.', 65.00, NULL, 'Available', 0, 60, 4),
(5, 1, 'Shanghai Rice', 'Crispy pork lumpiang shanghai rolls served with rice.', 46.00, NULL, 'Available', 0, 50, 5),
(6, 1, 'Siomai Rice', 'Steamed pork siomai with rice and chili garlic oil.', 40.00, NULL, 'Available', 0, 90, 6),
(7, 1, 'Siomaisilog', 'Steamed/fried siomai with garlic rice and fried egg.', 55.00, NULL, 'Available', 0, 75, 7),

-- 2. Add-Ons
(8, 2, 'Garlic Rice', 'Extra fragrant garlic sinangag rice.', 20.00, NULL, 'Available', 0, 200, 1),
(9, 2, 'Plain Rice', 'Extra warm steamed white rice.', 15.00, NULL, 'Available', 0, 200, 2),
(10, 2, 'Fried Egg', 'Extra sunny-side-up fried egg.', 15.00, NULL, 'Available', 0, 150, 3),

-- 3. Pulutan & Pares
(11, 3, 'Bagnet Pares', 'Classic tender beef pares paired with crispy bagnet.', 65.00, NULL, 'Available', 0, 40, 1),
(12, 3, 'Beef Pares', 'Tender braised beef stew in sweet-savory broth.', 65.00, NULL, 'Available', 0, 45, 2),
(13, 3, 'Mami Pares', 'Rich beef mami noodle soup with beef pares chunks.', 75.00, NULL, 'Available', 0, 30, 3),
(14, 3, 'Mami Pares w/ Egg', 'Beef mami pares topped with boiled egg.', 85.00, NULL, 'Available', 0, 25, 4),
(15, 3, 'Mami Pares w/ Rice', 'Beef mami pares noodle soup with extra garlic rice.', 90.00, NULL, 'Available', 0, 35, 5),
(16, 3, 'Mami Pares Bagnet', 'Beef mami pares noodle soup topped with crispy bagnet slices.', 75.00, NULL, 'Available', 0, 20, 6),
(17, 3, 'Papaitan', 'Traditional savory and bitter beef innards soup.', 85.00, NULL, 'Available', 0, 15, 7),
(18, 3, 'Bagnet', 'Crispy deep-fried pork belly platter.', 250.00, NULL, 'Available', 0, 18, 8),
(19, 3, 'Chicken Fillet', 'Crispy boneless chicken fillet platter with dipping sauce.', 140.00, NULL, 'Available', 0, 25, 9),
(20, 3, 'Dynamite', 'Spicy green chilies stuffed with pork and cheese.', 150.00, NULL, 'Available', 0, 30, 10),
(21, 3, 'Fried Bangus', 'Crispy fried marinated daing na bangus plate.', 120.00, NULL, 'Available', 0, 12, 11),
(22, 3, 'Shanghai', 'Golden crispy pork lumpiang shanghai platter (12 pcs).', 140.00, NULL, 'Available', 0, 40, 12),
(23, 3, 'Sisig', 'Sizzling savory minced pork sisig with onions and chili.', 160.00, NULL, 'Available', 0, 35, 13),
(24, 3, 'Sizzling Hotdog', 'Sizzling sliced tender juicy hotdogs in sweet chili sauce.', 160.00, NULL, 'Available', 0, 20, 14),

-- 4. Silog Meals
(25, 4, 'Baconsilog', 'Crispy bacon strips, sinangag rice, and fried egg.', 100.00, NULL, 'Available', 0, 30, 1),
(26, 4, 'Bagnetsilog', 'Crispy bagnet pork belly, sinangag rice, and fried egg.', 150.00, NULL, 'Available', 0, 40, 2),
(27, 4, 'Bangsilog', 'Fried marinated daing na bangus, garlic rice, and egg.', 100.00, NULL, 'Available', 0, 20, 3),
(28, 4, 'Chicksilog', 'Fried chicken quarter, garlic rice, and egg.', 135.00, NULL, 'Available', 0, 25, 4),
(29, 4, 'Filletsilog', 'Crispy fried chicken fillet, garlic rice, and egg.', 115.00, NULL, 'Available', 0, 30, 5),
(30, 4, 'Hamsilog', 'Sweet cooked ham slices, sinangag, and egg.', 100.00, NULL, 'Available', 0, 25, 6),
(31, 4, 'Hotsilog', 'Jumbo hotdog, garlic rice, and fried egg.', 95.00, NULL, 'Available', 0, 35, 7),
(32, 4, 'Hungariansilog', 'Sizzling spiced Hungarian sausage, rice, and egg.', 115.00, NULL, 'Available', 0, 20, 8),
(33, 4, 'Longsilog', 'Sweet native garlic longganisa, rice, and egg.', 100.00, NULL, 'Available', 0, 40, 9),
(34, 4, 'Malingsilog', 'Fried MaLing luncheon meat slices, rice, and egg.', 100.00, NULL, 'Available', 0, 30, 10),
(35, 4, 'Porkchopsilog', 'Golden fried seasoned porkchop, rice, and egg.', 115.00, NULL, 'Available', 0, 25, 11),
(36, 4, 'Sisigsilog', 'Savory pork sisig, garlic rice, and egg.', 120.00, NULL, 'Available', 0, 45, 12),
(37, 4, 'Spamsilog', 'Premium fried Spam slices, garlic rice, and egg.', 120.00, NULL, 'Available', 0, 35, 13),
(38, 4, 'Tapsilog', 'Tender marinated beef tapa, garlic rice, and egg.', 155.00, NULL, 'Available', 0, 50, 14),
(39, 4, 'Tinapasilog', 'Smoked tinapa fish, garlic rice, and egg.', 85.00, NULL, 'Available', 0, 15, 15),
(40, 4, 'Tocilog', 'Sweet cured pork tocino, garlic rice, and egg.', 100.00, NULL, 'Available', 0, 35, 16),
(41, 4, 'Tortasilog', 'Eggplant tortang talong, garlic rice, and egg.', 100.00, NULL, 'Available', 0, 18, 17),

-- 5. Silog Set Meals
(42, 5, 'Set Meal A', '2 Pastil Rice, Ham, and Egg combo.', 135.00, NULL, 'Available', 0, 20, 1),
(43, 5, 'Set Meal B', '2 Pastil Rice, Shanghai, and Egg combo.', 130.00, NULL, 'Available', 0, 25, 2),
(44, 5, 'Set Meal C', '2 Pastil Rice, Longganisa, and Egg combo.', 130.00, NULL, 'Available', 0, 20, 3),
(45, 5, 'Set Meal D', '2 Pastil Rice, Hotdog, and Egg combo.', 140.00, NULL, 'Available', 0, 22, 4),
(46, 5, 'Set Meal E', '2 Pastil Rice, Maling, and Egg combo.', 135.00, NULL, 'Available', 0, 15, 5),
(47, 5, 'Set Meal F', '2 Pastil Rice, Longganisa, Shanghai, and Egg combo.', 155.00, NULL, 'Available', 0, 18, 6),
(48, 5, 'Set Meal G', '2 Pastil Rice, Hotdog, Shanghai, and Egg combo.', 165.00, NULL, 'Available', 0, 20, 7),
(49, 5, 'Set Meal H', '2 Pastil Rice, Maling, Shanghai, and Egg combo.', 165.00, NULL, 'Available', 0, 16, 8),
(50, 5, 'Set Meal I', '2 Pastil Rice, Ham, Shanghai, and Egg combo.', 170.00, NULL, 'Available', 0, 25, 9),

-- 6. Bilao Favorites
(51, 6, 'Small Spaghetti', 'Small spaghetti bilao.', 500.00, NULL, 'Available', 0, 15, 1),
(52, 6, 'Small Carbonara', 'Small carbonara bilao.', 500.00, NULL, 'Available', 0, 15, 2),
(53, 6, 'Small Palabok', 'Small palabok bilao.', 650.00, NULL, 'Available', 0, 12, 3),

-- 7. Drinks (Seeded as requested: ₱20.00 each with stock 50)
(54, 7, 'Coca-Cola', 'Refreshing ice-cold Coca-Cola in 290ml glass bottle.', 20.00, NULL, 'Available', 0, 50, 1),
(55, 7, 'Sprite', 'Crisp lemon-lime flavored soda in 290ml glass bottle.', 20.00, NULL, 'Available', 0, 50, 2),
(56, 7, 'Royal', 'Sweet citrus orange flavored soda in 290ml glass bottle.', 20.00, NULL, 'Available', 0, 50, 3),
(57, 7, 'Mountain Dew', 'Invigorating citrus flavored soda in 290ml bottle.', 20.00, NULL, 'Available', 0, 50, 4),
(58, 7, 'Bottled Soda', 'Chilled classic bottled carbonated beverage.', 20.00, NULL, 'Available', 0, 50, 5)

ON DUPLICATE KEY UPDATE
    `item_name` = VALUES(`item_name`),
    `description` = VALUES(`description`),
    `price` = VALUES(`price`),
    `stock` = VALUES(`stock`),
    `availability` = VALUES(`availability`),
    `is_archived` = VALUES(`is_archived`);

INSERT INTO `menu_items` (`id`, `category_id`, `item_name`, `description`, `price`, `promo_price`, `availability`, `is_archived`, `stock`, `sort_order`) VALUES
(59, 6, 'Small Sotanghon', 'Small sotanghon bilao.', 650.00, NULL, 'Available', 0, 15, 4),
(60, 6, 'Small Bihon', 'Small bihon bilao.', 550.00, NULL, 'Available', 0, 15, 5),
(61, 6, 'Small Canton', 'Small pancit canton bilao.', 550.00, NULL, 'Available', 0, 15, 6)
ON DUPLICATE KEY UPDATE
    `item_name` = VALUES(`item_name`),
    `description` = VALUES(`description`),
    `price` = VALUES(`price`),
    `stock` = VALUES(`stock`),
    `availability` = VALUES(`availability`),
    `is_archived` = VALUES(`is_archived`);


-- Fresh-install operational defaults. Merchant details must be configured before launch.
INSERT INTO system_settings (setting_key, setting_value) VALUES
('over_short_threshold','50'), ('require_opening_cash','1'),
('refunds_require_approval','1'), ('qr_payment_timeout_minutes','30'),
('proof_retention_days','90'), ('cod_min_order','30'), ('cod_max_order','500'),
('cod_block_after_failures','2'), ('show_unverified_areas_to_customers','0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO payment_methods (code, name, is_enabled, ref_regex, ref_min_length, ref_max_length, ref_error_message) VALUES
('COD','Cash on Delivery / Pickup',1,'^.*$',0,50,''),
('GCASH','GCash',1,'^[0-9]{13}$',13,13,'Enter a valid 13-digit GCash reference number.')
ON DUPLICATE KEY UPDATE code = VALUES(code);
