<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardPointMovementKind;
use Database\Factories\RewardPointMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only point movement. operation_key is the stable semantic identity
 * (retries collapse); aggregate_operation_key groups deterministic multi-lot
 * allocations. linked_movement_id chains reserve/debit to credit,
 * release/refund/expire to their reserve source. Never updated or deleted.
 *
 * @property RewardPointMovementKind $kind
 */
class RewardPointMovement extends Model
{
    /** @use HasFactory<RewardPointMovementFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'lot_id',
        'kind',
        'delta',
        'operation_key',
        'aggregate_operation_key',
        'linked_movement_id',
        'reservation_key',
        'award_id',
        'actor_type',
        'actor_id',
        'reason',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RewardPointMovementKind::class,
            'delta' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardPointLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(RewardPointLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<RewardPointMovement, $this>
     */
    public function linkedMovement(): BelongsTo
    {
        return $this->belongsTo(RewardPointMovement::class, 'linked_movement_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward point movements are append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward point movements cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardPointMovementFactory
    {
        return RewardPointMovementFactory::new();
    }
}
