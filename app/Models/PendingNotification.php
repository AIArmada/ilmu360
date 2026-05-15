<?php

namespace App\Models;

use App\Enums\NotificationCadence;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use Database\Factories\PendingNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PendingNotification extends Model
{
    /** @use HasFactory<PendingNotificationFactory> */
    use HasFactory, HasUuids;

    protected $table = 'notification_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'fingerprint',
        'family',
        'trigger',
        'title',
        'body',
        'action_url',
        'entity_type',
        'entity_id',
        'priority',
        'delivery_cadence',
        'occurred_at',
        'read_at',
        'channels_attempted',
        'meta',
        'dispatched_at',
        'notification_id',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'family' => NotificationFamily::class,
            'trigger' => NotificationTrigger::class,
            'priority' => NotificationPriority::class,
            'delivery_cadence' => NotificationCadence::class,
            'occurred_at' => 'datetime',
            'read_at' => 'datetime',
            'channels_attempted' => 'array',
            'meta' => 'array',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_message_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function forCadence(Builder $query, NotificationCadence $cadence): Builder
    {
        return $query->where('delivery_cadence', $cadence->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function pendingDispatch(Builder $query): Builder
    {
        return $query->whereNull('dispatched_at');
    }
}
