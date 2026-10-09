<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardAwardStatus;
use App\Models\RewardAward;
use App\Models\RewardAwardEntry;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Staff fraud dismissal: returns a held/pending award to held with a
 * fraud_cleared entry. Dismissal is never approval — a separate human
 * approval with reason/evidence is still required, and re-evaluation
 * rechecks fraud, source, terms, and budget under lock.
 */
final readonly class DismissRewardFraudAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        RewardAward|string $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence = null,
        ?string $sourceOperationId = null,
        ?CarbonInterface $decidedAt = null,
    ): RewardAward {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if (! in_array($actorRole, ['staff', 'admin'], true) || $actorType !== 'user') {
            throw new InvalidArgumentException('Fraud dismissal requires a staff/admin user identity.');
        }

        if ($sourceOperationId !== null && ($sourceOperationId === '' || mb_strlen($sourceOperationId) > 191)) {
            throw new InvalidArgumentException('Source operation id must be null or 1..191 characters.');
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $awardId = $award instanceof RewardAward ? (string) $award->getKey() : $award;

        return DB::transaction(function () use (
            $awardId, $actorType, $actorId, $actorRole, $reason, $evidence, $sourceOperationId, $decided,
        ): RewardAward {
            $locked = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAward) {
                throw new InvalidArgumentException('Unknown reward award.');
            }

            if (! in_array($locked->status, [RewardAwardStatus::Held, RewardAwardStatus::PendingReview], true)) {
                throw new InvalidArgumentException('Fraud can only be dismissed on held or pending-review awards.');
            }

            if ($sourceOperationId !== null && RewardAwardEntry::query()
                ->where('award_id', $locked->getKey())
                ->where('source_operation_id', $sourceOperationId)
                ->exists()
            ) {
                return $locked;
            }

            $from = $locked->status->value;

            if ($locked->status === RewardAwardStatus::PendingReview) {
                $locked->forceFill(['status' => RewardAwardStatus::Held])->save();
            }

            RewardLedgerEntries::appendAwardEntry(
                $locked, $from, RewardAwardStatus::Held->value,
                $actorType, $actorId, $actorRole,
                'fraud_cleared:'.$reason,
                ['fraud' => 'cleared', 'approval_still_required' => true, ...($evidence ?? [])],
                $sourceOperationId, $decided,
            );

            return $locked->refresh();
        });
    }
}
