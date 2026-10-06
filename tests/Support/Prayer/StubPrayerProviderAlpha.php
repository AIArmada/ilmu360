<?php

declare(strict_types=1);

namespace Tests\Support\Prayer;

use App\Contracts\PrayerTimesProvider;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Services\Prayer\ProviderUnavailable;

class StubPrayerProviderAlpha implements PrayerTimesProvider
{
    public function key(): string
    {
        return 'alpha';
    }

    public function supports(string $countryCode): bool
    {
        return true;
    }

    public function requiresZone(): bool
    {
        return false;
    }

    public function cacheIdentitySegment(PrayerQuery $query): string
    {
        return 'alpha';
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
        throw new ProviderUnavailable('stub');
    }
}
