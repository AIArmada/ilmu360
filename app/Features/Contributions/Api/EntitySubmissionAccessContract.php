<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use App\Models\User;

/**
 * Submitter-scoped access to the entities an event submission may reference.
 *
 * Query methods return constrained Eloquent builders owned by this feature;
 * callers use them read-only for options and existence checks.
 */
interface EntitySubmissionAccessContract
{
    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Features\Institutions\Models\Institution>
     */
    public function institutionQueryForSubmitter(?User $user, ?string $countryId = null);

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Features\Institutions\Models\Institution>
     */
    public function memberInstitutionQueryForSubmitter(User $user);

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Features\Persons\Models\Person>
     */
    public function personQueryForSubmitter(?User $user);

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Features\Places\Models\Venue>
     */
    public function venueQuery(?string $countryId = null);

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
