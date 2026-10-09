<?php

declare(strict_types=1);

namespace App\Support\Rewards;

/**
 * Global ledger lock order (extends the core convention).
 *
 * Logical point operations (unique claim, not a lock) -> source
 * coordinators -> programs -> claims -> enrollments (+acceptances/periods)
 * -> milestone cycles -> milestone contributions -> awards (+entries) ->
 * appeals (+decisions) -> cap counters -> point lots -> point movements ->
 * badge grants -> adjustments. Every multi-row lock is taken in sorted key
 * order (ids, or family keys for claims, or cap_key|window_key for caps).
 * Single-row lookups use their unique keys.
 *
 * Proven orderings:
 * - Reserve, debit, and refund claim one immutable logical-operation row by
 *   (kind, operation key) BEFORE any lot lock; the unique claim is the
 *   single serialization point for concurrent same-key operations, including
 *   disjoint-lot contenders. Credit serializes on its single unique movement
 *   key; expire/release keys are per-lot/per-reservation and take lot locks
 *   in the same order.
 * - Evaluation takes coordinator -> program -> claims -> enrollments ->
 *   awards -> caps. Direct approvals take coordinator -> program -> claims
 *   -> award (plus cycle locks for milestone aggregates, plus cap reads for
 *   held-obligation verification). Appeal overturns take the SAME chain
 *   before award -> appeal, so the nested overturn transition re-locks held
 *   rows and only acquires caps after appeals; no path ever takes caps (or
 *   coordinators) after appeals from the other direction, so no cycle
 *   exists. Plain appeal opening/review take awards -> appeals only.
 * - Reserve, debit, expire, release, clawback, and refund all take lots
 *   before movements with lots sorted by id; previews that find lots or
 *   reservation scope run without locks (append-only/immutable reads) and
 *   every mutation re-reads under lock. Refund locks the FULL
 *   recipient-scope lot set up front, so sequence allocation computes the
 *   max in PHP from held rows and never locks lots after movements; credit
 *   locks the same scope the same way. Lot seq allocation locks lots by id
 *   and computes the max in PHP (PostgreSQL forbids aggregates under lock).
 * - Milestone recording takes programs -> enrollments -> cycles ->
 *   contributions -> awards; invalidation takes cycles -> contributions ->
 *   awards -> lots -> badges. Milestone fulfillment takes cycles ->
 *   contributions -> award under the same relative order. No path holding a
 *   later family ever requests a cycle lock, so no lock cycle exists.
 * - Award fulfillment and reversal nest point credit/clawback (lots ->
 *   movements) and badge grant/revoke strictly after the award lock; nested
 *   adjustment appends are leaf writes after every lock they depend on.
 *   Reversal voids attested reservations with ordinary release/expiry
 *   movements (same lots-before-movements order, same per-lot keys, so a
 *   racing release replays instead of double-creating); refund locks the
 *   original lots' awards before lots and partitions reversed awards from
 *   those locked rows, so the status read is current on every isolation
 *   and a racing reversal observes the refund's committed lots first.
 * - Benefit operations take grant -> item -> stock movements -> lots
 *   (sorted) -> point movements (sorted) -> submission -> leaf inserts.
 *   Redemption (which creates the grant) takes item -> stock -> lots;
 *   direct-award fulfillment takes item -> stock; fulfill/cancel/expire/
 *   revoke take the grant first and converge through the same suffix, so
 *   no benefit path ever inverts against another or against reversal
 *   (award -> lots -> movements -> submission). Consume and revoke lock
 *   voucher -> usage rows after the grant and before lots; usage
 *   recording holds the voucher row while inserting, so coupon-use races
 *   serialize on the voucher row.
 *
 * Every multi-row lock is taken in sorted key order. Random UUIDs are row
 * identity only; semantic idempotency comes from stable operation keys.
 */
final class RewardLedgerLockOrder
{
    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function sorted(array $keys): array
    {
        $sorted = array_values(array_unique($keys));
        sort($sorted);

        return $sorted;
    }
}
