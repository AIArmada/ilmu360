<?php

declare(strict_types=1);

namespace App\Support\EventDiscovery;

use AIArmada\Addressing\Data\AddressLocationData;

/**
 * The product-specific representation of public event discovery filters.
 *
 * Location is deliberately kept as package data so each search engine derives
 * its own predicate from the same canonical criteria.
 */
final class EventDiscoveryFilterSet
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function location(array $filters): AddressLocationData
    {
        $locationFilters = [];

        foreach (['country_id', 'state_id', 'city_id'] as $column) {
            $value = $filters[$column] ?? null;

            if (is_string($value) || is_int($value)) {
                $locationFilters[$column] = (string) $value;
            }
        }

        $assignments = $filters['area_assignments'] ?? [];

        if (is_array($assignments)) {
            $locationFilters['area_assignments'] = $assignments;
        }

        return AddressLocationData::fromArray($locationFilters);
    }

    /** @return list<string> */
    public function typesenseLocationFilterParts(AddressLocationData $location): array
    {
        $parts = array_map(
            static fn (string $column, string $value): string => "{$column}:={$value}",
            array_keys($location->criteria()),
            array_values($location->criteria()),
        );

        foreach ($location->assignments() as $role => $areaId) {
            $parts[] = "{$role}:={$areaId}";
        }

        return $parts;
    }
}
