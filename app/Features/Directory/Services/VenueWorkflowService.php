<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\Venues\BackfillVenueSlugsAction;
use App\Features\Directory\Actions\Venues\ChangeVenueStatusAction;
use App\Features\Directory\Actions\Venues\ChangeVenueVisibilityAction;
use App\Features\Directory\Actions\Venues\CreateVenueAction;
use App\Features\Directory\Actions\Venues\DeleteVenueAction;
use App\Features\Directory\Actions\Venues\GenerateVenueSlugAction;
use App\Features\Directory\Actions\Venues\SaveVenueAction;
use App\Features\Directory\Actions\Venues\SyncVenueRelationsAction;
use App\Features\Directory\Api\VenueWorkflow;
use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Implements the venue workflow through named Directory actions.
 */
final readonly class VenueWorkflowService implements VenueWorkflow
{
    public function __construct(
        private CreateVenueAction $createVenue,
        private SaveVenueAction $saveVenue,
        private DeleteVenueAction $deleteVenue,
        private ChangeVenueStatusAction $changeVenueStatus,
        private ChangeVenueVisibilityAction $changeVenueVisibility,
        private GenerateVenueSlugAction $generateVenueSlug,
        private BackfillVenueSlugsAction $backfillVenueSlugs,
        private SyncVenueRelationsAction $syncVenueRelations,
    ) {}

    public function createFromForm(array $payload): string
    {
        return (string) $this->createVenue->handle($payload)->getKey();
    }

    public function saveFromAdmin(array $payload, ?string $venueId = null): string
    {
        $venue = $venueId !== null ? $this->venue($venueId) : null;

        return (string) $this->saveVenue->handle($payload, $venue)->getKey();
    }

    public function delete(array $venueIds): int
    {
        $deleted = 0;

        foreach (DirectoryModel::query('venue')->whereKey($venueIds)->get() as $venue) {
            if (! $venue instanceof Model) {
                continue;
            }

            $this->deleteVenue->handle($venue);
            $deleted++;
        }

        return $deleted;
    }

    public function changeStatus(string $venueId, string $status): void
    {
        $this->changeVenueStatus->handle($venueId, $status);
    }

    public function changeVisibility(string $venueId, string $visibility): void
    {
        $this->changeVenueVisibility->handle($venueId, $visibility);
    }

    public function syncRelations(string $venueId, array $payload): void
    {
        $this->syncVenueRelations->handle($this->venue($venueId), $payload);
    }

    public function syncSlug(string $venueId): bool
    {
        return $this->generateVenueSlug->syncVenueSlug($this->venue($venueId));
    }

    public function syncSlugsForName(string $name): bool
    {
        return $this->generateVenueSlug->syncVenueSlugsForName($name);
    }

    public function backfillSlugs(array $venueIds = []): array
    {
        return $this->backfillVenueSlugs->handle($venueIds);
    }

    private function venue(string $venueId): Model
    {
        $venue = DirectoryModel::query('venue')->find($venueId);

        if (! $venue instanceof Model) {
            throw new RuntimeException("Venue [{$venueId}] was not found.");
        }

        return $venue;
    }
}
