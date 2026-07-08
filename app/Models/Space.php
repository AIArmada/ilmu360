<?php

namespace App\Models;

use AIArmada\Events\Models\VenueSpace;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Space extends VenueSpace implements AuditableContract
{
    use AuditsModelChanges;

    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_space')
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
