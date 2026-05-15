<?php

namespace App\Data\Api\EventRegistration;

use App\Models\Registration;
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
        return new self(
            id: (string) $registration->id,
            event_id: (string) $registration->event_id,
            user_id: is_string($registration->user_id) ? $registration->user_id : null,
            name: (string) $registration->name,
            email: is_string($registration->email) ? $registration->email : null,
            phone: is_string($registration->phone) ? $registration->phone : null,
            status: (string) $registration->status,
            created_at: $registration->created_at?->toIso8601String(),
        );
    }
}
