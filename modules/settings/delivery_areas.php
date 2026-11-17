<?php
/**
 * Kenji's Kitchen - Delivery Areas Management
 * Accessible to Admin and Manager
 * Part 1: Manage, verify, edit, bulk-import delivery coverage.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/functions.php';

require_role('admin');

$page = 'delivery_areas';
$title = 'Delivery Areas (Lagro)';
$heading = 'Delivery Areas';
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_msg_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRole = $currentUser['role_key'] ?? 'admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function verify_csrf(): void {
    if (
        empty($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $barangay = trim((string)($_POST['barangay'] ?? 'Greater Lagro'));
        $subdivision = trim((string)($_POST['subdivision'] ?? ''));
        $street = trim((string)($_POST['street_name'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($street === '') {
            $_SESSION['flash_msg'] = 'Street name is required.';
            $_SESSION['flash_msg_type'] = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO delivery_areas (barangay, subdivision, street_name, is_verified, note) VALUES (?, ?, ?, 0, ?)");
            $stmt->execute([$barangay, $subdivision ?: null, $street, $note ?: null]);
            $newId = $conn->lastInsertId();
            log_audit($conn, 'delivery_area_add', 'delivery_area', (string)$newId, "Added street: $street, $barangay (Unverified)");
            $_SESSION['flash_msg'] = 'Delivery area added successfully as UNVERIFIED.';
            $_SESSION['flash_msg_type'] = 'success';
        }
        redirect('/modules/settings/delivery_areas.php');
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $barangay = trim((string)($_POST['barangay'] ?? 'Greater Lagro'));
        $subdivision = trim((string)($_POST['subdivision'] ?? ''));
        $street = trim((string)($_POST['street_name'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($id > 0 && $street !== '') {
            // Requirement 1.3: Editing a verified entry resets it to unverified until checked again.
            $stmt = $conn->prepare("UPDATE delivery_areas SET barangay = ?, subdivision = ?, street_name = ?, note = ?, is_verified = 0, verified_by = NULL, verified_at = NULL WHERE id = ?");
            $stmt->execute([$barangay, $subdivision ?: null, $street, $note ?: null, $id]);
            log_audit($conn, 'delivery_area_edit', 'delivery_area', (string)$id, "Edited street: $street (Reset to Unverified)");
            $_SESSION['flash_msg'] = 'Area updated and reset to UNVERIFIED for review.';
            $_SESSION['flash_msg_type'] = 'success';
        }
        redirect('/modules/settings/delivery_areas.php');
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn->prepare("UPDATE delivery_areas SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            log_audit($conn, 'delivery_area_toggle_active', 'delivery_area', (string)$id, "Toggled active state");
            $_SESSION['flash_msg'] = 'Area active status updated.';
            $_SESSION['flash_msg_type'] = 'success';
        }
        redirect('/modules/settings/delivery_areas.php');
    }

    if ($action === 'verify') {
        $id = (int)($_POST['id'] ?? 0);
        $note = trim((string)($_POST['verify_note'] ?? ''));
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE delivery_areas SET is_verified = 1, verified_by = ?, verified_at = NOW(), note = CASE WHEN ? <> '' THEN ? ELSE note END WHERE id = ?");
            $stmt->execute([$userId, $note, $note, $id]);
            log_audit($conn, 'delivery_area_verify', 'delivery_area', (string)$id, "Verified by {$currentUser['username']}");
            $_SESSION['flash_msg'] = 'Delivery area marked as VERIFIED.';
            $_SESSION['flash_msg_type'] = 'success';
        }
        redirect('/modules/settings/delivery_areas.php');
    }

    if ($action === 'verify_selected') {
        $ids = $_POST['selected_ids'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $intIds = array_map('intval', $ids);
            $inClause = implode(',', $intIds);
            $conn->query("UPDATE delivery_areas SET is_verified = 1, verified_by = $userId, verified_at = NOW() WHERE id IN ($inClause)");
            log_audit($conn, 'delivery_area_bulk_verify', 'delivery_area', implode(',', $intIds), "Bulk verified " . count($intIds) . " areas by {$currentUser['username']}");
            $_SESSION['flash_msg'] = count($intIds) . ' areas marked as VERIFIED.';
            $_SESSION['flash_msg_type'] = 'success';
        }
        redirect('/modules/settings/delivery_areas.php');
    }

    if ($action === 'bulk_import') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $imported = 0;
            if ($file) {
                // Check header
                $header = fgetcsv($file);
                while (($row = fgetcsv($file)) !== false) {
                    if (empty($row[0])) continue;
                    // Format: Barangay, Subdivision, Street, Note
                    $b = trim($row[0] ?? 'Greater Lagro');
                    $s = trim($row[1] ?? '');
                    $st = trim($row[2] ?? '');
                    $n = trim($row[3] ?? 'CSV Imported');
                    if ($st === '') {
                        $st = $b;
                        $b = 'Greater Lagro';
                    }
                    $stmt = $conn->prepare("INSERT INTO delivery_areas (barangay, subdivision, street_name, is_verified, note) VALUES (?, ?, ?, 0, ?)");
                    $stmt->execute([$b, $s ?: null, $st, $n]);
                    $imported++;
                }
                fclose($file);
                log_audit($conn, 'delivery_area_import', 'delivery_area', null, "Imported $imported areas from CSV");
                $_SESSION['flash_msg'] = "Successfully imported $imported delivery areas as UNVERIFIED.";
                $_SESSION['flash_msg_type'] = 'success';
            }
        }
        redirect('/modules/settings/delivery_areas.php');
    }

    if ($action === 'update_unverified_setting') {
        // Admin only (Requirement 1.4)
        if ($userRole === 'admin') {
            $val = !empty($_POST['show_unverified_areas_to_customers']) ? '1' : '0';
            $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'show_unverified_areas_to_customers'")->execute([$val]);
            log_audit($conn, 'setting_update', 'system_settings', 'show_unverified_areas_to_customers', "Updated to $val");
            $_SESSION['flash_msg'] = 'Checkout area visibility setting updated.';
            $_SESSION['flash_msg_type'] = 'success';
        }
        redirect('/modules/settings/delivery_areas.php');
    }
}

// Queries
$unverifiedCount = (int)$conn->query("SELECT COUNT(*) FROM delivery_areas WHERE is_verified = 0 AND is_active = 1")->fetchColumn();
$verifiedCount   = (int)$conn->query("SELECT COUNT(*) FROM delivery_areas WHERE is_verified = 1 AND is_active = 1")->fetchColumn();
$totalCount      = (int)$conn->query("SELECT COUNT(*) FROM delivery_areas")->fetchColumn();

// Settings
$showUnverified = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'show_unverified_areas_to_customers'")->fetchColumn() ?: 0);

// Filters
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT da.*, u.full_name AS verifier_name FROM delivery_areas da LEFT JOIN users u ON u.id = da.verified_by WHERE 1=1";
$params = [];

if ($filter === 'unverified') {
    $sql .= " AND da.is_verified = 0";
} elseif ($filter === 'verified') {
    $sql .= " AND da.is_verified = 1";
} elseif ($filter === 'inactive') {
    $sql .= " AND da.is_active = 0";
}

if ($search !== '') {
    $sql .= " AND (da.street_name LIKE ? OR da.subdivision LIKE ? OR da.barangay LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY da.is_verified ASC, da.subdivision ASC, da.street_name ASC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$areas = $stmt->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>

<div class="page-head" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
        <h1 style="display:flex;align-items:center;gap:10px;">
            <i class="bi bi-geo-alt-fill" style="color:var(--primary,#F2C12E);"></i> Delivery Areas (Lagro Core)
        </h1>
        <p class="muted">Manage and verify eligible streets for in-house staff delivery (₱15 fee or Free First Order).</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-primary" onclick="openAddModal()"><i class="bi bi-plus-circle"></i> Add Area</button>
        <button class="btn btn-outline" onclick="openImportModal()"><i class="bi bi-upload"></i> Import CSV</button>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:16px;">
    <?= e($msg) ?>
</div>
<?php endif; ?>

<!-- Unverified Banner if unverified entries exist (Requirement 1.3) -->
<?php if ($unverifiedCount > 0): ?>
<div style="background:#fff3cd; border:1px solid #ffeeba; border-left:5px solid #f59e0b; padding:14px 18px; border-radius:10px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <strong style="color:#b45309; font-size:15px;"><i class="bi bi-exclamation-triangle-fill"></i> <?= $unverifiedCount ?> delivery areas still need verification!</strong>
        <p style="margin:4px 0 0 0; font-size:13px; color:#78350f;">
            Unverified entries cannot be used for Lagro staff delivery at checkout (unless the override setting below is enabled). Admin or Manager should review each street.
        </p>
    </div>
    <form method="POST" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="verify_selected">
        <?php foreach ($areas as $a): if (!$a['is_verified'] && $a['is_active']): ?>
            <input type="hidden" name="selected_ids[]" value="<?= $a['id'] ?>">
        <?php endif; endforeach; ?>
        <button type="submit" class="btn btn-primary" style="font-size:13px; padding:6px 14px;" onclick="return confirm('Verify all currently displayed unverified entries?');">
            <i class="bi bi-check2-all"></i> Verify Displayed
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Admin-Only Checkout Visibility Setting (Requirement 1.4) -->
<?php if ($userRole === 'admin'): ?>
<section class="card" style="margin-bottom:20px; padding:16px 20px;">
    <form method="POST" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin:0;">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update_unverified_setting">
        <div>
            <strong>Show unverified areas to customers at checkout</strong>
            <p class="muted" style="margin:2px 0 0; font-size:12px;">
                Default OFF: Customers only see verified Lagro streets. If ON, unverified streets can be chosen, with a warning that addresses may be outside our delivery boundary.
            </p>
        </div>
        <div style="display:flex; align-items:center; gap:12px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:700;">
                <input type="checkbox" name="show_unverified_areas_to_customers" value="1" <?= $showUnverified ? 'checked' : '' ?> onchange="this.form.submit()">
                <span><?= $showUnverified ? '<span style="color:#d97706;">ENABLED (Warning active)</span>' : '<span style="color:#16a34a;">DISABLED (Safe / Verified only)</span>' ?></span>
            </label>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- Filter & Search Toolbar -->
<div class="card" style="padding:14px 18px; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="?filter=all&search=<?= urlencode($search) ?>" class="btn <?= $filter === 'all' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:13px; padding:5px 12px;">All (<?= $totalCount ?>)</a>
            <a href="?filter=unverified&search=<?= urlencode($search) ?>" class="btn <?= $filter === 'unverified' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:13px; padding:5px 12px;">Unverified (<?= $unverifiedCount ?>)</a>
            <a href="?filter=verified&search=<?= urlencode($search) ?>" class="btn <?= $filter === 'verified' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:13px; padding:5px 12px;">Verified (<?= $verifiedCount ?>)</a>
            <a href="?filter=inactive&search=<?= urlencode($search) ?>" class="btn <?= $filter === 'inactive' ? 'btn-primary' : 'btn-outline' ?>" style="font-size:13px; padding:5px 12px;">Disabled</a>
        </div>
        <form method="GET" style="display:flex; gap:8px; margin:0;">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search street / area..." class="search-input" style="padding:6px 12px; font-size:13px; min-width:200px;">
            <button type="submit" class="btn btn-outline" style="padding:6px 12px; font-size:13px;"><i class="bi bi-search"></i></button>
        </form>
    </div>
</div>

<!-- Table of Areas -->
<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Street Name</th>
                    <th>Subdivision / Area</th>
                    <th>Barangay</th>
                    <th>Status</th>
                    <th>Verification</th>
                    <th>Note</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($areas)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:32px; color:var(--muted);">No delivery areas found matching filter.</td>
                </tr>
                <?php else: foreach ($areas as $i => $row): ?>
                <tr style="<?= !$row['is_active'] ? 'opacity:0.5;' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($row['street_name']) ?></strong></td>
                    <td><?= e($row['subdivision'] ?: '—') ?></td>
                    <td><?= e($row['barangay']) ?></td>
                    <td>
                        <form method="POST" style="margin:0; display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" class="badge <?= $row['is_active'] ? 'badge-success' : 'badge-danger' ?>" style="cursor:pointer; border:none;">
                                <?= $row['is_active'] ? 'Active' : 'Disabled' ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <?php if ($row['is_verified']): ?>
                            <span class="badge badge-success" title="Verified by <?= e($row['verifier_name'] ?? 'Staff') ?> on <?= e($row['verified_at']) ?>">
                                <i class="bi bi-check-circle-fill"></i> Verified
                            </span>
                            <div style="font-size:11px; color:var(--muted); margin-top:2px;">
                                By <?= e($row['verifier_name'] ?? 'Admin') ?> (<?= date('M d', strtotime($row['verified_at'])) ?>)
                            </div>
                        <?php else: ?>
                            <span class="badge badge-warning" style="background:#fef3c7; color:#92400e;">
                                <i class="bi bi-hourglass-split"></i> Unverified
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px; color:var(--muted); max-width:180px;"><?= e($row['note'] ?: '—') ?></td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex; gap:4px;">
                            <?php if (!$row['is_verified']): ?>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-outline" style="padding:4px 8px; font-size:12px; color:#15803d; border-color:#86efac;" title="Verify Street">
                                    <i class="bi bi-check-lg"></i> Verify
                                </button>
                            </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline" style="padding:4px 8px; font-size:12px;" onclick='openEditModal(<?= json_encode($row) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add Delivery Area -->
<div id="addModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:480px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;">Add Delivery Street / Area</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div style="margin-bottom:12px;">
                <label style="font-size:13px; font-weight:700;">Barangay</label>
                <input type="text" name="barangay" value="Greater Lagro" class="search-input" style="width:100%; padding:8px;" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:13px; font-weight:700;">Subdivision / Phase (Optional)</label>
                <input type="text" name="subdivision" placeholder="e.g. Lagro Core, Casa Milan, B-Streets" class="search-input" style="width:100%; padding:8px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:13px; font-weight:700;">Street / Area Name <span style="color:red;">*</span></label>
                <input type="text" name="street_name" placeholder="e.g. Misa de Gallo St" class="search-input" style="width:100%; padding:8px;" required>
            </div>
            <div style="margin-bottom:16px;">
                <label style="font-size:13px; font-weight:700;">Note / Boundary remarks</label>
                <input type="text" name="note" placeholder="e.g. Near gate 2" class="search-input" style="width:100%; padding:8px;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Area</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Delivery Area -->
<div id="editModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:480px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;">Edit Delivery Area</h3>
        <p style="font-size:12px; color:#b45309; background:#fef3c7; padding:6px 10px; border-radius:6px;">
            <i class="bi bi-info-circle"></i> Note: Editing any verified area will reset it to <strong>UNVERIFIED</strong>.
        </p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div style="margin-bottom:12px;">
                <label style="font-size:13px; font-weight:700;">Barangay</label>
                <input type="text" name="barangay" id="edit_barangay" class="search-input" style="width:100%; padding:8px;" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:13px; font-weight:700;">Subdivision / Phase</label>
                <input type="text" name="subdivision" id="edit_subdivision" class="search-input" style="width:100%; padding:8px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:13px; font-weight:700;">Street Name <span style="color:red;">*</span></label>
                <input type="text" name="street_name" id="edit_street_name" class="search-input" style="width:100%; padding:8px;" required>
            </div>
            <div style="margin-bottom:16px;">
                <label style="font-size:13px; font-weight:700;">Note</label>
                <input type="text" name="note" id="edit_note" class="search-input" style="width:100%; padding:8px;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update &amp; Re-verify</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: CSV Import -->
<div id="importModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:480px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;">Bulk Import Streets (CSV)</h3>
        <p class="muted" style="font-size:13px;">Upload a CSV file with columns: <code>Barangay, Subdivision, Street, Note</code>. All entries will be created as <strong>UNVERIFIED</strong>.</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="bulk_import">
            <div style="margin-bottom:16px;">
                <input type="file" name="csv_file" accept=".csv" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('importModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Import CSV</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
}
function openImportModal() {
    document.getElementById('importModal').style.display = 'flex';
}
function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_barangay').value = data.barangay || 'Greater Lagro';
    document.getElementById('edit_subdivision').value = data.subdivision || '';
    document.getElementById('edit_street_name').value = data.street_name || '';
    document.getElementById('edit_note').value = data.note || '';
    document.getElementById('editModal').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
