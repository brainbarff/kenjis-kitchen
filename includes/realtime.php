<?php
function realtime_secret(): string
{
    return (string)(getenv('REALTIME_SHARED_SECRET') ?: '');
}

function realtime_token(int $userId, string $username, string $role, int $ttl = 3600): string
{
    $secret = realtime_secret();
    if ($secret === '' || $userId < 1) return '';
    $encode = static function ($value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
    $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = $encode(json_encode(['sub' => $userId, 'uid' => $userId, 'username' => $username, 'role' => role_key($role), 'iat' => time(), 'exp' => time() + $ttl]));
    $signingInput = $header . '.' . $payload;
    return $signingInput . '.' . $encode(hash_hmac('sha256', $signingInput, $secret, true));
}

function realtime_publish(string $event, array $payload): void
{
    $secret = realtime_secret();
    if ($secret === '' || !function_exists('curl_init')) return;
    $body = json_encode(['event' => $event, 'payload' => $payload]);
    $curl = curl_init('http://127.0.0.1:' . (int)(getenv('REALTIME_PORT') ?: 8787) . '/internal/publish');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Realtime-Secret: ' . $secret],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 200,
        CURLOPT_TIMEOUT_MS => 500,
    ]);
    curl_exec($curl);
    curl_close($curl);
}
