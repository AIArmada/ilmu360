<?php

namespace App\Features\Institutions\Models;

use App\Shared\Directory\Enums\InstitutionVenueRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;
use App\Features\Places\Models\Venue;

/**
 * Read model for an institution↔venue bridge row.
 *
 * Directory owns bridge mutations. Direct pivot model saves are rejected;
 * read relations remain available to callers.
 */
class InstitutionVenue extends Pivot
{
    use HasUuids;

    #[\Override]
    protected $table = 'institution_venue';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'institution_id',
        'venue_id',
        'role',
        'is_primary',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'role' => InstitutionVenueRole::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[\Override]
    public function save(array $options = []): bool
    {
        throw new LogicException('Institution venue links must be persisted through the Directory InstitutionVenueLinks API.');
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
