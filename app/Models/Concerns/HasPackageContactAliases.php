<?php

namespace App\Models\Concerns;

use AIArmada\Contacting\Models\ContactMethod;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPackageContactAliases
{
    /**
     * @return MorphMany<ContactMethod, $this>
     */
    public function contacts(): MorphMany
    {
        return $this->contactMethods()
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    public function getEmailAttribute(): ?string
    {
        return $this->resolveEmail();
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->resolvePhone();
    }
}
