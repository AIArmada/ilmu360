<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use App\Features\ShareTracking\Api\Orm\GuestClaimScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Boundary adapter over the member/guest share-profile scope.
 */
final readonly class GuestClaimScopeService implements GuestClaimScope
{
    public function guestClaims(): Builder
    {
        return ShareProfileScope::guestClaims();
    }
}
