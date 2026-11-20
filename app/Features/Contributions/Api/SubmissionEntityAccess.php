<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

/**
 * Submitter entity access plus country consistency for event submissions.
 */
interface SubmissionEntityAccess extends EntitySubmissionAccessContract
{
    /**
     * @param  array<string, mixed>  $validated
     * @param  object|null  $scopedInstitution  Hydrated institution model.
     */
    public function assertSubmissionEntitiesMatchCountryFor(
        array $validated,
        ?string $organizerKind,
        string $submissionCountryId,
        ?object $scopedInstitution,
        string $validationKeyPrefix = '',
    ): void;
}
