-- Apply once to an existing Kenji's Kitchen database.
USE `kenjis_kitchen`;

ALTER TABLE `menu_items`
    ADD COLUMN `pending_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `price`,
    ADD COLUMN `pending_price_effective` DATETIME NULL DEFAULT NULL AFTER `pending_price`;

CREATE TABLE `price_change_logs` (
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
