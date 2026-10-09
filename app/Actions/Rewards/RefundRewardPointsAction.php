<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardPointLotStatus;
use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardAward;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardOperationKeys;
use App\Support\Rewards\RewardPointLedger;
use App\Support\Rewards\RewardPointOperationAuthority;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Linked refund: restores value from a prior debit aggregate into NEW lots —
 * one per original debit lot with deterministic allocations — never
 * resurrects the expired lot itself, never restores beyond the debited
 * amount, and never creates cash.
 *
 * Identity: the caller owns a canonical refund operation key, claimed as one
 * immutable logical-operation row binding the linked debit aggregate, amount,
 * and recipient scope BEFORE any lot is locked. Same-key replays collapse
 * onto the same movements; changed amount/terms reuse fails explicitly.
 * Distinct partial refunds require distinct keys; cumulative refunds across
 * all keys are bounded by the debited total under sorted
 * awards-then-lots-then-movements locks, with the full recipient lot scope
 * locked up front so sequence allocation never acquires lots after movements.
 *
 * Policy: each refund lot inherits its original lot's recipient, family,
 * unit, program, award, and expiry — never caller-supplied perpetual terms.
 * After-expiry refunds inherit past expiries and report as expired value,
 * never as spendable resurrection. Provenance for every original lot is
 * preserved via per-lot lots and linked debit movements.
 *
 * Corrected credit never resurrects: original lots whose award is already
 * reversed are excluded from refundable sourcing — the reversed portion stays
 * in the reversal dispute for human settlement instead of minting fresh
 * spendable lots. Multi-award debits still refund their live lots' portions
 * in full; only the corrected-credit remainder refuses.
 *
 * @return list<RewardPointMovement>
 */
final readonly class RefundRewardPointsAction
{
    use AsAction;

    /**
     * @return list<RewardPointMovement>
     */
    public function handle(
        string $refundOperationKey,
        string $linkedAggregateKey,
        int $points,
        string $reason,
        ?CarbonInterface $occurredAt = null,
        string $actorType = 'system',
        ?string $actorId = null,
    ): array {
        RewardOperationKeys::assertValid($refundOperationKey);
        RewardOperationKeys::assertValid($linkedAggregateKey);
        CreditRewardPointsAction::assertActor($actorType, $actorId);
        CreditRewardPointsAction::assertReason($reason);

        if ($points < 1) {
            throw new InvalidArgumentException('Refund points must be a positive integer.');
        }

        $occurred = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($occurredAt)->setTimezone('UTC');

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): array => $this->attempt(
                    $refundOperationKey, $linkedAggregateKey, $points, $reason, $occurred, $actorType, $actorId,
                ));
            } catch (QueryException $exception) {
                // Deadlock victims retry with the same key: the claim is
                // idempotent, so the retry preserves a single immutable
                // operation and every loser effect was rolled back already.
                if (self::isDeadlock($exception) && $attempt < 3) {
                    continue;
                }

                // A concurrent same-key refund won the operation claim;
                // collapse onto it only when its bound debit aggregate and
                // amount match. Mismatched contenders fail with no effect.
                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $operation = RewardPointOperationAuthority::find(
                    RewardPointOperationAuthority::KIND_REFUND,
                    $refundOperationKey,
                );

                if ($operation === null) {
                    throw $exception;
                }

                RewardPointOperationAuthority::assertTermsMatch(
                    $operation,
                    $this->winnerTerms($linkedAggregateKey, $points),
                    'Refund operation key',
                );

                $winner = RewardPointMovement::query()
                    ->where('aggregate_operation_key', $refundOperationKey)
                    ->orderBy('operation_key')
                    ->get()
                    ->all();

                if ($winner === []) {
                    throw $exception;
                }

                self::assertReplayBinds($winner, $linkedAggregateKey, $points);

                return $winner;
            }
        }
    }

    /**
     * @return list<RewardPointMovement>
     */
    private function attempt(
        string $refundOperationKey,
        string $linkedAggregateKey,
        int $points,
        string $reason,
        CarbonImmutable $occurred,
        string $actorType,
        ?string $actorId,
    ): array {
        // Preview the linked debit without locks: debits and lots are
        // append-only/immutable, so the scope derivation is stable and every
        // mutation below re-reads under lock.
        $preview = RewardPointMovement::query()
            ->where('aggregate_operation_key', $linkedAggregateKey)
            ->where('kind', RewardPointMovementKind::Debit)
            ->orderBy('lot_id')
            ->get();

        if ($preview->isEmpty()) {
            throw new InvalidArgumentException('Refund requires a prior debit aggregate; no new value is minted.');
        }

        $previewLotIds = array_values(array_unique($preview->map(
            static fn (RewardPointMovement $movement): string => (string) $movement->lot_id
        )->all()));

        sort($previewLotIds);

        $previewLots = RewardPointLot::query()
            ->whereIn('id', $previewLotIds)
            ->orderBy('id')
            ->get();

        if ($previewLots->count() !== count($previewLotIds)) {
            throw new InvalidArgumentException('Linked debit lot is missing.');
        }

        $scope = $previewLots->first();

        if (! $scope instanceof RewardPointLot) {
            throw new InvalidArgumentException('Linked debit lot is missing.');
        }

        foreach ($previewLots as $lot) {
            if ((string) $lot->recipient_type !== (string) $scope->recipient_type
                || (string) $lot->recipient_id !== (string) $scope->recipient_id
                || (string) $lot->family_key !== (string) $scope->family_key
                || (string) $lot->unit !== (string) $scope->unit
            ) {
                throw new InvalidArgumentException('Linked debit spans multiple recipients, families, or units; refusing incoherent refund.');
            }
        }

        // Operation identity before locks: concurrent refunds under the same
        // key — even against different linked debits — serialize here.
        $operation = RewardPointOperationAuthority::claim(
            RewardPointOperationAuthority::KIND_REFUND,
            $refundOperationKey,
            [
                'recipient_type' => (string) $scope->recipient_type,
                'recipient_id' => (string) $scope->recipient_id,
                'family_key' => (string) $scope->family_key,
                'unit' => (string) $scope->unit,
                'points' => $points,
                'linked_aggregate_key' => $linkedAggregateKey,
            ],
            'Refund operation key',
        );

        if (! $operation->wasRecentlyCreated) {
            $existing = RewardPointMovement::query()
                ->where('aggregate_operation_key', $refundOperationKey)
                ->orderBy('operation_key')
                ->get()
                ->all();

            if ($existing === []) {
                throw new InvalidArgumentException('Refund claim is incoherent; refusing to reallocate.');
            }

            self::assertReplayBinds($existing, $linkedAggregateKey, $points);

            return $existing;
        }

        // Awards before lots (global order): lock the original lots' awards so
        // the reversal-status partition below observes the latest committed
        // status on every isolation — a plain read would miss a
        // just-committed reversal under REPEATABLE READ and resurrect
        // corrected credit as spendable lots. Lots are immutable and debits
        // append-only, so the preview-derived award set is exact.
        $previewAwardIds = array_values(array_unique($previewLots
            ->map(static fn (RewardPointLot $lot): ?string => $lot->award_id === null ? null : (string) $lot->award_id)
            ->filter()
            ->all()));

        sort($previewAwardIds);

        $lockedPreviewAwards = $previewAwardIds === []
            ? collect()
            : RewardAward::query()
                ->whereIn('id', $previewAwardIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        if ($lockedPreviewAwards->count() !== count($previewAwardIds)) {
            throw new InvalidArgumentException('Linked debit award is missing.');
        }

        $reversedAwardIds = $lockedPreviewAwards
            ->filter(static fn (RewardAward $award): bool => $award->status === RewardAwardStatus::Reversed)
            ->map(static fn (RewardAward $award): string => (string) $award->getKey())
            ->all();

        // Full recipient-scope lots up front, sorted by id: this single
        // acquisition covers the original debit lots AND the sequence scope
        // for the new refund lots, so no lot is ever locked after movements.
        $scopedLots = RewardPointLot::query()
            ->where('recipient_type', (string) $scope->recipient_type)
            ->where('recipient_id', (string) $scope->recipient_id)
            ->where('family_key', (string) $scope->family_key)
            ->where('unit', (string) $scope->unit)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $originalLots = $scopedLots
            ->filter(static fn (RewardPointLot $lot): bool => in_array((string) $lot->getKey(), $previewLotIds, true))
            ->keyBy(static fn (RewardPointLot $lot): string => (string) $lot->getKey());

        if ($originalLots->count() !== count($previewLotIds)) {
            throw new InvalidArgumentException('Linked debit lot is missing.');
        }

        $debits = RewardPointMovement::query()
            ->where('aggregate_operation_key', $linkedAggregateKey)
            ->where('kind', RewardPointMovementKind::Debit)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($debits->isEmpty()) {
            throw new InvalidArgumentException('Refund requires a prior debit aggregate; no new value is minted.');
        }

        $debitIds = $debits->map(static fn (RewardPointMovement $movement): string => (string) $movement->getKey())->all();

        $priorRefunds = RewardPointMovement::query()
            ->where('kind', RewardPointMovementKind::Refund)
            ->whereIn('linked_movement_id', $debitIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $debited = (int) $debits->sum('delta');
        $refunded = (int) $priorRefunds->sum('delta');

        if ($points > $debited - $refunded) {
            throw new InvalidArgumentException('Refund exceeds the unrefunded debited amount; no new value is minted.');
        }

        // Reversed awards cannot source spendable refunds: their lots' debited
        // value was clawed or disputed at reversal, so refunding it would
        // resurrect corrected credit. Statuses come from the award rows locked
        // above — a concurrent reversal either committed first (visible here)
        // or observes this refund's lots afterwards.
        $liveLots = $originalLots->reject(
            static fn (RewardPointLot $lot): bool => $lot->award_id !== null
                && in_array((string) $lot->award_id, $reversedAwardIds, true)
        );
        $reversedLotCount = $originalLots->count() - $liveLots->count();

        $orderedLots = RewardPointLedger::oldestExpiringFirst($liveLots->values());

        $plan = [];
        $remaining = $points;
        $liveRefundable = 0;

        foreach ($orderedLots as $lot) {
            $lotId = (string) $lot->getKey();
            $lotDebits = $debits->filter(
                static fn (RewardPointMovement $movement): bool => (string) $movement->lot_id === $lotId
            );
            $lotDebitIds = $lotDebits->map(
                static fn (RewardPointMovement $movement): string => (string) $movement->getKey()
            )->all();
            $lotDebited = (int) $lotDebits->sum('delta');
            $lotRefunded = (int) $priorRefunds->filter(
                fn (RewardPointMovement $movement): bool => in_array((string) $movement->linked_movement_id, $lotDebitIds, true)
            )->sum('delta');
            $refundable = $lotDebited - $lotRefunded;
            $liveRefundable += max(0, $refundable);

            if ($remaining <= 0) {
                continue;
            }

            if ($refundable <= 0) {
                continue;
            }

            $take = min($refundable, $remaining);
            $plan[$lotId] = $take;
            $remaining -= $take;
        }

        if ($remaining > 0) {
            if ($reversedLotCount > 0) {
                throw new InvalidArgumentException(
                    'Refused: '.$reversedLotCount.' original lot(s) belong to reversed award(s) and cannot source a spendable refund; '
                    .'only '.$liveRefundable.' of '.$points.' requested points are refundable from live lots — '
                    .'route the corrected-credit remainder through dispute settlement.'
                );
            }

            throw new InvalidArgumentException('Refund allocation is incoherent; refusing to mint value.');
        }

        $created = [];

        // Sequences come from the already-locked scope set plus the lots
        // created below — no further lot query, so the acquisition order
        // stays lots-before-movements against credit/reserve/debit/expiry.
        $nextSeq = ((int) $scopedLots->max('seq')) + 1;

        foreach ($plan as $originalLotId => $take) {
            $original = $originalLots->get((string) $originalLotId);

            if (! $original instanceof RewardPointLot) {
                throw new InvalidArgumentException('Linked debit lot is missing.');
            }

            $operationKey = RewardOperationKeys::lotAllocation($refundOperationKey, (string) $originalLotId);

            if (RewardPointMovement::query()->where('operation_key', $operationKey)->exists()) {
                throw new InvalidArgumentException('Refund operation key is already bound to different allocations; supply a fresh key.');
            }

            $seq = $nextSeq;
            $nextSeq++;

            $lot = RewardPointLot::query()->create([
                'recipient_type' => $original->recipient_type,
                'recipient_id' => $original->recipient_id,
                'family_key' => $original->family_key,
                'program_id' => $original->program_id,
                'award_id' => $original->award_id,
                'seq' => $seq,
                'unit' => $original->unit,
                'credited_points' => $take,
                'source_operation_key' => $operationKey,
                'available_at' => $occurred,
                'expires_at' => $original->expires_at,
                'status' => RewardPointLotStatus::Open,
                'credited_at' => $occurred,
                'depleted_at' => null,
                'expired_at' => null,
            ]);

            $linkedDebit = $debits->first(
                fn (RewardPointMovement $movement): bool => (string) $movement->lot_id === (string) $originalLotId
            );

            $created[] = RewardPointMovement::query()->create([
                'lot_id' => $lot->getKey(),
                'kind' => RewardPointMovementKind::Refund,
                'delta' => $take,
                'operation_key' => $operationKey,
                'aggregate_operation_key' => $refundOperationKey,
                'linked_movement_id' => $linkedDebit instanceof RewardPointMovement ? $linkedDebit->getKey() : null,
                'reservation_key' => null,
                'award_id' => $original->award_id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'occurred_at' => $occurred,
            ]);
        }

        return $created;
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
     * Outside-transaction terms for winner verification: the recipient scope
     * is derived from the linked debit's immutable lots, exactly as the
     * in-transaction preview derives it.
     *
     * @return array{recipient_type: string, recipient_id: string, family_key: string, unit: string, points: int, linked_aggregate_key: string}
     */
    private function winnerTerms(string $linkedAggregateKey, int $points): array
    {
        $debit = RewardPointMovement::query()
            ->where('aggregate_operation_key', $linkedAggregateKey)
            ->where('kind', RewardPointMovementKind::Debit)
            ->orderBy('lot_id')
            ->first();

        $lot = $debit instanceof RewardPointMovement
            ? RewardPointLot::query()->whereKey((string) $debit->lot_id)->first()
            : null;

        if (! $lot instanceof RewardPointLot) {
            throw new InvalidArgumentException('Refund requires a prior debit aggregate; no new value is minted.');
        }

        return [
            'recipient_type' => (string) $lot->recipient_type,
            'recipient_id' => (string) $lot->recipient_id,
            'family_key' => (string) $lot->family_key,
            'unit' => (string) $lot->unit,
            'points' => $points,
            'linked_aggregate_key' => $linkedAggregateKey,
        ];
    }

    /**
     * @param  list<RewardPointMovement>  $existing
     */
    public static function assertReplayBinds(array $existing, string $linkedAggregateKey, int $points): void
    {
        $sum = 0;

        $linkedIds = array_values(array_unique(array_map(
            static fn (RewardPointMovement $movement): string => (string) $movement->linked_movement_id,
            $existing,
        )));

        $linked = $linkedIds === []
            ? collect()
            : RewardPointMovement::query()->whereIn('id', $linkedIds)->get()->keyBy(
                static fn (RewardPointMovement $movement): string => (string) $movement->getKey()
            );

        foreach ($existing as $movement) {
            if ($movement->kind !== RewardPointMovementKind::Refund) {
                throw new InvalidArgumentException('Refund operation key is already bound to different terms; supply a fresh key.');
            }

            $debit = $linked->get((string) $movement->linked_movement_id);

            if (! $debit instanceof RewardPointMovement
                || $debit->kind !== RewardPointMovementKind::Debit
                || (string) $debit->aggregate_operation_key !== $linkedAggregateKey
            ) {
                throw new InvalidArgumentException('Refund operation key is already bound to a different debit aggregate; supply a fresh key.');
            }

            $sum += (int) $movement->delta;
        }

        if ($sum !== $points) {
            throw new InvalidArgumentException('Refund operation key is already bound to a different amount; supply a fresh key.');
        }
    }
}
