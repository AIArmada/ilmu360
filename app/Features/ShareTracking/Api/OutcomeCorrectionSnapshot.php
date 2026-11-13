<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Immutable projection of a recorded share-outcome correction.
 *
 * The correction row is the durable reversal evidence; callers receive this
 * value so the feature boundary never exposes a mutable Eloquent model.
 */
final readonly class OutcomeCorrectionSnapshot
{
    public function __construct(
        public string $id,
        public string $conversionId,
        public string $businessKey,
        public string $reason,
        public ?string $actorType,
        public ?string $actorId,
        public string $occurredAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
