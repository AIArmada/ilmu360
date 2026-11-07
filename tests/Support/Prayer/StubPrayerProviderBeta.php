<?php

declare(strict_types=1);

namespace Tests\Support\Prayer;

use App\Features\Prayer\Api\PrayerQuery;
use App\Features\Prayer\Api\PrayerTimesDTO;
use App\Features\Prayer\Api\PrayerTimesProvider;
use App\Shared\Integrations\ProviderUnavailable;

class StubPrayerProviderBeta implements PrayerTimesProvider
{
    public function key(): string
    {
        return 'beta';
    }

    public function supports(string $countryCode): bool
    {
        return $countryCode !== 'MY';
    }

    public function requiresZone(): bool
    {
        return false;
    }

    public function cacheIdentitySegment(PrayerQuery $query): string
    {
        return 'beta';
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
