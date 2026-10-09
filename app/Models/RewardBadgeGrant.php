<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardBadgeStatus;
use Database\Factories\RewardBadgeGrantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Private badge grant. Grants are per-recipient, never public. A revoked
 * badge keeps its history row; a later re-grant appends a new seq row.
 * The single allowed mutation is granted -> revoked with reason/actor.
 *
 * @property RewardBadgeStatus $status
 * @property array<string, mixed>|null $evidence
 */
class RewardBadgeGrant extends Model
{
    /** @use HasFactory<RewardBadgeGrantFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'family_key',
        'badge_key',
        'seq',
        'program_id',
        'award_id',
        'status',
        'granted_by_type',
        'granted_by_id',
        'grant_reason',
        'evidence',
        'granted_at',
        'revoked_by_type',
        'revoked_by_id',
        'revoke_reason',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'status' => RewardBadgeStatus::class,
            'evidence' => 'array',
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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
        static::updating(static function (RewardBadgeGrant $grant): void {
            foreach ($grant->getDirty() as $field => $value) {
                if (! in_array($field, ['status', 'revoked_by_type', 'revoked_by_id', 'revoke_reason', 'revoked_at', 'updated_at'], true)) {
                    throw new LogicException("Reward badge grant [{$field}] is immutable.");
                }
            }

            if ($grant->isDirty('status')
                && ! ($grant->getOriginal('status') instanceof RewardBadgeStatus
                    && $grant->getOriginal('status') === RewardBadgeStatus::Granted
                    && $grant->status === RewardBadgeStatus::Revoked)
            ) {
                throw new LogicException('Reward badge grants only transition granted -> revoked.');
            }

            if ($grant->getOriginal('revoked_at') !== null && $grant->isDirty('revoked_at')) {
                throw new LogicException('Reward badge grant [revoked_at] is immutable once set.');
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward badge grants cannot be deleted; revoke them instead.');
        });
    }

    protected static function newFactory(): RewardBadgeGrantFactory
    {
        return RewardBadgeGrantFactory::new();
    }
}
