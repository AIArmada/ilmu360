<?php

declare(strict_types=1);

namespace App\Features\Rewards\Api;

use DateTimeImmutable;

/**
 * Gratitude digest planning, cancellation and dispatch.
 *
 * The boundary is scalar and immutable: times cross as `DateTimeImmutable` and
 * every slot read returns a snapshot, never a mutable model.
 */
interface GratitudeDigest
{
    public const string TRIGGER = 'share_gratitude_digest';

    /**
     * @param  list<string>  $categories
     */
    public function plan(
        string $recipientType,
        string $recipientId,
        array $categories = ['awards'],
        ?DateTimeImmutable $decidedAt = null,
    ): GratitudeDigestSnapshot;

    public function cancel(
        string $slotId,
        string $reason,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        bool $force = false,
        ?DateTimeImmutable $decidedAt = null,
    ): GratitudeDigestSnapshot;

    public function cancelPendingForRecipient(
        string $recipientType,
        string $recipientId,
        string $reason,
        ?DateTimeImmutable $decidedAt = null,
    ): int;

    /**
     * @return array{dispatched: int, reconciled: int, cancelled: int, skipped: int}
     */
    public function dispatchDue(int $batch = 50, ?DateTimeImmutable $now = null): array;
}
