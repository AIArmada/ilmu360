<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBadgeStatus;
use App\Models\RewardBadgeGrant;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Private badge revocation. The latest active grant row flips to revoked
 * with actor/reason/timestamp; history is retained and a later re-grant
 * appends a fresh row. Revoking an already-revoked badge is a no-op.
 */
final readonly class RevokeRewardBadgeAction
{
    use AsAction;

    public function handle(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $badgeKey,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $revokedAt = null,
    ): ?RewardBadgeGrant {
        CreditRewardPointsAction::assertRecipient($recipientType, $recipientId);
        CreditRewardPointsAction::assertFamily($familyKey);
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if (! in_array($actorRole, ['staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Badge revocation requires a staff/admin/system identity.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $badgeKey) !== 1) {
            throw new InvalidArgumentException('Badge key must be a 1..64 slug.');
        }

        $at = $revokedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($revokedAt)->setTimezone('UTC');

        return DB::transaction(function () use (
            $recipientType, $recipientId, $familyKey, $badgeKey,
            $actorType, $actorId, $reason, $at,
        ): ?RewardBadgeGrant {
            $active = RewardBadgeGrant::query()
                ->where('recipient_type', $recipientType)
                ->where('recipient_id', $recipientId)
                ->where('family_key', $familyKey)
                ->where('badge_key', $badgeKey)
                ->where('status', RewardBadgeStatus::Granted)
                ->orderByDesc('seq')
                ->lockForUpdate()
                ->first();

            if (! $active instanceof RewardBadgeGrant) {
                return null;
            }

            $active->forceFill([
                'status' => RewardBadgeStatus::Revoked,
                'revoked_by_type' => $actorType,
                'revoked_by_id' => $actorId,
                'revoke_reason' => $reason,
                'revoked_at' => $at,
            ])->save();

            CancelRewardDigestAction::cancelPendingForRecipient(
                $recipientType, $recipientId, 'badge-revoked', $at,
            );

            return $active->refresh();
        });
    }
}
