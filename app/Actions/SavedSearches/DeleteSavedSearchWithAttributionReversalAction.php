<?php

declare(strict_types=1);

namespace App\Actions\SavedSearches;

use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteSavedSearchWithAttributionReversalAction
{
    use AsAction;

    public function __construct(
        private DeleteSavedSearchAction $delete,
        private ReverseShareOutcomeAction $reverse,
    ) {}

    public function handle(SavedSearch $savedSearch, Request $request): void
    {
        DB::transaction(function () use ($savedSearch, $request): void {
            $businessKey = ShareOutcomeBusinessKey::savedSearch((string) $savedSearch->getKey());
            $owner = $request->user();

            $this->delete->handle($savedSearch);
            $this->reverse->handle(
                $businessKey,
                'saved_search_deleted',
                $owner instanceof User ? $owner : null,
                $request,
            );
        });
    }
}
