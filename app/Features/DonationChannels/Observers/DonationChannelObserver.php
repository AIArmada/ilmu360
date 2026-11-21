<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Observers;

use App\Features\DonationChannels\Actions\DemoteDefaultDonationChannelsAction;
use App\Features\DonationChannels\Models\DonationChannel;

final readonly class DonationChannelObserver
{
    public function __construct(private DemoteDefaultDonationChannelsAction $demoteDefault) {}

    public function saved(DonationChannel $channel): void
    {
        $this->demoteDefault->handle($channel);
    }
}
