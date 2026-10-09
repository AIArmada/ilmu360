<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardBenefitStockKind;
use App\Models\RewardBenefitGrant;
use App\Models\RewardBenefitItem;
use App\Models\RewardPointReservationSubmission;
use App\Support\Rewards\RewardBenefitStockLedger;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Expire a reserved grant whose catalog item expired: restore once.
 *
 * Only grants reserved against an item whose expiry has passed expire
 * here — the points release, the stock releases, the handoff resolves as
 * confirmed failed (expired undelivered), and the grant closes as
 * expired. Issued grants never expire through this path; their use or
 * revocation follows the live grant lifecycle.
 */
final readonly class ExpireRewardBenefitGrantAction
{
    use AsAction;

    public function handle(
        RewardBenefitGrant|string $grant,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitGrant {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if (! in_array($actorRole, ['staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Grant expiry requires a staff/admin/system identity.');
        }

        $grantId = $grant instanceof RewardBenefitGrant ? (string) $grant->getKey() : $grant;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($grantId, $decided, $actorType, $actorId, $actorRole): RewardBenefitGrant {
            $locked = RewardBenefitGrant::query()->whereKey($grantId)->lockForUpdate()->first();

            if (! $locked instanceof RewardBenefitGrant) {
                throw new InvalidArgumentException('Unknown reward benefit grant.');
            }

            if ($locked->status !== RewardBenefitGrantStatus::Reserved) {
                throw new InvalidArgumentException('Only reserved grants can expire.');
            }

            $item = RewardBenefitItem::query()->whereKey((string) $locked->item_id)->lockForUpdate()->firstOrFail();

            if ($item->expires_at === null || $item->expires_at->greaterThan($decided)) {
                throw new InvalidArgumentException('Grant expiry requires a passed catalog expiry.');
            }

            if ($locked->reservation_key !== null) {
                ReleaseRewardPointsAction::run(
                    (string) $locked->reservation_key, 'benefit-expired', $decided, $actorType, $actorId,
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

            $locked->forceFill([
                'status' => RewardBenefitGrantStatus::Expired,
                'expired_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }
}
