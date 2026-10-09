<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardReservationResolver;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardMilestoneStatus;
use App\Models\RewardAward;
use App\Models\RewardMilestoneContribution;
use App\Models\RewardMilestoneCycle;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Milestone contributor correction: revokes one contribution (history is
 * retained, never deleted) and recomputes the cycle. If the cycle had
 * achieved and now falls below target, the milestone grant is reversed
 * consistently — award reversal plus badge revocation for badge grants —
 * through the same transition actions as any other reversal. Points-grant
 * reversals void only host-attested unsubmitted reservations and block on any
 * unknown hold, exactly like a direct reversal.
 */
final readonly class InvalidateRewardMilestoneContributionAction
{
    use AsAction;

    public function __construct(
        private ?RewardReservationResolver $reservationResolver = null,
    ) {}

    public function handle(
        RewardMilestoneContribution|string $contribution,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $revokedAt = null,
    ): RewardMilestoneCycle {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if (! in_array($actorRole, ['staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Milestone invalidation requires a staff/admin/system identity.');
        }

        $at = $revokedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($revokedAt)->setTimezone('UTC');

        $contributionId = $contribution instanceof RewardMilestoneContribution
            ? (string) $contribution->getKey()
            : $contribution;

        return DB::transaction(function () use ($contributionId, $reason, $at): RewardMilestoneCycle {
            $pre = RewardMilestoneContribution::query()->whereKey($contributionId)->first();

            if (! $pre instanceof RewardMilestoneContribution) {
                throw new InvalidArgumentException('Unknown milestone contribution.');
            }

            $cycle = RewardMilestoneCycle::query()->whereKey((string) $pre->cycle_id)->lockForUpdate()->first();

            if (! $cycle instanceof RewardMilestoneCycle) {
                throw new InvalidArgumentException('Milestone cycle is missing.');
            }

            $locked = RewardMilestoneContribution::query()->whereKey($contributionId)->lockForUpdate()->first();

            if (! $locked instanceof RewardMilestoneContribution) {
                throw new InvalidArgumentException('Unknown milestone contribution.');
            }

            if ($locked->revoked_at === null) {
                $locked->forceFill([
                    'revoked_at' => $at,
                    'revoke_reason' => $reason,
                ])->save();
            }

            // Plain query count: the cycle row lock held above already serializes
            // all contributions for this cycle, and PostgreSQL forbids FOR UPDATE
            // with aggregate functions.
            $count = RewardMilestoneContribution::query()
                ->where('cycle_id', $cycle->getKey())
                ->whereNull('revoked_at')
                ->count();

            $cycle->forceFill(['current_count' => $count])->save();

            if ($cycle->status === RewardMilestoneStatus::Achieved
                && $count < $cycle->target_count
                && $cycle->award_id !== null
            ) {
                $award = RewardAward::query()->whereKey($cycle->award_id)->lockForUpdate()->first();

                if ($award instanceof RewardAward
                    && in_array($award->status, [RewardAwardStatus::Approved, RewardAwardStatus::Fulfilled], true)
                ) {
                    (new TransitionRewardAwardAction(null, null, $this->reservationResolver))->handle(
                        (string) $award->getKey(),
                        RewardAwardStatus::Reversed,
                        'system',
                        null,
                        'system',
                        'milestone-contribution-revoked:'.$reason,
                        ['cycle_id' => (string) $cycle->getKey(), 'contribution_id' => (string) $locked->getKey()],
                        'milestone-invalidate:'.(string) $locked->getKey(),
                        $at,
                    );

                    if ($award->component === RewardComponent::Badge) {
                        $badgeKey = $award->amount_snapshot['badge_key'] ?? null;

                        if (is_string($badgeKey)) {
                            RevokeRewardBadgeAction::run(
                                (string) $award->recipient_type,
                                (string) $award->recipient_id,
                                $award->family_key,
                                $badgeKey,
                                'system',
                                null,
                                'system',
                                'milestone-contribution-revoked:'.$reason,
                                $at,
                            );
                        }
                    }
                }

                $cycle->forceFill([
                    'status' => RewardMilestoneStatus::Invalidated,
                    'invalidated_at' => $at,
                ])->save();

                CancelRewardDigestAction::cancelPendingForRecipient(
                    (string) $cycle->recipient_type, (string) $cycle->recipient_id,
                    'milestone-invalidated', $at,
                );
            }

            return $cycle->refresh();
        });
    }
}
