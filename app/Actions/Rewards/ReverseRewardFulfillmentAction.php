<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Enums\Rewards\RewardRecoveryKind;
use App\Models\RewardAward;
use App\Models\RewardBenefitGrant;
use App\Models\RewardPayoutRequest;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Post-fulfillment reversal connector for cash and benefit awards.
 *
 * Open payout operations cancel or reconcile BEFORE reversal: requested,
 * approved, submitted, and unknown requests refuse — the operator drives
 * them terminal through the payout actions first. Cancelled/failed
 * requests (reservation already released) reverse plainly. Confirmed
 * payouts open a receivable recovery and then reverse; the original
 * fulfillment stays immutable and no money is pretended back.
 *
 * Benefit grants must be terminal (revoked/expired/consumed) before
 * reversal: reserved/issued grants refuse so the operator cancels,
 * revokes-unused, or confirms use first. Consumed grants open a dispute
 * recovery per grant and then reverse. Points/recognition/badge awards
 * delegate straight to the award transition (point settlement already
 * handles clawback, expiry evidence, and consumed-value disputes).
 *
 * @return array{award: RewardAward, recovery_ids: list<string>}
 */
final readonly class ReverseRewardFulfillmentAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>|null  $evidence
     * @return array{award: RewardAward, recovery_ids: list<string>}
     */
    public function handle(
        RewardAward|string $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence = null,
        ?CarbonInterface $decidedAt = null,
    ): array {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if (! in_array($actorRole, ['staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Fulfillment reversal requires a staff/admin/system identity.');
        }

        $awardId = $award instanceof RewardAward ? (string) $award->getKey() : $award;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use (
            $awardId, $actorType, $actorId, $actorRole, $reason, $evidence, $decided,
        ): array {
            $locked = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAward) {
                throw new InvalidArgumentException('Unknown reward award.');
            }

            if ($locked->status !== RewardAwardStatus::Fulfilled) {
                throw new InvalidArgumentException('Fulfillment reversal requires a fulfilled award.');
            }

            $recoveryIds = [];

            if ($locked->component === RewardComponent::Cash) {
                $recoveryIds = $this->prepareCashReversal($locked, $actorType, $actorId, $actorRole, $reason, $decided);
            }

            if ($locked->component === RewardComponent::Benefit) {
                $recoveryIds = $this->prepareBenefitReversal($locked, $actorType, $actorId, $actorRole, $reason, $decided);
            }

            $transitionEvidence = array_merge($evidence ?? [], ['reversal_prepared' => true], $recoveryIds === [] ? [] : ['recovery_ids' => $recoveryIds]);

            $reversed = app(TransitionRewardAwardAction::class)->handle(
                $locked, RewardAwardStatus::Reversed, $actorType, $actorId, $actorRole, $reason,
                $transitionEvidence === [] ? null : $transitionEvidence, null, $decided,
            );

            return ['award' => $reversed, 'recovery_ids' => $recoveryIds];
        });
    }

    /**
     * @return list<string>
     */
    private function prepareCashReversal(
        RewardAward $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        CarbonImmutable $decided,
    ): array {
        $request = RewardPayoutRequest::query()
            ->where('award_id', $award->getKey())
            ->lockForUpdate()
            ->first();

        if (! $request instanceof RewardPayoutRequest) {
            throw new InvalidArgumentException('Cash reversal requires the payout request; refusing blind reversal.');
        }

        if (in_array($request->status, [
            RewardPayoutRequestStatus::Requested,
            RewardPayoutRequestStatus::Approved,
            RewardPayoutRequestStatus::Submitted,
            RewardPayoutRequestStatus::Unknown,
        ], true)) {
            throw new InvalidArgumentException(
                'Cash reversal refuses while the payout is ['.$request->status->value.']; cancel or reconcile it first.'
            );
        }

        if (in_array($request->status, [
            RewardPayoutRequestStatus::Cancelled,
            RewardPayoutRequestStatus::Failed,
        ], true)) {
            return [];
        }

        $recovery = OpenRewardRecoveryAction::run(
            $award, RewardRecoveryKind::Receivable, $request, null,
            (int) $request->amount_minor, (string) $request->currency, null, null,
            $actorType, $actorId, $actorRole, $reason,
            ['source' => 'post-payment-reversal'], $decided,
        );

        return [(string) $recovery->getKey()];
    }

    /**
     * @return list<string>
     */
    private function prepareBenefitReversal(
        RewardAward $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        CarbonImmutable $decided,
    ): array {
        $grants = RewardBenefitGrant::query()
            ->where('award_id', $award->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $recoveryIds = [];

        foreach ($grants as $grant) {
            if (in_array($grant->status, [
                RewardBenefitGrantStatus::Reserved,
                RewardBenefitGrantStatus::Issued,
            ], true)) {
                throw new InvalidArgumentException(
                    'Benefit reversal refuses while grant ['.(string) $grant->grant_key.'] is ['
                    .$grant->status->value.']; cancel, revoke-unused, or confirm use first.'
                );
            }

            if ($grant->status !== RewardBenefitGrantStatus::Consumed) {
                continue;
            }

            $item = $grant->item()->firstOrFail();

            $recovery = OpenRewardRecoveryAction::run(
                $award, RewardRecoveryKind::Dispute, null, $grant,
                null, null, null, null,
                $actorType, $actorId, $actorRole, $reason,
                ['source' => 'consumed-benefit-reversal', 'benefit_key' => (string) $item->benefit_key],
                $decided,
            );

            $recoveryIds[] = (string) $recovery->getKey();
        }

        return $recoveryIds;
    }
}
