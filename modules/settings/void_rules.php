<?php
/**
 * Kenji's Kitchen - Void & Shift Operational Rules Settings (Admin Only)
 * Modules /settings/void_rules.php
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'void_rules';
$title = 'Void & Shift Settings';
$heading = 'Void & Shift Settings';
$msg = '';
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

// Fetch all system settings
function get_settings(PDO $conn): array {
    $rows = $conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'self_void_limit_per_order'     => (float)($rows['self_void_limit_per_order'] ?? 50),
        'max_self_voids_per_shift'      => (int)($rows['max_self_voids_per_shift'] ?? 5),
        'max_self_void_total_per_shift' => (float)($rows['max_self_void_total_per_shift'] ?? 200),
        'refunds_require_approval'      => (int)($rows['refunds_require_approval'] ?? 1),
        'over_short_threshold'          => (float)($rows['over_short_threshold'] ?? 50),
        'require_opening_cash'          => (int)($rows['require_opening_cash'] ?? 1),
        'manager_role_enabled'          => (int)($rows['manager_role_enabled'] ?? 1),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $selfVoidLimit = max(0, (float)($_POST['self_void_limit_per_order'] ?? 50));
    $maxSelfVoids = max(0, (int)($_POST['max_self_voids_per_shift'] ?? 5));
    $maxSelfVoidTotal = max(0, (float)($_POST['max_self_void_total_per_shift'] ?? 200));
    $refundsRequireApproval = !empty($_POST['refunds_require_approval']) ? 1 : 0;
    $overShortThreshold = max(0, (float)($_POST['over_short_threshold'] ?? 50));
    $requireOpeningCash = !empty($_POST['require_opening_cash']) ? 1 : 0;
    $managerRoleEnabled = !empty($_POST['manager_role_enabled']) ? 1 : 0;

    $updates = [
        'self_void_limit_per_order'     => (string)$selfVoidLimit,
        'max_self_voids_per_shift'      => (string)$maxSelfVoids,
        'max_self_void_total_per_shift' => (string)$maxSelfVoidTotal,
        'refunds_require_approval'      => (string)$refundsRequireApproval,
        'over_short_threshold'          => (string)$overShortThreshold,
        'require_opening_cash'          => (string)$requireOpeningCash,
        'manager_role_enabled'          => (string)$managerRoleEnabled,
    ];

    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");

    foreach ($updates as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    log_audit($conn, 'Settings Updated', 'setting', 'void_shift_rules', "Updated operational parameters: Self-void limit ₱{$selfVoidLimit}, Max voids/shift {$maxSelfVoids}, Max total ₱{$maxSelfVoidTotal}, Over/short threshold ₱{$overShortThreshold}");

    $msg = 'Operational rules and void policies have been saved successfully.';
    $msg_type = 'success';
}

$settings = get_settings($conn);

include ROOT_PATH . '/includes/header.php';
?>

<div style="margin-bottom: 20px;">
    <div style="background: #FFFBE6; border-left: 4px solid #F2C12E; padding: 12px 18px; border-radius: 6px; color: #111; font-size: 0.9rem; line-height: 1.5;">
        <strong><i class="bi bi-sliders"></i> Operational Guardrails:</strong>
        Configure void and refund authorization thresholds. Cashiers can self-void small mistakes up to the configured limit, but large voids, refunds, or kitchen-queued orders automatically require Admin or Manager authorization.
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?>" style="margin-bottom: 20px;">
    <?= $msg ?>
</div>
<?php endif; ?>

<div class="card" style="max-width: 720px;">
    <h2>Void & Shift Policy Settings</h2>
    <p class="muted" style="margin-bottom: 20px; font-size: 0.88rem;">Adjust limits for POS cashier terminal behavior and shift handovers.</p>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <h3 style="font-size:1.1rem; color:#F2C12E; border-bottom:1px solid #2a2d3a; padding-bottom:8px; margin-top:10px;">
            <i class="bi bi-x-circle"></i> POS Void Limits (Anti-Abuse)
        </h3>

        <div class="field" style="margin-bottom:16px;">
            <label>Self-Void Limit per Order (₱) <span style="color:#F2C12E;">*</span></label>
            <input type="number" step="0.5" name="self_void_limit_per_order" value="<?= e($settings['self_void_limit_per_order']) ?>" required style="max-width:240px;">
            <small class="muted" style="font-size:0.78rem; display:block; margin-top:4px;">
                Default: 50. Voids up to and including this cumulative amount can be performed by the cashier alone. Anything above requires Admin/Manager approval.
            </small>
        </div>

        <div class="field" style="margin-bottom:16px;">
            <label>Max Self-Approved Voids per Shift <span style="color:#F2C12E;">*</span></label>
            <input type="number" name="max_self_voids_per_shift" value="<?= e($settings['max_self_voids_per_shift']) ?>" required style="max-width:240px;">
            <small class="muted" style="font-size:0.78rem; display:block; margin-top:4px;">
                Default: 5. Once a cashier reaches this count in an active shift, all subsequent voids require supervisor approval.
            </small>
        </div>

        <div class="field" style="margin-bottom:16px;">
            <label>Max Self-Approved Void Total per Shift (₱) <span style="color:#F2C12E;">*</span></label>
            <input type="number" step="1" name="max_self_void_total_per_shift" value="<?= e($settings['max_self_void_total_per_shift']) ?>" required style="max-width:240px;">
            <small class="muted" style="font-size:0.78rem; display:block; margin-top:4px;">
                Default: 200. Cumulative total a cashier can self-void across their shift without supervisor approval.
            </small>
        </div>

        <div class="field" style="margin-bottom:24px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" name="refunds_require_approval" value="1" <?= $settings['refunds_require_approval'] ? 'checked' : '' ?>>
                <strong>Refunds always require Admin/Manager approval</strong> regardless of amount
            </label>
        </div>

        <h3 style="font-size:1.1rem; color:#F2C12E; border-bottom:1px solid #2a2d3a; padding-bottom:8px; margin-top:20px;">
            <i class="bi bi-cash-coin"></i> Cashier Shift & Handover Parameters
        </h3>

        <div class="field" style="margin-bottom:16px;">
            <label>Over / Short Note Required Threshold (₱) <span style="color:#F2C12E;">*</span></label>
            <input type="number" step="1" name="over_short_threshold" value="<?= e($settings['over_short_threshold']) ?>" required style="max-width:240px;">
            <small class="muted" style="font-size:0.78rem; display:block; margin-top:4px;">
                Default: 50. If the discrepancy between counted cash and expected cash exceeds this amount, an explanatory note is mandatory during handover.
            </small>
        </div>

        <div class="field" style="margin-bottom:20px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" name="require_opening_cash" value="1" <?= $settings['require_opening_cash'] ? 'checked' : '' ?>>
                <strong>Require cashier to enter opening float cash</strong> when starting a shift
            </label>
        </div>

        <div class="field" style="margin-bottom:20px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" name="manager_role_enabled" value="1" <?= $settings['manager_role_enabled'] ? 'checked' : '' ?>>
                <strong>Enable Manager Role</strong> (Allows managers to view reports, shift handovers, and approve voids)
            </label>
        </div>

        <button type="submit" class="btn btn-primary" style="padding:12px 28px; font-weight:800;">
            <i class="bi bi-save"></i> Save Operational Settings
        </button>
    </form>
</div>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
