<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * Account-scoped identifiers for Events-owned facts.
 *
 * Consumers use the identifiers to read the Events model they need; this
 * boundary never exposes a mutable relation or query builder.
 */
interface AccountEventFacts
{
    /** @return list<string> */
    public function ownedEventIds(string $accountId): array;

    /** @return list<string> */
    public function submittedEventIds(string $accountId): array;

    /** @return list<string> */
    public function eventSubmissionIds(string $accountId): array;

    /** @return list<string> */
    public function registrationIds(string $accountId): array;

    /** @return list<string> */
    public function checkinIds(string $accountId): array;

    /** @return list<string> */
    public function verifiedCheckinIds(string $accountId): array;

    /** @return list<string> */
    public function moderationReviewIds(string $accountId): array;
}
