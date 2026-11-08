<?php

declare(strict_types=1);

namespace App\Actions\SavedSearches;

use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteSavedSearchWithAttributionReversalAction
{
    use AsAction;

    public function __construct(
        private SavedSearchCommands $commands,
        private ReverseShareOutcomeAction $reverse,
    ) {}

    public function handle(SavedSearchSnapshot $savedSearch, Request $request): void
    {
        DB::transaction(function () use ($savedSearch, $request): void {
            $businessKey = ShareOutcomeBusinessKey::savedSearch($savedSearch->id);

            $this->commands->delete($savedSearch->userType, $savedSearch->userId, $savedSearch->id);
            $this->reverse->handle(
                $businessKey,
                'saved_search_deleted',
                $this->shareTrackingActor($request->user()),
                $request,
            );
        });
    }

    /**
     * ReverseShareOutcomeAction still requires a User actor. This is the
     * isolation point for the future ActorIdentity migration; remove it once
     * the action accepts a resource actor identity.
     */
    private function shareTrackingActor(?Authenticatable $actor): ?User
    {
        return $actor instanceof User ? $actor : null;
    }
}
