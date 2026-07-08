<?php

namespace App\Models;

use Database\Factories\PendingNotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Use AIArmada\Communications\Models\CommunicationDelivery instead.
 *     Kept for migration parity checking. Remove in Phase 12B.
 */
class PendingNotification extends Model
{
    /** @use HasFactory<PendingNotificationFactory> */
    use HasFactory, HasUuids;

    protected $table = 'notification_messages';

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
        'notification_id',
        'occurred_at',
        'processed_at',
        'dispatched_at',
        'read_at',
        'channels_attempted',
        'meta',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
