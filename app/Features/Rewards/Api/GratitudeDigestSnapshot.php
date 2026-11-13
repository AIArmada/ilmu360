<?php

declare(strict_types=1);

namespace App\Features\Rewards\Api;

/**
 * Immutable gratitude digest slot projection.
 *
 * Times are ISO 8601 strings and payload material is the stored scalar/array
 * shape; no model or framework runtime type crosses the boundary.
 */
final readonly class GratitudeDigestSnapshot
{
    /**
     * @param  list<string>  $categories
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $recipientType,
        public string $recipientId,
        public string $localDay,
        public string $planKey,
        public string $status,
        public array $categories,
        public array $payload,
        public string $timezone,
        public ?string $dueAt,
        public ?string $sentAt,
        public ?string $cancelledAt,
        public ?string $communicationId,
    ) {}
}
