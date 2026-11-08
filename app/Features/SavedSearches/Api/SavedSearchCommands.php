<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Api;

interface SavedSearchCommands
{
    /** @param array<string, mixed> $attributes */
    public function create(string $ownerType, string $ownerId, array $attributes): SavedSearchSnapshot;

    /** @param array<string, mixed> $attributes */
    public function update(string $ownerType, string $ownerId, string $searchId, array $attributes): SavedSearchSnapshot;

    public function delete(string $ownerType, string $ownerId, string $searchId): void;

    public function deleteOwner(string $ownerType, string $ownerId): void;

    /** @param list<array<string, mixed>> $records */
    public function restore(array $records): void;
}
