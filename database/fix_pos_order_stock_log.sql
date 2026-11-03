-- Repair legacy databases so menu stock deductions can be logged without
-- pretending they belong to a specific ingredient inventory row.
USE `kenjis_kitchen`;

ALTER TABLE `inventory_logs`
    MODIFY COLUMN `inventory_id` INT NULL;

-- Replace the three displayed large spaghetti bilao placeholders with the
-- requested small noodle tray products. Prices are retained pending the
-- store's confirmed price list.
UPDATE `menu_items`
SET `item_name` = 'Small Carbonara', `description` = 'Small carbonara bilao tray.'
WHERE `item_name` = 'Spaghetti Bilao Large';

UPDATE `menu_items`
SET `item_name` = 'Small Sotanghon', `description` = 'Small sotanghon bilao tray.'
WHERE `item_name` = 'Spaghetti Bilao Medium';

UPDATE `menu_items`
SET `item_name` = 'Small Canton', `description` = 'Small pancit canton bilao tray.'
WHERE `item_name` = 'Spaghetti Bilao XL';
