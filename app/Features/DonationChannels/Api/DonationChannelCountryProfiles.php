<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

use App\Features\DonationChannels\Api\Data\DonationChannelCountryProfile;

/**
 * Country-driven donation method and currency policy.
 */
interface DonationChannelCountryProfiles
{
    public function forCountryId(?string $countryId): DonationChannelCountryProfile;

    public function forCountryCode(?string $countryCode): DonationChannelCountryProfile;

    /**
     * Resolve the profile from the owner's canonical address country, falling
     * back to registered `donation-channel.owner-country` extensions for
     * owners that cannot expose the primary-address relation themselves.
     */
    public function forOwner(mixed $owner): DonationChannelCountryProfile;

    /**
     * @return array<string, string>
     */
    public function currencyOptions(): array;
}
