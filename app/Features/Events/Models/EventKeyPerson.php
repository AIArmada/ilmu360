<?php

namespace App\Features\Events\Models;

use AIArmada\Events\Features\Catalog\Models\EventInvolvement;
use App\Features\Persons\Models\Person;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @extends EventInvolvement<Event> */
class EventKeyPerson extends EventInvolvement
{
    #[\Override]
    protected $table = 'event_involvements';

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'event_id',
        'event_occurrence_id',
        'event_session_id',
        'involveable_type',
        'involveable_id',
        'event_role_id',
        'role_code',
        'status',
        'visibility',
        'prominence',
        'is_featured',
        'is_primary',
        'starts_at',
        'ends_at',
        'replaced_by_involvement_id',
        'replacement_reason',
        'notes',
        'sort_order',
        'display_name',
        'metadata',
    ];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(MorphModelResolver::resolve('person'), 'involveable_id');
    }

    public function getResolvedNameAttribute(): string
    {
        $person = $this->getRelationValue('person');

        if ($person instanceof Person) {
            return (string) $person->formatted_name;
        }

        return $this->getAttribute('display_name') ?? 'Key Person';
    }
}
