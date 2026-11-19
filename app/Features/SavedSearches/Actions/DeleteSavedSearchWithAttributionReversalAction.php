<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Api\DeleteSavedSearchWithAttributionReversal;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\ShareTracking\Api\SavedSearchShareOutcomes;
use App\Shared\Auth\ResourceOwner;
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
        $actor = request()->user();

        DB::transaction(function () use ($savedSearch, $actor): void {
            $this->commands->delete($savedSearch->userType, $savedSearch->userId, $savedSearch->id);
            $this->shareOutcomes->reverseDeleted(
                savedSearchId: $savedSearch->id,
                actor: $actor instanceof ResourceOwner ? $actor : null,
            );
        });
    }
}
