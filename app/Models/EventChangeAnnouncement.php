<?php

namespace App\Models;

use AIArmada\Events\Models\EventUpdate;
use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeStatus;
use App\Enums\EventChangeType;
use Database\Factories\EventChangeAnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventChangeAnnouncement extends EventUpdate
{
    protected $fillable = [
        'id',
        'event_id',
        'replacement_event_id',
        'actor_id',
        'update_type', 'type',
        'status',
        'severity',
        'public_message', 'message',
        'title',
        'internal_note', 'notes',
        'changed_fields',
        'before_snapshot',
        'after_snapshot',
        'published_at',
        'retracted_at', 'archived_at',
        'created_by_type', 'created_by_id',
        'visibility',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => EventChangeType::class,
            'status' => EventChangeStatus::class,
            'severity' => EventChangeSeverity::class,
            'changed_fields' => 'array',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'published_at' => 'immutable_datetime',
            'retracted_at' => 'immutable_datetime',
        ]);
    }

    public function getTypeAttribute(): ?EventChangeType
    {
        $value = $this->getAttributeFromArray('update_type');

        return is_string($value) ? EventChangeType::tryFrom($value) : null;
    }

    public function setTypeAttribute(EventChangeType|string|null $value): void
    {
        $this->attributes['update_type'] = $value instanceof EventChangeType ? $value->value : $value;
    }

    public function getStatusAttribute(): ?EventChangeStatus
    {
        $metadata = $this->metadata ?? [];

        $value = is_array($metadata) ? ($metadata['status'] ?? null) : null;

        return is_string($value) ? EventChangeStatus::tryFrom($value) : null;
    }

    public function setStatusAttribute(EventChangeStatus|string|null $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['status'] = $value instanceof EventChangeStatus ? $value->value : $value;
        $this->metadata = $metadata;
    }

    public function getPublicMessageAttribute(): ?string
    {
        return $this->message;
    }

    public function setPublicMessageAttribute(?string $value): void
    {
        $this->message = $value;
    }

    public function getInternalNoteAttribute(): ?string
    {
        return $this->notes;
    }

    public function setInternalNoteAttribute(?string $value): void
    {
        $this->notes = $value;
    }

    public function getChangedFieldsAttribute(): ?array
    {
        $metadata = $this->metadata ?? [];

        return is_array($metadata) ? ($metadata['changed_fields'] ?? null) : null;
    }

    public function setChangedFieldsAttribute(?array $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['changed_fields'] = $value;
        $this->metadata = $metadata;
    }

    public function getBeforeSnapshotAttribute(): ?array
    {
        $metadata = $this->metadata ?? [];

        return is_array($metadata) ? ($metadata['before_snapshot'] ?? null) : null;
    }

    public function setBeforeSnapshotAttribute(?array $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['before_snapshot'] = $value;
        $this->metadata = $metadata;
    }

    public function getAfterSnapshotAttribute(): ?array
    {
        $metadata = $this->metadata ?? [];

        return is_array($metadata) ? ($metadata['after_snapshot'] ?? null) : null;
    }

    public function setAfterSnapshotAttribute(?array $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['after_snapshot'] = $value;
        $this->metadata = $metadata;
    }

    public function getRetractedAtAttribute()
    {
        return $this->archived_at;
    }

    public function setRetractedAtAttribute($value): void
    {
        $this->archived_at = $value;
    }

    public function getActorIdAttribute(): ?string
    {
        return $this->created_by_id;
    }

    public function setActorIdAttribute(?string $value): void
    {
        $this->created_by_type = 'App\Models\User';
        $this->created_by_id = $value;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function replacementEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'replacement_event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    protected static function newFactory(): EventChangeAnnouncementFactory
    {
        return EventChangeAnnouncementFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->title)) {
                $model->title = $model->message ?? $model->public_message ?? 'Pengumuman perubahan';
            }
            if (empty($model->visibility)) {
                $model->visibility = 'public';
            }
        });
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query
            ->where('metadata->status', EventChangeStatus::Published->value)
            ->whereNull('archived_at');
    }
}
