<?php

namespace App\Models\Concerns;

use AIArmada\Contacting\Models\SocialProfile;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPackageSocialAliases
{
    /**
     * @return MorphMany<SocialProfile, $this>
     */
    public function socialMedia(): MorphMany
    {
        return $this->socialProfiles()
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }
}
