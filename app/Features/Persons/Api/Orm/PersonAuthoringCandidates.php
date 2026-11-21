<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Orm;

use App\Models\User;

/**
 * Person-owned authoring candidate reads.
 *
 * @internal No model transport: every method returns final labels or ids.
 *
 * Consumers: Contributions EntitySubmissionAccess and the Events submit-event
 * option/label providers.
 */
interface PersonAuthoringCandidates
{
    /**
     * Persons the submitter may choose, keyed by id.
     *
     * @return array<string, string>
     */
    public function options(?User $submitter): array;

    /**
     * Formatted labels for the supplied ids, restricted to the same submitter
     * scope, keyed by id.
     *
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function labels(?User $submitter, array $personIds): array;

    public function canUse(?User $submitter, string $personId): bool;

    /**
     * The subset of ids the submitter is not allowed to use.
     *
     * @param  list<string>  $personIds
     * @return list<string>
     */
    public function unusableIds(?User $submitter, array $personIds): array;
}
