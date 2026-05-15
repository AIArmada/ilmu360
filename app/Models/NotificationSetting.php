<?php

namespace App\Models;

use Database\Factories\NotificationSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    /** @use HasFactory<NotificationSettingFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'locale',
        'timezone',
        'quiet_hours_start',
        'quiet_hours_end',
        'digest_delivery_time',
        'digest_weekly_day',
        'preferred_channels',
        'fallback_channels',
        'fallback_strategy',
        'urgent_override',
        'meta',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'preferred_channels' => 'array',
            'fallback_channels' => 'array',
            'urgent_override' => 'boolean',
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
}
