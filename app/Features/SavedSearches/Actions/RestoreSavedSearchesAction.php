<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Models\SavedSearch;
use Lorisleiva\Actions\Concerns\AsAction;

final class RestoreSavedSearchesAction
{
    use AsAction;

    /** @param list<array<string, mixed>> $records */
    public function handle(array $records): void
    {
        foreach ($records as $attributes) {
            $savedSearch = new SavedSearch;
            $savedSearch->timestamps = false;
            $savedSearch->forceFill($attributes);
            $savedSearch->saveQuietly();
        }
    }
}
