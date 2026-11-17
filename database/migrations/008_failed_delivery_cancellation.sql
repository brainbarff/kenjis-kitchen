-- Allow Admin-reviewed cancellation of failed, unpaid online deliveries.
USE `kenjis_kitchen`;

ALTER TABLE `transaction_requests`
    MODIFY COLUMN `request_type` ENUM('Void','Refund','Cancel Delivery') NOT NULL,
    ADD COLUMN IF NOT EXISTS `stock_returned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `refund_confirmed_at`;
