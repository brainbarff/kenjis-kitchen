<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'approvals';
$title = 'Admin Approvals';
$heading = 'Admin Approvals';
$msg = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $reviewNote = trim($_POST['review_note'] ?? '');

    if ($requestId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['flash_msg'] = 'Invalid approval request.';
        redirect('/modules/approvals/index.php');
    }

    try {
        $conn->beginTransaction();

        $requestStmt = $conn->prepare("SELECT ar.*, u.full_name AS requester_name FROM approval_requests ar LEFT JOIN users u ON u.id = ar.requested_by WHERE ar.id = ? FOR UPDATE");
        $requestStmt->execute([$requestId]);
        $request = $requestStmt->fetch();

        if (!$request) {
            throw new RuntimeException('Approval request not found.');
        }

        if ($request['status'] !== 'Pending') {
            throw new RuntimeException('This approval request has already been reviewed.');
        }

        if ($action === 'reject') {
            $note = $reviewNote !== '' ? $reviewNote : 'Rejected by Admin.';
            $update = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
            $update->execute([$note, $_SESSION['u_id'], $requestId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Approval request could not be rejected.');
            }

            $conn->commit();
            $_SESSION['flash_msg'] = 'Request rejected.';
            redirect('/modules/approvals/index.php');
        }

        $type = $request['request_type'];
        $menuItemId = (int) ($request['menu_item_id'] ?? 0);
        $quantity = $request['quantity'] !== null ? (int) $request['quantity'] : null;

        if (in_array($type, ['Stock In', 'Stock Out', 'Adjustment'], true)) {
            if ($menuItemId <= 0 || $quantity === null || $quantity < 1) {
                throw new RuntimeException('The stock approval request contains invalid details.');
            }

            $itemStmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
            $itemStmt->execute([$menuItemId]);
            $item = $itemStmt->fetch();

            if (!$item || (int) $item['is_archived'] === 1) {
                $note = 'Menu item is no longer active.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: menu item is no longer active.';
                redirect('/modules/approvals/index.php');
            }

            $currentStock = (int) $item['stock'];
            if ($type === 'Stock In') {
                $newStock = $currentStock + $quantity;
                $logQuantity = $quantity;
            } elseif ($type === 'Stock Out') {
                if ($quantity > $currentStock) {
                    $note = 'Current stock is only ' . $currentStock . ' pcs, so the requested Stock Out cannot be approved.';
                    $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                    $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                    $conn->commit();
                    $_SESSION['flash_msg'] = 'Request rejected: current stock is lower than the requested Stock Out quantity.';
                    redirect('/modules/approvals/index.php');
                }
                $newStock = $currentStock - $quantity;
                $logQuantity = $quantity;
            } else {
                $newStock = $quantity;
                $logQuantity = $newStock;
            }

            $update = $conn->prepare("UPDATE menu_items SET stock = ?, availability = ? WHERE id = ? AND is_archived = 0");
            $update->execute([$newStock, $newStock <= 0 ? 'Unavailable' : 'Available', $menuItemId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Stock could not be updated.');
            }

            $requesterName = trim((string) ($request['requester_name'] ?? '')) ?: 'Inventory Staff';
            $remarks = trim((string) ($request['remarks'] ?? ''));
            $remarks = trim($remarks . ' Approved by Admin for request #' . $requestId . ' from ' . $requesterName);
            $remarks = substr($remarks, 0, 255);

            $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, ?, ?, ?)");
            $log->execute([$menuItemId, $_SESSION['u_id'], $type, $logQuantity, $remarks]);
        } elseif ($type === 'Archive') {
            if ($menuItemId <= 0) {
                throw new RuntimeException('The archive request contains an invalid menu item.');
            }

            $itemStmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
            $itemStmt->execute([$menuItemId]);
            $item = $itemStmt->fetch();
            if (!$item || (int) $item['is_archived'] === 1) {
                $note = 'Menu item is already archived or no longer exists.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: menu item is already archived.';
                redirect('/modules/approvals/index.php');
            }

            if ((int) $item['stock'] > 0) {
                $note = 'Archive approvals require the menu item to have 0 stock.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: menu item still has stock. Stock Out must reduce it to 0 first.';
                redirect('/modules/approvals/index.php');
            }

            $update = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ? AND is_archived = 0 AND stock <= 0");
            $update->execute([$menuItemId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Menu item could not be archived.');
            }
        } elseif ($type === 'Unarchive') {
            if ($menuItemId <= 0) {
                throw new RuntimeException('The restore request contains an invalid menu item.');
            }

            $itemStmt = $conn->prepare("SELECT id, item_name, stock, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
            $itemStmt->execute([$menuItemId]);
            $item = $itemStmt->fetch();
            if (!$item || (int) $item['is_archived'] === 0) {
                $note = 'Menu item is already active or no longer exists.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: menu item is already active.';
                redirect('/modules/approvals/index.php');
            }

            $update = $conn->prepare("UPDATE menu_items SET is_archived = 0, availability = IF(stock > 0, 'Available', 'Unavailable') WHERE id = ? AND is_archived = 1");
            $update->execute([$menuItemId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Menu item could not be restored.');
            }
        } else {
            throw new RuntimeException('Unsupported approval request type.');
        }

        $updateRequest = $conn->prepare("UPDATE approval_requests SET status = 'Approved', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
        $updateRequest->execute([$reviewNote !== '' ? $reviewNote : 'Approved by Admin.', $_SESSION['u_id'], $requestId]);
        if ($updateRequest->rowCount() !== 1) {
            throw new RuntimeException('Approval request could not be completed.');
        }

        $conn->commit();
        $_SESSION['flash_msg'] = 'Request approved and applied.';
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['flash_msg'] = 'Unable to process approval: ' . $e->getMessage();
    }

    redirect('/modules/approvals/index.php');
}

$pending = $conn->query("
    SELECT ar.*, mi.item_name, u.full_name AS requester_name
    FROM approval_requests ar
    LEFT JOIN menu_items mi ON mi.id = ar.menu_item_id
    LEFT JOIN users u ON u.id = ar.requested_by
    WHERE ar.status = 'Pending'
    ORDER BY ar.created_at ASC, ar.id ASC
")->fetchAll();

$recent = $conn->query("
    SELECT ar.*, mi.item_name, u.full_name AS requester_name, r.full_name AS reviewer_name
    FROM approval_requests ar
    LEFT JOIN menu_items mi ON mi.id = ar.menu_item_id
    LEFT JOIN users u ON u.id = ar.requested_by
    LEFT JOIN users r ON r.id = ar.reviewed_by
    WHERE ar.status IN ('Approved','Rejected')
    ORDER BY ar.reviewed_at DESC, ar.id DESC
    LIMIT 30
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<style>
.approval-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.approval-actions form {
    margin: 0;
}
.approval-summary {
    margin-top: 6px;
}
</style>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>

<section class="grid grid-3">
    <article class="card stat"><div><span class="muted">Pending Requests</span><strong><?= e(count($pending)) ?></strong></div><i class="bi bi-hourglass-split"></i></article>
    <article class="card stat"><div><span class="muted">Approved</span><strong><?= e(count(array_filter($recent, static fn($row) => $row['status'] === 'Approved'))) ?></strong></div><i class="bi bi-check-circle"></i></article>
    <article class="card stat"><div><span class="muted">Rejected</span><strong><?= e(count(array_filter($recent, static fn($row) => $row['status'] === 'Rejected'))) ?></strong></div><i class="bi bi-x-circle"></i></article>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Pending Approval Requests</h2>
            <p class="muted">Inventory and Menu requests that require Admin approval before changes are applied.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Request</th><th>Menu Item</th><th>Requested By</th><th>Details</th><th>Date / Time</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($pending): ?>
                    <?php foreach ($pending as $row): ?>
                        <?php
                        $detail = $row['request_type'];
                        if (in_array($row['request_type'], ['Stock In', 'Stock Out', 'Adjustment'], true)) {
                            $detail .= $row['request_type'] === 'Adjustment' ? ' → ' . (int) $row['quantity'] . ' pcs' : ' • ' . (int) $row['quantity'] . ' pcs';
                        }
                        ?>
                        <tr>
                            <td><strong>#<?= e($row['id']) ?></strong><div class="approval-summary muted"><?= e($detail) ?></div></td>
                            <td><?= e($row['item_name'] ?? 'Unknown Menu Item') ?></td>
                            <td><?= e($row['requester_name'] ?? 'Unknown User') ?></td>
                            <td><?= e($row['remarks'] ?? '') ?></td>
                            <td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                            <td>
                                <div class="approval-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= e($row['id']) ?>">
                                        <button class="btn btn-primary" type="submit" data-confirm="Approve and apply this request?"><i class="bi bi-check2"></i>Approve</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="request_id" value="<?= e($row['id']) ?>">
                                        <button class="btn btn-danger" type="submit" data-confirm="Reject this request?"><i class="bi bi-x"></i>Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:28px;">No pending approval requests.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Recent Approval History</h2>
            <p class="muted">The latest Admin decisions are retained for monitoring.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Request</th><th>Menu Item</th><th>Requested By</th><th>Status</th><th>Reviewed By</th><th>Date / Time</th></tr></thead>
            <tbody>
                <?php if ($recent): ?>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><strong>#<?= e($row['id']) ?></strong><div class="approval-summary muted"><?= e($row['request_type']) ?></div></td>
                            <td><?= e($row['item_name'] ?? 'Unknown Menu Item') ?></td>
                            <td><?= e($row['requester_name'] ?? 'Unknown User') ?></td>
                            <td><span class="badge <?= $row['status'] === 'Approved' ? 'badge-success' : 'badge-danger' ?>"><?= e($row['status']) ?></span></td>
                            <td><?= e($row['reviewer_name'] ?? 'Admin') ?></td>
                            <td><?= $row['reviewed_at'] ? e(date('M d, Y h:i A', strtotime($row['reviewed_at']))) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:28px;">No reviewed requests yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
