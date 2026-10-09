<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardPayeeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Per-recipient payout destination. The stored account_ref is an operator
 * handle (masked display in account_display); full credentials live with
 * the operator/provider, never here. Verification is an independent staff
 * act: verifier must differ from the recipient. A payee change supersedes
 * the old row; frozen per-operation snapshots keep reconciling against the
 * original terms.
 *
 * @property RewardPayeeStatus $status
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $superseded_at
 */
class RewardPayee extends Model
{
    use HasUuids;

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'method',
        'account_ref',
        'account_display',
        'status',
        'evidence',
        'verified_by',
        'verified_at',
        'rejected_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RewardPayeeStatus::class,
            'evidence' => 'array',
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardPayee $payee): void {
            foreach (['recipient_type', 'recipient_id', 'method', 'account_ref', 'account_display'] as $field) {
                if ($payee->isDirty($field)) {
                    throw new LogicException("Reward payee [{$field}] is frozen; register a new payee instead.");
                }
            }

            foreach (['verified_at', 'rejected_at', 'superseded_at'] as $field) {
                if ($payee->getOriginal($field) !== null && $payee->isDirty($field)) {
                    throw new LogicException("Reward payee [{$field}] is an immutable transition timestamp.");
                }
            }

            if ($payee->isDirty('status')) {
                $original = $payee->getOriginal('status');
                $from = $original instanceof RewardPayeeStatus
                    ? $original
                    : RewardPayeeStatus::tryFrom((string) $original);
                $allowed = $from === RewardPayeeStatus::Pending
                    ? [RewardPayeeStatus::Verified, RewardPayeeStatus::Rejected, RewardPayeeStatus::Superseded]
                    : ($from === RewardPayeeStatus::Verified ? [RewardPayeeStatus::Superseded] : []);

                if (! in_array($payee->status, $allowed, true)) {
                    throw new LogicException('Invalid reward payee status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward payees cannot be deleted; reject or supersede them instead.');
        });
    }
}
