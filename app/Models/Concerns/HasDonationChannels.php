<?php

namespace App\Models\Concerns;

use App\Models\DonationChannel;
use App\Shared\Support\RelatedModels;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasDonationChannels
{
    /**
     * @return MorphMany<DonationChannel, $this>
     */
    public function donationChannels(): MorphMany
    {
        return $this->morphMany(RelatedModels::donationChannel(), 'donatable')
            ->orderByDesc('is_default')
            ->orderBy('created_at');
    }
}
