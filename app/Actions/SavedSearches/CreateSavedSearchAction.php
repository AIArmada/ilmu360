<?php

namespace App\Actions\SavedSearches;

use App\Contracts\ShareTrackingContract;
use App\Enums\ShareOutcomeType;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\User;
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
    public function handle(User $user, array $attributes, ?Request $request = null): SavedSearchSnapshot
    {
        return DB::transaction(function () use ($user, $attributes, $request): SavedSearchSnapshot {
            $savedSearch = $this->savedSearchCommands->create($user->getMorphClass(), (string) $user->getKey(), $attributes);

            $this->shareTrackingService->recordOutcome(
                type: ShareOutcomeType::SavedSearchCreated,
                businessKey: ShareOutcomeBusinessKey::savedSearch($savedSearch->id),
                actor: $user,
                request: $request ?? request(),
                metadata: [
                    'saved_search_id' => $savedSearch->id,
                ],
                occurredAt: $savedSearch->createdAt ?? now(),
            );

            return $savedSearch;
        });
    }
}
