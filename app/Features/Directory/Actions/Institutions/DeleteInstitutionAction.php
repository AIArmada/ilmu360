<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Api\InstitutionSpaceLinks;
use App\Features\Directory\Api\InstitutionVenueLinks;
use App\Models\Institution;

/**
 * Delete an institution and its Directory bridges atomically.
 *
 * The model's ordinary Eloquent delete still fires its lifecycle events; the
 * model connection transaction keeps row deletion and both bridge cleanups
 * atomic.
 */
final readonly class DeleteInstitutionAction
{
    public function __construct(
        private InstitutionVenueLinks $institutionVenueLinks,
        private InstitutionSpaceLinks $institutionSpaceLinks,
    ) {}

    public function handle(Institution $institution): void
    {
        $institution->getConnection()->transaction(function () use ($institution): void {
            if (! $institution->delete()) {
                return;
            }

            $institutionId = (string) $institution->getKey();

            $this->institutionVenueLinks->deleteForInstitution($institutionId);
            $this->institutionSpaceLinks->deleteForInstitution($institutionId);
        });
    }
}
