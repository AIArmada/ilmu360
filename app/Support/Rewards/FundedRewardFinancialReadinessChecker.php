<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardBenefitCatalog;
use App\Contracts\Rewards\RewardFinancialReadinessChecker;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardPayeeStatus;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Models\RewardAward;
use App\Models\RewardBudgetMovement;
use App\Models\RewardPayee;
use App\Models\RewardPayoutRequest;

/**
 * Real per-award financial readiness: funded budget, verified payee,
 * working fulfillment adapter — each proven from immutable rows.
 *
 * canApproveFinancial: cash needs available budget covering the award
 * value plus program-paid fees, a verified payee for the recipient, and
 * a supported processor. canFulfillFinancial needs the payout request
 * CONFIRMED (paid) with a coherent frozen snapshot: fulfillment stamps
 * only actual receipt, never a submitted-but-unpaid promise, and it
 * never re-checks current funding (already consumed by the operation's
 * own spend) or the CURRENT payee (a later change must not strand the
 * frozen valid operation). Benefit awards consult the benefit catalog
 * adapter when bound; until then they report an explicit blocker
 * instead of silent approval.
 */
final class FundedRewardFinancialReadinessChecker implements RewardFinancialReadinessChecker
{
    public function __construct(
        private readonly RewardPayoutProcessorCatalog $catalog,
    ) {}

    public function canApproveFinancial(RewardAward $award): RewardFinancialReadinessAssessment
    {
        if ($award->component === RewardComponent::Benefit) {
            return $this->benefitAssessment($award, false);
        }

        if ($award->component !== RewardComponent::Cash) {
            return new RewardFinancialReadinessAssessment(ready: true);
        }

        $blockers = [];
        $notes = [];
        $amount = $award->amount_snapshot;
        $minor = $amount['minor_units'] ?? null;
        $currency = $amount['currency'] ?? null;

        if (! is_int($minor) || $minor <= 0 || ! is_string($currency)) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['cash-amount-unreadable'],
            );
        }

        try {
            $currency = RewardBudgetLedger::normalizeCurrency($currency);
        } catch (\InvalidArgumentException) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['cash-currency-invalid'],
            );
        }

        $balances = RewardBudgetLedger::balances((string) $award->program_id, $currency);

        // Own-obligation aware: the held award already reserves its value
        // inside `reserved`, so the gate credits the live obligation back
        // and prices only the uncovered remainder. A retired obligation
        // (rejection/request conversion) credits nothing.
        $ownReserved = 0;
        $epoch = $award->obligation_epoch;
        $obligated = $award->obligated_minor;

        if (is_int($epoch) && is_int($obligated) && $obligated > 0) {
            $retired = RewardBudgetMovement::query()
                ->where('movement_key', RewardBudgetLedger::releaseObligateKey((string) $award->getKey(), $epoch))
                ->exists();

            if (! $retired) {
                $ownReserved = $obligated;
            }
        }

        if ($minor > $balances['available'] + $ownReserved) {
            $blockers[] = 'insufficient-confirmed-funding';
        } else {
            $notes[] = 'Confirmed funding covers the award value.';
        }

        $payee = RewardPayee::query()
            ->where('recipient_type', (string) $award->recipient_type)
            ->where('recipient_id', (string) $award->recipient_id)
            ->where('status', RewardPayeeStatus::Verified->value)
            ->orderByDesc('verified_at')
            ->first();

        if (! $payee instanceof RewardPayee) {
            $blockers[] = 'payee-unverified';
        } elseif (! $this->catalog->isSupported((string) $payee->method)) {
            $blockers[] = 'payee-processor-unsupported';
        } else {
            $notes[] = 'Verified payee with a supported processor.';
        }

        if ($blockers !== []) {
            return new RewardFinancialReadinessAssessment(ready: false, blockers: $blockers, notes: $notes);
        }

        return new RewardFinancialReadinessAssessment(ready: true, notes: $notes);
    }

    public function canFulfillFinancial(RewardAward $award): RewardFinancialReadinessAssessment
    {
        if ($award->component === RewardComponent::Benefit) {
            return $this->benefitAssessment($award, true);
        }

        if ($award->component !== RewardComponent::Cash) {
            return new RewardFinancialReadinessAssessment(ready: true);
        }

        $request = RewardPayoutRequest::query()
            ->where('award_id', $award->getKey())
            ->first();

        if (! $request instanceof RewardPayoutRequest) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['payout-not-requested'],
            );
        }

        if ($request->status !== RewardPayoutRequestStatus::Confirmed) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['payout-not-confirmed:'.$request->status->value],
                notes: ['Cash fulfillment stamps only a confirmed (paid) payout.'],
            );
        }

        $snapshot = $request->payee_snapshot ?? [];

        if (($snapshot['payee_id'] ?? null) !== (string) $request->payee_id
            || ($snapshot['processor'] ?? null) !== (string) $request->processor
            || ! is_string($snapshot['account_ref'] ?? null)
            || ($snapshot['account_ref'] ?? null) === ''
        ) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['payout-terms-incoherent'],
            );
        }

        return new RewardFinancialReadinessAssessment(
            ready: true,
            notes: ['Payout confirmed against the frozen payee, provider, and destination.'],
        );
    }

    private function benefitAssessment(RewardAward $award, bool $fulfill): RewardFinancialReadinessAssessment
    {
        if (! interface_exists(RewardBenefitCatalog::class) || ! app()->bound(RewardBenefitCatalog::class)) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: [$fulfill ? 'benefit-fulfillment-adapter-missing' : 'benefit-catalog-missing'],
                notes: ['Benefit fulfillment arrives with the benefit scope; the award stays a held candidate.'],
            );
        }

        return app(RewardBenefitCatalog::class)->assess($award, $fulfill);
    }
}
