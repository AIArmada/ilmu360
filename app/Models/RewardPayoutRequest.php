<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardPayoutRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One cash award's payout operation. The operation_key is stable
 * (`reward-payout:{award_id}`); first_reference is the stable first bank/
 * provider reference minted at request time and never regenerated. The
 * payee/provider/method/value/fee snapshot is frozen at approval: a later
 * payee change affects future work only. Unknown provider outcomes stay
 * unknown until reconciliation proves completion or confirmed failure —
 * an uncertain cancel never becomes failure or releases money.
 *
 * @property RewardPayoutRequestStatus $status
 * @property array<string, mixed> $payee_snapshot
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 */
class RewardPayoutRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'award_id',
        'payee_id',
        'program_id',
        'currency',
        'amount_minor',
        'fee_minor',
        'fee_payer',
        'processor',
        'operation_key',
        'first_reference',
        'affiliate_payout_id',
        'backing_conversion_id',
        'status',
        'provider_reference',
        'failure_code',
        'payee_snapshot',
        'evidence',
        'requested_by_type',
        'requested_by_id',
        'requested_by_role',
        'approved_by',
        'confirmed_by',
        'requested_at',
        'approved_at',
        'submitted_at',
        'confirmed_at',
        'failed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'status' => RewardPayoutRequestStatus::class,
            'payee_snapshot' => 'array',
            'evidence' => 'array',
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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
     * @return BelongsTo<RewardPayee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(RewardPayee::class, 'payee_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardPayoutRequest $request): void {
            foreach ([
                'award_id', 'payee_id', 'program_id', 'currency', 'amount_minor',
                'fee_minor', 'fee_payer', 'processor', 'operation_key',
                'first_reference', 'payee_snapshot', 'requested_by_type',
                'requested_by_id', 'requested_by_role', 'requested_at',
            ] as $field) {
                if ($request->isDirty($field)) {
                    throw new LogicException("Reward payout request [{$field}] is frozen at request time.");
                }
            }

            foreach (['approved_at', 'submitted_at', 'confirmed_at', 'failed_at', 'cancelled_at'] as $field) {
                if ($request->getOriginal($field) !== null && $request->isDirty($field)) {
                    throw new LogicException("Reward payout request [{$field}] is an immutable transition timestamp.");
                }
            }

            if ($request->isDirty('status')) {
                $original = $request->getOriginal('status');
                $from = $original instanceof RewardPayoutRequestStatus
                    ? $original
                    : RewardPayoutRequestStatus::tryFrom((string) $original);

                if (! $from instanceof RewardPayoutRequestStatus || ! $from->canTransitionTo($request->status)) {
                    throw new LogicException('Invalid reward payout request status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward payout requests cannot be deleted; cancel or reconcile them instead.');
        });
    }
}
