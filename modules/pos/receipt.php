<?php
/**
 * Kenji's Kitchen - Standalone Thermal Receipt Viewer & Reprint API
 * GET /modules/pos/receipt.php?order_id=123 (or ?order_no=ORD-...)
 * Supports auto-print mode: &autoprint=1
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'cashier']);

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$orderNo = isset($_GET['order_no']) ? trim($_GET['order_no']) : '';
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

if ($orderId <= 0 && $orderNo === '') {
    die('Missing order identifier.');
}

if ($orderId > 0) {
    $stmt = $conn->prepare("
        SELECT o.*,
               COALESCE(NULLIF(TRIM(o.cashier_name), ''), CASE WHEN o.order_channel = 'POS' THEN u.full_name ELSE 'Online Order' END) as cashier_name,
               CASE WHEN o.order_channel = 'POS' THEN u.username ELSE NULL END as cashier_username,
               p.payment_method, p.payment_reference, p.amount_paid
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN (
            SELECT p1.* FROM payments p1
            INNER JOIN (SELECT order_id, MAX(id) as max_id FROM payments GROUP BY order_id) l ON l.max_id = p1.id
        ) p ON p.order_id = o.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
} else {
    $stmt = $conn->prepare("
        SELECT o.*,
               COALESCE(NULLIF(TRIM(o.cashier_name), ''), CASE WHEN o.order_channel = 'POS' THEN u.full_name ELSE 'Online Order' END) as cashier_name,
               CASE WHEN o.order_channel = 'POS' THEN u.username ELSE NULL END as cashier_username,
               p.payment_method, p.payment_reference, p.amount_paid
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN (
            SELECT p1.* FROM payments p1
            INNER JOIN (SELECT order_id, MAX(id) as max_id FROM payments GROUP BY order_id) l ON l.max_id = p1.id
        ) p ON p.order_id = o.id
        WHERE o.order_no = ?
        LIMIT 1
    ");
    $stmt->execute([$orderNo]);
}

$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    die('Order not found.');
}

// Fetch order items
$itemStmt = $conn->prepare("
    SELECT oi.*, COALESCE(NULLIF(oi.item_name, ''), m.item_name, 'Custom Item') as item_title,
           GREATEST(0, oi.quantity - COALESCE(oi.voided_quantity, 0)) AS receipt_quantity
    FROM order_items oi
    LEFT JOIN menu_items m ON m.id = oi.menu_item_id
    WHERE oi.order_id = ?
      AND oi.quantity > COALESCE(oi.voided_quantity, 0)
    ORDER BY oi.id ASC
");
$itemStmt->execute([$order['id']]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentMethod = !empty($order['payment_method']) ? strtoupper($order['payment_method']) : 'CASH';
$tendered = (float)($order['amount_paid'] ?? $order['total']);
$change = max(0, $tendered - (float)$order['total']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?= htmlspecialchars($order['order_no']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Courier New', Courier, monospace, monospace;
            background: #f1f5f9;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #000;
        }
        .no-print {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .btn {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #fff;
            border: none;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .btn:hover { background: #1e293b; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-secondary:hover { background: #cbd5e1; }

        /* 58mm / 80mm Standard Thermal Paper Container */
        .thermal-receipt {
            background: #ffffff;
            width: var(--receipt-width, 58mm);
            max-width: 100%;
            padding: 3mm var(--receipt-inset, 5mm);
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-radius: 4px;
            font-size: 11px;
            line-height: 1.35;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .dashed-line {
            border-bottom: 1px dashed #000;
            margin: 8px 0;
        }
        .table-items {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0;
        }
        .table-items th {
            text-align: left;
            font-size: 11px;
            padding-bottom: 4px;
            border-bottom: 1px dashed #000;
        }
        .table-items td {
            padding: 3px 0;
            vertical-align: top;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .grand-total {
            font-size: 14px;
            font-weight: 800;
            margin: 6px 0;
            display: flex;
            justify-content: space-between;
        }
        .reprint-badge {
            background: #fee2e2;
            color: #991b1b;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 6px;
            font-family: system-ui, sans-serif;
        }

        @media print {
            body {
                display: block !important;
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print { display: none !important; }
            .thermal-receipt {
                width: var(--receipt-width, 58mm) !important;
                max-width: none !important;
                box-shadow: none !important;
                padding: 3mm var(--receipt-inset, 5mm) !important;
                margin: 0 !important;
            }
        }
        .no-print { flex-wrap: wrap; justify-content: center; max-width: 620px; font-family: system-ui, sans-serif; }
        .print-help { flex-basis: 100%; font-size: 13px; line-height: 1.5; color: #475569; margin: 0; }
        .paper-control { display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .paper-control select { padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
        .btn:first-of-type { background: #f7c62f; color: #242424; }
        .thermal-receipt { overflow-wrap: anywhere; }
        .table-items { table-layout: fixed; }
        .table-items th:first-child { width: 8mm !important; }
        .table-items th:last-child { width: 19mm; text-align: right; }
        .table-items td:nth-child(2) { padding-right: 2mm; }
        .table-items td:last-child { white-space: nowrap; }
        .totals-row, .grand-total { gap: 2mm; }
        .totals-row span:last-child { text-align: right; }
        .order-identity { display: flex; flex-direction: column; gap: 2px; font-weight: bold; }
        @page { margin: 0; }
        @media print {
            .thermal-receipt { border-radius: 0; }
            .thermal-receipt * { color: #000 !important; }
            .reprint-badge { background: none; padding: 0; }
            tr, .grand-total, .totals-row { break-inside: avoid; }
        }
    </style>
    <style id="receipt-page-size"></style>
</head>
<body>

    <div class="no-print">
        <label class="paper-control" for="receipt-paper">Paper width <select id="receipt-paper"><option value="58">PT-210 / 58 mm (48 mm print area)</option><option value="80">80 mm</option></select></label>
        <button class="btn" onclick="printReceipt()"><i class="bi bi-printer-fill"></i> Print Receipt</button>
        <button class="btn btn-secondary" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>
    </div>

    <p class="no-print print-help">PT-210: use the narrow paper setting (48 mm printable area). On Windows, install its compatible driver; on Android, use a compatible print service or printer app. iPhone/iPad support is not confirmed for this model. Select the printer, match its paper size, use 100% scale and no margins, and disable headers and footers.</p>
    <div class="thermal-receipt">
        <div class="text-center">
            <span class="reprint-badge">CUSTOMER RECEIPT / REPRINT</span>
            <div style="font-size: 18px; font-weight: 900; letter-spacing: 1px;">KENJI'S KITCHEN</div>
            <div style="font-size: 11px; margin-top: 2px;">Restaurant Point of Sale</div>
            <div style="font-size: 10px; color: #444;"><?= date('M d, Y h:i:s A', strtotime($order['created_at'])) ?></div>
        </div>

        <div class="dashed-line"></div>

        <div class="order-identity">
            <span><?= htmlspecialchars($order['order_no']) ?></span>
            <span>Queue #<?= str_pad((string)$order['queue_no'], 4, '0', STR_PAD_LEFT) ?></span>
        </div>

        <div style="margin-top: 4px; font-size: 11px;">
            <div><strong>Type:</strong> <?= htmlspecialchars($order['order_type']) ?><?= !empty($order['table_no']) ? ' (Table ' . htmlspecialchars($order['table_no']) . ')' : '' ?></div>
            <div><strong>Channel:</strong> <?= htmlspecialchars($order['order_channel']) ?></div>
            <?php if (strtoupper((string)$order['order_type']) === 'ONLINE'): ?>
                <?php
                $isOutsideReceipt = (($order['delivery_mode'] ?? '') === 'CUSTOMER_BOOKED');
                $receiptBooker = strtoupper((string)($order['courier_booking_by'] ?? 'CUSTOMER'));
                $arrangementLabel = $isOutsideReceipt
                    ? ($receiptBooker === 'STORE'
                        ? 'Outside Greater Lagro - Third-Party Courier (Store-Booked, Paid Separately)'
                        : 'Outside Greater Lagro - Third-Party Courier (Customer-Booked, Paid Separately)')
                    : 'Within Greater Lagro - Free Store Delivery (₱0.00)';
                ?>
                <div><strong>Delivery:</strong> <?= htmlspecialchars($arrangementLabel) ?></div>
                <?php if (!empty($order['delivery_address'])): ?>
                    <div><strong>Address:</strong> <?= htmlspecialchars($order['delivery_address']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
            <div><strong>Cashier:</strong> <?= htmlspecialchars($order['cashier_name'] ?: ($order['cashier_username'] ?: 'Staff')) ?></div>
            <?php if (!empty($order['customer_name'])): ?>
                <div><strong>Customer:</strong> <?= htmlspecialchars($order['customer_name']) ?></div>
            <?php endif; ?>
        </div>

        <div class="dashed-line"></div>

        <table class="table-items">
            <thead>
                <tr>
                    <th style="width: 28px;">QTY</th>
                    <th>ITEM</th>
                    <th class="text-right">PRICE</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= (int)$item['receipt_quantity'] ?>x</td>
                        <td>
                            <?= htmlspecialchars($item['item_title']) ?>
                            <?php if (!empty($item['variation'])): ?>
                                <div style="font-size: 10px; color: #555;">(<?= htmlspecialchars($item['variation']) ?>)</div>
                            <?php endif; ?>
                            <?php if (!empty($item['notes'])): ?>
                                <div style="font-size: 10px; color: #555;"><?= htmlspecialchars($item['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">₱<?= number_format((float)$item['price'] * (int)$item['receipt_quantity'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="dashed-line"></div>

        <div class="totals-row">
            <span>Subtotal:</span>
            <span>₱<?= number_format((float)$order['subtotal'], 2) ?></span>
        </div>

        <?php if ((float)$order['discount'] + (float)$order['free_delivery_discount'] > 0): ?>
            <div class="totals-row" style="color: #000;">
                <span>Discount:</span>
                <span>-₱<?= number_format((float)$order['discount'] + (float)$order['free_delivery_discount'], 2) ?></span>
            </div>
        <?php endif; ?>

        <?php if ((float)$order['delivery_fee'] > 0): ?>
            <div class="totals-row">
                <span>Delivery Fee:</span>
                <span>₱<?= number_format((float)$order['delivery_fee'], 2) ?></span>
            </div>
        <?php elseif (strtoupper((string)$order['order_type']) === 'ONLINE'): ?>
            <div class="totals-row">
                <span>Delivery Fee:</span>
                <span><?= (($order['delivery_mode'] ?? '') === 'CUSTOMER_BOOKED') ? 'Third-party courier (separate)' : '₱0.00 (Free Within Lagro)' ?></span>
            </div>
        <?php endif; ?>

        <div class="dashed-line"></div>

        <div class="grand-total">
            <span>TOTAL:</span>
            <span>₱<?= number_format((float)$order['total'], 2) ?></span>
        </div>

        <div class="totals-row">
            <span>Payment Method:</span>
            <span><?= htmlspecialchars($paymentMethod) ?></span>
        </div>

        <?php if ($paymentMethod === 'CASH'): ?>
            <div class="totals-row">
                <span>Amount Tendered:</span>
                <span>₱<?= number_format($tendered, 2) ?></span>
            </div>
            <div class="totals-row">
                <span>Change:</span>
                <span>₱<?= number_format($change, 2) ?></span>
            </div>
        <?php elseif (!empty($order['payment_reference'])): ?>
            <div class="totals-row">
                <span>Reference Ref:</span>
                <span><?= htmlspecialchars($order['payment_reference']) ?></span>
            </div>
        <?php endif; ?>

        <div class="dashed-line"></div>

        <div class="text-center" style="margin-top: 10px; font-size: 11px;">
            <div>Thank you for dining with us!</div>
            <div style="font-size: 10px; margin-top: 2px;">Please come again.</div>
            <div style="font-size: 9px; margin-top: 6px; color: #666;">--- Official POS Receipt ---</div>
        </div>
    </div>

    <script>
        const paperSelect = document.getElementById('receipt-paper');
        try {
            const saved = localStorage.getItem('kenjisReceiptPaper');
            if (saved === '58' || saved === '80') paperSelect.value = saved;
        } catch (_) {}
        function prepareReceipt() {
            const width = paperSelect.value === '80' ? 80 : 58;
            document.documentElement.style.setProperty('--receipt-width', width + 'mm');
            document.documentElement.style.setProperty('--receipt-inset', width === 58 ? '5mm' : '3mm');
            // Match the roll length to the complete receipt, with a small feed allowance.
            const height = Math.ceil(document.querySelector('.thermal-receipt').getBoundingClientRect().height * 25.4 / 96) + 5;
            document.getElementById('receipt-page-size').textContent = '@page { size: ' + width + 'mm ' + height + 'mm; margin: 0; }';
        }
        async function printReceipt() {
            if (document.fonts) await document.fonts.ready;
            prepareReceipt();
            window.print();
        }
        paperSelect.addEventListener('change', function () {
            try { localStorage.setItem('kenjisReceiptPaper', paperSelect.value); } catch (_) {}
            prepareReceipt();
        });
        window.addEventListener('beforeprint', prepareReceipt);
        prepareReceipt();
        <?php if ($autoPrint): ?>
        window.addEventListener('load', printReceipt);
        <?php endif; ?>
    </script>

</body>
</html>
