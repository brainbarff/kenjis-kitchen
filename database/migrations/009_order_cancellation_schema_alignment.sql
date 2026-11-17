-- Align the older operational order tables with approval and cancellation flows.
USE `kenjis_kitchen`;

ALTER TABLE `orders`
    MODIFY COLUMN `status` ENUM('Pending','Preparing','Ready','Served','Picked Up','Cancelled','Refunded','Voided') NOT NULL DEFAULT 'Pending',
    ADD COLUMN IF NOT EXISTS `cancelled_at` TIMESTAMP NULL DEFAULT NULL AFTER `served_by`,
    ADD COLUMN IF NOT EXISTS `cancel_reason` VARCHAR(255) NULL AFTER `cancelled_at`;

ALTER TABLE `order_items`
    ADD COLUMN IF NOT EXISTS `voided_quantity` INT NOT NULL DEFAULT 0 AFTER `quantity`;
