<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\DonationChannels;

use Illuminate\Database\Eloquent\Model;

/**
 * Delete a donation channel through the owning feature.
 *
 * Deletion goes through the model so media cleanup and audits run.
 */
final readonly class DeleteDonationChannelAction
{
    public function handle(Model $donationChannel): void
    {
        $donationChannel->delete();
    }
}
