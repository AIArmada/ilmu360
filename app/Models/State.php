<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Nnjeim\World\Models\State as WorldState;

class State extends WorldState
{
    /**
     * @return HasMany<District, $this>
     */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    /**
     * @return HasMany<Subdistrict, $this>
     */
    public function subdistricts(): HasMany
    {
        return $this->hasMany(Subdistrict::class);
    }

    /**
     * Get all addresses in this state.
     *
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
