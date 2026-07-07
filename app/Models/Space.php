<?php

namespace App\Models;

use AIArmada\Events\Models\VenueSpace;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Space extends VenueSpace implements AuditableContract
{
    /** @use HasFactory<SpaceFactory> */
    use AuditsModelChanges, HasFactory;

    protected $fillable = [
        // Package VenueSpace columns
        'venue_id',
        'name', 'slug', 'code', 'space_type',
        'level', 'unit_no', 'block', 'wing',
        'capacity',
        'latitude', 'longitude',
        'google_maps_url', 'waze_url', 'map_url', 'directions',
        'status', 'visibility',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), []);
    }

    /**
     * @return BelongsToMany<Institution, $this>
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

    /**
     * @param  Builder<self>  $query
     */
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }

    protected static function newFactory(): SpaceFactory
    {
        return SpaceFactory::new();
    }
}
