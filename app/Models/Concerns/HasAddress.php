<?php

namespace App\Models\Concerns;

use App\Models\Address;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasAddress
{
    /**
     * @return MorphOne<Address, $this>
     */
    public function address(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable');
    }

    /**
     * Get the address line 1 (alias).
     */
    public function getAddressLine1Attribute(): string
    {
        $address = $this->addressModel;

        if (! $address instanceof Address || ! filled($address->line1)) {
            return '';
        }

        return (string) $address->line1;
    }

    /**
     * Get the address relationship (helper to avoid conflict with legacy accessors if any, strict typing).
     */
    public function getAddressModelAttribute(): ?Address
    {
        $loadedAddress = $this->relationLoaded('address') ? $this->getRelation('address') : null;

        if ($loadedAddress instanceof Address) {
            return $loadedAddress;
        }

        $address = $this->address()->first();

        return $address instanceof Address ? $address : null;
    }
}
