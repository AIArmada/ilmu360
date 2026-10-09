<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBadgeStatus;
use App\Models\RewardBadgeGrant;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Private badge grant. Idempotent while a grant row is active; a revoked
 * badge keeps its history and a later re-grant appends a new seq row.
 * Grants are per-recipient and never public. Concurrent first-grants
 * collapse onto the winner instead of minting duplicate rows.
 */
final readonly class GrantRewardBadgeAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $badgeKey,
        ?string $programId,
        ?string $awardId,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence = null,
        ?CarbonInterface $grantedAt = null,
    ): RewardBadgeGrant {
        CreditRewardPointsAction::assertRecipient($recipientType, $recipientId);
        CreditRewardPointsAction::assertFamily($familyKey);
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $badgeKey) !== 1) {
            throw new InvalidArgumentException('Badge key must be a 1..64 slug.');
        }

        foreach (['program' => $programId, 'award' => $awardId] as $label => $id) {
            if ($id !== null && ! Str::isUuid($id)) {
                throw new InvalidArgumentException("{$label} id must be a UUID.");
            }
        }

        $at = $grantedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($grantedAt)->setTimezone('UTC');

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): RewardBadgeGrant => $this->attempt(
                    $recipientType, $recipientId, $familyKey, $badgeKey, $programId,
                    $awardId, $actorType, $actorId, $reason, $evidence, $at,
                ));
            } catch (QueryException $exception) {
                // A concurrent grant won this seq, or a deadlock victim lost
                // the insert race; retry so the attempt collapses onto the
                // active row (catch outside the transaction, PostgreSQL-safe).
                if ($attempt >= 3 || (! CreateRewardProgramAction::isUniqueViolation($exception) && ! self::isDeadlock($exception))) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    private function attempt(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $badgeKey,
        ?string $programId,
        ?string $awardId,
        string $actorType,
        ?string $actorId,
        string $reason,
        ?array $evidence,
        CarbonImmutable $at,
    ): RewardBadgeGrant {
        $active = RewardBadgeGrant::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('badge_key', $badgeKey)
            ->where('status', RewardBadgeStatus::Granted)
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->first();

        if ($active instanceof RewardBadgeGrant) {
            return $active;
        }

        $seq = (int) (RewardBadgeGrant::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('badge_key', $badgeKey)
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->value('seq') ?? 0) + 1;

        $grant = RewardBadgeGrant::query()->create([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'family_key' => $familyKey,
            'badge_key' => $badgeKey,
            'seq' => $seq,
            'program_id' => $programId,
            'award_id' => $awardId,
            'status' => RewardBadgeStatus::Granted,
            'granted_by_type' => $actorType,
            'granted_by_id' => $actorId,
            'grant_reason' => $reason,
            'evidence' => $evidence,
            'granted_at' => $at,
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revoke_reason' => null,
            'revoked_at' => null,
        ]);

        // A fresh badge grant plans the recipient's delayed digest in
        // the same atomic unit; replays return the stored grant above
        // without replanning. Opted-out recipients yield no slot.
        try {
            PlanRewardGratitudeDigestAction::run($recipientType, $recipientId, ['badges'], $at);
        } catch (InvalidArgumentException) {
            // No slot for this recipient; the grant stands.
        }

        return $grant;
    }

    private static function isDeadlock(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        if (in_array($code, ['40001', '1213'], true)) {
            return true;
        }

        return stripos($exception->getMessage(), 'deadlock') !== false;
    }
}
