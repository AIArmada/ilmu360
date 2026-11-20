<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Http\Data;

use App\Features\Engagement\Api\Data\EventGoingStateData;
use App\Features\Engagement\Api\Data\EventSaveStateData;
use App\Features\Registration\Api\Data\EventCheckInStateData;
use App\Features\Registration\Api\Data\EventRegistrationStatusData;

final readonly class EventMeData
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'saved' => $this->saved->toArray(),
            'going' => $this->going->toArray(),
            'registration' => $this->registration->toArray(),
            'check_in' => $this->check_in->toArray(),
        ];
    }
}
