<?php

namespace App\Models\Concerns;

use AIArmada\Addressing\Models\Address;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasPrimaryAddressAccessors
{
    /**
     * Alias the package `addresses()` relation as the current app-owned primary address surface.
     *
     * @return MorphToMany<Address, $this>
     */
    public function address(): MorphToMany
    {
        return $this->addresses()
            ->wherePivot('type', 'primary')
            ->wherePivot('is_primary', true)
            ->withPivotValue('type', 'primary')
            ->withPivotValue('is_primary', true);
    }

    public function getAddressModelAttribute(): ?Address
    {
        $loadedAddress = $this->relationLoaded('address')
            ? $this->getRelation('address')
            : null;

        if ($loadedAddress instanceof Address) {
            return $loadedAddress;
        }

        if ($loadedAddress instanceof EloquentCollection) {
            $address = $loadedAddress->first();

            if ($address instanceof Address) {
                return $address;
            }
        }

        $loadedAddresses = $this->relationLoaded('addresses')
            ? $this->getRelation('addresses')
            : null;

        if ($loadedAddresses instanceof EloquentCollection) {
            $address = $loadedAddresses->first();

            if ($address instanceof Address) {
                return $address;
            }
        }

        return $this->primaryAddress();
    }

    public function getAddressAttribute(): ?Address
    {
        return $this->addressModel;
    }

    public function getAddressLine1Attribute(): string
    {
        $line = $this->addressModel?->line1;

        return is_string($line) ? $line : '';
    }
}
