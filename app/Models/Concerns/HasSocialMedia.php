<?php

namespace App\Models\Concerns;

use App\Models\SocialMedia;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasSocialMedia
{
    /**
     * @return MorphMany<SocialMedia, $this>
     */
    public function socialMedia(): MorphMany
    {
        return $this->morphMany(SocialMedia::class, 'socialable')
            ->orderBy('order_column')
            ->orderBy('created_at');
    }
}
