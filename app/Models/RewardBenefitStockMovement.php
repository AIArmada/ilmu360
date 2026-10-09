<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardBenefitStockKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only benefit stock movement. Available units derive from sums
 * only: capacity + restock - reserved - consume, where
 * reserved = reserve - release - consume. Restock exists only for
 * revoked-unused units returning to the pool. Rows are never updated or
 * deleted.
 *
 * @property RewardBenefitStockKind $kind
 */
class RewardBenefitStockMovement extends Model
{
    use HasUuids;

    protected $fillable = [
        'movement_key',
        'item_id',
        'kind',
        'grant_id',
        'quantity',
        'actor_type',
        'actor_id',
        'actor_role',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RewardBenefitStockKind::class,
            'quantity' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward benefit stock movements are append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward benefit stock movements cannot be deleted.');
        });
    }
}
