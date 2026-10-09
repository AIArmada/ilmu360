<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBudgetMovementKind;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardPayeeStatus;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Models\RewardAward;
use App\Models\RewardBudgetMovement;
use App\Models\RewardPayee;
use App\Models\RewardPayoutRequest;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardPayoutProcessorCatalog;
use App\Support\Rewards\RewardPayoutTerms;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Request a cash payout for an approved award.
 *
 * Freezes the payee/provider/method/value/fee snapshot, mints the stable
 * operation identity (`reward-payout:{award_id}` + first reference), and
 * reserves the gross backing from confirmed funding. Fee responsibility
 * is explicit (program|recipient). Program-paid means the member receives
 * the awarded amount to the minor unit: the backing is grossed up
 * against the LIVE installed fee schedule so gross minus provider fee
 * equals the promised net exactly, and the gross is what gets reserved,
 * backed, and spent. Recipient-paid means the installed fee is deducted
 * from the award, and the request is refused when the resulting net is
 * not positive. The payee's destination ref is frozen into the snapshot
 * after validation by the installed processor's own validateDetails
 * contract. A re-request after confirmed provider failure reuses the
 * SAME operation identity with a fresh reservation attempt — ambiguity
 * never becomes a new operation.
 */
final readonly class RequestRewardPayoutAction
{
    use AsAction;

    public const array FEE_PAYERS = ['program', 'recipient'];

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        RewardAward|string $award,
        RewardPayee|string $payee,
        string $processor,
        string $feePayer,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?array $evidence = null,
        ?CarbonInterface $decidedAt = null,
    ): RewardPayoutRequest {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Payout requests require a staff/admin user identity.');
        }

        if (! in_array($feePayer, self::FEE_PAYERS, true)) {
            throw new InvalidArgumentException('Fee payer must be explicit: program or recipient.');
        }

        $processor = mb_trim($processor);
        $catalog = app(RewardPayoutProcessorCatalog::class);

        if (! $catalog->isRegistered($processor)) {
            throw new InvalidArgumentException("Payout processor [{$processor}] is not registered.");
        }

        if (! $catalog->isSupported($processor)) {
            throw new InvalidArgumentException(
                'Payout processor ['.$processor.'] is not supported: missing '.implode(', ', $catalog->missingCredentials($processor))
            );
        }

        $awardId = $award instanceof RewardAward ? (string) $award->getKey() : $award;
        $payeeId = $payee instanceof RewardPayee ? (string) $payee->getKey() : $payee;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use (
            $awardId, $payeeId, $processor, $feePayer,
            $actorType, $actorId, $actorRole, $evidence, $decided,
        ): RewardPayoutRequest {
            $locked = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAward) {
                throw new InvalidArgumentException('Unknown reward award.');
            }

            if ($locked->component !== RewardComponent::Cash) {
                throw new InvalidArgumentException('Payout requests apply to cash awards only.');
            }

            if ($locked->status !== RewardAwardStatus::Approved) {
                throw new InvalidArgumentException('Payout requests require an approved award.');
            }

            if ($actorId === (string) $locked->recipient_id) {
                throw new InvalidArgumentException('Recipient-owned payout requests are prohibited.');
            }

            $amount = $locked->amount_snapshot;
            $minor = $amount['minor_units'] ?? null;
            $currency = $amount['currency'] ?? null;

            if (! is_int($minor) || $minor <= 0 || ! is_string($currency)) {
                throw new InvalidArgumentException('Cash award amount snapshot is missing minor_units/currency.');
            }

            $currency = RewardBudgetLedger::normalizeCurrency($currency);

            $payeeRow = RewardPayee::query()->whereKey($payeeId)->lockForUpdate()->first();

            if (! $payeeRow instanceof RewardPayee) {
                throw new InvalidArgumentException('Unknown reward payee.');
            }

            if ($payeeRow->status !== RewardPayeeStatus::Verified) {
                throw new InvalidArgumentException('Payout requests require a verified payee.');
            }

            if ((string) $payeeRow->recipient_type !== (string) $locked->recipient_type
                || (string) $payeeRow->recipient_id !== (string) $locked->recipient_id
            ) {
                throw new InvalidArgumentException('Payee recipient must match the award recipient.');
            }

            if ((string) $payeeRow->method !== $processor) {
                throw new InvalidArgumentException('Payee method must match the payout processor.');
            }

            // The frozen destination is validated by the installed
            // processor's own contract — a payee that can never be paid
            // refuses here even if it predates registration-time checks.
            $destination = RewardPayoutTerms::detailsFor($processor, (string) $payeeRow->account_ref);
            RewardPayoutTerms::assertValidDestination($processor, $destination);

            $installed = app(PayoutProcessorFactory::class)->make($processor);

            if ($feePayer === 'program') {
                $terms = RewardPayoutTerms::grossForNet($installed, $minor, $currency);
                $fee = $terms['fee_minor'];
                $reserveTotal = $terms['gross_minor'];
            } else {
                $fee = $installed->getFees($minor, $currency);

                if ($fee < 0) {
                    throw new InvalidArgumentException('Processor fee must be non-negative.');
                }

                if ($minor - $fee <= 0) {
                    throw new InvalidArgumentException('Recipient-paid fee leaves no positive net; refusing the payout.');
                }

                $reserveTotal = $minor;
            }

            $existing = RewardPayoutRequest::query()->where('award_id', $locked->getKey())->lockForUpdate()->first();

            if ($existing instanceof RewardPayoutRequest) {
                if ($existing->status !== RewardPayoutRequestStatus::Failed) {
                    return $existing;
                }

                return $this->requeue($existing, $fee, $feePayer, $reserveTotal, $decided, $actorType, $actorId, $actorRole);
            }

            $programId = (string) $locked->program_id;
            $funded = RewardBudgetLedger::lockFunding($programId, $currency);
            $balances = RewardBudgetLedger::lockedBalances($programId, $currency, $funded);

            // Attempt 1 converts the held obligation into the payout
            // reserve: the obligation's reserve is released first and the
            // availability gate credits exactly what this call retired, so
            // the gate prices only the incremental gross (fees), never the
            // award twice.
            $retired = $this->retireHeldObligation(
                $locked, $programId, $currency, $actorType, $actorId, $actorRole, $decided,
            );

            if ($reserveTotal > $balances['available'] + $retired) {
                throw new InvalidArgumentException('Confirmed funding does not cover the payout plus program-paid fees.');
            }

            RewardBudgetLedger::append(
                RewardBudgetMovementKind::Reserve, "reserve:{$awardId}:attempt:1",
                $programId, $currency, $reserveTotal, $awardId,
                $actorType, $actorId, $actorRole, $decided,
            );

            RewardBudgetLedger::assertNonNegativeLegs(
                RewardBudgetLedger::lockedBalances(
                    $programId, $currency,
                    RewardBudgetLedger::lockFunding($programId, $currency)
                )
            );

            return RewardPayoutRequest::query()->create([
                'award_id' => $locked->getKey(),
                'payee_id' => $payeeRow->getKey(),
                'program_id' => $locked->program_id,
                'currency' => $currency,
                'amount_minor' => $minor,
                'fee_minor' => $fee,
                'fee_payer' => $feePayer,
                'processor' => $processor,
                'operation_key' => "reward-payout:{$awardId}",
                'first_reference' => self::firstReference($awardId),
                'status' => RewardPayoutRequestStatus::Requested,
                'payee_snapshot' => [
                    'payee_id' => (string) $payeeRow->getKey(),
                    'method' => (string) $payeeRow->method,
                    'account_ref' => (string) $payeeRow->account_ref,
                    'account_display' => (string) $payeeRow->account_display,
                    'verified_by' => (string) $payeeRow->verified_by,
                    'verified_at' => $payeeRow->verified_at?->toIso8601String(),
                    'processor' => $processor,
                    'fee_minor' => $fee,
                    'fee_payer' => $feePayer,
                    'amount_minor' => $minor,
                    'gross_minor' => $reserveTotal,
                    'currency' => $currency,
                ],
                'evidence' => $evidence,
                'requested_by_type' => $actorType,
                'requested_by_id' => $actorId,
                'requested_by_role' => $actorRole,
                'requested_at' => $decided,
            ]);
        });
    }

    /**
     * Releases the award's held obligation into the attempt-1 payout
     * reserve. Returns the minor units freed by THIS call (0 when the
     * award carries no obligation, or a rejection already retired it —
     * the caller then prices the full gross against current available).
     * The award and funding rows are already locked by the caller, so
     * the existence check and the append cannot interleave.
     */
    private function retireHeldObligation(
        RewardAward $locked,
        string $programId,
        string $currency,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): int {
        $epoch = $locked->obligation_epoch;
        $minor = $locked->obligated_minor;

        if (! is_int($epoch) || ! is_int($minor) || $minor <= 0) {
            return 0;
        }

        $releaseKey = RewardBudgetLedger::releaseObligateKey((string) $locked->getKey(), $epoch);

        $already = RewardBudgetMovement::query()->where('movement_key', $releaseKey)->lockForUpdate()->first();

        if ($already instanceof RewardBudgetMovement) {
            if ($already->kind !== RewardBudgetMovementKind::Release
                || (string) $already->program_id !== $programId
                || mb_strtoupper((string) $already->currency) !== $currency
                || (int) $already->amount_minor !== $minor
                || (string) ($already->award_id ?? '') !== (string) $locked->getKey()
            ) {
                throw new InvalidArgumentException('Budget movement key is already bound to different terms; refusing the payout.');
            }

            return 0;
        }

        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Release, $releaseKey,
            $programId, $currency, $minor, (string) $locked->getKey(),
            $actorType, $actorId, $actorRole, $decided,
        );

        return $minor;
    }

    public static function firstReference(string $awardId): string
    {
        return 'RWP-'.mb_strtoupper(mb_substr(hash('sha256', 'reward-payout:'.$awardId), 0, 12));
    }

    private function requeue(
        RewardPayoutRequest $existing,
        int $fee,
        string $feePayer,
        int $reserveTotal,
        CarbonImmutable $decided,
        string $actorType,
        ?string $actorId,
        string $actorRole,
    ): RewardPayoutRequest {
        $attempt = ((int) ($existing->evidence['attempt'] ?? 1)) + 1;
        $programId = (string) $existing->program_id;
        $currency = (string) $existing->currency;

        $funded = RewardBudgetLedger::lockFunding($programId, $currency);
        $balances = RewardBudgetLedger::lockedBalances($programId, $currency, $funded);

        if ($balances['available'] < $reserveTotal) {
            throw new InvalidArgumentException('Confirmed funding does not cover the requeued payout.');
        }

        if ($feePayer !== (string) $existing->fee_payer || $fee !== (int) $existing->fee_minor) {
            throw new InvalidArgumentException('Requeue must reuse the frozen fee terms.');
        }

        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Reserve, "reserve:{$existing->award_id}:attempt:{$attempt}",
            $programId, $currency, $reserveTotal, (string) $existing->award_id,
            $actorType, $actorId, $actorRole, $decided,
        );

        RewardBudgetLedger::assertNonNegativeLegs(
            RewardBudgetLedger::lockedBalances(
                $programId, $currency,
                RewardBudgetLedger::lockFunding($programId, $currency)
            )
        );

        $evidence = $existing->evidence ?? [];
        $evidence['attempt'] = $attempt;
        $evidence['requeued_at'] = $decided->toIso8601String();

        // The failed attempt's payout is terminal; the retry binds a fresh
        // one under its own reference. The released backing conversion
        // stays bound and re-links into the new payout. The prior payout
        // id chains into evidence so the attempt history is complete.
        if ($existing->affiliate_payout_id !== null) {
            $priors = $evidence['prior_payouts'] ?? [];
            $priors[] = [
                'id' => (string) $existing->affiliate_payout_id,
                'attempt' => $attempt - 1,
            ];
            $evidence['prior_payouts'] = $priors;
        }

        $existing->forceFill([
            'status' => RewardPayoutRequestStatus::Requested,
            'affiliate_payout_id' => null,
            'failure_code' => null,
            'evidence' => $evidence,
        ])->save();

        return $existing->refresh();
    }
}
