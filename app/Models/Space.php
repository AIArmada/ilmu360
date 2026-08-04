<?php

namespace App\Models;

use AIArmada\Events\Models\VenueSpace;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string|null $slug
 * @property string $name
 * @property int|null $capacity
 * @property string|null $status
 * @property string|null $visibility
 * @property string|null $venue_id
 * @property string|null $space_type
 */
class Space extends VenueSpace implements AuditableContract
{
    use AuditsModelChanges;

    protected static function booted(): void
    {
        static::deleting(function (self $space): void {
            if ($space->eventLocations()->exists()) {
                throw ValidationException::withMessages([
                    'space' => __('Spaces referenced by event locations cannot be deleted.'),
                ]);
            }
        });
    }

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
            ->withPivot('capacity')
            ->withTimestamps();
    }

    public function effectiveCapacity(): ?int
    {
        $pivot = $this->getRelationValue('pivot');
        $override = $pivot instanceof Pivot ? $pivot->getAttribute('capacity') : null;

        return $override !== null ? (int) $override : ($this->capacity !== null ? (int) $this->capacity : null);
    }
}
