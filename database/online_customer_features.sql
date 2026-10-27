-- Apply once to an existing Kenji's Kitchen database.
USE `kenjis_kitchen`;

ALTER TABLE `receipts`
    ADD COLUMN `email_status` ENUM('Pending','Sent','Failed') NOT NULL DEFAULT 'Pending' AFTER `receipt_no`,
    ADD COLUMN `email_sent_at` DATETIME NULL AFTER `email_status`;

CREATE TABLE `customer_favorites` (
    `user_id` INT NOT NULL,
    `menu_item_id` INT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `menu_item_id`),
    INDEX `idx_customer_favorites_item` (`menu_item_id`),
    CONSTRAINT `fk_customer_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_customer_favorites_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
