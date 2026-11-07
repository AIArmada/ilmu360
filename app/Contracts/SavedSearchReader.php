<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SavedSearchSnapshot;
use Carbon\CarbonImmutable;

interface SavedSearchReader
{
    /** @return list<SavedSearchSnapshot> */
    public function forOwner(string $ownerType, string $ownerId, ?int $limit = null): array;

    /** @return iterable<SavedSearchSnapshot> */
    public function notificationCandidates(): iterable;

    public function find(string $id): ?SavedSearchSnapshot;

    public function exists(string $id): bool;

    public function hasEarlierForOwner(string $ownerType, string $ownerId, string $excludingId, CarbonImmutable $before): bool;

    /** @return list<array<string, mixed>> */
    public function restorationSnapshotForOwner(string $ownerType, string $ownerId): array;
}
