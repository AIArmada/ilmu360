<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardAppealStatus;
use App\Models\RewardAppeal;
use App\Models\RewardAward;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Appeal opening inside the appeal window.
 *
 * Opened by the recipient (member) or by staff on their behalf, within
 * windowDays of the award's latest decision. Only one open/in-review appeal
 * runs per award at a time; the opening freezes reason/evidence/window and
 * appends the first decision row. The original award history is untouched.
 */
final readonly class OpenRewardAppealAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        RewardAward|string $award,
        string $openedByType,
        string $openedById,
        string $openedByRole,
        string $reason,
        ?array $evidence = null,
        ?CarbonInterface $openedAt = null,
        int $windowDays = 30,
    ): RewardAppeal {
        RewardLedgerEntries::assertActor($openedByType, $openedById, $openedByRole);
        RewardLedgerEntries::assertReason($reason);

        if (! in_array($openedByRole, ['member', 'staff'], true)) {
            throw new InvalidArgumentException('Appeals are opened by the member or by staff on their behalf.');
        }

        if ($windowDays < 1 || $windowDays > 365) {
            throw new InvalidArgumentException('Appeal window_days must be 1..365.');
        }

        $at = $openedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($openedAt)->setTimezone('UTC');

        $awardId = $award instanceof RewardAward ? (string) $award->getKey() : $award;

        return DB::transaction(function () use (
            $awardId, $openedByType, $openedById, $openedByRole, $reason, $evidence, $at, $windowDays,
        ): RewardAppeal {
            $locked = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAward) {
                throw new InvalidArgumentException('Unknown reward award.');
            }

            if ($openedByRole === 'member' && $openedById !== (string) $locked->recipient_id) {
                throw new InvalidArgumentException('Members can only appeal their own awards.');
            }

            $latest = $this->latestDecision($locked);

            if ($latest === null) {
                throw new InvalidArgumentException('Award carries no decision to appeal.');
            }

            $deadline = $latest->addDays($windowDays);

            if ($at->greaterThan($deadline)) {
                throw new InvalidArgumentException('Appeal window has closed; opened after the deadline.');
            }

            $active = RewardAppeal::query()
                ->where('award_id', $locked->getKey())
                ->whereIn('status', [RewardAppealStatus::Open, RewardAppealStatus::InReview])
                ->lockForUpdate()
                ->exists();

            if ($active) {
                throw new InvalidArgumentException('An open appeal already runs for this award.');
            }

            $seq = (int) (RewardAppeal::query()
                ->where('award_id', $locked->getKey())
                ->orderByDesc('seq')
                ->lockForUpdate()
                ->value('seq') ?? 0) + 1;

            $appeal = RewardAppeal::query()->create([
                'award_id' => $locked->getKey(),
                'seq' => $seq,
                'status' => RewardAppealStatus::Open,
                'opened_by_type' => $openedByType,
                'opened_by_id' => $openedById,
                'opened_by_role' => $openedByRole,
                'reason' => $reason,
                'evidence' => $evidence,
                'window_ends_at' => $deadline,
                'opened_at' => $at,
                'in_review_at' => null,
                'upheld_at' => null,
                'overturned_at' => null,
                'closed_at' => null,
            ]);

            RewardLedgerEntries::appendAppealDecision(
                $appeal, 'none', RewardAppealStatus::Open->value,
                $openedByType, $openedById, $openedByRole, $reason, $evidence, $at,
            );

            return $appeal->refresh();
        });
    }

    private function latestDecision(RewardAward $award): ?CarbonImmutable
    {
        $latest = null;

        foreach ([
            'held_at', 'pending_review_at', 'approved_at', 'rejected_at',
            'fulfilled_at', 'reversed_at', 'corrected_at',
        ] as $column) {
            $value = $award->getAttribute($column);

            if ($value === null) {
                continue;
            }

            $at = CarbonImmutable::parse($value)->setTimezone('UTC');

            if ($latest === null || $at->greaterThan($latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }
}
