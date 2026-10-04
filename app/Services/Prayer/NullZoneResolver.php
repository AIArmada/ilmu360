<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use App\Contracts\PrayerZoneResolver;

/**
 * Zone resolver for coordinate-only countries.
 *
 * Passes coordinates through untouched and reports no zones, so callers
 * stay branch-free: the anchor action falls through to the daily
 * provider chain and no GPS lookup is ever queued.
 */
final class NullZoneResolver implements PrayerZoneResolver
{
    /**
     * @param  list<string>  $districtCandidates
     * @return array{zone: null, lat: float|null, lng: float|null, source: string}
     */
    public function resolve(
        ?float $latitude,
        ?float $longitude,
        ?string $stateCode = null,
        array $districtCandidates = [],
    ): array {
        return [
            'zone' => null,
            'lat' => $latitude,
            'lng' => $longitude,
            'source' => 'no-zone',
        ];
    }

    public function queueGpsResolution(float $latitude, float $longitude): void
    {
        // Coordinate-only countries have no zone lookup API.
    }

    public function isKnownZone(string $zone): bool
    {
        return false;
    }

    public function zoneCodes(): array
    {
        return [];
    }

    public function coordsForZone(string $zone): ?array
    {
        return null;
    }
}
