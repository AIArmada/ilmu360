<?php

declare(strict_types=1);

namespace App\Features\Organizations\Extensions;

use App\Features\DonationChannels\Api\DonationChannelOwnerCountry;
use App\Features\Organizations\Services\OrganizationProfileService;
use App\Platform\Models\MorphModelResolver;

/**
 * Resolves the donation country for organization owners from the canonical
 * organization primary address; organizations without an address stay
 * country-less instead of defaulting.
 */
final readonly class OrganizationDonationOwnerCountry implements DonationChannelOwnerCountry
{
    public function __construct(private OrganizationProfileService $profiles) {}

    public function supports(string $ownerType): bool
    {
        $modelClass = MorphModelResolver::tryResolve('organization');

        return $ownerType === 'organization' || ($modelClass !== null && $ownerType === $modelClass);
    }

    public function countryIdFor(string $ownerType, string $ownerId): ?string
    {
        return $this->profiles->countryIdFor($ownerId);
    }
}
