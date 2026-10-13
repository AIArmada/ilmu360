<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateAward;
use AIArmada\Affiliates\Actions\Payouts\CreatePayout;
use AIArmada\Affiliates\Actions\Payouts\ProcessAffiliatePayout;
use AIArmada\Affiliates\Enums\PayoutMethodType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\Models\AffiliatePayoutMethod;
use AIArmada\Affiliates\Services\PayoutReconciliationService;
use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Contracts\RewardPayoutExecution;
use AIArmada\Rewards\Contracts\RewardPayoutExecutor;
use AIArmada\Rewards\Contracts\RewardPayoutMethodCatalog;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Affiliates-backed payout executor: serves frozen payout terms through
 * the installed payout rail.
 *
 * Submit posts the exact gross backing conversion, creates the payout
 * from those explicit backing IDs, binds the FROZEN destination as the
 * serving default under a per-affiliate send mutex, and drives the
 * provider call outside every transaction. Cancel/status/settle delegate
 * to the installed processor and the canonical reconciliation service.
 * Core owns frozen terms, budget legs, idempotency, and outcome mapping;
 * this executor owns the provider side only.
 */
final class AffiliatesRewardPayoutExecutor implements RewardPayoutExecutor
{
    public function submit(RewardPayoutRequest $request): RewardPayoutExecution
    {
        $requestId = (string) $request->getKey();
        $locked = $request->refresh();

        $award = RewardAward::query()->whereKey((string) $locked->award_id)->firstOrFail();
        $recipient = RewardRecipientAffiliateResolver::resolve(
            (string) $award->recipient_type, (string) $award->recipient_id,
        );

        // Gross backing from the FROZEN terms: program-pays-fees backs
        // amount + fee so the bound provider deduction leaves the
        // promised net exactly; recipient-pays backs the award itself.
        $gross = (int) $locked->amount_minor
            + ((string) $locked->fee_payer === 'program' ? (int) $locked->fee_minor : 0);

        if ($locked->external_reference_id === null) {
            $result = app(RecordAffiliateAward::class)->handle(
                $recipient,
                'reward-cash:'.(string) $award->getKey(),
                $gross,
                (string) $locked->currency,
                [
                    'status' => ApprovedConversion::class,
                    'external_reference' => (string) $locked->first_reference,
                    'source_ref' => (string) $locked->operation_key,
                    'origin' => 'share-rewards',
                    'occurred_at' => $locked->requested_at,
                ],
            );

            if ($result->conversion === null) {
                throw new InvalidArgumentException('Cash backing refused: '.$result->disposition->value.'. The payout stays approved.');
            }

            $conversionId = (string) $result->conversion->id;

            DB::transaction(function () use ($requestId, $conversionId): void {
                $row = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
                $row->forceFill(['external_reference_id' => $conversionId])->save();
            });

            $locked = $locked->refresh();
        }

        if ($locked->external_payout_id === null) {
            $payout = $this->resolveOrCreatePayout($locked);

            DB::transaction(function () use ($requestId, $payout): void {
                $row = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
                $row->forceFill(['external_payout_id' => (string) $payout->getKey()])->save();
            });

            $locked = $locked->refresh();
        }

        $payout = AffiliatePayout::query()->whereKey((string) $locked->external_payout_id)->firstOrFail();

        // One serving default per affiliate at a time: the binding, the
        // provider call, and the result recording are mutually exclusive
        // across concurrent submits so a racing submit can never divert
        // this operation to another payee's destination. This executor is
        // the sole writer of affiliate payout methods.
        return Cache::lock('reward-payout-send:'.$recipient->getKey(), 30)->block(10, function () use (
            $requestId, $payout, $recipient, $locked,
        ): RewardPayoutExecution {
            $this->bindFrozenDestination($requestId, $recipient);

            // Provider call outside every transaction via the durable
            // operation. The mutex (not a transaction) spans it.
            $outcome = app(ProcessAffiliatePayout::class)->handle($payout);

            return new RewardPayoutExecution(
                status: $outcome->getStatus(),
                externalPayoutId: (string) $payout->getKey(),
                externalReferenceId: (string) $locked->external_reference_id,
                externalProvider: (string) $locked->processor,
                externalReference: $outcome->externalReference,
                failureCode: $outcome->failureCode,
            );
        });
    }

    public function cancel(RewardPayoutRequest $request): bool
    {
        $row = $request->refresh();

        $payout = AffiliatePayout::query()
            ->with('operation')
            ->whereKey((string) $row->external_payout_id)
            ->first();

        if (! $payout instanceof AffiliatePayout || $payout->operation === null) {
            return false;
        }

        // Never-sent and provider-terminal operations hold nothing: the
        // cancel confirms without a provider call.
        if (in_array((string) $payout->operation->status, ['claimed', 'failed', 'reversed', 'cancelled'], true)) {
            return true;
        }

        try {
            return app(PayoutProcessorFactory::class)->make((string) $row->processor)->cancel($payout) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function status(RewardPayoutRequest $request): string
    {
        $row = $request->refresh();

        $payout = AffiliatePayout::query()->whereKey((string) $row->external_payout_id)->firstOrFail();

        return app(PayoutProcessorFactory::class)->make((string) $row->processor)->getStatus($payout);
    }

    public function settle(RewardPayoutRequest $request, string $externalStatus, array $context = []): void
    {
        $row = $request->refresh();

        $payout = AffiliatePayout::query()
            ->with('operation')
            ->whereKey((string) $row->external_payout_id)
            ->lockForUpdate()
            ->firstOrFail();

        app(PayoutReconciliationService::class)->reconcilePayout($payout, $externalStatus, $context);
    }

    private function resolveOrCreatePayout(RewardPayoutRequest $request): AffiliatePayout
    {
        $conversionId = (string) $request->external_reference_id;
        $reference = SubmitRewardPayoutAction::payoutReferenceFor($request);

        return DB::transaction(function () use ($request, $conversionId, $reference): AffiliatePayout {
            $conversion = AffiliateConversion::query()->whereKey($conversionId)->lockForUpdate()->first();

            if (! $conversion instanceof AffiliateConversion) {
                throw new InvalidArgumentException('Cash backing conversion is missing; refusing the submission.');
            }

            if ($conversion->affiliate_payout_id !== null) {
                $orphan = AffiliatePayout::query()
                    ->whereKey((string) $conversion->affiliate_payout_id)
                    ->first();

                if (! $orphan instanceof AffiliatePayout) {
                    throw new InvalidArgumentException('Cash backing points at a missing payout; refusing the submission.');
                }

                $this->assertRecoveredPayoutMatches($request, $orphan, $conversion, $reference);

                return $orphan;
            }

            return app(CreatePayout::class)->handle([$conversionId], ['reference' => $reference]);
        });
    }

    private function assertRecoveredPayoutMatches(
        RewardPayoutRequest $request,
        AffiliatePayout $payout,
        AffiliateConversion $conversion,
        string $reference,
    ): void {
        $gross = (int) $request->amount_minor
            + ((string) $request->fee_payer === 'program' ? (int) $request->fee_minor : 0);

        $linked = $payout->conversions()->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        if ((string) $payout->reference !== $reference
            || (int) $payout->total_minor !== $gross
            || mb_strtoupper((string) $payout->currency) !== mb_strtoupper((string) $request->currency)
            || $linked !== [(string) $conversion->getKey()]
        ) {
            throw new InvalidArgumentException('Cash backing is already bound to a foreign payout; refusing the submission.');
        }
    }

    /**
     * Binds the FROZEN destination as the serving default immediately
     * before the provider call, under the affiliate send mutex.
     *
     * Resolution is by frozen payee + frozen destination + operation: an
     * earlier payee's method is never reused, and a same-payee row whose
     * destination differs is left untouched while a fresh exact row is
     * created — mismatches refuse or rebind, never silently reuse. The
     * request and award are re-verified under the shared locks, so a
     * cancel or reversal that landed after the initial check aborts the
     * send instead of racing it.
     */
    private function bindFrozenDestination(string $requestId, Affiliate $recipient): void
    {
        $awardId = (string) RewardPayoutRequest::query()->whereKey($requestId)->firstOrFail()->award_id;

        DB::transaction(function () use ($requestId, $awardId, $recipient): void {
            $award = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $award instanceof RewardAward || $award->status !== RewardAwardStatus::Approved) {
                throw new InvalidArgumentException('Payout submission requires an approved award; refusing a raced send.');
            }

            $row = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if (! in_array($row->status, [RewardPayoutRequestStatus::Approved, RewardPayoutRequestStatus::Unknown], true)) {
                throw new InvalidArgumentException('Payout submission requires an approved or unknown request; refusing a raced send.');
            }

            $type = PayoutMethodType::tryFrom((string) $row->processor);

            if (! $type instanceof PayoutMethodType) {
                throw new InvalidArgumentException('Payout processor has no payout-method binding.');
            }

            $catalog = app(RewardPayoutMethodCatalog::class);
            $snapshot = $row->payee_snapshot ?? [];
            $frozenPayeeId = (string) ($snapshot['payee_id'] ?? '');
            $frozenRef = (string) ($snapshot['account_ref'] ?? '');
            $operationKey = (string) $row->operation_key;

            if ($frozenPayeeId === '' || $frozenPayeeId !== (string) $row->payee_id) {
                throw new InvalidArgumentException('Payout snapshot drifted from frozen terms; refusing the submission.');
            }

            $payee = RewardPayee::query()->whereKey((string) $row->payee_id)->firstOrFail();

            $details = $catalog->detailsFor((string) $row->processor, $frozenRef, [
                'payee_id' => $frozenPayeeId,
                'account_display' => (string) ($snapshot['account_display'] ?? ''),
                'operation_key' => $operationKey,
            ]);

            // Defense in depth: the frozen destination must STILL satisfy
            // the installed validation contract at bind time.
            $errors = $catalog->validateDestination((string) $row->processor, $details);

            if ($errors !== []) {
                throw new InvalidArgumentException('Payout destination refused by ['.$row->processor.'] at bind time.');
            }

            $methods = AffiliatePayoutMethod::query()
                ->where('affiliate_id', $recipient->getKey())
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $bound = null;

            foreach ($methods as $method) {
                if (! $method->type instanceof PayoutMethodType || $method->type !== $type) {
                    continue;
                }

                $stored = is_array($method->details) ? $method->details : [];

                if (($stored['payee_id'] ?? null) === $frozenPayeeId
                    && $catalog->destinationRefFor((string) $row->processor, $stored)
                        === $catalog->destinationRefFor((string) $row->processor, $details)
                    && ($stored['operation_key'] ?? null) === $operationKey
                ) {
                    $bound = $method;

                    break;
                }
            }

            if (! $bound instanceof AffiliatePayoutMethod) {
                $bound = AffiliatePayoutMethod::query()->create([
                    'affiliate_id' => $recipient->getKey(),
                    'type' => $type->value,
                    'details' => $details,
                    'is_default' => false,
                    'verified_at' => $payee->verified_at,
                ]);
            }

            $bound->setAsDefault();
            $bound->refresh();

            // The installed processors serve the current default: assert
            // the enforced row is exactly the frozen binding.
            if (! $bound->is_default
                || ! $bound->type instanceof PayoutMethodType
                || $bound->type !== $type
            ) {
                throw new InvalidArgumentException('Frozen payout destination failed to bind; refusing the submission.');
            }

            $served = is_array($bound->details) ? $bound->details : [];
            $servedRef = $catalog->destinationRefFor((string) $row->processor, $served);
            $expectedRef = $catalog->destinationRefFor((string) $row->processor, $details);

            if (($served['payee_id'] ?? null) !== $frozenPayeeId || $servedRef !== $expectedRef) {
                throw new InvalidArgumentException('Frozen payout destination failed to bind; refusing the submission.');
            }

            $evidence = $row->evidence ?? [];
            $evidence['bound_method'] = [
                'id' => (string) $bound->getKey(),
                'type' => $type->value,
                'masked' => $bound->getMaskedDetails(),
            ];
            $row->forceFill(['evidence' => $evidence])->save();
        });
    }
}
