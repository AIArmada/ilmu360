<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Immutable projection of a frozen share source-attribution receipt.
 *
 * The receipt row is the attribution authority; this value carries the
 * committed decision across the feature boundary without exposing a mutable
 * Eloquent model.
 */
final readonly class SourceAttributionReceiptSnapshot
{
    public function __construct(
        public string $id,
        public string $businessKey,
        public ?string $attributionId,
        public string $outcomeType,
        public string $sourceTransitionId,
        public string $provenance,
        public string $definitionVersion,
        public string $occurredAt,
    ) {}

    public function isAttributed(): bool
    {
        return $this->attributionId !== null;
    }
}
