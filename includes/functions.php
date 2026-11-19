<?php
require_once dirname(__DIR__) . '/config/config.php';

/** Settled sales use the latest payment; a retained timestamp alone is not proof of payment. */
function kk_report_paid_orders_sql(): string
{
    return "SELECT o.*, COALESCE(o.paid_at, p.paid_at) AS report_paid_at,
                   p.id AS report_payment_id
            FROM orders o
            INNER JOIN payments p ON p.id = (SELECT MAX(lp.id) FROM payments lp WHERE lp.order_id = o.id)
            WHERE p.payment_status = 'Paid' AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded')";
}

function kk_report_date($value, string $fallback): string
{
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) return $fallback;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value && $value <= date('Y-m-d') ? $value : $fallback;
}

/** Keep user-entered text literal when opening CSV exports in spreadsheet software. */
function kk_report_csv_row($stream, array $cells): void
{
    foreach ($cells as &$cell) {
        if (is_string($cell) && preg_match('/^(?:[\\s\\x00-\\x20]*[=+@-]|[\\t\\r\\n])/', $cell)) {
            $cell = "'" . $cell;
        }
    }
    unset($cell);
    fputcsv($stream, $cells, ',', '"', '');
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($amount)
{
    return '₱' . number_format((float) $amount, 2);
}

function active_link($key)
{
    return ($GLOBALS['page'] ?? '') === $key ? 'active' : '';
}

function redirect($path)
{
    $path = trim((string)$path);
    if ($path === '') {
        $path = '/';
    }

    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        $target = $path;
    } elseif (defined('BASE_URL') && BASE_URL !== '' && (strpos($path, BASE_URL . '/') === 0 || $path === BASE_URL)) {
        // Path already includes BASE_URL, do not duplicate it
        $target = $path;
    } else {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $target = rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    header('Location: ' . $target);
    exit;
}


function menu_image_url($image)
{
    $path = str_replace('\\', '/', trim((string)$image));
    if ($path === '') {
        return '';
    }

    $parts = explode('/', $path);
    if (array_filter($parts, static function ($part) {
        return $part === '' || $part === '.' || $part === '..';
    })) {
        return '';
    }

    $uploadDir = dirname(__DIR__) . '/uploads/menu/';
    $directFile = $uploadDir . $path;

    if (file_exists($directFile)) {
        return BASE_URL . '/uploads/menu/' . implode('/', array_map('rawurlencode', $parts)) . '?v=' . (int)@filemtime($directFile);
    }

    // If file is not found directly (e.g. stored without category subfolder),
    // search inside category subfolders of uploads/menu/
    $baseName = basename($path);
    if ($baseName !== '' && is_dir($uploadDir)) {
        $subdirs = glob($uploadDir . '*', GLOB_ONLYDIR);
        if ($subdirs) {
            foreach ($subdirs as $dir) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $baseName;
                if (file_exists($candidate)) {
                    $relFolder = basename($dir);
                    return BASE_URL . '/uploads/menu/' . rawurlencode($relFolder) . '/' . rawurlencode($baseName) . '?v=' . (int)@filemtime($candidate);
                }
            }
        }

        // Fuzzy match: match name without extension (e.g. burgersteak vs burgersteak_rice)
        $nameNoExt = strtolower(pathinfo($baseName, PATHINFO_FILENAME));
        $cleanSearch = preg_replace('/[^a-z0-9]/', '', $nameNoExt);
        if (strlen($cleanSearch) >= 3 && $subdirs) {
            foreach ($subdirs as $dir) {
                $files = glob($dir . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp,PNG,JPG}', GLOB_BRACE);
                if ($files) {
                    foreach ($files as $f) {
                        $fBase = strtolower(pathinfo($f, PATHINFO_FILENAME));
                        $cleanFile = preg_replace('/[^a-z0-9]/', '', $fBase);
                        if ($cleanFile === $cleanSearch || strpos($cleanFile, $cleanSearch) !== false || strpos($cleanSearch, $cleanFile) !== false) {
                            $relFolder = basename($dir);
                            return BASE_URL . '/uploads/menu/' . rawurlencode($relFolder) . '/' . rawurlencode(basename($f)) . '?v=' . (int)@filemtime($f);
                        }
                    }
                }
            }
        }
    }

    return BASE_URL . '/uploads/menu/' . implode('/', array_map('rawurlencode', $parts));
}

function menu_category_upload_folder($categoryName)
{
    if (!defined('UPLOAD_PATH')) {
        define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/menu/');
    }

    $raw = trim((string)$categoryName);
    if ($raw === '') {
        $folder = 'general';
    } else {
        // Sanitize category name into safe folder name
        $folder = strtolower(preg_replace('/[^a-zA-Z0-9_\- &]/', '', $raw));
        $folder = trim($folder);
        if ($folder === '') {
            $folder = 'general';
        }
        // Prefer existing hyphenated category folder on disk (e.g. bilao-favorites)
        $hyphenated = preg_replace('/\s+/', '-', $folder);
        if ($hyphenated !== '' && $hyphenated !== $folder && is_dir(rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $hyphenated)) {
            $folder = $hyphenated;
        }
    }

    $targetDir = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return null;
        }
    }

    return $folder;
}

function upload_menu_image($file, $categoryName)
{
    if (empty($file) || !is_array($file) || empty($file['name'])) {
        return null;
    }

    if (!isset($file['tmp_name'], $file['error'], $file['size'])) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!is_file($file['tmp_name'])) {
        return null;
    }

    $isUploaded = is_uploaded_file($file['tmp_name']);
    if (!$isUploaded && php_sapi_name() !== 'cli') {
        return null;
    }

    // Support up to 10 MB photos (standard phone cameras / web uploads)
    if ($file['size'] <= 0 || $file['size'] > 10485760) {
        return null;
    }

    $originalName = (string)$file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        return null;
    }

    if (!extension_loaded('fileinfo')) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return null;
    }
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!$detectedMime) {
        return null;
    }

    $mimeToExt = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp']
    ];

    if (!isset($mimeToExt[$detectedMime])) {
        return null;
    }

    if (!in_array($extension, $mimeToExt[$detectedMime], true)) {
        return null;
    }

    $canonicalExt = ($detectedMime === 'image/jpeg') ? 'jpg' : (($detectedMime === 'image/png') ? 'png' : 'webp');
    $name = 'menu_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $canonicalExt;

    if (!defined('UPLOAD_PATH')) {
        define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/menu/');
    }

    $folder = menu_category_upload_folder($categoryName);
    if ($folder === null) {
        return null;
    }

    $dest = UPLOAD_PATH . $folder . DIRECTORY_SEPARATOR . $name;
    $saved = $isUploaded ? move_uploaded_file($file['tmp_name'], $dest) : copy($file['tmp_name'], $dest);

    if (!$saved) {
        return null;
    }

    return $folder . '/' . $name;
}

/**
 * Returns the session-bound CSRF token, creating it when missing.
 * Shares the $_SESSION['csrf_token'] key used by modules/settings/payments.php.
 */
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent()) {
                throw new RuntimeException('CSRF token requires an active session.');
            }
            kk_start_session();
        }

        $current = $_SESSION['csrf_token'] ?? '';
        if (!is_string($current) || !preg_match('/^[a-f0-9]{64}$/', $current)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

/**
 * Returns an escaped hidden input carrying the CSRF token for HTML forms.
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

/**
 * Validates a submitted CSRF token against the session token.
 * When $token is null, the token is read from $_POST['csrf_token'], then from
 * the X-CSRF-Token request header (for JSON/fetch requests).
 * Missing, empty, malformed, or mismatched tokens are always rejected.
 */
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return false;
        }

        if ($token === null) {
            $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }

        if (!is_string($token) || $token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        return hash_equals($expected, $token);
    }
}

/** Record an administrative action without hiding a completed operation if logging fails. */
if (!function_exists('log_audit')) {
    function log_audit(PDO $conn, string $action, string $targetType, ?string $targetId, string $details = ''): void
    {
        try {
            $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, user_role, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                isset($_SESSION['u_id']) ? (int)$_SESSION['u_id'] : null,
                isset($_SESSION['role']) ? (string)$_SESSION['role'] : null,
                $action,
                $targetType,
                $targetId,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('Audit log write failed: ' . $e->getMessage());
        }
    }
}

/**
 * Renders standardized pagination controls: <<  < Previous  Page X of Y  Next >  >>
 */
if (!function_exists('render_pagination_controls')) {
    function render_pagination_controls(int $currentPage, int $totalPages, callable $urlForPage): string
    {
        $totalPages = max(1, $totalPages);
        $currentPage = max(1, min($currentPage, $totalPages));
        $isFirst = $currentPage <= 1;
        $isLast = $currentPage >= $totalPages;

        $firstUrl = e((string)$urlForPage(1));
        $prevUrl = e((string)$urlForPage(max(1, $currentPage - 1)));
        $nextUrl = e((string)$urlForPage(min($totalPages, $currentPage + 1)));
        $lastUrl = e((string)$urlForPage($totalPages));

        $firstStyle = $isFirst ? 'pointer-events:none;opacity:0.5;' : '';
        $lastStyle = $isLast ? 'pointer-events:none;opacity:0.5;' : '';
        $firstAttrs = $isFirst ? ' aria-disabled="true" tabindex="-1"' : '';
        $lastAttrs = $isLast ? ' aria-disabled="true" tabindex="-1"' : '';

        return '<div class="kk-pagination" style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;margin-left:auto;">'
            . '<a class="btn btn-secondary" style="padding:7px 11px;font-size:13px;' . $firstStyle . '" href="' . $firstUrl . '" title="First Page" aria-label="First Page"' . $firstAttrs . '>&lt;&lt;</a>'
            . '<a class="btn btn-secondary" style="padding:7px 12px;font-size:13px;' . $firstStyle . '" href="' . $prevUrl . '" title="Previous Page" aria-label="Previous Page"' . $firstAttrs . '><i class="bi bi-chevron-left"></i> Previous</a>'
            . '<span class="muted" style="font-weight:700;font-size:13px;padding:0 6px;white-space:nowrap;">Page ' . $currentPage . ' of ' . $totalPages . '</span>'
            . '<a class="btn btn-secondary" style="padding:7px 12px;font-size:13px;' . $lastStyle . '" href="' . $nextUrl . '" title="Next Page" aria-label="Next Page"' . $lastAttrs . '>Next <i class="bi bi-chevron-right"></i></a>'
            . '<a class="btn btn-secondary" style="padding:7px 11px;font-size:13px;' . $lastStyle . '" href="' . $lastUrl . '" title="Last Page" aria-label="Last Page"' . $lastAttrs . '>&gt;&gt;</a>'
            . '</div>';
    }
}

/**
 * Formats an inventory_logs movement entry without altering stored quantities.
 * Stock Out / deductions render as negative changes (-), Stock In / Delivery as
 * positive increases (+), and Adjustment as the resulting stock level (Set to ...).
 *
 * @return array{action_label:string, qty_text:string, color:string, direction:string}
 */
if (!function_exists('kk_stock_movement_display')) {
    function kk_stock_movement_display(string $action, $quantity, ?string $unit = null): array
    {
        $rawAction = trim($action);
        $normalized = strtolower($rawAction);

        $absQty = abs((float)$quantity);
        $isWhole = abs($absQty - round($absQty)) < 0.0001;
        $formattedQty = $isWhole
            ? number_format(round($absQty), 0, '.', ',')
            : rtrim(rtrim(number_format($absQty, 2, '.', ''), '0'), '.');

        $trimmedUnit = trim((string)($unit ?? ''));
        $unitSuffix = $trimmedUnit !== '' ? ' ' . $trimmedUnit : '';

        if (in_array($normalized, ['stock in', 'delivery', 'restock', 'order return', 'return', 'restore'], true)) {
            return [
                'action_label' => $rawAction,
                'qty_text'     => '+' . $formattedQty . $unitSuffix,
                'color'        => '#15803d',
                'direction'    => 'increase',
            ];
        }

        if (in_array($normalized, ['stock out', 'order deduction', 'waste/spoilage', 'spoilage', 'deduction'], true)) {
            return [
                'action_label' => $rawAction,
                'qty_text'     => '-' . $formattedQty . $unitSuffix,
                'color'        => '#b91c1c',
                'direction'    => 'decrease',
            ];
        }

        if ($normalized === 'adjustment') {
            return [
                'action_label' => 'Adjustment',
                'qty_text'     => 'Set to ' . $formattedQty . $unitSuffix,
                'color'        => '#334155',
                'direction'    => 'set',
            ];
        }

        return [
            'action_label' => $rawAction !== '' ? $rawAction : 'Unspecified',
            'qty_text'     => $formattedQty . $unitSuffix,
            'color'        => '#475569',
            'direction'    => 'neutral',
        ];
    }
}

if (!function_exists('kk_customer_first_name')) {
    function kk_customer_first_name(?string $fullName): string
    {
        $trimmed = trim((string)$fullName);
        if ($trimmed === '') {
            return 'Customer';
        }
        $parts = preg_split('/\s+/u', $trimmed);
        return (!empty($parts[0])) ? (string)$parts[0] : 'Customer';
    }
}

if (!function_exists('kk_customer_avatar_filename')) {
    function kk_customer_avatar_filename(?string $profilePhoto): ?string
    {
        $raw = str_replace('\\', '/', trim((string)$profilePhoto));
        if ($raw === '' || strpos($raw, '..') !== false) {
            return null;
        }
        $base = basename($raw);
        if (!preg_match('/^[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$/i', $base)) {
            return null;
        }
        return $base;
    }
}

if (!function_exists('kk_customer_avatar_url')) {
    function kk_customer_avatar_url(?string $profilePhoto): ?string
    {
        $filename = kk_customer_avatar_filename($profilePhoto);
        if ($filename === null) {
            return null;
        }
        $fullPath = ROOT_PATH . '/uploads/avatars/' . $filename;
        if (!@is_file($fullPath)) {
            return null;
        }
        $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
        return $base . '/uploads/avatars/' . rawurlencode($filename) . '?v=' . (int)@filemtime($fullPath);
    }
}

if (!function_exists('kk_delete_customer_avatar_file')) {
    function kk_delete_customer_avatar_file(?string $profilePhoto): void
    {
        $filename = kk_customer_avatar_filename($profilePhoto);
        if ($filename === null) {
            return;
        }
        $fullPath = ROOT_PATH . '/uploads/avatars/' . $filename;
        if (@is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

/**
 * Compute live actionable notification counts for all staff sidebar modules.
 *
 * @return array<string, int>
 */
function kk_sidebar_badge_counts(PDO $conn, int $actorId = 0): array
{
    $counts = [
        'inventory' => 0,
        'pos' => 0,
        'payment_verification' => 0,
        'cod_approvals' => 0,
        'delivery' => 0,
        'kitchen' => 0,
        'serving' => 0,
        'approvals' => 0,
    ];

    try {
        $counts['inventory'] = (int)$conn->query("
            SELECT COUNT(*)
            FROM menu_items
            WHERE COALESCE(is_archived, 0) = 0
              AND (stock <= 5 OR availability <> 'Available')
        ")->fetchColumn();
    } catch (Throwable $t) {}

    try {
        $counts['pos'] = (int)$conn->query("
            SELECT COUNT(*)
            FROM orders
            WHERE order_channel = 'ONLINE'
              AND status IN ('Pending', 'Preparing', 'Ready')
        ")->fetchColumn();
    } catch (Throwable $t) {}

    try {
        require_once ROOT_PATH . '/includes/gcash_verification.php';
        expire_overdue_gcash_orders($conn, $actorId > 0 ? $actorId : null);
        $foodQrCount = (int)$conn->query("
            SELECT COUNT(*)
            FROM orders o
            JOIN payment_proofs pp ON pp.order_id = o.id AND pp.verification_status = 'pending' AND pp.payment_type = 'food'
            WHERE o.payment_verification_status = 'Awaiting Verification'
              AND o.status = 'Pending'
        ")->fetchColumn();
        $courierQrCount = (int)$conn->query("
            SELECT COUNT(*)
            FROM orders o
            JOIN payment_proofs pp ON pp.order_id = o.id AND pp.verification_status = 'pending' AND pp.payment_type = 'courier_fee'
        ")->fetchColumn();
        $counts['payment_verification'] = $foodQrCount + $courierQrCount;
    } catch (Throwable $t) {}

    try {
        $counts['cod_approvals'] = (int)$conn->query("
            SELECT COUNT(*)
            FROM orders
            WHERE order_channel = 'ONLINE'
              AND cod_approval_status = 'Pending'
              AND status = 'Pending'
        ")->fetchColumn();
    } catch (Throwable $t) {}

    try {
        $counts['delivery'] = (int)$conn->query("
            SELECT COUNT(*)
            FROM orders o
            LEFT JOIN delivery_dispatches d ON d.order_id = o.id
            WHERE o.order_channel = 'ONLINE'
              AND o.order_type = 'ONLINE'
              AND o.status IN ('Pending', 'Preparing', 'Ready')
              AND (
                  o.status = 'Ready'
                  OR (o.fulfillment_mode = 'OUTSIDE' AND (o.courier_tracking_status = 'Pending' OR o.courier_booking_by = 'STORE'))
              )
        ")->fetchColumn();
    } catch (Throwable $t) {}

    try {
        require_once ROOT_PATH . '/includes/delivery_tracking.php';
        $option1Gate = kk_kitchen_option1_gate_sql('o');
        $counts['kitchen'] = (int)$conn->query("
            SELECT COUNT(*)
            FROM orders o
            WHERE o.status IN ('Pending', 'Preparing')
              AND o.kitchen_queued_at IS NOT NULL
              AND (o.order_channel <> 'ONLINE' OR EXISTS (
                  SELECT 1 FROM payments p
                  WHERE p.order_id = o.id
                    AND (p.payment_method <> 'GCash' OR p.payment_status = 'Paid')
              ))
              AND {$option1Gate}
        ")->fetchColumn();
    } catch (Throwable $t) {}

    try {
        $counts['serving'] = (int)$conn->query("
            SELECT COUNT(*)
            FROM orders
            WHERE status = 'Ready'
        ")->fetchColumn();
    } catch (Throwable $t) {}

    try {
        $appReqCount = (int)$conn->query("
            SELECT COUNT(*)
            FROM approval_requests
            WHERE status = 'Pending'
        ")->fetchColumn();
        $cancelReqCount = (int)$conn->query("
            SELECT COUNT(*)
            FROM transaction_requests
            WHERE status = 'Pending'
        ")->fetchColumn();
        $counts['approvals'] = $appReqCount + $cancelReqCount;
    } catch (Throwable $t) {}

    return $counts;
}

/**
 * Return module-scoped notifications for the upper-right navbar bell.
 *
 * @return array{module: string, label: string, count: int, items: array<int, array{title: string, detail: string, tag: string, url: string}>}
 */
function kk_module_notifications(PDO $conn, string $module, int $actorId = 0): array
{
    $module = trim($module) !== '' ? trim($module) : 'dashboard';
    $labels = [
        'inventory' => 'Inventory Alerts',
        'pos' => 'Online Orders (POS)',
        'payment_verification' => 'QR Verification',
        'cod_approvals' => 'COD Approvals',
        'delivery' => 'Delivery Status',
        'kitchen' => 'Kitchen Queue',
        'serving' => 'Serving Queue',
        'approvals' => 'Approvals',
        'reports' => 'Revenue & Payments',
        'users' => 'User & Account Activity',
        'menu' => 'Menu & Pricing Alerts',
        'payments' => 'GCash & Payment Config',
    ];
    $label = $labels[$module] ?? 'Notifications';
    $items = [];
    $counts = kk_sidebar_badge_counts($conn, $actorId);
    $totalCount = (int)($counts[$module] ?? 0);

    try {
        if ($module === 'delivery') {
            $rows = $conn->query("
                SELECT o.id, o.order_no, o.customer_name, o.status AS order_status,
                       o.fulfillment_mode, o.delivery_mode, o.courier_booking_by,
                       o.courier_tracking_status, d.status AS dispatch_status,
                       tpd.delivery_fee AS tp_fee, tpd.fee_paid
                FROM orders o
                LEFT JOIN delivery_dispatches d ON d.order_id = o.id
                LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id
                WHERE o.order_channel = 'ONLINE'
                  AND o.order_type = 'ONLINE'
                  AND o.status IN ('Pending', 'Preparing', 'Ready')
                  AND (
                      o.status = 'Ready'
                      OR (o.fulfillment_mode = 'OUTSIDE' AND (o.courier_tracking_status = 'Pending' OR o.courier_booking_by = 'STORE'))
                  )
                ORDER BY o.created_at DESC
                LIMIT 12
            ")->fetchAll();
            foreach ($rows as $r) {
                $isOutside = ($r['fulfillment_mode'] ?? '') === 'OUTSIDE';
                $tag = 'Action Needed';
                $detail = (string)($r['customer_name'] ?: 'Online Customer');
                if (($r['dispatch_status'] ?? '') === 'Failed') {
                    $tag = 'Delivery Failed';
                    $detail .= ' · Delivery failed, retry handoff needed';
                } elseif (($r['dispatch_status'] ?? '') === 'Out for Delivery') {
                    $tag = 'Out for Delivery';
                    $detail .= ' · Confirm delivery when completed';
                } elseif (($r['courier_tracking_status'] ?? '') === 'Pending') {
                    $tag = 'Approve Link';
                    $detail .= ' · Customer tracking link awaiting review';
                } elseif ($isOutside && strtoupper((string)($r['courier_booking_by'] ?? '')) === 'STORE' && (float)($r['tp_fee'] ?? 0) <= 0) {
                    $tag = 'Quote Fee';
                    $detail .= ' · Enter third-party courier fee quote';
                } elseif ($isOutside && strtoupper((string)($r['courier_booking_by'] ?? '')) === 'STORE' && empty($r['fee_paid'])) {
                    $tag = 'Fee Unpaid';
                    $detail .= ' · Awaiting customer courier fee payment';
                } elseif (($r['order_status'] ?? '') === 'Ready') {
                    $tag = 'Ready for Handoff';
                    $detail .= ' · Ready for dispatch';
                }
                $items[] = [
                    'title' => (string)$r['order_no'],
                    'detail' => $detail,
                    'tag' => $tag,
                    'url' => BASE_URL . '/modules/delivery/index.php' . ($isOutside ? '?filter=outside' : ''),
                ];
            }
            $cancelDeliveryRows = $conn->query("
                SELECT o.order_no, o.customer_name, o.fulfillment_mode
                FROM transaction_requests tr
                JOIN orders o ON o.id = tr.order_id
                WHERE tr.status = 'Pending'
                  AND tr.request_type = 'Cancel Delivery'
                ORDER BY tr.created_at DESC
                LIMIT 6
            ")->fetchAll();
            foreach ($cancelDeliveryRows as $cr) {
                $isOut = ($cr['fulfillment_mode'] ?? '') === 'OUTSIDE';
                $items[] = [
                    'title' => (string)$cr['order_no'],
                    'detail' => (string)($cr['customer_name'] ?: 'Online Customer') . ' · Cancellation requested',
                    'tag' => 'Cancel Request',
                    'url' => BASE_URL . '/modules/delivery/index.php' . ($isOut ? '?filter=outside' : ''),
                ];
            }
            $totalCount = max($totalCount, count($items));
        } elseif ($module === 'kitchen') {
            require_once ROOT_PATH . '/includes/delivery_tracking.php';
            $option1Gate = kk_kitchen_option1_gate_sql('o');
            $rows = $conn->query("
                SELECT o.order_no, o.customer_name, o.status, o.order_type
                FROM orders o
                WHERE o.status IN ('Pending', 'Preparing')
                  AND o.kitchen_queued_at IS NOT NULL
                  AND (o.order_channel <> 'ONLINE' OR EXISTS (
                      SELECT 1 FROM payments p
                      WHERE p.order_id = o.id
                        AND (p.payment_method <> 'GCash' OR p.payment_status = 'Paid')
                  ))
                  AND {$option1Gate}
                ORDER BY o.kitchen_queued_at ASC
                LIMIT 12
            ")->fetchAll();
            foreach ($rows as $r) {
                $items[] = [
                    'title' => (string)$r['order_no'],
                    'detail' => (string)($r['customer_name'] ?: 'Walk-in Customer') . ' · ' . (string)$r['order_type'],
                    'tag' => $r['status'] === 'Pending' ? 'New Order' : 'Preparing',
                    'url' => BASE_URL . '/modules/kitchen/index.php',
                ];
            }
        } elseif ($module === 'serving') {
            $rows = $conn->query("
                SELECT order_no, customer_name, order_type, table_no
                FROM orders
                WHERE status = 'Ready'
                ORDER BY updated_at DESC, created_at DESC
                LIMIT 12
            ")->fetchAll();
            foreach ($rows as $r) {
                $place = $r['order_type'] === 'DINE-IN' && !empty($r['table_no']) ? 'Table ' . $r['table_no'] : (string)$r['order_type'];
                $items[] = [
                    'title' => (string)$r['order_no'],
                    'detail' => (string)($r['customer_name'] ?: 'Walk-in Customer') . ' · ' . $place,
                    'tag' => 'Ready to Serve',
                    'url' => BASE_URL . '/modules/serving/index.php',
                ];
            }
        } elseif ($module === 'payment_verification') {
            $rows = $conn->query("
                SELECT o.order_no, o.customer_name, o.total, pp.payment_type, tpd.delivery_fee AS tp_fee
                FROM orders o
                JOIN payment_proofs pp ON pp.order_id = o.id AND pp.verification_status = 'pending'
                LEFT JOIN thirdparty_deliveries tpd ON tpd.order_id = o.id
                WHERE (
                    (pp.payment_type = 'food' AND o.payment_verification_status = 'Awaiting Verification' AND o.status = 'Pending')
                    OR pp.payment_type = 'courier_fee'
                )
                ORDER BY pp.created_at ASC
                LIMIT 12
            ")->fetchAll();
            foreach ($rows as $r) {
                $isCourierFee = ($r['payment_type'] ?? '') === 'courier_fee';
                $amt = $isCourierFee ? (float)($r['tp_fee'] ?? 0) : (float)$r['total'];
                $items[] = [
                    'title' => (string)$r['order_no'],
                    'detail' => (string)($r['customer_name'] ?: 'Online Customer') . ' · ₱' . number_format($amt, 2),
                    'tag' => $isCourierFee ? 'Courier Fee QR' : 'Verify GCash',
                    'url' => BASE_URL . ($isCourierFee ? '/modules/delivery/index.php?filter=outside' : '/modules/pos/payment_verification.php'),
                ];
            }
        } elseif ($module === 'cod_approvals') {
            $rows = $conn->query("
                SELECT order_no, customer_name, total
                FROM orders
                WHERE order_channel = 'ONLINE' AND cod_approval_status = 'Pending' AND status = 'Pending'
                ORDER BY created_at ASC
                LIMIT 12
            ")->fetchAll();
            foreach ($rows as $r) {
                $items[] = [
                    'title' => (string)$r['order_no'],
                    'detail' => (string)($r['customer_name'] ?: 'Online Customer') . ' · ₱' . number_format((float)$r['total'], 2),
                    'tag' => 'COD Pending',
                    'url' => BASE_URL . '/modules/pos/cod_approvals.php',
                ];
            }
        } elseif ($module === 'pos') {
            $hour = (int)date('G');
            if ($hour >= 9 && $hour < 21) {
                $shiftWindowLabel = 'First Shift (9:00 AM - 9:00 PM)';
                $isStoreHours = true;
            } elseif ($hour >= 21 || $hour < 3) {
                $shiftWindowLabel = 'Closing Shift (9:00 PM - 3:00 AM)';
                $isStoreHours = true;
            } else {
                $shiftWindowLabel = 'Store Closed (Opens 9:00 AM)';
                $isStoreHours = false;
            }

            $openShift = $conn->query("
                SELECT cs.id, cs.opened_at, u.full_name, u.username
                FROM cashier_shifts cs
                JOIN users u ON u.id = cs.user_id
                WHERE cs.status = 'Open'
                ORDER BY cs.opened_at DESC
                LIMIT 1
            ")->fetch();

            if ($openShift) {
                $cashierLabel = (string)($openShift['full_name'] ?: $openShift['username']);
                $openedTs = strtotime((string)$openShift['opened_at']);
                $openedTime = $openedTs ? date('g:i A', $openedTs) : '';
                $openedHour = $openedTs ? (int)date('G', $openedTs) : $hour;
                $needsHandover = ($hour >= 21 && $openedHour >= 9 && $openedHour < 21)
                    || ($hour < 3 && $openedHour >= 9 && $openedHour < 21);

                $items[] = [
                    'title' => 'Shift Open: ' . $cashierLabel,
                    'detail' => $shiftWindowLabel . ($openedTime ? ' · Opened ' . $openedTime : ''),
                    'tag' => $needsHandover ? 'Shift Handover' : 'Active Shift',
                    'url' => BASE_URL . '/modules/pos/index.php',
                ];
                if ($needsHandover) {
                    $totalCount++;
                }
            } else {
                $items[] = [
                    'title' => 'No Active Cashier Shift',
                    'detail' => $shiftWindowLabel . ($isStoreHours ? ' · Start shift before taking POS orders' : ''),
                    'tag' => $isStoreHours ? 'Action Needed' : 'Closed',
                    'url' => BASE_URL . '/modules/pos/index.php',
                ];
                if ($isStoreHours) {
                    $totalCount++;
                }
            }

            $rows = $conn->query("
                SELECT order_no, customer_name, status, total
                FROM orders
                WHERE order_channel = 'ONLINE' AND status IN ('Pending', 'Preparing', 'Ready')
                ORDER BY created_at DESC
                LIMIT 12
            ")->fetchAll();
            foreach ($rows as $r) {
                $items[] = [
                    'title' => (string)$r['order_no'],
                    'detail' => (string)($r['customer_name'] ?: 'Online Customer') . ' · ₱' . number_format((float)$r['total'], 2),
                    'tag' => (string)$r['status'],
                    'url' => BASE_URL . '/modules/pos/index.php',
                ];
            }
        } elseif ($module === 'inventory') {
            $rows = $conn->query("
                SELECT item_name, stock, availability
                FROM menu_items
                WHERE COALESCE(is_archived, 0) = 0
                  AND (stock <= 5 OR availability <> 'Available')
                ORDER BY stock ASC, item_name ASC
                LIMIT 12
            ")->fetchAll();
            foreach ($rows as $r) {
                $stock = (int)$r['stock'];
                $isOut = $stock <= 0 || ($r['availability'] ?? '') !== 'Available';
                $items[] = [
                    'title' => (string)$r['item_name'],
                    'detail' => $isOut ? 'Currently out of stock / unavailable' : ('Remaining: ' . $stock . ' pcs left'),
                    'tag' => $isOut ? 'Out of Stock' : 'Low Stock',
                    'url' => BASE_URL . '/modules/inventory/index.php?stock_filter=' . ($isOut ? 'out' : 'low'),
                ];
            }
        } elseif ($module === 'reports') {
            $rows = $conn->query("
                SELECT o.order_no, o.customer_name, p.payment_method, p.payment_status, o.total, COALESCE(p.paid_at, p.created_at) AS tx_time
                FROM payments p
                JOIN orders o ON o.id = p.order_id
                WHERE p.payment_status = 'Paid'
                  AND DATE(COALESCE(p.paid_at, p.created_at)) = CURDATE()
                ORDER BY COALESCE(p.paid_at, p.created_at) DESC
                LIMIT 12
            ")->fetchAll();
            $totalCount = (int)$conn->query("
                SELECT COUNT(*)
                FROM payments
                WHERE payment_status = 'Paid'
                  AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
            ")->fetchColumn();
            foreach ($rows as $r) {
                $timeStr = !empty($r['tx_time']) ? date('g:i A', strtotime((string)$r['tx_time'])) : '';
                $items[] = [
                    'title' => (string)$r['order_no'] . ' · ₱' . number_format((float)$r['total'], 2),
                    'detail' => (string)($r['customer_name'] ?: 'Walk-in Customer') . ($timeStr ? ' · ' . $timeStr : ''),
                    'tag' => (string)$r['payment_method'] . ' Paid',
                    'url' => BASE_URL . '/modules/reports/index.php?tab=payments',
                ];
            }
        } elseif ($module === 'users') {
            $custRoleId = (int)$conn->query("SELECT id FROM roles WHERE LOWER(role_name) = 'customer' LIMIT 1")->fetchColumn();
            if ($custRoleId > 0) {
                $deactRows = $conn->query("
                    SELECT full_name, username, email, customer_status, deleted_at
                    FROM users
                    WHERE role_id = {$custRoleId}
                      AND (customer_status IN ('Archived', 'Deactivated') OR deleted_at IS NOT NULL OR COALESCE(is_archived, 0) = 1)
                    ORDER BY COALESCE(deleted_at, created_at) DESC
                    LIMIT 5
                ")->fetchAll();
                foreach ($deactRows as $r) {
                    $items[] = [
                        'title' => (string)($r['full_name'] ?: $r['username']),
                        'detail' => (string)($r['email'] ?: $r['username']) . ' · Account deactivated or archived',
                        'tag' => 'Deactivated',
                        'url' => BASE_URL . '/modules/users/index.php',
                    ];
                }
                $newCustRows = $conn->query("
                    SELECT full_name, username, email, created_at
                    FROM users
                    WHERE role_id = {$custRoleId}
                      AND COALESCE(is_archived, 0) = 0
                      AND created_at >= (NOW() - INTERVAL 1 DAY)
                    ORDER BY created_at DESC
                    LIMIT 5
                ")->fetchAll();
                foreach ($newCustRows as $r) {
                    $items[] = [
                        'title' => (string)($r['full_name'] ?: $r['username']),
                        'detail' => 'Registered ' . date('M j, g:i A', strtotime((string)$r['created_at'])),
                        'tag' => 'New Customer',
                        'url' => BASE_URL . '/modules/users/index.php',
                    ];
                }
            }
            $staffLogRows = $conn->query("
                SELECT action, details, created_at
                FROM audit_logs
                WHERE target_type = 'user'
                  AND created_at >= (NOW() - INTERVAL 1 DAY)
                ORDER BY created_at DESC
                LIMIT 5
            ")->fetchAll();
            foreach ($staffLogRows as $r) {
                $items[] = [
                    'title' => ucwords(str_replace('_', ' ', (string)$r['action'])),
                    'detail' => mb_strimwidth((string)($r['details'] ?: 'Staff account updated'), 0, 75, '…'),
                    'tag' => 'Account Change',
                    'url' => BASE_URL . '/modules/users/index.php',
                ];
            }
            $totalCount = count($items);
        } elseif ($module === 'menu') {
            $unavailRows = $conn->query("
                SELECT item_name, stock, availability
                FROM menu_items
                WHERE COALESCE(is_archived, 0) = 0
                  AND (availability <> 'Available' OR stock <= 0)
                ORDER BY item_name ASC
                LIMIT 6
            ")->fetchAll();
            foreach ($unavailRows as $r) {
                $items[] = [
                    'title' => (string)$r['item_name'],
                    'detail' => 'Hidden or out of stock on customer menu',
                    'tag' => 'Unavailable',
                    'url' => BASE_URL . '/modules/menu/index.php',
                ];
            }
            $priceRows = $conn->query("
                SELECT item_name, old_price, new_price, created_at
                FROM price_change_logs
                WHERE created_at >= (NOW() - INTERVAL 1 DAY)
                ORDER BY created_at DESC
                LIMIT 6
            ")->fetchAll();
            foreach ($priceRows as $r) {
                $items[] = [
                    'title' => (string)$r['item_name'],
                    'detail' => '₱' . number_format((float)$r['old_price'], 2) . ' → ₱' . number_format((float)$r['new_price'], 2),
                    'tag' => 'Price Updated',
                    'url' => BASE_URL . '/modules/menu/index.php',
                ];
            }
            $totalCount = count($items);
        } elseif ($module === 'payments') {
            $cfg = $conn->query("
                SELECT sps.gcash_account_name, sps.gcash_phone, sps.gcash_qr_image, sps.updated_at, u.username AS updated_by_name
                FROM store_payment_settings sps
                LEFT JOIN users u ON u.id = sps.updated_by
                WHERE sps.id = 1
                LIMIT 1
            ")->fetch();
            if (!$cfg || trim((string)($cfg['gcash_account_name'] ?? '')) === '' || trim((string)($cfg['gcash_phone'] ?? '')) === '') {
                $items[] = [
                    'title' => 'Missing GCash Account Details',
                    'detail' => 'Set your GCash account name and 11-digit number for online checkout.',
                    'tag' => 'Action Needed',
                    'url' => BASE_URL . '/modules/settings/payments.php',
                ];
            }
            if ($cfg && trim((string)($cfg['gcash_qr_image'] ?? '')) === '') {
                $items[] = [
                    'title' => 'No GCash QR Code Uploaded',
                    'detail' => 'Upload a GCash QR image so customers can scan and pay easily.',
                    'tag' => 'Optional QR',
                    'url' => BASE_URL . '/modules/settings/payments.php',
                ];
            }
            if ($cfg && !empty($cfg['updated_at'])) {
                $by = !empty($cfg['updated_by_name']) ? (' by ' . $cfg['updated_by_name']) : '';
                $items[] = [
                    'title' => 'GCash Config Active',
                    'detail' => 'Last updated ' . date('M j, g:i A', strtotime((string)$cfg['updated_at'])) . $by,
                    'tag' => 'Active',
                    'url' => BASE_URL . '/modules/settings/payments.php',
                ];
            }
            $totalCount = count($items);
        } elseif ($module === 'approvals') {
            $rows = $conn->query("
                SELECT id, request_type, target_name
                FROM approval_requests
                WHERE status = 'Pending'
                ORDER BY created_at DESC
                LIMIT 8
            ")->fetchAll();
            foreach ($rows as $r) {
                $items[] = [
                    'title' => (string)($r['request_type'] ?: 'Approval Request'),
                    'detail' => (string)($r['target_name'] ?: ('Request #' . $r['id'])),
                    'tag' => 'Pending',
                    'url' => BASE_URL . '/modules/approvals/index.php',
                ];
            }
            $txRows = $conn->query("
                SELECT tr.id, tr.request_type, o.order_no, o.customer_name
                FROM transaction_requests tr
                LEFT JOIN orders o ON o.id = tr.order_id
                WHERE tr.status = 'Pending'
                ORDER BY tr.created_at DESC
                LIMIT 8
            ")->fetchAll();
            foreach ($txRows as $tr) {
                $items[] = [
                    'title' => (string)($tr['request_type'] ?: 'Transaction Request') . (!empty($tr['order_no']) ? (' · ' . $tr['order_no']) : ''),
                    'detail' => (string)($tr['customer_name'] ?: ('Request #' . $tr['id'])),
                    'tag' => 'Needs Review',
                    'url' => BASE_URL . '/modules/approvals/index.php',
                ];
            }
        } else {
            // Dashboard / general overview filtered by role
            if ($module === 'dashboard' && can_access('admin')) {
                $todayStats = $conn->query("
                    SELECT COUNT(*) AS total_orders,
                           SUM(CASE WHEN EXISTS (
                               SELECT 1 FROM payments p WHERE p.order_id = orders.id AND p.payment_status = 'Paid'
                           ) THEN 1 ELSE 0 END) AS paid_orders,
                           COALESCE(SUM(CASE WHEN EXISTS (
                               SELECT 1 FROM payments p WHERE p.order_id = orders.id AND p.payment_status = 'Paid'
                           ) THEN total ELSE 0 END), 0) AS paid_sales
                    FROM orders
                    WHERE DATE(created_at) = CURDATE()
                      AND status NOT IN ('Cancelled', 'Voided', 'Refunded')
                ")->fetch();
                $todayOrders = (int)($todayStats['total_orders'] ?? 0);
                $paidOrders = (int)($todayStats['paid_orders'] ?? 0);
                $paidSales = (float)($todayStats['paid_sales'] ?? 0);
                $items[] = [
                    'title' => 'Today’s Sales: ₱' . number_format($paidSales, 2),
                    'detail' => $todayOrders . ' order' . ($todayOrders === 1 ? '' : 's') . ' today · ' . $paidOrders . ' paid',
                    'tag' => 'Today',
                    'url' => BASE_URL . '/modules/reports/index.php',
                ];

                $dashOpenShift = $conn->query("
                    SELECT cs.opened_at, u.full_name, u.username
                    FROM cashier_shifts cs
                    JOIN users u ON u.id = cs.user_id
                    WHERE cs.status = 'Open'
                    ORDER BY cs.opened_at DESC
                    LIMIT 1
                ")->fetch();
                $dashHour = (int)date('G');
                $dashShiftWindow = ($dashHour >= 9 && $dashHour < 21)
                    ? 'First Shift (9:00 AM - 9:00 PM)'
                    : (($dashHour >= 21 || $dashHour < 3) ? 'Closing Shift (9:00 PM - 3:00 AM)' : 'Store Closed');
                $items[] = [
                    'title' => $dashOpenShift
                        ? ('Cashier Shift: ' . (string)($dashOpenShift['full_name'] ?: $dashOpenShift['username']))
                        : 'No Active Cashier Shift',
                    'detail' => $dashShiftWindow,
                    'tag' => $dashOpenShift ? 'Open' : 'Unopened',
                    'url' => BASE_URL . '/modules/pos/index.php',
                ];
            }

            $overviewMap = [
                'payment_verification' => ['QR Verification', BASE_URL . '/modules/pos/payment_verification.php', ['admin', 'cashier'], 'Pending GCash verification'],
                'cod_approvals' => ['COD Approvals', BASE_URL . '/modules/pos/cod_approvals.php', ['admin', 'cashier'], 'Pending COD approval'],
                'delivery' => ['Delivery Status', BASE_URL . '/modules/delivery/index.php', ['admin', 'cashier'], 'Needs delivery action'],
                'kitchen' => ['Kitchen Queue', BASE_URL . '/modules/kitchen/index.php', ['admin', 'kitchen'], 'Active kitchen orders'],
                'serving' => ['Serving Queue', BASE_URL . '/modules/serving/index.php', ['admin', 'cashier', 'kitchen'], 'Ready to serve'],
                'inventory' => ['Inventory Alerts', BASE_URL . '/modules/inventory/index.php', ['admin', 'inventory'], 'Low or out of stock'],
                'approvals' => ['Approvals', BASE_URL . '/modules/approvals/index.php', ['admin'], 'Pending admin approval'],
            ];
            $totalCount = 0;
            foreach ($overviewMap as $k => [$modName, $modUrl, $roles, $desc]) {
                if (!can_access($roles)) continue;
                $c = (int)($counts[$k] ?? 0);
                if ($c > 0) {
                    $totalCount += $c;
                    $items[] = [
                        'title' => $modName,
                        'detail' => $desc,
                        'tag' => (string)$c,
                        'url' => $modUrl,
                    ];
                }
            }
        }
    } catch (Throwable $t) {}

    return [
        'module' => $module,
        'label' => $label,
        'count' => $totalCount,
        'items' => $items,
    ];
}
?>
