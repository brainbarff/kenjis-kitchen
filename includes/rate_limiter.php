<?php
/**
 * Staff Login Rate Limiter for Kenji's Kitchen
 *
 * Provides atomic, file-locked brute-force mitigation for staff login attempts.
 * Tracks failed attempts per (normalized username + client IP) and per client IP.
 * Enforces a bounded 15-minute cooldown after 5 consecutive failures.
 * Storage is placed outside the web document root (sys_get_temp_dir()).
 */

if (!defined('KK_LOGIN_MAX_FAILURES')) {
    define('KK_LOGIN_MAX_FAILURES', 5);
}

if (!defined('KK_LOGIN_WINDOW_SECONDS')) {
    define('KK_LOGIN_WINDOW_SECONDS', 900); // 15 minutes
}

if (!defined('KK_LOGIN_IP_MAX_FAILURES')) {
    define('KK_LOGIN_IP_MAX_FAILURES', 15); // Global IP threshold across different usernames
}

/**
 * Returns the sanitized client IP address.
 */
function kk_get_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '127.0.0.1';
    }
    return $ip;
}

/**
 * Returns the secure directory path used for rate-limit state storage.
 * Defaults to the OS temp directory outside webroot, with fallback to storage/ratelimit.
 */
function kk_get_rate_limit_dir(): string
{
    static $resolvedDir = null;
    if ($resolvedDir !== null) {
        return $resolvedDir;
    }

    $tempBase = sys_get_temp_dir();
    $targetDir = rtrim($tempBase, '\\/') . DIRECTORY_SEPARATOR . 'kenjis_kitchen_ratelimit';

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0700, true);
    }

    if (is_dir($targetDir) && is_writable($targetDir)) {
        $resolvedDir = $targetDir;
        return $resolvedDir;
    }

    // Fallback within storage directory if temp is unwritable
    $fallbackDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';
    if (!is_dir($fallbackDir)) {
        @mkdir($fallbackDir, 0700, true);
    }
    $htaccess = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }

    $resolvedDir = $fallbackDir;
    return $resolvedDir;
}

/**
 * Returns the path to the rate limit file for the given prefix and identifier.
 */
function kk_get_rate_limit_file_path(string $prefix, string $identifier): string
{
    $dir = kk_get_rate_limit_dir();
    $hash = hash('sha256', $prefix . ':' . $identifier);
    return $dir . DIRECTORY_SEPARATOR . $prefix . '_' . $hash . '.json';
}

/**
 * Atomically loads rate limit state from file under an exclusive flock.
 * Returns array with handle, file path, and state data.
 */
function kk_open_rate_limit_file(string $filePath): ?array
{
    $fp = @fopen($filePath, 'c+');
    if (!$fp) {
        return null;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return null;
    }

    $content = '';
    rewind($fp);
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) {
            break;
        }
        $content .= $chunk;
    }

    $data = [];
    if (trim($content) !== '') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    return [
        'handle' => $fp,
        'path'   => $filePath,
        'data'   => $data
    ];
}

/**
 * Atomically writes data and unlocks/closes the rate limit file.
 */
function kk_save_and_close_rate_limit_file(array $lockContext, array $data): void
{
    $fp = $lockContext['handle'];
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Releases lock without saving.
 */
function kk_close_rate_limit_file(array $lockContext): void
{
    flock($lockContext['handle'], LOCK_UN);
    fclose($lockContext['handle']);
}

/**
 * Prunes timestamps older than window and returns active failure timestamps.
 */
function kk_prune_rate_limit_timestamps(array $timestamps, int $windowSeconds, int $now): array
{
    $cutoff = $now - $windowSeconds;
    $valid = [];
    foreach ($timestamps as $ts) {
        if (is_int($ts) && $ts > $cutoff && $ts <= $now + 5) {
            $valid[] = $ts;
        }
    }
    return $valid;
}

/**
 * Checks whether the user/IP combination is currently rate-limited.
 * Returns: ['blocked' => bool, 'message' => string, 'retry_after' => int]
 */
function kk_check_login_rate_limit(string $username): array
{
    $now = time();
    $normUser = strtolower(trim($username));
    $ip = kk_get_client_ip();

    $userKey = $normUser . '|' . $ip;
    $userFile = kk_get_rate_limit_file_path('user_ip', $userKey);

    $userLock = kk_open_rate_limit_file($userFile);
    if ($userLock) {
        $lockoutUntil = (int)($userLock['data']['lockout_until'] ?? 0);
        $attempts = kk_prune_rate_limit_timestamps($userLock['data']['attempts'] ?? [], KK_LOGIN_WINDOW_SECONDS, $now);

        if ($lockoutUntil > $now) {
            $remaining = $lockoutUntil - $now;
            kk_close_rate_limit_file($userLock);
            $mins = max(1, (int)ceil($remaining / 60));
            return [
                'blocked'     => true,
                'message'     => "Too many failed login attempts. Please try again in {$mins} minute(s).",
                'retry_after' => $remaining
            ];
        }

        if (count($attempts) >= KK_LOGIN_MAX_FAILURES) {
            $remaining = KK_LOGIN_WINDOW_SECONDS - ($now - min($attempts));
            if ($remaining > 0) {
                kk_close_rate_limit_file($userLock);
                $mins = max(1, (int)ceil($remaining / 60));
                return [
                    'blocked'     => true,
                    'message'     => "Too many failed login attempts. Please try again in {$mins} minute(s).",
                    'retry_after' => $remaining
                ];
            }
        }
        kk_close_rate_limit_file($userLock);
    }

    // Secondary check: IP-level threshold across rotating usernames
    $ipFile = kk_get_rate_limit_file_path('ip', $ip);
    $ipLock = kk_open_rate_limit_file($ipFile);
    if ($ipLock) {
        $ipLockout = (int)($ipLock['data']['lockout_until'] ?? 0);
        $ipAttempts = kk_prune_rate_limit_timestamps($ipLock['data']['attempts'] ?? [], KK_LOGIN_WINDOW_SECONDS, $now);

        if ($ipLockout > $now) {
            $remaining = $ipLockout - $now;
            kk_close_rate_limit_file($ipLock);
            $mins = max(1, (int)ceil($remaining / 60));
            return [
                'blocked'     => true,
                'message'     => "Too many failed login attempts from this network. Please try again in {$mins} minute(s).",
                'retry_after' => $remaining
            ];
        }

        if (count($ipAttempts) >= KK_LOGIN_IP_MAX_FAILURES) {
            $remaining = KK_LOGIN_WINDOW_SECONDS - ($now - min($ipAttempts));
            if ($remaining > 0) {
                kk_close_rate_limit_file($ipLock);
                $mins = max(1, (int)ceil($remaining / 60));
                return [
                    'blocked'     => true,
                    'message'     => "Too many failed login attempts from this network. Please try again in {$mins} minute(s).",
                    'retry_after' => $remaining
                ];
            }
        }
        kk_close_rate_limit_file($ipLock);
    }

    return [
        'blocked'     => false,
        'message'     => '',
        'retry_after' => 0
    ];
}

/**
 * Records a failed login attempt for the user/IP combination and global IP.
 * Returns: ['blocked' => bool, 'message' => string, 'retry_after' => int, 'attempts' => int]
 */
function kk_record_login_failure(string $username): array
{
    $now = time();
    $normUser = strtolower(trim($username));
    $ip = kk_get_client_ip();

    $userKey = $normUser . '|' . $ip;
    $userFile = kk_get_rate_limit_file_path('user_ip', $userKey);

    $isBlocked = false;
    $retryAfter = 0;
    $attemptCount = 0;

    $userLock = kk_open_rate_limit_file($userFile);
    if ($userLock) {
        $attempts = kk_prune_rate_limit_timestamps($userLock['data']['attempts'] ?? [], KK_LOGIN_WINDOW_SECONDS, $now);
        $attempts[] = $now;
        $attemptCount = count($attempts);

        $lockoutUntil = 0;
        if ($attemptCount >= KK_LOGIN_MAX_FAILURES) {
            $lockoutUntil = $now + KK_LOGIN_WINDOW_SECONDS;
            $isBlocked = true;
            $retryAfter = KK_LOGIN_WINDOW_SECONDS;
        }

        kk_save_and_close_rate_limit_file($userLock, [
            'attempts'      => $attempts,
            'lockout_until' => $lockoutUntil,
            'last_failure'  => $now
        ]);
    }

    // Also record on global IP
    $ipFile = kk_get_rate_limit_file_path('ip', $ip);
    $ipLock = kk_open_rate_limit_file($ipFile);
    if ($ipLock) {
        $ipAttempts = kk_prune_rate_limit_timestamps($ipLock['data']['attempts'] ?? [], KK_LOGIN_WINDOW_SECONDS, $now);
        $ipAttempts[] = $now;
        $ipLockout = 0;
        if (count($ipAttempts) >= KK_LOGIN_IP_MAX_FAILURES) {
            $ipLockout = $now + KK_LOGIN_WINDOW_SECONDS;
            $isBlocked = true;
            $retryAfter = max($retryAfter, KK_LOGIN_WINDOW_SECONDS);
        }
        kk_save_and_close_rate_limit_file($ipLock, [
            'attempts'      => $ipAttempts,
            'lockout_until' => $ipLockout,
            'last_failure'  => $now
        ]);
    }

    if ($isBlocked) {
        $mins = max(1, (int)ceil($retryAfter / 60));
        return [
            'blocked'     => true,
            'message'     => "Too many failed login attempts. Please try again in {$mins} minute(s).",
            'retry_after' => $retryAfter,
            'attempts'    => $attemptCount
        ];
    }

    return [
        'blocked'     => false,
        'message'     => 'Invalid username or password.',
        'retry_after' => 0,
        'attempts'    => $attemptCount
    ];
}

/**
 * Resets the rate limit record for the user/IP upon successful login.
 */
function kk_clear_login_rate_limit(string $username): void
{
    $normUser = strtolower(trim($username));
    $ip = kk_get_client_ip();

    $userKey = $normUser . '|' . $ip;
    $userFile = kk_get_rate_limit_file_path('user_ip', $userKey);

    if (file_exists($userFile)) {
        $lock = kk_open_rate_limit_file($userFile);
        if ($lock) {
            kk_close_rate_limit_file($lock);
            @unlink($userFile);
        }
    }
}
