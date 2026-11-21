<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

/**
 * Institution-owned authoring candidate reads.
 *
 * The owner applies its own publicly-visible status, public-submission flag,
 * membership and country scope. Consumers receive final option labels and
 * eligibility booleans; Eloquent models and builders remain private.
 *
 * Consumers: Contributions EntitySubmissionAccess and the Events submit-event
 * option/label providers and scoped-institution resolver.
 */
interface InstitutionAuthoringCandidates
{
    /**
     * Institutions the submitter may choose, keyed by id.
     *
     * @return array<string, string>
     */
    public function options(?string $accountId, ?string $countryId = null): array;

    /**
     * Display labels for the supplied ids, restricted to the same submitter
     * scope, keyed by id.
     *
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function labels(?string $accountId, array $institutionIds): array;

    /**
     * Whether the submitter may use the institution.
     */
    public function canUse(?string $accountId, string $institutionId, ?string $countryId = null): bool;

    /**
     * Institutions the account is a visible member of, keyed by id.
     *
     * @return array<string, string>
     */
    public function memberOptions(string $accountId): array;

    /**
     * Whether the account currently belongs to this visible institution.
     */
    public function canMemberUse(string $accountId, string $institutionId): bool;

    public function belongsToCountry(string $institutionId, string $countryId): bool;
}
