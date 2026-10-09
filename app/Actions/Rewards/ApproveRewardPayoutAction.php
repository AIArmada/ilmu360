<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Models\RewardAward;
use App\Models\RewardPayoutRequest;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Independent finance approval of a payout request.
 *
 * The approver must be a staff/admin user who is neither the recipient nor
 * the requester: no own recipient/payee-owned payout authorization. The
 * frozen payee/provider/method/value/fee snapshot is re-verified, never
 * edited; approval only stamps the independent decision.
 *
 * Re-approval after a requeue keeps the FIRST approved_at immutable and
 * appends the new decision to evidence[reapprovals]: the retry proceeds
 * under a fresh independent approval without rewriting history.
 */
final readonly class ApproveRewardPayoutAction
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
            throw new InvalidArgumentException('Payout approval requires a staff/admin user identity.');
        }

        $requestId = $request instanceof RewardPayoutRequest ? (string) $request->getKey() : $request;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($requestId, $actorId, $decided): RewardPayoutRequest {
            $locked = RewardPayoutRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if (! $locked instanceof RewardPayoutRequest) {
                throw new InvalidArgumentException('Unknown reward payout request.');
            }

            if ($locked->status !== RewardPayoutRequestStatus::Requested) {
                throw new InvalidArgumentException('Only requested payouts can be approved.');
            }

            $award = RewardAward::query()->whereKey((string) $locked->award_id)->lockForUpdate()->first();

            if (! $award instanceof RewardAward) {
                throw new InvalidArgumentException('Unknown reward award.');
            }

            if ($actorId === (string) $award->recipient_id) {
                throw new InvalidArgumentException('Recipient-owned payout approval is prohibited.');
            }

            if ($actorId === (string) $locked->requested_by_id) {
                throw new InvalidArgumentException('Payout approval requires an approver independent of the requester.');
            }

            $snapshot = $locked->payee_snapshot;

            if (($snapshot['amount_minor'] ?? null) !== (int) $locked->amount_minor
                || ($snapshot['currency'] ?? null) !== (string) $locked->currency
                || ($snapshot['payee_id'] ?? null) !== (string) $locked->payee_id
                || ($snapshot['processor'] ?? null) !== (string) $locked->processor
                || ($snapshot['fee_minor'] ?? null) !== (int) $locked->fee_minor
                || ($snapshot['fee_payer'] ?? null) !== (string) $locked->fee_payer
                || ! is_string($snapshot['account_ref'] ?? null)
                || ($snapshot['account_ref'] ?? null) === ''
            ) {
                throw new InvalidArgumentException('Payout snapshot drifted from frozen terms; refusing approval.');
            }

            $attempt = RewardBudgetLedger::attemptOf($locked->evidence);

            if ($attempt <= 1) {
                $locked->forceFill([
                    'status' => RewardPayoutRequestStatus::Approved,
                    'approved_by' => $actorId,
                    'approved_at' => $decided,
                ])->save();
            } else {
                $evidence = $locked->evidence ?? [];
                $reapprovals = $evidence['reapprovals'] ?? [];
                $reapprovals[] = ['by' => $actorId, 'at' => $decided->toIso8601String(), 'attempt' => $attempt];
                $evidence['reapprovals'] = $reapprovals;

                $locked->forceFill([
                    'status' => RewardPayoutRequestStatus::Approved,
                    'approved_by' => $actorId,
                    'evidence' => $evidence,
                ])->save();
            }

            return $locked->refresh();
        });
    }
}
