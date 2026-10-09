<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardEnrollmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RewardEnrollmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Canonical enrollment identity: one row per (program, member). Holds only
 * lifecycle (status), the current-acceptance pointer, and first-transition
 * timestamps. Acceptance history and join/leave periods are append-only
 * children; entitlement decisions freeze acceptance references instead of
 * re-reading this row, so leave/pause cannot strand accrued obligations.
 *
 * @property RewardEnrollmentStatus $status
 */
class RewardEnrollment extends Model
{
    /** @use HasFactory<RewardEnrollmentFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'program_id',
        'member_type',
        'member_id',
        'status',
        'current_acceptance_id',
        'enrolled_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RewardEnrollmentStatus::class,
            'enrolled_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(RewardProgram::class, 'program_id');
    }

    /**
     * @return HasMany<RewardEnrollmentAcceptance, $this>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(RewardEnrollmentAcceptance::class, 'enrollment_id')->orderBy('seq');
    }

    /**
     * @return HasMany<RewardEnrollmentPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(RewardEnrollmentPeriod::class, 'enrollment_id')->orderBy('seq');
    }

    /**
     * @return BelongsTo<RewardEnrollmentAcceptance, $this>
     */
    public function currentAcceptance(): BelongsTo
    {
        return $this->belongsTo(RewardEnrollmentAcceptance::class, 'current_acceptance_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardEnrollment $enrollment): void {
            foreach (['program_id', 'member_type', 'member_id', 'enrolled_at'] as $field) {
                if ($enrollment->isDirty($field)) {
                    throw new LogicException("Reward enrollment [{$field}] is immutable after creation.");
                }
            }

            if ($enrollment->getOriginal('left_at') !== null && $enrollment->isDirty('left_at')) {
                throw new LogicException('Reward enrollment [left_at] records the first leave immutably.');
            }

            if ($enrollment->isDirty('left_at') && $enrollment->left_at !== null
                && CarbonImmutable::parse($enrollment->left_at)->lessThan(CarbonImmutable::parse($enrollment->enrolled_at))
            ) {
                throw new LogicException('Reward enrollment [left_at] cannot predate [enrolled_at].');
            }

            if ($enrollment->getOriginal('current_acceptance_id') !== null && $enrollment->current_acceptance_id === null) {
                throw new LogicException('Reward enrollment current_acceptance_id pointer cannot be cleared once set.');
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward enrollments cannot be deleted; leave instead.');
        });
    }

    protected static function newFactory(): RewardEnrollmentFactory
    {
        return RewardEnrollmentFactory::new();
    }
}
