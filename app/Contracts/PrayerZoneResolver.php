<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Maps event locations to prayer zones for one country.
 *
 * Zone-based countries (MY via JAKIM) resolve GPS/areas to a zone code and
 * serve zone-month caches; coordinate-only countries use the null adapter,
 * which passes coordinates through untouched. New zone-based countries
 * slot in by implementing this contract and adding one
 * `prayer.zone_resolvers` row — callers never branch on country.
 */
interface PrayerZoneResolver
{
    /**
     * Resolve a location to a zone, or pass coordinates through.
     *
     * @param  list<string>  $districtCandidates  finer-to-broader area names
     * @return array{zone: string|null, lat: float|null, lng: float|null, source: string}
     */
    public function resolve(
        ?float $latitude,
        ?float $longitude,
        ?string $stateCode = null,
        array $districtCandidates = [],
    ): array;

    /**
     * Queue an off-path GPS→zone lookup. No-op where the country has no
     * lookup API. Never performs HTTP itself.
     */
    public function queueGpsResolution(float $latitude, float $longitude): void;

    public function isKnownZone(string $zone): bool;

    /**
     * All zone codes this authority publishes, for prefetch warming.
     * Empty where the country has no zones.
     *
     * @return list<string>
     */
    public function zoneCodes(): array;

    /**
     * Representative coordinates for a zone, for coordinate providers
     * filling a zone-month cache key. Null where unknown.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function coordsForZone(string $zone): ?array;
}
