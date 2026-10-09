<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateAward;
use AIArmada\Affiliates\Actions\Payouts\CreatePayout;
use AIArmada\Affiliates\Actions\Payouts\ProcessAffiliatePayout;
use AIArmada\Affiliates\Data\PayoutResult;
use AIArmada\Affiliates\Enums\PayoutMethodType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\Models\AffiliatePayoutMethod;
use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use AIArmada\Affiliates\States\ApprovedConversion;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBudgetMovementKind;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Models\RewardAward;
use App\Models\RewardPayee;
use App\Models\RewardPayoutRequest;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardPayoutTerms;
use App\Support\Rewards\RewardRecipientAffiliateResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Submit an approved payout through the real payout rail.
 *
 * Pipeline: verify the frozen fee still matches the live installed
 * schedule, post the exact gross backing conversion via accepted
 * `RecordAffiliateAward` (authorized behind `RewardCashAwardPolicy`),
 * create the affiliate payout from those explicit backing IDs only via
 * `CreatePayout`, then — under a per-affiliate send mutex — bind the
 * FROZEN destination method, enforce it as the serving default, and
 * drive `ProcessAffiliatePayout` OUTSIDE any transaction. The installed
 * processors read the affiliate's current default method, so the mutex
 * plus adjacent binding is what makes the served destination exactly
 * the frozen one; a later payee change affects future work only.
 * Outcomes map honestly: completed → confirmed (+spend), pending →
 * submitted (manual bank awaits independent confirmation), unknown →
 * unknown (reservation kept, reconcile later), confirmed failure →
 * failed (+release). Every step resumes from durable rows, so a crash
 * replays without duplicating money.
 */
final readonly class SubmitRewardPayoutAction
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
            throw new InvalidArgumentException('Payout submission requires a staff/admin user identity.');
        }

        $requestId = $request instanceof RewardPayoutRequest ? (string) $request->getKey() : $request;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        // Unlocked pre-read for the award id; every lock below is
        // re-verified after acquisition in award -> request order.
        $pre = RewardPayoutRequest::query()->whereKey($requestId)->first();

        if (! $pre instanceof RewardPayoutRequest) {
            throw new InvalidArgumentException('Unknown reward payout request.');
        }

        $awardId = (string) $pre->award_id;

        $locked = DB::transaction(function () use ($requestId, $awardId): RewardPayoutRequest {
            $award = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $award instanceof RewardAward || $award->status !== RewardAwardStatus::Approved) {
                throw new InvalidArgumentException('Payout submission requires an approved award.');
            }

            $row = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if (! in_array($row->status, [RewardPayoutRequestStatus::Approved, RewardPayoutRequestStatus::Unknown], true)) {
                throw new InvalidArgumentException('Only approved or unknown payouts can be submitted.');
            }

            return $row;
        });

        $award = RewardAward::query()->whereKey((string) $locked->award_id)->firstOrFail();
        $recipient = RewardRecipientAffiliateResolver::resolve(
            (string) $award->recipient_type, (string) $award->recipient_id,
        );

        // Gross backing from the FROZEN terms: program-pays-fees backs
        // amount + fee so the installed provider deduction leaves the
        // promised net exactly; recipient-pays backs the award itself.
        $gross = (int) $locked->amount_minor
            + ((string) $locked->fee_payer === 'program' ? (int) $locked->fee_minor : 0);

        $this->assertLiveFeeMatchesFrozen($locked, $gross);

        if ($locked->backing_conversion_id === null) {
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
                $row->forceFill(['backing_conversion_id' => $conversionId])->save();
            });

            $locked = $locked->refresh();
        }

        if ($locked->affiliate_payout_id === null) {
            $payout = $this->resolveOrCreatePayout($locked);

            DB::transaction(function () use ($requestId, $payout): void {
                $row = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
                $row->forceFill(['affiliate_payout_id' => (string) $payout->getKey()])->save();
            });

            $locked = $locked->refresh();
        }

        $payout = AffiliatePayout::query()->whereKey((string) $locked->affiliate_payout_id)->firstOrFail();

        // One serving default per affiliate at a time: the binding, the
        // provider call, and the result recording are mutually exclusive
        // across concurrent submits so a racing submit can never divert
        // this operation to another payee's destination. This action is
        // the sole writer of affiliate payout methods.
        try {
            return Cache::lock('reward-payout-send:'.$recipient->getKey(), 30)->block(10, function () use (
                $requestId, $payout, $recipient, $actorType, $actorId, $actorRole, $decided,
            ): RewardPayoutRequest {
                $this->bindFrozenDestination($requestId, $recipient);

                // Provider call outside every transaction via the durable
                // operation. The mutex (not a transaction) spans it.
                $outcome = app(ProcessAffiliatePayout::class)->handle($payout);

                return $this->recordOutcome($requestId, $outcome, $actorType, $actorId, $actorRole, $decided);
            });
        } catch (LockTimeoutException) {
            throw new InvalidArgumentException('Another payout send for this recipient is in flight; retry the submission.');
        }
    }

    private function recordOutcome(
        string $requestId,
        PayoutResult $outcome,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): RewardPayoutRequest {
        return DB::transaction(function () use ($requestId, $outcome, $actorType, $actorId, $actorRole, $decided): RewardPayoutRequest {
            $pre = RewardPayoutRequest::query()->whereKey($requestId)->firstOrFail();

            // Award -> request -> funding: the shared cross-domain order,
            // so spend/release appends serialize with every other funder.
            RewardAward::query()->whereKey((string) $pre->award_id)->lockForUpdate()->firstOrFail();

            $row = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

            RewardBudgetLedger::lockFunding((string) $row->program_id, (string) $row->currency);

            $status = $outcome->getStatus();
            $reference = $outcome->externalReference;

            if ($status === 'completed') {
                $this->appendSpend($row, $actorType, $actorId, $actorRole, $decided);

                if ($row->status === RewardPayoutRequestStatus::Approved) {
                    // An immediately-completed send still passes through
                    // submitted: the machine forbids skipping it, and the
                    // submitted instant is real provider evidence.
                    $row->forceFill([
                        'status' => RewardPayoutRequestStatus::Submitted,
                        'provider_reference' => $reference ?? $row->provider_reference,
                        'failure_code' => null,
                        'submitted_at' => $decided,
                    ])->save();
                }

                $row->forceFill([
                    'status' => RewardPayoutRequestStatus::Confirmed,
                    'provider_reference' => $reference ?? $row->provider_reference,
                    'failure_code' => null,
                    'confirmed_by' => $actorId,
                    'submitted_at' => $row->submitted_at ?? $decided,
                    'confirmed_at' => $decided,
                ])->save();

                return $row->refresh();
            }

            if ($outcome->isPending()) {
                $fill = [
                    'status' => RewardPayoutRequestStatus::Submitted,
                    'provider_reference' => $reference ?? $row->provider_reference,
                    'failure_code' => null,
                    'submitted_at' => $row->submitted_at ?? $decided,
                ];

                if ($row->submitted_at !== null) {
                    // A later attempt re-submitting: the first stamp
                    // stands; the retry chains into evidence.
                    $evidence = $row->evidence ?? [];
                    $resubmits = $evidence['resubmits'] ?? [];
                    $resubmits[] = [
                        'at' => $decided->toIso8601String(),
                        'attempt' => RewardBudgetLedger::attemptOf($row->evidence),
                        'reference' => $reference ?? $row->provider_reference,
                    ];
                    $evidence['resubmits'] = $resubmits;
                    $fill['evidence'] = $evidence;
                }

                $row->forceFill($fill)->save();

                return $row->refresh();
            }

            if ($outcome->isUnknown()) {
                // Uncertain stays uncertain: keep the reservation and the
                // original operation; reconcile and accept late completion.
                $row->forceFill([
                    'status' => RewardPayoutRequestStatus::Unknown,
                    'provider_reference' => $reference ?? $row->provider_reference,
                    'failure_code' => $outcome->failureCode,
                ])->save();

                return $row->refresh();
            }

            $this->appendRelease($row, $actorType, $actorId, $actorRole, $decided);

            $attempt = RewardBudgetLedger::attemptOf($row->evidence);
            $fill = [
                'status' => RewardPayoutRequestStatus::Failed,
                'failure_code' => $outcome->failureCode,
            ];

            if ($attempt <= 1) {
                $fill['failed_at'] = $decided;
            } else {
                // The first failure stamp stands; later attempts chain.
                $evidence = $row->evidence ?? [];
                $refailures = $evidence['refailures'] ?? [];
                $refailures[] = [
                    'at' => $decided->toIso8601String(),
                    'attempt' => $attempt,
                    'code' => $outcome->failureCode,
                ];
                $evidence['refailures'] = $refailures;
                $fill['evidence'] = $evidence;
            }

            $row->forceFill($fill)->save();

            return $row->refresh();
        });
    }

    /**
     * Stable payout reference for this reservation attempt. The frozen
     * first reference is never regenerated; retries after a confirmed
     * failure are distinct provider operations, so attempts past the
     * first derive a distinct reference instead of colliding on the
     * unique payout reference.
     */
    public static function payoutReferenceFor(RewardPayoutRequest $request): string
    {
        $attempt = RewardBudgetLedger::attemptOf($request->evidence);

        return $attempt <= 1
            ? (string) $request->first_reference
            : (string) $request->first_reference.'-A'.$attempt;
    }

    /**
     * Binds the attempt's payout, recovering the durable row when a crash
     * (or a racing submit) created it before the pointer was stored. The
     * backing conversion row is the mutex: locked first, it either
     * already points at this attempt's payout or is still unlinked and
     * safe to claim. A recovered row is verified against the frozen
     * terms — a stranger is refused, never bound.
     */
    private function resolveOrCreatePayout(RewardPayoutRequest $request): AffiliatePayout
    {
        $conversionId = (string) $request->backing_conversion_id;
        $reference = self::payoutReferenceFor($request);

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
     * The frozen fee must still match the live installed schedule for the
     * frozen backing: a drifted schedule refuses loudly instead of
     * silently underpaying the promised net. Pure read, no HTTP.
     */
    private function assertLiveFeeMatchesFrozen(RewardPayoutRequest $request, int $gross): void
    {
        $live = app(PayoutProcessorFactory::class)
            ->make((string) $request->processor)
            ->getFees($gross, (string) $request->currency);

        if ($live !== (int) $request->fee_minor) {
            throw new InvalidArgumentException(
                'Installed fee schedule drifted from the frozen payout terms; refusing the submission.'
            );
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

            $snapshot = $row->payee_snapshot ?? [];
            $frozenPayeeId = (string) ($snapshot['payee_id'] ?? '');
            $frozenRef = (string) ($snapshot['account_ref'] ?? '');
            $operationKey = (string) $row->operation_key;

            if ($frozenPayeeId === '' || $frozenPayeeId !== (string) $row->payee_id) {
                throw new InvalidArgumentException('Payout snapshot drifted from frozen terms; refusing the submission.');
            }

            $payee = RewardPayee::query()->whereKey((string) $row->payee_id)->firstOrFail();

            $details = RewardPayoutTerms::detailsFor((string) $row->processor, $frozenRef, [
                'payee_id' => $frozenPayeeId,
                'account_display' => (string) ($snapshot['account_display'] ?? ''),
                'operation_key' => $operationKey,
            ]);

            // Defense in depth: the frozen destination must STILL satisfy
            // the installed validation contract at bind time.
            RewardPayoutTerms::assertValidDestination((string) $row->processor, $details);

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
                    && RewardPayoutTerms::destinationRefFor((string) $row->processor, $stored)
                        === RewardPayoutTerms::destinationRefFor((string) $row->processor, $details)
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
            $servedRef = RewardPayoutTerms::destinationRefFor((string) $row->processor, $served);
            $expectedRef = RewardPayoutTerms::destinationRefFor((string) $row->processor, $details);

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

    private function appendSpend(
        RewardPayoutRequest $row,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): void {
        $total = (int) $row->amount_minor + ((string) $row->fee_payer === 'program' ? (int) $row->fee_minor : 0);

        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Spend, "spend:{$row->award_id}",
            (string) $row->program_id, (string) $row->currency, $total, (string) $row->award_id,
            $actorType, $actorId, $actorRole, $decided,
        );

        // Spending consumes the reservation; the funded invariant counts
        // reserved and net-spent separately, so both movements append.
        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Release, "release:{$row->award_id}:spent",
            (string) $row->program_id, (string) $row->currency, $total, (string) $row->award_id,
            $actorType, $actorId, $actorRole, $decided,
        );

        RewardBudgetLedger::assertNonNegativeLegs(
            RewardBudgetLedger::lockedBalances(
                (string) $row->program_id, (string) $row->currency,
                RewardBudgetLedger::lockFunding((string) $row->program_id, (string) $row->currency)
            )
        );
    }

    private function appendRelease(
        RewardPayoutRequest $row,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): void {
        $total = (int) $row->amount_minor + ((string) $row->fee_payer === 'program' ? (int) $row->fee_minor : 0);

        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Release,
            RewardBudgetLedger::releaseKeyForAttempt(
                (string) $row->award_id, RewardBudgetLedger::attemptOf($row->evidence), 'failure'
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
    }
}
