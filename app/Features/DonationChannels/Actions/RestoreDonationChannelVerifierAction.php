<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Features\DonationChannels\Models\DonationChannel;

final readonly class RestoreDonationChannelVerifierAction
{
    /**
     * @param  list<string>  $channelIds
     */
    public function handle(string $accountId, array $channelIds): void
    {
        if ($channelIds === []) {
            return;
        }

        DonationChannel::query()
            ->whereKey($channelIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
