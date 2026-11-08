<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Policies;

use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Shared\Auth\ResourceOwner;

class SavedSearchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(ResourceOwner $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(ResourceOwner $user, SavedSearch|SavedSearchSnapshot $savedSearch): bool
    {
        return $this->isOwner($user, $savedSearch);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(ResourceOwner $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(ResourceOwner $user, SavedSearch|SavedSearchSnapshot $savedSearch): bool
    {
        return $this->isOwner($user, $savedSearch);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(ResourceOwner $user, SavedSearch|SavedSearchSnapshot $savedSearch): bool
    {
        return $this->isOwner($user, $savedSearch);
    }

    public function deleteAny(ResourceOwner $user): bool
    {
        return false;
    }

    private function isOwner(ResourceOwner $user, SavedSearch|SavedSearchSnapshot $savedSearch): bool
    {
        $ownerType = $savedSearch instanceof SavedSearchSnapshot ? $savedSearch->userType : $savedSearch->user_type;
        $ownerId = $savedSearch instanceof SavedSearchSnapshot ? $savedSearch->userId : $savedSearch->user_id;

        return $user->resourceOwnerType() === $ownerType && $user->resourceOwnerId() === $ownerId;
    }
}
