<?php

namespace App\Models;

use AIArmada\Events\Models\EventInvolvement;
use App\Enums\EventKeyPersonRole;
use App\Models\Concerns\HasEventInvolvementRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventKeyPerson extends EventInvolvement
{
    use HasEventInvolvementRole;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_occurrence_id',
        'event_session_id',
        'involveable_type',
        'involveable_id',
        'speaker_id',
        'event_role_id',
        'role_code',
        'role',
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
        'order_column',
        'name',
        'metadata',
    ];

    public function setSpeakerIdAttribute(mixed $value): void
    {
        $this->attributes['involveable_type'] = 'speaker';
        $this->attributes['involveable_id'] = $value !== null ? (string) $value : null;
    }

    public function getSpeakerIdAttribute(): ?string
    {
        if (($this->attributes['involveable_type'] ?? null) !== 'speaker') {
            return null;
        }

        $id = $this->attributes['involveable_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function setRoleAttribute(mixed $value): void
    {
        $role = $value instanceof EventKeyPersonRole ? $value->value : (is_scalar($value) ? (string) $value : null);
        $this->attributes['role_code'] = $role;
    }

    public function getRoleAttribute(): EventKeyPersonRole|string|null
    {
        $roleCode = $this->attributes['role_code'] ?? null;

        if (! is_string($roleCode) || $roleCode === '') {
            return null;
        }

        return EventKeyPersonRole::tryFrom($roleCode) ?? $roleCode;
    }

    public function setOrderColumnAttribute(mixed $value): void
    {
        $this->attributes['sort_order'] = $value !== null ? (int) $value : null;
    }

    public function getOrderColumnAttribute(): ?int
    {
        $value = $this->attributes['sort_order'] ?? null;

        return $value !== null ? (int) $value : null;
    }

    public function setIsPublicAttribute(mixed $value): void
    {
        $this->attributes['visibility'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'public' : 'private';
    }

    public function getIsPublicAttribute(): bool
    {
        return ($this->attributes['visibility'] ?? 'public') === 'public';
    }

    public function setNameAttribute(mixed $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['name'] = is_scalar($value) ? (string) $value : null;
        $this->metadata = $metadata;
    }

    public function getNameAttribute(): ?string
    {
        $metadata = $this->metadata ?? [];

        return isset($metadata['name']) && is_string($metadata['name']) ? $metadata['name'] : null;
    }

    /**
     * @return BelongsTo<Speaker, $this>
     */
    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class, 'involveable_id');
    }

}
