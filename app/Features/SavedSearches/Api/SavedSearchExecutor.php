<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Api;

interface SavedSearchExecutor
{
    public function execute(SavedSearchSnapshot $savedSearch): SavedSearchExecutionResult;
}
