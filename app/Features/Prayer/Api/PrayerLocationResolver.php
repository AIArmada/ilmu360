<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

use AIArmada\Addressing\Models\Address;

/**
 * Prayer-resolution location inputs derived from an event target.
 *
 * Callers own the event/submission flow; this boundary only answers which
 * canonical address applies and which country/coordinate/area inputs prayer
 * resolution reads from it.
 */
interface PrayerLocationResolver
{
    /**
     * Pick the target's canonical address: institution first, then venue, then
     * the venue's owning institution.
     */
    public function forTargets(?string $venueId, ?string $institutionId): ?Address;

    /**
     * @return array{countryCode: string|null, latitude: float|null, longitude: float|null, stateCode: string|null, districtCandidates: list<string>}
     */
    public function fromAddress(?Address $address, ?string $countryFallback = null): array;
}
