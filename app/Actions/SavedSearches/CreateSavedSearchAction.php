<?php

namespace App\Actions\SavedSearches;

use App\Contracts\ShareTrackingContract;
use App\Enums\ShareOutcomeType;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\User;
use App\Shared\Auth\ResourceOwner;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class CreateSavedSearchAction
{
    use AsAction;

    public function __construct(
        private ShareTrackingContract $shareTrackingService,
        private SavedSearchCommands $savedSearchCommands,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ResourceOwner $owner, array $attributes, ?Request $request = null): SavedSearchSnapshot
    {
        return DB::transaction(function () use ($owner, $attributes, $request): SavedSearchSnapshot {
            $savedSearch = $this->savedSearchCommands->create($owner->resourceOwnerType(), $owner->resourceOwnerId(), $attributes);

            $this->shareTrackingService->recordOutcome(
                type: ShareOutcomeType::SavedSearchCreated,
                businessKey: ShareOutcomeBusinessKey::savedSearch($savedSearch->id),
                actor: $this->shareTrackingActor($owner),
                request: $request ?? request(),
                metadata: [
                    'saved_search_id' => $savedSearch->id,
                ],
                occurredAt: $savedSearch->createdAt ?? now(),
            );

            return $savedSearch;
        });
    }

    /**
     * ShareTrackingContract still requires a User actor. This is the isolation
     * point for the future ActorIdentity migration; remove it once the contract
     * accepts a resource actor identity.
     */
    private function shareTrackingActor(ResourceOwner $owner): ?User
    {
        return $owner instanceof User ? $owner : null;
    }
}
