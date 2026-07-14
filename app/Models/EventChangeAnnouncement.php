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
        'update_type',
        'title',
        'message',
        'notes',
        'severity',
        'visibility',
        'published_at',
        'archived_at',
        'created_by_type',
        'created_by_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'update_type' => EventChangeType::class,
            'severity' => EventChangeSeverity::class,
        ]);
    }

    public function event(): BelongsTo
    {
        /** @phpstan-ignore-next-line childReturnType (covariant override) */
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function replacementEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'replacement_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
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
                $model->title = $model->message ?? 'Pengumuman perubahan';
            }

            if (empty($model->visibility)) {
                $model->visibility = 'public';
            }
        });
    }

    /**
     * @param  Builder<EventChangeAnnouncement>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query
            ->where('metadata->status', EventChangeStatus::Published->value)
            ->whereNull('archived_at');
    }
}
