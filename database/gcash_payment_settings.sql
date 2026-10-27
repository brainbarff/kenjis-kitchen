-- Apply once to an existing Kenji's Kitchen database.
USE `kenjis_kitchen`;

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
ON DUPLICATE KEY UPDATE `gcash_phone` = IF(`gcash_phone` = '', VALUES(`gcash_phone`), `gcash_phone`);
