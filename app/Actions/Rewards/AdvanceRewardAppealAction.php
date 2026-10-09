<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardFinancialReadinessChecker;
use App\Contracts\Rewards\RewardSourceFactReader;
use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Models\RewardAppeal;
use App\Models\RewardAward;
use App\Support\Rewards\NullRewardSourceFactReader;
use App\Support\Rewards\RewardApprovalLocks;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Appeal review and decision.
 *
 * Reviewers are staff/admin identities distinct from both the opener and the
 * award recipient — caller own-approval is prohibited wherever identities
 * are supplied. Upheld leaves the award standing; overturned appends override
 * evidence and reopens a rejected award to approved through the same
 * qualifying authority as live evaluation (current source validity, claim
 * uniqueness, caps, beneficiary identity, fraud/correction, finance) — the
 * original entries stand, the override is appended, never rewritten.
 *
 * Lock order is awards before appeals, matching appeal opening: the award
 * row is locked first, then the appeal row. Overturns additionally take the
 * shared approval chain (coordinator -> program -> claims) BEFORE the award,
 * exactly like a direct approval, so the nested overturn transition can never
 * invert the protocol against a concurrent direct approval.
 */
final readonly class AdvanceRewardAppealAction
{
    use AsAction;

    public function __construct(
        private ?RewardSourceFactReader $sourceReader = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        RewardAppeal|string $appeal,
        RewardAppealStatus|string $to,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence = null,
        ?CarbonInterface $decidedAt = null,
    ): RewardAppeal {
        $toEnum = $to instanceof RewardAppealStatus ? $to : RewardAppealStatus::tryFrom($to);

        if (! $toEnum instanceof RewardAppealStatus) {
            throw new InvalidArgumentException('Unknown appeal status.');
        }

        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Appeal review requires a staff/admin user identity.');
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $appealId = $appeal instanceof RewardAppeal ? (string) $appeal->getKey() : $appeal;
        $reader = $this->sourceReader ?? new NullRewardSourceFactReader;

        return DB::transaction(function () use (
            $appealId, $toEnum, $actorType, $actorId, $actorRole, $reason, $evidence, $decided, $reader,
        ): RewardAppeal {
            $pre = RewardAppeal::query()->whereKey($appealId)->first();

            if (! $pre instanceof RewardAppeal) {
                throw new InvalidArgumentException('Unknown reward appeal.');
            }

            $awardPre = RewardAward::query()->whereKey((string) $pre->award_id)->first();

            if (! $awardPre instanceof RewardAward) {
                throw new InvalidArgumentException('Appealed award is missing.');
            }

            if ($toEnum === RewardAppealStatus::Overturned) {
                RewardApprovalLocks::lockCoordinatorChain($awardPre);
            }

            $award = RewardAward::query()->whereKey((string) $pre->award_id)->lockForUpdate()->first();

            if (! $award instanceof RewardAward) {
                throw new InvalidArgumentException('Appealed award is missing.');
            }

            $locked = RewardAppeal::query()->whereKey($appealId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAppeal) {
                throw new InvalidArgumentException('Unknown reward appeal.');
            }

            if ((string) $locked->award_id !== (string) $award->getKey()) {
                throw new InvalidArgumentException('Appeal award binding is incoherent.');
            }

            if ($locked->status === $toEnum) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($toEnum)) {
                throw new InvalidArgumentException(
                    "Invalid appeal transition [{$locked->status->value}] -> [{$toEnum->value}]."
                );
            }

            if ($actorId === (string) $locked->opened_by_id) {
                throw new InvalidArgumentException('The opener cannot decide their own appeal.');
            }

            if ($actorId === (string) $award->recipient_id) {
                throw new InvalidArgumentException('Caller own-approval is prohibited: the reviewer must not be the recipient.');
            }

            $column = $toEnum->transitionColumn();

            if ($locked->getAttribute($column) !== null) {
                throw new InvalidArgumentException("Appeal [{$column}] is already stamped; decisions never rewrite history.");
            }

            $fromStatus = $locked->status->value;

            $locked->forceFill([
                'status' => $toEnum,
                $column => $decided,
            ])->save();

            RewardLedgerEntries::appendAppealDecision(
                $locked,
                $fromStatus,
                $toEnum->value, $actorType, $actorId, $actorRole, $reason,
                $evidence, $decided,
            );

            if ($toEnum === RewardAppealStatus::Overturned && $award->status === RewardAwardStatus::Rejected) {
                // The overturned award faces the REAL financial gates: a
                // null checker would refuse every cash/benefit overturn.
                (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
                    (string) $award->getKey(),
                    RewardAwardStatus::Approved,
                    $actorType,
                    $actorId,
                    $actorRole,
                    'appeal-overturned:'.$reason,
                    ['appeal_id' => (string) $locked->getKey(), 'override_authority' => 'appeal-merit-override', ...($evidence ?? [])],
                    'appeal-overturn:'.(string) $locked->getKey(),
                    $decided,
                );
            }

            return $locked->refresh();
        });
    }
}
