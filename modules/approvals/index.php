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
                $note = 'Cannot deactivate category "' . $cat['category_name'] . '" because it still contains ' . $itemCount . ' menu item(s).';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: category "' . $cat['category_name'] . '" still contains ' . $itemCount . ' menu item(s).';
                redirect('/modules/approvals/index.php');
            }

            $deactCat = $conn->prepare("UPDATE categories SET status = 'Inactive' WHERE id = ? AND status = 'Active'");
            $deactCat->execute([$categoryId]);
            if ($deactCat->rowCount() !== 1) {
                throw new RuntimeException('Category could not be deactivated.');
            }

            $closePendingStmt = $conn->prepare("
                UPDATE approval_requests
                SET status = 'Rejected',
                    review_notes = 'Closed automatically because the category was deactivated via an approved Deactivate Category request.',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE category_id = ? AND status = 'Pending' AND id <> ?
            ");
            $closePendingStmt->execute([$_SESSION['u_id'], $categoryId, $requestId]);

            if ($reviewNote === '') {
                $reviewNote = 'Category deactivated (archived) to preserve existing history and references.';
            }
            $deleteCatFlashMsg = 'Request approved: category "' . $cat['category_name'] . '" deactivated safely with history preserved.';
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
                $note = 'Archive approvals via this request require the menu item to have 0 stock.';
                $reject = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $reject->execute([$note, $_SESSION['u_id'], $requestId]);
                $conn->commit();
                $_SESSION['flash_msg'] = 'Request rejected: "' . $item['item_name'] . '" still has ' . (int) $item['stock'] . ' pcs in stock. Use Inventory → Stock Out first.';
                redirect('/modules/approvals/index.php');
            }

            $archiveStmt = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ? AND stock <= 0");
            $archiveStmt->execute([$menuItemId]);

            $closePendingStmt = $conn->prepare("
                UPDATE approval_requests
                SET status = 'Rejected',
                    review_notes = 'Closed automatically because the menu item was archived via an approved Archive Item request.',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE menu_item_id = ? AND status = 'Pending' AND id <> ?
            ");
            $closePendingStmt->execute([$_SESSION['u_id'], $menuItemId, $requestId]);

            if ($reviewNote === '') {
                $reviewNote = 'Item archived (permanent deletion disabled) to preserve existing history and references.';
            }
            $deleteFlashMsg = 'Request approved: "' . $item['item_name'] . '" safely archived with records preserved.';
        } else {
            throw new RuntimeException('Unsupported approval request type.');
        }

        $updateRequest = $conn->prepare("UPDATE approval_requests SET status = 'Approved', review_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
        $updateRequest->execute([$reviewNote !== '' ? $reviewNote : 'Approved by Admin.', $_SESSION['u_id'], $requestId]);
        if ($updateRequest->rowCount() !== 1) {
            throw new RuntimeException('Approval request could not be completed.');
        }

        $conn->commit();
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
    SELECT ar.*, mi.item_name, mi.stock AS current_stock, mi.price AS current_price, mi.availability AS current_availability,
           c.category_name, u.full_name AS requester_name
    FROM approval_requests ar
    LEFT JOIN menu_items mi ON mi.id = ar.menu_item_id
    LEFT JOIN categories c ON c.id = ar.category_id
    LEFT JOIN users u ON u.id = ar.requested_by
    WHERE ar.status = 'Pending'
    ORDER BY ar.created_at ASC, ar.id ASC
")->fetchAll();
$highlightId = (int)($_GET['highlight'] ?? 0);
$pendingOrderReqCount = (int)$conn->query("SELECT COUNT(*) FROM transaction_requests WHERE status = 'Pending'")->fetchColumn();

$statusCountsStmt = $conn->query("SELECT status, COUNT(*) AS cnt FROM approval_requests WHERE status IN ('Approved','Rejected') GROUP BY status");
$approvedCount = 0;
$rejectedCount = 0;
foreach ($statusCountsStmt->fetchAll() as $scRow) {
    if ($scRow['status'] === 'Approved') {
        $approvedCount = (int)$scRow['cnt'];
    } elseif ($scRow['status'] === 'Rejected') {
        $rejectedCount = (int)$scRow['cnt'];
    }
}

$filter = $_GET['status'] ?? 'all';
if (!in_array($filter, ['all', 'pending', 'approved', 'rejected'], true)) $filter = 'all';
$historyStatus = $filter === 'approved' ? 'Approved' : ($filter === 'rejected' ? 'Rejected' : null);
$historyPerPage = 10;
$totalHistoryRows = $historyStatus === 'Approved' ? $approvedCount : ($historyStatus === 'Rejected' ? $rejectedCount : $approvedCount + $rejectedCount);
$totalHistoryPages = max(1, (int)ceil($totalHistoryRows / $historyPerPage));
$historyPage = max(1, min((int)($_GET['history_page'] ?? 1), $totalHistoryPages));
$historyOffset = ($historyPage - 1) * $historyPerPage;

$recentStmt = $conn->prepare("
    SELECT ar.*, mi.item_name, c.category_name, u.full_name AS requester_name, r.full_name AS reviewer_name
    FROM approval_requests ar
    LEFT JOIN menu_items mi ON mi.id = ar.menu_item_id
    LEFT JOIN categories c ON c.id = ar.category_id
    LEFT JOIN users u ON u.id = ar.requested_by
    LEFT JOIN users r ON r.id = ar.reviewed_by
    WHERE ar.status IN ('Approved','Rejected')
      AND (:status_filter IS NULL OR ar.status = :selected_status)
    ORDER BY ar.reviewed_at DESC, ar.id DESC
    LIMIT :limit OFFSET :offset
");
$recentStmt->bindValue(':status_filter', $historyStatus, $historyStatus === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$recentStmt->bindValue(':selected_status', $historyStatus, $historyStatus === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$recentStmt->bindValue(':limit', $historyPerPage, PDO::PARAM_INT);
$recentStmt->bindValue(':offset', $historyOffset, PDO::PARAM_INT);
$recentStmt->execute();
$recent = $recentStmt->fetchAll();

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
.approval-context-line { display:flex; flex-wrap:wrap; align-items:center; gap:6px; font-size:13px; color:#242424; }
.approval-context-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; background:#fff6d6; border:1px solid #ecd585; color:#694c00; font-size:12px; font-weight:700; }
.approval-context-pill.is-danger { background:#fff1f0; border-color:#f3b8b3; color:#b42318; }
.approval-context-pill.is-success { background:#ecfdf3; border-color:#abefc6; color:#067647; }
.approval-remarks-text { margin-top:5px; font-size:12.5px; color:#625c4f; line-height:1.4; max-width:340px; overflow-wrap:anywhere; }
tr.approval-row-highlight td { background:#fffbeb!important; box-shadow:inset 0 2px 0 #f2c12e, inset 0 -2px 0 #f2c12e; }
.approval-heading { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:22px; }
.approval-heading h2 { font-size:26px; margin:0 0 6px; color:#242424; }
.approval-heading h2 i { color:#b58100; margin-right:8px; }
.approval-heading p { margin:0; font-size:14px; }
.approval-reset { color:#694c00; background:#fff4c5; padding:10px 15px; border-radius:9px; text-decoration:none; font-weight:600; white-space:nowrap; }
.approvals-workspace .approval-stat { text-decoration:none; color:#242424; border:1px solid #e5e5e5; box-shadow:0 3px 12px #00000004; transition:background .15s,border-color .15s; }
.approval-stat:hover { background:#fffbeb; border-color:#e8ba27; }
.approvals-workspace .approval-stat.is-selected { background:#fff3bd; border-color:#e3b325; box-shadow:inset 0 0 0 1px #e3b325; }
.approval-stat .stat-label { font-weight:600; font-size:14px; }
.approval-stat small { display:block; color:#69645a; font-size:12px; margin-top:6px; }
.approvals-workspace .approval-stat > i { background:#fff3c4; color:#775400; width:46px; height:46px; border-radius:12px; display:grid; place-items:center; font-size:23px; }
.approval-stat.is-selected > i { background:#f7c62f; color:#242424; }
.approvals-workspace > .card { border:1px solid #e7e5df; box-shadow:0 4px 16px #00000003; border-radius:16px; }
.approvals-workspace .page-head h2 { font-size:21px; margin:0 0 6px; }
.approvals-workspace .page-head p { margin:0; font-size:14px; }
.approvals-workspace th { background:#faf8f0; color:#655b3d; font-size:12px; font-weight:700; padding:14px 16px; }
.approvals-workspace td { padding:17px 16px; font-size:14px; vertical-align:middle; }
.approvals-workspace td:nth-child(2) { min-width:180px; }
.approval-actions { flex-wrap:nowrap; }
.approval-actions .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:40px; padding:9px 14px; border-radius:9px; font-size:13px; font-weight:700; border:1px solid transparent; white-space:nowrap; }
.approval-actions .approval-approve { background:#f7c62f; color:#242424; border-color:#e8b923; }
.approval-actions .approval-approve:hover { background:#eab91f; }
.approval-actions .approval-reject { background:#fff5f4; color:#b42318; border-color:#efc8c4; }
.approval-actions .approval-reject:hover { background:#fee4e2; border-color:#db8d86; }
.approvals-workspace a:focus-visible,.approval-actions button:focus-visible { outline:3px solid #a87900; outline-offset:3px; }
@media(max-width:760px) { .approval-heading { align-items:flex-start; flex-direction:column; } .approval-stats { grid-template-columns:1fr; } .approvals-workspace .page-head { flex-wrap:wrap; gap:14px; } }
.kk-approval-reject { width:min(480px,calc(100vw - 32px)); border:1px solid #e8e4d9; border-radius:18px; overflow:hidden; }
.kk-approval-reject .kk-dialog-accent { display:none; }
.kk-approval-reject .kk-dialog-body { padding:28px; }
.kk-approval-reject .kk-dialog-icon.danger { background:#fff1b8; color:#825d00; border-radius:12px; }
.kk-approval-reject .kk-dialog-title { color:#242424; font-size:21px; }
.kk-approval-reject .kk-dialog-details { background:#faf8f0; border:1px solid #ebe4d2; border-radius:10px; padding:14px; margin:18px 0; }
.kk-approval-reject .kk-dialog-detail-row { display:flex; justify-content:space-between; gap:18px; padding:5px 0; font-size:13px; }
.kk-approval-reject .kk-dialog-detail-row span { color:#746c5c; flex-shrink:0; }
.kk-approval-reject .kk-dialog-detail-row strong { text-align:right; overflow-wrap:anywhere; min-width:0; color:#343027; }
.kk-approval-reject .kk-dialog-message { color:#686253; font-size:14px; line-height:1.7; margin-bottom:0; }
.kk-approval-reject .kk-dialog-actions { background:#faf9f5; padding:18px 24px; gap:10px; border-top:1px solid #eee9dd; }
.kk-approval-reject .kk-dialog-actions .btn { min-height:42px; padding:10px 18px; border-radius:9px; font-weight:650; }
.kk-approval-reject .btn-danger { background:#fff0ee; color:#b42318; border:1px solid #eab9b3; }
.kk-approval-reject .btn-danger:hover { background:#fee0dc; }
.kk-approval-reject .btn-secondary { background:#fff; color:#514d43; border:1px solid #ded9ce; }
</style>

<?php if ($msg): ?><div class="alert alert-<?= $msgType === 'danger' ? 'danger' : 'success' ?>"><?= e($msg) ?></div><?php endif; ?>
<?php if ($pendingOrderReqCount > 0): ?>
<div class="alert alert-warning" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
    <span><i class="bi bi-exclamation-triangle-fill"></i> There <?= $pendingOrderReqCount === 1 ? 'is <strong>1</strong> pending order cancellation / refund request' : "are <strong>{$pendingOrderReqCount}</strong> pending order cancellation / refund requests" ?> awaiting review.</span>
    <a class="btn btn-sm" href="<?= e(BASE_URL . '/modules/transactions/index.php') ?>" style="background:#f7c62f; color:#242424; font-weight:700; text-decoration:none; padding:6px 12px; border-radius:8px;">Open Order Requests →</a>
</div>
<?php endif; ?>

<div class="approvals-workspace">
<header class="approval-heading"><div><p class="muted">Review menu and inventory changes before they take effect at Kenji’s Kitchen.</p></div><a class="approval-reset" href="?status=all" <?= $filter === 'all' ? 'aria-current="page"' : '' ?>>View all requests</a></header>
<section class="grid grid-3 approval-stats" aria-label="Filter requests by status">
<?php foreach (['pending' => ['Pending Requests', count($pending), 'hourglass-split', 'Waiting for your review'], 'approved' => ['Approved', $approvedCount, 'check-circle', 'Changes approved by admin'], 'rejected' => ['Rejected', $rejectedCount, 'x-circle', 'Requests declined by admin']] as $key => $stat): ?>
<a class="card stat approval-stat <?= $filter === $key ? 'is-selected' : '' ?>" href="?status=<?= $key ?>" <?= $filter === $key ? 'aria-current="page"' : '' ?>>
<div><span class="stat-label"><?= e($stat[0]) ?></span><strong><?= number_format($stat[1]) ?></strong><small><?= e($stat[3]) ?></small></div><i class="bi bi-<?= $stat[2] ?>" aria-hidden="true"></i>
</a>
<?php endforeach; ?>
</section>

<?php if ($filter === 'all' || $filter === 'pending'): ?>
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
                        $typeLabel = $type === 'Delete Category' ? 'Deactivate Category' : ($type === 'Delete Item' ? 'Archive Item' : $type);
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
                                $thumb = menu_image_url($payloadData['image']);
                            }
                        } else {
                            $targetTitle = $row['item_name'] ?? 'Unknown Menu Item';
                        }

                        $currStock = $row['current_stock'] !== null ? (int) $row['current_stock'] : null;
                        $reqQty = $row['quantity'] !== null ? (int) $row['quantity'] : null;
                        $contextHtml = '';

                        if ($type === 'Adjustment' && $reqQty !== null) {
                            if ($currStock !== null) {
                                $delta = $reqQty - $currStock;
                                $deltaStr = $delta > 0 ? ('+' . $delta) : (string) $delta;
                                $pillClass = $reqQty <= 0 ? 'is-danger' : ($delta >= 0 ? 'is-success' : '');
                                $contextHtml = '<div class="approval-context-line">'
                                    . '<span class="approval-context-pill ' . $pillClass . '">'
                                    . 'Stock: ' . $currStock . ' pcs &rarr; ' . $reqQty . ' pcs'
                                    . ($delta !== 0 ? ' (' . $deltaStr . ' pcs)' : ' (No change)')
                                    . '</span></div>';
                            } else {
                                $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill">New Stock: ' . $reqQty . ' pcs</span></div>';
                            }
                        } elseif ($type === 'Stock In' && $reqQty !== null) {
                            $afterStock = $currStock !== null ? ($currStock + $reqQty) : null;
                            $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill is-success">'
                                . 'Add +' . $reqQty . ' pcs'
                                . ($afterStock !== null ? (' (' . $currStock . ' &rarr; ' . $afterStock . ' pcs)') : '')
                                . '</span></div>';
                        } elseif ($type === 'Stock Out' && $reqQty !== null) {
                            $afterStock = $currStock !== null ? ($currStock - $reqQty) : null;
                            $insufficient = $currStock !== null && $reqQty > $currStock;
                            $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill ' . ($insufficient ? 'is-danger' : '') . '">'
                                . ($insufficient
                                    ? ('Insufficient stock: ' . $currStock . ' pcs available, requested -' . $reqQty . ' pcs')
                                    : ('Remove -' . $reqQty . ' pcs' . ($afterStock !== null ? (' (' . $currStock . ' &rarr; ' . $afterStock . ' pcs)') : '')))
                                . '</span></div>';
                        } elseif (in_array($type, ['Archive', 'Delete Item'], true)) {
                            $stockInfo = $currStock !== null ? ('Current stock: ' . $currStock . ' pcs &middot; ') : '';
                            $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill is-danger">'
                                . $stockInfo . 'Hides item from POS &amp; Online Menu</span></div>';
                        } elseif ($type === 'Unarchive') {
                            $stockInfo = $currStock !== null ? ('Current stock: ' . $currStock . ' pcs &rarr; ' . ($currStock > 0 ? 'Available' : 'Unavailable')) : 'Restores item to active menu';
                            $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill is-success">' . $stockInfo . '</span></div>';
                        } elseif ($type === 'Add Item') {
                            $initStock = isset($payloadData['stock']) ? (int) $payloadData['stock'] : 0;
                            $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill is-success">'
                                . 'New Menu Item &middot; Initial stock: ' . $initStock . ' pcs</span></div>';
                        } elseif ($type === 'Add Category') {
                            $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill is-success">Creates new active menu category</span></div>';
                        } elseif ($type === 'Delete Category') {
                            $contextHtml = '<div class="approval-context-line"><span class="approval-context-pill is-danger">Deactivates category from menu</span></div>';
                        }

                        $remarksText = trim((string) ($row['remarks'] ?? ''));
                        $isHighlighted = $highlightId > 0 && $highlightId === (int) $row['id'];
                        ?>
                        <tr id="approval-req-<?= (int) $row['id'] ?>" class="<?= $isHighlighted ? 'approval-row-highlight' : '' ?>">
                            <td>
                                <strong>#<?= e($row['id']) ?></strong>
                                <div class="approval-summary"><span class="badge <?= $badgeClass ?>"><?= e($typeLabel) ?></span></div>
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
                            <td>
                                <?= $contextHtml ?>
                                <?php if ($remarksText !== ''): ?>
                                    <div class="approval-remarks-text"><?= e($remarksText) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                            <td>
                                <div class="approval-actions">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= e($row['id']) ?>">
                                        <button class="btn approval-approve" type="submit" data-confirm="Approve and apply this request?"><i class="bi bi-check-circle"></i> Approve</button>
                                    </form>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="request_id" value="<?= e($row['id']) ?>">
                                        <button class="btn approval-reject" type="submit" data-confirm-kind="approval-reject" data-request-id="<?= e($row['id']) ?>" data-request-type="<?= e($type) ?>" data-target-name="<?= e($targetTitle) ?>" data-confirm="Reject this request?"><i class="bi bi-x-circle"></i> Reject</button>
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

<?php endif; ?>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Approval History<?= $historyStatus ? ' · ' . e($historyStatus) : '' ?></h2>
            <p class="muted"><?= $historyStatus ? e($historyStatus) . ' requests' : 'Approved and rejected requests' ?> · <?= number_format($totalHistoryRows) ?> records · 10 per page</p>
        </div>
        <?= render_pagination_controls($historyPage, $totalHistoryPages, static fn(int $p) => '?status=' . urlencode($filter) . '&history_page=' . $p) ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Request</th><th>Target / Item</th><th>Requested By</th><th>Status</th><th>Reviewed By</th><th>Date / Time</th></tr></thead>
            <tbody>
                <?php if ($recent): ?>
                    <?php foreach ($recent as $row): ?>
                        <?php
                        $type = $row['request_type'];
                        $typeLabel = $type === 'Delete Category' ? 'Deactivate Category' : ($type === 'Delete Item' ? 'Archive Item' : $type);
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
                            <td><strong>#<?= e($row['id']) ?></strong><div class="approval-summary muted"><?= e($typeLabel) ?></div></td>
                            <td><?= e($targetTitle) ?></td>
                            <td><?= e($row['requester_name'] ?? 'Unknown User') ?></td>
                            <td><span class="badge <?= $row['status'] === 'Approved' ? 'badge-success' : 'badge-danger' ?>"><?= e($row['status']) ?></span><?php if ($row['status'] === 'Rejected' && !empty($row['review_notes'])): ?><div class="approval-summary muted" style="max-width:260px;font-size:12px;overflow-wrap:anywhere;"><?= e($row['review_notes']) ?></div><?php endif; ?></td>
                            <td><?= e($row['reviewer_name'] ?? 'Admin') ?></td>
                            <td><?= $row['reviewed_at'] ? e(date('M d, Y h:i A', strtotime($row['reviewed_at']))) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:28px;">No <?= $historyStatus ? strtolower($historyStatus) : 'reviewed' ?> requests yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

</div>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
