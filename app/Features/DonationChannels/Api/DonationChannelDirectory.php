<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

use App\Features\DonationChannels\Api\Data\DonationChannelSummary;

/**
 * Read boundary for donation channels owned by a morph owner.
 *
 * Owner workspaces and donor-facing surfaces compose their payloads from
 * these scalar summaries and apply their own visibility policy.
 */
interface DonationChannelDirectory
{
    /**
     * @return list<DonationChannelSummary>
     */
    public function forOwner(string $ownerType, string $ownerId): array;

    public function findForOwner(string $ownerType, string $ownerId, string $channelId): ?DonationChannelSummary;
}
