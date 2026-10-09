<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardMilestoneStatus;
use Database\Factories\RewardMilestoneCycleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Deterministic milestone cycle: unique (recipient, family, milestone_key,
 * window_start). current_count is maintained under the cycle-row lock and
 * always reconciled from non-revoked contributions. Achieving appends the
 * milestone award; contributor corrections recompute and may reverse it.
 *
 * @property RewardMilestoneStatus $status
 * @property array<string, mixed> $reward_snapshot
 */
class RewardMilestoneCycle extends Model
{
    /** @use HasFactory<RewardMilestoneCycleFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'family_key',
        'program_id',
        'milestone_key',
        'window_start',
        'window_days',
        'target_count',
        'current_count',
        'reward_snapshot',
        'status',
        'award_id',
        'achieved_at',
        'invalidated_at',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'immutable_datetime',
            'window_days' => 'integer',
            'target_count' => 'integer',
            'current_count' => 'integer',
            'reward_snapshot' => 'array',
            'status' => RewardMilestoneStatus::class,
            'achieved_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
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
     * @return HasMany<RewardMilestoneContribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(RewardMilestoneContribution::class, 'cycle_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardMilestoneCycle $cycle): void {
            foreach ($cycle->getDirty() as $field => $value) {
                if (! in_array($field, ['current_count', 'status', 'award_id', 'achieved_at', 'invalidated_at', 'updated_at'], true)) {
                    throw new LogicException("Reward milestone cycle [{$field}] is immutable.");
                }
            }

            if ($cycle->getOriginal('award_id') !== null && $cycle->isDirty('award_id')) {
                throw new LogicException('Reward milestone cycle [award_id] cannot be rewritten once achieved.');
            }

            foreach (['achieved_at', 'invalidated_at'] as $field) {
                if ($cycle->getOriginal($field) !== null && $cycle->isDirty($field)) {
                    throw new LogicException("Reward milestone cycle [{$field}] is immutable once set.");
                }
            }

            if ($cycle->isDirty('status')) {
                $original = $cycle->getOriginal('status');
                $from = $original instanceof RewardMilestoneStatus
                    ? $original
                    : RewardMilestoneStatus::tryFrom((string) $original);

                $allowed = ($from === RewardMilestoneStatus::Open && in_array($cycle->status, [RewardMilestoneStatus::Achieved, RewardMilestoneStatus::Invalidated], true))
                    || ($from === RewardMilestoneStatus::Achieved && $cycle->status === RewardMilestoneStatus::Invalidated);

                if (! $allowed) {
                    throw new LogicException('Invalid reward milestone cycle status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward milestone cycles cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardMilestoneCycleFactory
    {
        return RewardMilestoneCycleFactory::new();
    }
}
