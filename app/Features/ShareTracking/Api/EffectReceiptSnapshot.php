<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Immutable projection of a share effect receipt.
 *
 * Effect receipts are the durable outbox for downstream share projections.
 * The row is the dispatch authority; callers receive this value so the
 * feature boundary never exposes a mutable Eloquent model.
 */
final readonly class EffectReceiptSnapshot
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $effectKey,
        public string $sourceType,
        public string $sourceId,
        public string $effectType,
        public string $status,
        public array $payload,
        public int $attempts,
        public ?string $leaseToken,
        public ?string $leasedBy,
        public ?string $leaseExpiresAt,
        public ?string $succeededAt,
        public ?string $failedAt,
        public ?string $cancelledAt,
    ) {}
}
