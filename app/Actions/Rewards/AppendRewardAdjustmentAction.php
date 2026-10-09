<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardAdjustmentKind;
use App\Enums\Rewards\RewardAdjustmentStatus;
use App\Models\RewardAdjustment;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardOperationKeys;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Append-only override/adjustment/dispute evidence.
 *
 * Idempotent by operation key. Every adjustment attaches to an award, an
 * appeal, or a prior adjustment — free-floating adjustments are refused.
 * Resolution records the resolver and reason without rewriting the opening
 * evidence; the resolver must differ from the opener wherever both
 * identities are supplied.
 */
final readonly class AppendRewardAdjustmentAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>|null  $amountSnapshot
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        RewardAdjustmentKind|string $kind,
        ?string $awardId,
        ?string $appealId,
        ?string $linkedAdjustmentId,
        string $operationKey,
        ?array $amountSnapshot,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence = null,
        ?CarbonInterface $openedAt = null,
    ): RewardAdjustment {
        $kindEnum = $kind instanceof RewardAdjustmentKind ? $kind : RewardAdjustmentKind::tryFrom($kind);

        if (! $kindEnum instanceof RewardAdjustmentKind) {
            throw new InvalidArgumentException('Unknown adjustment kind.');
        }

        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);
        RewardOperationKeys::assertValid($operationKey);

        foreach (['award' => $awardId, 'appeal' => $appealId, 'linked adjustment' => $linkedAdjustmentId] as $label => $id) {
            if ($id !== null && ! Str::isUuid($id)) {
                throw new InvalidArgumentException("{$label} id must be a UUID.");
            }
        }

        if ($awardId === null && $appealId === null && $linkedAdjustmentId === null) {
            throw new InvalidArgumentException('Adjustments must attach to an award, an appeal, or a prior adjustment.');
        }

        $at = $openedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($openedAt)->setTimezone('UTC');

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): RewardAdjustment => $this->attempt(
                    $kindEnum, $awardId, $appealId, $linkedAdjustmentId, $operationKey,
                    $amountSnapshot, $actorType, $actorId, $actorRole, $reason, $evidence, $at,
                ));
            } catch (QueryException $exception) {
                // Deadlock victims retry; every catch sits outside the
                // transaction (PostgreSQL-safe). A concurrent same-key
                // adjustment that won collapses the replay onto the winner.
                if (self::isDeadlock($exception) && $attempt < 3) {
                    continue;
                }

                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $winner = RewardAdjustment::query()->where('operation_key', $operationKey)->first();

                if ($winner instanceof RewardAdjustment) {
                    self::assertReplayBinds($winner, $kindEnum, $awardId, $appealId, $linkedAdjustmentId, $amountSnapshot);

                    return $winner;
                }

                throw $exception;
            }
        }
    }

    private static function isDeadlock(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        if (in_array($code, ['40001', '1213'], true)) {
            return true;
        }

        return stripos($exception->getMessage(), 'deadlock') !== false;
    }

    /**
     * @param  array<string, mixed>|null  $amountSnapshot
     * @param  array<string, mixed>|null  $evidence
     */
    private function attempt(
        RewardAdjustmentKind $kindEnum,
        ?string $awardId,
        ?string $appealId,
        ?string $linkedAdjustmentId,
        string $operationKey,
        ?array $amountSnapshot,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence,
        CarbonImmutable $at,
    ): RewardAdjustment {
        $existing = RewardAdjustment::query()->where('operation_key', $operationKey)->first();

        if ($existing instanceof RewardAdjustment) {
            self::assertReplayBinds($existing, $kindEnum, $awardId, $appealId, $linkedAdjustmentId, $amountSnapshot);

            return $existing;
        }

        return RewardAdjustment::query()->create([
            'award_id' => $awardId,
            'appeal_id' => $appealId,
            'linked_adjustment_id' => $linkedAdjustmentId,
            'kind' => $kindEnum,
            'status' => RewardAdjustmentStatus::Open,
            'operation_key' => $operationKey,
            'amount_snapshot' => $amountSnapshot,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'reason' => $reason,
            'evidence' => $evidence,
            'resolved_by_type' => null,
            'resolved_by_id' => null,
            'resolve_reason' => null,
            'opened_at' => $at,
            'resolved_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $amountSnapshot
     */
    public static function assertReplayBinds(
        RewardAdjustment $existing,
        RewardAdjustmentKind $kind,
        ?string $awardId,
        ?string $appealId,
        ?string $linkedAdjustmentId,
        ?array $amountSnapshot,
    ): void {
        if ($existing->kind !== $kind
            || ($existing->award_id === null ? null : (string) $existing->award_id) !== $awardId
            || ($existing->appeal_id === null ? null : (string) $existing->appeal_id) !== $appealId
            || ($existing->linked_adjustment_id === null ? null : (string) $existing->linked_adjustment_id) !== $linkedAdjustmentId
            || $existing->amount_snapshot !== $amountSnapshot
        ) {
            throw new InvalidArgumentException('Adjustment operation key is already bound to different terms; supply a fresh key.');
        }
    }

    public function resolve(
        RewardAdjustment|string $adjustment,
        string $resolvedByType,
        ?string $resolvedById,
        string $resolvedByRole,
        string $resolveReason,
        ?CarbonInterface $resolvedAt = null,
    ): RewardAdjustment {
        RewardLedgerEntries::assertActor($resolvedByType, $resolvedById, $resolvedByRole);
        RewardLedgerEntries::assertReason($resolveReason);

        if (! in_array($resolvedByRole, ['staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Adjustment resolution requires a staff/admin/system identity.');
        }

        $at = $resolvedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($resolvedAt)->setTimezone('UTC');

        $adjustmentId = $adjustment instanceof RewardAdjustment ? (string) $adjustment->getKey() : $adjustment;

        return DB::transaction(function () use (
            $adjustmentId, $resolvedByType, $resolvedById, $resolveReason, $at,
        ): RewardAdjustment {
            $locked = RewardAdjustment::query()->whereKey($adjustmentId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAdjustment) {
                throw new InvalidArgumentException('Unknown reward adjustment.');
            }

            if ($locked->status === RewardAdjustmentStatus::Resolved) {
                return $locked;
            }

            if ($resolvedById !== null && $locked->actor_id !== null && $resolvedById === (string) $locked->actor_id) {
                throw new InvalidArgumentException('The opener cannot resolve their own adjustment.');
            }

            $locked->forceFill([
                'status' => RewardAdjustmentStatus::Resolved,
                'resolved_by_type' => $resolvedByType,
                'resolved_by_id' => $resolvedById,
                'resolve_reason' => $resolveReason,
                'resolved_at' => $at,
            ])->save();

            return $locked->refresh();
        });
    }
}
