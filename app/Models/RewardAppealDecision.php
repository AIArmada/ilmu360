<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RewardAppealDecisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only appeal decision (override evidence). Never updated or deleted.
 *
 * @property array<string, mixed>|null $evidence
 */
class RewardAppealDecision extends Model
{
    /** @use HasFactory<RewardAppealDecisionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'appeal_id',
        'seq',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'actor_role',
        'reason',
        'evidence',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'evidence' => 'array',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardAppeal, $this>
     */
    public function appeal(): BelongsTo
    {
        return $this->belongsTo(RewardAppeal::class, 'appeal_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward appeal decisions are append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward appeal decisions cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardAppealDecisionFactory
    {
        return RewardAppealDecisionFactory::new();
    }
}
