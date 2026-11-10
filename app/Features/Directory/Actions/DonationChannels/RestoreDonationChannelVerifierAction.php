<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\DonationChannels;

use App\Features\Directory\Support\DirectoryModel;

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

        DirectoryModel::query('donation_channel')
            ->whereKey($channelIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
