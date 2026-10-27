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
        if (!$catChk->fetch()) {
            $_SESSION['flash_msg'] = 'Please select a valid active category.';
            header('Location: ' . ($showArchived ? 'index.php?archived=1' : 'index.php'));
            exit;
        }

        $image = upload_menu_image($_FILES['image'] ?? []);

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

    if ($action === 'update_image') {
        if (!$isAdmin) {
            $_SESSION['flash_msg'] = 'Only Admin can update menu images.';
            header('Location: index.php');
            exit;
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0 && !empty($_FILES['image']['name'])) {
            $image = upload_menu_image($_FILES['image']);
            if ($image) {
                $stmt = $conn->prepare("UPDATE menu_items SET image = ? WHERE id = ?");
                $stmt->execute([$image, $itemId]);
                $_SESSION['flash_msg'] = 'Photo updated successfully.';
            } else {
                $_SESSION['flash_msg'] = 'Failed to upload photo. Please ensure it is a valid JPG/PNG/WebP under 3 MB.';
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
                    $_SESSION['flash_msg'] = 'Cannot delete category "' . $cat['category_name'] . '" because it contains ' . $itemCount . ' menu item(s). Reassign or delete them first.';
                } else {
                    $reqCountStmt = $conn->prepare("SELECT COUNT(*) FROM approval_requests WHERE category_id = ?");
                    $reqCountStmt->execute([$id]);
                    $reqCount = (int)$reqCountStmt->fetchColumn();

                    if ($reqCount > 0) {
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
                        $_SESSION['flash_msg'] = 'Category "' . $cat['category_name'] . '" has existing approval history, so it was safely deactivated instead of permanently deleted.';
                    } else {
                        $delStmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                        $delStmt->execute([$id]);
                        if ($delStmt->rowCount() !== 1) {
                            throw new RuntimeException('Category could not be deleted.');
                        }

                        $conn->commit();
                        $_SESSION['flash_msg'] = 'Category "' . $cat['category_name'] . '" deleted successfully.';
                    }
                }
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['flash_msg'] = 'Unable to delete category: ' . $e->getMessage();
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
                $_SESSION['flash_msg'] = 'Cannot delete category "' . $cat['category_name'] . '" because it contains ' . $itemCount . ' menu item(s). Reassign or delete them first.';
            } else {
                $pendingChk = $conn->prepare("SELECT id FROM approval_requests WHERE request_type = 'Delete Category' AND category_id = ? AND status = 'Pending' LIMIT 1");
                $pendingChk->execute([$id]);
                if ($pendingChk->fetch()) {
                    $_SESSION['flash_msg'] = 'A deletion request for category "' . $cat['category_name'] . '" is already pending Admin approval.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO approval_requests (request_type, category_id, remarks, payload, requested_by) VALUES ('Delete Category', ?, ?, ?, ?)");
                    $stmt->execute([
                        $id,
                        'Delete empty category: ' . $cat['category_name'],
                        json_encode(['category_name' => $cat['category_name']]),
                        $_SESSION['u_id']
                    ]);
                    $_SESSION['flash_msg'] = 'Category deletion request for "' . $cat['category_name'] . '" submitted for Admin approval.';
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
                $_SESSION['flash_msg'] = 'Cannot delete "' . $item['item_name'] . '" while it still has ' . (int) $item['stock'] . ' pcs in stock. Use Inventory → Stock Out first.';
            } else {
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
                $refStmt->execute([$id, $id, $id, $id, $id, $id, $id]);
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
                    $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" has existing history or operational references, so it was safely archived instead of permanently deleted.';
                } else {
                    $delStmt = $conn->prepare("DELETE FROM menu_items WHERE id = ? AND stock <= 0");
                    $delStmt->execute([$id]);
                    if ($delStmt->rowCount() !== 1) {
                        throw new RuntimeException('Menu item could not be deleted.');
                    }

                    $conn->commit();
                    if (!empty($item['image']) && file_exists(UPLOAD_PATH . $item['image'])) {
                        @unlink(UPLOAD_PATH . $item['image']);
                    }
                    $_SESSION['flash_msg'] = 'Menu item "' . $item['item_name'] . '" permanently deleted.';
                }
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['flash_msg'] = 'Unable to delete menu item: ' . $e->getMessage();
        }
    } elseif ($isInventory) {
        $stmt = $conn->prepare("SELECT id, item_name, stock FROM menu_items WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) {
            $_SESSION['flash_msg'] = 'Menu item not found.';
        } elseif ((int) $item['stock'] > 0) {
            $_SESSION['flash_msg'] = 'Cannot request deletion for "' . $item['item_name'] . '" while it still has ' . (int) $item['stock'] . ' pcs in stock. Use Inventory → Stock Out first.';
        } else {
            $pendingChk = $conn->prepare("SELECT id, request_type FROM approval_requests WHERE menu_item_id = ? AND status = 'Pending' LIMIT 1");
            $pendingChk->execute([$id]);
            $existingPending = $pendingChk->fetch();
            if ($existingPending) {
                if ($existingPending['request_type'] === 'Delete Item') {
                    $_SESSION['flash_msg'] = 'A deletion request for "' . $item['item_name'] . '" is already pending Admin approval.';
                } else {
                    $_SESSION['flash_msg'] = 'Another request (' . $existingPending['request_type'] . ') for "' . $item['item_name'] . '" is already pending Admin approval.';
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO approval_requests (request_type, menu_item_id, remarks, requested_by) VALUES ('Delete Item', ?, ?, ?)");
                $stmt->execute([
                    $id,
                    'Delete menu item: ' . $item['item_name'],
                    $_SESSION['u_id']
                ]);
                $_SESSION['flash_msg'] = 'Deletion request for "' . $item['item_name'] . '" submitted for Admin approval.';
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

$categoriesWithCounts = $conn->query("
    SELECT c.*, COUNT(mi.id) AS item_count
    FROM categories c
    LEFT JOIN menu_items mi ON mi.category_id = c.id
    WHERE c.status = 'Active'
    GROUP BY c.id
    ORDER BY c.category_name ASC
")->fetchAll();

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

$itemsStmt = $conn->prepare("SELECT menu_items.*, categories.category_name FROM menu_items JOIN categories ON categories.id = menu_items.category_id $menuWhere ORDER BY menu_items.created_at DESC, menu_items.id DESC LIMIT :limit OFFSET :offset");
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
.menu-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}

.menu-card-title {
    min-width: 220px;
}

.menu-card-title h2 {
    margin: 0;
}

.menu-help-text {
    margin-top: 6px;
}

.menu-tools {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.menu-search-form {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

.menu-search-input {
    min-width: 250px;
    height: 44px;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 0 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--white);
}

.menu-search-input:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(242, 193, 46, .16);
}

.menu-search-input i {
    color: var(--muted);
}

.menu-search-input input {
    border: 0;
    outline: 0;
    width: 100%;
    min-width: 0;
}

.menu-pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    white-space: nowrap;
}

.menu-page-info {
    min-width: 96px;
    text-align: center;
}

@media (max-width: 1100px) {
    .menu-tools {
        width: 100%;
        justify-content: flex-start;
    }

    .menu-search-form {
        justify-content: flex-start;
    }
}

@media (max-width: 640px) {
    .menu-search-form,
    .menu-search-input,
    .menu-tools,
    .menu-pagination {
        width: 100%;
    }

    .menu-search-input {
        min-width: 0;
    }

    .menu-pagination {
        justify-content: space-between;
    }
}

.menu-item-cell {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 220px;
}

.menu-item-thumb {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    border-radius: 10px;
    object-fit: cover;
    border: 1px solid var(--border);
    background: var(--soft);
}

.menu-item-copy {
    min-width: 0;
}

.menu-item-copy strong {
    display: block;
}

.menu-item-copy .muted {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.menu-action-icon {
    min-width: 42px;
    height: 42px;
    padding: 0 14px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Icon-only variant (no text) keeps the square shape */
.menu-action-icon.menu-action-icon-only,
.menu-action-icon:empty,
.menu-action-icon:not(:has(span)):not(:has(a)) {
    width: 42px;
    padding: 0;
}

.menu-action-disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

/* POST action forms replace former GET links; keep them visually identical. */
.inline-action-form {
    display: inline-flex;
    margin: 0;
    flex-shrink: 0;
}

.inline-action-form > button.btn-secondary {
    color: inherit;
}

/* Ensure action table cells keep action buttons neatly aligned without clipping or wrapping */
.table-wrap td.actions {
    white-space: nowrap;
    align-items: center;
}

.custom-file-upload {
    display: flex;
    align-items: center;
    min-height: 50px;
    height: 50px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--white);
    padding: 0 10px;
    width: 100%;
    box-sizing: border-box;
    cursor: pointer;
    overflow: hidden;
    gap: 10px;
}

.custom-file-upload input[type="file"] {
    display: none;
}

.custom-file-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--soft, #f4f4f4);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--dark);
    white-space: nowrap;
    cursor: pointer;
    flex-shrink: 0;
}

.custom-file-text {
    font-size: 0.88rem;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

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

<?php if ($isAdmin || $isInventory): ?>
<div class="grid grid-2">
    <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <h2 style="margin:0;"><?= $isAdmin ? 'Add Menu Item' : 'Request New Menu Item' ?></h2>
            <?php if ($isInventory): ?>
                <span class="badge badge-warning" style="font-size:0.75rem;"><i class="bi bi-shield-lock"></i> Requires Admin Approval</span>
            <?php endif; ?>
        </div>
        <?php if ($isInventory): ?>
            <p class="muted" style="font-size:0.85rem; margin-top:0; margin-bottom:14px;">
                Adding new menu items as Inventory Staff will submit a request to the Admin for approval before appearing on POS and Online ordering.
            </p>
        <?php endif; ?>
        <form class="form" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="item">
            <div class="grid grid-2">
                <div class="field"><label>Item Name</label><input name="item_name" required></div>
                <div class="field"><label>Category</label><select name="category_id" required><?php foreach ($categories as $cat): ?><option value="<?= e($cat['id']) ?>"><?= e($cat['category_name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field"><label>Description</label><textarea name="description"></textarea></div>
            <div class="grid grid-2">
                <div class="field"><label>Price (₱)</label><input type="number" step="0.01" min="0" name="price" required></div>
                <div class="field">
                    <label>Item Photo <span class="muted" style="font-weight:400; font-size: 0.8rem;">(jpg/png/webp, max 3 MB)</span></label>
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <label class="custom-file-upload" for="menuImageInput">
                            <span class="custom-file-btn"><i class="bi bi-cloud-arrow-up"></i> Browse</span>
                            <span class="custom-file-text" id="menuImageFileName">No file chosen</span>
                            <input type="file" name="image" accept="image/*" id="menuImageInput" onchange="previewMenuImage(this)">
                        </label>
                        <img id="menuImagePreview" src="" alt="" style="display:none; width:48px; height:48px; object-fit:cover; border-radius:10px; border:1px solid var(--border); flex:0 0 48px;">
                    </div>
                    <small class="muted">Add a photo so customers can see what this item looks like on the online menu.</small>
                </div>
            </div>
            <div class="grid grid-3">
                <div class="field"><label>Promo Price</label><input type="number" step="0.01" name="promo_price"></div>
                <div class="field"><label>Promo Start</label><input type="date" name="promo_start"></div>
                <div class="field"><label>Promo End</label><input type="date" name="promo_end"></div>
            </div>
            <button class="btn btn-primary" type="submit">
                <i class="bi <?= $isAdmin ? 'bi-plus-circle' : 'bi-send-fill' ?>"></i> <?= $isAdmin ? 'Add Item' : 'Submit Item for Approval' ?>
            </button>
        </form>
    </section>

    <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <h2 style="margin:0;">Category Management</h2>
            <?php if ($isInventory): ?>
                <span class="badge badge-warning" style="font-size:0.75rem;"><i class="bi bi-shield-lock"></i> Requires Admin Approval</span>
            <?php endif; ?>
        </div>
        <?php if ($isInventory): ?>
            <p class="muted" style="font-size:0.85rem; margin-top:0; margin-bottom:14px;">
                Adding or deleting categories as Inventory Staff will submit a request to the Admin for approval.
            </p>
        <?php endif; ?>
        <form class="form" method="post" style="margin-bottom: 18px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="category">
            <div class="field">
                <label><?= $isAdmin ? 'Add New Category' : 'Request New Category' ?></label>
                <div style="display:flex; gap:8px;">
                    <input name="category_name" required placeholder="e.g. Desserts, Specials" style="flex:1;">
                    <button class="btn <?= $isAdmin ? 'btn-secondary' : 'btn-primary' ?>" type="submit">
                        <i class="bi <?= $isAdmin ? 'bi-folder-plus' : 'bi-send-fill' ?>"></i> <?= $isAdmin ? 'Save Category' : 'Request Category' ?>
                    </button>
                </div>
            </div>
        </form>

        <div style="margin-top: 12px;">
            <label style="font-weight:700; font-size:0.88rem; display:block; margin-bottom:6px;">Categories &amp; Actions</label>
            <div style="max-height: 230px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius); padding: 6px;">
                <table style="margin:0; font-size:0.88rem; width:100%;">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th style="text-align:center;">Items</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoriesWithCounts as $cat): ?>
                            <tr>
                                <td><strong><?= e($cat['category_name']) ?></strong></td>
                                <td style="text-align:center;">
                                    <span class="badge badge-muted"><?= (int)$cat['item_count'] ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ($isAdmin): ?>
                                        <?php if ((int)$cat['item_count'] === 0): ?>
                                            <form class="inline-action-form" method="post" action="<?= e($menuRedirect) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_id" value="<?= e($cat['id']) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="padding:4px 8px; font-size:0.75rem;" data-confirm="Delete category '<?= e(addslashes($cat['category_name'])) ?>'?"><i class="bi bi-trash"></i> Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted" style="font-size:0.75rem;" title="Cannot delete category containing menu items">In Use</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (!empty($pendingCategoryRequests[(int)$cat['id']])): ?>
                                            <span class="badge badge-warning" style="font-size:0.72rem;"><i class="bi bi-clock"></i> Pending Approval</span>
                                        <?php elseif ((int)$cat['item_count'] === 0): ?>
                                            <form class="inline-action-form" method="post" action="<?= e($menuRedirect) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_id" value="<?= e($cat['id']) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="padding:4px 8px; font-size:0.75rem;" data-confirm="Submit request to delete category '<?= e(addslashes($cat['category_name'])) ?>' for Admin approval?"><i class="bi bi-trash"></i> Request Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted" style="font-size:0.75rem;" title="Cannot delete category containing menu items">In Use</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<?php endif; ?>

<section class="card" style="margin-top:24px">
    <div class="menu-card-head">
        <div class="menu-card-title">
            <h2><?= $showArchived ? 'Archived Menu Items' : 'Menu Items' ?></h2>
            <p class="menu-help-text">
                <?php if ($menuSearch !== ''): ?>
                    Showing <?= count($items) ?> of <?= $totalMenuItems ?> matching <?= $showArchived ? 'archived ' : '' ?>menu items.
                <?php else: ?>
                    Showing <?= count($items) ?> of <?= $totalMenuItems ?> <?= $showArchived ? 'archived ' : '' ?>menu items.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($showArchived): ?>
            <a class="btn btn-secondary" href="index.php"><i class="bi bi-arrow-left"></i>Active Items</a>
        <?php elseif ($archivedCount > 0): ?>
            <a class="btn btn-secondary" href="?archived=1"><i class="bi bi-archive"></i>Archived Items (<?= $archivedCount ?>)</a>
        <?php endif; ?>
    </div>

    <div class="menu-tools">
        <form class="menu-search-form" method="get" action="index.php">
            <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
            <input type="hidden" name="menu_page" value="1">
            <div class="menu-search-input">
                <i class="bi bi-search"></i>
                <input type="search" name="menu_search" value="<?= e($menuSearch) ?>" placeholder="Search menu items" autocomplete="off">
            </div>
            <select name="category_id" class="form-select" onchange="this.form.submit()" style="padding: 9px 14px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.88rem; font-weight: 600; background: var(--white); color: var(--text);">
                <option value="all">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['id']) ?>" <?= $selectedCategory === (string)$cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Filter</button>
            <?php if ($isAdmin): ?>
                <button type="button" class="btn btn-warning" onclick="openBulkPriceModal()" style="display:inline-flex; align-items:center; gap:6px; background:#f59e0b; color:#111; font-weight:700; border:none;">
                    <i class="bi bi-tag-fill"></i>Bulk Adjust Prices
                </button>
            <?php endif; ?>
            <?php if ($menuSearch !== '' || ($selectedCategory !== '' && $selectedCategory !== 'all')): ?>
                <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => 1])) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <div class="menu-pagination">
            <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => $menuPage - 1, 'menu_search' => $menuSearch, 'category_id' => $selectedCategory])) ?>" <?= $menuPage <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>><i class="bi bi-chevron-left"></i>Previous</a>
            <strong class="menu-page-info">Page <?= $menuPage ?> of <?= $totalMenuPages ?></strong>
            <a class="btn btn-secondary" href="<?= e($buildQuery(['archived' => $showArchived ? 1 : '', 'menu_page' => $menuPage + 1, 'menu_search' => $menuSearch, 'category_id' => $selectedCategory])) ?>" <?= $menuPage >= $totalMenuPages ? 'style="pointer-events:none;opacity:0.5;"' : '' ?>>Next<i class="bi bi-chevron-right"></i></a>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:18px;">
        <table>
            <thead><tr><th>Item</th><th>Category</th><th>Price</th><th>Promo</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($items): ?>
                    <?php foreach ($items as $row): ?>
                    <tr>
                        <td>
                            <div class="menu-item-cell">
                                <?php if (!empty($row['image'])): ?>
                                    <img class="menu-item-thumb" src="<?= BASE_URL ?>/uploads/menu/<?= e($row['image']) ?>" alt="<?= e($row['item_name']) ?>">
                                <?php else: ?>
                                    <img class="menu-item-thumb" src="<?= BASE_URL ?>/assets/img/food-placeholder.svg" alt="">
                                <?php endif; ?>
                                <div class="menu-item-copy">
                                    <strong><?= e($row['item_name']) ?></strong>
                                    <span class="muted"><?= e($row['description']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($row['category_name']) ?></td>
                        <td>
                            <div><strong><?= money($row['price']) ?></strong></div>
                            <?php if (!empty($row['pending_price']) && !empty($row['pending_price_effective'])): ?>
                                <span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:0.75rem; display:inline-block; margin-top:4px; padding:2px 6px; border-radius:6px;" title="Scheduled price change">
                                    <i class="bi bi-clock-history"></i> Pending Price Increase: <?= money($row['pending_price']) ?> on <?= date('M j, Y g:i A', strtotime($row['pending_price_effective'])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['promo_price'] ? money($row['promo_price']) : '<span class="muted">None</span>' ?></td>
                        <td><?php if ($showArchived || !empty($row['is_archived'])): ?><span class="badge badge-muted">Archived</span><?php elseif ((int) $row['stock'] <= 0): ?><span class="badge badge-danger">Out of Stock</span><?php elseif (($row['availability'] ?? 'Available') !== 'Available'): ?><span class="badge badge-danger">Unavailable</span><?php elseif ((int) $row['stock'] <= 5): ?><span class="badge badge-warning">Low Stock</span><?php else: ?><span class="badge badge-success">Available</span><?php endif; ?></td>
                        <td class="actions">
                            <?php if ($showArchived): ?>
                                <?php if ($isAdmin): ?>
                                    <form class="inline-action-form" method="post" action="index.php?archived=1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn btn-secondary menu-action-icon" title="Restore to Active Menu" aria-label="Restore" data-confirm="Restore this item to the active customer menu?"><i class="bi bi-arrow-counterclockwise"></i><span>Restore</span></button>
                                    </form>
                                    <form class="inline-action-form" method="post" action="index.php?archived=1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn btn-danger menu-action-icon menu-action-icon-only" title="Permanently Delete Item" aria-label="Delete" data-confirm="Permanently delete this archived item?"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php elseif (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Unarchive'): ?>
                                    <span class="badge badge-warning"><i class="bi bi-clock"></i> Restore Pending</span>
                                <?php elseif (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Delete Item'): ?>
                                    <span class="badge badge-warning"><i class="bi bi-clock"></i> Delete Pending</span>
                                <?php elseif ($isInventory): ?>
                                    <form class="inline-action-form" method="post" action="index.php?archived=1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn btn-secondary menu-action-icon menu-action-icon-only" title="Request Restore" aria-label="Request Restore" data-confirm="Submit this unarchive request for Admin approval?"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    </form>
                                    <form class="inline-action-form" method="post" action="index.php?archived=1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn btn-danger menu-action-icon menu-action-icon-only" title="Request Delete" aria-label="Request Delete" data-confirm="Submit request to delete this menu item for Admin approval?"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-muted">Archived</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($isAdmin): ?>
                                    <button type="button" class="btn btn-secondary" onclick="openItemPriceModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['item_name'])) ?>', <?= (float)$row['price'] ?>, '<?= !empty($row['pending_price']) ? (float)$row['pending_price'] : '' ?>', '<?= !empty($row['pending_price_effective']) ? date('Y-m-d\TH:i', strtotime($row['pending_price_effective'])) : '' ?>')" title="Update or Schedule Base Price">
                                        <i class="bi bi-pencil-square"></i>Edit / Price
                                    </button>
                                    <form class="inline-action-form" method="post" action="index.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn btn-secondary"><i class="bi bi-arrow-repeat"></i>Availability</button>
                                    </form>
                                    <label class="btn btn-secondary" style="cursor: pointer; margin: 0;" title="Upload or Change Photo">
                                        <i class="bi bi-image"></i>Photo
                                        <input type="file" name="image" accept="image/*" style="display:none;" onchange="submitQuickImageUpload(this, <?= (int)$row['id'] ?>)">
                                    </label>
                                    <form class="inline-action-form" method="post" action="index.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn btn-secondary" title="Archive Menu Item" aria-label="Archive" data-confirm="Archive this item? It will be hidden from customer ordering."><i class="bi bi-archive"></i>Archive</button>
                                    </form>
                                    <form class="inline-action-form" method="post" action="index.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                        <button type="submit" class="btn btn-danger" title="Delete Menu Item" aria-label="Delete" data-confirm="Delete menu item '<?= e(addslashes($row['item_name'])) ?>'? (If ordered previously, it will be safely archived instead)"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <?php $stockValue = (int) $row['stock']; ?>
                                    <?php if (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Archive'): ?>
                                        <span class="badge badge-warning"><i class="bi bi-clock"></i> Archive Pending</span>
                                    <?php elseif (($pendingMenuRequests[(int) $row['id']] ?? '') === 'Delete Item'): ?>
                                        <span class="badge badge-warning"><i class="bi bi-clock"></i> Delete Pending</span>
                                    <?php else: ?>
                                        <?php if ($isInventory): ?>
                                            <form class="inline-action-form" method="post" action="index.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                                <button type="submit" class="btn btn-secondary" title="Request Archive" aria-label="Request Archive" data-confirm="Submit this archive request for Admin approval?"><i class="bi bi-archive"></i>Request Archive</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($isInventory): ?>
                                            <form class="inline-action-form" method="post" action="index.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="item_id" value="<?= e($row['id']) ?>">
                                                <button type="submit" class="btn btn-danger" title="Request Delete" aria-label="Request Delete" data-confirm="Submit request to delete this menu item for Admin approval?"><i class="bi bi-trash"></i>Request Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;"><?= $showArchived ? ($menuSearch !== '' ? 'No archived menu items match your search.' : 'No archived menu items.') : ($menuSearch !== '' ? 'No menu items match your search.' : 'No menu items found.') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRICE ADJUSTMENT MODALS & JAVASCRIPT
     ═══════════════════════════════════════════════════════════════════════════ -->

<!-- 1. Single Item Price Modal -->
<div id="itemPriceModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:var(--white); border-radius:14px; max-width:440px; width:100%; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.25); position:relative; border:1px solid var(--border);">
        <button type="button" onclick="closeItemPriceModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--muted);">&times;</button>
        <h3 style="margin-top:0; margin-bottom:6px; font-size:1.2rem; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-tag-fill" style="color:#f59e0b;"></i> Edit / Adjust Price
        </h3>
        <p class="muted" style="margin-bottom:18px; font-size:0.85rem;" id="itemPriceModalSubtitle">Update base price for item.</p>

        <form id="itemPriceForm" onsubmit="submitItemPrice(event)">
            <input type="hidden" id="priceItemId">

            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Current Price</label>
                <input type="text" id="priceCurrentDisplay" readonly style="background:var(--soft); font-weight:700; color:var(--muted);">
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">New Base Price (₱) <span style="color:var(--danger)">*</span></label>
                <input type="number" step="0.01" min="0" id="priceNewInput" required placeholder="e.g. 150.00" style="font-size:1.05rem; font-weight:700;">
            </div>

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">
                    Effective Date &amp; Time <span class="muted" style="font-weight:400; font-size:0.75rem;">(Optional — leave blank for immediate)</span>
                </label>
                <input type="datetime-local" id="priceEffectiveInput">
                <small class="muted" style="display:block; margin-top:4px; font-size:0.75rem;">
                    If set in the future, it will be saved as a pending scheduled price update and display a badge in the table.
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
                <button type="submit" class="btn btn-primary" id="btnSaveItemPrice" style="background:#f59e0b; border:none; color:#111; font-weight:700;">
                    Save Price
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Bulk Price Adjustment Modal -->
<div id="bulkPriceModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:var(--white); border-radius:14px; max-width:480px; width:100%; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.25); position:relative; border:1px solid var(--border);">
        <button type="button" onclick="closeBulkPriceModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--muted);">&times;</button>
        <h3 style="margin-top:0; margin-bottom:6px; font-size:1.2rem; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-tags-fill" style="color:#f59e0b;"></i> Bulk Adjust Prices
        </h3>
        <p class="muted" style="margin-bottom:18px; font-size:0.85rem;">Adjust base prices across an entire category or all menu items.</p>

        <form id="bulkPriceForm" onsubmit="submitBulkPrice(event)">
            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Target Selection</label>
                <select id="bulkCategorySelect" style="width:100%; padding:9px; border:1px solid var(--border); border-radius:var(--radius); font-weight:600;">
                    <option value="all">★ All Menu Items (Entire Menu)</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']) ?>">Category: <?= e($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-2" style="margin-bottom:14px;">
                <div class="field">
                    <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Operation</label>
                    <select id="bulkOperation" style="width:100%; padding:9px; border:1px solid var(--border); border-radius:var(--radius); font-weight:600;">
                        <option value="increase">Increase (+)</option>
                        <option value="decrease">Decrease (-)</option>
                    </select>
                </div>
                <div class="field">
                    <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Adjustment Type</label>
                    <select id="bulkAdjustType" style="width:100%; padding:9px; border:1px solid var(--border); border-radius:var(--radius); font-weight:600;">
                        <option value="fixed">Fixed Amount (₱)</option>
                        <option value="percent">Percentage (%)</option>
                    </select>
                </div>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">Adjustment Value <span style="color:var(--danger)">*</span></label>
                <input type="number" step="0.01" min="0.01" id="bulkAdjustValue" required placeholder="e.g. 10.00 or 5 for 5%" style="font-size:1.05rem; font-weight:700;">
            </div>

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; font-size:0.85rem; display:block; margin-bottom:4px;">
                    Effective Date &amp; Time <span class="muted" style="font-weight:400; font-size:0.75rem;">(Optional — leave blank for immediate)</span>
                </label>
                <input type="datetime-local" id="bulkEffectiveInput">
                <small class="muted" style="display:block; margin-top:4px; font-size:0.75rem;">
                    Future dates schedule price updates for automatic activation at that timestamp.
                </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeBulkPriceModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveBulkPrice" style="background:#f59e0b; border:none; color:#111; font-weight:700;">
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
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Failed to update price.');
            btn.disabled = false;
            btn.textContent = 'Save Price';
        }
    } catch (err) {
        alert('Network error while saving price.');
        btn.disabled = false;
        btn.textContent = 'Save Price';
    }
}

async function cancelPendingPrice() {
    if (!confirm('Cancel this scheduled price update?')) return;
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
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Failed to cancel scheduled update.');
        }
    } catch (_) {
        alert('Network error.');
    }
}

async function submitBulkPrice(e) {
    e.preventDefault();
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
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Bulk adjustment failed.');
            btn.disabled = false;
            btn.textContent = 'Apply Bulk Adjustments';
        }
    } catch (err) {
        alert('Network error while submitting bulk adjustments.');
        btn.disabled = false;
        btn.textContent = 'Apply Bulk Adjustments';
    }
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
