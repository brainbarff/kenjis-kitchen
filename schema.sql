-- =====================================================================
-- KENJI'S KITCHEN - CONSOLIDATED DATABASE SCHEMA (v1.1.0)
-- Production Ready Schema for Multi-Role Restaurant & Online Ordering
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
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role_id` INT NOT NULL,
    `email` VARCHAR(150) NULL UNIQUE,
    `phone` VARCHAR(20) NULL,
    `customer_address` VARCHAR(500) NULL,
    `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `customer_status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
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
    `request_type` ENUM('Stock In','Stock Out','Adjustment','Archive','Unarchive') NOT NULL,
    `menu_item_id` INT NOT NULL,
    `quantity` INT NULL,
    `remarks` VARCHAR(255) NULL,
    `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `requested_by` INT NOT NULL,
    `reviewed_by` INT NULL,
    `review_notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Orders Table
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(30) NOT NULL UNIQUE,
    `queue_no` INT NOT NULL,
    `user_id` INT NULL,
    `customer_name` VARCHAR(100),
    `customer_phone` VARCHAR(20) NULL,
    `delivery_address` VARCHAR(500) NULL,
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
    `preparing_at` TIMESTAMP NULL DEFAULT NULL,
    `ready_at` TIMESTAMP NULL DEFAULT NULL,
    `served_at` TIMESTAMP NULL DEFAULT NULL,
    `served_by` INT NULL,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `cancel_reason` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `is_seed` TINYINT(1) NOT NULL DEFAULT 0,
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
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
    `payment_reference` VARCHAR(100) NULL,
    `payment_proof` VARCHAR(255) NULL,
    `payment_status` ENUM('Pending','Paid','Failed') NOT NULL DEFAULT 'Paid',
    `amount_paid` DECIMAL(10,2) NOT NULL,
    `change_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `paid_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
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

-- 15. OTP Verifications Table (For OTP Expiration and Rate Limiting)
CREATE TABLE IF NOT EXISTS `otp_verifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL,
    `code` VARCHAR(6) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `last_sent_at` DATETIME NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_otp_email` (`email`),
    INDEX `idx_email_expires` (`email`, `expires_at`)
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
(5, 'Kenji Server', 'server', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 5, 'server@kenjis.local', 'Active'),
(6, 'Kenji Manager', 'manager', '$2y$10$FbfvNnWubP9hhAG7tZFhOelImcvaD99eJ9cC6dAi9Dr109W2xd4.K', 6, 'manager@kenjis.local', 'Active')
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
