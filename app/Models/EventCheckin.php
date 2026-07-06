<?php

namespace App\Models;

use AIArmada\Events\Models\EventAttendance;
use Database\Factories\EventCheckinFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCheckin extends EventAttendance
{
    protected $fillable = [
        'event_id',
        'event_registration_id', 'registration_id',
        'attendee_type', 'attendee_id',
        'user_id',
        'verified_by_user_id',
        'check_in_source', 'method',
        'checked_in_at',
        'attendance_type',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'checked_in_at' => 'datetime',
        ]);
    }

    public function getRegistrationIdAttribute(): ?string
    {
        return $this->event_registration_id;
    }

    public function setRegistrationIdAttribute(?string $value): void
    {
        $this->event_registration_id = $value;
    }

    public function getUserIdAttribute(): ?string
    {
        return $this->attendee_id;
    }

    public function setUserIdAttribute(?string $value): void
    {
        $this->attendee_type = 'App\Models\User';
        $this->attendee_id = $value;
    }

    public function getMethodAttribute(): ?string
    {
        return $this->check_in_source;
    }

    public function setMethodAttribute(?string $value): void
    {
        $this->check_in_source = $value;
    }

    public function getLatAttribute(): ?float
    {
        return $this->metadata['lat'] ?? null;
    }

    public function setLatAttribute(?float $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['lat'] = $value;
        $this->metadata = $metadata;
    }

    public function getLngAttribute(): ?float
    {
        return $this->metadata['lng'] ?? null;
    }

    public function setLngAttribute(?float $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['lng'] = $value;
        $this->metadata = $metadata;
    }

    public function getAccuracyMAttribute(): ?float
    {
        return $this->metadata['accuracy_m'] ?? null;
    }

    public function setAccuracyMAttribute(?float $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['accuracy_m'] = $value;
        $this->metadata = $metadata;
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'event_registration_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendee_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    protected static function newFactory(): EventCheckinFactory
    {
        return EventCheckinFactory::new();
    }
}
