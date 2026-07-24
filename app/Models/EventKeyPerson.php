<?php

namespace App\Models;

use AIArmada\Events\Models\EventInvolvement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventKeyPerson extends EventInvolvement
{
    /**
     * @var list<string>
     */
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
        return $this->belongsTo(Person::class, 'involveable_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function speaker(): BelongsTo
    {
        return $this->person();
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
