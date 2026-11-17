<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use App\Shared\Contributions\Enums\ContributionSubjectType;

/**
 * Creates a pending subject through its owning feature, then submits the
 * contribution request. A transport-owned callback may persist relationships
 * and media inside the same transaction before the request is created.
 */
interface StagedContributionCreates
{
    /**
     * @param  array<string, mixed>  $state
     * @param  (callable(string): void)|null  $persistRelationships  Receives the new entity ID.
     * @return string The staged subject ID.
     */
    public function submit(
        ContributionSubjectType $subjectType,
        array $state,
        string $proposerId,
        ?callable $persistRelationships = null,
        string $validationKeyPrefix = '',
    ): string;
}
