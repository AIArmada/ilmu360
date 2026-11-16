<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Models\DonationChannel;

final readonly class DetachDonationChannelVerifierAction
{
    public function handle(string $accountId): void
    {
        DonationChannel::query()
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
