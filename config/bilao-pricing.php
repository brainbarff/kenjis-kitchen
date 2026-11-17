<?php
/** Reference Bilao metadata and variant/drink state resolvers. Database prices remain authoritative. */
function bilao_pricing_specs(): array
{
    $pastaRates = [
        'S' => ['price' => 500, 'desc' => 'Good for 5-7 pax'],
        'M' => ['price' => 850, 'desc' => 'Good for 8-12 pax (Advance reservation required)'],
        'L' => ['price' => 1200, 'desc' => 'Good for 12-18 pax (Advance reservation required)'],
        'XL' => ['price' => 1550, 'desc' => 'Good for 18-25 pax (Advance reservation required)']
    ];
    $palabokRates = [
        'S' => ['price' => 650, 'desc' => 'Good for 6-9 pax'],
        'M' => ['price' => 850, 'desc' => 'Good for 9-13 pax (Advance reservation required)'],
        'L' => ['price' => 1000, 'desc' => 'Good for 20-25 pax (Advance reservation required)'],
        'XL' => ['price' => 1200, 'desc' => 'Good for 30-35 pax (Advance reservation required)']
    ];
    $bihonRates = [
        'S' => ['price' => 550, 'desc' => 'Good for 6-9 pax'],
        'M' => ['price' => 750, 'desc' => 'Good for 9-13 pax (Advance reservation required)'],
        'L' => ['price' => 950, 'desc' => 'Good for 20-25 pax (Advance reservation required)'],
        'XL' => ['price' => 1100, 'desc' => 'Good for 30-35 pax (Advance reservation required)']
    ];
    $rates = [
        'Spaghetti' => $pastaRates,
        'Carbonara' => $pastaRates,
        'Palabok' => $palabokRates,
        'Sotanghon' => $palabokRates,
        'Bihon' => $bihonRates,
        'Canton' => $bihonRates
    ];
    $subcategories = [
        'Spaghetti' => 'pasta', 'Carbonara' => 'pasta',
        'Palabok' => 'palabok_sotanghon', 'Sotanghon' => 'palabok_sotanghon',
        'Bihon' => 'bihon_canton', 'Canton' => 'bihon_canton'
    ];

    $specs = [];
    foreach ($rates as $dish => $prices) {
        $specs[$dish] = [
            'subcat' => $subcategories[$dish],
            'rates' => $prices
        ];
    }
    return $specs;
}

function bilao_dish_from_menu_name(string $itemName): ?string
{
    foreach (array_keys(bilao_pricing_specs()) as $dish) {
        if (preg_match('/\b' . preg_quote($dish, '/') . '\b/i', $itemName)) {
            return $dish;
        }
    }
    return null;
}

function bilao_price(string $dish, string $size): ?float
{
    $specs = bilao_pricing_specs();
    $size = strtoupper($size);
    return isset($specs[$dish]['rates'][$size])
        ? (float)$specs[$dish]['rates'][$size]['price']
        : null;
}

function bilao_size_from_menu_name(string $itemName): ?string
{
    if (preg_match('/\b(XL|Extra\s*Large)\b/i', $itemName)) {
        return 'XL';
    }
    if (preg_match('/\b(L|Large)\b/i', $itemName)) {
        return 'L';
    }
    if (preg_match('/\b(M|Medium)\b/i', $itemName)) {
        return 'M';
    }
    if (preg_match('/\b(S|Small)\b/i', $itemName)) {
        return 'S';
    }
    return null;
}

function bilao_packaging_images(string $dish, ?string $fallbackImage = null): array
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $dish));
    $uploadRoot = dirname(__DIR__) . '/uploads/menu/';
    $trayRel = 'bilao-favorites/' . $slug . '_tray.png';
    $bilaoRel = 'bilao-favorites/' . $slug . '_bilao.png';

    $trayPath = is_file($uploadRoot . $trayRel) ? $trayRel : ($fallbackImage ?: $bilaoRel);
    $bilaoPath = is_file($uploadRoot . $bilaoRel) ? $bilaoRel : ($fallbackImage ?: $trayRel);

    $resolveUrl = static function (?string $rel): string {
        if (!$rel) {
            return '';
        }
        if (function_exists('menu_image_url')) {
            return (string)menu_image_url($rel);
        }
        return $rel;
    };

    return [
        'tray_image' => $trayPath,
        'bilao_image' => $bilaoPath,
        'tray_image_url' => $resolveUrl($trayPath),
        'bilao_image_url' => $resolveUrl($bilaoPath)
    ];
}

/**
 * Resolves authoritative active/archived variant states for all Bilao Favorites dishes.
 * Every size maps strictly to its own canonical menu_items row; missing or archived sizes
 * never fall back to another size's ID or stock.
 */
function resolve_bilao_variant_states(PDO $conn): array
{
    $specs = bilao_pricing_specs();
    $stmt = $conn->query("
        SELECT m.*, c.category_name, c.id AS cat_id
        FROM menu_items m
        JOIN categories c ON c.id = m.category_id
        WHERE c.category_name = 'Bilao Favorites' AND c.status = 'Active'
        ORDER BY m.is_archived ASC, CASE WHEN m.sort_order > 0 THEN m.sort_order ELSE 999999 END ASC, m.id ASC
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $activeByDishSize = [];
    $archivedByDishSize = [];
    $dishMeta = [];
    $today = date('Y-m-d');

    foreach ($rows as $row) {
        $dish = bilao_dish_from_menu_name((string)($row['item_name'] ?? ''));
        if (!$dish || !isset($specs[$dish])) {
            continue;
        }
        $size = bilao_size_from_menu_name((string)$row['item_name']);
        if ($size === null || !isset($specs[$dish]['rates'][$size])) {
            continue;
        }
        $isArchived = (int)($row['is_archived'] ?? 0) === 1;

        if (!isset($dishMeta[$dish])) {
            $dishMeta[$dish] = [
                'image' => $row['image'] ?? ($row['image_path'] ?? null),
                'category_id' => (int)($row['category_id'] ?? 0)
            ];
        } elseif (empty($dishMeta[$dish]['image']) && (!empty($row['image']) || !empty($row['image_path']))) {
            $dishMeta[$dish]['image'] = $row['image'] ?? $row['image_path'];
        }

        if ($isArchived) {
            if (!isset($archivedByDishSize[$dish][$size])) {
                $archivedByDishSize[$dish][$size] = $row;
            }
        } else {
            if (!empty($row['image']) || !empty($row['image_path'])) {
                $dishMeta[$dish]['image'] = $row['image'] ?? $row['image_path'];
            }
            if (!isset($activeByDishSize[$dish][$size])) {
                $activeByDishSize[$dish][$size] = $row;
            }
        }
    }

    $groups = [];
    foreach ($specs as $dish => $spec) {
        $hasAnyActiveRow = !empty($activeByDishSize[$dish]);
        $hasAnyArchivedRow = !empty($archivedByDishSize[$dish]);
        if (!$hasAnyActiveRow && !$hasAnyArchivedRow) {
            continue;
        }

        $variants = [];
        $activeSizes = [];
        $archivedSizes = [];
        $eligibleSizes = [];
        $allowedIdsBySize = [];

        foreach ($spec['rates'] as $size => $rate) {
            if (isset($activeByDishSize[$dish][$size])) {
                $r = $activeByDishSize[$dish][$size];
                $stock = max(0, (int)($r['stock'] ?? 0));
                $avail = (string)($r['availability'] ?? 'Available');
                $eligible = ($avail === 'Available' && $stock > 0);

                $basePrice = isset($r['price']) && is_numeric($r['price']) ? (float)$r['price'] : (float)$rate['price'];
                $promoPrice = isset($r['promo_price']) && $r['promo_price'] !== null && $r['promo_price'] !== ''
                    ? (float)$r['promo_price']
                    : null;
                $promoStart = $r['promo_start'] ?? null;
                $promoEnd = $r['promo_end'] ?? null;
                $isPromo = ($promoPrice !== null && $promoStart !== null && $promoEnd !== null && $today >= $promoStart && $today <= $promoEnd);
                $effectivePrice = $isPromo ? $promoPrice : $basePrice;

                $dbDesc = trim((string)($r['description'] ?? ''));
                $desc = $dbDesc !== '' ? $dbDesc : (string)$rate['desc'];

                $variants[$size] = [
                    'id' => (int)$r['id'],
                    'item_name' => (string)($r['item_name'] ?? ($dish . ' ' . $size)),
                    'price' => $effectivePrice,
                    'base_price' => $basePrice,
                    'promo_price' => $promoPrice,
                    'is_promo' => $isPromo,
                    'desc' => $desc,
                    'stock' => $stock,
                    'availability' => $avail,
                    'is_archived' => false,
                    'eligible' => $eligible
                ];
                $activeSizes[] = $size;
                if ($eligible) {
                    $eligibleSizes[] = $size;
                }
                $allowedIdsBySize[$size] = [(int)$r['id']];
            } else {
                $archivedRow = $archivedByDishSize[$dish][$size] ?? null;
                $variants[$size] = [
                    'id' => $archivedRow ? (int)$archivedRow['id'] : 0,
                    'item_name' => $archivedRow ? (string)$archivedRow['item_name'] : ($dish . ' ' . $size),
                    'price' => (float)$rate['price'],
                    'base_price' => (float)$rate['price'],
                    'promo_price' => null,
                    'is_promo' => false,
                    'desc' => (string)$rate['desc'],
                    'stock' => 0,
                    'availability' => 'Unavailable',
                    'is_archived' => true,
                    'eligible' => false
                ];
                $archivedSizes[] = $size;
                $allowedIdsBySize[$size] = [];
            }
        }

        $pkgImages = bilao_packaging_images($dish, $dishMeta[$dish]['image'] ?? null);

        $groups[$dish] = [
            'dish' => $dish,
            'image' => $dishMeta[$dish]['image'] ?? $pkgImages['bilao_image'],
            'tray_image' => $pkgImages['tray_image'],
            'bilao_image' => $pkgImages['bilao_image'],
            'tray_image_url' => $pkgImages['tray_image_url'],
            'bilao_image_url' => $pkgImages['bilao_image_url'],
            'category_id' => $dishMeta[$dish]['category_id'] ?? 0,
            'subcat' => $spec['subcat'],
            'variants' => $variants,
            'active_sizes' => $activeSizes,
            'archived_sizes' => $archivedSizes,
            'eligible_sizes' => $eligibleSizes,
            'allowed_ids_by_size' => $allowedIdsBySize,
            'all_variants_archived' => empty($activeSizes)
        ];
    }

    return $groups;
}

/**
 * Validates that a Bilao Favorites order line references the exact unarchived, eligible size variant
 * and has not substituted another size's menu_item_id.
 */
function validate_bilao_variant_order(PDO $conn, int $submittedItemId, string $dish, string $size): array
{
    $size = strtoupper(trim($size));
    $groups = resolve_bilao_variant_states($conn);

    if (!isset($groups[$dish])) {
        return ['ok' => false, 'msg' => $dish . ' Bilao is no longer available.'];
    }

    $group = $groups[$dish];
    if (!empty($group['all_variants_archived'])) {
        return ['ok' => false, 'msg' => $dish . ' Bilao has been archived and is no longer available.'];
    }

    if (!isset($group['variants'][$size])) {
        return ['ok' => false, 'msg' => 'Please select a valid Bilao size (S, M, L, or XL).'];
    }

    $variant = $group['variants'][$size];
    if (!empty($variant['is_archived'])) {
        return ['ok' => false, 'msg' => $dish . ' (' . $size . ') has been archived or is not available.'];
    }

    $allowedIds = $group['allowed_ids_by_size'][$size] ?? [];
    if (!in_array($submittedItemId, $allowedIds, true)) {
        return ['ok' => false, 'msg' => 'The selected menu item does not match ' . $dish . ' (' . $size . '). Please refresh the menu and try again.'];
    }

    if (empty($variant['eligible'])) {
        return ['ok' => false, 'msg' => $dish . ' (' . $size . ') is currently unavailable or out of stock.'];
    }

    return ['ok' => true, 'variant' => $variant];
}

function one_liter_drink_flavors(): array
{
    return ['Coca-Cola', 'Sprite', 'Royal', 'Mountain Dew'];
}

function is_one_liter_grouped_drink_name(string $itemName): bool
{
    return strcasecmp(trim($itemName), 'Assorted 1L Bottled Drink') === 0;
}

function one_liter_flavor_from_item_name(string $itemName): ?string
{
    $clean = trim($itemName);
    if ($clean === '' || is_one_liter_grouped_drink_name($clean)) {
        return null;
    }
    if (!preg_match('/\b1\s*(?:l|liter|litre)\b/i', $clean)) {
        return null;
    }
    foreach (one_liter_drink_flavors() as $flavor) {
        if (preg_match('/^' . preg_quote($flavor, '/') . '\b/i', $clean)) {
            return $flavor;
        }
    }
    return null;
}

/**
 * Resolves each 1L bottled drink product independently from its own menu_items row.
 * The retired Assorted 1L Bottled Drink record never controls or disables individual 1L drinks.
 */
function resolve_one_liter_drink_variants(PDO $conn): array
{
    $stmt = $conn->query("
        SELECT m.*, c.category_name
        FROM menu_items m
        JOIN categories c ON c.id = m.category_id
        WHERE c.category_name = 'Drinks' AND c.status = 'Active'
        ORDER BY m.is_archived ASC, m.sort_order ASC, m.id ASC
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $parentRow = null;
    $childRowsByFlavor = [];
    $childItemIds = [];

    foreach ($rows as $row) {
        $name = (string)($row['item_name'] ?? '');
        if (is_one_liter_grouped_drink_name($name)) {
            if ($parentRow === null || ((int)$parentRow['is_archived'] === 1 && (int)$row['is_archived'] === 0)) {
                $parentRow = $row;
            }
            continue;
        }

        $flavor = one_liter_flavor_from_item_name($name);
        if ($flavor !== null) {
            $childRowsByFlavor[$flavor][] = $row;
            $childItemIds[] = (int)$row['id'];
        }
    }

    $parentId = $parentRow ? (int)$parentRow['id'] : 0;
    $parentArchived = !$parentRow || (int)($parentRow['is_archived'] ?? 0) === 1;

    $variants = [];
    $activeFlavors = [];
    $eligibleFlavors = [];
    $archivedFlavors = [];

    foreach (one_liter_drink_flavors() as $flavor) {
        if (empty($childRowsByFlavor[$flavor])) {
            $variants[$flavor] = [
                'flavor' => $flavor,
                'menu_item_id' => 0,
                'has_dedicated_row' => false,
                'is_archived' => true,
                'availability' => 'Unavailable',
                'stock' => 0,
                'eligible' => false
            ];
            $archivedFlavors[] = $flavor;
            continue;
        }

        $activeChild = null;
        $firstChild = $childRowsByFlavor[$flavor][0];
        foreach ($childRowsByFlavor[$flavor] as $candidate) {
            if ((int)($candidate['is_archived'] ?? 0) === 0) {
                $activeChild = $candidate;
                break;
            }
        }

        if ($activeChild === null) {
            $variants[$flavor] = [
                'flavor' => $flavor,
                'menu_item_id' => (int)$firstChild['id'],
                'has_dedicated_row' => true,
                'is_archived' => true,
                'availability' => 'Unavailable',
                'stock' => 0,
                'eligible' => false
            ];
            $archivedFlavors[] = $flavor;
        } else {
            $cStock = max(0, (int)($activeChild['stock'] ?? 0));
            $cAvail = (string)($activeChild['availability'] ?? 'Unavailable');
            $eligible = ($cAvail === 'Available' && $cStock > 0);
            $variants[$flavor] = [
                'flavor' => $flavor,
                'menu_item_id' => (int)$activeChild['id'],
                'has_dedicated_row' => true,
                'is_archived' => false,
                'availability' => $cAvail,
                'stock' => $cStock,
                'eligible' => $eligible
            ];
            $activeFlavors[] = $flavor;
            if ($eligible) {
                $eligibleFlavors[] = $flavor;
            }
        }
    }

    return [
        'parent_id' => $parentId,
        'parent_archived' => $parentArchived,
        'variants' => $variants,
        'active_flavors' => $activeFlavors,
        'eligible_flavors' => $eligibleFlavors,
        'archived_flavors' => $archivedFlavors,
        'all_variants_archived' => empty($activeFlavors),
        'child_item_ids' => array_values(array_unique($childItemIds))
    ];
}
