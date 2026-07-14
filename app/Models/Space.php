<?php

namespace App\Models;

use AIArmada\Events\Models\VenueSpace;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Space extends VenueSpace implements AuditableContract
{
    use AuditsModelChanges;

    /**
     * @param  callable(array<string, mixed>, static|null): array<string, mixed>|array<string, mixed>|int|null  $count
     * @param  callable(array<string, mixed>, static|null): array<string, mixed>|array<string, mixed>  $state
     */
    public static function factory($count = null, $state = []): SpaceFactory
    {
        return SpaceFactory::new()
            ->count(is_numeric($count) ? $count : null)
            ->state(is_callable($count) || is_array($count) ? $count : $state);
    }

    /**
     * @return BelongsToMany<Institution, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
     */
    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_space')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
