<?php
/**
 * Kenji's Kitchen – Price Update API
 * Handles immediate and scheduled base price updates for individual items and bulk categories.
 * Supports cross-origin requests from custom domains and Firebase hosting.
 */

// Credentialed requests are allowed only from explicitly configured origins.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_filter(array_map('trim', explode(',', getenv('KK_ALLOWED_ORIGINS') ?: '')));
$allowedOrigins[] = 'https://kenji-s-kitchen-otp.web.app';
$allowedOrigins[] = 'https://kenji-s-kitchen-otp.firebaseapp.com';
$appUrl = getenv('APP_URL') ?: '';
if ($appUrl !== '' && ($appHost = parse_url($appUrl, PHP_URL_HOST))) {
    $allowedOrigins[] = 'https://' . $appHost;
    if (parse_url($appUrl, PHP_URL_SCHEME) === 'http') $allowedOrigins[] = 'http://' . $appHost;
}
$allowedOrigins = array_unique($allowedOrigins);
if ($origin !== '') {
    if (!in_array($origin, $allowedOrigins, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => 'Origin is not allowed.']);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
kk_start_session();

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

function json_res($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Shared helper – defines processPendingPriceUpdates()
require_once __DIR__ . '/price_helpers.php';

// If called via GET, return pending scheduled updates or items
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'scheduled') {
        if (!can_access('admin')) json_res(['ok' => false, 'message' => 'Unauthorized.'], 403);
        processPendingPriceUpdates($conn);
        $stmt = $conn->query("
            SELECT id, item_name, price, pending_price, pending_price_effective
            FROM menu_items
            WHERE pending_price IS NOT NULL
            ORDER BY pending_price_effective ASC
        ");
        json_res(['ok' => true, 'scheduled' => $stmt->fetchAll()]);
    }
    json_res(['ok' => true, 'message' => 'Price Update API ready.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_res(['ok' => false, 'message' => 'Method not allowed.'], 405);

// Ensure user has admin privileges for price modifications
if (!can_access('admin')) {
    json_res(['ok' => false, 'message' => 'Unauthorized. Admin permissions required.'], 403);
}

// Decode JSON or POST form data
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$submittedToken = (is_array($data) && isset($data['csrf_token'])) ? $data['csrf_token'] : null;
if (!verify_csrf_token($submittedToken)) {
    json_res(['ok' => false, 'message' => 'Invalid or expired security token. Please reload the page and try again.'], 403);
}

processPendingPriceUpdates($conn);

$action = $data['action'] ?? 'single';
$userId = (int)($_SESSION['u_id'] ?? 1);

// ── ACTION A: Single Item Price Adjustment ──────────────────────────────────
if ($action === 'single') {
    $itemId = (int)($data['item_id'] ?? 0);
    $newPrice = filter_var($data['new_price'] ?? null, FILTER_VALIDATE_FLOAT);
    $effectiveAt = trim((string)($data['effective_at'] ?? '')); // e.g. YYYY-MM-DDTHH:MM or empty

    if (!$itemId || $newPrice === false || $newPrice < 0) {
        json_res(['ok' => false, 'message' => 'Valid item ID and positive price are required.'], 400);
    }

    $stmt = $conn->prepare("SELECT id, item_name, price FROM menu_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        json_res(['ok' => false, 'message' => 'Menu item not found.'], 404);
    }

    $oldPrice = (float)$item['price'];
    $isScheduled = false;

    if ($effectiveAt !== '') {
        $effectiveTimestamp = strtotime($effectiveAt);
        if (!$effectiveTimestamp || $effectiveTimestamp <= time()) json_res(['ok' => false, 'message' => 'Effective date must be a valid future date and time.'], 400);
        $isScheduled = true;
        $effectiveDate = date('Y-m-d H:i:s', $effectiveTimestamp);
    }

    if ($isScheduled) {
        // Save as scheduled price
        $up = $conn->prepare("
            UPDATE menu_items
            SET pending_price = ?, pending_price_effective = ?
            WHERE id = ?
        ");
        $up->execute([$newPrice, $effectiveDate, $itemId]);

        $log = $conn->prepare("
            INSERT INTO price_change_logs
            (menu_item_id, item_name, old_price, new_price, adjustment_type, status, effective_at, created_by)
            VALUES (?, ?, ?, ?, 'Single', 'Scheduled', ?, ?)
        ");
        $log->execute([$itemId, $item['item_name'], $oldPrice, $newPrice, $effectiveDate, $userId]);

        json_res([
            'ok' => true,
            'scheduled' => true,
            'message' => "Price change for {$item['item_name']} scheduled to ₱" . number_format($newPrice, 2) . " on " . date('M j, Y g:i A', $effectiveTimestamp) . ".",
            'pending_price' => $newPrice,
            'effective_date' => $effectiveDate
        ]);
    } else {
        // Apply immediately
        $up = $conn->prepare("
            UPDATE menu_items
            SET price = ?, pending_price = NULL, pending_price_effective = NULL
            WHERE id = ?
        ");
        $up->execute([$newPrice, $itemId]);

        $log = $conn->prepare("
            INSERT INTO price_change_logs
            (menu_item_id, item_name, old_price, new_price, adjustment_type, status, effective_at, created_by)
            VALUES (?, ?, ?, ?, 'Single', 'Immediate', NOW(), ?)
        ");
        $log->execute([$itemId, $item['item_name'], $oldPrice, $newPrice, $userId]);

        json_res([
            'ok' => true,
            'scheduled' => false,
            'message' => "Price for {$item['item_name']} updated to ₱" . number_format($newPrice, 2) . " immediately.",
            'new_price' => $newPrice
        ]);
    }
}

// ── ACTION B: Cancel Scheduled Update ──────────────────────────────────────
if ($action === 'cancel_scheduled') {
    $itemId = (int)($data['item_id'] ?? 0);
    if (!$itemId) {
        json_res(['ok' => false, 'message' => 'Invalid item ID.'], 400);
    }

    $up = $conn->prepare("
        UPDATE menu_items
        SET pending_price = NULL, pending_price_effective = NULL
        WHERE id = ?
    ");
    $up->execute([$itemId]);

    $conn->prepare("UPDATE price_change_logs SET status = 'Cancelled' WHERE menu_item_id = ? AND status = 'Scheduled'")->execute([$itemId]);

    json_res(['ok' => true, 'message' => 'Scheduled price adjustment cancelled.']);
}

// ── ACTION C: Bulk Adjust Prices ───────────────────────────────────────────
if ($action === 'bulk') {
    $targetCategory = $data['category_id'] ?? 'all'; // 'all' or numeric category_id
    $adjustType = $data['adjust_type'] ?? 'fixed';    // 'fixed' or 'percent'
    $adjustValue = (float)($data['adjust_value'] ?? 0);
    $operation = $data['operation'] ?? 'increase';     // 'increase' or 'decrease'
    $effectiveAt = trim((string)($data['effective_at'] ?? ''));

    if (!in_array($adjustType, ['fixed', 'percent'], true) || !in_array($operation, ['increase', 'decrease'], true) || !is_numeric($data['adjust_value'] ?? null) || $adjustValue <= 0 || ($adjustType === 'percent' && $adjustValue > 10000)) {
        json_res(['ok' => false, 'message' => 'Adjustment value must be greater than zero.'], 400);
    }
    if ($targetCategory !== 'all' && (!ctype_digit((string)$targetCategory) || (int)$targetCategory < 1)) json_res(['ok' => false, 'message' => 'Choose a valid category or all items.'], 400);

    // Determine target items
    $sql = "SELECT id, item_name, price FROM menu_items WHERE is_archived = 0";
    $params = [];
    if ($targetCategory !== 'all' && is_numeric($targetCategory)) {
        $sql .= " AND category_id = ?";
        $params[] = (int)$targetCategory;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $targetItems = $stmt->fetchAll();

    if (empty($targetItems)) {
        json_res(['ok' => false, 'message' => 'No active menu items found for this selection.'], 404);
    }

    $isScheduled = false;
    if ($effectiveAt !== '') {
        $effectiveTimestamp = strtotime($effectiveAt);
        if (!$effectiveTimestamp || $effectiveTimestamp <= time()) json_res(['ok' => false, 'message' => 'Effective date must be a valid future date and time.'], 400);
        $isScheduled = true;
        $effectiveDate = date('Y-m-d H:i:s', $effectiveTimestamp);
    }

    $updatedCount = 0;
    $conn->beginTransaction();

    try {
        foreach ($targetItems as $item) {
            $oldPrice = (float)$item['price'];
            $delta = ($adjustType === 'percent') ? ($oldPrice * ($adjustValue / 100)) : $adjustValue;
            $computedPrice = ($operation === 'decrease') ? max(0, $oldPrice - $delta) : ($oldPrice + $delta);
            $computedPrice = round($computedPrice, 2);

            if ($isScheduled) {
                $up = $conn->prepare("UPDATE menu_items SET pending_price = ?, pending_price_effective = ? WHERE id = ?");
                $up->execute([$computedPrice, $effectiveDate, $item['id']]);

                $log = $conn->prepare("
                    INSERT INTO price_change_logs
                    (menu_item_id, item_name, old_price, new_price, adjustment_type, status, effective_at, created_by)
                    VALUES (?, ?, ?, ?, ?, 'Scheduled', ?, ?)
                ");
                $log->execute([$item['id'], $item['item_name'], $oldPrice, $computedPrice, "Bulk $adjustType", $effectiveDate, $userId]);
            } else {
                $up = $conn->prepare("UPDATE menu_items SET price = ?, pending_price = NULL, pending_price_effective = NULL WHERE id = ?");
                $up->execute([$computedPrice, $item['id']]);

                $log = $conn->prepare("
                    INSERT INTO price_change_logs
                    (menu_item_id, item_name, old_price, new_price, adjustment_type, status, effective_at, created_by)
                    VALUES (?, ?, ?, ?, ?, 'Immediate', NOW(), ?)
                ");
                $log->execute([$item['id'], $item['item_name'], $oldPrice, $computedPrice, "Bulk $adjustType", $userId]);
            }
            $updatedCount++;
        }

        $conn->commit();

        $actionWord = $isScheduled ? "scheduled for " . date('M j, Y g:i A', $effectiveTimestamp) : "applied immediately";
        json_res([
            'ok' => true,
            'count' => $updatedCount,
            'scheduled' => $isScheduled,
            'message' => "Successfully {$actionWord} price update for {$updatedCount} menu item(s)."
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        json_res(['ok' => false, 'message' => 'Bulk adjustment failed: ' . $e->getMessage()], 500);
    }
}

json_res(['ok' => false, 'message' => 'Invalid action.'], 400);
