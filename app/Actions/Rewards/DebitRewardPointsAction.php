<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPointLotStatus;
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
 * Point spend: debits available value, oldest-expiring-first.
 *
 * Two modes: direct spend from available, or capture of a full reservation
 * (the reservation key must cover the exact amount; capture reduces reserved
 * and increases spent — never both at once). Negative balances are refused
 * under lot locks; the debit key claims one immutable logical-operation row
 * binding recipient/family/unit/amount/reservation BEFORE any lock, so
 * concurrent same-key debits — including disjoint-lot contenders — collapse
 * onto the winner only when every bound term matches.
 *
 * @return list<RewardPointMovement>
 */
final readonly class DebitRewardPointsAction
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
        string $operationKey,
        string $reason,
        ?string $reservationKey = null,
        ?CarbonInterface $occurredAt = null,
        string $actorType = 'system',
        ?string $actorId = null,
    ): array {
        CreditRewardPointsAction::assertRecipient($recipientType, $recipientId);
        CreditRewardPointsAction::assertFamily($familyKey);
        CreditRewardPointsAction::assertUnit($unit);
        CreditRewardPointsAction::assertActor($actorType, $actorId);
        CreditRewardPointsAction::assertReason($reason);
        RewardOperationKeys::assertValid($operationKey);

        if ($reservationKey !== null) {
            RewardOperationKeys::assertValid($reservationKey);
        }

        if ($points < 1 || $points > RewardRuleValidator::MAX_POINTS) {
            throw new InvalidArgumentException('Debit points must be an integer 1..'.RewardRuleValidator::MAX_POINTS.'.');
        }

        $occurred = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($occurredAt)->setTimezone('UTC');

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): array => $this->attempt(
                    $recipientType, $recipientId, $familyKey, $unit, $points,
                    $operationKey, $reason, $reservationKey, $occurred, $actorType, $actorId,
                ));
            } catch (QueryException $exception) {
                // Deadlock victims retry with the same key: the claim is
                // idempotent, so the retry preserves a single immutable
                // operation (every catch sits outside the transaction).
                if (self::isDeadlock($exception) && $attempt < 3) {
                    continue;
                }

                // A concurrent same-key debit won the operation claim;
                // collapse onto it only when its bound ownership and terms
                // match. Mismatched contenders fail with no partial effect.
                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $operation = RewardPointOperationAuthority::resolveWinner(
                    RewardPointOperationAuthority::KIND_DEBIT,
                    $operationKey,
                    $this->terms($recipientType, $recipientId, $familyKey, $unit, $points, $reservationKey),
                    'Debit aggregate',
                );

                if ($operation === null) {
                    throw $exception;
                }

                $winner = RewardPointMovement::query()
                    ->where('aggregate_operation_key', $operationKey)
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
     * @return array{recipient_type: string, recipient_id: string, family_key: string, unit: string, points: int, reservation_key: ?string}
     */
    private function terms(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $unit,
        int $points,
        ?string $reservationKey,
    ): array {
        return [
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'family_key' => $familyKey,
            'unit' => $unit,
            'points' => $points,
            'reservation_key' => $reservationKey,
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
        string $operationKey,
        string $reason,
        ?string $reservationKey,
        CarbonImmutable $occurred,
        string $actorType,
        ?string $actorId,
    ): array {
        if ($reservationKey !== null) {
            return $this->captureReservation(
                $reservationKey, $points, $operationKey, $reason, $occurred, $actorType, $actorId,
            );
        }

        // Operation identity first, locks second: the unique claim binds the
        // logical debit before any lot is touched, so contenders with
        // disjoint lots still serialize on the same row.
        $operation = RewardPointOperationAuthority::claim(
            RewardPointOperationAuthority::KIND_DEBIT,
            $operationKey,
            $this->terms($recipientType, $recipientId, $familyKey, $unit, $points, null),
            'Debit aggregate',
        );

        if (! $operation->wasRecentlyCreated) {
            $existing = RewardPointMovement::query()
                ->where('aggregate_operation_key', $operationKey)
                ->orderBy('operation_key')
                ->get()
                ->all();

            if ($existing === []) {
                throw new InvalidArgumentException('Debit claim is incoherent; refusing to reallocate.');
            }

            self::assertReplayBinds($existing, $recipientType, $recipientId, $familyKey, $unit, $points, null);

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
            throw new InvalidArgumentException('Insufficient available points; spending never goes negative.');
        }

        $created = [];

        foreach ($plan as $lotId => $take) {
            $created[] = RewardPointMovement::query()->create([
                'lot_id' => $lotId,
                'kind' => RewardPointMovementKind::Debit,
                'delta' => $take,
                'operation_key' => RewardOperationKeys::lotAllocation($operationKey, $lotId),
                'aggregate_operation_key' => $operationKey,
                'linked_movement_id' => null,
                'reservation_key' => null,
                'award_id' => null,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'occurred_at' => $occurred,
            ]);
        }

        $this->markDepleted(array_keys($plan), $occurred);

        return $created;
    }

    /**
     * @return list<RewardPointMovement>
     */
    private function captureReservation(
        string $reservationKey,
        int $points,
        string $operationKey,
        string $reason,
        CarbonImmutable $occurred,
        string $actorType,
        ?string $actorId,
    ): array {
        // Preview the reservation without locks to bind the capture's
        // ownership terms; reserves and lots are append-only/immutable, so
        // the preview is stable and every mutation below re-reads under lock.
        $preview = RewardPointMovement::query()
            ->where('kind', RewardPointMovementKind::Reserve)
            ->where('reservation_key', $reservationKey)
            ->orderBy('id')
            ->get();

        if ($preview->isEmpty()) {
            throw new InvalidArgumentException('Unknown reservation key.');
        }

        $previewLot = RewardPointLot::query()->whereKey((string) $preview->first()->lot_id)->first();

        if (! $previewLot instanceof RewardPointLot) {
            throw new InvalidArgumentException('Reservation lot is missing.');
        }

        // Operation identity before locks: concurrent captures of any
        // reservation under the same debit key serialize here.
        $operation = RewardPointOperationAuthority::claim(
            RewardPointOperationAuthority::KIND_DEBIT,
            $operationKey,
            $this->terms(
                (string) $previewLot->recipient_type,
                (string) $previewLot->recipient_id,
                (string) $previewLot->family_key,
                (string) $previewLot->unit,
                $points,
                $reservationKey,
            ),
            'Debit aggregate',
        );

        if (! $operation->wasRecentlyCreated) {
            $replay = RewardPointMovement::query()
                ->where('aggregate_operation_key', $operationKey)
                ->orderBy('operation_key')
                ->get()
                ->all();

            if ($replay === []) {
                throw new InvalidArgumentException('Debit claim is incoherent; refusing to reallocate.');
            }

            $sum = 0;

            foreach ($replay as $movement) {
                if ($movement->kind !== RewardPointMovementKind::Debit
                    || (string) $movement->reservation_key !== $reservationKey
                ) {
                    throw new InvalidArgumentException('Debit aggregate is already bound to different terms; supply a fresh key.');
                }

                $sum += (int) $movement->delta;
            }

            if ($sum !== $points) {
                throw new InvalidArgumentException('Debit aggregate is already bound to a different amount; supply a fresh key.');
            }

            return $replay;
        }

        $reserves = RewardPointMovement::query()
            ->where('kind', RewardPointMovementKind::Reserve)
            ->where('reservation_key', $reservationKey)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($reserves->isEmpty()) {
            throw new InvalidArgumentException('Unknown reservation key.');
        }

        $resolved = RewardPointMovement::query()
            ->whereIn('linked_movement_id', $reserves->map(fn (RewardPointMovement $m): string => (string) $m->getKey())->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->exists();

        if ($resolved) {
            throw new InvalidArgumentException('Reservation is already resolved; capture requires an active reservation.');
        }

        $reserved = (int) $reserves->sum('delta');

        if ($reserved !== $points) {
            throw new InvalidArgumentException('Capture requires the exact reserved amount; reservations are atomic.');
        }

        $created = [];

        foreach ($reserves as $reserve) {
            $created[] = RewardPointMovement::query()->create([
                'lot_id' => $reserve->lot_id,
                'kind' => RewardPointMovementKind::Debit,
                'delta' => (int) $reserve->delta,
                'operation_key' => RewardOperationKeys::lotAllocation($operationKey, (string) $reserve->lot_id),
                'aggregate_operation_key' => $operationKey,
                'linked_movement_id' => $reserve->getKey(),
                'reservation_key' => $reservationKey,
                'award_id' => $reserve->award_id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => 'capture:'.$reason,
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
        ?string $reservationKey,
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
            if ($movement->kind !== RewardPointMovementKind::Debit) {
                throw new InvalidArgumentException('Debit aggregate is already bound to different terms; supply a fresh key.');
            }

            if ($reservationKey === null) {
                if ($movement->reservation_key !== null) {
                    throw new InvalidArgumentException('Debit aggregate is already bound to a reservation capture; supply a fresh key.');
                }
            } elseif ((string) $movement->reservation_key !== $reservationKey) {
                throw new InvalidArgumentException('Debit aggregate is already bound to different terms; supply a fresh key.');
            }

            $lot = $lots->get((string) $movement->lot_id);

            if (! $lot instanceof RewardPointLot
                || (string) $lot->recipient_type !== $recipientType
                || (string) $lot->recipient_id !== $recipientId
                || (string) $lot->family_key !== $familyKey
                || (string) $lot->unit !== $unit
            ) {
                throw new InvalidArgumentException('Debit aggregate is already bound to a different recipient, family, or unit.');
            }

            $sum += (int) $movement->delta;
        }

        if ($sum !== $points) {
            throw new InvalidArgumentException('Debit aggregate is already bound to a different amount; supply a fresh key.');
        }
    }

    /**
     * @param  list<string>  $lotIds
     */
    private function markDepleted(array $lotIds, CarbonImmutable $occurred): void
    {
        foreach ($lotIds as $lotId) {
            $lot = RewardPointLot::query()->whereKey($lotId)->lockForUpdate()->first();

            if (! $lot instanceof RewardPointLot || $lot->status !== RewardPointLotStatus::Open) {
                continue;
            }

            if (RewardPointLedger::isExpired($lot, $occurred)) {
                continue;
            }

            $fresh = RewardPointMovement::query()->where('lot_id', $lotId)->orderBy('id')->lockForUpdate()->get();

            $residual = RewardPointLedger::unreservedResidual($lot, $fresh);

            if ($residual === 0 && RewardPointLedger::activeReserved($lot, $fresh) === 0) {
                $lot->forceFill([
                    'status' => RewardPointLotStatus::Depleted,
                    'depleted_at' => $occurred,
                ])->save();
            }
        }
    }
}
