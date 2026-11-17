<?php
// Use the same settled-order definition as the sales reports. Never expose order/customer data.
require_once dirname(__DIR__) . '/includes/functions.php';
$storeBestsellers = [];
$storeBestsellerPeriod = 'All-time bestsellers';
try {
    $paidOrders = kk_report_paid_orders_sql();
    $base = "SELECT m.id, m.item_name AS name, m.description, m.image, c.category_name,
                    SUM(oi.quantity - COALESCE(oi.voided_quantity, 0)) AS sold
             FROM order_items oi JOIN ($paidOrders) o ON o.id = oi.order_id
             JOIN menu_items m ON m.id = oi.menu_item_id JOIN categories c ON c.id = m.category_id
             WHERE o.report_paid_at IS NOT NULL AND o.status NOT IN ('Cancelled','Voided','Refunded')
               AND m.is_archived = 0 AND c.status = 'Active'
               AND LOWER(TRIM(c.category_name)) NOT IN ('add-ons', 'drinks')
               AND (oi.quantity - COALESCE(oi.voided_quantity, 0)) > 0";
    $group = ' GROUP BY m.id, m.item_name, m.description, m.image, c.category_name ORDER BY sold DESC, m.id ASC LIMIT 5';
    $storeBestsellers = $conn->query($base . $group)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($storeBestsellers as &$item) {
        $item['image_url'] = menu_image_url($item['image'] ?? '') ?: BASE_URL . '/assets/img/food-placeholder.svg';
        unset($item['image'], $item['sold']);
    }
    unset($item);
} catch (Throwable $e) { error_log('Storefront bestsellers: ' . $e->getMessage()); }
