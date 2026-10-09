<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardOperationKeys;
use App\Support\Rewards\RewardPointLedger;
use App\Support\Rewards\RewardPointOperationAuthority;
use App\Support\Rewards\RewardRuleValidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Point reservation: locks available value under a reservation key.
 *
 * Allocation is deterministic oldest-expiring-first under lot locks sorted by
 * id. The reservation key claims one immutable logical-operation row binding
 * recipient/family/unit/amount BEFORE any lot is locked, so concurrent
 * contenders with disjoint lots still serialize on the same identity: replays
 * return the existing movements, mismatched reuse fails explicitly. Reserved
 * points leave the available bucket but are never spendable and never
 * double-counted as spent. Reservations are atomic: release or capture moves
 * the full reserved amount.
 *
 * @return list<RewardPointMovement>
 */
final readonly class ReserveRewardPointsAction
{
    use AsAction;

    /**
     * @return list<RewardPointMovement>
     */
    public function handle(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        int $points,
        string $reservationKey,
        string $reason,
        ?CarbonInterface $occurredAt = null,
        string $actorType = 'system',
        ?string $actorId = null,
    ): array {
        CreditRewardPointsAction::assertRecipient($recipientType, $recipientId);
        CreditRewardPointsAction::assertFamily($familyKey);
        CreditRewardPointsAction::assertUnit($unit);
        CreditRewardPointsAction::assertActor($actorType, $actorId);
        CreditRewardPointsAction::assertReason($reason);
        RewardOperationKeys::assertValid($reservationKey);

        if ($points < 1 || $points > RewardRuleValidator::MAX_POINTS) {
            throw new InvalidArgumentException('Reserve points must be an integer 1..'.RewardRuleValidator::MAX_POINTS.'.');
        }

        $occurred = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($occurredAt)->setTimezone('UTC');

        $aggregate = RewardOperationKeys::aggregate('reserve', 'reservation', $reservationKey, $unit, $points);

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(function () use (
                    $recipientType, $recipientId, $familyKey, $unit, $points,
                    $reservationKey, $reason, $occurred, $actorType, $actorId, $aggregate,
                ): array {
                    return $this->attempt(
                        $recipientType, $recipientId, $familyKey, $unit, $points,
                        $reservationKey, $reason, $occurred, $actorType, $actorId, $aggregate,
                    );
                });
            } catch (QueryException $exception) {
                // Deadlock victims retry the whole attempt with the same
                // reservation key: the claim is idempotent, so the retry
                // preserves a single immutable operation (every catch sits
                // outside the transaction, PostgreSQL-safe).
                if (self::isDeadlock($exception) && $attempt < 3) {
                    continue;
                }

                // A concurrent same-key reservation won the operation claim;
                // collapse onto it only when its bound ownership and terms
                // match. Mismatched contenders — including disjoint-lot
                // racers — fail here with no partial effect.
                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $operation = RewardPointOperationAuthority::resolveWinner(
                    RewardPointOperationAuthority::KIND_RESERVE,
                    $reservationKey,
                    $this->terms($recipientType, $recipientId, $familyKey, $unit, $points),
                    'Reservation aggregate',
                );

                if ($operation === null) {
                    throw $exception;
                }

                $winner = RewardPointMovement::query()
                    ->where('aggregate_operation_key', $aggregate)
                    ->orderBy('operation_key')
                    ->get()
                    ->all();

                if ($winner === []) {
                    throw $exception;
                }

                self::assertReplayBinds($winner, $recipientType, $recipientId, $familyKey, $unit, $points, $reservationKey);

                return $winner;
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
     * @return array{recipient_type: string, recipient_id: string, family_key: string, unit: string, points: int}
     */
    private function terms(string $recipientType, string $recipientId, string $familyKey, string $unit, int $points): array
    {
        return [
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'family_key' => $familyKey,
            'unit' => $unit,
            'points' => $points,
        ];
    }

    /**
     * @return list<RewardPointMovement>
     */
    private function attempt(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        int $points,
        string $reservationKey,
        string $reason,
        CarbonImmutable $occurred,
        string $actorType,
        ?string $actorId,
        string $aggregate,
    ): array {
        // Operation identity first, locks second: the unique claim binds the
        // logical reservation before any lot is touched, so contenders with
        // disjoint lots still serialize on the same row. A committed claim
        // means its movements committed in the same transaction.
        $operation = RewardPointOperationAuthority::claim(
            RewardPointOperationAuthority::KIND_RESERVE,
            $reservationKey,
            $this->terms($recipientType, $recipientId, $familyKey, $unit, $points),
            'Reservation aggregate',
        );

        if (! $operation->wasRecentlyCreated) {
            $existing = RewardPointMovement::query()
                ->where('aggregate_operation_key', $aggregate)
                ->orderBy('operation_key')
                ->get()
                ->all();

            if ($existing === []) {
                throw new InvalidArgumentException('Reservation claim is incoherent; refusing to reallocate.');
            }

            self::assertReplayBinds($existing, $recipientType, $recipientId, $familyKey, $unit, $points, $reservationKey);

            return $existing;
        }

        $lots = RewardPointLedger::lockCandidateLots($recipientType, $recipientId, $familyKey, $unit);

        $movements = $lots->isEmpty()
            ? collect()
            : RewardPointMovement::query()
                ->whereIn('lot_id', $lots->map(fn (RewardPointLot $lot): string => (string) $lot->getKey())->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        $plan = RewardPointLedger::allocate($lots, $movements, $points, $occurred);

        if ($plan === []) {
            throw new InvalidArgumentException('Insufficient available points; reserved and pending value is never spendable.');
        }

        $created = [];

        foreach ($plan as $lotId => $take) {
            $created[] = RewardPointMovement::query()->create([
                'lot_id' => $lotId,
                'kind' => RewardPointMovementKind::Reserve,
                'delta' => $take,
                'operation_key' => RewardOperationKeys::lotAllocation($aggregate, $lotId),
                'aggregate_operation_key' => $aggregate,
                'linked_movement_id' => null,
                'reservation_key' => $reservationKey,
                'award_id' => null,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'occurred_at' => $occurred,
            ]);
        }

        return $created;
    }

    /**
     * @param  list<RewardPointMovement>  $existing
     */
    public static function assertReplayBinds(
        array $existing,
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        int $points,
        string $reservationKey,
    ): void {
        $sum = 0;

        $lotIds = array_values(array_unique(array_map(
            static fn (RewardPointMovement $movement): string => (string) $movement->lot_id,
            $existing,
        )));

        $lots = $lotIds === []
            ? collect()
            : RewardPointLot::query()->whereIn('id', $lotIds)->get()->keyBy(
                static fn (RewardPointLot $lot): string => (string) $lot->getKey()
            );

        foreach ($existing as $movement) {
            if ($movement->kind !== RewardPointMovementKind::Reserve
                || (string) $movement->reservation_key !== $reservationKey
            ) {
                throw new InvalidArgumentException('Reservation aggregate is already bound to different terms; supply a fresh key.');
            }

            $lot = $lots->get((string) $movement->lot_id);

            if (! $lot instanceof RewardPointLot
                || (string) $lot->recipient_type !== $recipientType
                || (string) $lot->recipient_id !== $recipientId
                || (string) $lot->family_key !== $familyKey
                || (string) $lot->unit !== $unit
            ) {
                throw new InvalidArgumentException('Reservation aggregate is already bound to a different recipient, family, or unit.');
            }

            $sum += (int) $movement->delta;
        }

        if ($sum !== $points) {
            throw new InvalidArgumentException('Reservation aggregate is already bound to a different amount; supply a fresh key.');
        }
    }
}
