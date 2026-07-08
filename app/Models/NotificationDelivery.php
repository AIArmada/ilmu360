<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationFamily;
use App\Enums\NotificationTrigger;
use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Use AIArmada\Communications\Models\CommunicationDelivery instead.
 *     Kept for migration parity checking. Remove in Phase 12B.
 */
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'notification_message_id',
        'user_id',
        'family',
        'trigger',
        'channel',
        'destination_id',
        'fingerprint',
        'provider',
        'provider_message_id',
        'status',
        'payload',
        'meta',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'family' => NotificationFamily::class,
            'trigger' => NotificationTrigger::class,
            'channel' => NotificationChannel::class,
            'status' => NotificationDeliveryStatus::class,
            'payload' => 'array',
            'meta' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
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
     * @return BelongsTo<NotificationDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(NotificationDestination::class, 'destination_id');
    }
}
