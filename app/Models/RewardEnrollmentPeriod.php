<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RewardEnrollmentPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Join/leave period. Created on (re)join with joined_at; the single allowed
 * mutation is setting left_at/leave_reason once on leave. Later cycles append
 * new rows — history is never rewritten.
 */
class RewardEnrollmentPeriod extends Model
{
    /** @use HasFactory<RewardEnrollmentPeriodFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'enrollment_id',
        'seq',
        'joined_at',
        'left_at',
        'join_acceptance_id',
        'leave_reason',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(RewardEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<RewardEnrollmentAcceptance, $this>
     */
    public function joinAcceptance(): BelongsTo
    {
        return $this->belongsTo(RewardEnrollmentAcceptance::class, 'join_acceptance_id');
    }

    public function isOpen(): bool
    {
        return $this->left_at === null;
    }

    protected static function booted(): void
    {
        static::creating(static function (RewardEnrollmentPeriod $period): void {
            if ($period->left_at !== null
                && CarbonImmutable::parse($period->left_at)->lessThan(CarbonImmutable::parse($period->joined_at))
            ) {
                throw new LogicException('Reward enrollment period [left_at] cannot predate [joined_at].');
            }
        });

        static::updating(static function (RewardEnrollmentPeriod $period): void {
            foreach ($period->getDirty() as $field => $value) {
                if (! in_array($field, ['left_at', 'leave_reason', 'updated_at'], true)) {
                    throw new LogicException("Reward enrollment period [{$field}] is immutable.");
                }
            }

            if ($period->getOriginal('left_at') !== null && $period->isDirty('left_at')) {
                throw new LogicException('Reward enrollment period [left_at] can only be set once.');
            }

            if ($period->isDirty('leave_reason') && $period->getOriginal('leave_reason') !== null) {
                throw new LogicException('Reward enrollment period [leave_reason] can only be set once.');
            }

            if ($period->isDirty('leave_reason') && $period->left_at === null) {
                throw new LogicException('Reward enrollment period leave_reason requires left_at.');
            }

            if ($period->isDirty('left_at') && $period->left_at !== null
                && CarbonImmutable::parse($period->left_at)->lessThan(CarbonImmutable::parse($period->joined_at))
            ) {
                throw new LogicException('Reward enrollment period [left_at] cannot predate [joined_at].');
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward enrollment periods cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardEnrollmentPeriodFactory
    {
        return RewardEnrollmentPeriodFactory::new();
    }
}
