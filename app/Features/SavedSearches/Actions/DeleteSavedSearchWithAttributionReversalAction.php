<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Api\DeleteSavedSearchWithAttributionReversal;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Shared\ShareTracking\SavedSearchShareOutcomes;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteSavedSearchWithAttributionReversalAction implements DeleteSavedSearchWithAttributionReversal
{
    use AsAction;

    public function __construct(
        private SavedSearchCommands $commands,
        private SavedSearchShareOutcomes $shareOutcomes,
    ) {}

    public function handle(SavedSearchSnapshot $savedSearch): void
    {
        $request = request();

        DB::transaction(function () use ($savedSearch, $request): void {
            $actor = $request->user();

            $this->commands->delete($savedSearch->userType, $savedSearch->userId, $savedSearch->id);
            $this->shareOutcomes->reverseDeleted(
                savedSearchId: $savedSearch->id,
                actor: $actor,
                request: $request,
            );
        });
    }
}
