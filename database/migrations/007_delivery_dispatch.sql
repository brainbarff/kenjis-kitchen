-- Manual dispatch records for online delivery orders; Admin/Cashier manage rider assignments.
USE `kenjis_kitchen`;

ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `delivery_mode` ENUM('LAGRO_FREE','CUSTOMER_BOOKED') NOT NULL DEFAULT 'LAGRO_FREE' AFTER `delivery_address`,
    ADD COLUMN IF NOT EXISTS `delivery_tracking_url` VARCHAR(1000) NULL AFTER `delivery_mode`;

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
