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
        public string $attendee_id,
        public ?string $event_registration_id,
        public string $check_in_source,
        public ?string $checked_in_at,
    ) {}

    public static function fromModel(EventCheckin $checkin): self
    {
        return new self(
            id: (string) $checkin->id,
            event_id: (string) $checkin->event_id,
            attendee_id: (string) $checkin->attendee_id,
            event_registration_id: is_string($checkin->event_registration_id) ? $checkin->event_registration_id : null,
            check_in_source: (string) $checkin->check_in_source,
            checked_in_at: $checkin->checked_in_at instanceof DateTimeInterface
                ? $checkin->checked_in_at->format(DateTimeInterface::ATOM)
                : null,
        );
    }
}
