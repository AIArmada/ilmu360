<?php

namespace App\Features\DonationChannels\Concerns;

use App\Features\DonationChannels\Models\DonationChannel;
use App\Platform\Models\Support\RelatedModels;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasDonationChannels
{
    /**
     * @return MorphMany<DonationChannel, $this>
     */
    public function donationChannels(): MorphMany
    {
        return $this->morphMany(RelatedModels::donationChannel(DonationChannel::class), 'donatable')
            ->orderByDesc('is_default')
            ->orderBy('created_at');
    }
}
