<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'reports';
$title = 'Reports Hub';
$heading = 'Reports Hub';

// Active Tab
$validTabs = ['sales', 'orders', 'inventory', 'products', 'discounts', 'payments'];
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs, true) ? $_GET['tab'] : 'sales';

// Date Range Filtering
$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$fromDate = kk_report_date($_GET['from_date'] ?? null, $defaultFrom);
$toDate = kk_report_date($_GET['to_date'] ?? null, $today);

$fromObject = DateTime::createFromFormat('Y-m-d', $fromDate);
$toObject = DateTime::createFromFormat('Y-m-d', $toDate);

if (!$fromObject || $fromObject->format('Y-m-d') !== $fromDate) {
    $fromDate = $defaultFrom;
    $fromObject = new DateTime($fromDate);
}
if (!$toObject || $toObject->format('Y-m-d') !== $toDate) {
    $toDate = $today;
    $toObject = new DateTime($toDate);
}
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$reportFrom = $fromDate . ' 00:00:00';
$reportTo = (new DateTime($toDate))->modify('+1 day')->format('Y-m-d 00:00:00');
$isSingleDay = ($fromDate === $toDate);

// Subquery for latest payment per order
$settledOrders = kk_report_paid_orders_sql();

$latestPayment = "
    SELECT p1.order_id, p1.payment_method, p1.payment_status, p1.payment_reference, p1.amount_paid,
           COALESCE(p1.payment_proof_path, p1.payment_proof) AS payment_proof_path
    FROM payments p1
    INNER JOIN (
        SELECT order_id, MAX(id) AS max_id
        FROM payments
        GROUP BY order_id
    ) latest ON latest.max_id = p1.id
";

// -------------------------------------------------------------
// TOP SUMMARY METRICS (Global across filtered date range)
// -------------------------------------------------------------
$topSummaryStmt = $conn->prepare("
    SELECT
        COUNT(o.id) AS total_orders,
        COALESCE(SUM(o.total), 0) AS total_revenue,
        COALESCE(SUM(o.discount + o.free_delivery_discount), 0) AS total_discounts,
        COALESCE(AVG(o.total), 0) AS avg_order_value,
        COALESCE(SUM(CASE WHEN COALESCE(p.payment_status, 'Paid') = 'Paid' AND LOWER(TRIM(COALESCE(p.payment_method, 'cash'))) IN ('cash', 'pay on pickup', 'cash on delivery') THEN o.total ELSE 0 END), 0) AS cash_revenue,
        COALESCE(SUM(CASE WHEN COALESCE(p.payment_status, 'Paid') = 'Paid' AND LOWER(TRIM(COALESCE(p.payment_method, ''))) LIKE '%gcash%' THEN o.total ELSE 0 END), 0) AS gcash_revenue
    FROM ($settledOrders) o
    LEFT JOIN ($latestPayment) p ON p.order_id = o.id
    WHERE o.report_paid_at IS NOT NULL
      AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
      AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded')
");
$topSummaryStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
$topSummary = $topSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_orders' => 0, 'total_revenue' => 0, 'total_discounts' => 0,
    'avg_order_value' => 0, 'cash_revenue' => 0, 'gcash_revenue' => 0
];

$globalInventorySummary = $conn->query("
    SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(GREATEST(stock, 0)), 0) AS total_units,
        COALESCE(SUM(GREATEST(stock, 0) * price), 0) AS total_value
    FROM menu_items
    WHERE is_archived = 0
")->fetch(PDO::FETCH_ASSOC) ?: ['total_items' => 0, 'total_units' => 0, 'total_value' => 0];

// -------------------------------------------------------------
// EXCEL / CSV EXPORT HANDLER
// -------------------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv', 'print'], true)) {
    $output = fopen('php://temp', 'w+');
    if ($activeTab === 'sales') {
        kk_report_csv_row($output, ['Order Number', 'Date & Time', 'Channel', 'Order Type', 'Payment Method', 'Subtotal (PHP)', 'Discount (PHP)', 'Total (PHP)', 'Status']);
        $stmt = $conn->prepare("
            SELECT o.order_no, o.report_paid_at AS paid_at, o.order_channel, o.order_type, COALESCE(p.payment_method, 'Cash') AS method,
                   o.subtotal, (o.discount + o.free_delivery_discount) AS discount, o.total, o.status
            FROM ($settledOrders) o
            LEFT JOIN ($latestPayment) p ON p.order_id = o.id
            WHERE o.report_paid_at IS NOT NULL
              AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
              AND o.status NOT IN ('Cancelled','Voided','Refunded')
            ORDER BY o.report_paid_at DESC, o.id DESC
        ");
        $stmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            kk_report_csv_row($output, [
                $row['order_no'], $row['paid_at'], $row['order_channel'], $row['order_type'],
                $row['method'], number_format($row['subtotal'], 2, '.', ''),
                number_format($row['discount'], 2, '.', ''), number_format($row['total'], 2, '.', ''),
                $row['status']
            ]);
        }
    } elseif ($activeTab === 'orders') {
        kk_report_csv_row($output, ['Order Number', 'Date Created', 'Cashier', 'Customer', 'Channel', 'Order Type', 'Total (PHP)', 'Status', 'Paid Status']);
        $stmt = $conn->prepare("
            SELECT o.order_no, o.created_at,
                   COALESCE(NULLIF(TRIM(o.cashier_name), ''), CASE WHEN o.order_channel = 'POS' THEN u.full_name ELSE 'Online Order' END, 'POS') AS cashier,
                   COALESCE(NULLIF(TRIM(o.customer_name), ''), 'Guest') AS customer,
                   o.order_channel, o.order_type, o.total, o.status,
                   COALESCE(p.payment_status, 'Unpaid') AS paid_status
            FROM orders o
            LEFT JOIN ($latestPayment) p ON p.order_id = o.id
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.created_at >= :r_from AND o.created_at < :r_to
            ORDER BY o.created_at DESC, o.id DESC
        ");
        $stmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            kk_report_csv_row($output, [
                $row['order_no'], $row['created_at'], $row['cashier'], $row['customer'], $row['order_channel'],
                $row['order_type'], number_format($row['total'], 2, '.', ''), $row['status'], $row['paid_status']
            ]);
        }
    } elseif ($activeTab === 'inventory') {
        kk_report_csv_row($output, ['Product Name', 'Category', 'Current Stock', 'Stock Status', 'Unit Price (PHP)', 'Total Stock Value (PHP)']);
        $stmt = $conn->query("
            SELECT m.item_name, COALESCE(c.category_name, 'Uncategorized') AS category_name, m.stock, m.price
            FROM menu_items m
            LEFT JOIN categories c ON c.id = m.category_id
            WHERE m.is_archived = 0
            ORDER BY m.stock ASC, m.item_name ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stock = (int)$row['stock'];
            $status = ($stock <= 0) ? 'Out of Stock' : (($stock <= 10) ? 'Low Stock' : 'Available');
            $val = $stock * (float)$row['price'];
            kk_report_csv_row($output, [
                $row['item_name'], $row['category_name'], $stock, $status,
                number_format($row['price'], 2, '.', ''), number_format($val, 2, '.', '')
            ]);
        }
    } elseif ($activeTab === 'products') {
        kk_report_csv_row($output, ['Rank', 'Product Name', 'Category', 'Price (PHP)', 'Units Sold', 'Total Revenue (PHP)']);
        $stmt = $conn->prepare("
            SELECT oi.item_name, COALESCE(c.category_name, 'Uncategorized') AS category_name,
                   MAX(oi.price) AS unit_price,
                   SUM(oi.quantity - COALESCE(oi.voided_quantity, 0)) AS qty_sold,
                   SUM((oi.quantity - COALESCE(oi.voided_quantity, 0)) * oi.price) AS total_revenue
            FROM order_items oi
            INNER JOIN ($settledOrders) o ON o.id = oi.order_id
            LEFT JOIN menu_items m ON m.id = oi.menu_item_id
            LEFT JOIN categories c ON c.id = m.category_id
            WHERE o.report_paid_at IS NOT NULL
              AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
              AND o.status NOT IN ('Cancelled','Voided','Refunded')
              AND (oi.quantity - COALESCE(oi.voided_quantity, 0)) > 0
            GROUP BY oi.menu_item_id, oi.item_name, c.category_name
            ORDER BY qty_sold DESC, total_revenue DESC, oi.menu_item_id, oi.item_name, c.category_name
        ");
        $stmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
        $rank = 1;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            kk_report_csv_row($output, [
                $rank++, $row['item_name'], $row['category_name'],
                number_format($row['unit_price'], 2, '.', ''),
                (int)$row['qty_sold'], number_format($row['total_revenue'], 2, '.', '')
            ]);
        }
    } elseif ($activeTab === 'discounts') {
        kk_report_csv_row($output, ['Order Number', 'Date & Time', 'Discount Type', 'Notes / Customer', 'Order Subtotal (PHP)', 'Discount Amount (PHP)', 'Final Total (PHP)']);
        $stmt = $conn->prepare("
            SELECT o.order_no, o.report_paid_at AS paid_at, o.notes, o.discount_id_number, o.subtotal, o.discount, o.free_delivery_discount, o.total,
                   CASE
                       WHEN LOWER(TRIM(COALESCE(o.discount_type, ''))) = 'senior citizen' OR (TRIM(COALESCE(o.discount_type, '')) = '' AND LOWER(COALESCE(o.notes, '')) LIKE '%senior%') THEN 'Senior Citizen Discount (20%)'
                       WHEN LOWER(TRIM(COALESCE(o.discount_type, ''))) = 'pwd' OR (TRIM(COALESCE(o.discount_type, '')) = '' AND LOWER(COALESCE(o.notes, '')) LIKE '%pwd%') THEN 'PWD Discount (20%)'
                       WHEN LOWER(TRIM(COALESCE(o.discount_type, ''))) = 'student' OR (TRIM(COALESCE(o.discount_type, '')) = '' AND LOWER(COALESCE(o.notes, '')) LIKE '%student%') THEN 'Student Discount (20%)'
                       WHEN o.free_delivery_discount > 0 THEN 'Free Delivery Promo'
                       ELSE 'Standard Order Discount'
                   END AS disc_type
            FROM ($settledOrders) o
            WHERE o.report_paid_at IS NOT NULL
              AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
              AND o.status NOT IN ('Cancelled','Voided','Refunded')
              AND (o.discount > 0 OR o.free_delivery_discount > 0)
            ORDER BY o.report_paid_at DESC, o.id DESC
        ");
        $stmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $disc = (float)$row['discount'] + (float)$row['free_delivery_discount'];
            $noteDisplay = trim((string)($row['notes'] ?? ''));
            if ($noteDisplay === '' && !empty($row['discount_id_number'])) {
                $noteDisplay = 'ID: ' . $row['discount_id_number'];
            }
            kk_report_csv_row($output, [
                $row['order_no'], $row['paid_at'], $row['disc_type'], $noteDisplay !== '' ? $noteDisplay : 'None',
                number_format($row['subtotal'], 2, '.', ''), number_format($disc, 2, '.', ''),
                number_format($row['total'], 2, '.', '')
            ]);
        }
    } elseif ($activeTab === 'payments') {
        kk_report_csv_row($output, ['Payment ID', 'Order Number', 'Payment Method', 'Amount Paid (PHP)', 'Payment Status', 'Reference No', 'Date Paid']);
        $stmt = $conn->prepare("
            SELECT p.id, o.order_no, p.payment_method,
                   (p.amount_paid - COALESCE(p.change_amount, 0)) AS net_amount_paid,
                   p.payment_status, p.payment_reference,
                   CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at) ELSE NULL END AS effective_paid_at
            FROM payments p
            INNER JOIN orders o ON o.id = p.order_id
            WHERE CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END >= :r_from
              AND CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END < :r_to
            ORDER BY CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END DESC, p.id DESC
        ");
        $stmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            kk_report_csv_row($output, [
                $row['id'], $row['order_no'], $row['payment_method'],
                number_format($row['net_amount_paid'], 2, '.', ''),
                $row['payment_status'], $row['payment_reference'] ?: 'N/A', $row['effective_paid_at']
            ]);
        }
    }
    rewind($output);
    $columns = fgetcsv($output);
    $periodLabel = date('m-d-Y', strtotime($fromDate)) . ' to ' . date('m-d-Y', strtotime($toDate));
    $reportName = ucfirst($activeTab) . ' Report';
    $filename = "kenjis_kitchen_{$activeTab}_report_{$fromDate}_to_{$toDate}";
    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo "\xEF\xBB\xBF";
        rewind($output); fpassthru($output);
    } elseif ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        $xml = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cell = static function ($value, $style = 0, $numeric = false) use ($xml) {
            return '<c s="'.$style.'"'.($numeric ? '><v>'.$xml($value).'</v>' : ' t="inlineStr"><is><t xml:space="preserve">'.$xml($value).'</t></is>').'</c>';
        };
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($columns as $i => $col) $sheet .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.(preg_match('/Name|Number|Date|Time|Reference|Method|Customer|Product/i',$col)?28:19).'" customWidth="1"/>';
        $sheet .= '</cols><sheetData><row ht="32" customHeight="1">'.$cell('Kenji’s Kitchen - '.$reportName,1).'</row><row ht="28" customHeight="1">'.$cell('Period: '.$periodLabel).'</row><row ht="36" customHeight="1">';
        foreach ($columns as $col) $sheet .= $cell($col,1);
        $sheet .= '</row>'; $rowIndex=0; $totals=array_fill(0,count($columns),0);
        while (($row=fgetcsv($output))!==false) {
            $sheet .= '<row ht="32" customHeight="1">';
            foreach ($row as $i=>$value) {
                $numeric=preg_match('/PHP|Stock$|Units|Rank|Quantity/i',$columns[$i]) && is_numeric($value);
                if ($numeric) $totals[$i]+=(float)$value;
                if (preg_match('/Date|Time/i',$columns[$i]) && $value && strtotime($value)!==false) $value=date('m-d-Y h:i A',strtotime($value));
                $money = $numeric && str_contains($columns[$i], 'PHP');
                $style = $money ? ($rowIndex%2===0 ? 4 : 3) : ($rowIndex%2===0 ? 2 : 0);
                $sheet .= $cell($value,$style,$numeric);
            }
            $sheet .= '</row>'; $rowIndex++;
        }
        if ($activeTab==='sales') { $sheet.='<row ht="32" customHeight="1">';foreach($columns as $i=>$col) $sheet.=$cell($i===0?'TOTAL':(str_contains($col,'PHP')?$totals[$i]:''),str_contains($col,'PHP')?5:1,str_contains($col,'PHP'));$sheet.='</row>'; }
        $sheet .= '</sheetData><autoFilter ref="A3:'.chr(64+count($columns)).(3+$rowIndex).'"/><mergeCells count="2"><mergeCell ref="A1:'.chr(64+count($columns)).'1"/><mergeCell ref="A2:'.chr(64+count($columns)).'2"/></mergeCells></worksheet>';
        $files = [
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="13"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF7C62F"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF7F7F2"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="6"><xf fontId="0" fillId="0" borderId="0" xfId="0"><alignment horizontal="left" vertical="center" wrapText="1" indent="1"/></xf><xf fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="left" vertical="center" wrapText="1" indent="1"/></xf><xf fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center" wrapText="1" indent="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf><xf numFmtId="4" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf><xf numFmtId="4" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml'=>$sheet
        ];
        // ZIP stored entries keep XLSX generation independent of optional PHP extensions.
        $zip=''; $directory=''; $offset=0;
        foreach($files as $name=>$content) {
            $size=strlen($content);$crc=crc32($content);$length=strlen($name);
            $entry=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$length,0).$name.$content;
            $directory.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$length,0,0,0,0,0,$offset).$name;
            $zip.=$entry;$offset+=strlen($entry);
        }
        echo $zip.$directory.pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($directory),strlen($zip),0);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . e($reportName) . ' - Kenji’s Kitchen</title><style>@page{size:A4 landscape;margin:12mm}body{font:12px Arial;color:#242424;margin:24px}h1{font-size:24px;margin-bottom:8px}p{line-height:1.5}table{width:100%;border-collapse:collapse;table-layout:fixed}th{background:#f7c62f;font-size:13px;text-align:left}td,th{padding:10px 8px;border:1px solid #ddd;overflow-wrap:anywhere}tbody tr:nth-child(even){background:#f7f7f2}thead{display:table-header-group}tr{break-inside:avoid}button{padding:12px 18px;background:#f7c62f;border:0;border-radius:8px;cursor:pointer}@media print{body{margin:0}button{display:none}*{print-color-adjust:exact;-webkit-print-color-adjust:exact}}.preset-pill.active { background:#f7c62f!important; color:#242424!important; font-weight:700; box-shadow:0 1px 4px #0001; }
</style></head><body><button onclick="window.print()">Print / Save as PDF</button><h1>Kenji’s Kitchen - ' . e($reportName) . '</h1><p>Reporting period: ' . e($periodLabel) . '<br>All matching records · Generated ' . date('M d, Y h:i A') . '</p><table><thead><tr>';
        foreach ($columns as $col) echo '<th>' . e($col) . '</th>';
        echo '</tr></thead><tbody>'; $count = 0; $salesTotals = array_fill(0, count($columns), 0);
        while (($row = fgetcsv($output)) !== false) { echo '<tr>'; foreach ($row as $i => $value) { if (str_contains($columns[$i], 'PHP')) $salesTotals[$i] += (float)$value; if (preg_match('/Date|Time/i', $columns[$i]) && $value && strtotime($value) !== false) $value = date('m-d-Y h:i A', strtotime($value)); echo '<td>' . e($value ?? '') . '</td>'; } echo '</tr>'; $count++; }
        if ($activeTab === 'sales') { echo '<tr style="font-weight:bold;background:#fff3c4">'; foreach ($columns as $i => $col) echo '<td>' . ($i === 0 ? 'TOTAL' : (str_contains($col, 'PHP') ? number_format($salesTotals[$i], 2) : '')) . '</td>'; echo '</tr>'; }
        echo '</tbody></table><p>' . number_format($count) . ' records</p></body></html>';
    }
    fclose($output);
    exit;
}

// -------------------------------------------------------------
// TAB DATA FETCHING & PAGINATION
// -------------------------------------------------------------
$perPage = 10;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

// 1. SALES REPORT DATA
$salesRows = [];
$totalSalesCount = 0;
if ($activeTab === 'sales') {
    $countStmt = $conn->prepare("
        SELECT COUNT(o.id)
        FROM ($settledOrders) o
        WHERE o.report_paid_at IS NOT NULL
          AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
    ");
    $countStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $totalSalesCount = (int)$countStmt->fetchColumn();
    $currentPage = max(1, min($currentPage, max(1, (int)ceil($totalSalesCount / $perPage))));
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT o.id, o.order_no, o.order_channel, o.order_type, o.subtotal,
               (o.discount + o.free_delivery_discount) AS total_discount,
               o.total, o.status, o.report_paid_at AS paid_at,
               COALESCE(p.payment_method, 'Cash') AS payment_method
        FROM ($settledOrders) o
        LEFT JOIN ($latestPayment) p ON p.order_id = o.id
        WHERE o.report_paid_at IS NOT NULL
          AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
        ORDER BY o.report_paid_at DESC, o.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':r_from', $reportFrom, PDO::PARAM_STR);
    $stmt->bindValue(':r_to', $reportTo, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $salesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 2. ORDER REPORT DATA
$orderRows = [];
$totalOrderCount = 0;
$orderStatusBreakdown = [];
if ($activeTab === 'orders') {
    // Status breakdown counts
    $statStmt = $conn->prepare("
        SELECT status, COUNT(*) AS count_val, COALESCE(SUM(total), 0) AS sum_val
        FROM orders
        WHERE created_at >= :r_from AND created_at < :r_to
        GROUP BY status
    ");
    $statStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $orderStatusBreakdown = $statStmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $conn->prepare("
        SELECT COUNT(id) FROM orders
        WHERE created_at >= :r_from AND created_at < :r_to
    ");
    $countStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $totalOrderCount = (int)$countStmt->fetchColumn();
    $currentPage = max(1, min($currentPage, max(1, (int)ceil($totalOrderCount / $perPage))));
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT o.id, o.order_no, o.order_channel, o.order_type, o.customer_name, o.customer_phone,
               o.total, o.status, o.created_at, o.paid_at,
               COALESCE(NULLIF(TRIM(o.cashier_name), ''), CASE WHEN o.order_channel = 'POS' THEN u.full_name ELSE 'Online Order' END, 'POS') AS cashier_name,
               COALESCE(p.payment_method, 'Pending') AS payment_method
        FROM orders o
        LEFT JOIN ($latestPayment) p ON p.order_id = o.id
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.created_at >= :r_from AND o.created_at < :r_to
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':r_from', $reportFrom, PDO::PARAM_STR);
    $stmt->bindValue(':r_to', $reportTo, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orderRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3. INVENTORY REPORT DATA
$inventoryRows = [];
$inventoryLogs = [];
$totalInventoryCount = 0;
$invStats = ['total' => 0, 'available' => 0, 'low' => 0, 'out' => 0, 'total_value' => 0];
if ($activeTab === 'inventory') {
    $statStmt = $conn->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN stock > 10 THEN 1 ELSE 0 END), 0) AS available,
            COALESCE(SUM(CASE WHEN stock > 0 AND stock <= 10 THEN 1 ELSE 0 END), 0) AS low,
            COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END), 0) AS `out`,
            COALESCE(SUM(stock * price), 0) AS total_value
        FROM menu_items
        WHERE is_archived = 0
    ");
    $invStats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: $invStats;

    $countStmt = $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_archived = 0");
    $totalInventoryCount = (int)$countStmt->fetchColumn();
    $currentPage = max(1, min($currentPage, max(1, (int)ceil($totalInventoryCount / $perPage))));
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT m.id, m.item_name, m.price, m.stock, m.availability,
               COALESCE(c.category_name, 'Uncategorized') AS category_name
        FROM menu_items m
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE m.is_archived = 0
        ORDER BY m.stock ASC, m.item_name ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $inventoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent stock movements
    $logStmt = $conn->prepare("
        SELECT il.id, il.action, il.quantity, il.remarks, il.created_at,
               CASE WHEN il.menu_item_id IS NOT NULL THEN 'pcs' ELSE NULLIF(TRIM(i.unit), '') END AS unit,
               COALESCE(m.item_name, i.ingredient_name, 'Unknown Item') AS item_name,
               COALESCE(u.username, 'System') AS user_name
        FROM inventory_logs il
        LEFT JOIN menu_items m ON m.id = il.menu_item_id
        LEFT JOIN inventory i ON i.id = il.inventory_id
        LEFT JOIN users u ON u.id = il.user_id
        WHERE il.created_at >= :r_from AND il.created_at < :r_to
        ORDER BY il.created_at DESC, il.id DESC
        LIMIT 10
    ");
    $logStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $inventoryLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. PRODUCT REPORT DATA (Best sellers & Product Performance)
$productRows = [];
$totalProductCount = 0;
if ($activeTab === 'products') {
    $countStmt = $conn->prepare("
        SELECT COUNT(*) FROM (
            SELECT 1
            FROM order_items oi
            INNER JOIN ($settledOrders) o ON o.id = oi.order_id
            LEFT JOIN menu_items m ON m.id = oi.menu_item_id
            LEFT JOIN categories c ON c.id = m.category_id
            WHERE o.report_paid_at IS NOT NULL
              AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
              AND o.status NOT IN ('Cancelled','Voided','Refunded')
              AND (oi.quantity - COALESCE(oi.voided_quantity, 0)) > 0
            GROUP BY oi.menu_item_id, oi.item_name, c.category_name
        ) grouped_products
    ");
    $countStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $totalProductCount = (int)$countStmt->fetchColumn();
    $currentPage = max(1, min($currentPage, max(1, (int)ceil($totalProductCount / $perPage))));
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT oi.menu_item_id, oi.item_name,
               COALESCE(c.category_name, 'Uncategorized') AS category_name,
               MAX(oi.price) AS unit_price,
               SUM(oi.quantity - COALESCE(oi.voided_quantity, 0)) AS qty_sold,
               SUM((oi.quantity - COALESCE(oi.voided_quantity, 0)) * oi.price) AS total_revenue
        FROM order_items oi
        INNER JOIN ($settledOrders) o ON o.id = oi.order_id
        LEFT JOIN menu_items m ON m.id = oi.menu_item_id
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE o.report_paid_at IS NOT NULL
          AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
          AND (oi.quantity - COALESCE(oi.voided_quantity, 0)) > 0
        GROUP BY oi.menu_item_id, oi.item_name, c.category_name
        ORDER BY qty_sold DESC, total_revenue DESC, oi.menu_item_id, oi.item_name, c.category_name
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':r_from', $reportFrom, PDO::PARAM_STR);
    $stmt->bindValue(':r_to', $reportTo, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $productRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 5. DISCOUNT REPORT DATA
$discountRows = [];
$totalDiscountCount = 0;
$discountSummary = ['student' => 0, 'senior' => 0, 'pwd' => 0, 'delivery' => 0, 'general' => 0, 'total_amount' => 0];
if ($activeTab === 'discounts') {
    // Tally breakdown by discount note/type
    $tallyStmt = $conn->prepare("
        SELECT o.notes, o.discount, o.free_delivery_discount, o.discount_type
        FROM ($settledOrders) o
        WHERE o.report_paid_at IS NOT NULL
          AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
          AND (o.discount > 0 OR o.free_delivery_discount > 0)
    ");
    $tallyStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    while ($r = $tallyStmt->fetch(PDO::FETCH_ASSOC)) {
        $n = strtolower($r['notes'] ?? '');
        $dt = strtolower(trim($r['discount_type'] ?? ''));
        $d = (float)$r['discount'];
        $fd = (float)$r['free_delivery_discount'];
        $discountSummary['total_amount'] += ($d + $fd);
        if ($fd > 0) $discountSummary['delivery'] += $fd;

        if ($dt === 'senior citizen' || ($dt === '' && strpos($n, 'senior') !== false)) {
            $discountSummary['senior'] += $d;
        } elseif ($dt === 'pwd' || ($dt === '' && strpos($n, 'pwd') !== false)) {
            $discountSummary['pwd'] += $d;
        } elseif ($dt === 'student' || ($dt === '' && strpos($n, 'student') !== false)) {
            $discountSummary['student'] += $d;
        } else {
            $discountSummary['general'] += $d;
        }
    }

    $countStmt = $conn->prepare("
        SELECT COUNT(id) FROM ($settledOrders) report_orders
        WHERE report_paid_at IS NOT NULL
          AND report_paid_at >= :r_from AND report_paid_at < :r_to
          AND status NOT IN ('Cancelled','Voided','Refunded')
          AND (discount > 0 OR free_delivery_discount > 0)
    ");
    $countStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $totalDiscountCount = (int)$countStmt->fetchColumn();
    $currentPage = max(1, min($currentPage, max(1, (int)ceil($totalDiscountCount / $perPage))));
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT o.id, o.order_no, o.report_paid_at AS paid_at, o.notes, o.subtotal, o.discount, o.free_delivery_discount, o.total,
               o.discount_type, o.discount_id_number,
               COALESCE(p.payment_method, 'Cash') AS payment_method
        FROM ($settledOrders) o
        LEFT JOIN ($latestPayment) p ON p.order_id = o.id
        WHERE o.report_paid_at IS NOT NULL
          AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
          AND o.status NOT IN ('Cancelled','Voided','Refunded')
          AND (o.discount > 0 OR o.free_delivery_discount > 0)
        ORDER BY o.report_paid_at DESC, o.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':r_from', $reportFrom, PDO::PARAM_STR);
    $stmt->bindValue(':r_to', $reportTo, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $discountRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 6. PAYMENT REPORT DATA
$paymentRows = [];
$totalPaymentCount = 0;
$paymentBreakdown = [];
if ($activeTab === 'payments') {
    $statStmt = $conn->prepare("
        SELECT p.payment_method,
               COUNT(*) AS count_val,
               COALESCE(SUM(p.amount_paid - COALESCE(p.change_amount, 0)), 0) AS total_val
        FROM payments p
        INNER JOIN ($settledOrders) o ON o.report_payment_id = p.id
        WHERE o.report_paid_at IS NOT NULL
          AND o.report_paid_at >= :r_from
          AND o.report_paid_at < :r_to
          AND p.payment_status = 'Paid'
          AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded')
        GROUP BY p.payment_method
        ORDER BY total_val DESC
    ");
    $statStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $paymentBreakdown = $statStmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $conn->prepare("
        SELECT COUNT(p.id)
        FROM payments p
        INNER JOIN orders o ON o.id = p.order_id
        WHERE CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END >= :r_from
          AND CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END < :r_to
    ");
    $countStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
    $totalPaymentCount = (int)$countStmt->fetchColumn();
    $currentPage = max(1, min($currentPage, max(1, (int)ceil($totalPaymentCount / $perPage))));
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT p.id, p.payment_method,
               (p.amount_paid - COALESCE(p.change_amount, 0)) AS amount_paid,
               p.amount_paid AS gross_amount_paid,
               p.change_amount, p.payment_status,
               p.payment_reference,
               CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at) ELSE NULL END AS paid_at,
               o.order_no, o.order_channel, o.order_type
        FROM payments p
        INNER JOIN orders o ON o.id = p.order_id
        WHERE CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END >= :r_from
          AND CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END < :r_to
        ORDER BY CASE WHEN p.payment_status IN ('Paid','Refunded','Voided') THEN COALESCE(o.paid_at, p.paid_at, o.created_at) ELSE o.created_at END DESC, p.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':r_from', $reportFrom, PDO::PARAM_STR);
    $stmt->bindValue(':r_to', $reportTo, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $paymentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------
// SUMMARY CHARTS DATA (Daily Timeline & Category Share)
// -------------------------------------------------------------
// 1. Timeline Chart (Daily revenue over the range)
$timelineStmt = $conn->prepare("
    SELECT DATE(report_paid_at) AS sale_date,
           COUNT(id) AS daily_orders,
           COALESCE(SUM(total), 0) AS daily_rev
    FROM ($settledOrders) report_orders
    WHERE report_paid_at IS NOT NULL
      AND report_paid_at >= :r_from AND report_paid_at < :r_to
      AND status NOT IN ('Cancelled','Voided','Refunded')
    GROUP BY DATE(report_paid_at)
    ORDER BY sale_date ASC
");
$timelineStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
$timelineRaw = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);

$chartLabels = [];
$chartRevenue = [];
$chartOrders = [];
$timelineByDate = array_column($timelineRaw, null, 'sale_date');
$chartDay = new DateTime($fromDate);
$chartEnd = new DateTime($toDate);
$timelineDayCount = (int)$chartDay->diff($chartEnd)->days + 1;
$timelineGrouping = $timelineDayCount <= 31 ? 'Daily' : ($timelineDayCount <= 120 ? 'Weekly' : 'Monthly');
$timelineBuckets = [];
while ($chartDay <= $chartEnd) {
    $t = $timelineByDate[$chartDay->format('Y-m-d')] ?? ['daily_rev' => 0, 'daily_orders' => 0];
    $key = match ($timelineGrouping) {
        'Monthly' => $chartDay->format('Y-m'),
        'Weekly' => (clone $chartDay)->modify('monday this week')->format('Y-m-d'),
        default => $chartDay->format('Y-m-d')
    };
    if (!isset($timelineBuckets[$key])) {
        $timelineBuckets[$key] = ['start' => clone $chartDay, 'end' => clone $chartDay, 'revenue' => 0, 'orders' => 0];
    }
    $timelineBuckets[$key]['end'] = clone $chartDay;
    $timelineBuckets[$key]['revenue'] += (float)$t['daily_rev'];
    $timelineBuckets[$key]['orders'] += (int)$t['daily_orders'];
    $chartDay->modify('+1 day');
}
foreach ($timelineBuckets as $bucket) {
    $chartLabels[] = $bucket['start'] == $bucket['end']
        ? $bucket['start']->format('M d, Y')
        : $bucket['start']->format('M d, Y') . ' – ' . $bucket['end']->format('M d, Y');
    $chartRevenue[] = round($bucket['revenue'], 2);
    $chartOrders[] = $bucket['orders'];
}

// 2. Category / Channel Share Doughnut
$catShareStmt = $conn->prepare("
    SELECT COALESCE(c.category_name, 'Other') AS cat_name,
           SUM((oi.quantity - COALESCE(oi.voided_quantity, 0)) * oi.price) AS cat_revenue
    FROM order_items oi
    INNER JOIN ($settledOrders) o ON o.id = oi.order_id
    LEFT JOIN menu_items m ON m.id = oi.menu_item_id
    LEFT JOIN categories c ON c.id = m.category_id
    WHERE o.report_paid_at IS NOT NULL
      AND o.report_paid_at >= :r_from AND o.report_paid_at < :r_to
      AND o.status NOT IN ('Cancelled','Voided','Refunded')
      AND (oi.quantity - COALESCE(oi.voided_quantity, 0)) > 0
    GROUP BY cat_name
    ORDER BY cat_revenue DESC, cat_name ASC
");
$catShareStmt->execute([':r_from' => $reportFrom, ':r_to' => $reportTo]);
$catShareRaw = $catShareStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($catShareRaw) > 6) {
    $remainder = array_splice($catShareRaw, 5);
    $catShareRaw[] = ['cat_name' => 'Other categories', 'cat_revenue' => array_sum(array_column($remainder, 'cat_revenue'))];
}

$catLabels = [];
$catData = [];
$catColors = ['#F2C12E', '#39B54A', '#2563EB', '#F97316', '#8B5CF6', '#EC4899'];
foreach ($catShareRaw as $c) {
    $catLabels[] = $c['cat_name'];
    $catData[] = (float)$c['cat_revenue'];
}

// Helpers
if (!function_exists('money')) {
    function money($v) {
        return '₱' . number_format((float)$v, 2);
    }
}

// Calculate active tab pagination total
$activeCount = match ($activeTab) {
    'sales' => $totalSalesCount,
    'orders' => $totalOrderCount,
    'inventory' => $totalInventoryCount,
    'products' => $totalProductCount,
    'discounts' => $totalDiscountCount,
    'payments' => $totalPaymentCount,
    default => 0
};
$totalPages = max(1, (int)ceil($activeCount / $perPage));

include ROOT_PATH . '/includes/header.php';
?>

<style>
/* ==============================================================
   REPORTS HUB - Olive Hospitality / Clean Executive UI
   Kenji's Kitchen Brand: #F2C12E Gold, #1E1E1E Dark, #F8FAFC Canvas
   ============================================================== */
.reports-hub-wrapper {
    max-width: 1560px;
    margin: 0 auto;
    padding: 0.5rem 0.75rem 3rem;
    color: #1e293b;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

/* Header & Controls Bar */
.reports-header-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
}

.reports-heading h1 {
    font-size: 1.55rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.025em;
    margin: 0 0 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.reports-heading p {
    font-size: 0.86rem;
    color: #64748b;
    margin: 0;
}

.reports-actions-group {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}

/* Date Preset Pills */
.date-preset-pills {
    display: flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 10px;
    gap: 2px;
}

.preset-pill {
    background: transparent;
    border: none;
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}

.preset-pill:hover {
    color: #0f172a;
    background: rgba(255,255,255,0.6);
}

.preset-pill.active, .preset-pill.active:hover {
    background: #f7c62f;
    color: #242424;
    font-weight: 700;
    box-shadow: 0 2px 5px rgba(160,115,0,0.18);
}

/* Date Range Form */
.date-filter-form {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 0.25rem 0.6rem;
}

.date-filter-form input[type="date"] {
    border: none;
    font-size: 0.82rem;
    font-weight: 600;
    color: #334155;
    background: transparent;
    padding: 0.35rem 0.25rem;
    outline: none;
}

.date-filter-form span {
    color: #94a3b8;
    font-size: 0.8rem;
    font-weight: 600;
}

.btn-filter-apply {
    background: #1e1e1e;
    color: #ffffff;
    border: none;
    padding: 0.4rem 0.75rem;
    font-size: 0.8rem;
    font-weight: 600;
    border-radius: 7px;
    cursor: pointer;
    transition: background 0.15s ease;
}

.btn-filter-apply:hover {
    background: #333333;
}

/* Export & Action Buttons */
.btn-export {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 0.95rem;
    font-size: 0.82rem;
    font-weight: 700;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
}

.btn-export:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}

.btn-export.btn-excel {
    color: #15803d;
    border-color: #bbf7d0;
    background: #f0fdf4;
}

.btn-export.btn-excel:hover {
    background: #dcfce7;
    border-color: #86efac;
}

.btn-export.btn-print {
    color: #1e293b;
    border-color: #cbd5e1;
}

/* TOP KPI METRICS ROW (Olive Style) */
.reports-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.kpi-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.15rem 1.25rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.02);
    position: relative;
    overflow: hidden;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    text-decoration: none;
    color: inherit;
    display: block;
}

.kpi-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.kpi-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: #e2e8f0;
}

.kpi-stat-card.accent-gold::before { background: #F2C12E; }
.kpi-stat-card.accent-green::before { background: #10b981; }
.kpi-stat-card.accent-blue::before { background: #3b82f6; }
.kpi-stat-card.accent-amber::before { background: #f59e0b; }
.kpi-stat-card.accent-purple::before { background: #8b5cf6; }
.kpi-stat-card.accent-cyan::before { background: #06b6d4; }
.kpi-stat-card.accent-emerald::before { background: #059669; }

.kpi-stat-label {
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    margin-bottom: 0.4rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.kpi-stat-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    letter-spacing: -0.02em;
}

.kpi-stat-hint {
    font-size: 0.74rem;
    color: #94a3b8;
    margin-top: 0.35rem;
    font-weight: 500;
}

/* REPORT CATEGORIES / NAVIGATION TABS (Dribbble/Olive pill bar) */
.reports-nav-tabs-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    margin-bottom: 1.25rem;
    overflow: hidden;
}

.tab-scroll-btn {
    flex-shrink: 0;
    display: none;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    margin: 0.4rem;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    color: #475569;
    font-size: 0.85rem;
    transition: all 0.15s ease;
    z-index: 2;
    flex: 0 0 36px;
}

.tab-scroll-btn:hover {
    background: #1e1e1e;
    color: #F2C12E;
    border-color: #1e1e1e;
}

.tab-scroll-btn.visible {
    display: inline-flex;
}

.reports-nav-tabs {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.25rem;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -ms-overflow-style: none;
    min-width: 0;
}

.reports-nav-tabs::-webkit-scrollbar {
    display: none;
}

.report-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.65rem 1.15rem;
    font-size: 0.88rem;
    font-weight: 700;
    color: #64748b;
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.18s ease;
    white-space: nowrap;
    border: 1px solid transparent;
}

.report-tab-btn i {
    font-size: 1rem;
}

.report-tab-btn:hover {
    color: #0f172a;
    background: #f8fafc;
}

.report-tab-btn.active {
    background: #1e1e1e;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

.report-tab-btn.active i {
    color: #F2C12E;
}

.report-tab-badge {
    background: rgba(255,255,255,0.2);
    color: inherit;
    font-size: 0.72rem;
    padding: 0.15rem 0.5rem;
    border-radius: 20px;
    font-weight: 700;
}

.report-tab-btn:not(.active) .report-tab-badge {
    background: #f1f5f9;
    color: #475569;
}

/* SUMMARY CHARTS SECTION (Timeline & Share) */
.reports-charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1.25rem;
    margin-bottom: 1.5rem;
}

.reports-charts-grid > *,
.chart-panel-card,
.timeline-chart-card {
    min-width: 0;
    max-width: 100%;
}

.chart-panel-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1.35rem 1.5rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.02);
}

.chart-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.chart-panel-title h3 {
    font-size: 1.05rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}

.chart-panel-title p {
    font-size: 0.8rem;
    color: #64748b;
    margin: 0.2rem 0 0;
}

.chart-container-box {
    position: relative;
    height: 250px;
    width: 100%;
}

.timeline-chart-card {
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.05);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
}

.timeline-chart-card .chart-panel-header {
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 20px;
}

.timeline-chart-card .chart-panel-title h3 {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
}

.timeline-chart-card .chart-container-box { height: 290px; }

.timeline-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    padding: 14px 18px;
    background: #f8fafc;
    border: 1px solid #edf2f7;
    border-radius: 12px;
    margin-bottom: 20px;
}

.timeline-metrics span {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.timeline-metrics strong {
    display: block;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin-top: 3px;
    overflow-wrap: anywhere;
}

.timeline-metrics > div {
    position: relative;
    padding-left: 36px;
}

.timeline-metrics > div > i {
    position: absolute;
    left: 0;
    top: 3px;
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 9px;
    background: #fff1bd;
    color: #9a6700;
    font-size: 15px;
}

@media (max-width: 540px) {
    .timeline-metrics { grid-template-columns: 1fr; gap: 12px; }
}

/* MAIN REPORT DATA TABLE SURFACES */
.report-content-panel {
    scroll-margin-top: 84px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.025);
    margin-bottom: 1.5rem;
}

.report-panel-topbar {
    padding: 1.15rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    background: #ffffff;
}

.report-panel-title-area h2 {
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}

.report-panel-title-area p {
    font-size: 0.82rem;
    color: #64748b;
    margin: 0.2rem 0 0;
}

.table-responsive {
    overflow-x: auto;
}

.report-data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    text-align: left;
}

.report-data-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.report-data-table td {
    padding: 0.95rem 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}

.report-data-table tr:hover td {
    background: #fdfdfd;
}

.order-code-badge {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.82rem;
    font-weight: 700;
    color: #0f172a;
    background: #f1f5f9;
    padding: 0.25rem 0.55rem;
    border-radius: 6px;
    display: inline-block;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.28rem 0.7rem;
    border-radius: 20px;
    font-size: 0.74rem;
    font-weight: 700;
    white-space: nowrap;
}

.status-pill.status-completed { background: #dcfce7; color: #15803d; }
.status-pill.status-pending { background: #fef3c7; color: #b45309; }
.status-pill.status-cancelled { background: #fee2e2; color: #b91c1c; }
.status-pill.status-ready { background: #e0e7ff; color: #3730a3; }
.status-pill.status-preparing { background: #e0f2fe; color: #0369a1; }
.status-pill.status-available { background: #dcfce7; color: #15803d; }
.status-pill.status-low { background: #ffedd5; color: #c2410c; }
.status-pill.status-out { background: #fee2e2; color: #b91c1c; }

/* Subcards & Mini Summaries inside tabs */
.sub-stat-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.sub-stat-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.9rem 1rem;
}

.sub-stat-box span {
    font-size: 0.74rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    display: block;
    margin-bottom: 0.25rem;
}

.sub-stat-box strong {
    font-size: 1.25rem;
    font-weight: 800;
    color: #0f172a;
}

/* Pagination Bar */
.report-pagination-bar {
    padding: 1rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid #f1f5f9;
    background: #ffffff;
    font-size: 0.84rem;
    color: #64748b;
}

.pagination-links {
    display: flex;
    gap: 0.4rem;
}

.page-num-btn {
    min-width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    border: 1px solid #e2e8f0;
    color: #475569;
    background: #ffffff;
    transition: all 0.15s ease;
}

.page-num-btn:hover {
    border-color: #cbd5e1;
    color: #0f172a;
    background: #f8fafc;
}

.page-num-btn.active {
    background: #1e1e1e;
    color: #ffffff;
    border-color: #1e1e1e;
}

/* Empty State */
.table-empty-state {
    padding: 3.5rem 1.5rem;
    text-align: center;
    color: #94a3b8;
}

.table-empty-state i {
    font-size: 2.75rem;
    color: #cbd5e1;
    margin-bottom: 0.75rem;
    display: block;
}

.table-empty-state h4 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #475569;
    margin: 0 0 0.25rem;
}

.table-empty-state p {
    font-size: 0.84rem;
    margin: 0;
}

/* PRINT STYLES */
@media print {
    body * {
        visibility: hidden;
    }
    .reports-hub-wrapper,
    .reports-hub-wrapper * {
        visibility: visible;
    }
    .reports-hub-wrapper {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0;
        margin: 0;
    }
    .reports-actions-group,
    .reports-nav-tabs-wrapper,
    .reports-nav-tabs,
    .tab-scroll-btn,
    .report-pagination-bar,
    .btn-export,
    .btn-filter-apply,
    .date-preset-pills {
        display: none !important;
    }
    .kpi-stat-card,
    .chart-panel-card,
    .report-content-panel {
    scroll-margin-top: 84px;
        box-shadow: none !important;
        border: 1px solid #ccc !important;
        break-inside: avoid;
    }
    .reports-kpi-grid {
        grid-template-columns: repeat(3, 1fr) !important;
    }
    .reports-charts-grid {
        grid-template-columns: 1fr !important;
    }
}

/* Responsive adjustments */
@media (max-width: 1200px) {
    .reports-kpi-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .reports-charts-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .reports-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .reports-header-panel {
        flex-direction: column;
        align-items: stretch;
    }
    .reports-actions-group {
        flex-direction: column;
        align-items: stretch;
    }
    .date-filter-form {
        flex-wrap: wrap;
    }
    .chart-panel-card,
    .timeline-chart-card {
        padding: 16px;
    }
}

@media (max-width: 480px) {
    .reports-kpi-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="reports-hub-wrapper">
    <!-- Top Executive Header & Filter Bar -->
    <header class="reports-header-panel">
        <div class="reports-heading">
            <h1><i class="bi bi-graph-up-arrow" style="color:#F2C12E;"></i> Reports Hub</h1>
            <p>Comprehensive operational reports, revenue metrics, inventory, discounts, and payment intelligence.</p>
        </div>

        <div class="reports-actions-group">
            <!-- Quick Date Presets -->
            <div class="date-preset-pills">
                <a href="?tab=<?= $activeTab ?>&from_date=<?= $today ?>&to_date=<?= $today ?>"
                   class="preset-pill <?= ($fromDate === $today && $toDate === $today) ? 'active' : '' ?>">Today</a>
                <a href="?tab=<?= $activeTab ?>&from_date=<?= date('Y-m-d', strtotime('monday this week')) ?>&to_date=<?= date('Y-m-d', strtotime('sunday this week')) ?>"
                   class="preset-pill <?= ($fromDate === date('Y-m-d', strtotime('monday this week')) && $toDate === min($today, date('Y-m-d', strtotime('sunday this week')))) ? 'active' : '' ?>">Week</a>
                <a href="?tab=<?= $activeTab ?>&from_date=<?= date('Y-m-01') ?>&to_date=<?= date('Y-m-t') ?>"
                   class="preset-pill <?= ($fromDate === date('Y-m-01') && $toDate === min($today, date('Y-m-t'))) ? 'active' : '' ?>">Month</a>
                <a href="?tab=<?= $activeTab ?>&from_date=<?= date('Y-01-01') ?>&to_date=<?= date('Y-12-31') ?>"
                   class="preset-pill <?= ($fromDate === date('Y-01-01') && $toDate === min($today, date('Y-12-31'))) ? 'active' : '' ?>">Year</a>
            </div>

            <!-- Custom Date Range Form -->
            <form method="GET" class="date-filter-form">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" required>
                <span>to</span>
                <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" required>
                <button type="submit" class="btn-filter-apply">Filter</button>
            </form>

            <!-- Export to Excel (CSV) -->
            <a href="?tab=<?= $activeTab ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&export=excel" class="btn-export btn-excel" title="Download formatted Excel workbook (.xlsx)">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export Excel
            </a>

            <!-- Print / PDF -->
            <a class="btn-export btn-print" href="?tab=<?= $activeTab ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&export=print" target="_blank" rel="noopener" title="Print all filtered records">
                <i class="bi bi-printer"></i> Print / PDF
            </a>
        </div>
    </header>

    <!-- 6 Executive Summary KPI Cards (Dribbble Olive Hospitality Style) -->
    <section class="reports-kpi-grid">
        <div class="kpi-stat-card accent-gold">
            <div class="kpi-stat-label">
                <span>Total Revenue</span>
                <i class="bi bi-currency-exchange" style="color:#d97706;"></i>
            </div>
            <div class="kpi-stat-value"><?= money($topSummary['total_revenue']) ?></div>
            <div class="kpi-stat-hint"><?= number_format($topSummary['total_orders']) ?> paid orders</div>
        </div>

        <div class="kpi-stat-card accent-green">
            <div class="kpi-stat-label">
                <span>Cash Revenue</span>
                <i class="bi bi-cash-stack" style="color:#10b981;"></i>
            </div>
            <div class="kpi-stat-value"><?= money($topSummary['cash_revenue']) ?></div>
            <div class="kpi-stat-hint">POS + COD + Cash pickup</div>
        </div>

        <div class="kpi-stat-card accent-blue">
            <div class="kpi-stat-label">
                <span>GCash Revenue</span>
                <i class="bi bi-qr-code" style="color:#3b82f6;"></i>
            </div>
            <div class="kpi-stat-value"><?= money($topSummary['gcash_revenue']) ?></div>
            <div class="kpi-stat-hint">QR & Online payments</div>
        </div>

        <div class="kpi-stat-card accent-purple">
            <div class="kpi-stat-label">
                <span>Avg. Order Value</span>
                <i class="bi bi-receipt" style="color:#8b5cf6;"></i>
            </div>
            <div class="kpi-stat-value"><?= money($topSummary['avg_order_value']) ?></div>
            <div class="kpi-stat-hint">Per completed ticket</div>
        </div>

        <div class="kpi-stat-card accent-amber">
            <div class="kpi-stat-label">
                <span>Discounts Given</span>
                <i class="bi bi-tags" style="color:#f59e0b;"></i>
            </div>
            <div class="kpi-stat-value"><?= money($topSummary['total_discounts']) ?></div>
            <div class="kpi-stat-hint">Student, Senior, PWD & Promos</div>
        </div>

        <div class="kpi-stat-card accent-cyan">
            <div class="kpi-stat-label">
                <span>Orders Fulfilled</span>
                <i class="bi bi-bag-check" style="color:#06b6d4;"></i>
            </div>
            <div class="kpi-stat-value"><?= number_format($topSummary['total_orders']) ?></div>
            <div class="kpi-stat-hint">Within selected date range</div>
        </div>

        <a href="?tab=inventory&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>#report-table-panel" class="kpi-stat-card accent-emerald" title="View per-product inventory valuation">
            <div class="kpi-stat-label">
                <span>Inventory Value</span>
                <i class="bi bi-boxes" style="color:#059669;"></i>
            </div>
            <div class="kpi-stat-value"><?= money($globalInventorySummary['total_value']) ?></div>
            <div class="kpi-stat-hint"><?= number_format((int)$globalInventorySummary['total_units']) ?> pcs across <?= number_format((int)$globalInventorySummary['total_items']) ?> items</div>
        </a>
    </section>

    <!-- Report Categories / Navigation Tabs -->
    <div class="reports-nav-tabs-wrapper">
        <button type="button" class="tab-scroll-btn" id="tabScrollLeft" title="Scroll left">
            <i class="bi bi-chevron-left"></i>
        </button>

        <nav class="reports-nav-tabs" id="reportsNavTabs">
            <a href="?tab=sales&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>"
               class="report-tab-btn <?= $activeTab === 'sales' ? 'active' : '' ?>">
                <i class="bi bi-cash-coin"></i> Sales Report
                <span class="report-tab-badge">Revenue</span>
            </a>

            <a href="?tab=orders&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>"
               class="report-tab-btn <?= $activeTab === 'orders' ? 'active' : '' ?>">
                <i class="bi bi-list-check"></i> Order Report
                <span class="report-tab-badge">Status</span>
            </a>

            <a href="?tab=inventory&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>"
               class="report-tab-btn <?= $activeTab === 'inventory' ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i> Inventory Report
                <span class="report-tab-badge">Stock & Movement</span>
            </a>

            <a href="?tab=products&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>"
               class="report-tab-btn <?= $activeTab === 'products' ? 'active' : '' ?>">
                <i class="bi bi-trophy"></i> Product Report
                <span class="report-tab-badge">Best Sellers</span>
            </a>

            <a href="?tab=discounts&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>"
               class="report-tab-btn <?= $activeTab === 'discounts' ? 'active' : '' ?>">
                <i class="bi bi-percent"></i> Discount Report
                <span class="report-tab-badge">Senior / PWD / Promo</span>
            </a>

            <a href="?tab=payments&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>"
               class="report-tab-btn <?= $activeTab === 'payments' ? 'active' : '' ?>">
                <i class="bi bi-credit-card"></i> Payment Report
                <span class="report-tab-badge">Cash vs GCash</span>
            </a>
        </nav>

        <button type="button" class="tab-scroll-btn" id="tabScrollRight" title="Scroll right">
            <i class="bi bi-chevron-right"></i>
        </button>
    </div>

    <!-- Summary Charts Section (Daily Timeline & Category Breakdown) -->
    <section class="reports-charts-grid">
        <div class="chart-panel-card timeline-chart-card">
            <div class="chart-panel-header">
                <div class="chart-panel-title">
                    <h3>Revenue & Order Timeline</h3>
                    <p><?= e($timelineGrouping) ?> sales and paid orders. Hover over the chart for details.</p>
                </div>
                <span class="order-code-badge"><?= htmlspecialchars($fromDate) ?> ~ <?= htmlspecialchars($toDate) ?></span>
            </div>
            <div class="timeline-metrics">
                <div><i class="bi bi-cash-stack" aria-hidden="true"></i><span>Total Revenue</span><strong><?= money($topSummary['total_revenue']) ?></strong></div>
                <div><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span>Average Daily Sales</span><strong><?= money((float)$topSummary['total_revenue'] / $timelineDayCount) ?></strong></div>
                <div><i class="bi bi-receipt" aria-hidden="true"></i><span>Paid Orders</span><strong><?= number_format((int)$topSummary['total_orders']) ?></strong></div>
            </div>
            <div class="chart-container-box">
                <canvas id="reportsTimelineChart"></canvas>
            </div>
        </div>

        <div class="chart-panel-card">
            <div class="chart-panel-header">
                <div class="chart-panel-title">
                    <h3>Category Revenue Share</h3>
                    <p>Top category contributions.</p>
                </div>
            </div>
            <div class="chart-container-box">
                <canvas id="reportsCategoryChart"></canvas>
            </div>
        </div>
    </section>

    <!-- ACTIVE REPORT TAB CONTENT -->
    <main class="report-content-panel" id="report-table-panel">
        <!-- Pagination Controls -->
        <?php if ($activeCount > 0): ?>
            <footer class="report-pagination-bar">
                <div>
                    Showing <?= min($activeCount, ($currentPage - 1) * $perPage + 1) ?>–<?= min($activeCount, $currentPage * $perPage) ?> entries · page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong> (<?= number_format($activeCount) ?> total entries)
                </div>
                <div class="pagination-links"><a class="page-num-btn" aria-label="First page" href="?tab=<?= $activeTab ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&p=1">&lt;&lt;</a>
                    <?php if ($currentPage > 1): ?>
                        <a href="?tab=<?= $activeTab ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&p=<?= $currentPage - 1 ?>" class="page-num-btn">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php
                    $startP = max(1, $currentPage - 2);
                    $endP = min($totalPages, $currentPage + 2);
                    for ($p = $startP; $p <= $endP; $p++):
                    ?>
                        <a href="?tab=<?= $activeTab ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&p=<?= $p ?>"
                           class="page-num-btn <?= $p === $currentPage ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?tab=<?= $activeTab ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&p=<?= $currentPage + 1 ?>" class="page-num-btn">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
<a class="page-num-btn" aria-label="Last page" href="?tab=<?= $activeTab ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&p=<?= $totalPages ?>">&gt;&gt;</a>
                </div>
            </footer>
        <?php endif; ?>


        <!-- ========================================== -->
        <!-- 1. SALES REPORT TAB                        -->
        <!-- ========================================== -->
        <?php if ($activeTab === 'sales'): ?>
            <div class="report-panel-topbar">
                <div class="report-panel-title-area">
                    <h2>Sales Transactions (Daily, Weekly, Monthly)</h2>
                    <p>Listing all finalized, paid orders with individual breakdown of subtotals, discounts, and payments.</p>
                </div>
                <div>
                    <span class="order-code-badge"><?= number_format($totalSalesCount) ?> records found</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="report-data-table">
                    <thead>
                        <tr>
                            <th>Order No</th>
                            <th>Paid At</th>
                            <th>Channel</th>
                            <th>Type</th>
                            <th>Payment Method</th>
                            <th>Subtotal</th>
                            <th>Discount</th>
                            <th>Final Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($salesRows)): ?>
                            <tr>
                                <td colspan="9">
                                    <div class="table-empty-state">
                                        <i class="bi bi-inbox"></i>
                                        <h4>No sales recorded for this period</h4>
                                        <p>Try adjusting your date range filter above.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($salesRows as $row): ?>
                                <tr>
                                    <td>
                                        <span class="order-code-badge"><?= htmlspecialchars($row['order_no']) ?></span>
                                    </td>
                                    <td style="font-size:0.8rem; color:#475569;">
                                        <?= $row['paid_at'] ? date('M d, Y h:i A', strtotime($row['paid_at'])) : '-' ?>
                                    </td>
                                    <td>
                                        <span style="font-weight:700; color:<?= $row['order_channel'] === 'POS' ? '#d97706' : '#2563eb' ?>;">
                                            <?= htmlspecialchars($row['order_channel']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight:600; color:#475569;">
                                            <?= htmlspecialchars($row['order_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="order-code-badge" style="background:#f8fafc; border:1px solid #e2e8f0;">
                                            <i class="bi <?= stripos($row['payment_method'], 'gcash') !== false ? 'bi-qr-code text-primary' : 'bi-cash text-success' ?>"></i>
                                            <?= htmlspecialchars($row['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td><?= money($row['subtotal']) ?></td>
                                    <td style="color: <?= $row['total_discount'] > 0 ? '#b91c1c' : '#94a3b8' ?>; font-weight:600;">
                                        <?= $row['total_discount'] > 0 ? '-' . money($row['total_discount']) : '₱0.00' ?>
                                    </td>
                                    <td style="font-weight:800; color:#0f172a; font-size:0.95rem;">
                                        <?= money($row['total']) ?>
                                    </td>
                                    <td>
                                        <span class="status-pill status-completed">
                                            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ========================================== -->
        <!-- 2. ORDER REPORT TAB                        -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'orders'): ?>
            <div class="report-panel-topbar">
                <div class="report-panel-title-area">
                    <h2>Orders Fulfillment & Status Report</h2>
                    <p>Track completed, pending, preparing, ready, and cancelled tickets across POS and Online channels.</p>
                </div>
                <div>
                    <span class="order-code-badge"><?= number_format($totalOrderCount) ?> orders total</span>
                </div>
            </div>

            <!-- Mini Summary of Statuses -->
            <div class="sub-stat-summary">
                <?php foreach ($orderStatusBreakdown as $sb): ?>
                    <div class="sub-stat-box">
                        <span><?= htmlspecialchars($sb['status']) ?> Orders</span>
                        <strong><?= number_format($sb['count_val']) ?></strong>
                        <small style="display:block; color:#94a3b8; font-size:0.75rem; margin-top:2px;"><?= money($sb['sum_val']) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="table-responsive">
                <table class="report-data-table">
                    <thead>
                        <tr>
                            <th>Order No</th>
                            <th>Date Created</th>
                            <th>Cashier</th>
                            <th>Customer</th>
                            <th>Channel</th>
                            <th>Type</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Order Status</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orderRows)): ?>
                            <tr>
                                <td colspan="10">
                                    <div class="table-empty-state">
                                        <i class="bi bi-inbox"></i>
                                        <h4>No orders found in this period</h4>
                                        <p>Adjust your date range filters above.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orderRows as $row): ?>
                                <tr>
                                    <td>
                                        <span class="order-code-badge"><?= htmlspecialchars($row['order_no']) ?></span>
                                    </td>
                                    <td style="font-size:0.8rem; color:#475569;">
                                        <?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>
                                    </td>
                                    <td style="font-size:0.8rem;">
                                        <?php if (!empty($row['cashier_name']) && $row['order_channel'] === 'POS'): ?>
                                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;background:#fff4cc;border:1px solid #e5c352;font-weight:700;color:#785300;font-size:0.76rem;">
                                                <i class="bi bi-person-badge"></i><?= htmlspecialchars($row['cashier_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['customer_name'] ?: 'Guest') ?></strong>
                                        <?php if (!empty($row['customer_phone'])): ?>
                                            <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($row['customer_phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-weight:700; color:<?= $row['order_channel'] === 'POS' ? '#d97706' : '#2563eb' ?>;">
                                            <?= htmlspecialchars($row['order_channel']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight:600; color:#475569;"><?= htmlspecialchars($row['order_type']) ?></span>
                                    </td>
                                    <td style="font-weight:800; color:#0f172a;">
                                        <?= money($row['total']) ?>
                                    </td>
                                    <td>
                                        <span class="order-code-badge" style="background:#f8fafc; border:1px solid #e2e8f0;">
                                            <?= htmlspecialchars($row['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $st = strtolower($row['status']);
                                        $pillClass = match ($st) {
                                            'served', 'picked up' => 'status-completed',
                                            'cancelled', 'voided', 'refunded' => 'status-cancelled',
                                            'ready' => 'status-ready',
                                            'preparing' => 'status-preparing',
                                            default => 'status-pending'
                                        };
                                        ?>
                                        <span class="status-pill <?= $pillClass ?>">
                                            <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <a href="<?= BASE_URL ?>/modules/pos/receipt.php?order_id=<?= (int)$row['id'] ?>&autoprint=1" target="_blank" class="btn btn-secondary" style="padding:5px 10px; font-size:12px; font-weight:700; border-radius:6px; display:inline-flex; align-items:center; gap:4px; text-decoration:none;" title="Reprint Receipt to Thermal Printer">
                                            <i class="bi bi-printer-fill" style="color:#d97706;"></i> Reprint
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ========================================== -->
        <!-- 3. INVENTORY REPORT TAB                     -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'inventory'): ?>
            <div class="report-panel-topbar">
                <div class="report-panel-title-area">
                    <h2>Inventory & Stock Movement Report</h2>
                    <p>Current on-hand stock levels, valuation, and recent inventory movement logs.</p>
                </div>
                <div>
                    <span class="order-code-badge"><?= number_format($invStats['total']) ?> items</span>
                </div>
            </div>

            <!-- Stock Status Sub-summary -->
            <div class="sub-stat-summary">
                <div class="sub-stat-box">
                    <span>Total Inventory Value</span>
                    <strong style="color:#0f172a;"><?= money($invStats['total_value']) ?></strong>
                </div>
                <div class="sub-stat-box">
                    <span>Available Stock</span>
                    <strong style="color:#15803d;"><?= number_format($invStats['available']) ?></strong>
                </div>
                <div class="sub-stat-box">
                    <span>Low Stock Items</span>
                    <strong style="color:#c2410c;"><?= number_format($invStats['low']) ?></strong>
                </div>
                <div class="sub-stat-box">
                    <span>Out of Stock</span>
                    <strong style="color:#b91c1c;"><?= number_format($invStats['out']) ?></strong>
                </div>
            </div>

            <div class="table-responsive">
                <table class="report-data-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Unit Price</th>
                            <th>Stock Count</th>
                            <th>Stock Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventoryRows)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="table-empty-state">
                                        <i class="bi bi-box-seam"></i>
                                        <h4>No menu items found</h4>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventoryRows as $row): ?>
                                <?php
                                $stock = (int)$row['stock'];
                                $statusLabel = ($stock <= 0) ? 'Out of Stock' : (($stock <= 10) ? 'Low Stock' : 'Available');
                                $pillClass = ($stock <= 0) ? 'status-out' : (($stock <= 10) ? 'status-low' : 'status-available');
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                    <td><span class="order-code-badge"><?= htmlspecialchars($row['category_name']) ?></span></td>
                                    <td><?= money($row['price']) ?></td>
                                    <td style="font-weight:800; font-size:0.95rem;"><?= number_format($stock) ?></td>
                                    <td style="font-weight:700; color:#0f172a;"><?= money($stock * (float)$row['price']) ?></td>
                                    <td>
                                        <span class="status-pill <?= $pillClass ?>">
                                            <i class="bi <?= $stock > 10 ? 'bi-check-circle' : ($stock > 0 ? 'bi-exclamation-triangle' : 'bi-x-circle') ?>"></i>
                                            <?= $statusLabel ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Movement Logs -->
            <?php if (!empty($inventoryLogs)): ?>
                <div style="padding: 1.25rem 1.5rem 0.5rem; border-top: 1px solid #e2e8f0;">
                    <h3 style="font-size: 1rem; font-weight:800; margin:0 0 0.5rem; color:#0f172a;">Recent Stock Movements</h3>
                </div>
                <div class="table-responsive">
                    <table class="report-data-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Product</th>
                                <th>Action</th>
                                <th>Movement / Level</th>
                                <th>Remarks</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventoryLogs as $log): ?>
                                <?php $mv = kk_stock_movement_display((string)($log['action'] ?? ''), $log['quantity'] ?? 0, $log['unit'] ?? null); ?>
                                <tr>
                                    <td style="font-size:0.8rem; color:#475569;"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                                    <td><strong><?= htmlspecialchars($log['item_name']) ?></strong></td>
                                    <td><span class="order-code-badge"><?= htmlspecialchars($mv['action_label']) ?></span></td>
                                    <td style="font-weight:700; color:<?= htmlspecialchars($mv['color']) ?>; white-space:nowrap;">
                                        <?= htmlspecialchars($mv['qty_text']) ?>
                                    </td>
                                    <td style="color:#64748b; font-size:0.8rem;"><?= htmlspecialchars($log['remarks'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($log['user_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <!-- ========================================== -->
        <!-- 4. PRODUCT REPORT TAB (Best Sellers)        -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'products'): ?>
            <div class="report-panel-topbar">
                <div class="report-panel-title-area">
                    <h2>Best-Selling Products & Item Performance</h2>
                    <p>Rankings of top menu items by volume sold and total gross revenue generated.</p>
                </div>
                <div>
                    <span class="order-code-badge"><?= number_format($totalProductCount) ?> products sold</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="report-data-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">Rank</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Unit Price</th>
                            <th>Units Sold</th>
                            <th>Total Revenue</th>
                            <th>Share of Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productRows)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="table-empty-state">
                                        <i class="bi bi-trophy"></i>
                                        <h4>No product sales recorded in this period</h4>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $rank = $offset + 1;
                            $globalRev = (float)$topSummary['total_revenue'];
                            foreach ($productRows as $row):
                                $share = ($globalRev > 0) ? round(((float)$row['total_revenue'] / $globalRev) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <span class="order-code-badge" style="background:<?= $rank <= 3 ? '#F2C12E' : '#f1f5f9' ?>; color:<?= $rank <= 3 ? '#000' : '#475569' ?>; font-weight:800;">
                                            #<?= $rank++ ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="font-size:0.92rem; color:#0f172a;"><?= htmlspecialchars($row['item_name']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="order-code-badge"><?= htmlspecialchars($row['category_name']) ?></span>
                                    </td>
                                    <td><?= money($row['unit_price']) ?></td>
                                    <td>
                                        <strong style="font-size:0.95rem; color:#15803d;"><?= number_format($row['qty_sold']) ?> units</strong>
                                    </td>
                                    <td style="font-weight:800; color:#0f172a; font-size:0.95rem;">
                                        <?= money($row['total_revenue']) ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:0.5rem;">
                                            <div style="flex:1; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; min-width:60px;">
                                                <div style="height:100%; width:<?= min(100, $share * 2) ?>%; background:#F2C12E;"></div>
                                            </div>
                                            <span style="font-size:0.76rem; font-weight:700; color:#64748b;"><?= $share ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ========================================== -->
        <!-- 5. DISCOUNT REPORT TAB                     -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'discounts'): ?>
            <div class="report-panel-topbar">
                <div class="report-panel-title-area">
                    <h2>Discount & Deductions Report</h2>
                    <p>Track Student, Senior Citizen, PWD statutory discounts, and marketing promos.</p>
                </div>
                <div>
                    <span class="order-code-badge"><?= number_format($totalDiscountCount) ?> orders discounted</span>
                </div>
            </div>

            <!-- Discount Categories Sub-summary -->
            <div class="sub-stat-summary">
                <div class="sub-stat-box">
                    <span>Total Discounts Given</span>
                    <strong style="color:#b91c1c;"><?= money($discountSummary['total_amount']) ?></strong>
                </div>
                <div class="sub-stat-box">
                    <span>Student Discounts</span>
                    <strong style="color:#3b82f6;"><?= money($discountSummary['student']) ?></strong>
                </div>
                <div class="sub-stat-box">
                    <span>Senior Discounts</span>
                    <strong style="color:#8b5cf6;"><?= money($discountSummary['senior']) ?></strong>
                </div>
                <div class="sub-stat-box">
                    <span>PWD Discounts</span>
                    <strong style="color:#f59e0b;"><?= money($discountSummary['pwd']) ?></strong>
                </div>
                <div class="sub-stat-box">
                    <span>Free Delivery Promos</span>
                    <strong style="color:#10b981;"><?= money($discountSummary['delivery']) ?></strong>
                </div>
            </div>

            <div class="table-responsive">
                <table class="report-data-table">
                    <thead>
                        <tr>
                            <th>Order No</th>
                            <th>Date Paid</th>
                            <th>Discount Category</th>
                            <th>Notes / Reference</th>
                            <th>Subtotal</th>
                            <th>Discount Amount</th>
                            <th>Final Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($discountRows)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="table-empty-state">
                                        <i class="bi bi-percent"></i>
                                        <h4>No discount transactions found</h4>
                                        <p>No orders with discounts or promos in this timeframe.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($discountRows as $row): ?>
                                <?php
                                $n = strtolower($row['notes'] ?? '');
                                $dt = strtolower(trim($row['discount_type'] ?? ''));
                                $d = (float)$row['discount'] + (float)$row['free_delivery_discount'];
                                $type = 'General Promo / POS Discount';
                                if ($dt === 'senior citizen' || ($dt === '' && strpos($n, 'senior') !== false)) {
                                    $type = 'Senior Citizen (20%)';
                                } elseif ($dt === 'pwd' || ($dt === '' && strpos($n, 'pwd') !== false)) {
                                    $type = 'PWD Discount (20%)';
                                } elseif ($dt === 'student' || ($dt === '' && strpos($n, 'student') !== false)) {
                                    $type = 'Student Discount (20%)';
                                } elseif ((float)$row['free_delivery_discount'] > 0) {
                                    $type = 'Free Delivery Promo';
                                }
                                ?>
                                <tr>
                                    <td><span class="order-code-badge"><?= htmlspecialchars($row['order_no']) ?></span></td>
                                    <td style="font-size:0.8rem; color:#475569;"><?= $row['paid_at'] ? date('M d, Y h:i A', strtotime($row['paid_at'])) : '-' ?></td>
                                    <td>
                                        <span class="status-pill status-ready" style="font-weight:700;">
                                            <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($type) ?>
                                        </span>
                                    </td>
                                    <td style="color:#64748b; font-size:0.82rem;"><?= htmlspecialchars($row['notes'] ?: (!empty($row['discount_id_number']) ? 'ID: ' . $row['discount_id_number'] : 'None specified')) ?></td>
                                    <td><?= money($row['subtotal']) ?></td>
                                    <td style="color:#b91c1c; font-weight:800; font-size:0.92rem;">
                                        -<?= money($d) ?>
                                    </td>
                                    <td style="font-weight:800; color:#0f172a;"><?= money($row['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ========================================== -->
        <!-- 6. PAYMENT REPORT TAB                      -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'payments'): ?>
            <div class="report-panel-topbar">
                <div class="report-panel-title-area">
                    <h2>Payment Transactions (Cash & GCash)</h2>
                    <p>Detailed transaction log of all settled payments across Cash, GCash, and COD methods.</p>
                </div>
                <div>
                    <span class="order-code-badge"><?= number_format($totalPaymentCount) ?> payments recorded</span>
                </div>
            </div>

            <!-- Payment Methods Sub-summary -->
            <div class="sub-stat-summary">
                <?php foreach ($paymentBreakdown as $pb): ?>
                    <div class="sub-stat-box">
                        <span><?= htmlspecialchars($pb['payment_method']) ?></span>
                        <strong><?= money($pb['total_val']) ?></strong>
                        <small style="display:block; color:#94a3b8; font-size:0.75rem; margin-top:2px;"><?= number_format($pb['count_val']) ?> transactions</small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="table-responsive">
                <table class="report-data-table">
                    <thead>
                        <tr>
                            <th>Txn ID</th>
                            <th>Order No</th>
                            <th>Paid At</th>
                            <th>Channel</th>
                            <th>Method</th>
                            <th>Reference No</th>
                            <th>Amount Paid</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paymentRows)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="table-empty-state">
                                        <i class="bi bi-credit-card"></i>
                                        <h4>No payments found in this timeframe</h4>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($paymentRows as $row): ?>
                                <tr>
                                    <td><span class="order-code-badge">#<?= htmlspecialchars($row['id']) ?></span></td>
                                    <td><span class="order-code-badge"><?= htmlspecialchars($row['order_no']) ?></span></td>
                                    <td style="font-size:0.8rem; color:#475569;"><?= $row['paid_at'] ? date('M d, Y h:i A', strtotime($row['paid_at'])) : '-' ?></td>
                                    <td>
                                        <span style="font-weight:700; color:<?= $row['order_channel'] === 'POS' ? '#d97706' : '#2563eb' ?>;">
                                            <?= htmlspecialchars($row['order_channel']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="order-code-badge" style="background:#f8fafc; border:1px solid #e2e8f0;">
                                            <i class="bi <?= stripos($row['payment_method'], 'gcash') !== false ? 'bi-qr-code text-primary' : 'bi-cash text-success' ?>"></i>
                                            <?= htmlspecialchars($row['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td style="font-size:0.8rem; color:#475569; font-family:monospace;">
                                        <?= htmlspecialchars($row['payment_reference'] ?: '-') ?>
                                    </td>
                                    <td style="font-weight:800; color:#0f172a; font-size:0.95rem;">
                                        <?= money($row['amount_paid']) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $payStat = (string)($row['payment_status'] ?? 'Paid');
                                        $payPillClass = 'status-completed';
                                        $payPillIcon = 'bi-check-circle-fill';
                                        if (strcasecmp($payStat, 'Pending') === 0) {
                                            $payPillClass = 'status-pending';
                                            $payPillIcon = 'bi-clock-history';
                                        } elseif (in_array(strtolower($payStat), ['failed', 'voided', 'refunded'], true)) {
                                            $payPillClass = 'status-cancelled';
                                            $payPillIcon = 'bi-x-circle-fill';
                                        }
                                        ?>
                                        <span class="status-pill <?= $payPillClass ?>">
                                            <i class="bi <?= $payPillIcon ?>"></i> <?= htmlspecialchars($payStat) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </main>
</div>

<!-- ========================================== -->
<!-- TAB SCROLLER + CHART.JS INITIALIZATION     -->
<!-- ========================================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    // ---- Tab Scroller Logic ----
    const tabsEl    = document.getElementById('reportsNavTabs');
    const btnLeft   = document.getElementById('tabScrollLeft');
    const btnRight  = document.getElementById('tabScrollRight');
    const SCROLL_BY = 200;

    function updateScrollBtns() {
        if (!tabsEl) return;
        const atStart = tabsEl.scrollLeft <= 2;
        const atEnd   = tabsEl.scrollLeft + tabsEl.clientWidth >= tabsEl.scrollWidth - 2;
        const hasOverflow = tabsEl.scrollWidth > tabsEl.clientWidth + 4;

        btnLeft.classList.toggle('visible',  hasOverflow && !atStart);
        btnRight.classList.toggle('visible', hasOverflow && !atEnd);
    }

    if (tabsEl && btnLeft && btnRight) {
        btnLeft.addEventListener('click', function() {
            tabsEl.scrollBy({ left: -SCROLL_BY, behavior: 'smooth' });
        });
        btnRight.addEventListener('click', function() {
            tabsEl.scrollBy({ left: SCROLL_BY, behavior: 'smooth' });
        });
        tabsEl.addEventListener('scroll', updateScrollBtns);

        // Scroll active tab into view on page load
        const activeTab = tabsEl.querySelector('.report-tab-btn.active');
        if (activeTab) {
            setTimeout(function() {
                activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }, 80);
        }

        // Initialise button visibility after a brief paint delay
        setTimeout(updateScrollBtns, 100);
        window.addEventListener('resize', updateScrollBtns);
    }

    // ---- Chart.js Initialization ----
    const timelineCtx = document.getElementById('reportsTimelineChart');
    if (timelineCtx) {
        new Chart(timelineCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                datasets: [{
                    type: 'line',
                    label: 'Revenue',
                    data: <?= json_encode($chartRevenue) ?>,
                    fill: true,
                    tension: 0.38,
                    cubicInterpolationMode: 'monotone',
                    borderWidth: 3,
                    borderColor: '#F2C12E',
                    backgroundColor: function(context) {
                        const { ctx, chartArea } = context.chart;
                        if (!chartArea) return 'rgba(242, 193, 46, 0.20)';
                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, 'rgba(242, 193, 46, 0.48)');
                        gradient.addColorStop(1, 'rgba(242, 193, 46, 0.03)');
                        return gradient;
                    },
                    pointBackgroundColor: '#F2C12E',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: <?= count($chartLabels) <= 31 ? 4 : 0 ?>,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#1e1e1e',
                    pointHoverBorderColor: '#F2C12E',
                    yAxisID: 'y',
                    order: 1
                }, {
                    type: 'bar',
                    label: 'Paid orders',
                    data: <?= json_encode($chartOrders) ?>,
                    backgroundColor: 'rgba(30, 30, 30, 0.72)',
                    hoverBackgroundColor: '#1e1e1e',
                    borderRadius: 5,
                    maxBarThickness: 26,
                    categoryPercentage: 0.65,
                    barPercentage: 0.8,
                    yAxisID: 'y1',
                    order: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        align: 'start',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 18,
                            color: '#64748b',
                            font: { family: getComputedStyle(document.body).fontFamily, size: 12, weight: 600 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1e1e1e',
                        titleColor: '#ffffff',
                        bodyColor: '#F2C12E',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                if (context.dataset.yAxisID === 'y1') {
                                    return 'Paid orders: ' + Number(context.parsed.y).toLocaleString('en-PH');
                                }
                                return 'Revenue: ₱' + Number(context.parsed.y).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    x: { display: false, grid: { display: false } },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        border: { display: false },
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#94a3b8', precision: 0, maxTicksLimit: 6 }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            color: '#94a3b8',
                            maxTicksLimit: 6,
                            font: { size: 11, family: getComputedStyle(document.body).fontFamily },
                            callback: function(value) {
                                return value >= 1000 ? '₱' + (value / 1000).toLocaleString('en-PH') + 'k' : '₱' + value;
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Category Share Doughnut
    const catCtx = document.getElementById('reportsCategoryChart');
    if (catCtx) {
        new Chart(catCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($catLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                datasets: [{
                    data: <?= json_encode($catData) ?>,
                    backgroundColor: <?= json_encode($catColors) ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { family: 'Inter', size: 11, weight: 600 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ' ' + context.label + ': ₱' + Number(context.raw).toLocaleString('en-US', { minimumFractionDigits: 2 });
                            }
                        }
                    }
                },
                cutout: '68%'
            }
        });
    }
});

// Keep exact viewport scroll position when paginating report tables
(function initInPlaceReportPagination() {
    const panel = document.getElementById('report-table-panel');
    if (!panel) return;
    let activeController = null;

    async function loadPage(url, pushHistory) {
        if (activeController) activeController.abort();
        activeController = new AbortController();
        const prevY = window.scrollY;
        const prevX = window.scrollX;
        panel.style.opacity = '0.65';
        try {
            const res = await fetch(url, { signal: activeController.signal, credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextPanel = doc.getElementById('report-table-panel');
            if (!nextPanel) throw new Error('Panel missing');
            panel.innerHTML = nextPanel.innerHTML;
            if (pushHistory) history.pushState({ reportsPanel: true }, '', url);
            window.scrollTo(prevX, prevY);
        } catch (err) {
            if (err && err.name === 'AbortError') return;
            window.location.href = url;
        } finally {
            panel.style.opacity = '';
        }
    }

    panel.addEventListener('click', function (e) {
        const link = e.target.closest('.report-pagination-bar a.page-num-btn');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        loadPage(link.href, true);
    });

    window.addEventListener('popstate', function () {
        loadPage(window.location.href, false);
    });
})();
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
