-- Cashier-requested, Admin-approved full-order void/refund workflow.
-- Uses existing orders, payments, order_items, menu_items, inventory_logs, and void_logs.
USE `kenjis_kitchen`;

CREATE TABLE IF NOT EXISTS `transaction_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `request_type` ENUM('Void','Refund') NOT NULL,
    `requested_by` INT NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `reviewed_by` INT NULL,
    `review_notes` VARCHAR(500) NULL,
    `refund_method` VARCHAR(50) NULL,
    `refund_reference` VARCHAR(100) NULL,
    `refund_confirmed_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME NULL,
    KEY `idx_transaction_requests_order` (`order_id`, `status`),
    KEY `idx_transaction_requests_status` (`status`, `created_at`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
