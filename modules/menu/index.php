<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role(['admin', 'inventory']);

// Automatically apply any scheduled prices that have matured for normal GET navigation
if (file_exists(ROOT_PATH . '/api/price_helpers.php')) {
    require_once ROOT_PATH . '/api/price_helpers.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        processPendingPriceUpdates($conn);
    }
}

$isAdmin = can_access('admin');
$isInventory = can_access('inventory');

$page = 'menu';
$title = 'Menu Management';
$heading = 'Menu Management';
$bodyClass = 'menu-management-page';
$msg = $_SESSION['flash_msg'] ?? '';
$msgType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
$menuPerPage = 10;
$menuPage = isset($_GET['menu_page']) ? (int) $_GET['menu_page'] : 1;
$menuSearch = trim($_GET['menu_search'] ?? '');

if ($menuPage < 1) {
    $menuPage = 1;
}

$menuRedirect = $showArchived ? 'index.php?archived=1' : 'index.php';
$postAction = ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null)) ? $_POST['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject forged or stale submissions before any database or filesystem change.
    if (!verify_csrf_token()) {
        $_SESSION['flash_msg'] = 'Invalid or expired security token. Please reload the page and try again.';
        header('Location: ' . $menuRedirect);
        exit;
    }

    // Process due scheduled prices only after the POST submission passes CSRF validation.
    if (function_exists('processPendingPriceUpdates')) {
        processPendingPriceUpdates($conn);
    }

    $action = $postAction;

    if ($action === 'category') {
        $name = trim($_POST['category_name'] ?? '');
        if ($name === '') {
            $_SESSION['flash_msg'] = 'Category name cannot be empty.';
            header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
            exit;
        }

        if ($isAdmin) {
            try {
                $conn->beginTransaction();

                $chk = $conn->prepare("SELECT id, category_name, status FROM categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1 FOR UPDATE");
                $chk->execute([$name]);
                $existingCat = $chk->fetch();

                if ($existingCat && $existingCat['status'] === 'Active') {
                    $conn->rollBack();
                    $_SESSION['flash_msg'] = 'A category named "' . $name . '" already exists.';
                } elseif ($existingCat && $existingCat['status'] === 'Inactive') {
                    $reactivateStmt = $conn->prepare("UPDATE categories SET status = 'Active' WHERE id = ? AND status = 'Inactive'");
                    $reactivateStmt->execute([(int) $existingCat['id']]);
                    if ($reactivateStmt->rowCount() !== 1) {
                        throw new RuntimeException('Category could not be reactivated.');
                    }
                    $conn->commit();
                    $_SESSION['flash_msg'] = 'Category "' . $existingCat['category_name'] . '" reactivated successfully.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
                    $stmt->execute([$name]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException('Category could not be created.');
                    }
                    $conn->commit();
                    $_SESSION['flash_msg'] = 'Category "' . $name . '" added successfully.';
                }
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $_SESSION['flash_msg'] = 'Unable to save category: ' . $e->getMessage();
            }
        } elseif ($isInventory) {
            $chk = $conn->prepare("SELECT id, category_name, status FROM categories WHERE LOWER(category_name) = LOWER(?) AND status = 'Active' LIMIT 1");
            $chk->execute([$name]);
            if ($chk->fetch()) {
                $_SESSION['flash_msg'] = 'A category named "' . $name . '" already exists.';
                header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
                exit;
            }

            $pendingChk = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Add Category' AND status = 'Pending' AND LOWER(payload) LIKE LOWER(?) LIMIT 1");
            $pendingChk->execute(['%"' . $name . '"%']);
            if ($pendingChk->fetch()) {
                $_SESSION['flash_msg'] = 'A request to add category "' . $name . '" is already pending Admin approval.';
            } else {
                $stmt = $conn->prepare("INSERT INTO approval_requests (request_type, remarks, payload, requested_by) VALUES ('Add Category', ?, ?, ?)");
                $stmt->execute([
                    'New category request: ' . $name,
                    json_encode(['category_name' => $name]),
                    $_SESSION['u_id']
                ]);
                $_SESSION['flash_msg'] = 'Category creation request for "' . $name . '" submitted for Admin approval.';
            }
        } else {
            $_SESSION['flash_msg'] = 'Unauthorized action.';
        }

        header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
        exit;
    }

    if ($action === 'item') {
        $itemName = trim($_POST['item_name'] ?? '');
        $catId = (int)($_POST['category_id'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);

        if ($itemName === '' || $catId <= 0 || $price < 0) {
            $_SESSION['flash_msg'] = 'Please provide valid item details (name, category, and price).';
            header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
            exit;
        }

        $catChk = $conn->prepare("SELECT id, category_name, status FROM categories WHERE id = ? AND status = 'Active' LIMIT 1");
        $catChk->execute([$catId]);
        $category = $catChk->fetch();
        if (!$category) {
            $_SESSION['flash_msg'] = 'Please select a valid active category.';
            header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
            exit;
        }

        $dupChk = $conn->prepare("SELECT id FROM menu_items WHERE category_id = ? AND LOWER(TRIM(item_name)) = LOWER(TRIM(?)) AND is_archived = 0 LIMIT 1");
        $dupChk->execute([$catId, $itemName]);
        if ($dupChk->fetchColumn()) {
            $_SESSION['flash_msg'] = 'An active menu item named "' . $itemName . '" already exists in ' . $category['category_name'] . '. Edit the existing item instead.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
            exit;
        }

        $image = upload_menu_image($_FILES['image'] ?? [], $category['category_name']);
        if (!empty($_FILES['image']['name']) && $image === null) {
            $_SESSION['flash_msg'] = 'Failed to upload photo. Use a valid JPG, PNG, or WebP under 10 MB.';
            header('Location: ' . $menuRedirect);
            exit;
        }

        if ($isAdmin) {
            $stmt = $conn->prepare("
                INSERT INTO menu_items (category_id, item_name, description, price, image, availability, promo_price, promo_start, promo_end)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $catId,
                $itemName,
                trim($_POST['description'] ?? ''),
                $price,
                $image,
                $_POST['availability'] ?? 'Available',
                !empty($_POST['promo_price']) ? (float)$_POST['promo_price'] : null,
                !empty($_POST['promo_start']) ? $_POST['promo_start'] : null,
                !empty($_POST['promo_end']) ? $_POST['promo_end'] : null
            ]);
            $_SESSION['flash_msg'] = 'Menu item "' . $itemName . '" added successfully.';
        } elseif ($isInventory) {
            $payload = json_encode([
                'category_id' => $catId,
                'item_name' => $itemName,
                'description' => trim($_POST['description'] ?? ''),
                'price' => $price,
                'image' => $image,
                'availability' => $_POST['availability'] ?? 'Available',
                'promo_price' => !empty($_POST['promo_price']) ? (float)$_POST['promo_price'] : null,
                'promo_start' => !empty($_POST['promo_start']) ? $_POST['promo_start'] : null,
                'promo_end' => !empty($_POST['promo_end']) ? $_POST['promo_end'] : null
            ]);
            $stmt = $conn->prepare("
                INSERT INTO approval_requests (request_type, category_id, remarks, payload, requested_by)
                VALUES ('Add Item', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $catId,
                'New item request: ' . $itemName . ' (₱' . number_format($price, 2) . ')',
                $payload,
                $_SESSION['u_id']
            ]);
            $_SESSION['flash_msg'] = 'Menu item request for "' . $itemName . '" submitted for Admin approval.';
        } else {
            $_SESSION['flash_msg'] = 'Unauthorized action.';
        }

        header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
        exit;
    }

    if ($action === 'edit_item') {
        if (!$isAdmin) {
            $_SESSION['flash_msg'] = 'Only Admin can edit menu items.';
            header('Location: ' . $menuRedirect);
            exit;
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        $itemName = trim($_POST['item_name'] ?? '');
        $catId = (int)($_POST['category_id'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $availability = $_POST['availability'] ?? 'Available';
        $promoPrice = !empty($_POST['promo_price']) ? (float)$_POST['promo_price'] : null;
        $promoStart = !empty($_POST['promo_start']) ? $_POST['promo_start'] : null;
        $promoEnd = !empty($_POST['promo_end']) ? $_POST['promo_end'] : null;

        if ($itemId <= 0 || $itemName === '' || $catId <= 0 || $price < 0) {
            $_SESSION['flash_msg'] = 'Please provide valid item details (name, category, and price).';
            header('Location: ' . $menuRedirect);
            exit;
        }

        $itemStmt = $conn->prepare('SELECT m.*, c.category_name FROM menu_items m LEFT JOIN categories c ON c.id = m.category_id WHERE m.id = ? LIMIT 1');
        $itemStmt->execute([$itemId]);
        $existing = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $_SESSION['flash_msg'] = 'Menu item not found.';
            header('Location: ' . $menuRedirect);
            exit;
        }

        $catStmt = $conn->prepare("SELECT category_name FROM categories WHERE id = ? LIMIT 1");
        $catStmt->execute([$catId]);
        $targetCatName = $catStmt->fetchColumn() ?: ($existing['category_name'] ?? 'general');

        $image = $existing['image'];
        if (!empty($_FILES['image']['name'])) {
            $uploaded = upload_menu_image($_FILES['image'], $targetCatName);
            if ($uploaded) {
                if (!empty($existing['image']) && $existing['image'] !== $uploaded) {
                    $oldBasename = basename($existing['image']);
                    if (str_starts_with($oldBasename, 'menu_')) {
                        $oldFullPath = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $existing['image']);
                        if (file_exists($oldFullPath)) {
                            @unlink($oldFullPath);
                        }
                    }
                }
                $image = $uploaded;
            } else {
                $_SESSION['flash_msg'] = 'Failed to upload photo. Please use a valid JPG, PNG, or WebP under 10 MB.';
                header('Location: ' . $menuRedirect);
                exit;
            }
        } elseif (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if (!empty($existing['image'])) {
                $oldBasename = basename($existing['image']);
                if (str_starts_with($oldBasename, 'menu_')) {
                    $oldFullPath = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $existing['image']);
                    if (file_exists($oldFullPath)) {
                        @unlink($oldFullPath);
                    }
                }
            }
            $image = null;
        }

        $priceChanged = abs((float)$existing['price'] - $price) > 0.009;
        $updateStmt = $conn->prepare("
            UPDATE menu_items 
            SET item_name = ?, category_id = ?, description = ?, price = ?, image = ?, availability = ?, promo_price = ?, promo_start = ?, promo_end = ?,
                pending_price = CASE WHEN ? = 1 THEN NULL ELSE pending_price END,
                pending_price_effective = CASE WHEN ? = 1 THEN NULL ELSE pending_price_effective END
            WHERE id = ?
        ");
        $updateStmt->execute([
            $itemName,
            $catId,
            $description,
            $price,
            $image,
            $availability,
            $promoPrice,
            $promoStart,
            $promoEnd,
            $priceChanged ? 1 : 0,
            $priceChanged ? 1 : 0,
            $itemId
        ]);

        $_SESSION['flash_msg'] = 'Menu item "' . $itemName . '" updated successfully.';
        header('Location: ' . $menuRedirect);
        exit;
    }

    if ($action === 'update_image') {
        if (!$isAdmin) {
            $_SESSION['flash_msg'] = 'Only Admin can update menu images.';
            header('Location: index.php');
            exit;
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0 && !empty($_FILES['image']['name'])) {
            $itemStmt = $conn->prepare('SELECT m.image, c.category_name FROM menu_items m LEFT JOIN categories c ON c.id = m.category_id WHERE m.id = ? LIMIT 1');
            $itemStmt->execute([$itemId]);
            $itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC);
            $itemCategory = $itemRow['category_name'] ?? 'general';
            $oldImage = $itemRow['image'] ?? null;

            $image = upload_menu_image($_FILES['image'], $itemCategory);
            if ($image) {
                $stmt = $conn->prepare("UPDATE menu_items SET image = ? WHERE id = ?");
                $stmt->execute([$image, $itemId]);

                // Delete the old custom uploaded photo if it starts with 'menu_' so duplicate files don't accumulate
                if (!empty($oldImage) && $oldImage !== $image) {
                    $oldBasename = basename($oldImage);
                    if (str_starts_with($oldBasename, 'menu_')) {
                        $oldFullPath = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldImage);
                        if (file_exists($oldFullPath)) {
                            @unlink($oldFullPath);
                        }
                    }
                }

                $_SESSION['flash_msg'] = 'Photo updated successfully.';
            } else {
                $_SESSION['flash_msg'] = 'Failed to upload photo. Please use a valid JPG, PNG, or WebP under 10 MB.';
            }
        }
        header('Location: index.php');
        exit;
    }
}

if ($postAction === 'delete_category') {
    $id = (int) ($_POST['category_id'] ?? 0);

    if ($isAdmin) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT id, category_name, status FROM categories WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $cat = $stmt->fetch();

            if (!$cat || $cat['status'] !== 'Active') {
                $conn->rollBack();
                $_SESSION['flash_msg'] = !$cat ? 'Category not found.' : 'Category "' . $cat['category_name'] . '" is already inactive.';
            } else {
                $itemCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ?");
                $itemCountStmt->execute([$id]);
                $itemCount = (int)$itemCountStmt->fetchColumn();

                if ($itemCount > 0) {
                    $conn->rollBack();
                    $_SESSION['flash_msg'] = 'Cannot deactivate category "' . $cat['category_name'] . '" because it contains ' . $itemCount . ' menu item(s). Reassign or archive them first.';
                } else {
                    $deactStmt = $conn->prepare("UPDATE categories SET status = 'Inactive' WHERE id = ? AND status = 'Active'");
                    $deactStmt->execute([$id]);
                    if ($deactStmt->rowCount() !== 1) {
                        throw new RuntimeException('Category could not be deactivated.');
                    }

                    $closePendingStmt = $conn->prepare("
                        UPDATE approval_requests
                        SET status = 'Rejected',
                            review_notes = 'Closed automatically because the category was deactivated by Admin.',
                            reviewed_by = ?,
                            reviewed_at = NOW()
                        WHERE category_id = ? AND status = 'Pending'
                    ");
                    $closePendingStmt->execute([$_SESSION['u_id'], $id]);

                    $conn->commit();
                    $_SESSION['flash_msg'] = 'Category "' . $cat['category_name'] . '" archived (deactivated) safely with its history preserved.';
                }
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['flash_msg'] = 'Unable to deactivate category: ' . $e->getMessage();
        }
    } elseif ($isInventory) {
        $stmt = $conn->prepare("SELECT id, category_name, status FROM categories WHERE id = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$id]);
        $cat = $stmt->fetch();

        if (!$cat) {
            $_SESSION['flash_msg'] = 'Category not found.';
        } else {
            $itemCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ?");
            $itemCountStmt->execute([$id]);
            $itemCount = (int)$itemCountStmt->fetchColumn();

            if ($itemCount > 0) {
                $_SESSION['flash_msg'] = 'Cannot deactivate category "' . $cat['category_name'] . '" because it contains ' . $itemCount . ' menu item(s). Reassign or archive them first.';
            } else {
                $pendingChk = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Delete Category' AND category_id = ? AND status = 'Pending' LIMIT 1");
                $pendingChk->execute([$id]);
                if ($pendingChk->fetch()) {
                    $_SESSION['flash_msg'] = 'A deactivation request for category "' . $cat['category_name'] . '" is already pending Admin approval.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO approval_requests (request_type, category_id, remarks, payload, requested_by) VALUES ('Delete Category', ?, ?, ?, ?)");
                    $stmt->execute([
                        $id,
                        'Deactivate empty category: ' . $cat['category_name'],
                        json_encode(['category_name' => $cat['category_name']]),
                        $_SESSION['u_id']
                    ]);
                    $_SESSION['flash_msg'] = 'Category deactivation request for "' . $cat['category_name'] . '" submitted for Admin approval.';
                }
            }
        }
    } else {
        $_SESSION['flash_msg'] = 'Unauthorized action.';
    }

    header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
    exit;
}

if ($postAction === 'delete_item') {
    $id = (int) ($_POST['item_id'] ?? 0);

    if ($isAdmin) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT id, item_name, image, stock, availability, is_archived FROM menu_items WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $item = $stmt->fetch();

            if (!$item) {
                $conn->rollBack();
                $_SESSION['flash_msg'] = 'Menu item not found.';
            } elseif ((int) $item['stock'] > 0) {
                $conn->rollBack();
                $_SESSION['flash_msg'] = 'Cannot archive "' . $item['item_name'] . '" via this action while it still has ' . (int) $item['stock'] . ' pcs in stock. Use Inventory → Stock Out first or use Archive.';
            } else {
                $archiveStmt = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ? AND stock <= 0");
                $archiveStmt->execute([$id]);

                $closePendingStmt = $conn->prepare("
                    UPDATE approval_requests
                    SET status = 'Rejected',
                        review_notes = 'Closed automatically because the menu item was archived by Admin.',
                        reviewed_by = ?,
                        reviewed_at = NOW()
                    WHERE menu_item_id = ? AND status = 'Pending'
                ");
                $closePendingStmt->execute([$_SESSION['u_id'], $id]);

                $conn->commit();
                $_SESSION['flash_msg'] = 'Permanent deletion is disabled. Menu item "' . $item['item_name'] . '" was archived safely with all records preserved.';
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['flash_msg'] = 'Unable to archive menu item: ' . $e->getMessage();
        }
    } elseif ($isInventory) {
        $stmt = $conn->prepare("SELECT id, item_name, stock FROM menu_items WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) {
            $_SESSION['flash_msg'] = 'Menu item not found.';
        } else {
            $pendingChk = $conn->prepare("SELECT id, request_type FROM approval_requests WHERE menu_item_id = ? AND status = 'Pending' LIMIT 1");
            $pendingChk->execute([$id]);
            $existingPending = $pendingChk->fetch();
            if ($existingPending) {
                $_SESSION['flash_msg'] = 'Another request (' . $existingPending['request_type'] . ') for "' . $item['item_name'] . '" is already pending Admin approval.';
            } else {
                $stmt = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Archive', ?, ?, ?)");
                $stmt->execute([
                    $id,
                    'Archive menu item: ' . $item['item_name'],
                    $_SESSION['u_id']
                ]);
                $_SESSION['flash_msg'] = 'Archive request for "' . $item['item_name'] . '" submitted for Admin approval.';
            }
        }
    } else {
        $_SESSION['flash_msg'] = 'Unauthorized action.';
    }

    header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
    exit;
}

if ($postAction === 'toggle') {
    if (!$isAdmin) {
        $_SESSION['flash_msg'] = 'Only Admin can change menu availability.';
        header('Location: index.php');
        exit;
    }

    $id = (int) ($_POST['item_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, item_name, stock, availability FROM menu_items WHERE id = ? AND is_archived = 0 LIMIT 1");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found.';
    } elseif ($item['availability'] === 'Available' && (int) $item['stock'] > 0) {
        $_SESSION['flash_msg'] = $item['item_name'] . ' still has ' . (int) $item['stock'] . ' pcs in stock. Use Inventory → Stock Out first before marking it Out of Stock.';
    } elseif ($item['availability'] === 'Available') {
        $update = $conn->prepare("UPDATE menu_items SET availability = 'Unavailable' WHERE id = ? AND is_archived = 0 AND stock = 0");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item marked unavailable because stock is 0.';
    } elseif ((int) $item['stock'] <= 0) {
        $_SESSION['flash_msg'] = 'This item is still Out of Stock. Add stock first before making it available.';
    } else {
        $update = $conn->prepare("UPDATE menu_items SET availability = 'Available' WHERE id = ? AND is_archived = 0 AND stock > 0");
        $update->execute([$id]);
        $_SESSION['flash_msg'] = 'Menu item is available again.';
    }

    header('Location: index.php');
    exit;
}

if ($postAction === 'archive') {
    $id = (int) ($_POST['item_id'] ?? 0);

    $check = $conn->prepare("SELECT id, item_name, stock FROM menu_items WHERE id = ? AND is_archived = 0 LIMIT 1");
    $check->execute([$id]);
    $item = $check->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found or already archived.';
        $_SESSION['flash_type'] = 'danger';
    } elseif ($isAdmin) {
        // Admin direct archive
        $update = $conn->prepare("UPDATE menu_items SET is_archived = 1, availability = 'Unavailable' WHERE id = ? AND is_archived = 0");
        $update->execute([$id]);

        $closePending = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = 'Closed automatically because the menu item was archived by Admin.', reviewed_by = ?, reviewed_at = NOW() WHERE menu_item_id = ? AND status = 'Pending'");
        $closePending->execute([$_SESSION['u_id'], $id]);

        $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has been archived.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($isInventory) {
        // Inventory staff approval request flow
        $pending = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Archive' AND menu_item_id = ? AND status = 'Pending' LIMIT 1");
        $pending->execute([$id]);
        if ($pending->fetch()) {
            $_SESSION['flash_msg'] = 'An archive request for this menu item is already pending Admin approval.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $request = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Archive', ?, 'Menu archive request', ?)");
            $request->execute([$id, $_SESSION['u_id']]);
            $_SESSION['flash_msg'] = 'Archive request submitted for Admin approval.';
            $_SESSION['flash_type'] = 'success';
        }
    } else {
        $_SESSION['flash_msg'] = 'Unauthorized action.';
        $_SESSION['flash_type'] = 'danger';
    }

    header('Location: index.php');
    exit;
}

if ($postAction === 'restore') {
    $id = (int) ($_POST['item_id'] ?? 0);

    $check = $conn->prepare("SELECT id, item_name, stock, availability FROM menu_items WHERE id = ? AND is_archived = 1 LIMIT 1");
    $check->execute([$id]);
    $item = $check->fetch();

    if (!$item) {
        $_SESSION['flash_msg'] = 'Menu item not found or already active.';
        $_SESSION['flash_type'] = 'danger';
    } elseif ($isAdmin) {
        // Admin direct restore
        $update = $conn->prepare("UPDATE menu_items SET is_archived = 0, availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE 'Available' END WHERE id = ? AND is_archived = 1");
        $update->execute([$id]);

        $closePending = $conn->prepare("UPDATE approval_requests SET status = 'Rejected', review_notes = 'Closed automatically because the menu item was restored by Admin.', reviewed_by = ?, reviewed_at = NOW() WHERE menu_item_id = ? AND status = 'Pending'");
        $closePending->execute([$_SESSION['u_id'], $id]);

        if ((int) $item['stock'] <= 0) {
            $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has been restored with 0 stock (Unavailable). Restock it in Inventory before it can be sold.';
        } else {
            $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has been restored to active menu.';
        }
        $_SESSION['flash_type'] = 'success';
    } elseif ($isInventory) {
        // Inventory staff approval request flow
        $pending = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Unarchive' AND menu_item_id = ? AND status = 'Pending' LIMIT 1");
        $pending->execute([$id]);
        if ($pending->fetch()) {
            $_SESSION['flash_msg'] = 'An unarchive request for this menu item is already pending Admin approval.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $request = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Unarchive', ?, 'Menu unarchive request', ?)");
            $request->execute([$id, $_SESSION['u_id']]);
            $_SESSION['flash_msg'] = 'Unarchive request submitted for Admin approval.';
            $_SESSION['flash_type'] = 'success';
        }
    } else {
        $_SESSION['flash_msg'] = 'Unauthorized action.';
        $_SESSION['flash_type'] = 'danger';
    }

    header('Location: index.php?archived=1');
    exit;
}

$categories = $conn->query("SELECT * FROM categories WHERE status = 'Active' ORDER BY category_name")->fetchAll();

$catCountsStmt = $conn->prepare("
    SELECT c.*, COUNT(mi.id) AS item_count
    FROM categories c
    LEFT JOIN menu_items mi ON mi.category_id = c.id AND mi.is_archived = :archived
    WHERE c.status = 'Active'
    GROUP BY c.id
    ORDER BY c.category_name ASC
");
$catCountsStmt->bindValue(':archived', $showArchived ? 1 : 0, PDO::PARAM_INT);
$catCountsStmt->execute();
$categoriesWithCounts = $catCountsStmt->fetchAll();

// Display order only; category records and IDs remain unchanged.
$categoryOrder = array_flip(['add-ons', 'student meals', 'pulutan & pares', 'drinks', 'silog', 'silog meals', 'silog set meals', 'bilao favorites']);
$sortCategories = static function (array $a, array $b) use ($categoryOrder): int {
    $aRank = $categoryOrder[strtolower(trim($a['category_name']))] ?? PHP_INT_MAX;
    $bRank = $categoryOrder[strtolower(trim($b['category_name']))] ?? PHP_INT_MAX;
    return ($aRank <=> $bRank) ?: strcasecmp($a['category_name'], $b['category_name']);
};
usort($categories, $sortCategories);
usort($categoriesWithCounts, $sortCategories);
$menuCategoryLabel = static function (string $name): string {
    return match (strtolower(trim($name))) {
        'add-ons' => 'Add-ons',
        'silog meals', 'silog' => 'Silog',
        default => $name,
    };
};

$pendingCategoryRequests = [];
$pendingCatStmt = $conn->query("SELECT id, category_id, request_type FROM approval_requests WHERE status = 'Pending' AND request_type IN ('Add Category', 'Delete Category')");
foreach ($pendingCatStmt->fetchAll() as $pCat) {
    if ($pCat['request_type'] === 'Delete Category' && !empty($pCat['category_id'])) {
        $pendingCategoryRequests[(int)$pCat['category_id']] = 'Delete Category';
    }
}

$pendingMenuRequests = [];
if ($isInventory || $isAdmin) {
    $pendingStmt = $conn->query("SELECT menu_item_id, request_type FROM approval_requests WHERE status = 'Pending' AND request_type IN ('Archive','Unarchive','Delete Item')");
    foreach ($pendingStmt->fetchAll() as $pendingRow) {
        if (!empty($pendingRow['menu_item_id'])) {
            $pendingMenuRequests[(int) $pendingRow['menu_item_id']] = $pendingRow['request_type'];
        }
    }
}

$myPendingCount = 0;
if ($isInventory && !$isAdmin) {
    $myPendingStmt = $conn->prepare("SELECT COUNT(*) FROM approval_requests WHERE requested_by = ? AND status = 'Pending' AND request_type IN ('Add Category', 'Delete Category', 'Add Item', 'Delete Item', 'Archive', 'Unarchive')");
    $myPendingStmt->execute([$_SESSION['u_id']]);
    $myPendingCount = (int)$myPendingStmt->fetchColumn();
}

$selectedCategory = trim($_GET['category_id'] ?? '');

$menuWhere = 'WHERE menu_items.is_archived = :archived';
if ($selectedCategory !== '' && $selectedCategory !== 'all') {
    $menuWhere .= ' AND menu_items.category_id = :category_id';
}
if ($menuSearch !== '') {
    $menuWhere .= ' AND (menu_items.item_name LIKE :search_name OR categories.category_name LIKE :search_category)';
}

$menuCountStmt = $conn->prepare("SELECT COUNT(*) FROM menu_items JOIN categories ON categories.id = menu_items.category_id $menuWhere");
$menuCountStmt->bindValue(':archived', $showArchived ? 1 : 0, PDO::PARAM_INT);

if ($selectedCategory !== '' && $selectedCategory !== 'all') {
    $menuCountStmt->bindValue(':category_id', (int)$selectedCategory, PDO::PARAM_INT);
}
if ($menuSearch !== '') {
    $menuSearchLike = '%' . $menuSearch . '%';
    $menuCountStmt->bindValue(':search_name', $menuSearchLike, PDO::PARAM_STR);
    $menuCountStmt->bindValue(':search_category', $menuSearchLike, PDO::PARAM_STR);
}


$menuCountStmt->execute();
$totalMenuItems = (int) $menuCountStmt->fetchColumn();
$totalMenuPages = max(1, (int) ceil($totalMenuItems / $menuPerPage));

if ($menuPage > $totalMenuPages) {
    $menuPage = $totalMenuPages;
}

$menuOffset = ($menuPage - 1) * $menuPerPage;

$itemsStmt = $conn->prepare("SELECT menu_items.*, categories.category_name FROM menu_items JOIN categories ON categories.id = menu_items.category_id $menuWhere ORDER BY categories.sort_order ASC, CASE WHEN menu_items.sort_order > 0 THEN menu_items.sort_order ELSE 999999 END ASC, menu_items.item_name ASC, menu_items.id ASC LIMIT :limit OFFSET :offset");
$itemsStmt->bindValue(':archived', $showArchived ? 1 : 0, PDO::PARAM_INT);

if ($selectedCategory !== '' && $selectedCategory !== 'all') {
    $itemsStmt->bindValue(':category_id', (int)$selectedCategory, PDO::PARAM_INT);
}
if ($menuSearch !== '') {
    $itemsStmt->bindValue(':search_name', $menuSearchLike, PDO::PARAM_STR);
    $itemsStmt->bindValue(':search_category', $menuSearchLike, PDO::PARAM_STR);
}

$itemsStmt->bindValue(':limit', $menuPerPage, PDO::PARAM_INT);
$itemsStmt->bindValue(':offset', $menuOffset, PDO::PARAM_INT);
$itemsStmt->execute();
$items = $itemsStmt->fetchAll();

$archivedCount = (int) $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_archived = 1")->fetchColumn();

$buildQuery = static function (array $params): string {
    $params = array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    });
    return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

include ROOT_PATH . '/includes/header.php';
?>
<style>
/* Modern Menu Management Layout (SimpleQR-inspired split layout) */
.menu-mgmt-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.menu-mgmt-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    background: #ffffff;
    padding: 20px 24px;
    border-radius: 14px;
    border: 1px solid #eef0f2;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
}

.menu-mgmt-header h1,
.menu-mgmt-header h2 {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 800;
    color: #1e1e1e;
    letter-spacing: -0.3px;
}

.menu-mgmt-header p {
    margin: 4px 0 0;
    font-size: 0.85rem;
    color: #64748b;
}

.menu-mgmt-top-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-kds-gold {
    background: #F2C12E !important;
    color: #111111 !important;
    border: 1px solid #D8AF18 !important;
    font-weight: 700 !important;
    border-radius: 10px !important;
    padding: 8px 16px !important;
    font-size: 0.85rem !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    text-decoration: none !important;
    cursor: pointer;
    transition: all 0.15s ease !important;
}

.btn-kds-gold:hover {
    background: #E8B717 !important;
    transform: translateY(-1px);
}

.btn-kds-dark {
    background: #1e1e1e !important;
    color: #ffffff !important;
    border: 1px solid #1e1e1e !important;
    font-weight: 700 !important;
    border-radius: 10px !important;
    padding: 8px 16px !important;
    font-size: 0.85rem !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    text-decoration: none !important;
    cursor: pointer;
    transition: all 0.15s ease !important;
}

.btn-kds-dark:hover {
    background: #2a2a2a !important;
    transform: translateY(-1px);
}

/* Two-column Menu layout: Categories panel (320px) + Product listings panel (Flexible) */
.menu-mgmt-body {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 20px;
    align-items: start;
}

/* Category Sidebar Panel */
.menu-categories-panel {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #eef0f2;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.menu-cat-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 2px;
}

.menu-cat-head h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #1e1e1e;
}

.menu-cat-search {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 12px;
    height: 38px;
    gap: 8px;
}

.menu-cat-search input {
    border: none;
    outline: none;
    background: transparent;
    font-size: 0.84rem;
    width: 100%;
}

.menu-cat-search i {
    color: #94a3b8;
}

.menu-cat-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    list-style: none;
    padding: 0;
    margin: 0;
}

.menu-cat-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid #eef0f2;
    background: #ffffff;
    transition: all 0.15s ease;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
}

.menu-cat-item:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateX(2px);
}

.menu-cat-item.active {
    background: #fffdf5;
    border-color: #F2C12E;
    box-shadow: 0 0 0 1px #F2C12E;
}

.menu-cat-item-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.menu-cat-item-title {
    font-size: 0.92rem;
    font-weight: 700;
    color: #1e1e1e;
}

.menu-cat-item-sub {
    font-size: 0.76rem;
    color: #64748b;
    margin-top: 2px;
}

.menu-cat-item-actions {
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-cat-action {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    transition: all 0.12s ease;
}

.btn-cat-action:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.btn-cat-action.delete:hover {
    background: #fef2f2;
    color: #ef4444;
}

/* Product Listing Panel */
.menu-products-panel {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #eef0f2;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    padding: 22px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.menu-products-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding-bottom: 14px;
    border-bottom: 1px solid #f1f5f9;
}

.menu-active-cat-title {
    display: flex;
    flex-direction: column;
}

.menu-active-cat-title h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: #1e1e1e;
}

.menu-active-cat-title span {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 2px;
}

.menu-products-filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.menu-prod-search {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 12px;
    height: 40px;
    min-width: 240px;
    gap: 8px;
}

.menu-prod-search input {
    border: none;
    outline: none;
    background: transparent;
    font-size: 0.86rem;
    width: 100%;
}

.menu-prod-search i {
    color: #94a3b8;
}

/* Cards Layout for Products matching SimpleQR reference */
.menu-items-cards-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.menu-item-row-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 18px;
    border-radius: 12px;
    border: 1px solid #f1f5f9;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    transition: all 0.15s ease;
}

.menu-item-row-card:hover {
    border-color: #e2e8f0;
    box-shadow: 0 4px 14px rgba(0,0,0,0.04);
}

.menu-item-left {
    display: flex;
    align-items: center;
    gap: 16px;
    min-width: 0;
    flex: 1;
}

.menu-item-thumb-box {
    position: relative;
    width: 68px;
    height: 68px;
    flex-shrink: 0;
    border-radius: 12px;
    overflow: hidden;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.menu-item-thumb-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.menu-thumb-edit-btn { position: absolute; inset: 0; cursor: pointer; border-radius: inherit; }
.menu-thumb-edit-btn input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
.menu-item-thumb-box:has(input:focus-visible) { outline: 3px solid var(--primary); outline-offset: 3px; }
.menu-item-thumb-box:has(.menu-thumb-edit-btn):hover { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(242,193,46,.18); }

.menu-item-details {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
}

.menu-item-header-line {
    display: flex;
    align-items: center;
    gap: 8px;
}

.menu-item-name {
    font-size: 1rem;
    font-weight: 800;
    color: #1e1e1e;
    margin: 0;
    line-height: 1.25;
}

.menu-item-desc {
    font-size: 0.82rem;
    color: #64748b;
    margin: 4px 0 6px;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.menu-item-meta-pills {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 0.78rem;
}

.menu-item-price-tag {
    font-size: 1.05rem;
    font-weight: 800;
    color: #1e1e1e;
    font-family: 'Inter', sans-serif;
}

.menu-item-actions-box {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex-shrink: 0;
}

.btn-menu-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-menu-action:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}

.btn-menu-action.danger:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: #ef4444;
}

.btn-menu-action.active-avail {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #065f46;
}

.btn-menu-action.unavail {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

@media (max-width: 900px) {
    .menu-mgmt-body {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .menu-item-row-card {
        flex-direction: column;
        align-items: stretch;
        padding: 14px;
    }
    .menu-item-actions-box {
        width: 100%;
        justify-content: flex-start;
    }
    .menu-mgmt-main,
    .menu-item-list {
        min-width: 0;
        max-width: 100%;
    }
}

/* Modal styling & clean file upload */
.menu-modal-dialog {
    background: #ffffff;
    border-radius: 16px;
    width: 620px;
    max-width: 95vw;
    padding: 28px 30px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    position: relative;
    border: 1px solid #e2e8f0;
    max-height: 90vh;
    overflow-y: auto;
    box-sizing: border-box;
}

.custom-file-upload {
    display: flex;
    align-items: center;
    height: 44px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    background: #ffffff;
    padding: 0 10px;
    width: 100%;
    box-sizing: border-box;
    cursor: pointer;
    overflow: hidden;
    gap: 10px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.custom-file-upload:hover {
    border-color: #F2C12E;
}

.custom-file-upload input[type="file"] {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}

.custom-file-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #334155;
    white-space: nowrap;
    cursor: pointer;
    flex-shrink: 0;
}

.custom-file-text {
    font-size: 0.82rem;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

.menu-modal-dialog .field label {
    font-weight: 700;
    font-size: 0.86rem;
    color: #1e1e1e;
    margin-bottom: 6px;
    display: block;
}

.menu-modal-dialog input[type="text"],
.menu-modal-dialog input[type="number"],
.menu-modal-dialog input[type="date"],
.menu-modal-dialog select,
.menu-modal-dialog textarea {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: 9px 12px;
    font-size: 0.88rem;
    color: #1e1e1e;
    background: #ffffff;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.menu-modal-dialog input:focus,
.menu-modal-dialog select:focus,
.menu-modal-dialog textarea:focus {
    border-color: #F2C12E;
    box-shadow: 0 0 0 3px rgba(242, 193, 46, 0.25);
}

.menu-modal-dialog { border-top: 4px solid var(--primary); }
.menu-modal-compact { width: 460px; }
.menu-modal-bulk { width: 560px; }
.menu-modal-dialog h3 { padding-right: 28px; }
.menu-modal-dialog::before { content: "KENJI'S KITCHEN · MENU"; display: block; color: #856200; font-size: 11px; font-weight: 700; letter-spacing: .08em; margin-bottom: 12px; }
.menu-modal-dialog input[type="datetime-local"] { width: 100%; min-width: 0; box-sizing: border-box; font: inherit; }
.menu-modal-dialog .grid { align-items: start; }
.menu-modal-dialog .field label { margin-bottom: 0; }
.menu-form-hint { color: var(--muted); font-size: 12px; line-height: 1.5; margin: -6px 0 20px; }
.menu-management-page .kk-dialog-card { border-radius: 18px; }
.menu-management-page .kk-dialog-accent { background: var(--primary); }
.menu-management-page .kk-dialog-body::before { content: "KENJI'S KITCHEN · MENU"; display: block; margin-bottom: 18px; font-size: 11px; font-weight: 700; letter-spacing: .08em; color: #856200; }
.menu-management-page .kk-dialog-icon:not(.danger) { background: #fff4d2; color: #856200; }
.menu-management-page .kk-dialog-details { background: #fffaf0; border-color: #f1df9e; }
@media (max-width: 600px) {
    .menu-modal-dialog { padding: 22px 18px; max-width: 100%; }
    .menu-modal-dialog .grid { grid-template-columns: 1fr; }
    .menu-products-filter-bar { width: 100%; }
    .menu-products-filter-bar .kk-pagination { margin-left: 0 !important; }
}

.menu-price-action { background: #fff9e5; border-color: #eed58b; color: #765400; }
.menu-price-action:hover { background: #fff0b3; border-color: var(--primary); }
#itemPriceModalSubtitle { background: #fff9e5; border: 1px solid #f1df9e; border-radius: 10px; padding: 12px 14px; color: #574314; font-weight: 600; }
.menu-scheduler { border: 1px solid var(--border); border-radius: 12px; padding: 12px; background: #fffdf7; }
.schedule-modes { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.menu-scheduler button { font: inherit; cursor: pointer; color: var(--dark); }
.schedule-modes button { border: 1px solid var(--border); background: white; border-radius: 8px; padding: 10px; font-size: 13px; font-weight: 600; }
.schedule-modes button[aria-pressed="true"] { background: var(--primary); border-color: var(--primary); }
.schedule-panel[hidden] { display: none; }
.schedule-month { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 16px 0 10px; }
.schedule-month button { border: 1px solid var(--border); background: white; border-radius: 8px; width: 34px; height: 34px; }
.schedule-month strong { font-size: 14px; }
.schedule-days, .schedule-week { display: grid; grid-template-columns: repeat(7, minmax(0,1fr)); gap: 4px; text-align: center; }
.schedule-week { font-size: 11px; color: var(--muted); margin-bottom: 6px; }
.schedule-days button { border: 1px solid transparent; background: transparent; border-radius: 8px; min-height: 34px; font-size: 13px; }
.schedule-days button:hover { background: #fff0b3; }
.schedule-days button[aria-pressed="true"] { background: var(--primary); font-weight: 700; }
.schedule-days button:disabled { opacity: .3; cursor: default; }
.schedule-time { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-top: 14px; }
.schedule-time label { font-size: 11px !important; color: var(--muted) !important; }
.menu-modal-dialog .schedule-time select { min-height: 40px; margin-top: 5px; padding: 7px; }
.schedule-summary { margin: 12px 0 0; color: #765400; font-size: 12px; line-height: 1.5; }
.menu-scheduler button:focus-visible { outline: 2px solid #806000; outline-offset: 2px; }
</style>
<script>
var KK_CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function previewMenuImage(input) {
    var preview = document.getElementById('menuImagePreview');
    var textSpan = document.getElementById('menuImageFileName');
    if (input.files && input.files[0]) {
        var file = input.files[0];
        if (textSpan) textSpan.textContent = file.name;
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        if (textSpan) textSpan.textContent = 'No file chosen';
        preview.src = '';
        preview.style.display = 'none';
    }
}

function submitQuickImageUpload(input, itemId) {
    if (!input.files || !input.files[0]) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';
    form.enctype = 'multipart/form-data';
    form.style.display = 'none';

    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'update_image';
    form.appendChild(actionInput);

    var idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'item_id';
    idInput.value = itemId;
    form.appendChild(idInput);

    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = KK_CSRF_TOKEN;
    form.appendChild(csrfInput);

    var fileClone = input.cloneNode(true);
    // Move the actual file input to form to preserve files property
    input.parentNode.appendChild(fileClone);
    form.appendChild(input);

    document.body.appendChild(form);
    form.submit();
}
</script>

<?php if ($msg): ?><div class="alert alert-<?= $msgType === 'danger' ? 'danger' : 'success' ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if ($myPendingCount > 0): ?>
    <div class="alert alert-info" style="display:flex; align-items:center; gap:10px; margin-bottom:18px;">
        <i class="bi bi-hourglass-split" style="font-size:1.2rem;"></i>
        <div>You have <strong><?= $myPendingCount ?></strong> pending menu/category request(s) awaiting Admin approval.</div>
    </div>
<?php endif; ?>

<div class="menu-mgmt-container">
    <!-- Header with Breadcrumb / Title & Primary Action Buttons matching SimpleQR -->
    <header class="menu-mgmt-header">
        <div>
            <h2>Menu Management</h2>
            <p>Manage your restaurant's food menu by adding, editing, organizing categories, uploading photos, and setting prices.</p>
        </div>

        <div class="menu-mgmt-top-actions">
            <?php if ($isAdmin): ?>
                <button type="button" class="btn-kds-dark" onclick="openBulkPriceModal()">
                    <i class="bi bi-tags-fill"></i> Bulk Price Adjust
                </button>
                <button type="button" class="btn-kds-dark" onclick="openCategoryModal()">
                    <i class="bi bi-folder-plus"></i> Add Category
                </button>
                <button type="button" class="btn-kds-gold" onclick="openAddItemModal()">
                    <i class="bi bi-plus-lg"></i> Add Menu Item
                </button>
            <?php elseif ($isInventory): ?>
                <button type="button" class="btn-kds-dark" onclick="openCategoryModal()">
                    <i class="bi bi-send-fill"></i> Request Category
                </button>
                <button type="button" class="btn-kds-gold" onclick="openAddItemModal()">
                    <i class="bi bi-send-fill"></i> Request Menu Item
                </button>
            <?php endif; ?>

            <?php /* Archived toggle hidden — archived items are retained in DB for sales history only */ ?>
        </div>
    </header>

    <!-- Split 2-Column Body: Categories on the Left (320px), Products on the Right -->
    <div class="menu-mgmt-body">
        <!-- LEFT: Categories List Panel -->
        <aside class="menu-categories-panel">
            <div class="menu-cat-head">
                <h3>Categories</h3>
                <span style="font-size:0.8rem;color:#64748b;"><?= count($categoriesWithCounts) ?> total</span>
            </div>

            <div class="menu-cat-search">
                <i class="bi bi-search"></i>
                <input type="search" id="catFilterInput" placeholder="Search categories..." oninput="filterCategoryCards(this.value)">
            </div>

            <ul class="menu-cat-list" id="categoriesCardList">
                <!-- 'All Categories' Tab -->
                <li>
                    <a class="menu-cat-item <?= ($selectedCategory === '' || $selectedCategory === 'all') ? 'active' : '' ?>" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => 1, 'category_id' => 'all', 'menu_search' => $menuSearch])) ?>">
                        <div class="menu-cat-item-info">
                            <span class="menu-cat-item-title">All Categories</span>
                            <span class="menu-cat-item-sub">View all menu items</span>
                        </div>
                        <span class="badge badge-muted" style="font-weight:800;border-radius:10px;"><?= $totalMenuItems ?></span>
                    </a>
                </li>

                <!-- Dynamic Categories -->
                <?php foreach ($categoriesWithCounts as $cat): ?>
                    <?php
                    $isCatActive = ($selectedCategory === (string)$cat['id']);
                    $catHasItems = (int)$cat['item_count'] > 0;
                    ?>
                    <li class="cat-list-entry" data-name="<?= strtolower(e($menuCategoryLabel($cat['category_name']))) ?>">
                        <div class="menu-cat-item <?= $isCatActive ? 'active' : '' ?>" onclick="window.location.href='<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => 1, 'category_id' => $cat['id'], 'menu_search' => $menuSearch])) ?>'">
                            <div class="menu-cat-item-info">
                                <span class="menu-cat-item-title"><?= e($menuCategoryLabel($cat['category_name'])) ?></span>
                                <span class="menu-cat-item-sub"><?= (int)$cat['item_count'] ?> item<?= (int)$cat['item_count'] === 1 ? '' : 's' ?></span>
                            </div>

                            <div class="menu-cat-item-actions" onclick="event.stopPropagation();">
                                <?php if ($isAdmin): ?>
                                    <?php if (!$catHasItems): ?>
                                        <form method="post" action="<?= e($menuRedirect) ?>" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?= e($cat['id']) ?>">
                                            <button type="submit" class="btn-cat-action delete" title="Deactivate Empty Category" data-confirm="Deactivate category '<?= e(addslashes($cat['category_name'])) ?>'? Its history will be preserved.">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($isInventory && !$catHasItems && empty($pendingCategoryRequests[(int)$cat['id']])): ?>
                                    <form method="post" action="<?= e($menuRedirect) ?>" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?= e($cat['id']) ?>">
                                        <button type="submit" class="btn-cat-action delete" title="Request Category Deactivation" data-confirm="Submit request to deactivate category '<?= e(addslashes($cat['category_name'])) ?>'? Its history will be preserved.">
                                            <i class="bi bi-archive"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <!-- RIGHT: Product Listings & Item Cards Panel -->
        <main class="menu-products-panel">
            <div class="menu-products-toolbar">
                <div class="menu-active-cat-title">
                    <?php
                    $activeCatName = 'All Categories';
                    foreach ($categories as $c) {
                        if ((string)$c['id'] === $selectedCategory) {
                            $activeCatName = $menuCategoryLabel($c['category_name']);
                            break;
                        }
                    }
                    ?>
                    <h3><?= e($activeCatName) ?></h3>
                    <span>Showing <?= count($items) ?> of <?= $totalMenuItems ?> product<?= $totalMenuItems === 1 ? '' : 's' ?></span>
                </div>

                <div class="menu-products-filter-bar">
                    <form method="get" action="index.php" style="margin:0;display:flex;gap:8px;">
                        <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
                        <input type="hidden" name="category_id" value="<?= e($selectedCategory) ?>">
                        <input type="hidden" name="menu_page" value="1">
                        <div class="menu-prod-search">
                            <i class="bi bi-search"></i>
                            <input type="search" name="menu_search" value="<?= e($menuSearch) ?>" placeholder="Search menu items..." autocomplete="off">
                        </div>
                        <?php if ($menuSearch !== ''): ?>
                            <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'category_id' => $selectedCategory, 'menu_page' => 1])) ?>">Clear</a>
                        <?php endif; ?>
                    </form>

                    <?= render_pagination_controls($menuPage, $totalMenuPages, static fn(int $p) => $buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => $p, 'menu_search' => $menuSearch, 'category_id' => $selectedCategory])) ?>
                </div>
            </div>

            <!-- Product Items List matching SimpleQR Cards -->
            <div class="menu-items-cards-list">
                <?php if (!$items): ?>
                    <div style="text-align:center;padding:48px 20px;color:#94a3b8;border:1px dashed #e2e8f0;border-radius:12px;">
                        <i class="bi bi-inbox" style="font-size:2.2rem;display:block;margin-bottom:8px;"></i>
                        <p style="margin:0;font-size:0.95rem;font-weight:600;">No menu products found in this category.</p>
                        <p style="margin:4px 0 0;font-size:0.8rem;">Click "Add Menu Item" above to add products.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($items as $row): ?>
                    <?php
                    $isArchivedItem = $showArchived || !empty($row['is_archived']);
                    $stockVal = (int)$row['stock'];
                    $isAvail = !$isArchivedItem && $stockVal > 0 && ($row['availability'] ?? '') === 'Available';
                    ?>
                    <article class="menu-item-row-card">
                        <div class="menu-item-left">
                            <div class="menu-item-thumb-box">
                                <?php if (!empty($row['image'])): ?>
                                    <img src="<?= e(menu_image_url($row['image'])) ?>" alt="<?= e($row['item_name']) ?>">
                                <?php else: ?>
                                    <img src="<?= BASE_URL ?>/assets/img/food-placeholder.svg" alt="">
                                <?php endif; ?>

                                <?php if ($isAdmin): ?>
                                    <label class="menu-thumb-edit-btn" title="Change photo for <?= e($row['item_name']) ?>">
                                        <input type="file" name="image" accept="image/*" aria-label="Change photo for <?= e($row['item_name']) ?>" onchange="submitQuickImageUpload(this, <?= (int)$row['id'] ?>)">
                                    </label>
                                <?php endif; ?>
                            </div>

                            <div class="menu-item-details">
                                <div class="menu-item-header-line">
                                    <h4 class="menu-item-name"><?= e($row['item_name']) ?></h4>
                                    <?php if ($isArchivedItem): ?>
                                        <span class="badge badge-muted" style="font-size:0.72rem;">Archived</span>
                                    <?php elseif ($stockVal <= 0): ?>
                                        <span class="badge badge-danger" style="font-size:0.72rem;">Out of Stock</span>
                                    <?php elseif (($row['availability'] ?? '') !== 'Available'): ?>
                                        <span class="badge badge-danger" style="font-size:0.72rem;">Unavailable</span>
                                    <?php elseif ($stockVal <= 5): ?>
                                        <span class="badge badge-warning" style="font-size:0.72rem;">Low Stock (<?= $stockVal ?>)</span>
                                    <?php else: ?>
                                        <span class="badge badge-success" style="font-size:0.72rem;">Available</span>
                                    <?php endif; ?>
                                </div>

                                <p class="menu-item-desc"><?= e($row['description'] ?: 'No description provided.') ?></p>

                                <div class="menu-item-meta-pills">
                                    <span class="menu-item-price-tag"><?= money($row['price']) ?></span>

                                    <?php if (!empty($row['promo_price'])): ?>
                                        <span class="badge badge-warning" style="font-size:0.75rem;">
                                            Promo: <?= money($row['promo_price']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <span style="color:#64748b;">•</span>
                                    <span style="color:#64748b;font-weight:600;"><?= e($menuCategoryLabel($row['category_name'])) ?></span>

                                    <?php if (!empty($row['pending_price']) && !empty($row['pending_price_effective'])): ?>
                                        <span class="badge" style="background:#fef3c7;color:#b45309;border:1px solid #fde68a;font-size:0.72rem;">
                                            <i class="bi bi-clock"></i> New price <?= money($row['pending_price']) ?> on <?= date('M j, g:i A', strtotime($row['pending_price_effective'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right Item Actions -->
                        <div class="menu-item-actions-box">
                            <?php if ($showArchived): ?>
                                <?php if ($isAdmin): ?>
                                    <form method="post" action="index.php?archived=1" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn-menu-action" title="Restore Item" data-confirm="Restore this item to the active customer menu?">
                                            <i class="bi bi-arrow-counterclockwise"></i> Restore
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($isAdmin): ?>
                                    <button type="button" class="btn-menu-action" onclick='openEditItemModal(<?= htmlspecialchars(json_encode([
                                        'id' => (int)$row['id'],
                                        'item_name' => $row['item_name'],
                                        'category_id' => (int)$row['category_id'],
                                        'description' => $row['description'] ?? '',
                                        'price' => (float)$row['price'],
                                        'image' => $row['image'] ?? '',
                                        'image_url' => !empty($row['image']) ? menu_image_url($row['image']) : '',
                                        'availability' => $row['availability'] ?? 'Available',
                                        'promo_price' => !empty($row['promo_price']) ? (float)$row['promo_price'] : '',
                                        'promo_start' => $row['promo_start'] ?? '',
                                        'promo_end' => $row['promo_end'] ?? '',
                                    ]), ENT_QUOTES, 'UTF-8') ?>)' title="Edit Details & Photo">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>
                                    <button type="button" class="btn-menu-action menu-price-action" onclick="openItemPriceModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['item_name'])) ?>', <?= (float)$row['price'] ?>, '<?= !empty($row['pending_price']) ? (float)$row['pending_price'] : '' ?>', '<?= !empty($row['pending_price_effective']) ? date('Y-m-d\TH:i', strtotime($row['pending_price_effective'])) : '' ?>')" title="Update Price">
                                        <i class="bi bi-tag"></i> Price
                                    </button>

                                    <!-- Quick Availability Toggle -->
                                    <form method="post" action="index.php" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn-menu-action <?= $isAvail ? 'active-avail' : 'unavail' ?>" title="Toggle Availability">
                                            <i class="bi <?= $isAvail ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                            <?= $isAvail ? 'Available' : 'Unavailable' ?>
                                        </button>
                                    </form>

                                    <form method="post" action="index.php" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn-menu-action" title="Archive Menu Item" data-menu-confirm="archive" data-menu-name="<?= e($row['item_name']) ?>">
                                            <i class="bi bi-archive"></i>
                                        </button>
                                    </form>
                                <?php elseif ($isInventory): ?>
                                    <?php if (isset($pendingMenuRequests[(int)$row['id']])): ?>
                                        <span class="badge badge-warning"><?= e($pendingMenuRequests[(int)$row['id']]) ?> Pending</span>
                                    <?php else: ?>
                                        <form method="post" action="index.php" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                            <button type="submit" class="btn-menu-action" title="Request Archive" data-menu-confirm="request-archive" data-menu-name="<?= e($row['item_name']) ?>">
                                                <i class="bi bi-archive"></i> Request Archive
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal 1: Add/Request Menu Item -->
<div id="addMenuItemModal" role="dialog" aria-modal="true" aria-label="Add menu item" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div class="menu-modal-dialog">
        <button type="button" onclick="closeAddItemModal()" aria-label="Close dialog" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        <h3 style="margin-top:0; margin-bottom:4px; font-size:1.3rem; font-weight:800; color:#1e1e1e;">
            <?= $isAdmin ? 'Add New Menu Item' : 'Request New Menu Item' ?>
        </h3>
        <p style="margin-bottom:20px; font-size:0.84rem; color:#64748b;">
            <?= $isAdmin ? 'Create a new food or beverage item for POS and Online ordering.' : 'Submit a new item for Admin approval.' ?>
        </p>

        <form class="form" method="post" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="item">
            <div class="grid grid-2" style="gap:14px;">
                <div class="field">
                    <label>Item Name</label>
                    <input type="text" name="item_name" required placeholder="e.g. Special Beef Pares">
                </div>
                <div class="field">
                    <label>Category</label>
                    <select name="category_id" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat['id']) ?>" <?= $selectedCategory === (string)$cat['id'] ? 'selected' : '' ?>><?= e($menuCategoryLabel($cat['category_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Description</label>
                <textarea name="description" rows="3" placeholder="Describe ingredients, cooking style, or portion size"></textarea>
            </div>
            <div class="grid grid-2" style="gap:14px;">
                <div class="field">
                    <label>Price (₱)</label>
                    <input type="number" step="0.01" min="0" name="price" required placeholder="0.00">
                </div>
                <div class="field">
                    <label>Item Photo <span style="font-weight:400; font-size:0.75rem; color:#64748b;">(jpg/png/webp, max 10 MB)</span></label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <label class="custom-file-upload" for="menuImageInput">
                            <span class="custom-file-btn"><i class="bi bi-cloud-arrow-up"></i> Browse</span>
                            <span class="custom-file-text" id="menuImageFileName">No file chosen</span>
                            <input type="file" name="image" accept="image/*" id="menuImageInput" onchange="previewMenuImage(this)">
                        </label>
                        <img id="menuImagePreview" src="" alt="" style="display:none; width:44px; height:44px; object-fit:cover; border-radius:10px; border:1px solid #e2e8f0; flex:0 0 44px;">
                    </div>
                </div>
            </div>
            <div class="grid grid-3" style="gap:12px;">
                <div class="field"><label>Promo Price</label><input type="number" step="0.01" name="promo_price" placeholder="Optional"></div>
                <div class="field"><label>Promo Start</label><input type="date" name="promo_start"></div>
                <div class="field"><label>Promo End</label><input type="date" name="promo_end"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeAddItemModal()" style="border-radius:10px; padding:9px 18px; font-weight:700;">Cancel</button>
                <button class="btn btn-primary" type="submit" style="border-radius:10px; padding:9px 20px; font-weight:700;">
                    <i class="bi <?= $isAdmin ? 'bi-plus-circle' : 'bi-send-fill' ?>"></i> <?= $isAdmin ? 'Save Menu Item' : 'Submit for Approval' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 1b: Edit Menu Item Modal (Admin) -->
<div id="editMenuItemModal" role="dialog" aria-modal="true" aria-label="Edit menu item" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div class="menu-modal-dialog">
        <button type="button" onclick="closeEditItemModal()" aria-label="Close dialog" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        <h3 style="margin-top:0; margin-bottom:4px; font-size:1.3rem; font-weight:800; color:#1e1e1e;">
            Edit Menu Item
        </h3>
        <p style="margin-bottom:20px; font-size:0.84rem; color:#64748b;">
            Update food or beverage details, photo, category, and pricing.
        </p>

        <form class="form" method="post" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="item_id" id="editItemId" value="">

            <div class="grid grid-2" style="gap:14px;">
                <div class="field">
                    <label>Item Name</label>
                    <input type="text" name="item_name" id="editItemName" required placeholder="e.g. Special Beef Pares">
                </div>
                <div class="field">
                    <label>Category</label>
                    <select name="category_id" id="editItemCategory" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat['id']) ?>"><?= e($menuCategoryLabel($cat['category_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>Description</label>
                <textarea name="description" id="editItemDesc" rows="3" placeholder="Describe ingredients, cooking style, or portion size"></textarea>
            </div>

            <div class="grid grid-2" style="gap:14px;">
                <div class="field">
                    <label>Price (₱)</label>
                    <input type="number" step="0.01" min="0" name="price" id="editItemPrice" required placeholder="0.00">
                </div>
                <div class="field">
                    <label>Availability</label>
                    <select name="availability" id="editItemAvailability">
                        <option value="Available">Available</option>
                        <option value="Unavailable">Unavailable</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>Item Photo <span style="font-weight:400; font-size:0.75rem; color:#64748b;">(jpg/png/webp, max 10 MB)</span></label>
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <img id="editMenuImagePreview" src="" alt="Photo preview" style="display:none; width:56px; height:56px; object-fit:cover; border-radius:10px; border:2px solid #e2e8f0; flex:0 0 56px;">
                    <div style="flex:1; min-width:200px;">
                        <label class="custom-file-upload" for="editMenuImageInput">
                            <span class="custom-file-btn"><i class="bi bi-cloud-arrow-up"></i> Change Photo</span>
                            <span class="custom-file-text" id="editMenuImageFileName">No file chosen</span>
                            <input type="file" name="image" accept="image/*" id="editMenuImageInput" onchange="previewEditMenuImage(this)">
                        </label>
                    </div>
                </div>
                <div id="editItemRemoveImgBox" style="display:none; margin-top:8px;">
                    <label style="font-size:0.8rem; color:#ef4444; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" name="remove_image" id="editItemRemoveImgInput" value="1">
                        <span>Remove current photo</span>
                    </label>
                </div>
            </div>

            <div class="grid grid-3" style="gap:12px;">
                <div class="field"><label>Promo Price</label><input type="number" step="0.01" name="promo_price" id="editItemPromoPrice" placeholder="Optional"></div>
                <div class="field"><label>Promo Start</label><input type="date" name="promo_start" id="editItemPromoStart"></div>
                <div class="field"><label>Promo End</label><input type="date" name="promo_end" id="editItemPromoEnd"></div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditItemModal()" style="border-radius:10px; padding:9px 18px; font-weight:700;">Cancel</button>
                <button class="btn btn-primary" type="submit" style="border-radius:10px; padding:9px 20px; font-weight:700;">
                    <i class="bi bi-check2-circle"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Add Category Modal -->
<div id="addCategoryModal" role="dialog" aria-modal="true" aria-label="Add category" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div class="menu-modal-dialog menu-modal-compact">
        <button type="button" onclick="closeCategoryModal()" aria-label="Close dialog" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        <h3 style="margin-top:0; margin-bottom:4px; font-size:1.25rem; font-weight:800; color:#1e1e1e;">
            <?= $isAdmin ? 'Add New Category' : 'Request New Category' ?>
        </h3>
        <p style="margin-bottom:18px; font-size:0.84rem; color:#64748b;">Organize your dishes into clear menu sections.</p>

        <form class="form" method="post" action="index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="category">
            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; font-size:0.86rem; color:#1e1e1e; margin-bottom:6px; display:block;">Category Name</label>
                <input type="text" name="category_name" required placeholder="e.g. Sizzling Specialties, Refreshments" style="width:100%; border:1px solid #d1d5db; border-radius:10px; padding:9px 12px; font-size:0.88rem; box-sizing:border-box;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCategoryModal()" style="border-radius:10px; padding:9px 18px; font-weight:700;">Cancel</button>
                <button class="btn btn-primary" type="submit" style="border-radius:10px; padding:9px 20px; font-weight:700;">
                    <i class="bi <?= $isAdmin ? 'bi-folder-plus' : 'bi-send-fill' ?>"></i> <?= $isAdmin ? 'Save Category' : 'Submit Request' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddItemModal() {
    var m = document.getElementById('addMenuItemModal');
    if (m) m.style.display = 'flex';
}
function closeAddItemModal() {
    var m = document.getElementById('addMenuItemModal');
    if (m) m.style.display = 'none';
}
function openEditItemModal(data) {
    if (!data) return;
    document.getElementById('editItemId').value = data.id || '';
    document.getElementById('editItemName').value = data.item_name || '';
    document.getElementById('editItemCategory').value = data.category_id || '';
    document.getElementById('editItemPrice').value = data.price !== undefined ? data.price : '';
    document.getElementById('editItemDesc').value = data.description || '';
    document.getElementById('editItemAvailability').value = data.availability || 'Available';
    document.getElementById('editItemPromoPrice').value = data.promo_price || '';
    document.getElementById('editItemPromoStart').value = data.promo_start || '';
    document.getElementById('editItemPromoEnd').value = data.promo_end || '';

    var removeBox = document.getElementById('editItemRemoveImgBox');
    var removeInput = document.getElementById('editItemRemoveImgInput');
    if (removeInput) removeInput.checked = false;

    var preview = document.getElementById('editMenuImagePreview');
    var fileName = document.getElementById('editMenuImageFileName');
    var fileInput = document.getElementById('editMenuImageInput');
    if (fileInput) fileInput.value = '';
    if (fileName) fileName.textContent = 'No file chosen';

    if (data.image_url) {
        preview.src = data.image_url;
        preview.style.display = 'block';
        if (removeBox) removeBox.style.display = 'block';
    } else {
        preview.src = '';
        preview.style.display = 'none';
        if (removeBox) removeBox.style.display = 'none';
    }

    var m = document.getElementById('editMenuItemModal');
    if (m) m.style.display = 'flex';
}
function closeEditItemModal() {
    var m = document.getElementById('editMenuItemModal');
    if (m) m.style.display = 'none';
}
function previewEditMenuImage(input) {
    var preview = document.getElementById('editMenuImagePreview');
    var textSpan = document.getElementById('editMenuImageFileName');
    if (input.files && input.files[0]) {
        var file = input.files[0];
        if (textSpan) textSpan.textContent = file.name;
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
}
function openCategoryModal() {
    var m = document.getElementById('addCategoryModal');
    if (m) m.style.display = 'flex';
}
function closeCategoryModal() {
    var m = document.getElementById('addCategoryModal');
    if (m) m.style.display = 'none';
}
function filterCategoryCards(query) {
    query = (query || '').toLowerCase().trim();
    var entries = document.querySelectorAll('.cat-list-entry');
    entries.forEach(function(el) {
        var name = el.getAttribute('data-name') || '';
        if (!query || name.indexOf(query) !== -1) {
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    });
}
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRICE ADJUSTMENT MODALS & JAVASCRIPT
     ═══════════════════════════════════════════════════════════════════════════ -->

<!-- 1. Single Item Price Modal -->
<div id="itemPriceModal" role="dialog" aria-modal="true" aria-labelledby="itemPriceTitle" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div class="menu-modal-dialog menu-modal-bulk">
        <button type="button" onclick="closeItemPriceModal()" aria-label="Close price dialog" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--muted);">&times;</button>
        <h3 id="itemPriceTitle" style="margin-top:0; margin-bottom:6px; font-size:1.2rem; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-tag-fill" style="color:#f59e0b;"></i> Update Menu Price
        </h3>
        <p class="muted" style="margin-bottom:18px; font-size:0.85rem;" id="itemPriceModalSubtitle">Update base price for item.</p>

        <form id="itemPriceForm" onsubmit="submitItemPrice(event)">
            <input type="hidden" id="priceItemId">
            
            <div class="field" style="margin-bottom:14px;">
                <label for="priceCurrentDisplay">Current Price</label>
                <input type="text" id="priceCurrentDisplay" readonly style="background:var(--soft); font-weight:700; color:var(--muted);">
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label for="priceNewInput">New Price (₱) <span style="color:var(--danger)">*</span></label>
                <input type="number" step="0.01" min="0" id="priceNewInput" required placeholder="e.g. 150.00" style="font-size:1.05rem; font-weight:700;">
            </div>

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">
                    When should this price apply?
                </label>
                <input type="hidden" id="priceEffectiveInput"><div class="menu-scheduler" data-schedule-input="priceEffectiveInput"></div>
                <small class="muted" style="display:block; margin-top:4px; font-size:0.75rem;">
                    Apply the new price now, or schedule it for later. Times use Philippine time.
                </small>
            </div>

            <div id="pendingPriceNotice" style="display:none; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:10px; margin-bottom:16px; font-size:0.82rem; color:#92400e;">
                <div style="font-weight:700; margin-bottom:2px;"><i class="bi bi-info-circle"></i> Currently Scheduled:</div>
                <div id="pendingPriceNoticeText"></div>
                <button type="button" onclick="cancelPendingPrice()" style="margin-top:6px; background:#fff; border:1px solid #b45309; color:#b45309; padding:4px 8px; border-radius:6px; font-size:0.75rem; font-weight:700; cursor:pointer;">
                    Cancel Scheduled Update
                </button>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeItemPriceModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveItemPrice" >
                    Save Price
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Bulk Price Adjustment Modal -->
<div id="bulkPriceModal" role="dialog" aria-modal="true" aria-label="Bulk price adjustment" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div class="menu-modal-dialog menu-modal-bulk">
        <button type="button" onclick="closeBulkPriceModal()" aria-label="Close dialog" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--muted);">&times;</button>
        <h3 style="margin-top:0; margin-bottom:6px; font-size:1.2rem; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-tags-fill" style="color:#f59e0b;"></i> Bulk Price Adjustment
        </h3>
        <p class="muted" style="margin-bottom:18px; font-size:0.85rem;">Adjust base prices across an entire category or all menu items.</p>

        <form id="bulkPriceForm" onsubmit="submitBulkPrice(event)">
            <div class="field" style="margin-bottom:14px;">
                <label for="bulkCategorySelect">Apply to</label>
                <select id="bulkCategorySelect">
                    <option value="all">All Menu Items</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']) ?>">Category: <?= e($menuCategoryLabel($cat['category_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-2" style="margin-bottom:14px;">
                <div class="field">
                    <label for="bulkOperation">Operation</label>
                    <select id="bulkOperation">
                        <option value="increase">Increase (+)</option>
                        <option value="decrease">Decrease (-)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="bulkAdjustType">Adjustment Type</label>
                    <select id="bulkAdjustType">
                        <option value="fixed">Fixed Amount (₱)</option>
                        <option value="percent">Percentage (%)</option>
                    </select>
                </div>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label for="bulkAdjustValue"><span id="bulkValueLabel">Amount (₱)</span> <span style="color:var(--danger)">*</span></label>
                <input type="number" step="0.01" min="0.01" id="bulkAdjustValue" required placeholder="e.g. 10.00" aria-describedby="bulkValueHint" style="font-size:1.05rem; font-weight:700;">
            </div>

            <p class="menu-form-hint" id="bulkValueHint">Enter the peso amount to adjust each selected item.</p>
            <div class="field" style="margin-bottom:16px;">
                <label for="bulkEffectiveInput">
                    When should these prices apply?
                </label>
                <input type="hidden" id="bulkEffectiveInput"><div class="menu-scheduler" data-schedule-input="bulkEffectiveInput"></div>
                <small class="muted" style="display:block; margin-top:4px; font-size:0.75rem;">
                    Apply now, or choose a future date and time. Times use Philippine time.
                </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeBulkPriceModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveBulkPrice">
                    Apply Bulk Adjustments
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openItemPriceModal(id, name, price, pendingPrice, pendingEffective) {
    document.getElementById('priceItemId').value = id;
    document.getElementById('itemPriceModalSubtitle').textContent = name;
    document.getElementById('priceCurrentDisplay').value = '₱' + Number(price).toFixed(2);
    document.getElementById('priceNewInput').value = pendingPrice ? Number(pendingPrice).toFixed(2) : Number(price).toFixed(2);
    document.getElementById('priceEffectiveInput').value = pendingEffective || '';
    menuSchedulers.priceEffectiveInput.refresh();

    var notice = document.getElementById('pendingPriceNotice');
    var noticeText = document.getElementById('pendingPriceNoticeText');
    if (pendingPrice && pendingEffective) {
        noticeText.textContent = '₱' + Number(pendingPrice).toFixed(2) + ' effective on ' + pendingEffective.replace('T', ' ');
        notice.style.display = 'block';
    } else {
        notice.style.display = 'none';
    }

    document.getElementById('itemPriceModal').style.display = 'flex';
}

function closeItemPriceModal() {
    document.getElementById('itemPriceModal').style.display = 'none';
}

function openBulkPriceModal() {
    document.getElementById('bulkPriceModal').style.display = 'flex';
}

function closeBulkPriceModal() {
    document.getElementById('bulkPriceModal').style.display = 'none';
}

async function submitItemPrice(e) {
    e.preventDefault();
    if (!validateMenuSchedule('priceEffectiveInput')) return;
    var btn = document.getElementById('btnSaveItemPrice');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var payload = {
        action: 'single',
        item_id: document.getElementById('priceItemId').value,
        new_price: document.getElementById('priceNewInput').value,
        effective_at: document.getElementById('priceEffectiveInput').value,
        csrf_token: KK_CSRF_TOKEN
    };

    try {
        var res = await fetch('<?= BASE_URL ?>/api/update_price.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': KK_CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (data.ok) {
            if (window.kkNotify) window.kkNotify(data.message || 'Price updated successfully.', 'success', { title: 'Price Updated' });
            setTimeout(() => window.location.reload(), 650);
        } else {
            if (window.kkNotify) window.kkNotify(data.message || 'Failed to update price.', 'error', { title: 'Update Failed' });
            btn.disabled = false;
            btn.textContent = 'Save Price';
        }
    } catch (err) {
        if (window.kkNotify) window.kkNotify('Network error while saving price.', 'error', { title: 'Network Error' });
        btn.disabled = false;
        btn.textContent = 'Save Price';
    }
}

async function cancelPendingPrice() {
    if (window.kkConfirm) {
        const confirmed = await window.kkConfirm({
            title: 'Cancel Scheduled Price Update?',
            message: 'Are you sure you want to cancel this scheduled price update? The current item price will remain unchanged.',
            confirmText: 'Cancel Scheduled Update',
            variant: 'danger'
        });
        if (!confirmed) return;
    }
    var itemId = document.getElementById('priceItemId').value;
    var payload = {
        action: 'cancel_scheduled',
        item_id: itemId,
        csrf_token: KK_CSRF_TOKEN
    };
    try {
        var res = await fetch('<?= BASE_URL ?>/api/update_price.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': KK_CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (data.ok) {
            if (window.kkNotify) window.kkNotify(data.message || 'Scheduled price update cancelled.', 'success', { title: 'Schedule Cancelled' });
            setTimeout(() => window.location.reload(), 650);
        } else {
            if (window.kkNotify) window.kkNotify(data.message || 'Failed to cancel scheduled update.', 'error', { title: 'Error' });
        }
    } catch (_) {
        if (window.kkNotify) window.kkNotify('Network error.', 'error', { title: 'Network Error' });
    }
}

async function submitBulkPrice(e) {
    e.preventDefault();
    if (!validateMenuSchedule('bulkEffectiveInput')) return;
    var btn = document.getElementById('btnSaveBulkPrice');
    btn.disabled = true;
    btn.textContent = 'Applying…';

    var payload = {
        action: 'bulk',
        category_id: document.getElementById('bulkCategorySelect').value,
        operation: document.getElementById('bulkOperation').value,
        adjust_type: document.getElementById('bulkAdjustType').value,
        adjust_value: document.getElementById('bulkAdjustValue').value,
        effective_at: document.getElementById('bulkEffectiveInput').value,
        csrf_token: KK_CSRF_TOKEN
    };

    try {
        var res = await fetch('<?= BASE_URL ?>/api/update_price.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': KK_CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (data.ok) {
            if (window.kkNotify) window.kkNotify(data.message || 'Bulk price adjustments applied.', 'success', { title: 'Bulk Update Applied' });
            setTimeout(() => window.location.reload(), 650);
        } else {
            if (window.kkNotify) window.kkNotify(data.message || 'Bulk adjustment failed.', 'error', { title: 'Update Failed' });
            btn.disabled = false;
            btn.textContent = 'Apply Bulk Adjustments';
        }
    } catch (err) {
        if (window.kkNotify) window.kkNotify('Network error while submitting bulk adjustments.', 'error', { title: 'Network Error' });
        btn.disabled = false;
        btn.textContent = 'Apply Bulk Adjustments';
    }
}

document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-menu-confirm]');
    if (!button) return;
    event.preventDefault();
    if (button.disabled) return;
    const action = button.dataset.menuConfirm;
    const isRequest = action === 'request-archive';
    button.disabled = true;
    try {
        const accepted = await window.kkConfirm({
            title: isRequest ? 'Request menu archive?' : 'Archive menu item?',
            details: [{ label: 'Menu item', value: button.dataset.menuName }],
            message: isRequest
                ? 'Send this item to the administrator for archive approval. It will remain unchanged until the request is approved.'
                : 'This item will be hidden from ordering. Its sales history and linked records will be kept.',
            confirmText: isRequest ? 'Send Request' : 'Archive Item',
            cancelText: 'Keep Item',
            variant: 'primary'
        });
        if (accepted) {
            button.disabled = false;
            button.form.requestSubmit(button);
        }
    } finally {
        button.disabled = false;
    }
});
const bulkType = document.getElementById('bulkAdjustType');
if (bulkType) bulkType.addEventListener('change', function () {
    const percent = this.value === 'percent';
    document.getElementById('bulkValueLabel').textContent = percent ? 'Percentage (%)' : 'Amount (₱)';
    document.getElementById('bulkAdjustValue').placeholder = percent ? 'e.g. 5 for 5%' : 'e.g. 10.00';
    document.getElementById('bulkValueHint').textContent = percent ? 'Enter the percentage to adjust each selected item.' : 'Enter the peso amount to adjust each selected item.';
});

// One shared scheduler for single-item and bulk pricing; preserves the API's local datetime format.
const menuSchedulers = {};
function menuManilaNow() {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
    const value = Object.fromEntries(parts.map(p => [p.type, p.value]));
    return value.year + '-' + value.month + '-' + value.day + 'T' + value.hour + ':' + value.minute;
}
function menuDateKey(year, month, day) {
    return year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
}
document.querySelectorAll('[data-schedule-input]').forEach(host => {
    const input = document.getElementById(host.dataset.scheduleInput);
    host.innerHTML = '<div class="schedule-modes"><button type="button" data-mode="now">Apply now</button><button type="button" data-mode="later">Schedule for later</button></div><div class="schedule-panel" hidden><div class="schedule-month"><button type="button" data-month="-1" aria-label="Previous month">‹</button><strong aria-live="polite"></strong><button type="button" data-month="1" aria-label="Next month">›</button></div><div class="schedule-week" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div><div class="schedule-days" role="group" aria-label="Choose a date"></div><div class="schedule-time"><label>Hour<select data-time="hour" aria-label="Hour"></select></label><label>Minute<select data-time="minute" aria-label="Minute"></select></label><label>Period<select data-time="period" aria-label="AM or PM"><option>AM</option><option>PM</option></select></label></div></div><p class="schedule-summary" aria-live="polite"></p>';
    const panel = host.querySelector('.schedule-panel');
    const hour = host.querySelector('[data-time="hour"]');
    const minute = host.querySelector('[data-time="minute"]');
    const period = host.querySelector('[data-time="period"]');
    for (let n = 1; n <= 12; n++) hour.add(new Option(String(n).padStart(2, '0'), String(n)));
    for (let n = 0; n < 60; n++) minute.add(new Option(String(n).padStart(2, '0'), String(n).padStart(2, '0')));
    let selected = '', year, month;
    function summary() {
        host.querySelector('.schedule-summary').textContent = input.value
            ? new Date(input.value + ':00+08:00').toLocaleString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) + ' · Philippine time'
            : 'The price change will apply when you save.';
    }
    function commit() {
        const h = Number(hour.value) % 12 + (period.value === 'PM' ? 12 : 0);
        input.value = selected + 'T' + String(h).padStart(2, '0') + ':' + minute.value;
        summary();
    }
    function renderDays() {
        host.querySelector('.schedule-month strong').textContent = new Date(year, month, 1).toLocaleDateString('en', { month: 'long', year: 'numeric' });
        const days = host.querySelector('.schedule-days');
        days.replaceChildren();
        for (let n = 0; n < new Date(year, month, 1).getDay(); n++) days.append(document.createElement('span'));
        for (let n = 1; n <= new Date(year, month + 1, 0).getDate(); n++) {
            const date = menuDateKey(year, month, n), button = document.createElement('button');
            button.type = 'button'; button.textContent = n;
            button.setAttribute('aria-label', new Date(year, month, n).toLocaleDateString('en', {month:'long', day:'numeric', year:'numeric'}));
            button.setAttribute('aria-pressed', String(date === selected));
            button.disabled = date < menuManilaNow().slice(0, 10);
            button.addEventListener('click', () => { selected = date; commit(); renderDays(); });
            days.append(button);
        }
    }
    function refresh() {
        const value = input.value || menuManilaNow();
        selected = value.slice(0,10); year = Number(value.slice(0,4)); month = Number(value.slice(5,7))-1;
        const h = Number(value.slice(11,13)); hour.value = String(h % 12 || 12); minute.value = value.slice(14,16); period.value = h >= 12 ? 'PM' : 'AM';
        panel.hidden = !input.value;
        host.querySelector('[data-mode="now"]').setAttribute('aria-pressed', String(!input.value));
        host.querySelector('[data-mode="later"]').setAttribute('aria-pressed', String(!!input.value));
        renderDays(); summary();
    }
    host.querySelector('[data-mode="now"]').addEventListener('click', () => { input.value = ''; refresh(); });
    host.querySelector('[data-mode="later"]').addEventListener('click', () => {
        if (!input.value) {
            const later = new Date(menuManilaNow() + ':00Z'); later.setUTCHours(later.getUTCHours() + 1);
            input.value = later.toISOString().slice(0,16);
        }
        refresh();
    });
    host.querySelectorAll('[data-month]').forEach(button => button.addEventListener('click', () => {
        const next = new Date(year, month + Number(button.dataset.month), 1); year = next.getFullYear(); month = next.getMonth(); renderDays();
    }));
    [hour, minute, period].forEach(select => select.addEventListener('change', commit));
    menuSchedulers[input.id] = { refresh };
    refresh();
});
function validateMenuSchedule(id) {
    const value = document.getElementById(id).value;
    if (value && value <= menuManilaNow()) {
        window.kkNotify('Choose a future date and time, or select Apply now.', 'error', { title: 'Check scheduled time' });
        return false;
    }
    return true;
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
