<?php

namespace App\Data\Api\UserRegistration;

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
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
        public ?string $created_at,
        public ?string $updated_at,
        public ?array $event,
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
            updated_at: $registration->updated_at?->toIso8601String(),
            event: $registration->event instanceof Event
                ? UserRegistrationEventData::fromModel($registration->event)->toArray()
                : null,
        );
    }
}
