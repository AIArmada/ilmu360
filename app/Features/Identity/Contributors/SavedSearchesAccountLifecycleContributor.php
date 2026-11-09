<?php

declare(strict_types=1);

namespace App\Features\Identity\Contributors;

use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Auth\UserMorphType;

/**
 * Preserves a user's saved searches across account deletion and restoration.
 *
 * Account deletion uses the plain owner deletion path (no attribution
 * reversal), matching the previous HasUserRestoration behaviour.
 */
final readonly class SavedSearchesAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(
        private SavedSearchReader $reader,
        private SavedSearchCommands $commands,
        private UserMorphType $userMorphType,
    ) {}

    public function key(): string
    {
        return 'saved_searches';
    }

    public function priority(): int
    {
        return 100;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'records' => $this->reader->restorationSnapshotForOwner(
                $this->ownerType(),
                $accountId,
            ),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->commands->deleteOwner($this->ownerType(), $accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $records = $snapshot['records'] ?? [];

        if (! is_array($records)) {
            return;
        }

        $this->commands->restore(array_values(array_filter(
            $records,
            is_array(...),
        )));
    }

    public function afterRestore(string $accountId): void {}

    private function ownerType(): string
    {
        return $this->userMorphType->morphClass();
    }
}
