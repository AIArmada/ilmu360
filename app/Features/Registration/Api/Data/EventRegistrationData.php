<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

final readonly class EventRegistrationData
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
