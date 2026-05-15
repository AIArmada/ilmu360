<?php

namespace App\Data\Api\EventCheckIn;

use Spatie\LaravelData\Data;

/**
 * @phpstan-type EventCheckInStatePayload array{
 *   available: bool,
 *   reason: string|null,
 *   method: 'self_reported'|'registered_self_checkin',
 *   registration_id: string|null
 * }
 */
class EventCheckInStateData extends Data
{
    public function __construct(
        public bool $is_checked_in,
        public bool $available,
        public ?string $reason,
        public string $method,
        public ?string $registration_id,
    ) {}

    /**
     * @param  EventCheckInStatePayload  $state
     */
    public static function fromState(bool $isCheckedIn, array $state): self
    {
        return new self(
            is_checked_in: $isCheckedIn,
            available: (bool) $state['available'],
            reason: is_string($state['reason']) ? $state['reason'] : null,
            method: (string) $state['method'],
            registration_id: is_string($state['registration_id']) ? $state['registration_id'] : null,
        );
    }
}
