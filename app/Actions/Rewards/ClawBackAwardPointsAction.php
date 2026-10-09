<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardReservationResolver;
use App\Enums\Rewards\RewardAdjustmentKind;
use App\Enums\Rewards\RewardPointLotStatus;
use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardAdjustment;
use App\Models\RewardAward;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\NullRewardReservationResolver;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardOperationKeys;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Point reversal settlement for a reversed award.
 *
 * Unspent available value is clawed back with negative adjust movements
 * (capped at the available remainder — never below zero, never a negative
 * bank debit). Expired remainders are recorded as evidence, never resurrected.
 *
 * Consumed value is NET confirmed consumption: gross debits on the award's
 * lots (original lots and their lineage-preserving refund lots alike) minus
 * confirmed refunds linked to those debits. Per-debit linkage attributes
 * multi-award debit/refund allocations and repeat spend/refund cycles
 * exactly; gross history stays in the movements, but only the outstanding
 * remainder opens a dispute obligation for human settlement. Replays collapse
 * onto the existing dispute and never add another liability.
 *
 * Active reservations are never counted as consumed and never left pending:
 * each pending reservation key on the award's lots must be attested
 * demonstrably unsubmitted by the reservation resolver, in which case its
 * pieces are voided atomically (ordinary release semantics — an expiry
 * movement on already-expired lots — plus the immediate claw of the freed
 * residual, so the void can never resurrect spendable value). Any key the
 * resolver cannot attest blocks the whole reversal truthfully: no status
 * change, no partial claw, no invented cancellation. The caller retries after
 * ordinary release/capture resolves the holds or after explicit host
 * attestation. The default null resolver attests nothing.
 *
 * @return array{clawed_points: int, consumed_points: int, dispute_id: ?string}
 */
final readonly class ClawBackAwardPointsAction
{
    use AsAction;

    public function __construct(
        private ?RewardReservationResolver $reservationResolver = null,
    ) {}

    /**
     * @return array{clawed_points: int, consumed_points: int, dispute_id: ?string}
     */
    public function handle(
        RewardAward|string $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?CarbonInterface $decidedAt = null,
    ): array {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $awardId = $award instanceof RewardAward ? (string) $award->getKey() : $award;
        $resolver = $this->reservationResolver ?? new NullRewardReservationResolver;

        return DB::transaction(function () use ($awardId, $actorType, $actorId, $actorRole, $reason, $decided, $resolver): array {
            $locked = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

            if (! $locked instanceof RewardAward) {
                throw new InvalidArgumentException('Unknown reward award.');
            }

            $lots = RewardPointLot::query()
                ->where('award_id', $locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $movements = $lots->isEmpty()
                ? collect()
                : RewardPointMovement::query()
                    ->whereIn('lot_id', $lots->map(fn (RewardPointLot $lot): string => (string) $lot->getKey())->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $this->voidAttestedReservations($lots, $movements, $resolver, $reason, $decided, $actorType, $actorId);

            $voidedKeys = $movements
                ->filter(fn (RewardPointMovement $movement): bool => str_starts_with((string) $movement->reason, 'reversal-void:'))
                ->map(fn (RewardPointMovement $movement): string => (string) $movement->reservation_key)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $voidedPoints = (int) $movements
                ->filter(fn (RewardPointMovement $movement): bool => str_starts_with((string) $movement->reason, 'reversal-void:'))
                ->sum('delta');

            $debitIds = $movements
                ->filter(fn (RewardPointMovement $movement): bool => $movement->kind === RewardPointMovementKind::Debit)
                ->map(fn (RewardPointMovement $movement): string => (string) $movement->getKey())
                ->all();

            // Locking re-read (lots-before-movements, sorted): concurrent
            // refunds serialize on the lot locks held above, and the lock makes
            // this read observe the latest committed rows on every isolation —
            // a plain read would miss a just-committed refund under
            // REPEATABLE READ and over-dispute. The sum runs in PHP because
            // PostgreSQL forbids aggregates under lock. A refund committed
            // first offsets consumption here; a later refund observes the
            // reversed award and refuses to resurrect corrected credit.
            $linkedRefunded = $debitIds === []
                ? 0
                : (int) RewardPointMovement::query()
                    ->where('kind', RewardPointMovementKind::Refund)
                    ->whereIn('linked_movement_id', $debitIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('delta')
                    ->sum();

            $clawed = 0;
            $grossSpent = 0;
            $expired = 0;
            $unit = null;

            foreach ($lots as $lot) {
                $unit ??= $lot->unit;
                $lotMovements = $movements->where('lot_id', (string) $lot->getKey());

                $credited = RewardPointLedger::sumKind($lotMovements, [
                    RewardPointMovementKind::Credit,
                    RewardPointMovementKind::Refund,
                ]) + RewardPointLedger::sumKind($lotMovements, [RewardPointMovementKind::Adjust]);
                $spent = RewardPointLedger::sumKind($lotMovements, [RewardPointMovementKind::Debit]);
                $reserved = RewardPointLedger::activeReserved($lot, $lotMovements);
                $lotExpired = RewardPointLedger::sumKind($lotMovements, [RewardPointMovementKind::Expire]);
                $residual = $credited - $spent - $reserved - $lotExpired;

                $grossSpent += $spent;
                $expired += $lotExpired;

                if (RewardPointLedger::isExpired($lot, $decided)) {
                    $expired += max(0, $residual);

                    continue;
                }

                if ($residual > 0) {
                    $clawed += $residual;

                    RewardPointMovement::query()->create([
                        'lot_id' => $lot->getKey(),
                        'kind' => RewardPointMovementKind::Adjust,
                        'delta' => -$residual,
                        'operation_key' => RewardOperationKeys::lotAllocation(
                            RewardOperationKeys::aggregate('clawback', 'award', (string) $locked->getKey(), (string) $lot->unit, $residual),
                            (string) $lot->getKey(),
                        ),
                        'aggregate_operation_key' => RewardOperationKeys::aggregate('clawback', 'award', (string) $locked->getKey(), (string) $lot->unit, $residual),
                        'linked_movement_id' => null,
                        'reservation_key' => null,
                        'award_id' => $locked->getKey(),
                        'actor_type' => $actorType,
                        'actor_id' => $actorId,
                        'reason' => 'reversal-clawback:'.$reason,
                        'occurred_at' => $decided,
                    ]);
                }

                if ($reserved === 0 && $lot->status === RewardPointLotStatus::Open) {
                    $lot->forceFill([
                        'status' => RewardPointLotStatus::Depleted,
                        'depleted_at' => $decided,
                    ])->save();
                }
            }

            // Net confirmed consumption: every linked refund demonstrably
            // returned value, so only the unrefunded remainder is outstanding
            // recovery liability. Bounded below at zero against incoherence.
            $consumed = max(0, $grossSpent - $linkedRefunded);

            $disputeId = null;

            if ($consumed > 0) {
                $operationKey = RewardOperationKeys::adjustment('dispute', 'award', (string) $locked->getKey(), 'consumed');

                // Locking read: a concurrent settlement of the same award
                // serializes on the award row, and the lock makes this check
                // observe its committed dispute on every isolation instead of
                // opening a second liability. Adjustments are leaf writes, so
                // no later family can invert against this lock.
                $existing = RewardAdjustment::query()
                    ->where('operation_key', $operationKey)
                    ->lockForUpdate()
                    ->first();

                if (! $existing instanceof RewardAdjustment) {
                    $adjustment = AppendRewardAdjustmentAction::run(
                        RewardAdjustmentKind::Dispute,
                        (string) $locked->getKey(),
                        null,
                        null,
                        $operationKey,
                        [
                            'type' => 'points',
                            'unit' => $unit,
                            'consumed_points' => $consumed,
                            'gross_spent_points' => $grossSpent,
                            'refunded_points' => $linkedRefunded,
                            'clawed_points' => $clawed,
                            'expired_points' => $expired,
                            'voided_reservations' => $voidedKeys,
                            'voided_points' => $voidedPoints,
                        ],
                        $actorType,
                        $actorId,
                        $actorRole,
                        'reversal-consumed-value:'.$reason,
                        ['note' => 'Net consumed value is not restored; human settlement required. Gross spend and linked refunds stay in history.'],
                        $decided,
                    );

                    $disputeId = (string) $adjustment->getKey();
                } else {
                    $disputeId = (string) $existing->getKey();
                }
            }

            return [
                'clawed_points' => $clawed,
                'consumed_points' => $consumed,
                'dispute_id' => $disputeId,
            ];
        });
    }

    /**
     * Voids every pending reservation piece on the award's lots after explicit
     * host attestation, appending the void movements to the locked collection
     * so the settlement loop below observes zero active reservations. Any
     * unattested key aborts the whole reversal with no partial effect.
     *
     * @param  Collection<int, RewardPointLot>  $lots
     * @param  Collection<int, RewardPointMovement>  $movements
     */
    private function voidAttestedReservations(
        Collection $lots,
        Collection $movements,
        RewardReservationResolver $resolver,
        string $reason,
        CarbonImmutable $decided,
        string $actorType,
        ?string $actorId,
    ): void {
        $linked = $movements
            ->filter(fn (RewardPointMovement $movement): bool => $movement->linked_movement_id !== null)
            ->map(fn (RewardPointMovement $movement): string => (string) $movement->linked_movement_id)
            ->all();

        $pending = $movements
            ->filter(fn (RewardPointMovement $movement): bool => $movement->kind === RewardPointMovementKind::Reserve)
            ->reject(fn (RewardPointMovement $movement): bool => in_array((string) $movement->getKey(), $linked, true))
            ->values();

        if ($pending->isEmpty()) {
            return;
        }

        $keys = $pending
            ->map(fn (RewardPointMovement $movement): string => $movement->reservation_key === null
                ? '(unkeyed-reservation)'
                : (string) $movement->reservation_key)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $unknown = [];

        foreach ($keys as $key) {
            if ($key === '(unkeyed-reservation)' || ! $resolver->mayVoidReservation($key)) {
                $unknown[] = $key;
            }
        }

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Refused: reservation(s) ['.implode(', ', $unknown).'] have unknown external status; '
                .'resolve them through release/capture or supply host attestation before reversal.'
            );
        }

        $byLot = $lots->keyBy(fn (RewardPointLot $lot): string => (string) $lot->getKey());

        foreach ($pending as $reserve) {
            $lot = $byLot->get((string) $reserve->lot_id);

            if (! $lot instanceof RewardPointLot) {
                throw new InvalidArgumentException('Reservation lot is missing.');
            }

            // Ordinary release semantics: an already-expired lot voids into an
            // expiry movement (never spendable), otherwise into a release whose
            // freed residual the settlement loop claws immediately below. The
            // operation key matches an ordinary release exactly, so a later
            // release replays onto these rows instead of resurrecting value,
            // and a later capture finds its reservation resolved. The lot's
            // expired stamp is left for the expiry job, which still owns any
            // unmaterialized residual.
            $void = RewardPointMovement::query()->create([
                'lot_id' => $lot->getKey(),
                'kind' => RewardPointLedger::isExpired($lot, $decided)
                    ? RewardPointMovementKind::Expire
                    : RewardPointMovementKind::Release,
                'delta' => (int) $reserve->delta,
                'operation_key' => RewardOperationKeys::reservationRelease(
                    (string) $reserve->reservation_key,
                    (string) $lot->getKey(),
                ),
                'aggregate_operation_key' => (string) $reserve->aggregate_operation_key,
                'linked_movement_id' => $reserve->getKey(),
                'reservation_key' => $reserve->reservation_key,
                'award_id' => $reserve->award_id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => 'reversal-void:'.$reason,
                'occurred_at' => $decided,
            ]);

            $movements->push($void);
        }
    }
}
