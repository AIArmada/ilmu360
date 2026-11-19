<?php

declare(strict_types=1);

namespace App\Features\Institutions\Services;

use App\Features\Institutions\Actions\AddInstitutionVenueLinkAction;
use App\Features\Institutions\Actions\DeleteInstitutionVenueLinksAction;
use App\Features\Institutions\Actions\DemoteInstitutionVenuePrimaryAction;
use App\Features\Institutions\Actions\RemoveInstitutionVenueLinkAction;
use App\Features\Institutions\Actions\SetInstitutionVenuePrimaryAction;
use App\Features\Institutions\Actions\SyncInstitutionVenuesAction;
use App\Features\Institutions\Api\Enums\InstitutionVenueRole;
use App\Features\Institutions\Api\InstitutionVenueLinks;
use App\Features\Institutions\Models\Institution;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Implements the institution↔venue bridge boundary through named actions.
 */
final readonly class InstitutionVenueLinkService implements InstitutionVenueLinks
{
    public function __construct(
        private SyncInstitutionVenuesAction $syncVenues,
        private AddInstitutionVenueLinkAction $addLink,
        private RemoveInstitutionVenueLinkAction $removeLink,
        private SetInstitutionVenuePrimaryAction $setPrimary,
        private DemoteInstitutionVenuePrimaryAction $demotePrimary,
        private DeleteInstitutionVenueLinksAction $deleteLinks,
    ) {}

    public function sync(string $institutionId, array $links): void
    {
        $institution = Institution::query()->find($institutionId);

        if (! $institution instanceof Model) {
            throw new RuntimeException("Institution [{$institutionId}] was not found.");
        }

        $this->syncVenues->handle($institution, $links);
    }

    public function add(
        string $institutionId,
        string $venueId,
        InstitutionVenueRole|string $role,
        bool $isPrimary = false,
    ): void {
        $this->addLink->handle($institutionId, $venueId, $role, $isPrimary);
    }

    public function remove(string $institutionId, string $venueId): void
    {
        $this->removeLink->handle($institutionId, $venueId);
    }

    public function setPrimary(string $institutionId, string $venueId): void
    {
        $this->setPrimary->handle($institutionId, $venueId);
    }

    public function demotePrimary(
        string $institutionId,
        InstitutionVenueRole|string $role,
        ?string $exceptVenueId = null,
    ): void {
        $this->demotePrimary->handle($institutionId, $role, $exceptVenueId);
    }

    public function deleteForInstitution(string $institutionId): void
    {
        $this->deleteLinks->handle($institutionId);
    }

    public function deleteForVenue(string $venueId): void
    {
        $this->deleteLinks->forVenue($venueId);
    }
}
