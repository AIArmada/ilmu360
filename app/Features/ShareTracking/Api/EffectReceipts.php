<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Writer and lease boundary for share effect receipts.
 *
 * Effects are frozen once per canonical effect key, leased to exactly one
 * dispatcher worker for five minutes, and then settled into a terminal state.
 * Every operation updates the table through the owning persistence writer so
 * the claim in feature.json stays the single source of truth.
 */
interface EffectReceipts
{
    /**
     * Freeze the projection effect for a share outcome business key.
     *
     * The first committed projection is authoritative: a replay with the same
     * key returns the existing row instead of rewriting its payload.
     */
    public function ensureProjection(
        string $businessKey,
        string $conversionId,
        string $affiliateId,
        string $linkId,
    ): EffectReceiptSnapshot;

    /**
     * Freeze the deferred ticket-affiliate reversal effect for a conversion.
     *
     * The effect key is the idempotent identity, so a duplicate deferral
     * reuses the existing receipt unchanged.
     */
    public function ensureTicketReversal(
        string $effectKey,
        string $registrationId,
        string $conversionId,
        string $orderId,
        string $reason,
    ): EffectReceiptSnapshot;

    /**
     * Lock and return pending effects plus leased effects whose lease is
     * expired, restricted to the effect types the caller owns.
     *
     * @param  list<string>  $ownedEffectTypes
     * @return list<EffectReceiptSnapshot>
     */
    public function claimableBatch(array $ownedEffectTypes, int $limit): array;

    /**
     * Lease one reclaimable effect to a worker for five minutes.
     *
     * Returns null when the receipt is missing, owned by another effect type,
     * terminal, or already leased by another live worker.
     *
     * @param  list<string>  $ownedEffectTypes
     */
    public function claim(
        string $receiptId,
        array $ownedEffectTypes,
        string $workerId,
        string $leaseToken,
    ): ?EffectReceiptSnapshot;

    /**
     * Lock and validate a live lease owned by the given worker.
     *
     * The caller must already be inside the transaction that applies the
     * effect so the row lock and the package writes commit together.
     */
    public function lockLease(
        string $receiptId,
        string $workerId,
        string $leaseToken,
    ): ?EffectReceiptSnapshot;

    public function markSucceeded(string $receiptId, string $workerId, string $leaseToken): void;

    public function markFailed(string $receiptId, string $workerId, string $leaseToken): void;

    public function markCancelled(string $receiptId, string $workerId, string $leaseToken): void;

    /**
     * Return a claimed effect to pending without settling it, for work that
     * must wait for an external transition such as an open payout.
     */
    public function releaseToPending(string $receiptId, string $workerId, string $leaseToken): void;

    /**
     * Lock the non-terminal effects of a source before it is corrected.
     *
     * Callers take these locks before the conversion lock to preserve the
     * dispatcher's receipt-then-conversion lock order.
     *
     * @return list<EffectReceiptSnapshot>
     */
    public function lockCancelableBySource(string $sourceId): array;

    /**
     * Cancel the non-terminal effects of a corrected source.
     */
    public function cancelPendingBySource(string $sourceId): void;
}
