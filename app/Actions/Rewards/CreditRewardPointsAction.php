<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPointLotStatus;
use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardOperationKeys;
use App\Support\Rewards\RewardRuleValidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Source-linked point credit: creates one lot plus its credit movement.
 *
 * Idempotent by operation key — retries return the existing credit movement
 * instead of minting a second lot. Amounts are bounded integers; expiry must
 * be after availability; cumulative overflow is refused.
 */
final readonly class CreditRewardPointsAction
{
    use AsAction;

    public function handle(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        int $points,
        CarbonInterface $availableAt,
        ?CarbonInterface $expiresAt = null,
        ?string $awardId = null,
        ?string $programId = null,
        ?string $operationKey = null,
        string $actorType = 'system',
        ?string $actorId = null,
        string $reason = 'award-credit',
        ?CarbonInterface $occurredAt = null,
    ): RewardPointMovement {
        self::assertRecipient($recipientType, $recipientId);
        self::assertFamily($familyKey);
        self::assertUnit($unit);
        self::assertActor($actorType, $actorId);
        self::assertReason($reason);

        if ($points < 1 || $points > RewardRuleValidator::MAX_POINTS) {
            throw new InvalidArgumentException('Credit points must be an integer 1..'.RewardRuleValidator::MAX_POINTS.'.');
        }

        $available = CarbonImmutable::parse($availableAt)->setTimezone('UTC');
        $expires = $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)->setTimezone('UTC');

        if ($expires !== null && ! $expires->greaterThan($available)) {
            throw new InvalidArgumentException('Lot expiry must be after availability.');
        }

        if ($awardId !== null && ! Str::isUuid($awardId)) {
            throw new InvalidArgumentException('Award id must be a UUID.');
        }

        if ($programId !== null && ! Str::isUuid($programId)) {
            throw new InvalidArgumentException('Program id must be a UUID.');
        }

        $key = $operationKey ?? ($awardId !== null
            ? RewardOperationKeys::credit('award', $awardId, $unit)
            : null);

        if ($key === null) {
            throw new InvalidArgumentException('Non-award credits require an explicit operation key.');
        }

        RewardOperationKeys::assertValid($key);

        $occurred = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($occurredAt)->setTimezone('UTC');

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): RewardPointMovement => $this->attempt(
                    $recipientType, $recipientId, $familyKey, $unit, $points, $available, $expires,
                    $awardId, $programId, $key, $actorType, $actorId, $reason, $occurred,
                ));
            } catch (QueryException $exception) {
                // Deadlock victims retry; every catch sits outside the
                // transaction (PostgreSQL-safe). A concurrent same-key
                // credit that won the insert collapses the replay onto the
                // winner only when its bound terms match.
                if (self::isDeadlock($exception) && $attempt < 3) {
                    continue;
                }

                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $winner = RewardPointMovement::query()->where('operation_key', $key)->first();

                if ($winner instanceof RewardPointMovement) {
                    self::assertReplayBinds($winner, $recipientType, $recipientId, $familyKey, $unit, $points, $available, $expires, $awardId, $programId);

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

    private function attempt(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        int $points,
        CarbonImmutable $available,
        ?CarbonImmutable $expires,
        ?string $awardId,
        ?string $programId,
        string $key,
        string $actorType,
        ?string $actorId,
        string $reason,
        CarbonImmutable $occurred,
    ): RewardPointMovement {
        $existing = RewardPointMovement::query()->where('operation_key', $key)->first();

        if ($existing instanceof RewardPointMovement) {
            self::assertReplayBinds($existing, $recipientType, $recipientId, $familyKey, $unit, $points, $available, $expires, $awardId, $programId);

            return $existing;
        }

        $lotExists = RewardPointLot::query()->where('source_operation_key', $key)->exists();

        if ($lotExists) {
            throw new InvalidArgumentException('Credit operation key is already used by a lot; supply a fresh key.');
        }

        $credited = (int) RewardPointLot::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('unit', $unit)
            ->sum('credited_points');

        if ($credited > PHP_INT_MAX - $points) {
            throw new InvalidArgumentException('Credit would overflow the recipient balance; refused.');
        }

        $seqs = RewardPointLot::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('unit', $unit)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('seq')
            ->all();

        $seq = ($seqs === [] ? 0 : max(array_map(intval(...), $seqs))) + 1;

        $lot = RewardPointLot::query()->create([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'family_key' => $familyKey,
            'program_id' => $programId,
            'award_id' => $awardId,
            'seq' => $seq,
            'unit' => $unit,
            'credited_points' => $points,
            'source_operation_key' => $key,
            'available_at' => $available,
            'expires_at' => $expires,
            'status' => RewardPointLotStatus::Open,
            'credited_at' => $occurred,
            'depleted_at' => null,
            'expired_at' => null,
        ]);

        return RewardPointMovement::query()->create([
            'lot_id' => $lot->getKey(),
            'kind' => RewardPointMovementKind::Credit,
            'delta' => $points,
            'operation_key' => $key,
            'aggregate_operation_key' => $key,
            'linked_movement_id' => null,
            'reservation_key' => null,
            'award_id' => $awardId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'occurred_at' => $occurred,
        ]);
    }

    public static function assertReplayBinds(
        RewardPointMovement $existing,
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        int $points,
        CarbonImmutable $available,
        ?CarbonImmutable $expires,
        ?string $awardId,
        ?string $programId,
    ): void {
        if ($existing->kind !== RewardPointMovementKind::Credit || (int) $existing->delta !== $points) {
            throw new InvalidArgumentException('Credit operation key is already bound to different terms; supply a fresh key.');
        }

        $lot = RewardPointLot::query()->whereKey((string) $existing->lot_id)->first();

        if (! $lot instanceof RewardPointLot) {
            throw new InvalidArgumentException('Credit operation key is already bound to different terms; supply a fresh key.');
        }

        $lotAvailable = $lot->available_at instanceof CarbonImmutable
            ? $lot->available_at->format('Y-m-d H:i:s')
            : CarbonImmutable::parse($lot->available_at)->format('Y-m-d H:i:s');
        $reqAvailable = $available->setTimezone('UTC')->format('Y-m-d H:i:s');
        $lotExpires = $lot->expires_at === null
            ? null
            : ($lot->expires_at instanceof CarbonImmutable
                ? $lot->expires_at->format('Y-m-d H:i:s')
                : CarbonImmutable::parse($lot->expires_at)->format('Y-m-d H:i:s'));
        $reqExpires = $expires?->setTimezone('UTC')->format('Y-m-d H:i:s');

        if ((string) $lot->recipient_type !== $recipientType
            || (string) $lot->recipient_id !== $recipientId
            || (string) $lot->family_key !== $familyKey
            || (string) $lot->unit !== $unit
            || (int) $lot->credited_points !== $points
            || $lotAvailable !== $reqAvailable
            || $lotExpires !== $reqExpires
            || ($lot->award_id === null ? null : (string) $lot->award_id) !== $awardId
            || ($lot->program_id === null ? null : (string) $lot->program_id) !== $programId
        ) {
            throw new InvalidArgumentException('Credit operation key is already bound to a different recipient, family, unit, amount, or schedule.');
        }
    }

    public static function assertRecipient(string $recipientType, string $recipientId): void
    {
        if ($recipientType !== 'user') {
            throw new InvalidArgumentException('Recipient type must be [user].');
        }

        if (! Str::isUuid($recipientId)) {
            throw new InvalidArgumentException('Recipient id must be a UUID.');
        }
    }

    public static function assertFamily(string $familyKey): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $familyKey) !== 1) {
            throw new InvalidArgumentException('Family key must be a 1..64 slug.');
        }
    }

    public static function assertUnit(string $unit): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,15}$/', $unit) !== 1) {
            throw new InvalidArgumentException('Unit must be 1..16 alphanumerics.');
        }
    }

    public static function assertActor(string $actorType, ?string $actorId): void
    {
        if (! in_array($actorType, ['user', 'system'], true)) {
            throw new InvalidArgumentException('Actor type must be [user] or [system].');
        }

        if ($actorType === 'system' && $actorId !== null) {
            throw new InvalidArgumentException('System actors must not carry an actor id.');
        }

        if ($actorType === 'user' && ($actorId === null || ! Str::isUuid($actorId))) {
            throw new InvalidArgumentException('User actors require an explicit UUID actor id.');
        }
    }

    public static function assertReason(string $reason): void
    {
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new InvalidArgumentException('Reason must be a non-empty string up to 191 characters.');
        }
    }
}
