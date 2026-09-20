<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier']);

$page = 'pos';
$title = 'Point of Sale';
$heading = 'Point of Sale';

$categories = $conn->query("SELECT * FROM categories WHERE status = 'Active' ORDER BY category_name")->fetchAll();
$items = $conn->query("
    SELECT menu_items.*, categories.category_name
    FROM menu_items
    JOIN categories ON categories.id = menu_items.category_id
    ORDER BY categories.category_name, menu_items.item_name
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<div class="pos-layout">
    <section>
        <div class="tabs">
            <button class="tab active" data-cat="all">All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="tab" data-cat="<?= e($cat['id']) ?>"><?= e($cat['category_name']) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="menu-grid" id="menuGrid">
            <?php foreach ($items as $item): ?>
            <?php
                $price = $item['price'];
                if ($item['promo_price'] && $item['promo_start'] <= date('Y-m-d') && $item['promo_end'] >= date('Y-m-d')) {
                    $price = $item['promo_price'];
                }
            ?>
            <article class="food-card <?= $item['availability'] === 'Unavailable' ? 'unavailable' : '' ?>" data-cat="<?= e($item['category_id']) ?>" data-id="<?= e($item['id']) ?>" data-name="<?= e($item['item_name']) ?>" data-price="<?= e($price) ?>">
                <?php if ($item['image']): ?>
                    <img src="<?= BASE_URL ?>/uploads/menu/<?= e($item['image']) ?>" alt="<?= e($item['item_name']) ?>">
                <?php else: ?>
                    <img src="<?= BASE_URL ?>/assets/img/food-placeholder.svg" alt="">
                <?php endif; ?>
                <div>
                    <h3><?= e($item['item_name']) ?></h3>
                    <p class="muted"><?= e($item['category_name']) ?></p>
                    <strong><?= money($price) ?></strong>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="card receipt-panel">
        <div class="receipt-brand">
            <span class="brand-mark">K</span>
            <h2>KENJI'S kitchen</h2>
            <p>From Silog to Sulit Meals, Busog Ka Dito!</p>
            <small>Florante At Laura St. | 09687430373</small>
        </div>
        <div class="form">
            <div class="field">
                <label>Order Type</label>
                <select id="orderType">
                    <option value="">Select order type</option>
                    <option value="DINE-IN">Dine-In</option>
                    <option value="TAKE-OUT">Take-Out</option>
                </select>
            </div>
            <div class="field"><label>Table Number</label><input id="tableNo" placeholder="Required for dine-in"></div>
        </div>

        <div class="cart-items" id="cartItems"></div>

        <div class="totals">
            <div><span>Subtotal</span><strong id="subtotal">₱0.00</strong></div>
            <div><span>Discount</span><input id="discount" type="number" min="0" value="0" style="width:90px"></div>
            <div><span>Tax</span><strong id="tax">Tax-Free</strong></div>
            <div class="grand"><span>Total</span><strong id="grandTotal">₱0.00</strong></div>
        </div>

        <div class="form">
            <div class="field"><label>Cash</label><input id="cash" type="number" min="0"></div>
            <div class="grand"><span>Change: </span><strong id="change">₱0.00</strong></div>
            <button class="btn btn-primary" id="checkoutBtn"><i class="bi bi-check-circle"></i>Checkout</button>
            <button class="btn btn-secondary" id="printBtn"><i class="bi bi-printer"></i>Print Receipt</button>
        </div>
    </aside>
</div>
<?php $script = 'pos.js'; include ROOT_PATH . '/includes/footer.php'; ?>
