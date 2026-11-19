<?php

namespace App\Features\DonationChannels\Concerns;

use App\Features\DonationChannels\Models\DonationChannel;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasDonationChannels
{
    /**
     * @return MorphMany<DonationChannel, $this>
     */
    public function donationChannels(): MorphMany
    {
        return $this->morphMany(MorphModelResolver::resolve('donation_channel'), 'donatable')
            ->orderByDesc('is_default')
            ->orderBy('created_at');
    }
}
