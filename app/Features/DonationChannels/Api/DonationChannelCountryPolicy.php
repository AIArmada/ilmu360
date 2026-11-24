<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

/**
 * Country policy guard for an owner's existing donation channel set.
 *
 * Callers about to change an owner's canonical country assert that the stored
 * channels remain valid for the new country before the change is written.
 */
interface DonationChannelCountryPolicy
{
    /**
     * @throws \Illuminate\Validation\ValidationException when a stored channel is not available in the new country
     */
    public function assertOwnerChannelsAllowedForCountryId(string $ownerType, string $ownerId, ?string $countryId): void;
}
