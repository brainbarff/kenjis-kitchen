<?php
// Customer-only tokens. The ordinary PHP session is shared with staff and must stay session-only.
function kk_remember_dir(): string {
    return dirname(__DIR__, 3) . '/storage/customer-sessions/';
}
function kk_remember_cookie(string $value, int $expires): void {
    $path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    setcookie('kk_customer_remember', $value, ['expires' => $expires, 'path' => $path,
        'secure' => kk_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function kk_remember_token(): ?array {
    $raw = $_COOKIE['kk_customer_remember'] ?? '';
    return is_string($raw) && preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D', $raw, $m) ? $m : null;
}
function kk_remember_forget(): void {
    if ($token = kk_remember_token()) {
        $file = kk_remember_dir() . $token[1] . '.token';
        if (is_file($file)) unlink($file);
    }
    if (isset($_COOKIE['kk_customer_remember'])) kk_remember_cookie('', time()-3600);
}
function kk_remember_issue(array $user): void {
    $selector = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    $expires = time() + 30 * 86400;
    $record = [(int)$user['id'], hash('sha256', $secret), hash('sha256', $user['password']), $expires];
    if (file_put_contents(kk_remember_dir() . $selector . '.token', json_encode($record), LOCK_EX) === false) return;
    kk_remember_cookie($selector . '.' . $secret, $expires);
    if (random_int(1, 32) === 1) {
        foreach (glob(kk_remember_dir() . '*.token') ?: [] as $file)
            if (filemtime($file) < time() - 30 * 86400) @unlink($file);
    }
}
function kk_remember_restore(PDO $conn): int {
    $token = kk_remember_token();
    if (!$token) return 0;
    $file = kk_remember_dir() . $token[1] . '.token';
    $record = json_decode((string)@file_get_contents($file), true);
    if (!is_array($record) || count($record) !== 4 || $record[3] <= time() ||
        !hash_equals($record[1], hash('sha256', $token[2]))) return 0;
    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$record[0]]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !hash_equals($record[2], hash('sha256', $hash))) return 0;
    // The caller still checks customer role, active status and email verification.
    return (int)$record[0];
}
