<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/rate_limiter.php';

/** Validate a customer-provided external tracking link without fetching it server-side. */
function normalize_delivery_tracking_url(?string $value): ?string
{
    $url = trim((string)$value);
    if ($url === '') return null;
    if (strlen($url) > 1000 || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Enter a valid HTTPS delivery tracking link.');
    }
    $parts = parse_url($url);
    if (!is_array($parts)) throw new InvalidArgumentException('Enter a valid HTTPS delivery tracking link.');
    $host = strtolower((string)($parts['host'] ?? ''));
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || $host === ''
        || !str_contains($host, '.')
        || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        || filter_var($host, FILTER_VALIDATE_IP)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])
        || str_ends_with($host, '.local')) {
        throw new InvalidArgumentException('Use a public HTTPS tracking link from the delivery provider.');
    }
    return $url;
}

/**
 * Resolve who books the third-party courier ('CUSTOMER', 'STORE', or 'NONE'),
 * preserving compatibility with historical orders that stored this in notes.
 */
function kk_resolve_courier_booking_by(array $order): string
{
    $mode = strtoupper(trim((string)($order['delivery_mode'] ?? '')));
    $fMode = strtoupper(trim((string)($order['fulfillment_mode'] ?? '')));
    if ($mode !== 'CUSTOMER_BOOKED' && $fMode !== 'OUTSIDE') {
        return 'NONE';
    }
    $structured = strtoupper(trim((string)($order['courier_booking_by'] ?? '')));
    if (in_array($structured, ['CUSTOMER', 'STORE'], true)) {
        return $structured;
    }
    $notes = (string)($order['notes'] ?? '');
    if (stripos($notes, 'Courier booking: Kenjis staff') !== false || stripos($notes, 'Ask Kenji') !== false || stripos($notes, 'Ask the store') !== false) {
        return 'STORE';
    }
    return 'CUSTOMER';
}

/**
 * Load and validate the authoritative Barangay Greater Lagro boundary dataset.
 * Throws RuntimeException if the boundary dataset is missing or invalid.
 */
function kk_delivery_boundary_data(?string $overridePath = null): array
{
    static $cached = null;
    static $cachedPath = null;

    $path = $overridePath ?? (defined('KK_DELIVERY_BOUNDARY_FILE') ? KK_DELIVERY_BOUNDARY_FILE : (dirname(__DIR__) . '/config/greater_lagro_boundary.json'));
    if ($overridePath === null && $cached !== null && $cachedPath === $path) {
        return $cached;
    }

    if (!is_string($path) || $path === '' || !is_readable($path)) {
        throw new RuntimeException('Delivery boundary dataset is unavailable.');
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Delivery boundary dataset is empty.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)
        || ($data['geometry']['type'] ?? '') !== 'Polygon'
        || !is_array($data['geometry']['coordinates'][0] ?? null)
        || count($data['geometry']['coordinates'][0]) < 20
        || empty($data['boundary_version'])) {
        throw new RuntimeException('Delivery boundary dataset is malformed.');
    }

    $ring = $data['geometry']['coordinates'][0];
    $ringHash = substr(hash('sha256', json_encode($ring)), 0, 12);
    $data['canonical_version'] = $data['boundary_version'] . '-' . $ringHash;

    if ($overridePath === null) {
        $cached = $data;
        $cachedPath = $path;
    }

    return $data;
}

/**
 * Check if a point (lat, lng) lies inside or on the boundary of a polygon ring ([lng, lat][]).
 */
function kk_point_in_polygon(float $lat, float $lng, array $ring): bool
{
    $n = count($ring);
    if ($n < 3) return false;

    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float)$ring[$i][0];
        $yi = (float)$ring[$i][1];
        $xj = (float)$ring[$j][0];
        $yj = (float)$ring[$j][1];

        // Exact vertex match
        if (abs($xi - $lng) < 1e-9 && abs($yi - $lat) < 1e-9) {
            return true;
        }

        $intersect = (($yi > $lat) !== ($yj > $lat))
            && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-15) + $xi);
        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}

/**
 * Calculate shortest distance in meters from (lat, lng) to any segment of the polygon ring ([lng, lat][]).
 */
function kk_distance_to_polygon_edge_meters(float $lat, float $lng, array $ring): float
{
    $n = count($ring);
    if ($n < 2) return INF;

    $metersPerDegLat = 110574.27;
    $metersPerDegLng = 111319.49 * cos(deg2rad($lat));

    $minDistSq = INF;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $x1 = ((float)$ring[$j][0] - $lng) * $metersPerDegLng;
        $y1 = ((float)$ring[$j][1] - $lat) * $metersPerDegLat;
        $x2 = ((float)$ring[$i][0] - $lng) * $metersPerDegLng;
        $y2 = ((float)$ring[$i][1] - $lat) * $metersPerDegLat;

        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lenSq = $dx * $dx + $dy * $dy;

        if ($lenSq <= 1e-12) {
            $distSq = $x1 * $x1 + $y1 * $y1;
        } else {
            // Projection of origin (0,0) onto segment P1 -> P2
            $t = -($x1 * $dx + $y1 * $dy) / $lenSq;
            $t = max(0.0, min(1.0, $t));
            $projX = $x1 + $t * $dx;
            $projY = $y1 + $t * $dy;
            $distSq = $projX * $projX + $projY * $projY;
        }

        if ($distSq < $minDistSq) {
            $minDistSq = $distSq;
        }
    }

    return sqrt($minDistSq);
}

/**
 * Calculate shortest distance in meters from (lat, lng) to a feature's bounding box in Greater Lagro.
 */
function kk_distance_to_feature_bbox_meters(float $lat, float $lng, array $feature): float
{
    $minLat = (float)($feature['min_lat'] ?? $feature['lat'] ?? 0);
    $maxLat = (float)($feature['max_lat'] ?? $feature['lat'] ?? 0);
    $minLng = (float)($feature['min_lng'] ?? $feature['lng'] ?? 0);
    $maxLng = (float)($feature['max_lng'] ?? $feature['lng'] ?? 0);

    $clampedLat = max($minLat, min($maxLat, $lat));
    $clampedLng = max($minLng, min($maxLng, $lng));

    $dLatMeters = ($lat - $clampedLat) * 110574.27;
    $dLngMeters = ($lng - $clampedLng) * 111319.49 * cos(deg2rad($lat));

    return sqrt($dLatMeters * $dLatMeters + $dLngMeters * $dLngMeters);
}

/**
 * Normalize text for street and locality matching.
 */
function kk_normalize_street_tokens(string $text): string
{
    $norm = mb_strtolower($text, 'UTF-8');
    // Normalize St. before saint names to 'saint' before street suffix normalization
    $norm = preg_replace('/\bst\.?\s+(anne|james|joseph|mary|anthony|paul|peter|john|jude|therese|luke|mark|matthew)\b/iu', 'saint $1', $norm) ?? $norm;
    $norm = preg_replace('/\bsta\.?\s+/iu', 'santa ', $norm) ?? $norm;
    $norm = preg_replace('/\bsto\.?\s+/iu', 'santo ', $norm) ?? $norm;
    $norm = preg_replace('/\bascencion\b/iu', 'ascension', $norm) ?? $norm;
    $norm = preg_replace('/\b(str\.?|st\.?|street)\b/iu', 'street', $norm) ?? $norm;
    $norm = preg_replace('/\b(ave\.?|av\.?|avenue)\b/iu', 'avenue', $norm) ?? $norm;
    $norm = preg_replace('/\b(rd\.?|road)\b/iu', 'road', $norm) ?? $norm;
    $norm = preg_replace('/\b(dr\.?|drive)\b/iu', 'drive', $norm) ?? $norm;
    $norm = preg_replace('/\b(ext\.?|extension)\b/iu', 'extension', $norm) ?? $norm;
    $norm = preg_replace('/\b(subd\.?|subdivision)\b/iu', 'subdivision', $norm) ?? $norm;
    $norm = preg_replace('/\b(brgy\.?|bgy\.?|barangay)\b/iu', 'barangay', $norm) ?? $norm;
    $norm = preg_replace('/[^a-z0-9\s\-]/u', ' ', $norm) ?? $norm;
    $norm = preg_replace('/\s+/u', ' ', trim($norm)) ?? $norm;
    return $norm;
}

/**
 * Analyze written delivery address against Greater Lagro streets, subdivisions,
 * outside localities, and spoofing/incompleteness rules.
 */
function kk_analyze_delivery_address_text(string $address, array $boundaryData, ?PDO $conn = null): array
{
    $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($address)) ?? '');
    $norm = kk_normalize_street_tokens($clean);

    // 1. Detect explicit outside-Lagro localities, barangays, subdivisions, and cities
    //    even if the customer also inserts "Greater Lagro" or "Lagro".
    $outsideRules = [
        'STI Fairview' => '/\bsti\s*fairview\b/i',
        'West Fairview' => '/\bwest\s+fairview\b/i',
        'North Fairview' => '/\bnorth\s+fairview\b/i',
        'East Fairview' => '/\beast\s+fairview\b/i',
        'South Fairview' => '/\bsouth\s+fairview\b/i',
        'Barangay Fairview / Fairview Subdivision' => '/\b(barangay|brgy\.?|bgy\.?)\s+fairview\b|\bfairview\s+(subd\.?|subdivision|park|proper)\b/i',
        'Fairview' => '/(?<!sm\s)(?<!sm\scity\s)(?<!toyota\s)\bfairview(?!\s+terraces)\b/i',
        'Fairview Center Mall (FCM)' => '/\bfcm\b|\bfairview\s+center\s+mall\b/i',
        'Dahlia Avenue (West Fairview)' => '/\bdahlia\s+(ave|avenue|st|street|fairview)\b|\bfev\b|\bfairview\s+general\b/i',
        'Regalado Avenue (Fairview)' => '/\bregalado\s+(ave|avenue)\b/i',
        'Barangay Commonwealth' => '/\b(barangay|brgy\.?|bgy\.?)\s+commonwealth\b|\bcommonwealth\s+(heights|market)\b/i',
        'Batasan / Payatas / Bagong Silang' => '/\bbatasan\b|\bpayatas\b|\bbagong\s+silang\b|\bholy\s+spirit\b/i',
        'Caloocan (Camarin / Deparo / Tala / Llano)' => '/\bcamarin\b|\bdeparo\b|\bllano\b|\btala\b|\bmalaria\b|\bamparo\b|\bcaloocan\b/i',
        'San Jose del Monte / Bulacan' => '/\bsan\s+jose\s+del\s+monte\b|\bsjdm\b|\btungko\b|\btungkong\s+mangga\b|\bmuzon\b|\bbulacan\b|\bsapang\s+palay\b|\bmarilao\b|\bmeycauayan\b/i',
        'Novaliches Proper / Sta. Monica / Sta. Lucia' => '/\bnovaliches\s+proper\b|\bbayan\s+novaliches\b|\bsanta\s+monica\b|\bsta\.?\s*monica\b|\bsanta\s+lucia\b|\bsta\.?\s*lucia\b/i',
        'Neighboring QC Barangays (Kaligayahan / Pasong Putik / Sauyo / Gulod / Bagbag)' => '/\bkaligayahan\b|\bpasong\s+putik\b|\bsan\s+bartolome\b|\bgulod\b|\bsauyo\b|\bbagbag\b|\btalipapa\b|\bnagkaisang\s+nayon\b|\bcapri\b/i',
        'Other Metro Manila Cities / Districts' => '/\btandang\s+sora\b|\bculiat\b|\bdiliman\b|\bmatandang\s+balara\b|\bcubao\b|\bmanila\b|\bmakati\b|\bpasig\b|\btaguig\b|\bmarikina\b|\bvalenzuela\b|\bmandaluyong\b|\bparanaque\b|\blas\s+pinas\b|\bmuntinlupa\b|\bsan\s+mateo\b|\brodriguez\b|\bmontalban\b|\bantipolo\b/i',
        'Outside Lagro' => '/\boutside\s+(greater\s+)?lagro\b/i',
    ];

    // Metro Manila is the region containing Greater Lagro, not Manila city.
    $localityText = preg_replace('/\bmetro\s+manila\b/i', 'NCR', $clean) ?? $clean;
    $outsideLocalities = [];
    foreach ($outsideRules as $label => $pattern) {
        if (preg_match($pattern, $localityText)) {
            $outsideLocalities[] = $label;
        }
    }
    // Deduplicate Fairview labels if a specific Fairview label already matched
    if (count($outsideLocalities) > 1 && in_array('Fairview', $outsideLocalities, true)) {
        $outsideLocalities = array_values(array_filter($outsideLocalities, static fn($l) => $l !== 'Fairview'));
    }

    // 2. Detect landmark-reference or directional spoofing phrases
    $landmarkSpoofPatterns = [
        '/\btapat\s+(ni|ng)\b/i',
        '/\b(near|malapit\s+sa|across|before|after|past|lampas\s+ng|boundary\s+of|going\s+to|papuntang|from|galing|beside|katabi\s+ng|likod\s+ng)\s+(brgy\.?\s*|barangay\s*)?(greater\s+)?lagro\b/i',
        '/^(near|malapit\s+sa|beside|across|tapat\s+ng)\s+/i',
    ];
    $hasLandmarkSpoof = false;
    foreach ($landmarkSpoofPatterns as $spoofPattern) {
        if (preg_match($spoofPattern, $clean)) {
            $hasLandmarkSpoof = true;
            break;
        }
    }

    // 3. Explicit Greater Lagro locality text
    $hasExplicitLagroText = (bool)preg_match(
        '/\b(barangay\s+greater\s+lagro|brgy\.?\s+greater\s+lagro|greater\s+lagro|lagro\s+subdivision|lagro\s+subd\.?|brgy\.?\s+lagro|barangay\s+lagro)\b/i',
        $clean
    );

    // 4. Concrete house / block / lot / unit / street-number structure
    $hasHouseOrLot = (bool)preg_match(
        '/\b(blk\.?\s*[0-9a-z]+|block\s*[0-9a-z]+|lot\s*[0-9a-z]+|unit\s*[0-9a-z\-]+|apt\.?\s*[0-9a-z\-]+|rm\.?\s*[0-9a-z\-]+|room\s*[0-9a-z\-]+|door\s*[0-9a-z\-]+|house\s*(no\.?|#)?\s*[0-9a-z\-]+|bldg\.?\s*[0-9a-z]+|building\s*[0-9a-z]+|no\.?\s*\d+|#\s*\d+)\b/i',
        $clean
    ) || (bool)preg_match('/(?:^|,\s*)\d{1,5}[a-z]?(?:-\d+)?\s+[a-z]{3,}/i', $clean);

    // 5. Match against local Greater Lagro streets/subdivisions/landmarks & delivery_areas table
    $localFeatures = $boundaryData['local_features'] ?? [];
    $dbAreas = [];
    $allowUnverifiedAreas = false;
    if ($conn instanceof PDO) {
        try {
            $allowUnverifiedAreas = ((int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'show_unverified_areas_to_customers'")->fetchColumn() ?: 0) === 1);
            $dbAreas = $conn->query("SELECT id, barangay, subdivision, street_name, is_verified FROM delivery_areas WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $dbAreas = [];
        }
    }

    $matchedFeatures = [];
    $matchedUnverifiedDbArea = null;

    foreach ($localFeatures as $feat) {
        $fName = (string)($feat['name'] ?? '');
        if ($fName === '') continue;
        $fNorm = kk_normalize_street_tokens($fName);
        $fBase = trim(preg_replace('/\b(street|avenue|road|drive|extension|bend|loop|subdivision)\b/u', '', $fNorm) ?? '');
        if (mb_strlen($fBase) < 3) continue;

        $matched = false;
        if (preg_match('/\b' . preg_quote($fNorm, '/') . '\b/u', $norm)) {
            $matched = true;
        } elseif (mb_strlen($fBase) >= 4 && preg_match('/\b' . preg_quote($fBase, '/') . '\b/u', $norm)) {
            // If matching base name, ensure either it has a street/subdivision keyword or is >= 5 chars
            if (($feat['kind'] ?? '') !== 'street' || preg_match('/\b' . preg_quote($fBase, '/') . '\s+(street|avenue|road|drive|extension|bend|loop)\b/u', $norm) || mb_strlen($fBase) >= 6) {
                $matched = true;
            }
        }

        if ($matched) {
            // Avoid matching generic "Commonwealth Avenue" or "Mindanao Avenue" as a Lagro-specific proof if outside context exists
            $matchedFeatures[$fNorm] = $feat;
        }
    }

    foreach ($dbAreas as $area) {
        $stName = trim((string)($area['street_name'] ?? ''));
        if ($stName === '') continue;
        $stClean = trim(preg_replace('/\(subdivision\)/i', '', $stName) ?? $stName);
        $stNorm = kk_normalize_street_tokens($stClean);
        $stBase = trim(preg_replace('/\b(street|avenue|road|drive|extension|bend|loop|subdivision)\b/u', '', $stNorm) ?? '');
        if (mb_strlen($stBase) < 3) continue;

        if (preg_match('/\b' . preg_quote($stNorm, '/') . '\b/u', $norm) || (mb_strlen($stBase) >= 5 && preg_match('/\b' . preg_quote($stBase, '/') . '\b/u', $norm))) {
            $isVer = (int)($area['is_verified'] ?? 0) === 1;
            if (!$isVer && !$allowUnverifiedAreas) {
                $matchedUnverifiedDbArea = $stName;
            } elseif (!isset($matchedFeatures[$stNorm])) {
                // A database street name without mapped geometry cannot prove proximity.
                $foundCoord = null;
                foreach ($localFeatures as $lf) {
                    if (stripos($lf['name'], $stBase) !== false) {
                        $foundCoord = $lf;
                        break;
                    }
                }
                if ($foundCoord !== null) {
                    $matchedFeatures[$stNorm] = $foundCoord;
                } else {
                    $matchedUnverifiedDbArea = $stName;
                }
            }
        }
    }

    // 6. Extract explicit "<Name> Street/Avenue/Road/Drive/Bend/Loop" phrases to detect invented/unrecognized streets
    $unrecognizedStreets = [];
    if (preg_match_all('/\b([a-z][a-z0-9\-\s]{1,28}?)\s+(street|avenue|road|drive|bend|loop)\b/u', $norm, $streetMatches, PREG_SET_ORDER)) {
        $ignorePrefixes = '/^(?:blk|block|lot|phase|unit|house|apt|room|door|no|barangay|greater|lagro|quezon|city|metro|manila|subdivision|main|north|south|east|west)\s+/u';
        foreach ($streetMatches as $sm) {
            $candidatePrefix = trim($sm[1]);
            // Keep last 1-3 words before the street suffix
            $words = explode(' ', $candidatePrefix);
            if (count($words) > 3) {
                $words = array_slice($words, -3);
            }
            $cleanedWords = [];
            foreach ($words as $w) {
                if (in_array($w, ['blk', 'block', 'lot', 'phase', 'unit', 'house', 'apt', 'room', 'door', 'no', 'barangay', 'greater', 'lagro', 'quezon', 'city', 'subdivision', 'and', 'at', 'de', 'del', 'ng', 'ni', 'sa'], true) && empty($cleanedWords)) {
                    continue;
                }
                if (preg_match('/^\d+[a-z]?$/', $w) && empty($cleanedWords)) {
                    continue;
                }
                $cleanedWords[] = $w;
            }
            $candBase = trim(implode(' ', $cleanedWords));
            if ($candBase === '' || mb_strlen($candBase) < 3 || preg_match('/^\d+$/', $candBase)) {
                continue;
            }
            $candFull = $candBase . ' ' . $sm[2];

            $isKnown = false;
            foreach ($matchedFeatures as $mKey => $mFeat) {
                $mBase = trim(preg_replace('/\b(street|avenue|road|drive|extension|bend|loop|subdivision)\b/u', '', $mKey) ?? '');
                if ($mBase !== '' && (str_contains($candBase, $mBase) || str_contains($mBase, $candBase))) {
                    $isKnown = true;
                    break;
                }
            }
            if (!$isKnown) {
                $unrecognizedStreets[] = $candFull;
            }
        }
    }

    // Generic street/subdivision structure check (for outside addresses as well)
    $hasStreetOrSubdStructure = (bool)preg_match(
        '/\b(street|avenue|road|drive|extension|bend|loop|subdivision|village|homes|park|phase|compound|condominium|highway|blvd|boulevard)\b/u',
        $norm
    ) || !empty($matchedFeatures);

    return [
        'clean_address' => $clean,
        'normalized_address' => $norm,
        'has_house_or_lot' => $hasHouseOrLot,
        'has_street_or_subd' => $hasStreetOrSubdStructure,
        'has_explicit_lagro_text' => $hasExplicitLagroText,
        'has_landmark_spoof' => $hasLandmarkSpoof,
        'outside_localities' => $outsideLocalities,
        'matched_features' => array_values($matchedFeatures),
        'matched_unverified_db_area' => $matchedUnverifiedDbArea,
        'unrecognized_streets' => array_values(array_unique($unrecognizedStreets)),
    ];
}

/**
 * Secret key for signing delivery coverage verification tokens.
 */
function kk_delivery_verification_secret(): string
{
    $envSecret = $_ENV['KK_DELIVERY_TOKEN_SECRET'] ?? getenv('KK_DELIVERY_TOKEN_SECRET');
    if (!is_string($envSecret) || strlen(trim($envSecret)) < 16) {
        $envSecret = $_ENV['DELIVERY_VERIFY_SECRET'] ?? getenv('DELIVERY_VERIFY_SECRET');
    }
    if (is_string($envSecret) && strlen(trim($envSecret)) >= 16) {
        return hash('sha256', trim($envSecret) . '|kk_delivery_v1');
    }
    // A random session key avoids predictable development database credentials.
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['delivery_verification_secret'])) {
        $_SESSION['delivery_verification_secret'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['delivery_verification_secret'];
}

/**
 * Issue a signed verification token binding the verification result to:
 * - normalized delivery address
 * - rounded coordinates (lat, lng)
 * - session / customer ID
 * - verified coverage status & delivery mode
 * - boundary version
 */
function kk_issue_delivery_verification_token(
    array $verification,
    string $address,
    float $lat,
    float $lng,
    int $customerId = 0
): ?string {
    $status = (string)($verification['status'] ?? '');
    if (!in_array($status, ['WITHIN_LAGRO', 'OUTSIDE_LAGRO'], true)) {
        return null;
    }

    $normAddress = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($address)) ?? ''), 'UTF-8');
    $uid = $customerId > 0 ? $customerId : (int)($_SESSION['customer_user_id'] ?? 0);
    $sid = session_status() === PHP_SESSION_ACTIVE ? session_id() : 'cli';

    $claims = [
        'v' => 1,
        'status' => $status,
        'mode' => (string)($verification['delivery_mode'] ?? ($status === 'WITHIN_LAGRO' ? 'LAGRO_FREE' : 'CUSTOMER_BOOKED')),
        'addr_hash' => hash('sha256', $normAddress),
        'lat' => number_format($lat, 6, '.', ''),
        'lng' => number_format($lng, 6, '.', ''),
        'bver' => (string)($verification['boundary_version'] ?? ''),
        'uid' => $uid,
        'sid_hash' => substr(hash('sha256', (string)$sid), 0, 16),
        'iat' => time(),
    ];

    $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
    $b64 = rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, kk_delivery_verification_secret());

    return $b64 . '.' . $sig;
}

/**
 * Validate a submitted delivery verification token against current order parameters.
 *
 * @return array{ok: bool, message: string, claims?: array}
 */
function kk_verify_delivery_verification_token(
    ?string $token,
    string $address,
    $lat,
    $lng,
    string $requestedMode,
    string $currentBoundaryVersion,
    int $customerId = 0
): array {
    $raw = trim((string)$token);
    if ($raw === '' || !str_contains($raw, '.')) {
        return [
            'ok' => false,
            'message' => 'Delivery coverage verification is missing or incomplete. Please confirm your location on the map.',
        ];
    }

    [$b64, $sig] = explode('.', $raw, 2);
    if ($b64 === '' || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
        return [
            'ok' => false,
            'message' => 'Invalid delivery coverage verification token. Please re-select your location on the map.',
        ];
    }

    $expectedSig = hash_hmac('sha256', $b64, kk_delivery_verification_secret());
    if (!hash_equals($expectedSig, $sig)) {
        return [
            'ok' => false,
            'message' => 'Delivery coverage verification token failed integrity check. Please re-confirm your destination.',
        ];
    }

    $padded = strtr($b64, '-_', '+/');
    $mod = strlen($padded) % 4;
    if ($mod > 0) {
        $padded .= str_repeat('=', 4 - $mod);
    }
    $claims = json_decode((string)base64_decode($padded, true), true);
    if (!is_array($claims)) {
        return [
            'ok' => false,
            'message' => 'Malformed delivery coverage verification token.',
        ];
    }

    $now = time();
    $iat = (int)($claims['iat'] ?? 0);
    if ($iat <= 0 || ($now - $iat) > 1800 || $iat > ($now + 60)) {
        return [
            'ok' => false,
            'message' => 'Delivery coverage verification has expired. Please re-confirm your destination on the map.',
        ];
    }

    if (($claims['bver'] ?? '') !== $currentBoundaryVersion) {
        return [
            'ok' => false,
            'message' => 'Delivery coverage boundary was updated. Please re-confirm your destination on the map.',
        ];
    }

    $uid = $customerId > 0 ? $customerId : (int)($_SESSION['customer_user_id'] ?? 0);
    $sid = session_status() === PHP_SESSION_ACTIVE ? session_id() : 'cli';
    $sidHash = substr(hash('sha256', (string)$sid), 0, 16);
    $tokenUid = (int)($claims['uid'] ?? 0);
    $tokenSidHash = (string)($claims['sid_hash'] ?? '');

    if ($tokenUid !== $uid) {
        return [
            'ok' => false,
            'message' => 'Delivery verification belongs to a different customer session. Please re-confirm your destination.',
        ];
    }
    if ($tokenSidHash === '' || !hash_equals($tokenSidHash, $sidHash)) {
        return [
            'ok' => false,
            'message' => 'Delivery verification session changed. Please re-confirm your destination on the map.',
        ];
    }

    $normAddress = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($address)) ?? ''), 'UTF-8');
    if (!hash_equals((string)($claims['addr_hash'] ?? ''), hash('sha256', $normAddress))) {
        return [
            'ok' => false,
            'message' => 'Your delivery address was edited after coverage was checked. Wait for the updated coverage check before submitting.',
        ];
    }

    if (!is_numeric($lat) || !is_numeric($lng)) {
        return [
            'ok' => false,
            'message' => 'Delivery map coordinates are missing.',
        ];
    }

    $latStr = number_format((float)$lat, 6, '.', '');
    $lngStr = number_format((float)$lng, 6, '.', '');
    if (($claims['lat'] ?? '') !== $latStr || ($claims['lng'] ?? '') !== $lngStr) {
        return [
            'ok' => false,
            'message' => 'Your selected map pin changed after coverage was checked. Wait for the updated coverage check before submitting.',
        ];
    }

    if (strtoupper((string)($claims['mode'] ?? '')) !== strtoupper(trim($requestedMode))) {
        return [
            'ok' => false,
            'message' => 'The submitted delivery mode does not match the verified coverage result.',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Verified.',
        'claims' => $claims,
    ];
}

/**
 * Rate limit coverage verification requests per IP/session (max 90 requests per 60 seconds).
 */
function kk_check_delivery_verify_rate_limit(): array
{
    if (PHP_SAPI === 'cli' && empty($_ENV['KK_FORCE_RATE_LIMIT_CHECK'])) {
        return ['blocked' => false, 'retry_after' => 0];
    }
    $now = time();
    $ip = kk_get_client_ip();
    $sid = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
    $key = $ip . '|' . substr($sid, 0, 24);
    $file = kk_get_rate_limit_file_path('delivery_cov', $key);
    $lock = kk_open_rate_limit_file($file);
    if (!$lock) {
        return ['blocked' => false, 'retry_after' => 0];
    }
    $window = 60;
    $maxRequests = 90;
    $hits = kk_prune_rate_limit_timestamps($lock['data']['hits'] ?? [], $window, $now);
    if (count($hits) >= $maxRequests) {
        $retryAfter = max(1, $window - ($now - min($hits)));
        kk_close_rate_limit_file($lock);
        return [
            'blocked' => true,
            'retry_after' => $retryAfter,
            'message' => 'Unable to check right now. Too many rapid coverage checks; please wait a few seconds.',
        ];
    }
    $hits[] = $now;
    kk_save_and_close_rate_limit_file($lock, ['hits' => $hits]);
    return ['blocked' => false, 'retry_after' => 0];
}

/**
 * Authoritative server-side delivery coverage & address consistency verification.
 *
 * Uses point-in-polygon calculation against the verified Barangay Greater Lagro
 * boundary (OSM Relation 1783169, PSGC 1381300141, ODbL 1.0), conservative 20m
 * boundary-edge buffering, and local street/locality consistency validation.
 *
 * Typed text or dropdown selection alone NEVER qualifies for free delivery.
 *
 * @param string $address Written delivery address
 * @param string $barangaySelection Optional legacy area selection
 * @param mixed $lat Selected destination latitude
 * @param mixed $lng Selected destination longitude
 * @param PDO|null $conn Optional database connection for delivery_areas check
 * @param string|null $overrideBoundaryPath Optional boundary file path for testing
 * @return array
 */
function verify_lagro_delivery_address(
    string $address,
    string $barangaySelection = '',
    $lat = null,
    $lng = null,
    ?PDO $conn = null,
    ?string $overrideBoundaryPath = null
): array {
    try {
        $boundary = kk_delivery_boundary_data($overrideBoundaryPath);
    } catch (Throwable $e) {
        return [
            'status' => 'OUTAGE',
            'headline' => 'Unable to check right now.',
            'reason_code' => 'BOUNDARY_UNAVAILABLE',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'coverage_checked' => false,
            'address_confirmed' => false,
            'pin_inside_boundary' => null,
            'boundary_version' => null,
            'message' => 'Unable to check right now: Delivery coverage boundary service is temporarily unavailable. Please try again shortly.',
        ];
    }

    $boundaryVersion = (string)($boundary['canonical_version'] ?? $boundary['boundary_version']);
    $ring = $boundary['geometry']['coordinates'][0];
    $bufferMeters = (float)($boundary['edge_buffer_meters'] ?? 20.0);

    $textAnalysis = kk_analyze_delivery_address_text($address, $boundary, $conn);
    $clean = $textAnalysis['clean_address'];
    $areaCode = strtoupper(trim($barangaySelection));
    $outsideLocalities = $textAnalysis['outside_localities'];
    $hasExplicitLagro = $textAnalysis['has_explicit_lagro_text'];
    $hasLandmarkSpoof = $textAnalysis['has_landmark_spoof'];
    $hasHouseOrLot = $textAnalysis['has_house_or_lot'];
    $hasStreetOrSubd = $textAnalysis['has_street_or_subd'];
    $matchedFeatures = $textAnalysis['matched_features'];
    $unrecognizedStreets = $textAnalysis['unrecognized_streets'];
    $unverifiedDbArea = $textAnalysis['matched_unverified_db_area'];

    // Suggest map center if a known Greater Lagro street/subdivision is mentioned in text
    $suggestedCenter = null;
    if (!empty($matchedFeatures)) {
        $firstFeat = $matchedFeatures[0];
        $suggestedCenter = [
            'name' => $firstFeat['name'],
            'lat' => (float)$firstFeat['lat'],
            'lng' => (float)$firstFeat['lng'],
        ];
    }

    $hasCoordInput = ($lat !== null && $lat !== '' && $lng !== null && $lng !== '');

    // 1. Check if coordinates are missing (Customer has not placed a pin on the map yet)
    if (!$hasCoordInput) {
        // Even without a pin, immediately flag contradictory or spoofed text so the customer sees why
        if (!empty($outsideLocalities) && ($hasExplicitLagro || $areaCode === 'BRGY_GREATER_LAGRO')) {
            return [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'CONFLICTING_BARANGAY',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'coverage_checked' => false,
                'address_confirmed' => false,
                'pin_inside_boundary' => null,
                'boundary_version' => $boundaryVersion,
                'written_address' => $clean,
                'suggested_center' => null,
                'message' => 'Address and location need confirmation: Your address lists conflicting localities (' . implode(', ', $outsideLocalities) . ' vs. Greater Lagro). Typing "Greater Lagro" does not qualify an outside address for free delivery. Correct the address and select your exact location on the map.',
            ];
        }

        if ($hasLandmarkSpoof) {
            return [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'LANDMARK_SPOOF',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'coverage_checked' => false,
                'address_confirmed' => false,
                'pin_inside_boundary' => null,
                'boundary_version' => $boundaryVersion,
                'written_address' => $clean,
                'suggested_center' => $suggestedCenter,
                'message' => 'Address and location need confirmation: Directional or landmark phrases (such as "near Lagro" or "tapat ng...") cannot establish coverage. Enter your complete House/Block/Lot and Street address and select your location on the map.',
            ];
        }

        return [
            'status' => 'SELECT_LOCATION',
            'headline' => 'Select your delivery location.',
            'reason_code' => 'PIN_REQUIRED',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'coverage_checked' => false,
            'address_confirmed' => false,
            'pin_inside_boundary' => null,
            'boundary_version' => $boundaryVersion,
            'written_address' => $clean,
            'suggested_center' => $suggestedCenter,
            'message' => $clean === ''
                ? 'Select your delivery location on the map and enter your complete House/Block/Lot and Street address.'
                : 'Select or confirm your exact delivery pin on the map to check coverage. Typed address text alone cannot verify free delivery.',
        ];
    }

    // 2. Validate coordinate format and bounds
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'INVALID_COORDINATES',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'coverage_checked' => false,
            'address_confirmed' => false,
            'pin_inside_boundary' => null,
            'boundary_version' => $boundaryVersion,
            'written_address' => $clean,
            'message' => 'Invalid map coordinates supplied. Please select your delivery location on the map.',
        ];
    }

    $latFloat = (float)$lat;
    $lngFloat = (float)$lng;
    if (!is_finite($latFloat) || !is_finite($lngFloat) || $latFloat < -90.0 || $latFloat > 90.0 || $lngFloat < -180.0 || $lngFloat > 180.0) {
        return [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'OUT_OF_BOUNDS_COORDINATES',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'coverage_checked' => false,
            'address_confirmed' => false,
            'pin_inside_boundary' => null,
            'boundary_version' => $boundaryVersion,
            'written_address' => $clean,
            'message' => 'Selected coordinates are out of valid geographic bounds. Please place the pin on the map.',
        ];
    }

    // Serviceable regional sanity check (Greater Metro Manila / nearby provinces)
    if ($latFloat < 14.10 || $latFloat > 15.20 || $lngFloat < 120.70 || $lngFloat > 121.45) {
        return [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'OUT_OF_SERVICE_REGION',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'coverage_checked' => true,
            'address_confirmed' => false,
            'pin_inside_boundary' => false,
            'boundary_version' => $boundaryVersion,
            'written_address' => $clean,
            'coordinates' => ['lat' => round($latFloat, 6), 'lng' => round($lngFloat, 6)],
            'message' => 'The selected map pin is outside Metro Manila / nearby delivery region. Please place the pin at your actual delivery destination in Quezon City or nearby areas.',
        ];
    }

    // 3. Perform server-side point-in-polygon and boundary-edge distance calculation
    $rawInside = kk_point_in_polygon($latFloat, $lngFloat, $ring);
    $edgeDistMeters = kk_distance_to_polygon_edge_meters($latFloat, $lngFloat, $ring);
    $isBoundaryEdge = ($edgeDistMeters <= $bufferMeters);
    $strictlyInside = ($rawInside && !$isBoundaryEdge);
    $strictlyOutside = (!$rawInside && !$isBoundaryEdge);

    // Find closest matched Greater Lagro street/subdivision feature to the selected pin
    $closestFeature = null;
    $closestFeatureDist = INF;
    foreach ($matchedFeatures as $mf) {
        $d = kk_distance_to_feature_bbox_meters($latFloat, $lngFloat, $mf);
        if ($d < $closestFeatureDist) {
            $closestFeatureDist = $d;
            $closestFeature = $mf;
        }
    }

    $baseResult = [
        'boundary_version' => $boundaryVersion,
        'boundary_name' => $boundary['name'] ?? 'Barangay Greater Lagro',
        'boundary_source' => $boundary['source'] ?? 'OpenStreetMap Relation 1783169 (ODbL 1.0)',
        'written_address' => $clean,
        'coordinates' => [
            'lat' => round($latFloat, 6),
            'lng' => round($lngFloat, 6),
        ],
        'coverage_checked' => true,
        'pin_inside_boundary' => $strictlyInside,
        'pin_near_boundary_edge' => $isBoundaryEdge,
        'edge_distance_meters' => round($edgeDistMeters, 1),
        'matched_street' => $closestFeature ? $closestFeature['name'] : null,
        'matched_street_distance_meters' => $closestFeature ? round($closestFeatureDist, 1) : null,
        'suggested_center' => $suggestedCenter,
    ];

    // 4. Conservative Boundary-Edge Rule (within 20m of the polygon border)
    if ($isBoundaryEdge) {
        return array_merge($baseResult, [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'BOUNDARY_EDGE',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'address_confirmed' => false,
            'message' => 'Address and location need confirmation: Your selected pin is within ' . round($edgeDistMeters, 1) . 'm of the Barangay Greater Lagro boundary edge. Boundary-edge points do not automatically qualify for free delivery and are not automatically charged as outside. Place the pin directly on your building entrance or contact the store.',
        ]);
    }

    // 5. Written address is required to confirm consistency with the selected pin
    if ($clean === '') {
        return array_merge($baseResult, [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'MISSING_ADDRESS',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'address_confirmed' => false,
            'message' => 'Coverage checked for your map pin (' . ($strictlyInside ? 'inside' : 'outside') . ' Barangay Greater Lagro), but your written delivery address is still blank. Enter your complete House/Block/Lot and Street address to confirm.',
        ]);
    }

    // 6. Contradictory barangay/locality in the written address itself (e.g. Fairview + Greater Lagro)
    if (!empty($outsideLocalities) && ($hasExplicitLagro || $areaCode === 'BRGY_GREATER_LAGRO')) {
        return array_merge($baseResult, [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'CONFLICTING_BARANGAY',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'address_confirmed' => false,
            'message' => 'Address and location need confirmation: Your written address contains conflicting locality details (' . implode(', ', $outsideLocalities) . ' and Greater Lagro). Remove the conflicting barangay/locality and confirm that your map pin matches your actual address.',
        ]);
    }

    // 7. Landmark / directional spoofing phrases
    if ($hasLandmarkSpoof) {
        return array_merge($baseResult, [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'LANDMARK_SPOOF',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'address_confirmed' => false,
            'message' => 'Address and location need confirmation: Directional or landmark references (such as "near Lagro" or "tapat ng...") cannot confirm address authenticity. Enter your exact House/Block/Lot and Street name.',
        ]);
    }

    // 8. Pin is STRICTLY INSIDE Barangay Greater Lagro
    if ($strictlyInside) {
        // 8a. Inside pin paired with an outside written address
        if (!empty($outsideLocalities) || $areaCode === 'OUTSIDE_LAGRO') {
            $conflictDesc = !empty($outsideLocalities) ? implode(', ', $outsideLocalities) : 'Outside Greater Lagro';
            return array_merge($baseResult, [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'INSIDE_PIN_OUTSIDE_ADDRESS',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'address_confirmed' => false,
                'message' => 'Address and location need confirmation: The selected map pin is inside Barangay Greater Lagro, but your written address indicates an outside location (' . $conflictDesc . '). A pin inside the boundary does not override a conflicting outside address. Move the map pin to your actual destination or correct the written address.',
            ]);
        }

        // 8b. Unverified area in delivery_areas table
        if ($unverifiedDbArea !== null) {
            return array_merge($baseResult, [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'UNVERIFIED_DELIVERY_AREA',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'address_confirmed' => false,
                'message' => 'Address and location need confirmation: "' . $unverifiedDbArea . '" is currently marked unverified in store delivery areas and requires staff confirmation.',
            ]);
        }

        // 8c. Incomplete written address (missing concrete House/Block/Lot/Unit or Street/Subdivision)
        if (!$hasHouseOrLot || !$hasStreetOrSubd || mb_strlen($clean) < 12) {
            return array_merge($baseResult, [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'INCOMPLETE_ADDRESS',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'address_confirmed' => false,
                'message' => 'Coverage checked (pin is inside Barangay Greater Lagro), but your written address is incomplete. Please include your specific House/Block/Lot/Unit number and Street/Subdivision name.',
            ]);
        }

        // 8d. Invented or unrecognized street name not found in verified Greater Lagro dataset
        if (!empty($unrecognizedStreets) || empty($matchedFeatures)) {
            $unrecLabel = !empty($unrecognizedStreets) ? (' ("' . implode('", "', $unrecognizedStreets) . '")') : '';
            return array_merge($baseResult, [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'UNVERIFIED_STREET',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'address_confirmed' => false,
                'message' => 'Coverage checked (pin is inside Barangay Greater Lagro), but the written street/subdivision' . $unrecLabel . ' could not be matched to verified Greater Lagro street data. Check your street spelling and confirm that your pin matches your written address.',
            ]);
        }

        // 8e. Known Greater Lagro street matched, but the selected pin is far (> 650m) from that street
        if ($closestFeatureDist > 650.0) {
            return array_merge($baseResult, [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'STREET_PIN_MISMATCH',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'address_confirmed' => false,
                'message' => 'Address and location need confirmation: Your written address names ' . ($closestFeature['name'] ?? 'a Greater Lagro street') . ', but the selected map pin is ~' . round($closestFeatureDist) . 'm away from that street. Move your pin to your actual street or update the written address.',
            ]);
        }

        // 8f. Verified Inside Greater Lagro!
        $verification = array_merge($baseResult, [
            'status' => 'WITHIN_LAGRO',
            'legacy_status' => 'VERIFIED_LAGRO',
            'headline' => 'Within Greater Lagro — free store delivery.',
            'reason_code' => 'VERIFIED_INSIDE_LAGRO',
            'eligible_free' => true,
            'eligible_outside' => false,
            'delivery_mode' => 'LAGRO_FREE',
            'delivery_fee' => 0.00,
            'address_confirmed' => true,
            'message' => 'Within Greater Lagro — free store delivery (₱0.00) by Kenji\'s Kitchen staff. Matched ' . ($closestFeature['name'] ?? 'Greater Lagro') . '; please confirm your written House/Block/Lot and map pin match.',
        ]);
        $verification['verification_token'] = kk_issue_delivery_verification_token($verification, $clean, $latFloat, $lngFloat);
        return $verification;
    }

    // 9. Pin is STRICTLY OUTSIDE Barangay Greater Lagro ($strictlyOutside === true)
    // 9a. Outside pin paired with a written address claiming to be in Greater Lagro
    if ($hasExplicitLagro || $areaCode === 'BRGY_GREATER_LAGRO' || (!empty($matchedFeatures) && empty($outsideLocalities))) {
        return array_merge($baseResult, [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'OUTSIDE_PIN_LAGRO_ADDRESS',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'address_confirmed' => false,
            'message' => 'Address and location need confirmation: Your selected map pin is outside Barangay Greater Lagro, but your written address references Greater Lagro. Move the map pin inside Greater Lagro if your address is inside the barangay, or update your written address to match the outside map location.',
        ]);
    }

    // 9b. Outside pin with incomplete/ambiguous written address
    if (!$hasHouseOrLot || !$hasStreetOrSubd || mb_strlen($clean) < 12) {
        return array_merge($baseResult, [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'INCOMPLETE_OUTSIDE_ADDRESS',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'address_confirmed' => false,
            'message' => 'Coverage checked (pin is outside Barangay Greater Lagro), but your written address is incomplete. Please enter your complete House/Block/Lot, Street, and Barangay/City so the third-party courier can locate your destination.',
        ]);
    }

    // 9c. Verified Outside Greater Lagro — third-party courier required
    $verification = array_merge($baseResult, [
        'status' => 'OUTSIDE_LAGRO',
        'legacy_status' => 'OUTSIDE_LAGRO',
        'headline' => 'Outside Greater Lagro — third-party courier required.',
        'reason_code' => 'VERIFIED_OUTSIDE_LAGRO',
        'eligible_free' => false,
        'eligible_outside' => true,
        'delivery_mode' => 'CUSTOMER_BOOKED',
        'delivery_fee' => null,
        'address_confirmed' => true,
        'message' => 'Outside Greater Lagro — third-party courier required. Delivery is arranged manually through a third-party courier. Choose whether you will book the courier or ask the store to book; the courier\'s actual booking charge is separate from the food amount.',
    ]);
    $verification['verification_token'] = kk_issue_delivery_verification_token($verification, $clean, $latFloat, $lngFloat);
    return $verification;
}
