<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Models\DonationChannel;

/**
 * Delete a donation channel through the owning feature.
 *
 * Deletion goes through the model so media cleanup and audits run.
 */
final readonly class DeleteDonationChannelAction
{
    public function handle(DonationChannel $donationChannel): void
    {
        $donationChannel->delete();
    }
}
