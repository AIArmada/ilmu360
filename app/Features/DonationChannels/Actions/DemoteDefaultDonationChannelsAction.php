<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Features\DonationChannels\Models\DonationChannel;
use App\Platform\Models\MorphModelResolver;

/**
 * Demote every other default channel for the same owner.
 */
final readonly class DemoteDefaultDonationChannelsAction
{
    public function handle(DonationChannel $channel): void
    {
        MorphModelResolver::assert($channel, 'donation_channel');

        if (! $channel->getAttribute('is_default')) {
            return;
        }

        $donatableType = $channel->getAttribute('donatable_type');
        $donatableId = $channel->getAttribute('donatable_id');

        if (blank($donatableType) || blank($donatableId)) {
            return;
        }

        DonationChannel::query()
            ->where('donatable_type', $donatableType)
            ->where('donatable_id', $donatableId)
            ->whereKeyNot($channel->getKey())
            ->where('is_default', true)
            ->update([
                'is_default' => false,
                'updated_at' => now(),
            ]);
    }
}
