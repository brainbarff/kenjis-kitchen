<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/includes/delivery_tracking.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$checks = 0;
function coverage_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
$address = 'Blk 79 Lot 34 Florante at Laura St., Greater Lagro, Quezon City';
$lat = 14.729954;
$lng = 121.066199;
$inside = verify_lagro_delivery_address($address, '', $lat, $lng);
coverage_check($inside['status'] === 'WITHIN_LAGRO' && $inside['delivery_fee'] === 0.0, 'Inside location is free');
$metro = verify_lagro_delivery_address($address . ', Metro Manila', '', $lat, $lng);
coverage_check($metro['status'] === 'WITHIN_LAGRO', 'Metro Manila is not Manila city');
$manila = verify_lagro_delivery_address($address . ', City of Manila', '', $lat, $lng);
coverage_check(!$manila['eligible_free'], 'Conflicting Manila city is rejected');
$outsideAddress = 'Lot 12 Block 5, Dahlia Avenue, West Fairview Subdivision, Barangay Fairview, Quezon City';
$outside = verify_lagro_delivery_address($outsideAddress, '', 14.70, 121.08);
coverage_check($outside['status'] === 'OUTSIDE_LAGRO' && $outside['delivery_fee'] === null, 'Outside courier fee is unknown, not free');
foreach ([$outsideAddress, $outsideAddress . ', Greater Lagro', 'Lagro', 'Greater Lagro', 'asdfghjkl 12345'] as $invalid) {
    coverage_check(!verify_lagro_delivery_address($invalid, '', $lat, $lng)['eligible_free'], 'Invalid/conflicting address denied: ' . $invalid);
}
coverage_check(verify_lagro_delivery_address($address)['status'] === 'SELECT_LOCATION', 'Text alone does not verify coverage');
$boundary = kk_delivery_boundary_data();
$edge = $boundary['geometry']['coordinates'][0][0];
coverage_check(verify_lagro_delivery_address($address, '', $edge[1], $edge[0])['status'] === 'NEEDS_CONFIRMATION', 'Boundary edge is uncertain');
coverage_check(verify_lagro_delivery_address($address, '', 91, 200)['status'] === 'NEEDS_CONFIRMATION', 'Invalid coordinates rejected');
coverage_check(verify_lagro_delivery_address($address, '', $lat, $lng, null, __DIR__ . '/nonexistent-boundary.json')['status'] === 'OUTAGE', 'Missing boundary fails closed');
$token = $inside['verification_token'];
$version = $inside['boundary_version'];
coverage_check(kk_verify_delivery_verification_token($token, $address, $lat, $lng, 'LAGRO_FREE', $version)['ok'], 'Valid token accepted');
foreach ([[$address . ' edited', $lat, $lng, 'LAGRO_FREE', $version], [$address, $lat + .01, $lng, 'LAGRO_FREE', $version], [$address, $lat, $lng, 'CUSTOMER_BOOKED', $version], [$address, $lat, $lng, 'LAGRO_FREE', 'changed-boundary']] as $args) {
    coverage_check(!kk_verify_delivery_verification_token($token, ...$args)['ok'], 'Edited destination/mode/boundary rejects token');
}
coverage_check(!kk_verify_delivery_verification_token($token . '0', $address, $lat, $lng, 'LAGRO_FREE', $version)['ok'], 'Tampered signature rejected');
$userToken = kk_issue_delivery_verification_token($inside, $address, $lat, $lng, 42);
coverage_check(!kk_verify_delivery_verification_token($userToken, $address, $lat, $lng, 'LAGRO_FREE', $version, 43)['ok'], 'Different customer rejected');
coverage_check(!kk_verify_delivery_verification_token($userToken, $address, $lat, $lng, 'LAGRO_FREE', $version, 0)['ok'], 'Signed-in token cannot become anonymous');
$oldId = session_id();
session_regenerate_id();
coverage_check(!kk_verify_delivery_verification_token($userToken, $address, $lat, $lng, 'LAGRO_FREE', $version, 42)['ok'], 'Different session rejected even for same customer');
foreach (['http://example.com/track', 'javascript:alert(1)', 'https://user:password@example.com/track', 'https://127.0.0.1/track'] as $url) {
    $rejected = false;
    try { normalize_delivery_tracking_url($url); } catch (InvalidArgumentException $e) { $rejected = true; }
    coverage_check($rejected, 'Unsafe tracking URL rejected');
}
coverage_check(normalize_delivery_tracking_url('https://example.com/track/123') !== null, 'HTTPS link accepted without fetching');
foreach (['CUSTOMER', 'STORE'] as $booker) {
    coverage_check(kk_resolve_courier_booking_by(['delivery_mode' => 'CUSTOMER_BOOKED', 'courier_booking_by' => $booker]) === $booker, 'Courier responsibility preserved');
}
session_destroy();
echo "PASS: $checks delivery coverage checks (no orders or database writes).\n";
