<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

/**
 * Country resolution for donation owners that cannot expose the canonical
 * primary address on their own model (for example organizations).
 *
 * Implementations are registered through the `donation-channel.owner-country`
 * extension slot and receive the owner morph type and key, never a model.
 */
interface DonationChannelOwnerCountry
{
    public function supports(string $ownerType): bool;

    public function countryIdFor(string $ownerType, string $ownerId): ?string;
}
