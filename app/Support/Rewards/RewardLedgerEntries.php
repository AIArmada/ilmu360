<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Models\RewardAppeal;
use App\Models\RewardAppealDecision;
use App\Models\RewardAward;
use App\Models\RewardAwardEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared append-only entry writers and actor validation for ledger actions.
 *
 * Seq is assigned under the parent row lock (callers hold it); the unique
 * (parent, seq) constraint arbitrates any residual race. Entries are never
 * updated, so history cannot be rewritten by later decisions.
 */
final class RewardLedgerEntries
{
    /**
     * @var list<string>
     */
    public const array ACTOR_TYPES = ['user', 'system'];

    /**
     * @var list<string>
     */
    public const array ACTOR_ROLES = ['member', 'staff', 'admin', 'system'];

    public static function assertActor(string $actorType, ?string $actorId, string $actorRole): void
    {
        if (! in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new InvalidArgumentException('Actor type must be one of ['.implode(',', self::ACTOR_TYPES).'].');
        }

        if (! in_array($actorRole, self::ACTOR_ROLES, true)) {
            throw new InvalidArgumentException('Actor role must be one of ['.implode(',', self::ACTOR_ROLES).'].');
        }

        if ($actorType === 'system' && $actorId !== null) {
            throw new InvalidArgumentException('System actors must not carry an actor id.');
        }

        if ($actorType === 'user' && ($actorId === null || ! Str::isUuid($actorId))) {
            throw new InvalidArgumentException('User actors require an explicit UUID actor id.');
        }

        if ($actorType === 'user' && $actorRole === 'system') {
            throw new InvalidArgumentException('User actors must not use the system role.');
        }

        if ($actorType === 'system' && $actorRole !== 'system') {
            throw new InvalidArgumentException('System actors must use the system role.');
        }
    }

    public static function assertReason(string $reason): void
    {
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new InvalidArgumentException('Reason must be a non-empty string up to 191 characters.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public static function appendAwardEntry(
        RewardAward $award,
        string $fromStatus,
        string $toStatus,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence,
        ?string $sourceOperationId,
        CarbonImmutable $decidedAt,
    ): RewardAwardEntry {
        $maxSeq = (int) (RewardAwardEntry::query()
            ->where('award_id', $award->getKey())
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->value('seq') ?? 0);

        return RewardAwardEntry::query()->create([
            'award_id' => $award->getKey(),
            'seq' => $maxSeq + 1,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'reason' => $reason,
            'evidence' => $evidence,
            'source_operation_id' => $sourceOperationId,
            'decided_at' => $decidedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public static function appendAppealDecision(
        RewardAppeal $appeal,
        string $fromStatus,
        string $toStatus,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence,
        CarbonImmutable $decidedAt,
    ): RewardAppealDecision {
        $maxSeq = (int) (RewardAppealDecision::query()
            ->where('appeal_id', $appeal->getKey())
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->value('seq') ?? 0);

        return RewardAppealDecision::query()->create([
            'appeal_id' => $appeal->getKey(),
            'seq' => $maxSeq + 1,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'reason' => $reason,
            'evidence' => $evidence,
            'decided_at' => $decidedAt,
        ]);
    }
}
