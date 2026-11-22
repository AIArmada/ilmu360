<?php

namespace App\Features\Institutions\Models;

use App\Features\Institutions\Api\Enums\InstitutionVenueRole;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * Read model for an institution↔venue bridge row.
 *
 * Institutions owns bridge mutations. Direct pivot model saves are rejected;
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
        throw new LogicException('Institution venue links must be persisted through the Institutions InstitutionVenueLinks API.');
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(MorphModelResolver::resolve('venue'));
    }
}
