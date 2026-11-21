<?php

declare(strict_types=1);

namespace App\Features\Registration\Queries;

use App\Features\Registration\Api\Data\RegistrationConfirmationFacts;
use App\Features\Registration\Api\RegistrationConfirmationFactsReader;
use App\Features\Registration\Models\Registration;
use Illuminate\Support\Str;

final readonly class DatabaseRegistrationConfirmationFactsReader implements RegistrationConfirmationFactsReader
{
    public function find(string $registrationId): ?RegistrationConfirmationFacts
    {
        if (! Str::isUuid($registrationId)) {
            return null;
        }

        $registration = Registration::query()->find($registrationId);
        if (! $registration instanceof Registration) {
            return null;
        }

        $userId = $registration->resolvedUserId();
        if (! is_string($userId) || $userId === '') {
            return null;
        }

        return new RegistrationConfirmationFacts(
            registrationId: (string) $registration->getKey(),
            eventId: (string) $registration->event_id,
            userId: $userId,
        );
    }
}
