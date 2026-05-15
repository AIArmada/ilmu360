<?php

namespace App\Models;

use App\Enums\NotificationCadence;
use App\Enums\NotificationRuleScope;
use Database\Factories\NotificationRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRule extends Model
{
    /** @use HasFactory<NotificationRuleFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'scope_type',
        'scope_key',
        'enabled',
        'cadence',
        'channels',
        'fallback_channels',
        'urgent_override',
        'meta',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'scope_type' => NotificationRuleScope::class,
            'enabled' => 'boolean',
            'cadence' => NotificationCadence::class,
            'channels' => 'array',
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
