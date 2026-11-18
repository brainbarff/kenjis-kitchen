<?php
/**
 * Delivery Areas & Coverage Boundary public API for online checkout.
 * Returns eligible streets from delivery_areas, the verified Barangay Greater Lagro
 * boundary polygon, store location, and configurable tile settings.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/delivery_tracking.php';

try {
    $showUnverified = (int)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'show_unverified_areas_to_customers'")->fetchColumn() ?: 0);

    $sql = "SELECT id, barangay, subdivision, street_name, is_verified FROM delivery_areas WHERE is_active = 1";
    if ($showUnverified !== 1) {
        $sql .= " AND is_verified = 1";
    }
    $sql .= " ORDER BY subdivision ASC, street_name ASC";

    $areas = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $boundary = kk_delivery_boundary_data();

    echo json_encode([
        'status' => 'success',
        'allow_unverified' => ($showUnverified === 1),
        'areas' => $areas,
        'boundary' => [
            'boundary_id' => $boundary['boundary_id'] ?? 'BRGY_GREATER_LAGRO_QC_1381300141',
            'boundary_version' => $boundary['canonical_version'] ?? $boundary['boundary_version'],
            'name' => $boundary['name'] ?? 'Barangay Greater Lagro',
            'municipality' => $boundary['municipality'] ?? 'Quezon City',
            'district' => $boundary['district'] ?? '5th District',
            'postal_code' => $boundary['postal_code'] ?? '1118',
            'psgc_10digit' => $boundary['psgc_10digit'] ?? '1381300141',
            'source' => $boundary['source'] ?? 'OpenStreetMap Relation 1783169',
            'source_url' => $boundary['source_url'] ?? 'https://www.openstreetmap.org/relation/1783169',
            'source_timestamp' => $boundary['source_timestamp'] ?? '',
            'crs' => $boundary['crs'] ?? 'EPSG:4326',
            'license' => $boundary['license'] ?? 'ODbL 1.0',
            'attribution' => $boundary['attribution'] ?? '© OpenStreetMap contributors (ODbL 1.0)',
            'edge_buffer_meters' => (float)($boundary['edge_buffer_meters'] ?? 2.0),
            'bbox' => $boundary['bbox'] ?? null,
            'geometry' => $boundary['geometry'],
        ],
        'store_location' => $boundary['store_location'] ?? [
            'name' => "Kenji's Kitchen",
            'address' => 'Blk 79 Lot 34 Florante at Laura St., Brgy. Greater Lagro, Quezon City',
            'lat' => 14.729954,
            'lng' => 121.066199,
        ],
        'map_config' => [
            'tile_url' => defined('KK_MAP_TILE_URL') ? KK_MAP_TILE_URL : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'tile_attribution' => defined('KK_MAP_TILE_ATTRIBUTION') ? KK_MAP_TILE_ATTRIBUTION : '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            'max_zoom' => defined('KK_MAP_TILE_MAX_ZOOM') ? KK_MAP_TILE_MAX_ZOOM : 19,
            'edge_buffer_meters' => (float)($boundary['edge_buffer_meters'] ?? 2.0),
        ],
        'local_features' => $boundary['local_features'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'code' => 'OUTAGE',
        'message' => 'Unable to check right now. Delivery coverage data is temporarily unavailable.',
    ]);
}
