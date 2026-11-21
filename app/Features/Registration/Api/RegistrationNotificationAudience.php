<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

use App\Features\Registration\Api\Data\EventCheckInFacts;

/**
 * Registration-owned audience identities and check-in facts for notification
 * dispatch. Consumers never receive registration or check-in models.
 */
interface RegistrationNotificationAudience
{
    /** @return list<string> */
    public function activeRegistrantUserIds(string $eventId): array;

    public function checkInFacts(string $checkInId): ?EventCheckInFacts;
}
