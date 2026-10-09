<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RewardAwardEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only award decision entry. One row per transition (seq), carrying
 * actor/reason/evidence plus the source operation id for replay detection.
 * Never updated or deleted.
 *
 * @property array<string, mixed>|null $evidence
 */
class RewardAwardEntry extends Model
{
    /** @use HasFactory<RewardAwardEntryFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'award_id',
        'seq',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'actor_role',
        'reason',
        'evidence',
        'source_operation_id',
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
     * @return BelongsTo<RewardAward, $this>
     */
    public function award(): BelongsTo
    {
        return $this->belongsTo(RewardAward::class, 'award_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward award entries are append-only.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward award entries cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardAwardEntryFactory
    {
        return RewardAwardEntryFactory::new();
    }
}
