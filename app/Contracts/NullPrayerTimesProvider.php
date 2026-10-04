<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Services\Prayer\ProviderUnavailable;

/**
 * Null-object provider for tests and disabled-provider environments.
 *
 * Never supports a country, so the registry skips it; direct calls fail
 * loudly instead of returning fabricated times.
 */
final class NullPrayerTimesProvider implements PrayerTimesProvider
{
    public function key(): string
    {
        return 'null';
    }

    public function supports(string $countryCode): bool
    {
        return false;
    }

    public function requiresZone(): bool
    {
        return false;
    }

    public function cacheIdentitySegment(PrayerQuery $query): string
    {
        return 'null';
    }

    public function isSourceCurrent(string $source, string $countryCode): bool
    {
        return true;
    }

    public function calcFingerprint(PrayerQuery $query): ?string
    {
        return null;
    }

    public function dailyPrayers(PrayerQuery $query): PrayerTimesDTO
    {
        throw new ProviderUnavailable('Null prayer-times provider cannot resolve times.');
    }
}
