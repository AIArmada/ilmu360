<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Models\SavedSearch;
use Lorisleiva\Actions\Concerns\AsAction;

final class DeleteSavedSearchAction
{
    use AsAction;

    public function handle(SavedSearch $savedSearch): void
    {
        $savedSearch->delete();
    }
}
