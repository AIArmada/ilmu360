<?php

namespace App\Data\Api\EventCheckIn;

use App\Models\EventCheckin;
use DateTimeInterface;
use Spatie\LaravelData\Data;

class EventCheckInData extends Data
{
    public function __construct(
        public string $id,
        public string $event_id,
        public string $user_id,
        public ?string $registration_id,
        public string $method,
        public ?string $checked_in_at,
    ) {}

    public static function fromModel(EventCheckin $checkin): self
    {
        return new self(
            id: (string) $checkin->id,
            event_id: (string) $checkin->event_id,
            user_id: (string) $checkin->user_id,
            registration_id: is_string($checkin->registration_id) ? $checkin->registration_id : null,
            method: (string) $checkin->method,
            checked_in_at: $checkin->checked_in_at instanceof DateTimeInterface
                ? $checkin->checked_in_at->format(DateTimeInterface::ATOM)
                : null,
        );
    }
}
