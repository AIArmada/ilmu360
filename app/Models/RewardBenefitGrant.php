<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardBenefitGrantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One recipient's benefit hold: reserved (points + stock held) → issued
 * (fulfilled to the recipient) → consumed (used). Revocation of an unused
 * grant restores once; recovery of consumed value is a separate recovery
 * case, never a silent restore.
 *
 * @property RewardBenefitGrantStatus $status
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable|null $reserved_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $expired_at
 */
class RewardBenefitGrant extends Model
{
    use HasUuids;

    protected $fillable = [
        'grant_key',
        'item_id',
        'recipient_type',
        'recipient_id',
        'award_id',
        'reservation_key',
        'quantity',
        'status',
        'fulfillment_adapter',
        'external_ref',
        'evidence',
        'actor_type',
        'actor_id',
        'actor_role',
        'reserved_at',
        'issued_at',
        'consumed_at',
        'revoked_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => RewardBenefitGrantStatus::class,
            'evidence' => 'array',
            'reserved_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardBenefitItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(RewardBenefitItem::class, 'item_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardBenefitGrant $grant): void {
            foreach ([
                'grant_key', 'item_id', 'recipient_type', 'recipient_id',
                'award_id', 'reservation_key', 'quantity', 'fulfillment_adapter',
                'actor_type', 'actor_id', 'actor_role', 'reserved_at',
            ] as $field) {
                if ($grant->isDirty($field)) {
                    throw new LogicException("Reward benefit grant [{$field}] is frozen at reservation time.");
                }
            }

            foreach (['issued_at', 'consumed_at', 'revoked_at', 'expired_at'] as $field) {
                if ($grant->getOriginal($field) !== null && $grant->isDirty($field)) {
                    throw new LogicException("Reward benefit grant [{$field}] is an immutable transition timestamp.");
                }
            }

            if ($grant->isDirty('status')) {
                $original = $grant->getOriginal('status');
                $from = $original instanceof RewardBenefitGrantStatus
                    ? $original
                    : RewardBenefitGrantStatus::tryFrom((string) $original);

                if (! $from instanceof RewardBenefitGrantStatus || ! $from->canTransitionTo($grant->status)) {
                    throw new LogicException('Invalid reward benefit grant status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward benefit grants cannot be deleted; revoke or expire them instead.');
        });
    }
}
