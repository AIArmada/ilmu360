<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardBenefitStockKind;
use App\Models\RewardBenefitGrant;
use App\Models\RewardPointReservationSubmission;
use App\Support\Rewards\RewardBenefitStockLedger;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Cancel a reserved (unfulfilled) grant: restore once.
 *
 * Held points release, held stock releases, and the handoff resolves as
 * confirmed failed (remote proved non-delivery: nothing was ever handed
 * over). The member may cancel their own pending redemption; staff may
 * cancel any reserved grant. Fulfilled grants never cancel here — revoke
 * unused ones, or open recovery for consumed value.
 */
final readonly class CancelRewardBenefitGrantAction
{
    use AsAction;

    public function handle(
        RewardBenefitGrant|string $grant,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitGrant {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if ($actorType !== 'user' || ! in_array($actorRole, ['member', 'staff', 'admin'], true)) {
            throw new InvalidArgumentException('Grant cancellation requires a user identity.');
        }

        $grantId = $grant instanceof RewardBenefitGrant ? (string) $grant->getKey() : $grant;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($grantId, $actorId, $actorRole, $reason, $decided, $actorType): RewardBenefitGrant {
            $locked = RewardBenefitGrant::query()->whereKey($grantId)->lockForUpdate()->first();

            if (! $locked instanceof RewardBenefitGrant) {
                throw new InvalidArgumentException('Unknown reward benefit grant.');
            }

            if ($locked->status !== RewardBenefitGrantStatus::Reserved) {
                throw new InvalidArgumentException('Only reserved grants can be cancelled.');
            }

            if ($actorRole === 'member' && $actorId !== (string) $locked->recipient_id) {
                throw new InvalidArgumentException('Members cancel only their own pending redemptions.');
            }

            if ($locked->reservation_key !== null) {
                ReleaseRewardPointsAction::run(
                    (string) $locked->reservation_key, "benefit-cancel:{$reason}", $decided, $actorType, $actorId,
                );

                MarkRewardReservationSubmittedAction::run(
                    (string) $locked->reservation_key,
                    RewardPointReservationSubmission::STATUS_FAILED,
                    (string) $locked->grant_key, $decided,
                );
            }

            RewardBenefitStockLedger::append(
                RewardBenefitStockKind::Release, "stock-release:{$locked->grant_key}",
                (string) $locked->item_id, (int) $locked->quantity, (string) $locked->getKey(),
                $actorType, $actorId, $actorRole, $decided,
            );

            $evidence = $locked->evidence ?? [];
            $evidence['cancellation'] = ['reason' => $reason, 'cancelled_at' => $decided->toIso8601String()];

            $locked->forceFill([
                'status' => RewardBenefitGrantStatus::Revoked,
                'evidence' => $evidence,
                'revoked_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }
}
