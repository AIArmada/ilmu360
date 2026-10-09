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
use Throwable;

/**
 * Cancel a payout request.
 *
 * The durable affiliate operation is inspected before anything moves: a
 * request WITH a bound payout is never treated as never-submitted.
 * Never-sent operations (claimed) and provider-terminal operations
 * (failed/reversed/cancelled) cancel through the canonical package
 * transition plus the attempt-bound budget release. Operations the
 * provider may hold (submitting/submitted/unknown/completed) cancel
 * ONLY when the provider confirms the cancel: cancel=false,
 * unsupported, or uncertain is NOT confirmed failure — the request
 * stays with its reservation and original operation intact, and
 * reconciliation must accept legitimate late completion.
 * Submitted/confirmed rows never cancel here; post-payment correction
 * flows through the recovery workflow.
 *
 * The affiliate send mutex serializes cancellation against in-flight
 * submits, reconciles, and confirmations, so a cancel can never slip
 * between a provider send and its recording.
 */
final readonly class CancelRewardPayoutAction
{
    use AsAction;

    public function handle(
        RewardPayoutRequest|string $request,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $decidedAt = null,
    ): RewardPayoutRequest {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Payout cancellation requires a staff/admin user identity.');
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
            RewardPayoutRequestStatus::Requested,
            RewardPayoutRequestStatus::Approved,
            RewardPayoutRequestStatus::Unknown,
        ], true)) {
            throw new InvalidArgumentException('Only requested, approved, or unknown payouts can be cancelled.');
        }

        $award = RewardAward::query()->whereKey((string) $pre->award_id)->firstOrFail();
        $recipient = RewardRecipientAffiliateResolver::resolve(
            (string) $award->recipient_type, (string) $award->recipient_id,
        );

        try {
            return Cache::lock('reward-payout-send:'.$recipient->getKey(), 30)->block(10, function () use (
                $requestId, $actorType, $actorId, $actorRole, $decided,
            ): RewardPayoutRequest {
                $plan = $this->plan($requestId);

                $providerConfirmed = $plan !== 'provider';

                if ($plan === 'provider') {
                    $providerConfirmed = $this->confirmProviderCancel($requestId);
                }

                return $this->apply($requestId, $plan, $providerConfirmed, $actorType, $actorId, $actorRole, $decided);
            });
        } catch (LockTimeoutException) {
            throw new InvalidArgumentException('Another payout operation for this recipient is in flight; retry the cancellation.');
        }
    }

    /**
     * Inspects the durable operation under the shared locks and returns
     * the cancellation plan: direct (no payout exists), package (the
     * operation never reached the provider or is provider-terminal), or
     * provider (the provider may hold the operation — its cancel must
     * confirm outside the transaction).
     *
     * @return 'direct'|'package'|'provider'
     */
    private function plan(string $requestId): string
    {
        return DB::transaction(function () use ($requestId): string {
            $locked = $this->lockedRequest($requestId);

            if ($locked->affiliate_payout_id === null) {
                return 'direct';
            }

            $payout = AffiliatePayout::query()
                ->with('operation')
                ->whereKey((string) $locked->affiliate_payout_id)
                ->lockForUpdate()
                ->first();

            if (! $payout instanceof AffiliatePayout || $payout->operation === null) {
                throw new InvalidArgumentException('Payout operation is missing; refusing blind cancellation.');
            }

            return match ((string) $payout->operation->status) {
                'claimed', 'failed', 'reversed', 'cancelled' => 'package',
                default => 'provider',
            };
        });
    }

    private function confirmProviderCancel(string $requestId): bool
    {
        $row = RewardPayoutRequest::query()->whereKey($requestId)->firstOrFail();

        // Provider call outside every transaction, under the send mutex.
        $payout = AffiliatePayout::query()->whereKey((string) $row->affiliate_payout_id)->firstOrFail();

        try {
            return app(PayoutProcessorFactory::class)->make((string) $row->processor)->cancel($payout) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  'direct'|'package'|'provider'  $plan
     */
    private function apply(
        string $requestId,
        string $plan,
        bool $providerConfirmed,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): RewardPayoutRequest {
        return DB::transaction(function () use (
            $requestId, $plan, $providerConfirmed, $actorType, $actorId, $actorRole, $decided,
        ): RewardPayoutRequest {
            $row = $this->lockedRequest($requestId);

            if (! in_array($row->status, [
                RewardPayoutRequestStatus::Requested,
                RewardPayoutRequestStatus::Approved,
                RewardPayoutRequestStatus::Unknown,
            ], true)) {
                throw new InvalidArgumentException('Only requested, approved, or unknown payouts can be cancelled.');
            }

            if ($plan === 'provider' && ! $providerConfirmed) {
                throw new InvalidArgumentException(
                    'Refused: the provider did not confirm cancellation. The payout keeps its status and reservation; resubmit or reconcile instead.'
                );
            }

            if ($plan !== 'direct') {
                $payout = AffiliatePayout::query()
                    ->with('operation')
                    ->whereKey((string) $row->affiliate_payout_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Canonical package transition; a no-op when the payout is
                // already terminal in a state that stays truthful (failed
                // at the provider while the operator cancels the attempt).
                app(PayoutReconciliationService::class)->reconcilePayout(
                    $payout, 'cancelled', ['reference' => (string) ($row->provider_reference ?? $row->first_reference)]
                );
            }

            $total = (int) $row->amount_minor + ((string) $row->fee_payer === 'program' ? (int) $row->fee_minor : 0);

            RewardBudgetLedger::append(
                RewardBudgetMovementKind::Release,
                RewardBudgetLedger::releaseKeyForAttempt(
                    (string) $row->award_id, RewardBudgetLedger::attemptOf($row->evidence), 'cancel'
                ),
                (string) $row->program_id, (string) $row->currency, $total, (string) $row->award_id,
                $actorType, $actorId, $actorRole, $decided,
            );

            RewardBudgetLedger::assertNonNegativeLegs(
                RewardBudgetLedger::lockedBalances(
                    (string) $row->program_id, (string) $row->currency,
                    RewardBudgetLedger::lockFunding((string) $row->program_id, (string) $row->currency)
                )
            );

            $row->forceFill([
                'status' => RewardPayoutRequestStatus::Cancelled,
                'cancelled_at' => $decided,
            ])->save();

            return $row->refresh();
        });
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
