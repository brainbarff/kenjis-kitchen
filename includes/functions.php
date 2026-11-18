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
?>
