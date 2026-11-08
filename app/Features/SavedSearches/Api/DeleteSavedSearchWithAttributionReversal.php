<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Api;

interface DeleteSavedSearchWithAttributionReversal
{
    public function handle(SavedSearchSnapshot $savedSearch): void;
}
