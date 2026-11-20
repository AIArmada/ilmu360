<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Writer boundary for share-outcome corrections.
 *
 * A correction is recorded once per business key when a share outcome is
 * reversed. The business key is the idempotent identity: a duplicate or
 * concurrent call replays the first committed row instead of overwriting its
 * conversion, reason, actor or occurrence time.
 */
interface OutcomeCorrections
{
    /**
     * Unlocked read of the recorded correction for exactly one business key.
     *
     * Reward source-fact readers need the durable reversal evidence without
     * opening a transaction or acquiring a lock.
     */
    public function findByBusinessKey(string $businessKey): ?OutcomeCorrectionSnapshot;

    /**
     * Oldest recorded corrections up to the requested limit, ordered by
     * occurrence time then id — the order reward correction sweeps consume.
     *
     * @return list<OutcomeCorrectionSnapshot>
     */
    public function oldest(int $limit): array;

    /**
     * Must be invoked inside the receipt-processing transaction. Acquire a
     * lock on any existing correction before deciding whether to project.
     */
    public function hasForBusinessKeyUnderLock(string $businessKey): bool;

    /** Lock and return an existing correction for idempotent replay. */
    public function findByBusinessKeyUnderLock(string $businessKey): ?OutcomeCorrectionSnapshot;

    /**
     * Record the correction for a reversed share outcome.
     *
     * The occurrence time is captured when the row is created. The row is
     * written through Eloquent so the registered correction observer still
     * dispatches its referral and reward jobs.
     */
    public function ensure(
        string $businessKey,
        string $conversionId,
        string $reason,
        ?string $actorType = null,
        ?string $actorId = null,
    ): OutcomeCorrectionSnapshot;
}
