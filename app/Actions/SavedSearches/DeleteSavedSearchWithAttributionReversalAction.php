<?php

declare(strict_types=1);

namespace App\Actions\SavedSearches;

use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
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
            $owner = $request->user();

            $this->commands->delete($savedSearch->userType, $savedSearch->userId, $savedSearch->id);
            $this->reverse->handle(
                $businessKey,
                'saved_search_deleted',
                $owner instanceof User ? $owner : null,
                $request,
            );
        });
    }
}
