<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

/**
 * @phpstan-type EventCheckInStatePayload array{
 *   available: bool,
 *   reason: string|null,
 *   method: 'self_reported'|'registered_self_checkin',
 *   registration_id: string|null
 * }
 */
final readonly class EventCheckInStateData
{
    public function __construct(
        public bool $is_checked_in,
        public bool $available,
        public ?string $reason,
        public string $method,
        public ?string $registration_id,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
