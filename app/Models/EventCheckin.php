<?php

namespace App\Models;

use AIArmada\Events\Models\EventAttendance;
use Database\Factories\EventCheckinFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCheckin extends EventAttendance
{
    protected $fillable = [
        'event_id',
        'event_occurrence_id',
        'event_session_id',
        'event_registration_id',
        'event_registration_participant_id',
        'pass_id',
        'attendee_type', 'attendee_id',
        'verified_by_user_id',
        'check_in_source',
        'checked_in_at',
        'attendance_type',
        'notes',
        'metadata',
    ];

    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'checked_in_at' => 'immutable_datetime',
        ]);
    }

    #[\Override]
    public function registration(): BelongsTo
    {
        /** @phpstan-ignore-next-line childReturnType (covariant override) */
        return $this->belongsTo(Registration::class, 'event_registration_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    #[\Override]
    protected static function newFactory(): EventCheckinFactory
    {
        return EventCheckinFactory::new();
    }
}
