-- Correct the small-item base prices used by the POS and online menu.
-- Medium, Large, and XL prices are calculated from config/bilao-pricing.php.
USE `kenjis_kitchen`;

UPDATE `menu_items`
SET `item_name` = 'Small Spaghetti', `description` = 'Small spaghetti bilao.', `price` = 500.00
WHERE `category_id` = (SELECT `id` FROM `categories` WHERE `category_name` = 'Bilao Favorites' LIMIT 1)
  AND `item_name` IN ('Spaghetti Bilao Small', 'Spaghetti / Carbonara', 'Small Spaghetti');

UPDATE `menu_items`
SET `item_name` = 'Small Carbonara', `description` = 'Small carbonara bilao.', `price` = 500.00
WHERE `category_id` = (SELECT `id` FROM `categories` WHERE `category_name` = 'Bilao Favorites' LIMIT 1)
  AND `item_name` IN ('Small Carbonara', 'Carbonara Bilao Small');

UPDATE `menu_items`
SET `item_name` = 'Small Palabok', `description` = 'Small palabok bilao.', `price` = 650.00
WHERE `category_id` = (SELECT `id` FROM `categories` WHERE `category_name` = 'Bilao Favorites' LIMIT 1)
  AND `item_name` IN ('Palabok Bilao Small', 'Palabok / Sotanghon', 'Small Palabok');

UPDATE `menu_items`
SET `item_name` = 'Small Sotanghon', `description` = 'Small sotanghon bilao.', `price` = 650.00
WHERE `category_id` = (SELECT `id` FROM `categories` WHERE `category_name` = 'Bilao Favorites' LIMIT 1)
  AND `item_name` IN ('Small Sotanghon', 'Sotanghon Bilao Small');

UPDATE `menu_items`
SET `item_name` = 'Small Bihon', `description` = 'Small bihon bilao.', `price` = 550.00
WHERE `category_id` = (SELECT `id` FROM `categories` WHERE `category_name` = 'Bilao Favorites' LIMIT 1)
  AND `item_name` IN ('Bihon Bilao Small', 'Bihon / Canton', 'Small Bihon');

UPDATE `menu_items`
SET `item_name` = 'Small Canton', `description` = 'Small pancit canton bilao.', `price` = 550.00
WHERE `category_id` = (SELECT `id` FROM `categories` WHERE `category_name` = 'Bilao Favorites' LIMIT 1)
  AND `item_name` IN ('Small Canton', 'Canton Bilao Small');

INSERT INTO `menu_items` (`category_id`, `item_name`, `description`, `price`, `availability`, `is_archived`, `stock`, `sort_order`)
SELECT c.`id`, wanted.`item_name`, wanted.`description`, wanted.`price`, 'Available', 0, 50, wanted.`sort_order`
FROM `categories` c
CROSS JOIN (
    SELECT 'Small Carbonara' AS `item_name`, 'Small carbonara bilao.' AS `description`, 500.00 AS `price`, 7 AS `sort_order`
    UNION ALL SELECT 'Small Sotanghon', 'Small sotanghon bilao.', 650.00, 8
    UNION ALL SELECT 'Small Canton', 'Small pancit canton bilao.', 550.00, 9
) wanted
WHERE c.`category_name` = 'Bilao Favorites'
  AND NOT EXISTS (
      SELECT 1 FROM `menu_items` m
      WHERE m.`category_id` = c.`id` AND m.`item_name` = wanted.`item_name`
  );
