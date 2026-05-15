<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDestinationStatus;
use Database\Factories\NotificationDestinationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationDestination extends Model
{
    /** @use HasFactory<NotificationDestinationFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'channel',
        'address',
        'external_id',
        'status',
        'is_primary',
        'verified_at',
        'meta',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationDestinationStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'meta' => 'array',
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
        return $this->hasMany(NotificationDelivery::class, 'destination_id');
    }
}
