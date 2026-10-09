<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardRecoveryKind;
use App\Enums\Rewards\RewardRecoveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Post-fulfillment recovery case. The original fulfillment stays immutable;
 * the recovery tracks the receivable/adjustment/dispute with authorized
 * settlement or writeoff plus evidence. Settlement of a confirmed cash
 * recovery replenishes the program budget; nothing else restores spent
 * value silently.
 *
 * @property RewardRecoveryKind $kind
 * @property RewardRecoveryStatus $status
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $written_off_at
 */
class RewardRecovery extends Model
{
    use HasUuids;

    protected $fillable = [
        'recovery_key',
        'award_id',
        'payout_request_id',
        'grant_id',
        'kind',
        'status',
        'amount_minor',
        'currency',
        'points',
        'unit',
        'evidence',
        'actor_type',
        'actor_id',
        'actor_role',
        'opened_at',
        'settled_at',
        'written_off_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RewardRecoveryKind::class,
            'status' => RewardRecoveryStatus::class,
            'amount_minor' => 'integer',
            'points' => 'integer',
            'evidence' => 'array',
            'opened_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'written_off_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardRecovery $recovery): void {
            foreach ([
                'recovery_key', 'award_id', 'payout_request_id', 'grant_id',
                'kind', 'amount_minor', 'currency', 'points', 'unit',
                'actor_type', 'actor_id', 'actor_role', 'opened_at',
            ] as $field) {
                if ($recovery->isDirty($field)) {
                    throw new LogicException("Reward recovery [{$field}] is frozen at opening time.");
                }
            }

            foreach (['settled_at', 'written_off_at'] as $field) {
                if ($recovery->getOriginal($field) !== null && $recovery->isDirty($field)) {
                    throw new LogicException("Reward recovery [{$field}] is an immutable transition timestamp.");
                }
            }

            if ($recovery->isDirty('status')) {
                $original = $recovery->getOriginal('status');
                $from = $original instanceof RewardRecoveryStatus
                    ? $original
                    : RewardRecoveryStatus::tryFrom((string) $original);

                $allowed = $from === RewardRecoveryStatus::Open
                    ? [RewardRecoveryStatus::Settled, RewardRecoveryStatus::WrittenOff]
                    : [];

                if (! in_array($recovery->status, $allowed, true)) {
                    throw new LogicException('Invalid reward recovery status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward recoveries cannot be deleted; settle or write them off instead.');
        });
    }
}
