<?php

namespace App\Models;

use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeStatus;
use App\Enums\EventChangeType;
use Carbon\CarbonImmutable;
use Database\Factories\EventChangeAnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $event_id
 * @property string|null $replacement_event_id
 * @property string|null $actor_id
 * @property EventChangeType $type
 * @property EventChangeStatus $status
 * @property EventChangeSeverity $severity
 * @property string|null $public_message
 * @property string|null $internal_note
 * @property array<int, string>|null $changed_fields
 * @property array<string, mixed>|null $before_snapshot
 * @property array<string, mixed>|null $after_snapshot
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $retracted_at
 * @property-read Event $event
 * @property-read Event|null $replacementEvent
 * @property-read User|null $actor
 */
class EventChangeAnnouncement extends Model
{
    /** @use HasFactory<EventChangeAnnouncementFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'replacement_event_id',
        'actor_id',
        'type',
        'status',
        'severity',
        'public_message',
        'internal_note',
        'changed_fields',
        'before_snapshot',
        'after_snapshot',
        'published_at',
        'retracted_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => EventChangeType::class,
            'status' => EventChangeStatus::class,
            'severity' => EventChangeSeverity::class,
            'changed_fields' => 'array',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'published_at' => 'immutable_datetime',
            'retracted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
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
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query
            ->where('status', EventChangeStatus::Published->value)
            ->whereNull('retracted_at');
    }
}
