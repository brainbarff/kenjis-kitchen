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
    $norm = preg_replace('/\bst\.?\s+(anne|james|joseph|mary|anthony|paul|peter|john|jude|therese|theresa|luke|mark|matthew|dominic|paolo|francis|claire|rose|michael|gabriel|raphael|elena|thomas|clara)\b/iu', 'saint $1', $norm) ?? $norm;
    $norm = preg_replace('/\bsta\.?\s+/iu', 'santa ', $norm) ?? $norm;
    $norm = preg_replace('/\bsto\.?\s+/iu', 'santo ', $norm) ?? $norm;
    $norm = preg_replace('/\bascencion\b/iu', 'ascension', $norm) ?? $norm;
    $norm = preg_replace('/\bflorante\s+(?:&\s*|and\s+)?laura\b/iu', 'florante at laura', $norm) ?? $norm;
    $norm = preg_replace('/\bmalakas\s+(?:&\s*|and\s+)?maganda\b/iu', 'malakas at maganda', $norm) ?? $norm;
    $norm = preg_replace('/\bph\.?\s*(?=[0-9ivx]+\b)/iu', 'phase ', $norm) ?? $norm;
    $romanToDigit = static function (array $m): string {
        static $map = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10'];
        return 'phase ' . ($map[strtolower($m[1])] ?? strtolower($m[1]));
    };
    $norm = preg_replace_callback('/\bphase\s+(i{1,3}|iv|vi{0,3}|ix|x)\b/iu', $romanToDigit, $norm) ?? $norm;
    $norm = preg_replace('/\b(str\.?|st\.?|street)\b/iu', 'street', $norm) ?? $norm;
    $norm = preg_replace('/\b(ave\.?|av\.?|avenue)\b/iu', 'avenue', $norm) ?? $norm;
    $norm = preg_replace('/\b(rd\.?|road)\b/iu', 'road', $norm) ?? $norm;
    $norm = preg_replace('/\b(dr\.?|drive)\b/iu', 'drive', $norm) ?? $norm;
    $norm = preg_replace('/\b(extn?\.?|extension)\b/iu', 'extension', $norm) ?? $norm;
    $norm = preg_replace('/\b(hwy\.?|highway)\b/iu', 'highway', $norm) ?? $norm;
    $norm = preg_replace('/\b(blvd\.?|boulevard)\b/iu', 'boulevard', $norm) ?? $norm;
    $norm = preg_replace('/\b(cor\.?|corner)\b/iu', 'corner', $norm) ?? $norm;
    $norm = preg_replace('/\b(subd\.?|subdivision)\b/iu', 'subdivision', $norm) ?? $norm;
    $norm = preg_replace('/\b(brgy\.?|bgy\.?|barangay)\b/iu', 'barangay', $norm) ?? $norm;
    $norm = preg_replace('/[^a-z0-9\s\-]/u', ' ', $norm) ?? $norm;
    $norm = preg_replace('/\s+/u', ' ', trim($norm)) ?? $norm;
    return $norm;
}

/**
 * Strip generic street/area suffix tokens to obtain the core feature name.
 */
function kk_strip_street_suffixes(string $norm): string
{
    $base = preg_replace('/\b(street|avenue|road|drive|extension|highway|boulevard|bend|loop|subdivision)\b/u', '', $norm) ?? $norm;
    return trim(preg_replace('/\s+/u', ' ', $base) ?? $base);
}

/**
 * Collapse spaces and hyphens for spacing-variant matching (e.g. "botaflores" vs "bota flores").
 */
function kk_compact_token_key(string $text): string
{
    return preg_replace('/[\s\-]+/u', '', $text) ?? $text;
}

/**
 * Detect explicit outside-Greater-Lagro localities, barangays, subdivisions, and cities.
 */
function kk_detect_outside_localities(string $clean): array
{
    $outsideRules = [
        'STI Fairview' => '/\bsti\s*fairview\b/i',
        'FEU-NRMF / FEU Fairview' => '/\bfeu[\s\-]*(nrmf|fairview|diliman)\b/i',
        'West Fairview' => '/\bwest\s+fairview\b/i',
        'North Fairview' => '/\bnorth\s+fairview\b/i',
        'East Fairview' => '/\beast\s+fairview\b/i',
        'South Fairview' => '/\bsouth\s+fairview\b/i',
        'Barangay Fairview / Fairview Subdivision' => '/\b(barangay|brgy\.?|bgy\.?)\s+fairview\b|\bfairview\s+(subd\.?|subdivision|park|proper)\b/i',
        'Fairview' => '/\bfairview\b/i',
        'Fairview Center Mall (FCM)' => '/\bfcm\b|\bfairview\s+center\s+mall\b/i',
        'Dahlia Avenue (West Fairview)' => '/\bdahlia\s+(ave|avenue|st|street|fairview)\b|\bfev\b|\bfairview\s+general\b/i',
        'Barangay Commonwealth' => '/\b(barangay|brgy\.?|bgy\.?)\s+commonwealth\b|\bcommonwealth\s+(heights|market)\b/i',
        'Batasan / Payatas / Bagong Silang' => '/\bbatasan\b|\bpayatas\b|\bbagong\s+silang\b|\bholy\s+spirit\b/i',
        'Caloocan (Camarin / Deparo / Tala / Llano)' => '/\bcamarin\b|\bdeparo\b|\bllano\b|\btala\b|\bmalaria\b|\bamparo\b|\bzabarte\b|\bcaloocan\b/i',
        'San Jose del Monte / Bulacan' => '/\bsan\s+jose\s+del\s+monte\b|\bsjdm\b|\btungko\b|\btungkong\s+mangga\b|\bmuzon\b|\bbulacan\b|\bsapang\s+palay\b|\bmarilao\b|\bmeycauayan\b/i',
        'Novaliches Proper / Sta. Monica / Sta. Lucia' => '/\bnovaliches\s+proper\b|\bbayan\s+novaliches\b|\b(robinsons|sm(\s+city)?)\s+novaliches\b|\bnova\s+square\b|\bsusano\s+(rd|road)\b|\bjordan\s+plains\b|\bsanta\s+monica\b|\bsta\.?\s*monica\b|\bsanta\s+lucia\b|\bsta\.?\s*lucia\b/i',
        'Neighboring QC Barangays (Kaligayahan / Pasong Putik / Sauyo / Gulod / Bagbag)' => '/\bkaligayahan\b|\bpasong\s+putik\b|\bsan\s+bartolome\b|\bgulod\b|\bsauyo\b|\bbagbag\b|\btalipapa\b|\bnagkaisang\s+nayon\b|\bcapri\b/i',
        'Other Metro Manila Cities / Districts' => '/\btandang\s+sora\b|\bculiat\b|\bdiliman\b|\bmatandang\s+balara\b|\bcubao\b|\bkatipunan\b|\btomas\s+morato\b|\btimog\b|\bcongressional\b|\bvisayas\s+(ave|avenue)\b|\bquezon\s+(ave|avenue)\b|\baurora\s+(blvd|boulevard)\b|\bespana\b|\bmanila\b|\bmakati\b|\bpasig\b|\btaguig\b|\bmarikina\b|\bvalenzuela\b|\bmandaluyong\b|\bparanaque\b|\blas\s+pinas\b|\bmuntinlupa\b|\bsan\s+mateo\b|\brodriguez\b|\bmontalban\b|\bantipolo\b/i',
        'Outside Lagro' => '/\boutside\s+(greater\s+)?lagro\b/i',
    ];

    // Metro Manila is the region containing Greater Lagro, not Manila city.
    $localityText = preg_replace('/\bmetro\s+manila\b/i', 'NCR', $clean) ?? $clean;
    // Mask legitimate inside-Greater-Lagro landmarks containing the word "Fairview" before running generic "Fairview".
    $genericFairviewText = preg_replace(
        '/\b(sm\s+(?:city\s+)?fairview|ayala\s+malls?\s+fairview\s+terraces|fairview\s+terraces|national\s+university\s+fairview|nu\s+fairview|neu\s+fairview|toyota\s+fairview)\b/i',
        'LagroLandmark',
        $localityText
    ) ?? $localityText;

    $outsideLocalities = [];
    foreach ($outsideRules as $label => $pattern) {
        $subject = ($label === 'Fairview') ? $genericFairviewText : $localityText;
        if (preg_match($pattern, $subject)) {
            $outsideLocalities[] = $label;
        }
    }
    if (count($outsideLocalities) > 1 && in_array('Fairview', $outsideLocalities, true)) {
        $outsideLocalities = array_values(array_filter($outsideLocalities, static fn($l) => $l !== 'Fairview'));
    }
    return $outsideLocalities;
}

/**
 * Match a normalized Greater Lagro feature against the normalized address and candidate phrases.
 */
function kk_feature_matches_address(
    string $fNorm,
    string $fBase,
    string $kind,
    string $norm,
    string $compactNorm,
    array $candidateBases
): bool {
    if (mb_strlen($fBase) < 3) {
        return false;
    }

    static $requireSuffixBases = [
        'quezon city', 'commonwealth', 'mindanao', 'quirino', 'regalado',
        'newport', 'valencia', 'london', 'paris', 'milan', 'venice',
        'berlin', 'dublin', 'rome', 'athens', 'florence', 'sorrento',
        'napoleon', 'bishop', 'dollar', 'sultan', 'cardigan', 'amor',
        'fe', 'caridad', 'esperanza', 'maligaya', 'mango',
    ];
    $mustHaveSuffix = ($kind === 'street' && in_array($fBase, $requireSuffixBases, true));

    if (preg_match('/\b' . preg_quote($fNorm, '/') . '\b/u', $norm)) {
        return true;
    }

    if (!$mustHaveSuffix && mb_strlen($fBase) >= 4 && preg_match('/\b' . preg_quote($fBase, '/') . '\b/u', $norm)) {
        if ($kind !== 'street'
            || preg_match('/\b' . preg_quote($fBase, '/') . '\s+(street|avenue|road|drive|extension|highway|boulevard|bend|loop)\b/u', $norm)
            || mb_strlen($fBase) >= 6) {
            return true;
        }
    }

    $fCompactBase = kk_compact_token_key($mustHaveSuffix ? $fNorm : $fBase);
    if (mb_strlen($fCompactBase) >= 6 && str_contains($compactNorm, $fCompactBase)) {
        return true;
    }

    // Bounded 1-2 edit typo tolerance on long named street/subdivision candidates (same initial char).
    $fMatchTarget = $mustHaveSuffix ? kk_compact_token_key($fNorm) : kk_compact_token_key($fBase);
    $baseLen = strlen($fMatchTarget);
    if ($baseLen >= 7 && !empty($candidateBases) && $fBase !== 'quezon city') {
        $maxDist = $baseLen >= 12 ? 2 : 1;
        foreach ($candidateBases as $candBase) {
            $candCompact = kk_compact_token_key($candBase);
            $candLen = strlen($candCompact);
            if ($candLen < 6 || $candCompact[0] !== $fMatchTarget[0]) {
                continue;
            }
            if (abs($candLen - $baseLen) <= $maxDist && levenshtein($candCompact, $fMatchTarget) <= $maxDist) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Analyze written delivery address against Greater Lagro streets, subdivisions,
 * outside localities, and spoofing/incompleteness rules.
 */
function kk_analyze_delivery_address_text(string $address, array $boundaryData, ?PDO $conn = null): array
{
    $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($address)) ?? '');
    $norm = kk_normalize_street_tokens($clean);
    $compactNorm = kk_compact_token_key($norm);

    // 1. Detect explicit outside-Lagro localities, barangays, subdivisions, and cities
    $outsideLocalities = kk_detect_outside_localities($clean);

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

    // 4. Concrete house / block / lot / unit / street-number / named establishment structure
    $hasHouseOrLot = (bool)preg_match(
        '/\b(blk\.?\s*[-:#]?\s*[0-9a-z]+|block\s*[-:#]?\s*[0-9a-z]+|lot\s*[-:#]?\s*[0-9a-z]+|b\s*[-]?\s*\d+[a-z]?\s*[\/,\s\-]*\s*(?:l|lot)\s*[-]?\s*\d+[a-z]?|unit\s*[-:#]?\s*[0-9a-z\-]+|apt\.?\s*[-:#]?\s*[0-9a-z\-]+|rm\.?\s*[-:#]?\s*[0-9a-z\-]+|room\s*[-:#]?\s*[0-9a-z\-]+|door\s*[-:#]?\s*[0-9a-z\-]+|suite\s*[-:#]?\s*[0-9a-z\-]+|stall\s*[-:#]?\s*[0-9a-z\-]+|house\s*(?:no\.?|#)?\s*[-:#]?\s*[0-9a-z\-]+|bldg\.?\s*[-:#]?\s*[0-9a-z]+|building\s*[-:#]?\s*[0-9a-z]+|tower\s*[0-9a-z]+|phase\s*[0-9a-z]+\s*(?:package|pkg\.?|area|zone|purok)\s*[0-9a-z]+|purok\s*[-:#]?\s*[0-9a-z]+|km\.?\s*\d+|no\.?\s*\d+|#\s*\d+|[0-9]+[a-z]?\s*f\b|(?:ugf|lgf|gf)\b)\b/i',
        $clean
    )
        || (bool)preg_match('/(?:^|,\s*)\d{1,5}[a-z]?(?:-[0-9a-z]+)?\s+[a-z]{3,}/i', $clean)
        || (bool)preg_match('/\b(sm\s+(?:city\s+)?(?:fairview|novaliches)|fairview\s+terraces|fairview\s+center\s+mall|fcm|robinsons\s+novaliches|national\s+university\s+fairview|nu\s+fairview|neu\s+fairview|lagro\s+(?:high|elementary)\s+school|sacred\s+heart\s+academy|school\s+of\s+saint\s+anthony|our\s+lady\s+of\s+fatima\s+university|st\.?\s*dominic\s+institute|plaza\s+lagro|santuario\s+di\s+san\s+paolo|feu[\s\-]*(?:nrmf|fairview)|fairview\s+general|zabarte\s+town\s+center|nova\s+square|mall|hospital|university|college|school|church|parish|condominium|condo|residences|town\s+center)\b/i', $clean);

    // 5. Extract explicit "<Name> Street/Avenue/Road/Drive/Bend/Loop/Highway" phrases first for typo matching & audit
    $streetCandidates = [];
    if (preg_match_all('/\b([a-z][a-z0-9\-\s]{1,32}?)\s+(street|avenue|road|drive|bend|loop|highway|boulevard)\b/u', $norm, $streetMatches, PREG_SET_ORDER)) {
        $stopPrefix = ['blk', 'block', 'lot', 'phase', 'unit', 'house', 'apt', 'room', 'door', 'no', 'barangay', 'greater', 'lagro', 'quezon', 'city', 'subdivision', 'corner', 'and', 'at', 'de', 'del', 'ng', 'ni', 'sa'];
        foreach ($streetMatches as $sm) {
            $words = explode(' ', trim($sm[1]));
            if (count($words) > 4) {
                $words = array_slice($words, -4);
            }
            $cleanedWords = [];
            foreach ($words as $w) {
                if ((in_array($w, $stopPrefix, true) || preg_match('/^(?:b|l)?\d+[a-z]?$/', $w)) && empty($cleanedWords)) {
                    continue;
                }
                $cleanedWords[] = $w;
            }
            $candBase = trim(implode(' ', $cleanedWords));
            if ($candBase !== '' && mb_strlen($candBase) >= 3 && !preg_match('/^\d+$/', $candBase)) {
                $streetCandidates[] = ['base' => $candBase, 'full' => $candBase . ' ' . $sm[2]];
            }
        }
    }
    $candidateBases = array_values(array_unique(array_column($streetCandidates, 'base')));

    // 6. Match against local Greater Lagro streets/subdivisions/landmarks & delivery_areas table
    static $broadAreaNames = ['greater lagro', 'lagro subdivision'];
    static $nonExclusiveNames = [
        'greater lagro', 'lagro subdivision',
        'commonwealth avenue', 'mindanao avenue', 'quirino highway', 'regalado highway',
        'sampaguita street', 'rosal street', 'jasmin street', 'violet street', 'santan street',
        'camia street', 'ilang ilang street', 'ilang-ilang street', 'waling waling street', 'waling-waling street',
        'calachuchi street', 'gumamela street', 'champaca street', 'dama de noche street',
        'maligaya street', 'esperanza street', 'amor street', 'fe street', 'caridad street',
        'mango drive', 'road 5', 'road 12', 'rome street', 'paris street', 'london street',
        'milan street', 'venice street', 'berlin street', 'dublin street', 'valencia street', 'newport street',
    ];

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
    $hasVerifiedDbArea = false;

    foreach ($localFeatures as $feat) {
        $fName = (string)($feat['name'] ?? '');
        if ($fName === '') continue;
        $fNorm = kk_normalize_street_tokens($fName);
        $fBase = kk_strip_street_suffixes($fNorm);
        $kind = (string)($feat['kind'] ?? 'street');

        if (!kk_feature_matches_address($fNorm, $fBase, $kind, $norm, $compactNorm, $candidateBases)) {
            continue;
        }

        // Regalado Avenue without explicit Lagro/subdivision context is ambiguous with North/West Fairview
        if ($fNorm === 'regalado highway' && str_contains($norm, 'regalado avenue') && !str_contains($norm, 'regalado highway')) {
            $hasLagroSubdHint = $hasExplicitLagroText || (bool)preg_match('/\b(neopolitan|brittany|casa\s+milan|greater\s+lagro|lagro)\b/i', $clean);
            if (!$hasLagroSubdHint) {
                continue;
            }
        }

        $feat['_norm'] = $fNorm;
        $feat['_is_broad_area'] = in_array($fNorm, $broadAreaNames, true);
        $feat['_non_exclusive'] = in_array($fNorm, $nonExclusiveNames, true);
        $matchedFeatures[$fNorm] = $feat;
    }

    // Handle "Regalado Ave" paired with Greater Lagro / Lagro subdivision even if "Regalado Highway" wasn't matched verbatim
    if (!isset($matchedFeatures['regalado highway'])
        && preg_match('/\bregalado\s+avenue\b/u', $norm)
        && ($hasExplicitLagroText || preg_match('/\b(neopolitan|brittany|casa\s+milan|lagro)\b/i', $clean))) {
        foreach ($localFeatures as $lf) {
            if (strcasecmp((string)($lf['name'] ?? ''), 'Regalado Highway') === 0) {
                $lf['_norm'] = 'regalado highway';
                $lf['_is_broad_area'] = false;
                $lf['_non_exclusive'] = true;
                $matchedFeatures['regalado highway'] = $lf;
                break;
            }
        }
    }

    foreach ($dbAreas as $area) {
        $stName = trim((string)($area['street_name'] ?? ''));
        if ($stName === '') continue;
        $stClean = trim(preg_replace('/\(subdivision\)/i', '', $stName) ?? $stName);
        $stNorm = kk_normalize_street_tokens($stClean);
        $stBase = kk_strip_street_suffixes($stNorm);
        if (mb_strlen($stBase) < 3) continue;

        if (kk_feature_matches_address($stNorm, $stBase, 'street', $norm, $compactNorm, $candidateBases)) {
            $isVer = (int)($area['is_verified'] ?? 0) === 1;
            if (!$isVer && !$allowUnverifiedAreas) {
                $matchedUnverifiedDbArea = $stName;
                continue;
            }
            $hasVerifiedDbArea = true;
            if (!isset($matchedFeatures[$stNorm])) {
                foreach ($localFeatures as $lf) {
                    $lfBase = kk_strip_street_suffixes(kk_normalize_street_tokens((string)($lf['name'] ?? '')));
                    if ($lfBase !== '' && (str_contains($lfBase, $stBase) || str_contains($stBase, $lfBase))) {
                        $lf['_norm'] = $stNorm;
                        $lf['_is_broad_area'] = in_array($stNorm, $broadAreaNames, true);
                        $lf['_non_exclusive'] = in_array($stNorm, $nonExclusiveNames, true);
                        $matchedFeatures[$stNorm] = $lf;
                        break;
                    }
                }
            }
        }
    }

    // 7. Identify unrecognized street phrases (supporting diagnostics without blocking verified subdivisions/Lagro addresses)
    $unrecognizedStreets = [];
    foreach ($streetCandidates as $cand) {
        $candBase = $cand['base'];
        $candCompact = kk_compact_token_key($candBase);
        $isKnown = false;
        foreach ($matchedFeatures as $mKey => $mFeat) {
            $mBase = kk_strip_street_suffixes($mKey);
            $mCompact = kk_compact_token_key($mBase);
            if ($mBase !== '' && (str_contains($candBase, $mBase) || str_contains($mBase, $candBase) || ($mCompact !== '' && str_contains($candCompact, $mCompact)))) {
                $isKnown = true;
                break;
            }
        }
        if (!$isKnown) {
            $unrecognizedStreets[] = $cand['full'];
        }
    }

    $matchedSpecificFeatures = array_values(array_filter(
        $matchedFeatures,
        static fn(array $f): bool => empty($f['_is_broad_area'])
    ));
    $matchedExclusiveFeatures = array_values(array_filter(
        $matchedFeatures,
        static fn(array $f): bool => empty($f['_non_exclusive'])
    ));

    // Generic street/subdivision/area structure check (for inside and outside addresses)
    $hasStreetOrSubdStructure = (bool)preg_match(
        '/\b(street|avenue|road|drive|extension|bend|loop|subdivision|village|homes|park|phase|package|compound|condominium|condo|residences|heights|terrace|terraces|purok|sitio|barangay|highway|boulevard|lane|alley|corner|mall|market|hospital|university|college|school|plaza|center)\b/u',
        $norm
    ) || !empty($matchedFeatures) || $hasVerifiedDbArea || !empty($outsideLocalities);

    // Specific street, subdivision, or landmark phrase (excluding bare "Lagro Subdivision" / "Greater Lagro")
    $hasNamedSubdOrLandmark = (bool)preg_match(
        '/\b(?!greater\s+lagro\b)(?!lagro\s+subdivision\b)[a-z][a-z0-9\-\s]{2,}\s+(subdivision|village|homes|park|heights|residences|condominium|condo|compound|mall|market|hospital|school|university|college|plaza|center)\b/u',
        $norm
    ) || (bool)preg_match(
        '/\b(sm\s+(?:city\s+)?(?:fairview|novaliches)|fairview\s+terraces|fairview\s+center\s+mall|fcm|robinsons\s+novaliches|national\s+university\s+fairview|nu\s+fairview|neu\s+fairview|feu[\s\-]*(?:nrmf|fairview)|lagro\s+(?:high|elementary)\s+school|sacred\s+heart\s+academy|school\s+of\s+saint\s+anthony|our\s+lady\s+of\s+fatima\s+university|st\.?\s*dominic\s+institute|plaza\s+lagro|santuario\s+di\s+san\s+paolo|nova\s+square|zabarte\s+town\s+center|commonwealth\s+market|casa\s+milan|neopolitan|brittany|fairville\s+homes|fairmont\s+park|la\s+mesa\s+heights|villa\s+vienna|sitio\s+seville|northridge\s+park)\b/i',
        $clean
    );
    $hasNamedStreetOrLandmark = !empty($streetCandidates) || $hasNamedSubdOrLandmark;

    return [
        'clean_address' => $clean,
        'normalized_address' => $norm,
        'has_house_or_lot' => $hasHouseOrLot,
        'has_street_or_subd' => $hasStreetOrSubdStructure,
        'has_named_street_or_landmark' => $hasNamedStreetOrLandmark,
        'has_explicit_lagro_text' => $hasExplicitLagroText,
        'has_landmark_spoof' => $hasLandmarkSpoof,
        'outside_localities' => $outsideLocalities,
        'matched_features' => array_values($matchedFeatures),
        'matched_specific_features' => $matchedSpecificFeatures,
        'matched_exclusive_features' => $matchedExclusiveFeatures,
        'has_verified_db_area' => $hasVerifiedDbArea,
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
    $bufferMeters = (float)($boundary['edge_buffer_meters'] ?? 2.0);

    $textAnalysis = kk_analyze_delivery_address_text($address, $boundary, $conn);
    $clean = $textAnalysis['clean_address'];
    $areaCode = strtoupper(trim($barangaySelection));
    $outsideLocalities = $textAnalysis['outside_localities'];
    $hasExplicitLagro = $textAnalysis['has_explicit_lagro_text'];
    $hasLandmarkSpoof = $textAnalysis['has_landmark_spoof'];
    $hasHouseOrLot = $textAnalysis['has_house_or_lot'];
    $hasStreetOrSubd = $textAnalysis['has_street_or_subd'];
    $hasNamedStreetOrLandmark = !empty($textAnalysis['has_named_street_or_landmark']);
    $matchedFeatures = $textAnalysis['matched_features'];
    $matchedSpecificFeatures = $textAnalysis['matched_specific_features'];
    $matchedExclusiveFeatures = $textAnalysis['matched_exclusive_features'];
    $hasVerifiedDbArea = $textAnalysis['has_verified_db_area'];
    $unrecognizedStreets = $textAnalysis['unrecognized_streets'];
    $unverifiedDbArea = $textAnalysis['matched_unverified_db_area'];

    // Suggest map center if a known specific Greater Lagro street/subdivision is mentioned in text
    $suggestedCenter = null;
    $centerPool = !empty($matchedSpecificFeatures) ? $matchedSpecificFeatures : $matchedFeatures;
    if (!empty($centerPool)) {
        $firstFeat = $centerPool[0];
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
    // Treat border-buffer points as ambiguous unless outside the polygon with an explicit outside locality.
    $isBoundaryEdge = ($edgeDistMeters <= $bufferMeters) && ($rawInside || empty($outsideLocalities) || $hasExplicitLagro);
    $strictlyInside = ($rawInside && !$isBoundaryEdge);
    $strictlyOutside = (!$rawInside && !$isBoundaryEdge);

    // Find closest matched specific Greater Lagro street/subdivision/landmark feature to the selected pin
    $closestFeature = null;
    $closestFeatureDist = INF;
    foreach ($matchedSpecificFeatures as $mf) {
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

        // 8c. Incomplete written address (require concrete House/Blk/Lot OR matched Lagro street/subd/landmark OR named street + Greater Lagro)
        $hasSpecificLagro = !empty($matchedSpecificFeatures) || $hasVerifiedDbArea;
        $hasCompleteInsideAddress = (($hasHouseOrLot && $hasStreetOrSubd) || $hasSpecificLagro || ($hasExplicitLagro && $hasNamedStreetOrLandmark))
            && mb_strlen($clean) >= 8;
        if (!$hasCompleteInsideAddress) {
            return array_merge($baseResult, [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'INCOMPLETE_ADDRESS',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'address_confirmed' => false,
                'message' => 'Coverage checked (pin is inside Barangay Greater Lagro), but your written address is incomplete. Include your Street, Subdivision, or Landmark (and House/Block/Lot if applicable).',
            ]);
        }

        // 8d. Require either a matched Greater Lagro feature/DB-verified street or explicit Greater Lagro locality text
        if (empty($matchedSpecificFeatures) && !$hasVerifiedDbArea && !$hasExplicitLagro) {
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
                'message' => 'Coverage checked (pin is inside Barangay Greater Lagro), but the written street/subdivision' . $unrecLabel . ' could not be matched to Greater Lagro. Include Barangay Greater Lagro or check your street spelling and map pin.',
            ]);
        }

        // 8e. Known Greater Lagro street/landmark matched, but the selected pin is far (> 1100m) from it
        if ($closestFeature !== null && $closestFeatureDist > 1100.0) {
            return array_merge($baseResult, [
                'status' => 'NEEDS_CONFIRMATION',
                'headline' => 'Address and location need confirmation.',
                'reason_code' => 'STREET_PIN_MISMATCH',
                'eligible_free' => false,
                'eligible_outside' => false,
                'delivery_mode' => '',
                'delivery_fee' => null,
                'address_confirmed' => false,
                'message' => 'Address and location need confirmation: Your written address names ' . ($closestFeature['name'] ?? 'a Greater Lagro street') . ', but the selected map pin is ~' . round($closestFeatureDist) . 'm away from that location. Move your pin to your actual street or update the written address.',
            ]);
        }

        // 8f. Verified Inside Greater Lagro!
        $matchedLabel = $closestFeature['name'] ?? ($hasExplicitLagro ? 'Barangay Greater Lagro' : 'Greater Lagro');
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
            'message' => 'Within Greater Lagro — free store delivery (₱0.00) by Kenji\'s Kitchen staff. Matched ' . $matchedLabel . '; please confirm your written House/Block/Lot and map pin match.',
        ]);
        $verification['verification_token'] = kk_issue_delivery_verification_token($verification, $clean, $latFloat, $lngFloat);
        return $verification;
    }

    // 9. Pin is STRICTLY OUTSIDE Barangay Greater Lagro ($strictlyOutside === true)
    // 9a. Outside pin paired with a written address claiming to be in Greater Lagro
    if ($hasExplicitLagro || $areaCode === 'BRGY_GREATER_LAGRO' || (!empty($matchedExclusiveFeatures) && empty($outsideLocalities))) {
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

    // 9b. Outside pin with incomplete/bare written address (require concrete House/Blk/Lot OR named street/subdivision/landmark)
    $hasCompleteOutsideAddress = (($hasHouseOrLot && $hasStreetOrSubd) || $hasNamedStreetOrLandmark)
        && mb_strlen($clean) >= 8;
    if (!$hasCompleteOutsideAddress) {
        return array_merge($baseResult, [
            'status' => 'NEEDS_CONFIRMATION',
            'headline' => 'Address and location need confirmation.',
            'reason_code' => 'INCOMPLETE_OUTSIDE_ADDRESS',
            'eligible_free' => false,
            'eligible_outside' => false,
            'delivery_mode' => '',
            'delivery_fee' => null,
            'address_confirmed' => false,
            'message' => 'Coverage checked (pin is outside Barangay Greater Lagro), but your written address is incomplete. Include your Street, Subdivision, or Landmark and Barangay/City so the third-party courier can locate your destination.',
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

/**
 * Perform a short-timeout, file-cached JSON request to Nominatim (OpenStreetMap).
 * Returns decoded array or null on network/timeout/HTTP failure.
 */
function kk_nominatim_fetch(string $endpoint, array $params, int $ttlSeconds = 3600): ?array
{
    if (PHP_SAPI === 'cli' && !empty($_ENV['KK_SKIP_NETWORK_GEOCODE'])) {
        return null;
    }

    ksort($params);
    $cacheKey = hash('sha256', $endpoint . '?' . http_build_query($params));
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kk_geocode_cache';
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';

    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) <= $ttlSeconds) {
        $cached = @file_get_contents($cacheFile);
        $decoded = is_string($cached) ? json_decode($cached, true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $url = 'https://nominatim.openstreetmap.org/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
    $raw = null;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'KenjisKitchenOnlineMenu/1.0 (+http://localhost/kenjis-kitchen)',
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en'],
            ]);
            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($resp) && $httpCode === 200) {
                $raw = $resp;
            }
        }
    }

    if ($raw === null) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }
    if (is_dir($cacheDir)) {
        @file_put_contents($cacheFile, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $decoded;
}

/**
 * Strip outside-locality terms when pin is inside Greater Lagro, or Lagro terms when pin is outside,
 * so reverse-geocoded labels never contradict the authoritative boundary polygon.
 */
function kk_sanitize_reverse_geocode_parts(array $parts, bool $pinInsideBoundary): array
{
    $cleaned = [];
    foreach ($parts as $part) {
        $val = trim((string)$part);
        if ($val === '') {
            continue;
        }
        if ($pinInsideBoundary && preg_match('/\b(west\s+fairview|north\s+fairview|east\s+fairview|south\s+fairview|pasong\s+putik|kaligayahan|novaliches|bagong\s+silang|camarin|fairview)\b/i', $val)
            && !preg_match('/\b(sm\s+(?:city\s+)?fairview|fairview\s+terraces|nu\s+fairview|neu\s+fairview|national\s+university\s+fairview)\b/i', $val)) {
            continue;
        }
        if (!$pinInsideBoundary && preg_match('/\b(greater\s+)?lagro\b/i', $val)) {
            continue;
        }
        $key = mb_strtolower($val, 'UTF-8');
        if ($pinInsideBoundary && $key === 'greater lagro') {
            continue;
        }
        if (!isset($cleaned[$key])) {
            $cleaned[$key] = $val;
        }
    }
    return array_values($cleaned);
}

/**
 * Resolve (lat, lng) to a clean readable Philippine delivery address using Nominatim
 * with deterministic offline fallback against Greater Lagro local_features and regional corridors.
 */
function kk_reverse_geocode_delivery_point(float $lat, float $lng, ?string $overrideBoundaryPath = null): array
{
    $boundary = kk_delivery_boundary_data($overrideBoundaryPath);
    $ring = $boundary['geometry']['coordinates'][0];
    $rawInside = kk_point_in_polygon($lat, $lng, $ring);
    $localFeatures = $boundary['local_features'] ?? [];

    // Find closest street and closest subdivision/landmark inside Greater Lagro
    $closestStreet = null;
    $closestStreetDist = INF;
    $closestArea = null;
    $closestAreaDist = INF;
    foreach ($localFeatures as $feat) {
        $name = trim((string)($feat['name'] ?? ''));
        if ($name === '' || preg_match('/^\d+$/', $name)) {
            continue;
        }
        $norm = mb_strtolower($name, 'UTF-8');
        if (in_array($norm, ['greater lagro', 'lagro subdivision'], true)) {
            continue;
        }
        $d = kk_distance_to_feature_bbox_meters($lat, $lng, $feat);
        $kind = (string)($feat['kind'] ?? 'street');
        if ($kind === 'street' && $d < $closestStreetDist) {
            $closestStreetDist = $d;
            $closestStreet = $name;
        } elseif ($kind !== 'street' && $d < $closestAreaDist) {
            $closestAreaDist = $d;
            $closestArea = $name;
        }
    }

    $nom = kk_nominatim_fetch('reverse', [
        'format' => 'jsonv2',
        'lat' => number_format($lat, 5, '.', ''),
        'lon' => number_format($lng, 5, '.', ''),
        'zoom' => 18,
        'addressdetails' => 1,
    ]);

    $streetPart = '';
    $subdPart = '';
    $cityPart = '';
    $source = 'local_fallback';

    if (is_array($nom) && !empty($nom['address']) && is_array($nom['address'])) {
        $a = $nom['address'];
        $houseNo = trim((string)($a['house_number'] ?? ''));
        $road = trim((string)($a['road'] ?? ($a['pedestrian'] ?? ($a['footway'] ?? ($a['amenity'] ?? ($a['building'] ?? ($a['shop'] ?? ($a['mall'] ?? ''))))))));
        if ($road !== '') {
            $streetPart = $houseNo !== '' ? ($houseNo . ' ' . $road) : $road;
        }
        $subdPart = trim((string)($a['neighbourhood'] ?? ($a['quarter'] ?? ($a['suburb'] ?? ($a['village'] ?? '')))));
        $cityPart = trim((string)($a['city'] ?? ($a['town'] ?? ($a['municipality'] ?? ''))));
        if ($streetPart !== '' || $subdPart !== '') {
            $source = 'nominatim';
        }
    }

    if ($rawInside) {
        if ($streetPart === '' && $closestStreet !== null && $closestStreetDist <= 500.0) {
            $streetPart = $closestStreet;
        }
        if ($subdPart === '' && $closestArea !== null && $closestAreaDist <= 500.0) {
            $subdPart = $closestArea;
        }
        $parts = kk_sanitize_reverse_geocode_parts([
            $streetPart !== '' ? $streetPart : ($closestStreet ?? ($closestArea ?? 'Lagro Road')),
            $subdPart,
            'Barangay Greater Lagro',
            $cityPart !== '' ? $cityPart : 'Quezon City',
        ], true);
        if (count($parts) < 3) {
            $parts = kk_sanitize_reverse_geocode_parts([
                $closestStreet ?? 'Florante at Laura Street',
                'Barangay Greater Lagro',
                'Quezon City',
            ], true);
        }
    } else {
        // Regional offline fallback for outside Greater Lagro pins when Nominatim has no road
        if ($streetPart === '') {
            if ($lat >= 14.742 && $lng < 121.055) {
                $streetPart = 'Camarin Road';
                $subdPart = $subdPart !== '' ? $subdPart : 'Camarin';
                $cityPart = $cityPart !== '' ? $cityPart : 'Caloocan City';
            } elseif ($lat >= 14.742 && $lng >= 121.055) {
                $streetPart = 'Bagong Silang Road';
                $subdPart = $subdPart !== '' ? $subdPart : 'Bagong Silang';
                $cityPart = $cityPart !== '' ? $cityPart : 'Caloocan City';
            } elseif ($lat >= 14.735) {
                $streetPart = 'Quirino Highway';
                $subdPart = $subdPart !== '' ? $subdPart : 'Barangay Pasong Putik';
                $cityPart = $cityPart !== '' ? $cityPart : 'Quezon City';
            } elseif ($lat < 14.716 && $lng < 121.064) {
                $streetPart = 'Regalado Avenue';
                $subdPart = $subdPart !== '' ? $subdPart : 'West Fairview';
                $cityPart = $cityPart !== '' ? $cityPart : 'Quezon City';
            } else {
                $streetPart = 'Commonwealth Avenue';
                $subdPart = $subdPart !== '' ? $subdPart : 'Fairview';
                $cityPart = $cityPart !== '' ? $cityPart : 'Quezon City';
            }
        }
        $parts = kk_sanitize_reverse_geocode_parts([
            $streetPart,
            $subdPart,
            $cityPart !== '' ? $cityPart : 'Quezon City',
        ], false);
    }

    $formatted = implode(', ', $parts);
    return [
        'address' => $formatted,
        'street' => $streetPart,
        'barangay' => $rawInside ? 'Barangay Greater Lagro' : $subdPart,
        'city' => $cityPart !== '' ? $cityPart : 'Quezon City',
        'has_house_number' => (bool)preg_match('/\b\d+/', $streetPart),
        'pin_inside_boundary' => $rawInside,
        'source' => $source,
        'coordinates' => ['lat' => round($lat, 6), 'lng' => round($lng, 6)],
    ];
}

/**
 * Resolve a typed delivery address to map coordinates (lat, lng) using local Greater Lagro
 * features, curated outside Metro Manila landmarks, and Nominatim forward search.
 */
function kk_forward_geocode_delivery_address(string $address, ?string $overrideBoundaryPath = null): array
{
    $boundary = kk_delivery_boundary_data($overrideBoundaryPath);
    $ring = $boundary['geometry']['coordinates'][0];
    $textAnalysis = kk_analyze_delivery_address_text($address, $boundary, null);
    $clean = $textAnalysis['clean_address'];

    if ($clean === '') {
        return ['found' => false, 'message' => 'Enter a street, subdivision, or landmark to locate on the map.'];
    }

    $store = $boundary['store_location'] ?? [];
    if (!empty($store['address']) && strcasecmp(kk_normalize_street_tokens($clean), kk_normalize_street_tokens((string)$store['address'])) === 0) {
        return [
            'found' => true,
            'lat' => round((float)$store['lat'], 6),
            'lng' => round((float)$store['lng'], 6),
            'display_name' => (string)$store['address'],
            'pin_inside_boundary' => true,
            'source' => 'store_location',
        ];
    }

    $outsideLocalities = $textAnalysis['outside_localities'];
    $matchedSpecific = $textAnalysis['matched_specific_features'];
    $matchedExclusive = $textAnalysis['matched_exclusive_features'];
    $mustBeInsideLagro = empty($outsideLocalities) && ($textAnalysis['has_explicit_lagro_text'] || !empty($matchedExclusive));

    // Strip Filipino Block/Lot/Unit prefixes before querying Nominatim so street/landmark search succeeds
    $searchQuery = preg_replace(
        '/\b(?:blk\.?|block|lot|unit|apt\.?|rm\.?|room|door|house\s*(?:no\.?|#)?|bldg\.?|building|phase|ph\.?|purok)\s*[-:#]?\s*[0-9a-z\-]+\b|\bb\s*[-]?\s*\d+[a-z]?\s*[\/,\s\-]*\s*(?:l|lot)\s*[-]?\s*\d+[a-z]?\b/i',
        ' ',
        $clean
    ) ?? $clean;
    $searchQuery = trim(preg_replace('/\s*,\s*,+|\s+/u', ' ', $searchQuery) ?? $searchQuery, " ,");
    if ($searchQuery === '') {
        $searchQuery = $clean;
    }
    if (!preg_match('/\b(quezon\s+city|caloocan|manila|bulacan|sjdm|san\s+jose\s+del\s+monte|philippines)\b/i', $searchQuery)) {
        $searchQuery .= ', Quezon City, Metro Manila';
    }

    $nomResults = kk_nominatim_fetch('search', [
        'format' => 'jsonv2',
        'q' => $searchQuery,
        'limit' => 5,
        'countrycodes' => 'ph',
        'viewbox' => '120.85,14.88,121.25,14.45',
    ]);

    if (is_array($nomResults) && !empty($nomResults)) {
        foreach ($nomResults as $item) {
            if (!isset($item['lat'], $item['lon'])) {
                continue;
            }
            $cLat = (float)$item['lat'];
            $cLng = (float)$item['lon'];
            if ($cLat < 14.10 || $cLat > 15.20 || $cLng < 120.70 || $cLng > 121.45) {
                continue;
            }
            $inPoly = kk_point_in_polygon($cLat, $cLng, $ring);
            if ($mustBeInsideLagro && !$inPoly) {
                continue;
            }
            if (!empty($outsideLocalities) && ($inPoly || kk_distance_to_polygon_edge_meters($cLat, $cLng, $ring) <= 2.0)) {
                continue;
            }
            return [
                'found' => true,
                'lat' => round($cLat, 6),
                'lng' => round($cLng, 6),
                'display_name' => (string)($item['display_name'] ?? $clean),
                'pin_inside_boundary' => $inPoly,
                'source' => 'nominatim',
            ];
        }
    }

    // Deterministic offline/local feature match for Greater Lagro
    if ($mustBeInsideLagro && !empty($matchedSpecific)) {
        $feat = $matchedSpecific[0];
        $fLat = (float)$feat['lat'];
        $fLng = (float)$feat['lng'];
        return [
            'found' => true,
            'lat' => round($fLat, 6),
            'lng' => round($fLng, 6),
            'display_name' => (string)$feat['name'] . ', Barangay Greater Lagro, Quezon City',
            'pin_inside_boundary' => kk_point_in_polygon($fLat, $fLng, $ring),
            'source' => 'local_feature',
        ];
    }

    // Curated offline fallback coordinates for major surrounding outside-Lagro landmarks & areas
    $outsideFallbackMap = [
        '/\brobinsons\s+novaliches\b/i' => [14.7369, 121.0570, 'Robinsons Novaliches, Quirino Highway, Pasong Putik, Quezon City'],
        '/\bsm(\s+city)?\s+novaliches\b/i' => [14.7039, 121.0361, 'SM City Novaliches, Quirino Highway, San Bartolome, Quezon City'],
        '/\b(fcm|fairview\s+center\s+mall)\b/i' => [14.7058, 121.0672, 'Fairview Center Mall, Commonwealth Avenue, Quezon City'],
        '/\bdahlia\b|\bfev\b|\bfeu[\s\-]*nrmf\b|\bwest\s+fairview\b/i' => [14.7016, 121.0637, 'Dahlia Avenue, West Fairview, Quezon City'],
        '/\bnorth\s+fairview\b/i' => [14.7118, 121.0585, 'North Fairview, Quezon City'],
        '/\bcamarin\b/i' => [14.7530, 121.0450, 'Camarin Road, Caloocan City'],
        '/\bbagong\s+silang\b/i' => [14.7735, 121.0620, 'Bagong Silang, Caloocan City'],
        '/\bpasong\s+putik\b/i' => [14.7385, 121.0590, 'Pasong Putik, Quezon City'],
        '/\bkaligayahan\b|\bzabarte\b/i' => [14.7395, 121.0490, 'Zabarte Road, Kaligayahan, Quezon City'],
        '/\b(barangay|brgy\.?|bgy\.?)\s+commonwealth\b|\bcommonwealth\s+ave/i' => [14.6880, 121.0805, 'Commonwealth Avenue, Quezon City'],
        '/\bbatasan\b|\bpayatas\b|\bholy\s+spirit\b/i' => [14.6960, 121.0930, 'Batasan Hills / Commonwealth, Quezon City'],
        '/\bcaloocan\b/i' => [14.7510, 121.0440, 'Caloocan City'],
    ];
    foreach ($outsideFallbackMap as $pattern => [$oLat, $oLng, $oLabel]) {
        if (preg_match($pattern, $clean)) {
            return [
                'found' => true,
                'lat' => $oLat,
                'lng' => $oLng,
                'display_name' => $oLabel,
                'pin_inside_boundary' => false,
                'source' => 'regional_fallback',
            ];
        }
    }

    if (empty($outsideLocalities) && $textAnalysis['has_explicit_lagro_text']) {
        $centerLat = 14.726547;
        $centerLng = 121.066023;
        return [
            'found' => true,
            'lat' => $centerLat,
            'lng' => $centerLng,
            'display_name' => 'Barangay Greater Lagro, Quezon City',
            'pin_inside_boundary' => true,
            'source' => 'lagro_center',
        ];
    }

    return [
        'found' => false,
        'message' => 'Could not find that exact address on the map yet. Tap or drag the pin directly on the map at your location.',
    ];
}

/**
 * Determine whether an online order uses Option 1 (customer-booked outside-Lagro courier).
 */
function kk_is_option1_customer_courier(array $order): bool
{
    if (($order['order_type'] ?? 'ONLINE') !== 'ONLINE') {
        return false;
    }
    if (($order['delivery_mode'] ?? '') !== 'CUSTOMER_BOOKED' && ($order['fulfillment_mode'] ?? '') !== 'OUTSIDE') {
        return false;
    }
    return kk_resolve_courier_booking_by($order) === 'CUSTOMER';
}

/**
 * Determine whether an Option 1 order has a staff-approved public HTTPS courier tracking link.
 */
function kk_option1_tracking_is_verified(array $order): bool
{
    if (($order['courier_tracking_status'] ?? 'None') !== 'Verified') {
        return false;
    }
    try {
        return normalize_delivery_tracking_url($order['delivery_tracking_url'] ?? '') !== null;
    } catch (InvalidArgumentException $e) {
        return false;
    }
}

/**
 * Determine whether verifying GCash food payment should release an online order to Kitchen immediately.
 */
function kk_should_queue_online_gcash_order_to_kitchen(array $order): bool
{
    if (!kk_is_option1_customer_courier($order)) {
        return true;
    }
    return kk_option1_tracking_is_verified($order);
}

/**
 * Canonical SQL predicate enforcing that Option 1 orders stay held from Kitchen
 * until staff verifies the customer's courier tracking link.
 */
function kk_kitchen_option1_gate_sql(string $alias = 'o'): string
{
    return "($alias.order_channel <> 'ONLINE' OR $alias.order_type <> 'ONLINE' OR $alias.delivery_mode <> 'CUSTOMER_BOOKED' OR ($alias.courier_booking_by <> 'CUSTOMER' AND NOT ($alias.courier_booking_by = 'NONE' AND COALESCE($alias.notes, '') NOT LIKE '%Courier booking: Kenjis staff%')) OR ($alias.courier_tracking_status = 'Verified' AND $alias.delivery_tracking_url IS NOT NULL AND LOWER($alias.delivery_tracking_url) LIKE 'https://%'))";
}

/**
 * Save or update a third-party tracking URL by staff (syncing Option 2 thirdparty_deliveries too).
 * Must run inside an active DB transaction.
 */
function kk_save_staff_tracking_link(PDO $conn, int $orderId, ?string $rawUrl, int $staffId): ?string
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Saving tracking link requires an active transaction.');
    }

    $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['order_channel'] ?? '') !== 'ONLINE' || ($order['order_type'] ?? '') !== 'ONLINE') {
        throw new RuntimeException('Online delivery order not found.');
    }
    if (($order['delivery_mode'] ?? '') !== 'CUSTOMER_BOOKED') {
        throw new RuntimeException('Tracking links are for outside-Lagro third-party deliveries.');
    }
    if (!in_array((string)$order['status'], ['Pending', 'Preparing', 'Ready'], true)) {
        throw new RuntimeException('Tracking link can no longer be edited for this order.');
    }

    $trackingUrl = normalize_delivery_tracking_url($rawUrl);

    $dispatchStmt = $conn->prepare("SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE");
    $dispatchStmt->execute([$orderId]);
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
    if ($trackingUrl === null && $dispatch && ($dispatch['status'] ?? '') === 'Out for Delivery') {
        throw new RuntimeException('Keep a tracking link while the third-party rider has the order.');
    }

    $resolvedBooker = kk_resolve_courier_booking_by($order);
    if ($resolvedBooker === 'CUSTOMER') {
        if ($trackingUrl === null) {
            if (in_array((string)$order['status'], ['Preparing', 'Ready'], true)) {
                throw new RuntimeException('Keep a verified tracking link once kitchen preparation has started.');
            }
            $conn->prepare("UPDATE orders SET delivery_tracking_url = NULL, courier_tracking_status = 'None', courier_tracking_verified_by = NULL, courier_tracking_verified_at = NULL, courier_tracking_rejection_reason = NULL WHERE id = ?")
                ->execute([$orderId]);
        } else {
            $conn->prepare("UPDATE orders SET delivery_tracking_url = ?, courier_tracking_status = 'Verified', courier_tracking_verified_by = ?, courier_tracking_verified_at = CURRENT_TIMESTAMP, courier_tracking_rejection_reason = NULL WHERE id = ?")
                ->execute([$trackingUrl, $staffId, $orderId]);
        }
    } else {
        $conn->prepare("UPDATE orders SET delivery_tracking_url = ? WHERE id = ?")->execute([$trackingUrl, $orderId]);
        $conn->prepare("UPDATE thirdparty_deliveries SET tracking_reference = COALESCE(?, tracking_reference) WHERE order_id = ?")
            ->execute([$trackingUrl, $orderId]);
    }

    if (function_exists('log_audit')) {
        log_audit($conn, 'outside_lagro_tracking_saved', 'order', (string)$orderId, $trackingUrl ? "Staff #$staffId saved third-party tracking link" : "Staff #$staffId cleared third-party tracking link");
    }

    return $trackingUrl;
}

/**
 * Staff approval or rejection of an Option 1 customer-submitted third-party courier tracking link.
 * Must run inside an active DB transaction.
 */
function kk_review_option1_tracking_link(PDO $conn, int $orderId, string $decision, int $staffId, string $reason = ''): array
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Tracking link review requires an active transaction.');
    }

    $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['order_channel'] ?? '') !== 'ONLINE' || !kk_is_option1_customer_courier($order)) {
        throw new RuntimeException('Option 1 customer-booked delivery order not found.');
    }
    if (!in_array((string)$order['status'], ['Pending', 'Preparing', 'Ready'], true)) {
        throw new RuntimeException('Only active orders can undergo tracking link review.');
    }

    $dispatchStmt = $conn->prepare("SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE");
    $dispatchStmt->execute([$orderId]);
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);

    $releasedToKitchen = false;
    if ($decision === 'verify') {
        $validUrl = normalize_delivery_tracking_url((string)($order['delivery_tracking_url'] ?? ''));
        if ($validUrl === null) {
            throw new RuntimeException('Customer must submit a valid HTTPS courier tracking link before approval.');
        }

        $payStmt = $conn->prepare("SELECT payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $payStmt->execute([$orderId]);
        $payStatus = (string)($payStmt->fetchColumn() ?: '');

        $shouldQueue = ($payStatus === 'Paid' && (string)$order['status'] === 'Pending' && $order['kitchen_queued_at'] === null);
        if ($shouldQueue) {
            $conn->prepare("UPDATE orders SET courier_tracking_status = 'Verified', courier_tracking_verified_by = ?, courier_tracking_verified_at = CURRENT_TIMESTAMP, courier_tracking_rejection_reason = NULL, kitchen_queued_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$staffId, $orderId]);
            $releasedToKitchen = true;
        } else {
            $conn->prepare("UPDATE orders SET courier_tracking_status = 'Verified', courier_tracking_verified_by = ?, courier_tracking_verified_at = CURRENT_TIMESTAMP, courier_tracking_rejection_reason = NULL WHERE id = ?")
                ->execute([$staffId, $orderId]);
        }

        if (function_exists('log_audit')) {
            log_audit($conn, 'outside_lagro_tracking_verified', 'order', (string)$orderId, "Option 1 tracking link verified by staff #$staffId" . ($releasedToKitchen ? ' (released to Kitchen)' : ''));
        }
    } elseif ($decision === 'reject') {
        if ($dispatch && ($dispatch['status'] ?? '') === 'Out for Delivery') {
            throw new RuntimeException('Cannot reject tracking link while courier is already Out for Delivery.');
        }
        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < 5 || mb_strlen($cleanReason) > 255) {
            throw new RuntimeException('Enter a rejection reason of 5–255 characters for the customer.');
        }
        $conn->prepare("UPDATE orders SET courier_tracking_status = 'Rejected', courier_tracking_verified_by = NULL, courier_tracking_verified_at = NULL, courier_tracking_rejection_reason = ?, kitchen_queued_at = CASE WHEN status = 'Pending' THEN NULL ELSE kitchen_queued_at END WHERE id = ?")
            ->execute([$cleanReason, $orderId]);

        if (function_exists('log_audit')) {
            log_audit($conn, 'outside_lagro_tracking_rejected', 'order', (string)$orderId, "Option 1 tracking link rejected by staff #$staffId: $cleanReason");
        }
    } else {
        throw new InvalidArgumentException('Invalid tracking link review decision.');
    }

    return [
        'order_no' => (string)$order['order_no'],
        'queue_no' => (int)$order['queue_no'],
        'released_to_kitchen' => $releasedToKitchen,
    ];
}

/**
 * Mark a kitchen-Ready outside-Lagro third-party order as Out for Delivery (handed to rider).
 * Enforces verified GCash food payment, verified Option 1 tracking or verified Option 2 fee, and HTTPS link.
 */
function kk_dispatch_thirdparty_order(PDO $conn, int $orderId, int $staffId): array
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Third-party dispatch requires an active transaction.');
    }

    $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['order_channel'] ?? '') !== 'ONLINE' || ($order['order_type'] ?? '') !== 'ONLINE' || ($order['delivery_mode'] ?? '') !== 'CUSTOMER_BOOKED') {
        throw new RuntimeException('Outside-Lagro online delivery order not found.');
    }
    if ((string)$order['status'] !== 'Ready') {
        throw new RuntimeException('The kitchen must mark the order Ready before handoff.');
    }

    $cancelCheck = $conn->prepare("SELECT COUNT(*) FROM transaction_requests WHERE order_id = ? AND request_type = 'Cancel Delivery' AND status = 'Pending'");
    $cancelCheck->execute([$orderId]);
    if ((int)$cancelCheck->fetchColumn() > 0) {
        throw new RuntimeException('Cancellation review is pending.');
    }

    $dispatchStmt = $conn->prepare("SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE");
    $dispatchStmt->execute([$orderId]);
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
    if ($dispatch && ($dispatch['status'] ?? '') !== 'Failed') {
        throw new RuntimeException('Courier handoff has already been recorded.');
    }

    $paymentStmt = $conn->prepare("SELECT payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $paymentStmt->execute([$orderId]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || ($payment['payment_status'] ?? '') !== 'Paid') {
        throw new RuntimeException('Verify the GCash food payment before courier handoff.');
    }

    $tpdStmt = $conn->prepare("SELECT * FROM thirdparty_deliveries WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $tpdStmt->execute([$orderId]);
    $tpd = $tpdStmt->fetch(PDO::FETCH_ASSOC);

    $resolvedBooker = kk_resolve_courier_booking_by($order);
    if ($resolvedBooker === 'STORE' && (empty($tpd) || (int)$tpd['fee_paid'] !== 1)) {
        throw new RuntimeException('Third-party delivery fee must be paid and verified first.');
    }

    $validTrackingUrl = null;
    $orderTracking = trim((string)($order['delivery_tracking_url'] ?? ''));
    $tpdTracking = trim((string)($tpd['tracking_reference'] ?? ''));
    try {
        if ($orderTracking !== '') {
            $validTrackingUrl = normalize_delivery_tracking_url($orderTracking);
        } elseif ($tpdTracking !== '' && preg_match('#^https?://#i', $tpdTracking)) {
            $validTrackingUrl = normalize_delivery_tracking_url($tpdTracking);
            if ($validTrackingUrl !== null) {
                $conn->prepare("UPDATE orders SET delivery_tracking_url = ? WHERE id = ?")->execute([$validTrackingUrl, $orderId]);
            }
        }
    } catch (Throwable $e) {
        $validTrackingUrl = null;
    }

    if ($validTrackingUrl === null) {
        throw new RuntimeException('A valid HTTPS third-party courier tracking link is required before handoff.');
    }
    if ($resolvedBooker === 'CUSTOMER' && ($order['courier_tracking_status'] ?? 'None') !== 'Verified') {
        throw new RuntimeException('Approve the customer’s third-party courier tracking link before handoff.');
    }

    if ($dispatch) {
        $conn->prepare("UPDATE delivery_dispatches SET rider_name = 'Third-party courier', rider_phone = NULL, status = 'Out for Delivery', notes = 'Manual third-party courier handoff', assigned_by = ?, updated_by = ?, assigned_at = CURRENT_TIMESTAMP, out_at = CURRENT_TIMESTAMP, delivered_at = NULL, failed_at = NULL WHERE id = ?")
            ->execute([$staffId, $staffId, (int)$dispatch['id']]);
    } else {
        $conn->prepare("INSERT INTO delivery_dispatches (order_id, rider_name, rider_phone, status, notes, assigned_by, updated_by, assigned_at, out_at) VALUES (?, 'Third-party courier', NULL, 'Out for Delivery', 'Manual third-party courier handoff', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
            ->execute([$orderId, $staffId, $staffId]);
    }
    if ($resolvedBooker === 'STORE') {
        $conn->prepare("UPDATE thirdparty_deliveries SET status = 'Dispatched' WHERE order_id = ?")->execute([$orderId]);
    }

    if (function_exists('log_audit')) {
        log_audit($conn, 'outside_lagro_dispatched', 'order', (string)$orderId, "Order dispatched to third-party courier by staff #$staffId");
    }

    return [
        'order_no' => (string)$order['order_no'],
        'booking_by' => $resolvedBooker,
    ];
}

/**
 * Record failed delivery attempt for an active Out-for-Delivery order (Within or Outside Lagro).
 */
function kk_fail_delivery_order(PDO $conn, int $orderId, int $staffId, string $notes): array
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Recording delivery failure requires an active transaction.');
    }
    $cleanNotes = trim($notes);
    if (mb_strlen($cleanNotes) < 8 || mb_strlen($cleanNotes) > 500) {
        throw new RuntimeException('Enter a failure reason of 8–500 characters.');
    }

    $orderStmt = $conn->prepare("SELECT id, order_no, order_channel, order_type, status FROM orders WHERE id = ? FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['order_channel'] ?? '') !== 'ONLINE' || ($order['order_type'] ?? '') !== 'ONLINE' || ($order['status'] ?? '') !== 'Ready') {
        throw new RuntimeException('Only a kitchen-ready order out for delivery can be marked Failed.');
    }

    $dispatchStmt = $conn->prepare("SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE");
    $dispatchStmt->execute([$orderId]);
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dispatch || ($dispatch['status'] ?? '') !== 'Out for Delivery') {
        throw new RuntimeException('Only an order out for delivery can be marked Failed.');
    }

    $conn->prepare("UPDATE delivery_dispatches SET status = 'Failed', notes = ?, failed_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?")
        ->execute([$cleanNotes, $staffId, (int)$dispatch['id']]);

    if (function_exists('log_audit')) {
        log_audit($conn, 'delivery_failed', 'order', (string)$orderId, "Delivery failure recorded by staff #$staffId: $cleanNotes");
    }

    return ['order_no' => (string)$order['order_no']];
}

/**
 * Complete an Out-for-Delivery order as Delivered & Handed to Customer (Within Lagro COD/GCash & Outside Lagro).
 * Must run inside an active DB transaction.
 */
function kk_complete_delivery_order(PDO $conn, int $orderId, int $staffId, string $roleKey, bool $cashCollectedConfirmed = false): array
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Completing delivery requires an active transaction.');
    }

    $orderStmt = $conn->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['order_channel'] ?? '') !== 'ONLINE' || ($order['order_type'] ?? '') !== 'ONLINE') {
        throw new RuntimeException('Online delivery order not found.');
    }
    if ((string)$order['status'] !== 'Ready') {
        throw new RuntimeException('The kitchen must mark the delivery Ready before delivery completion.');
    }

    $cancelCheck = $conn->prepare("SELECT COUNT(*) FROM transaction_requests WHERE order_id = ? AND request_type = 'Cancel Delivery' AND status = 'Pending'");
    $cancelCheck->execute([$orderId]);
    if ((int)$cancelCheck->fetchColumn() > 0) {
        throw new RuntimeException('Cancellation review is pending.');
    }

    $paymentStmt = $conn->prepare("SELECT id, payment_method, payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $paymentStmt->execute([$orderId]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new RuntimeException('Payment record is missing.');
    }

    $dispatchStmt = $conn->prepare("SELECT id, status FROM delivery_dispatches WHERE order_id = ? FOR UPDATE");
    $dispatchStmt->execute([$orderId]);
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dispatch || ($dispatch['status'] ?? '') !== 'Out for Delivery') {
        throw new RuntimeException('Only an order out for delivery can be marked Delivered.');
    }

    if (($order['delivery_mode'] ?? '') === 'CUSTOMER_BOOKED' && (strcasecmp((string)$payment['payment_method'], 'GCash') !== 0 || ($payment['payment_status'] ?? '') !== 'Paid')) {
        throw new RuntimeException('Outside-Lagro third-party delivery requires verified GCash payment.');
    }
    if (strcasecmp((string)$payment['payment_method'], 'GCash') === 0 && ($payment['payment_status'] ?? '') !== 'Paid') {
        throw new RuntimeException('Verify the GCash payment before completing delivery.');
    }

    if (($payment['payment_status'] ?? '') !== 'Paid') {
        if ($order['cash_collected'] !== null) {
            throw new RuntimeException('Cash has already been recorded for this order.');
        }
        if (!$cashCollectedConfirmed) {
            throw new RuntimeException('Confirm cash was collected from the customer before marking Delivered.');
        }
        $isCod = ($payment['payment_method'] ?? '') === 'Cash on Delivery';
        if ($isCod && ($order['cod_approval_status'] ?? '') !== 'Approved') {
            throw new RuntimeException('Approve the COD order before collecting payment.');
        }
        $shiftId = null;
        if ($roleKey === 'cashier') {
            $shiftStmt = $conn->prepare("SELECT id FROM cashier_shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $shiftStmt->execute([$staffId]);
            $shiftId = $shiftStmt->fetchColumn();
            if (!$shiftId && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['pos_active_cashier']['id'])) {
                $shiftStmt->execute([(int)$_SESSION['pos_active_cashier']['id']]);
                $shiftId = $shiftStmt->fetchColumn();
            }
            if (!$shiftId) {
                throw new RuntimeException('Open your cashier shift before collecting cash.');
            }
        }
        $conn->prepare("UPDATE payments SET payment_status = 'Paid', amount_paid = ?, change_amount = 0, paid_at = CURRENT_TIMESTAMP, shift_id = ? WHERE id = ?")
            ->execute([(float)$order['total'], $shiftId ?: null, (int)$payment['id']]);
        if ($isCod) {
            $conn->prepare('UPDATE orders SET cash_collected = ?, collected_by_user_id = ? WHERE id = ?')
                ->execute([(float)$order['total'], $staffId, $orderId]);
            if ($roleKey !== 'cashier') {
                $existingHandover = $conn->prepare("SELECT id FROM cod_cash_handovers WHERE order_id = ? LIMIT 1 FOR UPDATE");
                $existingHandover->execute([$orderId]);
                if (!$existingHandover->fetchColumn()) {
                    $conn->prepare("INSERT INTO cod_cash_handovers (order_id, collected_by_user_id, amount_collected, collected_at, status) VALUES (?, ?, ?, CURRENT_TIMESTAMP, 'pending')")
                        ->execute([$orderId, $staffId, (float)$order['total']]);
                }
            }
            if ((float)($order['free_delivery_discount'] ?? 0) > 0 && !empty($order['customer_phone'])) {
                $pHash = hash('sha256', preg_replace('/\D+/', '', (string)$order['customer_phone']));
                $aHash = hash('sha256', strtolower(trim((string)$order['delivery_address'])));
                $conn->prepare("INSERT IGNORE INTO first_order_benefit_hashes (phone_hash, address_hash, used_order_id) VALUES (?, ?, ?)")
                    ->execute([$pHash, $aHash, $orderId]);
            }
        }
    }

    if ((int)($order['inventory_deducted'] ?? 0) === 0) {
        $logCheck = $conn->prepare("SELECT COUNT(*) FROM inventory_logs WHERE action = 'Order Deduction' AND remarks LIKE ?");
        $logCheck->execute(['%' . $order['order_no'] . '%']);
        if ((int)$logCheck->fetchColumn() === 0) {
            $itemsStmt = $conn->prepare('SELECT menu_item_id, SUM(quantity) AS quantity FROM order_items WHERE order_id = ? GROUP BY menu_item_id ORDER BY menu_item_id');
            $itemsStmt->execute([$orderId]);
            $deduct = $conn->prepare("UPDATE menu_items SET stock = GREATEST(0, stock - ?), availability = CASE WHEN stock <= 0 THEN 'Unavailable' ELSE availability END WHERE id = ?");
            $log = $conn->prepare("INSERT INTO inventory_logs (inventory_id, menu_item_id, user_id, action, quantity, remarks) VALUES (NULL, ?, ?, 'Order Deduction', ?, ?)");
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $quantity = (int)$item['quantity'];
                $menuId = (int)$item['menu_item_id'];
                if ($quantity < 1) continue;
                $deduct->execute([$quantity, $menuId]);
                if ($deduct->rowCount() !== 1) {
                    throw new RuntimeException('Unable to deduct legacy order stock.');
                }
                $log->execute([$menuId, $staffId, $quantity, 'Online order ' . $order['order_no'] . ' completed deduction']);
            }
        }
        $conn->prepare('UPDATE orders SET inventory_deducted = 1 WHERE id = ?')->execute([$orderId]);
    }

    $conn->prepare("UPDATE delivery_dispatches SET status = 'Delivered', delivered_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?")
        ->execute([$staffId, (int)$dispatch['id']]);
    $orderUpdate = $conn->prepare("UPDATE orders SET status = 'Served', served_at = CURRENT_TIMESTAMP, served_by = ?, paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP) WHERE id = ? AND status = 'Ready'");
    $orderUpdate->execute([$staffId, $orderId]);
    if ($orderUpdate->rowCount() !== 1) {
        throw new RuntimeException('Order changed before delivery completion.');
    }

    if (function_exists('log_audit')) {
        log_audit($conn, 'delivery_completed', 'order', (string)$orderId, "Order {$order['order_no']} marked Delivered by staff #$staffId");
    }

    return [
        'order_id' => $orderId,
        'order_no' => (string)$order['order_no'],
        'delivery_mode' => (string)($order['delivery_mode'] ?? 'LAGRO_FREE'),
    ];
}
