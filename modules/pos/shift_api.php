<?php
/**
 * Kenji's Kitchen - Cashier Shifts API
 * POST /modules/pos/shift_api.php?action=start|end|status
 */
header('Content-Type: application/json; charset=UTF-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/pos_cashier.php';
require_once ROOT_PATH . '/includes/transaction_rules.php';
require_role(['admin', 'cashier']);

$action = $_GET['action'] ?? '';
if ($action !== 'status' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token())) {
    http_response_code($_SERVER['REQUEST_METHOD'] !== 'POST' ? 405 : 403);
    echo json_encode(['ok' => false, 'msg' => 'Invalid request method or security token.']);
    exit;
}
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}
try {
    $userId = (int)pos_active_cashier($conn)['id'];
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}

// Helper to auto-close shifts left open overnight
function auto_close_overnight_shifts(PDO $conn, int $userId): void {
    $stmt = $conn->prepare("
        UPDATE cashier_shifts 
        SET status = 'Closed', 
            closed_at = NOW(), 
            auto_closed = 1,
            remarks = COALESCE(CONCAT(remarks, ' [Auto-closed overnight by system]'), '[Auto-closed overnight by system]')
        WHERE user_id = ? AND status = 'Open' AND DATE(opened_at) < CURDATE()
    ");
    $stmt->execute([$userId]);
}
auto_close_overnight_shifts($conn, $userId);

// 1. GET CURRENT SHIFT STATUS
if ($action === 'status') {
    $terminal = trim($_GET['terminal'] ?? 'Main POS');
    
    // Check if current user has an open shift
    $stmt = $conn->prepare("
        SELECT * FROM cashier_shifts 
        WHERE user_id = ? AND status = 'Open' 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $userShift = $stmt->fetch();

    // Check if terminal has an open shift by another user
    $termStmt = $conn->prepare("
        SELECT cs.*, u.full_name, u.username 
        FROM cashier_shifts cs
        JOIN users u ON u.id = cs.user_id
        WHERE cs.terminal = ? AND cs.status = 'Open' AND cs.user_id != ?
        LIMIT 1
    ");
    $termStmt->execute([$terminal, $userId]);
    $otherShift = $termStmt->fetch();

    // Fetch operational rules
    $rows = $conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $settings = [
        'self_void_limit_per_order'     => (float)($rows['self_void_limit_per_order'] ?? 50),
        'max_self_voids_per_shift'      => (int)($rows['max_self_voids_per_shift'] ?? 5),
        'max_self_void_total_per_shift' => (float)($rows['max_self_void_total_per_shift'] ?? 200),
        'over_short_threshold'          => (float)($rows['over_short_threshold'] ?? 50),
        'require_opening_cash'          => (int)($rows['require_opening_cash'] ?? 1),
    ];

    $shiftSummary = null;
    if ($userShift) {
        $shiftId = (int)$userShift['id'];
        $metrics = kk_calculate_shift_metrics($conn, $shiftId);

        $shiftSummary = [
            'shift_id'             => $shiftId,
            'terminal'             => $userShift['terminal'],
            'opened_at'            => $userShift['opened_at'],
            'opening_cash'         => $metrics['opening_cash'],
            'order_count'          => $metrics['order_count'],
            'total_sales'          => $metrics['total_sales'],
            'cash_sales'           => $metrics['cash_sales'],
            'gcash_sales'          => $metrics['gcash_sales'],
            'gross_cash_collected' => $metrics['gross_cash_collected'],
            'total_refunds'        => $metrics['total_refunds'],
            'cash_refunds'         => $metrics['cash_refunds'],
            'gcash_refunds'        => $metrics['gcash_refunds'],
            'expected_cash'        => $metrics['expected_cash'],
            'void_count'           => $metrics['void_count'],
            'void_total'           => $metrics['void_total'],
        ];
    }

    echo json_encode([
        'ok'            => true,
        'has_open_shift'=> !empty($userShift),
        'shift'         => $shiftSummary,
        'terminal_busy' => !empty($otherShift),
        'busy_cashier'  => $otherShift ? ($otherShift['full_name'] ?: $otherShift['username']) : null,
        'settings'      => $settings
    ]);
    exit;
}

// 2. START SHIFT
if ($action === 'start') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $openingCash = max(0, (float)($data['opening_cash'] ?? 0));
    $terminal = trim($data['terminal'] ?? 'Main POS');
    if ($terminal === '') {
        $terminal = 'Main POS';
    }

    try {
        $conn->beginTransaction();

        // Serialize concurrent shift-open attempts across connections for the same cashier and terminal
        $lockUserStmt = $conn->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $lockUserStmt->execute([$userId]);
        $conn->query("SELECT setting_key FROM system_settings WHERE setting_key = 'require_opening_cash' FOR UPDATE");

        // Rule: Prevent two open shifts for one cashier (locking current read)
        $stmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'You already have an active open shift.']);
            exit;
        }

        // Rule: Prevent two open shifts on the same terminal (locking current read)
        $termStmt = $conn->prepare("SELECT id, user_id FROM cashier_shifts WHERE terminal = ? AND status = 'Open' LIMIT 1 FOR UPDATE");
        $termStmt->execute([$terminal]);
        if ($existing = $termStmt->fetch()) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Terminal "' . $terminal . '" already has an open shift in progress.']);
            exit;
        }

        $ins = $conn->prepare("
            INSERT INTO cashier_shifts (user_id, terminal, opening_cash, status, opened_at)
            VALUES (?, ?, ?, 'Open', NOW())
        ");
        $ins->execute([$userId, $terminal, $openingCash]);
        $newShiftId = (int)$conn->lastInsertId();

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Unable to start shift. Please try again.']);
        exit;
    }

    $_SESSION['active_shift_id'] = $newShiftId;
    log_audit($conn, 'Shift Started', 'shift', (string)$newShiftId, "Cashier #{$userId} started shift on {$terminal} with opening cash ₱{$openingCash}");

    echo json_encode(['ok' => true, 'shift_id' => $newShiftId, 'msg' => 'Shift started successfully.']);
    exit;
}

// 3. END SHIFT / HANDOVER
if ($action === 'end') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $countedCash = (float)($data['closing_cash'] ?? 0);
    $remarks = trim($data['remarks'] ?? '');

    try {
        $conn->beginTransaction();

        // Lock user's open shift
        $stmt = $conn->prepare("SELECT * FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);
        $shift = $stmt->fetch();

        if (!$shift) {
            $conn->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'No active shift found to close.']);
            exit;
        }

        $shiftId = (int)$shift['id'];
        $metrics = kk_calculate_shift_metrics($conn, $shiftId);

        $cashSales = $metrics['cash_sales'];
        $gcashSales = $metrics['gcash_sales'];
        $totalSales = $metrics['total_sales'];
        $totalRefunds = $metrics['total_refunds'];
        $cashRefunds = $metrics['cash_refunds'];
        $expectedCash = $metrics['expected_cash'];
        $variance = round($countedCash - $expectedCash, 2);

        // Check threshold note requirement
        $threshold = (float)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'over_short_threshold' LIMIT 1")->fetchColumn() ?: 50);

        if (abs($variance) > $threshold && $remarks === '') {
            $conn->rollBack();
            echo json_encode([
                'ok' => false,
                'msg' => "Variance of ₱" . number_format(abs($variance), 2) . " exceeds the ₱{$threshold} threshold. An explanatory note is required."
            ]);
            exit;
        }

        $upd = $conn->prepare("
            UPDATE cashier_shifts 
            SET closing_cash = ?, expected_cash = ?, variance = ?, total_sales = ?, cash_sales = ?, gcash_sales = ?, total_refunds = ?, status = 'Closed', closed_at = NOW(), remarks = ?
            WHERE id = ? AND status = 'Open'
        ");
        $upd->execute([$countedCash, $expectedCash, $variance, $totalSales, $cashSales, $gcashSales, $totalRefunds, $remarks, $shiftId]);

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Unable to close shift. Please try again.']);
        exit;
    }

    unset($_SESSION['active_shift_id']);
    log_audit($conn, 'Shift Closed / Handover', 'shift', (string)$shiftId, "Cashier #{$userId} ended shift. Expected: ₱{$expectedCash}, Counted: ₱{$countedCash}, Variance: ₱{$variance}, Cash Refunds: ₱{$cashRefunds}. Note: {$remarks}");

    echo json_encode([
        'ok' => true,
        'msg' => 'Shift ended successfully.',
        'data' => [
            'shift_id'      => $shiftId,
            'expected_cash' => $expectedCash,
            'closing_cash'  => $countedCash,
            'variance'      => $variance,
            'total_refunds' => $totalRefunds,
            'cash_refunds'  => $cashRefunds
        ]
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Invalid shift action.']);
