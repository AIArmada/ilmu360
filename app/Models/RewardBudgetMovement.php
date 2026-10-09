<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardBudgetMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only budget movement. Reserve/release/spend/recover only; rows are
 * never updated or deleted. Balances derive from sums, never from a stored
 * mutable total.
 *
 * Invariant (per program + currency):
 *   funded == available + reserved + net_spent
 * where reserved = reserve - release, net_spent = spend - recover, and
 * recover rows exist only for confirmed recoveries.
 *
 * @property RewardBudgetMovementKind $kind
 * @property CarbonImmutable|null $decided_at
 */
class RewardBudgetMovement extends Model
{
    use HasUuids;

    protected $fillable = [
        'movement_key',
        'program_id',
        'currency',
        'kind',
        'award_id',
        'amount_minor',
        'actor_type',
        'actor_id',
        'actor_role',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RewardBudgetMovementKind::class,
            'amount_minor' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward budget movements are append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward budget movements cannot be deleted.');
        });
    }
}
