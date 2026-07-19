<?php

namespace App\Models;

use AIArmada\Events\Models\VenueSpace;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string|null $slug
 * @property string $name
 * @property int|null $capacity
 * @property string|null $status
 * @property string|null $visibility
 */
class Space extends VenueSpace implements AuditableContract
{
    use AuditsModelChanges;

    /**
     * @param  callable(array<string, mixed>, static|null): array<string, mixed>|array<string, mixed>|int|null  $count
     * @param  callable(array<string, mixed>, static|null): array<string, mixed>|array<string, mixed>  $state
     */
    #[\Override]
    public static function factory($count = null, $state = []): SpaceFactory
    {
        return SpaceFactory::new()
            ->count(is_numeric($count) ? $count : null)
            ->state(is_callable($count) || is_array($count) ? $count : $state);
    }

    /**
     * @return BelongsToMany<Institution, $this, Pivot, 'pivot'>
     */
    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_space')
            ->withTimestamps();
    }
}
