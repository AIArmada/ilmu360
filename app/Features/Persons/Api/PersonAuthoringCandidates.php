<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

use App\Shared\Auth\AccountActor;

/**
 * Person-owned authoring candidate reads.
 *
 * Public value-only selection boundary for event and contribution forms.
 */
interface PersonAuthoringCandidates
{
    /**
     * Persons the submitter may choose, keyed by id.
     *
     * @return array<string, string>
     */
    public function options(?AccountActor $submitter): array;

    /**
     * Formatted labels for the supplied ids, restricted to the same submitter
     * scope, keyed by id.
     *
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function labels(?AccountActor $submitter, array $personIds): array;

    public function canUse(?AccountActor $submitter, string $personId): bool;

    /**
     * The subset of ids the submitter is not allowed to use.
     *
     * @param  list<string>  $personIds
     * @return list<string>
     */
    public function unusableIds(?AccountActor $submitter, array $personIds): array;
}
