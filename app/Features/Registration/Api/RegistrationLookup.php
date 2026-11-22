<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

use App\Features\Registration\Api\Data\CheckInSnapshot;
use App\Features\Registration\Api\Data\CheckInSourceFacts;
use App\Features\Registration\Api\Data\EventCheckInFacts;
use App\Features\Registration\Api\Data\EventRegistrationData;
use App\Features\Registration\Api\Data\RegistrationSnapshot;

/**
 * Cross-owner read boundary for registration and check-in records.
 *
 * Callers that only need existence, identity, or account-scoped facts use this
 * contract instead of querying Registration's Eloquent models.
 *
 * Read models are projected by Registration into immutable snapshots; framework
 * models and Eloquent collections never cross the feature boundary.
 *
 * Consumers: Registration/FeatureServiceProvider.php, Rewards/Queries, Events/Queries, Events/Notifications, Events/EntryPoints, Identity/EntryPoints
 */
interface RegistrationLookup
{
    public function exists(string $registrationId): bool;

    public function latestActiveForUser(string $eventId, string $userId): ?EventRegistrationData;

    public function hasCheckIn(string $eventId, string $attendeeId): bool;

    /**
     * Check-in ids for a registration, locked for the caller's transaction.
     *
     * @return list<string>
     */
    public function lockedCheckInIdsForRegistration(string $registrationId): array;

    public function checkInSourceFacts(string $checkInId): ?CheckInSourceFacts;

    /**
     * Documented transport: a hydrated registration model for framework
     * contracts that require the Eloquent row (participant relation,
     * lifecycle status helpers, occurrence/session scope fields).
     */
    public function findRegistration(string $registrationId): ?RegistrationSnapshot;

    /**
     * Documented transport: a hydrated check-in model for framework read
     * adapters that need its recorded attributes.
     */
    public function findCheckIn(string $checkInId): ?CheckInSnapshot;

    /**
     * Whether an earlier registration exists for the actor.
     */
    public function hasEarlierRegistrationActivity(
        string $actorId,
        string $actorType,
        \DateTimeInterface $before,
        string $exceptId,
    ): bool;

    /**
     * Whether an earlier check-in exists for the actor or their verifications.
     */
    public function hasEarlierCheckInActivity(
        string $actorId,
        string $actorType,
        \DateTimeInterface $before,
        string $exceptId,
    ): bool;

    /**
     * Documented transport: registrations for an account with the event
     * loaded, newest first.
     *
     * @return list<RegistrationSnapshot>
     */
    public function registrationsForAccount(string $accountId): array;

    /**
     * Documented transport: active planner registrations for the supplied ids
     * with the event and planner relations loaded, newest first.
     *
     * @param  list<string>  $registrationIds
     * @return list<RegistrationSnapshot>
     */
    public function plannerRegistrationsForIds(array $registrationIds): array;

    /**
     * Documented transport: check-ins for an account with the event and
     * verifier loaded, most recent first.
     *
     * @return list<CheckInSnapshot>
     */
    public function checkInsForAccount(string $accountId): array;

    /**
     * Documented transport: planner check-ins for an account with the event
     * and planner relations loaded, most recent first.
     *
     * @return list<CheckInSnapshot>
     */
    public function plannerCheckInsForAccount(string $accountId): array;

    /**
     * Confirmed registration ids for the account.
     *
     * @return list<string>
     */
    public function confirmedRegistrationIdsForAccount(string $accountId): array;

    /**
     * Check-in transition ids recorded for the account.
     *
     * @return list<string>
     */
    public function checkInTransitionIdsForAccount(string $accountId): array;

    /**
     * Active registration user ids for an event, in registration order.
     *
     * @return list<string>
     */
    public function activeRegistrantUserIds(string $eventId): array;

    public function checkInFacts(string $checkInId): ?EventCheckInFacts;

    /** @return list<string> */
    public function registrationIdsForAccount(string $accountId): array;

    /** @return list<string> */
    public function checkInIdsForAccount(string $accountId): array;

    /** @return list<string> */
    public function verifiedCheckInIdsForAccount(string $accountId): array;
}
