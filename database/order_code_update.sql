-- Preview existing online order codes.
SELECT id, order_no, order_channel
FROM orders
WHERE order_channel = 'ONLINE' AND order_no LIKE 'ORD-%'
ORDER BY id ASC;

-- Check for code conflicts before changing them.
SELECT o.id, o.order_no AS old_order_no, CONCAT('ONL-', SUBSTRING(o.order_no, 5)) AS new_order_no
FROM orders o
JOIN orders existing ON existing.order_no = CONCAT('ONL-', SUBSTRING(o.order_no, 5))
WHERE o.order_channel = 'ONLINE' AND o.order_no LIKE 'ORD-%';

-- Change existing online orders from ORD- to ONL-.
-- Walk-in/POS orders remain ORD-.
UPDATE orders o
LEFT JOIN orders existing
    ON existing.order_no = CONCAT('ONL-', SUBSTRING(o.order_no, 5))
SET o.order_no = CONCAT('ONL-', SUBSTRING(o.order_no, 5))
WHERE o.order_channel = 'ONLINE'
  AND o.order_no LIKE 'ORD-%'
  AND existing.id IS NULL;

-- Keep old inventory-log references consistent with the new online order prefix.
UPDATE inventory_logs
SET remarks = REPLACE(remarks, 'Online order ORD-', 'Online order ONL-')
WHERE remarks LIKE 'Online order ORD-%';

-- Verify.
SELECT id, order_no, order_channel, status
FROM orders
WHERE order_channel = 'ONLINE'
ORDER BY id DESC
LIMIT 30;
