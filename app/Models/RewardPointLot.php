<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardPointLotStatus;
use Database\Factories\RewardPointLotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Integer point lot: a source-linked credit bundle with its own availability
 * and expiry. Balances are always reconciled from movements, never from
 * cached counters. Reserved lots survive expiry until the linked fulfillment
 * resolves; status flips only when no active reservation remains.
 *
 * @property RewardPointLotStatus $status
 */
class RewardPointLot extends Model
{
    /** @use HasFactory<RewardPointLotFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'family_key',
        'program_id',
        'award_id',
        'seq',
        'unit',
        'credited_points',
        'source_operation_key',
        'available_at',
        'expires_at',
        'status',
        'credited_at',
        'depleted_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'credited_points' => 'integer',
            'available_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'status' => RewardPointLotStatus::class,
            'credited_at' => 'immutable_datetime',
            'depleted_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardAward, $this>
     */
    public function award(): BelongsTo
    {
        return $this->belongsTo(RewardAward::class, 'award_id');
    }

    /**
     * @return HasMany<RewardPointMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(RewardPointMovement::class, 'lot_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardPointLot $lot): void {
            foreach ([
                'recipient_type', 'recipient_id', 'family_key', 'program_id', 'award_id',
                'seq', 'unit', 'credited_points', 'source_operation_key', 'available_at',
                'expires_at', 'credited_at',
            ] as $field) {
                if ($lot->isDirty($field)) {
                    throw new LogicException("Reward point lot [{$field}] is frozen credit evidence.");
                }
            }

            foreach (['depleted_at', 'expired_at'] as $field) {
                if ($lot->getOriginal($field) !== null && $lot->isDirty($field)) {
                    throw new LogicException("Reward point lot [{$field}] is immutable once set.");
                }
            }

            if ($lot->isDirty('status')) {
                $original = $lot->getOriginal('status');
                $from = $original instanceof RewardPointLotStatus
                    ? $original
                    : RewardPointLotStatus::tryFrom((string) $original);

                $allowed = $from === RewardPointLotStatus::Open
                    && in_array($lot->status, [RewardPointLotStatus::Depleted, RewardPointLotStatus::Expired], true);

                if (! $allowed) {
                    throw new LogicException('Invalid reward point lot status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward point lots cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardPointLotFactory
    {
        return RewardPointLotFactory::new();
    }
}
