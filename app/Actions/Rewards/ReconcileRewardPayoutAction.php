<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\Services\PayoutReconciliationService;
use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use App\Enums\Rewards\RewardBudgetMovementKind;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Models\RewardAward;
use App\Models\RewardPayoutRequest;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardRecipientAffiliateResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Reconcile an unknown or submitted payout against the provider.
 *
 * The provider query runs outside any transaction, under the affiliate
 * send mutex. A legitimate late completion settles BOTH ledgers through
 * the canonical package transition (payout completed, backing
 * conversions paid) plus the app spend; a confirmed provider failure or
 * cancellation fails both ledgers and releases the attempt's
 * reservation; anything else keeps the request with the original
 * operation and payee intact. No new operation is ever created here and
 * no money is released while the old operation may still succeed.
 */
final readonly class ReconcileRewardPayoutAction
{
    use AsAction;

    public function handle(
        RewardPayoutRequest|string $request,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
    ): RewardPayoutRequest {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Payout reconciliation requires a staff/admin user identity.');
        }

        $requestId = $request instanceof RewardPayoutRequest ? (string) $request->getKey() : $request;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $pre = RewardPayoutRequest::query()->whereKey($requestId)->first();

        if (! $pre instanceof RewardPayoutRequest) {
            throw new InvalidArgumentException('Unknown reward payout request.');
        }

        if (! in_array($pre->status, [
            RewardPayoutRequestStatus::Unknown,
            RewardPayoutRequestStatus::Submitted,
        ], true)) {
            throw new InvalidArgumentException('Only unknown or submitted payouts can be reconciled.');
        }

        if ($pre->affiliate_payout_id === null) {
            throw new InvalidArgumentException('Payout carries no provider operation; submit it first.');
        }

        $award = RewardAward::query()->whereKey((string) $pre->award_id)->firstOrFail();
        $recipient = RewardRecipientAffiliateResolver::resolve(
            (string) $award->recipient_type, (string) $award->recipient_id,
        );

        try {
            return Cache::lock('reward-payout-send:'.$recipient->getKey(), 30)->block(10, function () use (
                $requestId, $actorType, $actorId, $actorRole, $decided,
            ): RewardPayoutRequest {
                $gated = DB::transaction(function () use ($requestId): RewardPayoutRequest {
                    return $this->lockedRequest($requestId);
                });

                $payout = AffiliatePayout::query()->whereKey((string) $gated->affiliate_payout_id)->firstOrFail();

                // Provider query outside every transaction, under the
                // send mutex.
                $status = app(PayoutProcessorFactory::class)->make((string) $gated->processor)->getStatus($payout);
                $status = mb_strtolower(mb_trim($status));

                return $this->apply($requestId, $status, $actorType, $actorId, $actorRole, $decided);
            });
        } catch (LockTimeoutException) {
            throw new InvalidArgumentException('Another payout operation for this recipient is in flight; retry the reconciliation.');
        }
    }

    private function apply(
        string $requestId,
        string $status,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): RewardPayoutRequest {
        return DB::transaction(function () use (
            $requestId, $status, $actorType, $actorId, $actorRole, $decided,
        ): RewardPayoutRequest {
            $row = $this->lockedRequest($requestId);

            if (! in_array($row->status, [
                RewardPayoutRequestStatus::Unknown,
                RewardPayoutRequestStatus::Submitted,
            ], true)) {
                return $row;
            }

            $total = (int) $row->amount_minor + ((string) $row->fee_payer === 'program' ? (int) $row->fee_minor : 0);
            $programId = (string) $row->program_id;
            $currency = (string) $row->currency;

            if (in_array($status, ['completed', 'complete', 'paid', 'succeeded', 'success'], true)) {
                $this->settlePackage($row, 'completed');

                RewardBudgetLedger::append(
                    RewardBudgetMovementKind::Spend, "spend:{$row->award_id}",
                    $programId, $currency, $total, (string) $row->award_id,
                    $actorType, $actorId, $actorRole, $decided,
                );

                RewardBudgetLedger::append(
                    RewardBudgetMovementKind::Release, "release:{$row->award_id}:spent",
                    $programId, $currency, $total, (string) $row->award_id,
                    $actorType, $actorId, $actorRole, $decided,
                );

                RewardBudgetLedger::assertNonNegativeLegs(
                    RewardBudgetLedger::lockedBalances(
                        $programId, $currency,
                        RewardBudgetLedger::lockFunding($programId, $currency)
                    )
                );

                $row->forceFill([
                    'status' => RewardPayoutRequestStatus::Confirmed,
                    'failure_code' => null,
                    'confirmed_by' => $actorId,
                    'confirmed_at' => $decided,
                ])->save();

                return $row->refresh();
            }

            if (in_array($status, ['failed', 'failure', 'cancelled', 'canceled', 'rejected', 'declined'], true)) {
                $this->settlePackage($row, 'failed');

                $attempt = RewardBudgetLedger::attemptOf($row->evidence);

                RewardBudgetLedger::append(
                    RewardBudgetMovementKind::Release,
                    RewardBudgetLedger::releaseKeyForAttempt(
                        (string) $row->award_id, $attempt, 'reconciled-failure'
                    ),
                    $programId, $currency, $total, (string) $row->award_id,
                    $actorType, $actorId, $actorRole, $decided,
                );

                RewardBudgetLedger::assertNonNegativeLegs(
                    RewardBudgetLedger::lockedBalances(
                        $programId, $currency,
                        RewardBudgetLedger::lockFunding($programId, $currency)
                    )
                );

                $fill = [
                    'status' => RewardPayoutRequestStatus::Failed,
                    'failure_code' => 'PROVIDER_RECONCILED_'.$status,
                ];

                if ($attempt <= 1) {
                    $fill['failed_at'] = $decided;
                } else {
                    $evidence = $row->evidence ?? [];
                    $refailures = $evidence['refailures'] ?? [];
                    $refailures[] = [
                        'at' => $decided->toIso8601String(),
                        'attempt' => $attempt,
                        'code' => 'PROVIDER_RECONCILED_'.$status,
                    ];
                    $evidence['refailures'] = $refailures;
                    $fill['evidence'] = $evidence;
                }

                $row->forceFill($fill)->save();

                return $row->refresh();
            }

            $evidence = $row->evidence ?? [];
            $evidence['last_reconciled_at'] = $decided->toIso8601String();
            $evidence['last_provider_status'] = $status;
            $row->forceFill(['evidence' => $evidence])->save();

            return $row->refresh();
        });
    }

    /**
     * Settles the canonical package ledger through its own declared
     * transition: completing marks the backing conversions paid, failing
     * releases the package reservation. A no-op return (already settled
     * or undeclared) keeps the app honest: the app only stamps what the
     * provider outcome proves, and the package row tells the same story.
     */
    private function settlePackage(RewardPayoutRequest $row, string $externalStatus): void
    {
        $payout = AffiliatePayout::query()
            ->with('operation')
            ->whereKey((string) $row->affiliate_payout_id)
            ->lockForUpdate()
            ->firstOrFail();

        app(PayoutReconciliationService::class)->reconcilePayout(
            $payout, $externalStatus, ['reference' => (string) ($row->provider_reference ?? $row->first_reference)]
        );
    }

    /**
     * Locks the payout, affiliate, award, request, and funding rows in the
     * shared cross-domain order and returns the locked request.
     */
    private function lockedRequest(string $requestId): RewardPayoutRequest
    {
        $pre = RewardPayoutRequest::query()->whereKey($requestId)->firstOrFail();

        if ($pre->affiliate_payout_id !== null) {
            $payout = AffiliatePayout::query()
                ->with('operation')
                ->whereKey((string) $pre->affiliate_payout_id)
                ->lockForUpdate()
                ->first();

            if ($payout?->operation !== null) {
                Affiliate::query()
                    ->whereKey((string) $payout->operation->affiliate_id)
                    ->lockForUpdate()
                    ->first();
            }
        }

        RewardAward::query()->whereKey((string) $pre->award_id)->lockForUpdate()->firstOrFail();

        $row = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

        RewardBudgetLedger::lockFunding((string) $row->program_id, (string) $row->currency);

        return $row;
    }
}
