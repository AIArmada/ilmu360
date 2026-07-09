<?php

namespace App\Models;

use AIArmada\Moderation\Models\ModerationAction;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * App wrapper around package moderation actions with legacy-friendly accessors
 * used by moderation transitions and account-deletion cleanup.
 */
class ModerationReview extends ModerationAction implements Auditable
{
    use AuditsModelChanges;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'actionable_type',
        'actionable_id',
        'actioned_by_type',
        'actioned_by_id',
        'type',
        'reason',
        'notes',
        'metadata',
        // Legacy aliases accepted on write and mapped in mutators below.
        'event_id',
        'moderator_id',
        'decision',
        'reason_code',
    ];

    public function getEventIdAttribute(): ?string
    {
        if (($this->attributes['actionable_type'] ?? null) !== Event::class
            && ($this->attributes['actionable_type'] ?? null) !== (new Event)->getMorphClass()) {
            return null;
        }

        $id = $this->attributes['actionable_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function setEventIdAttribute(mixed $value): void
    {
        $this->attributes['actionable_type'] = (new Event)->getMorphClass();
        $this->attributes['actionable_id'] = $value !== null ? (string) $value : null;
    }

    public function getModeratorIdAttribute(): ?string
    {
        if (($this->attributes['actioned_by_type'] ?? null) !== User::class
            && ($this->attributes['actioned_by_type'] ?? null) !== (new User)->getMorphClass()) {
            return null;
        }

        $id = $this->attributes['actioned_by_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function setModeratorIdAttribute(mixed $value): void
    {
        $this->attributes['actioned_by_type'] = (new User)->getMorphClass();
        $this->attributes['actioned_by_id'] = $value !== null ? (string) $value : null;
    }

    public function getDecisionAttribute(): ?string
    {
        $type = $this->attributes['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        return match ($type) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'changes_requested' => 'needs_changes',
            default => $type,
        };
    }

    public function setDecisionAttribute(mixed $value): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $this->attributes['type'] = match ($value) {
            'approved' => 'approve',
            'rejected' => 'reject',
            'needs_changes' => 'changes_requested',
            default => $value,
        };
    }

    public function getReasonCodeAttribute(): ?string
    {
        $reason = $this->attributes['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function setReasonCodeAttribute(mixed $value): void
    {
        $this->attributes['reason'] = is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereEventId(Builder $query, string $eventId): Builder
    {
        return $query
            ->where('actionable_id', $eventId)
            ->where(function (Builder $typeQuery): void {
                $typeQuery
                    ->where('actionable_type', Event::class)
                    ->orWhere('actionable_type', (new Event)->getMorphClass());
            });
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'actionable_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_id');
    }
}
