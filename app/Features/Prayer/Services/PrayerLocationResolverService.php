<?php

declare(strict_types=1);

namespace App\Features\Prayer\Services;

use App\Features\Prayer\Api\PrayerLocationData;
use App\Features\Prayer\Api\PrayerLocationResolver;
use App\Features\Prayer\Support\PrayerLocation;

final readonly class PrayerLocationResolverService implements PrayerLocationResolver
{
    public function forTargets(?string $venueId, ?string $institutionId, ?string $countryFallback = null): PrayerLocationData
    {
        $inputs = PrayerLocation::fromAddress(
            PrayerLocation::forTargets($venueId, $institutionId),
            $countryFallback,
        );

        return new PrayerLocationData(
            countryCode: $inputs['countryCode'],
            latitude: $inputs['latitude'],
            longitude: $inputs['longitude'],
            stateCode: $inputs['stateCode'],
            districtCandidates: $inputs['districtCandidates'],
        );
    }
}
