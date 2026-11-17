<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'payments';
$title = 'Online Payment Settings';
$heading = 'Online Payment Settings';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$notice = $_SESSION['payment_settings_notice'] ?? null;
unset($_SESSION['payment_settings_notice']);

$settingsStmt = $conn->query('SELECT gcash_account_name, gcash_phone, gcash_qr_image FROM store_payment_settings WHERE id = 1 LIMIT 1');
$settings = $settingsStmt->fetch() ?: ['gcash_phone' => '09952071574', 'gcash_qr_image' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid form token. Please reload the page and try again.');
    }

    $accountName = trim((string)($_POST['gcash_account_name'] ?? ''));
    if ($accountName === '' || mb_strlen($accountName) > 120) {
        $_SESSION['payment_settings_notice'] = ['type' => 'error', 'text' => 'Enter the GCash account name, up to 120 characters.'];
        redirect('/modules/settings/payments.php');
    }
    $phone = preg_replace('/\D+/', '', (string)($_POST['gcash_phone'] ?? ''));
    if (!preg_match('/^09\d{9}$/', $phone)) {
        $_SESSION['payment_settings_notice'] = ['type' => 'error', 'text' => 'Enter a valid 11-digit Philippine GCash number starting with 09.'];
        redirect('/modules/settings/payments.php');
    }

    $oldQr = (string)($settings['gcash_qr_image'] ?? '');
    $qrPath = $oldQr !== '' ? $oldQr : null;
    $newAbsolutePath = null;
    $upload = $_FILES['gcash_qr'] ?? null;

    try {
        if (!empty($_POST['remove_qr'])) {
            $qrPath = null;
        }

        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The QR image upload did not complete. Please try again.');
            }
            if (($upload['size'] ?? 0) < 1 || ($upload['size'] ?? 0) > 4 * 1024 * 1024) {
                throw new RuntimeException('The QR image must be smaller than 4 MB.');
            }

            $imageInfo = @getimagesize($upload['tmp_name']);
            $imageMime = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
            $allowedTypes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            if (!isset($allowedTypes[$imageMime]) || ($imageInfo[0] ?? 0) > 4096 || ($imageInfo[1] ?? 0) > 4096) {
                throw new RuntimeException('Upload a PNG, JPG, or WebP QR image no larger than 4096 × 4096 pixels.');
            }

            $relativeDir = 'uploads/payments';
            $absoluteDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payments';
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
                throw new RuntimeException('The payment image folder could not be created.');
            }

            $filename = 'gcash-qr-' . bin2hex(random_bytes(12)) . '.' . $allowedTypes[$imageMime];
            $newAbsolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($upload['tmp_name'], $newAbsolutePath)) {
                throw new RuntimeException('The QR image could not be saved. Check the uploads folder permissions.');
            }
            $qrPath = $relativeDir . '/' . $filename;
        }

        $save = $conn->prepare("INSERT INTO store_payment_settings (id, gcash_account_name, gcash_phone, gcash_qr_image, updated_by) VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE gcash_account_name = VALUES(gcash_account_name), gcash_phone = VALUES(gcash_phone), gcash_qr_image = VALUES(gcash_qr_image), updated_by = VALUES(updated_by)");
        $save->execute([$accountName, $phone, $qrPath, (int)$_SESSION['u_id']]);

        if ($oldQr !== '' && $oldQr !== $qrPath && str_starts_with($oldQr, 'uploads/payments/')) {
            $oldAbsolutePath = ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . basename($oldQr);
            if (is_file($oldAbsolutePath)) {
                @unlink($oldAbsolutePath);
            }
        }

        $_SESSION['payment_settings_notice'] = ['type' => 'success', 'text' => 'Online GCash payment details saved.'];
    } catch (Throwable $error) {
        if ($newAbsolutePath && is_file($newAbsolutePath)) {
            @unlink($newAbsolutePath);
        }
        error_log('GCash settings update failed: ' . $error->getMessage());
        $_SESSION['payment_settings_notice'] = ['type' => 'error', 'text' => $error instanceof RuntimeException ? $error->getMessage() : 'Settings could not be saved. Please try again.'];
    }

    redirect('/modules/settings/payments.php');
}

$settingsStmt = $conn->query('SELECT gcash_account_name, gcash_phone, gcash_qr_image FROM store_payment_settings WHERE id = 1 LIMIT 1');
$settings = $settingsStmt->fetch() ?: ['gcash_phone' => '09952071574', 'gcash_qr_image' => null];
$qrPath = (string)($settings['gcash_qr_image'] ?? '');
$basePath = parse_url(BASE_URL, PHP_URL_PATH);
$basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
$qrUrl = $qrPath !== '' ? $basePath . '/' . ltrim($qrPath, '/') : '';

include ROOT_PATH . '/includes/header.php';
?>
<section class="payment-settings-page">
    <?php if ($notice): ?>
        <div class="payment-settings-alert <?= e($notice['type']) ?>" role="status"><?= e($notice['text']) ?></div>
    <?php endif; ?>

    <article class="payment-settings-card">
        <div class="payment-settings-intro">
            <span class="payment-settings-icon"><i class="bi bi-qr-code-scan"></i></span>
            <div>
                <h2>GCash for Online Orders</h2>
                <p>Customers see these payment details in online checkout when they choose GCash.</p>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="payment-settings-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div class="payment-fields">
            <h3>Payment details</h3>
            <label for="gcashAccountName">Account name</label>
                    <input type="text" id="gcashAccountName" name="gcash_account_name" value="<?= e($settings['gcash_account_name'] ?? '') ?>" maxlength="120" autocomplete="off" required>
                    <small>Use the registered name customers should check before paying.</small>
                    <label for="gcashPhone">Account number</label>
            <input type="tel" id="gcashPhone" name="gcash_phone" value="<?= e($settings['gcash_phone']) ?>" maxlength="11" pattern="09[0-9]{9}" inputmode="numeric" autocomplete="tel" required>
            <small>The GCash number customers will send their payment to. Use 11 digits starting with 09.</small>

            <label for="gcashQr">GCash QR code image <span>(optional)</span></label>
            <input type="file" id="gcashQr" name="gcash_qr" accept="image/png,image/jpeg,image/webp">
            <small>PNG, JPG or WebP · Up to 4 MB · Maximum 4096 × 4096 pixels.</small>

            </div>
            <aside class="payment-qr-panel">
                <h3>Kenji's Kitchen · GCash</h3>
                <p class="qr-caption">Shown to customers at online checkout.</p>
            <?php if ($qrUrl !== ''): ?>
                <div class="payment-settings-preview">
                    <img src="<?= e($qrUrl) ?>" alt="Current GCash QR code">
                    <label><input type="checkbox" name="remove_qr" value="1"> Remove current QR image</label>
                </div>
            <?php else: ?>
                <div class="payment-settings-no-qr"><i class="bi bi-qr-code"></i> <strong>No QR code yet</strong><span>Upload your GCash QR image to let customers scan to pay.</span></div>
            <?php endif; ?>

            <dl class="payment-account-summary"><dt>Account name</dt><dd><?= e($settings['gcash_account_name'] ?? '') ?></dd><dt>Account number</dt><dd><?= e($settings['gcash_phone']) ?></dd></dl>
            </aside>
            <div class="payment-save-bar"><span>Changes appear in online checkout after saving.</span>
            <button type="submit" class="btn-primary payment-settings-save"><i class="bi bi-check2-circle"></i> Save GCash Details</button>
            </div>
        </form>
    </article>
</section>
<style>
.payment-settings-page{max-width:820px;margin:0 auto;padding:24px 0 48px}.payment-settings-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 8px 28px rgba(15,23,42,.06)}.payment-settings-intro{display:flex;align-items:center;gap:14px;margin-bottom:24px}.payment-settings-intro h2{margin:0 0 5px;font-size:1.15rem}.payment-settings-intro p,.payment-settings-form small{margin:0;color:#64748b;font-size:.84rem;line-height:1.5}.payment-settings-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:13px;background:#fff7db;color:#b77900;font-size:1.4rem;flex:none}.payment-settings-form{display:grid;gap:9px}.payment-settings-form>label{margin-top:9px;font-size:.88rem;font-weight:700}.payment-settings-form>label span{font-weight:500;color:#64748b}.payment-settings-form>input[type=tel],.payment-settings-form>input[type=file]{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.payment-settings-preview{display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-top:10px}.payment-settings-preview img{width:180px;height:180px;object-fit:contain;padding:8px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}.payment-settings-preview label{display:flex;gap:8px;align-items:center;font-size:.84rem}.payment-settings-no-qr{display:flex;gap:9px;align-items:center;padding:13px;color:#64748b;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;font-size:.84rem}.payment-settings-no-qr i{font-size:1.25rem}.payment-settings-save{justify-self:start;margin-top:12px;padding:12px 18px}.payment-settings-alert{margin:0 0 16px;padding:12px 14px;border-radius:10px;font-size:.88rem}.payment-settings-alert.success{color:#166534;background:#dcfce7}.payment-settings-alert.error{color:#991b1b;background:#fee2e2}@media(max-width:600px){.payment-settings-page{padding:14px 0 30px}.payment-settings-card{padding:18px}.payment-settings-intro{align-items:flex-start}}

.payment-settings-page{max-width:1100px;padding:0 0 32px;margin:0 auto}
.payment-settings-card{padding:0;overflow:hidden;border-color:#e8e5dd;box-shadow:0 4px 18px #00000004}
.payment-settings-intro{margin:0;padding:24px 28px;border-bottom:1px solid #eeeae1;align-items:center}
.payment-settings-intro h2{font-size:20px;color:#242424}
.payment-settings-icon{background:#fff1b8;color:#815b00}
.payment-settings-form{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(260px,1fr);gap:28px;padding:28px}
.payment-settings-form h3{font-size:15px;margin:0 0 18px;color:#292929}
.payment-fields{display:flex;flex-direction:column;gap:10px;min-width:0}
.payment-fields label{font-size:14px;font-weight:650;margin-top:4px}
.payment-fields label[for=gcashQr]{margin-top:20px}
.payment-fields label span{font-weight:400;color:#737373}
.payment-fields input{width:100%;border:1px solid #dcd8cd;border-radius:10px;background:#fff;padding:12px;font:inherit;min-height:46px;box-sizing:border-box}
.payment-fields input:focus-visible{outline:3px solid #fff0ad;border-color:#c49412}
.payment-fields input[type=file]{font-size:13px;background:#fcfbf7;padding:9px}
.payment-fields input::file-selector-button{background:#fff0b5;color:#594000;border:0;border-radius:7px;padding:9px 12px;margin-right:10px;font-weight:600;cursor:pointer}
.payment-qr-panel{background:#faf9f5;border:1px solid #ece8dd;border-radius:12px;padding:20px;min-width:0}
.payment-qr-panel h3{margin-bottom:6px}
.qr-caption{font-size:13px;color:#737063;margin:0 0 18px}
.payment-settings-preview{flex-direction:column;align-items:center;margin:0}
.payment-settings-preview img{width:200px;height:200px;max-width:100%;border-color:#e4dfd3;border-radius:8px;padding:10px}
.payment-settings-preview label{color:#a03429;cursor:pointer}
.payment-settings-no-qr{min-height:200px;flex-direction:column;justify-content:center;text-align:center;background:#fff;border-color:#ded8c8;padding:24px;color:#777062}
.payment-settings-no-qr i{font-size:32px;color:#ae8421;margin-bottom:4px}
.payment-settings-no-qr strong{color:#494334;font-size:14px}
.payment-settings-no-qr span{font-size:13px;line-height:1.6;max-width:230px}
.payment-save-bar{grid-column:1/-1;border-top:1px solid #eeeae1;padding-top:20px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.payment-save-bar>span{font-size:13px;color:#737063}
.payment-settings-save{display:inline-flex;align-items:center;gap:8px;margin:0;background:#f7c62f;color:#242424;border:1px solid #e5b821;border-radius:10px;padding:12px 18px;font:600 14px system-ui,sans-serif;cursor:pointer;white-space:nowrap}
.payment-settings-save:hover{background:#eebd22}
.payment-settings-save:focus-visible{outline:3px solid #b4870a;outline-offset:3px}
@media(max-width:800px){.payment-settings-form{grid-template-columns:1fr;padding:20px;gap:24px}.payment-settings-intro{padding:20px}.payment-save-bar{flex-direction:column;align-items:stretch}.payment-settings-save{justify-content:center}}
.payment-account-summary{display:grid;gap:6px;margin:20px 0 0}.payment-account-summary dt{font-size:12px;color:#737063}.payment-account-summary dd{margin:0 0 10px;font-weight:700;overflow-wrap:anywhere}.payment-settings-preview img{width:100%;height:auto;max-width:300px;max-height:none;padding:0}.payment-fields input:focus-visible{outline:3px solid #f7c62f;outline-offset:2px}
</style>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
