<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Models\RewardPointReservationSubmission;
use App\Support\Rewards\RewardLedgerLockOrder;
use App\Support\Rewards\RewardOperationKeys;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Marks a point reservation as handed to external fulfillment.
 *
 * Host flows (benefit redemption, voucher issue, provider calls) MUST call
 * this BEFORE the external call. The action itself takes the production
 * locks: when ledger movements exist for the key it locks the reservation's
 * lots in sorted id order FIRST (lots → registry row, the same relative
 * order as reversal attestation). The INITIAL handoff mark (no registry
 * row yet) additionally requires at least one pending (unresolved) reserve
 * piece — a reversal that committed first voids the pieces, so a late
 * initial mark observes the void instead of reviving a submitted shadow.
 * Later forward transitions on an existing row (sending → submitted →
 * delivered, e.g. after capture) were attested at handoff time and
 * proceed. Keys with no ledger movements are non-point handoffs and
 * proceed without lot locks.
 *
 * The row is the reversal attestation authority: once a
 * `sending`/`submitted`/`unknown`/`delivered` row exists, award reversal
 * refuses to void the reservation. Only a confirmed `failed` handoff
 * (remote proved non-delivery and the operator resolved it) releases the
 * reservation back to voidable.
 *
 * Idempotent per reservation key: replays return the stored row when the
 * status transition is legal, and throw on conflicting-terminal replay.
 */
final readonly class MarkRewardReservationSubmittedAction
{
    use AsAction;

    public function handle(
        string $reservationKey,
        string $status,
        ?string $reference = null,
        ?CarbonInterface $at = null,
    ): RewardPointReservationSubmission {
        RewardOperationKeys::assertValid($reservationKey);

        if (! in_array($status, RewardPointReservationSubmission::statuses(), true)) {
            throw new InvalidArgumentException('Unknown reservation submission status.');
        }

        if ($reference !== null && ($reference === '' || mb_strlen($reference) > 191)) {
            throw new InvalidArgumentException('Submission reference must be null or 1..191 characters.');
        }

        $stamped = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($at)->setTimezone('UTC');

        return DB::transaction(function () use ($reservationKey, $status, $reference, $stamped): RewardPointReservationSubmission {
            $hasLedgerPieces = $status !== RewardPointReservationSubmission::STATUS_FAILED
                ? $this->lockReservationLots($reservationKey)
                : false;

            $existing = RewardPointReservationSubmission::query()
                ->where('reservation_key', $reservationKey)
                ->lockForUpdate()
                ->first();

            if ($hasLedgerPieces && ! $existing instanceof RewardPointReservationSubmission) {
                $this->assertPendingPieces($reservationKey);
            }

            if (! $existing instanceof RewardPointReservationSubmission) {
                return RewardPointReservationSubmission::query()->create([
                    'reservation_key' => $reservationKey,
                    'status' => $status,
                    'reference' => $reference,
                    'submitted_at' => $stamped,
                    'resolved_at' => $status === RewardPointReservationSubmission::STATUS_FAILED ? $stamped : null,
                ]);
            }

            $current = (string) $existing->getAttribute('status');

            if ($current === $status) {
                return $existing;
            }

            $terminal = [
                RewardPointReservationSubmission::STATUS_DELIVERED,
                RewardPointReservationSubmission::STATUS_FAILED,
            ];

            if (in_array($current, $terminal, true)) {
                throw new InvalidArgumentException("Reservation submission [{$reservationKey}] is terminal [{$current}]; refusing [{$status}].");
            }

            if ($status === RewardPointReservationSubmission::STATUS_FAILED) {
                $existing->forceFill([
                    'status' => $status,
                    'reference' => $reference ?? $existing->getAttribute('reference'),
                    'resolved_at' => $stamped,
                ])->save();

                return $existing->refresh();
            }

            $existing->forceFill([
                'status' => $status,
                'reference' => $reference ?? $existing->getAttribute('reference'),
            ])->save();

            return $existing->refresh();
        });
    }

    /**
     * Production lot guard: lock every lot touched by the key's ledger
     * movements in sorted id order. Returns whether ledger pieces exist —
     * no movements means a non-point handoff with nothing to lock.
     */
    private function lockReservationLots(string $reservationKey): bool
    {
        $lotIds = RewardPointMovement::query()
            ->where('reservation_key', $reservationKey)
            ->orderBy('id')
            ->get()
            ->map(static fn (RewardPointMovement $movement): string => (string) $movement->lot_id)
            ->unique()
            ->values()
            ->all();

        if ($lotIds === []) {
            return false;
        }

        foreach (RewardLedgerLockOrder::sorted($lotIds) as $lotId) {
            RewardPointLot::query()->whereKey($lotId)->lockForUpdate()->first();
        }

        return true;
    }

    /**
     * Initial-handoff attestation: under the held lot locks, the key must
     * still own at least one pending (unlinked) reserve piece. Resolved
     * (released/captured/voided) reservations refuse a late first mark.
     */
    private function assertPendingPieces(string $reservationKey): void
    {
        $locked = RewardPointMovement::query()
            ->where('reservation_key', $reservationKey)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $linked = $locked
            ->filter(static fn (RewardPointMovement $movement): bool => $movement->linked_movement_id !== null)
            ->map(static fn (RewardPointMovement $movement): string => (string) $movement->linked_movement_id)
            ->all();

        $pending = $locked
            ->filter(static fn (RewardPointMovement $movement): bool => $movement->kind === RewardPointMovementKind::Reserve)
            ->reject(static fn (RewardPointMovement $movement): bool => in_array((string) $movement->getKey(), $linked, true));

        if ($pending->isEmpty()) {
            throw new InvalidArgumentException(
                "Reservation [{$reservationKey}] has no pending pieces; refusing a late handoff mark on resolved value."
            );
        }
    }
}
