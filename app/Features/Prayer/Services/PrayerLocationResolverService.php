<?php

declare(strict_types=1);

namespace App\Features\Prayer\Services;

use AIArmada\Addressing\Models\Address;
use App\Features\Prayer\Api\PrayerLocationResolver;
use App\Features\Prayer\Support\PrayerLocation;

/**
 * Boundary adapter over the static prayer-location extraction helpers.
 */
final readonly class PrayerLocationResolverService implements PrayerLocationResolver
{
    public function forTargets(?string $venueId, ?string $institutionId): ?Address
    {
        return PrayerLocation::forTargets($venueId, $institutionId);
    }

    public function fromAddress(?Address $address, ?string $countryFallback = null): array
    {
        return PrayerLocation::fromAddress($address, $countryFallback);
    }
}
