<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Api\CreateSavedSearchWithAttribution;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Shared\Auth\ResourceOwner;
use App\Shared\ShareTracking\SavedSearchShareOutcomes;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class CreateSavedSearchWithAttributionAction implements CreateSavedSearchWithAttribution
{
    use AsAction;

    public function __construct(
        private SavedSearchShareOutcomes $shareOutcomes,
        private SavedSearchCommands $savedSearchCommands,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ResourceOwner $owner, array $attributes): SavedSearchSnapshot
    {
        return DB::transaction(function () use ($owner, $attributes): SavedSearchSnapshot {
            $savedSearch = $this->savedSearchCommands->create(
                $owner->resourceOwnerType(),
                $owner->resourceOwnerId(),
                $attributes,
            );

            $this->shareOutcomes->recordCreated(
                savedSearchId: $savedSearch->id,
                actor: $owner,
                request: request(),
                occurredAt: $savedSearch->createdAt ?? now(),
            );

            return $savedSearch;
        });
    }
}
