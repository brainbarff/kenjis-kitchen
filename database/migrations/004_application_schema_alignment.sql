-- Apply to an existing installation only after inspecting its actual schema and backing it up.
-- Fresh installations use schema.sql, which already contains these structures.
-- Migrations 001, 002, and 003 must also be applied where their structures are missing.
USE `kenjis_kitchen`;

ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `approval_requests`
    MODIFY COLUMN `request_type` ENUM(
        'Stock In','Stock Out','Adjustment','Archive','Unarchive',
        'Add Category','Delete Category','Add Item','Delete Item'
    ) NOT NULL,
    MODIFY COLUMN `menu_item_id` INT NULL,
    ADD COLUMN IF NOT EXISTS `category_id` INT NULL,
    ADD COLUMN IF NOT EXISTS `payload` LONGTEXT NULL;

-- The menu/stock approval UI preserves category history when a category is removed.
SET @approval_category_fk = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'approval_requests'
      AND COLUMN_NAME = 'category_id'
      AND REFERENCED_TABLE_NAME = 'categories'
);
SET @approval_category_ddl = IF(
    @approval_category_fk = 0,
    'ALTER TABLE `approval_requests` ADD CONSTRAINT `fk_approval_category` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE approval_category_stmt FROM @approval_category_ddl;
EXECUTE approval_category_stmt;
DEALLOCATE PREPARE approval_category_stmt;
