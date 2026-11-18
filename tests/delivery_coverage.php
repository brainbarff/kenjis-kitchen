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

// Regression cases: shorthands, subdivisions, spelling variants, perimeter & outside landmarks
$withinCases = [
    ['B79 L34 shorthand', 'B79 L34 Florante at Laura St., Greater Lagro, QC', 14.729954, 121.066199],
    ['B/L slash shorthand', 'B4/L9 Misa de Gallo St, Brgy. Greater Lagro, Quezon City', 14.7268, 121.0655],
    ['12-A street number', '12-A Roma Ave, Greater Lagro, Quezon City', 14.7317, 121.0580],
    ['Minor typo Misa de Galo', 'B4 L9 Misa de Galo St, Brgy. Greater Lagro, QC', 14.7268, 121.0655],
    ['Spacing variant Botaflores', 'B1 L2 Botaflores St, Greater Lagro, QC', 14.7285, 121.0658],
    ['Ampersand Florante & Laura', 'Blk 79 Lot 34 Florante & Laura St, Greater Lagro, QC', 14.729954, 121.066199],
    ['Bronson St', 'Blk 5 Lot 2, Bronson St, Greater Lagro, Quezon City', 14.7265, 121.0660],
    ['Casa Milan Phase 5 (digit)', 'Blk 3 Lot 7, Casa Milan Phase 5, Greater Lagro, QC', 14.7270, 121.0620],
    ['Brittany Phase 4 (digit)', 'B2 L8 Brittany Phase 4, Greater Lagro, QC', 14.7276, 121.0618],
    ['Unmapped street inside Casa Milan', 'Blk 10 Lot 3, Emerald St, Casa Milan Phase V, Greater Lagro, QC', 14.7270, 121.0620],
    ['Phase-only inside Greater Lagro', 'B12 L4 Phase 2, Greater Lagro, Quezon City', 14.7265, 121.0660],
    ['Regalado Ave with Lagro subdivision', 'Unit 4B, Neopolitan Condominiums, Regalado Ave, Greater Lagro, QC', 14.7298, 121.0586],
    ['Ayala Malls Fairview Terraces', '2F Ayala Malls Fairview Terraces, Greater Lagro, QC', 14.7343, 121.0598],
    ['NU Fairview inside SM Fairview', 'NU Fairview, SM City Fairview, Greater Lagro, QC', 14.733137, 121.060285],
    ['Perimeter Saint Anne St (17.7m inside)', 'Blk 4 Lot 9 Saint Anne St, La Mesa Heights, Greater Lagro', 14.7279, 121.0713],
    ['Reverse-geocoded Misa de Gallo without Blk/Lot', 'Misa de Gallo Street, Barangay Greater Lagro, Quezon City', 14.7268, 121.0655],
    ['Reverse-geocoded SM City Fairview without Blk/Lot', 'SM City Fairview, Quezon City', 14.7340, 121.0575],
    ['Reverse-geocoded unmapped street + Greater Lagro', 'Emerald Street, Barangay Greater Lagro, Quezon City', 14.7265, 121.0660],
    ['Near inner border (>2m inside)', 'Blk 4 Lot 9 Police Station 16, Quirino Highway, Barangay Greater Lagro, Quezon City', 14.735474, 121.069424],
];
foreach ($withinCases as [$label, $addr, $cLat, $cLng]) {
    $res = verify_lagro_delivery_address($addr, '', $cLat, $cLng);
    coverage_check(
        $res['status'] === 'WITHIN_LAGRO' && $res['delivery_mode'] === 'LAGRO_FREE' && $res['delivery_fee'] === 0.0 && !empty($res['verification_token']),
        'Within Lagro accepted (' . $label . '): got ' . ($res['status'] ?? '') . '/' . ($res['reason_code'] ?? '')
    );
}

$outsideCases = [
    ['Robinsons Novaliches', 'Robinsons Novaliches, Quirino Highway, Pasong Putik, QC', 14.7365, 121.0565],
    ['Fairview Center Mall', 'Fairview Center Mall, Regalado Ave, North Fairview, QC', 14.7060, 121.0670],
    ['Camarin B/L shorthand', 'B12 L5 Camarin Road, Caloocan City', 14.7500, 121.0450],
    ['Commonwealth Ave outside without brgy', '456 Commonwealth Avenue, Quezon City', 14.6850, 121.0800],
    ['Commonwealth Ave reverse-geocoded without number', 'Commonwealth Avenue, Quezon City', 14.6850, 121.0800],
    ['Camarin Road reverse-geocoded without number', 'Camarin Road, Caloocan City', 14.7500, 121.0450],
    ['Dahlia Ave West Fairview without number', 'Dahlia Avenue, West Fairview, Quezon City', 14.7016, 121.0637],
    ['Shared street name outside without brgy', '123 Sampaguita St, Quezon City', 14.6950, 121.0500],
    ['Bagong Silang Phase Package', 'Phase 3 Package 2, Bagong Silang, Caloocan City', 14.7700, 121.0600],
];
foreach ($outsideCases as [$label, $addr, $cLat, $cLng]) {
    $res = verify_lagro_delivery_address($addr, '', $cLat, $cLng);
    coverage_check(
        $res['status'] === 'OUTSIDE_LAGRO' && $res['delivery_mode'] === 'CUSTOMER_BOOKED' && $res['delivery_fee'] === null && !empty($res['verification_token']),
        'Outside Lagro accepted (' . $label . '): got ' . ($res['status'] ?? '') . '/' . ($res['reason_code'] ?? '')
    );
}

$blockedCases = [
    ['Inside pin + outside West Fairview', 'Blk 4 Lot 9 Dahlia Ave, West Fairview, Quezon City', $lat, $lng, 'INSIDE_PIN_OUTSIDE_ADDRESS'],
    ['Conflicting Fairview + Greater Lagro', 'Blk 4 Lot 9 North Fairview, Greater Lagro, Quezon City', $lat, $lng, 'CONFLICTING_BARANGAY'],
    ['Landmark spoof near Lagro', 'Near Greater Lagro, Blk 4 Lot 9 Misa de Gallo St', $lat, $lng, 'LANDMARK_SPOOF'],
    ['Landmark spoof tapat ng', 'Tapat ng SM Fairview, Quirino Hwy, Greater Lagro', 14.7340, 121.0575, 'LANDMARK_SPOOF'],
    ['Bare Greater Lagro, Quezon City', 'Greater Lagro, Quezon City', $lat, $lng, 'INCOMPLETE_ADDRESS'],
    ['No Lagro context on unknown street', 'Blk 5 Lot 8, Mabuhay Street, Quezon City', $lat, $lng, 'UNVERIFIED_STREET'],
    ['Ambiguous Regalado Ave without Lagro', '123 Regalado Ave, Quezon City', 14.7295, 121.0612, 'UNVERIFIED_STREET'],
    ['Street-pin mismatch > 1100m', 'Blk 79 Lot 34 Sampaguita St, Greater Lagro, Quezon City', $lat, $lng, 'STREET_PIN_MISMATCH'],
    ['Outside pin + Greater Lagro text', $address, 14.7000, 121.0800, 'OUTSIDE_PIN_LAGRO_ADDRESS'],
    ['Incomplete outside address', 'Quezon City', 14.7000, 121.0800, 'INCOMPLETE_OUTSIDE_ADDRESS'],
    ['Incomplete outside city Caloocan', 'Caloocan City', 14.7500, 121.0450, 'INCOMPLETE_OUTSIDE_ADDRESS'],
];
foreach ($blockedCases as [$label, $addr, $cLat, $cLng, $expectedCode]) {
    $res = verify_lagro_delivery_address($addr, '', $cLat, $cLng);
    coverage_check(
        $res['status'] === 'NEEDS_CONFIRMATION' && ($res['reason_code'] ?? '') === $expectedCode && empty($res['verification_token']),
        'Blocked case (' . $label . '): expected ' . $expectedCode . ', got ' . ($res['status'] ?? '') . '/' . ($res['reason_code'] ?? '')
    );
}

// DB delivery_areas integration via in-memory SQLite (verified vs unverified DB rows)
$covDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$covDb->exec('CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$covDb->exec("INSERT INTO system_settings VALUES ('show_unverified_areas_to_customers', '0')");
$covDb->exec('CREATE TABLE delivery_areas (id INTEGER PRIMARY KEY, barangay TEXT, subdivision TEXT, street_name TEXT, is_verified INTEGER, is_active INTEGER)');
$covDb->exec("INSERT INTO delivery_areas VALUES (1, 'Greater Lagro', 'B-Streets', 'Bronson St', 1, 1), (2, 'Greater Lagro', 'Custom', 'Custom Verified Alley', 1, 1), (3, 'Greater Lagro', 'Pending', 'Pending Alley St', 0, 1)");
coverage_check(
    verify_lagro_delivery_address('Blk 5 Lot 2 Bronson St, Greater Lagro, QC', '', 14.7265, 121.0660, $covDb)['status'] === 'WITHIN_LAGRO',
    'Verified DB street Bronson St accepted'
);
coverage_check(
    verify_lagro_delivery_address('Blk 2 Lot 3 Custom Verified Alley, Quezon City', '', 14.7265, 121.0660, $covDb)['status'] === 'WITHIN_LAGRO',
    'Verified DB-only area accepted even without JSON geometry'
);
$pendingRes = verify_lagro_delivery_address('Blk 2 Lot 3 Pending Alley St, Greater Lagro, QC', '', 14.7265, 121.0660, $covDb);
coverage_check(
    $pendingRes['status'] === 'NEEDS_CONFIRMATION' && ($pendingRes['reason_code'] ?? '') === 'UNVERIFIED_DELIVERY_AREA',
    'Unverified DB area still requires confirmation when toggle is off'
);

// Reverse & forward geocoding deterministic offline fallback + round-trip verification consistency
$_ENV['KK_SKIP_NETWORK_GEOCODE'] = '1';
$revInside = kk_reverse_geocode_delivery_point(14.729954, 121.066199);
coverage_check(
    $revInside['pin_inside_boundary'] === true && !empty($revInside['address']) && verify_lagro_delivery_address($revInside['address'], '', 14.729954, 121.066199)['status'] === 'WITHIN_LAGRO',
    'Offline reverse geocode inside Lagro verifies cleanly as WITHIN_LAGRO'
);
$revOutside = kk_reverse_geocode_delivery_point(14.7500, 121.0450);
coverage_check(
    $revOutside['pin_inside_boundary'] === false && !empty($revOutside['address']) && verify_lagro_delivery_address($revOutside['address'], '', 14.7500, 121.0450)['status'] === 'OUTSIDE_LAGRO',
    'Offline reverse geocode outside Lagro verifies cleanly as OUTSIDE_LAGRO'
);
$fwdInside = kk_forward_geocode_delivery_address('Blk 4 Lot 9 Misa de Gallo St, Greater Lagro, Quezon City');
coverage_check(
    !empty($fwdInside['found']) && $fwdInside['pin_inside_boundary'] === true,
    'Forward geocode resolves Greater Lagro street inside polygon'
);
$fwdOutside = kk_forward_geocode_delivery_address('Robinsons Novaliches, Pasong Putik, Quezon City');
coverage_check(
    !empty($fwdOutside['found']) && $fwdOutside['pin_inside_boundary'] === false,
    'Forward geocode resolves Robinsons Novaliches outside polygon'
);
unset($_ENV['KK_SKIP_NETWORK_GEOCODE']);

session_destroy();
echo "PASS: $checks delivery coverage checks (no orders or database writes).\n";
