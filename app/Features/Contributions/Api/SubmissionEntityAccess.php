<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use App\Shared\Auth\AccountActor;

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
interface SubmissionEntityAccess
{
    /**
     * Institutions the submitter may choose, keyed by id.
     *
     * @return array<string, string>
     */
    public function institutionOptions(?AccountActor $user, ?string $countryId = null): array;

    /**
     * Display labels for the supplied ids within the submitter scope.
     *
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function institutionLabels(?AccountActor $user, array $institutionIds): array;

    /**
     * Institutions the account is a visible member of, keyed by id.
     *
     * @return array<string, string>
     */
    public function memberInstitutionOptions(AccountActor $user): array;

    /**
     * Persons the submitter may choose, keyed by id.
     *
     * @return array<string, string>
     */
    public function personOptions(?AccountActor $user): array;

    /**
     * Formatted labels for the supplied ids within the submitter scope.
     *
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function personLabels(?AccountActor $user, array $personIds): array;

    public function personLabel(?AccountActor $user, string $personId): ?string;

    /**
     * Venues available for authoring, keyed by id.
     *
     * @return array<string, string>
     */
    public function venueOptions(?string $countryId = null): array;

    public function canUseInstitution(?AccountActor $user, string $institutionId): bool;

    public function canUseMemberInstitution(AccountActor $user, string $institutionId): bool;

    public function canUsePerson(?AccountActor $user, string $personId): bool;

    /**
     * @param  list<string>  $personIds
     * @return list<string> The subset of IDs the submitter is not allowed to use.
     */
    public function unusablePersonIds(?AccountActor $user, array $personIds): array;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertSubmissionEntitiesAreAccessible(
        array $validated,
        ?AccountActor $submitter,
        ?string $organizerKind,
        string $validationKeyPrefix = '',
    ): void;

    public function institutionBelongsToCountry(string $institutionId, string $countryId): bool;

    public function venueBelongsToCountry(string $venueId, string $countryId): bool;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertSubmissionEntitiesMatchCountryFor(
        array $validated,
        ?string $organizerKind,
        string $submissionCountryId,
        ?string $scopedInstitutionId,
        string $validationKeyPrefix = '',
    ): void;
}
