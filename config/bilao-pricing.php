<?php
/** Canonical bilao prices used by both the POS display and order processor. */
function bilao_pricing_specs(): array
{
    $pastaRates = [
        'S' => ['price' => 500, 'desc' => 'Good for 5-7 pax'],
        'M' => ['price' => 850, 'desc' => 'Good for 8-12 pax'],
        'L' => ['price' => 1200, 'desc' => 'Good for 12-18 pax'],
        'XL' => ['price' => 1550, 'desc' => 'Good for 18-25 pax']
    ];
    $palabokRates = [
        'S' => ['price' => 650, 'desc' => 'Good for 6-9 pax'],
        'M' => ['price' => 850, 'desc' => 'Good for 9-13 pax'],
        'L' => ['price' => 1000, 'desc' => 'Good for 20-25 pax'],
        'XL' => ['price' => 1200, 'desc' => 'Good for 30-35 pax']
    ];
    $bihonRates = [
        'S' => ['price' => 550, 'desc' => 'Good for 6-9 pax'],
        'M' => ['price' => 750, 'desc' => 'Good for 9-13 pax'],
        'L' => ['price' => 950, 'desc' => 'Good for 20-25 pax'],
        'XL' => ['price' => 1100, 'desc' => 'Good for 30-35 pax']
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
            'rates' => []
        ];
        $specs[$dish]['rates'] = $prices;
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
