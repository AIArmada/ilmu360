<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

interface PrayerLocationResolver
{
    public function forTargets(?string $venueId, ?string $institutionId, ?string $countryFallback = null): PrayerLocationData;
}
