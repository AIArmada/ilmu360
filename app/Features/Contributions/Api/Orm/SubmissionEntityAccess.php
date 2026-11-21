<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Orm;

use App\Features\Institutions\Models\Institution;

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
     * @param  Institution|null  $scopedInstitution  Hydrated institution model.
     */
    public function assertSubmissionEntitiesMatchCountryFor(
        array $validated,
        ?string $organizerKind,
        string $submissionCountryId,
        ?Institution $scopedInstitution,
        string $validationKeyPrefix = '',
    ): void;
}
