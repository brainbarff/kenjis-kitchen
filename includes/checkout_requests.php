<?php
declare(strict_types=1);

/** Canonical request identity excludes only transport/session fields. */
function checkout_request_hash(array $payload, ?string $proofHash = null): string
{
    unset($payload['csrf_token'], $payload['request_key'], $payload['verification_token']);
    $sort = static function ($value) use (&$sort) {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        return array_map($sort, $value);
    };
    return hash('sha256', json_encode([$sort($payload), $proofHash], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

function checkout_request_key($value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
        throw new InvalidArgumentException('Reload checkout before placing your order.');
    }
    return $value;
}

/**
 * Claim before touching stock. The unique key serializes simultaneous retries.
 * The claim and saved response commit or roll back with the order itself.
 */
function checkout_request_claim(PDO $conn, string $channel, int $actorId, string $key, string $hash): ?array
{
    if (!$conn->inTransaction()) throw new LogicException('Checkout requires a transaction.');
    $insert = $conn->prepare('INSERT INTO checkout_requests (channel, actor_id, request_key, request_hash) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE request_key = VALUES(request_key)');
    $insert->execute([$channel, $actorId, $key, $hash]);
    $select = $conn->prepare('SELECT request_hash, response_json FROM checkout_requests WHERE channel = ? AND actor_id = ? AND request_key = ? FOR UPDATE');
    $select->execute([$channel, $actorId, $key]);
    $request = $select->fetch(PDO::FETCH_ASSOC);
    if (!$request || !hash_equals($request['request_hash'], $hash)) {
        throw new DomainException('This checkout attempt has different details. Restore the original details to retry, or start a new order.');
    }
    return $request['response_json'] === null ? null : json_decode($request['response_json'], true, 512, JSON_THROW_ON_ERROR);
}

function checkout_request_complete(PDO $conn, string $channel, int $actorId, string $key, int $orderId, array $response): void
{
    if (!$conn->inTransaction()) throw new LogicException('Checkout requires a transaction.');
    $save = $conn->prepare('UPDATE checkout_requests SET order_id = ?, response_json = ? WHERE channel = ? AND actor_id = ? AND request_key = ? AND response_json IS NULL');
    $save->execute([$orderId, json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $channel, $actorId, $key]);
    if ($save->rowCount() !== 1) throw new RuntimeException('Unable to save checkout confirmation.');
}

/** Post-commit notifications must never turn a saved order into a failed checkout. */
function checkout_after_commit(callable $task): bool
{
    try {
        $task();
        return true;
    } catch (Throwable $e) {
        error_log('Saved checkout notification failed: ' . $e->getMessage());
        return false;
    }
}

/** A connection can fail after MySQL commits but before PHP receives the result. */
function checkout_discard_proof(?string $path, bool $commitAttempted): void
{
    if (!$commitAttempted && $path !== null && is_file($path)) @unlink($path);
}

/** Calendar-day pickup slots in Philippine time; Sunday is entirely closed. */
function kk_pickup_slots(?DateTimeImmutable $now = null, int $minLeadMinutes = 30, int $dayCount = 2): array
{
    $tz = new DateTimeZone('Asia/Manila');
    $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    $earliest = $now->modify('+' . max(30, $minLeadMinutes) . ' minutes');
    $days = [];
    for ($offset = 0; $offset < max(2, min(7, $dayCount)); $offset++) {
        $date = $now->setTime(0, 0)->modify('+' . $offset . ' days');
        $dow = (int)$date->format('N');
        $slots = [];
        for ($minute = 0; $minute < 1440; $minute += 30) {
            // Tue–Sat have the previous day's overnight hours. Saturday ends at midnight.
            $open = $dow !== 7 && ($minute >= 540 || ($dow >= 2 && $minute < 180));
            $at = $date->modify('+' . $minute . ' minutes');
            if ($open && $at >= $earliest) {
                $slots[] = ['value' => $at->format('Y-m-d H:i:s'), 'label' => $at->format('g:i A')];
            }
        }
        $label = $offset === 0 ? 'Today' : ($offset === 1 ? 'Tomorrow' : $date->format('D, M j'));
        $days[] = ['date' => $date->format('Y-m-d'), 'label' => $label, 'slots' => $slots];
    }
    return $days;
}

function kk_validate_pickup_time($value, ?DateTimeImmutable $now = null, bool $requiresAdvance = false): string
{
    $minLeadMinutes = $requiresAdvance ? 1440 : 30;
    $dayCount = $requiresAdvance ? 4 : 2;
    if (is_string($value)) {
        foreach (kk_pickup_slots($now, $minLeadMinutes, $dayCount) as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['value'] === $value) return $value;
            }
        }
    }
    if ($requiresAdvance) {
        throw new InvalidArgumentException('Bilao Medium, Large, and Extra Large sizes require 1 to 2 days advance reservation. Choose an advance pickup slot at least 24 hours ahead.');
    }
    throw new InvalidArgumentException('Choose an available pickup time for today or tomorrow. Allow at least 30 minutes for preparation.');
}
