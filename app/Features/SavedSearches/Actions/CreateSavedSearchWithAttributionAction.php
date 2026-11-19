<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Api\CreateSavedSearchWithAttribution;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\ShareTracking\Api\SavedSearchShareOutcomes;
use App\Shared\Auth\ResourceOwner;
use App\Shared\ShareTracking\ShareRequestContext;
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
                request: ShareRequestContext::current(),
                occurredAt: $savedSearch->createdAt ?? now(),
            );

            return $savedSearch;
        });
    }
}
