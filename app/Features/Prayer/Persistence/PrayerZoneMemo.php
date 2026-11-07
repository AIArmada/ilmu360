<?php

declare(strict_types=1);

namespace App\Features\Prayer\Persistence;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PrayerZoneMemo
{
    public function get(float $latitude, float $longitude): ?string
    {
        try {
            $zone = $this->cache()->get($this->memoKey($latitude, $longitude));
        } catch (Throwable) {
            // A down cache store degrades to state/country defaults.
            return null;
        }

        return is_string($zone) && $zone !== '' ? $zone : null;
    }

    public function remember(float $latitude, float $longitude, string $zone): void
    {
        // No TTL: zones don't move. Invalidated on unknown-zone 404.
        try {
            $this->cache()->forever($this->memoKey($latitude, $longitude), strtoupper($zone));
        } catch (Throwable) {
            // Best-effort memo; the GPS lookup simply reruns next time.
        }
    }

    public function forget(float $latitude, float $longitude): void
    {
        try {
            $this->cache()->forget($this->memoKey($latitude, $longitude));
        } catch (Throwable) {
            // Best-effort invalidation.
        }
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
