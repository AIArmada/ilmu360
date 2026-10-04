<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use App\Contracts\PrayerZoneResolver;
use App\Jobs\ResolveGpsZone;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Maps Malaysian locations to JAKIM prayer zones.
 *
 * Cache reads only — never performs HTTP. GPS→zone lookups run in the
 * prefetch job (off-path); the submit path reads the memo this resolver
 * maintains and degrades to state/country defaults on a miss.
 */
final class JakimZoneResolver implements PrayerZoneResolver
{
    /**
     * Zone ladder: GPS memo, offline district match, state default,
     * country default. District candidates climb finer→broader areas
     * (subdivision literal, district, mukim parent, city); the first
     * candidate matching zone text within the state wins.
     *
     * @param  list<string>  $districtCandidates
     * @return array{zone: string, lat: float, lng: float, source: string}
     */
    public function resolve(
        ?float $latitude,
        ?float $longitude,
        ?string $stateCode = null,
        array $districtCandidates = [],
        ?DistrictZoneMatcher $matcher = null,
    ): array {
        if ($latitude !== null && $longitude !== null) {
            $memoized = $this->memoizedZone($latitude, $longitude);

            if ($memoized !== null) {
                return [
                    'zone' => $memoized,
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'source' => 'gps-memo',
                ];
            }
        }

        $matcher ??= new DistrictZoneMatcher;
        $fallback = $this->defaultForState($stateCode);

        foreach ($districtCandidates as $candidate) {
            $zone = $matcher->matchZone(is_string($candidate) ? $candidate : null, $stateCode);

            if ($zone !== null) {
                $zoneCoords = $this->coordsForZone($zone);

                return [
                    'zone' => $zone,
                    'lat' => $latitude ?? $zoneCoords['lat'] ?? $fallback['lat'],
                    'lng' => $longitude ?? $zoneCoords['lng'] ?? $fallback['lng'],
                    'source' => 'district-match',
                ];
            }
        }

        return [
            'zone' => $fallback['zone'],
            // Input coords stay authoritative for coordinate providers even
            // when the zone itself fell back to a default.
            'lat' => $latitude ?? $fallback['lat'],
            'lng' => $longitude ?? $fallback['lng'],
            'source' => $fallback['source'],
        ];
    }

    public function queueGpsResolution(float $latitude, float $longitude): void
    {
        if ($this->memoizedZone($latitude, $longitude) !== null) {
            return;
        }

        try {
            Bus::dispatchAfterResponse(new ResolveGpsZone($latitude, $longitude));
        } catch (Throwable $exception) {
            Log::debug('Prayer GPS-zone dispatch failed; the lookup reruns next time.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function memoizedZone(float $latitude, float $longitude): ?string
    {
        try {
            $zone = $this->cache()->get($this->memoKey($latitude, $longitude));
        } catch (Throwable) {
            // A down cache store degrades to state/country defaults.
            return null;
        }

        if (! is_string($zone) || $zone === '') {
            return null;
        }

        // Memos outlive snapshot rows: a zone dropped from the snapshot
        // must not pin GPS lookups forever. Forgetting the obsolete
        // entry reads as a miss, so district/state fallback serves
        // while GPS refresh remaps the coordinates.
        if (! $this->isKnownZone($zone)) {
            $this->forgetMemo($latitude, $longitude);

            return null;
        }

        return $zone;
    }

    public function rememberZone(float $latitude, float $longitude, string $zone): void
    {
        // No TTL: zones don't move. Invalidated on unknown-zone 404.
        try {
            $this->cache()->forever($this->memoKey($latitude, $longitude), strtoupper($zone));
        } catch (Throwable) {
            // Best-effort memo; the GPS lookup simply reruns next time.
        }
    }

    public function forgetMemo(float $latitude, float $longitude): void
    {
        try {
            $this->cache()->forget($this->memoKey($latitude, $longitude));
        } catch (Throwable) {
            // Best-effort invalidation.
        }
    }

    public function isKnownZone(string $zone): bool
    {
        return array_key_exists(strtoupper($zone), config('prayer_zones.zones', []));
    }

    /**
     * @return list<string>
     */
    public function zoneCodes(): array
    {
        return array_values(array_map(
            static fn (mixed $code): string => strtoupper((string) $code),
            array_keys($this->zones()),
        ));
    }

    /**
     * Representative coordinates for a zone: the per-zone main town from
     * `zone_coords`, falling back to state-capital coords for capital-area
     * zones without a row.
     *
     * Lets coordinate providers (Ummah) fill a zone-month cache key when the
     * mirror is down. WLY02 resolves to Labuan, not KL.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function coordsForZone(string $zone): ?array
    {
        $zone = strtoupper(trim($zone));
        $zones = config('prayer_zones.zones', []);
        $entry = is_array($zones) ? ($zones[$zone] ?? null) : null;

        if (! is_array($entry)) {
            return null;
        }

        $overrides = config('prayer_zones.zone_coords', []);
        $override = is_array($overrides) ? ($overrides[$zone] ?? null) : null;

        if (is_array($override) && isset($override['lat'], $override['lng'])) {
            return ['lat' => (float) $override['lat'], 'lng' => (float) $override['lng']];
        }

        if ($zone === 'WLY02') {
            $default = config('prayer_zones.state_defaults.15');
        } else {
            $keys = config('prayer_zones.zone_state_keys', []);
            $stateKey = is_array($keys) ? ($keys[$entry['state'] ?? ''] ?? null) : null;
            $default = is_string($stateKey) ? config("prayer_zones.state_defaults.{$stateKey}") : null;
        }

        if (! is_array($default) || ! isset($default['lat'], $default['lng'])) {
            return null;
        }

        return ['lat' => (float) $default['lat'], 'lng' => (float) $default['lng']];
    }

    /**
     * @return array<string, array{state: string, districts: string}>
     */
    public function zones(): array
    {
        return config('prayer_zones.zones', []);
    }

    /**
     * @return array{zone: string, lat: float, lng: float, source: string}
     */
    private function defaultForState(?string $stateCode): array
    {
        $stateCode = $stateCode !== null ? strtoupper(trim($stateCode)) : null;
        $defaults = config('prayer_zones.state_defaults', []);

        if ($stateCode !== null && isset($defaults[$stateCode])) {
            return [
                'zone' => $defaults[$stateCode]['zone'],
                'lat' => (float) $defaults[$stateCode]['lat'],
                'lng' => (float) $defaults[$stateCode]['lng'],
                'source' => "state-default:{$stateCode}",
            ];
        }

        $country = config('prayer_zones.country_default', ['zone' => 'WLY01', 'lat' => 3.1390, 'lng' => 101.6869]);

        return [
            'zone' => $country['zone'],
            'lat' => (float) $country['lat'],
            'lng' => (float) $country['lng'],
            'source' => 'country-default',
        ];
    }

    private function memoKey(float $latitude, float $longitude): string
    {
        $prefix = (string) config('prayer.cache.prefix', 'prayer:v3');

        // Exact-value memo at address-column precision (decimal 10,7); the
        // live lookup itself always runs on full-precision inputs.
        return sprintf('%s:gps-memo:%.7F:%.7F', $prefix, $latitude, $longitude);
    }

    private function cache(): Repository
    {
        /** @var string|null $store */
        $store = config('prayer.cache.store');

        return Cache::store($store);
    }
}
