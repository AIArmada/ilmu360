<?php

namespace App\Data\Api\EventRegistration;

use Spatie\LaravelData\Data;

class EventRegistrationStatusData extends Data
{
    public function __construct(
        public bool $is_registered,
        public ?EventRegistrationData $registration,
    ) {}

    public static function fromNullableRegistration(?EventRegistrationData $registration): self
    {
        return new self(
            is_registered: $registration instanceof EventRegistrationData,
            registration: $registration,
        );
    }
}
