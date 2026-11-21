<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

final readonly class RegistrationConfirmationFacts
{
    public function __construct(
        public string $registrationId,
        public string $eventId,
        public string $userId,
    ) {}
}
