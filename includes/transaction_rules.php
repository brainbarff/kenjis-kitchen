<?php
declare(strict_types=1);

function transaction_allowed_statuses(string $type): array
{
    return match ($type) {
        'Void' => ['Pending'],
        'Refund' => ['Served', 'Picked Up', 'Cancelled'],
        'Cancel Delivery' => ['Ready'],
        default => [],
    };
}

function transaction_require_status(string $type, string $status): void
{
    $allowed = transaction_allowed_statuses($type);
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException($type . ' requests require an order with status: ' . implode(', ', $allowed) . '.');
    }
}

/** Caller holds the order lock and records the Admin decision in this transaction. */
function transaction_refund_payment(PDO $conn, int $paymentId): void
{
    if (!$conn->inTransaction()) throw new LogicException('Refund requires a transaction.');
    $stmt = $conn->prepare("UPDATE payments SET payment_status = 'Refunded' WHERE id = ? AND payment_status = 'Paid'");
    $stmt->execute([$paymentId]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Payment has changed or was already refunded.');
}

/** Find an open cashier shift for a given user, or any open shift on Main POS when $userId is null. */
function transaction_find_open_shift_id(PDO $conn, ?int $userId = null): ?int
{
    try {
        if ($userId !== null && $userId > 0) {
            $stmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
        $anyStmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE status = 'Open' ORDER BY (terminal = 'Main POS') DESC, id DESC LIMIT 1");
        $anyStmt->execute();
        $anyId = (int)$anyStmt->fetchColumn();
        return $anyId > 0 ? $anyId : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve which active shift handles a paid void/refund payout.
 * Ensures cross-shift and different-method refunds are attributed to the currently open shift
 * rather than corrupting a closed historical shift.
 */
function transaction_resolve_refund_shift_id(
    PDO $conn,
    array $order,
    array $payment,
    array $request,
    int $adminUserId,
    ?int $explicitShiftId = null
): ?int {
    try {
        $checkOpen = $conn->prepare("SELECT id FROM cashier_shifts WHERE id = ? AND status = 'Open' LIMIT 1 FOR UPDATE");
        $checkUserOpen = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1 FOR UPDATE");

        // 1. Explicit open shift chosen at approval
        if ($explicitShiftId !== null && $explicitShiftId > 0) {
            $checkOpen->execute([$explicitShiftId]);
            $id = (int)$checkOpen->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        // 2. Shift recorded when the request was submitted, if still open
        $reqShiftId = (int)($request['refund_shift_id'] ?? 0);
        if ($reqShiftId > 0) {
            $checkOpen->execute([$reqShiftId]);
            $id = (int)$checkOpen->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        // 3. Requesting cashier's currently open shift
        $requestedBy = (int)($request['requested_by'] ?? 0);
        if ($requestedBy > 0) {
            $checkUserOpen->execute([$requestedBy]);
            $id = (int)$checkUserOpen->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        // 4. Order's original shift, only if still open
        $origShiftId = (int)($order['shift_id'] ?? ($payment['shift_id'] ?? 0));
        if ($origShiftId > 0) {
            $checkOpen->execute([$origShiftId]);
            $id = (int)$checkOpen->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        // 5. Reviewing admin's currently open shift
        if ($adminUserId > 0) {
            $checkUserOpen->execute([$adminUserId]);
            $id = (int)$checkUserOpen->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        // 6. Any open shift on Main POS or active terminal
        $anyOpen = $conn->prepare("SELECT id FROM cashier_shifts WHERE status = 'Open' ORDER BY (terminal = 'Main POS') DESC, id DESC LIMIT 1 FOR UPDATE");
        $anyOpen->execute();
        $id = (int)$anyOpen->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        // Ignore if table unavailable in minimal unit test DB
    }

    return null;
}

/**
 * Compute reconciled shift metrics including gross cash inflows and shift-scoped cash refunds.
 * Expected drawer cash = opening_cash + gross_cash_collected - cash_refunds_in_shift.
 */
function kk_calculate_shift_metrics(PDO $conn, int $shiftId): array
{
    $shiftStmt = $conn->prepare("SELECT id, opening_cash, opened_at, closed_at, status FROM cashier_shifts WHERE id = ? LIMIT 1");
    $shiftStmt->execute([$shiftId]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => $shiftId,
        'opening_cash' => 0.0,
        'opened_at' => null,
        'closed_at' => null,
        'status' => 'Open',
    ];

    $openingCash = (float)($shift['opening_cash'] ?? 0.0);
    $closedAt = $shift['closed_at'] ?? null;

    // 1. Orders and payments originally collected in this shift
    $salesStmt = $conn->prepare("
        SELECT
            COUNT(CASE WHEN p.payment_status = 'Paid' AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded') THEN o.id END) AS order_count,
            COALESCE(SUM(CASE WHEN p.payment_status = 'Paid' AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded') THEN o.total ELSE 0 END), 0) AS total_sales,
            COALESCE(SUM(CASE WHEN p.payment_status = 'Paid' AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded') AND LOWER(TRIM(p.payment_method)) IN ('cash', 'cash on delivery', 'pay on pickup') THEN o.total ELSE 0 END), 0) AS cash_sales,
            COALESCE(SUM(CASE WHEN p.payment_status = 'Paid' AND o.status NOT IN ('Cancelled', 'Voided', 'Refunded') AND LOWER(TRIM(p.payment_method)) = 'gcash' THEN o.total ELSE 0 END), 0) AS gcash_sales,
            COALESCE(SUM(CASE WHEN p.payment_status IN ('Paid', 'Refunded') AND LOWER(TRIM(p.payment_method)) IN ('cash', 'cash on delivery', 'pay on pickup') THEN o.total ELSE 0 END), 0) AS gross_cash_collected,
            COALESCE(SUM(CASE WHEN p.payment_status IN ('Paid', 'Refunded') AND LOWER(TRIM(p.payment_method)) = 'gcash' THEN o.total ELSE 0 END), 0) AS gross_gcash_collected
        FROM orders o
        JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
        WHERE (o.shift_id = ? OR (o.shift_id IS NULL AND p.shift_id = ?))
          AND o.paid_at IS NOT NULL
    ");
    $salesStmt->execute([$shiftId, $shiftId]);
    $sales = $salesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // 2. Approved refunds / paid voids executed in this shift
    $refStmt = $conn->prepare("
        SELECT
            COUNT(tr.id) AS refund_count,
            COALESCE(SUM(o.total), 0) AS total_refunds,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(tr.refund_method)) = 'cash' THEN o.total ELSE 0 END), 0) AS cash_refunds,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(tr.refund_method)) = 'gcash' THEN o.total ELSE 0 END), 0) AS gcash_refunds
        FROM transaction_requests tr
        JOIN orders o ON o.id = tr.order_id
        LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
        WHERE tr.status = 'Approved'
          AND tr.request_type IN ('Refund', 'Void')
          AND tr.refund_confirmed_at IS NOT NULL
          AND (
              tr.refund_shift_id = ?
              OR (
                  tr.refund_shift_id IS NULL
                  AND (o.shift_id = ? OR (o.shift_id IS NULL AND p.shift_id = ?))
                  AND (? IS NULL OR tr.refund_confirmed_at <= ?)
              )
          )
    ");
    $refStmt->execute([$shiftId, $shiftId, $shiftId, $closedAt, $closedAt]);
    $ref = $refStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // 3. Void logs in this shift
    $voidStmt = $conn->prepare("
        SELECT
            COUNT(*) AS void_count,
            COALESCE(SUM(price * quantity), 0) AS void_total
        FROM void_logs
        WHERE shift_id = ?
    ");
    $voidStmt->execute([$shiftId]);
    $voids = $voidStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $grossCashCollected = (float)($sales['gross_cash_collected'] ?? 0.0);
    $cashRefunds = (float)($ref['cash_refunds'] ?? 0.0);
    $gcashRefunds = (float)($ref['gcash_refunds'] ?? 0.0);
    $totalRefunds = (float)($ref['total_refunds'] ?? 0.0);
    $expectedCash = round($openingCash + $grossCashCollected - $cashRefunds, 2);

    return [
        'shift_id'             => $shiftId,
        'opening_cash'         => $openingCash,
        'order_count'          => (int)($sales['order_count'] ?? 0),
        'total_sales'          => (float)($sales['total_sales'] ?? 0.0),
        'cash_sales'           => (float)($sales['cash_sales'] ?? 0.0),
        'gcash_sales'          => (float)($sales['gcash_sales'] ?? 0.0),
        'gross_cash_collected' => $grossCashCollected,
        'gross_gcash_collected'=> (float)($sales['gross_gcash_collected'] ?? 0.0),
        'refund_count'         => (int)($ref['refund_count'] ?? 0),
        'total_refunds'        => $totalRefunds,
        'cash_refunds'         => $cashRefunds,
        'gcash_refunds'        => $gcashRefunds,
        'expected_cash'        => $expectedCash,
        'void_count'           => (int)($voids['void_count'] ?? 0),
        'void_total'           => (float)($voids['void_total'] ?? 0.0),
    ];
}
