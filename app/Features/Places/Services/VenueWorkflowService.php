<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use App\Features\Places\Actions\Venues\ChangeVenueStatusAction;
use App\Features\Places\Actions\Venues\CreateVenueAction;
use App\Features\Places\Actions\Venues\DeleteVenueAction;
use App\Features\Places\Actions\Venues\DetachVenueVerifierAction;
use App\Features\Places\Actions\Venues\GenerateVenueSlugAction;
use App\Features\Places\Actions\Venues\RestoreVenueVerifierAction;
use App\Features\Places\Actions\Venues\SaveVenueAction;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\Places\Models\Venue;
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
        private GenerateVenueSlugAction $generateVenueSlug,
        private DetachVenueVerifierAction $detachVenueVerifier,
        private RestoreVenueVerifierAction $restoreVenueVerifier,
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

        foreach (Venue::query()->whereKey($venueIds)->get() as $venue) {
            if (! $venue instanceof Venue) {
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

    /**
     * @param  list<string>  $venueIds
     */
    public function verifyPending(array $venueIds): void
    {
        if ($venueIds === []) {
            return;
        }

        Venue::query()
            ->whereKey($venueIds)
            ->where('status', 'pending')
            ->get()
            ->each(function (Venue $venue): void {
                $this->changeVenueStatus->handle((string) $venue->getKey(), 'verified');
            });
    }

    public function syncSlug(string $venueId): bool
    {
        return $this->generateVenueSlug->syncVenueSlug($this->venue($venueId));
    }

    public function syncSlugsForName(string $name): bool
    {
        return $this->generateVenueSlug->syncVenueSlugsForName($name);
    }

    public function verifiedIdsForAccount(string $accountId): array
    {
        return Venue::query()
            ->where('verified_by', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function detachVerifier(string $accountId): void
    {
        $this->detachVenueVerifier->handle($accountId);
    }

    public function restoreVerifier(string $accountId, array $venueIds): void
    {
        $this->restoreVenueVerifier->handle($accountId, $venueIds);
    }

    private function venue(string $venueId): Venue
    {
        $venue = Venue::query()->find($venueId);

        if (! $venue instanceof Venue) {
            throw new RuntimeException("Venue [{$venueId}] was not found.");
        }

        return $venue;
    }
}
