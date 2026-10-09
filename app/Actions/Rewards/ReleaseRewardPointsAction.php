<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardOperationKeys;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Reservation release: returns the full reserved amount to available — unless
 * the lot has expired, in which case the release becomes an immediate expiry
 * movement (reserved value is never resurrected as spendable). Reserved lots
 * survive expiry until this linked fulfillment resolves. Replays are safe:
 * already-resolved reservations return their existing movements, and a
 * concurrent same-reservation release collapses onto the winner.
 *
 * @return list<RewardPointMovement>
 */
final readonly class ReleaseRewardPointsAction
{
    use AsAction;

    /**
     * @return list<RewardPointMovement>
     */
    public function handle(
        string $reservationKey,
        string $reason,
        ?CarbonInterface $occurredAt = null,
        string $actorType = 'system',
        ?string $actorId = null,
    ): array {
        RewardOperationKeys::assertValid($reservationKey);
        CreditRewardPointsAction::assertActor($actorType, $actorId);
        CreditRewardPointsAction::assertReason($reason);

        $occurred = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($occurredAt)->setTimezone('UTC');

        try {
            return DB::transaction(fn (): array => $this->attempt(
                $reservationKey, $reason, $occurred, $actorType, $actorId,
            ));
        } catch (QueryException $exception) {
            // A concurrent same-reservation release won; collapse onto its
            // movements (read outside the rolled-back transaction).
            if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = RewardPointMovement::query()
                ->where('reservation_key', $reservationKey)
                ->whereIn('kind', [
                    RewardPointMovementKind::Release,
                    RewardPointMovementKind::Expire,
                    RewardPointMovementKind::Debit,
                ])
                ->orderBy('operation_key')
                ->get()
                ->all();

            if ($winner !== []) {
                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * @return list<RewardPointMovement>
     */
    private function attempt(
        string $reservationKey,
        string $reason,
        CarbonImmutable $occurred,
        string $actorType,
        ?string $actorId,
    ): array {
        // Preview the reservation without locks to find its lots; reserves
        // are append-only, so the preview set is stable and the lots below
        // are locked BEFORE any movement — lots-then-movements, matching
        // reserve/debit/expire/clawback/refund with no inversion.
        $preview = RewardPointMovement::query()
            ->where('kind', RewardPointMovementKind::Reserve)
            ->where('reservation_key', $reservationKey)
            ->orderBy('id')
            ->get();

        if ($preview->isEmpty()) {
            throw new InvalidArgumentException('Unknown reservation key.');
        }

        $previewLotIds = array_values(array_unique($preview->map(
            static fn (RewardPointMovement $movement): string => (string) $movement->lot_id
        )->all()));

        sort($previewLotIds);

        $lots = RewardPointLot::query()
            ->whereIn('id', $previewLotIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (RewardPointLot $lot): string => (string) $lot->getKey());

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
            ->whereIn('kind', [RewardPointMovementKind::Release, RewardPointMovementKind::Expire, RewardPointMovementKind::Debit])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $resolvedIds = $resolved->map(fn (RewardPointMovement $m): string => (string) $m->linked_movement_id)->all();
        $pending = $reserves->reject(fn (RewardPointMovement $m): bool => in_array((string) $m->getKey(), $resolvedIds, true));

        if ($pending->isEmpty()) {
            return $resolved->sortBy(fn (RewardPointMovement $m): string => $m->operation_key)->values()->all();
        }

        $created = [];

        foreach ($pending as $reserve) {
            $lot = $lots->get((string) $reserve->lot_id);

            if (! $lot instanceof RewardPointLot) {
                throw new InvalidArgumentException('Reservation lot is missing.');
            }

            $expired = $lot->expires_at !== null
                && ! CarbonImmutable::parse($lot->expires_at)->setTimezone('UTC')->greaterThan($occurred);

            $created[] = RewardPointMovement::query()->create([
                'lot_id' => $lot->getKey(),
                'kind' => $expired ? RewardPointMovementKind::Expire : RewardPointMovementKind::Release,
                'delta' => (int) $reserve->delta,
                'operation_key' => RewardOperationKeys::reservationRelease($reservationKey, (string) $lot->getKey()),
                'aggregate_operation_key' => (string) $reserve->aggregate_operation_key,
                'linked_movement_id' => $reserve->getKey(),
                'reservation_key' => $reservationKey,
                'award_id' => $reserve->award_id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => ($expired ? 'release-after-expiry:' : 'release:').$reason,
                'occurred_at' => $occurred,
            ]);

            if ($expired && $lot->expired_at === null) {
                $lot->forceFill(['expired_at' => $occurred])->save();
            }
        }

        return $created;
    }
}
