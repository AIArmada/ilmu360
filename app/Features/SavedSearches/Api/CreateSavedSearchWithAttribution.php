<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Api;

use App\Shared\Auth\ResourceOwner;

interface CreateSavedSearchWithAttribution
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ResourceOwner $owner, array $attributes): SavedSearchSnapshot;
}
