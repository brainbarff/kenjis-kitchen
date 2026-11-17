<?php
/**
 * Kenji's Kitchen - Online Customer Management (Admin Only)
 * Modules /admin/customers.php
 *
 * Requirements:
 * - Admin only
 * - Search by name, email, phone
 * - Filter Active vs Archived
 * - Detail view with profile, order history, and receipts
 * - Archive/Restore: cannot archive customer with active orders (Pending, Preparing, Ready)
 * - Delete: Two-step (must be archived first), typed confirmation ('DELETE' or email) + admin password verification
 *   Permanently erases PII, keeps orders and receipts relabeled as "Deleted customer", frees up email for re-registration
 * - Log all actions in audit_logs without logging erased PII
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page  = 'customers';
$title = 'Online Customers';
$heading = 'Online Customers';
$msg   = '';
$msg_type = 'success';

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

// Handle Customer Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $customerId = (int)($_POST['customer_id'] ?? 0);

    // 1. ARCHIVE CUSTOMER
    if ($action === 'archive') {
        // Check for active orders (Pending, Preparing, Ready)
        $activeOrdersStmt = $conn->prepare("
            SELECT COUNT(*) FROM orders 
            WHERE user_id = ? AND order_channel = 'ONLINE' AND status IN ('Pending', 'Preparing', 'Ready')
        ");
        $activeOrdersStmt->execute([$customerId]);
        $activeCount = (int)$activeOrdersStmt->fetchColumn();

        if ($activeCount > 0) {
            $msg = "Cannot archive customer: Customer currently has {$activeCount} active order(s) (Pending, Preparing, or Ready). Please fulfill or cancel the orders first.";
            $msg_type = 'error';
        } else {
            $conn->prepare("UPDATE users u JOIN roles r ON r.id = u.role_id SET u.is_archived = 1, u.status = 'Inactive', u.customer_status = 'Inactive', u.deleted_at = COALESCE(u.deleted_at, NOW()) WHERE u.id = ? AND r.role_name = 'Customer (Online)'")->execute([$customerId]);
            $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$customerId]);
            log_audit($conn, 'Customer Archived', 'customer', (string)$customerId, "Customer ID {$customerId} archived by admin.");
            $msg = "Customer has been archived. They cannot log in, place orders, or request password resets.";
            $msg_type = 'success';
        }
    }

    // 2. RESTORE CUSTOMER
    if ($action === 'restore') {
        $restoreStmt = $conn->prepare("UPDATE users u JOIN roles r ON r.id = u.role_id SET u.is_archived = 0, u.status = 'Active', u.customer_status = 'Active', u.deleted_at = NULL WHERE u.id = ? AND r.role_name = 'Customer (Online)' AND u.password != 'DELETED_ACCOUNT' AND (u.is_archived = 1 OR u.deleted_at IS NOT NULL)");
        $restoreStmt->execute([$customerId]);
        if ($restoreStmt->rowCount() === 1) {
        log_audit($conn, 'Customer Restored', 'customer', (string)$customerId, "Customer ID {$customerId} restored to Active by admin.");
        $msg = "Customer account has been restored and activated.";
        $msg_type = 'success';
        } else {
            $msg = 'This account cannot be restored: it is already active, permanently erased, or not a customer account.';
            $msg_type = 'error';
        }
    }

    // RESET COD BLOCK & FAILS (Part 6.5)
    if ($action === 'reset_cod') {
        $conn->prepare("UPDATE users u JOIN roles r ON r.id = u.role_id SET u.cod_blocked = 0, u.cod_fail_count = 0 WHERE u.id = ? AND r.role_name = 'Customer (Online)'")->execute([$customerId]);
        log_audit($conn, 'Customer COD Unblocked', 'customer', (string)$customerId, "Admin reset COD fail count and unblocked customer ID {$customerId}.");
        $msg = "Customer COD block has been lifted and fail count reset to 0.";
        $msg_type = 'success';
    }

    // 3. ARCHIVE-ONLY GUARD (Permanent deletion disabled)
    if ($action === 'delete') {
        $msg = 'Permanent customer deletion is disabled. Records and history are preserved and may only be archived.';
        $msg_type = 'error';
    }
}

// Search and Filter parameters
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'active'; // 'active' | 'archived' | 'all'
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;

$whereClauses = ["r.role_name = 'Customer (Online)'"];
$params = [];

if ($filter === 'active') {
    $whereClauses[] = "u.is_archived = 0 AND (u.deleted_at IS NULL)";
} elseif ($filter === 'archived') {
    $whereClauses[] = "(u.is_archived = 1 OR u.deleted_at IS NOT NULL) AND u.password != 'DELETED_ACCOUNT'";
}

if ($search !== '') {
    $whereClauses[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $whereClauses);

$customers = $conn->prepare("
    SELECT u.id, u.full_name, u.email, u.phone, u.customer_address, u.status, u.is_archived, u.created_at, u.deleted_at, (u.password = 'DELETED_ACCOUNT') AS is_deleted,
           COUNT(o.id) AS order_count,
           COALESCE(SUM(o.total), 0) AS lifetime_spent
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN orders o ON o.user_id = u.id AND o.order_channel = 'ONLINE'
    WHERE {$whereSql}
    GROUP BY u.id
    ORDER BY u.created_at DESC
");
$customers->execute($params);
$customerList = $customers->fetchAll();

// Detail view data if requested
$viewCustomer = null;
$customerOrders = [];
$orderPage = max(1, (int)($_GET['order_page'] ?? 1));
$orderPerPage = 10;
$totalCustomerOrders = 0;
$totalOrderPages = 1;
if ($viewId > 0) {
    $cStmt = $conn->prepare("
        SELECT u.*, r.role_name, (u.password = 'DELETED_ACCOUNT') AS is_deleted 
        FROM users u 
        JOIN roles r ON r.id = u.role_id 
        WHERE u.id = ? AND r.role_name = 'Customer (Online)'
        LIMIT 1
    ");
    $cStmt->execute([$viewId]);
    $viewCustomer = $cStmt->fetch();

    if ($viewCustomer) {
        $orderCountStmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN receipts r ON r.order_id = o.id WHERE o.user_id = ? AND o.order_channel = 'ONLINE'");
        $orderCountStmt->execute([$viewId]);
        $totalCustomerOrders = (int)$orderCountStmt->fetchColumn();
        $totalOrderPages = max(1, (int)ceil($totalCustomerOrders / $orderPerPage));
        $orderPage = min($orderPage, $totalOrderPages);
        $oStmt = $conn->prepare("
            SELECT o.id, o.order_no, o.total, o.order_type, o.status, o.created_at, p.payment_method, r.receipt_no
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.id
            LEFT JOIN receipts r ON r.order_id = o.id
            WHERE o.user_id = ? AND o.order_channel = 'ONLINE'
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT ? OFFSET ?
        ");
        $oStmt->bindValue(1, $viewId, PDO::PARAM_INT);
        $oStmt->bindValue(2, $orderPerPage, PDO::PARAM_INT);
        $oStmt->bindValue(3, ($orderPage - 1) * $orderPerPage, PDO::PARAM_INT);
        $oStmt->execute();
        $customerOrders = $oStmt->fetchAll();
    }
}

include ROOT_PATH . '/includes/header.php';
?>

<style>
/* Online Customers UI Polish */
.customers-layout-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}
@media (max-width: 1024px) {
    .customers-layout-grid {
        display: block;
    }
}

/* Search and Filter Bar */
.customer-search-form {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.customer-search-wrap {
    position: relative;
    flex: 1;
    min-width: 260px;
}
.customer-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #6B7280;
    font-size: 15px;
    pointer-events: none;
}
.customer-search-input {
    width: 100%;
    height: 42px;
    box-sizing: border-box;
    padding: 0 14px 0 38px;
    border-radius: 8px;
    border: 1px solid #D1D5DB;
    background: #FFFFFF;
    color: #111827;
    font-size: 14px;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.customer-search-input::placeholder {
    color: #6B7280;
}
.customer-search-input:focus {
    outline: none;
    border-color: #F2C12E;
    box-shadow: 0 0 0 3px rgba(242, 193, 46, 0.35);
}

.customer-filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.filter-tabs {
    display: inline-flex;
    background: #F3F4F6;
    border-radius: 8px;
    padding: 3px;
    gap: 3px;
    height: 42px;
    box-sizing: border-box;
}
.filter-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    color: #4B5563;
    transition: all .15s ease;
}
.filter-tab:hover {
    color: #111827;
}
.filter-tab.is-active {
    background: #F2C12E;
    color: #111827;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.btn-customer-filter {
    height: 42px;
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #F2C12E;
    color: #111827;
    border: 1px solid #E0B028;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s ease, transform .15s ease;
}
.btn-customer-filter:hover {
    background: #E0B028;
}
.btn-customer-clear {
    height: 42px;
    padding: 0 14px;
    display: inline-flex;
    align-items: center;
    background: #FFFFFF;
    border: 1px solid #D1D5DB;
    border-radius: 8px;
    color: #4B5563;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: all .15s ease;
}
.btn-customer-clear:hover {
    background: #F9FAFB;
    color: #111827;
}

@media (max-width: 768px) {
    .customer-search-wrap {
        width: 100%;
        flex: 1 1 100%;
    }
    .customer-filter-group {
        width: 100%;
        justify-content: space-between;
    }
}

/* Customers Table */
.customers-table {
    min-width: 620px;
}
.customers-table tbody tr {
    transition: background .12s ease;
}
.customers-table tbody tr:hover {
    background: #FAF8F0;
}
.customer-row-selected td {
    background: rgba(242, 193, 46, 0.12) !important;
}
.customer-row-selected td:first-child {
    box-shadow: inset 4px 0 0 #F2C12E;
}
.cust-id-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    background: #F3F4F6;
    color: #4B5563;
    font-size: 11px;
    font-weight: 700;
    font-family: monospace;
}
.customer-email {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    word-break: break-all;
}

/* Customer Details Box */
.customer-info-box {
    background: #F8F9FA;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.customer-info-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    padding-bottom: 8px;
    border-bottom: 1px dashed #E5E7EB;
}
.customer-info-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.customer-info-label {
    font-size: 13px;
    color: #6B7280;
    font-weight: 500;
    white-space: nowrap;
    flex-shrink: 0;
}
.customer-info-value {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    text-align: right;
    overflow-wrap: break-word;
    word-break: break-word;
    max-width: 70%;
}
@media (max-width: 640px) {
    .customer-info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    .customer-info-value {
        text-align: left;
        max-width: 100%;
    }
}

/* Customer Details Sticky & Mobile Slide-up */
@media (min-width: 1025px) {
    .customer-details-wrapper {
        position: sticky;
        top: 24px;
        align-self: flex-start;
    }
    .customer-details-backdrop-overlay {
        display: none !important;
    }
}
@media (max-width: 1024px) {
    .customer-details-wrapper {
        position: fixed;
        inset: 0;
        z-index: 1050;
        display: flex;
        align-items: flex-end;
        justify-content: center;
    }
    .customer-details-backdrop-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        backdrop-filter: blur(2px);
    }
    .customer-details-card {
        position: relative;
        z-index: 1;
        width: 100%;
        max-height: 85vh;
        overflow-y: auto;
        border-radius: 20px 20px 0 0 !important;
        border-bottom: none !important;
        box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.25) !important;
        animation: customerSheetSlideUp .25s cubic-bezier(0.16, 1, 0.3, 1);
        padding: 24px 20px 36px !important;
    }
    @keyframes customerSheetSlideUp {
        from { transform: translateY(100%); }
        to { transform: translateY(0); }
    }
    .customer-empty-panel {
        display: none !important;
    }
}

.customer-details-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    gap: 12px;
}
.btn-close-details {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 14px;
    background: #FFFFFF;
    border: 1px solid #D1D5DB;
    border-radius: 8px;
    color: #374151;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all .15s ease;
    cursor: pointer;
}
.btn-close-details:hover {
    background: #F3F4F6;
    border-color: #9CA3AF;
    color: #111827;
}

/* Skeleton Loading Support */
.skeleton-box {
    background: linear-gradient(90deg, #F3F4F6 25%, #E5E7EB 50%, #F3F4F6 75%);
    background-size: 200% 100%;
    animation: skeleton-shimmer 1.5s infinite;
    border-radius: 4px;
}
@keyframes skeleton-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.customer-search-heading { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.customer-search-heading h2, .customer-list-title { font-size:28px; line-height:1.3; margin:0; }
.customer-list-title { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
.customer-result-count { background:#fff4d2; color:#765400; border-radius:999px; padding:4px 10px; font-size:14px; font-weight:600; }
.customer-search-input { height:46px; border-radius:10px; background:#fafafa; }
.filter-tabs { height:46px; border-radius:10px; background:#f4f4f2; padding:4px; gap:4px; }
.filter-option { position:relative; display:flex; cursor:pointer; }
.filter-option input { position:absolute; opacity:0; width:1px; height:1px; }
.filter-option .filter-tab { min-width:64px; padding:0 14px; border-radius:7px; }
.filter-option input:checked + .filter-tab { background:var(--primary); color:#1e1e1e; box-shadow:0 1px 3px #00000012; }
.filter-option input:focus-visible + .filter-tab { outline:2px solid #806000; outline-offset:2px; }
.btn-customer-filter, .btn-customer-clear { height:46px; box-sizing:border-box; border-radius:10px; justify-content:center; gap:8px; font-size:14px; font-weight:600; white-space:nowrap; }
.btn-customer-filter:focus-visible, .btn-customer-clear:focus-visible { outline:2px solid #806000; outline-offset:3px; }
.customers-table th { font-size:12px; background:#fafafa; }
.customers-table td { padding:16px 12px; font-size:14px; line-height:1.55; }
.customers-table .customer-email { overflow-wrap:anywhere; font-size:13px; font-weight:500; }
.customers-table td:first-child > div { flex-wrap:wrap; }
.customers-table .btn { min-height:36px; padding:7px 10px; font-size:13px; border-radius:9px; }
.customers-table .btn-danger-outline { background:#fff; border:1px solid var(--border); color:#806000; }
.customers-table .btn-danger-outline:hover { background:#fff4d2; border-color:#e8cf86; }
.customer-info-row { gap:20px; padding-top:12px; padding-bottom:12px; line-height:1.5; }
.customer-info-value { overflow-wrap:anywhere; }
.customer-details-card .table td { font-size:13px; line-height:1.5; padding:14px 10px; }
.customer-details-card .table th { font-size:12px; padding:12px 10px; }
@media (max-width:768px) {
 .customer-search-wrap { min-width:0; }
 .customer-filter-group { justify-content:flex-start; gap:10px; }
 .filter-tabs { flex:1 1 100%; }
 .filter-option { flex:1; }
 .filter-option .filter-tab { flex:1; }
 .customer-search-heading h2, .customer-list-title { font-size:24px; }
}
.customer-consent-box { margin: 16px 0; padding: 14px; border: 1px solid #eadba6; background: #fffaf0; border-radius: 10px; }
.customer-consent-box label { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; line-height: 1.5; color: #292929; cursor: pointer; }
.customer-consent-box input { margin: 4px 0 0; width: 17px; height: 17px; flex-shrink: 0; accent-color: #b68400; }
.customer-consent-box p { margin: 10px 0 0 27px; font-size: 12px; line-height: 1.5; color: #666; }
#deleteCustomerModal > div { max-height: calc(100dvh - 32px); overflow-y: auto; }
</style>

<div style="margin-bottom: 20px;">
    <div style="background: #FFFBE6; border-left: 4px solid #F2C12E; padding: 12px 18px; border-radius: 6px; color: #111; font-size: 0.9rem; line-height: 1.5;">
        <strong><i class="bi bi-shield-check"></i> Customer Account Archive Policy:</strong>
        Admin-only management. Deleting customer records or order histories is disabled. Archiving a customer revokes active sessions and prevents sign-in or new orders while keeping customer details, historical receipts, and financial logs intact.
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?>" style="margin-bottom: 20px;">
    <?= $msg ?>
</div>
<?php endif; ?>

<!-- Search & Filter Bar -->
<div class="card customer-search-card" style="margin-bottom: 20px;">
    <div class="customer-search-heading"><h2>Search &amp; Customer Archive</h2><a class="btn-customer-clear" href="../users/index.php"><i class="bi bi-arrow-left"></i> Back to Users</a></div>
    <form method="get" class="customer-search-form">
        <div class="customer-search-wrap">
            <i class="bi bi-search customer-search-icon"></i>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search customer by name, email, or mobile..." class="customer-search-input" aria-label="Search customer by name, email, or mobile">
        </div>
        <div class="customer-filter-group">
            <div class="filter-tabs" role="group" aria-label="Customer status">
                <?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $statusValue => $statusLabel): ?>
                    <label class="filter-option">
                        <input type="radio" name="filter" value="<?= e($statusValue) ?>" <?= $filter === $statusValue ? 'checked' : '' ?> onchange="this.form.requestSubmit()">
                        <span class="filter-tab"><?= e($statusLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn-customer-filter" aria-label="Apply filter"><i class="bi bi-funnel"></i> Filter</button>
                <a href="customers.php" class="btn-customer-clear" aria-label="Clear search and filters"><i class="bi bi-arrow-counterclockwise"></i> Clear</a>
        </div>
    </form>
</div>

<!-- Main Section: List and Detail -->
<div class="customers-layout-grid">
    <!-- Customer List Table -->
    <section class="card">
        <h2 class="customer-list-title"><?= $filter === 'archived' ? 'Archived Customers' : ($filter === 'all' ? 'All Customers' : 'Active Customers') ?> <span class="customer-result-count"><?= count($customerList) ?></span></h2>
        <p class="muted" style="margin-bottom:14px; font-size:0.85rem;">Registered customer accounts on the storefront.</p>

        <div class="table-wrap">
            <table class="table customers-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Orders</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customerList)): ?>
                        <tr><td colspan="5" class="muted text-center" style="padding:24px;">No customer accounts found matching criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($customerList as $c): ?>
                            <tr class="<?= ($viewId === (int)$c['id']) ? 'customer-row-selected' : '' ?>">
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <span class="cust-id-badge">#<?= (int)$c['id'] ?></span>
                                        <strong style="color:#111827; font-size:0.95rem;"><?= e($c['full_name']) ?></strong>
                                    </div>
                                    <small class="muted" style="font-size:0.8rem;">Joined <?= date('M j, Y', strtotime($c['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="customer-email"><?= e($c['email'] ?: 'No email') ?></div>
                                    <small class="muted" style="font-size:0.8rem;"><?= e($c['phone'] ?: 'No phone') ?></small>
                                </td>
                                <td>
                                    <strong style="color:#111827;"><?= (int)$c['order_count'] ?> <?= (int)$c['order_count'] === 1 ? 'order' : 'orders' ?></strong><br>
                                    <small style="color:#806000; font-weight:700;">₱<?= number_format((float)$c['lifetime_spent'], 2) ?></small>
                                </td>
                                <td>
                                    <?php if ($c['is_deleted']): ?>
                                        <span class="badge" style="background:#374151; color:#9ca3af; font-size:12px; font-weight:600;">Deleted</span>
                                    <?php elseif ($c['is_archived'] || $c['deleted_at']): ?>
                                        <span class="badge" style="background:#F3F4F6; color:#4B5563; border:1px solid #D1D5DB; font-size:12px; font-weight:600;">Archived</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#d1fae5; color:#065f46; font-size:12px; font-weight:600;">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <a href="customers.php?view=<?= (int)$c['id'] ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-sm btn-secondary" title="View Profile & Orders" aria-label="View Profile and Orders for <?= e($c['full_name']) ?>">
                                        <i class="bi bi-eye"></i> Details
                                    </a>

                                    <?php if (!$c['is_deleted']): ?>
                                        <?php if (!$c['is_archived'] && !$c['deleted_at']): ?>
                                            <form method="post" style="display:inline;" data-customer-confirm="archive" data-customer-name="<?= e($c['full_name']) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger-outline" title="Archive customer" aria-label="Archive customer <?= e($c['full_name']) ?>"><i class="bi bi-archive"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" style="display:inline;" data-customer-confirm="restore" data-customer-name="<?= e($c['full_name']) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-primary" title="Restore customer" aria-label="Restore customer <?= e($c['full_name']) ?>"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Detail View: Customer Profile & Order History -->
    <?php if ($viewCustomer): ?>
    <div class="customer-details-wrapper">
        <div class="customer-details-backdrop-overlay" onclick="location.href='customers.php<?= $filter ? '?filter=' . urlencode($filter) : '' ?><?= $search ? '&search=' . urlencode($search) : '' ?>'"></div>
        <section class="card customer-details-card">
            <div class="customer-details-header">
                <div>
                    <h2 style="margin:0 0 4px; font-size:1.35rem; color:#111827;">Customer Details</h2>
                    <p class="muted" style="margin:0; font-size:0.85rem; color:#6B7280;">Account profile and comprehensive receipt history.</p>
                </div>
                <a href="customers.php<?= $filter ? '?filter=' . urlencode($filter) : '' ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn-close-details" aria-label="Close customer details panel">
                    <i class="bi bi-x-lg"></i>
                    <span>Close</span>
                </a>
            </div>

            <div class="customer-info-box">
                <div class="customer-info-row">
                    <span class="customer-info-label">Full Name:</span>
                    <span class="customer-info-value"><?= e($viewCustomer['full_name']) ?></span>
                </div>
                <div class="customer-info-row">
                    <span class="customer-info-label">Email Address:</span>
                    <span class="customer-info-value"><?= e($viewCustomer['email'] ?: 'None') ?></span>
                </div>
                <div class="customer-info-row">
                    <span class="customer-info-label">Mobile Phone:</span>
                    <span class="customer-info-value"><?= e($viewCustomer['phone'] ?: 'None') ?></span>
                </div>
                <div class="customer-info-row">
                    <span class="customer-info-label">Delivery Address:</span>
                    <span class="customer-info-value"><?= e($viewCustomer['customer_address'] ?: 'None saved') ?></span>
                </div>
                <div class="customer-info-row">
                    <span class="customer-info-label">Account Status:</span>
                    <div>
                        <?php if ($viewCustomer['is_deleted']): ?>
                            <span class="badge" style="background:#374151; color:#9ca3af; font-size:12px; font-weight:600;">Deleted</span>
                        <?php elseif ($viewCustomer['is_archived'] || $viewCustomer['deleted_at']): ?>
                            <span class="badge" style="background:#F3F4F6; color:#4B5563; border:1px solid #D1D5DB; font-size:12px; font-weight:600;">Archived</span>
                        <?php else: ?>
                            <span class="badge" style="background:#d1fae5; color:#065f46; font-size:12px; font-weight:600;">Active</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <h3 id="customer-order-history" style="margin: 0 0 12px; font-size: 1.1rem; color: #111827; scroll-margin-top:90px;">Order History &amp; Receipts (<?= $totalCustomerOrders ?>)</h3>
            <div class="customer-order-pagination" style="display:flex;justify-content:flex-end;margin:0 0 16px;">
                <?= render_pagination_controls($orderPage, $totalOrderPages, static fn(int $p) => 'customers.php?' . http_build_query(['view' => $viewId, 'filter' => $filter, 'search' => $search, 'order_page' => $p], '', '&', PHP_QUERY_RFC3986) . '#customer-order-history') ?>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Type</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customerOrders)): ?>
                            <tr>
                                <td colspan="5" style="padding:36px 16px; text-align:center;">
                                    <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px;">
                                        <i class="bi bi-receipt" style="font-size:32px; color:#9CA3AF;"></i>
                                        <span style="font-size:14px; font-weight:500; color:#6B7280;">No orders found for this customer.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customerOrders as $ord): ?>
                                <tr>
                                    <td>
                                        <strong style="color:#111827;"><?= e($ord['order_no']) ?></strong><br>
                                        <small class="muted" style="font-size:0.8rem;"><?= e($ord['receipt_no'] ?: 'No receipt') ?></small>
                                    </td>
                                    <td style="font-size:0.85rem; color:#374151;"><?= e($ord['order_type']) ?></td>
                                    <td><strong style="color:var(--brand, #F2C12E); font-size:0.95rem;">₱<?= number_format((float)$ord['total'], 2) ?></strong></td>
                                    <td>
                                        <span class="badge" style="font-size:0.75rem; font-weight:600;"><?= e($ord['status']) ?></span>
                                    </td>
                                    <td style="font-size:0.8rem; color:#6B7280;">
                                        <?= date('M j, Y g:i A', strtotime($ord['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php else: ?>
    <!-- Friendly Empty State (Visible on desktop before any customer is selected) -->
    <div class="customer-empty-panel customer-details-wrapper">
        <section class="card" style="text-align:center; padding:48px 24px; border:1px dashed #D1D5DB; background:#FAFAFA;">
            <div style="max-width:320px; margin:0 auto; display:flex; flex-direction:column; align-items:center; gap:12px;">
                <div style="width:64px; height:64px; border-radius:50%; background:#F3F4F6; border:1px solid #E5E7EB; display:grid; place-items:center; color:#9CA3AF; font-size:28px;">
                    <i class="bi bi-person-lines-fill"></i>
                </div>
                <h3 style="margin:0; font-size:1.15rem; color:#111827;">Select a Customer</h3>
                <p style="margin:0; font-size:14px; color:#6B7280; line-height:1.5;">
                    Select a customer to view details, inspect contact information, and review past order receipts.
                </p>
            </div>
        </section>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('submit', async function(event) {
    const form = event.target;
    if (!form.matches('[data-customer-confirm]')) return;
    if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
    event.preventDefault();
    if (form.dataset.confirming === '1') return;
    form.dataset.confirming = '1';
    const archive = form.dataset.customerConfirm === 'archive';
    try {
        const accepted = await window.kkConfirm({
            title: archive ? 'Archive customer account?' : 'Restore customer account?',
            details: [{label: 'Customer', value: form.dataset.customerName}],
            message: archive ? 'This customer will no longer be able to sign in, place orders, or request password resets at Kenji’s Kitchen. Their account details, past orders, and receipts will be kept. You can restore the account later.' : 'This customer will regain access to their Kenji’s Kitchen account and online ordering. Their past orders and receipts will remain unchanged.',
            confirmText: archive ? 'Archive Customer' : 'Restore Customer',
            cancelText: 'Cancel',
            variant: 'primary'
        });
        if (accepted) { form.dataset.confirmed = '1'; form.requestSubmit(); }
    } finally { delete form.dataset.confirming; }
});

// Pressing Escape closes details panel
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const closeDetailsBtn = document.querySelector('.btn-close-details');
        if (closeDetailsBtn) {
            closeDetailsBtn.click();
        }
    }
});
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
