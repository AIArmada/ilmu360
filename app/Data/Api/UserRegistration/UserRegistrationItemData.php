<?php

namespace App\Data\Api\UserRegistration;

use App\Models\Event;
use App\Models\Registration;
use Spatie\LaravelData\Data;

class UserRegistrationItemData extends Data
{
    /**
     * @param  array<string, mixed>|null  $event
     */
    public function __construct(
        public string $id,
        public string $event_id,
        public ?string $user_id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $status,
        public ?string $checkin_token,
        public ?string $created_at,
        public ?string $updated_at,
        public ?array $event,
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
            checkin_token: is_string($registration->checkin_token) ? $registration->checkin_token : null,
            created_at: $registration->created_at?->toIso8601String(),
            updated_at: $registration->updated_at?->toIso8601String(),
            event: $registration->event instanceof Event
                ? UserRegistrationEventData::fromModel($registration->event)->toArray()
                : null,
        );
    }
}
