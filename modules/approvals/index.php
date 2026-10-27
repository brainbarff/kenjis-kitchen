<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'approvals';
$title = 'Admin Approvals';
$heading = 'Admin Approvals';
$msg = $_SESSION['flash_msg'] ?? '';
$msgType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $_SESSION['flash_msg'] = 'Invalid or expired security token. Please reload the page and try again.';
        $_SESSION['flash_type'] = 'danger';
        redirect('/modules/approvals/index.php');
    }

    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $reviewNote = trim($_POST['review_note'] ?? '');

    if ($requestId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['flash_msg'] = 'Invalid approval request.';
        $_SESSION['flash_type'] = 'danger';
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
            if ($request['request_type'] === 'Add Item' && !empty($request['payload'])) {
                $payloadData = json_decode($request['payload'], true);
                if (!empty($payloadData['image']) && file_exists(UPLOAD_PATH . $payloadData['image'])) {
                    @unlink(UPLOAD_PATH . $payloadData['image']);
                }
            }
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
        $categoryId = (int) ($request['category_id'] ?? 0);
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
                $_SESSION['flash_type'] = 'danger';
                redirect('/modules/approvals/index.php');
            }

            $update = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ? AND is_archived = 0");
            $update->execute([$menuItemId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Menu item could not be archived.');
            }

            $archiveFlashMsg = 'Request approved: menu item "' . $item['item_name'] . '" has been archived.';
        } elseif ($type === 'Unarchive') {
            if ($menuItemId <= 0) {
                throw new RuntimeException('The restore request contains an invalid menu item.');
            }

            $itemStmt = $conn->prepare("SELECT id, item_name, stock, availability, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
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

            $update = $conn->prepare("UPDATE menu_items SET is_archived = 0, availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE 'Available' END WHERE id = ? AND is_archived = 1");
            $update->execute([$menuItemId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Menu item could not be restored.');
            }

            if ((int) $item['stock'] <= 0) {
                $unarchiveFlashMsg = 'Request approved: "' . $item['item_name'] . '" restored with 0 stock (Unavailable). Restock it in Inventory before it can be sold.';
            } else {
                $unarchiveFlashMsg = 'Request approved: "' . $item['item_name'] . '" restored to active menu.';
            }
        } elseif ($type === 'Add Category') {
            $payload = json_decode($request['payload'] ?? '{}', true) ?: [];
            $catName = trim($payload['category_name'] ?? '');
            if ($catName === '') {
                throw new RuntimeException('Category name cannot be empty.');
            }

            $catCheck = $conn->prepare("SELECT id, category_name, status FROM categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1 FOR UPDATE");
            $catCheck->execute([$catName]);
            $existingCat = $catCheck->fetch();

            if ($existingCat && $existingCat['status'] === 'Active') {
                $note = 'Category "' . $existingCat['category_name'] . '" already exists and is active.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: category "' . $existingCat['category_name'] . '" already exists.';
                redirect('/modules/approvals/index.php');
            } elseif ($existingCat && $existingCat['status'] === 'Inactive') {
                $reactivateCat = $conn->prepare("UPDATE categories SET status = 'Active' WHERE id = ? AND status = 'Inactive'");
                $reactivateCat->execute([(int) $existingCat['id']]);
                if ($reactivateCat->rowCount() !== 1) {
                    throw new RuntimeException('Category could not be reactivated.');
                }
                if ($reviewNote === '') {
                    $reviewNote = 'Reactivated existing inactive category "' . $existingCat['category_name'] . '".';
                }
                $addCatFlashMsg = 'Request approved: category "' . $existingCat['category_name'] . '" reactivated.';
            } else {
                $insertCat = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
                $insertCat->execute([$catName]);
                if ($insertCat->rowCount() !== 1) {
                    throw new RuntimeException('Category could not be created.');
                }
                $addCatFlashMsg = 'Request approved: category "' . $catName . '" created.';
            }
        } elseif ($type === 'Delete Category') {
            if ($categoryId <= 0) {
                throw new RuntimeException('Invalid category specified.');
            }

            $catStmt = $conn->prepare("SELECT id, category_name, status FROM categories WHERE id = ? FOR UPDATE");
            $catStmt->execute([$categoryId]);
            $cat = $catStmt->fetch();
            if (!$cat || $cat['status'] !== 'Active') {
                $note = !$cat ? 'Category no longer exists.' : 'Category "' . $cat['category_name'] . '" is already inactive.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: ' . (!$cat ? 'category no longer exists.' : 'category "' . $cat['category_name'] . '" is already inactive.');
                redirect('/modules/approvals/index.php');
            }

            $itemCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ?");
            $itemCountStmt->execute([$categoryId]);
            $itemCount = (int)$itemCountStmt->fetchColumn();
            if ($itemCount > 0) {
                $note = 'Cannot delete category "' . $cat['category_name'] . '" because it still contains ' . $itemCount . ' menu item(s).';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: category "' . $cat['category_name'] . '" still contains ' . $itemCount . ' menu item(s).';
                redirect('/modules/approvals/index.php');
            }

            $reqCountStmt = $conn->prepare("SELECT COUNT(*) FROM approval_requests WHERE category_id = ?");
            $reqCountStmt->execute([$categoryId]);
            $reqCount = (int)$reqCountStmt->fetchColumn();

            if ($reqCount > 0) {
                $deactCat = $conn->prepare("UPDATE categories SET status = 'Inactive' WHERE id = ? AND status = 'Active'");
                $deactCat->execute([$categoryId]);
                if ($deactCat->rowCount() !== 1) {
                    throw new RuntimeException('Category could not be deactivated.');
                }

                $closePendingStmt = $conn->prepare("
                    UPDATE approval_requests
                    SET status = 'Rejected',
                        review_notes = 'Closed automatically because the category was deactivated via an approved Delete Category request.',
                        reviewed_by = ?,
                        reviewed_at = NOW()
                    WHERE category_id = ? AND status = 'Pending' AND id <> ?
                ");
                $closePendingStmt->execute([$_SESSION['u_id'], $categoryId, $requestId]);

                if ($reviewNote === '') {
                    $reviewNote = 'Category deactivated instead of permanently deleted to preserve existing approval history and references.';
                }
                $deleteCatFlashMsg = 'Request approved: category "' . $cat['category_name'] . '" has approval history, so it was safely deactivated instead of permanently deleted.';
            } else {
                $delCat = $conn->prepare("DELETE FROM categories WHERE id = ?");
                $delCat->execute([$categoryId]);
                if ($delCat->rowCount() !== 1) {
                    throw new RuntimeException('Category could not be deleted.');
                }
                $deleteCatFlashMsg = 'Request approved: category "' . $cat['category_name'] . '" permanently deleted.';
            }
        } elseif ($type === 'Add Item') {
            $payload = json_decode($request['payload'] ?? '{}', true) ?: [];
            $itemName = trim($payload['item_name'] ?? '');
            $targetCatId = (int)($payload['category_id'] ?? 0);
            $price = (float)($payload['price'] ?? 0);

            if ($itemName === '' || $targetCatId <= 0 || $price < 0) {
                throw new RuntimeException('Invalid menu item details in request.');
            }

            $catCheck = $conn->prepare("SELECT id, category_name, status FROM categories WHERE id = ? FOR UPDATE");
            $catCheck->execute([$targetCatId]);
            $targetCat = $catCheck->fetch();
            if (!$targetCat || $targetCat['status'] !== 'Active') {
                $note = !$targetCat
                    ? 'The selected category no longer exists.'
                    : 'The selected category "' . $targetCat['category_name'] . '" is no longer active.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: ' . (!$targetCat ? 'the selected category no longer exists.' : 'the selected category "' . $targetCat['category_name'] . '" is no longer active.');
                redirect('/modules/approvals/index.php');
            }

            $insertItem = $conn->prepare("
                INSERT INTO menu_items (category_id, item_name, description, price, image, availability, promo_price, promo_start, promo_end)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertItem->execute([
                $targetCatId,
                $itemName,
                trim($payload['description'] ?? ''),
                $price,
                $payload['image'] ?? null,
                $payload['availability'] ?? 'Available',
                !empty($payload['promo_price']) ? (float)$payload['promo_price'] : null,
                !empty($payload['promo_start']) ? $payload['promo_start'] : null,
                !empty($payload['promo_end']) ? $payload['promo_end'] : null,
            ]);
        } elseif ($type === 'Delete Item') {
            if ($menuItemId <= 0) {
                throw new RuntimeException('Invalid menu item specified.');
            }

            $itemStmt = $conn->prepare("SELECT id, item_name, image, stock, availability, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
            $itemStmt->execute([$menuItemId]);
            $item = $itemStmt->fetch();
            if (!$item) {
                $note = 'Menu item no longer exists.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: menu item no longer exists.';
                redirect('/modules/approvals/index.php');
            }

            if ((int) $item['stock'] > 0) {
                $note = 'Delete approvals require the menu item to have 0 stock.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: "' . $item['item_name'] . '" still has ' . (int) $item['stock'] . ' pcs in stock. Use Inventory → Stock Out first.';
                redirect('/modules/approvals/index.php');
            }

            $refStmt = $conn->prepare("
                SELECT
                    (SELECT COUNT(*) FROM order_items WHERE menu_item_id = ?) AS order_items_count,
                    (SELECT COUNT(*) FROM inventory_logs WHERE menu_item_id = ?) AS inventory_logs_count,
                    (SELECT COUNT(*) FROM approval_requests WHERE menu_item_id = ?) AS approval_requests_count,
                    (SELECT COUNT(*) FROM price_change_logs WHERE menu_item_id = ?) AS price_change_logs_count,
                    (SELECT COUNT(*) FROM void_logs WHERE menu_item_id = ?) AS void_logs_count,
                    (SELECT COUNT(*) FROM customer_favorites WHERE menu_item_id = ?) AS customer_favorites_count,
                    (SELECT COUNT(*) FROM menu_ingredients WHERE menu_item_id = ?) AS menu_ingredients_count
            ");
            $refStmt->execute([$menuItemId, $menuItemId, $menuItemId, $menuItemId, $menuItemId, $menuItemId, $menuItemId]);
            $refs = $refStmt->fetch();

            $totalRefs = (int) ($refs['order_items_count'] ?? 0)
                + (int) ($refs['inventory_logs_count'] ?? 0)
                + (int) ($refs['approval_requests_count'] ?? 0)
                + (int) ($refs['price_change_logs_count'] ?? 0)
                + (int) ($refs['void_logs_count'] ?? 0)
                + (int) ($refs['customer_favorites_count'] ?? 0)
                + (int) ($refs['menu_ingredients_count'] ?? 0);

            if ($totalRefs > 0) {
                $archiveStmt = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ? AND stock <= 0");
                $archiveStmt->execute([$menuItemId]);

                $closePendingStmt = $conn->prepare("
                    UPDATE approval_requests
                    SET status = 'Rejected',
                        review_notes = 'Closed automatically because the menu item was archived via an approved Delete Item request.',
                        reviewed_by = ?,
                        reviewed_at = NOW()
                    WHERE menu_item_id = ? AND status = 'Pending' AND id <> ?
                ");
                $closePendingStmt->execute([$_SESSION['u_id'], $menuItemId, $requestId]);

                if ($reviewNote === '') {
                    $reviewNote = 'Item archived instead of permanently deleted to preserve existing history and references.';
                }
                $deleteFlashMsg = 'Request approved: "' . $item['item_name'] . '" has existing history or operational references, so it was safely archived instead of permanently deleted.';
            } else {
                $delStmt = $conn->prepare("DELETE FROM menu_items WHERE id = ? AND stock <= 0");
                $delStmt->execute([$menuItemId]);
                if ($delStmt->rowCount() !== 1) {
                    throw new RuntimeException('Menu item could not be deleted.');
                }
                if (!empty($item['image']) && file_exists(UPLOAD_PATH . $item['image'])) {
                    $deleteImageToUnlink = UPLOAD_PATH . $item['image'];
                }
                $deleteFlashMsg = 'Request approved: "' . $item['item_name'] . '" permanently deleted.';
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
        if (!empty($deleteImageToUnlink) && file_exists($deleteImageToUnlink)) {
            @unlink($deleteImageToUnlink);
        }
        $_SESSION['flash_msg'] = $archiveFlashMsg ?? $unarchiveFlashMsg ?? $deleteFlashMsg ?? $deleteCatFlashMsg ?? $addCatFlashMsg ?? 'Request approved and applied.';
        $_SESSION['flash_type'] = 'success';
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['flash_msg'] = 'Unable to process approval: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'danger';
    }

    redirect('/modules/approvals/index.php');
}

$pending = $conn->query("
    SELECT ar.*, mi.item_name, c.category_name, u.full_name AS requester_name
    FROM approval_requests ar
    LEFT JOIN menu_items mi ON mi.id = ar.menu_item_id
    LEFT JOIN categories c ON c.id = ar.category_id
    LEFT JOIN users u ON u.id = ar.requested_by
    WHERE ar.status = 'Pending'
    ORDER BY ar.created_at ASC, ar.id ASC
")->fetchAll();

$recent = $conn->query("
    SELECT ar.*, mi.item_name, c.category_name, u.full_name AS requester_name, r.full_name AS reviewer_name
    FROM approval_requests ar
    LEFT JOIN menu_items mi ON mi.id = ar.menu_item_id
    LEFT JOIN categories c ON c.id = ar.category_id
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

<?php if ($msg): ?><div class="alert alert-<?= $msgType === 'danger' ? 'danger' : 'success' ?>"><?= e($msg) ?></div><?php endif; ?>

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
            <thead><tr><th>Request</th><th>Target / Item</th><th>Requested By</th><th>Details</th><th>Date / Time</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($pending): ?>
                    <?php foreach ($pending as $row): ?>
                        <?php
                        $type = $row['request_type'];
                        $payloadData = !empty($row['payload']) ? (json_decode($row['payload'], true) ?: []) : [];

                        $badgeClass = 'badge-primary';
                        if (in_array($type, ['Stock In', 'Add Item', 'Add Category', 'Unarchive'], true)) {
                            $badgeClass = 'badge-success';
                        } elseif (in_array($type, ['Stock Out', 'Delete Item', 'Delete Category', 'Archive'], true)) {
                            $badgeClass = 'badge-danger';
                        } elseif ($type === 'Adjustment') {
                            $badgeClass = 'badge-warning';
                        }

                        $targetTitle = '—';
                        $targetSubtitle = '';
                        $thumb = null;

                        if ($type === 'Add Category') {
                            $targetTitle = 'Category: ' . ($payloadData['category_name'] ?? 'New Category');
                        } elseif ($type === 'Delete Category') {
                            $targetTitle = 'Category: ' . ($row['category_name'] ?? ($payloadData['category_name'] ?? 'ID #' . $row['category_id']));
                        } elseif ($type === 'Add Item') {
                            $targetTitle = ($payloadData['item_name'] ?? 'New Item') . ' (' . money($payloadData['price'] ?? 0) . ')';
                            if (!empty($payloadData['description'])) {
                                $targetSubtitle = $payloadData['description'];
                            }
                            if (!empty($payloadData['image'])) {
                                $thumb = BASE_URL . '/uploads/menu/' . $payloadData['image'];
                            }
                        } else {
                            $targetTitle = $row['item_name'] ?? 'Unknown Menu Item';
                        }

                        $detail = $type;
                        if (in_array($type, ['Stock In', 'Stock Out', 'Adjustment'], true)) {
                            $detail .= $type === 'Adjustment' ? ' → ' . (int) $row['quantity'] . ' pcs' : ' • ' . (int) $row['quantity'] . ' pcs';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong>#<?= e($row['id']) ?></strong>
                                <div class="approval-summary"><span class="badge <?= $badgeClass ?>"><?= e($type) ?></span></div>
                            </td>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <?php if ($thumb): ?>
                                        <img src="<?= e($thumb) ?>" style="width:40px; height:40px; object-fit:cover; border-radius:8px; border:1px solid var(--border); flex:0 0 40px;" alt="">
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= e($targetTitle) ?></strong>
                                        <?php if ($targetSubtitle): ?>
                                            <div class="muted" style="font-size:0.8rem; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e($targetSubtitle) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($row['requester_name'] ?? 'Unknown User') ?></td>
                            <td><?= e($row['remarks'] ?? '') ?></td>
                            <td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                            <td>
                                <div class="approval-actions">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= e($row['id']) ?>">
                                        <button class="btn btn-primary" type="submit" data-confirm="Approve and apply this request?"><i class="bi bi-check2"></i>Approve</button>
                                    </form>
                                    <form method="post">
                                        <?= csrf_field() ?>
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
            <thead><tr><th>Request</th><th>Target / Item</th><th>Requested By</th><th>Status</th><th>Reviewed By</th><th>Date / Time</th></tr></thead>
            <tbody>
                <?php if ($recent): ?>
                    <?php foreach ($recent as $row): ?>
                        <?php
                        $type = $row['request_type'];
                        $payloadData = !empty($row['payload']) ? (json_decode($row['payload'], true) ?: []) : [];
                        $targetTitle = '—';
                        if ($type === 'Add Category') {
                            $targetTitle = 'Category: ' . ($payloadData['category_name'] ?? 'New Category');
                        } elseif ($type === 'Delete Category') {
                            $targetTitle = 'Category: ' . ($row['category_name'] ?? ($payloadData['category_name'] ?? 'ID #' . $row['category_id']));
                        } elseif ($type === 'Add Item') {
                            $targetTitle = ($payloadData['item_name'] ?? 'New Item') . ' (' . money($payloadData['price'] ?? 0) . ')';
                        } else {
                            $targetTitle = $row['item_name'] ?? 'Unknown Menu Item';
                        }
                        ?>
                        <tr>
                            <td><strong>#<?= e($row['id']) ?></strong><div class="approval-summary muted"><?= e($row['request_type']) ?></div></td>
                            <td><?= e($targetTitle) ?></td>
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
