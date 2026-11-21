<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Orm;

use App\Features\Institutions\Models\Institution;
use App\Models\User;

/**
 * Institution-owned authoring candidate reads.
 *
 * The owner applies its own publicly-visible status, public-submission flag,
 * membership and country scope. Consumers receive final option labels or a
 * bounded member-institution identity; they never add predicates to an
 * Institution builder.
 *
 * @internal Model transport is limited to the scoped member institution a
 *           submission page must render and authorize.
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
    public function options(?User $submitter, ?string $countryId = null): array;

    /**
     * Display labels for the supplied ids, restricted to the same submitter
     * scope, keyed by id.
     *
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function labels(?User $submitter, array $institutionIds): array;

    /**
     * Whether the submitter may use the institution.
     */
    public function canUse(?User $submitter, string $institutionId, ?string $countryId = null): bool;

    /**
     * Institutions the account is a visible member of, keyed by id.
     *
     * @return array<string, string>
     */
    public function memberOptions(User $submitter): array;

    /**
     * The visible member institution identity, or null when the account is
     * not an eligible member.
     */
    public function findMemberCandidate(User $submitter, string $institutionId): ?Institution;

    public function belongsToCountry(string $institutionId, string $countryId): bool;
}
