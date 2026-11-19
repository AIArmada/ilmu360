<?php

namespace App\Features\Events\EntryPoints\Http\Data;

use App\Features\Engagement\EntryPoints\Http\Data\EventGoingStateData;
use App\Features\Engagement\EntryPoints\Http\Data\EventSaveStateData;
use App\Features\Registration\EntryPoints\Http\Data\EventCheckInStateData;
use App\Features\Registration\EntryPoints\Http\Data\EventRegistrationStatusData;
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
