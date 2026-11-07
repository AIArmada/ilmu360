<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

interface PrayerProviderRegistry
{
    /**
     * Zone resolver for a country. Unmapped countries are coordinate-only
     * and silently receive the null adapter — most countries need no zone
     * machinery, so unlike provider mappings this emits no warning.
     */
    public function zoneResolverFor(string $countryCode): PrayerZoneResolver;

    /**
     * Resolved calculation settings for coordinate providers. Both submit
     * and preview build their queries through here so daily cache cells
     * share one identity per country.
     *
     * @return array{ummah: string|null, madhab: string|null}
     */
    public function methodsFor(string $countryCode): array;

    /**
     * Display timezone for a country (preview endpoint, prefetch month
     * boundaries, job payload defaults). Unmapped countries use UTC.
     */
    public function timezoneFor(string $countryCode): string;
}
