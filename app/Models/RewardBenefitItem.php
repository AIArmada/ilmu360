<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardBenefitItemStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Benefit catalog item: conditions, point cost or direct entitlement,
 * fixed capacity, expiry. Terms are frozen at creation; only retirement
 * transitions. A new capacity/terms revision is a new item row, never an
 * edit — outstanding grants keep reconciling against frozen terms.
 *
 * @property RewardBenefitItemStatus $status
 * @property array<int, string> $conditions
 * @property array<string, mixed>|null $fulfillment_config
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $retired_at
 */
class RewardBenefitItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'program_id',
        'benefit_key',
        'title',
        'conditions',
        'cost_points',
        'cost_unit',
        'direct_entitlement',
        'capacity',
        'fulfillment_config',
        'status',
        'expires_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'fulfillment_config' => 'array',
            'cost_points' => 'integer',
            'direct_entitlement' => 'boolean',
            'capacity' => 'integer',
            'status' => RewardBenefitItemStatus::class,
            'expires_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardBenefitItem $item): void {
            foreach (['program_id', 'benefit_key', 'title', 'conditions', 'cost_points', 'cost_unit', 'direct_entitlement', 'capacity', 'fulfillment_config', 'expires_at'] as $field) {
                if ($item->isDirty($field)) {
                    throw new LogicException("Reward benefit item [{$field}] is frozen; create a new catalog revision instead.");
                }
            }

            if ($item->getOriginal('retired_at') !== null && $item->isDirty('retired_at')) {
                throw new LogicException('Reward benefit item [retired_at] is an immutable transition timestamp.');
            }

            if ($item->isDirty('status')) {
                $original = $item->getOriginal('status');
                $from = $original instanceof RewardBenefitItemStatus
                    ? $original
                    : RewardBenefitItemStatus::tryFrom((string) $original);

                if (! ($from === RewardBenefitItemStatus::Active && $item->status === RewardBenefitItemStatus::Retired)) {
                    throw new LogicException('Reward benefit items retire once and never return.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward benefit items cannot be deleted; retire them instead.');
        });
    }
}
