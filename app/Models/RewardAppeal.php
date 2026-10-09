<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardAppealStatus;
use Database\Factories\RewardAppealFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Role-appropriate appeal over an award decision. Opened inside the appeal
 * window by the recipient (or staff on their behalf); reviewed and decided
 * by a different staff identity — the domain guards caller own-approval
 * wherever identities are supplied. Every transition appends a decision row
 * with actor/reason/evidence; the original award history is never rewritten.
 *
 * @property RewardAppealStatus $status
 * @property array<string, mixed>|null $evidence
 */
class RewardAppeal extends Model
{
    /** @use HasFactory<RewardAppealFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'award_id',
        'seq',
        'status',
        'opened_by_type',
        'opened_by_id',
        'opened_by_role',
        'reason',
        'evidence',
        'window_ends_at',
        'opened_at',
        'in_review_at',
        'upheld_at',
        'overturned_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'status' => RewardAppealStatus::class,
            'evidence' => 'array',
            'window_ends_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'in_review_at' => 'immutable_datetime',
            'upheld_at' => 'immutable_datetime',
            'overturned_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
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
     * @return HasMany<RewardAppealDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(RewardAppealDecision::class, 'appeal_id')->orderBy('seq');
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardAppeal $appeal): void {
            foreach ([
                'award_id', 'seq', 'opened_by_type', 'opened_by_id', 'opened_by_role',
                'reason', 'evidence', 'window_ends_at', 'opened_at',
            ] as $field) {
                if ($appeal->isDirty($field)) {
                    throw new LogicException("Reward appeal [{$field}] is immutable opening evidence.");
                }
            }

            foreach (['in_review_at', 'upheld_at', 'overturned_at', 'closed_at'] as $field) {
                if ($appeal->getOriginal($field) !== null && $appeal->isDirty($field)) {
                    throw new LogicException("Reward appeal [{$field}] is an immutable transition timestamp.");
                }
            }

            if ($appeal->isDirty('status')) {
                $original = $appeal->getOriginal('status');
                $from = $original instanceof RewardAppealStatus
                    ? $original
                    : RewardAppealStatus::tryFrom((string) $original);

                if (! $from instanceof RewardAppealStatus || ! $from->canTransitionTo($appeal->status)) {
                    throw new LogicException('Invalid reward appeal status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward appeals cannot be deleted; close them instead.');
        });
    }

    protected static function newFactory(): RewardAppealFactory
    {
        return RewardAppealFactory::new();
    }
}
