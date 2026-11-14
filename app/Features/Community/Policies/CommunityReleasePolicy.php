<?php

declare(strict_types=1);

namespace App\Features\Community\Policies;

use App\Features\Community\Models\CommunityRelease;
use App\Shared\Auth\AccountActor;

/**
 * Community release authorization, separately tiered:
 *
 * - Any admin-panel roleholder may browse and read releases.
 * - Only super_admin/admin may publish (fixed months, threshold-gated,
 *   immutable once published).
 * - Guests and members use the public latest-release endpoint, which
 *   needs no policy (coarse aggregates or honest insufficient-data).
 */
final class CommunityReleasePolicy
{
    public function viewAny(?AccountActor $user): bool
    {
        return $user instanceof AccountActor
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator', 'editor', 'viewer']);
    }

    public function view(?AccountActor $user, CommunityRelease $release): bool
    {
        return $this->viewAny($user);
    }

    public function publish(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
