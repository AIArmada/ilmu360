<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CommunityRelease;
use App\Models\User;

/**
 * Community release authorization, separately tiered:
 *
 * - Any admin-panel roleholder may browse and read releases.
 * - Only super_admin/admin may publish (fixed months, threshold-gated,
 *   immutable once published).
 * - Guests and members use the public latest-release endpoint, which
 *   needs no policy (coarse aggregates or honest insufficient-data).
 */
class CommunityReleasePolicy
{
    public function viewAny(?User $user): bool
    {
        return $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator', 'editor', 'viewer']);
    }

    public function view(?User $user, CommunityRelease $release): bool
    {
        return $this->viewAny($user);
    }

    public function publish(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
