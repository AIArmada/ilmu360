<?php

declare(strict_types=1);

namespace App\Actions\SavedSearches;

use App\Models\SavedSearch;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteOwnerSavedSearchesAction
{
    use AsAction;

    public function __construct(private DeleteSavedSearchAction $delete) {}

    public function handle(string $ownerType, string $ownerId): void
    {
        SavedSearch::query()
            ->where('user_type', $ownerType)
            ->where('user_id', $ownerId)
            ->each(function (SavedSearch $savedSearch): void {
                $this->delete->handle($savedSearch);
            });
    }
}
