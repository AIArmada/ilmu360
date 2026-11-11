<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\Institutions\AddInstitutionVenueLinkAction;
use App\Features\Directory\Actions\Institutions\DeleteInstitutionVenueLinksAction;
use App\Features\Directory\Actions\Institutions\DemoteInstitutionVenuePrimaryAction;
use App\Features\Directory\Actions\Institutions\RemoveInstitutionVenueLinkAction;
use App\Features\Directory\Actions\Institutions\SetInstitutionVenuePrimaryAction;
use App\Features\Directory\Actions\Institutions\SyncInstitutionVenuesAction;
use App\Features\Directory\Api\InstitutionVenueLinks;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\InstitutionVenueRole;
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
        $institution = DirectoryModel::query('institution')->find($institutionId);

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
