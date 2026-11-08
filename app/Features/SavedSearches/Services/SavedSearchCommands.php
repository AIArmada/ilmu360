<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Services;

use App\Features\SavedSearches\Actions\CreateSavedSearchAction;
use App\Features\SavedSearches\Actions\DeleteOwnerSavedSearchesAction;
use App\Features\SavedSearches\Actions\DeleteSavedSearchAction;
use App\Features\SavedSearches\Actions\RestoreSavedSearchesAction;
use App\Features\SavedSearches\Actions\UpdateSavedSearchAction;
use App\Features\SavedSearches\Api\SavedSearchCommands as SavedSearchCommandsContract;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Features\SavedSearches\Queries\DatabaseSavedSearchReader;

final readonly class SavedSearchCommands implements SavedSearchCommandsContract
{
    public function __construct(
        private CreateSavedSearchAction $create,
        private UpdateSavedSearchAction $update,
        private DeleteSavedSearchAction $delete,
        private DeleteOwnerSavedSearchesAction $deleteOwner,
        private RestoreSavedSearchesAction $restore,
        private DatabaseSavedSearchReader $reader,
    ) {}

    public function create(string $ownerType, string $ownerId, array $attributes): SavedSearchSnapshot
    {
        return $this->reader->snapshot($this->create->handle($ownerType, $ownerId, $attributes));
    }

    public function update(string $ownerType, string $ownerId, string $searchId, array $attributes): SavedSearchSnapshot
    {
        return $this->reader->snapshot($this->update->handle($this->findOwned($ownerType, $ownerId, $searchId), $attributes));
    }

    public function delete(string $ownerType, string $ownerId, string $searchId): void
    {
        $this->delete->handle($this->findOwned($ownerType, $ownerId, $searchId));
    }

    public function deleteOwner(string $ownerType, string $ownerId): void
    {
        $this->deleteOwner->handle($ownerType, $ownerId);
    }

    public function restore(array $records): void
    {
        $this->restore->handle($records);
    }

    private function findOwned(string $ownerType, string $ownerId, string $searchId): SavedSearch
    {
        return SavedSearch::query()
            ->where('user_type', $ownerType)
            ->where('user_id', $ownerId)
            ->findOrFail($searchId);
    }
}
