<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Orm;


/**
 * Submitter entity access plus country consistency for event submissions.
 *
 * @internal Model transport is limited to the scoped member institution for
 *           Livewire page identity and authorization.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/EntryPoints, Institutions/EntryPoints, Contributions/Services
 */
interface SubmissionEntityAccess extends EntitySubmissionAccessContract
{
    /**
     * @param  array<string, mixed>  $validated
     * @param  string|null  $scopedInstitutionId  Authorized institution identity.
     */
    public function assertSubmissionEntitiesMatchCountryFor(
        array $validated,
        ?string $organizerKind,
        string $submissionCountryId,
        ?string $scopedInstitutionId,
        string $validationKeyPrefix = '',
    ): void;
}
