<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Models\SavedSearch;
use App\Shared\Auth\UserMorphType;

/** Assign the canonical user morph identity before persisting a saved search. */
final readonly class AssignSavedSearchUserMorphTypeAction
{
    public function __construct(private UserMorphType $userMorphType) {}

    public function handle(SavedSearch $savedSearch): void
    {
        $savedSearch->user_type ??= $this->userMorphType->morphClass();
    }
}
