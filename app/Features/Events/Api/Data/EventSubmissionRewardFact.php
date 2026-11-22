<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

/** Immutable evidence of an event submission; the Eloquent model stays in Events. */
final readonly class EventSubmissionRewardFact
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public ?string $submitterType,
        public ?string $submitterId,
        public ?string $status,
        public ?string $occurredAt,
        public array $metadata,
        public ?string $fraudStatus,
        public bool $isAmbiguousDevice,
        public bool $isBot,
    ) {}
}
