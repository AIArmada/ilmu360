<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Orm;

use App\Models\User;

/**
 * Submitter-scoped access to the entities an event submission may reference.
 *
 * The owning features execute candidate queries. This contract returns
 * option/label arrays and permission facts, never models or builders.
 *
 * Consumers: Events submit-event options, form schema, prefill, scoped
 * institution resolver and request handler; Institution dashboard membership
 * check.
 */
interface EntitySubmissionAccessContract
{
    /**
     * Institutions the submitter may choose, keyed by id.
     *
     * @return array<string, string>
     */
    public function institutionOptions(?User $user, ?string $countryId = null): array;

    /**
     * Display labels for the supplied ids within the submitter scope.
     *
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function institutionLabels(?User $user, array $institutionIds): array;

    /**
     * Institutions the account is a visible member of, keyed by id.
     *
     * @return array<string, string>
     */
    public function memberInstitutionOptions(User $user): array;

    /**
     * Persons the submitter may choose, keyed by id.
     *
     * @return array<string, string>
     */
    public function personOptions(?User $user): array;

    /**
     * Formatted labels for the supplied ids within the submitter scope.
     *
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function personLabels(?User $user, array $personIds): array;

    public function personLabel(?User $user, string $personId): ?string;

    /**
     * Venues available for authoring, keyed by id.
     *
     * @return array<string, string>
     */
    public function venueOptions(?string $countryId = null): array;

    public function canUseInstitution(?User $user, string $institutionId): bool;

    public function canUseMemberInstitution(User $user, string $institutionId): bool;

    public function canUsePerson(?User $user, string $personId): bool;

    /**
     * @param  list<string>  $personIds
     * @return list<string> The subset of IDs the submitter is not allowed to use.
     */
    public function unusablePersonIds(?User $user, array $personIds): array;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertSubmissionEntitiesAreAccessible(
        array $validated,
        ?User $submitter,
        ?string $organizerKind,
        string $validationKeyPrefix = '',
    ): void;

    public function institutionBelongsToCountry(string $institutionId, string $countryId): bool;

    public function venueBelongsToCountry(string $venueId, string $countryId): bool;
}
