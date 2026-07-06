<?php

namespace App\Data\Api\EventRegistration;

use App\Models\Registration;
use App\Models\User;
use Spatie\LaravelData\Data;

class EventRegistrationData extends Data
{
    public function __construct(
        public string $id,
        public string $event_id,
        public ?string $user_id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $status,
        public ?string $created_at,
    ) {}

    public static function fromModel(Registration $registration): self
    {
        $registrant = $registration->registrant;

        return new self(
            id: (string) $registration->id,
            event_id: (string) $registration->event_id,
            user_id: $registrant instanceof User ? (string) $registrant->getKey() : null,
            name: $registration->resolvedName() ?? '',
            email: $registration->resolvedEmail(),
            phone: $registration->resolvedPhone(),
            status: $registration->statusValue(),
            created_at: $registration->created_at?->toIso8601String(),
        );
    }
}
