<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Orm;

/**
 * Submitter entity access plus country consistency for event submissions.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/EntryPoints, Institutions/EntryPoints, Contributions/Services
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
