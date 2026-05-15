<?php

namespace App\Data\Api\Event;

use App\Data\Api\EventCheckIn\EventCheckInStateData;
use App\Data\Api\EventGoing\EventGoingStateData;
use App\Data\Api\EventRegistration\EventRegistrationStatusData;
use App\Data\Api\EventSave\EventSaveStateData;
use Spatie\LaravelData\Data;

class EventMeData extends Data
{
    public function __construct(
        public EventSaveStateData $saved,
        public EventGoingStateData $going,
        public EventRegistrationStatusData $registration,
        public EventCheckInStateData $check_in,
    ) {}

    public static function fromState(
        EventSaveStateData $saved,
        EventGoingStateData $going,
        EventRegistrationStatusData $registration,
        EventCheckInStateData $checkIn,
    ): self {
        return new self(
            saved: $saved,
            going: $going,
            registration: $registration,
            check_in: $checkIn,
        );
    }
}
