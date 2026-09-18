<?php

declare(strict_types=1);

namespace App\Support\Location;

use AIArmada\Addressing\Data\AddressHierarchyDefinition;

/**
 * Maps provider hierarchy levels to Google address-component types.
 *
 * The mapping is derived from hierarchy shape alone — postal versus
 * administrative, and the level's depth among its hierarchy's area levels —
 * so newly registered country providers resolve through the same rule with
 * zero app changes. Provider type filters and parent scoping disambiguate
 * levels that share a candidate component.
 */
final class GooglePlaceComponentMapper
{
    /**
     * Ordered Google address-component types whose value should name a level.
     *
     * @return list<string>
     */
    public static function componentTypesForLevel(AddressHierarchyDefinition $hierarchy, int $areaDepth): array
    {
        if ($hierarchy->key === 'postal') {
            return ['locality', 'postal_town', 'sublocality_level_1', 'sublocality_level_2', 'sublocality', 'neighborhood'];
        }

        $primary = 'administrative_area_level_'.($areaDepth + 2);
        $orderedAdmin = [$primary];

        for ($level = 2; $level <= 7; $level++) {
            $type = "administrative_area_level_{$level}";

            if ($type !== $primary) {
                $orderedAdmin[] = $type;
            }
        }

        // Deep Google admin levels are rarely reported; past the second area
        // level the populated-place name carries the signal (town, mukim),
        // while shallow levels keep the admin stack first so a town sharing
        // its district's name can never shadow the district itself.
        if ($areaDepth >= 2) {
            return [
                $primary,
                'locality',
                'postal_town',
                ...array_slice($orderedAdmin, 1),
                'sublocality_level_1',
                'sublocality_level_2',
                'sublocality',
                'neighborhood',
            ];
        }

        return [
            ...$orderedAdmin,
            'locality',
            'postal_town',
            'sublocality_level_1',
            'sublocality_level_2',
            'sublocality',
            'neighborhood',
        ];
    }

    /**
     * Leading administrative generics Google omits from area names.
     *
     * Escape hatch, not a requirement: providers resolve without an entry,
     * and provider-authored AddressAreaName aliases cover most divergences.
     * Add a line only when a provider's official names carry a generic that
     * Google drops ("Kota Jakarta Pusat" versus "Jakarta Pusat") and aliases
     * are impractical. Both sides strip equally, so entries can only turn a
     * missed match into a found one — never into a wrong one.
     *
     * @return list<string> lowercase, matched repeatedly at string start
     */
    public static function namePrefixesToStrip(string $countryCode): array
    {
        return match (mb_strtoupper(mb_trim($countryCode))) {
            'ID' => ['kabupaten', 'kota', 'kecamatan', 'kelurahan', 'provinsi', 'administrasi', 'dki', 'di'],
            default => [],
        };
    }
}
