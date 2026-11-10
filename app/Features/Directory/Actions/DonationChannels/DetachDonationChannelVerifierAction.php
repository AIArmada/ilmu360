<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\DonationChannels;

use App\Features\Directory\Support\DirectoryModel;

final readonly class DetachDonationChannelVerifierAction
{
    public function handle(string $accountId): void
    {
        DirectoryModel::query('donation_channel')
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
